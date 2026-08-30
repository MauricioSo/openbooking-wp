<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para leer y escribir ajustes.
 */
interface SettingsInterface {

    public function get( string $key, $default = null ): mixed;

    public function set( string $key, $value ): void;
}
