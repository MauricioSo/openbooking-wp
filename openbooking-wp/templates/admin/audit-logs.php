<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap" id="obwp-audit-logs-app">
    <h1 class="ob-page-title"><?php esc_html_e( 'Audit Logs', 'openbooking-wp' ); ?></h1>

    <div class="obwp-agenda-layout obwp-audit-layout">
        <div class="obwp-agenda-main">
            <form id="obwp-audit-filters-form" class="obwp-form obwp-audit-filters">
                <div class="obwp-field-row">
                    <div class="obwp-field">
                        <label for="obwp-audit-entity-type"><?php esc_html_e( 'Entidad', 'openbooking-wp' ); ?></label>
                        <select id="obwp-audit-entity-type" name="entity_type">
                            <option value=""><?php esc_html_e( 'Todas', 'openbooking-wp' ); ?></option>
                            <option value="booking"><?php esc_html_e( 'Booking', 'openbooking-wp' ); ?></option>
                            <option value="payment"><?php esc_html_e( 'Payment', 'openbooking-wp' ); ?></option>
                        </select>
                    </div>
                    <div class="obwp-field">
                        <label for="obwp-audit-action"><?php esc_html_e( 'Accion', 'openbooking-wp' ); ?></label>
                        <input type="text" id="obwp-audit-action" name="action" placeholder="admin_refund">
                    </div>
                    <div class="obwp-field">
                        <label for="obwp-audit-entity-id"><?php esc_html_e( 'Entity ID', 'openbooking-wp' ); ?></label>
                        <input type="number" id="obwp-audit-entity-id" name="entity_id" min="1" placeholder="123">
                    </div>
                    <div class="obwp-field">
                        <label for="obwp-audit-actor"><?php esc_html_e( 'Actor', 'openbooking-wp' ); ?></label>
                        <input type="text" id="obwp-audit-actor" name="actor_type" placeholder="admin">
                    </div>
                </div>
                <div class="obwp-field-row">
                    <div class="obwp-field">
                        <label for="obwp-audit-date-from"><?php esc_html_e( 'Desde', 'openbooking-wp' ); ?></label>
                        <input type="date" id="obwp-audit-date-from" name="date_from">
                    </div>
                    <div class="obwp-field">
                        <label for="obwp-audit-date-to"><?php esc_html_e( 'Hasta', 'openbooking-wp' ); ?></label>
                        <input type="date" id="obwp-audit-date-to" name="date_to">
                    </div>
                    <div class="obwp-field">
                        <label for="obwp-audit-search"><?php esc_html_e( 'Busqueda libre', 'openbooking-wp' ); ?></label>
                        <input type="text" id="obwp-audit-search" name="search" placeholder="refund, reason, gateway...">
                    </div>
                    <div class="obwp-field">
                        <label for="obwp-audit-request-id"><?php esc_html_e( 'Request ID', 'openbooking-wp' ); ?></label>
                        <input type="text" id="obwp-audit-request-id" name="request_id" placeholder="obwp_uuid...">
                    </div>
                </div>
                <div class="obwp-form-actions">
                    <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Filtrar', 'openbooking-wp' ); ?></button>
                    <button type="button" class="ob-btn ob-btn-secondary" id="obwp-audit-clear-filters"><?php esc_html_e( 'Limpiar filtros', 'openbooking-wp' ); ?></button>
                </div>
            </form>

            <div id="obwp-audit-logs-list">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando logs...', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <aside class="obwp-agenda-sidebar">
            <div id="obwp-audit-log-detail">
                <p class="obwp-empty"><?php esc_html_e( 'Selecciona un evento para ver el detalle.', 'openbooking-wp' ); ?></p>
            </div>
        </aside>
    </div>
</div>
