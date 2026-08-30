<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Domain\Shared\Port\ActorContextInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lee y guarda feature flags con una cache local por request.
 */
class Feature_Flag_Service {

    private array $cache = [];

    public function __construct(
        private \OpenBooking\Domain\Shared\Repository\FeatureFlagRepositoryInterface $repository,
        private ActorContextInterface $actor_context,
    ) {
$this->actor_context = $actor_context ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_ActorContext();
    }

    public function is_enabled( string $key ): bool {
        return filter_var( $this->get_raw( $key ), FILTER_VALIDATE_BOOLEAN );
    }

    public function get_raw( string $key ): string {
        if ( $this->has_cached_value( $key ) ) {
            return $this->cache[ $key ];
        }

        $this->cache[ $key ] = $this->repository->get_value( $key ) ?: 'false';

        return $this->cache[ $key ];
    }

    public function set( string $key, string $value, int $user_id = 0 ): bool {
        $this->repository->set( $key, $value, $user_id ?: $this->actor_context->get_current_user_id() );

        unset( $this->cache[ $key ] );

        return true;
    }

    public function get_all(): array {
        return $this->repository->find_all();
    }

    public function is_safe_mode(): bool {
        return $this->is_enabled( 'safe_mode' );
    }

    public function is_maintenance_mode(): bool {
        return $this->is_enabled( 'maintenance_mode' );
    }

    public function is_readonly_booking(): bool {
        return $this->is_enabled( 'readonly_booking_page' );
    }

    private function has_cached_value( string $key ): bool {
        return isset( $this->cache[ $key ] );
    }
}
