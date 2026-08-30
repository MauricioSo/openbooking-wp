<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

interface NotificationCampaignRepositoryInterface {

    public function create( array $data ): int;

    public function find( int $id ): ?array;

    public function find_all( array $args = [] ): array;

    public function update_progress( int $campaign_id ): void;
}
