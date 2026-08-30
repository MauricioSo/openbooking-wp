<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Application\Shared\Port\HookDispatcherInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador para aplicar filtros y acciones de WordPress.
 */
class WP_HookDispatcher implements HookDispatcherInterface {

    public function apply_filters( string $tag, $value, ...$args ): mixed {
        return function_exists( 'apply_filters' )
            ? \apply_filters( $tag, $value, ...$args )
            : $value;
    }

    public function do_action( string $tag, ...$args ): void {
        if ( function_exists( 'do_action' ) ) {
            \do_action( $tag, ...$args );
        }
    }
}
