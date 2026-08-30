<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para aplicar filtros y acciones.
 */
interface HookDispatcherInterface {

    public function apply_filters( string $tag, $value, ...$args ): mixed;

    public function do_action( string $tag, ...$args ): void;
}
