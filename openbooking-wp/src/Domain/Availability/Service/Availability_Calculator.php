<?php


declare( strict_types=1 );
namespace OpenBooking\Domain\Availability\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use OpenBooking\Domain\Catalog\Entity\Service_Entity;

/**
 * Ejecuta calculos del dominio de disponibilidad.
 */

class Availability_Calculator {

    public function generate_slots_for_resource(
        Service_Entity $service,
        ?int $resource_id,
        int $capacity,
        string $date,
        array $rules,
        array $blocks,
        \DateTimeZone $timezone
    ): array {
        $weekly_rules = $this->get_weekly_rules( $rules, $date );
        if ( empty( $weekly_rules ) ) {
            return [];
        }

        $break_rules     = $this->get_break_rules( $rules, $date );
        $exception_rules = $this->get_date_exceptions( $rules, $date );

        if ( ! empty( $exception_rules ) ) {
            foreach ( $exception_rules as $exc ) {
                if ( $exc->rule_type === 'date_exception' && $exc->meta ) {
                    $meta = is_string( $exc->meta ) ? json_decode( $exc->meta, true ) : $exc->meta;
                    if ( isset( $meta['closed'] ) && $meta['closed'] ) {
                        return [];
                    }
                }
            }
        }

        $slots = [];
        $duration      = $service->duration_minutes;
        $buffer_after  = (int) ( $service->buffer_after_minutes ?? 0 );
        $buffer_before = (int) ( $service->buffer_before_minutes ?? 0 );
        $step_seconds  = ( $duration + $buffer_after + $buffer_before ) * 60;

        $seen_slots = [];

        foreach ( $weekly_rules as $rule ) {
            $start_dt = $this->parse_business_datetime( $date . ' ' . $rule->time_from, $timezone );
            $end_dt   = $this->parse_business_datetime( $date . ' ' . $rule->time_to, $timezone );

            if ( ! $start_dt || ! $end_dt || $end_dt <= $start_dt ) {
                continue;
            }

            $time_start = $start_dt->getTimestamp();
            $time_end   = $end_dt->getTimestamp();
            $slot_time   = $time_start + ( $buffer_before * 60 );

            while ( $slot_time + ( $duration * 60 ) <= $time_end ) {
                $slot_start_dt = ( new \DateTimeImmutable( '@' . $slot_time ) )->setTimezone( $timezone );
                $slot_start    = $slot_start_dt->format( 'Y-m-d H:i:s' );
                $slot_end_time  = $slot_time + ( $duration * 60 );
                $slot_end_dt    = ( new \DateTimeImmutable( '@' . $slot_end_time ) )->setTimezone( $timezone );
                $slot_end       = $slot_end_dt->format( 'Y-m-d H:i:s' );
                $slot_key       = ( $resource_id ?? 0 ) . '|' . $slot_start . '|' . $slot_end;

                if (
                    ! isset( $seen_slots[ $slot_key ] ) &&
                    $slot_start_dt->format( 'Y-m-d' ) === $date &&
                    $slot_end_dt->format( 'Y-m-d' ) === $date &&
                    $slot_end > $slot_start &&
                    ! $this->is_in_break( $slot_start, $slot_end, $break_rules, $timezone ) &&
                    ! $this->is_blocked( $slot_start, $slot_end, $blocks, $timezone )
                ) {
                    $slot_capacity = $capacity;
                    if ( $rule->capacity !== null ) {
                        $slot_capacity = $rule->capacity;
                    }

                    $slots[] = [
                        'start_at'           => $slot_start,
                        'end_at'             => $slot_end,
                        'available_capacity' => $slot_capacity,
                        'total_capacity'     => $slot_capacity,
                        'resource_id'        => $resource_id,
                    ];
                    $seen_slots[ $slot_key ] = true;
                }

                $slot_time += $step_seconds;
            }
        }

        return $slots;
    }

    public function apply_booked_capacity_from_map( array $slots, array $day_locks ): array {
        if ( empty( $day_locks ) && empty( $slots ) ) {
            return $slots;
        }

        $locks_map = [];
        $wildcard  = [];

        foreach ( $day_locks as $lock ) {
            $time_key = $lock['slot_start'] . '|' . $lock['slot_end'];
            $count    = (int) $lock['lock_count'];
            $res_key  = (int) $lock['resource_key'];
            if ( $res_key === 0 ) {
                $wildcard[ $time_key ] = ( $wildcard[ $time_key ] ?? 0 ) + $count;
            } else {
                $slot_key               = $time_key . '|' . $res_key;
                $locks_map[ $slot_key ] = ( $locks_map[ $slot_key ] ?? 0 ) + $count;
            }
        }

        foreach ( $slots as $i => $slot ) {
            $time_key = $slot['start_at'] . '|' . $slot['end_at'];
            $lock_ct  = $wildcard[ $time_key ] ?? 0;
            if ( $slot['resource_id'] !== null ) {
                $slot_key  = $time_key . '|' . $slot['resource_id'];
                $lock_ct   += $locks_map[ $slot_key ] ?? 0;
            } else {
                foreach ( $locks_map as $key => $cnt ) {
                    if ( strncmp( $key, $time_key . '|', strlen( $time_key ) + 1 ) === 0 ) {
                        $lock_ct += $cnt;
                    }
                }
            }
            $slots[ $i ]['available_capacity'] = max( 0, $slot['available_capacity'] - $lock_ct );
        }

        return array_values( array_filter( $slots, fn( $s ) => $s['available_capacity'] > 0 ) );
    }

    public function merge_slots_by_time( array $slots ): array {
        $merged = [];
        foreach ( $slots as $slot ) {
            $key = $slot['start_at'] . '|' . $slot['end_at'];
            if ( isset( $merged[ $key ] ) ) {
                $merged[ $key ]['available_capacity'] += $slot['available_capacity'];
                $merged[ $key ]['total_capacity']     += $slot['total_capacity'];
                $merged[ $key ]['resource_id'] = null;
            } else {
                $merged[ $key ] = $slot;
            }
        }
        return array_values( $merged );
    }

    public function is_in_break( string $start, string $end, array $breaks, \DateTimeZone $timezone ): bool {
        $s_dt     = $this->parse_business_datetime( $start, $timezone );
        $e_dt     = $this->parse_business_datetime( $end, $timezone );

        if ( ! $s_dt || ! $e_dt ) {
            return true;
        }

        $s = $s_dt->getTimestamp();
        $e = $e_dt->getTimestamp();

        foreach ( $breaks as $break ) {
            $date       = substr( $start, 0, 10 );
            $b_start_dt = $this->parse_business_datetime( $date . ' ' . $break->time_from, $timezone );
            $b_end_dt   = $this->parse_business_datetime( $date . ' ' . $break->time_to, $timezone );

            if ( ! $b_start_dt || ! $b_end_dt ) {
                continue;
            }

            $b_start = $b_start_dt->getTimestamp();
            $b_end   = $b_end_dt->getTimestamp();

            if ( $s < $b_end && $e > $b_start ) {
                return true;
            }
        }

        return false;
    }

    public function is_blocked( string $start, string $end, array $blocks, \DateTimeZone $timezone ): bool {
        $s_dt     = $this->parse_business_datetime( $start, $timezone );
        $e_dt     = $this->parse_business_datetime( $end, $timezone );

        if ( ! $s_dt || ! $e_dt ) {
            return true;
        }

        $s = $s_dt->getTimestamp();
        $e = $e_dt->getTimestamp();

        foreach ( $blocks as $block ) {
            $b_start_dt = $this->parse_business_datetime( $block['start_at'], $timezone );
            $b_end_dt   = $this->parse_business_datetime( $block['end_at'], $timezone );

            if ( ! $b_start_dt || ! $b_end_dt ) {
                continue;
            }

            $b_start = $b_start_dt->getTimestamp();
            $b_end   = $b_end_dt->getTimestamp();

            if ( $s < $b_end && $e > $b_start ) {
                return true;
            }
        }

        return false;
    }

    public function get_weekly_rules( array $rules, string $date ): array {
        $dow = (int) date( 'N', strtotime( $date ) );

        return array_filter( $rules, function ( $rule ) use ( $dow ) {
            return $rule->rule_type === 'weekly' && (int) $rule->weekday === $dow
                && ! empty( $rule->time_from ) && ! empty( $rule->time_to );
        } );
    }

    public function get_break_rules( array $rules, string $date ): array {
        $dow = (int) date( 'N', strtotime( $date ) );

        return array_filter( $rules, function ( $rule ) use ( $dow ) {
            return $rule->rule_type === 'break' && ( $rule->weekday === null || (int) $rule->weekday === $dow )
                && ! empty( $rule->time_from ) && ! empty( $rule->time_to );
        } );
    }

    public function get_date_exceptions( array $rules, string $date ): array {
        return array_filter( $rules, function ( $rule ) use ( $date ) {
            if ( $rule->rule_type !== 'date_exception' && $rule->rule_type !== 'holiday' ) {
                return false;
            }
            $from = $rule->date_from;
            $to   = $rule->date_to ?: $from;
            return $date >= $from && $date <= $to;
        } );
    }

    private function parse_business_datetime( string $datetime, \DateTimeZone $timezone ): ?\DateTimeImmutable {
        $parsed   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );

        if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $datetime ) {
            return null;
        }

        return $parsed;
    }
}
