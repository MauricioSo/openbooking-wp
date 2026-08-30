<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Payment;

use OpenBooking\Domain\Payment\Repository\PaymentRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y consulta pagos.
 */
class Payment_Repository implements PaymentRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_payments';
    }

    public function find( int $id ): ?\OpenBooking\Domain\Payment\Entity\Payment_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row ) : null;
    }

    public function find_by_booking( int $booking_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY created_at DESC", $booking_id ),
            ARRAY_A
        );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function find_by_provider_id( string $provider_payment_id ): ?\OpenBooking\Domain\Payment\Entity\Payment_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE provider_payment_id = %s", $provider_payment_id ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row ) : null;
    }

    public function find_all( array $args = [] ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['booking_id'] ) ) {
            $where[]  = 'booking_id = %d';
            $params[] = absint( $args['booking_id'] );
        }
        if ( ! empty( $args['gateway'] ) ) {
            $where[]  = 'gateway = %s';
            $params[] = sanitize_text_field( $args['gateway'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = sanitize_text_field( $args['status'] );
        }

        $where_clause = implode( ' AND ', $where );
        $limit  = ! empty( $args['limit'] ) ? absint( $args['limit'] ) : 50;
        $offset = ! empty( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function insert( \OpenBooking\Domain\Payment\Entity\Payment_Entity $entity ): int {
        $this->wpdb->insert( $this->table, [
            'booking_id'          => absint( $entity->booking_id ),
            'gateway'             => sanitize_text_field( $entity->gateway ),
            'provider_payment_id' => $entity->provider_payment_id ?: null,
            'status'              => sanitize_text_field( $entity->status ),
            'amount_minor'        => absint( $entity->amount_minor ),
            'currency'            => sanitize_text_field( $entity->currency ),
            'mode'                => sanitize_text_field( $entity->mode ),
            'raw_payload'         => $entity->raw_payload ? sanitize_textarea_field( $entity->raw_payload ) : null,
            'provider_checkout_id' => $entity->provider_checkout_id ?: null,
            'checkout_url'        => $entity->checkout_url ?: null,
            'checkout_expires_at' => $entity->checkout_expires_at ?: null,
        ] );

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Atomically transitions a payment to 'paid' only if it is not terminal.
     * Uses a single guarded UPDATE so concurrent webhook deliveries cannot both
     * commit — only the first affected row wins.
     *
     * @return bool  true if the row was updated, false if it was already paid (idempotent).
     */
    public function mark_as_paid_atomically( int $id, string $paid_at, ?string $provider_payment_id = null, ?string $raw_payload = null ): bool {
        $set    = 'status = %s, paid_at = %s';
        $params = [ \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID, $paid_at ];

        if ( $provider_payment_id !== null ) {
            $set     .= ', provider_payment_id = %s';
            $params[] = $provider_payment_id;
        }
        if ( $raw_payload !== null ) {
            $set     .= ', raw_payload = %s';
            $params[] = $raw_payload;
        }

        $params[] = $id;
        $params[] = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID;
        $params[] = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED;
        $params[] = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_DISPUTED;
        $params[] = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PARTIALLY_PAID;
        $params[] = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED;
        $params[] = \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED;

        $affected = $this->wpdb->query(
            $this->wpdb->prepare( "UPDATE {$this->table} SET {$set} WHERE id = %d AND status NOT IN (%s, %s, %s, %s, %s, %s)", $params )
        );

        return $affected > 0;
    }

    public function find_pending_for_booking_gateway_locked( int $booking_id, string $gateway ): ?\OpenBooking\Domain\Payment\Entity\Payment_Entity {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE booking_id = %d AND gateway = %s AND status = %s FOR UPDATE",
                $booking_id,
                $gateway,
                \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING
            ),
            ARRAY_A
        );
        return $row ? \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row ) : null;
    }

    public function expire_pending_for_booking( int $booking_id ): int {
        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET status = %s WHERE booking_id = %d AND status = %s",
                \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED,
                $booking_id,
                \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING
            )
        );
        return (int) $affected;
    }

    public function expire_pending_payment( int $payment_id ): bool {
        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET status = %s WHERE id = %d AND status = %s",
                \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED,
                $payment_id,
                \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING
            )
        );
        return $affected > 0;
    }

    public function find_orphaned_pending( int $limit = 200 ): array {
        $limit          = max( 1, absint( $limit ) );
        $bookings_table = $this->wpdb->prefix . 'ob_bookings';
        $sql            = $this->wpdb->prepare(
            "SELECT p.* FROM {$this->table} p
             INNER JOIN {$bookings_table} b ON p.booking_id = b.id
             WHERE p.status = %s
             AND b.status IN (%s, %s, %s)
             ORDER BY p.created_at ASC
             LIMIT %d",
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PENDING,
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_EXPIRED,
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_ADMIN,
            \OpenBooking\Domain\Booking\Entity\Booking_Entity::STATUS_CANCELLED_BY_CUSTOMER,
            $limit
        );
        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row );
        }, $rows ?: [] );
    }

    public function update( \OpenBooking\Domain\Payment\Entity\Payment_Entity $entity ): bool {
        if ( ! $entity->id ) {
            return false;
        }
        $data = [
            'status'       => sanitize_text_field( $entity->status ),
            'amount_minor' => absint( $entity->amount_minor ),
            'currency'     => sanitize_text_field( $entity->currency ),
            'mode'         => sanitize_text_field( $entity->mode ),
        ];
        if ( $entity->provider_payment_id ) {
            $data['provider_payment_id'] = $entity->provider_payment_id;
        }
        if ( $entity->paid_at ) {
            $data['paid_at'] = $entity->paid_at;
        }
        if ( $entity->raw_payload ) {
            $data['raw_payload'] = $entity->raw_payload;
        }
        if ( $entity->provider_checkout_id !== null ) {
            $data['provider_checkout_id'] = $entity->provider_checkout_id;
        }
        if ( $entity->checkout_url !== null ) {
            $data['checkout_url'] = $entity->checkout_url;
        }
        if ( $entity->checkout_expires_at !== null ) {
            $data['checkout_expires_at'] = $entity->checkout_expires_at;
        }

        $result = $this->wpdb->update( $this->table, $data, [ 'id' => $entity->id ] );
        return false !== $result;
    }

    public function mark_disputed(int $payment_id, string $reason): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'ob_payments',
            [ 'disputed_at' => current_time( 'mysql', true ), 'dispute_reason' => sanitize_text_field( $reason ) ],
            [ 'id' => $payment_id ]
        );
    }

    public function count_orphan_payments(): int {
        $bookings_table = $this->wpdb->prefix . 'ob_bookings';
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} p
             LEFT JOIN {$bookings_table} b ON b.id = p.booking_id
             WHERE b.id IS NULL"
        );
    }

    public function find_recent_reconcilable( int $limit = 200 ): array {
        $limit = max( 1, absint( $limit ) );
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status IN (%s, %s, %s, %s, %s)
             ORDER BY updated_at DESC, created_at DESC
             LIMIT %d",
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PAID,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_FAILED,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_EXPIRED,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_REFUNDED,
            \OpenBooking\Domain\Payment\Entity\Payment_Entity::STATUS_PARTIALLY_PAID,
            $limit
        );

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );
        return array_map( function ( $row ) {
            return \OpenBooking\Domain\Payment\Entity\Payment_Entity::from_array( $row );
        }, $rows ?: [] );
    }
}
