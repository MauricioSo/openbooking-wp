<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Booking;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Booking\Repository\BookingStateLogRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de reservas.
 */

class Booking_State_Log_Repository implements BookingStateLogRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_booking_state_log';
    }

    public function table_exists(): bool {
        return $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table;
    }

    public function insert_state_change(
        Booking_Entity $booking,
        string $new_status,
        ?string $reason,
        string $actor_type,
        ?int $actor_id,
        string $to_payment_status,
        ?string $request_id
    ): void {
        if ( ! $this->table_exists() ) {
            return;
        }

        $this->wpdb->insert( $this->table, [
            'booking_id'          => $booking->id,
            'from_status'         => $booking->status,
            'to_status'           => $new_status,
            'from_payment_status' => $booking->payment_status,
            'to_payment_status'   => $to_payment_status,
            'actor_type'          => $actor_type,
            'actor_id'            => $actor_id,
            'reason'              => $reason ? sanitize_text_field( $reason ) : null,
            'request_id'          => $request_id,
        ] );
    }

    public function find_state_events_for_booking( int $booking_id ): array {
        if ( ! $this->table_exists() ) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE booking_id = %d ORDER BY created_at ASC", $booking_id ),
            ARRAY_A
        );

        return $rows ?: [];
    }
}
