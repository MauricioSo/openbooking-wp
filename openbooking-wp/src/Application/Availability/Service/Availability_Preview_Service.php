<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Availability\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;
use OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Availability_Preview_Service {


    public function __construct(
        private AvailabilityConfigRepositoryInterface $availability_repo,
        private BookingRepositoryInterface $booking_repo,
        private ServiceRepositoryInterface $service_repo,
        private ResourceRepositoryInterface $resource_repo,
        private SettingsInterface $settings,
    ) {}

    public function generate_preview( array $proposed_rules, array $proposed_blocks, int $service_id, string $mode = 'week' ): array {
        $service = $this->service_repo->find( $service_id );
        if ( ! $service ) {
            return [ 'error' => 'Servicio no encontrado.' ];
        }

        $rules  = $this->hydrate_rules( $proposed_rules );
        $blocks = $proposed_blocks;

        $tz = (string) $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        try {
            $dt_now = new \DateTime( 'now', new \DateTimeZone( $tz ) );
        } catch ( \Exception $e ) {
            $dt_now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        }

        if ( $mode === 'week' ) {
            $dow = (int) $dt_now->format( 'N' );
            $monday = clone $dt_now;
            $monday->modify( '-' . ( $dow - 1 ) . ' days' );
            $start = $monday->format( 'Y-m-d' );
            $end_dt = clone $monday;
            $end_dt->modify( '+6 days' );
            $end = $end_dt->format( 'Y-m-d' );
        } else {
            $start = $dt_now->format( 'Y-m-01' );
            $end_dt = clone $dt_now;
            $end_dt->modify( 'last day of this month' );
            $end = $end_dt->format( 'Y-m-d' );
        }

        $resources = $this->resource_repo->find_by_service( $service_id );
        if ( empty( $resources ) ) {
            $resources = [ null ];
        }

        $booked_by_date = $this->booking_repo->get_booked_slots_grouped_by_date(
            $service_id,
            $start . ' 00:00:00',
            date( 'Y-m-d', strtotime( $end . ' +1 day' ) ) . ' 00:00:00'
        );

        $preview = [];
        $current = new \DateTime( $start );
        $end_dt_obj = new \DateTime( $end );

        while ( $current <= $end_dt_obj ) {
            $date_str   = $current->format( 'Y-m-d' );
            $day_booked = $booked_by_date[ $date_str ] ?? [];

            $day_blocks = array_values( array_filter( $blocks, function ( $b ) use ( $date_str ) {
                $b_start = isset( $b['start_at'] ) ? substr( $b['start_at'], 0, 10 ) : ( $b['date_from'] ?? '' );
                $b_end   = isset( $b['end_at'] ) ? substr( $b['end_at'], 0, 10 ) : ( $b['date_to'] ?? $b_start );
                return $b_start <= $date_str && $b_end >= $date_str;
            } ) );

            $day_slots     = [];
            $has_available = false;

            foreach ( $resources as $res ) {
                $res_id  = $res ? $res->id : null;
                $res_cap = $res ? $res->capacity : $service->capacity;

                $weekly  = $this->filter_weekly( $rules, $date_str );
                $breaks  = $this->filter_breaks( $rules, $date_str );
                $exc     = $this->filter_exceptions( $rules, $date_str );

                $closed = false;
                foreach ( $exc as $ex ) {
                    if ( $ex->rule_type === 'date_exception' && $ex->meta ) {
                        $meta = is_string( $ex->meta ) ? json_decode( $ex->meta, true ) : $ex->meta;
                        if ( isset( $meta['closed'] ) && $meta['closed'] ) {
                            $closed = true;
                        }
                    }
                }

                if ( empty( $weekly ) || $closed ) {
                    if ( $closed ) {
                        $day_slots = [];
                    }
                    continue;
                }

                $duration     = $service->duration_minutes;
                $buffer_after = (int) ( $service->buffer_after_minutes ?? 0 );
                $buffer_before = (int) ( $service->buffer_before_minutes ?? 0 );
                $step_seconds = ( $duration + $buffer_after + $buffer_before ) * 60;

                foreach ( $weekly as $rule ) {
                    $time_start = strtotime( $date_str . ' ' . $rule->time_from );
                    $time_end   = strtotime( $date_str . ' ' . $rule->time_to );
                    $slot_time  = $time_start + ( $buffer_before * 60 );

                    while ( $slot_time + ( $duration * 60 ) <= $time_end ) {
                        $slot_start    = date( 'Y-m-d H:i:s', $slot_time );
                        $slot_end_time = $slot_time + ( $duration * 60 );
                        $slot_end_str  = date( 'Y-m-d H:i:s', $slot_end_time );

                        $in_break  = $this->check_overlap( $slot_start, $slot_end_str, $breaks, $date_str );
                        $in_block  = $this->check_block_overlap( $slot_start, $slot_end_str, $day_blocks );

                        if ( ! $in_break && ! $in_block ) {
                            $cap = $res_cap;
                            if ( $rule->capacity !== null ) {
                                $cap = $rule->capacity;
                            }
                            $day_slots[] = [
                                'start_at'           => $slot_start,
                                'end_at'             => $slot_end_str,
                                'available_capacity' => $cap,
                                'total_capacity'     => $cap,
                                'resource_id'        => $res_id,
                            ];
                            $has_available = true;
                        }

                        $slot_time += $step_seconds;
                    }
                }
            }

            $day_info = [
                'date'          => $date_str,
                'weekday'       => (int) $current->format( 'N' ),
                'has_available'  => $has_available,
                'slot_count'    => count( $day_slots ),
                'slots'         => $day_slots,
            ];

            $preview[] = $day_info;
            $current->modify( '+1 day' );
        }

        return [
            'service_id' => $service_id,
            'mode'       => $mode,
            'start'      => $start,
            'end'        => $end,
            'days'       => $preview,
        ];
    }

    public function detect_conflicts( array $proposed_rules, array $proposed_blocks, int $service_id, string $scope_type, ?int $scope_id ): array {
        $conflicts = [];

        $existing_bookings = $this->booking_repo->find_all( [
            'service_id' => $service_id,
            'status'     => [ 'pending', 'confirmed' ],
            'limit'      => 500,
        ] );

        if ( empty( $existing_bookings ) ) {
            return $conflicts;
        }

        $tz = (string) $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );
        try {
            $dt_now = new \DateTime( 'now', new \DateTimeZone( $tz ) );
        } catch ( \Exception $e ) {
            $dt_now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        }

        $next_month_end = clone $dt_now;
        $next_month_end->modify( '+60 days' );
        $rules = $this->hydrate_rules( $proposed_rules );

        foreach ( $existing_bookings as $booking ) {
            $b_start = strtotime( $booking->start_at );
            $b_end   = strtotime( $booking->end_at );
            $b_date  = substr( $booking->start_at, 0, 10 );

            if ( $b_start < $dt_now->getTimestamp() ) {
                continue;
            }
            if ( $b_start > $next_month_end->getTimestamp() ) {
                continue;
            }

            $dow = (int) date( 'N', $b_start );
            $weekly_for_day = array_filter( $rules, function ( $r ) use ( $dow ) {
                return $r->rule_type === 'weekly' && (int) $r->weekday === $dow
                    && ! empty( $r->time_from ) && ! empty( $r->time_to );
            } );

            $is_covered = false;
            foreach ( $weekly_for_day as $rule ) {
                $r_start = strtotime( $b_date . ' ' . $rule->time_from );
                $r_end   = strtotime( $b_date . ' ' . $rule->time_to );
                if ( $b_start >= $r_start && $b_end <= $r_end ) {
                    $is_covered = true;
                    break;
                }
            }

            if ( ! $is_covered ) {
                $conflicts[] = [
                    'booking_id'   => $booking->id,
                    'start_at'     => $booking->start_at,
                    'customer'     => $booking->customer_id,
                    'reason'       => 'booking_outside_proposed_hours',
                    'message'      => 'Reserva #' . $booking->id . ' (' . $booking->start_at . ') queda fuera del horario propuesto.',
                ];
            }
        }

        return $conflicts;
    }

    private function hydrate_rules( array $proposed_rules ): array {
        return array_map( function ( $data ) {
            return \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity::from_array( $data );
        }, $proposed_rules );
    }

    private function filter_weekly( array $rules, string $date ): array {
        $dow = (int) date( 'N', strtotime( $date ) );
        return array_values( array_filter( $rules, function ( $r ) use ( $dow ) {
            return $r->rule_type === 'weekly' && (int) $r->weekday === $dow
                && ! empty( $r->time_from ) && ! empty( $r->time_to );
        } ) );
    }

    private function filter_breaks( array $rules, string $date ): array {
        $dow = (int) date( 'N', strtotime( $date ) );
        return array_values( array_filter( $rules, function ( $r ) use ( $dow ) {
            return $r->rule_type === 'break' && ( $r->weekday === null || (int) $r->weekday === $dow )
                && ! empty( $r->time_from ) && ! empty( $r->time_to );
        } ) );
    }

    private function filter_exceptions( array $rules, string $date ): array {
        return array_values( array_filter( $rules, function ( $r ) use ( $date ) {
            if ( $r->rule_type !== 'date_exception' && $r->rule_type !== 'holiday' ) {
                return false;
            }
            $from = $r->date_from;
            $to   = $r->date_to ?: $from;
            return $date >= $from && $date <= $to;
        } ) );
    }

    private function check_overlap( string $start, string $end, array $breaks, string $date ): bool {
        foreach ( $breaks as $break ) {
            $b_start = strtotime( $date . ' ' . $break->time_from );
            $b_end   = strtotime( $date . ' ' . $break->time_to );
            $s       = strtotime( $start );
            $e       = strtotime( $end );
            if ( $s < $b_end && $e > $b_start ) {
                return true;
            }
        }
        return false;
    }

    private function check_block_overlap( string $start, string $end, array $blocks ): bool {
        foreach ( $blocks as $block ) {
            $b_start_str = $block['start_at'] ?? ( $block['date_from'] ?? '' );
            $b_end_str   = $block['end_at'] ?? ( $block['date_to'] ?? $b_start_str );
            if ( $b_start_str && $b_end_str ) {
                $b_start = strtotime( $b_start_str );
                $b_end   = strtotime( $b_end_str );
                $s       = strtotime( $start );
                $e       = strtotime( $end );
                if ( $s < $b_end && $e > $b_start ) {
                    return true;
                }
            }
        }
        return false;
    }
}
