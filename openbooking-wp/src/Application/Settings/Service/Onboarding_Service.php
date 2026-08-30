<?php


declare( strict_types=1 );
namespace OpenBooking\Application\Settings\Service;

use OpenBooking\Support\Setting_Keys;
use OpenBooking\Support\Option_Keys;

use OpenBooking\Domain\Shared\Port\SettingsInterface;
use OpenBooking\Domain\Shared\Port\ActorContextInterface;
use OpenBooking\Domain\Catalog\Repository\ServiceRepositoryInterface;
use OpenBooking\Domain\Availability\Repository\AvailabilityConfigRepositoryInterface;
use OpenBooking\Application\Audit\Service\Audit_Logger;
use OpenBooking\Support\Currency_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Onboarding_Service {

    public function __construct(
        private SettingsInterface $settings,
        private ServiceRepositoryInterface $service_repo,
        private AvailabilityConfigRepositoryInterface $availability_repo,
        private Audit_Logger $audit_logger,
        private ActorContextInterface $actor_context,
    ) {}

    public function execute( array $body ): array {
        if ( ! empty( $body['business_name'] ) ) {
            $this->settings->set( Setting_Keys::BUSINESS_NAME, sanitize_text_field( $body['business_name'] ) );
        }
        if ( ! empty( $body['country'] ) ) {
            $this->settings->set( Setting_Keys::BUSINESS_COUNTRY, sanitize_text_field( $body['country'] ) );
        }
        if ( ! empty( $body['currency'] ) ) {
            $currency = Currency_Helper::sanitize_supported_currency( $body['currency'] );
            if ( null === $currency ) {
                return [ 'error' => 'Moneda no soportada.', 'code' => 400 ];
            }
            $this->settings->set( Setting_Keys::BUSINESS_CURRENCY, $currency );
        }
        if ( ! empty( $body['timezone'] ) ) {
            $this->settings->set( Setting_Keys::BUSINESS_TIMEZONE, sanitize_text_field( $body['timezone'] ) );
        }
        if ( ! empty( $body['language'] ) ) {
            $this->settings->set( Setting_Keys::BUSINESS_LANGUAGE, sanitize_text_field( $body['language'] ) );
        }
        if ( ! empty( $body['payment_mode'] ) ) {
            $this->settings->set( Setting_Keys::PAYMENT_MODE, sanitize_text_field( $body['payment_mode'] ) );
        }
        if ( ! empty( $body['service_name'] ) ) {
            $service = new \OpenBooking\Domain\Catalog\Entity\Service_Entity();
            $service->name             = sanitize_text_field( $body['service_name'] );
            $service->duration_minutes = absint( $body['duration_minutes'] ?? 60 );
            $service->price_minor      = ! empty( $body['price'] ) ? absint( round( floatval( $body['price'] ) * 100 ) ) : 0;
            $service_currency = Currency_Helper::sanitize_supported_currency( $body['currency'] ?? $this->settings->get( Setting_Keys::BUSINESS_CURRENCY, 'USD' ) );
            if ( null === $service_currency ) {
                return [ 'error' => 'Moneda no soportada.', 'code' => 400 ];
            }
            $service->currency         = $service_currency;
            $service->mode             = sanitize_text_field( $body['service_mode'] ?? 'presencial' );
            $service->capacity         = absint( $body['capacity'] ?? 1 );
            $service->status           = 'active';
            $this->service_repo->insert( $service );
        }
        if ( ! empty( $body['schedule'] ) ) {
            $this->availability_repo->delete_rules_by_scope( 'global', 0 );
            foreach ( $body['schedule'] as $rule_data ) {
                $entity = \OpenBooking\Domain\Availability\Entity\AvailabilityRule_Entity::from_array( $rule_data );
                $this->availability_repo->insert_rule( $entity );
            }
        }
        $page_url = '';
        if ( ! empty( $body['publish_mode'] ) && $body['publish_mode'] === 'new_page' ) {
            $page_name = sanitize_text_field( $body['page_name'] ?? 'Reservar hora' );
            $page_id = wp_insert_post( [
                'post_title'   => $page_name,
                'post_content' => '[openbooking]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ] );
            if ( $page_id && ! is_wp_error( $page_id ) ) {
                $page_url = get_permalink( $page_id );
            }
        }
        $this->settings->set( Option_Keys::ONBOARDING_DONE, true );
        delete_transient( Option_Keys::SHOW_ONBOARDING );

        if ( ! empty( $body['preset_key'] ) ) {
            $this->settings->set( Option_Keys::ONBOARDING_PRESET, sanitize_key( $body['preset_key'] ) );
        }
        if ( ! empty( $body['cancel_min_hours'] ) ) {
            $this->settings->set( Setting_Keys::CANCEL_MIN_HOURS, absint( $body['cancel_min_hours'] ) );
        }
        if ( ! empty( $body['reminder_hours'] ) ) {
            $this->settings->set( Setting_Keys::REMINDER_HOURS_BEFORE, absint( $body['reminder_hours'] ) );
        }

        $this->audit_logger->log( [
            'entity_type' => 'settings',
            'entity_id'   => 0,
            'action'      => 'onboarding_completed',
            'actor_type'  => 'admin',
            'actor_id'    => $this->actor_context->get_current_user_id(),
            'message'     => 'Onboarding wizard completed.',
            'context'     => array_filter( [
                'business_name' => sanitize_text_field( $body['business_name'] ?? '' ),
                'country'       => sanitize_text_field( $body['country'] ?? '' ),
                'currency'      => sanitize_text_field( $body['currency'] ?? '' ),
                'timezone'      => sanitize_text_field( $body['timezone'] ?? '' ),
                'service_name'  => sanitize_text_field( $body['service_name'] ?? '' ),
                'payment_mode'  => sanitize_text_field( $body['payment_mode'] ?? '' ),
                'preset_key'    => sanitize_key( $body['preset_key'] ?? '' ),
            ] ),
        ] );

        return [
            'success'  => true,
            'redirect' => admin_url( 'admin.php?page=openbooking' ),
            'page_url' => $page_url,
        ];
    }

}
