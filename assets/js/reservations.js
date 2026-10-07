/* Table reservations: a new day loads its times without reloading the page, so
   nothing typed is lost; the booking button shows it is working; and the message
   after a failed booking takes focus so it is heard. */
( function () {
	const notice = document.querySelector( '.wowrestro-reservation-notice.is-error' );
	if ( notice ) notice.focus();

	document.addEventListener( 'change', function ( event ) {
		const date = event.target.closest( '[data-wowrestro-reservation-date]' );
		if ( ! date || ! date.value ) return;
		const time = date.form.querySelector( '[name="time"]' );
		const url = new URL( window.location.href );
		url.searchParams.set( 'wr_reservation_date', date.value );
		time.disabled = true;
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) throw new Error( String( response.status ) );
				return response.text();
			} )
			.then( function ( html ) {
				const next = new DOMParser().parseFromString( html, 'text/html' ).querySelector( '.wowrestro-reservation-form [name="time"]' );
				if ( ! next ) throw new Error( 'No time list' );
				time.innerHTML = next.innerHTML;
				time.disabled = false;
				window.history.replaceState( null, '', url );
				// A booking that fails comes back to this day, not the one the page opened on.
				const back = date.form.querySelector( '[name="redirect"]' );
				if ( back ) {
					const target = new URL( back.value, window.location.href );
					target.searchParams.set( 'wr_reservation_date', date.value );
					back.value = target.pathname + target.search;
				}
			} )
			// Without the new list, load the page for that day as before.
			.catch( function () { window.location.href = url.toString(); } );
	} );

	// Runs only once the browser's own checks pass; a second press would book twice.
	document.addEventListener( 'submit', function ( event ) {
		const button = event.target.closest( '.wowrestro-reservation-form' ) && event.target.querySelector( '.wowrestro-reservation-submit' );
		if ( ! button ) return;
		if ( 'true' === button.getAttribute( 'aria-busy' ) ) {
			event.preventDefault();
			return;
		}
		button.setAttribute( 'aria-busy', 'true' );
	} );

	// Back from the next page, the button must not still spin.
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.wowrestro-reservation-submit[aria-busy]' ).forEach( function ( button ) {
			button.removeAttribute( 'aria-busy' );
		} );
	} );
} )();
