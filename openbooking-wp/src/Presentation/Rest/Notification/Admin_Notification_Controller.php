<?php

declare( strict_types=1 );

namespace OpenBooking\Presentation\Rest\Notification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Expone acciones administrativas y publicas relacionadas con notificaciones.
 */
class Admin_Notification_Controller {


    public function __construct(
        private \OpenBooking\Domain\Notification\Service\EmailServiceInterface $email_service, // envia correos electronicos
        private \OpenBooking\Domain\Notification\Service\WhatsAppServiceInterface $whatsapp_service, // envia mensajes WhatsApp
        private \OpenBooking\Domain\Notification\Service\SMSServiceInterface $sms_service, // envia mensajes SMS
        private \OpenBooking\Domain\Notification\Repository\NotificationLogRepositoryInterface $log_repo, // consulta log de notificaciones
        private \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface $queue_repo, // gestiona cola de notificaciones
        private \OpenBooking\Domain\Notification\Service\NotificationManagerInterface $notification_manager, // orquesta envio de notificaciones
        private \OpenBooking\Domain\Notification\Repository\NotificationCampaignRepositoryInterface $campaign_repo, // consulta campanas de notificacion
        private \OpenBooking\Domain\Notification\Repository\NotificationPreferencesRepositoryInterface $preferences_repo, // consulta preferencias de canal
        private \OpenBooking\Domain\Notification\Repository\ConsentLogRepositoryInterface $consent_repo, // registra consentimientos
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo, // consulta y persiste reservas
        private \OpenBooking\Application\Audit\Service\Audit_Logger $audit_logger, // deja trazabilidad de cambios
        private \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case $cancel_booking_use_case,
        private \OpenBooking\Application\Notification\Service\Notification_Settings_Service $notification_settings, // configuracion de notificaciones
        private \OpenBooking\Application\Notification\Service\Notification_Broadcast_Service $broadcast_service, // difusion masiva de notificaciones
        private \OpenBooking\Application\Notification\Service\Notification_Test_Service $test_service, // prueba envio de notificaciones
    ) {}

    public function admin_get_email_templates( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'templates' => $this->email_service->get_all_templates() ], 200 );
    }

    public function admin_save_email_template( \WP_REST_Request $request ): \WP_REST_Response {
        $key  = sanitize_key( $request['key'] );
        $body = $this->decode_json_body( $request );
        $this->email_service->save_template(
            $key,
            sanitize_text_field( $body['subject'] ?? '' ),
            sanitize_textarea_field( $body['body'] ?? '' )
        );
        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Plantilla de email guardada correctamente.' ], 200 );
    }

    public function admin_test_email( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $result = $this->test_service->test_email(
            sanitize_email( $body['to'] ?? '' ),
            sanitize_text_field( $body['subject'] ?? 'OpenBooking WP — Email de prueba' ),
            sanitize_textarea_field( $body['body'] ?? 'Este es un email de prueba desde OpenBooking WP.' )
        );
        $status = $result['status'] ?? ( $result['success'] ?? false ? 200 : 400 );
        unset( $result['status'] );
        return new \WP_REST_Response( $result, $status );
    }

    public function admin_get_notification_settings( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->notification_settings->get_settings(), 200 );
    }

    public function admin_save_notification_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $this->notification_settings->save_settings( $body );
        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Ajustes de notificaciones guardados correctamente.' ], 200 );
    }

    public function admin_get_whatsapp_templates( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'templates' => $this->whatsapp_service->get_all_templates() ], 200 );
    }

    public function admin_save_whatsapp_template( \WP_REST_Request $request ): \WP_REST_Response {
        $key  = sanitize_key( $request['key'] );
        $body = $this->decode_json_body( $request );

        if ( empty( $body['body'] ) ) {
            return new \WP_REST_Response( [ 'error' => 'El cuerpo del mensaje no puede estar vacío.' ], 400 );
        }

        $this->whatsapp_service->save_template( $key, sanitize_textarea_field( $body['body'] ) );

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Plantilla de WhatsApp guardada correctamente.' ], 200 );
    }

    public function admin_test_whatsapp( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $result = $this->test_service->test_whatsapp(
            sanitize_text_field( $body['to'] ?? '' ),
            sanitize_textarea_field( $body['message'] ?? 'Mensaje de prueba desde OpenBooking WP.' )
        );
        $status = $result['status'] ?? ( $result['success'] ?? false ? 200 : 400 );
        unset( $result['status'] );
        return new \WP_REST_Response( $result, $status );
    }

    public function admin_get_notification_logs( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->log_repo->search( $this->build_log_filters( $request ) ), 200 );
    }

    public function admin_export_notification_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $result = $this->log_repo->search( $this->build_log_filters( $request, 1, 1000 ) );

        $stream = fopen( 'php://temp', 'r+' );
        fputcsv( $stream, [ 'id', 'fecha', 'canal', 'evento', 'cliente_nombre', 'cliente_email', 'cliente_telefono', 'reserva_id', 'destinatario', 'estado', 'intentos', 'mensaje_preview' ] );
        $this->write_log_export_rows( $stream, $result['logs'] ?? [] );
        rewind( $stream );
        $csv = (string) stream_get_contents( $stream );
        fclose( $stream );

        $total_rows = count( $result['logs'] ?? [] );

        $this->audit_logger->log( [
            'entity_type' => 'export',
            'entity_id'   => 0,
            'action'      => 'export_notification_logs_csv',
            'actor_type'  => 'admin',
            'message'     => "Notification logs CSV exported ({$total_rows} rows).",
            'context'     => [
                'rows'      => $total_rows,
                'channel'   => $request->get_param( 'channel' ),
                'status'    => $request->get_param( 'status' ),
                'date_from' => $request->get_param( 'date_from' ),
                'date_to'   => $request->get_param( 'date_to' ),
            ],
            'severity' => 'info',
        ] );

        $response = new \WP_REST_Response( $csv, 200 );
        if ( method_exists( $response, 'header' ) ) {
            $response->header( 'Content-Type', 'text/csv; charset=utf-8' );
            $response->header( 'Content-Disposition', 'attachment; filename="openbooking-notification-logs.csv"' );
            $response->header( 'X-PII-Warning', 'Este archivo contiene datos personales. Almacénalo de forma segura y elimínalo cuando ya no lo necesites.' );
        }
        return $response;
    }

    public function admin_resend_notification_log( \WP_REST_Request $request ): \WP_REST_Response {
        $log  = $this->log_repo->find( absint( $request['id'] ) );
        if ( ! $log ) {
            return new \WP_REST_Response( [ 'error' => 'Notificación no encontrada.' ], 404 );
        }

        $attempts = (int) ( $log['attempts'] ?? 1 ) + 1;

        $sent = $this->resend_notification_log( $log, $attempts );

        return new \WP_REST_Response( [ 'success' => $sent ], $sent ? 200 : 400 );
    }

	public function admin_get_notification_queue( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = min( 500, max( 1, absint( $request->get_param( 'limit' ) ?: 100 ) ) );
		$offset = absint( $request->get_param( 'offset' ) ?: 0 );
		$items = $this->queue_repo->list( [
			'status'     => $request->get_param( 'status' ),
			'channel'    => $request->get_param( 'channel' ),
			'booking_id' => $request->get_param( 'booking_id' ),
			'campaign_id'=> $request->get_param( 'campaign_id' ),
			'limit'      => $limit,
			'offset'     => $offset,
		] );

        $items = $this->decorate_queue_items( $items );

        return new \WP_REST_Response( [
            'queue' => $items,
            'total' => $this->queue_repo->count( [
                'status'  => $request->get_param( 'status' ),
                'channel' => $request->get_param( 'channel' ),
            ] ),
            'summary' => [
                'by_status' => [
                    'pending'  => $this->queue_repo->count( [ 'status' => 'pending' ] ),
                    'failed'   => $this->queue_repo->count( [ 'status' => 'failed' ] ),
                    'dead'     => $this->queue_repo->count( [ 'status' => 'dead' ] ),
                    'sent'     => $this->queue_repo->count( [ 'status' => 'sent' ] ),
                ],
            ],
        ], 200 );
    }

    public function admin_cancel_notification_queue_item( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => $this->queue_repo->cancel( absint( $request['id'] ) ) ], 200 );
    }

    public function admin_retry_notification_queue_item( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => $this->queue_repo->retry( absint( $request['id'] ) ) ], 200 );
    }

    public function admin_cancel_notification_queue_for_booking( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'cancelled' => $this->queue_repo->cancel_for_booking( absint( $request['id'] ) ) ], 200 );
    }

    public function admin_get_notification_stats( \WP_REST_Request $request ): \WP_REST_Response {
        $period = sanitize_text_field( (string) $request->get_param( 'period' ) );
        $days = '90d' === $period ? 90 : ( '7d' === $period ? 7 : 30 );
        $stats = $this->log_repo->stats( $days );
        $stats['queue_pending'] = $this->queue_repo->count_due_by_channel();
        return new \WP_REST_Response( $stats, 200 );
    }

	public function admin_bulk_cancel_notifications( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $this->decode_json_body( $request );
		$booking_ids = array_values( array_filter( array_map( 'absint', (array) ( $body['booking_ids'] ?? [] ) ) ) );
		if ( empty( $booking_ids ) ) {
			return new \WP_REST_Response( [ 'error' => 'No se enviaron reservas.' ], 400 );
		}
		if ( count( $booking_ids ) > 500 ) {
			return new \WP_REST_Response( [ 'error' => 'No se pueden cancelar mas de 500 reservas por solicitud.' ], 400 );
		}
		return new \WP_REST_Response( $this->broadcast_service->bulk_cancel( $booking_ids, $body ), 200 );
	}

    public function admin_broadcast_notifications( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $scope = is_array( $body['scope'] ?? null ) ? $body['scope'] : [];
        $channels = array_values( array_filter( array_map( 'sanitize_key', (array) ( $body['channels'] ?? [ 'email' ] ) ) ) );
        return new \WP_REST_Response( $this->broadcast_service->broadcast( $scope, $channels, $body ), 200 );
    }

    public function admin_preview_notification( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $booking_id = absint( $body['booking_id'] ?? 0 );
        $template_key = sanitize_key( $body['template_key'] ?? '' );
        $channel = sanitize_key( $body['channel'] ?? 'email' );

        $preview = $this->build_preview( $channel, $template_key, $booking_id );
        if ( ! $preview ) {
            return new \WP_REST_Response( [ 'error' => 'No se pudo generar preview.' ], 400 );
        }

        return new \WP_REST_Response( $preview, 200 );
    }

    public function admin_get_notification_campaigns( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'campaigns' => $this->campaign_repo->find_all( [ 'limit' => 100 ] ) ], 200 );
    }

    public function admin_get_customer_notification_preferences( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'preferences' => $this->preferences_repo->get_or_create( absint( $request['id'] ) ) ], 200 );
    }

    public function admin_save_customer_notification_preferences( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $customer_id = absint( $request['id'] );
        $marketing_enabled = ! empty( $body['marketing'] );
        $prefs = $this->preferences_repo->upsert( $customer_id, [
            'channel_email'    => ! empty( $body['channel_email'] ),
            'channel_whatsapp' => ! empty( $body['channel_whatsapp'] ),
            'channel_sms'      => ! empty( $body['channel_sms'] ),
            'reminders'        => ! empty( $body['reminders'] ),
            'marketing'        => $marketing_enabled,
        ] );

        if ( $marketing_enabled ) {
            foreach ( [ 'email', 'whatsapp', 'sms' ] as $channel ) {
                $this->consent_repo->record_opt_in( $customer_id, $channel, 'marketing', 'admin_panel' );
            }
        }

        return new \WP_REST_Response( [ 'success' => true, 'preferences' => $prefs, 'message' => 'Preferencias guardadas correctamente.' ], 200 );
    }

    public function public_get_notification_unsubscribe( \WP_REST_Request $request ): \WP_REST_Response {
        $prefs = $this->preferences_repo->find_by_token( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
        if ( ! $prefs ) {
            return new \WP_REST_Response( [ 'preferences' => self::public_preferences_defaults() ], 200 );
        }
        return new \WP_REST_Response( [ 'preferences' => self::build_public_preferences_payload( $prefs ) ], 200 );
    }

    public function public_post_notification_unsubscribe( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $prefs = $this->preferences_repo->find_by_token( sanitize_text_field( (string) ( $body['token'] ?? '' ) ) );
        if ( ! $prefs ) {
            return new \WP_REST_Response( [ 'success' => true, 'message' => 'Si el enlace es valido, tus preferencias fueron actualizadas.' ], 200 );
        }

        $update = $this->build_unsubscribe_update( sanitize_key( $body['channel'] ?? 'all' ) );

        $updated = $this->preferences_repo->upsert( absint( $prefs['customer_id'] ), $update );
        return new \WP_REST_Response( [ 'success' => true, 'preferences' => self::build_public_preferences_payload( $updated ) ], 200 );
    }

    private static function public_preferences_defaults(): array {
        return [ 'channel_email' => false, 'channel_whatsapp' => false, 'channel_sms' => false, 'reminders' => false, 'marketing' => false ];
    }

    private static function build_public_preferences_payload( array $prefs ): array {
        return [
            'channel_email'    => (bool) ( $prefs['channel_email'] ?? false ),
            'channel_whatsapp' => (bool) ( $prefs['channel_whatsapp'] ?? false ),
            'channel_sms'      => (bool) ( $prefs['channel_sms'] ?? false ),
            'reminders'        => (bool) ( $prefs['reminders'] ?? false ),
            'marketing'        => (bool) ( $prefs['marketing'] ?? false ),
        ];
    }

    public function admin_get_sms_templates( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'templates' => $this->sms_service->get_all_templates() ], 200 );
    }

    public function admin_save_sms_template( \WP_REST_Request $request ): \WP_REST_Response {
        $key  = sanitize_key( $request['key'] );
        $body = $this->decode_json_body( $request );

        if ( empty( $body['body'] ) ) {
            return new \WP_REST_Response( [ 'error' => 'El cuerpo del mensaje no puede estar vacio.' ], 400 );
        }

        $this->sms_service->save_template( $key, sanitize_textarea_field( $body['body'] ) );

        return new \WP_REST_Response( [ 'success' => true, 'message' => 'Plantilla SMS guardada correctamente.' ], 200 );
    }

    public function admin_test_sms( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $this->decode_json_body( $request );
        $result = $this->test_service->test_sms(
            sanitize_text_field( $body['to'] ?? '' ),
            sanitize_textarea_field( $body['message'] ?? 'Mensaje de prueba desde OpenBooking WP.' )
        );
        $status = $result['status'] ?? ( $result['success'] ?? false ? 200 : 400 );
        unset( $result['status'] );
        return new \WP_REST_Response( $result, $status );
    }

    private function safe_csv_cell( $value ): string {
        $value = (string) $value;
        return preg_match( '/^[=+\-@]/', ltrim( $value ) ) ? "'" . $value : $value;
    }

    /**
     * Decodifica el body JSON de una request REST.
     */
    private function decode_json_body( \WP_REST_Request $request ): array {
        $body = json_decode( $request->get_body(), true );

        return is_array( $body ) ? $body : [];
    }

    /**
     * Construye los filtros de busqueda para logs de notificacion.
     */
    private function build_log_filters( \WP_REST_Request $request, ?int $page = null, ?int $per_page = null ): array {
        return [
            'page'         => $page ?? $request->get_param( 'page' ),
            'per_page'     => $per_page ?? $request->get_param( 'per_page' ),
            'channel'      => $request->get_param( 'channel' ),
            'status'       => $request->get_param( 'status' ),
            'template_key' => $request->get_param( 'template_key' ),
            'search'       => $request->get_param( 'search' ),
            'booking_id'   => $request->get_param( 'booking_id' ),
            'date_from'    => $request->get_param( 'date_from' ),
            'date_to'      => $request->get_param( 'date_to' ),
            'order'        => $request->get_param( 'order' ),
        ];
    }

    /**
     * Reescribe la salida de la cola con etiquetas legibles para el admin.
     */
    private function decorate_queue_items( array $items ): array {
        $status_labels = [
            'pending'            => 'Pendiente de envío',
            'sent'               => 'Enviado',
            'failed'             => 'Fallo (será reintentado)',
            'permanently_failed' => 'Fallo permanente',
            'dead'               => 'En cola de reintentos manuales (DLQ)',
            'cancelled'          => 'Cancelado',
        ];

        return array_map(
            function ( array $item ) use ( $status_labels ) {
                $item['status_label'] = $status_labels[ $item['status'] ?? '' ] ?? ucfirst( $item['status'] ?? '' );
                $item['needs_attention'] = in_array( $item['status'] ?? '', [ 'dead', 'permanently_failed' ], true );
                $priority = (int) ( $item['priority'] ?? 5 );
                if ( 1 === $priority ) {
                    $item['priority_label'] = 'Crítico';
                } elseif ( 10 === $priority ) {
                    $item['priority_label'] = 'Bajo (recordatorio/broadcast)';
                } else {
                    $item['priority_label'] = 'Normal';
                }

                return $item;
            },
            $items
        );
    }

    /**
     * Convierte una fila de log en CSV de forma segura.
     */
    private function write_log_export_rows( $stream, array $logs ): void {
        foreach ( $logs as $log ) {
            $payload = $this->decode_log_payload( $log );
            $message = (string) ( $payload['message'] ?? $payload['body'] ?? '' );

            fputcsv( $stream, array_map( [ $this, 'safe_csv_cell' ], [
                $log['id'],
                $log['sent_at'] ?? $log['created_at'] ?? '',
                $log['channel'] ?? '',
                $log['template_key'] ?? '',
                trim( ( $log['first_name'] ?? '' ) . ' ' . ( $log['last_name'] ?? '' ) ),
                $log['customer_email'] ?? '',
                $log['customer_phone'] ?? '',
                $log['booking_id'] ?? '',
                $log['recipient'] ?? '',
                $log['status'] ?? '',
                $log['attempts'] ?? 1,
                mb_substr( $message, 0, 120 ),
            ] ) );
        }
    }

    /**
     * Lee el payload persistido dentro de un log.
     */
    private function decode_log_payload( array $log ): array {
        $payload = ! empty( $log['payload'] ) ? json_decode( (string) $log['payload'], true ) : [];

        return is_array( $payload ) ? $payload : [];
    }

    /**
     * Reenvia una notificacion segun el canal original.
     */
    private function resend_notification_log( array $log, int $attempts ): bool {
        $booking_id = (int) $log['booking_id'];
        $options    = [ 'attempts' => $attempts ];

        return match ( $log['channel'] ) {
            'email' => $this->email_service->send( $log['template_key'], $booking_id, [], $options ),
            'sms'   => $this->sms_service->send( $log['template_key'], $booking_id, [], $options ),
            default => $this->whatsapp_service->send( $log['template_key'], $booking_id, [], $options ),
        };
    }

    /**
     * Genera la vista previa adecuada segun el canal pedido.
     */
    private function build_preview( string $channel, string $template_key, int $booking_id ): ?array {
        if ( 'email' === $channel ) {
            return $this->build_email_preview( $template_key, $booking_id );
        }

        return $this->build_whatsapp_preview( $template_key, $booking_id );
    }

    /**
     * Genera vista previa de email con subject, body y HTML renderizado.
     */
    private function build_email_preview( string $template_key, int $booking_id ): ?array {
        $preview = $this->email_service->preview( $template_key, $booking_id );

        if ( ! $preview ) {
            return null;
        }

        return [ 'subject' => $preview['subject'], 'body' => $preview['body'], 'html' => $preview['final_body'] ];
    }

    /**
     * Genera vista previa de WhatsApp con el mensaje renderizado.
     */
    private function build_whatsapp_preview( string $template_key, int $booking_id ): ?array {
        $preview = $this->whatsapp_service->preview( $template_key, $booking_id );

        if ( ! $preview ) {
            return null;
        }

        return [ 'body' => $preview['message'] ];
    }

    /**
     * Construye la mutacion para dar de baja notificaciones publicas.
     */
    private function build_unsubscribe_update( string $channel ): array {
        return match ( $channel ) {
            'all'       => [ 'channel_email' => false, 'channel_whatsapp' => false, 'channel_sms' => false, 'reminders' => false, 'marketing' => false ],
            'email'     => [ 'channel_email' => false ],
            'whatsapp'  => [ 'channel_whatsapp' => false ],
            'sms'       => [ 'channel_sms' => false ],
            'marketing' => [ 'marketing' => false ],
            default     => [],
        };
    }
}
