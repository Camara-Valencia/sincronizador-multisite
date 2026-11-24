<?php
/*
Plugin Name: Sincronizador Multisite
Description: Sincroniza entradas entre el sitio principal y los subsitios.
Version: 1.1.0
Author: Tu Nombre
Network: true
*/

// Evita el acceso directo
defined( 'ABSPATH' ) || exit;

define('MMS_VERSION', '1.1.0');

/**
 * Lee un campo ACF; si ACF no está cargado, lee la meta directamente
 * (antes un ACF desactivado provocaba un error fatal al publicar).
 */
function mms_get_field($name, $post_id) {
    return function_exists('get_field') ? get_field($name, $post_id) : get_post_meta($post_id, $name, true);
}

/**
 * Guarda un campo ACF; si ACF no está cargado, guarda la meta directamente.
 */
function mms_update_field($name, $value, $post_id) {
    return function_exists('update_field') ? update_field($name, $value, $post_id) : update_post_meta($post_id, $name, $value);
}

// Matriz de red: qué tipos de contenido se sincronizan con qué subsitios (solo superadmins)
require_once plugin_dir_path(__FILE__) . 'includes/network-settings.php';

// Media compartida en el bucket común (Offload Media) y correcciones de Offload en multisite
require_once plugin_dir_path(__FILE__) . 'includes/offload-compat.php';

// Hook para crear el post type personalizado
add_action('init', 'mms_register_synced_post_type');

function mms_register_synced_post_type() {
    $labels = array(
        'name'          => 'Posts Sincronizados',
        'singular_name' => 'Post Sincronizado',
    );
    $args = array(
        'labels'        => $labels,
        'public'        => true,
        'has_archive'   => false,
        'show_in_menu'  => true,
        'supports'      => array('title'),
        'show_in_rest'  => true,
    );
    register_post_type('synced_post', $args);
}

// Hook para sincronizar el post cuando se guarda en el sitio principal
add_action('save_post', 'mms_sync_post_to_sites', 10, 2);

function mms_sync_post_to_sites($post_id, $post) {
    // Interruptor de emergencia: define( 'MMS_DISABLE_SYNC', true ); en wp-config.php
    // para la sincronización automática sin desactivar el plugin (los synced_post
    // existentes siguen registrados y visibles en los subsitios).
    if (defined('MMS_DISABLE_SYNC') && MMS_DISABLE_SYNC) {
        return;
    }

    // Solo desde el sitio principal; evitar revisiones, autosaves y auto-drafts
    if (!is_main_site() || wp_is_post_revision($post_id) || $post->post_status === 'auto-draft' || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    // Solo contenidos publicados: el synced_post se crea siempre como 'publish', así que
    // sincronizar borradores/pendientes/privados (p. ej. los que genera la IA) los hacía
    // públicos en los subsitios y disparaba en cada subsitio el vaciado de cachés.
    if ($post->post_status !== 'publish') {
        return;
    }

    // Subsitios configurados para este tipo de contenido en
    // Administración de la red → Ajustes → Sincronización. Por defecto, ninguno.
    $target_sites = mms_get_sync_target_sites($post->post_type);
    if (empty($target_sites)) {
        return;
    }

    $title_object_post_type = get_post_type_title_by_id($post_id);
    $original_post_type_slug = $title_object_post_type->slug;
    $original_post_type_title = $title_object_post_type->title;

    foreach ($target_sites as $site_id) {
        if ($site_id === get_current_blog_id() || !get_site($site_id)) {
            continue;
        }

        switch_to_blog($site_id);

        // Comprobar si ya existe un post sincronizado para el post original
        $existing_posts = get_posts(array(
            'post_type'   => 'synced_post',
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => 1,
            'meta_query'  => array(
                array(
                    'key'   => 'original_post_id',
                    'value' => $post_id,
                ),
            ),
        ));

        if (empty($existing_posts)) {
            // Crear el post sincronizado con el ID original
            $new_post_id = wp_insert_post(array(
                'post_title'  => $post->post_title,
                'post_type'   => 'synced_post',
                'post_status' => 'publish',
            ));

            if ($new_post_id && !is_wp_error($new_post_id)) {
                mms_update_field('original_post_id', $post_id, $new_post_id);
                mms_update_field('original_post_type_slug', $original_post_type_slug, $new_post_id);
                mms_update_field('original_post_type_title', $original_post_type_title, $new_post_id);
            }
        }

        restore_current_blog();
    }
}

// Hook para añadir el botón de duplicar
add_filter('post_row_actions', 'mms_add_duplicate_button', 10, 2);

// Hooks para funcionalidad de duplicación de contenido entre sitios
add_action('admin_enqueue_scripts', 'mms_enqueue_duplicate_scripts');
add_action('post_row_actions', 'mms_add_duplicate_content_action', 10, 2);
add_action('page_row_actions', 'mms_add_duplicate_content_action', 10, 2);
add_action('add_meta_boxes', 'mms_add_duplicate_meta_box');
add_action('wp_ajax_duplicate_content_to_site', 'mms_handle_duplicate_content_ajax');
add_action('wp_ajax_get_available_sites', 'mms_get_available_sites_ajax');

/**
 * Enlace para convertir un synced_post en contenido local. El nonce va ligado al post.
 */
function mms_get_duplicate_link($post_id) {
    return wp_nonce_url(
        admin_url('admin-post.php?action=mms_duplicate_post&post_id=' . (int) $post_id),
        'mms_duplicate_nonce_' . (int) $post_id
    );
}

function mms_add_duplicate_button($actions, $post) {
    if ($post->post_type === 'synced_post' && current_user_can('publish_posts')) {
        $duplicate_link = mms_get_duplicate_link($post->ID);
        $actions['duplicate'] = '<a href="' . esc_url($duplicate_link) . '" title="Duplicar esta entrada">Duplicar</a>';
    }
    return $actions;
}

// Hook para añadir el botón de duplicar en la pantalla de edición del post sincronizado
add_action('post_submitbox_misc_actions', 'mms_add_duplicate_button_on_edit_screen');

function mms_add_duplicate_button_on_edit_screen($post) {
    // Asegúrate de que solo se muestre en el post type 'synced_post'
    if ($post->post_type === 'synced_post' && current_user_can('publish_posts')) {
        $duplicate_link = mms_get_duplicate_link($post->ID);
        $type_title = mms_get_field('original_post_type_title', $post->ID);
        ?>
        <div class="misc-pub-section misc-pub-duplicate">
            <a class="button button-primary button-large" href="<?php echo esc_url($duplicate_link); ?>" title="Duplicar en este sitio">
                Duplicar <?php echo esc_html($type_title); ?>
            </a>
            <p class="description">
                Esto creará una copia de <?php echo esc_html($type_title); ?> en este sitio y eliminará esta entrada sincronizada.
            </p>
        </div>
        <?php
    }
}

// Mostrar el tipo de post original en la parte superior izquierda del editor
add_action('edit_form_after_title', 'mms_show_original_post_type_info');
function mms_show_original_post_type_info($post) {
    if ($post->post_type === 'synced_post') {
        $original_post_type = mms_get_field('original_post_type_slug', $post->ID);
        $post_type_label = $original_post_type ? mms_get_field('original_post_type_title', $post->ID) : 'Entrada';
        if (!$original_post_type) {
            $original_post_type = 'post';
        }

        echo '<div style="margin: 24px 0 16px 0; padding: 16px; background: #f1f8ff; border-left: 6px solid #0073aa; font-size: 1.7em; font-weight: bold; color: #0073aa;">Tipo de publicación: ' . esc_html($post_type_label) . ' <span style="font-size:0.7em;color:#555;">(' . esc_html($original_post_type) . ')</span></div>';
    }
}

function duplicate_attachment($attachment_id, $target_site_id = null) {
    $attachment_id = (int) $attachment_id;
    if (!$attachment_id) {
        return false;
    }

    // Quien llama ya suele estar en el sitio destino
    $target_blog_id = $target_site_id ? (int) $target_site_id : get_current_blog_id();
    $can_share = mms_offload_table_exists($target_blog_id);

    switch_to_blog(get_main_site_id());
    $attachment = get_post($attachment_id);
    $shared = false;
    $source = false;
    if ($attachment && $attachment->post_type === 'attachment') {
        // En el bucket común: se comparte el objeto, sin copiar el archivo.
        // Si no está en el bucket (o el destino no tiene Offload): copia física.
        $shared = $can_share ? mms_offload_get_share_data($attachment) : false;
        if (!$shared) {
            $source = mms_get_attachment_source($attachment_id);
        }
    }
    // Volver siempre al blog de partida: antes el return sin restore y el
    // switch_to_blog() anidado dejaban la pila de blogs desequilibrada y el resto
    // de la petición seguía ejecutándose en el sitio equivocado.
    restore_current_blog();

    if (!$shared && !$source) {
        return false;
    }

    $switched = $target_blog_id !== get_current_blog_id();
    if ($switched) {
        switch_to_blog($target_blog_id);
    }

    $new_attachment_id = false;
    if ($shared) {
        $new_attachment_id = mms_offload_create_shared_attachment($shared);
    }
    if (!$new_attachment_id && $source) {
        $new_attachment_id = mms_copy_attachment_file($source['path'], $source['name']);
    }

    if ($switched) {
        restore_current_blog();
    }

    if ($source && $source['temp']) {
        @unlink($source['path']);
    }

    return $new_attachment_id;
}

/**
 * Archivo de origen de un attachment del sitio actual, listo para copiar.
 *
 * Con Offload Media activo, get_attached_file() filtrado devuelve la URL del bucket
 * y file_exists() fallaba: la media se perdía sin avisar. Se usa la ruta local sin
 * filtrar y, si el archivo ya no está en disco (quitado del servidor tras subirlo),
 * se descarga a un temporal desde la URL del bucket.
 *
 * @return array|false array('path' => ruta local, 'name' => nombre de archivo, 'temp' => bool)
 */
function mms_get_attachment_source($attachment_id) {
    $local = get_attached_file($attachment_id, true);
    if ($local && file_exists($local)) {
        return array('path' => $local, 'name' => basename($local), 'temp' => false);
    }

    // La URL del item se lee de la tabla del sitio actual: tras un switch_to_blog(),
    // wp_get_attachment_url() de Offload puede resolver contra la tabla de otro sitio.
    $row = mms_offload_get_row($attachment_id);
    $url = $row ? $row['url'] : wp_get_attachment_url($attachment_id);
    if (!$url) {
        return false;
    }

    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        error_log('[MMS] No se pudo descargar el adjunto ' . $attachment_id . ': ' . $tmp->get_error_message());
        return false;
    }

    $name = $local ? basename($local) : basename(wp_parse_url($url, PHP_URL_PATH));
    return array('path' => $tmp, 'name' => $name, 'temp' => true);
}

/**
 * Copia el archivo al uploads del sitio actual y crea su attachment.
 *
 * @param string $file_path Ruta local del archivo de origen.
 * @param string $name      Nombre de archivo de destino (por defecto, el del origen).
 */
function mms_copy_attachment_file($file_path, $name = '') {

    if (!$file_path || !file_exists($file_path)) {
        return false;
    }
    // Copiar el archivo a la carpeta de uploads del sitio actual
    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error'])) {
        return false;
    }
    $filename = wp_unique_filename($upload_dir['path'], sanitize_file_name($name ?: basename($file_path)));
    $new_file = $upload_dir['path'] . '/' . $filename;

    if (!@copy($file_path, $new_file)) {
        return false;
    }

    // Tras un switch_to_blog(), Offload guardaría el item en la tabla de otro sitio
    $suspended = mms_offload_suspend_upload();
    $new_attachment_id = mms_create_attachment_from_file($new_file, $filename);
    if ($suspended) {
        mms_offload_resume_upload();
    }

    return $new_attachment_id;
}

/**
 * Crea el attachment del archivo ya copiado en el uploads del sitio actual.
 */
function mms_create_attachment_from_file($new_file, $filename) {
    $filetype = wp_check_filetype($filename, null);
    $attachment_data = array(
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );
    $new_attachment_id = wp_insert_attachment($attachment_data, $new_file);
    if (!$new_attachment_id || is_wp_error($new_attachment_id)) {
        @unlink($new_file);
        return false;
    }

    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    $attach_data = wp_generate_attachment_metadata($new_attachment_id, $new_file);
    wp_update_attachment_metadata($new_attachment_id, $attach_data);

    return $new_attachment_id;
}

function mms_duplicate_acf_media_recursive($field_value, $field_object) {
    if (empty($field_object) || empty($field_object['type'])) {
        return $field_value;
    }

    $type = $field_object['type'];

    if ($type === 'image' || $type === 'file') {
        if (!empty($field_value)) {
            $media_id = is_array($field_value) && isset($field_value['ID']) ? $field_value['ID'] : $field_value;
            $new_media_id = duplicate_attachment($media_id);
            if ($new_media_id) {
                return $new_media_id;
            }
        }
        return $field_value;
    }

    if ($type === 'gallery') {
        if (!empty($field_value) && is_array($field_value)) {
            $new_gallery = array();
            foreach ($field_value as $img_id) {
                $media_id = is_array($img_id) && isset($img_id['ID']) ? $img_id['ID'] : $img_id;
                $new_media_id = duplicate_attachment($media_id);
                if ($new_media_id) {
                    $new_gallery[] = $new_media_id;
                }
            }
            return $new_gallery;
        }
        return $field_value;
    }

    if ($type === 'repeater' && !empty($field_object['sub_fields']) && is_array($field_value)) {
        $new_repeater = array();
        foreach ($field_value as $row) {
            $new_row = array();
            foreach ($field_object['sub_fields'] as $sub_field) {
                $sub_key = $sub_field['name'];
                if (isset($row[$sub_key])) {
                    $new_row[$sub_key] = mms_duplicate_acf_media_recursive($row[$sub_key], $sub_field);
                }
            }
            $new_repeater[] = $new_row;
        }
        return $new_repeater;
    }

    if ($type === 'flexible_content' && !empty($field_object['layouts']) && is_array($field_value)) {
        $new_flexible = array();
        foreach ($field_value as $flex_row) {
            $layout_name = isset($flex_row['acf_fc_layout']) ? $flex_row['acf_fc_layout'] : '';
            $new_row = array('acf_fc_layout' => $layout_name);
            $layout = null;
            foreach ($field_object['layouts'] as $l) {
                if ($l['name'] === $layout_name) {
                    $layout = $l;
                    break;
                }
            }
            if ($layout && !empty($layout['sub_fields'])) {
                foreach ($layout['sub_fields'] as $sub_field) {
                    $sub_key = $sub_field['name'];
                    if (isset($flex_row[$sub_key])) {
                        $new_row[$sub_key] = mms_duplicate_acf_media_recursive($flex_row[$sub_key], $sub_field);
                    }
                }
            }
            $new_flexible[] = $new_row;
        }
        return $new_flexible;
    }

    return $field_value;
}

// Hook para gestionar la acción del botón
add_action('admin_post_mms_duplicate_post', 'mms_handle_duplicate_action');

function mms_handle_duplicate_action() {
    $synced_post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

    // Verificar el nonce de seguridad (ligado al post)
    if (!$synced_post_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mms_duplicate_nonce_' . $synced_post_id)) {
        wp_die('Error de seguridad.');
    }

    // Crea contenido publicado y borra el synced_post: hace falta poder publicar y
    // borrar esa entrada. Antes bastaba 'edit_posts' (colaboradores incluidos).
    if (!current_user_can('publish_posts') || !current_user_can('delete_post', $synced_post_id)) {
        wp_die('No tienes permisos suficientes.');
    }

    // Solo synced_post: antes se aceptaba cualquier ID y se borraba ese post al final
    $synced_post = get_post($synced_post_id);
    if (!$synced_post || $synced_post->post_type !== 'synced_post') {
        wp_die('La entrada indicada no es un contenido sincronizado.');
    }

    $original_post_id = (int) mms_get_field('original_post_id', $synced_post_id);
    if (!$original_post_id) {
        wp_die('La entrada sincronizada no tiene contenido original asociado.');
    }

    // Obtener el contenido del post original del sitio principal
    switch_to_blog(get_main_site_id());
    $original_post = get_post($original_post_id);
    if (!$original_post) {
        restore_current_blog();
        wp_die('El post original no fue encontrado.');
    }

    // Obtener taxonomías y términos asociados
    $taxonomies = get_object_taxonomies($original_post->post_type);
    $terms_by_tax = array();
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($original_post_id, $taxonomy, array('fields' => 'slugs'));
        if (!empty($terms) && !is_wp_error($terms)) {
            $terms_by_tax[$taxonomy] = $terms;
        }
    }

    // Obtener campos ACF
    if (function_exists('get_fields')) {
        $acf_fields = get_fields($original_post_id);
    } else {
        $acf_fields = array();
    }

    // Precalcular los objetos de campo ACF en el principal (evita un switch por campo)
    $acf_field_objects = array();
    if (!empty($acf_fields) && function_exists('get_field_object')) {
        foreach ($acf_fields as $field_key => $field_value) {
            $acf_field_objects[$field_key] = get_field_object($field_key, $original_post_id);
        }
    }

    $thumbnail_id = get_post_thumbnail_id($original_post_id);

    restore_current_blog(); // Volver al subsitio

    // Duplicar imagen destacada (duplicate_attachment soporta archivos en Offload Media)
    $new_thumbnail_id = $thumbnail_id ? duplicate_attachment($thumbnail_id) : false;

    // Crear la nueva entrada en el subsitio actual, duplicando también la fecha de creación y publicando
    $new_post_args = array(
        'post_title'   => $original_post->post_title,
        'post_content' => $original_post->post_content,
        'post_status'  => 'publish',
        'post_type'    => $original_post->post_type,
        'post_date'    => $original_post->post_date,
        'post_date_gmt'=> $original_post->post_date_gmt,
    );
    $new_post_id = wp_insert_post($new_post_args, true);
    if (is_wp_error($new_post_id) || !$new_post_id) {
        wp_die('No se pudo crear el contenido: ' . (is_wp_error($new_post_id) ? esc_html($new_post_id->get_error_message()) : 'error desconocido'));
    }

    // Asignar imagen destacada duplicada si existe
    if ($new_thumbnail_id) {
        set_post_thumbnail($new_post_id, $new_thumbnail_id);
    }
    
    // Asignar taxonomías y términos
    if (!empty($terms_by_tax)) {
        foreach ($terms_by_tax as $taxonomy => $terms) {
            // Asignar términos por slug (no por ID)
            wp_set_object_terms($new_post_id, $terms, $taxonomy, false);
        }
    }

    // Duplicar y asignar archivos/media de campos ACF tipo file, image, gallery
    if (!empty($acf_fields) && function_exists('update_field')) {
        foreach ($acf_fields as $field_key => $field_value) {
            $field_object = isset($acf_field_objects[$field_key]) ? $acf_field_objects[$field_key] : false;

            if (!$field_object) {
                update_field($field_key, $field_value, $new_post_id);
                continue;
            }

            $new_value = mms_duplicate_acf_media_recursive($field_value, $field_object);

            update_field($field_key, $new_value, $new_post_id);
        }
    }

    // Eliminar el post sincronizado
    wp_delete_post($synced_post_id);
    
    // Redirigir al usuario
    wp_redirect(admin_url('edit.php?post_type=' . $original_post->post_type));
    exit;
}

function get_post_type_title_by_id($post_id) {
    $post_type_slug = get_post_type($post_id);
    $result = (object) [
        'slug' => '',
        'title' => ''
    ];
    if ($post_type_slug) {
        $post_type_object = get_post_type_object($post_type_slug);
        if ($post_type_object && isset($post_type_object->labels->singular_name)) {
            $result->slug = $post_type_slug;
            $result->title = $post_type_object->labels->singular_name;
        }
    }
    return $result;
}

/**
 * Enqueue admin scripts for duplicate functionality
 */
function mms_enqueue_duplicate_scripts($hook) {
    global $post;
    // Only load on post list pages and editor
    if (!in_array($hook, array('edit.php', 'post.php', 'post-new.php'))) {
        return;
    }

    // "Duplicar en" solo existe en el sitio principal
    if (!mms_can_duplicate_to_sites()) {
        return;
    }
    
    wp_enqueue_script('mms-duplicate-script', plugin_dir_url(__FILE__) . 'js/duplicate-admin.js', array('jquery'), MMS_VERSION, true);
    wp_enqueue_style('mms-duplicate-style', plugin_dir_url(__FILE__) . 'css/duplicate-admin.css', array(), MMS_VERSION);
    
    // Localize script with AJAX data
    wp_localize_script('mms-duplicate-script', 'mms_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('duplicate_content_nonce'),
        'post_slug' => $post ? get_post_field('post_name', $post->ID) : '',
        'post_type' => $post ? $post->post_type : '',
        'strings' => array(
            'duplicating' => 'Duplicando...',
            'success' => 'Contenido duplicado exitosamente',
            'error' => 'Error al duplicar contenido',
            'select_site' => 'Selecciona un sitio'
        )
    ));
}

/**
 * Add duplicate content action to post/page row actions
 */
/**
 * Duplicar contenido a otro sitio solo se puede hacer desde el sitio principal
 * y con permisos de superadministrador.
 */
function mms_can_duplicate_to_sites() {
    return is_multisite() && is_main_site() && current_user_can('manage_sites');
}

/**
 * Sitios de destino para "Duplicar en": todos los de la red menos el actual,
 * sin los borrados ni los marcados como spam, ordenados por nombre.
 *
 * @return WP_Site[]
 */
function mms_get_duplicate_target_sites() {
    $current_site_id = get_current_blog_id();

    $sites = array_filter(
        get_sites(array('number' => 0, 'deleted' => 0, 'spam' => 0)),
        function ($site) use ($current_site_id) {
            return (int) $site->blog_id !== (int) $current_site_id;
        }
    );

    usort($sites, function ($a, $b) {
        return strcasecmp(get_blog_option($a->blog_id, 'blogname'), get_blog_option($b->blog_id, 'blogname'));
    });

    return $sites;
}

function mms_add_duplicate_content_action($actions, $post) {
    // Only show for published posts/pages
    if ($post->post_status !== 'publish' || $post->post_type === 'synced_post') {
        return $actions;
    }

    // Solo desde el sitio principal y para superadministradores
    if (!mms_can_duplicate_to_sites()) {
        return $actions;
    }

    // Add duplicate action
    $actions['duplicate_to'] = sprintf(
        '<a href="#" class="mms-duplicate-content" data-post-id="%d" data-post-type="%s">Duplicar en</a>',
        $post->ID,
        $post->post_type
    );
    
    return $actions;
}

/**
 * Add duplicate meta box to post editor
 */
function mms_add_duplicate_meta_box() {
    // Only add to published posts/pages
    global $post;
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    
    // Solo desde el sitio principal y para superadministradores
    if (!mms_can_duplicate_to_sites()) {
        return;
    }

    // Get all public post types
    $post_types = get_post_types(array('public' => true), 'names');
    
    // Post types excluidos donde no se debe mostrar el meta box de duplicar
    $excluded_post_types = array(
        'auditoria-de-articul',
        'synced_post',
        // Agrega más post types aquí si es necesario
    );
    
    // Filtrar los post types excluidos
    $post_types = array_diff($post_types, $excluded_post_types);
    
    // Add meta box to all public post types
    foreach ($post_types as $post_type) {
        add_meta_box(
            'mms-duplicate-content',
            'Duplicar en otro sitio',
            'mms_duplicate_meta_box_callback',
            $post_type,
            'side',
            'low'
        );
    }
}

/**
 * Meta box callback
 */
function mms_duplicate_meta_box_callback($post) {
    ?>
    <div id="mms-editor-duplicate">
        <p>Duplica este contenido a otro sitio de la red.</p>
        <button type="button" class="button button-secondary mms-duplicate-content" 
                data-post-id="<?php echo $post->ID; ?>" 
                data-post-type="<?php echo $post->post_type; ?>">
            Duplicar en
        </button>
    </div>
    <?php
}

/**
 * Handle duplicate content AJAX request
 */
function mms_handle_duplicate_content_ajax() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'duplicate_content_nonce')) {
        wp_die('Token de seguridad no válido.');
    }
    
    // Solo desde el sitio principal y para superadministradores
    if (!mms_can_duplicate_to_sites()) {
        wp_send_json_error('Solo se puede duplicar contenido desde el sitio principal.');
    }

    $post_id = (int) $_POST['post_id'];
    $target_site_id = (int) $_POST['target_site_id'];

    // Validate post exists
    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error('Post no encontrado.');
    }

    // Validate target site exists
    $target_site = get_site($target_site_id);
    if (!$target_site || $target_site->deleted || $target_site->spam) {
        wp_send_json_error('Sitio destino no encontrado.');
    }
    
    // Don't allow duplicating to same site
    if ($target_site_id == get_current_blog_id()) {
        wp_send_json_error('No puedes duplicar al mismo sitio.');
    }
    
    // Perform content duplication
    $result = mms_duplicate_content_to_site($post_id, $target_site_id);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success(array(
            'message' => 'Contenido duplicado exitosamente',
            'new_post_id' => $result,
            'target_site_url' => get_admin_url($target_site_id, 'post.php?post=' . $result . '&action=edit')
        ));
    }
}

/**
 * Get available sites for AJAX
 */
function mms_get_available_sites_ajax() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'duplicate_content_nonce')) {
        wp_die('Token de seguridad no válido.');
    }
    
    // Solo desde el sitio principal y para superadministradores
    if (!mms_can_duplicate_to_sites()) {
        wp_send_json_error('Solo se puede duplicar contenido desde el sitio principal.');
    }

    // El slug y el tipo se leen del propio post: antes llegaban de la página
    // (vacíos en el listado), la comprobación encontraba cualquier entrada y se
    // ocultaban casi todos los sitios.
    $post = get_post(isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0);
    if (!$post) {
        wp_send_json_error('Post no encontrado.');
    }

    // Se muestran todos los sitios; los que ya tienen un contenido del mismo tipo
    // y con el mismo slug se marcan como "ya existe" y no se pueden elegir.
    $available_sites = array();
    foreach (mms_get_duplicate_target_sites() as $site) {
        $exists = false;

        if ($post->post_name !== '') {
            switch_to_blog($site->blog_id);
            $existing = get_posts(array(
                'name'             => $post->post_name,
                'post_type'        => $post->post_type,
                'post_status'      => 'any',
                'numberposts'      => 1,
                'fields'           => 'ids',
                'suppress_filters' => true,
            ));
            $exists = !empty($existing);
            restore_current_blog();
        }

        $available_sites[] = array(
            'blog_id'  => (int) $site->blog_id,
            'blogname' => get_blog_option($site->blog_id, 'blogname', $site->domain . $site->path),
            'domain'   => $site->domain,
            'path'     => $site->path,
            'exists'   => $exists,
        );
    }

    wp_send_json_success(array('sites' => $available_sites));
}

/**
 * Duplicate content to another site using existing attachment handling
 */
function mms_duplicate_content_to_site($post_id, $target_site_id) {
    // Obtener todos los datos necesarios ANTES de cambiar de blog
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', 'Post no encontrado.');
    }

    // Obtener meta del post
    $post_meta = get_post_meta($post_id);

    // Obtener imagen destacada
    $thumbnail_id = get_post_thumbnail_id($post_id);

    // Obtener taxonomías y términos
    $taxonomies = get_object_taxonomies($post->post_type);
    $terms_by_tax = array();
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
        if (!is_wp_error($terms) && !empty($terms)) {
            $terms_by_tax[$taxonomy] = $terms;
        }
    }

    // Obtener campos ACF
    $acf_fields = array();
    $acf_field_objects = array();
    if (function_exists('get_fields')) {
        $acf_fields = get_fields($post_id);
        if (!empty($acf_fields) && function_exists('get_field_object')) {
            foreach ($acf_fields as $field_key => $field_value) {
                $acf_field_objects[$field_key] = get_field_object($field_key, $post_id);
            }
        }
    }

    // Cambiar al sitio destino
    switch_to_blog($target_site_id);

    // Crear nuevo post
    $new_post_data = array(
        'post_title' => $post->post_title,
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_status' => $post->post_status,
        'post_type' => $post->post_type,
        'post_author' => get_current_user_id(),
        'post_date' => $post->post_date,
        'post_date_gmt' => $post->post_date_gmt,
        'comment_status' => $post->comment_status,
        'ping_status' => $post->ping_status,
        'post_parent' => $post->post_parent,
        'menu_order' => $post->menu_order,
        'post_mime_type' => $post->post_mime_type,
    );

    $new_post_id = wp_insert_post($new_post_data);

    if (is_wp_error($new_post_id)) {
        restore_current_blog();
        return $new_post_id;
    }

    // Copiar meta del post, salvo la que solo tiene sentido en el sitio de origen
    // (IDs de otro sitio, bloqueos de edición, estado de tic-sync)
    $skip_meta = array('_thumbnail_id', '_edit_lock', '_edit_last', '_wp_old_slug', '_tic_remote_id', '_tic_sync_pending');
    foreach ($post_meta as $meta_key => $meta_values) {
        if (in_array($meta_key, $skip_meta, true)) {
            continue;
        }
        foreach ($meta_values as $meta_value) {
            add_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
        }
    }

    // Copiar imagen destacada usando la función de duplicación de attachments
    if ($thumbnail_id) {
        $new_thumbnail_id = duplicate_attachment($thumbnail_id, $target_site_id);

        if ($new_thumbnail_id) {
            set_post_thumbnail($new_post_id, $new_thumbnail_id);
        }
    }

     // Asignar taxonomías y términos
     if (!empty($terms_by_tax)) {
        foreach ($terms_by_tax as $taxonomy => $terms) {
            // Asignar términos por slug (no por ID)
            wp_set_object_terms($new_post_id, $terms, $taxonomy, false);
        }
    }

    // Duplicar y asignar archivos/media de campos ACF tipo file, image, gallery
    if (!empty($acf_fields) && function_exists('update_field')) {
        foreach ($acf_fields as $field_key => $field_value) {
            // Calculados antes de cambiar de blog. Antes se hacía switch_to_blog(main) +
            // switch_to_blog(target) por campo, sin restore: la pila de blogs crecía y
            // ms_is_switched() seguía a true el resto de la petición.
            $field_object = isset($acf_field_objects[$field_key]) ? $acf_field_objects[$field_key] : false;

            if (!$field_object) {
                update_field($field_key, $field_value, $new_post_id);
                continue;
            }

            $new_value = mms_duplicate_acf_media_recursive($field_value, $field_object);

            update_field($field_key, $new_value, $new_post_id);
        }
    }

    restore_current_blog();

    return $new_post_id;
}