<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Customer\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Customer_Crud_Service {


    public function __construct(
        private \OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface $customer_repo,
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo,
        private \OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger,
    ) {}

    public function update( int $id, array $body ): array {
        $customer = $this->customer_repo->find( $id );
        if ( ! $customer ) {
            return [ 'error' => 'Cliente no encontrado.' ];
        }

        $before = $customer->to_array();
        $fields = [ 'first_name', 'last_name', 'phone', 'notes' ];

        foreach ( $fields as $field ) {
            if ( array_key_exists( $field, $body ) ) {
                $customer->$field = $field === 'notes'
                    ? sanitize_textarea_field( $body[ $field ] )
                    : sanitize_text_field( $body[ $field ] );
            }
        }

        $this->customer_repo->update( $customer );
        $after = $customer->to_array();

        $this->audit_logger->log_entity_change(
            'customer',
            $customer->id,
            'customer_updated',
            $before,
            $after,
            [],
            [ 'message' => 'Customer updated from admin.' ]
        );

        return [ 'success' => true, 'customer' => $customer->to_array() ];
    }

    public function anonymize( int $id ): array {
        $customer = $this->customer_repo->find( $id );
        if ( ! $customer ) {
            return [ 'error' => 'Cliente no encontrado.', 'code' => 404 ];
        }

        $before = $customer->to_array();
        $customer->first_name      = 'Cliente anonimizado';
        $customer->last_name       = '';
        $customer->email           = sprintf( 'obwp-anonymized-%d@example.invalid', $customer->id );
        $customer->phone           = '';
        $customer->notes           = 'Datos personales anonimizados por solicitud de privacidad.';
        $customer->whatsapp_opt_in = 0;

        $this->customer_repo->update( $customer );

        $this->audit_logger->log_entity_change(
            'customer',
            $customer->id ?? $id,
            'customer_anonymized',
            $before,
            $customer->to_array(),
            [],
            [ 'message' => 'Customer anonymized from admin.' ]
        );

        return [ 'success' => true, 'customer' => $customer->to_array() ];
    }

    public function enrich_booking( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        $data = $booking->to_array();
        $service  = $this->service_repo->find( $booking->service_id );
        $customer = $this->customer_repo->find( $booking->customer_id );
        $data['service_name']   = $service ? $service->name : '';
        $data['customer_name']  = $customer ? $customer->get_full_name() : '';
        $data['customer_email'] = $customer ? $customer->email : '';
        return $data;
    }
}
