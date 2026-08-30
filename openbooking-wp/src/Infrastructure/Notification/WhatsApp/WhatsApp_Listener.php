<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Notification\WhatsApp;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connects domain action hooks to WhatsApp_Service.
 *
 * Mirrors Email_Listener: same events, same idempotency pattern,
 * same WordPress filter hooks so each notification type can be
 * enabled/disabled independently via filter.
 *
 * This listener is registered only when WhatsApp is enabled in settings.
 * See OpenBooking_WP::boot() where both Email_Listener and WhatsApp_Listener
 * are instantiated.
 */
/**
 * Traduce eventos de dominio en mensajes de WhatsApp.
 */
class WhatsApp_Listener {

    private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo;

    private \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo;

    private \OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager;

    public function __construct(
        \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo,
        \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo,
        \OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager,
    ) {
        $this->booking_repo         = $booking_repo;
        $this->customer_repo        = $customer_repo;
        $this->notification_manager = $notification_manager;

        // Solo registra hooks si WhatsApp esta habilitado globalmente
        if ( ! (bool) get_option( Setting_Keys::WHATSAPP_ENABLED, false ) ) {
            return;
        }
        add_action( 'openbooking_booking_confirmed',   [ $this, 'on_booking_confirmed' ],   10, 2 );
        add_action( 'openbooking_booking_cancelled',   [ $this, 'on_booking_cancelled' ],   10, 2 );
        add_action( 'openbooking_booking_rescheduled', [ $this, 'on_booking_rescheduled' ], 10, 2 );
        add_action( 'openbooking_booking_expired',     [ $this, 'on_booking_expired' ],     10, 2 );
        add_action( 'openbooking_payment_received',    [ $this, 'on_payment_received' ],    10, 2 );
        add_action( 'openbooking_attendance_confirmed', [ $this, 'on_attendance_confirmed' ], 10, 2 );
    }

    /**
     * Booking confirmed: notify customer + admin (if admin phone configured).
     */
    public function on_booking_confirmed( int $booking_id, array $data ): void {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking || $booking->confirmed_wa_sent ) {
            return;
        }
        $booking->confirmed_wa_sent = 1;
        $this->booking_repo->update( $booking );

        if ( ! apply_filters( 'openbooking_send_whatsapp_booking_confirmed', true, $booking_id ) ) {
            return;
        }

        if ( $this->customer_opted_in( $booking->customer_id ) ) {
            $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', 'booking_confirmed' );
        }

        if ( get_option( Setting_Keys::WHATSAPP_NOTIFY_ADMIN ) && get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE ) ) {
            $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', 'new_booking_admin', (string) get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE ) );
        }
    }

    /**
     * Booking cancelled: notify customer.
     */
    public function on_booking_cancelled( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_whatsapp_booking_cancelled', true, $booking_id ) ) {
            return;
        }

        if ( ! $this->customer_opted_in_for_booking( $booking_id ) ) {
            return;
        }

        $event = ( $data['status'] ?? '' ) === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN
            ? 'booking_cancelled_by_admin'
            : 'booking_cancelled';
        $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', $event, '', [
            '{cancel_reason_block}' => ! empty( $data['notes_internal'] ) ? (string) $data['notes_internal'] : '',
        ] );
    }

    /**
     * Booking rescheduled: notify customer.
     */
    public function on_booking_rescheduled( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_whatsapp_booking_rescheduled', true, $booking_id ) ) {
            return;
        }

        if ( ! $this->customer_opted_in_for_booking( $booking_id ) ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', 'booking_rescheduled', '', [
            '{old_start_at}' => $data['old_start_at'] ?? '',
        ] );
    }

    /**
     * Booking expired: notify customer that payment window closed.
     */
    public function on_booking_expired( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_whatsapp_booking_expired', true, $booking_id ) ) {
            return;
        }

        if ( ! $this->customer_opted_in_for_booking( $booking_id ) ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', 'booking_expired' );
    }

    public function on_payment_received( int $payment_id, array $data ): void {
        $booking_id = absint( $data['booking_id'] ?? 0 );
        if ( $booking_id <= 0 ) {
            return;
        }

        if ( ! $this->customer_opted_in_for_booking( $booking_id ) ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', 'payment_received' );
    }

    public function on_attendance_confirmed( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_whatsapp_attendance_confirmed_admin', true, $booking_id ) ) {
            return;
        }
        $admin_phone = (string) get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE, '' );
        if ( ! $admin_phone ) {
            return;
        }
        // Admin notification — not subject to customer opt-in.
        $this->notification_manager->queue_booking_message(
            $booking_id,
            'whatsapp',
            'attendance_confirmed_admin',
            $admin_phone
        );
    }

    /**
     * Returns true when the customer linked to a booking has opted in to WhatsApp.
     * Loads both booking and customer in a single call chain (2 queries max).
     */
    private function customer_opted_in_for_booking( int $booking_id ): bool {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return false;
        }
        return $this->customer_opted_in( $booking->customer_id );
    }

    private function customer_opted_in( int $customer_id ): bool {
        $customer = $this->customer_repo->find( $customer_id );
        // Missing customer record is treated as opted-out to avoid unsolicited messages.
        return $customer && (bool) $customer->whatsapp_opt_in;
    }
}
