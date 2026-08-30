<?php

declare( strict_types=1 );

namespace OpenBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM authenticated encryption for sensitive wp_options values.
 *
 * Encrypted values are prefixed so legacy formats are transparently
 * handled during migration:
 *
 *   "enc2:" — current format, AES-256-GCM (authenticated).
 *   "enc:"  — legacy format, AES-256-CBC (no authentication, kept for decrypt only).
 *   (none)  — plaintext legacy value, returned as-is until re-saved.
 *
 * Key derivation: uses OBWP_ENCRYPTION_KEY constant if defined in wp-config.php,
 * otherwise falls back to WordPress AUTH_KEY (already present in every install).
 *
 * Usage:
 *   update_option( Setting_Keys::STRIPE_SECRET_KEY, Crypto::encrypt( $value ) );
 *   $key = Crypto::decrypt( get_option( Setting_Keys::STRIPE_SECRET_KEY, '' ) );
 */
class Crypto {

	private const PREFIX_LEGACY = 'enc:';
	private const PREFIX_AEAD   = 'enc2:';
	private const CIPHER_AEAD   = 'aes-256-gcm';
	private const CIPHER_LEGACY = 'aes-256-cbc';

	private static function master_key(): string {
		$raw = defined( 'OBWP_ENCRYPTION_KEY' ) && OBWP_ENCRYPTION_KEY
			? OBWP_ENCRYPTION_KEY
			: ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'obwp-fallback-no-auth-key' );

		return hash( 'sha256', $raw, true ); // 32-byte binary key for AES-256
	}

	/**
	 * Encrypts a plaintext string using AES-256-GCM.
	 *
	 * Returns a string prefixed with "enc2:" containing the nonce, ciphertext
	 * and authentication tag. If OpenSSL is unavailable, returns an empty
	 * string rather than storing plaintext.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$nonce_len = openssl_cipher_iv_length( self::CIPHER_AEAD );
		if ( $nonce_len === false ) {
			return '';
		}

		$nonce = random_bytes( $nonce_len );
		$tag   = '';

		$ct = openssl_encrypt(
			$plaintext,
			self::CIPHER_AEAD,
			self::master_key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag
		);

		if ( $ct === false || $tag === '' ) {
			return '';
		}

		return self::PREFIX_AEAD . base64_encode( $nonce . $tag . $ct );
	}

	/**
	 * Decrypts a value encrypted by encrypt() or a legacy "enc:" value.
	 *
	 * Unprefixed values are returned as-is (legacy plaintext) so existing
	 * installations keep working until the option is re-saved through the
	 * admin UI, at which point it is upgraded to "enc2:".
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( str_starts_with( $value, self::PREFIX_AEAD ) ) {
			return self::decrypt_aead( $value );
		}

		if ( str_starts_with( $value, self::PREFIX_LEGACY ) ) {
			return self::decrypt_legacy( $value );
		}

		return $value; // legacy plaintext — return unchanged
	}

	private static function decrypt_aead( string $value ): string {
		$raw = base64_decode( substr( $value, strlen( self::PREFIX_AEAD ) ), true );

		$nonce_len = openssl_cipher_iv_length( self::CIPHER_AEAD );
		$tag_len   = 16; // GCM tag is always 16 bytes

		if ( $raw === false || strlen( $raw ) <= $nonce_len + $tag_len ) {
			return '';
		}

		$nonce = substr( $raw, 0, $nonce_len );
		$tag   = substr( $raw, $nonce_len, $tag_len );
		$ct    = substr( $raw, $nonce_len + $tag_len );

		$pt = openssl_decrypt(
			$ct,
			self::CIPHER_AEAD,
			self::master_key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag
		);

		return $pt !== false ? $pt : '';
	}

	private static function decrypt_legacy( string $value ): string {
		$raw = base64_decode( substr( $value, strlen( self::PREFIX_LEGACY ) ), true );

		$iv_len = openssl_cipher_iv_length( self::CIPHER_LEGACY );
		if ( $raw === false || strlen( $raw ) <= $iv_len ) {
			return '';
		}

		$iv = substr( $raw, 0, $iv_len );
		$ct = substr( $raw, $iv_len );

		$pt = openssl_decrypt( $ct, self::CIPHER_LEGACY, self::master_key(), OPENSSL_RAW_DATA, $iv );

		return $pt !== false ? $pt : '';
	}

	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX_AEAD )
			|| str_starts_with( $value, self::PREFIX_LEGACY );
	}

	/**
	 * Returns true if the value uses the legacy unauthenticated CBC format
	 * and should be re-encrypted with encrypt() on next save.
	 */
	public static function needs_upgrade( string $value ): bool {
		return str_starts_with( $value, self::PREFIX_LEGACY );
	}

	/**
	 * Decrypts and, if the value used legacy encryption, returns the
	 * re-encrypted value so the caller can transparently upgrade.
	 *
	 * Usage:
	 *   $value = get_option( $key, '' );
	 *   [ $plain, $upgraded ] = Crypto::decrypt_and_upgrade( $value );
	 *   if ( $upgraded !== null ) update_option( $key, $upgraded );
	 *
	 * @return array{0:string,1:?string} [plaintext, upgraded_encrypted_or_null]
	 */
	public static function decrypt_and_upgrade( string $value ): array {
		if ( ! self::needs_upgrade( $value ) ) {
			return [ self::decrypt( $value ), null ];
		}

		$plaintext = self::decrypt( $value );

		if ( $plaintext === '' ) {
			return [ '', null ];
		}

		$upgraded = self::encrypt( $plaintext );

		return [ $plaintext, $upgraded !== '' ? $upgraded : null ];
	}
}
