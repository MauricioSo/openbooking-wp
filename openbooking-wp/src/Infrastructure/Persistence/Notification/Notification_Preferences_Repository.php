<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Persistence\Notification;

use OpenBooking\Domain\Notification\Repository\NotificationPreferencesRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste preferencias de notificacion por cliente.
 */
class Notification_Preferences_Repository implements NotificationPreferencesRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_notification_preferences';
    }

    public function find_by_customer_id( int $customer_id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE customer_id = %d", $customer_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function find_by_token( string $token ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE opt_out_token = %s", $token ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function get_or_create( int $customer_id ): array {
        $existing = $this->find_by_customer_id( $customer_id );
        if ( $existing ) {
            return $existing;
        }

        $this->wpdb->insert( $this->table, [
            'customer_id'       => $customer_id,
            'channel_email'     => 1,
            'channel_whatsapp'  => 1,
            'channel_sms'       => 1,
            'reminders'         => 1,
            'marketing'         => 0,
            'opt_out_token'     => \wp_generate_password( 64, false, false ),
        ] );

        return $this->find_by_customer_id( $customer_id ) ?? [
            'customer_id'      => $customer_id,
            'channel_email'    => 1,
            'channel_whatsapp' => 1,
            'channel_sms'      => 1,
            'reminders'        => 1,
            'marketing'        => 0,
            'opt_out_token'    => '',
        ];
    }

    public function upsert( int $customer_id, array $data ): array {
        $current = $this->get_or_create( $customer_id );
        $payload = [
            'channel_email'    => isset( $data['channel_email'] ) ? (int) (bool) $data['channel_email'] : (int) $current['channel_email'],
            'channel_whatsapp' => isset( $data['channel_whatsapp'] ) ? (int) (bool) $data['channel_whatsapp'] : (int) $current['channel_whatsapp'],
            'channel_sms'      => isset( $data['channel_sms'] ) ? (int) (bool) $data['channel_sms'] : (int) ( $current['channel_sms'] ?? 1 ),
            'reminders'        => isset( $data['reminders'] ) ? (int) (bool) $data['reminders'] : (int) $current['reminders'],
            'marketing'        => isset( $data['marketing'] ) ? (int) (bool) $data['marketing'] : (int) $current['marketing'],
        ];

        $this->wpdb->update( $this->table, $payload, [ 'customer_id' => $customer_id ] );

        return $this->get_or_create( $customer_id );
    }
}
