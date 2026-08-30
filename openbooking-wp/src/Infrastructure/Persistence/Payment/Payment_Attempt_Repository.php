<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Payment;

use OpenBooking\Domain\Payment\Repository\PaymentAttemptRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y consulta intentos de pago.
 */
class Payment_Attempt_Repository implements PaymentAttemptRepositoryInterface {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ob_payment_attempts';
    }

    /**
     * Insert a new payment attempt and return its ID.
     * Returns 0 if the table does not exist yet (before migration 005).
     */
    public function insert( array $data ): int {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return 0;
        }

        $wpdb->insert(
            $this->table,
            [
                'payment_id'       => (int) ( $data['payment_id']       ?? 0 ),
                'booking_id'       => (int) ( $data['booking_id']       ?? 0 ),
                'gateway'          => sanitize_text_field( $data['gateway'] ?? '' ),
                'amount_minor'     => (int) ( $data['amount_minor']     ?? 0 ),
                'currency'         => strtoupper( substr( sanitize_text_field( $data['currency'] ?? 'USD' ), 0, 3 ) ),
                'status'           => sanitize_text_field( $data['status'] ?? \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::STATUS_INITIATED ),
                'gateway_ref'      => isset( $data['gateway_ref'] ) ? sanitize_text_field( $data['gateway_ref'] ) : null,
                'gateway_response' => isset( $data['gateway_response'] ) ? wp_json_encode( $data['gateway_response'] ) : null,
                'ip_address'       => isset( $data['ip_address'] ) ? sanitize_text_field( $data['ip_address'] ) : null,
                'initiated_at'     => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Resolve an attempt (mark as succeeded/failed/refunded) and record when.
     */
    public function resolve( int $attempt_id, string $status, ?string $gateway_ref = null, $gateway_response = null ): bool {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return false;
        }

        $data   = [ 'status' => sanitize_text_field( $status ), 'resolved_at' => current_time( 'mysql', true ) ];
        $format = [ '%s', '%s' ];

        if ( $gateway_ref !== null ) {
            $data['gateway_ref'] = sanitize_text_field( $gateway_ref );
            $format[]            = '%s';
        }

        if ( $gateway_response !== null ) {
            $data['gateway_response'] = is_string( $gateway_response ) ? $gateway_response : wp_json_encode( $gateway_response );
            $format[]                 = '%s';
        }

        return false !== $wpdb->update( $this->table, $data, [ 'id' => $attempt_id ], $format, [ '%d' ] );
    }

    /**
     * Get all attempts for a payment aggregate, newest first.
     *
     * @return \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity[]
     */
    public function find_by_payment( int $payment_id ): array {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE payment_id = %d ORDER BY initiated_at DESC",
                $payment_id
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return [];
        }

        return array_map(
            fn( array $r ) => \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::from_array( $r ),
            $rows
        );
    }

    /**
     * Get all attempts for a booking (across all its payment aggregates).
     *
     * @return \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity[]
     */
    public function find_by_booking( int $booking_id ): array {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY initiated_at DESC",
                $booking_id
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return [];
        }

        return array_map(
            fn( array $r ) => \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity::from_array( $r ),
            $rows
        );
    }

    private function table_exists(): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (string) $wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" ) === $this->table;
    }
}
