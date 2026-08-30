<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

interface NotificationQueueRepositoryInterface {

    public function enqueue( array $data ): int;

    public function find( int $id ): ?array;

    public function list( array $args = [] ): array;

    public function count( array $args = [] ): int;

    public function claim_due( int $limit = 25 ): array;

    public function mark_sent( int $id ): void;

    public function mark_skipped( int $id, string $message ): void;

    public function mark_failed( int $id, int $attempts, int $max_attempts, string $message ): void;

    public function cancel( int $id ): bool;

    public function retry( int $id ): bool;

    public function cancel_for_booking( int $booking_id ): int;

    public function recover_stale_processing( int $stale_minutes = 10 ): int;

    public function count_due_by_channel(): array;

    public function count_stale_pending( int $hours = 24 ): int;

    public function count_by_status( string $status ): int;
}
