<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Privacy;

use OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository;
use OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository;
use OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository;
use OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository;
use OpenBooking\Infrastructure\Persistence\Payment\Payment_Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exporta y borra datos personales relacionados con reservas.
 */
class Customer_Privacy_Handler implements \OpenBooking\Domain\Shared\Port\PrivacyHandlerInterface {

    public function __construct(
        private Customer_Repository $customer_repo,
        private Booking_Repository $booking_repo,
        private Service_Repository $service_repo,
        private Payment_Repository $payment_repo,
        private Consent_Log_Repository $consent_repo,
    ) {}

    public function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
    }

    public function register_exporter( array $exporters ): array {
        $exporters['openbooking-wp'] = [
            'exporter_friendly_name' => __( 'OpenBooking WP', 'openbooking-wp' ),
            'callback'               => [ $this, 'export_personal_data' ],
        ];

        return $exporters;
    }

    public function register_eraser( array $erasers ): array {
        $erasers['openbooking-wp'] = [
            'eraser_friendly_name' => __( 'OpenBooking WP', 'openbooking-wp' ),
            'callback'             => [ $this, 'erase_personal_data' ],
        ];

        return $erasers;
    }

    public function export_personal_data( string $email_address, int $page = 1 ): array {
        $customer = $this->customer_repo->find_by_email( sanitize_email( $email_address ) );

        if ( ! $customer ) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $page     = max( 1, absint( $page ) );
        $per_page = 20;
        $data     = [];

        if ( 1 === $page ) {
            $data[] = [
                'group_id'    => 'openbooking-customer',
                'group_label' => __( 'OpenBooking Customer', 'openbooking-wp' ),
                'item_id'     => 'openbooking-customer-' . $customer->id,
                'data'        => [
                    [ 'name' => __( 'First name', 'openbooking-wp' ), 'value' => $customer->first_name ],
                    [ 'name' => __( 'Last name', 'openbooking-wp' ), 'value' => (string) $customer->last_name ],
                    [ 'name' => __( 'Email', 'openbooking-wp' ), 'value' => $customer->email ],
                    [ 'name' => __( 'Phone', 'openbooking-wp' ), 'value' => (string) $customer->phone ],
                    [ 'name' => __( 'Notes', 'openbooking-wp' ), 'value' => (string) $customer->notes ],
                ],
            ];
        }

        $bookings = $this->booking_repo->find_all( [
            'customer_id' => $customer->id,
            'order_by'    => 'start_at',
            'order'       => 'ASC',
            'limit'       => $per_page,
            'offset'      => ( $page - 1 ) * $per_page,
        ] );

        foreach ( $bookings as $booking ) {
            $service = $this->service_repo->find( $booking->service_id );
            $data[]  = [
                'group_id'    => 'openbooking-bookings',
                'group_label' => __( 'OpenBooking Bookings', 'openbooking-wp' ),
                'item_id'     => 'openbooking-booking-' . $booking->id,
                'data'        => [
                    [ 'name' => __( 'Booking ID', 'openbooking-wp' ), 'value' => (string) $booking->id ],
                    [ 'name' => __( 'Service', 'openbooking-wp' ), 'value' => $service ? $service->name : '' ],
                    [ 'name' => __( 'Status', 'openbooking-wp' ), 'value' => $booking->status ],
                    [ 'name' => __( 'Payment status', 'openbooking-wp' ), 'value' => $booking->payment_status ],
                    [ 'name' => __( 'Start time', 'openbooking-wp' ), 'value' => $booking->start_at ],
                    [ 'name' => __( 'End time', 'openbooking-wp' ), 'value' => $booking->end_at ],
                    [ 'name' => __( 'Customer notes', 'openbooking-wp' ), 'value' => (string) $booking->notes_customer ],
                ],
            ];
        }

        return [
            'data' => $data,
            'done' => count( $bookings ) < $per_page,
        ];
    }

    public function export_json_portable( string $email_address ): array {
        $customer = $this->customer_repo->find_by_email( sanitize_email( $email_address ) );

        if ( ! $customer ) {
            return [ 'error' => 'Customer not found.', 'code' => 404 ];
        }

        $export = [
            'export_format' => 'openbooking-gdpr-portable-v1',
            'exported_at'   => current_time( 'mysql', true ),
            'customer'      => [
                'first_name' => $customer->first_name,
                'last_name'  => (string) $customer->last_name,
                'email'      => $customer->email,
                'phone'      => (string) $customer->phone,
            ],
            'bookings'    => [],
            'consent_log' => [],
        ];

        $bookings = $this->find_all_customer_bookings( (int) $customer->id, 500 );

        foreach ( $bookings as $booking ) {
            $service = $this->service_repo->find( $booking->service_id );
            $entry   = [
                'id'             => $booking->id,
                'service'        => $service ? $service->name : null,
                'status'         => $booking->status,
                'payment_status' => $booking->payment_status,
                'start_at'       => $booking->start_at,
                'end_at'         => $booking->end_at,
                'timezone'       => $booking->timezone,
                'currency'       => $booking->currency,
                'notes_customer' => (string) $booking->notes_customer,
                'created_at'     => $booking->created_at,
            ];

            $payments = $this->payment_repo->find_all( [ 'booking_id' => $booking->id ] );
            if ( ! empty( $payments ) ) {
                $entry['payments'] = array_map( static function ( $p ) {
                    return [
                        'id'       => $p->id,
                        'status'   => $p->status,
                        'amount'   => $p->amount_minor,
                        'currency' => $p->currency,
                        'gateway'  => $p->gateway,
                    ];
                }, $payments );
            }

            $export['bookings'][] = $entry;
        }

        $export['consent_log'] = $this->consent_repo->find_by_customer( $customer->id );

        return $export;
    }

    public function erase_personal_data( string $email_address, int $page = 1 ): array {
        $customer = $this->customer_repo->find_by_email( sanitize_email( $email_address ) );

        if ( ! $customer ) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $removed  = false;

        $bookings = $this->find_all_customer_bookings( (int) $customer->id, 200 );

        foreach ( $bookings as $booking ) {
            if ( null === $booking->notes_customer || '' === $booking->notes_customer ) {
                continue;
            }

            $booking->notes_customer = null;
            $this->booking_repo->update( $booking );
            $removed = true;
        }

        $customer->first_name = __( 'Deleted customer', 'openbooking-wp' );
        $customer->last_name  = null;
        $customer->email      = 'anon+' . $customer->id . '@example.invalid';
        $customer->phone      = null;
        $customer->notes      = null;
        $this->customer_repo->update( $customer );
        $removed = true;

        return [
            'items_removed'  => $removed,
            'items_retained' => true,
            'messages'       => [ __( 'OpenBooking customer identity was anonymized while preserving booking records for operational history.', 'openbooking-wp' ) ],
            'done'           => true,
        ];
    }

    private function find_all_customer_bookings( int $customer_id, int $page_size ): array {
        $all = [];
        $offset = 0;

        do {
            $batch = $this->booking_repo->find_all( [
                'customer_id' => $customer_id,
                'order_by'    => 'start_at',
                'order'       => 'ASC',
                'limit'       => $page_size,
                'offset'      => $offset,
            ] );

            $all = array_merge( $all, $batch );
            $offset += $page_size;
        } while ( count( $batch ) === $page_size );

        return $all;
    }
}
