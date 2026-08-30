<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para sanitizar entradas.
 */
interface SanitizerInterface {
    public function text( $value ): string;
    public function textarea( $value ): string;
    public function key( $value ): string;
    public function email( $value ): string;
    public function absint( $value ): int;
}
