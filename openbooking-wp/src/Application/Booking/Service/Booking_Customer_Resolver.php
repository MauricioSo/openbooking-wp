<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Customer\Entity\Customer_Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resuelve dependencias o mapeos del bounded context de reservas.
 */

class Booking_Customer_Resolver {


    public function __construct(
        private CustomerRepositoryInterface $customer_repo,
    ) {}

    public function resolve(
        string $email,
        string $first_name,
        string $last_name = '',
        string $phone = '',
        ?bool $whatsapp_opt_in = null
    ): Customer_Entity {
        return $this->customer_repo->find_or_create_by_email(
            $email,
            $first_name,
            $last_name,
            $phone ?: null,
            $whatsapp_opt_in
        );
    }
}
