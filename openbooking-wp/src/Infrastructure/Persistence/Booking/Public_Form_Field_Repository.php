<?php


declare( strict_types=1 );
namespace OpenBooking\Infrastructure\Persistence\Booking;

use OpenBooking\Domain\Booking\Repository\PublicFormFieldRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persiste y recupera entidades del bounded context de reservas.
 */

class Public_Form_Field_Repository implements PublicFormFieldRepositoryInterface {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'ob_form_fields';
    }

    public function find_enabled_for_public_form(): array {
        $rows = $this->wpdb->get_results(
            'SELECT field_key, label, placeholder, is_required, is_enabled, sort_order FROM `' . esc_sql( $this->table ) . '` WHERE is_enabled = 1 ORDER BY sort_order ASC',
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function find_all_ordered(): array {
        $rows = $this->wpdb->get_results(
            'SELECT * FROM `' . esc_sql( $this->table ) . '` ORDER BY sort_order ASC',
            ARRAY_A
        );

        return $rows ?: [];
    }

    public function save_all( array $fields ): void {
        foreach ( $fields as $field ) {
            $field_key = sanitize_key( $field['field_key'] ?? '' );
            if ( ! $field_key ) {
                continue;
            }
            $is_core_identity_field = in_array( $field_key, [ 'first_name', 'email' ], true );
            $this->wpdb->update( $this->table, [
                'is_enabled'  => $is_core_identity_field ? 1 : ( ! empty( $field['is_enabled'] ) ? 1 : 0 ),
                'is_required' => $is_core_identity_field ? 1 : ( ! empty( $field['is_required'] ) ? 1 : 0 ),
                'sort_order'  => absint( $field['sort_order'] ?? 0 ),
                'label'       => sanitize_text_field( $field['label'] ?? '' ),
            ], [ 'field_key' => $field_key ] );
        }
    }
}
