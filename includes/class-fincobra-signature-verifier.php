<?php
/**
 * Webhook signature validation.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Signature_Verifier {
	/**
	 * @param string $body Raw request bytes.
	 * @param string $signature Lowercase hex HMAC.
	 * @param string $secret Webhook secret.
	 */
	public static function verify(
		string $body,
		string $signature,
		string $secret
	): bool {
		if ( '' === $secret ) {
			return false;
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Validate an ISO-8601 webhook creation time.
	 *
	 * @param string $created_at Signed event creation time.
	 * @param int    $now Current Unix timestamp.
	 * @param int    $tolerance Maximum clock skew.
	 */
	public static function is_fresh( string $created_at, int $now, int $tolerance = 300 ): bool {
		if ( $tolerance < 0
			|| 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $created_at ) ) {
			return false;
		}

		try {
			$created = new DateTimeImmutable( $created_at );
		} catch ( Exception $error ) {
			unset( $error );
			return false;
		}

		return abs( $now - $created->getTimestamp() ) <= $tolerance;
	}
}
