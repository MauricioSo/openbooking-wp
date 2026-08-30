<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <div class="obwp-onboarding">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
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
            <span style="font-family:var(--ob-font-display);font-size:22px;font-weight:600;letter-spacing:-0.03em">open<span style="color:var(--ob-accent)">booking</span></span>
        </div>
        <h1 class="ob-page-title"><?php esc_html_e( 'Configura tu agenda', 'openbooking-wp' ); ?></h1>
        <p class="obwp-onboarding-subtitle"><?php esc_html_e( 'En pocos pasos la dejaremos lista.', 'openbooking-wp' ); ?></p>

        <div class="obwp-onboarding-progress">
            <span id="obwp-onboarding-step">Paso 1 de 7</span>
            <div class="obwp-progress-bar ob-progress-track">
                <div class="obwp-progress-fill ob-progress-fill" id="obwp-progress-fill" style="width: 14%;"></div>
            </div>
        </div>

        <form id="obwp-onboarding-form">
            <?php wp_nonce_field( 'obwp_onboarding', '_obwp_nonce' ); ?>

            <!-- Step 1: Welcome -->
            <div class="obwp-onboarding-step active" data-onb-step="1">
                <h2><?php esc_html_e( 'Bienvenida', 'openbooking-wp' ); ?></h2>
                <p><?php esc_html_e( 'Que haremos:', 'openbooking-wp' ); ?></p>
                <ul>
                    <li><?php esc_html_e( 'Elegir el tipo de agenda para tu negocio', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Configurar datos basicos (detectados automaticamente)', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Crear tu primer servicio con defaults inteligentes', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Definir tu horario', 'openbooking-wp' ); ?></li>
                    <li><?php esc_html_e( 'Configurar cobro y publicar', 'openbooking-wp' ); ?></li>
                </ul>
                <p><em><?php esc_html_e( 'Tiempo estimado: 3-5 minutos', 'openbooking-wp' ); ?></em></p>
                <div class="obwp-form-actions">
                    <button type="button" class="ob-btn ob-btn-primary obwp-onb-next"><?php esc_html_e( 'Comenzar', 'openbooking-wp' ); ?></button>
                </div>
            </div>

            <!-- Step 2: Context presets -->
            <div class="obwp-onboarding-step" data-onb-step="2">
                <h2><?php esc_html_e( 'Que tipo de agenda necesitas?', 'openbooking-wp' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Selecciona la que mas se acerca a tu negocio. Preconfiguraremos todo para ti.', 'openbooking-wp' ); ?></p>
                <div class="obwp-preset-grid" id="obwp-preset-grid">
                    <div class="obwp-preset-card" data-preset="health">
                        <span class="dashicons dashicons-heart"></span>
                        <strong><?php esc_html_e( 'Salud / Clinica', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Consultas medicas, dental, psicologia', 'openbooking-wp' ); ?></small>
                    </div>
                    <div class="obwp-preset-card" data-preset="beauty">
                        <span class="dashicons dashicons-admin-appearance"></span>
                        <strong><?php esc_html_e( 'Belleza / Estetica', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Peluqueria, manicura, spa', 'openbooking-wp' ); ?></small>
                    </div>
                    <div class="obwp-preset-card" data-preset="education">
                        <span class="dashicons dashicons-welcome-learn-more"></span>
                        <strong><?php esc_html_e( 'Educacion / Clases', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Tutorias, talleres, capacitaciones', 'openbooking-wp' ); ?></small>
                    </div>
                    <div class="obwp-preset-card" data-preset="legal">
                        <span class="dashicons dashicons-businessperson"></span>
                        <strong><?php esc_html_e( 'Legal / Consultoria', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Abogados, contadores, asesores', 'openbooking-wp' ); ?></small>
                    </div>
                    <div class="obwp-preset-card" data-preset="fitness">
                        <span class="dashicons dashicons-performance"></span>
                        <strong><?php esc_html_e( 'Fitness / Deporte', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Personal trainer, yoga, pilates', 'openbooking-wp' ); ?></small>
                    </div>
                    <div class="obwp-preset-card" data-preset="spaces">
                        <span class="dashicons dashicons-building"></span>
                        <strong><?php esc_html_e( 'Espacios / Reservas', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Salas, canchas, coworking, estudios', 'openbooking-wp' ); ?></small>
                    </div>
                    <div class="obwp-preset-card" data-preset="generic">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <strong><?php esc_html_e( 'Otro / General', 'openbooking-wp' ); ?></strong>
                        <small><?php esc_html_e( 'Cualquier tipo de agenda', 'openbooking-wp' ); ?></small>
                    </div>
                </div>
                <input type="hidden" id="obwp-selected-preset" name="preset_key" value="generic">
                <div class="obwp-form-actions">
                    <button type="button" class="ob-btn ob-btn-secondary obwp-onb-prev"><?php esc_html_e( 'Volver', 'openbooking-wp' ); ?></button>
                    <button type="button" class="ob-btn ob-btn-primary obwp-onb-next"><?php esc_html_e( 'Continuar', 'openbooking-wp' ); ?></button>
                </div>
            </div>

            <!-- Step 3: Basic data (auto-detected) -->
            <div class="obwp-onboarding-step" data-onb-step="3">
                <h2><?php esc_html_e( 'Datos basicos', 'openbooking-wp' ); ?></h2>
                <p class="description" id="obwp-detect-msg"><?php esc_html_e( 'Detectamos tu ubicacion. Corrige si es necesario.', 'openbooking-wp' ); ?></p>
                <div class="obwp-field">
                    <label for="onb_business_name"><?php esc_html_e( 'Nombre del negocio', 'openbooking-wp' ); ?></label>
                    <input type="text" id="onb_business_name" name="business_name" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" required>
                </div>
                <div class="obwp-field-row">
                    <div class="obwp-field">
                        <label for="onb_country"><?php esc_html_e( 'Pais', 'openbooking-wp' ); ?></label>
                        <select id="onb_country" name="country">
                            <?php $countries = [ 'CL' => 'Chile', 'CO' => 'Colombia', 'MX' => 'Mexico', 'AR' => 'Argentina', 'PE' => 'Peru', 'BR' => 'Brasil', 'US' => 'Estados Unidos', 'ES' => 'Espana' ]; ?>
                            <?php foreach ( $countries as $code => $name ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="obwp-field">
                        <label for="onb_currency"><?php esc_html_e( 'Moneda', 'openbooking-wp' ); ?></label>
                        <select id="onb_currency" name="currency">
                            <option value="CLP">CLP</option>
                            <option value="COP">COP</option>
                            <option value="MXN">MXN</option>
                            <option value="ARS">ARS</option>
                            <option value="PEN">PEN</option>
                            <option value="BRL">BRL</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </div>
                </div>
                <div class="obwp-field-row">
                    <div class="obwp-field">
                        <label for="onb_timezone"><?php esc_html_e( 'Zona horaria', 'openbooking-wp' ); ?></label>
                        <select id="onb_timezone" name="timezone">
                            <option value="America/Santiago">America/Santiago</option>
                            <option value="America/Bogota">America/Bogota</option>
                            <option value="America/Mexico_City">America/Mexico_City</option>
                            <option value="America/Buenos_Aires">America/Buenos_Aires</option>
                            <option value="America/Lima">America/Lima</option>
                            <option value="America/Sao_Paulo">America/Sao_Paulo</option>
                            <option value="UTC">UTC</option>
                        </select>
                    </div>
                    <div class="obwp-field">
                        <label for="onb_language"><?php esc_html_e( 'Idioma', 'openbooking-wp' ); ?></label>
                        <select id="onb_language" name="language">
                            <option value="es">Espanol</option>
                            <option value="en">English</option>
                            <option value="pt">Portugues</option>
                        </select>
                    </div>
                </div>
                <div class="obwp-form-actions">
                    <button type="button" class="ob-btn ob-btn-secondary obwp-onb-prev"><?php esc_html_e( 'Volver', 'openbooking-wp' ); ?></button>
                    <button type="button" class="ob-btn ob-btn-primary obwp-onb-next"><?php esc_html_e( 'Continuar', 'openbooking-wp' ); ?></button>
                </div>
            </div>

            <!-- Step 4: First service (pre-filled from preset) -->
            <div class="obwp-onboarding-step" data-onb-step="4">
                <h2><?php esc_html_e( 'Tu primer servicio', 'openbooking-wp' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Preconfigurado segun tu tipo de agenda. Ajusta segun necesites.', 'openbooking-wp' ); ?></p>
                <div class="obwp-field">
                    <label for="onb_service_name"><?php esc_html_e( 'Nombre del servicio', 'openbooking-wp' ); ?></label>
                    <input type="text" id="onb_service_name" name="service_name" required>
                </div>
                <div class="obwp-field-row">
                    <div class="obwp-field">
                        <label for="onb_duration"><?php esc_html_e( 'Duracion', 'openbooking-wp' ); ?></label>
                        <select id="onb_duration" name="duration_minutes">
                            <?php foreach ( [ 15, 30, 45, 60, 90, 120 ] as $d ) : ?>
                                <option value="<?php echo esc_attr( $d ); ?>"><?php echo $d; ?> min</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="obwp-field">
                        <label for="onb_price"><?php esc_html_e( 'Precio (0 = gratis)', 'openbooking-wp' ); ?></label>
                        <input type="number" id="onb_price" name="price" value="0" min="0" step="0.01">
                    </div>
                </div>
                <div class="obwp-field">
                    <label><?php esc_html_e( 'Modalidad', 'openbooking-wp' ); ?></label>
                    <div class="obwp-radio-group">
                        <label><input type="radio" name="service_mode" value="presencial" checked> <?php esc_html_e( 'Presencial', 'openbooking-wp' ); ?></label>
                        <label><input type="radio" name="service_mode" value="online"> <?php esc_html_e( 'Online', 'openbooking-wp' ); ?></label>
                        <label><input type="radio" name="service_mode" value="both"> <?php esc_html_e( 'Ambas', 'openbooking-wp' ); ?></label>
                    </div>
                </div>
                <div class="obwp-form-actions">
                    <button type="button" class="ob-btn ob-btn-secondary obwp-onb-prev"><?php esc_html_e( 'Volver', 'openbooking-wp' ); ?></button>
                    <button type="button" class="ob-btn ob-btn-primary obwp-onb-next"><?php esc_html_e( 'Continuar', 'openbooking-wp' ); ?></button>
                </div>
            </div>

            <!-- Step 5: Schedule (pre-filled from preset) -->
            <div class="obwp-onboarding-step" data-onb-step="5">
                <h2><?php esc_html_e( 'Tu horario', 'openbooking-wp' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Preconfigurado segun tu tipo de agenda.', 'openbooking-wp' ); ?></p>
                <div class="obwp-schedule-grid" id="obwp-onb-schedule">
                    <?php
                    $days = [ 1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie', 6 => 'Sab', 7 => 'Dom' ];
                    foreach ( $days as $num => $label ) : ?>
                    <div class="obwp-schedule-row" data-day="<?php echo esc_attr( $num ); ?>">
                        <label class="obwp-day-check">
                            <input type="checkbox" name="enabled_days[]" value="<?php echo esc_attr( $num ); ?>" <?php checked( $num <= 5 ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                        <input type="time" name="time_from[<?php echo esc_attr( $num ); ?>]" value="09:00" class="obwp-time-input">
                        <span>a</span>
                        <input type="time" name="time_to[<?php echo esc_attr( $num ); ?>]" value="18:00" class="obwp-time-input">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="obwp-form-actions">
                    <button type="button" class="ob-btn ob-btn-secondary obwp-onb-prev"><?php esc_html_e( 'Volver', 'openbooking-wp' ); ?></button>
                    <button type="button" class="ob-btn ob-btn-primary obwp-onb-next"><?php esc_html_e( 'Continuar', 'openbooking-wp' ); ?></button>
                </div>
            </div>

            <!-- Step 6: Payment + publish -->
            <div class="obwp-onboarding-step" data-onb-step="6">
                <h2><?php esc_html_e( 'Como quieres cobrar', 'openbooking-wp' ); ?></h2>
                <div class="obwp-field">
                    <div class="obwp-radio-group">
                        <label><input type="radio" name="payment_mode" value="full" checked> <?php esc_html_e( 'Pago completo', 'openbooking-wp' ); ?></label>
                        <label><input type="radio" name="payment_mode" value="deposit"> <?php esc_html_e( 'Anticipo', 'openbooking-wp' ); ?></label>
                        <label><input type="radio" name="payment_mode" value="manual"> <?php esc_html_e( 'Pago manual / en local', 'openbooking-wp' ); ?></label>
                    </div>
                </div>

                <h3><?php esc_html_e( 'Publica tu agenda', 'openbooking-wp' ); ?></h3>
                <div class="obwp-field">
                    <div class="obwp-radio-group">
                        <label><input type="radio" name="publish_mode" value="new_page" checked> <?php esc_html_e( 'Crear una pagina nueva', 'openbooking-wp' ); ?></label>
                        <label><input type="radio" name="publish_mode" value="shortcode"> <?php esc_html_e( 'Copiar shortcode', 'openbooking-wp' ); ?></label>
                    </div>
                </div>
                <div class="obwp-field" id="onb-page-name-field">
                    <label for="onb_page_name"><?php esc_html_e( 'Nombre de la pagina', 'openbooking-wp' ); ?></label>
                    <input type="text" id="onb_page_name" name="page_name" value="<?php esc_attr_e( 'Reservar hora', 'openbooking-wp' ); ?>">
                </div>
                <div class="obwp-form-actions">
                    <button type="button" class="ob-btn ob-btn-secondary obwp-onb-prev"><?php esc_html_e( 'Volver', 'openbooking-wp' ); ?></button>
                    <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Publicar agenda', 'openbooking-wp' ); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>
