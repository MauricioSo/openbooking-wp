<?php if ( ! defined( 'ABSPATH' ) ) exit;

$action = sanitize_text_field( $_GET['action'] ?? 'list' );
$is_edit = in_array( $action, [ 'edit', 'new' ], true );

$active_tab = $is_edit ? 'servicios' : sanitize_key( $_GET['tab'] ?? 'servicios' );
if ( ! in_array( $active_tab, [ 'servicios', 'recursos', 'disponibilidad' ], true ) ) {
    $active_tab = 'servicios';
}
?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <nav class="ob-hub-nav">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&tab=servicios' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'servicios' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Servicios', 'openbooking-wp' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&tab=recursos' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'recursos' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Recursos', 'openbooking-wp' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&tab=disponibilidad' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'disponibilidad' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Disponibilidad', 'openbooking-wp' ); ?>
        </a>
        <?php if ( $active_tab === 'servicios' && ! $is_edit ) : ?>
        <span class="ob-hub-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-services&action=new' ) ); ?>" class="ob-btn ob-btn-primary">
                + <?php esc_html_e( 'Nuevo servicio', 'openbooking-wp' ); ?>
            </a>
        </span>
        <?php elseif ( $active_tab === 'recursos' ) : ?>
        <span class="ob-hub-actions">
            <button type="button" class="ob-btn ob-btn-primary" id="obwp-new-resource-btn">
                + <?php esc_html_e( 'Nuevo recurso', 'openbooking-wp' ); ?>
            </button>
        </span>
        <?php endif; ?>
    </nav>
    <?php
    $notice_code = sanitize_key( $_GET['obwp_notice'] ?? '' );
    $notice_messages = [
        'service_created' => __( 'Servicio creado correctamente.', 'openbooking-wp' ),
        'service_updated' => __( 'Servicio actualizado correctamente.', 'openbooking-wp' ),
    ];
    if ( isset( $notice_messages[ $notice_code ] ) ) :
    ?>
        <div class="notice notice-success is-dismissible obwp-persistent-notice">
            <p><?php echo esc_html( $notice_messages[ $notice_code ] ); ?></p>
        </div>
    <?php endif; ?>
    <div class="ob-hub-panel">
        <?php if ( $active_tab === 'servicios' ) : ?>
            <?php if ( $is_edit ) : ?>
                <?php include OBWP_PLUGIN_DIR . 'templates/admin/service-edit.php'; ?>
            <?php else : ?>
                <?php include OBWP_PLUGIN_DIR . 'templates/admin/services.php'; ?>
            <?php endif; ?>
        <?php elseif ( $active_tab === 'recursos' ) : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/resources.php'; ?>
        <?php else : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/availability.php'; ?>
        <?php endif; ?>
    </div>
</div>
