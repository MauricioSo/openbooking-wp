<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <h1 class="obwp-header-with-action ob-page-title">
        <?php esc_html_e( 'Servicios', 'openbooking-wp' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&action=new' ) ); ?>" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Nuevo servicio', 'openbooking-wp' ); ?></a>
    </h1>

    <?php if ( empty( $services ) ) : ?>
        <div class="obwp-empty-state ob-empty-state">
            <p><?php esc_html_e( 'Todavía no tienes servicios. Crea el primero para comenzar.', 'openbooking-wp' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&action=new' ) ); ?>" class="ob-btn ob-btn-primary"><?php esc_html_e( 'Crear servicio', 'openbooking-wp' ); ?></a>
        </div>
    <?php else : ?>
        <div class="obwp-filter-bar" id="obwp-service-filters">
            <label for="obwp-service-status-filter"><?php esc_html_e( 'Estado', 'openbooking-wp' ); ?></label>
            <select id="obwp-service-status-filter">
                <option value=""><?php esc_html_e( 'Todos', 'openbooking-wp' ); ?></option>
                <option value="active"><?php esc_html_e( 'Activo', 'openbooking-wp' ); ?></option>
                <option value="draft"><?php esc_html_e( 'Borrador', 'openbooking-wp' ); ?></option>
                <option value="archived"><?php esc_html_e( 'Archivado', 'openbooking-wp' ); ?></option>
            </select>
            <label for="obwp-service-visibility-filter"><?php esc_html_e( 'Visibilidad', 'openbooking-wp' ); ?></label>
            <select id="obwp-service-visibility-filter">
                <option value=""><?php esc_html_e( 'Todas', 'openbooking-wp' ); ?></option>
                <option value="public"><?php esc_html_e( 'Publica', 'openbooking-wp' ); ?></option>
                <option value="private"><?php esc_html_e( 'Privada', 'openbooking-wp' ); ?></option>
            </select>
        </div>
        <div class="obwp-cards-grid">
            <?php foreach ( $services as $svc ) : ?>
                <div class="obwp-card" data-status="<?php echo esc_attr( $svc->status ); ?>" data-visibility="<?php echo esc_attr( $svc->visibility ); ?>">
                    <div class="obwp-card-header">
                        <?php if ( $svc->color ) : ?>
                            <span class="obwp-color-dot" style="--obwp-color-dot: <?php echo esc_attr( $svc->color ); ?>"></span>
                        <?php endif; ?>
                        <h3><?php echo esc_html( $svc->name ); ?></h3>
                        <span class="obwp-status obwp-status--<?php echo esc_attr( $svc->status ); ?>"><?php echo esc_html( $svc->status ); ?></span>
                    </div>
                    <div class="obwp-card-body">
                        <p><?php echo esc_html( $svc->duration_minutes ); ?> min &middot; <?php echo esc_html( $svc->get_formatted_price() ); ?> &middot; <?php echo esc_html( $svc->mode ); ?> &middot; <?php echo esc_html( $svc->visibility ); ?></p>
                        <?php if ( $svc->capacity > 1 ) : ?>
                            <p><?php echo esc_html( $svc->capacity ); ?> <?php esc_html_e( 'cupos', 'openbooking-wp' ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="obwp-card-actions">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&action=edit&id=' . $svc->id ) ); ?>" class="ob-btn ob-btn-secondary ob-btn-sm"><?php esc_html_e( 'Editar', 'openbooking-wp' ); ?></a>
                        <button class="ob-btn ob-btn-danger ob-btn-sm obwp-delete-service" data-id="<?php echo esc_attr( $svc->id ); ?>"><?php esc_html_e( 'Eliminar', 'openbooking-wp' ); ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="obwp-empty ob-is-hidden" id="obwp-service-filter-empty"><?php esc_html_e( 'No hay servicios que coincidan con los filtros.', 'openbooking-wp' ); ?></p>
        <script>
        (function($) {
            function applyServiceFilters() {
                var status = $('#obwp-service-status-filter').val();
                var visibility = $('#obwp-service-visibility-filter').val();
                var visible = 0;
                $('.obwp-cards-grid .obwp-card').each(function() {
                    var $card = $(this);
                    var matches = (!status || $card.data('status') === status) && (!visibility || $card.data('visibility') === visibility);
                    $card.toggle(matches);
                    if (matches) visible++;
                });
                $('#obwp-service-filter-empty').toggleClass('ob-is-hidden', visible > 0);
            }
            $(document).on('change', '#obwp-service-status-filter, #obwp-service-visibility-filter', applyServiceFilters);
        })(jQuery);
        </script>
    <?php endif; ?>
</div>
