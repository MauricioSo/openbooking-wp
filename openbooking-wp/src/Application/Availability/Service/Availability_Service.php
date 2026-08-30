<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Availability\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Availability\Service\Availability_Calculator;
use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;
use OpenBooking\Application\Shared\Port\HookDispatcherInterface;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Availability_Service {


    public function __construct(
        private \OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface $service_repo,
        private \OpenBooking\Domain\Catalog\Repository\ResourceRepositoryInterface $resource_repo,
        private AvailabilityConfigRepositoryInterface $availability_repo,
        private Slot_Lock_Service $slot_lock_service,
        private SettingsInterface $settings,
        private HookDispatcherInterface $hooks,
        private Availability_Calculator $calculator,
    ) {
$this->calculator        = $calculator ?? new Availability_Calculator();
    }

    public function get_slots( int $service_id, string $date, ?int $resource_id = null ): array {
        $service = $this->find_active_service( $service_id );
        if ( null === $service ) {
            return [];
        }

        $target_resources = $this->resolve_target_resources( $service_id, $resource_id );

        $rules  = $this->resolve_effective_rules_for_date(
            $this->availability_repo->get_applicable_rules( $service_id, $resource_id ),
            $date
        );
        $blocks = $this->availability_repo->get_applicable_blocks( $service_id, $resource_id, $date . ' 00:00:00', $date . ' 23:59:59' );

        // Vista merged (sin resource_id): incluir también los bloques de cada recurso
        // asignado al servicio, para que una ventana de indisponibilidad de recurso
        // no se ofrezca en la vista agregada del servicio (QA OB500-058/087).
        if ( null === $resource_id ) {
            foreach ( $target_resources as $res ) {
                if ( $res ) {
                    $blocks = array_merge(
                        $blocks,
                        $this->availability_repo->get_applicable_blocks( $service_id, $res->id, $date . ' 00:00:00', $date . ' 23:59:59' )
                    );
                }
            }
        }

        $slots = $this->generate_slots_for_resources( $service, $target_resources, $date, $rules, $blocks );

        $slots = $this->merge_or_dedupe_slots( $slots, count( $target_resources ) > 1 );

        $slots = $this->apply_booked_capacity( $slots, $service_id, $date, $resource_id );
        $slots = $this->apply_advance_limits( $slots, $service );

        $slots = $this->decorate_slots_with_context( $slots );

        return $this->hooks->apply_filters( 'openbooking_available_slots', $slots, $service_id, $date, $resource_id );
    }

    private function find_active_service( int $service_id ): ?\OpenBooking\Domain\Catalog\Entity\Service_Entity {
        $service = $this->service_repo->find( $service_id );

        if ( ! $service || 'active' !== $service->status ) {
            return null;
        }

        return $service;
    }

    private function resolve_target_resources( int $service_id, ?int $resource_id ): array {
        $resources = $this->resource_repo->find_by_service( $service_id );

        if ( empty( $resources ) ) {
            $resources = [ null ];
        }

        if ( null === $resource_id ) {
            return $resources;
        }

        $target_resources = array_filter(
            $resources,
            function ( $resource ) use ( $resource_id ) {
                return $resource && $resource->id === $resource_id;
            }
        );

        return empty( $target_resources ) ? [ null ] : $target_resources;
    }

    private function generate_slots_for_resources(
        \OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        array $resources,
        string $date,
        array $rules,
        array $blocks
    ): array {
        $slots = [];

        foreach ( $resources as $resource ) {
            $resource_id = $resource ? $resource->id : null;
            $capacity    = $resource ? $resource->capacity : $service->capacity;

            $slots = array_merge(
                $slots,
                $this->calculator->generate_slots_for_resource(
                    $service,
                    $resource_id,
                    $capacity,
                    $date,
                    $rules,
                    $blocks,
                    $this->calculator_timezone()
                )
            );
        }

        return $slots;
    }

    private function decorate_slots_with_context( array $slots ): array {
        $tz_label = (string) $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );

        foreach ( $slots as &$slot ) {
            $slot['timezone'] = $tz_label;
            if ( ! isset( $slot['unavailability_reason'] ) ) {
                $slot['unavailability_reason'] = null;
            }
        }
        unset( $slot );

        return array_values( $slots );
    }

    private function apply_booked_capacity( array $slots, int $service_id, string $date, ?int $resource_id ): array {
        $date_from = $date . ' 00:00:00';
        $date_to   = date( 'Y-m-d', strtotime( $date . ' +1 day' ) ) . ' 00:00:00';

        $locks = $this->slot_lock_service->get_locked_slots_for_date( $service_id, $date_from, $date_to, $resource_id );
        $locks_map = [];
        $locks_wildcard = [];

        foreach ( $locks as $lock ) {
            $time_key = $lock['slot_start'] . '|' . $lock['slot_end'];
            $count    = (int) $lock['lock_count'];
            $res_key  = (int) $lock['resource_key'];
            if ( $res_key === 0 ) {
                $locks_wildcard[ $time_key ] = ( $locks_wildcard[ $time_key ] ?? 0 ) + $count;
            } else {
                $locks_map[ $time_key . '|' . $res_key ] = ( $locks_map[ $time_key . '|' . $res_key ] ?? 0 ) + $count;
            }
        }

        foreach ( $slots as $i => $slot ) {
            $time_key = $slot['start_at'] . '|' . $slot['end_at'];
            $lock_ct  = $locks_wildcard[ $time_key ] ?? 0;
            if ( $slot['resource_id'] !== null ) {
                $lock_ct += $locks_map[ $time_key . '|' . $slot['resource_id'] ] ?? 0;
            } else {
                foreach ( $locks_map as $key => $cnt ) {
                    if ( strncmp( $key, $time_key . '|', strlen( $time_key ) + 1 ) === 0 ) {
                        $lock_ct += $cnt;
                    }
                }
            }

            $remaining = max( 0, $slot['available_capacity'] - $lock_ct );
            $slots[ $i ]['available_capacity'] = $remaining;
            if ( $remaining === 0 && $lock_ct > 0 ) {
                $slots[ $i ]['unavailability_reason'] = 'fully_booked';
            }
        }

        return $slots;
    }

    private function apply_advance_limits( array $slots, \OpenBooking\Domain\Catalog\Entity\Service_Entity $service ): array {
        $timezone = $this->calculator_timezone();
        $dt_now   = new \DateTimeImmutable( 'now', $timezone );
        $now_ts   = $dt_now->getTimestamp();

        return array_filter( $slots, function ( $slot ) use ( $now_ts, $timezone ) {
            $slot_start = $this->parse_business_datetime( $slot['start_at'], $timezone );
            return $slot_start && $slot_start->getTimestamp() >= $now_ts;
        } );
    }

    public function get_available_dates( int $service_id, string $month_start, string $month_end ): array {
        $cache_key = $this->build_monthly_cache_key( $service_id, $month_start, $month_end );

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $service = $this->find_active_service( $service_id );
        if ( null === $service ) {
            return $this->cache_and_return( $cache_key, [] );
        }

        $dates = $this->scan_month_for_available_dates(
            $service,
            $service_id,
            $month_start,
            $month_end
        );

        $result = $this->hooks->apply_filters( 'openbooking_available_dates', $dates, $service_id, $month_start, $month_end );

        return $this->cache_and_return( $cache_key, $result );
    }

    private function scan_month_for_available_dates(
        \OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        int $service_id,
        string $month_start,
        string $month_end,
    ): array {
        $rules         = $this->availability_repo->get_applicable_rules( $service_id, null );
        $blocks        = $this->availability_repo->get_applicable_blocks( $service_id, null, $month_start . ' 00:00:00', $month_end . ' 23:59:59' );
        $locks_by_date = $this->fetch_locks_for_month( $service_id, $month_start, $month_end );
        $resources     = $this->resolve_target_resources( $service_id, null );
        $dates         = [];

        $current = new \DateTime( $month_start );
        $end_dt  = new \DateTime( $month_end );

        while ( $current <= $end_dt ) {
            $date_str = $current->format( 'Y-m-d' );
            if ( $this->date_has_availability( $service, $resources, $date_str, $rules, $blocks, $locks_by_date ) ) {
                $dates[] = $date_str;
            }
            $current->modify( '+1 day' );
        }

        return $dates;
    }

    private function date_has_availability(
        \OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        array $resources,
        string $date_str,
        array $rules,
        array $blocks,
        array $locks_by_date,
    ): bool {
        $day_locks  = $locks_by_date[ $date_str ] ?? [];
        $day_blocks = $this->filter_blocks_for_date( $blocks, $date_str );

        foreach ( $resources as $res ) {
            $res_id  = $res ? $res->id : null;
            $res_cap = $res ? $res->capacity : $service->capacity;

            $effective_rules = $this->resolve_effective_rules_for_date( $rules, $date_str );
            $slots = $this->calculator->generate_slots_for_resource(
                $service, $res_id, $res_cap, $date_str, $effective_rules, $day_blocks,
                $this->calculator_timezone()
            );
            $slots = $this->merge_or_dedupe_slots( $slots, false );

            if ( empty( $slots ) ) {
                continue;
            }

            $slots = $this->calculator->apply_booked_capacity_from_map( $slots, $day_locks );
            $slots = $this->apply_advance_limits( $slots, $service );

            if ( ! empty( $slots ) ) {
                return true;
            }
        }

        return false;
    }

    private function filter_blocks_for_date( array $blocks, string $date_str ): array {
        return array_values( array_filter( $blocks, function ( $b ) use ( $date_str ) {
            return substr( $b['start_at'], 0, 10 ) <= $date_str
                && substr( $b['end_at'], 0, 10 ) >= $date_str;
        } ) );
    }

    private function fetch_locks_for_month( int $service_id, string $month_start, string $month_end ): array {
        return $this->slot_lock_service->get_locked_slots_grouped_by_date(
            $service_id,
            $month_start . ' 00:00:00',
            date( 'Y-m-d', strtotime( $month_end . ' +1 day' ) ) . ' 00:00:00'
        );
    }

    private function build_monthly_cache_key( int $service_id, string $month_start, string $month_end ): string {
        return Setting_Keys::AVAIL_PREFIX
            . $this->get_cache_version_prefix( $service_id )
            . '_' . $month_start . '_' . $month_end;
    }

    private function cache_and_return( string $cache_key, array $result ): array {
        set_transient( $cache_key, $result, self::CACHE_TTL_MONTHLY );

        return $result;
    }

    public function is_slot_available( int $service_id, string $start_at, string $end_at, ?int $resource_id = null, ?int $exclude_booking_id = null ): bool {
        $service = $this->find_active_service( $service_id );
        if ( null === $service ) {
            return false;
        }

        $match = $this->find_matching_slot(
            $this->get_slots( $service_id, substr( $start_at, 0, 10 ), $resource_id ),
            $start_at,
            $end_at
        );

        if ( null === $match ) {
            return false;
        }

        if ( $match['available_capacity'] > 0 ) {
            return true;
        }

        return $this->has_capacity_excluding_booking( $match, $service, $service_id, $start_at, $end_at, $resource_id, $exclude_booking_id );
    }

    private function has_capacity_excluding_booking(
        array $match,
        \OpenBooking\Domain\Catalog\Entity\Service_Entity $service,
        int $service_id,
        string $start_at,
        string $end_at,
        ?int $resource_id,
        ?int $exclude_booking_id,
    ): bool {
        if ( null === $exclude_booking_id ) {
            return false;
        }

        $active_locks = $this->slot_lock_service->count_active_locks_for_slot(
            $service_id, $start_at, $end_at, $resource_id, $exclude_booking_id
        );

        $capacity = max( 1, (int) ( $match['total_capacity'] ?? $service->capacity ) );

        return $active_locks < $capacity;
    }

    private function find_matching_slot( array $slots, string $start_at, string $end_at ): ?array {
        foreach ( $slots as $slot ) {
            if ( $slot['start_at'] === $start_at && $slot['end_at'] === $end_at ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Weekly opening rules inherit by scope for each weekday: resource > service > global.
     * Non-opening rules (breaks, holidays, exceptions) remain additive but deduplicated.
     */
    private function resolve_effective_rules_for_date( array $rules, string $date ): array {
        $weekday = (int) date( 'N', strtotime( $date ) );
        $weekly_by_rank = [];
        $other_rules = [];

        foreach ( $rules as $rule ) {
            if ( ! is_object( $rule ) ) {
                continue;
            }

            if ( $rule->rule_type === 'weekly' && (int) $rule->weekday === $weekday ) {
                $rank = $this->scope_rank( (string) $rule->scope_type );
                $weekly_by_rank[ $rank ][] = $rule;
                continue;
            }

            $other_rules[] = $rule;
        }

        $effective_weekly = [];
        if ( ! empty( $weekly_by_rank ) ) {
            krsort( $weekly_by_rank, SORT_NUMERIC );
            $effective_weekly = reset( $weekly_by_rank ) ?: [];
        }

        return array_merge(
            $this->dedupe_rules( $effective_weekly ),
            $this->dedupe_rules( $other_rules )
        );
    }

    private function scope_rank( string $scope_type ): int {
        return match ( $scope_type ) {
            'resource' => 3,
            'service'  => 2,
            default    => 1,
        };
    }

    private function dedupe_rules( array $rules ): array {
        $seen = [];
        $deduped = [];

        foreach ( $rules as $rule ) {
            $key = implode( '|', [
                $rule->scope_type ?? '',
                (string) ( $rule->scope_id ?? 0 ),
                $rule->rule_type ?? '',
                (string) ( $rule->weekday ?? '' ),
                (string) ( $rule->date_from ?? '' ),
                (string) ( $rule->date_to ?? '' ),
                (string) ( $rule->time_from ?? '' ),
                (string) ( $rule->time_to ?? '' ),
                (string) ( $rule->capacity ?? '' ),
            ] );

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $deduped[] = $rule;
        }

        return $deduped;
    }

    private function merge_or_dedupe_slots( array $slots, bool $aggregate_capacity ): array {
        if ( $aggregate_capacity ) {
            return $this->calculator->merge_slots_by_time( $slots );
        }

        $seen = [];
        $deduped = [];
        foreach ( $slots as $slot ) {
            $key = ( $slot['resource_id'] ?? 0 ) . '|' . $slot['start_at'] . '|' . $slot['end_at'];
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $deduped[] = $slot;
        }

        return array_values( $deduped );
    }

    private const CACHE_TTL_MONTHLY = DAY_IN_SECONDS;

    public function invalidate_cache( int $service_id ): void {
        $key     = Setting_Keys::AVAIL_PREFIX . 'ver_' . $service_id;
        $version = (int) $this->settings->get( $key, 0 ) + 1;
        $this->settings->set( $key, $version );
    }

    public function invalidate_all_cache(): void {
        $version = (int) $this->settings->get( Setting_Keys::AVAIL_GLOBAL_VERSION, 0 ) + 1;
        $this->settings->set( Setting_Keys::AVAIL_GLOBAL_VERSION, $version );
    }

    private function get_cache_version_prefix( int $service_id ): string {
        $service_version = (int) $this->settings->get( Setting_Keys::AVAIL_PREFIX . 'ver_' . $service_id, 0 );
        $global_version  = (int) $this->settings->get( Setting_Keys::AVAIL_GLOBAL_VERSION, 0 );

        return $service_id . '_' . $service_version . '_' . $global_version;
    }

    private function calculator_timezone(): \DateTimeZone {
        $timezone = (string) $this->settings->get( Setting_Keys::BUSINESS_TIMEZONE, 'UTC' );

        try {
            return new \DateTimeZone( $timezone ?: 'UTC' );
        } catch ( \Exception $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }

    private function parse_business_datetime( string $datetime, ?\DateTimeZone $timezone = null ): ?\DateTimeImmutable {
        $timezone = $timezone ?: $this->calculator_timezone();
        $parsed   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );

        if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $datetime ) {
            return null;
        }

        return $parsed;
    }
}
