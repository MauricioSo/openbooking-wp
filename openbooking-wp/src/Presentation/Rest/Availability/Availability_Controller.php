<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Availability;

use OpenBooking\Application\Availability\Service\Availability_Config_Save_Service;
use OpenBooking\Application\Availability\Service\Availability_Preview_Service;
use OpenBooking\Application\Availability\Service\Availability_Service;
use OpenBooking\Application\Availability\Service\Availability_Snapshot_Service;
use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone consultas y mantenimiento administrativo de disponibilidad.
 */
class Availability_Controller {


    public function __construct(
        private Availability_Service $engine, // calcula disponibilidad de slots
        private AvailabilityConfigRepositoryInterface $repo, // persiste reglas de disponibilidad
        private Availability_Preview_Service $preview_service, // previsualiza cambios de disponibilidad
        private Availability_Snapshot_Service $snapshot_service, // gestiona snapshots de cache
        private Availability_Config_Save_Service $config_save_service, // guarda config de disponibilidad
    ) {}

    public function get_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $service_id  = absint( $request->get_param( 'service_id' ) );
        $date        = sanitize_text_field( $request->get_param( 'date' ) );
        $resource_id = $request->get_param( 'resource_id' ) ? absint( $request->get_param( 'resource_id' ) ) : null;

        if ( ! $service_id || ! $date ) {
            return new \WP_REST_Response( [ 'error' => 'service_id y date son requeridos.' ], 400 );
        }

        $slots = $this->engine->get_slots( $service_id, $date, $resource_id );
        $response = new \WP_REST_Response( [ 'slots' => $slots ], 200 );
        $response->set_headers( [ 'Cache-Control' => 'no-store, max-age=0' ] );

        return $response;
    }

    public function get_available_dates( \WP_REST_Request $request ): \WP_REST_Response {
        $service_id = absint( $request->get_param( 'service_id' ) );
        $month      = sanitize_text_field( $request->get_param( 'month' ) );

        if ( ! $service_id || ! $month ) {
            return new \WP_REST_Response( [ 'error' => 'service_id y month son requeridos.' ], 400 );
        }

        if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
            return new \WP_REST_Response( [ 'error' => 'month debe tener formato YYYY-MM con mes entre 01 y 12.' ], 400 );
        }

        $start = $month . '-01';
        $end   = ( new \DateTime( $start ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
        $dates = $this->engine->get_available_dates( $service_id, $start, $end );
        $response = new \WP_REST_Response( [ 'dates' => $dates ], 200 );
        $response->set_headers( [ 'Cache-Control' => 'private, max-age=60' ] );

        return $response;
    }

    public function admin_availability_settings( \WP_REST_Request $request ): \WP_REST_Response {
        if ( 'GET' === $request->get_method() ) {
            $scope_type = sanitize_text_field( $request->get_param( 'scope_type' ) ?: 'global' );
            $scope_id   = absint( $request->get_param( 'scope_id' ) ?: 0 );

            return new \WP_REST_Response( [
                'rules'  => array_map( fn( $rule ) => $rule->to_array(), $this->repo->get_rules( $scope_type, $scope_id ) ),
                'blocks' => $this->repo->get_blocks( $scope_type, $scope_id ),
            ], 200 );
        }

        $body = $this->decode_json_body( $request );
        $scope_type = sanitize_text_field( $body['scope_type'] ?? 'global' );
        $scope_id   = absint( $body['scope_id'] ?? 0 );
        $dry_run    = ! empty( $body['dry_run'] );

        if ( $dry_run ) {
            return $this->admin_validate_availability( $request );
        }

        $result = $this->config_save_service->save_settings( $scope_type, $scope_id, $body );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( $result, $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function admin_import_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $csv  = (string) ( $body['csv'] ?? '' );
        $result = $this->config_save_service->import_csv( $csv );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( $result, $result['code'] ?? 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function admin_preview_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $body       = $this->decode_json_body( $request );
        $service_id = absint( $body['service_id'] ?? 0 );
        $mode       = sanitize_text_field( $body['mode'] ?? 'week' );
        $rules      = $body['rules'] ?? [];
        $blocks     = $body['blocks'] ?? [];

        if ( ! $service_id ) {
            return new \WP_REST_Response( [ 'error' => 'service_id es requerido.' ], 400 );
        }

        if ( ! in_array( $mode, [ 'week', 'month' ], true ) ) {
            $mode = 'week';
        }

        $result = $this->preview_service->generate_preview( $rules, $blocks, $service_id, $mode );

        if ( isset( $result['error'] ) ) {
            return new \WP_REST_Response( $result, 400 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    public function admin_validate_availability( \WP_REST_Request $request ): \WP_REST_Response {
        $body       = $this->decode_json_body( $request );
        $service_id = absint( $body['service_id'] ?? 0 );
        $scope_type = sanitize_text_field( $body['scope_type'] ?? 'global' );
        $scope_id   = absint( $body['scope_id'] ?? 0 );
        $rules      = $body['rules'] ?? [];
        $blocks     = $body['blocks'] ?? [];
        $dry_run    = ! empty( $body['dry_run'] );

        if ( ! $service_id && 'global' !== $scope_type ) {
            return new \WP_REST_Response( [ 'error' => 'service_id es requerido.' ], 400 );
        }

        $conflicts = $service_id
            ? $this->preview_service->detect_conflicts( $rules, $blocks, $service_id, $scope_type, $scope_id )
            : [];
        $warnings  = array_merge(
            $this->validate_rule_time_ranges( $rules ),
            $this->validate_block_time_ranges( $blocks ),
        );

        if ( $dry_run ) {
            return new \WP_REST_Response( [
                'dry_run'   => true,
                'conflicts' => $conflicts,
                'warnings'  => $warnings,
                'can_save'  => empty( $conflicts ),
            ], 200 );
        }

        return new \WP_REST_Response( [
            'conflicts' => $conflicts,
            'warnings'  => $warnings,
        ], 200 );
    }

    public function admin_list_snapshots( \WP_REST_Request $request ): \WP_REST_Response {
        $scope_type = sanitize_text_field( $request->get_param( 'scope_type' ) ?: 'global' );
        $scope_id   = absint( $request->get_param( 'scope_id' ) ?: 0 );
        $limit      = min( 50, max( 1, absint( $request->get_param( 'limit' ) ?: 20 ) ) );

        $snapshots = $this->snapshot_service->list_snapshots( $scope_type, $scope_id ?: null, $limit );

        return new \WP_REST_Response( [ 'snapshots' => $snapshots ], 200 );
    }

    public function admin_create_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
        $body       = $this->decode_json_body( $request );
        $scope_type = sanitize_text_field( $body['scope_type'] ?? 'global' );
        $scope_id   = absint( $body['scope_id'] ?? 0 ) ?: null;
        $label      = sanitize_text_field( $body['label'] ?? '' ) ?: null;

        $id = $this->snapshot_service->create_snapshot(
            $scope_type,
            $scope_id,
            $label,
            get_current_user_id()
        );

        return new \WP_REST_Response( [ 'success' => true, 'snapshot_id' => $id ], 201 );
    }

    public function admin_restore_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
        $snapshot_id = absint( $request['id'] ?? 0 );
        if ( ! $snapshot_id ) {
            return new \WP_REST_Response( [ 'error' => 'ID de snapshot requerido.' ], 400 );
        }

        $restored = $this->snapshot_service->restore_snapshot( $snapshot_id );

        if ( ! $restored ) {
            return new \WP_REST_Response( [ 'error' => 'Snapshot no encontrado.' ], 404 );
        }

        return new \WP_REST_Response( [ 'success' => true, 'restored_from' => $snapshot_id ], 200 );
    }

    public function admin_delete_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
        $snapshot_id = absint( $request['id'] ?? 0 );
        if ( ! $snapshot_id ) {
            return new \WP_REST_Response( [ 'error' => 'ID de snapshot requerido.' ], 400 );
        }

        $deleted = $this->snapshot_service->delete_snapshot( $snapshot_id );

        if ( ! $deleted ) {
            return new \WP_REST_Response( [ 'error' => 'Snapshot no encontrado.' ], 404 );
        }

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Decodifica el body JSON de forma segura.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }

    /**
     * Valida que las reglas tengan time_from < time_to.
     *
     * @return array[] Lista de advertencias.
     */
    private function validate_rule_time_ranges( array $rules ): array {
        $warnings = [];

        foreach ( $rules as $rule ) {
            if ( isset( $rule['time_from'], $rule['time_to'] ) && $rule['time_from'] >= $rule['time_to'] ) {
                $warnings[] = [
                    'type'    => 'invalid_time_range',
                    'message' => 'La hora de inicio debe ser anterior a la hora de fin en una regla.',
                    'rule'    => $rule,
                ];
            }
        }

        return $warnings;
    }

    /**
     * Valida que los bloques tengan start_at < end_at.
     *
     * @return array[] Lista de advertencias.
     */
    private function validate_block_time_ranges( array $blocks ): array {
        $warnings = [];

        foreach ( $blocks as $block ) {
            if ( isset( $block['start_at'], $block['end_at'] ) && $block['start_at'] >= $block['end_at'] ) {
                $warnings[] = [
                    'type'    => 'invalid_block_range',
                    'message' => 'El bloque tiene fecha de inicio posterior a la fecha de fin.',
                    'block'   => $block,
                ];
            }
        }

        return $warnings;
    }
}
