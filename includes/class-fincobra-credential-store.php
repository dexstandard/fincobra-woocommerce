<?php
/**
 * Encrypts store credentials before WordPress persists gateway settings.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Credential_Store {
	private const PREFIX = 'v1:';

	/**
	 * @param string $plaintext Credential.
	 * @return string|\WP_Error
	 */
	public static function encrypt( string $plaintext ) {
		if ( '' === $plaintext || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return new \WP_Error( 'fincobra_crypto_unavailable', __( 'Secure credential storage is unavailable on this server.', 'fincobra-woocommerce' ) );
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * @param string $encoded Encrypted credential.
	 */
	public static function decrypt( string $encoded ): ?string {
		if ( ! str_starts_with( $encoded, self::PREFIX ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}

		$decoded = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true );
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::key() );

		return false === $plaintext ? null : $plaintext;
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|fincobra-woocommerce', true );
	}
}
