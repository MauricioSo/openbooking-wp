<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php esc_html_e( 'Pagos', 'openbooking-wp' ); ?></h1>

    <form id="obwp-payments-form" class="obwp-form">
        <?php wp_nonce_field( 'obwp_save_payments', '_obwp_nonce' ); ?>

        <div class="obwp-field">
            <label><?php esc_html_e( 'País actual', 'openbooking-wp' ); ?></label>
            <p class="description"><?php echo esc_html( get_option( 'obwp_business_country', '—' ) ); ?></p>
        </div>

        <div class="obwp-field">
            <label><?php esc_html_e( 'Forma de cobro', 'openbooking-wp' ); ?></label>
            <div class="obwp-radio-group">
                <label><input type="radio" name="payment_mode" value="full" <?php checked( get_option( 'obwp_payment_mode', 'full' ), 'full' ); ?>> <?php esc_html_e( 'Pago completo', 'openbooking-wp' ); ?></label>
                <label><input type="radio" name="payment_mode" value="deposit" <?php checked( get_option( 'obwp_payment_mode' ), 'deposit' ); ?>> <?php esc_html_e( 'Anticipo', 'openbooking-wp' ); ?></label>
                <label><input type="radio" name="payment_mode" value="manual" <?php checked( get_option( 'obwp_payment_mode' ), 'manual' ); ?>> <?php esc_html_e( 'Pago manual / en local', 'openbooking-wp' ); ?></label>
            </div>
        </div>

        <div class="obwp-field obwp-deposit-field<?php echo get_option( 'obwp_payment_mode' ) === 'deposit' ? '' : ' ob-is-hidden'; ?>" id="obwp-deposit-field">
            <label for="deposit_percent"><?php esc_html_e( 'Porcentaje de anticipo (%)', 'openbooking-wp' ); ?></label>
            <input type="number" id="deposit_percent" name="deposit_percent" min="1" max="99" step="1"
                   value="<?php echo esc_attr( (int) get_option( 'obwp_deposit_percent', 30 ) ); ?>" class="ob-input-compact">
            <p class="description"><?php esc_html_e( 'El cliente pagará este porcentaje del total al reservar.', 'openbooking-wp' ); ?></p>
        </div>

        <div class="obwp-field">
            <label for="checkout_ttl_minutes"><?php esc_html_e( 'Tiempo de vigencia del checkout (minutos)', 'openbooking-wp' ); ?></label>
            <input type="number" id="checkout_ttl_minutes" name="checkout_ttl_minutes" min="5" max="1440" step="1" class="ob-input-compact"
                   value="<?php echo esc_attr( (int) get_option( 'obwp_checkout_ttl_minutes', 30 ) ); ?>">
            <p class="description"><?php esc_html_e( 'Tiempo durante el cual reutilizamos el enlace de pago antes de generar uno nuevo.', 'openbooking-wp' ); ?></p>
        </div>

        <script>
        (function(){
            var radios = document.querySelectorAll('input[name="payment_mode"]');
            var field  = document.getElementById('obwp-deposit-field');
            if ( ! field ) return;
            radios.forEach(function(r){
                r.addEventListener('change', function(){
                    field.style.display = (this.value === 'deposit') ? '' : 'none';
                });
            });
        })();
        </script>

        <div class="obwp-section">
            <div class="ob-section-title"><?php esc_html_e( 'Medios disponibles para este país', 'openbooking-wp' ); ?></div>
            <div id="obwp-gateways-list">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando medios de pago...', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <?php /* Stripe gateway settings */ ?>
        <div class="obwp-section obwp-gateway-config ob-is-hidden" id="obwp-stripe-config">
            <div class="ob-section-title"><?php esc_html_e( 'Configuración de Stripe', 'openbooking-wp' ); ?></div>
            <form class="obwp-gateway-settings-form" data-gateway="stripe">
                <div class="obwp-field">
                    <label for="stripe_secret_key"><?php esc_html_e( 'Clave secreta (sk_...)', 'openbooking-wp' ); ?></label>
                    <input type="password" id="stripe_secret_key" name="secret_key"
                           value=""
                           placeholder="<?php echo get_option( 'obwp_stripe_secret_key', '' ) ? esc_attr__( 'Ya configurada; deja vacío para conservarla', 'openbooking-wp' ) : esc_attr__( 'sk_live_...', 'openbooking-wp' ); ?>" autocomplete="off">
                </div>
                <div class="obwp-field">
                    <label for="stripe_publishable_key"><?php esc_html_e( 'Clave publicable (pk_...)', 'openbooking-wp' ); ?></label>
                    <input type="text" id="stripe_publishable_key" name="publishable_key"
                           value="<?php echo esc_attr( get_option( 'obwp_stripe_publishable_key', '' ) ); ?>"
                           placeholder="pk_live_...">
                </div>
                <div class="obwp-field">
                    <label for="stripe_webhook_secret"><?php esc_html_e( 'Webhook secret (whsec_...)', 'openbooking-wp' ); ?></label>
                    <input type="password" id="stripe_webhook_secret" name="webhook_secret"
                           value=""
                           placeholder="<?php echo get_option( 'obwp_stripe_webhook_secret', '' ) ? esc_attr__( 'Ya configurado; deja vacío para conservarlo', 'openbooking-wp' ) : esc_attr__( 'whsec_...', 'openbooking-wp' ); ?>" autocomplete="off">
                    <p class="description">
                        <?php
                        $wh_url = rest_url( 'openbooking/v1/payments/webhook/stripe' );
                        printf(
                            /* translators: %s: webhook URL */
                            esc_html__( 'URL de webhook: %s', 'openbooking-wp' ),
                            '<code>' . esc_html( $wh_url ) . '</code>'
                        );
                        ?>
                    </p>
                </div>
                <button type="submit" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Guardar configuración Stripe', 'openbooking-wp' ); ?></button>
            </form>
        </div>

        <?php /* MercadoPago gateway settings */ ?>
        <div class="obwp-section obwp-gateway-config ob-is-hidden" id="obwp-mercadopago-config">
            <div class="ob-section-title"><?php esc_html_e( 'Configuración de MercadoPago', 'openbooking-wp' ); ?></div>
            <form class="obwp-gateway-settings-form" data-gateway="mercadopago">
                <div class="obwp-field">
                    <label for="mp_access_token"><?php esc_html_e( 'Access Token', 'openbooking-wp' ); ?></label>
                    <input type="password" id="mp_access_token" name="access_token"
                           value=""
                           placeholder="<?php echo get_option( 'obwp_mp_access_token', '' ) ? esc_attr__( 'Ya configurado; deja vacío para conservarlo', 'openbooking-wp' ) : esc_attr__( 'APP_USR-...', 'openbooking-wp' ); ?>" autocomplete="off">
                </div>
                <div class="obwp-field">
                    <label>
                        <input type="checkbox" name="sandbox" value="1" <?php checked( get_option( 'obwp_mp_sandbox', false ) ); ?>>
                        <?php esc_html_e( 'Modo sandbox (pruebas)', 'openbooking-wp' ); ?>
                    </label>
                </div>
                <div class="obwp-field">
                    <label for="mp_webhook_secret"><?php esc_html_e( 'Webhook secret', 'openbooking-wp' ); ?></label>
                    <input type="password" id="mp_webhook_secret" name="webhook_secret"
                           value=""
                           placeholder="<?php echo get_option( 'obwp_mp_webhook_secret', '' ) ? esc_attr__( 'Ya configurado; deja vacío para conservarlo', 'openbooking-wp' ) : esc_attr__( 'Secreto de firma opcional', 'openbooking-wp' ); ?>" autocomplete="off">
                    <p class="description"><?php esc_html_e( 'Si MercadoPago envía firma en tus webhooks, guárdala aquí para validar notificaciones entrantes.', 'openbooking-wp' ); ?></p>
                </div>
                <div class="obwp-field">
                    <p class="description">
                        <?php
                        $wh_url = rest_url( 'openbooking/v1/payments/webhook/mercadopago' );
                        printf(
                            /* translators: %s: webhook URL */
                            esc_html__( 'URL de webhook: %s', 'openbooking-wp' ),
                            '<code>' . esc_html( $wh_url ) . '</code>'
                        );
                        ?>
                    </p>
                </div>
                <button type="submit" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Guardar configuración MercadoPago', 'openbooking-wp' ); ?></button>
            </form>
        </div>

        <?php /* Webpay (Transbank) gateway settings */ ?>
        <div class="obwp-section obwp-gateway-config ob-is-hidden" id="obwp-webpay-config">
            <div class="ob-section-title"><?php esc_html_e( 'Configuración de Webpay (Transbank)', 'openbooking-wp' ); ?></div>
            <form class="obwp-gateway-settings-form" data-gateway="webpay">
                <div class="obwp-field">
                    <label for="webpay_commerce_code"><?php esc_html_e( 'Código de comercio', 'openbooking-wp' ); ?></label>
                    <input type="text" id="webpay_commerce_code" name="commerce_code"
                           value="<?php echo esc_attr( get_option( 'obwp_webpay_commerce_code', '' ) ); ?>"
                           placeholder="<?php esc_attr_e( 'Ej: 597055555532 (sandbox)', 'openbooking-wp' ); ?>">
                </div>
                <div class="obwp-field">
                    <label for="webpay_api_key"><?php esc_html_e( 'API Key secret', 'openbooking-wp' ); ?></label>
                    <input type="password" id="webpay_api_key" name="api_key"
                           value=""
                           placeholder="<?php echo get_option( 'obwp_webpay_api_key', '' ) ? esc_attr__( 'Ya configurada; deja vacío para conservarla', 'openbooking-wp' ) : esc_attr__( 'API Key entregada por Transbank', 'openbooking-wp' ); ?>" autocomplete="off">
                </div>
                <div class="obwp-field">
                    <label>
                        <input type="checkbox" name="sandbox" value="1" <?php checked( get_option( 'obwp_webpay_sandbox', '1' ) ); ?>>
                        <?php esc_html_e( 'Modo sandbox (pruebas)', 'openbooking-wp' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'En sandbox usa código 597055555532 y la API Key pública de Transbank.', 'openbooking-wp' ); ?></p>
                </div>
                <div class="obwp-field">
                    <p class="description">
                        <?php
                        $return_url = rest_url( 'openbooking/v1/payments/webpay-return' );
                        printf(
                            /* translators: %s: return URL */
                            esc_html__( 'URL de retorno (configura en Transbank): %s', 'openbooking-wp' ),
                            '<code>' . esc_html( $return_url ) . '</code>'
                        );
                        ?>
                    </p>
                </div>
                <button type="submit" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Guardar configuración Webpay', 'openbooking-wp' ); ?></button>
            </form>
        </div>

        <script>
        (function(){
            // Show gateway config panels when gateways list loads and user enables a gateway.
            function showGatewayConfig() {
                var gateways  = document.querySelectorAll('.obwp-gateway-toggle');
                gateways.forEach(function(chk){
                    var key    = chk.dataset.gateway;
                    var panel  = document.getElementById('obwp-' + key + '-config');
                    if ( panel ) panel.style.display = chk.checked ? '' : 'none';
                    chk.addEventListener('change', function(){
                        if ( panel ) panel.style.display = this.checked ? '' : 'none';
                    });
                });
            }
            // Run after JS builds the gateways list.
            document.addEventListener('obwpGatewaysLoaded', showGatewayConfig);
        })();
        </script>

        <div class="obwp-section">
            <div class="ob-section-title"><?php esc_html_e( 'Pagos recientes', 'openbooking-wp' ); ?></div>
            <div id="obwp-payments-recent">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando...', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-section">
            <div class="ob-section-title"><?php esc_html_e( 'Historial / Auditoria', 'openbooking-wp' ); ?></div>
            <div id="obwp-payments-audit-log">
                <p class="obwp-empty"><?php esc_html_e( 'Selecciona un pago para ver su auditoria.', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-form-actions">
            <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar cambios', 'openbooking-wp' ); ?></button>
        </div>
    </form>
</div>
