<?php
/**
 * Mantenimiento de los synced_post de los subsitios.
 *
 * 1. Desincronización: cuando un contenido del sitio principal deja de estar publicado
 *    (borrador, pendiente, privado, papelera) o se borra, se eliminan sus synced_post
 *    de todos los subsitios. Antes se quedaban para siempre y las páginas que los
 *    recorren eran cada vez más lentas.
 *
 * 2. Limpieza de huérfanos (Red → Ajustes → Sincronización, o `wp mms limpiar-sincronizados`):
 *    borra los synced_post que no tienen original, cuyo original ya no existe o no está
 *    publicado (p. ej. los creados antes de la 1.1.0 por los borradores de la IA), y los
 *    duplicados del mismo original en un mismo sitio.
 *
 * No se tocan los synced_post en borrador o papelera cuyo original sigue publicado:
 * puede que alguien los haya ocultado a propósito en ese subsitio.
 */

defined('ABSPATH') || exit;

const MMS_CLEANUP_BATCH = 200;

/* -------------------------------------------------------------------------
 * Consultas
 * ---------------------------------------------------------------------- */

/**
 * IDs de los synced_post de un subsitio que apuntan a un original.
 * Consulta directa: no hace falta switch_to_blog() para saber si hay algo que borrar.
 */
function mms_find_synced_posts_for_original($blog_id, $original_post_id) {
    global $wpdb;

    $prefix = $wpdb->get_blog_prefix($blog_id);

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID
           FROM {$prefix}posts p
           INNER JOIN {$prefix}postmeta m ON m.post_id = p.ID AND m.meta_key = 'original_post_id'
          WHERE p.post_type = 'synced_post' AND m.meta_value = %s",
        (string) (int) $original_post_id
    )));
}

/**
 * synced_post sobrantes de un subsitio, con el motivo.
 *
 * @return array<int, string> ID del synced_post => motivo
 */
function mms_find_orphan_synced_posts($blog_id) {
    global $wpdb;

    $prefix      = $wpdb->get_blog_prefix($blog_id);
    $main_prefix = $wpdb->get_blog_prefix(get_main_site_id());

    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_status, m.meta_value AS original_id, o.ID AS o_id, o.post_status AS o_status, o.post_type AS o_type
           FROM {$prefix}posts p
           LEFT JOIN {$prefix}postmeta m ON m.post_id = p.ID AND m.meta_key = 'original_post_id'
           LEFT JOIN {$main_prefix}posts o ON o.ID = CAST(m.meta_value AS UNSIGNED)
          WHERE p.post_type = 'synced_post'
          ORDER BY p.ID ASC",
        ARRAY_A
    );

    $orphans = array();
    $kept    = array(); // original_id => synced_post que se conserva

    foreach ($rows as $row) {
        $id = (int) $row['ID'];
        if (isset($orphans[$id]) || in_array($id, $kept, true)) {
            continue; // meta duplicada: la misma fila puede salir dos veces
        }

        $original_id = (int) $row['original_id'];

        if (!$original_id) {
            $orphans[$id] = 'sin_original';
        } elseif (!$row['o_id'] || in_array($row['o_type'], array('revision', 'attachment', 'synced_post'), true)) {
            $orphans[$id] = 'original_borrado';
        } elseif ($row['o_status'] !== 'publish') {
            $orphans[$id] = 'original_no_publicado';
        } elseif (isset($kept[$original_id])) {
            $orphans[$id] = 'duplicado';
        } else {
            // Se conserva el primero (el más antiguo) de cada original
            $kept[$original_id] = $id;
        }
    }

    return $orphans;
}

/**
 * Borra synced_post de un subsitio. Un solo switch para todo el lote.
 *
 * @return int borrados
 */
function mms_delete_synced_posts($blog_id, array $ids) {
    if (!$ids) {
        return 0;
    }

    $deleted  = 0;
    $switched = (int) $blog_id !== get_current_blog_id();
    if ($switched) {
        switch_to_blog($blog_id);
    }

    wp_defer_term_counting(true);
    foreach ($ids as $id) {
        $post = get_post($id);
        if ($post && $post->post_type === 'synced_post' && wp_delete_post($id, true)) {
            $deleted++;
        }
    }
    wp_defer_term_counting(false);

    if ($switched) {
        restore_current_blog();
    }

    return $deleted;
}

/* -------------------------------------------------------------------------
 * Desincronización automática
 * ---------------------------------------------------------------------- */

/**
 * Borra de todos los subsitios los synced_post de un original.
 * Se recorren todos los subsitios, no solo los de la matriz: la matriz puede haber cambiado.
 */
function mms_unsync_post($post_id) {
    $post_id = (int) $post_id;
    if (!$post_id) {
        return;
    }

    foreach (mms_get_sync_subsites() as $site) {
        $ids = mms_find_synced_posts_for_original($site->blog_id, $post_id);
        if ($ids) {
            mms_delete_synced_posts($site->blog_id, $ids);
        }
    }
}

/**
 * ¿Puede tener synced_post este contenido del principal?
 */
function mms_post_may_be_synced($post) {
    if (!$post instanceof WP_Post || !is_main_site() || wp_is_post_revision($post)) {
        return false;
    }
    if (in_array($post->post_type, mms_sync_excluded_post_types(), true) || $post->post_type === 'nav_menu_item') {
        return false;
    }
    $post_type = get_post_type_object($post->post_type);
    return $post_type && $post_type->public;
}

// Publicado → borrador, pendiente, privado, programado o papelera
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($old_status === 'publish' && $new_status !== 'publish' && mms_post_may_be_synced($post)) {
        mms_unsync_post($post->ID);
    }
}, 10, 3);

// Borrado definitivo de un contenido publicado, sin pasar por la papelera
add_action('before_delete_post', function ($post_id, $post = null) {
    $post = $post ?: get_post($post_id);
    if ($post && $post->post_status === 'publish' && mms_post_may_be_synced($post)) {
        mms_unsync_post($post->ID);
    }
}, 10, 2);

/* -------------------------------------------------------------------------
 * Limpieza de huérfanos: administración de la red
 * ---------------------------------------------------------------------- */

function mms_cleanup_reason_labels() {
    return array(
        'sin_original'          => 'Sin original',
        'original_borrado'      => 'Original borrado',
        'original_no_publicado' => 'Original no publicado',
        'duplicado'             => 'Duplicado',
    );
}

/**
 * Resumen por subsitio: blog_id => array( 'total' => n, 'motivos' => array( motivo => n ) ).
 */
function mms_cleanup_report() {
    $report = array();

    foreach (mms_get_sync_subsites() as $site) {
        $orphans = mms_find_orphan_synced_posts($site->blog_id);
        $report[(int) $site->blog_id] = array(
            'total'   => count($orphans),
            'motivos' => array_count_values($orphans),
        );
    }

    return $report;
}

/**
 * Borra hasta $limit huérfanos en total, sitio a sitio.
 *
 * @return array( 'borrados' => n, 'pendientes' => n )
 */
function mms_cleanup_run($limit = MMS_CLEANUP_BATCH) {
    $deleted = 0;
    $pending = 0;

    foreach (mms_get_sync_subsites() as $site) {
        $ids = array_keys(mms_find_orphan_synced_posts($site->blog_id));

        $room = max(0, $limit - $deleted);
        if ($room) {
            $deleted += mms_delete_synced_posts($site->blog_id, array_slice($ids, 0, $room));
        }
        $pending += max(0, count($ids) - $room);
    }

    return array('borrados' => $deleted, 'pendientes' => $pending);
}

add_action('network_admin_edit_mms_cleanup_synced', 'mms_handle_cleanup_request');

function mms_handle_cleanup_request() {
    if (!current_user_can('manage_network_options')) {
        wp_die('No tienes permisos para hacer esto.');
    }
    check_admin_referer('mms_cleanup_synced');

    // Por lotes: cada borrado dispara los hooks de WordPress y del tema
    $result = mms_cleanup_run(MMS_CLEANUP_BATCH);

    wp_safe_redirect(add_query_arg(
        array(
            'page'         => MMS_SYNC_SETTINGS_PAGE,
            'mms_analizar' => 1,
            'mms_borrados' => $result['borrados'],
        ),
        network_admin_url('settings.php')
    ) . '#mms-limpieza');
    exit;
}

/**
 * Sección de limpieza en la página de la matriz (Red → Ajustes → Sincronización).
 */
function mms_render_cleanup_section() {
    $labels  = mms_cleanup_reason_labels();
    $analyze = !empty($_GET['mms_analizar']);
    $url     = add_query_arg(array('page' => MMS_SYNC_SETTINGS_PAGE, 'mms_analizar' => 1), network_admin_url('settings.php')) . '#mms-limpieza';
    ?>
    <hr>
    <h2 id="mms-limpieza">Limpieza de posts sincronizados</h2>
    <p>
        Busca en los subsitios los <code>synced_post</code> que sobran: sin original, con el original
        borrado o sin publicar (p. ej. borradores de la IA) y duplicados del mismo original.
        Los que están en borrador o en la papelera con el original publicado no se tocan.
    </p>

    <?php if (isset($_GET['mms_borrados'])) : ?>
        <div class="notice notice-success inline"><p><?php echo (int) $_GET['mms_borrados']; ?> posts sincronizados borrados.</p></div>
    <?php endif; ?>

    <?php if (!$analyze) : ?>
        <p><a class="button" href="<?php echo esc_url($url); ?>">Analizar</a></p>
        <?php
        return;
    endif;

    $report = mms_cleanup_report();
    $total  = array_sum(wp_list_pluck($report, 'total'));
    ?>
    <table class="widefat striped" style="max-width: 900px;">
        <thead>
            <tr>
                <th>Subsitio</th>
                <?php foreach ($labels as $label) : ?>
                    <th style="text-align:right;"><?php echo esc_html($label); ?></th>
                <?php endforeach; ?>
                <th style="text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report as $blog_id => $data) : ?>
                <tr>
                    <td><?php echo esc_html(get_blog_option($blog_id, 'blogname')); ?></td>
                    <?php foreach (array_keys($labels) as $reason) : ?>
                        <td style="text-align:right;"><?php echo isset($data['motivos'][$reason]) ? (int) $data['motivos'][$reason] : 0; ?></td>
                    <?php endforeach; ?>
                    <td style="text-align:right;"><strong><?php echo (int) $data['total']; ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total > 0) : ?>
        <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=mms_cleanup_synced')); ?>" style="margin-top: 1em;">
            <?php wp_nonce_field('mms_cleanup_synced'); ?>
            <?php submit_button(
                sprintf('Borrar %d (hasta %d por clic)', $total, MMS_CLEANUP_BATCH),
                'delete',
                'submit',
                false,
                array('onclick' => "return confirm('Se borrarán definitivamente estos posts sincronizados. ¿Continuar?');")
            ); ?>
        </form>
    <?php else : ?>
        <p><strong>No hay posts sincronizados que limpiar.</strong></p>
    <?php endif; ?>
    <?php
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp mms limpiar-sincronizados [--dry-run] [--lote=<n>]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mms limpiar-sincronizados', function ($args, $assoc_args) {
        $labels = mms_cleanup_reason_labels();
        $report = mms_cleanup_report();

        $items = array();
        foreach ($report as $blog_id => $data) {
            $item = array('sitio' => get_blog_option($blog_id, 'blogname') . " ({$blog_id})");
            foreach ($labels as $reason => $label) {
                $item[$label] = isset($data['motivos'][$reason]) ? $data['motivos'][$reason] : 0;
            }
            $item['total'] = $data['total'];
            $items[]       = $item;
        }
        WP_CLI\Utils\format_items('table', $items, array_merge(array('sitio'), array_values($labels), array('total')));

        if (!empty($assoc_args['dry-run'])) {
            return;
        }

        $batch = isset($assoc_args['lote']) ? max(1, (int) $assoc_args['lote']) : 500;
        $total = 0;
        do {
            $result = mms_cleanup_run($batch);
            $total += $result['borrados'];
            WP_CLI::log("Borrados {$total}; pendientes {$result['pendientes']}");
        } while ($result['borrados'] > 0 && $result['pendientes'] > 0);

        WP_CLI::success("{$total} posts sincronizados borrados.");
    });
}
