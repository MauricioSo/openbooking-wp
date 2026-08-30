<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Compatibility shim for legacy path inspections. DATA_RETENTION is scheduled
// by OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager.
class_alias( \OpenBooking\Infrastructure\WordPress\Cron\Cron_Manager::class, \OpenBooking\Core\Infrastructure\Cron\Cron_Manager::class );
