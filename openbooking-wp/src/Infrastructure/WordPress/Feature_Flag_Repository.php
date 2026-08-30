<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste flags de funcionalidad.
 */
class Feature_Flag_Repository implements \OpenBooking\Domain\Shared\Repository\FeatureFlagRepositoryInterface {

    public function get_value(string $key): ?string {
        global $wpdb;
        $table = $wpdb->prefix . 'ob_feature_flags';
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM {$table} WHERE flag_key = %s",
            $key
        ));
        return $row !== null ? $row : null;
    }

    public function set(string $key, string $value, int $updated_by): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ob_feature_flags';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE flag_key = %s",
            $key
        ));

        if ($exists) {
            $wpdb->update($table, ['value' => $value, 'updated_by' => $updated_by], ['flag_key' => $key]);
        } else {
            $wpdb->insert($table, ['flag_key' => $key, 'value' => $value, 'updated_by' => $updated_by]);
        }
    }

    public function find_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ob_feature_flags';
        $rows = $wpdb->get_results("SELECT flag_key, value, updated_by, updated_at FROM {$table} ORDER BY flag_key", ARRAY_A);
        return $rows ?: [];
    }
}
