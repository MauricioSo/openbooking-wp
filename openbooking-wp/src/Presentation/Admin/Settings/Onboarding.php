<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Admin\Settings;

use OpenBooking\Support\Option_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gestiona el flujo de onboarding inicial.
 */
class Onboarding {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'redirect_to_onboarding_if_needed' ] );
        add_action( 'admin_menu', [ $this, 'register_onboarding_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_onboarding_assets' ] );
    }

    public function redirect_to_onboarding_if_needed(): void {
        if ( ! get_transient( Option_Keys::SHOW_ONBOARDING ) ) {
            return;
        }
        if ( get_option( Option_Keys::ONBOARDING_DONE, false ) ) {
            delete_transient( Option_Keys::SHOW_ONBOARDING );
            return;
        }
        delete_transient( Option_Keys::SHOW_ONBOARDING );

        if ( isset( $_GET['page'] ) && $_GET['page'] === 'openbooking-onboarding' ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=openbooking-onboarding' ) );
        exit;
    }

    public function register_onboarding_page(): void {
        add_submenu_page(
            'openbooking',
            __( 'Configuracion inicial', 'openbooking-wp' ),
            '',
            'manage_options',
            'openbooking-onboarding',
            [ $this, 'render_onboarding_screen' ]
        );
    }

    public function enqueue_onboarding_assets( string $hook ): void {
        if ( $hook !== 'openbooking_page_openbooking-onboarding' ) {
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
        wp_enqueue_style( 'obwp-onboarding', OBWP_PLUGIN_URL . 'assets/css/onboarding.css', [ 'obwp-admin-components' ], OBWP_VERSION );
        wp_enqueue_script( 'obwp-onboarding', OBWP_PLUGIN_URL . 'assets/js/onboarding.js', [ 'jquery' ], OBWP_VERSION, true );
        wp_localize_script( 'obwp-onboarding', 'obwpOnboarding', [
            'restUrl' => rest_url( 'openbooking/v1/' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    public function render_onboarding_screen(): void {
        if ( get_option( Option_Keys::ONBOARDING_DONE, false ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=openbooking' ) );
            exit;
        }
        include OBWP_PLUGIN_DIR . 'templates/admin/onboarding.php';
    }
}
