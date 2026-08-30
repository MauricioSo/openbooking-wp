<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stable PHP API for same-server callers (e.g. Dentbot running on the same WP).
 *
 * @deprecated Use the REST integration API at /wp-json/openbooking/v1/integrations/ instead.
 *             This facade will be removed in a future version.
 */
final class Booking_Facade {

    private $create_booking_use_case;
    private $public_service;
    private $availability;
    private $service_repo;

    public function __construct(
        $create_booking_use_case,
        $public_service,
        $availability,
        $service_repo
    ) {
        $this->create_booking_use_case = $create_booking_use_case;
        $this->public_service         = $public_service;
        $this->availability           = $availability;
        $this->service_repo           = $service_repo;
    }

    public function create_booking( array $params ): array {
        $params['source'] = $params['source'] ?? 'integration';
        $context  = \OpenBooking\Application\Booking\Service\Booking_Request_Context::public();
        return $this->create_booking_use_case->execute( $params, $context );
    }

    public function get_booking_by_token( string $token ): array {
        return $this->public_service->get_public_booking_by_token( $token );
    }

    public function cancel_by_token( string $cancel_token, ?string $reason = null ): array {
        return $this->public_service->cancel_by_token( $cancel_token, $reason );
    }

    public function reschedule_by_token( string $reschedule_token, string $new_start_at, ?int $resource_id = null ): array {
        return $this->public_service->reschedule_by_token( $reschedule_token, $new_start_at, $resource_id );
    }

    public function get_available_slots( int $service_id, string $date, ?int $resource_id = null ): array {
        return $this->availability->get_slots( $service_id, $date, $resource_id );
    }

    public function get_service( int $service_id ): ?array {
        $svc = $this->service_repo->find( $service_id );
        return $svc ? \OpenBooking\Support\Service_Payloads::public_from_entity( $svc ) : null;
    }

    public function list_services(): array {
        $results = $this->service_repo->find_all( [ 'status' => 'active' ] );
        return array_map( fn( $s ) => \OpenBooking\Support\Service_Payloads::public_from_entity( $s ), $results );
    }
}
