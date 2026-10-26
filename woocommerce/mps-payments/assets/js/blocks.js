/**
 * MPS Payments in the block checkout. Plain script on WordPress's globals, so the plugin needs no
 * build step; the title and description come from the gateway's settings (Mps_Payments_Blocks).
 */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'mps_payments_data', {} );
	const title = decodeEntities( settings.title || '' );
	const description = decodeEntities( settings.description || '' );

	const Content = () =>
		createElement(
			'div',
			null,
			description.split( '\n\n' ).map( ( paragraph, index ) => createElement( 'p', { key: index }, paragraph ) )
		);

	registerPaymentMethod( {
		name: 'mps_payments',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
