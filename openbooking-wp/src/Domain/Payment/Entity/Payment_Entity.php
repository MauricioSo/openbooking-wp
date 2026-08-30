<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Payment\Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa un pago dentro del dominio.
 */
class Payment_Entity {

    public ?int $id = null;
    public int $booking_id = 0;
    public string $gateway = '';
    public ?string $provider_payment_id = null;
    public string $status = 'pending';
    public int $amount_minor = 0;
    public string $currency = 'USD';
    public string $mode = 'full';
    public ?string $raw_payload = null;
    public ?string $paid_at = null;
    public ?string $provider_checkout_id = null;
    public ?string $checkout_url = null;
    public ?string $checkout_expires_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public const STATUS_PENDING        = 'pending';
    public const STATUS_AUTHORIZED     = 'authorized';
    public const STATUS_PAID           = 'paid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_FAILED         = 'failed';
    public const STATUS_REFUNDED       = 'refunded';
    public const STATUS_EXPIRED        = 'expired';
    public const STATUS_DISPUTED       = 'disputed'; // chargeback/dispute initiated by customer

    public function to_array(): array {
        return [
            'id'                  => $this->id,
            'booking_id'          => $this->booking_id,
            'gateway'             => $this->gateway,
            'provider_payment_id' => $this->provider_payment_id,
            'status'              => $this->status,
            'amount_minor'        => $this->amount_minor,
            'currency'            => $this->currency,
            'mode'                => $this->mode,
            'raw_payload'         => $this->raw_payload,
            'paid_at'             => $this->paid_at,
            'provider_checkout_id' => $this->provider_checkout_id,
            'checkout_url'        => $this->checkout_url,
            'checkout_expires_at' => $this->checkout_expires_at,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }

    public static function from_array( array $data ): self {
        $entity = new self();
        $entity->id                  = isset( $data['id'] ) ? (int) $data['id'] : null;
        $entity->booking_id          = (int) ( $data['booking_id'] ?? 0 );
        $entity->gateway             = $data['gateway'] ?? '';
        $entity->provider_payment_id = $data['provider_payment_id'] ?? null;
        $entity->status              = $data['status'] ?? 'pending';
        $entity->amount_minor        = (int) ( $data['amount_minor'] ?? 0 );
        $entity->currency            = $data['currency'] ?? 'USD';
        $entity->mode                = $data['mode'] ?? 'full';
        $entity->raw_payload         = $data['raw_payload'] ?? null;
        $entity->paid_at             = $data['paid_at'] ?? null;
        $entity->provider_checkout_id = $data['provider_checkout_id'] ?? null;
        $entity->checkout_url        = $data['checkout_url'] ?? null;
        $entity->checkout_expires_at = $data['checkout_expires_at'] ?? null;
        $entity->created_at          = $data['created_at'] ?? null;
        $entity->updated_at          = $data['updated_at'] ?? null;
        return $entity;
    }
}
