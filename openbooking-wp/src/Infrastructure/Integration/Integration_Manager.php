<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Integration;

use OpenBooking\Integration\Domain_Event;
use OpenBooking\Infrastructure\Integration\Webhook\Outbound_Webhook_Dispatcher;
use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Booking\Event\BookingCancelled;
use OpenBooking\Domain\Booking\Event\BookingConfirmed;
use OpenBooking\Domain\Booking\Event\BookingCreated;
use OpenBooking\Domain\Booking\Event\BookingExpired;
use OpenBooking\Domain\Booking\Event\BookingNoShow;
use OpenBooking\Domain\Booking\Event\BookingRescheduled;
use OpenBooking\Domain\Shared\Event\DomainEvent;
use OpenBooking\Domain\Shared\Port\EventBusInterface;
use OpenBooking\Domain\Payment\Event\PaymentFailed;
use OpenBooking\Domain\Payment\Event\PaymentReceived;
use OpenBooking\Support\Booking_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conecta eventos de dominio con el dispatcher de webhooks.
 *
 * Subscribes to typed domain events via EventBus::listen().
 * Also keeps add_action() fallbacks for backward compatibility
 * with any external code that hooks directly.
 */
class Integration_Manager {

    private Outbound_Webhook_Dispatcher $dispatcher;

    public function __construct(
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private EventBusInterface $event_bus, // publica eventos de dominio
    ) {
$this->dispatcher = new Outbound_Webhook_Dispatcher();$this->register_typed_listeners();
    }

    /**
     * Typed listeners via EventBus.
     * Receives DomainEvent objects with typed accessors.
     *
     * Note: we do NOT register add_action() here because EventBus::dispatch()
     * already fires do_action() for backward compat with listeners that
     * haven't migrated yet. Using both would double-dispatch.
     */
    private function register_typed_listeners(): void {
        $this->event_bus->listen( BookingCreated::class,    [ $this, 'on_booking_created' ] );
        $this->event_bus->listen( BookingConfirmed::class,  [ $this, 'on_booking_confirmed' ] );
        $this->event_bus->listen( BookingCancelled::class,  [ $this, 'on_booking_cancelled' ] );
        $this->event_bus->listen( BookingRescheduled::class,[ $this, 'on_booking_rescheduled' ] );
        $this->event_bus->listen( BookingNoShow::class,     [ $this, 'on_booking_no_show' ] );
        $this->event_bus->listen( BookingExpired::class,    [ $this, 'on_booking_expired' ] );
        $this->event_bus->listen( PaymentReceived::class,   [ $this, 'on_payment_received' ] );
        $this->event_bus->listen( PaymentFailed::class,     [ $this, 'on_payment_failed' ] );
    }

    // ── Typed event handlers ────────────────────────────────────────────

    public function on_booking_created( BookingCreated $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::BOOKING_CREATED, $event->booking() )
        );
    }

    public function on_booking_confirmed( BookingConfirmed $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::BOOKING_CONFIRMED, $event->booking() )
        );
    }

    public function on_booking_cancelled( BookingCancelled $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::BOOKING_CANCELLED, $event->booking() )
        );
    }

    public function on_booking_rescheduled( BookingRescheduled $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::BOOKING_RESCHEDULED, $event->booking() )
        );
    }

    public function on_booking_no_show( BookingNoShow $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::BOOKING_NO_SHOW, $event->to_array() )
        );
    }

    public function on_booking_expired( BookingExpired $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::BOOKING_EXPIRED, $event->to_array() )
        );
    }

    public function on_payment_received( PaymentReceived $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $booking = $this->load_booking_for_payment( $event->to_array() );
        if ( ! $booking ) {
            return;
        }
        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::PAYMENT_CAPTURED, $booking )
        );
    }

    public function on_payment_failed( PaymentFailed $event ): void {
        if ( ! $this->should_dispatch_sync_outbound_webhooks() ) {
            return;
        }

        $booking = $this->load_booking_for_payment( $event->to_array() );
        if ( ! $booking ) {
            return;
        }
        $this->dispatcher->dispatch(
            Domain_Event::from_booking( Domain_Event::PAYMENT_FAILED, $booking )
        );
    }

    private function load_booking_for_payment( array $payment ): ?array {
        $booking_id = (int) ( $payment['booking_id'] ?? 0 );
        if ( ! $booking_id ) {
            return null;
        }
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return null;
        }

        return Booking_Payloads::public_from_entity( $booking );
    }

    private function should_dispatch_sync_outbound_webhooks(): bool {
        return ! ( (bool) get_option( Setting_Keys::OUTBOX_RECORD_EVENTS, 0 ) && (bool) get_option( Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS, 0 ) );
    }
}
