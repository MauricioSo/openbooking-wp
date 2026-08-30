<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para limitar acciones por identificador.
 */
interface RateLimiterInterface {
    public function check(string $action, string $identifier, int $max_attempts, int $ttl): bool;
    public function purge_expired(): void;
}
