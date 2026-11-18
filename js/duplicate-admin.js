/**
 * Sincronizador Multisite - Duplicate Admin JavaScript
 */

jQuery(document).ready(function ($) {

    // Handle duplicate content click
    $(document).on('click', '.mms-duplicate-content', function (e) {
        e.preventDefault();

        var postId = $(this).data('post-id');
        var postType = $(this).data('post-type');

        showSiteSelector(postId, postType);
    });

    // Handle site selection
    $(document).on('click', '.mms-site-item', function () {
        $('.mms-site-item').removeClass('selected');
        $(this).addClass('selected');
        $(this).find('input[type="radio"]').prop('checked', true);
    });

    // Handle confirm button
    $(document).on('click', '.mms-confirm-duplicate', function () {
        var selectedSite = $('.mms-site-item.selected input[type="radio"]').val();
        var postId = $(this).data('post-id');

        if (!selectedSite) {
            alert(mms_ajax.strings.select_site);
            return;
        }

        duplicateContent(postId, selectedSite);
    });

    // Handle cancel button
    $(document).on('click', '.mms-cancel-duplicate', function () {
        hideSiteSelector();
    });

    // Handle overlay click
    $(document).on('click', '.mms-overlay', function () {
        hideSiteSelector();
    });

    function showSiteSelector(postId, postType) {
        // Create overlay
        var overlay = $('<div class="mms-overlay"></div>');

        // Create selector HTML
        var selectorHtml = `
            <div class="mms-site-selector">
                <div class="mms-site-selector-header">
                    <h3>Duplicar ${postType} en otro sitio</h3>
                </div>
                <div class="mms-site-selector-body">
                    <div class="mms-loading">
                        <div class="spinner is-active"></div>
                        <p>Cargando sitios disponibles...</p>
                    </div>
                    <div class="mms-site-list" style="display: none;"></div>
                </div>
                <div class="mms-site-selector-footer" style="display: none;">
                    <button type="button" class="button mms-cancel-duplicate">Cancelar</button>
                    <button type="button" class="button button-primary mms-confirm-duplicate" data-post-id="${postId}">Duplicar</button>
                </div>
            </div>
        `;

        // Add to page
        $('body').append(overlay);
        $('body').append(selectorHtml);

        // Load sites
        loadAvailableSites();
    }

    function loadAvailableSites() {
        var postSlug = mms_ajax.post_slug || '';
        var postType = mms_ajax.post_type || '';

        $.ajax({
            url: mms_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_available_sites',
                nonce: mms_ajax.nonce,
                post_slug: postSlug,
                post_type: postType
            },
            success: function (response) {
                if (response.success) {
                    displaySites(response.data.sites);
                } else {
                    alert('Error al cargar sitios: ' + response.data);
                }
            },
            error: function () {
                alert('Error de conexión al cargar sitios');
            }
        });
    }

    function displaySites(sites) {
        var siteList = $('.mms-site-list');
        var loading = $('.mms-loading');
        var footer = $('.mms-site-selector-footer');

        if (sites.length === 0) {
            siteList.html('<p>No hay sitios disponibles para duplicar.</p>');
        } else {
            var html = '';
            sites.forEach(function (site) {
                html += `
                    <div class="mms-site-item">
                        <input type="radio" name="target_site" value="${site.blog_id}" id="site_${site.blog_id}">
                        <label for="site_${site.blog_id}">
                            <span class="mms-site-name">${site.blogname}</span>
                            <span class="mms-site-url">${site.domain}${site.path}</span>
                        </label>
                    </div>
                `;
            });
            siteList.html(html);
        }

        loading.hide();
        siteList.show();
        footer.show();
    }

    function duplicateContent(postId, targetSiteId) {
        var confirmBtn = $('.mms-confirm-duplicate');
        var originalText = confirmBtn.text();

        // Show loading state
        confirmBtn.prop('disabled', true).text(mms_ajax.strings.duplicating);

        $.ajax({
            url: mms_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'duplicate_content_to_site',
                post_id: postId,
                target_site_id: targetSiteId,
                nonce: mms_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Show success message
                    showNotice('success', response.data.message);

                    // Hide selector
                    hideSiteSelector();

                    // Optionally redirect to the new post
                    if (response.data.target_site_url) {
                        if (confirm('¿Deseas ver el contenido duplicado?')) {
                            window.open(response.data.target_site_url, '_blank');
                        }
                    }
                } else {
                    showNotice('error', response.data);
                }
            },
            error: function () {
                showNotice('error', 'Error de conexión al duplicar contenido');
            },
            complete: function () {
                confirmBtn.prop('disabled', false).text(originalText);
            }
        });
    }

    function hideSiteSelector() {
        $('.mms-overlay').remove();
        $('.mms-site-selector').remove();
    }

    function showNotice(type, message) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notice = $(`
            <div class="notice ${noticeClass} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Descartar este aviso.</span>
                </button>
            </div>
        `);

        // Add to admin notices area
        $('.wrap h1').first().after(notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function () {
            notice.fadeOut();
        }, 5000);
    }

});
