<?php if ( ! defined( 'ABSPATH' ) ) exit;
$is_new = ! $service;
?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php echo $is_new ? esc_html__( 'Nuevo servicio', 'openbooking-wp' ) : esc_html__( 'Editar servicio', 'openbooking-wp' ); ?></h1>

    <form id="obwp-service-form" class="obwp-form" method="post">
        <input type="hidden" name="action" value="obwp_save_service">
        <input type="hidden" name="service_id" value="<?php echo $is_new ? 0 : esc_attr( $service->id ); ?>">
        <?php if ( ! $is_new ) : ?>
        <input type="hidden" name="expected_updated_at" value="<?php echo esc_attr( (string) $service->updated_at ); ?>">
        <?php endif; ?>
        <?php wp_nonce_field( 'obwp_save_service', '_obwp_nonce' ); ?>

        <div class="obwp-tabs ob-tabs">
            <button type="button" class="obwp-tab active" data-tab="basic"><?php esc_html_e( 'Básico', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="schedule"><?php esc_html_e( 'Agenda', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="pricing"><?php esc_html_e( 'Cobro', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="resources"><?php esc_html_e( 'Recursos', 'openbooking-wp' ); ?></button>
        </div>

        <div class="obwp-tab-panel active" id="tab-basic">
            <div class="obwp-field">
                <label for="service_name"><?php esc_html_e( 'Nombre del servicio', 'openbooking-wp' ); ?></label>
                <input type="text" id="service_name" name="name" value="<?php echo $is_new ? '' : esc_attr( $service->name ); ?>" required>
            </div>

            <div class="obwp-field">
                <label for="service_description"><?php esc_html_e( 'Descripción', 'openbooking-wp' ); ?></label>
                <textarea id="service_description" name="description" rows="3"><?php echo $is_new ? '' : esc_textarea( $service->description ); ?></textarea>
            </div>

            <div class="obwp-field-row">
                <div class="obwp-field">
                    <label for="service_duration"><?php esc_html_e( 'Duración', 'openbooking-wp' ); ?></label>
                    <select id="service_duration" name="duration_minutes">
                        <?php foreach ( [ 15, 30, 45, 60, 90, 120 ] as $d ) : ?>
                            <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $is_new ? 60 : $service->duration_minutes, $d ); ?>><?php echo esc_html( $d ); ?> min</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="obwp-field">
                    <label for="service_capacity"><?php esc_html_e( 'Capacidad', 'openbooking-wp' ); ?></label>
                    <input type="number" id="service_capacity" name="capacity" value="<?php echo $is_new ? 1 : esc_attr( $service->capacity ); ?>" min="1">
                </div>
            </div>

            <div class="obwp-field">
                <label><?php esc_html_e( 'Modalidad', 'openbooking-wp' ); ?></label>
                <div class="obwp-radio-group">
                    <label><input type="radio" name="mode" value="presencial" <?php checked( $is_new ? 'presencial' : $service->mode, 'presencial' ); ?>> <?php esc_html_e( 'Presencial', 'openbooking-wp' ); ?></label>
                    <label><input type="radio" name="mode" value="online" <?php checked( $service->mode ?? '', 'online' ); ?>> <?php esc_html_e( 'Online', 'openbooking-wp' ); ?></label>
                    <label><input type="radio" name="mode" value="both" <?php checked( $service->mode ?? '', 'both' ); ?>> <?php esc_html_e( 'Ambas', 'openbooking-wp' ); ?></label>
                </div>
            </div>

            <div class="obwp-field-row">
                <div class="obwp-field">
                    <label for="service_color"><?php esc_html_e( 'Color', 'openbooking-wp' ); ?></label>
                    <input type="color" id="service_color" name="color" value="<?php echo $is_new ? '#111111' : esc_attr( $service->color ?? '#111111' ); ?>">
                </div>
                <div class="obwp-field">
                    <label for="service_status"><?php esc_html_e( 'Estado', 'openbooking-wp' ); ?></label>
                    <select id="service_status" name="status">
                        <option value="active" <?php selected( $is_new ? 'active' : $service->status, 'active' ); ?>><?php esc_html_e( 'Activo', 'openbooking-wp' ); ?></option>
                        <option value="draft" <?php selected( $service->status ?? '', 'draft' ); ?>><?php esc_html_e( 'Borrador', 'openbooking-wp' ); ?></option>
                        <option value="archived" <?php selected( $service->status ?? '', 'archived' ); ?>><?php esc_html_e( 'Archivado', 'openbooking-wp' ); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-schedule">
            <div class="obwp-field-row">
                <div class="obwp-field">
                    <label for="buffer_before"><?php esc_html_e( 'Buffer antes (min)', 'openbooking-wp' ); ?></label>
                    <input type="number" id="buffer_before" name="buffer_before_minutes" value="<?php echo $is_new ? 0 : esc_attr( $service->buffer_before_minutes ); ?>" min="0">
                </div>
                <div class="obwp-field">
                    <label for="buffer_after"><?php esc_html_e( 'Buffer después (min)', 'openbooking-wp' ); ?></label>
                    <input type="number" id="buffer_after" name="buffer_after_minutes" value="<?php echo $is_new ? 0 : esc_attr( $service->buffer_after_minutes ); ?>" min="0">
                </div>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-pricing">
            <div class="obwp-field">
                <label for="service_price"><?php esc_html_e( 'Precio', 'openbooking-wp' ); ?></label>
                <input type="number" id="service_price" name="price_minor" value="<?php echo $is_new ? 0 : esc_attr( round( $service->price_minor / 100, 2 ) ); ?>" min="0" step="0.01">
            </div>
            <div class="obwp-field">
                <label for="service_currency"><?php esc_html_e( 'Moneda', 'openbooking-wp' ); ?></label>
                <select id="service_currency" name="currency">
                    <?php $currencies = [ 'USD' => 'USD', 'CLP' => 'CLP', 'COP' => 'COP', 'MXN' => 'MXN', 'EUR' => 'EUR', 'ARS' => 'ARS', 'PEN' => 'PEN', 'BRL' => 'BRL' ]; ?>
                    <?php foreach ( $currencies as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $is_new ? 'USD' : $service->currency, $code ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="obwp-field">
                <label for="service_visibility"><?php esc_html_e( 'Visibilidad', 'openbooking-wp' ); ?></label>
                <select id="service_visibility" name="visibility">
                    <option value="public" <?php selected( $is_new ? 'public' : $service->visibility, 'public' ); ?>><?php esc_html_e( 'Público', 'openbooking-wp' ); ?></option>
                    <option value="private" <?php selected( $service->visibility ?? '', 'private' ); ?>><?php esc_html_e( 'Privado', 'openbooking-wp' ); ?></option>
                </select>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-resources">
            <?php if ( ! $is_new && $service ) : ?>
            <div class="obwp-field">
                <label><?php esc_html_e( 'Recursos asignados', 'openbooking-wp' ); ?></label>
                <div id="obwp-service-resources-list">
                    <p class="obwp-loading"><?php esc_html_e( 'Cargando recursos...', 'openbooking-wp' ); ?></p>
                </div>
            </div>
            <?php else : ?>
            <p class="description"><?php esc_html_e( 'Guarda el servicio primero para asignar recursos.', 'openbooking-wp' ); ?></p>
            <?php endif; ?>
        </div>

        <div class="obwp-form-actions">
            <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar', 'openbooking-wp' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services' ) ); ?>" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Cancelar', 'openbooking-wp' ); ?></a>
        </div>
    </form>

<script>
(function($) {
    'use strict';
    if (!window.obwpAdmin) {
        $('#obwp-service-resources-list').html('<p class="obwp-error"><?php echo esc_js( __( 'No se pudo inicializar la configuracion REST del admin.', 'openbooking-wp' ) ); ?></p>');
        return;
    }
    var restUrl = obwpAdmin.restUrl;
    var nonce   = obwpAdmin.nonce;
    var serviceId = <?php echo $is_new ? 0 : (int) $service->id; ?>;

    function api(method, url, data) {
        var opts = { url: url, method: method, beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); } };
        if (data && method !== 'GET') { opts.data = JSON.stringify(data); opts.contentType = 'application/json'; }
        return $.ajax(opts);
    }

    function esc(s) { return $('<span>').text(s || '').html(); }

    function loadAssignedResources() {
        var $list = $('#obwp-service-resources-list');
        $list.html('<p class="obwp-loading"><?php echo esc_js( __( 'Cargando recursos...', 'openbooking-wp' ) ); ?></p>');
        api('GET', restUrl + 'admin/resources').done(function(res) {
            var resources = (res.resources || []).filter(function(r) {
                var serviceIds = (r.service_ids || []).map(function(id) { return parseInt(id, 10); });
                return serviceIds.indexOf(serviceId) >= 0;
            });
            if (!resources.length) {
                $list.html('<p class="obwp-empty"><?php echo esc_js( __( 'No hay recursos asignados a este servicio.', 'openbooking-wp' ) ); ?></p>');
                return;
            }
            var html = '<table class="widefat"><thead><tr><th>Nombre</th><th>Tipo</th><th>Capacidad</th><th>Estado</th></tr></thead><tbody>';
            resources.forEach(function(r) {
                html += '<tr>';
                html += '<td><strong>' + esc(r.name) + '</strong></td>';
                html += '<td>' + esc(r.type) + '</td>';
                html += '<td>' + r.capacity + '</td>';
                html += '<td><span class="obwp-status obwp-status--' + esc(r.status) + '">' + esc(r.status) + '</span></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '<p class="description ob-space-top-sm"><?php echo esc_js( __( 'Para asignar o desasignar recursos, usa la página de Recursos.', 'openbooking-wp' ) ); ?></p>';
            $list.html(html);
        }).fail(function(xhr) {
            var msg = (xhr && xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || '<?php echo esc_js( __( 'No pudimos cargar los recursos asignados.', 'openbooking-wp' ) ); ?>';
            $list.html('<div class="notice notice-error inline"><p>' + esc(msg) + '</p><p><button type="button" class="ob-btn ob-btn-secondary" id="obwp-retry-service-resources"><?php echo esc_js( __( 'Reintentar', 'openbooking-wp' ) ); ?></button></p></div>');
        });
    }

    if (serviceId && $('#obwp-service-resources-list').length) {
        loadAssignedResources();
    }
    $(document).on('click', '#obwp-retry-service-resources', loadAssignedResources);
})(jQuery);
</script>
</div>
