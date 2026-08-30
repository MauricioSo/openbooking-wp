<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Booking\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato de campos del formulario publico.
 */
interface PublicFormFieldRepositoryInterface {

    public function find_enabled_for_public_form(): array;

    public function find_all_ordered(): array;

    public function save_all( array $fields ): void;
}
