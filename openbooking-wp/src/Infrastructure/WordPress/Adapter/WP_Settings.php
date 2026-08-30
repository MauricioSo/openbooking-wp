<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador de ajustes sobre la API nativa de WordPress.
 */
class WP_Settings implements SettingsInterface {

    public function get( string $key, $default = null ): mixed {
        return function_exists( 'get_option' ) ? get_option( $key, $default ) : $default;
    }

    public function set( string $key, $value ): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( $key, $value, false );
        }
    }
}
