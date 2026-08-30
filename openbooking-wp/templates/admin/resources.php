<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <p class="description"><?php esc_html_e( 'Personas, salas o equipos que se asignan a tus servicios (ej: profesionales, consultorios, equipos).', 'openbooking-wp' ); ?></p>
    <p class="description"><?php printf( esc_html__( 'El Core Free permite hasta %d recursos o profesionales activos. Puedes archivar recursos inactivos sin perder su historial.', 'openbooking-wp' ), (int) \OpenBooking\Support\Free_Core_Limits::active_resources() ); ?></p>

    <div id="obwp-resource-form-wrap" class="ob-card ob-card-limited ob-is-hidden">
        <h2 id="obwp-resource-form-title"><?php esc_html_e( 'Nuevo recurso', 'openbooking-wp' ); ?></h2>
        <input type="hidden" id="obwp-resource-id" value="">
        <div class="obwp-field">
            <label for="resource_name"><?php esc_html_e( 'Nombre', 'openbooking-wp' ); ?> *</label>
            <input type="text" id="resource_name" class="large-text">
        </div>
        <div class="obwp-field-row">
            <div class="obwp-field">
                <label for="resource_type"><?php esc_html_e( 'Tipo', 'openbooking-wp' ); ?></label>
                <select id="resource_type">
                    <option value="person"><?php esc_html_e( 'Persona', 'openbooking-wp' ); ?></option>
                    <option value="room"><?php esc_html_e( 'Sala / espacio', 'openbooking-wp' ); ?></option>
                    <option value="equipment"><?php esc_html_e( 'Equipo', 'openbooking-wp' ); ?></option>
                    <option value="other"><?php esc_html_e( 'Otro', 'openbooking-wp' ); ?></option>
                </select>
            </div>
            <div class="obwp-field">
                <label for="resource_capacity"><?php esc_html_e( 'Capacidad', 'openbooking-wp' ); ?></label>
                <input type="number" id="resource_capacity" value="1" min="1" class="ob-input-compact">
            </div>
        </div>
        <div class="obwp-field">
            <label for="resource_status"><?php esc_html_e( 'Estado', 'openbooking-wp' ); ?></label>
            <select id="resource_status">
                <option value="active"><?php esc_html_e( 'Activo', 'openbooking-wp' ); ?></option>
                <option value="inactive"><?php esc_html_e( 'Inactivo', 'openbooking-wp' ); ?></option>
            </select>
        </div>
        <div class="obwp-field">
            <label><?php esc_html_e( 'Servicios asignados', 'openbooking-wp' ); ?></label>
            <div id="obwp-resource-services-checklist" class="obwp-scrollable-checklist">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando servicios...', 'openbooking-wp' ); ?></p>
            </div>
        </div>
        <div class="obwp-form-actions">
            <button class="ob-btn ob-btn-primary" id="obwp-save-resource"><?php esc_html_e( 'Guardar', 'openbooking-wp' ); ?></button>
            <button class="ob-btn ob-btn-secondary" id="obwp-cancel-resource"><?php esc_html_e( 'Cancelar', 'openbooking-wp' ); ?></button>
        </div>
    </div>

    <div class="obwp-filter-bar" id="obwp-resource-filters">
        <label for="obwp-resource-search-filter"><?php esc_html_e( 'Buscar', 'openbooking-wp' ); ?></label>
        <input type="search" id="obwp-resource-search-filter" placeholder="<?php esc_attr_e( 'Nombre o servicio', 'openbooking-wp' ); ?>">
        <label for="obwp-resource-type-filter"><?php esc_html_e( 'Tipo', 'openbooking-wp' ); ?></label>
        <select id="obwp-resource-type-filter">
            <option value=""><?php esc_html_e( 'Todos', 'openbooking-wp' ); ?></option>
            <option value="person"><?php esc_html_e( 'Persona', 'openbooking-wp' ); ?></option>
            <option value="room"><?php esc_html_e( 'Sala / espacio', 'openbooking-wp' ); ?></option>
            <option value="equipment"><?php esc_html_e( 'Equipo', 'openbooking-wp' ); ?></option>
            <option value="other"><?php esc_html_e( 'Otro', 'openbooking-wp' ); ?></option>
        </select>
        <label for="obwp-resource-status-filter"><?php esc_html_e( 'Estado', 'openbooking-wp' ); ?></label>
        <select id="obwp-resource-status-filter">
            <option value=""><?php esc_html_e( 'Todos', 'openbooking-wp' ); ?></option>
            <option value="active"><?php esc_html_e( 'Activo', 'openbooking-wp' ); ?></option>
            <option value="inactive"><?php esc_html_e( 'Inactivo', 'openbooking-wp' ); ?></option>
            <option value="archived"><?php esc_html_e( 'Archivado', 'openbooking-wp' ); ?></option>
        </select>
    </div>

    <div id="obwp-resources-table">
        <p class="obwp-loading"><?php esc_html_e( 'Cargando recursos...', 'openbooking-wp' ); ?></p>
    </div>
</div>

<script>
(function($) {
    'use strict';
    $(function() {
    if (!window.obwpAdmin) return;
    var restUrl = obwpAdmin.restUrl;
    var nonce   = obwpAdmin.nonce;
    var allServices = [];
    var allResources = [];

    function notify(msg, type) {
        if (window.obwpNotice) {
            window.obwpNotice(msg, type || 'success');
            return;
        }
        console.warn(msg);
    }

    function api(method, url, data) {
        var opts = { url: url, method: method, beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', nonce); } };
        if (data && method !== 'GET') { opts.data = JSON.stringify(data); opts.contentType = 'application/json'; }
        return $.ajax(opts);
    }

    function esc(s) { return $('<span>').text(s || '').html(); }

    function loadServices() {
        api('GET', restUrl + 'admin/services').done(function(res) {
            allServices = res.services || [];
        });
    }

    function renderServiceChecklist(selectedIds) {
        var $container = $('#obwp-resource-services-checklist');
        if (!allServices.length) {
            $container.html('<p class="obwp-empty"><?php echo esc_js( __( 'No hay servicios creados.', 'openbooking-wp' ) ); ?></p>');
            return;
        }
        var html = '';
        allServices.forEach(function(s) {
            var checked = selectedIds && selectedIds.indexOf(s.id) >= 0 ? ' checked' : '';
            html += '<label><input type="checkbox" class="rs-service-check" value="' + s.id + '"' + checked + '> ' + esc(s.name) + '</label><br>';
        });
        $container.html(html);
    }

    function getSelectedServiceIds() {
        var ids = [];
        $('.rs-service-check:checked').each(function() { ids.push(parseInt($(this).val())); });
        return ids;
    }

    function applyResourceFilters() {
        var term = ($('#obwp-resource-search-filter').val() || '').toLowerCase();
        var type = $('#obwp-resource-type-filter').val();
        var status = $('#obwp-resource-status-filter').val();
        var filtered = allResources.filter(function(r) {
            var haystack = [r.name, r.type, r.status].concat(r.service_names || []).join(' ').toLowerCase();
            return (!term || haystack.indexOf(term) >= 0) && (!type || r.type === type) && (!status || r.status === status);
        });
        renderResources(filtered, true);
    }

    function renderResources(resources, filtered) {
            if (!resources.length) {
                $('#obwp-resources-table').html(
                    '<div class="ob-empty-state">' +
                    '<div class="ob-empty-state__icon"><svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48"><rect x="6" y="6" width="36" height="36" rx="6"/><circle cx="18" cy="20" r="4"/><circle cx="30" cy="20" r="4"/><path d="M10 38c0-5.5 5.4-10 14-10s14 4.5 14 10"/></svg></div>' +
                    '<p class="ob-empty-state__title">' + (filtered ? '<?php echo esc_js( __( 'No hay recursos que coincidan con los filtros.', 'openbooking-wp' ) ); ?>' : '<?php echo esc_js( __( 'Todavía no tienes recursos', 'openbooking-wp' ) ); ?>') + '</p>' +
                    '<p class="ob-empty-state__desc">' + (filtered ? '<?php echo esc_js( __( 'Ajusta la busqueda, tipo o estado para ampliar los resultados.', 'openbooking-wp' ) ); ?>' : '<?php echo esc_js( __( 'Los recursos son las personas, salas o equipos que atienden tus servicios. Por ejemplo: un profesional, un consultorio o una máquina.', 'openbooking-wp' ) ); ?>') + '</p>' +
                    (filtered ? '' : '<button type="button" class="ob-btn ob-btn-primary" id="obwp-new-resource-empty-btn"><?php echo esc_js( __( '+ Crear primer recurso', 'openbooking-wp' ) ); ?></button>') +
                    '</div>'
                );
                return;
            }
            var html = '<table class="widefat"><thead><tr><th>Nombre</th><th>Tipo</th><th>Capacidad</th><th>Estado</th><th>Servicios</th><th></th></tr></thead><tbody>';
            resources.forEach(function(r) {
                var svcNames = (r.service_names || []).map(function(n) { return esc(n); }).join(', ') || '—';
                html += '<tr>';
                html += '<td><strong>' + esc(r.name) + '</strong></td>';
                html += '<td>' + esc(r.type) + '</td>';
                html += '<td>' + r.capacity + '</td>';
                html += '<td><span class="obwp-status obwp-status--' + esc(r.status) + '">' + esc(r.status) + '</span></td>';
                html += '<td><small>' + svcNames + '</small></td>';
                html += '<td>';
                html += '<button class="ob-btn ob-btn-secondary ob-btn-sm obwp-edit-resource" data-id="' + r.id + '" data-name="' + esc(r.name) + '" data-type="' + esc(r.type) + '" data-capacity="' + r.capacity + '" data-status="' + esc(r.status) + '" data-services="' + (r.service_ids || []).join(',') + '"><?php echo esc_js( __( 'Editar', 'openbooking-wp' ) ); ?></button> ';
                html += '<button class="ob-btn ob-btn-danger ob-btn-sm obwp-delete-resource" data-id="' + r.id + '"><?php echo esc_js( __( 'Eliminar', 'openbooking-wp' ) ); ?></button>';
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            $('#obwp-resources-table').html(html);
    }

    function loadResources() {
        $('#obwp-resources-table').html('<p class="obwp-loading"><?php echo esc_js( __( 'Cargando recursos...', 'openbooking-wp' ) ); ?></p>');
        api('GET', restUrl + 'admin/resources').done(function(res) {
            allResources = res.resources || [];
            applyResourceFilters();
        }).fail(function(xhr) {
            var msg = (xhr && xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || '<?php echo esc_js( __( 'No pudimos cargar los recursos.', 'openbooking-wp' ) ); ?>';
            $('#obwp-resources-table').html(
                '<div class="notice notice-error inline"><p>' + esc(msg) + '</p>' +
                '<p><button type="button" class="ob-btn ob-btn-secondary" id="obwp-retry-resources"><?php echo esc_js( __( 'Reintentar', 'openbooking-wp' ) ); ?></button></p></div>'
            );
        });
    }

    $(document).on('input change', '#obwp-resource-search-filter, #obwp-resource-type-filter, #obwp-resource-status-filter', applyResourceFilters);
    $(document).on('click', '#obwp-retry-resources', loadResources);

    $('#obwp-new-resource-btn').on('click', function() {
        $('#obwp-resource-id').val('');
        $('#resource_name').val('');
        $('#resource_type').val('person');
        $('#resource_capacity').val(1);
        $('#resource_status').val('active');
        $('#obwp-resource-form-title').text('<?php echo esc_js( __( 'Nuevo recurso', 'openbooking-wp' ) ); ?>');
        renderServiceChecklist([]);
        $('#obwp-resource-form-wrap').show();
    });

    $(document).on('click', '.obwp-edit-resource', function() {
        var $btn = $(this);
        $('#obwp-resource-id').val($btn.data('id'));
        $('#resource_name').val($btn.data('name'));
        $('#resource_type').val($btn.data('type'));
        $('#resource_capacity').val($btn.data('capacity'));
        $('#resource_status').val($btn.data('status'));
        var serviceIds = ($btn.data('services') || '').toString().split(',').filter(function(s) { return s; }).map(function(s) { return parseInt(s); });
        renderServiceChecklist(serviceIds);
        $('#obwp-resource-form-title').text('<?php echo esc_js( __( 'Editar recurso', 'openbooking-wp' ) ); ?>');
        $('#obwp-resource-form-wrap').show();
        $('html, body').animate({ scrollTop: 0 }, 200);
    });

    $('#obwp-cancel-resource').on('click', function() {
        $('#obwp-resource-form-wrap').hide();
    });

    $('#obwp-save-resource').on('click', function() {
        var id       = $('#obwp-resource-id').val();
        var payload  = {
            name:        $('#resource_name').val().trim(),
            type:        $('#resource_type').val(),
            capacity:    parseInt($('#resource_capacity').val()) || 1,
            status:      $('#resource_status').val(),
            service_ids: getSelectedServiceIds(),
        };
        if (!payload.name) { notify('El nombre es obligatorio.', 'error'); return; }

        var method   = id ? 'PATCH' : 'POST';
        var endpoint = id ? restUrl + 'admin/resources/' + id : restUrl + 'admin/resources';

        api(method, endpoint, payload).done(function(res) {
            if (res.success) {
                $('#obwp-resource-form-wrap').hide();
                loadResources();
                notify(res.message || '<?php echo esc_js( __( 'Recurso guardado correctamente.', 'openbooking-wp' ) ); ?>', 'success');
            } else {
                notify(res.error || '<?php echo esc_js( __( 'Error al guardar.', 'openbooking-wp' ) ); ?>', 'error');
            }
        }).fail(function(xhr) {
            notify((xhr && xhr.responseJSON && xhr.responseJSON.error) || '<?php echo esc_js( __( 'Error al guardar.', 'openbooking-wp' ) ); ?>', 'error');
        });
    });

    $(document).on('click', '.obwp-delete-resource', function() {
        if (!confirm('<?php echo esc_js( __( '¿Estás seguro de eliminar esto?', 'openbooking-wp' ) ); ?>')) return;
        var id   = $(this).data('id');
        var $row = $(this).closest('tr');
        api('DELETE', restUrl + 'admin/resources/' + id).done(function(res) {
            if (res.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
                notify(res.message || '<?php echo esc_js( __( 'Recurso eliminado correctamente.', 'openbooking-wp' ) ); ?>', 'success');
            } else {
                notify(res.error || '<?php echo esc_js( __( 'Error al eliminar.', 'openbooking-wp' ) ); ?>', 'error');
            }
        });
    });

    $(document).on('click', '#obwp-new-resource-empty-btn', function() {
        $('#obwp-new-resource-btn').trigger('click');
    });

    loadServices();
    loadResources();
    }); // end document.ready
})(jQuery);
</script>
