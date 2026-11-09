<?php
defined( 'ABSPATH' ) || exit;

/**
 * XChaCha20-Poly1305 (libsodium, bundled with PHP 7.2+ and WordPress' sodium_compat).
 *
 * - The key lives in wp-config.php (WWP_DATA_KEY), never in the database, so a stolen
 *   database dump or backup does not reveal anyone's finances.
 * - Additional data binds every ciphertext to its owner and date: copying a row to
 *   another user, or editing the date, makes decryption fail instead of showing wrong data.
 * - key_id lets a future key rotation re-encrypt old rows without guessing which key was used.
 */
final class WWP_Crypto {

	private static function key(): string {
		if ( ! defined( 'WWP_DATA_KEY' ) ) {
			throw new RuntimeException( 'WWP_DATA_KEY is not defined in wp-config.php' );
		}
		$k = base64_decode( WWP_DATA_KEY, true );
		if ( false === $k || SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== strlen( $k ) ) {
			throw new RuntimeException( 'WWP_DATA_KEY must be 32 random bytes, base64-encoded' );
		}
		return $k;
	}

	public static function key_id(): string {
		return substr( hash( 'sha256', 'wwp-key-id|' . self::key() ), 0, 12 );
	}

	public static function aad( int $user_id, string $taken_on ): string {
		return "wwp:v1|user:$user_id|date:$taken_on";
	}

	public static function seal( string $plain, string $aad ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ct    = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, $aad, $nonce, self::key() );
		return base64_encode( $nonce . $ct );
	}

	/** @return string|null plaintext, or null when the ciphertext, owner or date does not match. */
	public static function open( string $sealed, string $aad ): ?string {
		$raw = base64_decode( $sealed, true );
		$n   = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) <= $n ) {
			return null;
		}
		try {
			$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, $n ), $aad, substr( $raw, 0, $n ), self::key() );
		} catch ( SodiumException $e ) {
			return null;
		}
		return false === $pt ? null : $pt;
	}
}
