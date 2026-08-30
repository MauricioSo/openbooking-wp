<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Notification\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define el contrato para el bounded context de notificaciones.
 */

interface EmailServiceInterface {
    public function send( string $template_key, int $booking_id, array $extra_data = [], array $context = [] ): bool;
    public function preview( string $template_key, int $booking_id, array $extra_data = [], ?array $template_override = null ): ?array;
    public function get_template( string $key ): ?array;
    public function save_template( string $key, string $subject, string $body ): void;
    public function get_all_templates(): array;
}
