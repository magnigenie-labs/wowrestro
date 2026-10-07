( function () {
	'use strict';
	let activeCleanup = null;
	let firebaseReady = null;
	window.WowRestroFirebaseState = 'connecting';

	function reportFirebase( state, error ) {
		window.WowRestroFirebaseState = state;
		document.dispatchEvent( new CustomEvent( 'wowrestro-firebase-status', { detail: { state: state, error: error || '' } } ) );
	}

	async function connectFirebase( askPermission ) {
		const boardSettings = window.WowRestroBoard;
		const settings = boardSettings && boardSettings.firebase;
		if ( ! settings || ! window.firebase || ! window.Notification || ! navigator.serviceWorker ) {
			reportFirebase( 'error', 'Firebase messaging is unavailable in this browser.' );
			return false;
		}
		if ( Notification.permission === 'default' ) {
			if ( ! askPermission || await Notification.requestPermission() !== 'granted' ) return false;
		}
		if ( Notification.permission !== 'granted' ) {
			reportFirebase( 'error', 'Browser notification permission is blocked.' );
			return false;
		}
		if ( firebaseReady ) return firebaseReady;

		firebaseReady = ( async function () {
			if ( ! await window.firebase.messaging.isSupported() ) throw new Error( 'Firebase messaging is unsupported.' );
			if ( ! window.firebase.apps.length ) window.firebase.initializeApp( settings.config );
			const worker = await navigator.serviceWorker.register( settings.serviceWorkerUrl );
			const messaging = window.firebase.messaging();
			const token = await messaging.getToken( { serviceWorkerRegistration: worker, vapidKey: settings.vapidKey } );
			if ( ! token ) throw new Error( 'Firebase did not issue a browser token.' );
			const response = await fetch( boardSettings.devicesUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boardSettings.nonce },
				body: JSON.stringify( { token: token, platform: 'web', location: settings.location || '' } )
			} );
			if ( ! response.ok ) throw new Error( 'The store rejected the browser token (' + response.status + ').' );
			messaging.onMessage( function ( message ) {
				document.dispatchEvent( new CustomEvent( 'wowrestro-firebase-order', { detail: message.data || {} } ) );
				if ( ! document.querySelector( '[data-wowrestro-board]' ) && message.data && ( message.data.type === 'new_order' || message.data.type === 'order_updated' ) ) {
					const notice = new Notification( ( message.notification && message.notification.title ) || message.data.title || 'New order', {
						body: ( message.notification && message.notification.body ) || message.data.body || ''
					} );
					notice.onclick = function () {
						window.focus();
						window.location.href = settings.liveOrdersUrl;
						notice.close();
					};
				}
			} );
			reportFirebase( 'connected' );
			return true;
		} )().catch( function ( error ) {
			firebaseReady = null;
			reportFirebase( 'error', error.message || 'Firebase could not connect.' );
			window.console.error( '[WowRestro] Firebase connection failed:', error );
			return false;
		} );
		return firebaseReady;
	}

	window.WowRestroEnableFirebase = connectFirebase;
	connectFirebase( false );
	window.addEventListener( 'online', function () { connectFirebase( false ); } );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && 'connected' !== window.WowRestroFirebaseState ) connectFirebase( false );
	} );

	function initBoard() {
	const board = document.querySelector( '[data-wowrestro-board]' );
	const status = document.querySelector( '[data-wowrestro-status]' );
	const alertButton = document.querySelector( '[data-wowrestro-alerts]' );
	const refreshButton = document.querySelector( '[data-wowrestro-refresh]' );
	const pauseButton = document.querySelector( '[data-wowrestro-pause]' );
	const manualForm = document.querySelector( '[data-wowrestro-manual-form]' );
	const filterBar = document.querySelector( '[data-wowrestro-filters]' );
	const searchInput = document.querySelector( '[data-wowrestro-search]' );
	if ( ! board || ! window.WowRestroBoard ) {
		if ( activeCleanup ) {
			activeCleanup();
			activeCleanup = null;
		}
		return;
	}
	if ( board.dataset.wowrestroInitialized === 'yes' ) return;
	if ( activeCleanup ) activeCleanup();
	board.dataset.wowrestroInitialized = 'yes';

	const L = WowRestroBoard.labels;
	let paintAlerts = null;
	let known = new Set();
	let hydrated = false;
	let alertedLate = new Set();
	let audioContext = null;
	const activeTones = new Set();
	let refreshing = false;
	let activeRequests = 0;
	let queueEtag = '';
	let queueFingerprint = '';
	let orders = [];
	let filter = 'all';
	let query = '';

	// Phosphor icon element, matching the class names the design uses.
	function icon( className ) {
		const node = element( 'i', className );
		node.setAttribute( 'aria-hidden', 'true' );
		return node;
	}

	function element( tag, className, value ) {
		const node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( value !== undefined ) node.textContent = value;
		return node;
	}

	function setButtonLabel( button, iconClass, label ) {
		if ( ! button ) return;
		button.replaceChildren( icon( iconClass ), document.createTextNode( label ) );
	}

	function setActionBusy( button, busy, iconClass, label ) {
		button.disabled = busy;
		if ( busy ) {
			button.setAttribute( 'aria-busy', 'true' );
			setButtonLabel( button, 'wra-btn__spinner', L.updating );
			return;
		}
		button.removeAttribute( 'aria-busy' );
		setButtonLabel( button, iconClass, label );
	}

	function ensureAudioContext() {
		const AudioEngine = window.AudioContext || window.webkitAudioContext;
		if ( ! audioContext && AudioEngine ) audioContext = new AudioEngine();
		return audioContext;
	}

	function tone( frequency, delay, duration, volume ) {
		if ( ! audioContext ) return;
		const oscillator = audioContext.createOscillator();
		const gain = audioContext.createGain();
		const start = audioContext.currentTime + ( delay || 0 );
		oscillator.connect( gain );
		gain.connect( audioContext.destination );
		oscillator.type = 'sine';
		oscillator.frequency.value = frequency;
		activeTones.add( oscillator );
		oscillator.addEventListener( 'ended', function () { activeTones.delete( oscillator ); } );
		gain.gain.setValueAtTime( 0.0001, start );
		gain.gain.exponentialRampToValueAtTime( volume || 0.12, start + 0.02 );
		gain.gain.exponentialRampToValueAtTime( 0.0001, start + duration );
		oscillator.start( start );
		oscillator.stop( start + duration );
	}

	function stopOrderSound() {
		activeTones.forEach( function ( oscillator ) {
			try { oscillator.stop(); } catch ( error ) {}
		} );
		activeTones.clear();
	}

	function newOrderSound() {
		if ( ! soundOn() ) return;
		tone( 659, 0, 0.24, 0.14 );
		tone( 880, 0.2, 0.24, 0.14 );
		tone( 1047, 0.4, 0.42, 0.16 );
	}

	function lateOrderSound() {
		if ( ! soundOn() ) return;
		tone( 392, 0, 0.28, 0.1 );
		tone( 330, 0.25, 0.38, 0.1 );
	}

	function soundOn() {
		return localStorage.getItem( 'wowrestroSound' ) === 'enabled';
	}

	if ( soundOn() && ensureAudioContext() ) {
		document.addEventListener( 'pointerdown', function () { audioContext.resume(); }, { once: true } );
	}

	if ( alertButton ) {
		paintAlerts = function () {
			const needsPermission = soundOn() && window.Notification && Notification.permission === 'default';
			const failed = 'error' === window.WowRestroFirebaseState;
			setButtonLabel( alertButton, failed ? 'ph-fill ph-warning-circle' : ( needsPermission ? 'ph-bold ph-bell' : ( soundOn() ? 'ph-fill ph-speaker-high' : 'ph ph-speaker-simple-slash' ) ), failed ? L.liveAlertsError : ( needsPermission ? L.liveAlerts : ( soundOn() ? L.soundOn : L.soundOff ) ) );
			alertButton.title = failed ? L.liveAlertsRetry : '';
			alertButton.setAttribute( 'aria-pressed', soundOn() ? 'true' : 'false' );
		};
		alertButton.addEventListener( 'click', function () {
			if ( soundOn() && window.Notification && ( Notification.permission === 'default' || 'error' === window.WowRestroFirebaseState ) ) {
				connectFirebase( true ).then( paintAlerts );
				return;
			}
			if ( soundOn() ) {
				localStorage.removeItem( 'wowrestroSound' );
			} else {
				if ( ! ensureAudioContext() ) return;
				localStorage.setItem( 'wowrestroSound', 'enabled' );
				audioContext.resume().then( newOrderSound );
				connectFirebase( true ).then( paintAlerts );
			}
			paintAlerts();
		} );
		document.addEventListener( 'wowrestro-firebase-status', paintAlerts );
		paintAlerts();
	}

	function beginRequest() {
		activeRequests++;
		board.classList.add( 'is-loading' );
		board.setAttribute( 'aria-busy', 'true' );
	}

	function endRequest() {
		activeRequests = Math.max( 0, activeRequests - 1 );
		if ( activeRequests ) return;
		board.classList.remove( 'is-loading' );
		board.removeAttribute( 'aria-busy' );
	}

	async function request( url, options, localOnly ) {
		if ( ! localOnly ) beginRequest();
		try {
			const defaults = { headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WowRestroBoard.nonce } };
			const config = Object.assign( {}, defaults, options || {} );
			config.headers = Object.assign( {}, defaults.headers, options && options.headers ? options.headers : {} );
			const response = await fetch( url, config );
			const payload = await response.json().catch( function () { return {}; } );
			if ( ! response.ok ) {
				const error = new Error( payload.message || L.error );
				error.status = response.status;
				throw error;
			}
			return payload;
		} finally {
			if ( ! localOnly ) endRequest();
		}
	}

	// The orders endpoint supplies an ETag. A 304 response means the board is
	// already current, so keep every ticket in place instead of redrawing it.
	async function requestQueue( localOnly ) {
		if ( ! localOnly ) beginRequest();
		try {
			const headers = { 'X-WP-Nonce': WowRestroBoard.nonce };
			if ( queueEtag ) headers[ 'If-None-Match' ] = queueEtag;
			const response = await fetch( WowRestroBoard.queueUrl, { headers: headers } );
			if ( response.status === 304 ) return null;
			const payload = await response.json().catch( function () { return {}; } );
			if ( ! response.ok ) {
				const error = new Error( payload.message || L.error );
				error.status = response.status;
				throw error;
			}
			queueEtag = response.headers.get( 'ETag' ) || '';
			return payload;
		} finally {
			if ( ! localOnly ) endRequest();
		}
	}

	// The operations route rejects a transition unless the client proves it is
	// looking at the current order, so every call carries the order's revision.
	async function transition( order, next ) {
		stopOrderSound();
		await request( WowRestroBoard.operationsUrl + order.id + '/transition', {
			method: 'POST',
			body: JSON.stringify( { status: next, revision: order.revision } )
		}, true );
		clearError();
		await refresh( true );
	}

	const TRANSITION_ICONS = {
		accepted: 'ph-bold ph-check',
		preparing: 'ph-bold ph-play',
		ready: 'ph-bold ph-fire',
		out_for_delivery: 'ph-bold ph-moped',
		completed: 'ph-bold ph-check-circle',
		cancelled: 'ph ph-x'
	};
	const FLOW_ICONS = {
		new: 'ph-bold ph-bell',
		accepted: 'ph-bold ph-check',
		preparing: 'ph-bold ph-fire',
		ready: 'ph-bold ph-bag',
		out_for_delivery: 'ph-bold ph-moped'
	};

	function promisedTime( order ) {
		const gmt = String( order.promised_at_gmt || '' ).trim();
		if ( gmt ) {
			const normalized = gmt.indexOf( 'T' ) >= 0 ? gmt : gmt.replace( ' ', 'T' ) + 'Z';
			const parsed = Date.parse( normalized );
			if ( ! isNaN( parsed ) ) return parsed;
		}
		const local = Date.parse( order.promised_at_local || order.promised_at || '' );
		return isNaN( local ) ? NaN : local;
	}

	function orderTimeLabel( order ) {
		if ( order.created_label ) return order.created_label;
		const created = Date.parse( order.created_at || '' );
		return isNaN( created ) ? '-' : new Intl.DateTimeFormat( undefined, {
			month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
		} ).format( created );
	}

	function shortDuration( minutes ) {
		const safe = Math.max( 1, Math.round( Math.abs( minutes ) ) );
		if ( safe < 60 ) return safe + ' min';
		const hours = Math.floor( safe / 60 );
		const remainder = safe % 60;
		return hours + 'h' + ( remainder ? ' ' + remainder + 'm' : '' );
	}

	// Show staff the useful promise delta, never a multi-day order-age stopwatch.
	function promiseDelta( order ) {
		const promise = promisedTime( order );
		if ( ! isNaN( promise ) ) {
			const minutes = ( promise - Date.now() ) / 60000;
			if ( order.is_late || minutes < 0 ) return shortDuration( minutes ) + ' late';
			return shortDuration( minutes ) + ' left';
		}
		const created = Date.parse( order.created_at || '' );
		return isNaN( created ) ? '' : shortDuration( ( Date.now() - created ) / 60000 ) + ' old';
	}

	function tickTimers() {
		board.querySelectorAll( '[data-order-timer]' ).forEach( function ( node ) {
			const order = orders.find( function ( candidate ) { return String( candidate.id ) === node.dataset.orderTimer; } );
			const text = node.querySelector( 'span' );
			if ( order && text ) text.textContent = promiseDelta( order );
		} );
	}

	function statusClass( value ) {
		// An order with no operational status must not borrow "New" colouring.
		const key = String( value || '' ).replace( /[^a-z_]/g, '' );
		return key || 'unset';
	}

	function visible() {
		const needle = query.trim().toLowerCase();
		return orders.filter( function ( order ) {
			if ( filter !== 'all' && order.status !== filter ) return false;
			if ( ! needle ) return true;
			return String( order.number ).toLowerCase().indexOf( needle ) >= 0 ||
				String( order.customer ).toLowerCase().indexOf( needle ) >= 0 ||
				String( order.phone || '' ).toLowerCase().indexOf( needle ) >= 0;
		} );
	}

	function renderFilters() {
		if ( ! filterBar ) return;
		const counts = { all: orders.length };
		orders.forEach( function ( order ) {
			counts[ order.status ] = ( counts[ order.status ] || 0 ) + 1;
		} );
		const fragment = document.createDocumentFragment();
		[ [ 'all', L.all ] ].concat( WowRestroBoard.statuses ).forEach( function ( pair ) {
			const key = pair[ 0 ];
			const button = element( 'button', 'wra-pill wra-filter-' + statusClass( key ) + ( filter === key ? ' is-on' : '' ) );
			button.type = 'button';
			button.append( document.createTextNode( pair[ 1 ] ), element( 'span', 'wra-pill__count', String( counts[ key ] || 0 ) ) );
			button.addEventListener( 'click', function () {
				filter = key;
				renderFilters();
				renderBoard();
			} );
			fragment.append( button );
		} );
		filterBar.replaceChildren( fragment );
	}

	function ticket( order ) {
		const state = statusClass( order.status );
		const card = element( 'article', 'wra-ticket wra-state-' + state + ( order.is_late ? ' is-late' : '' ) );
		card.dataset.orderId = String( order.id );
		card.append( element( 'span', 'wra-ticket__bar wra-bar-' + state ) );

		const header = element( 'header', 'wra-ticket__head' );
		header.append( element( 'strong', 'wra-ticket__num', '#' + order.number ) );
		const mode = element( 'span', 'wra-ticket__mode' );
		mode.append( icon( order.mode === 'delivery' ? 'ph-fill ph-moped' : 'ph-fill ph-bag' ),
			document.createTextNode( order.dining_type === 'dine_in' ? 'Dine-in · Table ' + ( order.table || '-' ) : ( order.mode === 'delivery' ? L.delivery : L.pickup ) ) );
		header.append( mode );
		if ( order.is_late ) header.append( element( 'span', 'wra-ticket__late', L.late ) );
		const timer = element( 'span', 'wra-ticket__timer' );
		timer.dataset.orderTimer = String( order.id );
		timer.append( icon( 'ph ph-timer' ), element( 'span', '', promiseDelta( order ) ) );
		header.append( timer );
		card.append( header );

		const customerHeading = element( 'h2', 'wra-ticket__name' );
		const customerButton = element( 'button', 'wra-ticket__namebutton', order.customer );
		customerButton.type = 'button';
		customerButton.title = L.orderDetails;
		customerButton.addEventListener( 'click', function () { openDrawer( order.id ); } );
		customerHeading.append( customerButton );
		card.append( customerHeading );
		const times = element( 'div', 'wra-ticket__times' );
		const ordered = element( 'span' );
		ordered.append( element( 'small', '', L.orderTime ), element( 'strong', '', orderTimeLabel( order ) ) );
		const service = element( 'span' );
		service.append( element( 'small', '', L.serviceTime ), element( 'strong', '', order.service_label || order.promised_label || L.notScheduled ) );
		times.append( ordered, service );
		card.append( times );

		const chip = element( 'span', 'wra-ticket__status' );
		const inner = element( 'span', 'wra-chip wra-s-' + state );
		inner.append( element( 'span', 'wra-chip__dot' ), document.createTextNode( order.status_label || '' ) );
		chip.append( inner );
		card.append( chip );

		const items = element( 'ul', 'wra-ticket__lines' );
		( order.items || [] ).forEach( function ( item ) {
			const line = element( 'li' );
			line.append( element( 'span', 'wra-qty', String( item.quantity ) ) );
			const text = element( 'span' );
			text.append( document.createTextNode( item.name ) );
			const extras = [];
			if ( item.modifiers ) {
				Object.keys( item.modifiers ).forEach( function ( key ) {
					const value = item.modifiers[ key ];
					extras.push( typeof value === 'object' && value !== null ? ( value.label || value.name || '' ) : String( value ) );
				} );
			}
			if ( item.note ) extras.push( item.note );
			const detail = extras.filter( Boolean ).join( ' · ' );
			if ( detail ) text.append( element( 'small', 'wra-linemods', detail ) );
			line.append( text );
			items.append( line );
		} );
		card.append( items );

		if ( order.note ) {
			const note = element( 'p', 'wra-note' );
			note.append( icon( 'ph-fill ph-note-pencil' ), element( 'span', '', order.note ) );
			card.append( note );
		}

		const foot = element( 'div', 'wra-ticket__foot' );
		foot.append( element( 'span', 'wra-ticket__phone', order.phone || '' ) );
		foot.append( element( 'strong', 'wra-ticket__total', order.total ) );
		card.append( foot );

		const actions = element( 'div', 'wra-ticket__actions' );
		// The server decides what may happen next; the board only offers it.
		const next = ( order.allowed_transitions || [] )[ 0 ];
		if ( ! next && WowRestroBoard.canOperate ) {
			// No legal move usually means the order never got an operational status.
			// Say that rather than leaving a card with no action and no explanation.
			const blocked = element( 'button', 'wra-btn wra-btn--primary' );
			blocked.append( icon( 'ph ph-x' ), document.createTextNode( L.noAction ) );
			blocked.type = 'button';
			blocked.disabled = true;
			blocked.title = L.noActionHint;
			actions.append( blocked );
		}
		if ( next && WowRestroBoard.canOperate ) {
			const actionIcon = TRANSITION_ICONS[ next ] || 'ph-bold ph-check';
			const actionLabel = WowRestroBoard.transitionLabels[ next ] || next;
			const button = element( 'button', 'wra-btn wra-btn--primary wra-action-' + statusClass( next ) );
			button.append( icon( actionIcon ), document.createTextNode( actionLabel ) );
			button.type = 'button';
			button.addEventListener( 'click', function () {
				setActionBusy( button, true, actionIcon, actionLabel );
				transition( order, next ).catch( function ( error ) {
					setActionBusy( button, false, actionIcon, actionLabel );
					// A stale or missing revision means someone else moved this order.
					// Reload first, then report - otherwise the refresh overwrites the
					// reason and the board looks like it simply ignored the click.
					if ( error.status === 409 || error.status === 428 ) {
						flagError( error.message );
						refresh();
						return;
					}
					flagError( error.message );
				} );
			} );
			actions.append( button );
		}
		card.append( actions );
		return card;
	}

	/* ---------------- order detail drawer ---------------- */

	const drawerRoot = element( 'div', 'wra-drawerroot' );
	drawerRoot.dataset.wowrestroDrawerRoot = 'yes';
	document.body.append( drawerRoot );

	function closeDrawer() {
		drawerRoot.replaceChildren();
	}

	function section( label, node ) {
		const wrap = element( 'div' );
		wrap.append( element( 'p', 'wra-sectionlabel', label ), node );
		return wrap;
	}

	function steps( detail ) {
		const flow = WowRestroBoard.statuses.filter( function ( pair ) {
			return pair[ 0 ] !== 'out_for_delivery' || detail.mode === 'delivery';
		} );
		const at = flow.map( function ( pair ) { return pair[ 0 ]; } ).indexOf( detail.status );
		const row = element( 'div', 'wra-steps' );
		flow.forEach( function ( pair, index ) {
			const done = at >= 0 && index < at;
			const now = index === at;
			const step = element( 'span', 'wra-step' + ( done ? ' is-done' : now ? ' is-now' : '' ) );
			step.append( icon( FLOW_ICONS[ pair[ 0 ] ] || 'ph-bold ph-check' ), document.createTextNode( pair[ 1 ] ) );
			row.append( step );
		} );
		return row;
	}

	function totals( detail ) {
		const box = element( 'div', 'wra-box' );
		const add = function ( label, value ) {
			const row = element( 'div', 'wra-row' );
			row.append( element( 'span', '', label ), element( 'span', '', value ) );
			box.append( row );
		};
		if ( detail.subtotal ) add( L.subtotal, detail.subtotal );
		if ( detail.has_shipping ) add( detail.mode === 'delivery' ? L.delivery : L.shipping, detail.shipping_total );
		if ( detail.has_discount ) add( L.discount, '−' + detail.discount_total );
		if ( detail.tax_total ) add( L.tax, detail.tax_total );
		const total = element( 'div', 'wra-row wra-row--total' );
		total.append( element( 'strong', '', L.total ), element( 'strong', '', detail.total ) );
		box.append( total );
		return box;
	}

	function renderDrawer( detail ) {
		const scrim = element( 'div', 'wra-scrim' );
		scrim.addEventListener( 'click', closeDrawer );

		const panel = element( 'aside', 'wra-drawer' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.setAttribute( 'aria-label', L.order + ' #' + detail.number );
		panel.tabIndex = -1;

		const bar = element( 'div', 'wra-drawer__bar' );
		bar.append( element( 'span', 'wra-drawer__eyebrow', L.order + ' #' + detail.number ) );
		const close = element( 'button', 'wra-drawer__close' );
		close.append( icon( 'ph ph-x' ) );
		close.type = 'button';
		close.title = L.close;
		close.setAttribute( 'aria-label', L.close );
		close.addEventListener( 'click', closeDrawer );
		bar.append( close );

		const body = element( 'div', 'wra-drawer__body' );

		const head = element( 'div' );
		head.append( element( 'h2', 'wra-drawer__title', detail.customer ) );
		const meta = [ detail.dining_type === 'dine_in' ? 'Dine-in · Table ' + ( detail.table || '-' ) : ( detail.mode === 'delivery' ? L.delivery : L.pickup ) ];
		meta.push( L.orderTime + ' ' + orderTimeLabel( detail ) );
		meta.push( L.serviceTime + ' ' + ( detail.service_label || detail.promised_label || L.notScheduled ) );
		if ( detail.phone ) meta.push( detail.phone );
		head.append( element( 'p', 'wra-drawer__intro', meta.join( ' · ' ) ) );
		body.append( head );

		const chip = element( 'span', 'wra-chip wra-s-' + statusClass( detail.status ) );
		chip.append( element( 'span', 'wra-chip__dot' ), document.createTextNode( detail.status_label || '' ) );
		const chipWrap = element( 'div' );
		chipWrap.append( chip );
		body.append( chipWrap );

		body.append( section( L.workflow, steps( detail ) ) );

		const lines = element( 'div', 'wra-box' );
		( detail.items || [] ).forEach( function ( item ) {
			const line = element( 'div', 'wra-line' );
			line.append( element( 'span', 'wra-line__qty', String( item.quantity ) ) );
			const name = element( 'span', 'wra-line__name' );
			name.append( document.createTextNode( item.name ) );
			const extras = [];
			if ( item.modifiers ) {
				Object.keys( item.modifiers ).forEach( function ( key ) {
					const value = item.modifiers[ key ];
					extras.push( typeof value === 'object' && value !== null ? ( value.label || value.name || '' ) : String( value ) );
				} );
			}
			if ( item.note ) extras.push( item.note );
			const detailText = extras.filter( Boolean ).join( ' · ' );
			if ( detailText ) name.append( element( 'small', 'wra-linemods', detailText ) );
			line.append( name, element( 'span', 'wra-line__price', item.total ) );
			lines.append( line );
		} );
		body.append( section( L.items, lines ) );

		if ( detail.note ) {
			body.append( section( L.kitchenNote, element( 'p', 'wra-note', detail.note ) ) );
		}

		body.append( section( L.totals, totals( detail ) ) );

		if ( detail.billing_address ) {
			body.append( section( detail.mode === 'delivery' && detail.shipping_address ? L.deliveryAddress : L.billing,
				element( 'p', 'wra-drawer__meta', detail.mode === 'delivery' && detail.shipping_address ? detail.shipping_address : detail.billing_address ) ) );
		}

		if ( detail.history && detail.history.length ) {
			const list = element( 'ul', 'wra-history' );
			detail.history.forEach( function ( event ) {
				const row = element( 'li' );
				row.append( element( 'span', '', event.label ) );
				const at = element( 'time', '', event.at_gmt ? new Date( event.at_gmt.replace( ' ', 'T' ) + 'Z' ).toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } ) : '' );
				row.append( at );
				list.append( row );
			} );
			body.append( section( L.history, list ) );
		}

		const actions = element( 'div', 'wra-drawer__actions' );
		const next = ( detail.allowed_transitions || [] )[ 0 ];
		if ( next && WowRestroBoard.canOperate ) {
			const actionIcon = TRANSITION_ICONS[ next ] || 'ph-bold ph-check';
			const actionLabel = WowRestroBoard.transitionLabels[ next ] || next;
			const advance = element( 'button', 'wra-btn wra-btn--primary wra-action-' + statusClass( next ) );
			advance.append( icon( actionIcon ), document.createTextNode( actionLabel ) );
			advance.type = 'button';
			advance.addEventListener( 'click', function () {
				setActionBusy( advance, true, actionIcon, actionLabel );
				transition( detail, next ).then( closeDrawer ).catch( function ( error ) {
					setActionBusy( advance, false, actionIcon, actionLabel );
					// Reload behind the drawer, then say why nothing moved.
					if ( error.status === 409 || error.status === 428 ) {
						flagError( error.message );
						refresh().then( function () { openDrawer( detail.id ); } );
						return;
					}
					flagError( error.message );
				} );
			} );
			actions.append( advance );
		}
		const printOne = element( 'button', 'wra-btn wra-btn--icon' );
		printOne.append( icon( 'ph ph-printer' ) );
		printOne.type = 'button';
		printOne.title = L.printTicket;
		printOne.setAttribute( 'aria-label', L.printTicket );
		printOne.addEventListener( 'click', function () { window.print(); } );
		actions.append( printOne );
		body.append( actions );

		panel.append( bar, body );
		drawerRoot.replaceChildren( scrim, panel );
		panel.focus();
	}

	async function openDrawer( id ) {
		stopOrderSound();
		try {
			const detail = await request( WowRestroBoard.operationsUrl + id );
			renderDrawer( detail );
		} catch ( error ) {
			setStatus( error.message, true );
			closeDrawer();
		}
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) closeDrawer();
	} );

	function renderBoard() {
		const list = visible();
		if ( ! list.length ) {
			const empty = element( 'div', 'wra-empty' );
			const mark = element( 'span', 'wra-empty__mark' );
			mark.append( icon( 'ph ph-cooking-pot' ) );
			empty.append( mark );
			empty.append( element( 'strong', 'wra-empty__title', orders.length ? L.noMatch : L.calm ) );
			empty.append( element( 'span', 'wra-empty__copy', orders.length ? L.noMatchCopy : L.empty ) );
			board.replaceChildren( empty );
			return;
		}
		const fragment = document.createDocumentFragment();
		list.forEach( function ( order ) { fragment.append( ticket( order ) ); } );
		board.replaceChildren( fragment );
	}

	function announce( list ) {
		let hasNewOrder = false;
		let hasLateOrder = false;
		list.forEach( function ( order ) {
			if ( hydrated && ! known.has( order.id ) && order.status === 'new' ) hasNewOrder = true;
			if ( order.is_late && ! alertedLate.has( order.id ) ) {
				hasLateOrder = true;
				alertedLate.add( order.id );
			}
		} );
		known = new Set( list.map( function ( order ) { return order.id; } ) );
		hydrated = true;
		if ( hasNewOrder ) newOrderSound();
		else if ( hasLateOrder ) lateOrderSound();
	}

	function setStatus( text, isError ) {
		if ( ! status ) return;
		status.textContent = text;
		status.classList.toggle( 'is-error', !! isError );
	}

	// A refresh already in flight resolves the next refresh() immediately, so an
	// error set around one would be overwritten by the poll that lands after it.
	// Hold the reason until it has been on screen long enough to read.
	let stickyError = '';
	let stickyTimer = null;
	function flagError( message ) {
		stickyError = message;
		setStatus( message, true );
		clearTimeout( stickyTimer );
		stickyTimer = setTimeout( function () { stickyError = ''; }, 8000 );
	}
	function clearError() {
		stickyError = '';
		clearTimeout( stickyTimer );
	}

	function updatePauseButton( paused ) {
		if ( ! pauseButton ) return;
		pauseButton.setAttribute( 'aria-pressed', paused ? 'true' : 'false' );
		setButtonLabel( pauseButton, paused ? 'ph-fill ph-play-circle' : 'ph-fill ph-pause-circle', paused ? L.resume : L.pause );
		const pill = document.querySelector( '[data-wowrestro-live]' );
		if ( pill ) {
			pill.classList.toggle( 'is-paused', paused );
			const text = pill.querySelector( '[data-wowrestro-live-label]' );
			if ( text ) text.textContent = paused ? L.paused : L.open;
		}
	}

	async function refresh( localOnly ) {
		if ( refreshing ) return;
		refreshing = true;
		if ( refreshButton && ! localOnly ) {
			refreshButton.disabled = true;
			refreshButton.setAttribute( 'aria-busy', 'true' );
		}
		try {
			const payload = await requestQueue( localOnly );
			if ( payload === null ) {
				setStatus( stickyError || L.upToDate, !! stickyError );
				return;
			}
			const fingerprint = JSON.stringify( { orders: payload.orders || [], paused: !! payload.paused } );
			if ( queueFingerprint && fingerprint === queueFingerprint ) {
				setStatus( stickyError || L.upToDate, !! stickyError );
				return;
			}
			queueFingerprint = fingerprint;
			orders = payload.orders || [];
			announce( orders );
			renderFilters();
			renderBoard();
			updatePauseButton( !! payload.paused );
			if ( stickyError ) {
				setStatus( stickyError, true );
			} else {
				setStatus( ( payload.paused ? L.paused + ' · ' : '' ) + L.updated, false );
			}
		} catch ( error ) {
			setStatus( L.error, true );
		} finally {
			refreshing = false;
			if ( refreshButton && ! localOnly ) {
				refreshButton.disabled = false;
				refreshButton.removeAttribute( 'aria-busy' );
			}
		}
	}

	if ( refreshButton ) refreshButton.addEventListener( 'click', refresh );

	if ( pauseButton ) {
		pauseButton.addEventListener( 'click', async function () {
			const paused = pauseButton.getAttribute( 'aria-pressed' ) !== 'true';
			pauseButton.disabled = true;
			try {
				const payload = await request( WowRestroBoard.statusUrl, { method: 'POST', body: JSON.stringify( { paused: paused } ) } );
				updatePauseButton( !! payload.paused );
				setStatus( payload.paused ? L.paused : L.open, false );
			} catch ( error ) {
				setStatus( error.message, true );
			}
			pauseButton.disabled = false;
		} );
	}

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			query = searchInput.value;
			renderBoard();
		} );
	}

	function addManualItem() {
		const holder = document.querySelector( '[data-wowrestro-manual-items]' );
		if ( ! holder ) return;
		const row = element( 'div', 'wra-manual__item' );
		row.dataset.wowrestroManualItem = 'yes';
		const select = document.createElement( 'select' );
		select.required = true;
		select.setAttribute( 'aria-label', L.menuItem );
		select.append( new Option( L.chooseItem, '' ) );
		WowRestroBoard.products.forEach( function ( product ) {
			select.append( new Option( product.name + ' · ' + product.price, product.id ) );
		} );
		const quantity = document.createElement( 'input' );
		quantity.type = 'number';
		quantity.min = '1';
		quantity.value = '1';
		quantity.setAttribute( 'aria-label', L.quantity );
		const details = element( 'div', 'wra-manual__item-details' );
		const remove = element( 'button', 'wra-btn wra-btn--sm', L.removeItem );
		remove.type = 'button';
		remove.addEventListener( 'click', function () {
			row.remove();
			clearQuote();
			if ( ! holder.children.length ) addManualItem();
		} );
		select.addEventListener( 'change', function () {
			renderManualItemDetails( row, Number( select.value ) );
			clearQuote();
		} );
		quantity.addEventListener( 'change', clearQuote );
		row.append( select, quantity, remove, details );
		holder.append( row );
		clearQuote();
	}

	function productById( id ) {
		return WowRestroBoard.products.find( function ( product ) { return Number( product.id ) === Number( id ); } );
	}

	function renderManualItemDetails( row, productId ) {
		const details = row.querySelector( '.wra-manual__item-details' );
		const product = productById( productId );
		details.replaceChildren();
		if ( ! product ) return;
		if ( product.variations && product.variations.length ) {
			const label = element( 'label', '', L.variation );
			const variation = document.createElement( 'select' );
			variation.dataset.variationId = 'yes';
			variation.required = true;
			variation.append( new Option( L.chooseVariation, '' ) );
			product.variations.forEach( function ( item ) { variation.append( new Option( item.name + ' · ' + item.price, item.id ) ); } );
			variation.addEventListener( 'change', clearQuote );
			label.append( variation );
			details.append( label );
		}
		( product.modifiers || [] ).forEach( function ( group ) {
			const fieldset = element( 'fieldset', 'wra-manual__modifiers' );
			fieldset.dataset.modifierGroup = String( group.id );
			const legend = element( 'legend', '', group.name + ( group.min ? ' *' : '' ) );
			fieldset.append( legend );
			( group.options || [] ).forEach( function ( option ) {
				const label = element( 'label' );
				const input = document.createElement( 'input' );
				input.type = group.type === 'single' ? 'radio' : 'checkbox';
				input.name = 'modifier-' + row.dataset.wowrestroItemKey + '-' + group.id;
				input.value = option.id;
				input.checked = !! option.default;
				input.addEventListener( 'change', clearQuote );
				label.append( input, document.createTextNode( ' ' + option.label + ( Number( option.price ) ? ' (+' + option.price + ')' : '' ) ) );
				fieldset.append( label );
			} );
			details.append( fieldset );
		} );
		const noteLabel = element( 'label', '', L.itemNote );
		const note = document.createElement( 'textarea' );
		note.rows = 2;
		note.maxLength = 500;
		note.dataset.itemNote = 'yes';
		noteLabel.append( note );
		details.append( noteLabel );
	}

	let manualItemKey = 0;
	function collectManualItems() {
		return Array.from( manualForm.querySelectorAll( '[data-wowrestro-manual-item]' ) ).map( function ( row ) {
			const productId = Number( row.querySelector( 'select' ).value );
			const modifiers = {};
			row.querySelectorAll( '[data-modifier-group]' ).forEach( function ( group ) {
				modifiers[ group.dataset.modifierGroup ] = Array.from( group.querySelectorAll( 'input:checked' ) ).map( function ( input ) { return input.value; } );
			} );
			return {
				product_id: productId,
				variation_id: Number( row.querySelector( '[data-variation-id]' )?.value || 0 ),
				quantity: Number( row.querySelector( 'input[type="number"]' ).value ) || 1,
				modifiers: modifiers,
				note: row.querySelector( '[data-item-note]' )?.value || ''
			};
		} ).filter( function ( item ) { return item.product_id > 0; } );
	}

	function manualPayload() {
		const data = new FormData( manualForm );
		const address = {
			address_1: data.get( 'address_1' ) || '', address_2: data.get( 'address_2' ) || '',
			city: data.get( 'city' ) || '', state: data.get( 'state' ) || '', postcode: data.get( 'postcode' ) || '',
			country: String( data.get( 'country' ) || '' ).toUpperCase(), email: data.get( 'email' ) || '', phone: data.get( 'phone' ) || ''
		};
		return {
			customer: data.get( 'customer' ), phone: data.get( 'phone' ), email: data.get( 'email' ),
			mode: data.get( 'mode' ), table: data.get( 'table' ), location: new URL( window.location.href ).searchParams.get( 'wr_location' ) || '', postcode: data.get( 'postcode' ), requested_at: data.get( 'requested_at' ),
			payment_flow: data.get( 'payment_flow' ) || 'pay_later', send_payment_link: data.get( 'payment_flow' ) === 'payment_link',
			shipping_rate_id: data.get( 'shipping_rate_id' ) || '', billing: address, shipping: address,
			note: data.get( 'note' ), status_opt_in: data.has( 'status_opt_in' ), items: collectManualItems()
		};
	}

	function clearQuote() {
		if ( ! manualForm ) return;
		manualForm.dataset.wowrestroQuoteReady = 'no';
		manualForm.dataset.wowrestroRateRequired = 'no';
		const rates = manualForm.querySelector( '[data-wowrestro-shipping-rate]' );
		if ( rates ) {
			rates.disabled = true;
			rates.replaceChildren( new Option( L.quoteFirst, '' ) );
		}
	}

	function updateManualServiceFields() {
		if ( ! manualForm ) return;
		const mode = manualForm.elements.mode ? manualForm.elements.mode.value : 'pickup';
		manualForm.querySelectorAll( '[data-wowrestro-service-field]' ).forEach( function ( field ) {
			const active = field.dataset.wowrestroServiceField === mode;
			field.hidden = ! active;
			field.querySelectorAll( 'input, select, textarea' ).forEach( function ( control ) {
				control.disabled = ! active;
				if ( ! active ) control.value = '';
			} );
		} );
		clearQuote();
	}

	if ( manualForm ) {
		const originalAddManualItem = addManualItem;
		addManualItem = function () {
			originalAddManualItem();
			const rows = manualForm.querySelectorAll( '[data-wowrestro-manual-item]' );
			const row = rows[ rows.length - 1 ];
			if ( row ) row.dataset.wowrestroItemKey = String( ++manualItemKey );
		};
		addManualItem();
		if ( manualForm.elements.mode ) manualForm.elements.mode.addEventListener( 'change', updateManualServiceFields );
		updateManualServiceFields();
		const adder = document.querySelector( '[data-wowrestro-add-item]' );
		if ( adder ) adder.addEventListener( 'click', addManualItem );
		const quoteButton = manualForm.querySelector( '[data-wowrestro-quote]' );
		if ( quoteButton ) quoteButton.addEventListener( 'click', async function () {
			if ( ! manualForm.reportValidity() ) return;
			const message = manualForm.querySelector( '[data-wowrestro-manual-message]' );
			const rates = manualForm.querySelector( '[data-wowrestro-shipping-rate]' );
			quoteButton.disabled = true;
			message.classList.remove( 'is-error' );
			message.textContent = L.checking;
			try {
				const payload = manualPayload();
				const requestedTime = manualForm.elements.requested_at;
				if ( payload.requested_at && Date.parse( payload.requested_at ) <= Date.now() ) {
					if ( requestedTime ) requestedTime.value = '';
					payload.requested_at = '';
				}
				const quote = await request( WowRestroBoard.quoteUrl, { method: 'POST', body: JSON.stringify( payload ) } );
				const serviceMode = payload.mode === 'dinein' ? 'pickup' : payload.mode;
				const available = ( quote.shipping_rates || [] ).filter( function ( rate ) { return rate.mode === serviceMode; } );
				const pickupWithoutRate = serviceMode === 'pickup' && quote.available !== false && ! available.length;
				rates.replaceChildren( new Option( pickupWithoutRate ? L.noRate : L.chooseRate, '' ) );
				available.forEach( function ( rate ) { rates.append( new Option( rate.label + ' · ' + rate.cost_label, rate.id ) ); } );
				rates.disabled = ! available.length;
				if ( available.length === 1 ) rates.value = available[ 0 ].id;
				manualForm.dataset.wowrestroQuoteReady = available.length || pickupWithoutRate ? 'yes' : 'no';
				manualForm.dataset.wowrestroRateRequired = available.length ? 'yes' : 'no';
				message.textContent = available.length ? L.rateReady : ( pickupWithoutRate ? L.pickupReady : ( quote.message || L.manualError ) );
				message.classList.toggle( 'is-error', ! available.length && ! pickupWithoutRate );
			} catch ( error ) {
				message.classList.add( 'is-error' );
				message.textContent = error.message || L.manualError;
			}
			quoteButton.disabled = false;
		} );
		manualForm.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const message = manualForm.querySelector( '[data-wowrestro-manual-message]' );
			const submit = manualForm.querySelector( '[type="submit"]' );
			const payload = manualPayload();
			if ( quoteButton && ( manualForm.dataset.wowrestroQuoteReady !== 'yes' || ( manualForm.dataset.wowrestroRateRequired === 'yes' && ! payload.shipping_rate_id ) ) ) {
				message.classList.add( 'is-error' );
				message.textContent = L.quoteFirst;
				return;
			}
			submit.disabled = true;
			message.classList.remove( 'is-error' );
			message.textContent = L.creating;
			try {
				const createUrl = quoteButton ? WowRestroBoard.createUrl : WowRestroBoard.manualUrl;
				const headers = quoteButton ? { 'Idempotency-Key': ( window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'phone-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 ) ) } : {};
				const order = await request( createUrl, { method: 'POST', headers: headers, body: JSON.stringify( payload ) } );
				message.textContent = L.created.replace( '%s', order.number );
				manualForm.reset();
				updateManualServiceFields();
				document.querySelector( '[data-wowrestro-manual-items]' ).replaceChildren();
				addManualItem();
				clearQuote();
				await refresh();
			} catch ( error ) {
				message.classList.add( 'is-error' );
				message.textContent = error.message || L.manualError;
			}
			submit.disabled = false;
		} );
	}

	const onlineHandler = refresh;
	const offlineHandler = function () { setStatus( L.error, true ); };
	const visibilityHandler = function () {
		if ( document.visibilityState === 'visible' ) refresh();
	};
	const firebaseHandler = function ( event ) {
		if ( event.detail && ( event.detail.type === 'new_order' || event.detail.type === 'order_updated' ) ) refresh();
	};
	window.addEventListener( 'online', onlineHandler );
	window.addEventListener( 'offline', offlineHandler );
	document.addEventListener( 'visibilitychange', visibilityHandler );
	document.addEventListener( 'wowrestro-firebase-order', firebaseHandler );
	refresh();
	connectFirebase( false );
	const tickTimer = window.setInterval( tickTimers, 1000 );
	activeCleanup = function () {
		stopOrderSound();
		window.clearInterval( tickTimer );
		window.removeEventListener( 'online', onlineHandler );
		window.removeEventListener( 'offline', offlineHandler );
		document.removeEventListener( 'visibilitychange', visibilityHandler );
		document.removeEventListener( 'wowrestro-firebase-order', firebaseHandler );
		if ( refreshButton ) refreshButton.removeEventListener( 'click', refresh );
		if ( paintAlerts ) document.removeEventListener( 'wowrestro-firebase-status', paintAlerts );
		activeRequests = 0;
		board.classList.remove( 'is-loading' );
		board.removeAttribute( 'aria-busy' );
		drawerRoot.remove();
	};
	}

	window.WowRestroInitBoard = initBoard;
	initBoard();
} )();
