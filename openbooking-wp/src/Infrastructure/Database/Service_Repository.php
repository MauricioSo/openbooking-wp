<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class_alias( \OpenBooking\Infrastructure\Persistence\Catalog\Service_Repository::class, \OpenBooking\Infrastructure\Database\Service_Repository::class );
