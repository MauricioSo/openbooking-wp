<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Integration\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de logs de integracion.
 */
interface IntegrationRequestLogRepositoryInterface {

    public function find_by_request_id(string $request_id): ?array;

    public function find_by_idempotency_key(string $client_key, string $idempotency_key): ?array;

    public function insert(array $data): int;

    public function update_result(int $id, int $status_code, ?string $error_code = null, ?string $entity_type = null, ?int $entity_id = null): bool;

    public function find_requests_for_booking(int $booking_id): array;

    public function has_request_for_booking(int $booking_id): bool;
}
