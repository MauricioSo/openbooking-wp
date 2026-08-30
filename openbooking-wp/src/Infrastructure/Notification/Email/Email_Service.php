<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Notification\Email;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renderiza y envia correos transaccionales desde plantillas del plugin.
 */
class Email_Service implements \OpenBooking\Domain\Notification\Service\EmailServiceInterface {

    private const DEFAULT_TEMPLATES = [
        'booking_confirmed' => [
            'subject' => 'Tu reserva esta confirmada',
            'body'    => "Hola {customer_name},\n\nTu reserva ha sido confirmada.\n\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}\n\nSi necesitas cancelar: {cancel_link}\n\nSi necesitas reprogramar: {reschedule_link}",
        ],
        'booking_cancelled' => [
            'subject' => 'Tu reserva ha sido cancelada',
            'body'    => "Hola {customer_name},\n\nTu reserva ha sido cancelada:\n\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}",
        ],
        'booking_cancelled_by_admin' => [
            'subject' => 'Lamentamos informarte: tu reserva ha sido cancelada',
            'body'    => "Hola {customer_name},\n\nQueremos avisarte que tu reserva de {service_name} del {booking_date} a las {booking_time} ha sido cancelada por el equipo de {business_name}.\n\n{cancel_reason_block}\n\n{refund_block}\n\nSi quieres reservar en otra fecha, puedes hacerlo aqui:\n{reschedule_offer_link}\n\nPedimos disculpas por los inconvenientes. Quedamos a tu disposicion.\n\n{business_name}\n{business_contact}",
        ],
        'booking_rescheduled' => [
            'subject' => 'Tu reserva ha sido reprogramada',
            'body'    => "Hola {customer_name},\n\nTu reserva ha sido reprogramada:\n\nServicio: {service_name}\nNueva fecha: {booking_date}\nHora: {booking_time}\n\nCancelar: {cancel_link}\nReprogramar: {reschedule_link}",
        ],
        'payment_received' => [
            'subject' => 'Pago recibido',
            'body'    => "Hola {customer_name},\n\nHemos recibido tu pago de {payment_amount} por:\n\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}",
        ],
        'payment_failed' => [
            'subject' => 'Problema con tu pago',
            'body'    => "Hola {customer_name},\n\nNo pudimos procesar tu pago para:\n\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}\n\nPor favor intenta de nuevo o usa otro medio de pago.",
        ],
        'reminder_customer' => [
            'subject' => 'Recordatorio: tu reserva es manana',
            'body'    => "Hola {customer_name},\n\nTe recordamos tu reserva:\n\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}\n\n¿Confirmas que vas a asistir?\nSi — confirma aquí: {confirm_attendance_link}\n\nSi no puedes:\nCancelar: {cancel_link}\nReprogramar: {reschedule_link}",
        ],
        'new_booking_admin' => [
            'subject' => 'Nueva reserva',
            'body'    => "Nueva reserva recibida:\n\nCliente: {customer_name}\nEmail: {customer_email}\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}",
        ],
        'booking_expired' => [
            'subject' => 'Tu reserva ha expirado',
            'body'    => "Hola {customer_name},\n\nEl horario que habiamos apartado para {service_name} del {booking_date} ya no esta disponible porque el tiempo de pago vencio.\n\nNo te preocupes, puedes volver a reservar cuando quieras:\n{booking_link}\n\nEsperamos verte pronto.\n{business_name}",
        ],
        'first_booking' => [
            'subject' => 'Bienvenido/a, {customer_name}',
            'body'    => "Esta es tu primera reserva con nosotros.\n\nTe damos la bienvenida y quedamos atentos para que tu experiencia con {service_name} sea excelente.\n\nFecha: {booking_date}\nHora: {booking_time}",
        ],
        'broadcast' => [
            'subject' => '{broadcast_subject}',
            'body'    => '{broadcast_body}',
        ],
        'attendance_confirmed_admin' => [
            'subject' => 'Confirmación de asistencia: {customer_name}',
            'body'    => "{customer_name} confirmó que asistirá a su cita.\n\nServicio: {service_name}\nFecha: {booking_date}\nHora: {booking_time}\nTeléfono: {customer_phone}",
        ],
    ];


    public function __construct(
        private \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository $booking_repo, // persiste reservas en WP
        private \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository $service_repo, // persiste servicios del catalogo
        private \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository $customer_repo, // persiste clientes en WP
        private \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository $preferences_repo, // persiste preferencias de canal
    ) {}

    public function send( string $template_key, int $booking_id, array $extra_data = [], array $context = [] ): bool {
        $rendered = $this->preview( $template_key, $booking_id, $extra_data, $context['template_override'] ?? null );
        if ( ! $rendered ) {
            return false;
        }

        $sent = wp_mail( $rendered['recipient'], $rendered['subject'], $rendered['final_body'], $rendered['headers'] );
        $this->log_notification(
            $booking_id,
            'email',
            $rendered['template_key'],
            $rendered['recipient'],
            $sent ? 'sent' : 'failed',
            [
                'queue_id'       => $context['queue_id'] ?? null,
                'campaign_id'    => $context['campaign_id'] ?? null,
                'attempts'       => $context['attempts'] ?? 1,
                'payload'        => [ 'subject' => $rendered['subject'], 'body' => $rendered['body'] ],
                'error_message'  => $sent ? null : 'wp_mail returned false',
            ]
        );

        return $sent;
    }

    public function preview( string $template_key, int $booking_id, array $extra_data = [], ?array $template_override = null ): ?array {
        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return null;
        }

        $service  = $this->service_repo->find( $booking->service_id );
        $customer = $this->customer_repo->find( $booking->customer_id );
        if ( ! $customer ) {
            return null;
        }

        $template_key = $this->resolve_template_key( $template_key, $booking->customer_id );
        $template = $template_override ?: $this->get_template( $template_key );
        if ( ! $template ) {
            return null;
        }

        $tags    = $this->build_merge_tags( $booking, $service, $customer, $extra_data );
        $subject = $this->replace_tags( $template['subject'], $tags );
        $body    = $this->replace_tags( $template['body'], $tags );

        $to = $customer->email;
        if ( $template_key === 'new_booking_admin' ) {
            $to = get_bloginfo( 'admin_email' );
        }

        $html_enabled = (bool) get_option( Setting_Keys::EMAIL_HTML_ENABLED, false );
        $content_type = $html_enabled ? 'text/html' : 'text/plain';
        $final_body   = $html_enabled ? $this->wrap_html( $subject, $body ) : $body;

        $headers = [
            'From: ' . $this->sanitize_header_field( get_option( Setting_Keys::EMAIL_SENDER_NAME, get_bloginfo( 'name' ) ) ) . ' <' . $this->sanitize_header_field( get_option( Setting_Keys::EMAIL_SENDER_ADDRESS, get_bloginfo( 'admin_email' ) ) ) . '>',
            'Content-Type: ' . $content_type . '; charset=UTF-8',
        ];

        return [
            'template_key' => $template_key,
            'recipient'    => $to,
            'subject'      => $subject,
            'body'         => $body,
            'final_body'   => $final_body,
            'headers'      => $headers,
        ];
    }

    /**
     * Wrap plain-text body in a minimal responsive HTML email shell.
     * Lines are converted to <p> tags; existing HTML in the body is preserved.
     * The business name and a configurable accent color are used for branding.
     */
    private function wrap_html( string $subject, string $body ): string {
        $business = esc_html( get_option( Setting_Keys::BUSINESS_NAME, get_bloginfo( 'name' ) ) );
        $color    = esc_attr( get_option( Setting_Keys::EMAIL_ACCENT_COLOR, '#2563eb' ) );
        $safe_sub = esc_html( $subject );

        $html_body = ( false === strpos( $body, '<' ) )
            ? '<p>' . implode( '</p><p>', array_map( 'esc_html', array_filter( explode( "\n", $body ) ) ) ) . '</p>'
            : $body;

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$safe_sub}</title>
<style>
body{margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:600px;margin:32px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.header{background:{$color};padding:24px 32px}
.header h1{margin:0;color:#ffffff;font-size:20px;font-weight:600}
.body{padding:32px;color:#374151;font-size:15px;line-height:1.7}
.body p{margin:0 0 12px}
.body a{color:{$color};text-decoration:none}
.footer{padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center}
</style>
</head>
<body>
<div class="wrap">
  <div class="header"><h1>{$business}</h1></div>
  <div class="body">{$html_body}</div>
  <div class="footer">&copy; {$business}</div>
</div>
</body>
</html>
HTML;
    }

    public function get_template( string $key ): ?array {
        $saved = get_option( Setting_Keys::EMAIL_TEMPLATE_PREFIX . $key, null );
        if ( $saved && is_array( $saved )
            && ! empty( trim( $saved['subject'] ?? '' ) )
            && ! empty( trim( $saved['body'] ?? '' ) ) ) {
            return $saved;
        }
        return self::DEFAULT_TEMPLATES[ $key ] ?? null;
    }

    public function save_template( string $key, string $subject, string $body ): void {
        update_option( Setting_Keys::EMAIL_TEMPLATE_PREFIX . $key, [
            'subject' => $subject,
            'body'    => $body,
        ] );
    }

    public function get_all_templates(): array {
        $templates = [];
        foreach ( self::DEFAULT_TEMPLATES as $key => $default ) {
            $templates[ $key ] = $this->get_template( $key );
        }
        return $templates;
    }

    private function build_merge_tags(
        \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking,
        ?\OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        \OpenBooking\Domain\Customer\Entity\Customer_Entity $customer,
        array $extra = []
    ): array {
        $tags = [];

        $tags['{customer_name}']   = $customer->get_full_name();
        $tags['{customer_email}']  = $customer->email;
        $tags['{customer_phone}']  = $customer->phone ?? '';
        $tags['{service_name}']    = $service ? $service->name : '';
        $tags['{business_name}']   = get_option( Setting_Keys::BUSINESS_NAME, get_bloginfo( 'name' ) );
        $tags['{business_contact}'] = get_option( Setting_Keys::EMAIL_SENDER_ADDRESS, get_bloginfo( 'admin_email' ) );
        $tags['{booking_date}']    = date_i18n( get_option( 'date_format' ), strtotime( $booking->start_at ) );
        $tags['{booking_time}']    = date_i18n( get_option( 'time_format' ), strtotime( $booking->start_at ) );
        $tags['{booking_timezone}'] = $booking->timezone;

        $tags['{cancel_link}'] = '';
        if ( $booking->cancel_token ) {
            $tags['{cancel_link}'] = \OpenBooking\Support\Public_Booking_Page::get_url( [
                Setting_Keys::TEMPLATE_CANCEL_PREFIX => $booking->cancel_token,
            ] );
        }

        $tags['{reschedule_link}'] = '';
        if ( $booking->reschedule_token ) {
            $tags['{reschedule_link}'] = \OpenBooking\Support\Public_Booking_Page::get_url( [
                Setting_Keys::TEMPLATE_RESCHEDULE_PREFIX => $booking->reschedule_token,
            ] );
        }

        $tags['{confirm_attendance_link}'] = '';
        if ( $booking->confirm_token ) {
            $tags['{confirm_attendance_link}'] = \OpenBooking\Support\Public_Booking_Page::get_url( [
                Setting_Keys::TEMPLATE_CONFIRM_PREFIX => $booking->confirm_token,
            ] );
        }

        $tags['{reschedule_offer_link}'] = \OpenBooking\Support\Public_Booking_Page::get_url();
        $tags['{booking_link}']          = \OpenBooking\Support\Public_Booking_Page::get_url();
        $tags['{unsubscribe_link}']      = '';
        $tags['{cancel_reason_block}']   = (string) ( $extra['{cancel_reason_block}'] ?? '' );
        $tags['{refund_block}']          = (string) ( $extra['{refund_block}'] ?? '' );
        $tags['{broadcast_subject}']     = (string) ( $extra['{broadcast_subject}'] ?? '' );
        $tags['{broadcast_body}']        = wp_kses( (string) ( $extra['{broadcast_body}'] ?? '' ), [
            'p'      => [],
            'br'     => [],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'a'      => [ 'href' => true ],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'h1'     => [],
            'h2'     => [],
            'h3'     => [],
            'span'   => [],
        ] );

        if ( $booking->price_total_minor > 0 ) {
            $tags['{payment_amount}'] = $this->format_price( $booking->price_total_minor, $booking->currency );
        } elseif ( $service ) {
            $tags['{payment_amount}'] = $this->format_price( $service->price_minor, $service->currency );
        } else {
            $tags['{payment_amount}'] = '';
        }

        $prefs = $this->preferences_repo->get_or_create( (int) $customer->id );
        $tags['{unsubscribe_link}'] = add_query_arg( [
            'token' => $prefs['opt_out_token'],
        ], rest_url( 'openbooking/v1/public/notifications/unsubscribe' ) );

        $booking_count = $this->count_customer_bookings( (int) $customer->id );
        $tags['{customer_since}'] = $customer->created_at ?: '';
        $tags['{total_bookings}'] = (string) $booking_count;

        $allowed_extra_keys = [
            '{cancel_reason_block}', '{refund_block}', '{broadcast_subject}', '{broadcast_body}',
        ];
        foreach ( $allowed_extra_keys as $key ) {
            if ( isset( $extra[ $key ] ) ) {
                $tags[ $key ] = $extra[ $key ];
            }
        }

        return $tags;
    }

    private function replace_tags( string $text, array $tags ): string {
        return str_replace( array_keys( $tags ), array_values( $tags ), $text );
    }

    private function format_price( int $minor, string $currency ): string {
        return $currency . ' ' . \OpenBooking\Support\Currency_Helper::format_minor( $minor, $currency );
    }

    private function resolve_template_key( string $template_key, int $customer_id ): string {
        if ( 'booking_confirmed' === $template_key && $this->count_customer_bookings( $customer_id ) <= 1 ) {
            return 'first_booking';
        }

        return $template_key;
    }

    private function count_customer_bookings( int $customer_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ob_bookings WHERE customer_id = %d", $customer_id ) );
    }

    private function log_notification( int $booking_id, string $channel, string $template_key, string $recipient, string $status, array $context = [] ): void {
        global $wpdb;

        $redacted_payload = $context['payload'] ?? [];
        if ( ! empty( $redacted_payload ) ) {
            $redacted_payload = $this->redact_payload_tokens( $redacted_payload );
        }

        $wpdb->insert( $wpdb->prefix . 'ob_notification_logs', [
            'queue_id'      => isset( $context['queue_id'] ) ? absint( $context['queue_id'] ) : null,
            'campaign_id'   => isset( $context['campaign_id'] ) ? absint( $context['campaign_id'] ) : null,
            'booking_id'   => $booking_id,
            'channel'      => $channel,
            'template_key' => $template_key,
            'recipient'    => $this->mask_email( $recipient ),
            'status'       => $status,
            'error_message'=> $context['error_message'] ?? null,
            'attempts'     => absint( $context['attempts'] ?? 1 ),
            'payload'      => ! empty( $redacted_payload ) ? wp_json_encode( $redacted_payload ) : null,
            'sent_at'      => current_time( 'mysql' ),
        ] );
    }

    private function redact_payload_tokens( array $payload ): array {
        array_walk_recursive( $payload, function ( &$value ) {
            if ( is_string( $value ) ) {
                $value = preg_replace(
                    '/obwp_(cancel|reschedule|confirm)=[a-zA-Z0-9]+/',
                    'obwp_$1=[redacted]',
                    $value
                );
                $value = preg_replace(
                    '/[?&]token=[a-zA-Z0-9]+/',
                    '$1token=[redacted]',
                    $value
                );
            }
        } );
        return $payload;
    }

    private function mask_email( string $email ): string {
        $parts = explode( '@', $email );
        if ( 2 !== count( $parts ) ) {
            return $email;
        }
        $local = $parts[0];
        $domain = $parts[1];
        if ( strlen( $local ) <= 2 ) {
            return $local[0] . '***@' . $domain;
        }
        return substr( $local, 0, 2 ) . '***@' . $domain;
    }

    private function sanitize_header_field( string $value ): string {
        return str_replace( [ "\r", "\n", "\t" ], '', $value );
    }
}
