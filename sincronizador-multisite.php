<?php
/*
Plugin Name: Sincronizador Multisite
Description: Sincroniza entradas entre el sitio principal y los subsitios.
Version: 1.0
Author: Tu Nombre
Network: true
*/

// Evita el acceso directo
defined( 'ABSPATH' ) || exit;

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
    // Si no es el sitio principal o el tipo de post no es 'post', o es una revisión, salir.
    $post_types_availible = array(
        'post', 
        'caso-de-exito',
        'evento',
        'caso-de-exito',
        'guia-o-manual-practi',
        'infografia',
        'informe-o-estudio',
        'partner',
        'plantilla-descargabl',
        'podcast',
        'recurso',
        'servicio',
        'solucion',
        'tendencia',
        'video',
        'webinar'
    );
    
    if (!in_array($post->post_type, $post_types_availible)) {
        return;
    }

    $title_object_post_type = get_post_type_title_by_id($post_id);
    $original_post_type_slug = $title_object_post_type->slug;
    $original_post_type_title = $title_object_post_type->title;

    // Evitar bucles infinitos y auto-drafts

    if (!is_main_site() || $post->post_status === 'auto-draft' || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    $sites = get_sites();
    foreach ($sites as $site) {
        if ($site->id !== get_current_blog_id()) { // No sincronizar en el mismo sitio
            switch_to_blog($site->id);
            
            
            $post_types_no_duplicate = get_field('post_types_no_duplicate', 'options');
            if(!in_array($original_post_type_slug, $post_types_no_duplicate)) {
                // Comprobar si ya existe un post sincronizado para el post original
                $existing_posts = get_posts(array(
                    'post_type'  => 'synced_post',
                    'meta_query' => array(
                        array(
                            'key'   => 'original_post_id',
                            'value' => $post_id,
                        ),
                    ),
                ));
                if (empty($existing_posts)) {
                    // Crear el post sincronizado con el ID original
                    $new_post_args = array(
                        'post_title'  => $post->post_title,
                        'post_type'   => 'synced_post',
                        'post_status' => 'publish',
                    );
                    $new_post_id = wp_insert_post($new_post_args);
                    update_field('original_post_id', $post_id, $new_post_id);
                    update_field('original_post_type_slug', $original_post_type_slug, $new_post_id);
                    update_field('original_post_type_title', $original_post_type_title, $new_post_id);
                }
            }


            restore_current_blog();
        }
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

function mms_add_duplicate_button($actions, $post) {
    if ($post->post_type === 'synced_post') {
        $duplicate_link = wp_nonce_url(
            admin_url('admin-post.php?action=mms_duplicate_post&post_id=' . $post->ID),
            'mms_duplicate_nonce'
        );
        $actions['duplicate'] = '<a href="' . esc_url($duplicate_link) . '" title="Duplicar esta entrada">Duplicar</a>';
    }
    return $actions;
}

// Hook para añadir el botón de duplicar en la pantalla de edición del post sincronizado
add_action('post_submitbox_misc_actions', 'mms_add_duplicate_button_on_edit_screen');

function mms_add_duplicate_button_on_edit_screen($post) {
    // Asegúrate de que solo se muestre en el post type 'synced_post'
    if ($post->post_type === 'synced_post') {
        $duplicate_link = wp_nonce_url(
            admin_url('admin-post.php?action=mms_duplicate_post&post_id=' . $post->ID),
            'mms_duplicate_nonce'
        );
        ?>
        <div class="misc-pub-section misc-pub-duplicate">
            <a class="button button-primary button-large" href="<?php echo esc_url($duplicate_link); ?>" title="Duplicar en este sitio">
                Duplicar <?= get_field('original_post_type_title', $post->ID) ?>
            </a>
            <p class="description">
                Esto creará una copia de <?= get_field('original_post_type_title', $post->ID) ?> en este sitio y eliminará esta entrada sincronizada.
            </p>
        </div>
        <?php
    }
}

// Mostrar el tipo de post original en la parte superior izquierda del editor
add_action('edit_form_after_title', 'mms_show_original_post_type_info');
function mms_show_original_post_type_info($post) {
    if ($post->post_type === 'synced_post') {
        $original_post_type = get_field('original_post_type_slug', $post->ID);
        if (!$original_post_type) {
            $original_post_type = 'post';
        }
        $post_type_label = get_field('original_post_type_slug', $post->ID) ? get_field('original_post_type_title', $post->ID) : 'Entrada';

        echo '<div style="margin: 24px 0 16px 0; padding: 16px; background: #f1f8ff; border-left: 6px solid #0073aa; font-size: 1.7em; font-weight: bold; color: #0073aa;">Tipo de publicación: ' . esc_html($post_type_label) . ' <span style="font-size:0.7em;color:#555;">(' . esc_html($original_post_type) . ')</span></div>';
    }
}

function duplicate_attachment($attachment_id, $target_site_id = null) {
    switch_to_blog(get_main_site_id());
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return false;
        }
        $file_path = get_attached_file($attachment_id);
    if($target_site_id) {
        switch_to_blog($target_site_id);
    }else{
        restore_current_blog();
    }

    if (!file_exists($file_path)) {
        return false;
    }
    // Copiar el archivo a la carpeta de uploads del sitio actual
    $upload_dir = wp_upload_dir();
    $filename = wp_unique_filename($upload_dir['path'], basename($file_path));
    $new_file = $upload_dir['path'] . '/' . $filename;

    if (copy($file_path, $new_file)) {
        // Crear attachment en el subsitio
        $filetype = wp_check_filetype($filename, null);
        $attachment_data = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        $new_attachment_id = wp_insert_attachment($attachment_data, $new_file);

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', dirname( __FILE__, 4 ) . '/' );
        }
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        // Para videos (mp4), asegurar que la función wp_read_video_metadata esté disponible
        if ( ! function_exists( 'wp_read_video_metadata' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
        }
        $attach_data = wp_generate_attachment_metadata($new_attachment_id, $new_file);
        wp_update_attachment_metadata($new_attachment_id, $attach_data);

        return $new_attachment_id;
    }

    return false;
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
    if (!current_user_can('edit_posts')) {
        wp_die('No tienes permisos suficientes.');
    }
    
    // Verificar el nonce de seguridad
    if (!isset($_GET['post_id']) || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mms_duplicate_nonce')) {
        wp_die('Error de seguridad.');
    }

    $synced_post_id = intval($_GET['post_id']);
    $original_post_id = get_field('original_post_id', $synced_post_id);
    
    // Obtener el contenido del post original del sitio principal
    switch_to_blog(get_main_site_id());
    $original_post = get_post($original_post_id);

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

    // Duplicar imagen destacada si existe
    $new_thumbnail_id = false;
    if (has_post_thumbnail($original_post_id)) {
        $thumbnail_id = get_post_thumbnail_id($original_post_id);
        $thumbnail = get_post($thumbnail_id);

        // Obtener la ruta del archivo en el sitio principal
        $thumbnail_path = get_attached_file($thumbnail_id);

        // Copiar el archivo a la carpeta de uploads del sitio actual
        restore_current_blog(); // Volver al subsitio antes de manipular uploads

        $upload_dir = wp_upload_dir();
        $filename = wp_unique_filename($upload_dir['path'], basename($thumbnail_path));
        $new_file = $upload_dir['path'] . '/' . $filename;

        if (copy($thumbnail_path, $new_file)) {
            // Crear attachment en el subsitio
            $filetype = wp_check_filetype($filename, null);
            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name($filename),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );
            $new_thumbnail_id = wp_insert_attachment($attachment, $new_file);

            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($new_thumbnail_id, $new_file);
            wp_update_attachment_metadata($new_thumbnail_id, $attach_data);
        }
    } else {
        restore_current_blog();
    }

    
    
    if (!$original_post) {
        wp_die('El post original no fue encontrado.');
    }
    
    // Crear la nueva entrada en el subsitio actual, duplicando también la fecha de creación y publicando
    $new_post_args = array(
        'post_title'   => $original_post->post_title,
        'post_content' => $original_post->post_content,
        'post_status'  => 'publish',
        'post_type'    => $original_post->post_type,
        'post_date'    => $original_post->post_date,
        'post_date_gmt'=> $original_post->post_date_gmt,
    );
    $new_post_id = wp_insert_post($new_post_args);

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
            switch_to_blog(get_main_site_id());
                $field_object = get_field_object($field_key, $original_post_id);
            restore_current_blog();
            
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
    
    wp_enqueue_script('mms-duplicate-script', plugin_dir_url(__FILE__) . 'js/duplicate-admin.js', array('jquery'), '1.0.0', true);
    wp_enqueue_style('mms-duplicate-style', plugin_dir_url(__FILE__) . 'css/duplicate-admin.css', array(), '1.0.0');
    
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
function mms_add_duplicate_content_action($actions, $post) {
    // Only show for published posts/pages
    if ($post->post_status !== 'publish') {
        return $actions;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_sites')) {
        return $actions;
    }
    
    // Get available sites
    $sites = get_sites(array('number' => 0));
    $current_site_id = get_current_blog_id();
    
    // Filter out current site
    $available_sites = array();
    foreach ($sites as $site) {
        if ($site->blog_id != $current_site_id) {
            $available_sites[] = $site;
        }
    }
    
    if (empty($available_sites)) {
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
    
    // Check user capabilities
    if (!current_user_can('manage_sites')) {
        return;
    }
    
    // Get available sites
    $sites = get_sites(array('number' => 0));
    $current_site_id = get_current_blog_id();
    
    // Filter out current site
    $available_sites = array();
    foreach ($sites as $site) {
        if ($site->blog_id != $current_site_id) {
            $available_sites[] = $site;
        }
    }
    
    if (empty($available_sites)) {
        return;
    }
    
    // Get all public post types
    $post_types = get_post_types(array('public' => true), 'names');
    
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
    
    // Check capabilities
    if (!current_user_can('manage_sites')) {
        wp_die('No tienes permisos para duplicar contenido.');
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
    if (!$target_site) {
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
    
    // Check capabilities
    if (!current_user_can('manage_sites')) {
        wp_die('No tienes permisos para ver sitios.');
    }
    $post_slug = isset($_POST['post_slug']) ? sanitize_text_field($_POST['post_slug']) : '';
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    
    // Get available sites
    $sites = get_sites(array('number' => 0));
    $current_site_id = get_current_blog_id();
    
    // Filter out current site and format data
    $available_sites = array();
    foreach ($sites as $site) {
        if ($site->blog_id == $current_site_id) {
            continue;
        }

        // Check if a post with the same slug and post type exists on this site
        switch_to_blog($site->blog_id);

        $args = array(
            'name'           => $post_slug,
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        );
        $query = new WP_Query($args);
        $exists = !empty($query->posts);

        restore_current_blog();

        if ($exists) {
            // If post exists, do not add this site to available_sites
            continue;
        }

        $available_sites[] = array(
            'blog_id' => $site->blog_id,
            'blogname' => get_blog_option($site->blog_id, 'blogname', $site->domain . $site->path),
            'domain' => $site->domain,
            'path' => $site->path
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

    // Copiar meta del post
    foreach ($post_meta as $meta_key => $meta_values) {
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
            switch_to_blog(get_main_site_id());
                $field_object = get_field_object($field_key, $post_id);
            switch_to_blog($target_site_id);
            
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