<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para resolver locales.
 */
interface LocaleProviderInterface {
    public function get_locale(): string;
    public function get_user_locale(): string;
}
