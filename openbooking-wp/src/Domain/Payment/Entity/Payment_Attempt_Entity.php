<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Payment\Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra cada intento de cobro sobre un pago.
 */
class Payment_Attempt_Entity {

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REFUNDED  = 'refunded';

    public ?int    $id               = null;
    public int     $payment_id       = 0;
    public int     $booking_id       = 0;
    public string  $gateway          = '';
    public int     $amount_minor     = 0;
    public string  $currency         = 'USD';
    public string  $status           = self::STATUS_INITIATED;
    public ?string $gateway_ref      = null;
    public ?string $gateway_response = null;
    public ?string $ip_address       = null;
    public ?string $initiated_at     = null;
    public ?string $resolved_at      = null;

    public static function from_array( array $data ): self {
        $e                    = new self();
        $e->id                = isset( $data['id'] ) ? (int) $data['id'] : null;
        $e->payment_id        = (int) ( $data['payment_id']       ?? 0 );
        $e->booking_id        = (int) ( $data['booking_id']       ?? 0 );
        $e->gateway           = (string) ( $data['gateway']       ?? '' );
        $e->amount_minor      = (int) ( $data['amount_minor']     ?? 0 );
        $e->currency          = (string) ( $data['currency']      ?? 'USD' );
        $e->status            = (string) ( $data['status']        ?? self::STATUS_INITIATED );
        $e->gateway_ref       = $data['gateway_ref']              ?? null;
        $e->gateway_response  = $data['gateway_response']         ?? null;
        $e->ip_address        = $data['ip_address']               ?? null;
        $e->initiated_at      = $data['initiated_at']             ?? null;
        $e->resolved_at       = $data['resolved_at']              ?? null;
        return $e;
    }

    public function to_array(): array {
        return [
            'id'               => $this->id,
            'payment_id'       => $this->payment_id,
            'booking_id'       => $this->booking_id,
            'gateway'          => $this->gateway,
            'amount_minor'     => $this->amount_minor,
            'currency'         => $this->currency,
            'status'           => $this->status,
            'gateway_ref'      => $this->gateway_ref,
            'gateway_response' => $this->gateway_response,
            'ip_address'       => $this->ip_address,
            'initiated_at'     => $this->initiated_at,
            'resolved_at'      => $this->resolved_at,
        ];
    }
}
