<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para exportacion de datos personales.
 */
interface PrivacyHandlerInterface {
    public function export_json_portable( string $email_address ): array;
}
