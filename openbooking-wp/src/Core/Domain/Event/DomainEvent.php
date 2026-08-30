<?php
declare( strict_types=1 );
namespace OpenBooking\Core\Domain\Event;
if ( ! defined( 'ABSPATH' ) ) { exit; }
interface DomainEvent extends \OpenBooking\Domain\Shared\Event\DomainEvent {}
