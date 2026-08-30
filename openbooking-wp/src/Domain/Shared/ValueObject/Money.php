<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\ValueObject;

use OpenBooking\Support\Currency_Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Representa dinero en unidades menores y moneda ISO.
 */
final class Money {

    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'CLP', 'ARS', 'MXN', 'COP', 'PEN', 'BRL', 'UYU',
    ];

    public function __construct(
        private int $amountMinor,                // monto en unidades menores (>= 0)
        private string $currency,                // codigo ISO 4217 (3 chars)
    ) {
        if ( $amountMinor < 0 ) {
            throw new \InvalidArgumentException( 'Money amount cannot be negative.' );
        }
        $this->currency = strtoupper( trim( $currency ) );
        if ( ! in_array( $this->currency, self::VALID_CURRENCIES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported currency: {$this->currency}" );
        }
    }

    public static function fromMinor( int $amountMinor, string $currency ): self {
        return new self( $amountMinor, $currency );
    }

    public static function zero( string $currency ): self {
        return new self( 0, $currency );
    }

    public function amountMinor(): int {
        return $this->amountMinor;
    }

    public function amountMajor(): float {
        return Currency_Helper::minor_to_major( $this->amountMinor, $this->currency );
    }

    public function currency(): string {
        return $this->currency;
    }

    public function isZero(): bool {
        return 0 === $this->amountMinor;
    }

    public function isSameCurrency( self $other ): bool {
        return $this->currency === $other->currency;
    }

    public function equals( self $other ): bool {
        return $this->amountMinor === $other->amountMinor
            && $this->currency === $other->currency;
    }

    public function add( self $other ): self {
        $this->assertSameCurrency( $other );
        return new self( $this->amountMinor + $other->amountMinor, $this->currency );
    }

    public function subtract( self $other ): self {
        $this->assertSameCurrency( $other );
        $result = $this->amountMinor - $other->amountMinor;
        if ( $result < 0 ) {
            throw new \InvalidArgumentException( 'Result cannot be negative.' );
        }
        return new self( $result, $this->currency );
    }

    public function formatted(): string {
        return $this->currency . ' ' . Currency_Helper::format_minor( $this->amountMinor, $this->currency );
    }

    private function assertSameCurrency( self $other ): void {
        if ( ! $this->isSameCurrency( $other ) ) {
            throw new \InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
