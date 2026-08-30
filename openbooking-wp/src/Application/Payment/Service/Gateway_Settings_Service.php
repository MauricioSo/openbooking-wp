<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Payment\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Application\Shared\Port\HookDispatcherInterface;
use OpenBooking\Domain\Payment\Repository\GatewayResolverInterface;
use OpenBooking\Application\Audit\Service\Audit_Logger;
use OpenBooking\Support\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lee y guarda la configuracion administrativa de los gateways de pago.
 */
class Gateway_Settings_Service {

    public function __construct(
        private SettingsInterface $settings,
        private HookDispatcherInterface $hooks,
        private GatewayResolverInterface $gateway_resolver,
        private Audit_Logger $audit_logger,
    ) {}

    public function get_gateway_overview(): array {
        $country  = $this->settings->get( Setting_Keys::BUSINESS_COUNTRY, '' );
        $enabled  = (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] );
        $gateways = $this->gateway_resolver->get_available_for_country( $country );

        $data = array_map(
            function ( $gateway ) use ( $enabled ) {
                return $this->build_gateway_overview_item( $gateway, $enabled );
            },
            $gateways
        );

        return array_values( $data );
    }

    public function save_gateway_settings( string $key, array $body ): array {
        $allowed_keys = $this->hooks->apply_filters( 'openbooking_gateway_settings_keys', [ 'stripe' => [ 'secret_key', 'publishable_key', 'webhook_secret' ], 'mercadopago' => [ 'access_token', 'sandbox', 'webhook_secret' ], 'webpay' => [ 'commerce_code', 'api_key', 'sandbox' ] ], $key );
        if ( ! isset( $allowed_keys[ $key ] ) ) {
            return [ 'error' => 'Gateway no soportado.', 'code' => 404 ];
        }
        $fields = $allowed_keys[ $key ];

        // Acepta `mode` como alias de `sandbox` para que mode=live/test llegue a Webpay/MercadoPago.
        if ( in_array( $key, [ 'webpay', 'mercadopago' ], true ) && isset( $body['mode'] ) && ! isset( $body['sandbox'] ) ) {
            $body['sandbox'] = 'live' === sanitize_key( (string) $body['mode'] ) ? '0' : '1';
        }

        $before = [];
        $after  = [];
        $applied = [];
        $ignored = [];
        $secret_fields = [ 'secret_key', 'api_key', 'access_token', 'webhook_secret' ];
        $boolean_fields = [ 'sandbox' ];

        foreach ( array_keys( $body ) as $field ) {
            if ( ! in_array( $field, $fields, true ) && 'mode' !== $field ) {
                $ignored[] = $field;
            }
        }

        foreach ( $fields as $field ) {
            $option_key = $this->gateway_option_key( $key, $field );
            $before[ $field ] = $this->settings->get( $option_key, '' );
            if ( isset( $body[ $field ] ) ) {
                if ( in_array( $field, $boolean_fields, true ) ) {
                    $this->settings->set( $option_key, $this->normalize_flag( $body[ $field ] ) );
                    $applied[] = $field;
                } else {
                    $value = sanitize_text_field( (string) $body[ $field ] );
                    if ( in_array( $field, $secret_fields, true ) && '' === $value ) {
                        $after[ $field ] = $this->settings->get( $option_key, '' );
                        continue;
                    }
                    $stored = in_array( $field, $secret_fields, true )
                        ? Crypto::encrypt( $value )
                        : $value;
                    $this->settings->set( $option_key, $stored );
                    $applied[] = $field;
                }
            }
            $after[ $field ] = $this->settings->get( $option_key, '' );
        }
        $this->audit_logger->log_entity_change( 'gateway', 0, 'gateway_settings_updated', $before, $after, [ 'gateway' => $key ], [ 'message' => 'Gateway settings updated from admin.', 'allowed_fields' => $fields, 'redacted_fields' => [ 'secret_key', 'access_token', 'webhook_secret' ] ] );

        return [ 'applied' => $applied, 'ignored_fields' => $ignored ];
    }

    /**
     * Normaliza flags booleanos a '1'/'0'. Valores desconocidos fallan a '1'
     * (sandbox) para no exponer pagos reales por un typo.
     */
    private function normalize_flag( mixed $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }
        $value = strtolower( trim( (string) $value ) );
        if ( in_array( $value, [ '0', 'false', 'no', 'off', '' ], true ) ) {
            return '0';
        }

        return '1';
    }

    public function is_gateway_configured( string $key ): bool {
        switch ( $key ) {
            case 'stripe':
                return (bool) ( $this->settings->get( Setting_Keys::STRIPE_SECRET_KEY, '' ) && $this->settings->get( Setting_Keys::STRIPE_PUBLISHABLE_KEY, '' ) && $this->settings->get( Setting_Keys::STRIPE_WEBHOOK_SECRET, '' ) );
            case 'mercadopago':
                return (bool) ( $this->settings->get( Setting_Keys::MP_ACCESS_TOKEN, '' ) && $this->settings->get( Setting_Keys::MP_WEBHOOK_SECRET, '' ) );
            case 'webpay':
                return (bool) ( $this->settings->get( Setting_Keys::WEBPAY_COMMERCE_CODE, '' ) && $this->settings->get( Setting_Keys::WEBPAY_API_KEY, '' ) );
            case 'manual':
                return true;
        }
        return false;
    }

    public function get_gateway_health( string $key ): array {
        switch ( $key ) {
            case 'stripe':
                $missing = [];
                if ( ! $this->settings->get( Setting_Keys::STRIPE_SECRET_KEY, '' ) ) { $missing[] = 'secret_key'; }
                if ( ! $this->settings->get( Setting_Keys::STRIPE_PUBLISHABLE_KEY, '' ) ) { $missing[] = 'publishable_key'; }
                if ( ! $this->settings->get( Setting_Keys::STRIPE_WEBHOOK_SECRET, '' ) ) { $missing[] = 'webhook_secret'; }
                return [ 'configured' => empty( $missing ), 'status' => empty( $missing ) ? 'ok' : 'warning', 'missing' => $missing ];
            case 'mercadopago':
                $missing = [];
                if ( ! $this->settings->get( Setting_Keys::MP_ACCESS_TOKEN, '' ) ) { $missing[] = 'access_token'; }
                return [ 'configured' => empty( $missing ), 'status' => empty( $missing ) ? 'ok' : 'warning', 'missing' => $missing, 'webhook_signed' => (bool) $this->settings->get( Setting_Keys::MP_WEBHOOK_SECRET, '' ) ];
            case 'webpay':
                $missing = [];
                if ( ! $this->settings->get( Setting_Keys::WEBPAY_COMMERCE_CODE, '' ) ) { $missing[] = 'commerce_code'; }
                if ( ! $this->settings->get( Setting_Keys::WEBPAY_API_KEY, '' ) ) { $missing[] = 'api_key'; }
                return [ 'configured' => empty( $missing ), 'status' => empty( $missing ) ? 'ok' : 'warning', 'missing' => $missing, 'sandbox' => (bool) $this->settings->get( Setting_Keys::WEBPAY_SANDBOX, true ) ];
            case 'manual':
                return [ 'configured' => true, 'status' => 'ok', 'missing' => [] ];
        }
        return [ 'configured' => false, 'status' => 'warning', 'missing' => [ 'unknown_gateway' ] ];
    }

    public function get_payment_settings(): array {
        $country = $this->settings->get( Setting_Keys::BUSINESS_COUNTRY, '' );
        $stripe_pk = (string) $this->settings->get( Setting_Keys::STRIPE_PUBLISHABLE_KEY, '' );
        $mp_token  = (string) $this->settings->get( Setting_Keys::MP_ACCESS_TOKEN, '' );
        $webpay_code = (string) $this->settings->get( Setting_Keys::WEBPAY_COMMERCE_CODE, '' );
        $gateway_modes = [
            'stripe'      => str_starts_with( $stripe_pk, 'pk_live_' ) ? 'live' : ( $stripe_pk ? 'test' : 'unconfigured' ),
            'mercadopago' => str_starts_with( $mp_token, 'APP_USR-' ) ? 'live' : ( $mp_token ? 'test' : 'unconfigured' ),
            'webpay'      => $webpay_code ? ( $this->settings->get( Setting_Keys::WEBPAY_SANDBOX, '1' ) ? 'test' : 'live' ) : 'unconfigured',
            'manual'      => 'live',
        ];
        return [
            'payment_mode'         => $this->settings->get( Setting_Keys::PAYMENT_MODE, 'full' ),
            'deposit_percent'      => (int) $this->settings->get( Setting_Keys::DEPOSIT_PERCENT, 30 ),
            'enabled_gateways'     => (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ),
            'checkout_ttl_minutes' => (int) $this->settings->get( Setting_Keys::CHECKOUT_TTL_MINUTES, 30 ),
            'country'              => $country,
            'available_gateways'   => $this->gateway_resolver->get_gateways_for_country( $country ),
            'gateway_modes'        => $gateway_modes,
        ];
    }

    public function save_payment_settings( array $body ): void {
        $before = [
            'payment_mode'         => $this->settings->get( Setting_Keys::PAYMENT_MODE, 'full' ),
            'deposit_percent'      => (int) $this->settings->get( Setting_Keys::DEPOSIT_PERCENT, 30 ),
            'enabled_gateways'     => (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ),
            'checkout_ttl_minutes' => (int) $this->settings->get( Setting_Keys::CHECKOUT_TTL_MINUTES, 30 ),
        ];
		if ( isset( $body['payment_mode'] ) ) {
			$mode = sanitize_key( $body['payment_mode'] );
			if ( in_array( $mode, [ 'full', 'deposit', 'none' ], true ) ) {
				$this->settings->set( Setting_Keys::PAYMENT_MODE, $mode );
			}
		}
        if ( isset( $body['deposit_percent'] ) ) {
            $pct = max( 1, min( 99, (int) $body['deposit_percent'] ) );
            $this->settings->set( Setting_Keys::DEPOSIT_PERCENT, $pct );
        }
		if ( isset( $body['enabled_gateways'] ) ) {
			$available = array_keys( $this->gateway_resolver->get_available_for_country(
				(string) $this->settings->get( Setting_Keys::BUSINESS_COUNTRY, '' )
			) );
			$enabled = array_values( array_unique( array_filter(
				array_map( 'sanitize_key', (array) $body['enabled_gateways'] ),
				static fn( string $gateway ): bool => in_array( $gateway, $available, true )
			) ) );
			$this->settings->set( Setting_Keys::ENABLED_GATEWAYS, $enabled );
		}
        if ( isset( $body['checkout_ttl_minutes'] ) ) {
            $this->settings->set( Setting_Keys::CHECKOUT_TTL_MINUTES, max( 5, absint( $body['checkout_ttl_minutes'] ) ) );
        }
        $after = [
            'payment_mode'         => $this->settings->get( Setting_Keys::PAYMENT_MODE, 'full' ),
            'deposit_percent'      => (int) $this->settings->get( Setting_Keys::DEPOSIT_PERCENT, 30 ),
            'enabled_gateways'     => (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ),
            'checkout_ttl_minutes' => (int) $this->settings->get( Setting_Keys::CHECKOUT_TTL_MINUTES, 30 ),
        ];
        $this->audit_logger->log_entity_change( 'settings', 0, 'settings_updated_payments', $before, $after, [], [
            'message'        => 'Payment settings updated from admin.',
            'allowed_fields' => array_keys( $after ),
        ] );
    }

    public function get_gateway_checklist( string $key ): ?array {
        $checklists = [
            'stripe' => [
                'label' => 'Stripe',
                'docs_url' => 'https://stripe.com/docs/keys',
                'steps' => [
                    [ 'id' => 'publishable_key', 'label' => 'Clave pública (pk_...)', 'done' => (bool) $this->settings->get( Setting_Keys::STRIPE_PUBLISHABLE_KEY, '' ), 'action' => 'Ingresa la clave pública de tu cuenta Stripe en Ajustes > Pagos.' ],
                    [ 'id' => 'secret_key', 'label' => 'Clave secreta (sk_...)', 'done' => (bool) $this->settings->get( Setting_Keys::STRIPE_SECRET_KEY, '' ), 'action' => 'Ingresa la clave secreta de tu cuenta Stripe en Ajustes > Pagos.' ],
                    [ 'id' => 'webhook_secret', 'label' => 'Webhook secret (whsec_...)', 'done' => (bool) $this->settings->get( Setting_Keys::STRIPE_WEBHOOK_SECRET, '' ), 'action' => 'Crea un webhook en el dashboard de Stripe apuntando a: ' . rest_url( 'openbooking/v1/payments/webhook/stripe' ) ],
                    [ 'id' => 'gateway_enabled', 'label' => 'Gateway habilitado', 'done' => in_array( 'stripe', (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ), true ), 'action' => 'Habilita Stripe en Ajustes > Pagos > Métodos de pago.' ],
                    [ 'id' => 'test_mode', 'label' => 'Modo de prueba verificado', 'done' => (bool) $this->settings->get( Setting_Keys::STRIPE_TEST_MODE_VERIFIED, false ), 'action' => 'Realiza una reserva de prueba con tarjeta 4242 4242 4242 4242.' ],
                ],
                'mode' => str_starts_with( (string) $this->settings->get( Setting_Keys::STRIPE_PUBLISHABLE_KEY, '' ), 'pk_live_' ) ? 'live' : 'test',
            ],
            'mercadopago' => [
                'label' => 'MercadoPago',
                'docs_url' => 'https://www.mercadopago.com/developers/es/docs',
                'steps' => [
                    [ 'id' => 'access_token', 'label' => 'Access token', 'done' => (bool) $this->settings->get( Setting_Keys::MP_ACCESS_TOKEN, '' ), 'action' => 'Ingresa el access token de tu cuenta MercadoPago en Ajustes > Pagos.' ],
                    [ 'id' => 'webhook_secret', 'label' => 'Webhook secret', 'done' => (bool) $this->settings->get( Setting_Keys::MP_WEBHOOK_SECRET, '' ), 'action' => 'Configura el webhook secret en Ajustes > Pagos > MercadoPago. URL del webhook: ' . rest_url( 'openbooking/v1/payments/webhook/mercadopago' ) ],
                    [ 'id' => 'gateway_enabled', 'label' => 'Gateway habilitado', 'done' => in_array( 'mercadopago', (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ), true ), 'action' => 'Habilita MercadoPago en Ajustes > Pagos > Métodos de pago.' ],
                ],
                'mode' => str_starts_with( (string) $this->settings->get( Setting_Keys::MP_ACCESS_TOKEN, '' ), 'APP_USR-' ) ? 'live' : 'test',
            ],
            'webpay' => [
                'label' => 'Webpay (Transbank)',
                'docs_url' => 'https://www.transbankdevelopers.cl/documentacion/webpay-plus',
                'steps' => [
                    [ 'id' => 'commerce_code', 'label' => 'Código de comercio', 'done' => (bool) $this->settings->get( Setting_Keys::WEBPAY_COMMERCE_CODE, '' ), 'action' => 'Ingresa el código de comercio entregado por Transbank en Ajustes > Pagos > Webpay. Sandbox: 597055555532' ],
                    [ 'id' => 'api_key', 'label' => 'API Key secret', 'done' => (bool) $this->settings->get( Setting_Keys::WEBPAY_API_KEY, '' ), 'action' => 'Ingresa la API Key secret entregada por Transbank. Sandbox: 579B532A7440BB0C9079DED94D31EA1615BACEB56610332264630D42D0A36B1C' ],
                    [ 'id' => 'return_url_reachable', 'label' => 'Return URL pública', 'done' => (bool) $this->settings->get( Setting_Keys::WEBPAY_RETURN_URL_VERIFIED, false ), 'action' => 'Asegúrate de que tu sitio sea accesible desde internet. URL de retorno: ' . rest_url( 'openbooking/v1/payments/webpay-return' ) ],
                    [ 'id' => 'gateway_enabled', 'label' => 'Gateway habilitado', 'done' => in_array( 'webpay', (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ), true ), 'action' => 'Habilita Webpay en Ajustes > Pagos > Métodos de pago.' ],
                    [ 'id' => 'sandbox_test', 'label' => 'Prueba en sandbox verificada', 'done' => (bool) $this->settings->get( Setting_Keys::WEBPAY_SANDBOX_VERIFIED, false ), 'action' => 'Realiza una reserva de prueba. Tarjeta Visa: 4051 8856 0044 6623 / CVV: 123 / Fecha: cualquier mes futuro.' ],
                ],
                'mode' => $this->settings->get( Setting_Keys::WEBPAY_SANDBOX, '1' ) ? 'test' : 'live',
            ],
            'manual' => [
                'label' => 'Pago manual',
                'docs_url' => null,
                'steps' => [
                    [ 'id' => 'gateway_enabled', 'label' => 'Gateway habilitado', 'done' => in_array( 'manual', (array) $this->settings->get( Setting_Keys::ENABLED_GATEWAYS, [] ), true ), 'action' => 'Habilita el pago manual en Ajustes > Pagos > Métodos de pago.' ],
                ],
                'mode' => 'live',
            ],
        ];
        return $checklists[ $key ] ?? null;
    }

    private function gateway_option_key( string $gateway, string $field ): string {
        return 'mercadopago' === $gateway ? Setting_Keys::MP_PREFIX . $field : 'obwp_' . $gateway . '_' . $field;
    }

    private function build_gateway_overview_item( $gateway, array $enabled ): array {
        $key = $gateway->get_key();

        return [
            'key'        => $key,
            'label'      => $gateway->get_label(),
            'enabled'    => in_array( $key, $enabled, true ) || 'manual' === $key,
            'configured' => $this->is_gateway_configured( $key ),
            'health'     => $this->get_gateway_health( $key ),
        ];
    }
}
