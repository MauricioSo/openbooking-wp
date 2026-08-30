<?php

declare( strict_types=1 );

/**
 * Plugin Name: OpenBooking WP
 * Plugin URI:  https://openbookingwp.com
 * Description: Plugin open source de agendamiento para WordPress. Reservas, disponibilidad, pagos, WhatsApp con API propia y agenda publica.
 * Version:     1.2.4

 * Author:      OpenBooking WP
 * Author URI:  https://openbookingwp.com
 * License:     GPL-2.0-or-later
 * Text Domain: openbooking-wp
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( PHP_VERSION_ID < 80100 ) {
    add_action( 'admin_notices', 'obwp_php_version_notice' );

    return;
}

function obwp_php_version_notice() {
    echo '<div class="notice notice-error"><p>' . esc_html__( 'OpenBooking WP requiere PHP 8.1 o superior. Cambia la version PHP del sitio en Herd y vuelve a activar el plugin.', 'openbooking-wp' ) . '</p></div>';
}

define( 'OBWP_VERSION', '1.2.4' );
define( 'OBWP_PLUGIN_FILE', __FILE__ );
define( 'OBWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OBWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OBWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once OBWP_PLUGIN_DIR . 'src/Core/Autoloader.php';

$autoloader = new \OpenBooking\Core\Autoloader();
$autoloader->register();

/**
 * Orquesta el arranque del plugin y registra sus capas principales.
 */
final class OpenBooking_WP {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_textdomain();
        $this->init_hooks();
    }

    private function load_textdomain() {
        load_plugin_textdomain( 'openbooking-wp', false, dirname( OBWP_PLUGIN_BASENAME ) . '/languages' );
    }

    private function init_hooks() {
        register_activation_hook( OBWP_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( OBWP_PLUGIN_FILE, [ $this, 'deactivate' ] );

        add_action( 'plugins_loaded', [ $this, 'boot' ], 10 );
    }

    public function activate() {
        $activator = new \OpenBooking\Infrastructure\WordPress\Database\Activator();
        $activator->activate();

        if ( false === get_option( \OpenBooking\Support\Option_Keys::ONBOARDING_DONE, false ) ) {
            set_transient( \OpenBooking\Support\Option_Keys::SHOW_ONBOARDING, true, 60 );
        }

        update_option( \OpenBooking\Support\Option_Keys::DB_VERSION, OBWP_VERSION );

        ( new \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager() )->schedule_events();

        flush_rewrite_rules();
    }

    public function deactivate() {
        foreach ( \OpenBooking\Support\Cron_Hook_Keys::all() as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }

        flush_rewrite_rules();
    }

    public function boot() {
        $this->bootstrap_runtime();
        $this->register_cache_invalidation();
        $this->register_security_layers();
        $this->maybe_run_database_migration();

        $this->register_gateways();
        $this->register_notification_listeners();
        $this->register_privacy_support();
        $this->register_woocommerce_bridge();
        $this->boot_admin_features();
        $this->boot_frontend_features();
        $this->register_core_services();

        do_action( 'openbooking_loaded' );
    }

    /**
     * Inicializa el runtime base del plugin antes de registrar integraciones.
     */
    private function bootstrap_runtime() {
        \OpenBooking\Support\Request_Context::bootstrap();
        \OpenBooking\Support\Container::boot();
        \OpenBooking\Infrastructure\WordPress\EventBus::set_outbox_service(
            \OpenBooking\Support\Container::get( \OpenBooking\Application\Core\Service\Outbox_Service::class )
        );
    }

    /**
     * Invalida cache de opciones cuando WordPress confirma un cambio.
     */
    private function register_cache_invalidation() {
        add_action( 'updated_option', function ( $option ) {
            \OpenBooking\Support\Cached_Options::invalidate( (string) $option );
        } );
    }

    /**
     * Registra las defensas transversales de seguridad HTTP.
     */
    private function register_security_layers() {
        \OpenBooking\Support\Security_Headers::register();
    }

    /**
     * Ejecuta migraciones cuando la version de la base de datos quedo atras.
     */
    private function maybe_run_database_migration() {
        if ( ! $this->needs_database_migration() ) {
            return;
        }

        $activator = new \OpenBooking\Infrastructure\WordPress\Database\Activator();
        $activator->activate();
        update_option( \OpenBooking\Support\Option_Keys::DB_VERSION, OBWP_VERSION );
    }

    /**
     * Verifica si la base de datos requiere migracion.
     */
    private function needs_database_migration() {
        $db_version = get_option( \OpenBooking\Support\Option_Keys::DB_VERSION, '' );

        return version_compare( $db_version, OBWP_VERSION, '<' )
            || \OpenBooking\Infrastructure\WordPress\Database\Activator::needs_migration();
    }

    /**
     * Registra listeners de notificaciones para correo, WhatsApp y SMS.
     */
    private function register_notification_listeners() {
        \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\Notification\Email\Email_Listener::class );
        \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Listener::class );
        \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\Notification\SMS\SMS_Listener::class );
    }

    /**
     * Registra apoyo para exportacion, borrado y guias de privacidad.
     */
    private function register_privacy_support() {
        new \OpenBooking\Infrastructure\WordPress\Privacy\Privacy_Policy_Guide();
        ( new \OpenBooking\Infrastructure\WordPress\Privacy\Customer_Privacy_Handler(
            new \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository(),
            new \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository(),
            new \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository(),
            new \OpenBooking\Infrastructure\Persistence\Payment\Payment_Repository(),
            new \OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository()
        ) )->register();
    }

    /**
     * Conecta los hooks de WooCommerce con el plugin.
     */
    private function register_woocommerce_bridge() {
        \OpenBooking\Infrastructure\WordPress\WooCommerce_Bridge::init_hooks();
    }

    /**
     * Activa componentes exclusivos del panel de administracion.
     */
    private function boot_admin_features() {
        if ( ! is_admin() ) {
            return;
        }

        \OpenBooking\Support\Container::get( \OpenBooking\Presentation\Admin\Menu\Admin_Menu::class );
        new \OpenBooking\Presentation\Admin\Settings\Onboarding();
    }

    /**
     * Registra la capa publica del sitio.
     */
    private function boot_frontend_features() {
        new \OpenBooking\Presentation\Public\Booking\Booking_Shortcode();
        new \OpenBooking\Presentation\Public\Booking\Booking_Block();
    }

    /**
     * Registra servicios de API, cron e integraciones de alto nivel.
     */
    private function register_core_services() {
        \OpenBooking\Support\Container::get( \OpenBooking\Presentation\Rest\Core\Rest_Api_Registrar::class );
        \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager::class );
        \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\Integration\Outbox\Outbox_Webhook_Handler::class )->register();
        \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\Integration\Integration_Manager::class );
    }

    private function register_gateways() {
        $registry = \OpenBooking\Infrastructure\PaymentGateway\Gateway_Registry::class;

        $registry::register( \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\PaymentGateway\Manual\Manual_Gateway::class ) );
        $registry::register( \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\PaymentGateway\Stripe\Stripe_Gateway::class ) );
        $registry::register( \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\PaymentGateway\MercadoPago\MercadoPago_Gateway::class ) );
        $registry::register( \OpenBooking\Support\Container::get( \OpenBooking\Infrastructure\PaymentGateway\Webpay\Webpay_Gateway::class ) );

        do_action( 'openbooking_register_gateways', $registry );
    }
}

OpenBooking_WP::get_instance();
