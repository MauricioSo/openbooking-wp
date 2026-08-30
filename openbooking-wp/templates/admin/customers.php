<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php esc_html_e( 'Clientes', 'openbooking-wp' ); ?></h1>

    <div class="obwp-search-bar ob-space-bottom-md">
        <input type="text" id="obwp-customer-search" placeholder="<?php esc_attr_e( 'Buscar por nombre o email', 'openbooking-wp' ); ?>" class="regular-text">
        <button class="ob-btn ob-btn-secondary" id="obwp-customer-search-btn"><?php esc_html_e( 'Buscar', 'openbooking-wp' ); ?></button>
    </div>

    <div id="obwp-customers-list">
        <p class="obwp-loading"><?php esc_html_e( 'Cargando clientes...', 'openbooking-wp' ); ?></p>
    </div>

    <div id="obwp-customer-detail" class="ob-card ob-stack-xl ob-is-hidden">
        <div class="ob-panel-header">
            <h2 id="obwp-customer-detail-name" class="ob-title-reset"></h2>
            <div class="ob-panel-actions">
                <button class="ob-btn ob-btn-secondary" id="obwp-customer-edit-btn"><?php esc_html_e( 'Editar', 'openbooking-wp' ); ?></button>
                <button class="ob-btn ob-btn-primary ob-is-hidden" id="obwp-customer-save-btn"><?php esc_html_e( 'Guardar', 'openbooking-wp' ); ?></button>
                <button class="ob-btn ob-btn-secondary ob-is-hidden" id="obwp-customer-cancel-edit-btn"><?php esc_html_e( 'Cancelar', 'openbooking-wp' ); ?></button>
            </div>
        </div>
        <div id="obwp-customer-detail-info"></div>
        <div id="obwp-customer-edit-form" class="ob-stack-md ob-is-hidden">
            <table class="ob-table-form">
                <tr>
                    <th><label for="obwp-edit-first-name"><?php esc_html_e( 'Nombre', 'openbooking-wp' ); ?></label></th>
                    <td><input type="text" id="obwp-edit-first-name" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="obwp-edit-last-name"><?php esc_html_e( 'Apellido', 'openbooking-wp' ); ?></label></th>
                    <td><input type="text" id="obwp-edit-last-name" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="obwp-edit-phone"><?php esc_html_e( 'Teléfono', 'openbooking-wp' ); ?></label></th>
                    <td><input type="text" id="obwp-edit-phone" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="obwp-edit-notes"><?php esc_html_e( 'Notas', 'openbooking-wp' ); ?></label></th>
                    <td><textarea id="obwp-edit-notes" rows="3" class="large-text"></textarea></td>
                </tr>
            </table>
        </div>
        <h3 class="ob-section-title ob-stack-md"><?php esc_html_e( 'Preferencias de notificación', 'openbooking-wp' ); ?></h3>
        <div id="obwp-customer-notification-prefs" class="ob-prefs-list">
            <label><input type="checkbox" id="obwp-pref-email"> <?php esc_html_e( 'Recibir email', 'openbooking-wp' ); ?></label><br>
            <label><input type="checkbox" id="obwp-pref-wa"> <?php esc_html_e( 'Recibir WhatsApp', 'openbooking-wp' ); ?></label><br>
            <label><input type="checkbox" id="obwp-pref-reminders"> <?php esc_html_e( 'Recibir recordatorios', 'openbooking-wp' ); ?></label><br>
            <label><input type="checkbox" id="obwp-pref-marketing"> <?php esc_html_e( 'Recibir anuncios del negocio', 'openbooking-wp' ); ?></label><br>
            <button class="ob-btn ob-btn-secondary ob-space-top-sm" id="obwp-save-customer-prefs"><?php esc_html_e( 'Guardar preferencias', 'openbooking-wp' ); ?></button>
        </div>
        <h3 class="ob-section-title ob-stack-md"><?php esc_html_e( 'Historial de reservas', 'openbooking-wp' ); ?></h3>
        <div id="obwp-customer-bookings">
            <p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    function notify(msg, type) {
        if (window.obwpNotice) {
            window.obwpNotice(msg, type || 'success');
            return;
        }
        console.warn(msg);
    }
    $(function() {
    if (!window.obwpAdmin) return;
    var restUrl = obwpAdmin.restUrl;
    var nonce   = obwpAdmin.nonce;
    var currentCustomerId = null;

    function api(method, url, data) {
        var opts = { url: url, method: method, beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); } };
        if (data && method !== 'GET') { opts.data = JSON.stringify(data); opts.contentType = 'application/json'; }
        return $.ajax(opts);
    }

    function esc(s) { return $('<span>').text(s || '').html(); }

    function loadCustomers(search) {
        var $list = $('#obwp-customers-list');
        $list.html('<p class="obwp-loading"><?php echo esc_js( __( 'Cargando clientes...', 'openbooking-wp' ) ); ?></p>');
        $('#obwp-customer-detail').hide();

        var url = restUrl + 'admin/customers?limit=50';
        if (search) url += '&search=' + encodeURIComponent(search);

        api('GET', url).done(function(res) {
            var customers = res.customers || [];
            if (!customers.length) {
                $list.html('<p class="obwp-empty"><?php echo esc_js( __( 'No se encontraron clientes.', 'openbooking-wp' ) ); ?></p>');
                return;
            }
            var html = '<table class="widefat"><thead><tr><th><?php echo esc_js( __( 'Nombre', 'openbooking-wp' ) ); ?></th><th>Email</th><th><?php echo esc_js( __( 'Teléfono', 'openbooking-wp' ) ); ?></th><th></th></tr></thead><tbody>';
            customers.forEach(function(c) {
                var fullName = esc((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
                html += '<tr>';
                html += '<td><strong>' + fullName + '</strong></td>';
                html += '<td>' + esc(c.email) + '</td>';
                html += '<td>' + esc(c.phone || '—') + '</td>';
                html += '<td><button class="ob-btn ob-btn-secondary ob-btn-sm obwp-view-customer" data-id="' + c.id + '" data-name="' + esc(fullName) + '" data-email="' + esc(c.email) + '" data-phone="' + esc(c.phone || '') + '" data-notes="' + esc(c.notes || '') + '"><?php echo esc_js( __( 'Ver detalle', 'openbooking-wp' ) ); ?></button></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            $list.html(html);
        }).fail(function() {
            $list.html('<p class="obwp-empty"><?php echo esc_js( __( 'Error al cargar clientes.', 'openbooking-wp' ) ); ?></p>');
        });
    }

    function showDetail(customerId, name, email, phone, notes) {
        currentCustomerId = customerId;
        $('#obwp-customer-detail-name').text(name);
        renderInfo(email, phone, notes);
        $('#obwp-customer-edit-form').hide();
        $('#obwp-customer-edit-btn').show();
        $('#obwp-customer-save-btn').hide();
        $('#obwp-customer-cancel-edit-btn').hide();
        $('#obwp-customer-detail').show();

        $('#obwp-edit-first-name').val('');
        $('#obwp-edit-last-name').val('');
        $('#obwp-edit-phone').val(phone || '');
        $('#obwp-edit-notes').val(notes || '');

        api('GET', restUrl + 'admin/customers/' + customerId).done(function(res) {
            var c = res.customer || {};
            $('#obwp-edit-first-name').val(c.first_name || '');
            $('#obwp-edit-last-name').val(c.last_name || '');
            $('#obwp-edit-phone').val(c.phone || '');
            $('#obwp-edit-notes').val(c.notes || '');
        });

        api('GET', restUrl + 'admin/customers/' + customerId + '/notification-preferences').done(function(res) {
            var p = res.preferences || {};
            $('#obwp-pref-email').prop('checked', !!parseInt(p.channel_email || 0, 10));
            $('#obwp-pref-wa').prop('checked', !!parseInt(p.channel_whatsapp || 0, 10));
            $('#obwp-pref-reminders').prop('checked', !!parseInt(p.reminders || 0, 10));
            $('#obwp-pref-marketing').prop('checked', !!parseInt(p.marketing || 0, 10));
        });

        var $bookings = $('#obwp-customer-bookings');
        $bookings.html('<p class="obwp-loading"><?php echo esc_js( __( 'Cargando...', 'openbooking-wp' ) ); ?></p>');

        api('GET', restUrl + 'admin/bookings?customer_id=' + customerId + '&limit=20').done(function(res) {
            var bookings = res.bookings || [];
            if (!bookings.length) {
                $bookings.html('<p class="obwp-empty"><?php echo esc_js( __( 'Sin reservas.', 'openbooking-wp' ) ); ?></p>');
                return;
            }
            var html = '<table class="widefat"><thead><tr><th>ID</th><th><?php echo esc_js( __( 'Servicio', 'openbooking-wp' ) ); ?></th><th><?php echo esc_js( __( 'Fecha', 'openbooking-wp' ) ); ?></th><th><?php echo esc_js( __( 'Estado', 'openbooking-wp' ) ); ?></th></tr></thead><tbody>';
            bookings.forEach(function(b) {
                html += '<tr>';
                html += '<td>' + b.id + '</td>';
                html += '<td>' + esc(b.service_name || '') + '</td>';
                html += '<td>' + esc(b.start_at || '') + '</td>';
                html += '<td><span class="obwp-status obwp-status--' + esc(b.status) + '">' + esc(b.status) + '</span></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            $bookings.html(html);
        });

        $('html, body').animate({ scrollTop: $('#obwp-customer-detail').offset().top - 40 }, 300);
    }

    function renderInfo(email, phone, notes) {
        var info = '<p><strong>Email:</strong> ' + esc(email) + '</p>';
        info += '<p><strong><?php echo esc_js( __( 'Teléfono', 'openbooking-wp' ) ); ?>:</strong> ' + esc(phone || '—') + '</p>';
        if (notes) {
            info += '<p><strong><?php echo esc_js( __( 'Notas', 'openbooking-wp' ) ); ?>:</strong> ' + esc(notes) + '</p>';
        }
        $('#obwp-customer-detail-info').html(info);
    }

    $(document).on('click', '#obwp-customer-search-btn', function() {
        loadCustomers($('#obwp-customer-search').val().trim());
    });

    $('#obwp-customer-search').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            loadCustomers($(this).val().trim());
        }
    });

    $(document).on('click', '.obwp-view-customer', function() {
        var $btn = $(this);
        showDetail($btn.data('id'), $btn.data('name'), $btn.data('email'), $btn.data('phone'), $btn.data('notes'));
    });

    $(document).on('click', '#obwp-customer-edit-btn', function() {
        $('#obwp-customer-edit-form').show();
        $(this).hide();
        $('#obwp-customer-save-btn').show();
        $('#obwp-customer-cancel-edit-btn').show();
    });

    $(document).on('click', '#obwp-customer-cancel-edit-btn', function() {
        $('#obwp-customer-edit-form').hide();
        $('#obwp-customer-edit-btn').show();
        $('#obwp-customer-save-btn').hide();
        $(this).hide();
    });

    $(document).on('click', '#obwp-customer-save-btn', function() {
        if (!currentCustomerId) return;
        var $btn = $(this).prop('disabled', true);

        api('PATCH', restUrl + 'admin/customers/' + currentCustomerId, {
            first_name: $('#obwp-edit-first-name').val().trim(),
            last_name: $('#obwp-edit-last-name').val().trim(),
            phone: $('#obwp-edit-phone').val().trim(),
            notes: $('#obwp-edit-notes').val().trim()
        }).done(function(res) {
            $btn.prop('disabled', false);
            if (res.success && res.customer) {
                var c = res.customer;
                var fullName = ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
                $('#obwp-customer-detail-name').text(fullName);
                renderInfo(c.email, c.phone, c.notes);
                $('#obwp-customer-edit-form').hide();
                $('#obwp-customer-edit-btn').show();
                $('#obwp-customer-save-btn').hide();
                $('#obwp-customer-cancel-edit-btn').hide();
                notify(res.message || '<?php echo esc_js( __( 'Cliente guardado correctamente.', 'openbooking-wp' ) ); ?>', 'success');
            } else {
                notify(res.error || '<?php echo esc_js( __( 'Error al guardar.', 'openbooking-wp' ) ); ?>', 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            notify('<?php echo esc_js( __( 'Error al guardar.', 'openbooking-wp' ) ); ?>', 'error');
        });
    });

    $(document).on('click', '#obwp-save-customer-prefs', function() {
        if (!currentCustomerId) return;
        api('PATCH', restUrl + 'admin/customers/' + currentCustomerId + '/notification-preferences', {
            channel_email: $('#obwp-pref-email').is(':checked'),
            channel_whatsapp: $('#obwp-pref-wa').is(':checked'),
            reminders: $('#obwp-pref-reminders').is(':checked'),
            marketing: $('#obwp-pref-marketing').is(':checked')
        }).done(function(res) {
            if (res.success) notify(res.message || '<?php echo esc_js( __( 'Preferencias guardadas.', 'openbooking-wp' ) ); ?>', 'success');
            else notify(res.error || '<?php echo esc_js( __( 'Error al guardar.', 'openbooking-wp' ) ); ?>', 'error');
        }).fail(function() {
            notify('<?php echo esc_js( __( 'Error al guardar.', 'openbooking-wp' ) ); ?>', 'error');
        });
    });

    loadCustomers();
    }); // end document.ready
})(jQuery);
</script>
