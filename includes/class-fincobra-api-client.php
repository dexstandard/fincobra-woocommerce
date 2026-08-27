<?php
/**
 * FinCobra REST API client.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Api_Client {
	public const BILLING_PLAN_URL = 'https://fincobra.com/woocommerce';

	private const INSTALLATIONS_PATH = '/api/checkout/woocommerce/installations';
	private const INVOICES_PATH      = '/api/checkout/woocommerce/invoices';
	private const MISSING_BILLING_PLAN_MARKER = 'Select a WooCommerce billing plan';

	private string $base_url;
	private FinCobra_Logger $logger;

	public function __construct( string $base_url, FinCobra_Logger $logger ) {
		$this->base_url = untrailingslashit( esc_url_raw( $base_url ) );
		$this->logger   = $logger;
	}

	/**
	 * Exchange a merchant API key for store-scoped credentials.
	 *
	 * @param string $merchant_api_key Merchant API key, never persisted.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function connect( string $merchant_api_key ) {
		return $this->request(
			'POST',
			self::INSTALLATIONS_PATH,
			array(
				'storeUrl'   => home_url( '/' ),
				'label'      => get_bloginfo( 'name' ),
				'webhookUrl' => rest_url( 'fincobra/v1/webhooks' ),
			),
			array( 'X-Api-Key' => $merchant_api_key )
		);
	}

	/**
	 * List installations owned by a merchant API key.
	 *
	 * @param string $merchant_api_key Merchant API key, never persisted.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_installations( string $merchant_api_key ) {
		return $this->request(
			'GET',
			self::INSTALLATIONS_PATH,
			null,
			array( 'X-Api-Key' => $merchant_api_key )
		);
	}

	/**
	 * Rotate scoped credentials for an active installation.
	 *
	 * @param string $installation_id Installation identifier.
	 * @param string $merchant_api_key Merchant API key, never persisted.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function rotate_installation( string $installation_id, string $merchant_api_key ) {
		return $this->request(
			'POST',
			self::INSTALLATIONS_PATH . '/' . rawurlencode( $installation_id ) . '/rotate',
			null,
			array( 'X-Api-Key' => $merchant_api_key )
		);
	}

	/**
	 * @param string $installation_id Installation identifier.
	 * @param string $scoped_key Store-scoped credential.
	 * @return true|\WP_Error
	 */
	public function disconnect( string $installation_id, string $scoped_key ) {
		$result = $this->request(
			'DELETE',
			self::INSTALLATIONS_PATH . '/' . rawurlencode( $installation_id ),
			null,
			$this->scoped_headers( $scoped_key )
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * A dashboard-side disconnect invalidates the scoped key before WordPress
	 * can clear its local copy. Treat those terminal responses as already done.
	 *
	 * @param mixed $error Potential WordPress error.
	 */
	public static function is_already_disconnected_error( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		return in_array( $status, array( 401, 404 ), true );
	}

	/**
	 * @param mixed $error Potential WordPress error.
	 */
	public static function is_conflict_error( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && 409 === (int) $data['status'];
	}

	/**
	 * @param array<string, mixed> $invoice Invoice request.
	 * @param string               $scoped_key Store-scoped credential.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_invoice( array $invoice, string $scoped_key ) {
		return $this->request( 'POST', self::INVOICES_PATH, $invoice, $this->scoped_headers( $scoped_key ) );
	}

	public static function is_missing_billing_plan_error( string $message ): bool {
		return false !== stripos( $message, self::MISSING_BILLING_PLAN_MARKER )
			|| $message === self::missing_billing_plan_message();
	}

	public static function missing_billing_plan_message(): string {
		return sprintf(
			/* translators: %s: FinCobra WooCommerce billing URL. */
			__( 'Choose Annual or Commission at %s, then try again.', 'fincobra-woocommerce' ),
			self::BILLING_PLAN_URL
		);
	}

	public static function missing_billing_plan_notice_html(): string {
		$url = self::BILLING_PLAN_URL;
		return sprintf(
			/* translators: %s: FinCobra WooCommerce billing URL. */
			__( 'Choose Annual or Commission at %s, then try again.', 'fincobra-woocommerce' ),
			'<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>'
		);
	}

	/**
	 * @param mixed $error Potential WordPress error.
	 */
	public static function format_error_notice( $error ): string {
		if ( ! is_wp_error( $error ) ) {
			return '';
		}
		$message = $error->get_error_message();
		if ( 'fincobra_missing_billing_plan' === $error->get_error_code()
			|| self::is_missing_billing_plan_error( $message ) ) {
			return self::missing_billing_plan_notice_html();
		}
		return esc_html( $message );
	}

	public static function rewrite_error_message( string $message ): string {
		return self::is_missing_billing_plan_error( $message )
			? self::missing_billing_plan_message()
			: $message;
	}

	/**
	 * @param string $invoice_id Invoice identifier.
	 * @param string $scoped_key Store-scoped credential.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_invoice( string $invoice_id, string $scoped_key ) {
		return $this->request(
			'GET',
			self::INVOICES_PATH . '/' . rawurlencode( $invoice_id ),
			null,
			$this->scoped_headers( $scoped_key )
		);
	}

	/**
	 * @param string $scoped_key Store-scoped credential.
	 * @return array<string, string>
	 */
	private function scoped_headers( string $scoped_key ): array {
		return array( 'X-WooCommerce-Key' => $scoped_key );
	}

	/**
	 * @param string                    $method HTTP method.
	 * @param string                    $path API path.
	 * @param array<string, mixed>|null $body JSON body.
	 * @param array<string, string>     $headers Additional headers.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body, array $headers ) {
		if ( ! wp_http_validate_url( $this->base_url ) || 'https' !== wp_parse_url( $this->base_url, PHP_URL_SCHEME ) ) {
			return new \WP_Error( 'fincobra_invalid_url', __( 'FinCobra API URL must use HTTPS.', 'fincobra-woocommerce' ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'FinCobra API request failed' );
			return new \WP_Error( 'fincobra_unavailable', __( 'FinCobra is temporarily unavailable. Please try again.', 'fincobra-woocommerce' ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$this->logger->error( 'FinCobra API returned an error', array( 'http_status' => $status ) );
			$message = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] )
				? sanitize_text_field( $data['error'] )
				: __( 'FinCobra rejected the request. Please try again.', 'fincobra-woocommerce' );
			if ( self::is_missing_billing_plan_error( $message ) ) {
				return new \WP_Error(
					'fincobra_missing_billing_plan',
					self::missing_billing_plan_message(),
					array( 'status' => $status )
				);
			}
			return new \WP_Error( 'fincobra_api_error', $message, array( 'status' => $status ) );
		}

		return $data;
	}
}
