<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <div class="ob-page-header">
        <h1 class="ob-page-title"><?php esc_html_e( 'Agenda', 'openbooking-wp' ); ?></h1>
    </div>

    <div class="obwp-agenda-toolbar">
        <div class="obwp-agenda-views">
            <button class="button obwp-agenda-view" data-view="day"><?php esc_html_e( 'Hoy', 'openbooking-wp' ); ?></button>
            <button class="button obwp-agenda-view active" data-view="week"><?php esc_html_e( 'Semana', 'openbooking-wp' ); ?></button>
            <button class="button obwp-agenda-view" data-view="month"><?php esc_html_e( 'Mes', 'openbooking-wp' ); ?></button>
        </div>
        <div class="obwp-agenda-filters">
            <select id="obwp-filter-service" class="obwp-filter-select">
                <option value=""><?php esc_html_e( 'Todos los servicios', 'openbooking-wp' ); ?></option>
            </select>
            <select id="obwp-filter-status" class="obwp-filter-select">
                <option value=""><?php esc_html_e( 'Todos los estados', 'openbooking-wp' ); ?></option>
                <option value="pending"><?php esc_html_e( 'Pendiente', 'openbooking-wp' ); ?></option>
                <option value="confirmed"><?php esc_html_e( 'Confirmada', 'openbooking-wp' ); ?></option>
                <option value="cancelled_by_customer"><?php esc_html_e( 'Cancelada (cliente)', 'openbooking-wp' ); ?></option>
                <option value="cancelled_by_admin"><?php esc_html_e( 'Cancelada (admin)', 'openbooking-wp' ); ?></option>
                <option value="completed"><?php esc_html_e( 'Completada', 'openbooking-wp' ); ?></option>
                <option value="no_show"><?php esc_html_e( 'No asistió', 'openbooking-wp' ); ?></option>
            </select>
        </div>
    </div>

    <div class="obwp-agenda-layout">
        <div class="obwp-agenda-main" id="obwp-agenda-main">
            <p class="obwp-loading"><?php esc_html_e( 'Cargando agenda...', 'openbooking-wp' ); ?></p>
        </div>
        <div class="obwp-agenda-sidebar" id="obwp-agenda-sidebar">
            <div class="obwp-sidebar-content" id="obwp-booking-detail">
                <p class="obwp-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32"><path d="M15 12H3m0 0 4-4m-4 4 4 4M21 12h-3"/></svg>
                    <?php esc_html_e( 'Haz clic en una reserva para ver sus detalles aquí.', 'openbooking-wp' ); ?>
                </p>
            </div>
        </div>
    </div>
</div>
