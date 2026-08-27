<?php
/**
 * Action Scheduler fallback for missed webhooks.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Reconciler {
	private const HOOK         = 'fincobra_reconcile_order';
	private const GROUP        = 'fincobra';
	private const MAX_ATTEMPTS = 96;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'reconcile_order' ) );
	}

	public static function schedule_order( int $order_id, bool $from_current_action = false ): void {
		$args = array( $order_id );
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( $from_current_action || ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + 300, self::HOOK, $args, self::GROUP, ! $from_current_action );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + 300, self::HOOK, $args );
		}
	}

	public static function reconcile_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'fincobra' !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}

		$invoice_id = sanitize_text_field( (string) $order->get_meta( '_fincobra_invoice_id', true ) );
		$attempts   = max( 0, (int) $order->get_meta( '_fincobra_reconcile_attempts', true ) );
		if ( '' === $invoice_id || $attempts >= self::MAX_ATTEMPTS || $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}

		$gateway = new FinCobra_Gateway();
		$result  = FinCobra_Webhook_Controller::reconcile_invoice( $invoice_id, $gateway, 'reconcile-' . ( $attempts + 1 ) );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}

		$order->update_meta_data( '_fincobra_reconcile_attempts', $attempts + 1 );
		$order->save();
		if ( is_wp_error( $result ) || $order->has_status( 'on-hold' ) ) {
			self::schedule_order( $order_id, true );
		}
	}
}
