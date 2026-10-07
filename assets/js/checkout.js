( function () {
	'use strict';
	if ( ! window.WowRestroCheckout ) return;
	let timer = null;

	function field( selectors ) {
		for ( let index = 0; index < selectors.length; index++ ) {
			const match = document.querySelector( selectors[ index ] );
			if ( match ) return match;
		}
		return null;
	}

	function output() {
		let node = document.querySelector( '[data-wowrestro-promise]' );
		if ( node ) return node;
		node = document.createElement( 'div' );
		node.className = 'wowrestro-promise-preview';
		node.dataset.wowrestroPromise = '';
		node.setAttribute( 'aria-live', 'polite' );
		const target = field( [ '[name="wowrestro/fulfillment-mode"]', '[id*="wowrestro"][id*="fulfillment-mode"]', '.wc-block-checkout__main' ] );
		if ( target ) ( target.closest( '.wc-block-components-checkout-step' ) || target.parentElement ).append( node );
		return node;
	}

	async function update() {
		const modeField = field( [ '[name="wowrestro_fulfillment_mode"]', '[name="wowrestro/fulfillment-mode"]', '[id*="wowrestro"][id*="fulfillment-mode"]' ] );
		if ( ! modeField ) return;
		const requestedField = field( [ '[name="wowrestro_requested_time"]', '[name="wowrestro/requested-time"]', '[id*="wowrestro"][id*="requested-time"]' ] );
		const postcodeField = field( [ '#shipping_postcode', '#billing_postcode', '[name="shipping-postcode"]', '[name="billing-postcode"]' ] );
		const node = output();
		if ( ! node || ! node.isConnected ) return;
		node.textContent = WowRestroCheckout.labels.checking;
		const url = new URL( WowRestroCheckout.availabilityUrl );
		url.searchParams.set( 'mode', modeField.value || 'pickup' );
		url.searchParams.set( 'requested_at', requestedField ? requestedField.value : '' );
		url.searchParams.set( 'postcode', postcodeField ? postcodeField.value : '' );
		try {
			const response = await fetch( url.toString() );
			const quote = await response.json();
			if ( quote.available ) {
				node.textContent = WowRestroCheckout.labels.promise + ' ' + quote.promised_label + ( quote.mode === 'delivery' && quote.delivery_fee > 0 ? ' · ' + WowRestroCheckout.labels.deliveryFee + ' ' + quote.delivery_fee_label : '' );
				node.classList.remove( 'is-unavailable' );
			} else if ( quote.reason === 'postcode_required' ) {
				node.textContent = '';
			} else {
				node.textContent = quote.reason === 'paused' ? WowRestroCheckout.labels.paused : WowRestroCheckout.labels.unavailable;
				node.classList.add( 'is-unavailable' );
			}
		} catch ( error ) {
			node.textContent = WowRestroCheckout.labels.unavailable;
		}
	}

	function schedule( event ) {
		clearTimeout( timer );
		timer = setTimeout( update, 250 );
		if ( event && window.jQuery && event.target && /fulfillment_mode|postcode/.test( event.target.name || event.target.id || '' ) ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	document.addEventListener( 'change', schedule );
	document.addEventListener( 'input', function ( event ) {
		if ( /requested|postcode/.test( event.target.name || event.target.id || '' ) ) schedule( event );
	} );
	new MutationObserver( function () { if ( ! document.querySelector( '[data-wowrestro-promise]' ) ) schedule(); } ).observe( document.body, { childList: true, subtree: true } );
	schedule();
} )();
