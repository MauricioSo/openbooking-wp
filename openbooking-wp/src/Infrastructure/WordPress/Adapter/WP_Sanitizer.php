<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Domain\Shared\Port\SanitizerInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador de sanitizacion sobre helpers nativos de WordPress.
 */
class WP_Sanitizer implements SanitizerInterface {
    public function text( $value ): string {
        return \sanitize_text_field( (string) $value );
    }

    public function textarea( $value ): string {
        return \sanitize_textarea_field( (string) $value );
    }

    public function key( $value ): string {
        return \sanitize_key( (string) $value );
    }

    public function email( $value ): string {
        return \sanitize_email( (string) $value );
    }

    public function absint( $value ): int {
        return \absint( $value );
    }
}
