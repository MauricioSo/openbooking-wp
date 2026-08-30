<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Notification\SMS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define el contrato para el bounded context de notificaciones.
 */

interface SMS_Provider_Interface {

    public function get_name(): string;

    public function is_configured(): bool;

    public function send( string $to, string $message, array $context = [] ): bool;
}
