<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Availability\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de disponibilidad.
 */

interface AvailabilitySnapshotRepositoryInterface {

    public function insert_snapshot(string $scope_type, ?int $scope_id, ?string $label, array $rules_data, array $blocks, ?int $created_by): int;

    public function list_snapshots(string $scope_type, ?int $scope_id, int $limit): array;

    public function find_snapshot(int $id): ?array;

    public function delete_snapshot(int $id): bool;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;
}
