<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define el contrato para el bounded context de notificaciones.
 */

interface SMSServiceInterface {
    public function send( string $template_key, int $booking_id, array $extra_data = [], array $context = [] ): bool;
    public function preview( string $template_key, int $booking_id, array $extra_data = [], ?string $template_override = null, string $recipient = '' ): ?array;
    public function send_raw( string $to, string $message ): bool;
    public function get_template( string $key ): ?string;
    public function save_template( string $key, string $body ): void;
    public function get_all_templates(): array;
    public function is_enabled(): bool;
    public function resolve_provider(): ?\OpenBooking\Infrastructure\Notification\SMS\SMS_Provider_Interface;
}
