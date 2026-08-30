<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para obtener tiempo actual.
 */
interface ClockInterface {

    public function now(): \DateTimeImmutable;

    public function timestamp(): int;
}
