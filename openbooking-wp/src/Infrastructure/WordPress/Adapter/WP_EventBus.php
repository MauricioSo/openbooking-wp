<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Event\DomainEvent;
use OpenBooking\Domain\Shared\Port\EventBusInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador de publicacion de eventos de dominio sobre WordPress.
 */
final class WP_EventBus implements EventBusInterface {

    private static array $listeners = [];

    public function __construct(
        private \OpenBooking\Application\Core\Service\Outbox_Service $outbox_service,
    ) {}

    public function dispatch( DomainEvent $event ): void {
        $this->record_outbox_event( $event );

        $hook_name = $event->event_name();
        if ( function_exists( 'do_action' ) ) {
            \do_action( $hook_name, $event->aggregate_id(), $event->to_array() );
        }

        $class_name = get_class( $event );
        if ( isset( self::$listeners[ $class_name ] ) ) {
            foreach ( self::$listeners[ $class_name ] as $callback ) {
                $callback( $event );
            }
        }
        if ( isset( self::$listeners[ DomainEvent::class ] ) ) {
            foreach ( self::$listeners[ DomainEvent::class ] as $callback ) {
                $callback( $event );
            }
        }
    }

    public function listen( string $eventClass, callable $callback ): void {
        if ( ! isset( self::$listeners[ $eventClass ] ) ) {
            self::$listeners[ $eventClass ] = [];
        }
        self::$listeners[ $eventClass ][] = $callback;
    }

    private function record_outbox_event( DomainEvent $event ): void {
        if ( ! (bool) \get_option( Setting_Keys::OUTBOX_RECORD_EVENTS, 0 ) ) {
            return;
        }

        try {
            $this->outbox_service->record_domain_event( $event );
        } catch ( \Throwable $e ) {
            \error_log( '[OpenBooking] Outbox record failed: ' . $e->getMessage() );
        }
    }
}
