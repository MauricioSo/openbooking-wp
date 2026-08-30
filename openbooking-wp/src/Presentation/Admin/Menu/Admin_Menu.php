<?php


declare( strict_types=1 );
namespace OpenBooking\Presentation\Admin\Menu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Componente del bounded context de admin.
 */

class Admin_Menu {


    public function __construct(
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
    ) {
add_action( 'admin_menu', [ $this, 'register_admin_menus' ] );        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function register_admin_menus(): void {
        $menu_icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">'
            . '<rect x="14" y="22" width="92" height="84" rx="24" fill="#C8761E"/>'
            . '<rect x="14" y="22" width="92" height="28" rx="24" fill="#B85C3D"/>'
            . '<rect x="14" y="38" width="92" height="12" fill="#B85C3D"/>'
            . '<rect x="34" y="10" width="12" height="24" rx="6" fill="#2F2E26"/>'
            . '<rect x="74" y="10" width="12" height="24" rx="6" fill="#2F2E26"/>'
            . '<circle cx="42" cy="64" r="6" fill="#2F2E26"/>'
            . '<circle cx="78" cy="64" r="6" fill="#2F2E26"/>'
            . '<path d="M42 78C50 90 70 90 78 78" stroke="#2F2E26" stroke-width="5" stroke-linecap="round" fill="none"/>'
            . '</svg>'
        );

        add_menu_page(
            __( 'OpenBooking', 'openbooking-wp' ),
            __( 'OpenBooking', 'openbooking-wp' ),
            'manage_options',
            'openbooking',
            [ $this, 'render_dashboard_screen' ],
            $menu_icon,
            30
        );

        add_submenu_page( 'openbooking', __( 'Inicio',         'openbooking-wp' ), __( 'Inicio',         'openbooking-wp' ), 'manage_options', 'openbooking',               [ $this, 'render_dashboard_screen' ] );
        add_submenu_page( 'openbooking', __( 'Reservas',       'openbooking-wp' ), __( 'Reservas',       'openbooking-wp' ), 'manage_options', 'openbooking-reservas',      [ $this, 'render_bookings_screen' ] );
        add_submenu_page( 'openbooking', __( 'Servicios',      'openbooking-wp' ), __( 'Servicios',      'openbooking-wp' ), 'manage_options', 'openbooking-services',      [ $this, 'render_services_screen' ] );
        add_submenu_page( 'openbooking', __( 'Pagos',          'openbooking-wp' ), __( 'Pagos',          'openbooking-wp' ), 'manage_options', 'openbooking-payments',      [ $this, 'render_payments_screen' ] );
        add_submenu_page( 'openbooking', __( 'Notificaciones', 'openbooking-wp' ), __( 'Notificaciones', 'openbooking-wp' ), 'manage_options', 'openbooking-notifications', [ $this, 'render_notifications_screen' ] );
        add_submenu_page( 'openbooking', __( 'Ajustes',        'openbooking-wp' ), __( 'Ajustes',        'openbooking-wp' ), 'manage_options', 'openbooking-settings',      [ $this, 'render_settings_screen' ] );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'openbooking' ) === false && $hook !== 'toplevel_page_openbooking' ) {
            return;
        }

        wp_enqueue_style(
            'ob-fonts',
            'https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700;6..12,800;6..12,900&display=swap',
            [],
            null
        );

        wp_enqueue_style( 'obwp-admin-tokens', OBWP_PLUGIN_URL . 'assets/css/admin-tokens.css', [ 'ob-fonts' ], OBWP_VERSION );
        wp_enqueue_style( 'obwp-admin-components', OBWP_PLUGIN_URL . 'assets/css/admin-components.css', [ 'obwp-admin-tokens' ], OBWP_VERSION );
        wp_enqueue_style( 'obwp-admin', OBWP_PLUGIN_URL . 'assets/css/admin.css', [ 'obwp-admin-components' ], OBWP_VERSION );

        $admin_js_path = OBWP_PLUGIN_DIR . 'assets/js/admin.js';
        $admin_js_ver  = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : OBWP_VERSION;
        wp_enqueue_script( 'obwp-admin', OBWP_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], $admin_js_ver, true );

        wp_localize_script( 'obwp-admin', 'obwpAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'openbooking/v1/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'strings' => [
                'confirm_delete'   => __( 'Estas seguro de eliminar esto?', 'openbooking-wp' ),
                'confirm_cancel'   => __( 'Cancelar esta reserva?', 'openbooking-wp' ),
                'saving'           => __( 'Guardando...', 'openbooking-wp' ),
                'error_generic'    => __( 'Ocurrio un error. Intenta de nuevo.', 'openbooking-wp' ),
                'session_expired'  => __( 'Tu sesion expiro. Recarga la pagina e inicia sesion nuevamente si hace falta.', 'openbooking-wp' ),
                'no_bookings'      => __( 'No hay reservas para hoy.', 'openbooking-wp' ),
            ],
        ] );
    }

    public function render_dashboard_screen(): void {
        $today        = current_time( 'Y-m-d' );

        $today_bookings = $this->booking_repo->find_all( [
            'date_from' => $today . ' 00:00:00',
            'date_to'   => $today . ' 23:59:59',
            'status'    => [ 'pending', 'confirmed' ],
            'order_by'  => 'start_at',
            'order'     => 'ASC',
            'limit'     => 20,
        ] );

        $today_booking_count = $this->booking_repo->count_for_date( $today );
        $pending_bookings    = $this->booking_repo->find_all( [
            'status' => 'pending',
            'limit'  => 100,
        ] );

        include OBWP_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }

    public function render_bookings_screen(): void {
        include OBWP_PLUGIN_DIR . 'templates/admin/reservas.php';
    }

    public function render_services_screen(): void {
        $action             = sanitize_text_field( $_GET['action'] ?? 'list' );
        $service_id         = absint( $_GET['id'] ?? 0 );
        $service            = null;
        $services           = [];

        if ( $action === 'edit' && $service_id ) {
            $service = $this->service_repo->find( $service_id );
        } elseif ( $action === 'new' ) {
            $service = null;
        } else {
            $services = $this->service_repo->find_all();
        }

        include OBWP_PLUGIN_DIR . 'templates/admin/servicios-hub.php';
    }

    public function render_settings_screen(): void {
        include OBWP_PLUGIN_DIR . 'templates/admin/ajustes-hub.php';
    }

    public function render_payments_screen(): void {
        include OBWP_PLUGIN_DIR . 'templates/admin/payments.php';
    }

    public function render_notifications_screen(): void {
        include OBWP_PLUGIN_DIR . 'templates/admin/notifications.php';
    }
}
