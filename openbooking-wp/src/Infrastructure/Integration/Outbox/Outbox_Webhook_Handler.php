<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Integration\Outbox;

use OpenBooking\Integration\Domain_Event;
use OpenBooking\Infrastructure\Integration\Webhook\Outbound_Webhook_Dispatcher;
use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Booking\Event\BookingCancelled;
use OpenBooking\Domain\Booking\Event\BookingConfirmed;
use OpenBooking\Domain\Booking\Event\BookingCreated;
use OpenBooking\Domain\Booking\Event\BookingExpired;
use OpenBooking\Domain\Booking\Event\BookingNoShow;
use OpenBooking\Domain\Booking\Event\BookingRescheduled;
use OpenBooking\Domain\Payment\Event\PaymentFailed;
use OpenBooking\Domain\Payment\Event\PaymentReceived;
use OpenBooking\Support\Booking_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convierte eventos de outbox en eventos de dominio para webhooks.
 */
class Outbox_Webhook_Handler {

    private Outbound_Webhook_Dispatcher $dispatcher;

    private ?\OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo;

    /** @var array<string, string> */
    private array $booking_events = [
        BookingCreated::class     => Domain_Event::BOOKING_CREATED,
        BookingConfirmed::class   => Domain_Event::BOOKING_CONFIRMED,
        BookingCancelled::class   => Domain_Event::BOOKING_CANCELLED,
        BookingRescheduled::class => Domain_Event::BOOKING_RESCHEDULED,
        BookingNoShow::class      => Domain_Event::BOOKING_NO_SHOW,
        BookingExpired::class     => Domain_Event::BOOKING_EXPIRED,
    ];

    /** @var array<string, string> */
    private array $payment_events = [
        PaymentReceived::class => Domain_Event::PAYMENT_CAPTURED,
        PaymentFailed::class   => Domain_Event::PAYMENT_FAILED,
    ];

    public function __construct(
        ?Outbound_Webhook_Dispatcher $dispatcher = null,
        ?\OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo = null,
    ) {
        $this->dispatcher = $dispatcher ?: new Outbound_Webhook_Dispatcher();
        $this->booking_repo = $booking_repo;
    }

    public function register(): void {
        if ( ! (bool) get_option( Setting_Keys::ASYNC_OUTBOUND_WEBHOOKS, 0 ) ) {
            return;
        }

        add_filter( 'openbooking_outbox_handle_event', [ $this, 'handle' ], 10, 2 );
    }

    public function handle( bool $handled, array $row ): bool {
        if ( $handled ) {
            return true;
        }

        $event = $this->to_domain_event( $row );
        if ( ! $event ) {
            return false;
        }

        $dispatched = $this->dispatcher->dispatch( $event );
        if ( ! $dispatched ) {
            // Propaga el error real (SSRF/allowlist/HTTP/red) al last_error del outbox
            // en lugar del generico "No outbox handler accepted the event.".
            $error = $this->dispatcher->get_last_error();
            throw new \RuntimeException( '' !== $error ? $error : 'Webhook dispatch failed.' );
        }

        return true;
    }

    private function to_domain_event( array $row ): ?Domain_Event {
        $stored = json_decode( (string) ( $row['payload'] ?? '' ), true );
        if ( ! is_array( $stored ) ) {
            return null;
        }

        $event_class = (string) ( $stored['event_class'] ?? $row['event_class'] ?? '' );
        $payload     = $stored['payload'] ?? [];
        if ( ! is_array( $payload ) ) {
            return null;
        }

        if ( isset( $this->booking_events[ $event_class ] ) ) {
            return Domain_Event::from_booking( $this->booking_events[ $event_class ], $payload );
        }

        if ( isset( $this->payment_events[ $event_class ] ) ) {
            $booking = $this->load_booking_for_payment( $payload );

            return $booking ? Domain_Event::from_booking( $this->payment_events[ $event_class ], $booking ) : null;
        }

        return null;
    }

    private function load_booking_for_payment( array $payment ): ?array {
        $booking_id = (int) ( $payment['booking_id'] ?? 0 );
        if ( ! $booking_id ) {
            return null;
        }

        if ( ! $this->booking_repo ) {
            return null;
        }

        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return null;
        }

        return Booking_Payloads::public_from_entity( $booking );
    }
}
