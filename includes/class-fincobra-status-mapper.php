<?php
/**
 * Maps FinCobra invoice states to safe WooCommerce actions.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Status_Mapper {
	public const COMPLETE = 'complete';
	public const PENDING  = 'pending';
	public const FAIL     = 'fail';
	public const IGNORE   = 'ignore';

	/**
	 * @param string $status FinCobra invoice status.
	 */
	public static function action_for( string $status ): string {
		$normalized = strtolower( trim( $status ) );

		if ( in_array( $normalized, array( 'confirmed', 'paid_out_of_band' ), true ) ) {
			return self::COMPLETE;
		}

		if ( in_array( $normalized, array( 'awaiting_payment', 'partially_paid', 'payment_detected' ), true ) ) {
			return self::PENDING;
		}

		if ( in_array( $normalized, array( 'expired', 'voided' ), true ) ) {
			return self::FAIL;
		}

		return self::IGNORE;
	}
}
