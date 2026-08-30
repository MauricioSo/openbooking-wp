<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Convierte entidades y arrays de reserva en payloads de salida.
 */
final class Booking_Payloads {

    public static function public_from_entity( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        return [
            'id'                  => $booking->id,
            'service_id'          => $booking->service_id,
            'resource_id'         => $booking->resource_id,
            'customer_id'         => $booking->customer_id,
            'status'              => $booking->status,
            'payment_status'      => $booking->payment_status,
            'start_at'            => $booking->start_at,
            'end_at'              => $booking->end_at,
            'timezone'            => $booking->timezone,
            'price_total_minor'   => $booking->price_total_minor,
            'price_due_now_minor' => $booking->price_due_now_minor,
            'price_paid_minor'    => $booking->price_paid_minor,
            'currency'            => $booking->currency,
            'source'              => $booking->source,
            'notes_customer'      => $booking->notes_customer,
            'client_ref'          => $booking->client_ref,
            'external_id'         => $booking->external_id,
            'created_via'         => $booking->created_via,
            'expires_at'          => $booking->expires_at,
            'created_at'          => $booking->created_at,
            'updated_at'          => $booking->updated_at,
        ];
    }

    public static function admin_from_entity( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking ): array {
        return self::public_from_entity( $booking ) + [
            'notes_internal'          => $booking->notes_internal,
            'attendance_confirmed_at' => $booking->attendance_confirmed_at,
            'confirmed_email_sent'    => $booking->confirmed_email_sent,
            'confirmed_wa_sent'       => $booking->confirmed_wa_sent,
        ];
    }

    public static function public_from_array( array $booking ): array {
        return [
            'booking_id'          => (int) ( $booking['id'] ?? 0 ),
            'status'              => $booking['status'] ?? '',
            'payment_status'      => $booking['payment_status'] ?? '',
            'service_id'          => (int) ( $booking['service_id'] ?? 0 ),
            'service_name'        => $booking['service_name'] ?? '',
            'resource_id'         => isset( $booking['resource_id'] ) ? (int) $booking['resource_id'] : null,
            'customer_id'         => (int) ( $booking['customer_id'] ?? 0 ),
            'customer_name'       => trim( ( $booking['first_name'] ?? '' ) . ' ' . ( $booking['last_name'] ?? '' ) ),
            'customer_email'      => $booking['email'] ?? '',
            'customer_phone'      => $booking['phone'] ?? '',
            'start_at'            => $booking['start_at'] ?? '',
            'end_at'              => $booking['end_at'] ?? '',
            'timezone'            => $booking['timezone'] ?? 'UTC',
            'currency'            => $booking['currency'] ?? '',
            'price_total_minor'   => (int) ( $booking['price_total_minor'] ?? 0 ),
            'price_due_now_minor' => (int) ( $booking['price_due_now_minor'] ?? 0 ),
            'price_paid_minor'    => (int) ( $booking['price_paid_minor'] ?? 0 ),
            'source'              => $booking['source'] ?? '',
            'client_ref'          => $booking['client_ref'] ?? null,
        ];
    }
}
