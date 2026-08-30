<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para activar y versionar el esquema.
 */
interface ActivatorInterface {
    public function get_schema_version(): int;
    public function needs_migration(): bool;
    public function get_expected_schema_version(): int;
}
