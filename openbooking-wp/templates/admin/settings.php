<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php esc_html_e( 'Ajustes', 'openbooking-wp' ); ?></h1>

    <form id="obwp-settings-form" class="obwp-form" method="post">
        <?php wp_nonce_field( 'obwp_save_settings', '_obwp_nonce' ); ?>

        <div class="obwp-tabs ob-tabs">
            <button type="button" class="obwp-tab active" data-tab="general"><?php esc_html_e( 'General', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="emails"><?php esc_html_e( 'Emails', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="notifications"><?php esc_html_e( 'Notificaciones', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="integrations"><?php esc_html_e( 'Integraciones', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="policies"><?php esc_html_e( 'Políticas', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="advanced"><?php esc_html_e( 'Avanzado', 'openbooking-wp' ); ?></button>
        </div>

        <div class="obwp-tab-panel active" id="tab-general">
            <div class="obwp-field">
                <label for="settings_business_name"><?php esc_html_e( 'Nombre del negocio', 'openbooking-wp' ); ?></label>
                <input type="text" id="settings_business_name" name="business_name" value="<?php echo esc_attr( get_option( 'obwp_business_name', '' ) ); ?>">
            </div>
            <div class="obwp-field-row">
                <div class="obwp-field">
                    <label for="settings_country"><?php esc_html_e( 'País', 'openbooking-wp' ); ?></label>
                    <select id="settings_country" name="country">
                        <?php
                        $countries = [ 'CL' => 'Chile', 'CO' => 'Colombia', 'MX' => 'México', 'AR' => 'Argentina', 'PE' => 'Perú', 'BR' => 'Brasil', 'US' => 'Estados Unidos', 'ES' => 'España' ];
                        $current = get_option( 'obwp_business_country', '' );
                        foreach ( $countries as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="obwp-field">
                    <label for="settings_currency"><?php esc_html_e( 'Moneda', 'openbooking-wp' ); ?></label>
                    <select id="settings_currency" name="currency">
                        <?php $currencies = [ 'USD' => 'USD', 'CLP' => 'CLP', 'COP' => 'COP', 'MXN' => 'MXN', 'EUR' => 'EUR', 'ARS' => 'ARS', 'PEN' => 'PEN', 'BRL' => 'BRL' ]; ?>
                        <?php foreach ( $currencies as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( get_option( 'obwp_business_currency', 'USD' ), $code ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="obwp-field-row">
                <div class="obwp-field">
                    <label for="settings_timezone"><?php esc_html_e( 'Zona horaria', 'openbooking-wp' ); ?></label>
                    <select id="settings_timezone" name="timezone">
                        <?php $tzs = [ 'UTC', 'America/Santiago', 'America/Bogota', 'America/Mexico_City', 'America/Buenos_Aires', 'America/Lima', 'America/Sao_Paulo', 'America/New_York', 'Europe/Madrid' ]; ?>
                        <?php foreach ( $tzs as $tz ) : ?>
                            <option value="<?php echo esc_attr( $tz ); ?>" <?php selected( get_option( 'obwp_business_timezone', 'UTC' ), $tz ); ?>><?php echo esc_html( $tz ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="obwp-field">
                    <label for="settings_language"><?php esc_html_e( 'Idioma', 'openbooking-wp' ); ?></label>
                    <select id="settings_language" name="language">
                        <option value="es" <?php selected( get_option( 'obwp_business_language', 'es' ), 'es' ); ?>><?php esc_html_e( 'Español', 'openbooking-wp' ); ?></option>
                        <option value="en" <?php selected( get_option( 'obwp_business_language' ), 'en' ); ?>>English</option>
                        <option value="pt" <?php selected( get_option( 'obwp_business_language' ), 'pt' ); ?>>Português</option>
                    </select>
                </div>
            </div>
            <div class="obwp-section">
                <div class="ob-section-title"><?php esc_html_e( 'Página de reservas', 'openbooking-wp' ); ?></div>

                <?php
                // Detect current booking page
                $saved_url    = get_option( 'obwp_public_booking_page_url', '' );
                $detected_url = \OpenBooking\Support\Public_Booking_Page::get_url();
                $detected_page = null;
                $pages = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1 ] );
                foreach ( $pages as $p ) {
                    if ( has_shortcode( $p->post_content, 'openbooking' ) ) { $detected_page = $p; break; }
                }
                ?>

                <?php if ( $detected_page ) : ?>
                <div class="obwp-notice obwp-notice-success">
                    ✓ <?php
                    printf(
                        /* translators: %1$s: page title, %2$s: edit link, %3$s: view link */
                        esc_html__( 'Página detectada: %1$s — %2$s · %3$s', 'openbooking-wp' ),
                        '<strong>' . esc_html( $detected_page->post_title ) . '</strong>',
                        '<a href="' . esc_url( get_edit_post_link( $detected_page->ID ) ) . '" target="_blank">' . esc_html__( 'Editar', 'openbooking-wp' ) . '</a>',
                        '<a href="' . esc_url( get_permalink( $detected_page->ID ) ) . '" target="_blank">' . esc_html__( 'Ver', 'openbooking-wp' ) . '</a>'
                    );
                    ?>
                </div>
                <?php else : ?>
                <div class="obwp-notice obwp-notice-warning">
                    <?php esc_html_e( 'No se encontró ninguna página con el shortcode [openbooking]. Crea una o usa el botón de abajo.', 'openbooking-wp' ); ?>
                </div>
                <?php endif; ?>

                <div class="obwp-field">
                    <label><?php esc_html_e( 'Cómo mostrar el formulario de reservas', 'openbooking-wp' ); ?></label>
                    <div class="obwp-shortcode-instructions">
                        <p><?php esc_html_e( '1. Crea una página en WordPress (Páginas → Añadir nueva).', 'openbooking-wp' ); ?></p>
                        <p><?php esc_html_e( '2. Agrega el shortcode en el contenido de la página:', 'openbooking-wp' ); ?></p>
                        <code class="obwp-shortcode-display">[openbooking]</code>
                        <button type="button" class="ob-btn ob-btn-secondary ob-btn-sm" onclick="navigator.clipboard.writeText('[openbooking]');this.textContent='<?php esc_attr_e( '¡Copiado!', 'openbooking-wp' ); ?>';setTimeout(()=>this.textContent='<?php esc_attr_e( 'Copiar shortcode', 'openbooking-wp' ); ?>',2000)">
                            <?php esc_html_e( 'Copiar shortcode', 'openbooking-wp' ); ?>
                        </button>
                        <p class="ob-space-top-sm"><?php esc_html_e( '3. Publica la página y guarda su URL abajo.', 'openbooking-wp' ); ?></p>
                    </div>
                </div>

                <div class="obwp-field">
                    <label><?php esc_html_e( 'Parámetros opcionales del shortcode', 'openbooking-wp' ); ?></label>
                    <table class="obwp-shortcode-params">
                        <tr><td><code>[openbooking]</code></td><td><?php esc_html_e( 'Muestra todos los servicios activos', 'openbooking-wp' ); ?></td></tr>
                        <tr><td><code>[openbooking service="42"]</code></td><td><?php esc_html_e( 'Abre directo en el servicio con ID 42', 'openbooking-wp' ); ?></td></tr>
                        <tr><td><code>[openbooking layout="steps"]</code></td><td><?php esc_html_e( 'Diseño por pasos (por defecto)', 'openbooking-wp' ); ?></td></tr>
                    </table>
                </div>

                <?php if ( ! $detected_page ) : ?>
                <div class="obwp-field">
                    <button type="button" id="obwp-create-booking-page" class="ob-btn ob-btn-secondary">
                        <?php esc_html_e( '+ Crear página de reservas automáticamente', 'openbooking-wp' ); ?>
                    </button>
                    <p class="description"><?php esc_html_e( 'Crea una página llamada "Reservas" con el shortcode ya insertado.', 'openbooking-wp' ); ?></p>
                    <p id="obwp-create-page-result" class="ob-is-hidden ob-space-top-sm"></p>
                </div>
                <script>
                document.getElementById('obwp-create-booking-page').addEventListener('click', function() {
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = '<?php esc_js( esc_html_e( 'Creando...', 'openbooking-wp' ) ); ?>';
                    fetch('<?php echo esc_url( rest_url( 'openbooking/v1/admin/create-booking-page' ) ); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
                    })
                    .then(r => r.json())
                    .then(data => {
                        var el = document.getElementById('obwp-create-page-result');
                        el.style.display = '';
                        el.textContent = '';
                        if (data.page_url) {
                            var check = document.createTextNode('✓ <?php esc_html_e( 'Página creada:', 'openbooking-wp' ); ?> ');
                            var a = document.createElement('a');
                            a.href = data.page_url;
                            a.target = '_blank';
                            a.rel = 'noopener noreferrer';
                            a.textContent = data.page_url;
                            var suffix = document.createTextNode('. <?php esc_html_e( 'Recarga esta página para ver los cambios.', 'openbooking-wp' ); ?>');
                            el.appendChild(check);
                            el.appendChild(a);
                            el.appendChild(suffix);
                            document.getElementById('settings_public_booking_page_url').value = data.page_url;
                        } else {
                            el.textContent = (data.error && typeof data.error === 'string') ? data.error : '<?php esc_html_e( 'Error al crear la página.', 'openbooking-wp' ); ?>';
                            btn.disabled = false;
                            btn.textContent = '<?php esc_html_e( '+ Crear página de reservas automáticamente', 'openbooking-wp' ); ?>';
                        }
                    });
                });
                </script>
                <?php endif; ?>

                <div class="obwp-field">
                    <label for="settings_public_booking_page_url"><?php esc_html_e( 'URL de la página de reservas', 'openbooking-wp' ); ?></label>
                    <input type="url" id="settings_public_booking_page_url" name="public_booking_page_url"
                           value="<?php echo esc_attr( $saved_url ); ?>"
                           placeholder="<?php echo esc_attr( $detected_url ); ?>">
                    <p class="description">
                        <?php esc_html_e( 'MercadoPago, Webpay y los emails de confirmación usan esta URL para redirigir al cliente. Si está vacía, se detecta automáticamente desde la página con el shortcode.', 'openbooking-wp' ); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-emails">
            <div class="obwp-field">
                <label for="email_sender_name"><?php esc_html_e( 'Nombre del remitente', 'openbooking-wp' ); ?></label>
                <input type="text" id="email_sender_name" name="email_sender_name" value="<?php echo esc_attr( get_option( 'obwp_email_sender_name', get_bloginfo( 'name' ) ) ); ?>">
            </div>
            <div class="obwp-field">
                <label for="email_sender_address"><?php esc_html_e( 'Email del remitente', 'openbooking-wp' ); ?></label>
                <input type="email" id="email_sender_address" name="email_sender_address" value="<?php echo esc_attr( get_option( 'obwp_email_sender_address', get_bloginfo( 'admin_email' ) ) ); ?>">
            </div>
            <div class="obwp-field">
                <label><?php esc_html_e( 'Plantillas de email', 'openbooking-wp' ); ?></label>
                <div id="obwp-email-templates" class="obwp-sortable-list">
                    <p class="obwp-loading"><?php esc_html_e( 'Cargando plantillas...', 'openbooking-wp' ); ?></p>
                </div>
            </div>
            <div class="obwp-field ob-field-block">
                <label><?php esc_html_e( 'Enviar email de prueba', 'openbooking-wp' ); ?></label>
                <div class="ob-flex-row">
                    <input type="email" id="obwp-test-email-to" placeholder="<?php esc_attr_e( 'Email destinatario', 'openbooking-wp' ); ?>" class="regular-text" value="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>">
                    <button type="button" class="ob-btn ob-btn-secondary" id="obwp-send-test-email"><?php esc_html_e( 'Enviar prueba', 'openbooking-wp' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'Envía un email de prueba para verificar la configuración de envío.', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-notifications">

            <h3 class="ob-section-title ob-title-reset"><?php esc_html_e( 'Configuración de Email', 'openbooking-wp' ); ?></h3>

            <div class="obwp-field">
                <label>
                    <input type="checkbox" id="notif_email_html_enabled" value="1">
                    <?php esc_html_e( 'Habilitar emails en HTML', 'openbooking-wp' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Envía los emails con diseño HTML responsivo. Si está desactivado se envía texto plano.', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-field ob-is-hidden" id="obwp-email-accent-row">
                <label for="notif_email_accent_color"><?php esc_html_e( 'Color de acento del email', 'openbooking-wp' ); ?></label>
                <input type="color" id="notif_email_accent_color" value="#2563eb">
                <p class="description"><?php esc_html_e( 'Color del encabezado y enlaces en el email HTML.', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-form-actions ob-form-actions-with-bottom">
                <button type="button" id="obwp-save-email-notif" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar ajustes de email', 'openbooking-wp' ); ?></button>
            </div>

            <hr class="ob-divider">

            <h3 class="ob-section-title"><?php esc_html_e( 'WhatsApp con API propia', 'openbooking-wp' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Disponible usando tus propias credenciales de Twilio o Meta. La entrega depende de tu sitio, del proveedor configurado y de WP-Cron.', 'openbooking-wp' ); ?></p>

            <div class="obwp-field">
                <label>
                    <input type="checkbox" id="notif_whatsapp_enabled" value="1">
                    <?php esc_html_e( 'Habilitar WhatsApp con mis propias credenciales', 'openbooking-wp' ); ?>
                </label>
            </div>

            <div id="obwp-wa-config">
                <div class="obwp-field">
                    <label for="notif_whatsapp_provider"><?php esc_html_e( 'Proveedor', 'openbooking-wp' ); ?></label>
                    <select id="notif_whatsapp_provider" class="ob-select-md">
                        <option value="twilio">Twilio</option>
                        <option value="meta"><?php esc_html_e( 'Meta (WhatsApp Cloud API)', 'openbooking-wp' ); ?></option>
                    </select>
                </div>

                <div id="obwp-wa-twilio">
                    <h4 class="ob-subtitle"><?php esc_html_e( 'Credenciales Twilio', 'openbooking-wp' ); ?></h4>
                    <div class="obwp-field-row">
                        <div class="obwp-field">
                            <label for="notif_whatsapp_twilio_sid">Account SID</label>
                            <input type="text" id="notif_whatsapp_twilio_sid" class="regular-text" autocomplete="off">
                        </div>
                        <div class="obwp-field">
                            <label for="notif_whatsapp_twilio_token">Auth Token</label>
                            <input type="password" id="notif_whatsapp_twilio_token" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Dejar vacío para no cambiar', 'openbooking-wp' ); ?>">
                        </div>
                    </div>
                    <div class="obwp-field">
                        <label for="notif_whatsapp_twilio_from"><?php esc_html_e( 'Número Twilio (from)', 'openbooking-wp' ); ?></label>
                        <input type="text" id="notif_whatsapp_twilio_from" class="regular-text" placeholder="whatsapp:+1234567890">
                        <p class="description"><?php esc_html_e( 'Debe tener el prefijo whatsapp: tal como aparece en tu consola de Twilio.', 'openbooking-wp' ); ?></p>
                    </div>
                </div>

                <div id="obwp-wa-meta" class="ob-is-hidden">
                    <h4 class="ob-subtitle"><?php esc_html_e( 'Credenciales Meta (WhatsApp Cloud API)', 'openbooking-wp' ); ?></h4>
                    <div class="obwp-field-row">
                        <div class="obwp-field">
                            <label for="notif_whatsapp_meta_token">Access Token</label>
                            <input type="password" id="notif_whatsapp_meta_token" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Dejar vacío para no cambiar', 'openbooking-wp' ); ?>">
                        </div>
                        <div class="obwp-field">
                            <label for="notif_whatsapp_meta_phone_id">Phone Number ID</label>
                            <input type="text" id="notif_whatsapp_meta_phone_id" class="regular-text">
                            <p class="description"><?php esc_html_e( 'ID del número en Meta for Developers > WhatsApp > API Setup.', 'openbooking-wp' ); ?></p>
                        </div>
                    </div>
                    <div class="obwp-field">
                        <label>
                            <input type="checkbox" id="notif_whatsapp_meta_use_templates">
                            <?php esc_html_e( 'Usar templates aprobados por Meta', 'openbooking-wp' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Necesario si tu cuenta está en Tier 1 o tiene restricciones. Meta requiere que los templates estén aprobados previamente.', 'openbooking-wp' ); ?></p>
                    </div>
                    <div id="obwp-wa-meta-tpl-wrap" class="ob-indented-block ob-is-hidden">
                        <div class="obwp-field">
                            <label for="notif_whatsapp_meta_language"><?php esc_html_e( 'Idioma de templates', 'openbooking-wp' ); ?></label>
                            <select id="notif_whatsapp_meta_language" class="ob-select-sm">
                                <option value="es">Español (es)</option>
                                <option value="en">English (en)</option>
                                <option value="pt_BR">Português (pt_BR)</option>
                            </select>
                        </div>
                        <p class="description ob-description-spaced"><?php esc_html_e( 'Ingresa el nombre exacto del template tal como está aprobado en Meta Business Manager.', 'openbooking-wp' ); ?></p>
                        <?php
                        $meta_tpls = [
                            'booking_confirmed'   => __( 'Reserva confirmada', 'openbooking-wp' ),
                            'booking_cancelled'   => __( 'Reserva cancelada', 'openbooking-wp' ),
                            'booking_rescheduled' => __( 'Reserva reprogramada', 'openbooking-wp' ),
                            'reminder_customer'   => __( 'Recordatorio al cliente', 'openbooking-wp' ),
                            'new_booking_admin'   => __( 'Nueva reserva (admin)', 'openbooking-wp' ),
                        ];
                        foreach ( $meta_tpls as $tpl_key => $tpl_label ) : ?>
                        <div class="obwp-field">
                            <label for="notif_meta_tpl_<?php echo esc_attr( $tpl_key ); ?>"><?php echo esc_html( $tpl_label ); ?></label>
                            <input type="text" id="notif_meta_tpl_<?php echo esc_attr( $tpl_key ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'nombre_del_template', 'openbooking-wp' ); ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="obwp-field ob-section-bordered">
                    <label>
                        <input type="checkbox" id="notif_whatsapp_notify_admin">
                        <?php esc_html_e( 'Notificar al admin por WhatsApp en cada nueva reserva', 'openbooking-wp' ); ?>
                    </label>
                </div>
                <div class="obwp-field">
                    <label for="notif_whatsapp_admin_phone"><?php esc_html_e( 'Teléfono del admin (con código de país)', 'openbooking-wp' ); ?></label>
                    <input type="text" id="notif_whatsapp_admin_phone" class="regular-text" placeholder="+56912345678">
                </div>
            </div>

            <div class="obwp-form-actions ob-form-actions-with-bottom">
                <button type="button" id="obwp-save-wa-notif" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar ajustes de WhatsApp', 'openbooking-wp' ); ?></button>
            </div>

            <hr class="ob-divider">

            <h3 class="ob-section-title"><?php esc_html_e( 'Mensajes de WhatsApp', 'openbooking-wp' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Personaliza el texto de cada mensaje local. Merge tags disponibles: {customer_name}, {service_name}, {booking_date}, {booking_time}, {cancel_link}, {reschedule_link}, {payment_amount}.', 'openbooking-wp' ); ?></p>
            <div id="obwp-wa-templates" class="ob-space-top-sm">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando mensajes...', 'openbooking-wp' ); ?></p>
            </div>

            <hr class="ob-divider">

            <h3 class="ob-section-title"><?php esc_html_e( 'Enviar mensaje de prueba', 'openbooking-wp' ); ?></h3>
            <div class="ob-flex-row">
                <input type="text" id="obwp-wa-test-to" class="regular-text" placeholder="+56912345678">
                <button type="button" id="obwp-send-test-wa" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Enviar prueba', 'openbooking-wp' ); ?></button>
            </div>
            <p class="description"><?php esc_html_e( 'Envía un mensaje de texto libre para verificar tus credenciales. Esta prueba no valida monitoreo, reintentos gestionados ni cron real.', 'openbooking-wp' ); ?></p>

            <hr class="ob-divider">

            <h3 class="ob-section-title">SMS</h3>

            <div class="obwp-field">
                <label>
                    <input type="checkbox" id="notif_sms_enabled" value="1">
                    <?php esc_html_e( 'Habilitar notificaciones por SMS', 'openbooking-wp' ); ?>
                </label>
            </div>

            <div id="obwp-sms-config">
                <h4 class="ob-subtitle"><?php esc_html_e( 'Credenciales Twilio', 'openbooking-wp' ); ?></h4>
                <div class="obwp-field-row">
                    <div class="obwp-field">
                        <label for="notif_sms_twilio_sid">Account SID</label>
                        <input type="text" id="notif_sms_twilio_sid" class="regular-text" autocomplete="off">
                    </div>
                    <div class="obwp-field">
                        <label for="notif_sms_twilio_token">Auth Token</label>
                        <input type="password" id="notif_sms_twilio_token" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Dejar vacío para no cambiar', 'openbooking-wp' ); ?>">
                    </div>
                </div>
                <div class="obwp-field">
                    <label for="notif_sms_twilio_from"><?php esc_html_e( 'Número Twilio (from)', 'openbooking-wp' ); ?></label>
                    <input type="text" id="notif_sms_twilio_from" class="regular-text" placeholder="+14155238886">
                    <p class="description"><?php esc_html_e( 'Número Twilio en formato E.164, ej: +14155238886. Distinto al número de WhatsApp.', 'openbooking-wp' ); ?></p>
                </div>
            </div>

            <div class="obwp-form-actions ob-form-actions-with-bottom">
                <button type="button" id="obwp-save-sms-notif" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar ajustes de SMS', 'openbooking-wp' ); ?></button>
            </div>

            <hr class="ob-divider">

            <h3 class="ob-section-title"><?php esc_html_e( 'Mensajes de SMS', 'openbooking-wp' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Personaliza el texto de cada SMS. Los SMS son texto plano, máx. 160 caracteres. Merge tags: {customer_name}, {service_name}, {booking_date}, {booking_time}.', 'openbooking-wp' ); ?></p>
            <div id="obwp-sms-templates" class="ob-space-top-sm">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando mensajes...', 'openbooking-wp' ); ?></p>
            </div>

            <hr class="ob-divider">

            <h3 class="ob-section-title"><?php esc_html_e( 'Enviar SMS de prueba', 'openbooking-wp' ); ?></h3>
            <div class="ob-flex-row">
                <input type="text" id="obwp-sms-test-to" class="regular-text" placeholder="+56912345678">
                <button type="button" id="obwp-send-test-sms" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Enviar prueba', 'openbooking-wp' ); ?></button>
            </div>
            <p class="description"><?php esc_html_e( 'Envía un SMS de prueba para verificar que las credenciales funcionan.', 'openbooking-wp' ); ?></p>

            <hr class="ob-divider">

            <h3 class="ob-section-title"><?php esc_html_e( 'Historial de notificaciones', 'openbooking-wp' ); ?></h3>
            <div class="ob-flex-row ob-space-bottom-md">
                <select id="obwp-notif-log-channel" class="ob-select-sm">
                    <option value=""><?php esc_html_e( 'Todos los canales', 'openbooking-wp' ); ?></option>
                    <option value="email">Email</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="sms">SMS</option>
                </select>
                <select id="obwp-notif-log-status" class="ob-select-sm">
                    <option value=""><?php esc_html_e( 'Todos los estados', 'openbooking-wp' ); ?></option>
                    <option value="sent"><?php esc_html_e( 'Enviado', 'openbooking-wp' ); ?></option>
                    <option value="failed"><?php esc_html_e( 'Fallido', 'openbooking-wp' ); ?></option>
                </select>
                <button type="button" id="obwp-notif-log-load" class="ob-btn ob-btn-secondary"><?php esc_html_e( 'Cargar historial', 'openbooking-wp' ); ?></button>
            </div>
            <div id="obwp-notif-log-table"></div>

        </div>

        <div class="obwp-tab-panel" id="tab-integrations">
            <div class="obwp-field">
                <label><?php esc_html_e( 'WooCommerce', 'openbooking-wp' ); ?></label>
                <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                    <p class="obwp-status-on"><?php esc_html_e( 'WooCommerce detectado y activo.', 'openbooking-wp' ); ?></p>
                <?php else : ?>
                    <p class="obwp-status-off"><?php esc_html_e( 'WooCommerce no está activo. Puedes usar el plugin sin WooCommerce.', 'openbooking-wp' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-policies">
            <h3 class="ob-section-title ob-title-reset"><?php esc_html_e( 'Cancelación y reprogramación', 'openbooking-wp' ); ?></h3>
            <div class="obwp-field">
                <label for="cancel_min_hours"><?php esc_html_e( 'Cancelación mínima (horas de anticipación)', 'openbooking-wp' ); ?></label>
                <input type="number" id="cancel_min_hours" name="cancel_min_hours" min="0" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_cancel_min_hours', 0 ) ); ?>">
                <p class="description"><?php esc_html_e( 'Horas mínimas de antelación para permitir cancelaciones. 0 = sin límite.', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-field">
                <label for="reschedule_min_hours"><?php esc_html_e( 'Reprogramación mínima (horas de anticipación)', 'openbooking-wp' ); ?></label>
                <input type="number" id="reschedule_min_hours" name="reschedule_min_hours" min="0" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_reschedule_min_hours', 0 ) ); ?>">
                <p class="description"><?php esc_html_e( 'Horas mínimas de antelación para permitir reprogramaciones. 0 = sin límite.', 'openbooking-wp' ); ?></p>
            </div>

            <hr class="ob-divider">
            <h3 class="ob-section-title"><?php esc_html_e( 'Plazos del sistema', 'openbooking-wp' ); ?></h3>
            <div class="obwp-field">
                <label for="free_booking_expiry_minutes"><?php esc_html_e( 'Tiempo de expiración de reservas sin cobro (minutos)', 'openbooking-wp' ); ?></label>
                <input type="number" id="free_booking_expiry_minutes" name="free_booking_expiry_minutes" min="3" max="1440" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_free_booking_expiry_minutes', 5 ) ); ?>">
                <p class="description"><?php esc_html_e( 'Margen interno para reservas gratuitas antes de su confirmación final. Mínimo 3 minutos.', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-field">
                <label for="booking_expiry_minutes"><?php esc_html_e( 'Tiempo de expiración de reserva pendiente (minutos)', 'openbooking-wp' ); ?></label>
                <input type="number" id="booking_expiry_minutes" name="booking_expiry_minutes" min="5" max="1440" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_booking_expiry_minutes', 15 ) ); ?>">
                <p class="description"><?php esc_html_e( 'Minutos que tiene el cliente para completar el pago antes de que la reserva expire y el horario se libere. Mínimo 5 minutos.', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-field">
                <label for="reminder_hours_before"><?php esc_html_e( 'Enviar recordatorio con cuántas horas de anticipación', 'openbooking-wp' ); ?></label>
                <input type="number" id="reminder_hours_before" name="reminder_hours_before" min="1" max="168" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_reminder_hours_before', 24 ) ); ?>">
                <p class="description"><?php esc_html_e( 'El cron de recordatorios busca reservas que ocurren dentro de este número de horas y envía el aviso. Por defecto 24 h (día siguiente).', 'openbooking-wp' ); ?></p>
            </div>

            <hr class="ob-divider">
            <h3 class="ob-section-title"><?php esc_html_e( 'Retención de registros', 'openbooking-wp' ); ?></h3>
            <div class="obwp-field">
                <label for="notification_log_retention_days"><?php esc_html_e( 'Retención del historial de notificaciones (días)', 'openbooking-wp' ); ?></label>
                <input type="number" id="notification_log_retention_days" name="notification_log_retention_days" min="7" max="365" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_notification_log_retention_days', 30 ) ); ?>">
                <p class="description"><?php esc_html_e( 'Los registros de emails y WhatsApp más antiguos que este número de días se eliminan automáticamente. Mínimo 7 días.', 'openbooking-wp' ); ?></p>
            </div>
            <div class="obwp-field">
                <label for="audit_log_retention_days"><?php esc_html_e( 'Retención del log de auditoría (días)', 'openbooking-wp' ); ?></label>
                <input type="number" id="audit_log_retention_days" name="audit_log_retention_days" min="0" max="3650" step="1" class="ob-input-narrow"
                       value="<?php echo esc_attr( (int) get_option( 'obwp_audit_log_retention_days', 0 ) ); ?>">
                <p class="description"><?php esc_html_e( '0 = conservar siempre. Número mayor: eliminar registros más antiguos que ese número de días.', 'openbooking-wp' ); ?></p>
            </div>

            <hr class="ob-divider">
            <h3 class="ob-section-title"><?php esc_html_e( 'Privacidad y datos personales (Ley 19.628)', 'openbooking-wp' ); ?></h3>
            <div class="obwp-notice obwp-notice-info">
                <strong><?php esc_html_e( 'Datos que este plugin almacena de tus clientes:', 'openbooking-wp' ); ?></strong>
                <ul class="ob-space-top-sm">
                    <li><?php esc_html_e( 'Nombre y apellido', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Correo electrónico', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Teléfono (opcional)', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Historial de reservas (fechas, servicios, estado de pago)', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Notas ingresadas al reservar', 'openbooking-wp' ); ?></li>
                </ul>
                <p class="ob-space-top-sm"><?php esc_html_e( 'No se almacenan datos bancarios ni de tarjeta — el pago se procesa 100% en Webpay/MercadoPago/Stripe.', 'openbooking-wp' ); ?></p>
            </div>

            <div class="obwp-field">
                <label for="privacy_policy_url"><?php esc_html_e( 'URL de tu política de privacidad', 'openbooking-wp' ); ?></label>
                <input type="url" id="privacy_policy_url" name="privacy_policy_url"
                       value="<?php echo esc_attr( get_option( 'obwp_privacy_policy_url', get_privacy_policy_url() ) ); ?>"
                       placeholder="<?php echo esc_attr( get_privacy_policy_url() ?: home_url( '/politica-de-privacidad/' ) ); ?>">
                <p class="description">
                    <?php esc_html_e( 'Se muestra en el formulario de reserva antes de confirmar. El cliente debe aceptar antes de continuar.', 'openbooking-wp' ); ?>
                    <?php if ( ! get_privacy_policy_url() ) : ?>
                        <br><strong><?php esc_html_e( 'WordPress no detectó una página de política de privacidad.', 'openbooking-wp' ); ?></strong>
                        <?php printf(
                            /* translators: %s: link */
                            esc_html__( 'Puedes crearla en %s.', 'openbooking-wp' ),
                            '<a href="' . esc_url( admin_url( 'privacy.php' ) ) . '">' . esc_html__( 'Ajustes → Privacidad', 'openbooking-wp' ) . '</a>'
                        ); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="obwp-field">
                <label>
                    <input type="checkbox" name="privacy_consent_required" value="1"
                           <?php checked( get_option( 'obwp_privacy_consent_required', '1' ), '1' ); ?>>
                    <?php esc_html_e( 'Requerir aceptación de política de privacidad para completar la reserva', 'openbooking-wp' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Recomendado para cumplir con la Ley 19.628 de protección de datos personales en Chile.', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-advanced">
            <div class="obwp-field">
                <label>
                    <input type="checkbox" name="uninstall_remove_data" value="1" <?php checked( get_option( 'obwp_uninstall_remove_data', false ) ); ?>>
                    <?php esc_html_e( 'Eliminar datos al desinstalar', 'openbooking-wp' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Si activas esto, todas las reservas, servicios y configuraciones se borrarán al desinstalar el plugin.', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-form-actions">
            <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar cambios', 'openbooking-wp' ); ?></button>
        </div>
    </form>
</div>
