<?php if ( ! defined( 'ABSPATH' ) ) exit;
$active_tab = sanitize_key( $_GET['tab'] ?? 'general' );
if ( ! in_array( $active_tab, [ 'general', 'diseno', 'estado', 'audit' ], true ) ) {
    $active_tab = 'general';
}
?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <nav class="ob-hub-nav">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-settings&tab=general' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>">
            <?php esc_html_e( 'General', 'openbooking-wp' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-settings&tab=diseno' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'diseno' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Diseño', 'openbooking-wp' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-settings&tab=estado' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'estado' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Estado', 'openbooking-wp' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-settings&tab=audit' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'audit' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Audit Logs', 'openbooking-wp' ); ?>
        </a>
    </nav>
    <div class="ob-hub-panel">
        <?php if ( $active_tab === 'general' ) : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/settings.php'; ?>
        <?php elseif ( $active_tab === 'diseno' ) : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/design.php'; ?>
        <?php elseif ( $active_tab === 'estado' ) : ?>
            <?php ( new \OpenBooking\Presentation\Admin\System\System_Status_Page() )->render_system_status_page(); ?>
        <?php else : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/audit-logs.php'; ?>
        <?php endif; ?>
    </div>
</div>
