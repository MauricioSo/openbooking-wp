<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Booking;

use OpenBooking\Domain\Booking\Repository\BookingTimelineRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de reservas.
 */

class Booking_Timeline_Repository implements BookingTimelineRepositoryInterface {

    private \wpdb $wpdb;
    private Booking_State_Log_Repository $state_log_repo;

    public function __construct() {
        global $wpdb;
        $this->wpdb           = $wpdb;
        $this->state_log_repo = new Booking_State_Log_Repository();
    }

    public function get_timeline_events( int $booking_id ): array {
        $events = array_merge(
            $this->state_events( $booking_id ),
            $this->audit_events( $booking_id ),
            $this->payment_events( $booking_id ),
            $this->notification_events( $booking_id )
        );

        usort( $events, fn( $a, $b ) => strcmp( $a['timestamp'] ?? '', $b['timestamp'] ?? '' ) );

        return $events;
    }

    private function state_events( int $booking_id ): array {
        return array_map( static function ( array $row ): array {
            return [
                'type'       => 'state_change',
                'from'       => $row['from_status'],
                'to'         => $row['to_status'],
                'actor_type' => $row['actor_type'],
                'actor_id'   => $row['actor_id'],
                'reason'     => $row['reason'],
                'timestamp'  => $row['created_at'],
            ];
        }, $this->state_log_repo->find_state_events_for_booking( $booking_id ) );
    }

    private function audit_events( int $booking_id ): array {
        $table = $this->wpdb->prefix . 'ob_audit_logs';
        $rows  = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT action, actor_type, actor_id, message, severity, created_at FROM `{$table}` WHERE entity_type = 'booking' AND entity_id = %d ORDER BY created_at ASC", $booking_id ),
            ARRAY_A
        );

        return array_map( static function ( array $row ): array {
            return [
                'type'       => 'audit',
                'action'     => $row['action'],
                'actor_type' => $row['actor_type'],
                'actor_id'   => $row['actor_id'],
                'message'    => $row['message'],
                'severity'   => $row['severity'],
                'timestamp'  => $row['created_at'],
            ];
        }, $rows ?: [] );
    }

    private function payment_events( int $booking_id ): array {
        $table = $this->wpdb->prefix . 'ob_payment_attempts';
        if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT gateway, amount_minor, currency, status, gateway_ref, initiated_at, resolved_at FROM `{$table}` WHERE booking_id = %d ORDER BY initiated_at ASC", $booking_id ),
            ARRAY_A
        );

        return array_map( static function ( array $row ): array {
            return [
                'type'        => 'payment_attempt',
                'gateway'     => $row['gateway'],
                'amount'      => $row['amount_minor'],
                'currency'    => $row['currency'],
                'status'      => $row['status'],
                'gateway_ref' => $row['gateway_ref'],
                'timestamp'   => $row['initiated_at'],
                'resolved_at' => $row['resolved_at'],
            ];
        }, $rows ?: [] );
    }

    private function notification_events( int $booking_id ): array {
        $table = $this->wpdb->prefix . 'ob_notification_logs';
        $rows  = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT channel, template_key, recipient, status, attempts, sent_at, error_message FROM `{$table}` WHERE booking_id = %d ORDER BY sent_at ASC", $booking_id ),
            ARRAY_A
        );

        return array_map( static function ( array $row ): array {
            return [
                'type'          => 'notification',
                'channel'       => $row['channel'],
                'template'      => $row['template_key'],
                'recipient'     => $row['recipient'],
                'status'        => $row['status'],
                'attempts'      => (int) $row['attempts'],
                'timestamp'     => $row['sent_at'],
                'error_message' => $row['error_message'],
            ];
        }, $rows ?: [] );
    }
}
