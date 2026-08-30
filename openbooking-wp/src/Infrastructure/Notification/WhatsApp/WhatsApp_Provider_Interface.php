<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Notification\WhatsApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract for WhatsApp message providers.
 *
 * Each provider handles authentication and the HTTP call to its own API.
 * WhatsApp_Service is responsible for template rendering; the provider
 * receives the final, ready-to-send message body.
 */
interface WhatsApp_Provider_Interface {

    /**
     * Send a WhatsApp message.
     *
     * @param string $to      Recipient phone in E.164 format (e.g. +56912345678).
     * @param string $message Plain-text message body.
     * @param array  $context Optional metadata (booking_id, template_key, etc.).
     *
     * @return bool True on success, false on failure.
     */
    public function send( string $to, string $message, array $context = [] ): bool;

    /**
     * Human-readable provider identifier (e.g. 'twilio', 'meta').
     */
    public function get_name(): string;

    /**
     * Whether the provider is fully configured and ready to send.
     * Used to surface warnings in the admin UI.
     */
    public function is_configured(): bool;
}
