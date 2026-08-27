<?php
/**
 * FinCobra WooCommerce payment gateway.
 *
 * @package FinCobra_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class FinCobra_Gateway extends WC_Payment_Gateway {
	private const DEFAULT_API_URL = 'https://fincobra.com';

	private FinCobra_Logger $fincobra_logger;
	private FinCobra_Api_Client $api;

	public function __construct() {
		$this->id                 = 'fincobra';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'FinCobra', 'fincobra-woocommerce' );
		$this->method_description = __( 'Accept cryptocurrency with a secure hosted FinCobra checkout.', 'fincobra-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title              = sanitize_text_field( $this->get_option( 'title', __( 'Pay with crypto', 'fincobra-woocommerce' ) ) );
		$this->description        = sanitize_textarea_field( $this->get_option( 'description', __( 'Pay securely with supported cryptocurrencies.', 'fincobra-woocommerce' ) ) );
		$this->enabled            = $this->get_option( 'enabled', 'no' );
		$this->fincobra_logger    = new FinCobra_Logger( 'yes' === $this->get_option( 'debug', 'no' ) );
		$this->api                = new FinCobra_Api_Client( $this->api_url(), $this->fincobra_logger );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_notices', array( $this, 'currency_notice' ) );
		add_action( 'admin_notices', array( $this, 'billing_plan_notice' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable FinCobra', 'fincobra-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show FinCobra at checkout', 'fincobra-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Checkout title', 'fincobra-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Pay with crypto', 'fincobra-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'The payment method name shoppers see.', 'fincobra-woocommerce' ),
			),
			'description' => array(
				'title'       => __( 'Checkout description', 'fincobra-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely with supported cryptocurrencies.', 'fincobra-woocommerce' ),
				'description' => __( 'No wallet details are entered in your store.', 'fincobra-woocommerce' ),
			),
			'connection'  => array(
				'title' => __( 'FinCobra connection', 'fincobra-woocommerce' ),
				'type'  => 'fincobra_connection',
			),
			'api_url'     => array(
				'title'       => __( 'API URL', 'fincobra-woocommerce' ),
				'type'        => 'url',
				'default'     => self::DEFAULT_API_URL,
				'description' => __( 'Change this only for a FinCobra development environment. HTTPS is required.', 'fincobra-woocommerce' ),
			),
			'debug'       => array(
				'title'       => __( 'Diagnostics', 'fincobra-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Write redacted events to WooCommerce logs', 'fincobra-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Credentials, signatures, customer data, and request bodies are never logged.', 'fincobra-woocommerce' ),
			),
		);
	}

	public function is_available(): bool {
		return parent::is_available()
			&& 'USD' === get_woocommerce_currency()
			&& $this->is_connected();
	}

	public function is_connected(): bool {
		return '' !== $this->installation_id()
			&& null !== $this->scoped_key()
			&& null !== $this->webhook_secret();
	}

	/**
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Unable to start FinCobra checkout.', 'fincobra-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'USD' !== $order->get_currency() ) {
			wc_add_notice( __( 'FinCobra currently supports USD orders only.', 'fincobra-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$scoped_key = $this->scoped_key();
		if ( null === $scoped_key ) {
			wc_add_notice( __( 'FinCobra is not connected. Please choose another payment method.', 'fincobra-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$attempt = max( 1, (int) $order->get_meta( '_fincobra_payment_attempt', true ) );
		if ( $order->has_status( 'failed' ) && '' !== (string) $order->get_meta( '_fincobra_invoice_id', true ) ) {
			++$attempt;
			$order->delete_meta_data( '_fincobra_invoice_id' );
			$order->delete_meta_data( '_fincobra_payment_url' );
		}
		$order->update_meta_data( '_fincobra_payment_attempt', $attempt );
		$order->save();

		$reference       = $this->merchant_reference( $order );
		$idempotency_key = FinCobra_Idempotency::key( $this->installation_id(), $order->get_id(), $attempt );
		if ( null === $idempotency_key ) {
			wc_add_notice( __( 'Unable to create a safe FinCobra payment attempt.', 'fincobra-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$invoice_request = array(
			'idempotencyKey' => $idempotency_key,
			'orderId'       => (string) $order->get_id(),
			'amountUsd'     => (float) wc_format_decimal( $order->get_total(), 2 ),
			'currency'      => 'USD',
			'productName'   => sprintf(
				/* translators: %s: WooCommerce order number. */
				__( 'WooCommerce order #%s', 'fincobra-woocommerce' ),
				$order->get_order_number()
			),
			'redirectUrl'   => $this->get_return_url( $order ),
			'metadata'      => array(
				'wooOrderId'     => (string) $order->get_id(),
				'wooOrderNumber' => (string) $order->get_order_number(),
				'cancelUrl'      => $order->get_cancel_order_url_raw(),
			),
		);
		$customer = $this->customer_payload( $order );
		if ( array() !== $customer ) {
			$invoice_request['customer'] = $customer;
		}

		$result = $this->api->create_invoice( $invoice_request, $scoped_key );

		if ( is_wp_error( $result ) ) {
			$this->fincobra_logger->error( 'Invoice creation failed', array( 'order_id' => $order->get_id() ) );
			wc_add_notice( FinCobra_Api_Client::format_error_notice( $result ), 'error' );
			return array( 'result' => 'failure' );
		}

		$invoice_id  = isset( $result['id'] ) && is_string( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '';
		$payment_url = isset( $result['paymentUrl'] ) && is_string( $result['paymentUrl'] ) ? esc_url_raw( $result['paymentUrl'] ) : '';
		if ( '' === $invoice_id || ! wp_http_validate_url( $payment_url ) || 'https' !== wp_parse_url( $payment_url, PHP_URL_SCHEME ) ) {
			$this->fincobra_logger->error( 'Invoice response was incomplete', array( 'order_id' => $order->get_id() ) );
			wc_add_notice( __( 'FinCobra returned an invalid checkout link. Please try again.', 'fincobra-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_fincobra_invoice_id', $invoice_id );
		$order->update_meta_data( '_fincobra_payment_url', $payment_url );
		$order->update_meta_data( '_fincobra_merchant_reference', $reference );
		$order->update_meta_data( '_fincobra_reconcile_attempts', 0 );
		$order->update_status( 'on-hold', __( 'Awaiting confirmed FinCobra payment.', 'fincobra-woocommerce' ) );
		$order->save();
		wc_reduce_stock_levels( $order->get_id() );
		FinCobra_Reconciler::schedule_order( $order->get_id() );

		return array(
			'result'   => 'success',
			'redirect' => $payment_url,
		);
	}

	/**
	 * Custom connection field: the merchant key is submitted once and never saved.
	 *
	 * @param string               $key Field key.
	 * @param array<string, mixed> $data Field data.
	 */
	public function generate_fincobra_connection_html( string $key, array $data ): string {
		unset( $key, $data );
		$connected = $this->is_connected();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'FinCobra connection', 'fincobra-woocommerce' ); ?></th>
			<td class="forminp">
				<?php if ( $connected ) : ?>
					<p><strong><?php esc_html_e( 'Connected', 'fincobra-woocommerce' ); ?></strong></p>
					<p class="description"><?php esc_html_e( 'This store uses a scoped credential. Your merchant API key was not saved.', 'fincobra-woocommerce' ); ?></p>
					<div class="notice notice-warning inline">
						<p><?php echo wp_kses( FinCobra_Api_Client::missing_billing_plan_notice_html(), self::notice_allowed_html() ); ?></p>
					</div>
					<label><input type="checkbox" name="fincobra_disconnect" value="1"> <?php esc_html_e( 'Disconnect this store when changes are saved', 'fincobra-woocommerce' ); ?></label>
				<?php else : ?>
					<label for="fincobra_merchant_api_key"><strong><?php esc_html_e( 'Merchant API key', 'fincobra-woocommerce' ); ?></strong></label>
					<input id="fincobra_merchant_api_key" name="fincobra_merchant_api_key" type="password" autocomplete="new-password" class="input-text regular-input">
					<p class="description"><?php esc_html_e( 'Paste the key and click Save changes to connect.', 'fincobra-woocommerce' ); ?></p>
					<p class="description"><?php esc_html_e( 'Used once to connect this store, then discarded. Create a key in your FinCobra account.', 'fincobra-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		if ( ! $saved ) {
			return false;
		}

		$this->init_settings();
		$this->api = new FinCobra_Api_Client( $this->api_url(), $this->fincobra_logger );

		$disconnect = isset( $_POST['fincobra_disconnect'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['fincobra_disconnect'] ) );
		if ( $disconnect ) {
			$scoped_key = $this->scoped_key();
			if ( null !== $scoped_key && '' !== $this->installation_id() ) {
				$result = $this->api->disconnect( $this->installation_id(), $scoped_key );
				if ( is_wp_error( $result ) && ! FinCobra_Api_Client::is_already_disconnected_error( $result ) ) {
					WC_Admin_Settings::add_error( $result->get_error_message() );
					return false;
				}
			}
			$this->clear_connection();
			WC_Admin_Settings::add_message( __( 'FinCobra disconnected.', 'fincobra-woocommerce' ) );
			return true;
		}

		$merchant_key = isset( $_POST['fincobra_merchant_api_key'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['fincobra_merchant_api_key'] ) ) )
			: '';
		if ( '' === $merchant_key ) {
			return true;
		}

		$result = $this->connect_with_merchant_api_key( $merchant_key );
		if ( is_wp_error( $result ) ) {
			WC_Admin_Settings::add_error( $result->get_error_message() );
			return false;
		}
		WC_Admin_Settings::add_message( __( 'FinCobra connected successfully.', 'fincobra-woocommerce' ) );
		return true;
	}

	/**
	 * Exchange a normal merchant key for encrypted installation credentials.
	 * The merchant key is validated, used only for this call, and never saved.
	 *
	 * @param string $merchant_key Merchant API key.
	 * @return true|\WP_Error
	 */
	public function connect_with_merchant_api_key( string $merchant_key ) {
		$merchant_key = trim( $merchant_key );
		if ( 1 !== preg_match( '/^fc_live_[0-9a-f]{64}$/', $merchant_key ) ) {
			return new \WP_Error( 'fincobra_invalid_merchant_key', __( 'Enter a valid FinCobra merchant API key.', 'fincobra-woocommerce' ) );
		}

		$installations = $this->api->list_installations( $merchant_key );
		if ( is_wp_error( $installations ) ) {
			return $installations;
		}
		$active_installation = self::active_installation_for_store( $installations, home_url( '/' ) );
		$scoped_key          = $this->scoped_key();
		if ( null !== $active_installation && null !== $scoped_key && $this->is_connected() ) {
			$active_id     = isset( $active_installation['id'] ) && is_string( $active_installation['id'] ) ? sanitize_text_field( $active_installation['id'] ) : '';
			$active_prefix = isset( $active_installation['keyPrefix'] ) && is_string( $active_installation['keyPrefix'] ) ? sanitize_text_field( $active_installation['keyPrefix'] ) : '';
			if (
				'' !== $active_id &&
				'' !== $active_prefix &&
				hash_equals( $active_id, $this->installation_id() ) &&
				hash_equals( $active_prefix, substr( $scoped_key, 0, strlen( $active_prefix ) ) )
			) {
				return true;
			}
		}

		$result = null !== $active_installation
			? $this->api->rotate_installation( (string) $active_installation['id'], $merchant_key )
			: $this->api->connect( $merchant_key );
		if ( FinCobra_Api_Client::is_conflict_error( $result ) ) {
			$installations = $this->api->list_installations( $merchant_key );
			if ( is_wp_error( $installations ) ) {
				return $installations;
			}
			$active_installation = self::active_installation_for_store( $installations, home_url( '/' ) );
			if ( null === $active_installation ) {
				return $result;
			}
			$result = $this->api->rotate_installation( (string) $active_installation['id'], $merchant_key );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$installation_id = isset( $result['installation']['id'] ) && is_string( $result['installation']['id'] ) ? sanitize_text_field( $result['installation']['id'] ) : '';
		$scoped_key      = isset( $result['apiKey'] ) && is_string( $result['apiKey'] ) ? $result['apiKey'] : '';
		$webhook_secret  = isset( $result['webhookSecret'] ) && is_string( $result['webhookSecret'] ) ? $result['webhookSecret'] : '';
		if ( '' === $installation_id || '' === $scoped_key || '' === $webhook_secret ) {
			if ( '' !== $installation_id && '' !== $scoped_key ) {
				$this->api->disconnect( $installation_id, $scoped_key );
			}
			return new \WP_Error( 'fincobra_incomplete_credentials', __( 'FinCobra returned incomplete connection credentials.', 'fincobra-woocommerce' ) );
		}

		$encrypted_key    = FinCobra_Credential_Store::encrypt( $scoped_key );
		$encrypted_secret = FinCobra_Credential_Store::encrypt( $webhook_secret );
		if ( is_wp_error( $encrypted_key ) || is_wp_error( $encrypted_secret ) ) {
			$this->api->disconnect( $installation_id, $scoped_key );
			return new \WP_Error( 'fincobra_credential_storage_failed', __( 'The server could not securely store FinCobra credentials.', 'fincobra-woocommerce' ) );
		}

		$this->settings['installation_id']          = $installation_id;
		$this->settings['scoped_key_ciphertext']    = $encrypted_key;
		$this->settings['webhook_secret_ciphertext'] = $encrypted_secret;
		update_option( $this->get_option_key(), $this->settings, false );
		return true;
	}

	/**
	 * @param array<string, mixed> $response Installation list response.
	 * @param string               $store_url Current store URL.
	 */
	public static function active_installation_id_for_store( array $response, string $store_url ): string {
		$installation = self::active_installation_for_store( $response, $store_url );
		return null !== $installation ? (string) $installation['id'] : '';
	}

	/**
	 * @param array<string, mixed> $response Installation list response.
	 * @param string               $store_url Current store URL.
	 * @return array<string, mixed>|null
	 */
	public static function active_installation_for_store( array $response, string $store_url ): ?array {
		$installations = isset( $response['installations'] ) && is_array( $response['installations'] )
			? $response['installations']
			: array();
		$expected_url = untrailingslashit( esc_url_raw( $store_url ) );
		foreach ( $installations as $installation ) {
			if ( ! is_array( $installation ) || true !== ( $installation['isActive'] ?? false ) ) {
				continue;
			}
			$candidate_url = isset( $installation['storeUrl'] ) && is_string( $installation['storeUrl'] )
				? untrailingslashit( esc_url_raw( $installation['storeUrl'] ) )
				: '';
			$id = isset( $installation['id'] ) && is_string( $installation['id'] )
				? sanitize_text_field( $installation['id'] )
				: '';
			if ( '' !== $expected_url && $candidate_url === $expected_url && '' !== $id ) {
				$installation['id'] = $id;
				return $installation;
			}
		}
		return null;
	}

	public function installation_id(): string {
		return sanitize_text_field( $this->get_option( 'installation_id', '' ) );
	}

	public function scoped_key(): ?string {
		return FinCobra_Credential_Store::decrypt( (string) $this->get_option( 'scoped_key_ciphertext', '' ) );
	}

	public function webhook_secret(): ?string {
		return FinCobra_Credential_Store::decrypt( (string) $this->get_option( 'webhook_secret_ciphertext', '' ) );
	}

	public function api_client(): FinCobra_Api_Client {
		return $this->api;
	}

	public function currency_notice(): void {
		if ( 'yes' !== $this->enabled || 'USD' === get_woocommerce_currency() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'FinCobra is hidden at checkout because this store is not using USD. USD is required in version 1.', 'fincobra-woocommerce' );
		echo '</p></div>';
	}

	public function billing_plan_notice(): void {
		if ( ! $this->is_connected() || ! $this->is_gateway_settings_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo wp_kses( FinCobra_Api_Client::missing_billing_plan_notice_html(), self::notice_allowed_html() );
		echo '</p></div>';
	}

	private function api_url(): string {
		return esc_url_raw( $this->get_option( 'api_url', self::DEFAULT_API_URL ) );
	}

	private function is_gateway_settings_screen(): bool {
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		return 'wc-settings' === $page && 'checkout' === $tab && $this->id === $section;
	}

	/**
	 * @return array<string, array<string, bool>>
	 */
	private static function notice_allowed_html(): array {
		return array(
			'a' => array(
				'href' => true,
			),
		);
	}

	private function merchant_reference( WC_Order $order ): string {
		return (string) $order->get_id();
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private function customer_payload( WC_Order $order ): array {
		$customer = array();
		if ( $order->get_customer_id() > 0 ) {
			$customer['id'] = (string) $order->get_customer_id();
		}

		$email = sanitize_email( $order->get_billing_email() );
		if ( '' !== $email ) {
			$customer['email'] = $email;
		}

		$name = trim( sanitize_text_field( $order->get_formatted_billing_full_name() ) );
		if ( '' !== $name ) {
			$customer['name'] = $name;
		}

		return $customer;
	}

	private function clear_connection(): void {
		unset(
			$this->settings['installation_id'],
			$this->settings['scoped_key_ciphertext'],
			$this->settings['webhook_secret_ciphertext']
		);
		$this->settings['enabled'] = 'no';
		update_option( $this->get_option_key(), $this->settings, false );
	}
}
