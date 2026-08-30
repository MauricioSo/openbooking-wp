<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Notification\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Notification_Broadcast_Service {


    public function __construct(
        private \OpenBooking\Domain\Notification\Service\NotificationManagerInterface $notification_manager,
        private \OpenBooking\Domain\Notification\Repository\NotificationQueueRepositoryInterface $queue_repo,
        private \OpenBooking\Domain\Booking\Repository\BookingRepositoryInterface $booking_repo,
        private \OpenBooking\Application\Booking\UseCase\Cancel_Booking_Use_Case $cancel_booking_use_case,
        private \OpenBooking\Domain\Notification\Service\SMSServiceInterface $sms_service,
        private \OpenBooking\Domain\Shared\Port\ActorContextInterface $actor_context,
    ) {}

    public function bulk_cancel( array $booking_ids, array $body ): array {
        $campaign_id = ! empty( $body['send_notice'] )
            ? $this->notification_manager->create_campaign( [
                'type'              => 'bulk_cancel',
                'title'             => 'Bulk cancel ' . current_time( 'mysql' ),
                'message_email'     => $body['custom_message'] ?? '',
                'message_whatsapp'  => $body['custom_message'] ?? '',
                'scope_json'        => [ 'booking_ids' => $booking_ids ],
                'total_targets'     => count( $booking_ids ),
                'status'            => 'sending',
                'created_by'        => $this->actor_context->get_current_user_id(),
            ] )
            : 0;

        $cancelled_from_queue = 0;
        $notices_queued = 0;
        foreach ( $booking_ids as $booking_id ) {
            $result = $this->cancel_booking_use_case->execute( $booking_id, 'admin', sanitize_text_field( $body['cancel_reason'] ?? '' ) );
            if ( empty( $result['success'] ) ) {
                continue;
            }
            $cancelled_from_queue += $this->queue_repo->cancel_for_booking( $booking_id );
            if ( ! empty( $body['send_notice'] ) ) {
                $payload = [
                    '{cancel_reason_block}' => sanitize_textarea_field( (string) ( $body['cancel_reason'] ?? '' ) ),
                    '{refund_block}'        => sanitize_textarea_field( (string) ( $body['custom_message'] ?? '' ) ),
                ];
                $notices_queued += $this->notification_manager->queue_booking_message( $booking_id, 'email', 'booking_cancelled_by_admin', '', $payload, $campaign_id );
                $notices_queued += $this->notification_manager->queue_booking_message( $booking_id, 'whatsapp', 'booking_cancelled_by_admin', '', $payload, $campaign_id );
            }
        }

        return [
            'cancelled_from_queue' => $cancelled_from_queue,
            'notices_queued'       => $notices_queued,
            'campaign_id'          => $campaign_id,
        ];
    }

    public function broadcast( array $scope, array $channels, array $body ): array {
        $schedule_at = ! empty( $body['schedule_at'] ) ? sanitize_text_field( $body['schedule_at'] ) : current_time( 'mysql' );

        $args = [ 'limit' => 1000 ];
        if ( ! empty( $scope['date_from'] ) ) { $args['date_from'] = sanitize_text_field( $scope['date_from'] ) . ' 00:00:00'; }
        if ( ! empty( $scope['date_to'] ) ) { $args['date_to'] = sanitize_text_field( $scope['date_to'] ) . ' 23:59:59'; }
        if ( ! empty( $scope['status'] ) ) { $args['status'] = (array) $scope['status']; }
        $bookings = $this->booking_repo->find_all( $args );
        if ( ! empty( $scope['service_ids'] ) ) {
            $service_ids = array_map( 'absint', (array) $scope['service_ids'] );
            $bookings = array_values( array_filter( $bookings, static function ( $booking ) use ( $service_ids ): bool {
                return in_array( (int) $booking->service_id, $service_ids, true );
            } ) );
        }

        $campaign_id = $this->notification_manager->create_campaign( [
            'type'             => 'broadcast',
            'title'            => sanitize_text_field( $body['title'] ?? 'Broadcast' ),
            'message_email'    => $body['message_email'] ?? '',
            'message_whatsapp' => $body['message_whatsapp'] ?? '',
            'scope_json'       => $scope,
            'total_targets'    => count( $bookings ),
            'status'           => 'sending',
            'created_by'       => $this->actor_context->get_current_user_id(),
        ] );

        $queued = 0;
        foreach ( $bookings as $booking ) {
            if ( in_array( 'email', $channels, true ) ) {
                $queued += $this->notification_manager->queue_booking_message( (int) $booking->id, 'email', 'broadcast', '', [
                    'marketing' => true,
                    'template_override' => [
                        'subject' => sanitize_text_field( $body['subject_email'] ?? ( $body['title'] ?? 'Mensaje del negocio' ) ),
                        'body'    => (string) ( $body['message_email'] ?? '' ),
                    ],
                ], $campaign_id, $schedule_at );
            }
            if ( in_array( 'whatsapp', $channels, true ) ) {
                $wa_message = (string) ( $body['message_whatsapp'] ?? '' );
                $wa_message = preg_replace(
                    '/obwp_(cancel|reschedule|confirm)=[a-zA-Z0-9]+/',
                    '',
                    $wa_message
                );
                $queued += $this->notification_manager->queue_booking_message( (int) $booking->id, 'whatsapp', 'broadcast', '', [
                    'marketing' => true,
                    'template_override' => $wa_message,
                ], $campaign_id, $schedule_at );
            }
            if ( in_array( 'sms', $channels, true ) ) {
                $sms_message = (string) ( $body['message_sms'] ?? $body['message_whatsapp'] ?? '' );
                $sms_message = preg_replace(
                    '/obwp_(cancel|reschedule|confirm)=[a-zA-Z0-9]+/',
                    '',
                    $sms_message
                );
                if ( $this->sms_service->is_enabled() ) {
                    $queued += $this->notification_manager->queue_booking_message( (int) $booking->id, 'sms', 'broadcast', '', [
                        'marketing' => true,
                        'template_override' => $sms_message,
                    ], $campaign_id, $schedule_at );
                }
            }
        }

        return [ 'success' => true, 'campaign_id' => $campaign_id, 'queued' => $queued ];
    }
}
