<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para manejar transacciones.
 */
interface TransactionManagerInterface {

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function last_error(): string;
}
