<?php

declare( strict_types=1 );

namespace OpenBooking\Domain\Shared\ValueObject;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encapsula una direccion de email valida.
 */
final class Email {

    private string $value;

    public function __construct(
        string $email
    ) {
        $email = trim( $email );
        if ( '' === $email ) {
            throw new \InvalidArgumentException( 'Email cannot be empty.' );
        }
        if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            throw new \InvalidArgumentException( "Invalid email address: {$email}" );
        }
        $this->value = strtolower( $email );
    }

    public static function fromString( string $email ): self {
        return new self( $email );
    }

    public static function tryFromString( string $email ): ?self {
        try {
            return new self( $email );
        } catch ( \InvalidArgumentException $e ) {
            return null;
        }
    }

    public function toString(): string {
        return $this->value;
    }

    public function equals( self $other ): bool {
        return $this->value === $other->value;
    }

    public function __toString(): string {
        return $this->value;
    }
}
