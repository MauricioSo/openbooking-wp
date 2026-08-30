<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Customer\Repository;

use OpenBooking\Domain\Customer\Entity\Customer_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de clientes.
 */
interface CustomerRepositoryInterface {

    public function find( int $id ): ?Customer_Entity;

    public function find_by_email( string $email ): ?Customer_Entity;

    /** @return Customer_Entity[] */
    public function find_all( array $args = [] ): array;

    /** @return Customer_Entity[] */
    public function find_by_ids( array $ids ): array;

    public function insert( Customer_Entity $entity ): int;

    public function update( Customer_Entity $entity ): bool;

    /**
     * Find existing customer by email, or create a new one.
     * Idempotent: INSERT IGNORE handles concurrent inserts.
     */
    public function find_or_create_by_email(
        string $email,
        string $first_name = '',
        string $last_name = '',
        ?string $phone = null,
        ?bool $whatsapp_opt_in = null
    ): Customer_Entity;
}
