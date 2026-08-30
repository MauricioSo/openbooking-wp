<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Availability\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity;

/**
 * Persiste y recupera entidades del bounded context de disponibilidad.
 */

interface AvailabilityConfigRepositoryInterface {

    public function find_rule(int $id): ?AvailabilityRule_Entity;

    public function get_rules(string $scope_type = 'global', ?int $scope_id = null, ?string $rule_type = null): array;

    public function get_applicable_rules(int $service_id, ?int $resource_id = null): array;

    public function insert_rule(AvailabilityRule_Entity $entity): int;

    public function update_rule(AvailabilityRule_Entity $entity): bool;

    public function delete_rule(int $id): bool;

    public function delete_rules_by_scope(string $scope_type, int $scope_id): void;

    public function delete_blocks_by_scope(string $scope_type, int $scope_id): void;

    public function get_blocks(string $scope_type = 'global', ?int $scope_id = null, ?string $date_from = null, ?string $date_to = null): array;

    public function get_applicable_blocks(int $service_id, ?int $resource_id, string $date_from, string $date_to): array;

    public function insert_block(array $data): int;

    public function delete_block(int $id): bool;

    public function rules_table_exists(): bool;

    public function count_invalid_time_range_rules(): int;
}
