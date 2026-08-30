<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resuelve dependencias o mapeos del bounded context de reservas.
 */

class Booking_Catalog_Resolver {


    public function __construct(
        private ServiceRepositoryInterface $service_repo,
        private ?ResourceRepositoryInterface $resource_repo = null,
    ) {}

    public function resolve( int $service_id, ?int $price_check, \DateTimeImmutable $start_at_dt, ?int $resource_id = null ): array {
        $service = $this->service_repo->find( $service_id );
        if ( ! $service ) {
            return [ 'error' => true, 'message' => $this->translate( 'Servicio no encontrado.' ), 'code' => 404 ];
        }

        if ( 'active' !== $service->status ) {
            return [ 'error' => true, 'message' => $this->translate( 'Servicio no disponible.' ), 'code' => 404 ];
        }

        if ( $price_check !== null && $price_check !== $service->price_minor ) {
            return [
                'error'          => true,
                'message'        => $this->translate( 'El precio del servicio cambio. Recarga y revisa el nuevo precio.' ),
                'code'           => 409,
                'price_changed'  => true,
                'previous_price' => $price_check,
                'current_price'  => $service->price_minor,
            ];
        }

        if ( $resource_id !== null && $resource_id > 0 ) {
            $resource_check = $this->validate_resource_for_service( $service_id, $resource_id );
            if ( $resource_check['error'] ) {
                return $resource_check;
            }
        }

        $end_at = $start_at_dt->modify( '+' . $service->duration_minutes . ' minutes' )->format( 'Y-m-d H:i:s' );

        return [
            'error'   => false,
            'service' => $service,
            'end_at'  => $end_at,
        ];
    }

    private function translate( string $message ): string {
        return function_exists( '__' ) ? __( $message, 'openbooking-wp' ) : $message;
    }

    private function validate_resource_for_service( int $service_id, int $resource_id ): array {
        if ( null === $this->resource_repo ) {
            return [ 'error' => false ];
        }

        $resource = $this->resource_repo->find( $resource_id );
        if ( ! $resource || 'active' !== $resource->status ) {
            return [ 'error' => true, 'message' => $this->translate( 'Recurso no disponible.' ), 'code' => 404 ];
        }

        foreach ( $this->resource_repo->find_by_service( $service_id ) as $assigned ) {
            if ( (int) $assigned->id === $resource_id ) {
                return [ 'error' => false ];
            }
        }

        return [ 'error' => true, 'message' => $this->translate( 'El recurso no pertenece al servicio seleccionado.' ), 'code' => 400 ];
    }
}
