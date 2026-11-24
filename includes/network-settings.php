<?php
/**
 * Configuración de red: qué tipos de contenido del sitio principal se sincronizan
 * con qué subsitios.
 *
 * Solo la pueden ver y modificar los superadministradores (Administración de la red →
 * Ajustes → Sincronización). Por defecto no se sincroniza nada: un tipo de contenido
 * solo llega a un subsitio si su casilla está marcada en la matriz.
 *
 * Se guarda en la opción de red 'mms_sync_matrix' con la forma:
 *   array( <blog_id> => array( '<post_type>', ... ), ... )
 */

defined('ABSPATH') || exit;

const MMS_SYNC_MATRIX_OPTION = 'mms_sync_matrix';
const MMS_SYNC_SETTINGS_PAGE = 'mms-sync-matrix';

/**
 * Post types que nunca aparecen en la matriz.
 */
function mms_sync_excluded_post_types() {
    return array('attachment', 'synced_post', 'auditoria-de-articul');
}

/**
 * Matriz guardada: blog_id => lista de post types.
 */
function mms_get_sync_matrix() {
    $matrix = get_site_option(MMS_SYNC_MATRIX_OPTION, array());
    return is_array($matrix) ? $matrix : array();
}

/**
 * IDs de los subsitios a los que hay que sincronizar un post type.
 */
function mms_get_sync_target_sites($post_type) {
    $targets = array();

    foreach (mms_get_sync_matrix() as $blog_id => $post_types) {
        if (is_array($post_types) && in_array($post_type, $post_types, true)) {
            $targets[] = (int) $blog_id;
        }
    }

    return $targets;
}

/**
 * Subsitios de la red, sin el sitio principal (que es el origen).
 */
function mms_get_sync_subsites() {
    $main_site_id = get_main_site_id();

    return array_values(array_filter(
        get_sites(array('number' => 0, 'deleted' => 0, 'archived' => 0)),
        function ($site) use ($main_site_id) {
            return (int) $site->blog_id !== (int) $main_site_id;
        }
    ));
}

/**
 * Post types públicos del sitio principal que se pueden sincronizar.
 *
 * La administración de la red se ejecuta en el contexto del sitio principal, así que
 * aquí están registrados los CPT de su tema.
 *
 * @return WP_Post_Type[] slug => objeto
 */
function mms_get_syncable_post_types() {
    $post_types = get_post_types(array('public' => true), 'objects');

    foreach (mms_sync_excluded_post_types() as $excluded) {
        unset($post_types[$excluded]);
    }

    uasort($post_types, function ($a, $b) {
        return strcasecmp($a->labels->name, $b->labels->name);
    });

    return $post_types;
}

add_action('network_admin_menu', 'mms_register_network_settings_page');

function mms_register_network_settings_page() {
    add_submenu_page(
        'settings.php',
        'Sincronización de contenidos',
        'Sincronización',
        'manage_network_options',
        MMS_SYNC_SETTINGS_PAGE,
        'mms_render_network_settings_page'
    );
}

add_action('network_admin_edit_mms_save_sync_matrix', 'mms_save_network_settings');

function mms_save_network_settings() {
    if (!current_user_can('manage_network_options')) {
        wp_die('No tienes permisos para modificar esta configuración.');
    }

    check_admin_referer('mms_save_sync_matrix');

    $valid_sites = array_map(function ($site) {
        return (int) $site->blog_id;
    }, mms_get_sync_subsites());
    $valid_post_types = array_keys(mms_get_syncable_post_types());

    $submitted = isset($_POST['mms_matrix']) && is_array($_POST['mms_matrix']) ? wp_unslash($_POST['mms_matrix']) : array();
    $matrix = array();

    foreach ($submitted as $blog_id => $post_types) {
        $blog_id = (int) $blog_id;
        if (!in_array($blog_id, $valid_sites, true) || !is_array($post_types)) {
            continue;
        }

        $post_types = array_values(array_intersect(array_map('sanitize_key', $post_types), $valid_post_types));
        if ($post_types) {
            $matrix[$blog_id] = $post_types;
        }
    }

    update_site_option(MMS_SYNC_MATRIX_OPTION, $matrix);

    wp_safe_redirect(add_query_arg(
        array('page' => MMS_SYNC_SETTINGS_PAGE, 'updated' => 'true'),
        network_admin_url('settings.php')
    ));
    exit;
}

function mms_render_network_settings_page() {
    if (!current_user_can('manage_network_options')) {
        wp_die('No tienes permisos para ver esta página.');
    }

    $sites = mms_get_sync_subsites();
    $post_types = mms_get_syncable_post_types();
    $matrix = mms_get_sync_matrix();
    ?>
    <div class="wrap mms-sync-matrix">
        <h1>Sincronización de contenidos</h1>

        <?php if (isset($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
        <?php endif; ?>

        <p>
            Marca qué tipos de contenido del sitio principal
            (<strong><?php echo esc_html(get_blog_option(get_main_site_id(), 'blogname')); ?></strong>)
            se sincronizan con cada subsitio. <strong>Sólo lo que esté marcado se sincroniza.</strong>
        </p>
        <p class="description">
            El cambio se aplica a los contenidos que se guarden a partir de ahora. Los posts sincronizados
            que ya existen en los subsitios no se crean ni se borran al cambiar esta tabla.
        </p>

        <?php if (empty($sites)) : ?>
            <p>No hay subsitios en la red.</p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=mms_save_sync_matrix')); ?>">
                <?php wp_nonce_field('mms_save_sync_matrix'); ?>

                <div class="mms-grid-wrap">
                    <table class="widefat striped mms-grid">
                        <thead>
                            <tr>
                                <th class="mms-row-head">Tipo de contenido</th>
                                <?php foreach ($sites as $site) : ?>
                                    <th class="mms-col">
                                        <span class="mms-site-name"><?php echo esc_html(get_blog_option($site->blog_id, 'blogname')); ?></span>
                                        <span class="mms-site-url"><?php echo esc_html($site->domain . $site->path); ?></span>
                                        <label class="mms-toggle">
                                            <input type="checkbox" class="mms-toggle-col" data-site="<?php echo (int) $site->blog_id; ?>"> Todos
                                        </label>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($post_types as $slug => $post_type) : ?>
                                <tr>
                                    <th scope="row" class="mms-row-head">
                                        <span class="mms-pt-name"><?php echo esc_html($post_type->labels->name); ?></span>
                                        <code><?php echo esc_html($slug); ?></code>
                                        <label class="mms-toggle">
                                            <input type="checkbox" class="mms-toggle-row" data-post-type="<?php echo esc_attr($slug); ?>"> Todos
                                        </label>
                                    </th>
                                    <?php foreach ($sites as $site) :
                                        $blog_id = (int) $site->blog_id;
                                        $checked = isset($matrix[$blog_id]) && in_array($slug, (array) $matrix[$blog_id], true);
                                        ?>
                                        <td class="mms-col">
                                            <input type="checkbox"
                                                class="mms-cell"
                                                name="mms_matrix[<?php echo $blog_id; ?>][]"
                                                value="<?php echo esc_attr($slug); ?>"
                                                data-site="<?php echo $blog_id; ?>"
                                                data-post-type="<?php echo esc_attr($slug); ?>"
                                                aria-label="<?php echo esc_attr($post_type->labels->name . ' → ' . get_blog_option($blog_id, 'blogname')); ?>"
                                                <?php checked($checked); ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php submit_button('Guardar cambios'); ?>
            </form>
        <?php endif; ?>

        <?php if (function_exists('mms_render_cleanup_section')) { mms_render_cleanup_section(); } ?>
    </div>

    <style>
        .mms-grid-wrap { overflow-x: auto; max-width: 100%; }
        .mms-grid { width: auto; min-width: 100%; }
        .mms-grid th, .mms-grid td { vertical-align: middle; }
        .mms-grid .mms-col { text-align: center; min-width: 120px; }
        .mms-grid thead .mms-col { vertical-align: bottom; }
        .mms-grid .mms-row-head { position: sticky; left: 0; background: #fff; z-index: 1; min-width: 220px; text-align: left; }
        .mms-grid tbody tr:nth-child(odd) .mms-row-head { background: #f6f7f7; }
        .mms-site-name, .mms-pt-name { display: block; font-weight: 600; }
        .mms-site-url { display: block; font-size: 11px; color: #646970; font-weight: 400; }
        .mms-toggle { display: block; margin-top: 4px; font-size: 11px; font-weight: 400; color: #646970; }
        .mms-grid tbody tr:hover td, .mms-grid tbody tr:hover th { background: #f0f6fc; }
    </style>

    <script>
        (function () {
            var table = document.querySelector('.mms-grid');
            if (!table) return;

            function cells(attr, value) {
                return table.querySelectorAll('.mms-cell[data-' + attr + '="' + value + '"]');
            }

            function syncToggles() {
                table.querySelectorAll('.mms-toggle-col').forEach(function (toggle) {
                    var list = Array.prototype.slice.call(cells('site', toggle.dataset.site));
                    toggle.checked = list.length > 0 && list.every(function (c) { return c.checked; });
                });
                table.querySelectorAll('.mms-toggle-row').forEach(function (toggle) {
                    var list = Array.prototype.slice.call(cells('post-type', toggle.dataset.postType));
                    toggle.checked = list.length > 0 && list.every(function (c) { return c.checked; });
                });
            }

            table.addEventListener('change', function (e) {
                var t = e.target;
                if (t.classList.contains('mms-toggle-col')) {
                    cells('site', t.dataset.site).forEach(function (c) { c.checked = t.checked; });
                } else if (t.classList.contains('mms-toggle-row')) {
                    cells('post-type', t.dataset.postType).forEach(function (c) { c.checked = t.checked; });
                }
                syncToggles();
            });

            syncToggles();
        })();
    </script>
    <?php
}
