import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '../..' );

test( 'Checkout Blocks registers a product-only payment method with escaped text nodes', async () => {
	const source = await readFile( join( root, 'assets/js/blocks.js' ), 'utf8' );
	let registration;
	const context = {
		window: {
			wc: {
				wcBlocksRegistry: {
					registerPaymentMethod( value ) {
						registration = value;
					},
				},
				wcSettings: {
					getSetting() {
						return {
							title: '&lt;b&gt;Crypto&lt;/b&gt;',
							description: 'Hosted checkout',
							supports: [ 'products' ],
						};
					},
				},
			},
			wp: {
				element: {
					createElement( element, properties, children ) {
						return { children, element, properties };
					},
				},
				htmlEntities: {
					decodeEntities( value ) {
						return value.replaceAll( '&lt;', '<' ).replaceAll( '&gt;', '>' );
					},
				},
			},
		},
	};

	vm.runInNewContext( source, context );
	assert.equal( registration.name, 'fincobra' );
	assert.equal( registration.ariaLabel, '<b>Crypto</b>' );
	assert.deepEqual( [ ...registration.supports.features ], [ 'products' ] );
	assert.equal( registration.canMakePayment(), true );
	assert.equal( registration.label.element, 'span' );
	assert.equal( registration.label.children, '<b>Crypto</b>' );
} );

test( 'Blocks script never uses remote code or dangerous HTML rendering', async () => {
	const source = await readFile( join( root, 'assets/js/blocks.js' ), 'utf8' );
	assert.doesNotMatch( source, /https?:\/\// );
	assert.doesNotMatch( source, /innerHTML|dangerouslySetInnerHTML|eval\s*\(/ );
	assert.doesNotMatch( source, /console\./ );
} );
