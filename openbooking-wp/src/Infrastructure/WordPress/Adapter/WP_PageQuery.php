<?php

declare( strict_types=1 );

namespace OpenBooking\Infrastructure\WordPress\Adapter;

use OpenBooking\Domain\Shared\Port\PageQueryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adaptador para buscar paginas publicadas por contenido.
 */
class WP_PageQuery implements PageQueryInterface {
    public function find_published_pages_containing( string $content, int $limit = 1 ): array {
        if ( ! function_exists( 'get_posts' ) ) {
            return [];
        }

        return get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $content,
        ] );
    }
}
