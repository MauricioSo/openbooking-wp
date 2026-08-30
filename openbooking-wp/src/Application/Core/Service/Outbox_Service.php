<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Domain\Shared\Event\DomainEvent;
use OpenBooking\Domain\Shared\Repository\OutboxEventRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reexpone el registro de eventos de dominio hacia la tabla outbox.
 */
class Outbox_Service {


    public function __construct(
        private OutboxEventRepositoryInterface $repository,
    ) {}

    public function record_domain_event( DomainEvent $event ): bool {
        return $this->repository->record_domain_event( $event );
    }
}
