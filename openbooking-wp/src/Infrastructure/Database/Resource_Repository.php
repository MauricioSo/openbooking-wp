<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class_alias( \OpenBooking\Infrastructure\Persistence\Catalog\Resource_Repository::class, \OpenBooking\Infrastructure\Database\Resource_Repository::class );
