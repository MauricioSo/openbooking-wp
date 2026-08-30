<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Payment\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de intentos de pago.
 */
interface PaymentAttemptRepositoryInterface {

    public function insert(array $data): int;

    public function resolve(int $attempt_id, string $status, ?string $gateway_ref = null, $gateway_response = null): bool;

    public function find_by_payment(int $payment_id): array;

    public function find_by_booking(int $booking_id): array;
}
