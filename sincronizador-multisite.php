<?php
/*
Plugin Name: Sincronizador Multisite
Description: Sincroniza entradas entre el sitio principal y los subsitios.
Version: 1.4.0
Author: Tu Nombre
Network: true
*/

// Evita el acceso directo
defined( 'ABSPATH' ) || exit;

define('MMS_VERSION', '1.4.0');

// Meta que marca, en un subsitio, el contenido local que viene de un original del
// principal ("Duplicar" o "Duplicar en"). Valor: ID del original.
define('MMS_LOCAL_COPY_META', '_mms_original_post_id');

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

// Borrado de synced_post al despublicar el original y limpieza de huérfanos
require_once plugin_dir_path(__FILE__) . 'includes/cleanup.php';

// Clonado de los contenidos en los subsitios (en segundo plano) y conversión de synced_post
require_once plugin_dir_path(__FILE__) . 'includes/clone.php';

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

// Al publicar en el principal ya no se crean synced_post: se clona el contenido en los
// subsitios de la matriz, en segundo plano (includes/clone.php). El tipo synced_post se
// mantiene para los que ya existen hasta convertirlos o limpiarlos.

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

/**
 * Attachment del sitio principal disponible en el sitio destino.
 *
 * Toda la media vive en el bucket con la misma ruta para todos los sitios: no se copia
 * ningún archivo. En el destino se crea (o reutiliza) un attachment que apunta al mismo
 * objeto del bucket; hace falta igualmente un ID local para que ACF, las funciones de
 * imagen de WordPress y la reescritura de URLs de Offload funcionen en ese sitio.
 *
 * Si el original no está en el bucket (p. ej. su subida sigue en la cola de Offload)
 * no se enlaza: se apunta en mms_media_failures() y se devuelve false.
 *
 * @return int|false
 */
function duplicate_attachment($attachment_id, $target_site_id = null) {
    static $done = array();

    $attachment_id = (int) $attachment_id;
    if (!$attachment_id) {
        return false;
    }

    // Quien llama ya suele estar en el sitio destino
    $target_blog_id = $target_site_id ? (int) $target_site_id : get_current_blog_id();

    // La misma imagen puede salir varias veces (destacada, ACF, contenido)
    $key = $target_blog_id . ':' . $attachment_id;
    if (array_key_exists($key, $done)) {
        return $done[$key];
    }

    $shared = false;
    if (mms_offload_table_exists($target_blog_id)) {
        switch_to_blog(get_main_site_id());
        $attachment = get_post($attachment_id);
        if ($attachment && $attachment->post_type === 'attachment') {
            $shared = mms_offload_get_share_data($attachment);
        }
        restore_current_blog();
    }

    if (!$shared) {
        error_log('[MMS] El adjunto ' . $attachment_id . ' del sitio principal no está en el bucket; no se enlaza en el sitio ' . $target_blog_id . '.');
        mms_media_failures($attachment_id);
        return $done[$key] = false;
    }

    $switched = $target_blog_id !== get_current_blog_id();
    if ($switched) {
        switch_to_blog($target_blog_id);
    }

    $new_attachment_id = mms_offload_create_shared_attachment($shared);

    if ($switched) {
        restore_current_blog();
    }

    if (!$new_attachment_id) {
        mms_media_failures($attachment_id);
    }

    return $done[$key] = $new_attachment_id ?: false;
}

/**
 * Adjuntos del principal que no se han podido enlazar en esta petición.
 *
 * @param int|null $attachment_id Si se indica, se añade a la lista.
 * @return int[]
 */
function mms_media_failures($attachment_id = null) {
    static $failures = array();
    if ($attachment_id !== null) {
        $failures[(int) $attachment_id] = (int) $attachment_id;
    }
    return array_values($failures);
}

/**
 * Texto de aviso para los adjuntos que no se han podido enlazar ('' si no hay).
 */
function mms_media_failures_message() {
    $failures = mms_media_failures();
    if (!$failures) {
        return '';
    }
    return sprintf(
        '%d imagen(es)/archivo(s) no estaban en el bucket y no se han enlazado (IDs en el sitio principal: %s). Espera a que Offload termine de subirlos y vuelve a duplicar, o asígnalos a mano.',
        count($failures),
        implode(', ', $failures)
    );
}

/**
 * URL directa del bucket de un archivo de un adjunto del sitio principal.
 *
 * Todos los tamaños de una imagen están en la misma carpeta del bucket, así que se toma
 * la carpeta del item de Offload del original y el nombre de archivo de $src (que indica
 * el tamaño). Requiere bucket público y URLs que no caducan.
 *
 * @return string '' si el adjunto no está en el bucket
 */
function mms_bucket_url_for($attachment_id, $src) {
    $file = wp_basename((string) wp_parse_url($src, PHP_URL_PATH));
    if ($file === '') {
        return '';
    }

    switch_to_blog(get_main_site_id());
    $row = mms_offload_get_row((int) $attachment_id);
    restore_current_blog();

    if (!$row || empty($row['url'])) {
        return '';
    }

    return trailingslashit(dirname($row['url'])) . rawurlencode(rawurldecode($file));
}

/**
 * Cambia en el contenido los IDs de media del sitio principal por los de los attachments
 * compartidos en el sitio actual (el destino).
 *
 * Offload elige la imagen SOLO por el ID (wp-image-{ID} de la clase), sin mirar la URL:
 * un ID del principal sin cambiar mostraba en cada subsitio el adjunto que tuviera ese
 * número allí, es decir, una imagen distinta en cada sitio. Se tocan:
 *   - class="wp-image-{ID}" y data-id="{ID}" (galerías)
 *   - "id", "mediaId" e "ids" en los comentarios de los bloques de media
 *
 * Si un adjunto no se puede enlazar, se le QUITA el ID (Offload ya no lo toca) y su
 * <img> pasa a apuntar directamente a la URL del bucket del original. Nunca queda un ID
 * del principal en el contenido del subsitio.
 */
function mms_duplicate_content_media($content) {
    if (!is_string($content) || $content === '') {
        return $content;
    }

    // ID del principal => ID en el destino (0 = no se ha podido enlazar)
    $resolved = array();
    $map = function ($old_id) use (&$resolved) {
        $old_id = (int) $old_id;
        if (!isset($resolved[$old_id])) {
            $resolved[$old_id] = (int) duplicate_attachment($old_id);
        }
        return $resolved[$old_id];
    };

    // 1. <img>: wp-image-{ID} y data-id="{ID}" (galerías antiguas)
    $content = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($map) {
        $tag    = $m[0];
        $failed = 0;

        $tag = preg_replace_callback('/(\s*)\bwp-image-(\d+)\b/', function ($c) use ($map, &$failed) {
            $new_id = $map($c[2]);
            if ($new_id) {
                return $c[1] . 'wp-image-' . $new_id;
            }
            $failed = (int) $c[2];
            return '';
        }, $tag);

        $tag = preg_replace_callback('/\s+data-id="(\d+)"/', function ($c) use ($map, &$failed) {
            $new_id = $map($c[1]);
            if ($new_id) {
                return ' data-id="' . $new_id . '"';
            }
            $failed = $failed ?: (int) $c[1];
            return '';
        }, $tag);

        // Sin enlazar: ya sin ID, src directo al bucket del original
        if ($failed) {
            $tag = preg_replace('/\s+(srcset|sizes)="[^"]*"/i', '', $tag);
            if (preg_match('/\bsrc="([^"]*)"/i', $tag, $src_match)) {
                $bucket = mms_bucket_url_for($failed, html_entity_decode($src_match[1]));
                if ($bucket) {
                    $tag = str_replace($src_match[0], 'src="' . esc_url($bucket) . '"', $tag);
                }
            }
        }

        return $tag;
    }, $content);

    // 2. Comentarios de bloques de media
    $content = preg_replace_callback(
        '/<!--\s+wp:(image|cover|file|video|audio|media-text|gallery)\s+(\{.*?\})\s+(\/?)-->/s',
        function ($m) use ($map) {
            $attrs = json_decode($m[2], true);
            if (!is_array($attrs)) {
                return $m[0];
            }
            foreach (array('id', 'mediaId') as $key) {
                if (isset($attrs[$key]) && is_numeric($attrs[$key])) {
                    $new_id = $map($attrs[$key]);
                    if ($new_id) {
                        $attrs[$key] = $new_id;
                    } else {
                        unset($attrs[$key]);
                    }
                }
            }
            if (isset($attrs['ids']) && is_array($attrs['ids'])) {
                $attrs['ids'] = array_values(array_filter(array_map($map, $attrs['ids'])));
            }
            // Mismo formato que serialize_block_attributes() de WordPress
            $json = $attrs ? serialize_block_attributes($attrs) : '';
            return '<!-- wp:' . $m[1] . ($json !== '' && $json !== '[]' ? ' ' . $json : '') . ' ' . ($m[3] ? '/' : '') . '-->';
        },
        $content
    );

    return $content;
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
            // Sin enlazar se deja vacío: el ID del principal en el subsitio es otro adjunto (o ninguno)
            return $new_media_id ? $new_media_id : '';
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

    $original_post = get_blog_post(get_main_site_id(), $original_post_id);
    if (!$original_post) {
        wp_die('El post original no fue encontrado.');
    }

    // Clon vinculado (se actualizará con los cambios del principal mientras no se edite aquí).
    // Si ya hay un clon, se actualiza en lugar de crear otro. Borra el synced_post.
    $clone = mms_find_clone(get_current_blog_id(), $original_post_id);
    $new_post_id = mms_clone_post_to_site($original_post_id, get_current_blog_id(), $clone && $clone['post_status'] !== 'trash' ? (int) $clone['ID'] : 0);
    if (is_wp_error($new_post_id)) {
        wp_die('No se pudo crear el contenido: ' . esc_html($new_post_id->get_error_message()));
    }

    // Por si el synced_post no apuntaba al original por la meta (se borra igualmente)
    if (get_post($synced_post_id)) {
        wp_delete_post($synced_post_id, true);
    }

    // Redirigir al usuario (con aviso si alguna media no estaba en el bucket)
    $redirect = admin_url('edit.php?post_type=' . $original_post->post_type);
    if (mms_media_failures()) {
        $redirect = add_query_arg('mms_media_fallos', implode(',', mms_media_failures()), $redirect);
    }
    wp_safe_redirect($redirect);
    exit;
}

// Aviso tras "Duplicar" si alguna media no se pudo enlazar
add_action('admin_notices', function () {
    if (empty($_GET['mms_media_fallos'])) {
        return;
    }
    $ids = array_filter(array_map('intval', explode(',', wp_unslash($_GET['mms_media_fallos']))));
    if (!$ids) {
        return;
    }
    printf(
        '<div class="notice notice-warning is-dismissible"><p>Contenido duplicado, pero %d imagen(es)/archivo(s) no estaban en el bucket y no se han enlazado (IDs en el sitio principal: %s). Asígnalos a mano o vuelve a duplicar cuando Offload termine de subirlos.</p></div>',
        count($ids),
        esc_html(implode(', ', $ids))
    );
});

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
    check_ajax_referer('duplicate_content_nonce', 'nonce');

    // Solo desde el sitio principal y para superadministradores
    if (!mms_can_duplicate_to_sites()) {
        wp_send_json_error('Solo se puede duplicar contenido desde el sitio principal.');
    }

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $target_site_id = isset($_POST['target_site_id']) ? (int) $_POST['target_site_id'] : 0;

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
    $clone  = mms_find_clone($target_site_id, $post_id);
    $result = mms_clone_post_to_site($post_id, $target_site_id, $clone && $clone['post_status'] !== 'trash' ? (int) $clone['ID'] : 0);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } else {
        wp_send_json_success(array(
            'message' => trim('Contenido duplicado exitosamente. ' . esc_html(mms_media_failures_message())),
            'new_post_id' => $result,
            'target_site_url' => get_admin_url($target_site_id, 'post.php?post=' . $result . '&action=edit')
        ));
    }
}

/**
 * Get available sites for AJAX
 */
function mms_get_available_sites_ajax() {
    check_ajax_referer('duplicate_content_nonce', 'nonce');

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
