<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\Notification;

use OpenBooking\Infrastructure\Persistence\Notification\Notification_Queue_Repository;
use OpenBooking\Infrastructure\Persistence\Notification\Notification_Preferences_Repository;
use OpenBooking\Infrastructure\Persistence\Notification\Notification_Campaign_Repository;
use OpenBooking\Infrastructure\Persistence\Notification\Notification_Log_Repository;
use OpenBooking\Infrastructure\Persistence\Notification\Consent_Log_Repository;
use OpenBooking\Support\Setting_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orquesta la cola, plantillas y campañas de notificaciones.
 */
class Notification_Manager implements \OpenBooking\Domain\Notification\Service\NotificationManagerInterface {


    public function __construct(
        private Notification_Queue_Repository $queue_repo, // persiste cola de notificaciones
        private Notification_Preferences_Repository $preferences_repo, // persiste preferencias de canal
        private Notification_Campaign_Repository $campaign_repo, // persiste campanas
        private Notification_Log_Repository $log_repo, // persiste log de notificaciones
        private \OpenBooking\Infrastructure\Persistence\Booking\Booking_Repository $booking_repo, // persiste reservas en WP
        private \OpenBooking\Infrastructure\Persistence\Customer\Customer_Repository $customer_repo, // persiste clientes en WP
        private \OpenBooking\Infrastructure\Notification\Email\Email_Service $email_service, // servicio de envio de email
        private \OpenBooking\Infrastructure\Notification\WhatsApp\WhatsApp_Service $wa_service, // servicio de envio de WhatsApp
        private \OpenBooking\Infrastructure\Notification\SMS\SMS_Service $sms_service, // servicio de envio de SMS
        private Consent_Log_Repository $consent_repo, // persiste consentimientos
    ) {}

    public function queue_event( string $event, int $booking_id, array $extra = [] ): int {
        $count = 0;
        switch ( $event ) {
            case 'booking_confirmed':
                $count += $this->queue_booking_message( $booking_id, 'email', 'booking_confirmed', '', $extra );
                $count += $this->queue_booking_message( $booking_id, 'email', 'new_booking_admin', \get_bloginfo( 'admin_email' ), $extra );
                if ( $this->wa_service->is_enabled() ) {
                    $count += $this->queue_booking_message( $booking_id, 'whatsapp', 'booking_confirmed', '', $extra );
                    if ( \get_option( Setting_Keys::WHATSAPP_NOTIFY_ADMIN ) && \get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE ) ) {
                        $count += $this->queue_booking_message( $booking_id, 'whatsapp', 'new_booking_admin', (string) \get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE ), $extra );
                    }
                }
                if ( $this->sms_service->is_enabled() ) {
                    $count += $this->queue_booking_message( $booking_id, 'sms', 'booking_confirmed', '', $extra, null, null, 'booking_confirmed:' . $booking_id . ':sms' );
                }
                break;
            case 'booking_created_pending_admin':
                $count += $this->queue_booking_message( $booking_id, 'email', 'new_booking_admin', \get_bloginfo( 'admin_email' ), $extra );
                break;
            case 'booking_cancelled':
            case 'booking_cancelled_by_admin':
            case 'booking_rescheduled':
            case 'booking_expired':
            case 'payment_received':
                $template_key = $event;
                if ( 'payment_received' === $event ) {
                    $template_key = 'payment_received';
                }
                $count += $this->queue_booking_message( $booking_id, 'email', $template_key, '', $extra );
                if ( $this->wa_service->is_enabled() ) {
                    $count += $this->queue_booking_message( $booking_id, 'whatsapp', $template_key, '', $extra );
                }
                if ( $this->sms_service->is_enabled() ) {
                    $count += $this->queue_booking_message( $booking_id, 'sms', $template_key, '', $extra );
                }
                break;
            case 'reminder_customer':
                $count += $this->queue_booking_message( $booking_id, 'email', 'reminder_customer', '', $extra );
                if ( $this->wa_service->is_enabled() ) {
                    $count += $this->queue_booking_message( $booking_id, 'whatsapp', 'reminder_customer', '', $extra );
                }
                if ( $this->sms_service->is_enabled() ) {
                    $count += $this->queue_booking_message( $booking_id, 'sms', 'reminder_customer', '', $extra );
                }
                break;
        }

        return $count;
    }

    public function queue_booking_message( int $booking_id, string $channel, string $template_key, string $recipient = '', array $payload = [], ?int $campaign_id = null, ?string $scheduled_at = null, ?string $dedupe_key = null ): int {
        if ( 'whatsapp' === $channel && ! $this->wa_service->is_enabled() ) {
            return 0;
        }
        if ( 'sms' === $channel && ! $this->sms_service->is_enabled() ) {
            return 0;
        }

        $booking = $this->booking_repo->find( $booking_id );
        if ( ! $booking ) {
            return 0;
        }
        $customer = $this->customer_repo->find( $booking->customer_id );

        if ( '' === $recipient ) {
            if ( 'email' === $channel ) {
                $recipient = 'new_booking_admin' === $template_key ? \get_bloginfo( 'admin_email' ) : ( $customer ? (string) $customer->email : '' );
            } elseif ( 'sms' === $channel ) {
                $recipient = $customer ? (string) $customer->phone : '';
            } else {
                $recipient = 'new_booking_admin' === $template_key ? (string) \get_option( Setting_Keys::WHATSAPP_ADMIN_PHONE, '' ) : ( $customer ? (string) $customer->phone : '' );
            }
        }

        if ( '' === trim( $recipient ) ) {
            return 0;
        }

        $inserted = $this->queue_repo->enqueue( [
            'booking_id'   => $booking_id,
            'campaign_id'  => $campaign_id,
            'dedupe_key'   => $dedupe_key,
            'customer_id'  => $booking->customer_id,
            'channel'      => $channel,
            'template_key' => $template_key,
            'priority'     => $this->resolve_priority( $template_key ),
            'recipient'    => $recipient,
            'scheduled_at' => $scheduled_at ?: \current_time( 'mysql' ),
            'status'       => 'pending',
            'max_attempts' => 3,
            'payload'      => $payload,
        ] );

        return $inserted > 0 ? 1 : 0;
    }

    public function process_queue( int $limit = 25 ): int {
        $this->queue_repo->recover_stale_processing();

        $rows = $this->queue_repo->claim_due( $limit );
        if ( ! $rows ) {
            return 0;
        }

        $processed = 0;
        foreach ( $rows as $row ) {
            $payload = ! empty( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : [];
            $payload = is_array( $payload ) ? $payload : [];

            $skip_reason = $this->get_skip_reason( $row, $payload );
            if ( $skip_reason ) {
                $this->queue_repo->mark_skipped( (int) $row['id'], $skip_reason );
                $this->sync_campaign( $row );
                $processed++;
                continue;
            }

            $success = false;
            if ( 'email' === $row['channel'] ) {
                $success = $this->email_service->send( $row['template_key'], (int) $row['booking_id'], $payload['extra_data'] ?? [], [
                    'queue_id'         => (int) $row['id'],
                    'campaign_id'      => isset( $row['campaign_id'] ) ? (int) $row['campaign_id'] : null,
                    'attempts'         => (int) $row['attempts'],
                    'payload_context'  => $payload,
                    'template_override'=> $payload['template_override'] ?? null,
                ] );
            } elseif ( 'whatsapp' === $row['channel'] ) {
                $success = $this->wa_service->send( $row['template_key'], (int) $row['booking_id'], $payload['extra_data'] ?? [], [
                    'queue_id'         => (int) $row['id'],
                    'campaign_id'      => isset( $row['campaign_id'] ) ? (int) $row['campaign_id'] : null,
                    'attempts'         => (int) $row['attempts'],
                    'payload_context'  => $payload,
                    'template_override'=> $payload['template_override'] ?? null,
                ] );
            } elseif ( 'sms' === $row['channel'] ) {
                $success = $this->sms_service->send( $row['template_key'], (int) $row['booking_id'], $payload['extra_data'] ?? [], [
                    'queue_id'         => (int) $row['id'],
                    'campaign_id'      => isset( $row['campaign_id'] ) ? (int) $row['campaign_id'] : null,
                    'attempts'         => (int) $row['attempts'],
                    'recipient'        => (string) $row['recipient'],
                    'payload_context'  => $payload,
                    'template_override'=> $payload['template_override'] ?? null,
                ] );
            }

            if ( $success ) {
                $this->queue_repo->mark_sent( (int) $row['id'] );
            } else {
                $this->queue_repo->mark_failed( (int) $row['id'], (int) $row['attempts'], (int) $row['max_attempts'], 'Delivery failed.' );
                if ( (int) $row['attempts'] >= (int) $row['max_attempts'] ) {
                    do_action( 'openbooking_notification_permanently_failed', $row );
                }
            }

            $this->sync_campaign( $row );
            $processed++;
        }

        return $processed;
    }

    public function create_campaign( array $data ): int {
        return $this->campaign_repo->create( $data );
    }

    public function get_skip_reason( array $row, array $payload = [] ): ?string {
        $customer_id = \absint( $row['customer_id'] ?? 0 );
        if ( ! $customer_id ) {
            return null;
        }

        $preferences = $this->preferences_repo->get_or_create( $customer_id );
        $template_key = (string) $row['template_key'];
        $marketing = ! empty( $payload['marketing'] );

        if ( 'email' === $row['channel'] && empty( $preferences['channel_email'] ) ) {
            return 'Customer opted out from email notifications.';
        }
        if ( 'whatsapp' === $row['channel'] && empty( $preferences['channel_whatsapp'] ) ) {
            return 'Customer opted out from WhatsApp notifications.';
        }
        if ( 'sms' === $row['channel'] && empty( $preferences['channel_sms'] ?? 1 ) ) {
            return 'Customer opted out from SMS notifications.';
        }
        if ( false !== strpos( $template_key, 'reminder' ) && empty( $preferences['reminders'] ) ) {
            return 'Customer opted out from reminders.';
        }
        if ( $marketing && empty( $preferences['marketing'] ) ) {
            return 'Customer opted out from marketing notifications.';
        }
        if ( $marketing ) {
            $channel_map = [ 'email' => 'email', 'whatsapp' => 'whatsapp', 'sms' => 'sms' ];
            $consent_channel = $channel_map[ $row['channel'] ] ?? $row['channel'];
            if ( ! $this->consent_repo->has_consent( $customer_id, $consent_channel, 'marketing' ) ) {
                return 'No marketing consent recorded for this channel.';
            }
        }

        return null;
    }

    public function record_consent( int $customer_id, string $channel, string $purpose, string $action, string $source = '' ): int {
        $result = 0;
        if ( 'opted_in' === $action ) {
            $result = $this->consent_repo->record_opt_in( $customer_id, $channel, $purpose, $source );
        } else {
            $result = $this->consent_repo->record_opt_out( $customer_id, $channel, $purpose, $source );
        }

        $prefs_update = [];
        if ( 'marketing' === $purpose ) {
            $prefs_update['marketing'] = 'opted_in' === $action;
        }
        if ( 'email' === $channel && 'transactional' === $purpose ) {
            $prefs_update['channel_email'] = 'opted_in' === $action;
        }
        if ( 'whatsapp' === $channel && 'transactional' === $purpose ) {
            $prefs_update['channel_whatsapp'] = 'opted_in' === $action;
        }
        if ( 'sms' === $channel && 'transactional' === $purpose ) {
            $prefs_update['channel_sms'] = 'opted_in' === $action;
        }
        if ( ! empty( $prefs_update ) ) {
            $this->preferences_repo->upsert( $customer_id, $prefs_update );
        }

        return $result;
    }

    private function sync_campaign( array $row ): void {
        $campaign_id = isset( $row['campaign_id'] ) ? \absint( $row['campaign_id'] ) : 0;
        if ( $campaign_id > 0 ) {
            $this->campaign_repo->update_progress( $campaign_id );
        }
    }

    private function resolve_priority( string $template_key ): int {
        $critical = [ 'booking_confirmed', 'payment_received' ];
        $low      = [ 'reminder_customer', 'new_booking_admin', 'broadcast' ];

        if ( in_array( $template_key, $critical, true ) ) {
            return Notification_Queue_Repository::PRIORITY_CRITICAL;
        }
        if ( in_array( $template_key, $low, true ) ) {
            return Notification_Queue_Repository::PRIORITY_LOW;
        }
        return Notification_Queue_Repository::PRIORITY_NORMAL;
    }
}
