<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Notification\SMS;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renderiza y envía mensajes SMS transaccionales.
 */
class SMS_Service implements \OpenBooking\Domain\Notification\Service\SMSServiceInterface {

    private const DEFAULT_TEMPLATES = [
        'booking_confirmed' => 'Hola {customer_name}, tu reserva de {service_name} el {booking_date} a las {booking_time} esta confirmada. Cancelar: {cancel_link}',
        'booking_cancelled' => 'Hola {customer_name}, tu reserva de {service_name} del {booking_date} ha sido cancelada.',
        'booking_cancelled_by_admin' => 'Hola {customer_name}, tu reserva de {service_name} del {booking_date} ha sido cancelada. {cancel_reason_block}',
        'booking_expired' => 'Hola {customer_name}, tu reserva de {service_name} expiro porque no se completo el pago a tiempo.',
        'booking_rescheduled' => 'Hola {customer_name}, tu reserva fue reprogramada al {booking_date} a las {booking_time}.',
        'reminder_customer' => 'Recordatorio: {service_name} el {booking_date} a las {booking_time}. Confirmar: {confirm_attendance_link}',
        'payment_received'  => 'Hola {customer_name}, recibimos tu pago de {payment_amount} por {service_name}.',
        'broadcast'         => '{broadcast_body}',
        'attendance_confirmed_admin' => '{customer_name} confirmo asistencia para {service_name} el {booking_date}.',
    ];


    public function __construct(
        private \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository $booking_repo, // persiste reservas en WP
        private \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository $service_repo, // persiste servicios del catalogo
        private \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository $customer_repo, // persiste clientes en WP
        private \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository $preferences_repo, // persiste preferencias de canal
    ) {}

    public function send( string $template_key, int $booking_id, array $extra_data = [], array $context = [] ): bool {
        if ( ! $this->is_enabled() ) {
            return false;
        }

        $preview = $this->preview( $template_key, $booking_id, $extra_data, $context['template_override'] ?? null, $context['recipient'] ?? '' );
        if ( ! $preview ) {
            return false;
        }

        $provider = $this->resolve_provider();
        if ( ! $provider ) {
            return false;
        }

        $sent = $provider->send( $preview['recipient'], $preview['message'], [
            'booking_id'   => $booking_id,
            'template_key' => $preview['template_key'],
        ] );

        $this->log_notification( $booking_id, $preview['template_key'], $preview['recipient'], $sent ? 'sent' : 'failed', $preview['message'], [
            'queue_id'      => $context['queue_id'] ?? null,
            'campaign_id'   => $context['campaign_id'] ?? null,
            'attempts'      => $context['attempts'] ?? 1,
            'error_message' => $sent ? null : 'Provider send returned false',
        ] );

        return $sent;
    }

    public function preview( string $template_key, int $booking_id, array $extra_data = [], ?string $template_override = null, string $recipient = '' ): ?array {
        if ( ! $this->is_enabled() ) {
            return null;
        }

        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return null;
        }

        $service  = $this->service_repo->find( $booking->service_id );
        $customer = $this->customer_repo->find( $booking->customer_id );
        if ( ! $customer ) {
            return null;
        }

        $to = '' !== trim( $recipient ) ? $recipient : (string) $customer->phone;
        if ( '' === trim( $to ) ) {
            return null;
        }

        $template = $template_override ?: $this->get_template( $template_key );
        if ( ! $template ) {
            return null;
        }

        $tags    = $this->build_merge_tags( $booking, $service, $customer, $extra_data );
        $message = $this->replace_tags( $template, $tags );

        if ( mb_strlen( $message ) > 160 ) {
            $message = mb_substr( $message, 0, 157 ) . '...';
        }

        return [
            'template_key' => $template_key,
            'recipient'    => $to,
            'message'      => $message,
        ];
    }

    public function send_raw( string $to, string $message ): bool {
        if ( ! $this->is_enabled() ) {
            return false;
        }

        $provider = $this->resolve_provider();
        if ( ! $provider ) {
            return false;
        }

        return $provider->send( $to, $message );
    }

    public function get_template( string $key ): ?string {
        $saved = get_option( Setting_Keys::SMS_TEMPLATE_PREFIX . $key, null );
        if ( $saved && is_string( $saved ) && '' !== trim( $saved ) ) {
            return $saved;
        }
        return self::DEFAULT_TEMPLATES[ $key ] ?? null;
    }

    public function save_template( string $key, string $body ): void {
        update_option( Setting_Keys::SMS_TEMPLATE_PREFIX . $key, $body );
    }

    public function get_all_templates(): array {
        $templates = [];
        foreach ( self::DEFAULT_TEMPLATES as $key => $default ) {
            $templates[ $key ] = $this->get_template( $key ) ?? $default;
        }
        return $templates;
    }

    public function is_enabled(): bool {
        return (bool) get_option( Setting_Keys::SMS_ENABLED, false );
    }

    public function resolve_provider(): ?SMS_Provider_Interface {
        $provider_name = (string) get_option( Setting_Keys::SMS_PROVIDER, 'twilio' );

        $provider = apply_filters( 'openbooking_sms_provider', null, $provider_name );
        if ( $provider instanceof SMS_Provider_Interface ) {
            return $provider->is_configured() ? $provider : null;
        }

        $p = new Twilio_SMS_Provider();

        if ( ! $p->is_configured() ) {
            error_log( sprintf( '[OpenBooking] SMS proveedor "%s" no esta configurado.', $provider_name ) );
            return null;
        }

        return $p;
    }

    private function build_merge_tags(
        \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking,
        ?\OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        \OpenBooking\Domain\Customer\Entity\Customer_Entity $customer,
        array $extra = []
    ): array {
        $tags = [];

        $tags['{customer_name}']    = $customer->get_full_name();
        $tags['{service_name}']     = $service ? $service->name : '';
        $tags['{business_name}']    = get_option( Setting_Keys::BUSINESS_NAME, get_bloginfo( 'name' ) );
        $tags['{booking_date}']     = date_i18n( get_option( 'date_format' ), strtotime( $booking->start_at ) );
        $tags['{booking_time}']     = date_i18n( get_option( 'time_format' ), strtotime( $booking->start_at ) );

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

        $tags['{booking_link}']          = \OpenBooking\Support\Public_Booking_Page::get_url();
        $tags['{cancel_reason_block}']   = (string) ( $extra['{cancel_reason_block}'] ?? '' );
        $tags['{broadcast_body}']        = (string) ( $extra['{broadcast_body}'] ?? '' );

        if ( $booking->price_total_minor > 0 ) {
            $tags['{payment_amount}'] = $this->format_price( $booking->price_total_minor, $booking->currency );
        } elseif ( $service ) {
            $tags['{payment_amount}'] = $this->format_price( $service->price_minor, $service->currency );
        } else {
            $tags['{payment_amount}'] = '';
        }

        return array_merge( $tags, $extra );
    }

    private function replace_tags( string $text, array $tags ): string {
        return str_replace( array_keys( $tags ), array_values( $tags ), $text );
    }

    private function format_price( int $minor, string $currency ): string {
        return $currency . ' ' . \OpenBooking\Support\Currency_Helper::format_minor( $minor, $currency );
    }

    private function log_notification( int $booking_id, string $template_key, string $recipient, string $status, string $message, array $context = [] ): void {
        global $wpdb;

        $redacted_message = $this->redact_tokens( $message );

        $wpdb->insert( $wpdb->prefix . 'ob_notification_logs', [
            'queue_id'      => isset( $context['queue_id'] ) ? absint( $context['queue_id'] ) : null,
            'campaign_id'   => isset( $context['campaign_id'] ) ? absint( $context['campaign_id'] ) : null,
            'booking_id'    => $booking_id,
            'channel'       => 'sms',
            'template_key'  => $template_key,
            'recipient'     => $this->mask_recipient( $recipient ),
            'status'        => $status,
            'error_message' => $context['error_message'] ?? null,
            'attempts'      => absint( $context['attempts'] ?? 1 ),
            'payload'       => wp_json_encode( [ 'message' => $redacted_message ] ),
            'sent_at'       => current_time( 'mysql' ),
        ] );
    }

    private function redact_tokens( string $text ): string {
        return preg_replace(
            '/obwp_(cancel|reschedule|confirm)=[a-zA-Z0-9]+/',
            'obwp_$1=[redacted]',
            $text
        );
    }

    private function mask_recipient( string $recipient ): string {
        $digits = preg_replace( '/\D/', '', $recipient );
        $len = strlen( $digits );
        if ( $len <= 4 ) {
            return $recipient;
        }
        return substr( $digits, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $digits, -2 );
    }
}
