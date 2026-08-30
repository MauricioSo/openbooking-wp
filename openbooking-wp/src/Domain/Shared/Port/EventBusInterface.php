<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\Port;

use OpenBooking\Domain\Shared\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para publicar eventos de dominio.
 */
interface EventBusInterface {

    public function dispatch( DomainEvent $event ): void;

    public function listen( string $eventClass, callable $callback ): void;
}
