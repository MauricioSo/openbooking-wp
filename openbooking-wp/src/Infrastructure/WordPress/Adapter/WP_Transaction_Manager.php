<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Domain\Shared\Port\TransactionManagerInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador de transacciones sobre la base de datos de WordPress.
 */
class WP_Transaction_Manager implements TransactionManagerInterface {

    private \wpdb $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function begin(): void {
        $this->wpdb->query( 'START TRANSACTION' );
    }

    public function commit(): void {
        $this->wpdb->query( 'COMMIT' );
    }

    public function rollback(): void {
        $this->wpdb->query( 'ROLLBACK' );
    }

    public function last_error(): string {
        return property_exists( $this->wpdb, 'last_error' ) ? (string) $this->wpdb->last_error : '';
    }
}
