<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Booking\Entity;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa una reserva.
 */
class Booking_Entity {

    public ?int $id = null;
    public int $service_id = 0;
    public ?int $resource_id = null;
    public int $customer_id = 0;
    public string $status = 'pending';
    public string $payment_status = 'pending';
    public string $start_at = '';
    public string $end_at = '';
    public string $timezone = 'UTC';
    public int $price_total_minor = 0;
    public int $price_due_now_minor = 0;
    public int $price_paid_minor = 0;
    public string $currency = 'USD';
    public string $source = 'public';
    public ?string $notes_customer = null;
    public ?string $notes_internal = null;
    public ?string $cancel_token = null;
    public ?string $cancel_token_expires_at = null;
    public ?string $reschedule_token = null;
    public ?string $reschedule_token_expires_at = null;
    public ?string $view_token = null;
    public ?string $view_token_expires_at = null;
    public ?string $booking_token = null;
    public ?string $booking_token_expires_at = null;
    public int $token_version = 1;
    public ?string $confirm_token = null;
    public ?string $attendance_confirmed_at = null;
    public int $confirmed_email_sent = 0;
    public int $confirmed_wa_sent = 0;

    public function get_payment_token(): ?string {
        return $this->booking_token;
    }

    public ?string $client_ref = null;
    public ?string $expires_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?string $integration_client_key = null;
    public ?string $integration_request_id = null;
    public ?string $external_id = null;
    public string $created_via = 'core';

    public const STATUS_PENDING             = 'pending';
    public const STATUS_CONFIRMED           = 'confirmed';
    public const STATUS_CANCELLED_BY_CUSTOMER = 'cancelled_by_customer';
    public const STATUS_CANCELLED_BY_ADMIN  = 'cancelled_by_admin';
    public const STATUS_COMPLETED           = 'completed';
    public const STATUS_NO_SHOW             = 'no_show';
    public const STATUS_EXPIRED             = 'expired';

    public const PAYMENT_PENDING       = 'pending';
    public const PAYMENT_AUTHORIZED    = 'authorized';
    public const PAYMENT_PAID          = 'paid';
    public const PAYMENT_PARTIALLY_PAID = 'partially_paid';
    public const PAYMENT_FAILED        = 'failed';
    public const PAYMENT_REFUNDED      = 'refunded';
    public const PAYMENT_EXPIRED       = 'expired';

    public function to_array(): array {
        return [
            'id'                           => $this->id,
            'service_id'                   => $this->service_id,
            'resource_id'                  => $this->resource_id,
            'customer_id'                  => $this->customer_id,
            'status'                       => $this->status,
            'payment_status'               => $this->payment_status,
            'start_at'                     => $this->start_at,
            'end_at'                       => $this->end_at,
            'timezone'                     => $this->timezone,
            'price_total_minor'            => $this->price_total_minor,
            'price_due_now_minor'          => $this->price_due_now_minor,
            'price_paid_minor'             => $this->price_paid_minor,
            'currency'                     => $this->currency,
            'source'                       => $this->source,
            'notes_customer'               => $this->notes_customer,
            'notes_internal'               => $this->notes_internal,
            'cancel_token'                 => $this->cancel_token,
            'cancel_token_expires_at'      => $this->cancel_token_expires_at,
            'reschedule_token'             => $this->reschedule_token,
            'reschedule_token_expires_at'  => $this->reschedule_token_expires_at,
            'view_token'                   => $this->view_token,
            'view_token_expires_at'        => $this->view_token_expires_at,
            'booking_token'                => $this->booking_token,
            'booking_token_expires_at'     => $this->booking_token_expires_at,
            'token_version'                => $this->token_version,
            'confirm_token'                => $this->confirm_token,
            'attendance_confirmed_at'      => $this->attendance_confirmed_at,
            'confirmed_email_sent'         => $this->confirmed_email_sent,
            'confirmed_wa_sent'            => $this->confirmed_wa_sent,
            'client_ref'                   => $this->client_ref,
            'expires_at'                   => $this->expires_at,
            'created_at'                   => $this->created_at,
            'updated_at'                   => $this->updated_at,
            'integration_client_key'       => $this->integration_client_key,
            'integration_request_id'       => $this->integration_request_id,
            'external_id'                  => $this->external_id,
            'created_via'                  => $this->created_via,
        ];
    }

    private const SENSITIVE_FIELDS = [
        'cancel_token', 'cancel_token_expires_at',
        'reschedule_token', 'reschedule_token_expires_at',
        'view_token', 'view_token_expires_at',
        'booking_token', 'booking_token_expires_at',
        'confirm_token', 'notes_internal',
    ];

    public function to_safe_array(): array {
        return array_filter(
            $this->to_array(),
            static fn( string $key ): bool => ! in_array( $key, self::SENSITIVE_FIELDS, true ),
            ARRAY_FILTER_USE_KEY
        );
    }

    public static function from_array( array $data ): self {
        $entity = new self();
        $entity->id                    = isset( $data['id'] ) ? (int) $data['id'] : null;
        $entity->service_id            = (int) ( $data['service_id'] ?? 0 );
        $entity->resource_id           = isset( $data['resource_id'] ) ? (int) $data['resource_id'] : null;
        $entity->customer_id           = (int) ( $data['customer_id'] ?? 0 );
        $entity->status                = $data['status'] ?? 'pending';
        $entity->payment_status        = $data['payment_status'] ?? 'pending';
        $entity->start_at              = $data['start_at'] ?? '';
        $entity->end_at                = $data['end_at'] ?? '';
        $entity->timezone              = $data['timezone'] ?? 'UTC';
        $entity->price_total_minor     = (int) ( $data['price_total_minor'] ?? 0 );
        $entity->price_due_now_minor   = (int) ( $data['price_due_now_minor'] ?? 0 );
        $entity->price_paid_minor      = (int) ( $data['price_paid_minor'] ?? 0 );
        $entity->currency              = $data['currency'] ?? 'USD';
        $entity->source                = $data['source'] ?? 'public';
        $entity->notes_customer        = $data['notes_customer'] ?? null;
        $entity->notes_internal        = $data['notes_internal'] ?? null;
        $entity->cancel_token                  = $data['cancel_token'] ?? null;
        $entity->cancel_token_expires_at       = $data['cancel_token_expires_at'] ?? null;
        $entity->reschedule_token              = $data['reschedule_token'] ?? null;
        $entity->reschedule_token_expires_at   = $data['reschedule_token_expires_at'] ?? null;
        $entity->view_token                    = $data['view_token'] ?? null;
        $entity->view_token_expires_at         = $data['view_token_expires_at'] ?? null;
        $entity->booking_token                 = $data['booking_token'] ?? null;
        $entity->booking_token_expires_at      = $data['booking_token_expires_at'] ?? null;
        $entity->token_version                 = isset( $data['token_version'] ) ? (int) $data['token_version'] : 1;
        $entity->confirm_token                 = $data['confirm_token'] ?? null;
        $entity->attendance_confirmed_at       = $data['attendance_confirmed_at'] ?? null;
        $entity->confirmed_email_sent         = (int) ( $data['confirmed_email_sent'] ?? 0 );
        $entity->confirmed_wa_sent            = (int) ( $data['confirmed_wa_sent'] ?? 0 );
        $entity->client_ref                    = $data['client_ref'] ?? null;
        $entity->expires_at                    = $data['expires_at'] ?? null;
        $entity->created_at                    = $data['created_at'] ?? null;
        $entity->updated_at                    = $data['updated_at'] ?? null;
        $entity->integration_client_key        = $data['integration_client_key'] ?? null;
        $entity->integration_request_id        = $data['integration_request_id'] ?? null;
        $entity->external_id                   = $data['external_id'] ?? null;
        $entity->created_via                   = $data['created_via'] ?? 'core';
        return $entity;
    }

    public function is_active(): bool {
        return in_array( $this->status, [ self::STATUS_PENDING, self::STATUS_CONFIRMED ], true );
    }

}
