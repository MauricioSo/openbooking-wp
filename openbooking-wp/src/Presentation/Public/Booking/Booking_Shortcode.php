<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Public\Booking;

use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra el shortcode y assets del frontend.
 */

class Booking_Shortcode {

    public function __construct() {
        add_action( 'init', [ $this, 'register_shortcodes' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
        add_action( 'wp_ajax_obwp_refresh_nonce', [ $this, 'ajax_refresh_nonce' ] );
        add_action( 'wp_ajax_nopriv_obwp_refresh_nonce', [ $this, 'ajax_refresh_nonce' ] );
    }

    public function ajax_refresh_nonce(): void {
        wp_send_json( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
    }

    public function register_shortcodes(): void {
        add_shortcode( 'openbooking', [ $this, 'render_shortcode' ] );
    }

    public function render_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'service' => '',
            'layout'  => 'steps',
            'preset'  => '',
        ], $atts, 'openbooking' );

        self::enqueue_public_assets();

        return self::render_form( $atts );
    }

    public static function render_form( array $atts ): string {
        ob_start();
        include OBWP_PLUGIN_DIR . 'templates/public/booking-steps.php';
        return ob_get_clean();
    }

    public function maybe_enqueue_assets(): void {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        if ( ! has_shortcode( $post->post_content, 'openbooking' ) && ! has_block( 'openbooking/booking-form', $post ) ) {
            return;
        }

        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );

        self::enqueue_public_assets();
    }

    public static function enqueue_public_assets(): void {
        if ( wp_script_is( 'obwp-public', 'enqueued' ) ) {
            return;
        }

        wp_enqueue_style( 'obwp-public', OBWP_PLUGIN_URL . 'assets/css/public.css', [], OBWP_VERSION );
        wp_enqueue_script( 'obwp-public', OBWP_PLUGIN_URL . 'assets/js/public.js', [ 'jquery' ], OBWP_VERSION, true );

        $ui_config  = get_option( Setting_Keys::UI_CONFIG, [] );
        $custom_css = $ui_config['custom_css'] ?? '';
        if ( $custom_css !== '' ) {
            wp_add_inline_style( 'obwp-public', $custom_css );
        }

        wp_localize_script( 'obwp-public', 'obwpFront', [
            'restUrl'  => rest_url( 'openbooking/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'privacyPolicyUrl'       => get_option( Setting_Keys::PRIVACY_POLICY_URL, get_privacy_policy_url() ),
            'privacyConsentRequired' => (bool) get_option( Setting_Keys::PRIVACY_CONSENT_REQUIRED, '1' ),
            'nonceRefreshUrl' => admin_url( 'admin-ajax.php?action=obwp_refresh_nonce' ),
            'telemetryUrl'    => rest_url( 'openbooking/v1/telemetry/public' ),
            'paymentMode'     => get_option( Setting_Keys::PAYMENT_MODE, 'full' ),
            'depositPercent'  => (int) get_option( Setting_Keys::DEPOSIT_PERCENT, 30 ),
            'businessTimezone' => get_option( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' ),
            'strings'  => [
                'loading'           => __( 'Cargando...', 'openbooking-wp' ),
                'error_generic'     => __( 'Ocurrio un error. Intenta de nuevo.', 'openbooking-wp' ),
                'slot_taken'        => __( 'Ese horario acaba de ocuparse.', 'openbooking-wp' ),
                'slot_taken_poll'   => __( 'Uno de los horarios fue reservado por otra persona. Se actualizo la lista.', 'openbooking-wp' ),
                'booking_success'   => __( 'Tu reserva fue confirmada', 'openbooking-wp' ),
                'booking_pending'   => __( 'Tu reserva quedo registrada', 'openbooking-wp' ),
                'booking_restore'   => __( 'Recuperamos tu progreso para que continues donde lo dejaste.', 'openbooking-wp' ),
                'booking_restore_clear' => __( 'Empezar de nuevo', 'openbooking-wp' ),
                'no_slots'          => __( 'No hay horarios disponibles para esta fecha.', 'openbooking-wp' ),
                'no_slots_help'     => __( 'Prueba otra fecha o elige otro servicio.', 'openbooking-wp' ),
                'select_date'       => __( 'Selecciona una fecha', 'openbooking-wp' ),
                'select_time'       => __( 'Selecciona un horario', 'openbooking-wp' ),
                'fill_data'         => __( 'Completa tus datos', 'openbooking-wp' ),
                'required_field'    => __( 'Este campo es obligatorio.', 'openbooking-wp' ),
                'invalid_email'     => __( 'Correo electronico no valido.', 'openbooking-wp' ),
                'invalid_phone'     => __( 'Ingresa un telefono valido.', 'openbooking-wp' ),
                'cancel'            => __( 'Cancelar reserva', 'openbooking-wp' ),
                'reschedule'        => __( 'Reprogramar', 'openbooking-wp' ),
                'cancel_confirm'    => __( 'Cancelar esta reserva?', 'openbooking-wp' ),
                'payment_success'   => __( 'Pago procesado correctamente.', 'openbooking-wp' ),
                'payment_failed'    => __( 'El pago no se completo.', 'openbooking-wp' ),
                'payment_redirect'  => __( 'Te llevaremos al proveedor de pago para completar la operacion.', 'openbooking-wp' ),
                'payment_retry'     => __( 'Puedes intentar de nuevo.', 'openbooking-wp' ),
                'payment_pending_title' => __( 'Pago en verificacion', 'openbooking-wp' ),
                'payment_pending_note'  => __( 'Tu pago esta siendo verificado. Te enviaremos un correo cuando la reserva quede confirmada.', 'openbooking-wp' ),
                'retry'             => __( 'Volver a intentar', 'openbooking-wp' ),
                'reschedule_title'  => __( 'Reprograma tu reserva', 'openbooking-wp' ),
                'reschedule_confirm'=> __( 'Confirmar nueva fecha', 'openbooking-wp' ),
                'reschedule_success'=> __( 'Tu reserva fue reprogramada', 'openbooking-wp' ),
                'reschedule_unavailable' => __( 'Esta reserva ya no se puede reprogramar.', 'openbooking-wp' ),
                'current_booking'   => __( 'Horario actual: ', 'openbooking-wp' ),
                'back_home'         => __( 'Volver', 'openbooking-wp' ),
                'total'             => __( 'Total', 'openbooking-wp' ),
                'pay_now'           => __( 'Pagas ahora', 'openbooking-wp' ),
                'pay_later'         => __( 'Pagas despues', 'openbooking-wp' ),
                'reserve_without_payment' => __( 'Reservar sin pago', 'openbooking-wp' ),
                'continue_to_payment' => __( 'Continuar al pago', 'openbooking-wp' ),
                'request_booking'   => __( 'Solicitar reserva', 'openbooking-wp' ),
                'current_timezone'  => __( 'Horario mostrado en', 'openbooking-wp' ),
                'summary_title'     => __( 'Resumen de tu reserva', 'openbooking-wp' ),
                'summary_service'   => __( 'Servicio', 'openbooking-wp' ),
                'summary_datetime'  => __( 'Fecha y hora', 'openbooking-wp' ),
                'summary_payment'   => __( 'Cobro', 'openbooking-wp' ),
                'next_steps'        => __( 'Siguientes pasos', 'openbooking-wp' ),
                'yes'               => __( 'Si', 'openbooking-wp' ),
                'no'                => __( 'No', 'openbooking-wp' ),
                // Sprint 2 - states & empty states
                'no_services'               => __( 'No hay servicios disponibles en este momento.', 'openbooking-wp' ),
                'no_services_help'          => __( 'Intenta nuevamente mas tarde o contacta al negocio.', 'openbooking-wp' ),
                'load_slow'                 => __( 'La carga esta demorando mas de lo habitual.', 'openbooking-wp' ),
                'error_services_load'       => __( 'No pudimos cargar los servicios.', 'openbooking-wp' ),
                'loading_slots'             => __( 'Buscando horarios disponibles...', 'openbooking-wp' ),
                'error_slots_load'          => __( 'No pudimos cargar los horarios.', 'openbooking-wp' ),
                // Sprint 2 / Sprint 4 - payment states
                'payment_pending_what_happens' => __( 'No se ha cobrado nada hasta que el pago se confirme. Recibiras un correo con la confirmacion.', 'openbooking-wp' ),
                'payment_pending_status'    => __( 'Estado de la reserva: pendiente de confirmacion de pago.', 'openbooking-wp' ),
                'payment_no_charge'         => __( 'No se realizo ningun cobro.', 'openbooking-wp' ),
                'payment_retry_help'        => __( 'Tu reserva sigue registrada. Puedes intentar pagar de nuevo.', 'openbooking-wp' ),
                'payment_redirecting'       => __( 'Redirigiendo al proveedor de pago...', 'openbooking-wp' ),
                'check_status'              => __( 'Verificar estado', 'openbooking-wp' ),
                // Sprint 2 / Sprint 5 - cancel flow
                'cancel_confirm_title'      => __( 'Cancelar reserva', 'openbooking-wp' ),
                'yes_cancel'                => __( 'Si, cancelar', 'openbooking-wp' ),
                'cancel_success'            => __( 'Tu reserva fue cancelada.', 'openbooking-wp' ),
                'cancel_confirmed_note'     => __( 'Recibiras un correo confirmando la cancelacion.', 'openbooking-wp' ),
                'cancel_failed'             => __( 'No se pudo cancelar', 'openbooking-wp' ),
                'cancel_help'               => __( 'Si el enlace expiro, contacta al negocio directamente.', 'openbooking-wp' ),
                // Sprint 2 - confirmation states
                'pending_title'             => __( 'Tu reserva esta registrada', 'openbooking-wp' ),
                'pending_note'              => __( 'La reserva se confirmara una vez que validemos el pago. Te enviaremos un correo.', 'openbooking-wp' ),
                'manual_pending_title'      => __( 'Tu reserva quedo registrada', 'openbooking-wp' ),
                'manual_pending_note'       => __( 'Seleccionaste pago manual. La reserva quedara confirmada una vez que validemos tu pago.', 'openbooking-wp' ),
                'manual_note'               => __( 'Pago manual seleccionado. Tu reserva quedo registrada.', 'openbooking-wp' ),
                'booking_ref'               => __( 'Referencia', 'openbooking-wp' ),
                // Sprint 1 / Sprint 5 - reschedule token error copy
                'reschedule_token_expired'       => __( 'Este enlace ya no es valido. Es posible que haya expirado o que la reserva haya cambiado de estado.', 'openbooking-wp' ),
                'reschedule_token_wrong_type'    => __( 'Este enlace no permite reprogramar esta reserva.', 'openbooking-wp' ),
                'reschedule_token_already_used'  => __( 'Este enlace ya fue utilizado. Si necesitas reprogramar nuevamente, usa el enlace mas reciente que recibiste.', 'openbooking-wp' ),
                // Sprint 5 - reschedule submit errors
                'reschedule_failed_expired'      => __( 'Este enlace ya no es valido. La reserva puede haber cambiado de estado.', 'openbooking-wp' ),
                'reschedule_failed_forbidden'    => __( 'No tienes permiso para realizar esta accion.', 'openbooking-wp' ),
                'reschedule_failed_generic'      => __( 'No se pudo reprogramar. Por favor intenta de nuevo o contacta al negocio.', 'openbooking-wp' ),
                // Privacy consent
                'privacy_consent_prefix'         => __( 'He leido y acepto la', 'openbooking-wp' ),
                'privacy_policy'                 => __( 'politica de privacidad', 'openbooking-wp' ),
            ],
        ] );
    }
}
