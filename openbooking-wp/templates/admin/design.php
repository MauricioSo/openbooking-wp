<?php if ( ! defined( 'ABSPATH' ) ) exit;
$ui_config = get_option( 'obwp_ui_config', [] );
?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php esc_html_e( 'Diseño de la agenda', 'openbooking-wp' ); ?></h1>

    <form id="obwp-design-form" class="obwp-form">
        <?php wp_nonce_field( 'obwp_save_design', '_obwp_nonce' ); ?>

        <div class="obwp-tabs ob-tabs">
            <button type="button" class="obwp-tab active" data-tab="theme"><?php esc_html_e( 'Tema', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="typography"><?php esc_html_e( 'Tipografía', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="layout"><?php esc_html_e( 'Layout', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="fields"><?php esc_html_e( 'Campos', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="css"><?php esc_html_e( 'CSS personalizado', 'openbooking-wp' ); ?></button>
            <button type="button" class="obwp-tab" data-tab="preview"><?php esc_html_e( 'Vista previa', 'openbooking-wp' ); ?></button>
        </div>

        <div class="obwp-tab-panel active" id="tab-theme">
            <div class="obwp-field">
                <label for="design_preset"><?php esc_html_e( 'Preset', 'openbooking-wp' ); ?></label>
                <select id="design_preset" name="preset">
                    <option value="minimal" <?php selected( $ui_config['preset'] ?? 'minimal', 'minimal' ); ?>>Minimal</option>
                    <option value="classic" <?php selected( $ui_config['preset'] ?? '', 'classic' ); ?>>Classic</option>
                    <option value="modern" <?php selected( $ui_config['preset'] ?? '', 'modern' ); ?>>Modern</option>
                </select>
            </div>
            <div class="obwp-field-row">
                <div class="obwp-field">
                    <label for="design_color_primary"><?php esc_html_e( 'Color principal', 'openbooking-wp' ); ?></label>
                    <input type="color" id="design_color_primary" name="color_primary" value="<?php echo esc_attr( $ui_config['color_primary'] ?? '#111111' ); ?>">
                </div>
                <div class="obwp-field">
                    <label for="design_color_bg"><?php esc_html_e( 'Fondo', 'openbooking-wp' ); ?></label>
                    <input type="color" id="design_color_bg" name="color_bg" value="<?php echo esc_attr( $ui_config['color_bg'] ?? '#ffffff' ); ?>">
                </div>
                <div class="obwp-field">
                    <label for="design_color_text"><?php esc_html_e( 'Texto', 'openbooking-wp' ); ?></label>
                    <input type="color" id="design_color_text" name="color_text" value="<?php echo esc_attr( $ui_config['color_text'] ?? '#1f1f1f' ); ?>">
                </div>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-typography">
            <div class="obwp-field">
                <label for="design_font"><?php esc_html_e( 'Tipografía', 'openbooking-wp' ); ?></label>
                <select id="design_font" name="font_family">
                    <option value="Inter, sans-serif" <?php selected( $ui_config['font_family'] ?? '', 'Inter, sans-serif' ); ?>>Inter</option>
                    <option value="system-ui, sans-serif" <?php selected( $ui_config['font_family'] ?? '', 'system-ui, sans-serif' ); ?>>System</option>
                    <option value="Georgia, serif" <?php selected( $ui_config['font_family'] ?? '', 'Georgia, serif' ); ?>>Georgia</option>
                </select>
            </div>
            <div class="obwp-field">
                <label for="design_radius"><?php esc_html_e( 'Bordes redondeados', 'openbooking-wp' ); ?></label>
                <input type="range" id="design_radius" name="radius" min="0" max="24" value="<?php echo esc_attr( intval( $ui_config['radius'] ?? 12 ) ); ?>">
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-layout">
            <div class="obwp-field">
                <label><?php esc_html_e( 'Layout', 'openbooking-wp' ); ?></label>
                <div class="obwp-radio-group">
                    <label><input type="radio" name="layout" value="steps" <?php checked( $ui_config['layout'] ?? 'steps', 'steps' ); ?>> <?php esc_html_e( 'Paso a paso', 'openbooking-wp' ); ?></label>
                </div>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-fields">
            <p class="description"><?php esc_html_e( 'Arrastra para reordenar los campos del formulario.', 'openbooking-wp' ); ?></p>
            <div id="obwp-form-fields" class="obwp-sortable-list">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando campos...', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-tab-panel" id="tab-css">

            <div class="obwp-field">
                <label for="design_custom_css"><?php esc_html_e( 'CSS personalizado', 'openbooking-wp' ); ?></label>
                <p class="description"><?php esc_html_e( 'Escribe CSS usando las clases del widget. Se aplica sólo en el formulario público de reserva.', 'openbooking-wp' ); ?></p>
                <textarea
                    id="design_custom_css"
                    name="custom_css"
                    rows="14"
                    spellcheck="false"
                    placeholder=".obwp-btn--primary { border-radius: 4px; }
.obwp-time-slot { font-size: 16px; }
.obwp-booking-detail .obwp-state--pending { background: #1a1a2e; }"
                    class="obwp-css-editor"
                ><?php echo esc_textarea( $ui_config['custom_css'] ?? '' ); ?></textarea>
            </div>

            <details class="obwp-css-reference">
                <summary class="obwp-css-reference__summary">
                    <?php esc_html_e( 'Referencia de clases CSS disponibles', 'openbooking-wp' ); ?>
                </summary>
                <div class="obwp-css-reference__body">

                    <?php
                    $groups = [
                        __( 'Contenedor y pasos', 'openbooking-wp' ) => [
                            '.obwp-public'            => __( 'Contenedor raíz del widget', 'openbooking-wp' ),
                            '.obwp-stepper'           => __( 'Barra de pasos (1–2–3–4)', 'openbooking-wp' ),
                            '.obwp-step'              => __( 'Paso individual', 'openbooking-wp' ),
                            '.obwp-step--active'      => __( 'Paso activo (actual)', 'openbooking-wp' ),
                            '.obwp-step--completed'   => __( 'Paso completado', 'openbooking-wp' ),
                            '.obwp-step-num'          => __( 'Círculo con número de paso', 'openbooking-wp' ),
                            '.obwp-step-label'        => __( 'Etiqueta de texto del paso', 'openbooking-wp' ),
                            '.obwp-panel'             => __( 'Panel de contenido de cada paso', 'openbooking-wp' ),
                            '.obwp-panel-title'       => __( 'Título del panel', 'openbooking-wp' ),
                            '.obwp-panel-subtitle'    => __( 'Subtítulo del panel', 'openbooking-wp' ),
                        ],
                        __( 'Servicios', 'openbooking-wp' ) => [
                            '.obwp-service-cards'     => __( 'Contenedor de tarjetas de servicio', 'openbooking-wp' ),
                            '.obwp-service-card'      => __( 'Tarjeta de un servicio', 'openbooking-wp' ),
                        ],
                        __( 'Calendario', 'openbooking-wp' ) => [
                            '.obwp-calendar-header'          => __( 'Cabecera del calendario (mes + flechas)', 'openbooking-wp' ),
                            '.obwp-calendar-grid'            => __( 'Grilla de días del mes', 'openbooking-wp' ),
                            '.obwp-calendar-day'             => __( 'Celda de día', 'openbooking-wp' ),
                            '.obwp-calendar-day--available'  => __( 'Día con disponibilidad', 'openbooking-wp' ),
                            '.obwp-calendar-day--selected'   => __( 'Día seleccionado', 'openbooking-wp' ),
                            '.obwp-calendar-day--disabled'   => __( 'Día sin disponibilidad o pasado', 'openbooking-wp' ),
                        ],
                        __( 'Horarios', 'openbooking-wp' ) => [
                            '.obwp-time-picker-section'  => __( 'Sección de selección de hora', 'openbooking-wp' ),
                            '.obwp-time-grid'            => __( 'Grilla de bloques de hora', 'openbooking-wp' ),
                            '.obwp-time-slot'            => __( 'Bloque de hora individual', 'openbooking-wp' ),
                            '.obwp-time-slot--selected'  => __( 'Bloque de hora seleccionado', 'openbooking-wp' ),
                        ],
                        __( 'Formulario de datos', 'openbooking-wp' ) => [
                            '.obwp-field'              => __( 'Grupo de un campo (label + input)', 'openbooking-wp' ),
                            '.obwp-field-label'        => __( 'Etiqueta del campo', 'openbooking-wp' ),
                            '.obwp-field input'        => __( 'Input de texto / email / teléfono', 'openbooking-wp' ),
                            '.obwp-field textarea'     => __( 'Textarea de notas', 'openbooking-wp' ),
                        ],
                        __( 'Botones y navegación', 'openbooking-wp' ) => [
                            '.obwp-btn'                => __( 'Botón base', 'openbooking-wp' ),
                            '.obwp-btn--primary'       => __( 'Botón principal (color de marca)', 'openbooking-wp' ),
                            '.obwp-btn--secondary'     => __( 'Botón secundario (borde gris)', 'openbooking-wp' ),
                            '.obwp-nav-buttons'        => __( 'Contenedor Volver / Siguiente', 'openbooking-wp' ),
                        ],
                        __( 'Tarjeta de confirmación', 'openbooking-wp' ) => [
                            '.obwp-booking-detail'             => __( 'Tarjeta completa de confirmación o detalle de reserva', 'openbooking-wp' ),
                            '.obwp-state'                      => __( 'Cabecera de estado', 'openbooking-wp' ),
                            '.obwp-state--success'             => __( 'Estado: reserva confirmada (fondo verde)', 'openbooking-wp' ),
                            '.obwp-state--pending'             => __( 'Estado: pendiente de pago (fondo naranja)', 'openbooking-wp' ),
                            '.obwp-state--failed'              => __( 'Estado: pago fallido (fondo rojo)', 'openbooking-wp' ),
                            '.obwp-booking-detail-card'        => __( 'Cuerpo blanco con las filas de datos', 'openbooking-wp' ),
                            '.obwp-detail-row'                 => __( 'Fila de dato (label + valor)', 'openbooking-wp' ),
                            '.obwp-detail-label'               => __( 'Etiqueta de la fila (gris)', 'openbooking-wp' ),
                            '.obwp-detail-value'               => __( 'Valor de la fila (negrita)', 'openbooking-wp' ),
                            '.obwp-confirmation-actions'       => __( 'Franja inferior con botones Reprogramar / Cancelar', 'openbooking-wp' ),
                        ],
                        __( 'Resumen lateral', 'openbooking-wp' ) => [
                            '.obwp-booking-summary'       => __( 'Contenedor del resumen de reserva', 'openbooking-wp' ),
                            '.obwp-booking-summary-card'  => __( 'Tarjeta interior del resumen', 'openbooking-wp' ),
                        ],
                        __( 'Variables CSS (--custom properties)', 'openbooking-wp' ) => [
                            '--ob-color-primary'  => __( 'Color principal (botones, días disponibles, etc.)', 'openbooking-wp' ),
                            '--ob-color-bg'       => __( 'Color de fondo del widget', 'openbooking-wp' ),
                            '--ob-color-text'     => __( 'Color de texto principal', 'openbooking-wp' ),
                            '--ob-font-family'    => __( 'Tipografía del widget', 'openbooking-wp' ),
                            '--ob-radius'         => __( 'Radio de bordes (botones, tarjetas, slots)', 'openbooking-wp' ),
                        ],
                    ];
                    foreach ( $groups as $group_label => $items ) : ?>
                        <div class="obwp-css-reference__group">
                            <h4 class="obwp-css-reference__group-title"><?php echo esc_html( $group_label ); ?></h4>
                            <table class="obwp-css-reference__table">
                                <?php foreach ( $items as $selector => $desc ) : ?>
                                <tr class="obwp-css-reference__row">
                                    <td class="obwp-css-reference__selector"><?php echo esc_html( $selector ); ?></td>
                                    <td class="obwp-css-reference__desc"><?php echo esc_html( $desc ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endforeach; ?>

                </div>
            </details>

        </div>

        <div class="obwp-tab-panel" id="tab-preview">
            <div class="obwp-preview-header">
                <div class="obwp-preview-state-bar">
                    <button type="button" class="active" data-preview-state="service"><?php esc_html_e( 'Servicio', 'openbooking-wp' ); ?></button>
                    <button type="button" data-preview-state="calendar"><?php esc_html_e( 'Calendario', 'openbooking-wp' ); ?></button>
                    <button type="button" data-preview-state="form"><?php esc_html_e( 'Formulario', 'openbooking-wp' ); ?></button>
                    <button type="button" data-preview-state="confirmed"><?php esc_html_e( 'Confirmado', 'openbooking-wp' ); ?></button>
                    <button type="button" data-preview-state="pending"><?php esc_html_e( 'Pendiente', 'openbooking-wp' ); ?></button>
                    <button type="button" data-preview-state="failed"><?php esc_html_e( 'Pago fallido', 'openbooking-wp' ); ?></button>
                </div>
                <div class="obwp-preview-controls">
                    <label><input type="radio" name="preview_mode" value="desktop" checked> Desktop</label>
                    <label><input type="radio" name="preview_mode" value="mobile"> Mobile</label>
                </div>
            </div>
            <div id="obwp-design-preview" class="obwp-preview-frame">
                <p class="obwp-loading"><?php esc_html_e( 'Cargando vista previa...', 'openbooking-wp' ); ?></p>
            </div>
        </div>

        <div class="obwp-form-actions">
            <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar cambios', 'openbooking-wp' ); ?></button>
        </div>
    </form>
</div>
