<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del repositorio de logs de notificacion.
 */
interface NotificationLogRepositoryInterface {

    public function find( int $id ): ?array;

    public function search( array $args = [] ): array;

    public function stats( int $days = 30 ): array;

    public function count_recent_failed( int $days = 7 ): int;
}
