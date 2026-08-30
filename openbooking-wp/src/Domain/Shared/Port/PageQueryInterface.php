<?php

declare(strict_types=1);

namespace OpenBooking\Domain\Shared\Port;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contrato para buscar paginas publicadas.
 */
interface PageQueryInterface {
    public function find_published_pages_containing( string $content, int $limit = 1 ): array;
}
