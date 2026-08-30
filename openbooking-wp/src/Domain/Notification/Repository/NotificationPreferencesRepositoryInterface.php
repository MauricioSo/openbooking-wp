<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

interface NotificationPreferencesRepositoryInterface {

    public function find_by_customer_id( int $customer_id ): ?array;

    public function find_by_token( string $token ): ?array;

    public function get_or_create( int $customer_id ): array;

    public function upsert( int $customer_id, array $data ): array;
}
