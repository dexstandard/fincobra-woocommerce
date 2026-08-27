<?php
/**
 * Validates authoritative WooCommerce invoice identity.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Invoice_Validator {
	/**
	 * @param array<string, mixed> $invoice Invoice response.
	 * @param string               $invoice_id Expected invoice identifier.
	 * @param string               $installation_id Expected installation identifier.
	 * @return int|WP_Error WooCommerce order ID on success.
	 */
	public static function order_id( array $invoice, string $invoice_id, string $installation_id ) {
		$string_fields = array( 'id', 'productCode', 'integrationId', 'merchantReference', 'status' );
		foreach ( $string_fields as $field ) {
			if ( ! isset( $invoice[ $field ] ) || ! is_string( $invoice[ $field ] ) ) {
				return new WP_Error( 'fincobra_invalid_invoice', __( 'FinCobra returned an incomplete invoice.', 'fincobra-woocommerce' ), array( 'status' => 502 ) );
			}
		}

		if ( ! isset( $invoice['amountUsd'] ) || ( ! is_int( $invoice['amountUsd'] ) && ! is_float( $invoice['amountUsd'] ) )
			|| ! is_finite( (float) $invoice['amountUsd'] ) || $invoice['amountUsd'] <= 0 ) {
			return new WP_Error( 'fincobra_invalid_invoice', __( 'FinCobra returned an invalid invoice amount.', 'fincobra-woocommerce' ), array( 'status' => 502 ) );
		}

		if ( ! hash_equals( $invoice_id, $invoice['id'] )
			|| 'woocommerce' !== $invoice['productCode']
			|| ! hash_equals( $installation_id, $invoice['integrationId'] ) ) {
			return new WP_Error( 'fincobra_invoice_mismatch', __( 'Invoice identity does not match this store.', 'fincobra-woocommerce' ), array( 'status' => 409 ) );
		}

		if ( 1 !== preg_match( '/^([1-9][0-9]*)$/', $invoice['merchantReference'], $matches ) ) {
			return new WP_Error( 'fincobra_reference_mismatch', __( 'Invoice reference is invalid.', 'fincobra-woocommerce' ), array( 'status' => 409 ) );
		}

		$order_id = filter_var( $matches[1], FILTER_VALIDATE_INT );
		if ( false === $order_id || $order_id < 1 ) {
			return new WP_Error( 'fincobra_reference_mismatch', __( 'Invoice reference is invalid.', 'fincobra-woocommerce' ), array( 'status' => 409 ) );
		}

		return $order_id;
	}
}
