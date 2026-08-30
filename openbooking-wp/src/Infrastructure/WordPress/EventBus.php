<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Envuelve la publicacion de eventos de dominio sobre WordPress.
 *
 * Usage:
 *
 *   // In services (dipatchers):
 *   EventBus::dispatch( new BookingCreated( $booking_id, $booking_array ) );
 *
 *   // In listeners (subscribers):
 *   EventBus::listen( BookingCreated::class, function ( BookingCreated $event ) { ... } );
 *
 * Backward compatibility:
 *   - Every call to dispatch() also fires a WordPress do_action() with the
 *     event_name() as hook and a flat array payload. Existing WordPress hook
 *     listeners continue to work without changes.
 *   - EventBus::listen() uses a typed callback; add_action() listeners continue
 *     to receive arrays.
 */
final class EventBus {

    /** @var array<string, callable[]> */
    private static array $listeners = [];
    private static ?\OpenBooking\Application\Core\Service\Outbox_Service $outbox_service = null;

    private function __construct() {}

    /**
     * Dispatch a domain event.
     *
     * 1. Fires the WordPress action hook (backward compat).
     * 2. Notifies typed listeners registered via listen().
     */
    public static function dispatch( DomainEvent $event ): void {
        $hook_name = $event->event_name();

        self::record_outbox_event( $event );

        // Fire WordPress hook so existing add_action listeners keep working.
        \do_action( $hook_name, $event->aggregate_id(), $event->to_array() );

        // Notify typed listeners.
        $class_name = get_class( $event );
        if ( isset( self::$listeners[ $class_name ] ) ) {
            foreach ( self::$listeners[ $class_name ] as $callback ) {
                $callback( $event );
            }
        }
        // Also notify listeners subscribed to the interface.
        if ( isset( self::$listeners[ DomainEvent::class ] ) ) {
            foreach ( self::$listeners[ DomainEvent::class ] as $callback ) {
                $callback( $event );
            }
        }
    }

    /**
     * Register a typed listener for a specific event class.
     *
     * @param string   $eventClass FQCN of a DomainEvent implementation.
     * @param callable $callback   Receives the event instance.
     */
    public static function listen( string $eventClass, callable $callback ): void {
        if ( ! isset( self::$listeners[ $eventClass ] ) ) {
            self::$listeners[ $eventClass ] = [];
        }
        self::$listeners[ $eventClass ][] = $callback;
    }

    public static function set_outbox_service( \OpenBooking\Application\Core\Service\Outbox_Service $outbox_service ): void {
        self::$outbox_service = $outbox_service;
    }

    private static function record_outbox_event( DomainEvent $event ): void {
        if ( ! (bool) \get_option( Setting_Keys::OUTBOX_RECORD_EVENTS, 0 ) ) {
            return;
        }

        if ( null === self::$outbox_service ) {
            return;
        }

        try {
            self::$outbox_service->record_domain_event( $event );
        } catch ( \Throwable $e ) {
            \error_log( '[OpenBooking] Outbox record failed: ' . $e->getMessage() );
        }
    }
}
