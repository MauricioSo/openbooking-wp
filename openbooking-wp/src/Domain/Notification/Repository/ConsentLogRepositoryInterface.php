<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de notificaciones.
 */

interface ConsentLogRepositoryInterface {

    public function log( int $customer_id, string $channel, string $purpose, string $action, string $source = '', ?string $source_text = null, ?string $ip_hash = null, ?string $user_agent = null ): int;

    public function has_consent( int $customer_id, string $channel, string $purpose = 'marketing' ): bool;

    public function get_history( int $customer_id, int $limit = 50 ): array;

    public function find_by_customer( int $customer_id, int $limit = 100 ): array;

    public function record_opt_in( int $customer_id, string $channel, string $purpose, string $source = '', ?string $source_text = null ): int;

    public function record_opt_out( int $customer_id, string $channel, string $purpose, string $source = '' ): int;
}
