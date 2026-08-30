<?php


declare( strict_types=1 );
namespace OpenBooking\Presentation\Rest\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Traduce comandos HTTP en llamadas al dominio de catalogo.
 */

class Service_Controller {

    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo, // consulta servicios del catalogo
    ) {}

    public function get_services( \WP_REST_Request $request ): \WP_REST_Response {
        $services = $this->service_repo->find_active_public();
        $data = array_map( function ( $s ) {
            $arr = $s->to_array();
            $arr['formatted_price'] = $s->get_formatted_price();
            return $arr;
        }, $services );

        return new \WP_REST_Response( [ 'services' => $data ], 200 );
    }
}
