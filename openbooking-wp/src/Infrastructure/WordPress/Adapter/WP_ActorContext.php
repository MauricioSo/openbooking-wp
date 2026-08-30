<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador del contexto del actor sobre WordPress.
 */
class WP_ActorContext implements ActorContextInterface {
    public function is_user_logged_in(): bool {
        return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
    }

    public function current_user_can( string $capability ): bool {
        return function_exists( 'current_user_can' ) && current_user_can( $capability );
    }

    public function get_current_user_id(): int {
        return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
    }
}
