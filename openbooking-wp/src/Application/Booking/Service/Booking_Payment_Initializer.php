<?php

declare( strict_types=1 );

namespace OpenBooking\Application\Booking\Service;

use OpenBooking\Support\Setting_Keys;

use OpenBooking\Domain\Catalog\Entity\Service_Entity;
use OpenBooking\Domain\Shared\Port\SettingsInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calcula el importe a cobrar para una reserva.
 */
class Booking_Payment_Initializer {


    public function __construct(
        private SettingsInterface $settings,
    ) {}

    public function compute( Service_Entity $service ): array {
        $payment_mode = $this->get_payment_mode();
        $deposit_pct  = max( 1, min( 100, $this->get_deposit_percent() ) );

        $price_due_now = ( $payment_mode === 'deposit' && $service->price_minor > 0 )
            ? (int) round( $service->price_minor * $deposit_pct / 100 )
            : $service->price_minor;

        return [
            'price_total_minor'   => $service->price_minor,
            'price_due_now_minor' => $price_due_now,
            'price_paid_minor'    => 0,
            'currency'            => $service->currency,
            'payment_mode'        => $payment_mode,
        ];
    }

    protected function get_payment_mode(): string {
        return (string) $this->settings->get( Setting_Keys::PAYMENT_MODE, 'full' );
    }

    protected function get_deposit_percent(): int {
        return (int) $this->settings->get( Setting_Keys::DEPOSIT_PERCENT, 30 );
    }
}
