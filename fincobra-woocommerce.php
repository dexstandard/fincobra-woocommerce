<?php
/**
 * Plugin Name: FinCobra for WooCommerce
 * Plugin URI: https://fincobra.com/woocommerce
 * Description: Accept cryptocurrency through FinCobra's hosted checkout.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 10.9
 * Author: FinCobra
 * Author URI: https://fincobra.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fincobra-woocommerce
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'FINCOBRA_WC_VERSION', '0.1.0' );
define( 'FINCOBRA_WC_FILE', __FILE__ );
define( 'FINCOBRA_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FINCOBRA_WC_URL', plugin_dir_url( __FILE__ ) );

require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-money.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-status-mapper.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-signature-verifier.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-idempotency.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-invoice-validator.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-credential-store.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-logger.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-api-client.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-webhook-controller.php';
require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-reconciler.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				FINCOBRA_WC_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				FINCOBRA_WC_FILE,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>';
						echo esc_html__( 'FinCobra for WooCommerce requires WooCommerce to be installed and active.', 'fincobra-woocommerce' );
						echo '</p></div>';
					}
				}
			);
			return;
		}

		require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-gateway.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'FinCobra_Gateway';
				return $gateways;
			}
		);

		FinCobra_Webhook_Controller::register();
		FinCobra_Reconciler::register();

		if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				static function ( $registry ): void {
					require_once FINCOBRA_WC_DIR . 'includes/class-fincobra-blocks-support.php';
					$registry->register( new FinCobra_Blocks_Support() );
				}
			);
		}
	},
	20
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'FinCobra requires WooCommerce. Install and activate WooCommerce, then activate FinCobra again.', 'fincobra-woocommerce' ),
				esc_html__( 'Plugin activation failed', 'fincobra-woocommerce' ),
				array( 'back_link' => true )
			);
		}
	}
);
