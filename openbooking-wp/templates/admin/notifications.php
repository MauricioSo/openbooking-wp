<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php esc_html_e( 'Notificaciones', 'openbooking-wp' ); ?></h1>

    <div class="obwp-tabs ob-tabs">
        <button type="button" class="obwp-tab active" data-tab="summary"><?php esc_html_e( 'Resumen', 'openbooking-wp' ); ?></button>
        <button type="button" class="obwp-tab" data-tab="history"><?php esc_html_e( 'Historial', 'openbooking-wp' ); ?></button>
        <button type="button" class="obwp-tab" data-tab="queue"><?php esc_html_e( 'Cola', 'openbooking-wp' ); ?></button>
        <button type="button" class="obwp-tab" data-tab="campaigns"><?php esc_html_e( 'Campañas', 'openbooking-wp' ); ?></button>
        <button type="button" class="obwp-tab" data-tab="config"><?php esc_html_e( 'Configuración', 'openbooking-wp' ); ?></button>
    </div>

    <div class="obwp-tab-panel active" id="tab-summary">
        <div id="obwp-notif-summary-cards" class="ob-grid-auto-cards ob-stack-md"><p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p></div>
        <div class="ob-grid-2 ob-stack-lg">
            <div>
                <h3><?php esc_html_e( 'Últimas fallas', 'openbooking-wp' ); ?></h3>
                <div id="obwp-notif-last-failures"><p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p></div>
            </div>
            <div>
                <h3><?php esc_html_e( 'Pendientes en cola', 'openbooking-wp' ); ?></h3>
                <div id="obwp-notif-pending-queue"><p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p></div>
            </div>
        </div>
    </div>

    <div class="obwp-tab-panel" id="tab-history">
        <div class="ob-flex-row ob-stack-md ob-space-bottom-md">
            <input type="text" id="obwp-notif-search" class="regular-text" placeholder="<?php esc_attr_e( 'Buscar cliente, email o teléfono', 'openbooking-wp' ); ?>">
            <select id="obwp-notif-filter-channel"><option value=""><?php esc_html_e( 'Todos los canales', 'openbooking-wp' ); ?></option><option value="email">Email</option><option value="whatsapp">WhatsApp</option></select>
            <select id="obwp-notif-filter-status"><option value=""><?php esc_html_e( 'Todos los estados', 'openbooking-wp' ); ?></option><option value="sent"><?php esc_html_e( 'Enviado', 'openbooking-wp' ); ?></option><option value="failed"><?php esc_html_e( 'Fallido', 'openbooking-wp' ); ?></option><option value="skipped"><?php esc_html_e( 'Omitido', 'openbooking-wp' ); ?></option></select>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-notif-load-history"><?php esc_html_e( 'Buscar', 'openbooking-wp' ); ?></button>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-notif-export-history"><?php esc_html_e( 'Exportar CSV', 'openbooking-wp' ); ?></button>
        </div>
        <div id="obwp-notif-history"></div>
    </div>

    <div class="obwp-tab-panel" id="tab-queue">
        <div class="ob-flex-row ob-stack-md ob-space-bottom-md">
            <select id="obwp-queue-filter-status"><option value="pending"><?php esc_html_e( 'Pendientes', 'openbooking-wp' ); ?></option><option value="failed"><?php esc_html_e( 'Fallidos', 'openbooking-wp' ); ?></option><option value="processing"><?php esc_html_e( 'Procesando', 'openbooking-wp' ); ?></option><option value="sent"><?php esc_html_e( 'Enviados', 'openbooking-wp' ); ?></option></select>
            <select id="obwp-queue-filter-channel"><option value=""><?php esc_html_e( 'Todos los canales', 'openbooking-wp' ); ?></option><option value="email">Email</option><option value="whatsapp">WhatsApp</option></select>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-load-queue"><?php esc_html_e( 'Actualizar', 'openbooking-wp' ); ?></button>
        </div>
        <div id="obwp-notif-queue"><p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p></div>
    </div>

    <div class="obwp-tab-panel" id="tab-campaigns">
        <div class="ob-grid-2-lg ob-stack-md">
            <div>
                <h3><?php esc_html_e( 'Campañas', 'openbooking-wp' ); ?></h3>
                <div id="obwp-notif-campaigns"><p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p></div>
            </div>
            <div>
                <h3><?php esc_html_e( 'Nuevo broadcast', 'openbooking-wp' ); ?></h3>
                <div class="obwp-field"><label for="obwp-broadcast-title"><?php esc_html_e( 'Título', 'openbooking-wp' ); ?></label><input type="text" id="obwp-broadcast-title" class="regular-text"></div>
                <div class="obwp-field"><label for="obwp-broadcast-email-subject"><?php esc_html_e( 'Asunto email', 'openbooking-wp' ); ?></label><input type="text" id="obwp-broadcast-email-subject" class="regular-text"></div>
                <div class="obwp-field"><label for="obwp-broadcast-email"><?php esc_html_e( 'Mensaje email', 'openbooking-wp' ); ?></label><textarea id="obwp-broadcast-email" rows="6" class="large-text"></textarea></div>
                <div class="obwp-field"><label for="obwp-broadcast-wa"><?php esc_html_e( 'Mensaje WhatsApp', 'openbooking-wp' ); ?></label><textarea id="obwp-broadcast-wa" rows="5" class="large-text"></textarea></div>
                <div class="obwp-field"><label><input type="checkbox" id="obwp-broadcast-email-enabled" checked> Email</label> <label><input type="checkbox" id="obwp-broadcast-wa-enabled"> WhatsApp</label></div>
                <div class="obwp-field"><label for="obwp-broadcast-schedule"><?php esc_html_e( 'Programar para', 'openbooking-wp' ); ?></label><input type="datetime-local" id="obwp-broadcast-schedule"></div>
                <p><button type="button" class="ob-btn ob-btn-primary" id="obwp-send-broadcast"><?php esc_html_e( 'Crear campaña', 'openbooking-wp' ); ?></button></p>
            </div>
        </div>
    </div>

    <div class="obwp-tab-panel" id="tab-config">
        <p><?php esc_html_e( 'La configuración detallada de templates y canales sigue disponible en Ajustes > Notificaciones.', 'openbooking-wp' ); ?></p>
        <p><a class="ob-btn ob-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-settings' ) ); ?>"><?php esc_html_e( 'Ir a Ajustes de Notificaciones', 'openbooking-wp' ); ?></a></p>
    </div>
</div>

<script>
(function($){
    'use strict';

    var restUrl, nonce;

    function api(method, endpoint, data){
        var options = { url: restUrl + endpoint, method: method, beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', nonce); } };
        if (data && method !== 'GET' && method !== 'DELETE') { options.data = JSON.stringify(data); options.contentType = 'application/json'; }
        return $.ajax(options);
    }
    function esc(v){ return $('<span>').text(v == null ? '' : String(v)).html(); }
    function notice(msg, type){
        if (window.obwpNotice) {
            window.obwpNotice(msg, type || 'success');
            return;
        }
        console.warn(msg);
    }

    function loadSummary(){
        api('GET', 'admin/notification-stats?period=30d').done(function(res){
            var cards = '';
            cards += card('Email enviados', (res.total_sent && res.total_sent.email) || 0);
            cards += card('WA enviados', (res.total_sent && res.total_sent.whatsapp) || 0);
            cards += card('Entrega email', ((res.delivery_rate && res.delivery_rate.email) || 0) + '%');
            cards += card('Entrega WA', ((res.delivery_rate && res.delivery_rate.whatsapp) || 0) + '%');
            $('#obwp-notif-summary-cards').html(cards || '<p class="obwp-empty">Sin datos en los últimos 30 días.</p>');

            var lastFail = res.last_failures || [];
            if (!lastFail.length) {
                $('#obwp-notif-last-failures').html('<p class="obwp-empty">Sin fallas recientes.</p>');
            } else {
                var failures = '<table class="widefat"><thead><tr><th>ID</th><th>Canal</th><th>Evento</th><th>Estado</th></tr></thead><tbody>';
                lastFail.forEach(function(row){ failures += '<tr><td>' + esc(row.id) + '</td><td>' + esc(row.channel) + '</td><td>' + esc(row.template_key) + '</td><td>' + esc(row.status) + '</td></tr>'; });
                failures += '</tbody></table>';
                $('#obwp-notif-last-failures').html(failures);
            }

            var q = res.queue_pending || {};
            $('#obwp-notif-pending-queue').html('<p>Email: <strong>' + esc(q.email || 0) + '</strong></p><p>WhatsApp: <strong>' + esc(q.whatsapp || 0) + '</strong></p>');
        }).fail(function(xhr){
            var msg = (xhr && xhr.status === 403) ? 'Sin permisos para ver estadísticas.' : 'Error al cargar el resumen (' + (xhr ? xhr.status : '') + ').';
            $('#obwp-notif-summary-cards').html('<p class="obwp-error">' + esc(msg) + '</p>');
            $('#obwp-notif-last-failures').html('');
            $('#obwp-notif-pending-queue').html('');
        });
    }
    function card(label, value){ return '<div class="ob-card-sm ob-text-center"><div class="ob-meta">' + esc(label) + '</div><div class="ob-stat-value">' + esc(value) + '</div></div>'; }

    function loadHistory(){
        var url = 'admin/notification-logs?page=1&per_page=25';
        var search = $('#obwp-notif-search').val().trim();
        var channel = $('#obwp-notif-filter-channel').val();
        var status = $('#obwp-notif-filter-status').val();
        if (search) url += '&search=' + encodeURIComponent(search);
        if (channel) url += '&channel=' + encodeURIComponent(channel);
        if (status) url += '&status=' + encodeURIComponent(status);
        $('#obwp-notif-history').html('<p class="obwp-loading">Cargando...</p>');
        api('GET', url).done(function(res){
            var logs = res.logs || [];
            if (!logs.length) { $('#obwp-notif-history').html('<p class="obwp-empty">Sin registros para los filtros seleccionados.</p>'); return; }
            var html = '<table class="widefat"><thead><tr><th>ID</th><th>Reserva</th><th>Canal</th><th>Evento</th><th>Destinatario</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
            logs.forEach(function(row){
                html += '<tr><td>' + esc(row.id) + '</td><td>#' + esc(row.booking_id) + '</td><td>' + esc(row.channel) + '</td><td>' + esc(row.template_key) + '</td><td>' + esc(row.recipient) + '</td><td>' + esc(row.status) + '</td><td><button class="ob-btn ob-btn-secondary ob-btn-sm obwp-resend-log" data-id="' + esc(row.id) + '">Reenviar</button></td></tr>';
            });
            html += '</tbody></table>';
            if (res.total > logs.length) html += '<p class="obwp-empty ob-space-top-sm">Mostrando 25 de ' + esc(res.total) + ' registros. Usa los filtros para acotar.</p>';
            $('#obwp-notif-history').html(html);
        }).fail(function(xhr){ $('#obwp-notif-history').html('<p class="obwp-error">Error al cargar: ' + esc(xhr.status + ' ' + xhr.statusText) + '</p>'); });
    }

    function loadQueue(){
        var $el = $('#obwp-notif-queue');
        $el.html('<p class="obwp-loading">Cargando...</p>');
        var url = 'admin/notification-queue?limit=50';
        var status = $('#obwp-queue-filter-status').val();
        var channel = $('#obwp-queue-filter-channel').val();
        if (status) url += '&status=' + encodeURIComponent(status);
        if (channel) url += '&channel=' + encodeURIComponent(channel);
        api('GET', url).done(function(res){
            var items = res.queue || [];
            if (!items.length) { $el.html('<p class="obwp-empty">No hay elementos en la cola para los filtros seleccionados.</p>'); return; }
            var html = '<table class="widefat"><thead><tr><th>ID</th><th>Reserva</th><th>Canal</th><th>Evento</th><th>Programada</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
            items.forEach(function(row){
                html += '<tr><td>' + esc(row.id) + '</td><td>#' + esc(row.booking_id) + '</td><td>' + esc(row.channel) + '</td><td>' + esc(row.template_key) + '</td><td>' + esc(row.scheduled_at) + '</td><td>' + esc(row.status) + '</td><td>';
                if (row.status === 'pending') html += '<button class="ob-btn ob-btn-secondary ob-btn-sm obwp-cancel-queue" data-id="' + esc(row.id) + '">Cancelar</button> ';
                if (row.status === 'failed') html += '<button class="ob-btn ob-btn-secondary ob-btn-sm obwp-retry-queue" data-id="' + esc(row.id) + '">Reintentar</button>';
                html += '</td></tr>';
            });
            html += '</tbody></table>';
            $el.html(html);
        }).fail(function(xhr){ $el.html('<p class="obwp-error">Error al cargar la cola (' + esc(xhr ? xhr.status : '') + ').</p>'); });
    }

    function loadCampaigns(){
        var $el = $('#obwp-notif-campaigns');
        api('GET', 'admin/notification-campaigns').done(function(res){
            var items = res.campaigns || [];
            if (!items.length) { $el.html('<p class="obwp-empty">No hay campañas todavía.</p>'); return; }
            var html = '<table class="widefat"><thead><tr><th>ID</th><th>Tipo</th><th>Título</th><th>Targets</th><th>Enviados</th><th>Fallidos</th><th>Estado</th></tr></thead><tbody>';
            items.forEach(function(row){
                html += '<tr><td>' + esc(row.id) + '</td><td>' + esc(row.type) + '</td><td>' + esc(row.title) + '</td><td>' + esc(row.total_targets) + '</td><td>' + esc(row.total_sent) + '</td><td>' + esc(row.total_failed) + '</td><td>' + esc(row.status) + '</td></tr>';
            });
            html += '</tbody></table>';
            $el.html(html);
        }).fail(function(xhr){ $el.html('<p class="obwp-error">Error al cargar campañas (' + esc(xhr ? xhr.status : '') + ').</p>'); });
    }

    // obwpAdmin is defined in the footer by wp_localize_script, after this inline script runs.
    // All handlers and initial loads are deferred until document-ready so restUrl/nonce are set.
    $(document).ready(function() {
        if (!window.obwpAdmin) {
            $('#obwp-notif-summary-cards').html('<p class="obwp-error">Error: no se pudo inicializar (obwpAdmin no definido).</p>');
            return;
        }
        restUrl = obwpAdmin.restUrl;
        nonce   = obwpAdmin.nonce;

        $(document).on('click', '#obwp-notif-load-history', loadHistory);
        $(document).on('click', '#obwp-load-queue', loadQueue);
        $(document).on('click', '.obwp-resend-log', function(){
            var id = $(this).data('id');
            var $btn = $(this).prop('disabled', true).text('Enviando...');
            api('POST', 'admin/notification-logs/' + id + '/resend')
                .done(function(){ notice('Notificación reenviada.', 'success'); loadHistory(); })
                .fail(function(){ notice('No se pudo reenviar.', 'error'); })
                .always(function(){ $btn.prop('disabled', false).text('Reenviar'); });
        });
        $(document).on('click', '.obwp-cancel-queue', function(){
            api('DELETE', 'admin/notification-queue/' + $(this).data('id'))
                .done(function(){ loadQueue(); })
                .fail(function(){ notice('No se pudo cancelar.', 'error'); });
        });
        $(document).on('click', '.obwp-retry-queue', function(){
            api('POST', 'admin/notification-queue/' + $(this).data('id') + '/retry')
                .done(function(){ loadQueue(); })
                .fail(function(){ notice('No se pudo reintentar.', 'error'); });
        });
        $(document).on('click', '#obwp-notif-export-history', function(){
            var params = ['_wpnonce=' + encodeURIComponent(nonce)];
            var search = $('#obwp-notif-search').val().trim();
            var channel = $('#obwp-notif-filter-channel').val();
            var status = $('#obwp-notif-filter-status').val();
            if (search) params.push('search=' + encodeURIComponent(search));
            if (channel) params.push('channel=' + encodeURIComponent(channel));
            if (status) params.push('status=' + encodeURIComponent(status));
            window.location.href = restUrl + 'admin/notification-logs/export?' + params.join('&');
        });
        $(document).on('click', '#obwp-send-broadcast', function(){
            var channels = [];
            if ($('#obwp-broadcast-email-enabled').is(':checked')) channels.push('email');
            if ($('#obwp-broadcast-wa-enabled').is(':checked')) channels.push('whatsapp');
            api('POST', 'admin/notifications/broadcast', {
                title: $('#obwp-broadcast-title').val().trim(),
                subject_email: $('#obwp-broadcast-email-subject').val().trim(),
                message_email: $('#obwp-broadcast-email').val(),
                message_whatsapp: $('#obwp-broadcast-wa').val(),
                channels: channels,
                schedule_at: $('#obwp-broadcast-schedule').val() || null,
                scope: { status: ['confirmed'] }
            }).done(function(){ notice('Campaña creada.', 'success'); loadCampaigns(); }).fail(function(){ notice('No se pudo crear la campaña.', 'error'); });
        });

        loadSummary();
        loadHistory();
        loadQueue();
        loadCampaigns();
    });
})(jQuery);
</script>
