<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Notification;

use OpenBooking\Domain\Notification\Repository\NotificationLogRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera logs de notificacion.
 */
class Notification_Log_Repository implements NotificationLogRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_notification_logs';
    }

    public function find( int $id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
        return $row ?: null;
    }

    public function search( array $args = [] ): array {
        $booking_table  = $this->wpdb->prefix . 'ob_bookings';
        $customer_table = $this->wpdb->prefix . 'ob_customers';
        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['channel'] ) ) { $where[] = 'nl.channel = %s'; $params[] = \sanitize_key( $args['channel'] ); }
        if ( ! empty( $args['status'] ) ) { $where[] = 'nl.status = %s'; $params[] = \sanitize_key( $args['status'] ); }
        if ( ! empty( $args['template_key'] ) ) { $where[] = 'nl.template_key = %s'; $params[] = \sanitize_key( $args['template_key'] ); }
        if ( ! empty( $args['booking_id'] ) ) { $where[] = 'nl.booking_id = %d'; $params[] = \absint( $args['booking_id'] ); }
        if ( ! empty( $args['date_from'] ) ) { $where[] = 'nl.sent_at >= %s'; $params[] = \sanitize_text_field( $args['date_from'] ); }
        if ( ! empty( $args['date_to'] ) ) { $where[] = 'nl.sent_at <= %s'; $params[] = \sanitize_text_field( $args['date_to'] ); }
        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $this->wpdb->esc_like( \sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = '(c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR nl.recipient LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like );
        }

        $where_sql = implode( ' AND ', $where );
		$limit  = ! empty( $args['per_page'] ) ? min( 1000, max( 1, \absint( $args['per_page'] ) ) ) : 25;
        $page   = ! empty( $args['page'] ) ? max( 1, \absint( $args['page'] ) ) : 1;
        $offset = ( $page - 1 ) * $limit;
        $order  = ( ! empty( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$this->table} nl LEFT JOIN {$booking_table} b ON nl.booking_id = b.id LEFT JOIN {$customer_table} c ON b.customer_id = c.id WHERE {$where_sql}";
        $total = (int) $this->wpdb->get_var( $params ? $this->wpdb->prepare( $count_sql, $params ) : $count_sql );

        $data_sql = "SELECT nl.*, c.first_name, c.last_name, c.email AS customer_email, c.phone AS customer_phone FROM {$this->table} nl LEFT JOIN {$booking_table} b ON nl.booking_id = b.id LEFT JOIN {$customer_table} c ON b.customer_id = c.id WHERE {$where_sql} ORDER BY nl.id {$order} LIMIT {$limit} OFFSET {$offset}";
        $rows = (array) $this->wpdb->get_results( $params ? $this->wpdb->prepare( $data_sql, $params ) : $data_sql, ARRAY_A );

        return [
            'logs'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $limit,
            'pages'    => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
        ];
    }

    public function stats( int $days = 30 ): array {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
        $rows = (array) $this->wpdb->get_results( $this->wpdb->prepare( "SELECT channel, status, COUNT(*) AS total FROM {$this->table} WHERE created_at >= %s GROUP BY channel, status", $cutoff ), ARRAY_A );
        $daily = (array) $this->wpdb->get_results( $this->wpdb->prepare( "SELECT DATE(created_at) AS day, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed FROM {$this->table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC", $cutoff ), ARRAY_A );
        $by_event = (array) $this->wpdb->get_results( $this->wpdb->prepare( "SELECT template_key, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed FROM {$this->table} WHERE created_at >= %s GROUP BY template_key", $cutoff ), ARRAY_A );

        $totals = [ 'email' => [ 'sent' => 0, 'failed' => 0 ], 'whatsapp' => [ 'sent' => 0, 'failed' => 0 ], 'sms' => [ 'sent' => 0, 'failed' => 0 ] ];
        foreach ( $rows as $row ) {
            $channel = $row['channel'];
            $status  = $row['status'];
            if ( ! isset( $totals[ $channel ] ) ) {
                $totals[ $channel ] = [ 'sent' => 0, 'failed' => 0 ];
            }
            if ( isset( $totals[ $channel ][ $status ] ) ) {
                $totals[ $channel ][ $status ] = (int) $row['total'];
            }
        }

        $event_stats = [];
        foreach ( $by_event as $row ) {
            $event_stats[ $row['template_key'] ] = [ 'sent' => (int) $row['sent'], 'failed' => (int) $row['failed'] ];
        }

        $last_failures = (array) $this->wpdb->get_results( "SELECT * FROM {$this->table} WHERE status = 'failed' ORDER BY id DESC LIMIT 5", ARRAY_A );

        return [
            'total_sent' => [ 'email' => $totals['email']['sent'], 'whatsapp' => $totals['whatsapp']['sent'], 'sms' => $totals['sms']['sent'] ],
            'total_failed' => [ 'email' => $totals['email']['failed'], 'whatsapp' => $totals['whatsapp']['failed'], 'sms' => $totals['sms']['failed'] ],
            'delivery_rate' => [
                'email' => $this->calculate_rate( $totals['email']['sent'], $totals['email']['failed'] ),
                'whatsapp' => $this->calculate_rate( $totals['whatsapp']['sent'], $totals['whatsapp']['failed'] ),
                'sms' => $this->calculate_rate( $totals['sms']['sent'], $totals['sms']['failed'] ),
            ],
            'by_event' => $event_stats,
            'trend_daily' => array_map( static function ( array $row ): array {
                return [ 'date' => $row['day'], 'sent' => (int) $row['sent'], 'failed' => (int) $row['failed'] ];
            }, $daily ),
            'last_failures' => $last_failures,
        ];
    }

    private function calculate_rate( int $sent, int $failed ): float {
        $total = $sent + $failed;
        if ( 0 === $total ) {
            return 100.0;
        }
        return round( ( $sent / $total ) * 100, 1 );
    }

    public function count_recent_failed( int $days = 7 ): int {
        $days = max( 1, absint( $days ) );
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'failed' AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
