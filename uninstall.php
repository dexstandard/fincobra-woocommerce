<?php
/**
 * Remove FinCobra credentials and scheduled actions.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'woocommerce_fincobra_settings', array() );
if ( is_array( $settings ) && isset( $settings['installation_id'], $settings['scoped_key_ciphertext'] )
	&& is_string( $settings['installation_id'] ) && is_string( $settings['scoped_key_ciphertext'] ) ) {
	require_once __DIR__ . '/includes/class-fincobra-credential-store.php';
	require_once __DIR__ . '/includes/class-fincobra-logger.php';
	require_once __DIR__ . '/includes/class-fincobra-api-client.php';

	$scoped_key = FinCobra_Credential_Store::decrypt( $settings['scoped_key_ciphertext'] );
	$api_url    = isset( $settings['api_url'] ) && is_string( $settings['api_url'] )
		? $settings['api_url']
		: 'https://fincobra.com';
	if ( null !== $scoped_key ) {
		$api = new FinCobra_Api_Client( $api_url, new FinCobra_Logger( false ) );
		$api->disconnect( $settings['installation_id'], $scoped_key );
	}
}

delete_option( 'woocommerce_fincobra_settings' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'fincobra_reconcile_order', array(), 'fincobra' );
}

wp_clear_scheduled_hook( 'fincobra_reconcile_order' );
