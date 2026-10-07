( function () {
	'use strict';
	const board = document.querySelector( '[data-wowrestro-board]' );
	const status = document.querySelector( '[data-wowrestro-status]' );
	const alertButton = document.querySelector( '[data-wowrestro-alerts]' );
	const pauseButton = document.querySelector( '[data-wowrestro-pause]' );
	const manualForm = document.querySelector( '[data-wowrestro-manual-form]' );
	if ( ! board || ! window.WowRestroBoard ) return;

	let known = new Set();
	let alertedLate = new Set();
	let audioContext = null;
	let refreshing = false;
	const nextLabel = { 'wr-new': 'Accept', 'wr-accepted': 'Start preparing', 'wr-preparing': 'Mark ready', 'wr-ready': 'Complete', 'wr-out-for-delivery': 'Complete' };

	function element( tag, className, value ) {
		const node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( value !== undefined ) node.textContent = value;
		return node;
	}

	function beep( frequency ) {
		if ( ! audioContext ) return;
		const oscillator = audioContext.createOscillator();
		const gain = audioContext.createGain();
		oscillator.connect( gain );
		gain.connect( audioContext.destination );
		oscillator.frequency.value = frequency || 720;
		gain.gain.value = 0.06;
		oscillator.start();
		oscillator.stop( audioContext.currentTime + 0.18 );
	}

	if ( alertButton ) {
		alertButton.addEventListener( 'click', function () {
			audioContext = audioContext || new ( window.AudioContext || window.webkitAudioContext )();
			localStorage.setItem( 'wowrestroSound', 'enabled' );
			alertButton.textContent = 'Sound alerts enabled';
			beep();
		} );
		if ( localStorage.getItem( 'wowrestroSound' ) === 'enabled' ) {
			alertButton.textContent = 'Enable sound alerts';
		}
	}

	async function request( url, options ) {
		const response = await fetch( url, Object.assign( { headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WowRestroBoard.nonce } }, options || {} ) );
		const payload = await response.json().catch( function () { return {}; } );
		if ( ! response.ok ) throw new Error( payload.message || 'request' );
		return payload;
	}

	async function transition( id, next ) {
		await request( WowRestroBoard.ordersUrl + id + '/transition', { method: 'POST', body: JSON.stringify( { status: next } ) } );
		await refresh();
	}

	function transitionFor( order ) {
		if ( order.status === 'wr-ready' && order.mode === 'delivery' ) return 'wr-out-for-delivery';
		return { 'wr-new': 'wr-accepted', 'wr-accepted': 'wr-preparing', 'wr-preparing': 'wr-ready', 'wr-ready': 'completed', 'wr-out-for-delivery': 'completed' }[ order.status ];
	}

	function render( orders ) {
		if ( ! orders.length ) {
			board.replaceChildren( element( 'p', 'wowrestro-empty', WowRestroBoard.labels.empty ) );
			known = new Set();
			return;
		}
		const fragment = document.createDocumentFragment();
		orders.forEach( function ( order ) {
			if ( ! known.has( order.id ) && known.size ) beep();
			if ( order.is_late && ! alertedLate.has( order.id ) ) {
				beep( 430 );
				alertedLate.add( order.id );
			}
			known.add( order.id );
			const card = element( 'article', 'wowrestro-ticket status-' + order.status + ( order.is_late ? ' is-late' : '' ) );
			card.dataset.orderId = String( order.id );
			const header = element( 'header' );
			header.append( element( 'strong', '', '#' + order.number ), element( 'span', '', order.mode ) );
			if ( order.is_late ) header.append( element( 'b', 'wowrestro-late', 'Late' ) );
			card.append( header, element( 'h2', '', order.customer ) );
			const items = element( 'ul' );
			order.items.forEach( function ( item ) { items.append( element( 'li', '', item.quantity + ' × ' + item.name ) ); } );
			card.append( items );
			card.append( element( 'p', 'wowrestro-ticket__promise', 'Promise: ' + ( order.promised_at || 'ASAP' ) ) );
			if ( order.phone ) card.append( element( 'p', '', 'Phone: ' + order.phone ) );
			if ( order.note ) card.append( element( 'p', 'wowrestro-ticket__note', 'Note: ' + order.note ) );
			card.append( element( 'p', 'wowrestro-ticket__total', order.total ) );
			const actions = element( 'div', 'wowrestro-ticket__actions' );
			const next = transitionFor( order );
			if ( next ) {
				const button = element( 'button', 'button button-primary', nextLabel[ order.status ] );
				if ( order.status === 'wr-ready' && order.mode === 'delivery' ) button.textContent = 'Out for delivery';
				button.addEventListener( 'click', function () { button.disabled = true; transition( order.id, next ).catch( function ( error ) { status.textContent = error.message; button.disabled = false; } ); } );
				actions.append( button );
			}
			const print = element( 'button', 'button', 'Print ticket' );
			print.type = 'button';
			print.addEventListener( 'click', function () { card.classList.add( 'is-printing' ); document.body.classList.add( 'wowrestro-print-ticket' ); window.print(); document.body.classList.remove( 'wowrestro-print-ticket' ); card.classList.remove( 'is-printing' ); } );
			actions.append( print );
			card.append( actions );
			fragment.append( card );
		} );
		board.replaceChildren( fragment );
	}

	function updatePauseButton( paused ) {
		if ( ! pauseButton ) return;
		pauseButton.setAttribute( 'aria-pressed', paused ? 'true' : 'false' );
		pauseButton.textContent = paused ? 'Resume ordering' : 'Pause ordering';
		pauseButton.classList.toggle( 'is-paused', paused );
	}

	async function refresh() {
		if ( refreshing ) return;
		refreshing = true;
		try {
			const payload = await request( WowRestroBoard.queueUrl );
			render( payload.orders || [] );
			updatePauseButton( !! payload.paused );
			status.textContent = ( payload.paused ? WowRestroBoard.labels.paused + ' · ' : '' ) + WowRestroBoard.labels.updated;
		} catch ( error ) {
			status.textContent = WowRestroBoard.labels.error;
		} finally {
			refreshing = false;
		}
	}

	if ( pauseButton ) {
		pauseButton.addEventListener( 'click', async function () {
			const paused = pauseButton.getAttribute( 'aria-pressed' ) !== 'true';
			pauseButton.disabled = true;
			try {
				const payload = await request( WowRestroBoard.statusUrl, { method: 'POST', body: JSON.stringify( { paused: paused } ) } );
				updatePauseButton( !! payload.paused );
				status.textContent = payload.paused ? WowRestroBoard.labels.paused : WowRestroBoard.labels.open;
			} catch ( error ) { status.textContent = error.message; }
			pauseButton.disabled = false;
		} );
	}

	function addManualItem() {
		const holder = document.querySelector( '[data-wowrestro-manual-items]' );
		if ( ! holder ) return;
		const row = element( 'div', 'wowrestro-manual__item' );
		const select = document.createElement( 'select' );
		select.required = true;
		select.setAttribute( 'aria-label', 'Menu item' );
		select.append( new Option( 'Choose an item', '' ) );
		WowRestroBoard.products.forEach( function ( product ) { select.append( new Option( product.name + ' · ' + product.price, product.id ) ); } );
		const quantity = document.createElement( 'input' );
		quantity.type = 'number'; quantity.min = '1'; quantity.value = '1'; quantity.setAttribute( 'aria-label', 'Quantity' );
		const remove = element( 'button', 'button', 'Remove' ); remove.type = 'button'; remove.addEventListener( 'click', function () { row.remove(); } );
		row.append( select, quantity, remove ); holder.append( row );
	}

	if ( manualForm ) {
		addManualItem();
		document.querySelector( '[data-wowrestro-add-item]' ).addEventListener( 'click', addManualItem );
		manualForm.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const message = manualForm.querySelector( '[data-wowrestro-manual-message]' );
			const submit = manualForm.querySelector( '[type="submit"]' );
			const data = new FormData( manualForm );
			const items = Array.from( manualForm.querySelectorAll( '.wowrestro-manual__item' ) ).map( function ( row ) {
				return { product_id: Number( row.querySelector( 'select' ).value ), quantity: Number( row.querySelector( 'input' ).value ) || 1 };
			} ).filter( function ( item ) { return item.product_id > 0; } );
			const payload = { customer: data.get( 'customer' ), phone: data.get( 'phone' ), email: data.get( 'email' ), mode: data.get( 'mode' ), postcode: data.get( 'postcode' ), requested_at: data.get( 'requested_at' ), note: data.get( 'note' ), status_opt_in: data.has( 'status_opt_in' ), items: items };
			submit.disabled = true; message.textContent = 'Creating order…';
			try {
				const order = await request( WowRestroBoard.manualUrl, { method: 'POST', body: JSON.stringify( payload ) } );
				message.textContent = 'Phone order #' + order.number + ' created.';
				manualForm.reset();
				document.querySelector( '[data-wowrestro-manual-items]' ).replaceChildren();
				addManualItem();
				await refresh();
			} catch ( error ) { message.textContent = error.message || WowRestroBoard.labels.manualError; }
			submit.disabled = false;
		} );
	}

	window.addEventListener( 'online', refresh );
	window.addEventListener( 'offline', function () { status.textContent = WowRestroBoard.labels.error; } );
	refresh();
	window.setInterval( refresh, 5000 );
} )();
