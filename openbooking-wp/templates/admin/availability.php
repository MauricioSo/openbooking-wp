<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="ob-page-title"><?php esc_html_e( 'Disponibilidad', 'openbooking-wp' ); ?></h1>

    <div class="obwp-tabs ob-tabs">
        <button class="obwp-tab active" data-tab="rules"><?php esc_html_e( 'Horarios semanales', 'openbooking-wp' ); ?></button>
        <button class="obwp-tab" data-tab="breaks"><?php esc_html_e( 'Descansos', 'openbooking-wp' ); ?></button>
        <button class="obwp-tab" data-tab="exceptions"><?php esc_html_e( 'Excepciones', 'openbooking-wp' ); ?></button>
        <button class="obwp-tab" data-tab="preview"><?php esc_html_e( 'Vista previa', 'openbooking-wp' ); ?></button>
        <button class="obwp-tab" data-tab="snapshots"><?php esc_html_e( 'Versiones', 'openbooking-wp' ); ?></button>
    </div>

    <form id="obwp-availability-form" class="obwp-form">
        <?php wp_nonce_field( 'obwp_save_availability', '_obwp_nonce' ); ?>

        <div class="obwp-field">
            <label for="scope_type"><?php esc_html_e( 'Aplicar a', 'openbooking-wp' ); ?></label>
            <select id="scope_type" name="scope_type">
                <option value="global"><?php esc_html_e( 'Horario general', 'openbooking-wp' ); ?></option>
                <option value="service"><?php esc_html_e( 'Servicio', 'openbooking-wp' ); ?></option>
                <option value="resource"><?php esc_html_e( 'Recurso', 'openbooking-wp' ); ?></option>
            </select>
        </div>

        <div class="obwp-field ob-is-hidden" id="obwp-availability-scope-service">
            <label for="scope_service_id"><?php esc_html_e( 'Servicio', 'openbooking-wp' ); ?></label>
            <select id="scope_service_id" name="scope_service_id">
                <option value="0"><?php esc_html_e( 'Selecciona un servicio', 'openbooking-wp' ); ?></option>
            </select>
        </div>

        <div class="obwp-field ob-is-hidden" id="obwp-availability-scope-resource">
            <label for="scope_resource_id"><?php esc_html_e( 'Recurso', 'openbooking-wp' ); ?></label>
            <select id="scope_resource_id" name="scope_resource_id">
                <option value="0"><?php esc_html_e( 'Selecciona un recurso', 'openbooking-wp' ); ?></option>
            </select>
        </div>

        <!-- Tab: Weekly rules -->
        <div id="tab-rules" class="obwp-tab-panel active">
            <div class="ob-section-title"><?php esc_html_e( 'Horarios semanales', 'openbooking-wp' ); ?></div>
            <div class="obwp-schedule-grid" id="obwp-schedule-grid">
                <?php
                $days = [
                    1 => __( 'Lunes', 'openbooking-wp' ),
                    2 => __( 'Martes', 'openbooking-wp' ),
                    3 => __( 'Miércoles', 'openbooking-wp' ),
                    4 => __( 'Jueves', 'openbooking-wp' ),
                    5 => __( 'Viernes', 'openbooking-wp' ),
                    6 => __( 'Sábado', 'openbooking-wp' ),
                    7 => __( 'Domingo', 'openbooking-wp' ),
                ];
                foreach ( $days as $num => $label ) :
                ?>
                <div class="obwp-schedule-row ob-card-sm" data-day="<?php echo esc_attr( $num ); ?>">
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
        </div>

        <!-- Tab: Breaks -->
        <div id="tab-breaks" class="obwp-tab-panel">
            <div class="ob-section-title"><?php esc_html_e( 'Descansos', 'openbooking-wp' ); ?></div>
            <p class="description"><?php esc_html_e( 'Agrega descansos que se aplican dentro de los horarios semanales.', 'openbooking-wp' ); ?></p>
            <div id="obwp-breaks-list"></div>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-add-break-global"><?php esc_html_e( 'Agregar descanso', 'openbooking-wp' ); ?></button>
        </div>

        <!-- Tab: Exceptions -->
        <div id="tab-exceptions" class="obwp-tab-panel">
            <div class="ob-section-title"><?php esc_html_e( 'Excepciones', 'openbooking-wp' ); ?></div>
            <p class="description"><?php esc_html_e( 'Feriados, bloqueos y fechas especiales.', 'openbooking-wp' ); ?></p>
            <div class="obwp-exceptions" id="obwp-exceptions">
                <div class="obwp-exception-row obwp-template ob-is-hidden">
                    <input type="date" name="exception_date[]">
                    <select name="exception_type[]">
                        <option value="holiday"><?php esc_html_e( 'Feriado', 'openbooking-wp' ); ?></option>
                        <option value="block"><?php esc_html_e( 'Bloquear', 'openbooking-wp' ); ?></option>
                    </select>
                    <input type="text" name="exception_reason[]" placeholder="<?php esc_attr_e( 'Motivo', 'openbooking-wp' ); ?>">
                    <button type="button" class="ob-btn ob-btn-secondary ob-btn-sm obwp-remove-exception">&times;</button>
                </div>
            </div>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-add-exception"><?php esc_html_e( 'Agregar feriado', 'openbooking-wp' ); ?></button>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-add-block"><?php esc_html_e( 'Bloquear fecha', 'openbooking-wp' ); ?></button>
        </div>

        <!-- Tab: Preview -->
        <div id="tab-preview" class="obwp-tab-panel">
            <div class="ob-section-title"><?php esc_html_e( 'Vista previa de disponibilidad', 'openbooking-wp' ); ?></div>
            <p class="description"><?php esc_html_e( 'Previsualiza cómo quedará la agenda antes de guardar cambios.', 'openbooking-wp' ); ?></p>
            <div class="obwp-field">
                <label><?php esc_html_e( 'Servicio para previsualizar', 'openbooking-wp' ); ?></label>
                <select id="obwp-preview-service">
                    <option value="0"><?php esc_html_e( 'Selecciona un servicio', 'openbooking-wp' ); ?></option>
                </select>
            </div>
            <div class="obwp-field">
                <label>
                    <input type="radio" name="preview_mode" value="week" checked> <?php esc_html_e( 'Semana actual', 'openbooking-wp' ); ?>
                </label>
                <label>
                    <input type="radio" name="preview_mode" value="month"> <?php esc_html_e( 'Mes actual', 'openbooking-wp' ); ?>
                </label>
            </div>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-generate-preview"><?php esc_html_e( 'Generar vista previa', 'openbooking-wp' ); ?></button>
            <div id="obwp-preview-result" class="ob-result-block"></div>
            <div id="obwp-preview-conflicts" class="ob-result-block-sm"></div>
        </div>

        <!-- Tab: Snapshots -->
        <div id="tab-snapshots" class="obwp-tab-panel">
            <div class="ob-section-title"><?php esc_html_e( 'Versiones guardadas', 'openbooking-wp' ); ?></div>
            <p class="description"><?php esc_html_e( 'Restaura una configuracion anterior de disponibilidad.', 'openbooking-wp' ); ?></p>
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-create-snapshot"><?php esc_html_e( 'Guardar version actual', 'openbooking-wp' ); ?></button>
            <div id="obwp-snapshots-list" class="ob-result-block-sm"></div>
        </div>

        <div class="obwp-form-actions ob-form-actions-tight">
            <button type="button" class="ob-btn ob-btn-secondary" id="obwp-validate-before-save"><?php esc_html_e( 'Validar antes de guardar', 'openbooking-wp' ); ?></button>
            <button type="submit" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Guardar cambios', 'openbooking-wp' ); ?></button>
        </div>
        <div id="obwp-save-validation-result" class="ob-space-top-sm"></div>
    </form>
</div>
