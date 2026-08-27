<?php
/**
 * Dependency-free unit tests for pure security and mapping logic.
 *
 * Run with: php tests/php/run.php
 *
 * @package FinCobra_WooCommerce
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	/**
	 * @param array<string, mixed> $data Error data.
	 */
	public function __construct( public string $code, public string $message, public array $data = array() ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_error_data(): array {
		return $this->data;
	}
}

class WC_Payment_Gateway {
}

final class FinCobra_Logger {
	/**
	 * @param array<string, mixed> $context Log context.
	 */
	public function error( string $message, array $context = array() ): void {
		unset( $message, $context );
	}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $message, string $domain ): string {
	unset( $domain );
	return $message;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function esc_url_raw( string $value ): string {
	return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : '';
}

function sanitize_text_field( string $value ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '' );
}

function esc_html( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $value ): string {
	return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : '';
}

function home_url( string $path ): string {
	return 'https://woo-demo.fincobra.com' . $path;
}

function rest_url( string $path ): string {
	return 'https://woo-demo.fincobra.com/wp-json/' . $path;
}

function get_bloginfo( string $field ): string {
	unset( $field );
	return 'FinCobra Store';
}

function wp_http_validate_url( string $value ): bool {
	return false !== filter_var( $value, FILTER_VALIDATE_URL );
}

function wp_parse_url( string $value, int $component ): string|int|null|false {
	return parse_url( $value, $component );
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

/** @return array<string, mixed> */
function wp_safe_remote_request( string $url, array $args ): array {
	$GLOBALS['fincobra_http_request'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['fincobra_http_response'];
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['status'];
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_body( array $response ): string {
	return (string) $response['body'];
}

function wp_salt( string $scheme ): string {
	return 'unit-test-' . $scheme . '-salt';
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/class-fincobra-money.php';
require_once $root . '/includes/class-fincobra-status-mapper.php';
require_once $root . '/includes/class-fincobra-signature-verifier.php';
require_once $root . '/includes/class-fincobra-idempotency.php';
require_once $root . '/includes/class-fincobra-credential-store.php';
require_once $root . '/includes/class-fincobra-invoice-validator.php';
require_once $root . '/includes/class-fincobra-api-client.php';
require_once $root . '/includes/class-fincobra-gateway.php';

$assertions = 0;

/**
 * @param mixed  $expected Expected value.
 * @param mixed  $actual Actual value.
 * @param string $message Assertion description.
 */
function assert_same( mixed $expected, mixed $actual, string $message ): void {
	global $assertions;
	++$assertions;
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * @param bool   $actual Actual value.
 * @param string $message Assertion description.
 */
function assert_true( bool $actual, string $message ): void {
	assert_same( true, $actual, $message );
}

/**
 * @param bool   $actual Actual value.
 * @param string $message Assertion description.
 */
function assert_false( bool $actual, string $message ): void {
	assert_same( false, $actual, $message );
}

// Exact-money normalization and rejection.
$money_cases = array(
	array( '0', 2, '0' ),
	array( '0.00', 2, '0' ),
	array( '1', 2, '100' ),
	array( '1.2', 2, '120' ),
	array( '1.20', 2, '120' ),
	array( '001.20', 2, null ),
	array( '999999999999999999.99', 2, '99999999999999999999' ),
	array( '12.3400', 2, '1234' ),
	array( '12.3401', 2, null ),
	array( '-1.00', 2, null ),
	array( '+1.00', 2, null ),
	array( '1e2', 2, null ),
	array( '1,00', 2, null ),
	array( 'NaN', 2, null ),
	array( '', 2, null ),
	array( ' 1.00 ', 2, '100' ),
	array( '1.', 2, null ),
	array( '.5', 2, null ),
	array( '1.001', 2, null ),
	array( '1.000', 2, '100' ),
);
foreach ( $money_cases as list( $input, $scale, $expected ) ) {
	assert_same( $expected, FinCobra_Money::to_minor_units( $input, $scale ), 'money case: ' . $input );
}
assert_true( FinCobra_Money::equal( '10', '10.00' ), 'equivalent USD values compare equal' );
assert_true( FinCobra_Money::equal( '0.10', '0.1000' ), 'zero-only excess precision is accepted' );
assert_false( FinCobra_Money::equal( '10.01', '10.02' ), 'different values do not compare equal' );
assert_false( FinCobra_Money::equal( '10.001', '10.00' ), 'non-zero excess precision is rejected' );

// Dashboard-side disconnects are idempotent, while transient failures retain credentials.
assert_true(
	FinCobra_Api_Client::is_already_disconnected_error( new WP_Error( 'api', 'invalid key', array( 'status' => 401 ) ) ),
	'an invalidated scoped key is already disconnected'
);
assert_true(
	FinCobra_Api_Client::is_already_disconnected_error( new WP_Error( 'api', 'missing installation', array( 'status' => 404 ) ) ),
	'a missing installation is already disconnected'
);
assert_false(
	FinCobra_Api_Client::is_already_disconnected_error( new WP_Error( 'api', 'temporarily unavailable', array( 'status' => 503 ) ) ),
	'a transient failure must retain local credentials'
);
assert_true(
	FinCobra_Api_Client::is_conflict_error( new WP_Error( 'api', 'connected', array( 'status' => 409 ) ) ),
	'an existing installation is detected as a recoverable conflict'
);
assert_false(
	FinCobra_Api_Client::is_conflict_error( new WP_Error( 'api', 'unavailable', array( 'status' => 503 ) ) ),
	'a transient API failure is not treated as an installation conflict'
);

$installation_response = array(
	'installations' => array(
		array(
			'id'       => 'inactive-id',
			'storeUrl' => 'https://woo-demo.fincobra.com',
			'isActive' => false,
		),
		array(
			'id'       => 'other-store-id',
			'storeUrl' => 'https://other.example.com',
			'isActive' => true,
		),
		array(
			'id'       => 'demo-installation-id',
			'storeUrl' => 'https://woo-demo.fincobra.com',
			'isActive' => true,
			'keyPrefix' => 'fc_woo_expected',
		),
	),
);
assert_same(
	'demo-installation-id',
	FinCobra_Gateway::active_installation_id_for_store( $installation_response, 'https://woo-demo.fincobra.com/' ),
	'recovery selects only the active installation for the exact store'
);
assert_same(
	'fc_woo_expected',
	FinCobra_Gateway::active_installation_for_store( $installation_response, 'https://woo-demo.fincobra.com/' )['keyPrefix'],
	'recovery preserves the backend key prefix for stale-credential detection'
);
assert_same(
	'',
	FinCobra_Gateway::active_installation_id_for_store( $installation_response, 'https://not-the-demo.example.com' ),
	'recovery refuses an installation owned by another store'
);
assert_same(
	'',
	FinCobra_Gateway::active_installation_id_for_store( array( 'installations' => 'invalid' ), 'https://woo-demo.fincobra.com' ),
	'malformed installation lists fail closed'
);

$GLOBALS['fincobra_http_response'] = array(
	'status' => 200,
	'body'   => '{"installations":[]}',
);
$api_client = new FinCobra_Api_Client( 'https://fincobra.com/', new FinCobra_Logger() );
$api_client->list_installations( 'fc_live_test' );
assert_same(
	'https://fincobra.com/api/checkout/woocommerce/installations',
	$GLOBALS['fincobra_http_request']['url'],
	'installation listing uses the canonical endpoint'
);
assert_same( 'GET', $GLOBALS['fincobra_http_request']['args']['method'], 'installation listing uses GET' );
assert_same(
	'fc_live_test',
	$GLOBALS['fincobra_http_request']['args']['headers']['X-Api-Key'],
	'installation listing authenticates with the one-time merchant key'
);
assert_false( isset( $GLOBALS['fincobra_http_request']['args']['body'] ), 'installation listing sends no request body' );

$GLOBALS['fincobra_http_response'] = array(
	'status' => 200,
	'body'   => '{"installation":{"id":"installation-id"},"apiKey":"fc_woo_test","webhookSecret":"whsec_test"}',
);
$api_client->rotate_installation( 'id/with spaces', 'fc_live_test' );
assert_same(
	'https://fincobra.com/api/checkout/woocommerce/installations/id%2Fwith%20spaces/rotate',
	$GLOBALS['fincobra_http_request']['url'],
	'installation rotation encodes the identifier in the canonical endpoint'
);
assert_same( 'POST', $GLOBALS['fincobra_http_request']['args']['method'], 'installation rotation uses POST' );
assert_false( isset( $GLOBALS['fincobra_http_request']['args']['body'] ), 'installation rotation sends no request body' );

$GLOBALS['fincobra_http_response'] = array(
	'status' => 403,
	'body'   => '{"error":"Billing blocked invoice creation: Select a WooCommerce billing plan."}',
);
$billing_plan_error = $api_client->create_invoice( array( 'orderId' => '42' ), 'fc_woo_test' );
assert_true( is_wp_error( $billing_plan_error ), 'a missing Woo billing plan is an invoice error' );
assert_same(
	'fincobra_missing_billing_plan',
	$billing_plan_error->get_error_code(),
	'a missing Woo billing plan uses a dedicated error code'
);
assert_same(
	'Choose Annual or Commission at https://fincobra.com/woocommerce, then try again.',
	FinCobra_Api_Client::rewrite_error_message( 'Billing blocked invoice creation: Select a WooCommerce billing plan.' ),
	'the raw billing-plan API sentence is rewritten for merchants'
);
assert_same(
	'Choose Annual or Commission at https://fincobra.com/woocommerce, then try again.',
	$billing_plan_error->get_error_message(),
	'invoice creation returns the merchant-facing billing-plan sentence'
);
assert_same(
	'Choose Annual or Commission at <a href="https://fincobra.com/woocommerce">https://fincobra.com/woocommerce</a>, then try again.',
	FinCobra_Api_Client::format_error_notice( $billing_plan_error ),
	'checkout and settings notices link the WooCommerce billing page'
);
assert_true(
	FinCobra_Api_Client::is_missing_billing_plan_error( 'Billing blocked invoice creation: Select a WooCommerce billing plan.' ),
	'the exact API billing-plan sentence is detected'
);
assert_false(
	FinCobra_Api_Client::is_missing_billing_plan_error( 'Billing blocked invoice creation: Past-due billing invoice must be paid first.' ),
	'other billing blocks keep their original API sentence'
);
$GLOBALS['fincobra_http_response'] = array(
	'status' => 403,
	'body'   => '{"error":"Billing blocked invoice creation: Past-due billing invoice must be paid first."}',
);
$past_due_error = $api_client->create_invoice( array( 'orderId' => '42' ), 'fc_woo_test' );
assert_true( is_wp_error( $past_due_error ), 'a past-due billing block is still an invoice error' );
assert_same( 'fincobra_api_error', $past_due_error->get_error_code(), 'other billing blocks keep the generic API error code' );
assert_same(
	'Billing blocked invoice creation: Past-due billing invoice must be paid first.',
	$past_due_error->get_error_message(),
	'other billing blocks are not rewritten as a missing-plan notice'
);

$GLOBALS['fincobra_http_response'] = array(
	'status' => 201,
	'body'   => '{"installation":{"id":"installation-id"},"apiKey":"fc_woo_test","webhookSecret":"whsec_test"}',
);
$api_client->connect( 'fc_live_test' );
$connect_body = json_decode( $GLOBALS['fincobra_http_request']['args']['body'], true );
assert_same( 'https://woo-demo.fincobra.com/', $connect_body['storeUrl'], 'connection binds the exact store URL' );
assert_same( 'FinCobra Store', $connect_body['label'], 'connection includes the store label' );
assert_same(
	'https://woo-demo.fincobra.com/wp-json/fincobra/v1/webhooks',
	$connect_body['webhookUrl'],
	'connection registers the public plugin webhook'
);

// Every documented invoice state and conservative unknown-state handling.
$status_cases = array(
	'confirmed'        => FinCobra_Status_Mapper::COMPLETE,
	'paid_out_of_band' => FinCobra_Status_Mapper::COMPLETE,
	'awaiting_payment' => FinCobra_Status_Mapper::PENDING,
	'partially_paid'   => FinCobra_Status_Mapper::PENDING,
	'payment_detected' => FinCobra_Status_Mapper::PENDING,
	'expired'          => FinCobra_Status_Mapper::FAIL,
	'voided'           => FinCobra_Status_Mapper::FAIL,
	'refunded'         => FinCobra_Status_Mapper::IGNORE,
	'overpaid'         => FinCobra_Status_Mapper::IGNORE,
	''                 => FinCobra_Status_Mapper::IGNORE,
	'unknown'          => FinCobra_Status_Mapper::IGNORE,
);
foreach ( $status_cases as $status => $expected ) {
	assert_same( $expected, FinCobra_Status_Mapper::action_for( $status ), 'status case: ' . $status );
}
assert_same( FinCobra_Status_Mapper::COMPLETE, FinCobra_Status_Mapper::action_for( ' CONFIRMED ' ), 'status normalization is safe' );

// HMAC validation, freshness boundaries, tampering, and malformed headers.
$body      = '{"invoiceId":"inv_123","status":"confirmed"}';
$secret    = 'whsec_unit_test';
$signature = hash_hmac( 'sha256', $body, $secret );
assert_true( FinCobra_Signature_Verifier::verify( $body, $signature, $secret ), 'valid signature' );
assert_false( FinCobra_Signature_Verifier::verify( $body, strtoupper( $signature ), $secret ), 'uppercase signature rejected' );
assert_false( FinCobra_Signature_Verifier::verify( $body, 'sha256=' . $signature, $secret ), 'prefixed signature rejected' );
assert_false( FinCobra_Signature_Verifier::verify( $body . ' ', $signature, $secret ), 'body tampering' );
assert_false( FinCobra_Signature_Verifier::verify( $body, $signature, 'wrong' ), 'wrong secret' );
assert_false( FinCobra_Signature_Verifier::verify( $body, '', $secret ), 'missing signature' );
assert_false( FinCobra_Signature_Verifier::verify( $body, str_repeat( 'a', 63 ), $secret ), 'short signature' );
assert_false( FinCobra_Signature_Verifier::verify( $body, str_repeat( 'z', 64 ), $secret ), 'non-hex signature' );
assert_false( FinCobra_Signature_Verifier::verify( $body, $signature, '' ), 'empty secret' );
assert_true( FinCobra_Signature_Verifier::is_fresh( '2033-05-18T03:33:20.000Z', 2000000000 ), 'fresh event' );
assert_true( FinCobra_Signature_Verifier::is_fresh( '2033-05-18T03:28:20.000Z', 2000000000 ), 'past tolerance boundary' );
assert_true( FinCobra_Signature_Verifier::is_fresh( '2033-05-18T03:38:20.000Z', 2000000000 ), 'future tolerance boundary' );
assert_false( FinCobra_Signature_Verifier::is_fresh( '2033-05-18T03:28:19.000Z', 2000000000 ), 'stale event' );
assert_false( FinCobra_Signature_Verifier::is_fresh( 'not-a-date', 2000000000 ), 'malformed event time' );
assert_false( FinCobra_Signature_Verifier::is_fresh( 'now', 2000000000 ), 'relative event time rejected' );
assert_false( FinCobra_Signature_Verifier::is_fresh( '2033-05-18 03:33:20', 2000000000 ), 'timezone-less event time rejected' );
assert_false( FinCobra_Signature_Verifier::is_fresh( '', 2000000000 ), 'missing event time' );
assert_false( FinCobra_Signature_Verifier::is_fresh( '2033-05-18T03:33:20.000Z', 2000000000, -1 ), 'invalid tolerance' );

// Stable, scoped idempotency keys and invalid-input rejection.
assert_same( 'woocommerce:inst_abc-123:42:1', FinCobra_Idempotency::key( 'inst_abc-123', 42, 1 ), 'stable attempt key' );
assert_same( 'woocommerce:inst_abc-123:42:2', FinCobra_Idempotency::key( 'inst_abc-123', 42, 2 ), 'new attempt gets a new key' );
assert_same( null, FinCobra_Idempotency::key( 'bad:installation', 42, 1 ), 'delimiter injection rejected' );
assert_same( null, FinCobra_Idempotency::key( '', 42, 1 ), 'empty installation rejected' );
assert_same( null, FinCobra_Idempotency::key( str_repeat( 'a', 97 ), 42, 1 ), 'oversized installation rejected' );
assert_same( null, FinCobra_Idempotency::key( 'valid', 0, 1 ), 'invalid order rejected' );
assert_same( null, FinCobra_Idempotency::key( 'valid', 42, 0 ), 'invalid attempt rejected' );

// Authoritative invoice identity and order-reference validation.
$valid_invoice = array(
	'id'                => '11111111-1111-4111-8111-111111111111',
	'productCode'       => 'woocommerce',
	'integrationId'     => '22222222-2222-4222-8222-222222222222',
	'amountUsd'         => 19.95,
	'merchantReference' => '42',
	'status'            => 'confirmed',
);
assert_same(
	42,
	FinCobra_Invoice_Validator::order_id( $valid_invoice, $valid_invoice['id'], $valid_invoice['integrationId'] ),
	'valid authoritative invoice yields the order ID'
);

$invalid_invoice_cases = array(
	'missing id'          => array_diff_key( $valid_invoice, array( 'id' => true ) ),
	'wrong invoice'       => array_merge( $valid_invoice, array( 'id' => '33333333-3333-4333-8333-333333333333' ) ),
	'wrong product'       => array_merge( $valid_invoice, array( 'productCode' => 'checkout' ) ),
	'wrong installation'  => array_merge( $valid_invoice, array( 'integrationId' => '33333333-3333-4333-8333-333333333333' ) ),
	'string amount'       => array_merge( $valid_invoice, array( 'amountUsd' => '19.95' ) ),
	'zero amount'         => array_merge( $valid_invoice, array( 'amountUsd' => 0 ) ),
	'infinite amount'     => array_merge( $valid_invoice, array( 'amountUsd' => INF ) ),
	'leading zero order'  => array_merge( $valid_invoice, array( 'merchantReference' => '042' ) ),
	'delimiter injection' => array_merge( $valid_invoice, array( 'merchantReference' => '42:1' ) ),
	'negative order'      => array_merge( $valid_invoice, array( 'merchantReference' => '-42' ) ),
	'empty order'         => array_merge( $valid_invoice, array( 'merchantReference' => '' ) ),
	'oversized order'     => array_merge( $valid_invoice, array( 'merchantReference' => str_repeat( '9', 30 ) ) ),
);
foreach ( $invalid_invoice_cases as $description => $invoice ) {
	assert_true(
		FinCobra_Invoice_Validator::order_id( $invoice, $valid_invoice['id'], $valid_invoice['integrationId'] ) instanceof WP_Error,
		'invalid invoice rejected: ' . $description
	);
}

// At-rest credentials round-trip, use randomized nonces, and reject corruption.
if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	$encrypted_one = FinCobra_Credential_Store::encrypt( 'scoped_secret_123' );
	$encrypted_two = FinCobra_Credential_Store::encrypt( 'scoped_secret_123' );
	assert_true( is_string( $encrypted_one ), 'credential encryption returns a value' );
	assert_true( is_string( $encrypted_two ), 'second credential encryption returns a value' );
	assert_false( $encrypted_one === $encrypted_two, 'random nonces prevent deterministic ciphertext' );
	assert_same( 'scoped_secret_123', FinCobra_Credential_Store::decrypt( $encrypted_one ), 'credential decrypts' );
	assert_same( null, FinCobra_Credential_Store::decrypt( $encrypted_one . 'tampered' ), 'tampered credential rejected' );
	assert_same( null, FinCobra_Credential_Store::decrypt( 'plaintext' ), 'plaintext credential rejected' );
}

fwrite( STDOUT, "Passed {$assertions} PHP assertions.\n" );
