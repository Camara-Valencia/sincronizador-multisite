<?php
/**
 * Compatibilidad con "Offload Media - Cloud Storage" en la red.
 *
 * Todos los sitios apuntan al mismo bucket con la misma estructura y no hay archivos
 * locales, así que al duplicar un contenido la media nunca se copia: en el sitio destino
 * se crea un attachment que apunta al mismo objeto del bucket ("attachment compartido").
 * Desde la 1.3.0 tampoco hay copia física de respaldo si el objeto no está en el bucket.
 *
 * Además corrige dos problemas de Offload Media en multisite:
 *
 * 1. Borrado: Offload borra el objeto del bucket al borrar el attachment. Con objetos
 *    compartidos, borrar una copia (o el original) dejaba sin imagen al resto de sitios.
 *
 * 2. switch_to_blog(): la tabla de items es una constante (ACOOFM_ITEM_TABLE) fijada con
 *    el prefijo del sitio de la petición. Este plugin lee siempre la tabla del sitio
 *    actual (mms_offload_get_row) y no usa las funciones de Offload tras un switch.
 *
 *    1.1.1: se retira el cebado de la caché de Offload en las lecturas del front tras
 *    switch_to_blog() (1.1.0). Reescribía una transient grande del blog 1 por imagen
 *    desde cada subsitio; con las cachés purgadas y tráfico, las escrituras concurrentes
 *    sobre la misma fila bloqueaban MySQL y los subsitios daban 504.
 */

defined('ABSPATH') || exit;

const MMS_SHARED_FROM_META = '_mms_shared_from';

/**
 * Offload Media está cargado.
 */
function mms_offload_available() {
    return defined('ACOOFM_ITEM_TABLE') && function_exists('acoofm_get_cache') && function_exists('acoofm_set_cache');
}

/**
 * Meta que enlaza el attachment con su item de Offload.
 */
function mms_offload_meta_key() {
    return defined('ACOOFM_ATTACHMENT_META_KEY') ? ACOOFM_ATTACHMENT_META_KEY : 'acoofm_item_id';
}

/**
 * Tabla de items de Offload de un sitio (por defecto, el actual tras cualquier switch).
 */
function mms_offload_table($blog_id = null) {
    global $wpdb;
    return $wpdb->get_blog_prefix($blog_id) . 'acoofm_items';
}

function mms_offload_table_exists($blog_id = null) {
    global $wpdb;
    static $cache = array();

    $table = mms_offload_table($blog_id);
    if (!isset($cache[$table])) {
        $cache[$table] = mms_offload_available()
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
    return $cache[$table];
}

/**
 * Item de Offload de un attachment del sitio actual, leído de la tabla correcta.
 *
 * @return array|false
 */
function mms_offload_get_row($attachment_id) {
    global $wpdb;

    $attachment_id = (int) $attachment_id;
    if (!$attachment_id || !mms_offload_table_exists()) {
        return false;
    }

    $table   = mms_offload_table();
    $item_id = (int) get_post_meta($attachment_id, mms_offload_meta_key(), true);
    $row     = null;

    if ($item_id) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND source_id = %d", $item_id, $attachment_id), ARRAY_A);
    }
    if (!$row) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_id = %d ORDER BY id DESC LIMIT 1", $attachment_id), ARRAY_A);
    }

    return $row ?: false;
}

/* -------------------------------------------------------------------------
 * Attachments compartidos entre sitios
 * ---------------------------------------------------------------------- */

/**
 * Datos para compartir un attachment del sitio actual (llamar en el sitio de origen).
 *
 * @return array|false false si el attachment no está en el bucket
 */
function mms_offload_get_share_data(WP_Post $attachment) {
    $row = mms_offload_get_row($attachment->ID);
    if (!$row) {
        return false;
    }

    // Si ya es una copia compartida, se enlaza con el original
    $source = get_post_meta($attachment->ID, MMS_SHARED_FROM_META, true);

    return array(
        'source'        => $source ?: get_current_blog_id() . ':' . $attachment->ID,
        'row'           => $row,
        'post'          => array(
            'post_title'     => $attachment->post_title,
            'post_excerpt'   => $attachment->post_excerpt,
            'post_content'   => $attachment->post_content,
            'post_mime_type' => $attachment->post_mime_type,
        ),
        'attached_file' => get_post_meta($attachment->ID, '_wp_attached_file', true),
        'metadata'      => get_post_meta($attachment->ID, '_wp_attachment_metadata', true),
        'alt'           => get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
    );
}

/**
 * Crea (o reutiliza) en el sitio actual un attachment que apunta al mismo objeto del bucket.
 * No descarga, copia ni regenera nada.
 *
 * @return int|false
 */
function mms_offload_create_shared_attachment(array $data) {
    global $wpdb;

    if (!mms_offload_table_exists()) {
        return false;
    }

    // Reutilizar el que ya se compartió antes con este sitio
    $existing = get_posts(array(
        'post_type'        => 'attachment',
        'post_status'      => 'inherit',
        'meta_key'         => MMS_SHARED_FROM_META,
        'meta_value'       => $data['source'],
        'fields'           => 'ids',
        'numberposts'      => 1,
        'suppress_filters' => true,
    ));
    if ($existing && mms_offload_get_row($existing[0])) {
        return (int) $existing[0];
    }

    // Sin 'file': no se dispara update_attached_file ni la subida de Offload
    $new_id = wp_insert_attachment(array_merge($data['post'], array(
        'post_status' => 'inherit',
        'guid'        => $data['row']['url'],
    )), false, 0, true);

    if (is_wp_error($new_id) || !$new_id) {
        return false;
    }

    // Marca primero: si algo falla y se borra, el objeto del bucket queda protegido
    update_post_meta($new_id, MMS_SHARED_FROM_META, $data['source']);

    $row = $data['row'];
    unset($row['id']);
    $row['source_id'] = $new_id;

    if (!$wpdb->insert(mms_offload_table(), $row)) {
        wp_delete_attachment($new_id, true);
        return false;
    }

    update_post_meta($new_id, mms_offload_meta_key(), (int) $wpdb->insert_id);
    if ($data['attached_file']) {
        update_post_meta($new_id, '_wp_attached_file', $data['attached_file']);
    }
    if ($data['metadata']) {
        update_post_meta($new_id, '_wp_attachment_metadata', $data['metadata']);
    }
    if ($data['alt']) {
        update_post_meta($new_id, '_wp_attachment_image_alt', $data['alt']);
    }

    if (function_exists('acoofm_delete_cache')) {
        acoofm_delete_cache($new_id, $new_id);
    }

    return (int) $new_id;
}

/**
 * Hay attachments en otros sitios que comparten el objeto de este.
 */
function mms_offload_has_shared_copies($blog_id, $attachment_id) {
    global $wpdb;

    $source = (int) $blog_id . ':' . (int) $attachment_id;

    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        if ((int) $site_id === (int) $blog_id) {
            continue;
        }
        $postmeta = $wpdb->get_blog_prefix($site_id) . 'postmeta';
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", MMS_SHARED_FROM_META, $source))) {
            return true;
        }
    }

    return false;
}

/**
 * Antes de que Offload borre el objeto del bucket (prioridad 20): si el objeto es
 * compartido, se desenlaza el item para que Offload no lo toque. El objeto se queda
 * en el bucket aunque ya no lo use nadie: mejor un huérfano que imágenes rotas.
 */
function mms_offload_protect_shared_object($post_id) {
    global $wpdb;

    if (!mms_offload_available() || !mms_offload_table_exists()) {
        return;
    }

    $is_copy = (bool) get_post_meta($post_id, MMS_SHARED_FROM_META, true);
    if (!$is_copy && !mms_offload_has_shared_copies(get_current_blog_id(), $post_id)) {
        return;
    }

    $wpdb->delete(mms_offload_table(), array('source_id' => (int) $post_id), array('%d'));
    delete_post_meta($post_id, mms_offload_meta_key());
    if (function_exists('acoofm_delete_cache')) {
        acoofm_delete_cache($post_id, $post_id);
    }
}
add_action('delete_attachment', 'mms_offload_protect_shared_object', 5);
