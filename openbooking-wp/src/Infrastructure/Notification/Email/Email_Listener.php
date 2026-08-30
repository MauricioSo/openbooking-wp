<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Notification\Email;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connects domain action hooks to Email_Service.
 * This is the missing link between Booking_Service/Payment_Service and outgoing emails.
 */
class Email_Listener {


    public function __construct(
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Infrastructure\Notification\Notification_Manager $notification_manager, // gestor central de notificaciones
    ) {
add_action( 'openbooking_booking_confirmed',   [ $this, 'on_booking_confirmed' ],   10, 2 );        add_action( 'openbooking_booking_cancelled',   [ $this, 'on_booking_cancelled' ],   10, 2 );        add_action( 'openbooking_booking_rescheduled', [ $this, 'on_booking_rescheduled' ], 10, 2 );        add_action( 'openbooking_booking_created',     [ $this, 'on_booking_created' ],     10, 2 );        add_action( 'openbooking_booking_expired',     [ $this, 'on_booking_expired' ],     10, 2 );        add_action( 'openbooking_payment_received',    [ $this, 'on_payment_received' ],    10, 2 );        add_action( 'openbooking_attendance_confirmed', [ $this, 'on_attendance_confirmed' ], 10, 2 );
    }

    /**
     * Fires when a booking is confirmed (free service, admin action, or payment received).
     */
    public function on_booking_confirmed( int $booking_id, array $data ): void {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking || $booking->confirmed_email_sent ) {
            return;
        }
        $booking->confirmed_email_sent = 1;
        $this->booking_repo->update( $booking );

        if ( ! apply_filters( 'openbooking_send_email_booking_confirmed', true, $booking_id ) ) {
            return;
        }
        $this->notification_manager->queue_booking_message( $booking_id, 'email', 'booking_confirmed' );
        $this->notification_manager->queue_booking_message( $booking_id, 'email', 'new_booking_admin', get_bloginfo( 'admin_email' ) );
    }

    /**
     * Fires when a booking is cancelled by either party.
     */
    public function on_booking_cancelled( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_email_booking_cancelled', true, $booking_id ) ) {
            return;
        }
        $event = ( $data['status'] ?? '' ) === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN
            ? 'booking_cancelled_by_admin'
            : 'booking_cancelled';
        $this->notification_manager->queue_booking_message( $booking_id, 'email', $event, '', [
            '{cancel_reason_block}' => ! empty( $data['notes_internal'] ) ? (string) $data['notes_internal'] : '',
        ] );
    }

    /**
     * Fires when a booking is rescheduled.
     */
    public function on_booking_rescheduled( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_email_booking_rescheduled', true, $booking_id ) ) {
            return;
        }
        $this->notification_manager->queue_booking_message( $booking_id, 'email', 'booking_rescheduled', '', [
            '{old_start_at}' => $data['old_start_at'] ?? '',
        ] );
    }

    /**
     * Fires when a pending booking expires due to unpaid payment.
     */
    public function on_booking_expired( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_email_booking_expired', true, $booking_id ) ) {
            return;
        }
        $this->notification_manager->queue_booking_message( $booking_id, 'email', 'booking_expired' );
    }

    /**
     * Fires when a booking is created in pending state (paid service, waiting for payment).
     * Only notify admin so they are aware, but do NOT send customer confirmation yet.
     */
    public function on_booking_created( int $booking_id, array $data ): void {
        $status = $data['status'] ?? '';

        // If already confirmed (free service), booking_confirmed will handle it.
        if ( $status === \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CONFIRMED ) {
            return;
        }

        // Pending booking (requires payment): notify admin only.
        if ( ! apply_filters( 'openbooking_send_email_pending_admin', true, $booking_id ) ) {
            return;
        }
        $this->notification_manager->queue_booking_message( $booking_id, 'email', 'new_booking_admin', get_bloginfo( 'admin_email' ) );
    }

    public function on_payment_received( int $payment_id, array $data ): void {
        $booking_id = absint( $data['booking_id'] ?? 0 );
        if ( $booking_id <= 0 ) {
            return;
        }

        $this->notification_manager->queue_booking_message( $booking_id, 'email', 'payment_received' );
    }

    public function on_attendance_confirmed( int $booking_id, array $data ): void {
        if ( ! apply_filters( 'openbooking_send_email_attendance_confirmed_admin', true, $booking_id ) ) {
            return;
        }
        $this->notification_manager->queue_booking_message(
            $booking_id,
            'email',
            'attendance_confirmed_admin',
            get_bloginfo( 'admin_email' )
        );
    }
}
