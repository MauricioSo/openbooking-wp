<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato del orquestador de notificaciones.
 */
interface NotificationManagerInterface {
    public function queue_event( string $event, int $booking_id, array $extra = [] ): int;
    public function queue_booking_message( int $booking_id, string $channel, string $template_key, string $recipient = '', array $payload = [], ?int $campaign_id = null, ?string $scheduled_at = null, ?string $dedupe_key = null ): int;
    public function process_queue( int $limit = 25 ): int;
    public function create_campaign( array $data ): int;
    public function get_skip_reason( array $row, array $payload = [] ): ?string;
    public function record_consent( int $customer_id, string $channel, string $purpose, string $action, string $source = '' ): int;
}
