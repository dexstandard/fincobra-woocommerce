import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '../..' );

async function phpSources() {
	const includeNames = await readdir( join( root, 'includes' ) );
	const paths = [
		join( root, 'fincobra-woocommerce.php' ),
		join( root, 'uninstall.php' ),
		...includeNames.filter( ( name ) => name.endsWith( '.php' ) ).map( ( name ) => join( root, 'includes', name ) ),
	];
	return Promise.all( paths.map( async ( path ) => ( { path, source: await readFile( path, 'utf8' ) } ) ) );
}

test( 'REST paths and authentication headers are centralized in the API client', async () => {
	const files = await phpSources();
	for ( const { path, source } of files ) {
		if ( path.endsWith( 'class-fincobra-api-client.php' ) ) {
			continue;
		}
		assert.doesNotMatch( source, /\/api\/checkout\/woocommerce\//, path );
		assert.doesNotMatch( source, /X-WooCommerce-Key/, path );
	}
} );

test( 'plugin uses the canonical checkout service contract without legacy aliases', async () => {
	const client = await readFile( join( root, 'includes/class-fincobra-api-client.php' ), 'utf8' );
	const gateway = await readFile( join( root, 'includes/class-fincobra-gateway.php' ), 'utf8' );
	const webhook = await readFile( join( root, 'includes/class-fincobra-webhook-controller.php' ), 'utf8' );
	assert.match( client, /'X-Api-Key' => \$merchant_api_key/ );
	assert.match( client, /'X-WooCommerce-Key' => \$scoped_key/ );
	assert.doesNotMatch( client, /Authorization|Idempotency-Key/ );
	for ( const field of [
		'idempotencyKey',
		'orderId',
		'amountUsd',
		'currency',
		'productName',
		'redirectUrl',
	] ) {
		assert.match( gateway, new RegExp( `'${ field }'` ) );
	}
	assert.match( gateway, /\$result\['installation'\]\['id'\]/ );
	assert.match( gateway, /\$result\['apiKey'\]/ );
	assert.match( gateway, /\$result\['webhookSecret'\]/ );
	assert.doesNotMatch( gateway, /installationId|scopedKey|successUrl/ );
	assert.match( webhook, /x-checkout-signature/ );
	assert.match( webhook, /\$event\['invoice'\]\['integrationId'\]/ );
	assert.doesNotMatch( webhook, /x-fincobra-signature|x-fincobra-timestamp|x-fincobra-event-id/ );
} );

test( 'application source does not log secrets or raw request bodies', async () => {
	const files = await phpSources();
	for ( const { path, source } of files ) {
		assert.doesNotMatch( source, /error_log\s*\(|var_dump\s*\(|print_r\s*\(/, path );
		assert.doesNotMatch( source, /wc_get_logger\(\)->log\([^;]*(?:secret|signature|body|credential|key)/is, path );
	}
} );

test( 'merchant keys remain one-time inputs while recovery rotates only scoped credentials', async () => {
	const gateway = await readFile( join( root, 'includes/class-fincobra-gateway.php' ), 'utf8' );
	const client = await readFile( join( root, 'includes/class-fincobra-api-client.php' ), 'utf8' );
	assert.match( gateway, /connect_with_merchant_api_key/ );
	assert.match( gateway, /active_installation_id_for_store/ );
	assert.match( gateway, /hash_equals\( \$active_prefix/ );
	assert.match( client, /list_installations/ );
	assert.match( client, /rotate_installation/ );
	assert.doesNotMatch( gateway, /settings\[['"]merchant_api_key['"]\]/ );
	assert.doesNotMatch( gateway, /update_option\([^;]*merchant_key/is );
} );

test( 'webhooks verify raw bytes then fetch authoritative invoice state', async () => {
	const source = await readFile( join( root, 'includes/class-fincobra-webhook-controller.php' ), 'utf8' );
	const rawBody = source.indexOf( '$request->get_body()' );
	const verify = source.indexOf( 'FinCobra_Signature_Verifier::verify' );
	const decode = source.indexOf( 'json_decode( $body' );
	const fetch = source.indexOf( 'get_invoice(' );
	const complete = source.indexOf( 'payment_complete(' );
	assert.ok( rawBody >= 0 && rawBody < verify );
	assert.ok( verify < decode );
	assert.ok( fetch >= 0 && fetch < complete );
	assert.match( source, /hash_equals\( \$invoice_id/ );
	assert.match( source, /FinCobra_Money::equal/ );
	assert.match( source, /merchantReference/ );
	assert.match( source, /x-checkout-signature/ );
	assert.match( source, /is_fresh\( \$event\['createdAt'\]/ );
} );

test( 'missing Woo billing plan errors are rewritten and linked on the settings screen', async () => {
	const client = await readFile( join( root, 'includes/class-fincobra-api-client.php' ), 'utf8' );
	const gateway = await readFile( join( root, 'includes/class-fincobra-gateway.php' ), 'utf8' );
	assert.match( client, /BILLING_PLAN_URL = 'https:\/\/fincobra\.com\/woocommerce'/ );
	assert.match( client, /rewrite_error_message/ );
	assert.match( client, /Choose Annual or Commission at %s, then try again/ );
	assert.match( client, /fincobra_missing_billing_plan/ );
	assert.match( gateway, /billing_plan_notice/ );
	assert.match( gateway, /missing_billing_plan_notice_html/ );
	assert.match( gateway, /Paste the key and click Save changes to connect/ );
	assert.doesNotMatch( gateway, /wc_add_notice\(\s*esc_html\(\s*\$result->get_error_message\(\)/ );
	assert.doesNotMatch( gateway, /Select a WooCommerce billing plan/ );
} );

test( 'plugin advertises only product payments and cleans credentials on uninstall', async () => {
	const gateway = await readFile( join( root, 'includes/class-fincobra-gateway.php' ), 'utf8' );
	const uninstall = await readFile( join( root, 'uninstall.php' ), 'utf8' );
	assert.match( gateway, /supports\s*=\s*array\( 'products' \)/ );
	assert.doesNotMatch( gateway, /subscriptions|refunds|pre-orders/ );
	assert.match( uninstall, /delete_option\( 'woocommerce_fincobra_settings' \)/ );
	assert.match( uninstall, /as_unschedule_all_actions/ );
} );
