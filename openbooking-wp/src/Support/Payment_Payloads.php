<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convierte entidades de pago a payloads para API y admin.
 */
final class Payment_Payloads {

    public static function attempt_from_entity( \OpenBooking\Domain\Payment\Entity\Payment_Attempt_Entity $attempt ): array {
        return [
            'id'               => $attempt->id,
            'payment_id'       => $attempt->payment_id,
            'booking_id'       => $attempt->booking_id,
            'gateway'          => $attempt->gateway,
            'amount_minor'     => $attempt->amount_minor,
            'currency'         => $attempt->currency,
            'status'           => $attempt->status,
            'gateway_ref'      => $attempt->gateway_ref,
            'gateway_response' => $attempt->gateway_response,
            'ip_address'       => $attempt->ip_address,
            'initiated_at'     => $attempt->initiated_at,
            'resolved_at'      => $attempt->resolved_at,
        ];
    }

    public static function admin_from_entity( \OpenBooking\Domain\Payment\Entity\Payment_Entity $payment ): array {
        return self::admin_list_from_entity( $payment ) + [
            'raw_payload' => $payment->raw_payload,
        ];
    }

    public static function admin_list_from_entity( \OpenBooking\Domain\Payment\Entity\Payment_Entity $payment ): array {
        return [
            'id'                  => $payment->id,
            'booking_id'          => $payment->booking_id,
            'gateway'             => $payment->gateway,
            'provider_payment_id' => $payment->provider_payment_id,
            'status'              => $payment->status,
            'amount_minor'        => $payment->amount_minor,
            'currency'            => $payment->currency,
            'mode'                => $payment->mode,
            'paid_at'             => $payment->paid_at,
            'provider_checkout_id' => $payment->provider_checkout_id,
            'checkout_url'        => $payment->checkout_url,
            'checkout_expires_at' => $payment->checkout_expires_at,
            'created_at'          => $payment->created_at,
            'updated_at'          => $payment->updated_at,
        ];
    }
}
