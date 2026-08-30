<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class_alias( \OpenBooking\Infrastructure\Persistence\Audit\Audit_Log_Repository::class, \OpenBooking\Infrastructure\Database\Audit_Log_Repository::class );
