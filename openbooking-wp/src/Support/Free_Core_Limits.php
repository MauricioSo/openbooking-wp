<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Limites comerciales del Core Free. Plugins externos pueden ampliarlos via filtros.
 */
class Free_Core_Limits {

    public const ACTIVE_RESOURCES = 2;

    public static function active_resources(): int {
        return max( 1, (int) apply_filters( 'openbooking_free_limit_active_resources', self::ACTIVE_RESOURCES ) );
    }
}
