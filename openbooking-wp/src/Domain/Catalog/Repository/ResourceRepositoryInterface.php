<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Catalog\Repository;

use OpenBooking\Domain\Catalog\Entity\Resource_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de catalogo.
 */

interface ResourceRepositoryInterface {

    public function find( int $id ): ?Resource_Entity;

    /** @return Resource_Entity[] */
    public function find_all( array $args = [] ): array;

    /**
     * Returns the number of resources matching the given status.
     *
     * Uses a lightweight COUNT(*) query instead of loading full entities.
     * When wrapped in a transaction with SELECT ... FOR UPDATE on the same
     * table, this provides an atomic check to prevent exceeding Free limits
     * under concurrent requests.
     */
    public function count_by_status( string $status ): int;

    /** @return Resource_Entity[] */
    public function find_by_service( int $service_id ): array;

    public function insert( Resource_Entity $entity ): int;

    public function update( Resource_Entity $entity ): bool;

    public function delete( int $id ): bool;

    public function attach_to_service( int $service_id, int $resource_id ): void;

    public function detach_from_service( int $service_id, int $resource_id ): void;
}
