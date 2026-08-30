<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Repository;

use OpenBooking\Domain\Shared\Event\DomainEvent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de nucleo.
 */

interface OutboxEventRepositoryInterface {

    public function record_domain_event(DomainEvent $event): bool;

    public function table_name(): string;

    public function claim_due(int $limit, string $worker_id): array;

    public function mark_processed(int $id): bool;

    public function mark_failed_attempt(array $row, string $error_message): bool;

    public function counts_by_status(): array;

    public function oldest_pending_created_at(): ?string;

    public function delete_processed_older_than(string $cutoff): int;

    public function list_recent(string $status = '', int $limit = 50, int $offset = 0): array;

    public function retry_failed(int $id): bool;

    public function release_stale_processing(int $stale_seconds = 900): int;

    public function ignore(int $id): bool;

    public function table_exists(): bool;
}
