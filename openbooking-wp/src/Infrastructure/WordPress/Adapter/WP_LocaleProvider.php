<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Domain\Shared\Port\LocaleProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador para resolver locale y user locale de WordPress.
 */
class WP_LocaleProvider implements LocaleProviderInterface {
    public function get_locale(): string {
        return function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
    }

    public function get_user_locale(): string {
        return function_exists( 'get_user_locale' ) ? (string) get_user_locale() : $this->get_locale();
    }
}
