<?php


declare( strict_types=1 );
namespace OpenBooking\Presentation\Rest\Booking;

use OpenBooking\Domain\Booking\Entity\Booking_Entity;
use OpenBooking\Domain\Catalog\Entity\Service_Entity;
use OpenBooking\Domain\Customer\Entity\Customer_Entity;
use OpenBooking\Support\Booking_Payloads;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transforma datos entre capas del bounded context de reservas.
 */

final class Booking_Response_Mapper {

    public static function public_create(
        Booking_Entity $booking,
        ?Service_Entity $service = null,
        ?Customer_Entity $customer = null,
        bool $duplicate = false,
        array $payment = []
    ): array {
        $response = [
            'success'    => true,
            'booking_id' => $booking->id,
            'duplicate'  => $duplicate,
            'booking'    => self::public_booking_payload( $booking, $service, $customer, true ),
        ];

        if ( ! empty( $payment ) ) {
            $response['payment'] = $payment;
        }

        return $response;
    }

    public static function public_status(
        Booking_Entity $booking,
        ?Service_Entity $service = null,
        bool $can_cancel = false,
        bool $can_reschedule = false,
        ?string $cancel_deadline = null
    ): array {
        return [
            'success'         => true,
            'status'          => $booking->status,
            'start_at'        => $booking->start_at,
            'end_at'          => $booking->end_at,
            'timezone'        => $booking->timezone,
            'service_name'    => $service ? $service->name : '',
            'can_cancel'      => $can_cancel,
            'can_reschedule'  => $can_reschedule,
            'cancel_deadline' => $cancel_deadline,
        ];
    }

    public static function admin_booking(
        Booking_Entity $booking,
        ?Service_Entity $service = null,
        ?Customer_Entity $customer = null
    ): array {
        $data = Booking_Payloads::admin_from_entity( $booking );
        $data['service_name']   = $service ? $service->name : '';
        $data['customer_name']  = $customer ? $customer->get_full_name() : '';
        $data['customer_email'] = $customer ? $customer->email : '';
        return $data;
    }

    public static function admin_list(
        array $bookings,
        array $service_map = [],
        array $customer_map = []
    ): array {
        return array_map( function ( Booking_Entity $booking ) use ( $service_map, $customer_map ) {
            $service  = $service_map[ $booking->service_id ] ?? null;
            $customer = $customer_map[ $booking->customer_id ] ?? null;
            return self::admin_booking( $booking, $service, $customer );
        }, $bookings );
    }

    public static function integration_booking(
        Booking_Entity $booking,
        ?Service_Entity $service = null,
        ?Customer_Entity $customer = null
    ): array {
        $payload = Booking_Payloads::public_from_entity( $booking );
        unset(
            $payload['cancel_token'],
            $payload['reschedule_token'],
            $payload['view_token'],
            $payload['booking_token'],
            $payload['confirm_token'],
            $payload['token_version'],
            $payload['cancel_token_expires_at'],
            $payload['integration_client_key']
        );

        $payload['customer'] = $customer
            ? [
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name ?? '',
                'email'      => $customer->email,
                'phone'      => $customer->phone ?? '',
            ]
            : null;

        return $payload;
    }

    public static function integration_create(
        string $request_id,
        Booking_Entity $booking,
        ?Service_Entity $service = null,
        ?Customer_Entity $customer = null,
        bool $duplicate = false,
        array $create_result = []
    ): array {
        $response = [
            'success'     => true,
            'request_id'  => $request_id,
            'booking_id'  => $booking->id,
            'external_id' => $booking->external_id,
            'duplicate'   => $duplicate,
            'booking'     => self::integration_booking( $booking, $service, $customer ),
        ];

        if ( $service ) {
            $response['service'] = \OpenBooking\Support\Service_Payloads::public_from_entity( $service );
        }
        if ( $customer ) {
            $response['customer'] = [
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name ?? '',
                'email'      => $customer->email,
            ];
        }

        if ( ! $duplicate && ! empty( $create_result['payment'] ) ) {
            $response['payment'] = $create_result['payment'];
        } elseif ( $duplicate && $booking->price_due_now_minor > 0 ) {
            $response['payment'] = [
                'required'        => true,
                'token'           => $booking->get_payment_token(),
                'amount_minor'    => $booking->price_due_now_minor,
                'amount_currency' => $booking->currency,
            ];
        }

        return $response;
    }

    private static function public_booking_payload(
        Booking_Entity $booking,
        ?Service_Entity $service,
        ?Customer_Entity $customer,
        bool $include_tokens = false
    ): array {
        $payload = [
            'id'                => $booking->id,
            'status'            => $booking->status,
            'service_name'      => $service ? $service->name : '',
            'start_at'          => $booking->start_at,
            'end_at'            => $booking->end_at,
            'timezone'          => $booking->timezone,
            'price_total'       => $booking->price_total_minor,
            'currency'          => $booking->currency,
            'customer'          => [
                'first_name' => $customer ? $customer->first_name : '',
                'email'      => $customer ? $customer->email : '',
            ],
        ];

        if ( $include_tokens ) {
            $payload['cancel_token']      = $booking->cancel_token;
            $payload['reschedule_token']  = $booking->reschedule_token;
            $payload['view_token']        = $booking->view_token;
        }

        return $payload;
    }
}
