<?php
/**
 * Clonado de contenidos del sitio principal en los subsitios (1.4.0).
 *
 * Sustituye a los synced_post (referencias): cada subsitio de la matriz recibe un post
 * real del mismo tipo, con contenido, extracto, meta, campos ACF, taxonomías e imagen
 * destacada. La media no se copia: se enlaza el mismo objeto del bucket
 * (duplicate_attachment / offload-compat.php).
 *
 * Funcionamiento
 * - Al publicar o actualizar en el principal solo se ENCOLAN tareas de wp-cron, una por
 *   subsitio y escalonadas (MMS_CLONE_STAGGER). Publicar no espera a los 18 sitios y los
 *   vaciados de caché de cada subsitio no coinciden en el tiempo.
 * - Cada tarea compara el estado actual del original con el del clon y lo ajusta
 *   (mms_clone_reconcile). Es idempotente: da igual cuántas veces se ejecute.
 * - Clon vinculado: lleva MMS_LOCAL_COPY_META (ID del original) y MMS_CLONE_SYNCED_META
 *   (su post_modified_gmt tras la última sincronización). Si alguien lo edita en el
 *   subsitio, post_modified_gmt cambia y el clon queda desvinculado: ya no se actualiza
 *   ni se despublica desde el principal.
 * - Original despublicado o borrado: los clones vinculados pasan a borrador. Si el
 *   original se vuelve a publicar, se republican.
 * - Un clon en la papelera del subsitio no se vuelve a crear.
 * - Quitar un sitio de la matriz no toca los clones que ya tiene.
 */

defined('ABSPATH') || exit;

const MMS_CLONE_HOOK        = 'mms_clone_reconcile';
const MMS_CLONE_FIRST_DELAY = 30;  // margen para el guardado de los metaboxes (ACF) en el editor de bloques
const MMS_CLONE_STAGGER     = 20;  // segundos entre un subsitio y el siguiente
const MMS_CLONE_SYNCED_META = '_mms_synced_modified';
const MMS_CONVERT_BATCH     = 20;

/* -------------------------------------------------------------------------
 * Consultas
 * ---------------------------------------------------------------------- */

/**
 * Clon de un original en un subsitio (incluida la papelera).
 *
 * @return array|null array( 'ID', 'post_status', 'post_modified_gmt', 'synced' )
 */
function mms_find_clone($blog_id, $original_post_id) {
    global $wpdb;

    $prefix = $wpdb->get_blog_prefix($blog_id);

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT p.ID, p.post_status, p.post_modified_gmt, s.meta_value AS synced
           FROM {$prefix}posts p
           INNER JOIN {$prefix}postmeta m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
           LEFT JOIN {$prefix}postmeta s ON s.post_id = p.ID AND s.meta_key = %s
          WHERE p.post_type NOT IN ('synced_post', 'revision', 'attachment', 'nav_menu_item')
            AND p.post_status <> 'auto-draft'
          ORDER BY p.ID ASC
          LIMIT 1",
        MMS_LOCAL_COPY_META,
        (string) (int) $original_post_id,
        MMS_CLONE_SYNCED_META
    ), ARRAY_A);

    return $row ?: null;
}

/**
 * El clon se ha editado en el subsitio después de la última sincronización.
 * Sin marca de sincronización (copias anteriores) se considera desvinculado: no se pisa.
 */
function mms_clone_is_detached(array $clone) {
    return empty($clone['synced']) || $clone['synced'] !== $clone['post_modified_gmt'];
}

/* -------------------------------------------------------------------------
 * Cola (wp-cron del sitio principal)
 * ---------------------------------------------------------------------- */

function mms_schedule_clone($post_id, $blog_id, $delay) {
    $args = array((int) $post_id, (int) $blog_id);
    // Si ya hay una pendiente para ese original y sitio, leerá el estado más reciente al ejecutarse
    if (!wp_next_scheduled(MMS_CLONE_HOOK, $args)) {
        wp_schedule_single_event(time() + (int) $delay, MMS_CLONE_HOOK, $args);
    }
}

add_action(MMS_CLONE_HOOK, function ($post_id, $blog_id) {
    if (defined('MMS_DISABLE_SYNC') && MMS_DISABLE_SYNC) {
        return;
    }
    $result = mms_clone_reconcile($post_id, $blog_id);
    if (is_wp_error($result)) {
        error_log('[MMS] Clonado de ' . $post_id . ' en el sitio ' . $blog_id . ': ' . $result->get_error_message());
    }
}, 10, 2);

// Publicado o actualizado en el principal. wp_after_insert_post: ya con términos y meta guardados.
add_action('wp_after_insert_post', function ($post_id, $post) {
    // Interruptor de emergencia: define( 'MMS_DISABLE_SYNC', true ); en wp-config.php
    if (defined('MMS_DISABLE_SYNC') && MMS_DISABLE_SYNC) {
        return;
    }
    if (!is_main_site() || !$post instanceof WP_Post || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
        return;
    }
    // Solo contenidos publicados (los borradores de la IA no salen del principal)
    if ($post->post_status !== 'publish') {
        return;
    }

    $valid = array_map('intval', wp_list_pluck(mms_get_sync_subsites(), 'blog_id'));
    $delay = MMS_CLONE_FIRST_DELAY;
    foreach (mms_get_sync_target_sites($post->post_type) as $blog_id) {
        if (in_array($blog_id, $valid, true)) {
            mms_schedule_clone($post_id, $blog_id, $delay);
            $delay += MMS_CLONE_STAGGER;
        }
    }
}, 20, 2);

/**
 * Encola el ajuste de los subsitios que tienen clon de un original (para despublicarlos).
 */
function mms_schedule_clone_unpublish($post_id) {
    $delay = 0;
    foreach (mms_get_sync_subsites() as $site) {
        if (mms_find_clone($site->blog_id, $post_id)) {
            mms_schedule_clone($post_id, $site->blog_id, $delay);
            $delay += MMS_CLONE_STAGGER;
        }
    }
}

// Publicado → borrador, pendiente, privado, programado o papelera
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($old_status === 'publish' && $new_status !== 'publish' && mms_post_may_be_synced($post)) {
        mms_schedule_clone_unpublish($post->ID);
    }
}, 10, 3);

// Borrado definitivo de un contenido publicado, sin pasar por la papelera
add_action('before_delete_post', function ($post_id, $post = null) {
    $post = $post ?: get_post($post_id);
    if ($post && $post->post_status === 'publish' && mms_post_may_be_synced($post)) {
        mms_schedule_clone_unpublish($post->ID);
    }
}, 10, 2);

/* -------------------------------------------------------------------------
 * Ajuste de un clon
 * ---------------------------------------------------------------------- */

/**
 * Deja el clon de un original en un subsitio como debe estar.
 *
 * @param bool $ignore_matrix Clonar aunque el sitio no esté en la matriz (conversión de synced_post).
 * @return string|WP_Error created | updated | drafted | detached | trashed | local | none
 */
function mms_clone_reconcile($post_id, $blog_id, $ignore_matrix = false) {
    $post_id = (int) $post_id;
    $blog_id = (int) $blog_id;

    $valid = array_map('intval', wp_list_pluck(mms_get_sync_subsites(), 'blog_id'));
    if (!in_array($blog_id, $valid, true)) {
        return 'none';
    }

    $original  = get_blog_post(get_main_site_id(), $post_id);
    $published = $original && $original->post_status === 'publish';
    $clone     = mms_find_clone($blog_id, $post_id);

    if (!$published) {
        // Original despublicado o borrado: el clon vinculado pasa a borrador
        if ($clone && $clone['post_status'] === 'publish' && !mms_clone_is_detached($clone)) {
            switch_to_blog($blog_id);
            wp_update_post(array('ID' => (int) $clone['ID'], 'post_status' => 'draft'));
            mms_clone_mark_synced((int) $clone['ID']);
            restore_current_blog();
            return 'drafted';
        }
        return 'none';
    }

    if (!$ignore_matrix && !in_array($blog_id, mms_get_sync_target_sites($original->post_type), true)) {
        return 'none';
    }

    if ($clone) {
        if ($clone['post_status'] === 'trash') {
            return 'trashed';
        }
        if (mms_clone_is_detached($clone)) {
            return 'detached';
        }
        $result = mms_clone_post_to_site($post_id, $blog_id, (int) $clone['ID']);
        return is_wp_error($result) ? $result : 'updated';
    }

    // Copia propia sin marca (anterior a la 1.3.0 o creada a mano con el mismo tipo y slug): no se duplica
    if (mms_find_local_copy($blog_id, $post_id, $original->post_type, $original->post_name)) {
        return 'local';
    }

    $result = mms_clone_post_to_site($post_id, $blog_id);
    return is_wp_error($result) ? $result : 'created';
}

/**
 * Guarda en el clon (sitio actual) su post_modified_gmt como "sincronizado".
 */
function mms_clone_mark_synced($clone_id) {
    clean_post_cache($clone_id);
    update_post_meta($clone_id, MMS_CLONE_SYNCED_META, get_post_field('post_modified_gmt', $clone_id, 'raw'));
}

/**
 * Crea o actualiza en un subsitio el clon de un contenido del principal.
 * Se puede llamar desde cualquier sitio: lee en el principal y escribe en el destino.
 *
 * @param int $clone_id Clon existente que se actualiza (0 = crear).
 * @return int|WP_Error ID del clon
 */
function mms_clone_post_to_site($post_id, $target_site_id, $clone_id = 0) {
    $post_id        = (int) $post_id;
    $target_site_id = (int) $target_site_id;
    $clone_id       = (int) $clone_id;

    /* --- Lectura en el principal --- */
    switch_to_blog(get_main_site_id());

    $post = get_post($post_id);
    if (!$post) {
        restore_current_blog();
        return new WP_Error('post_not_found', 'Post no encontrado.');
    }

    $post_meta    = get_post_meta($post_id);
    $thumbnail_id = (int) get_post_thumbnail_id($post_id);

    // Todas las taxonomías del tipo, también las vacías: al actualizar hay que quitar términos
    $terms_by_tax = array();
    foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
        $terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
        $terms_by_tax[$taxonomy] = is_wp_error($terms) ? array() : $terms;
    }

    $acf_fields        = array();
    $acf_field_objects = array();
    if (function_exists('get_fields')) {
        $acf_fields = get_fields($post_id) ?: array();
        if ($acf_fields && function_exists('get_field_object')) {
            foreach ($acf_fields as $field_key => $field_value) {
                $acf_field_objects[$field_key] = get_field_object($field_key, $post_id);
            }
        }
    }

    restore_current_blog();

    /* --- Escritura en el destino --- */
    switch_to_blog($target_site_id);

    // El contenido viene del principal, de confianza. En cron (sin usuario) y para los
    // administradores de un multisite kses filtra el HTML y quitaba iframes, embeds, etc.
    $kses = has_filter('content_save_pre', 'wp_filter_post_kses');
    if ($kses) {
        kses_remove_filters();
    }

    $data = array(
        // wp_slash(): wp_insert_post() quita barras y rompía los \u00xx del JSON de los bloques
        'post_title'     => wp_slash($post->post_title),
        // Los IDs de media del contenido pasan a los attachments compartidos de este sitio
        'post_content'   => wp_slash(mms_duplicate_content_media($post->post_content)),
        'post_excerpt'   => wp_slash($post->post_excerpt),
        'post_name'      => $post->post_name,
        'post_status'    => 'publish',
        'post_type'      => $post->post_type,
        'post_author'    => (int) $post->post_author,
        'post_date'      => $post->post_date,
        'post_date_gmt'  => $post->post_date_gmt,
        'comment_status' => $post->comment_status,
        'ping_status'    => $post->ping_status,
        'menu_order'     => $post->menu_order,
        'post_mime_type' => $post->post_mime_type,
    );

    if ($clone_id) {
        $data['ID'] = $clone_id;
        $new_post_id = wp_update_post($data, true);
    } else {
        $new_post_id = wp_insert_post($data, true);
    }

    if ($kses) {
        kses_init_filters();
    }

    if (is_wp_error($new_post_id) || !$new_post_id) {
        restore_current_blog();
        return is_wp_error($new_post_id) ? $new_post_id : new WP_Error('insert_failed', 'No se pudo crear el contenido.');
    }

    // Meta, salvo la que solo tiene sentido en el origen (IDs de otro sitio, bloqueos, tic-sync, marcas del plugin)
    $skip_meta = array(
        '_thumbnail_id', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
        '_tic_remote_id', '_tic_sync_pending', MMS_LOCAL_COPY_META, MMS_CLONE_SYNCED_META,
    );
    foreach ($post_meta as $meta_key => $meta_values) {
        if (in_array($meta_key, $skip_meta, true)) {
            continue;
        }
        if ($clone_id) {
            delete_post_meta($new_post_id, $meta_key);
        }
        foreach ($meta_values as $meta_value) {
            // wp_slash(): add_post_meta() quita barras (rompía JSON y rutas guardadas en meta)
            add_post_meta($new_post_id, $meta_key, wp_slash(maybe_unserialize($meta_value)));
        }
    }

    // Imagen destacada: attachment compartido del bucket
    $new_thumbnail_id = $thumbnail_id ? duplicate_attachment($thumbnail_id) : false;
    if ($new_thumbnail_id) {
        set_post_thumbnail($new_post_id, $new_thumbnail_id);
    } elseif ($clone_id) {
        delete_post_thumbnail($new_post_id);
    }

    // Taxonomías por slug (si no existe el término en el subsitio, se crea)
    foreach ($terms_by_tax as $taxonomy => $terms) {
        if (taxonomy_exists($taxonomy)) {
            wp_set_object_terms($new_post_id, $terms, $taxonomy, false);
        }
    }

    // Campos ACF, con la media enlazada al bucket
    if ($acf_fields && function_exists('update_field')) {
        foreach ($acf_fields as $field_key => $field_value) {
            $field_object = isset($acf_field_objects[$field_key]) ? $acf_field_objects[$field_key] : false;
            $new_value    = $field_object ? mms_duplicate_acf_media_recursive($field_value, $field_object) : $field_value;
            update_field($field_key, $new_value, $new_post_id);
        }
    }

    // Marcas: vínculo con el original y estado de la última sincronización
    update_post_meta($new_post_id, MMS_LOCAL_COPY_META, $post_id);
    mms_clone_mark_synced($new_post_id);

    restore_current_blog();

    // El synced_post de este original en ese sitio ya sobra: ahora tiene el contenido real
    mms_delete_synced_posts($target_site_id, mms_find_synced_posts_for_original($target_site_id, $post_id));

    return (int) $new_post_id;
}

/* -------------------------------------------------------------------------
 * Conversión de los synced_post existentes en clones
 * ---------------------------------------------------------------------- */

/**
 * synced_post convertibles de un subsitio: original publicado, sin copia propia y sin
 * duplicados (los demás son cosa de la limpieza).
 *
 * @return array<int, int> synced_post ID => original ID
 */
function mms_find_convertible_synced_posts($blog_id) {
    $analysis = mms_analyze_synced_posts($blog_id);
    return array_flip($analysis['kept']);
}

/**
 * Pendientes de convertir por subsitio: blog_id => n.
 */
function mms_convert_report() {
    $report = array();
    foreach (mms_get_sync_subsites() as $site) {
        $report[(int) $site->blog_id] = count(mms_find_convertible_synced_posts($site->blog_id));
    }
    return $report;
}

/**
 * Convierte hasta $limit synced_post en clones, sitio a sitio.
 *
 * @return array( 'convertidos' => n, 'errores' => n, 'pendientes' => n )
 */
function mms_convert_run($limit = MMS_CONVERT_BATCH) {
    $converted = 0;
    $errors    = 0;
    $pending   = 0;

    foreach (mms_get_sync_subsites() as $site) {
        $blog_id = (int) $site->blog_id;
        $items   = mms_find_convertible_synced_posts($blog_id);

        foreach ($items as $synced_id => $original_id) {
            if ($converted + $errors >= $limit) {
                $pending++;
                continue;
            }

            $result = mms_clone_reconcile($original_id, $blog_id, true);

            if (is_wp_error($result)) {
                $errors++;
                error_log('[MMS] Conversión del synced_post ' . $synced_id . ' (sitio ' . $blog_id . '): ' . $result->get_error_message());
                continue;
            }

            // created/updated ya borran el synced_post; con el clon en la papelera también sobra
            mms_delete_synced_posts($blog_id, array($synced_id));
            $converted++;
        }
    }

    return array('convertidos' => $converted, 'errores' => $errors, 'pendientes' => $pending);
}

add_action('network_admin_edit_mms_convert_synced', function () {
    if (!current_user_can('manage_network_options')) {
        wp_die('No tienes permisos para hacer esto.');
    }
    check_admin_referer('mms_convert_synced');

    $result = mms_convert_run(MMS_CONVERT_BATCH);

    wp_safe_redirect(add_query_arg(
        array(
            'page'            => MMS_SYNC_SETTINGS_PAGE,
            'mms_convertir'   => 1,
            'mms_convertidos' => $result['convertidos'],
            'mms_errores'     => $result['errores'],
        ),
        network_admin_url('settings.php')
    ) . '#mms-conversion');
    exit;
});

/**
 * Sección de conversión en la página de la matriz (Red → Ajustes → Sincronización).
 */
function mms_render_convert_section() {
    $analyze = !empty($_GET['mms_convertir']);
    $url     = add_query_arg(array('page' => MMS_SYNC_SETTINGS_PAGE, 'mms_convertir' => 1), network_admin_url('settings.php')) . '#mms-conversion';
    ?>
    <hr>
    <h2 id="mms-conversion">Convertir posts sincronizados en clones</h2>
    <p>
        Sustituye cada <code>synced_post</code> (referencia al principal) por un clon real del contenido
        en su subsitio y borra la referencia. Solo los que tienen el original publicado; los demás se
        quitan con la limpieza de abajo. Cada conversión crea un contenido completo y vacía la caché del
        subsitio: mejor en horas de poco tráfico, o con <code>wp mms convertir-sincronizados</code>.
    </p>

    <?php if (isset($_GET['mms_convertidos'])) : ?>
        <div class="notice notice-success inline"><p>
            <?php echo (int) $_GET['mms_convertidos']; ?> convertidos.
            <?php if (!empty($_GET['mms_errores'])) : ?>
                <?php echo (int) $_GET['mms_errores']; ?> con error (ver el log de PHP).
            <?php endif; ?>
        </p></div>
    <?php endif; ?>

    <?php if (!$analyze) : ?>
        <p><a class="button" href="<?php echo esc_url($url); ?>">Analizar</a></p>
        <?php
        return;
    endif;

    $report = mms_convert_report();
    $total  = array_sum($report);
    ?>
    <table class="widefat striped" style="max-width: 600px;">
        <thead><tr><th>Subsitio</th><th style="text-align:right;">Por convertir</th></tr></thead>
        <tbody>
            <?php foreach ($report as $blog_id => $count) : ?>
                <tr>
                    <td><?php echo esc_html(get_blog_option($blog_id, 'blogname')); ?></td>
                    <td style="text-align:right;"><?php echo (int) $count; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total > 0) : ?>
        <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=mms_convert_synced')); ?>" style="margin-top: 1em;">
            <?php wp_nonce_field('mms_convert_synced'); ?>
            <?php submit_button(
                sprintf('Convertir %d (hasta %d por clic)', $total, MMS_CONVERT_BATCH),
                'primary',
                'submit',
                false,
                array('onclick' => "return confirm('Se crearán los clones y se borrarán sus posts sincronizados. ¿Continuar?');")
            ); ?>
        </form>
    <?php else : ?>
        <p><strong>No hay posts sincronizados que convertir.</strong></p>
    <?php endif; ?>
    <?php
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp mms convertir-sincronizados [--dry-run] [--lote=<n>]
 *         wp mms clonar <post_id> [--sitio=<blog_id>]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mms convertir-sincronizados', function ($args, $assoc_args) {
        $items = array();
        foreach (mms_convert_report() as $blog_id => $count) {
            $items[] = array('sitio' => get_blog_option($blog_id, 'blogname') . " ({$blog_id})", 'por_convertir' => $count);
        }
        WP_CLI\Utils\format_items('table', $items, array('sitio', 'por_convertir'));

        if (!empty($assoc_args['dry-run'])) {
            return;
        }

        $batch = isset($assoc_args['lote']) ? max(1, (int) $assoc_args['lote']) : 50;
        $total = 0;
        $errs  = 0;
        do {
            $result = mms_convert_run($batch);
            $total += $result['convertidos'];
            $errs  += $result['errores'];
            WP_CLI::log("Convertidos {$total}; errores {$errs}; pendientes {$result['pendientes']}");
        } while ($result['convertidos'] > 0 && $result['pendientes'] > 0);

        WP_CLI::success("{$total} posts sincronizados convertidos en clones.");
    });

    WP_CLI::add_command('mms clonar', function ($args, $assoc_args) {
        $post_id = (int) $args[0];
        $sites   = isset($assoc_args['sitio'])
            ? array((int) $assoc_args['sitio'])
            : array_map('intval', wp_list_pluck(mms_get_sync_subsites(), 'blog_id'));

        foreach ($sites as $blog_id) {
            $result = mms_clone_reconcile($post_id, $blog_id);
            WP_CLI::log("Sitio {$blog_id}: " . (is_wp_error($result) ? 'error: ' . $result->get_error_message() : $result));
        }
    });
}
