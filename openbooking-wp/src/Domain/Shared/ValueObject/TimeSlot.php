<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\ValueObject;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa una franja horaria con zona horaria explicita.
 */
final class TimeSlot {

    public function __construct(
        private \DateTimeImmutable $start,       // inicio de la franja
        private \DateTimeImmutable $end,         // fin de la franja
        private \DateTimeZone $timezone,         // zona horaria
    ) {
        if ( $start >= $end ) {
            throw new \InvalidArgumentException(
                "TimeSlot start ({$start->format('c')}) must be before end ({$end->format('c')})."
            );
        }
    }

    /**
     * @param string $start ISO 8601 or MySQL DATETIME string
     * @param string $end   ISO 8601 or MySQL DATETIME string
     * @param string $tz    Timezone identifier (e.g. 'America/Santiago', 'UTC')
     */
    public static function fromStrings( string $start, string $end, string $tz = 'UTC' ): self {
        $timezone = new \DateTimeZone( $tz );
        $startDt  = new \DateTimeImmutable( $start, $timezone );
        $endDt    = new \DateTimeImmutable( $end, $timezone );
        return new self( $startDt, $endDt, $timezone );
    }

    public function start(): \DateTimeImmutable {
        return $this->start;
    }

    public function end(): \DateTimeImmutable {
        return $this->end;
    }

    public function timezone(): \DateTimeZone {
        return $this->timezone;
    }

    public function startAt(): string {
        return $this->start->format( 'Y-m-d H:i:s' );
    }

    public function endAt(): string {
        return $this->end->format( 'Y-m-d H:i:s' );
    }

    public function date(): string {
        return $this->start->format( 'Y-m-d' );
    }

    public function durationMinutes(): int {
        return (int) ( ( $this->end->getTimestamp() - $this->start->getTimestamp() ) / 60 );
    }

    public function overlaps( self $other ): bool {
        return $this->start < $other->end && $this->end > $other->start;
    }

    public function contains( \DateTimeImmutable $point ): bool {
        return $point >= $this->start && $point < $this->end;
    }

    public function isInPast( ?\DateTimeImmutable $now = null ): bool {
        $now = $now ?? new \DateTimeImmutable( 'now', $this->timezone );
        return $this->end <= $now;
    }

    /**
     * Return a DateTimeImmutable representing "now" in the business timezone.
     */
    public function businessNow(): \DateTimeImmutable {
        return new \DateTimeImmutable( 'now', $this->timezone );
    }

    public function dateKey(): string {
        return $this->start->format( 'Y-m-d' );
    }

    public function equals( self $other ): bool {
        return $this->start == $other->start
            && $this->end == $other->end
            && $this->timezone->getName() === $other->timezone->getName();
    }
}
