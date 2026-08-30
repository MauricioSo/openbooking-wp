<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$ui_config = get_option( 'obwp_ui_config', [] );
// Validate colors as strict HEX to prevent CSS injection.
$primary   = sanitize_hex_color( $ui_config['color_primary'] ?? '' ) ?: '#111111';
$bg        = sanitize_hex_color( $ui_config['color_bg']      ?? '' ) ?: '#ffffff';
$text_clr  = sanitize_hex_color( $ui_config['color_text']   ?? '' ) ?: '#1f1f1f';
// Font family: allow only safe characters (letters, digits, spaces, commas, hyphens, underscores, dots).
$font      = preg_replace( '/[^a-zA-Z0-9\s,\-_.]/', '', $ui_config['font_family'] ?? 'Inter, sans-serif' ) ?: 'Inter, sans-serif';
// Radius: allow only a valid CSS dimension (e.g. 12px, 0.5em, 50%).
$radius    = ( preg_match( '/^\d+(\.\d+)?(px|em|rem|%)$/', $ui_config['radius'] ?? '' ) ) ? $ui_config['radius'] : '12px';
$service_slug = $atts['service'] ?? '';

// Preferred gateway: first enabled gateway for this country (skip manual if others exist).
$country   = get_option( 'obwp_business_country', '' );
$gateways  = \OpenBooking\Infrastructure\PaymentGateway\Gateway_Registry::get_enabled_for_country( $country );
$preferred = 'manual';
foreach ( $gateways as $gw ) {
    if ( $gw->get_key() !== 'manual' && $gw->is_enabled() ) { $preferred = $gw->get_key(); break; }
}
?>

<style>
:root {
    --ob-color-primary: <?php echo esc_html( $primary ); ?>;
    --ob-color-bg:      <?php echo esc_html( $bg ); ?>;
    --ob-color-text:    <?php echo esc_html( $text_clr ); ?>;
    --ob-font-family:   <?php echo esc_html( $font ); ?>;
    --ob-radius:        <?php echo esc_html( $radius ); ?>;
}
</style>

<div id="obwp-booking-app"
     class="obwp-public"
     data-service="<?php echo esc_attr( $service_slug ); ?>"
     role="main"
     aria-label="<?php esc_attr_e( 'Formulario de reserva', 'openbooking-wp' ); ?>">

    <div id="obwp-flow-notice" class="obwp-flow-notice" style="display:none;" aria-live="polite"></div>

    <aside id="obwp-booking-summary" class="obwp-booking-summary" aria-live="polite"></aside>

    <?php /* Stepper — 4 visible steps (step 4 = payment is hidden if free) */ ?>
    <nav class="obwp-stepper" aria-label="<?php esc_attr_e( 'Pasos del proceso', 'openbooking-wp' ); ?>">
        <div class="obwp-step obwp-step--active" data-step="1" aria-current="step">
            <span class="obwp-step-num" aria-hidden="true">1</span>
            <span class="obwp-step-label" id="step-1-label" data-step-short="<?php esc_attr_e( 'Servicio', 'openbooking-wp' ); ?>"><?php esc_html_e( 'Servicio', 'openbooking-wp' ); ?></span>
        </div>
        <div class="obwp-step" data-step="2">
            <span class="obwp-step-num" aria-hidden="true">2</span>
            <span class="obwp-step-label" data-step-short="<?php esc_attr_e( 'Fecha', 'openbooking-wp' ); ?>"><?php esc_html_e( 'Fecha y hora', 'openbooking-wp' ); ?></span>
        </div>
        <div class="obwp-step" data-step="3">
            <span class="obwp-step-num" aria-hidden="true">3</span>
            <span class="obwp-step-label" data-step-short="<?php esc_attr_e( 'Datos', 'openbooking-wp' ); ?>"><?php esc_html_e( 'Tus datos', 'openbooking-wp' ); ?></span>
        </div>
        <div class="obwp-step" data-step="4">
            <span class="obwp-step-num" aria-hidden="true">4</span>
            <span class="obwp-step-label" data-step-short="<?php esc_attr_e( 'Pago', 'openbooking-wp' ); ?>"><?php esc_html_e( 'Pago', 'openbooking-wp' ); ?></span>
        </div>
    </nav>

    <div class="obwp-step-panels">

        <?php /* Panel 1: Select service */ ?>
        <div class="obwp-panel obwp-panel--active" data-panel="1" role="tabpanel" aria-labelledby="step-1-label">
            <h2 class="obwp-panel-title"><?php esc_html_e( 'Reserva tu hora', 'openbooking-wp' ); ?></h2>
            <p class="obwp-panel-subtitle"><?php esc_html_e( 'Selecciona un servicio', 'openbooking-wp' ); ?></p>
            <div id="obwp-services-list" class="obwp-service-cards" role="list" aria-live="polite">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando servicios...', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <?php /* Panel 2: Date & time */ ?>
        <div class="obwp-panel" data-panel="2" role="tabpanel" aria-labelledby="step-2-label">
            <h2 class="obwp-panel-title" id="step-2-label"><?php esc_html_e( 'Reserva tu hora', 'openbooking-wp' ); ?></h2>
            <p class="obwp-panel-subtitle" id="obwp-selected-service-name" aria-live="polite"></p>

            <div class="obwp-date-picker-section" aria-label="<?php esc_attr_e( 'Calendario', 'openbooking-wp' ); ?>">
                <?php /* Calendar rendered by JS */ ?>
            </div>

            <div class="obwp-time-picker-section" id="obwp-time-section" style="display:none;">
                <label class="obwp-field-label" for="obwp-time-slots"><?php esc_html_e( 'Horarios disponibles', 'openbooking-wp' ); ?></label>
                <div id="obwp-time-slots" class="obwp-time-grid" role="listbox" aria-live="polite"></div>
            </div>

            <div class="obwp-nav-buttons">
                <button class="obwp-btn obwp-btn--secondary obwp-btn-prev" data-to="1">
                    <?php esc_html_e( 'Volver', 'openbooking-wp' ); ?>
                </button>
            </div>
        </div>

        <?php /* Panel 3: Customer data */ ?>
        <div class="obwp-panel" data-panel="3" role="tabpanel" aria-labelledby="step-3-label">
            <h2 class="obwp-panel-title" id="step-3-label"><?php esc_html_e( 'Tus datos', 'openbooking-wp' ); ?></h2>
            <div id="obwp-form-fields" aria-live="polite">
                <?php /* Fields rendered dynamically by JS (from ob_form_fields table) */ ?>
            </div>

            <div class="obwp-nav-buttons">
                <button class="obwp-btn obwp-btn--secondary obwp-btn-prev" data-to="2">
                    <?php esc_html_e( 'Volver', 'openbooking-wp' ); ?>
                </button>
                <button class="obwp-btn obwp-btn--primary obwp-btn-next" data-to="4">
                    <?php esc_html_e( 'Confirmar reserva', 'openbooking-wp' ); ?>
                </button>
            </div>
        </div>

        <?php /* Panel 4: Payment */ ?>
        <div class="obwp-panel" data-panel="4" role="tabpanel" aria-labelledby="step-4-label">
            <h2 class="obwp-panel-title" id="step-4-label"><?php esc_html_e( 'Pago', 'openbooking-wp' ); ?></h2>
            <div id="obwp-payment-panel" aria-live="polite">
                <p class="obwp-loading"><?php esc_html_e( 'Preparando pago...', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-nav-buttons">
                <button class="obwp-btn obwp-btn--secondary obwp-btn-prev" data-to="3">
                    <?php esc_html_e( 'Volver', 'openbooking-wp' ); ?>
                </button>
            </div>
        </div>

        <?php /* Panel 5: Confirmation */ ?>
        <div class="obwp-panel" data-panel="5" role="tabpanel" aria-labelledby="step-5-label">
            <h2 class="obwp-panel-title screen-reader-text" id="step-5-label"><?php esc_html_e( 'Confirmación', 'openbooking-wp' ); ?></h2>
            <div class="obwp-confirmation" id="obwp-confirmation" aria-live="assertive">
                <?php /* Populated by JS */ ?>
            </div>
        </div>

    </div>
</div>

<?php
// Pass preferred gateway to JS.
wp_add_inline_script( 'obwp-public', 'window.obwpFront = window.obwpFront || {}; obwpFront.preferredGateway = ' . wp_json_encode( $preferred ) . ';' );
?>
