<?php
/**
 * Stable invoice idempotency keys.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Idempotency {
	/**
	 * @param string $installation_id Installation identifier.
	 * @param int    $order_id WooCommerce order identifier.
	 * @param int    $attempt Payment attempt, starting at one.
	 */
	public static function key( string $installation_id, int $order_id, int $attempt ): ?string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,96}$/', $installation_id ) || $order_id < 1 || $attempt < 1 ) {
			return null;
		}

		return implode( ':', array( 'woocommerce', $installation_id, (string) $order_id, (string) $attempt ) );
	}
}
