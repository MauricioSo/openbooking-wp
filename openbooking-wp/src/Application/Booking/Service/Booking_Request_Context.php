<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Booking\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encapsula el contexto de ejecucion de reservas.
 */

final class Booking_Request_Context {

    public const SOURCE_PUBLIC      = 'public';
    public const SOURCE_ADMIN       = 'admin';
    public const SOURCE_INTEGRATION = 'integration';


    public function __construct(
        private string $source,
        private int $actor_id = 0,
        private ?string $integration_client_key = null,
        private ?string $integration_request_id = null,
        private ?string $external_id = null,
    ) {}

    public static function public(): self {
        return new self( self::SOURCE_PUBLIC );
    }

    public static function admin( int $actor_id = 0 ): self {
        return new self( self::SOURCE_ADMIN, $actor_id );
    }

    public static function integration(
        string $client_key,
        string $request_id,
        ?string $external_id = null
    ): self {
        return new self( self::SOURCE_INTEGRATION, 0, $client_key, $request_id, $external_id );
    }

    public function source(): string {
        return $this->source;
    }

    public function actor_id(): int {
        return $this->actor_id;
    }

    public function integration_client_key(): ?string {
        return $this->integration_client_key;
    }

    public function integration_request_id(): ?string {
        return $this->integration_request_id;
    }

    public function external_id(): ?string {
        return $this->external_id;
    }

    public function is_public(): bool {
        return $this->source === self::SOURCE_PUBLIC;
    }

    public function is_admin(): bool {
        return $this->source === self::SOURCE_ADMIN;
    }

    public function is_integration(): bool {
        return $this->source === self::SOURCE_INTEGRATION;
    }
}
