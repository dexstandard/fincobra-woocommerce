<?php
/**
 * Signed FinCobra webhook endpoint.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Webhook_Controller {
	private const REPLAY_TTL = 604800;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	public static function register_route(): void {
		register_rest_route(
			'fincobra/v1',
			'/webhooks',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Webhook request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		$gateway = new FinCobra_Gateway();
		$secret  = $gateway->webhook_secret();
		if ( null === $secret || '' === $gateway->installation_id() ) {
			return new WP_Error( 'fincobra_not_connected', __( 'FinCobra is not connected.', 'fincobra-woocommerce' ), array( 'status' => 503 ) );
		}

		$body      = $request->get_body();
		$signature = trim( (string) $request->get_header( 'x-checkout-signature' ) );
		if ( ! FinCobra_Signature_Verifier::verify( $body, $signature, $secret ) ) {
			return new WP_Error( 'fincobra_invalid_signature', __( 'Invalid webhook signature.', 'fincobra-woocommerce' ), array( 'status' => 401 ) );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['createdAt'], $event['event'], $event['invoice'] )
			|| ! is_string( $event['id'] ) || ! is_string( $event['createdAt'] ) || ! is_string( $event['event'] )
			|| ! is_array( $event['invoice'] ) || ! isset( $event['invoice']['id'], $event['invoice']['integrationId'] )
			|| ! is_string( $event['invoice']['id'] ) || ! is_string( $event['invoice']['integrationId'] ) ) {
			return new WP_Error( 'fincobra_invalid_payload', __( 'Invalid webhook payload.', 'fincobra-woocommerce' ), array( 'status' => 400 ) );
		}

		$event_id = sanitize_text_field( $event['id'] );
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $event_id )
			|| ! hash_equals( $event_id, $event['id'] ) ) {
			return new WP_Error( 'fincobra_invalid_event', __( 'Webhook event identifier is invalid.', 'fincobra-woocommerce' ), array( 'status' => 400 ) );
		}

		$replay_key = 'fincobra_event_' . hash( 'sha256', $gateway->installation_id() . '|' . $event_id );
		if ( false !== get_transient( $replay_key ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		if ( ! FinCobra_Signature_Verifier::is_fresh( $event['createdAt'], time() ) ) {
			return new WP_Error( 'fincobra_stale_event', __( 'Webhook event is outside the replay window.', 'fincobra-woocommerce' ), array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $gateway->installation_id(), $event['invoice']['integrationId'] ) ) {
			return new WP_Error( 'fincobra_wrong_installation', __( 'Webhook installation does not match this store.', 'fincobra-woocommerce' ), array( 'status' => 403 ) );
		}

		$result = self::reconcile_invoice( sanitize_text_field( $event['invoice']['id'] ), $gateway, $event_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( $replay_key, 1, self::REPLAY_TTL );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Fetch and apply authoritative invoice state.
	 *
	 * @param string            $invoice_id Invoice identifier.
	 * @param FinCobra_Gateway $gateway Gateway.
	 * @param string            $event_id Event identifier or reconciliation marker.
	 * @return true|WP_Error
	 */
	public static function reconcile_invoice( string $invoice_id, FinCobra_Gateway $gateway, string $event_id ) {
		$scoped_key = $gateway->scoped_key();
		if ( null === $scoped_key ) {
			return new WP_Error( 'fincobra_credentials_unavailable', __( 'FinCobra credentials are unavailable.', 'fincobra-woocommerce' ), array( 'status' => 503 ) );
		}

		$invoice = $gateway->api_client()->get_invoice( $invoice_id, $scoped_key );
		if ( is_wp_error( $invoice ) ) {
			return new WP_Error( 'fincobra_invoice_unavailable', __( 'Could not verify the invoice.', 'fincobra-woocommerce' ), array( 'status' => 503 ) );
		}

		$validated = FinCobra_Invoice_Validator::order_id( $invoice, $invoice_id, $gateway->installation_id() );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$order_id = $validated;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'fincobra' !== $order->get_payment_method() ) {
			return new WP_Error( 'fincobra_order_not_found', __( 'Matching WooCommerce order not found.', 'fincobra-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( ! hash_equals( $invoice_id, (string) $order->get_meta( '_fincobra_invoice_id', true ) )
			|| ! hash_equals( (string) $invoice['merchantReference'], (string) $order->get_meta( '_fincobra_merchant_reference', true ) )
			|| ! FinCobra_Money::equal( (string) $invoice['amountUsd'], wc_format_decimal( $order->get_total(), 2 ) ) ) {
			return new WP_Error( 'fincobra_invoice_mismatch', __( 'Invoice details do not match the WooCommerce order.', 'fincobra-woocommerce' ), array( 'status' => 409 ) );
		}

		$action = FinCobra_Status_Mapper::action_for( (string) $invoice['status'] );
		if ( FinCobra_Status_Mapper::COMPLETE === $action ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $invoice_id );
				$order->add_order_note( __( 'FinCobra payment confirmed.', 'fincobra-woocommerce' ) );
			}
		} elseif ( FinCobra_Status_Mapper::PENDING === $action ) {
			if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
				$order->update_status( 'on-hold', __( 'FinCobra is still confirming the payment.', 'fincobra-woocommerce' ) );
			}
		} elseif ( FinCobra_Status_Mapper::FAIL === $action ) {
			if ( ! $order->is_paid() && ! $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
				$order->update_status( 'failed', __( 'FinCobra invoice expired or was voided. The customer may retry payment.', 'fincobra-woocommerce' ) );
			}
		}

		$order->update_meta_data( '_fincobra_last_status', sanitize_text_field( (string) $invoice['status'] ) );
		$order->update_meta_data( '_fincobra_last_event_id', sanitize_text_field( $event_id ) );
		$order->save();
		return true;
	}

}
