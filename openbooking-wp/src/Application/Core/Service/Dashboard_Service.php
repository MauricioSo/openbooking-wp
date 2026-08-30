<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Core\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;

use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Customer\Repository\CustomerRepositoryInterface;
use OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Support\Booking_Payloads;
use OpenBooking\Support\Timezone_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ensambla los datos operativos del panel principal.
 */
class Dashboard_Service {


    public function __construct(
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private CustomerRepositoryInterface $customer_repo,
        private NotificationQueueRepositoryInterface $queue_repo,
        private SettingsInterface $settings,
    ) {}

    public function get_dashboard_data(): array {
        $business_now  = $this->get_business_now();
        $today         = $business_now->format( 'Y-m-d' );
        $tomorrow      = $business_now->modify( '+1 day' )->format( 'Y-m-d' );
        $now_datetime  = $business_now->format( 'Y-m-d H:i:s' );

        $today_bookings = $this->enrich_bookings_with_urgency(
            $this->booking_repo->find_all( [
                'date_from' => $now_datetime,
                'date_to'   => $today . ' 23:59:59',
                'status'    => [ 'pending', 'confirmed' ],
                'limit'     => 30,
                'order_by'  => 'start_at',
                'order'     => 'ASC',
            ] )
        );

        $tomorrow_bookings = $this->enrich_bookings_with_urgency(
            $this->booking_repo->find_all( [
                'date_from' => $tomorrow . ' 00:00:00',
                'date_to'   => $tomorrow . ' 23:59:59',
                'status'    => [ 'pending', 'confirmed' ],
                'limit'     => 10,
                'order_by'  => 'start_at',
                'order'     => 'ASC',
            ] )
        );

        $far_future = $business_now->modify( '+6 months' )->format( 'Y-m-d' );
        $upcoming_bookings = $this->enrich_bookings_with_urgency(
            $this->booking_repo->find_all( [
                'date_from' => $now_datetime,
                'date_to'   => $far_future . ' 23:59:59',
                'status'    => [ 'pending', 'confirmed' ],
                'limit'     => 10,
                'order_by'  => 'start_at',
                'order'     => 'ASC',
            ] )
        );

        $since_48h = gmdate( 'Y-m-d H:i:s', time() - 172800 );

        $recent_bookings = $this->enrich_bookings_with_urgency(
            $this->booking_repo->find_all( [
                'created_after' => $since_48h,
                'limit'         => 10,
                'order_by'      => 'id',
                'order'         => 'DESC',
            ] )
        );

        $attention_bookings = $this->enrich_bookings_with_urgency(
            $this->booking_repo->find_all( [
                'status' => 'pending',
                'limit'  => 20,
                'order_by' => 'created_at',
                'order'    => 'DESC',
            ] )
        );

        $queue_pending = $this->queue_repo->count_by_status( 'pending' );
        $queue_dead    = $this->queue_repo->count_by_status( 'dead' );

        $desynced = $this->booking_repo->count_booking_payment_inconsistencies();

        $today_count   = $this->booking_repo->count_for_date( $today );
        $pending_count = $this->booking_repo->count_pending();
        $unpaid_count  = $this->booking_repo->count_unpaid();

        $urgency_items = [];
        $expired_pending = $this->booking_repo->count_expired_pending_bookings();
        if ( $expired_pending > 0 ) {
            $urgency_items[] = [ 'type' => 'expired_pending', 'label' => 'Reservas expiradas sin procesar', 'count' => $expired_pending ];
        }
        $queue_failed = $this->queue_repo->count_by_status( 'failed' );
        if ( $queue_failed > 0 ) {
            $urgency_items[] = [ 'type' => 'failed_notifications', 'label' => 'Notificaciones fallidas', 'count' => $queue_failed ];
        }

        return [
            'stats' => [
                'today_bookings'   => $today_count,
                'pending_bookings' => $pending_count,
                'unpaid_bookings'  => $unpaid_count,
            ],
            'today_count'        => $today_count,
            'today_confirmed'    => $this->booking_repo->count_for_date( $today, [ 'confirmed' ] ),
            'pending_count'      => $pending_count,
            'today_bookings'     => $today_bookings,
            'tomorrow_bookings'  => $tomorrow_bookings,
            'upcoming_bookings'  => $upcoming_bookings,
            'recent_bookings'    => $recent_bookings,
            'attention_required' => array_values( array_filter( $attention_bookings, fn( $b ) => ( $b['urgency'] ?? '' ) === 'high' ) ),
            'urgency'            => $urgency_items,
            'operational' => [
                'queue_pending'  => $queue_pending,
                'queue_dead'     => $queue_dead,
                'desynced_count' => $desynced,
                'cron_alive'     => $this->is_cron_alive(),
            ],
        ];
    }

    private function enrich_bookings_with_urgency( array $bookings ): array {
        if ( empty( $bookings ) ) {
            return [];
        }

        $service_ids  = [];
        $customer_ids = [];
        foreach ( $bookings as $booking ) {
            if ( $booking instanceof \OpenBooking\Domain\Booking\Entity\Booking_Entity ) {
                $service_ids[]  = $booking->service_id;
                $customer_ids[] = $booking->customer_id;
            }
        }

        $services  = $this->service_repo->find_by_ids( array_unique( $service_ids ) );
        $customers = $this->customer_repo->find_by_ids( array_unique( $customer_ids ) );

        return array_map( function ( $booking ) use ( $services, $customers ) {
            return $this->enrich_booking_with_urgency_from_maps( $booking, $services, $customers );
        }, $bookings );
    }

    private function enrich_booking_with_urgency_from_maps( \OpenBooking\Domain\Booking\Entity\Booking_Entity $booking, array $services, array $customers ): array {
        $data     = Booking_Payloads::admin_from_entity( $booking );
        $service  = $services[ $booking->service_id ] ?? null;
        $customer = $customers[ $booking->customer_id ] ?? null;

        $data['service_name']   = $service ? $service->name : '';
        $data['customer_name']  = $customer ? $customer->get_full_name() : '';
        $data['customer_email'] = $customer ? $customer->email : '';
        $data['first_name']     = $customer ? $customer->first_name : '';
        $data['last_name']      = $customer ? ( $customer->last_name ?? '' ) : '';
        $data['email']          = $customer ? $customer->email : '';

        $urgency = 'normal';
        if ( $booking->status === 'pending' && $booking->expires_at && strtotime( $booking->expires_at ) < time() ) {
            $urgency = 'high';
            $data['urgency_reason'] = 'payment_window_expired';
        } elseif ( $booking->status === 'confirmed' && $booking->start_at ) {
            $starts_in = strtotime( $booking->start_at ) - time();
            if ( $starts_in >= 0 && $starts_in <= 2 * HOUR_IN_SECONDS ) {
                $urgency = 'high';
                $data['urgency_reason'] = 'starting_soon';
            }
        }

        $data['urgency'] = $urgency;
        $data['time_until_start'] = $booking->start_at ? max( 0, strtotime( $booking->start_at ) - time() ) : null;
        return $data;
    }

    private function is_cron_alive(): bool {
        $last = $this->settings->get( Option_Keys::CRON_HEARTBEAT_LAST, null );
        if ( ! $last ) {
            return false;
        }
        return ( time() - strtotime( $last ) ) < 600;
    }

    private function get_business_now(): \DateTimeImmutable {
        $tz_name = $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        return Timezone_Helper::get_business_now( is_string( $tz_name ) && $tz_name !== '' ? $tz_name : 'UTC' );
    }
}
