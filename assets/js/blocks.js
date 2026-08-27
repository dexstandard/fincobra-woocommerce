( function () {
	'use strict';

	const settings = window.wc.wcSettings.getSetting( 'fincobra_data', {} );
	const decode = window.wp.htmlEntities.decodeEntities;
	const createElement = window.wp.element.createElement;
	const label = decode( settings.title || 'FinCobra' );

	const Content = function () {
		return createElement(
			'p',
			null,
			decode(
				settings.description ||
					'Continue to FinCobra to pay on a hosted checkout page.',
			),
		);
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'fincobra',
		label: createElement( 'span', null, label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
