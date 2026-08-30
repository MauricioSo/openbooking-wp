<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\ValueObject;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Modela capacidad total y cupos ocupados.
 */
final class Capacity {

    public function __construct(
        private int $total,                       // cupos maximos (>= 1)
        private int $booked = 0,                  // cupos ocupados
    ) {
        if ( $total < 1 ) {
            throw new \InvalidArgumentException( 'Total capacity must be at least 1.' );
        }
        if ( $booked < 0 || $booked > $total ) {
            throw new \InvalidArgumentException(
                "Booked ({$booked}) must be between 0 and total ({$total})."
            );
        }
    }

    public static function of( int $total, int $booked = 0 ): self {
        return new self( $total, $booked );
    }

    public function total(): int {
        return $this->total;
    }

    public function booked(): int {
        return $this->booked;
    }

    public function available(): int {
        return $this->total - $this->booked;
    }

    public function isFull(): bool {
        return $this->available() <= 0;
    }

    public function hasAvailability(): bool {
        return ! $this->isFull();
    }

    public function withBooked( int $booked ): self {
        return new self( $this->total, $booked );
    }

    public function decrement(): self {
        if ( $this->isFull() ) {
            throw new \InvalidArgumentException( 'Cannot decrement: capacity is full.' );
        }
        return new self( $this->total, $this->booked + 1 );
    }

    public function increment(): self {
        if ( $this->booked <= 0 ) {
            throw new \InvalidArgumentException( 'Cannot increment: nothing booked.' );
        }
        return new self( $this->total, $this->booked - 1 );
    }
}
