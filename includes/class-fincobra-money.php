<?php
/**
 * Exact decimal helpers.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Money {
	/**
	 * Convert a positive decimal value into integer minor units without floats.
	 *
	 * @param string $amount Decimal amount.
	 * @param int    $scale Number of decimal places.
	 * @return string|null
	 */
	public static function to_minor_units( string $amount, int $scale = 2 ): ?string {
		$amount = trim( $amount );
		if ( $scale < 0 || ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\\.([0-9]+))?$/', $amount, $matches ) ) {
			return null;
		}

		$fraction = isset( $matches[1] ) ? $matches[1] : '';
		if ( strlen( $fraction ) > $scale && preg_match( '/[1-9]/', substr( $fraction, $scale ) ) ) {
			return null;
		}

		$whole    = strstr( $amount, '.', true );
		$whole    = false === $whole ? $amount : $whole;
		$fraction = substr( str_pad( $fraction, $scale, '0' ), 0, $scale );
		$minor    = ltrim( $whole . $fraction, '0' );

		return '' === $minor ? '0' : $minor;
	}

	/**
	 * Compare two decimal amounts exactly at a fixed scale.
	 *
	 * @param string $left Left amount.
	 * @param string $right Right amount.
	 * @param int    $scale Decimal places.
	 */
	public static function equal( string $left, string $right, int $scale = 2 ): bool {
		$left_minor  = self::to_minor_units( $left, $scale );
		$right_minor = self::to_minor_units( $right, $scale );

		return null !== $left_minor && null !== $right_minor && hash_equals( $left_minor, $right_minor );
	}
}
