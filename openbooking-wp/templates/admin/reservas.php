<?php if ( ! defined( 'ABSPATH' ) ) exit;
$active_tab = sanitize_key( $_GET['tab'] ?? 'agenda' );
if ( ! in_array( $active_tab, [ 'agenda', 'clientes' ], true ) ) {
    $active_tab = 'agenda';
}
?>
<div class="wrap obwp-wrap ob-admin-wrap">
    <nav class="ob-hub-nav">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-reservas&tab=agenda' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'agenda' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Agenda', 'openbooking-wp' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=openbooking-reservas&tab=clientes' ) ); ?>"
           class="ob-hub-tab <?php echo $active_tab === 'clientes' ? 'active' : ''; ?>">
            <?php esc_html_e( 'Clientes', 'openbooking-wp' ); ?>
        </a>
    </nav>
    <div class="ob-hub-panel">
        <?php if ( $active_tab === 'agenda' ) : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/agenda.php'; ?>
        <?php else : ?>
            <?php include OBWP_PLUGIN_DIR . 'templates/admin/customers.php'; ?>
        <?php endif; ?>
    </div>
</div>
