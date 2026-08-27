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
		add_action( 'wp_enqueue_scripts', array( $this, 'register_payment_method_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_payment_method_script' ) );
	}

	public function is_active(): bool {
		$gateway = new FinCobra_Gateway();
		return $gateway->is_available();
	}

	public function register_payment_method_script(): void {
		$handle = 'fincobra-woocommerce-blocks';
		if ( wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_register_script(
			$handle,
			FINCOBRA_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			FINCOBRA_WC_VERSION,
			true
		);
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		if ( $this->can_register_payment_method_script() ) {
			$this->register_payment_method_script();
		}
		return array( 'fincobra-woocommerce-blocks' );
	}

	private function can_register_payment_method_script(): bool {
		return doing_action( 'wp_enqueue_scripts' )
			|| doing_action( 'admin_enqueue_scripts' )
			|| did_action( 'wp_enqueue_scripts' )
			|| did_action( 'admin_enqueue_scripts' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => sanitize_text_field( $this->get_setting( 'title', __( 'FinCobra', 'fincobra-woocommerce' ) ) ),
			'description' => sanitize_text_field( $this->get_setting( 'description', __( 'Continue to FinCobra to pay on a hosted checkout page.', 'fincobra-woocommerce' ) ) ),
			'supports'    => array( 'products' ),
		);
	}
}
