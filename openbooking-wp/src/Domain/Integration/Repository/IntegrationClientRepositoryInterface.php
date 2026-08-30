<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Integration\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de clientes de integracion.
 */
interface IntegrationClientRepositoryInterface {

    public function find_by_client_key(string $client_key): ?array;

    public function find_by_id(int $id): ?array;

    public function create(string $client_key, string $name, string $secret_hash, array $scopes = [], array $allowed_ips = [], int $rate_limit_per_minute = 60, int $rate_limit_per_hour = 1000): int;

    public function update_last_used(int $id): void;

    public function deactivate(int $id): bool;

    public function get_scopes(array $client): array;

    public function get_allowed_ips(array $client): array;
}
