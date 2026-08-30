<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de nucleo.
 */

interface FeatureFlagRepositoryInterface {
    public function get_value(string $key): ?string;
    public function set(string $key, string $value, int $updated_by): void;
    public function find_all(): array;
}
