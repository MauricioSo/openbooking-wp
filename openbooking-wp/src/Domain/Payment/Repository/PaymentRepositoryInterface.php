<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Payment\Repository;

use OpenBooking\Domain\Payment\Entity\Payment_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de pagos.
 */
interface PaymentRepositoryInterface {

    public function find( int $id ): ?Payment_Entity;

    /** @return Payment_Entity[] */
    public function find_by_booking( int $booking_id ): array;

    public function find_by_provider_id( string $provider_payment_id ): ?Payment_Entity;

    /** @return Payment_Entity[] */
    public function find_all( array $args = [] ): array;

    public function insert( Payment_Entity $entity ): int;

    public function update( Payment_Entity $entity ): bool;

    /**
     * Atomically mark a payment as paid.
     * Returns the number of rows affected (1 = success, 0 = already paid).
     */
    public function mark_as_paid_atomically( int $id, string $paid_at, ?string $provider_payment_id = null, ?string $raw_payload = null ): bool;

    public function find_pending_for_booking_gateway_locked( int $booking_id, string $gateway ): ?Payment_Entity;

    public function expire_pending_for_booking( int $booking_id ): int;

    public function expire_pending_payment( int $payment_id ): bool;

    /** @return Payment_Entity[] */
    public function find_orphaned_pending( int $limit = 200 ): array;

    public function mark_disputed(int $payment_id, string $reason): bool;

    public function count_orphan_payments(): int;
}
