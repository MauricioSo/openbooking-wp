<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Metrics;

use OpenBooking\Support\Option_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recolector de metricas de requests y embudos.
 *
 * Strategy: one WP transient per endpoint per 1-hour bucket.
 * Each transient stores: count, error_count, sum_ms, max_ms, samples[]
 * (last 200 samples kept for p95 approximation).
 * TTL = 2 hours so data survives the boundary between buckets.
 *
 * Designed to work without external infrastructure (Redis, APM, etc.).
 */
class Request_Metrics {

    /** Endpoints we track — the canonical slug maps to a readable label. */
    public const ENDPOINTS = [
        'availability'       => 'GET /availability',
        'availability_dates' => 'GET /availability/dates',
        'booking_create'     => 'POST /bookings',
        'payment_create'     => 'POST /payments/create',
        'booking_cancel'     => 'POST /bookings/cancel',
        'booking_reschedule' => 'POST /bookings/reschedule',
        'booking_public'     => 'GET /bookings/public',
        'health'             => 'GET /health',
    ];

    public const FUNNEL_EVENTS = [
        'service_selected'        => 'Servicio seleccionado',
        'date_selected'           => 'Fecha seleccionada',
        'slot_selected'           => 'Horario seleccionado',
        'booking_submit'          => 'Submit de reserva',
        'slot_conflict'           => 'Conflicto de horario',
        'payment_start'           => 'Inicio de pago',
        'payment_return'          => 'Retorno de pago',
        'booking_success'         => 'Reserva completada',
        'booking_restore'         => 'Recuperacion de sesion',
        'baseline_init'           => 'Carga inicial del wizard',
        'baseline_services_loaded' => 'Servicios cargados',
        'baseline_services_empty' => 'Sin servicios disponibles',
        'baseline_first_interaction' => 'Primera interaccion del usuario',
        'baseline_flow_completed' => 'Flujo completado',
        'baseline_abandon'        => 'Abandono detectado',
    ];

    /** How many latency samples to keep per bucket (for p95). */
    private const MAX_SAMPLES = 200;

    /** Transient TTL: keep the last two buckets in memory. */
    private const TRANSIENT_TTL = 2 * HOUR_IN_SECONDS;

    /**
     * Record a completed request.
     *
     * @param string $endpoint  Slug from self::ENDPOINTS keys.
     * @param float  $ms        Duration in milliseconds.
     * @param bool   $is_error  Whether the response was 4xx/5xx.
     */
    public static function record( string $endpoint, float $ms, bool $is_error = false ): void {
        $key  = self::bucket_key( $endpoint );
        $data = self::get_raw( $key );

        $data['count']++;
        if ( $is_error ) {
            $data['error_count']++;
        }
        $data['sum_ms'] += $ms;
        if ( $ms > $data['max_ms'] ) {
            $data['max_ms'] = $ms;
        }

        $data['samples'][] = $ms;
        if ( count( $data['samples'] ) > self::MAX_SAMPLES ) {
            array_shift( $data['samples'] );
        }

        \set_transient( $key, $data, self::TRANSIENT_TTL );
    }

    /**
     * Return computed stats for a single endpoint (current + previous hour combined).
     */
    public static function get_stats( string $endpoint ): array {
        $current  = self::get_raw( self::bucket_key( $endpoint ) );
        $previous = self::get_raw( self::bucket_key( $endpoint, -1 ) );

        $merged = self::merge_buckets( $current, $previous );

        return self::compute_stats( $endpoint, $merged );
    }

    /**
     * Return stats for all tracked endpoints.
     */
    public static function get_all_stats(): array {
        $result = [];
        foreach ( array_keys( self::ENDPOINTS ) as $endpoint ) {
            $result[ $endpoint ] = self::get_stats( $endpoint );
        }
        return $result;
    }

    /**
     * Return KPI-level aggregate counts from the DB.
     * Separate from latency data — hits the DB directly.
     */
    public static function get_db_kpis(): array {
        global $wpdb;

        $bookings_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ob_bookings WHERE DATE(created_at) = %s",
                \current_time( 'Y-m-d' )
            )
        );

        $bookings_7d = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ob_bookings WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );

        $payments_by_gateway = $wpdb->get_results(
            "SELECT gateway, status, COUNT(*) as total FROM {$wpdb->prefix}ob_payments
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             GROUP BY gateway, status",
            ARRAY_A
        ) ?: [];

        $queue_pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ob_notification_queue WHERE status = 'pending'"
        );

        $queue_failed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ob_notification_queue WHERE status = 'failed'"
        );

        $desynced = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}ob_bookings b
             INNER JOIN {$wpdb->prefix}ob_payments p ON p.booking_id = b.id
             WHERE (
                (p.status = 'paid' AND (b.payment_status != 'paid' OR b.status != 'confirmed'))
                OR (p.status = 'failed' AND b.payment_status != 'failed')
                OR (p.status = 'expired' AND b.payment_status != 'expired')
             )"
        );

        $stale_pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ob_bookings
             WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()"
        );

        $cron_heartbeat_last = \get_option( Option_Keys::CRON_HEARTBEAT_LAST, null );
        $heartbeat_ok = false;
        if ( $cron_heartbeat_last ) {
            $heartbeat_ok = ( time() - (int) strtotime( $cron_heartbeat_last ) ) < ( 10 * MINUTE_IN_SECONDS );
        }

        return [
            'bookings_today'       => $bookings_today,
            'bookings_7d'          => $bookings_7d,
            'payments_by_gateway'  => $payments_by_gateway,
            'queue_pending'        => $queue_pending,
            'queue_failed'         => $queue_failed,
            'desynced_count'       => $desynced,
            'stale_pending'        => $stale_pending,
            'cron_heartbeat_last'  => $cron_heartbeat_last,
            'cron_heartbeat_ok'    => $heartbeat_ok,
            'funnel'               => self::get_funnel_stats(),
            'abandonment_rate'     => self::get_abandonment_rate(),
        ];
    }

    public static function record_funnel_event( string $event, array $meta = [] ): void {
        if ( ! isset( self::FUNNEL_EVENTS[ $event ] ) ) {
            return;
        }

        $key  = self::funnel_key( $event );
        $data = \get_transient( $key );
        if ( ! is_array( $data ) ) {
            $data = [
                'count'       => 0,
                'last_at'     => null,
                'last_meta'   => [],
            ];
        }

        $data['count']++;
        $data['last_at']   = \current_time( 'mysql', true );
        $data['last_meta'] = array_slice( self::sanitize_funnel_meta( $meta ), 0, 10, true );

        \set_transient( $key, $data, self::TRANSIENT_TTL );
    }

    public static function get_funnel_stats(): array {
        $stats = [];
        foreach ( self::FUNNEL_EVENTS as $event => $label ) {
            $data = \get_transient( self::funnel_key( $event ) );
            if ( ! is_array( $data ) ) {
                $data = [ 'count' => 0, 'last_at' => null, 'last_meta' => [] ];
            }

            $stats[ $event ] = [
                'label'     => $label,
                'count'     => (int) ( $data['count'] ?? 0 ),
                'last_at'   => $data['last_at'] ?? null,
                'last_meta' => is_array( $data['last_meta'] ?? null ) ? $data['last_meta'] : [],
            ];
        }

        return $stats;
    }

    public static function get_abandonment_rate(): array {
        $init_data    = \get_transient( self::funnel_key( 'baseline_init' ) );
        $abandon_data = \get_transient( self::funnel_key( 'baseline_abandon' ) );
        $complete_data = \get_transient( self::funnel_key( 'baseline_flow_completed' ) );
        $interact_data = \get_transient( self::funnel_key( 'baseline_first_interaction' ) );

        $inits     = is_array( $init_data ) ? (int) ( $init_data['count'] ?? 0 ) : 0;
        $abandons  = is_array( $abandon_data ) ? (int) ( $abandon_data['count'] ?? 0 ) : 0;
        $completes = is_array( $complete_data ) ? (int) ( $complete_data['count'] ?? 0 ) : 0;
        $interacts = is_array( $interact_data ) ? (int) ( $interact_data['count'] ?? 0 ) : 0;

        $abandon_rate    = $inits > 0 ? round( $abandons / $inits * 100, 1 ) : null;
        $completion_rate = $inits > 0 ? round( $completes / $inits * 100, 1 ) : null;
        $interaction_rate = $inits > 0 ? round( $interacts / $inits * 100, 1 ) : null;

        $last_abandon_meta = [];
        if ( is_array( $abandon_data ) && is_array( $abandon_data['last_meta'] ?? null ) ) {
            $last_abandon_meta = $abandon_data['last_meta'];
        }

        return [
            'inits'            => $inits,
            'abandons'         => $abandons,
            'completes'        => $completes,
            'first_interactions' => $interacts,
            'abandon_rate_pct' => $abandon_rate,
            'completion_rate_pct' => $completion_rate,
            'interaction_rate_pct' => $interaction_rate,
            'last_abandon_meta' => $last_abandon_meta,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function bucket_key( string $endpoint, int $offset_hours = 0 ): string {
        $bucket = (int) floor( ( time() + ( $offset_hours * HOUR_IN_SECONDS ) ) / HOUR_IN_SECONDS );
        return Option_Keys::METRICS_PREFIX . $endpoint . '_' . $bucket;
    }

    private static function empty_bucket(): array {
        return [
            'count'       => 0,
            'error_count' => 0,
            'sum_ms'      => 0.0,
            'max_ms'      => 0.0,
            'samples'     => [],
        ];
    }

    private static function get_raw( string $key ): array {
        $data = \get_transient( $key );
        return is_array( $data ) ? $data : self::empty_bucket();
    }

    private static function merge_buckets( array $a, array $b ): array {
        return [
            'count'       => $a['count'] + $b['count'],
            'error_count' => $a['error_count'] + $b['error_count'],
            'sum_ms'      => $a['sum_ms'] + $b['sum_ms'],
            'max_ms'      => max( $a['max_ms'], $b['max_ms'] ),
            'samples'     => array_merge( $a['samples'], $b['samples'] ),
        ];
    }

    private static function compute_stats( string $endpoint, array $bucket ): array {
        $count = $bucket['count'];
        $avg   = $count > 0 ? round( $bucket['sum_ms'] / $count, 1 ) : null;
        $p95   = null;
        $p50   = null;

        if ( ! empty( $bucket['samples'] ) ) {
            $samples = $bucket['samples'];
            sort( $samples );
            $n    = count( $samples );
            $p50  = round( $samples[ (int) floor( $n * 0.50 ) ], 1 );
            $p95  = round( $samples[ (int) floor( $n * 0.95 ) ], 1 );
        }

        return [
            'endpoint'    => self::ENDPOINTS[ $endpoint ] ?? $endpoint,
            'count'       => $count,
            'error_count' => $bucket['error_count'],
            'error_rate'  => $count > 0 ? round( $bucket['error_count'] / $count * 100, 1 ) : 0,
            'avg_ms'      => $avg,
            'max_ms'      => $bucket['max_ms'] > 0 ? round( $bucket['max_ms'], 1 ) : null,
            'p50_ms'      => $p50,
            'p95_ms'      => $p95,
            'window'      => 'last_2h',
        ];
    }

    private static function funnel_key( string $event ): string {
        return Option_Keys::FUNNEL_PREFIX . $event;
    }

    private static function sanitize_funnel_meta( array $meta ): array {
        $clean = [];
        foreach ( $meta as $key => $value ) {
            $key = \sanitize_key( (string) $key );
            if ( '' === $key ) {
                continue;
            }
            if ( is_scalar( $value ) || null === $value ) {
                $clean[ $key ] = \sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }
}
