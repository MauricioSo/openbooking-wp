<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Notification\WhatsApp;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends WhatsApp messages via the Meta (Facebook) Cloud API.
 *
 * Setup:
 *  1. Create a Meta developer app at developers.facebook.com.
 *  2. Add the WhatsApp product and get a permanent access token.
 *  3. Note your Phone Number ID (not the phone number itself).
 *  4. For outbound messages to customers who haven't messaged you first,
 *     you MUST use pre-approved Message Templates (managed in Meta Business Suite).
 *     Free-form text only works within a 24-hour customer-initiated session.
 *  5. Fill in Token and Phone Number ID in OpenBooking > Ajustes > Notificaciones.
 *
 * Template-based sending:
 *  If `obwp_whatsapp_meta_use_templates` is enabled the provider reads per-event
 *  template names from options (obwp_whatsapp_meta_tpl_{template_key}) and sends
 *  the first body parameter as the full rendered message text.
 *  This covers the "utility" template category required for transactional messages.
 *
 * No external PHP libraries required — all HTTP via wp_remote_post().
 */
class Meta_WhatsApp_Provider implements WhatsApp_Provider_Interface {

    private string $access_token;
    private string $phone_number_id;
    private bool   $use_templates;

    private const API_BASE = 'https://graph.facebook.com/v19.0/';

    public function __construct() {
        $this->access_token    = \OpenBooking\Support\Crypto::decrypt( (string) get_option( Setting_Keys::WHATSAPP_META_TOKEN, '' ) );
        $this->phone_number_id = (string) get_option( Setting_Keys::WHATSAPP_META_PHONE_ID, '' );
        $this->use_templates   = (bool) get_option( Setting_Keys::WHATSAPP_META_USE_TEMPLATES, false );
    }

    public function get_name(): string {
        return 'meta';
    }

    public function is_configured(): bool {
        return '' !== $this->access_token && '' !== $this->phone_number_id;
    }

    /**
     * Send a message.
     *
     * When $context['template_key'] is provided and use_templates is enabled,
     * this sends a template message using the mapped Meta template name.
     * Otherwise it sends a free-form text message (only valid within a 24h session).
     */
    public function send( string $to, string $message, array $context = [] ): bool {
        if ( ! $this->is_configured() ) {
            error_log( '[OpenBooking] Meta WhatsApp: credenciales no configuradas.' );
            return false;
        }

        $raw_to = $to;
        $to     = $this->normalize_phone( $to );
        if ( ! $to ) {
            error_log( sprintf( '[OpenBooking] Meta WhatsApp: número de destino inválido "%s".', $raw_to ) );
            return false;
        }

        $template_key = $context['template_key'] ?? '';
        $use_tpl      = $this->use_templates && $template_key;
        $meta_tpl     = $use_tpl
            ? (string) get_option( Setting_Keys::WHATSAPP_META_TPL_PREFIX . $template_key, '' )
            : '';

        $body = $use_tpl && $meta_tpl
            ? $this->build_template_payload( $to, $meta_tpl, $message )
            : $this->build_text_payload( $to, $message );

        $url = self::API_BASE . $this->phone_number_id . '/messages';

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( sprintf(
                '[OpenBooking] Meta WhatsApp error: %s (booking_id=%s)',
                $response->get_error_message(),
                $context['booking_id'] ?? 'n/a'
            ) );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            $raw = wp_remote_retrieve_body( $response );
            error_log( sprintf(
                '[OpenBooking] Meta WhatsApp HTTP %d: %s (booking_id=%s)',
                $code,
                $raw,
                $context['booking_id'] ?? 'n/a'
            ) );
            return false;
        }

        return true;
    }

    /**
     * Build free-form text payload.
     * Only works if the recipient has sent a message to the business in the last 24 h.
     */
    private function build_text_payload( string $to, string $message ): array {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $message,
            ],
        ];
    }

    /**
     * Build template-based payload.
     *
     * The rendered $message is sent as the first (and only) body component parameter.
     * This covers the common "utility" template pattern where the full text is a single
     * variable: {{1}}. If your template uses multiple variables, implement a custom
     * filter `openbooking_whatsapp_meta_template_components`.
     *
     * Template language defaults to 'es' (Spanish). Override via
     * `obwp_whatsapp_meta_language` option.
     */
    private function build_template_payload( string $to, string $template_name, string $message ): array {
        $language = (string) get_option( Setting_Keys::WHATSAPP_META_LANGUAGE, 'es' );

        $components = apply_filters( 'openbooking_whatsapp_meta_template_components', [
            [
                'type'       => 'body',
                'parameters' => [
                    [ 'type' => 'text', 'text' => $message ],
                ],
            ],
        ], $template_name, $message );

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $template_name,
                'language'   => [ 'code' => $language ],
                'components' => $components,
            ],
        ];
    }

    /**
     * Normalize to E.164. Meta requires the number without the leading +.
     */
    private function normalize_phone( string $phone ): string {
        $phone = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );

        // Strip leading + (str_starts_with requires PHP 8.0; use strpos for 7.4 compat).
        if ( 0 === strpos( $phone, '+' ) ) {
            $phone = substr( $phone, 1 );
        }

        if ( preg_match( '/^\d{7,15}$/', $phone ) ) {
            return $phone;
        }

        return '';
    }
}
