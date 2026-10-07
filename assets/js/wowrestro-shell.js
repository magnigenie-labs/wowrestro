/**
 * Shared WowRestro admin shell: the live clock and the toast queue.
 *
 * Loaded on every redesigned WowRestro screen. Screen scripts raise toasts with
 * window.wowRestroToast( message, 'ok' | 'warn' ).
 */
( function () {
	'use strict';

	const clock = document.querySelector( '[data-wowrestro-clock]' );
	if ( clock ) {
		const tick = function () {
			clock.textContent = new Date().toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
		};
		tick();
		window.setInterval( tick, 1000 );
	}

	let root = null;
	function toastRoot() {
		if ( ! root ) {
			root = document.createElement( 'div' );
			root.className = 'wra-toastroot';
			root.setAttribute( 'aria-live', 'polite' );
			document.body.append( root );
		}
		return root;
	}

	window.wowRestroToast = function ( message, kind ) {
		if ( ! message ) return;
		const node = document.createElement( 'div' );
		node.className = 'wra-toast' + ( kind === 'warn' ? ' is-warn' : '' );
		const icon = document.createElement( 'span' );
		icon.className = 'wra-toast__icon';
		icon.textContent = kind === 'warn' ? '!' : '✓';
		const text = document.createElement( 'span' );
		text.textContent = message;
		node.append( icon, text );
		toastRoot().append( node );
		window.setTimeout( function () { node.remove(); }, 2800 );
	};
} )();
