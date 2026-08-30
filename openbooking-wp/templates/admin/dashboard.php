<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <div class="ob-page-header">
        <div style="display:flex;align-items:center;gap:12px">
            <svg width="42" height="42" viewBox="0 0 120 120" fill="none" aria-hidden="true">
                <rect x="14" y="22" width="92" height="84" rx="24" fill="var(--ob-accent)"/>
                <rect x="14" y="22" width="92" height="28" rx="24" fill="var(--ob-accent-2)"/>
                <rect x="14" y="38" width="92" height="12" fill="var(--ob-accent-2)"/>
                <rect x="34" y="10" width="12" height="24" rx="6" fill="var(--ob-text-primary)"/>
                <rect x="74" y="10" width="12" height="24" rx="6" fill="var(--ob-text-primary)"/>
                <circle cx="42" cy="64" r="6" fill="var(--ob-text-primary)"/>
                <circle cx="78" cy="64" r="6" fill="var(--ob-text-primary)"/>
                <path d="M42 78C50 90 70 90 78 78" stroke="var(--ob-text-primary)" stroke-width="5" stroke-linecap="round" fill="none"/>
            </svg>
            <div>
                <h1 class="ob-page-title" style="margin:0"><?php esc_html_e( 'Inicio', 'openbooking-wp' ); ?></h1>
                <p class="ob-meta" style="margin:0"><?php echo date_i18n( 'l, j \d\e F Y' ); ?></p>
            </div>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&action=new' ) ); ?>" class="ob-btn ob-btn-primary">+ Nuevo servicio</a>
    </div>

    <div class="ob-stats-grid" id="obwp-dashboard-stats">
        <div class="ob-stat-card">
            <div class="ob-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="var(--ob-accent)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="ob-stat-value" id="obwp-stat-today">-</div>
            <div class="ob-stat-label"><?php esc_html_e( 'Reservas hoy', 'openbooking-wp' ); ?></div>
        </div>
        <div class="ob-stat-card">
            <div class="ob-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="var(--ob-warning)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="ob-stat-value" id="obwp-stat-pending">-</div>
            <div class="ob-stat-label"><?php esc_html_e( 'Pendientes', 'openbooking-wp' ); ?></div>
        </div>
        <div class="ob-stat-card">
            <div class="ob-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="var(--ob-danger)" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
            <div class="ob-stat-value" id="obwp-stat-unpaid">-</div>
            <div class="ob-stat-label"><?php esc_html_e( 'Sin pagar', 'openbooking-wp' ); ?></div>
        </div>
    </div>

    <div class="ob-card ob-is-hidden" id="obwp-urgency-section">
        <div class="ob-section-title ob-accent-danger"><?php esc_html_e( 'Requiere atención', 'openbooking-wp' ); ?></div>
        <p class="ob-meta"><?php esc_html_e( 'Alertas operativas y diagnóstico rápido. Úsalo solo si algo necesita revisión.', 'openbooking-wp' ); ?></p>
        <div id="obwp-integrity-summary" class="ob-meta ob-space-bottom-sm"><?php esc_html_e( 'Cargando diagnóstico...', 'openbooking-wp' ); ?></div>
        <ul id="obwp-urgency-list" class="obwp-booking-list"></ul>
        <div class="ob-static-actions">
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-run-reconcile"><?php esc_html_e( 'Reconciliar ahora', 'openbooking-wp' ); ?></button>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-run-integrity"><?php esc_html_e( 'Ver diagnóstico', 'openbooking-wp' ); ?></button>
        </div>
        <div id="obwp-integrity-result" class="ob-is-hidden ob-space-top-sm"></div>
    </div>

    <div class="ob-card">
        <div class="ob-section-title"><?php esc_html_e( 'Próximas reservas', 'openbooking-wp' ); ?></div>
        <div id="obwp-today-bookings">
            <p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p>
        </div>
    </div>

    <div class="ob-card">
        <div class="ob-section-title"><?php esc_html_e( 'Nuevas reservas (últimas 48 h)', 'openbooking-wp' ); ?></div>
        <div id="obwp-recent-bookings">
            <p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p>
        </div>
    </div>

    <div class="ob-card">
        <div class="ob-section-title"><?php esc_html_e( 'Acciones rapidas', 'openbooking-wp' ); ?></div>
        <div class="obwp-quick-actions ob-stack-md">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&action=new' ) ); ?>" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Crear servicio', 'openbooking-wp' ); ?></a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&tab=recursos' ) ); ?>" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Agregar recurso', 'openbooking-wp' ); ?></a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&tab=disponibilidad' ) ); ?>" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Bloquear horario', 'openbooking-wp' ); ?></a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-reservas&tab=agenda' ) ); ?>" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Ver agenda', 'openbooking-wp' ); ?></a>
        </div>
    </div>

    <div class="ob-card ob-is-hidden" id="obwp-readiness-section">
        <div class="ob-section-title"><?php esc_html_e( 'Estado de publicacion', 'openbooking-wp' ); ?></div>
        <div id="obwp-readiness-checklist"></div>
        <div id="obwp-readiness-bar" class="ob-stack-md">
            <div class="ob-progress-track">
                <div class="ob-progress-fill" id="obwp-readiness-fill"></div>
            </div>
            <p class="ob-meta ob-progress-label" id="obwp-readiness-label"></p>
        </div>
    </div>
</div>
