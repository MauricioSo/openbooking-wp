<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Audit\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de auditoria.
 */

interface AuditRepositoryInterface {

    public function insert( array $data ): int;

    public function find( int $id ): ?array;

    public function find_all( array $args = [] ): array;

    public function count_all( array $args = [] ): int;

    public function delete_older_than( string $cutoff ): int;

    public function count_by_action_since( string $action, string $cutoff ): int;
}
