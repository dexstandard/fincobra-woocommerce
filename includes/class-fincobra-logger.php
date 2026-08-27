<?php
/**
 * Redacted WooCommerce logger.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Logger {
	private bool $enabled;

	public function __construct( bool $enabled ) {
		$this->enabled = $enabled;
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Safe structured context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Safe structured context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * @param string               $level Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Safe structured context.
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( ! $this->enabled || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$allowed = array_intersect_key(
			$context,
			array_flip( array( 'order_id', 'invoice_id', 'event_id', 'status', 'http_status' ) )
		);
		wc_get_logger()->log(
			$level,
			$message . ( array() === $allowed ? '' : ' ' . wp_json_encode( $allowed ) ),
			array( 'source' => 'fincobra' )
		);
	}
}
