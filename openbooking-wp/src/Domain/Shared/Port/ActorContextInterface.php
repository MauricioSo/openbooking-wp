<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para el contexto del actor.
 */
interface ActorContextInterface {
    public function is_user_logged_in(): bool;
    public function current_user_can( string $capability ): bool;
    public function get_current_user_id(): int;
}
