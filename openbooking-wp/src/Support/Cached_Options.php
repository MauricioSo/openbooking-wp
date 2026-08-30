<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cachea opciones de WordPress para reducir consultas repetidas.
 */
class Cached_Options {

    private const CACHE_GROUP = 'openbooking_options';
    private const DEFAULT_TTL = 300;

    /**
     * Get an option value, reading through wp_cache first.
     */
    public static function get( string $key, $default = null ) {
        $cache_key = "obwp:{$key}";
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

        if ( false !== $cached ) {
            return $cached;
        }

        $value = get_option( $key, $default );
        wp_cache_set( $cache_key, $value, self::CACHE_GROUP, self::DEFAULT_TTL );

        return $value;
    }

    /**
     * Invalidate the cache for a single option key.
     * Called automatically by the "updated_option" hook.
     * Only acts on obwp_* options to avoid unnecessary cache operations.
     */
    public static function invalidate( string $key ): void {
        if ( 0 !== strpos( $key, 'obwp_' ) ) {
            return;
        }
        wp_cache_delete( "obwp:{$key}", self::CACHE_GROUP );
    }

    /**
     * Invalidate all cached options for this plugin.
     * Only needed in extreme cases (bulk import, reset).
     */
    public static function invalidate_all(): void {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
        }
    }
}
