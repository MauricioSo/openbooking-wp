<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Catalog\Repository;

use OpenBooking\Domain\Catalog\Entity\Service_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de catalogo.
 */

interface ServiceRepositoryInterface {

    public function find( int $id ): ?Service_Entity;

    /** @return Service_Entity[] */
    public function find_all( array $args = [] ): array;

    public function find_by_slug( string $slug ): ?Service_Entity;

    public function insert( Service_Entity $entity ): int;

    public function update( Service_Entity $entity ): bool;

    public function delete( int $id ): bool;
}
