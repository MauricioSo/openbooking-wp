<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Proxy de transacciones del dominio hacia el adapter de WordPress.
 */
class Transaction_Manager implements \OpenBooking\Domain\Shared\Port\TransactionManagerInterface {

    private \OpenBooking\Domain\Shared\Port\TransactionManagerInterface $inner;

    public function __construct( ?\OpenBooking\Domain\Shared\Port\TransactionManagerInterface $inner = null ) {
        $this->inner = $inner ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_Transaction_Manager();
    }

    public function begin(): void {
        $this->inner->begin();
    }

    public function commit(): void {
        $this->inner->commit();
    }

    public function rollback(): void {
        $this->inner->rollback();
    }

    public function last_error(): string {
        return $this->inner->last_error();
    }
}
