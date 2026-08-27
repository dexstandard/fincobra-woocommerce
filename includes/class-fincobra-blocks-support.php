<?php
/**
 * WooCommerce Cart and Checkout Blocks registration.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	protected $name = 'fincobra';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_fincobra_settings', array() );
	}

	public function is_active(): bool {
		$gateway = new FinCobra_Gateway();
		return $gateway->is_available();
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'fincobra-woocommerce-blocks';
		wp_register_script(
			$handle,
			FINCOBRA_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			FINCOBRA_WC_VERSION,
			true
		);
		return array( $handle );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => sanitize_text_field( $this->get_setting( 'title', __( 'Pay with crypto', 'fincobra-woocommerce' ) ) ),
			'description' => sanitize_text_field( $this->get_setting( 'description', __( 'Pay securely with supported cryptocurrencies.', 'fincobra-woocommerce' ) ) ),
			'supports'    => array( 'products' ),
		);
	}
}
