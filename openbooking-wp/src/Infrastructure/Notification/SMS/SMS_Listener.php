<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Notification\SMS;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Traduce eventos de dominio en mensajes SMS.
 */
class SMS_Listener {

    private \OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager;

    public function __construct(
        \OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager ) {        $this->notification_manager = $notification_manager;        if ( ! (bool) get_option( Setting_Keys::SMS_ENABLED,        false )
    ) {
return;        }        add_action( 'openbooking_booking_confirmed',   [ $this, 'on_booking_confirmed' ],   10, 2 );        add_action( 'openbooking_booking_cancelled',   [ $this, 'on_booking_cancelled' ],   10, 2 );        add_action( 'openbooking_booking_rescheduled', [ $this, 'on_booking_rescheduled' ], 10, 2 );        add_action( 'openbooking_booking_expired',     [ $this, 'on_booking_expired' ],     10, 2 );        add_action( 'openbooking_payment_received',    [ $this, 'on_payment_received' ],    10, 2 );        add_action( 'openbooking_attendance_confirmed', [ $this, 'on_attendance_confirmed' ], 10, 2 );
    }

    public function on_booking_confirmed( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_sms_booking_confirmed', true, $booking_id ) ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'sms', 'booking_confirmed', '', [], null, null, 'booking_confirmed:' . $booking_id . ':sms' );
    }

    public function on_booking_cancelled( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_sms_booking_cancelled', true, $booking_id ) ) {
            return;
        }

        $event = ( $data['status'] ?? '' ) === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN
            ? 'booking_cancelled_by_admin'
            : 'booking_cancelled';
        $this->notification_manager->queue_booking_message( $booking_id, 'sms', $event, '', [
            '{cancel_reason_block}' => ! empty( $data['notes_internal'] ) ? (string) $data['notes_internal'] : '',
        ] );
    }

    public function on_booking_rescheduled( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_sms_booking_rescheduled', true, $booking_id ) ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'sms', 'booking_rescheduled', '', [
            '{old_start_at}' => $data['old_start_at'] ?? '',
        ] );
    }

    public function on_booking_expired( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_sms_booking_expired', true, $booking_id ) ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'sms', 'booking_expired' );
    }

    public function on_payment_received( int $payment_id, array $data ): void {
        $booking_id = absint( $data['booking_id'] ?? 0 );
        if ( $booking_id <= 0 ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'sms', 'payment_received' );
    }

    public function on_attendance_confirmed( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_sms_attendance_confirmed_admin', true, $booking_id ) ) {
            return;
        }

        $admin_phone = (string) get_option( Setting_Keys::SMS_ADMIN_PHONE, '' );
        if ( ! $admin_phone ) {
            $admin_phone = (string) get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE, '' );
        }
        if ( ! $admin_phone ) {
            return;
        }

        $this->notification_manager->queue_booking_message(
            $booking_id,
            'sms',
            'attendance_confirmed_admin',
            $admin_phone
        );
    }
}
