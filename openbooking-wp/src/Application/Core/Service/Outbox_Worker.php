<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Domain\Shared\Repository\OutboxEventRepositoryInterface;
use OpenBooking\Application\Shared\Port\HookDispatcherInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Procesa eventos pendientes del outbox.
 */
class Outbox_Worker {

    public function __construct(
        private OutboxEventRepositoryInterface $repository,
        private ?HookDispatcherInterface $hooks = null,
    ) {
        $this->hooks = $hooks ?? new \OpenBooking\Infrastructure\WordPress\Adapter\WP_HookDispatcher();
    }

    public function process_due( int $limit = 25, ?callable $handler = null ): int {
        $this->repository->release_stale_processing( 15 * MINUTE_IN_SECONDS );

        $worker_id = $this->worker_id();
        $rows      = $this->repository->claim_due( $limit, $worker_id );

        $processed = 0;
        foreach ( $rows as $row ) {
            try {
                $handled = $handler ? (bool) $handler( $row ) : $this->handle_event( $row );
                if ( ! $handled ) {
                    $this->repository->mark_failed_attempt( $row, 'No outbox handler accepted the event.' );
                    continue;
                }

                $this->repository->mark_processed( (int) $row['id'] );
                $processed++;
            } catch ( \Throwable $e ) {
                $this->repository->mark_failed_attempt( $row, $e->getMessage() );
            }
        }

        return $processed;
    }

    protected function handle_event( array $row ): bool {
        return (bool) $this->hooks->apply_filters( 'openbooking_outbox_handle_event', false, $row );
    }

    private function worker_id(): string {
        return substr( 'worker-' . md5( php_uname( 'n' ) . '-' . getmypid() . '-' . microtime( true ) ), 0, 80 );
    }
}
