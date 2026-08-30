<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Notification\WhatsApp;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends WhatsApp notifications for booking events.
 *
 * Mirrors the structure of Email_Service:
 *  - Same merge-tag system ({customer_name}, {service_name}, etc.)
 *  - Templates stored as WordPress options (obwp_whatsapp_template_{key})
 *  - Logs every attempt to ob_notification_logs with channel = 'whatsapp'
 *  - Provider selection driven by the obwp_whatsapp_provider option
 *
 * To enable: set obwp_whatsapp_enabled = 1 and configure the chosen provider's
 * credentials in OpenBooking > Ajustes > Notificaciones.
 */
/**
 * Renderiza y envía mensajes de WhatsApp transaccionales.
 */
class WhatsApp_Service implements \OpenBooking\Domain\Notification\Service\WhatsAppServiceInterface {

    /**
     * Default WhatsApp message templates.
     * These are intentionally shorter than email templates — suited for mobile reading.
     * Admins can override any template from the admin UI.
     */
    private const DEFAULT_TEMPLATES = [
        'booking_confirmed' => "Hola {customer_name}, tu reserva de *{service_name}* el {booking_date} a las {booking_time} está confirmada ✓\n\nCancelar: {cancel_link}\nReprogramar: {reschedule_link}",
        'booking_cancelled' => "Hola {customer_name}, tu reserva de *{service_name}* del {booking_date} ha sido cancelada.",
        'booking_cancelled_by_admin' => "Hola {customer_name}, lamentamos informarte que tu reserva de *{service_name}* del {booking_date} ha sido cancelada. {cancel_reason_block} Si quieres reagendar: {reschedule_offer_link} Disculpa los inconvenientes. {business_name}",
        'booking_rescheduled' => "Hola {customer_name}, tu reserva fue reprogramada a *{booking_date}* a las {booking_time}.\n\nCancelar: {cancel_link}\nReprogramar: {reschedule_link}",
        'payment_received'  => "Hola {customer_name}, recibimos tu pago de {payment_amount} por *{service_name}* el {booking_date}. ✓",
        'reminder_customer' => "Recordatorio: Hola {customer_name}, tienes *{service_name}* mañana {booking_date} a las {booking_time}.\n\n¿Confirmas que vas? Toca aquí: {confirm_attendance_link}\n\nNo puedes ir:\nCancelar: {cancel_link}",
        'new_booking_admin' => "Nueva reserva: {customer_name} — *{service_name}* el {booking_date} {booking_time}.",
        'booking_expired'   => "Hola {customer_name}, el tiempo para pagar tu reserva de *{service_name}* vencio y el horario ya no esta disponible. Puedes volver a reservar aqui: {booking_link}",
        'first_booking'     => "Hola {customer_name}, esta es tu primera reserva con nosotros. Te damos la bienvenida para tu cita de *{service_name}* el {booking_date} a las {booking_time}.",
        'broadcast'         => '{broadcast_body}',
        'attendance_confirmed_admin' => "✓ {customer_name} confirmó asistencia para *{service_name}* el {booking_date} a las {booking_time}.",
    ];


    public function __construct(
        private \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository $booking_repo, // persiste reservas en WP
        private \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository $service_repo, // persiste servicios del catalogo
        private \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository $customer_repo, // persiste clientes en WP
        private \OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository $preferences_repo, // persiste preferencias de canal
    ) {}

    /**
     * Send a WhatsApp notification to the booking's customer.
     *
     * @param string $template_key  One of the keys in DEFAULT_TEMPLATES.
     * @param int    $booking_id
     * @param array  $extra_data    Additional merge tags.
     *
     * @return bool
     */
    public function send( string $template_key, int $booking_id, array $extra_data = [], array $context = [] ): bool {
        if ( ! $this->is_enabled() ) {
            return false;
        }

        $preview = $this->preview( $template_key, $booking_id, $extra_data, $context['template_override'] ?? null );
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

    public function preview( string $template_key, int $booking_id, array $extra_data = [], ?string $template_override = null ): ?array {
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

        $to = $template_key === 'new_booking_admin'
            ? (string) get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE, '' )
            : ( $customer->phone ?? '' );

        if ( '' === trim( $to ) ) {
            return null;
        }

        $template_key = $this->resolve_template_key( $template_key, (int) $customer->id );
        $template = $template_override ?: $this->get_template( $template_key );
        if ( ! $template ) {
            return null;
        }

        $tags    = $this->build_merge_tags( $booking, $service, $customer, $extra_data );
        $message = $this->replace_tags( $template, $tags );

        return [
            'template_key' => $template_key,
            'recipient'    => $to,
            'message'      => $message,
        ];
    }

    /**
     * Send a WhatsApp message directly to a given phone number.
     * Useful for admin test sends and for admin-phone notifications.
     */
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
        $saved = get_option( Setting_Keys::WHATSAPP_TEMPLATE_PREFIX . $key, null );
        if ( $saved && is_string( $saved ) && '' !== trim( $saved ) ) {
            return $saved;
        }
        return self::DEFAULT_TEMPLATES[ $key ] ?? null;
    }

    public function save_template( string $key, string $body ): void {
        update_option( Setting_Keys::WHATSAPP_TEMPLATE_PREFIX . $key, $body );
    }

    public function get_all_templates(): array {
        $templates = [];
        foreach ( self::DEFAULT_TEMPLATES as $key => $default ) {
            $templates[ $key ] = $this->get_template( $key ) ?? $default;
        }
        return $templates;
    }

    public function is_enabled(): bool {
        return (bool) get_option( Setting_Keys::WHATSAPP_ENABLED, false );
    }

    /**
     * Return the configured provider instance, or null if none is configured.
     */
    public function resolve_provider(): ?WhatsApp_Provider_Interface {
        $provider_name = (string) get_option( Setting_Keys::WHATSAPP_PROVIDER, 'twilio' );

        $provider = apply_filters( 'openbooking_whatsapp_provider', null, $provider_name );
        if ( $provider instanceof WhatsApp_Provider_Interface ) {
            return $provider->is_configured() ? $provider : null;
        }

        switch ( $provider_name ) {
            case 'meta':
                $p = new Meta_WhatsApp_Provider();
                break;
            case 'twilio':
            default:
                $p = new Twilio_WhatsApp_Provider();
                break;
        }

        if ( ! $p->is_configured() ) {
            error_log( sprintf( '[OpenBooking] WhatsApp proveedor "%s" no está configurado.', $provider_name ) );
            return null;
        }

        return $p;
    }

    // -------------------------------------------------------------------------
    // Merge tags
    // -------------------------------------------------------------------------

    private function build_merge_tags(
        \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking,
        ?\OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        \OpenBooking\Domain\Customer\Entity\Customer_Entity $customer,
        array $extra = []
    ): array {
        $tags = [];

        $tags['{customer_name}']    = $customer->get_full_name();
        $tags['{customer_email}']   = $customer->email;
        $tags['{customer_phone}']   = $customer->phone ?? '';
        $tags['{service_name}']     = $service ? $service->name : '';
        $tags['{business_name}']    = get_option( Setting_Keys::BUSINESS_NAME, get_bloginfo( 'name' ) );
        $tags['{booking_date}']     = date_i18n( get_option( 'date_format' ), strtotime( $booking->start_at ) );
        $tags['{booking_time}']     = date_i18n( get_option( 'time_format' ), strtotime( $booking->start_at ) );
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
        $tags['{cancel_reason_block}']   = (string) ( $extra['{cancel_reason_block}'] ?? '' );
        $tags['{broadcast_body}']        = (string) ( $extra['{broadcast_body}'] ?? '' );

        if ( $booking->price_total_minor > 0 ) {
            $tags['{payment_amount}'] = $this->format_price( $booking->price_total_minor, $booking->currency );
        } elseif ( $service ) {
            $tags['{payment_amount}'] = $this->format_price( $service->price_minor, $service->currency );
        } else {
            $tags['{payment_amount}'] = '';
        }

        $prefs = $this->preferences_repo->get_or_create( (int) $customer->id );
        $tags['{unsubscribe_link}'] = add_query_arg( [ 'token' => $prefs['opt_out_token'] ], rest_url( 'openbooking/v1/public/notifications/unsubscribe' ) );

        return array_merge( $tags, $extra );
    }

    private function replace_tags( string $text, array $tags ): string {
        return str_replace( array_keys( $tags ), array_values( $tags ), $text );
    }

    private function format_price( int $minor, string $currency ): string {
        return $currency . ' ' . \OpenBooking\Support\Currency_Helper::format_minor( $minor, $currency );
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function resolve_template_key( string $template_key, int $customer_id ): string {
        global $wpdb;
        if ( 'booking_confirmed' === $template_key ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ob_bookings WHERE customer_id = %d", $customer_id ) );
            if ( $count <= 1 ) {
                return 'first_booking';
            }
        }

        return $template_key;
    }

    private function log_notification( int $booking_id, string $template_key, string $recipient, string $status, string $message, array $context = [] ): void {
        global $wpdb;

        $redacted_message = preg_replace(
            '/obwp_(cancel|reschedule|confirm)=[a-zA-Z0-9]+/',
            'obwp_$1=[redacted]',
            $message
        );

        $wpdb->insert( $wpdb->prefix . 'ob_notification_logs', [
            'queue_id'      => isset( $context['queue_id'] ) ? absint( $context['queue_id'] ) : null,
            'campaign_id'   => isset( $context['campaign_id'] ) ? absint( $context['campaign_id'] ) : null,
            'booking_id'   => $booking_id,
            'channel'      => 'whatsapp',
            'template_key' => $template_key,
            'recipient'    => $this->mask_phone( $recipient ),
            'status'       => $status,
            'error_message'=> $context['error_message'] ?? null,
            'attempts'     => absint( $context['attempts'] ?? 1 ),
            'payload'      => wp_json_encode( [ 'message' => $redacted_message ] ),
            'sent_at'      => current_time( 'mysql' ),
        ] );
    }

    private function mask_phone( string $phone ): string {
        $digits = preg_replace( '/\D/', '', $phone );
        $len = strlen( $digits );
        if ( $len <= 4 ) {
            return $phone;
        }
        return substr( $digits, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $digits, -2 );
    }
}
