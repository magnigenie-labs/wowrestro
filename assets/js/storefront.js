( function () {
	const menus = document.querySelectorAll( '[data-wowrestro-menu]' );
	menus.forEach( function ( menu ) {
		const input = menu.querySelector( '[data-wowrestro-search]' );
		if ( ! input ) return;
		input.addEventListener( 'input', function () {
			const query = input.value.trim().toLowerCase();
			menu.querySelectorAll( '.wowrestro-product' ).forEach( function ( product ) {
				product.hidden = query && ! product.dataset.search.includes( query );
			} );
		} );
		menu.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '.ajax_add_to_cart' );
			if ( ! button ) return;
			button.dataset.wowrestroCartPending = 'yes';
			const observer = new MutationObserver( function () {
				if ( button.classList.contains( 'added' ) && ! button.classList.contains( 'loading' ) ) {
					updateCartCount( button );
					observer.disconnect();
				}
			} );
			observer.observe( button, { attributes: true, attributeFilter: [ 'class' ] } );
			setTimeout( function () { observer.disconnect(); }, 15000 );
		} );
	} );

	function updateCartCount( button ) {
		if ( ! button || button.dataset.wowrestroCartPending !== 'yes' ) return;
		button.dataset.wowrestroCartPending = 'no';
		const quantity = Math.max( 1, Number( button.dataset.quantity || 1 ) || 1 );
		document.querySelectorAll( '[data-wowrestro-cart-count]' ).forEach( function ( count ) {
			count.textContent = String( ( Number( count.textContent ) || 0 ) + quantity );
		} );
	}

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', function ( event, fragments, cartHash, button ) {
			updateCartCount( button && button[ 0 ] );
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		const input = event.target.closest( '[data-wowrestro-product-addons] input[type="checkbox"]' );
		if ( ! input ) return;
		const group = input.closest( '[data-addon-max]' );
		const maximum = Number( group && group.dataset.addonMax ) || 0;
		if ( ! maximum ) return;
		const checked = group.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
		group.querySelectorAll( 'input[type="checkbox"]:not(:checked)' ).forEach( function ( option ) {
			option.disabled = checked >= maximum;
		} );
	} );
} )();

/* Bridge WowRestro add-ons into the custom Order Online product modal. */
( function () {
	const originalFetch = window.fetch.bind( window );
	let productId = 0;
	let products = new Map();
	let selected = {};
	let baseMinor = 0;
	let lastTotalLabel = '';
	let scheduled = false;
	let validationAnnounced = false;
	let menuPromise = null;
	let cartProductIds = [];
	let cartStateLoaded = false;

	function loadProducts() {
		if ( menuPromise ) return menuPromise;
		const config = window.WOWRESTRO || {};
		if ( ! config.pluginApi ) return Promise.resolve( products );
		menuPromise = originalFetch( config.pluginApi.replace( /\/$/, '' ) + '/menu?per_page=60', { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) throw new Error( 'Unable to load WowRestro menu data.' );
				return response.json();
			} )
			.then( function ( data ) {
				products = new Map( ( data.products || [] ).map( function ( product ) { return [ Number( product.id ), product ]; } ) );
				return products;
			} )
			.catch( function () {
				menuPromise = null;
				return products;
			} );
		return menuPromise;
	}

	function exposeModifierOptions( response ) {
		return Promise.all( [ response.json(), loadProducts() ] ).then( function ( result ) {
			const data = result[ 0 ];
			if ( Array.isArray( data ) ) {
				data.forEach( function ( product ) {
					const pluginProduct = products.get( Number( product.id ) );
					if ( ! pluginProduct || ! pluginProduct.modifiers || ! pluginProduct.modifiers.length || product.has_options ) return;
					// The custom storefront only opens its options dialog when
					// has_options is true. A synthetic zero-attribute variation lets
					// simple products use that same dialog without changing the cart id.
					product.has_options = true;
					product.variations = [ { id: Number( product.id ), attributes: [] } ];
				} );
			}
			const headers = new Headers( response.headers );
			headers.delete( 'content-length' );
			headers.delete( 'content-encoding' );
			return new Response( JSON.stringify( data ), {
				status: response.status,
				statusText: response.statusText,
				headers: headers,
			} );
		} );
	}

	window.fetch = function ( input, options ) {
		const url = typeof input === 'string' ? input : ( input && input.url ) || '';
		const product = products.get( productId );
		if ( /\/wc\/store\/v1\/cart\/add-item(?:\?|$)/.test( url ) && product && product.modifiers.length && document.querySelector( '.oe-modal__box' ) ) {
			try {
				const next = Object.assign( {}, options || {} );
				const body = JSON.parse( next.body || '{}' );
				body.wowrestro_modifiers = selected;
				next.body = JSON.stringify( body );
				options = next;
			} catch ( error ) {
				// Leave non-JSON requests untouched.
			}
		}
		const request = originalFetch( input, options );
		if ( /\/wc\/store\/v1\/products(?:\?|$)/.test( url ) ) {
			return request.then( exposeModifierOptions );
		}
		if ( /\/wc\/store\/v1\/cart(?:\/|\?|$)/.test( url ) ) {
			request.then( trackCartResponse ).catch( function () {} );
		}
		return request;
	};

	function syncCartState( data ) {
		if ( ! data || ! Array.isArray( data.items ) ) return;
		cartStateLoaded = true;
		cartProductIds = data.items.reduce( function ( ids, item ) {
			[ item.id, item.parent_id, item.variation_id, item.variation && item.variation.id ].forEach( function ( id ) {
				id = Number( id || 0 );
				if ( id && ids.indexOf( id ) === -1 ) ids.push( id );
			} );
			return ids;
		}, [] );
		scheduleRender();
	}

	function trackCartResponse( response ) {
		if ( ! response || ! response.ok || ! response.clone ) return response;
		response.clone().json().then( syncCartState ).catch( function () {} );
		return response;
	}

	function loadCartState() {
		const root = ( window.WOWRESTRO && window.WOWRESTRO.pluginApi ) || '';
		const match = root.match( /^(.*\/wp-json)\// );
		const url = ( match ? match[ 1 ] : window.location.origin + '/wp-json' ) + '/wc/store/v1/cart';
		return originalFetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) throw new Error( 'Unable to load cart.' );
				return response.json();
			} )
			.then( syncCartState )
			.catch( function () {} );
	}

	function escapeHtml( value ) {
		return String( value == null ? '' : value ).replace( /[&<>\"]/g, function ( character ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ character ];
		} );
	}

	function currency() {
		return ( window.WOWRESTRO && window.WOWRESTRO.currency ) || { symbol: '', minor: 2, decimal: '.', thousand: ',' };
	}

	function money( minor ) {
		const format = currency();
		const digits = Number( format.minor == null ? 2 : format.minor );
		let value = Math.max( 0, Math.round( minor ) );
		let number = String( value ).padStart( digits + 1, '0' );
		let whole = digits ? number.slice( 0, -digits ) : number;
		const fraction = digits ? number.slice( -digits ) : '';
		whole = whole.replace( /\B(?=(\d{3})+(?!\d))/g, format.thousand == null ? ',' : format.thousand );
		return ( format.symbol || '' ) + whole + ( fraction ? ( format.decimal || '.' ) + fraction : '' );
	}

	function parseMoney( label ) {
		const format = currency();
		let value = String( label ).replace( format.symbol || '', '' );
		if ( format.thousand ) value = value.split( format.thousand ).join( '' );
		if ( format.decimal && format.decimal !== '.' ) value = value.replace( format.decimal, '.' );
		value = value.replace( /[^0-9.-]/g, '' );
		return Math.round( ( parseFloat( value ) || 0 ) * Math.pow( 10, Number( format.minor == null ? 2 : format.minor ) ) );
	}

	function optionMinor( option ) {
		return Math.round( Number( option.price || 0 ) * Math.pow( 10, Number( currency().minor == null ? 2 : currency().minor ) ) );
	}

	function currentProduct() {
		return products.get( productId );
	}

	function initializeSelection( product ) {
		selected = {};
		( product.modifiers || [] ).forEach( function ( group ) {
			selected[ group.id ] = ( group.options || [] ).filter( function ( option ) { return option.default; } ).map( function ( option ) { return option.id; } );
		} );
		baseMinor = 0;
		lastTotalLabel = '';
		validationAnnounced = false;
	}

	function hint( group ) {
		if ( group.type === 'single' ) return Number( group.min ) ? 'Choose one' : 'Optional';
		if ( Number( group.min ) ) return 'Choose ' + group.min + '–' + group.max;
		return 'Choose up to ' + group.max;
	}

	function addonMarkup( product ) {
		return '<div class="wowrestro-oe-addons" data-wowrestro-oe-addons>' + product.modifiers.map( function ( group ) {
			const generatedName = group.name === product.name + ' Add-ons' || group.name === 'Add-ons';
			const name = generatedName ? 'Addons' : ( group.name || 'Addons' );
			const required = Number( group.min || 0 ) > 0;
			const groupId = 'wowrestro-addon-group-' + group.id;
			const labelId = groupId + '-label';
			const hintId = groupId + '-hint';
			return '<div id="' + groupId + '" class="oe-group' + ( required ? ' is-required' : '' ) + '" role="group" tabindex="-1" aria-labelledby="' + labelId + '" aria-describedby="' + hintId + '" data-wowrestro-addon-group="' + group.id + '">' +
				'<div class="oe-group__head"><span class="oe-group__title"><span id="' + labelId + '" class="oe-group__name">' + escapeHtml( name ) + '</span>' +
				( required ? '<span class="wowrestro-addon-required" aria-label="Required selection">Required</span>' : '' ) + '</span>' +
				'<span id="' + hintId + '" class="oe-group__hint">' + escapeHtml( hint( group ) ) + '</span></div>' +
				( group.options || [] ).map( function ( option ) {
					const type = group.type === 'single' ? 'radio' : 'checkbox';
					return '<label class="oe-opt"><input class="wowrestro-addon-input" type="' + type + '" name="wowrestro-addon-' + group.id + '" data-wowrestro-addon-option="' + escapeHtml( option.id ) + '" data-group-id="' + group.id + '" aria-required="' + ( required ? 'true' : 'false' ) + '" aria-describedby="' + hintId + '">' +
						'<span class="oe-opt__name">' + escapeHtml( option.label ) + '</span>' +
						'<span class="oe-opt__price">' + ( Number( option.price ) ? '+' + escapeHtml( money( optionMinor( option ) ) ) : 'Included' ) + '</span></label>';
				} ).join( '' ) + '</div>';
		} ).join( '' ) + '</div>';
	}

	function productForCartId( id ) {
		if ( products.has( Number( id ) ) ) return products.get( Number( id ) );
		return Array.from( products.values() ).find( function ( product ) {
			return ( product.variations || [] ).some( function ( variation ) { return Number( variation.id ) === Number( id ); } );
		} );
	}

	function cartSuggestions() {
		const cartProducts = [];
		cartProductIds.forEach( function ( id ) {
			const product = productForCartId( id );
			if ( product && ! cartProducts.some( function ( item ) { return Number( item.id ) === Number( product.id ); } ) ) cartProducts.push( product );
		} );
		const inCart = new Set( cartProducts.map( function ( product ) { return Number( product.id ); } ) );
		const suggestions = [];
		cartProducts.forEach( function ( product ) {
			( product.cross_sells || [] ).forEach( function ( id ) {
				const suggestion = products.get( Number( id ) );
				if ( ! suggestion || ! suggestion.in_stock || inCart.has( Number( suggestion.id ) ) ) return;
				if ( suggestions.some( function ( item ) { return Number( item.id ) === Number( suggestion.id ); } ) ) return;
				suggestions.push( suggestion );
			} );
		} );
		return suggestions.slice( 0, 4 );
	}

	function suggestionsMarkup( suggestions ) {
		if ( ! suggestions.length ) return '';
		return '<section class="wowrestro-oe-suggestions wowrestro-oe-cart-suggestions" data-wowrestro-oe-suggestions data-wowrestro-signature="' + suggestions.map( function ( item ) { return Number( item.id ); } ).join( '-' ) + '" aria-labelledby="wowrestro-cart-suggestions-title">' +
			'<div class="wowrestro-oe-suggestions__head"><span>Suggested items</span><h3 id="wowrestro-cart-suggestions-title">Complete your order</h3><small>Popular additions for your cart</small></div>' +
			'<div class="wowrestro-oe-suggestions__list">' + suggestions.map( function ( suggestion ) {
				const customizable = ( suggestion.variations || [] ).length || ( suggestion.modifiers || [] ).length;
				const action = customizable ? 'Customise' : 'Add';
				const image = suggestion.image ? '<img src="' + escapeHtml( suggestion.image ) + '" alt="" loading="lazy">' : '<span class="wowrestro-oe-suggestion__placeholder" aria-hidden="true">+</span>';
				return '<button type="button" class="wowrestro-oe-suggestion" data-wowrestro-suggestion="' + Number( suggestion.id ) + '" aria-label="' + action + ' suggested item ' + escapeHtml( suggestion.name ) + '">' +
					image + '<span class="wowrestro-oe-suggestion__copy"><strong>' + escapeHtml( suggestion.name ) + '</strong><small>' + escapeHtml( money( optionMinor( suggestion ) ) ) + '</small></span>' +
					'<span class="wowrestro-oe-suggestion__action">' + action + '</span></button>';
			} ).join( '' ) + '</div></section>';
	}

	function cartColumn( panel ) {
		let column = panel.closest( '.wowrestro-oe-cart-column' );
		if ( column ) return column;
		column = document.createElement( 'div' );
		column.className = 'wowrestro-oe-cart-column';
		panel.parentNode.insertBefore( column, panel );
		column.appendChild( panel );
		document.querySelectorAll( '.wowrestro-oe-cart-column:empty' ).forEach( function ( stale ) { stale.remove(); } );
		return column;
	}

	function renderCartSuggestions() {
		const panel = document.querySelector( '.oe-panel' );
		if ( ! panel ) return;
		let column = panel.closest( '.wowrestro-oe-cart-column' );
		const suggestions = cartStateLoaded && ! panel.querySelector( '.oe-panel__empty' ) ? cartSuggestions() : [];
		if ( ! suggestions.length ) {
			if ( column ) {
				column.parentNode.insertBefore( panel, column );
				column.remove();
			}
			return;
		}
		column = column || cartColumn( panel );
		const existing = column.querySelector( ':scope > [data-wowrestro-oe-suggestions]' );
		const signature = suggestions.map( function ( item ) { return Number( item.id ); } ).join( '-' );
		if ( existing && existing.dataset.wowrestroSignature === signature ) return;
		const wrapper = document.createElement( 'div' );
		wrapper.innerHTML = suggestionsMarkup( suggestions );
		if ( existing ) existing.replaceWith( wrapper.firstElementChild );
		else column.appendChild( wrapper.firstElementChild );
	}

	function validSelection( product ) {
		return ( product.modifiers || [] ).every( function ( group ) {
			const count = ( selected[ group.id ] || [] ).length;
			return count >= Number( group.min || 0 ) && count <= Number( group.max || 1 );
		} );
	}

	function groupIsValid( group ) {
		const count = ( selected[ group.id ] || [] ).length;
		return count >= Number( group.min || 0 ) && count <= Number( group.max || 1 );
	}

	function validationMessage( group ) {
		const minimum = Number( group.min || 0 );
		const maximum = Number( group.max || 1 );
		if ( minimum === 1 && maximum === 1 ) return 'Choose one option to add this item.';
		if ( minimum === maximum ) return 'Choose ' + minimum + ' options to add this item.';
		return 'Choose between ' + minimum + ' and ' + maximum + ' options to add this item.';
	}

	function syncValidation( product, modal, announce ) {
		let firstInvalid = null;
		( product.modifiers || [] ).forEach( function ( group ) {
			const element = modal.querySelector( '[data-wowrestro-addon-group="' + group.id + '"]' );
			if ( ! element ) return;
			const valid = groupIsValid( group );
			const hintId = 'wowrestro-addon-group-' + group.id + '-hint';
			const errorId = 'wowrestro-addon-group-' + group.id + '-error';
			element.classList.toggle( 'has-error', ! valid && announce );
			element.setAttribute( 'aria-invalid', ! valid && announce ? 'true' : 'false' );
			let message = element.querySelector( '.wowrestro-addon-error' );
			if ( valid || ! announce ) {
				if ( message ) message.remove();
				element.setAttribute( 'aria-describedby', hintId );
				element.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.setAttribute( 'aria-invalid', 'false' );
					input.setAttribute( 'aria-describedby', hintId );
				} );
				return;
			}
			if ( ! message ) {
				message = document.createElement( 'div' );
				message.className = 'wowrestro-addon-error';
				message.id = errorId;
				message.setAttribute( 'role', 'alert' );
				message.setAttribute( 'aria-live', 'assertive' );
				( element.querySelector( '.oe-group__head' ) || element ).after( message );
			}
			const text = validationMessage( group );
			if ( message.textContent !== text ) message.textContent = text;
			element.setAttribute( 'aria-describedby', hintId + ' ' + errorId );
			element.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
				input.setAttribute( 'aria-describedby', hintId + ' ' + errorId );
			} );
			if ( ! firstInvalid ) firstInvalid = element;
		} );
		if ( firstInvalid && announce ) {
			firstInvalid.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			const input = firstInvalid.querySelector( 'input' );
			if ( input ) input.focus( { preventScroll: true } );
			else firstInvalid.focus( { preventScroll: true } );
		}
		return ! firstInvalid;
	}

	function modifierMinor( product ) {
		return ( product.modifiers || [] ).reduce( function ( total, group ) {
			return total + ( group.options || [] ).reduce( function ( subtotal, option ) {
				return subtotal + ( ( selected[ group.id ] || [] ).indexOf( option.id ) !== -1 ? optionMinor( option ) : 0 );
			}, 0 );
		}, 0 );
	}

	function patchVariationOptions( product, modal ) {
		const selectedValues = {};
		modal.querySelectorAll( '[data-opt].is-on' ).forEach( function ( button ) {
			selectedValues[ button.dataset.opt ] = button.dataset.value;
		} );
		modal.querySelectorAll( '[data-opt]' ).forEach( function ( button ) {
			const values = Object.assign( {}, selectedValues );
			values[ button.dataset.opt ] = button.dataset.value;
			const wanted = Object.values( values );
			const variation = ( product.variations || [] ).find( function ( item ) {
				const available = Object.values( item.attributes || {} );
				return wanted.every( function ( value ) { return available.indexOf( value ) !== -1; } );
			} );
			button.setAttribute( 'role', 'radio' );
			button.setAttribute( 'aria-checked', button.classList.contains( 'is-on' ) ? 'true' : 'false' );
			const price = button.querySelector( '.oe-opt__price' );
			const label = variation ? money( optionMinor( variation ) ) : '';
			if ( price && variation && price.textContent !== label ) price.textContent = label;
		} );
	}

	function patchModal() {
		const product = currentProduct();
		const modal = document.querySelector( '.oe-modal__box' );
		if ( ! product || ! modal ) return;
		patchVariationOptions( product, modal );
		if ( ! product.modifiers.length ) return;

		modal.querySelectorAll( '[data-wowrestro-addon-option]' ).forEach( function ( input ) {
			const on = ( selected[ input.dataset.groupId ] || [] ).indexOf( input.dataset.wowrestroAddonOption ) !== -1;
			input.checked = on;
			input.closest( '.oe-opt' ).classList.toggle( 'is-on', on );
		} );

		const add = modal.querySelector( '[data-modaladd]' );
		if ( ! add ) return;
		const label = add.textContent.trim();
		if ( /^Add\s*[·•]/.test( label ) && label !== lastTotalLabel ) baseMinor = parseMoney( label );
		const quantity = Math.max( 1, Number( ( modal.querySelector( '.oe-step--lg span' ) || {} ).textContent ) || 1 );
		if ( /^Add\s*[·•]/.test( label ) ) {
			const total = 'Add · ' + money( baseMinor + modifierMinor( product ) * quantity );
			if ( add.textContent !== total ) add.textContent = total;
			lastTotalLabel = total;
		}
		const valid = syncValidation( product, modal, validationAnnounced );
		if ( valid ) validationAnnounced = false;
		if ( validSelection( product ) ) delete add.dataset.wowrestroInvalidAddons;
		else add.dataset.wowrestroInvalidAddons = 'yes';
	}

	function renderEnhancements() {
		renderCartSuggestions();
		const product = currentProduct();
		const modal = document.querySelector( '.oe-modal__box' );
		if ( ! product || ! modal ) return;
		if ( product.modifiers.length && ! modal.querySelector( '[data-wowrestro-oe-addons]' ) ) {
			const warning = modal.querySelector( '.oe-modal__warn' );
			const wrapper = document.createElement( 'div' );
			wrapper.innerHTML = addonMarkup( product );
			const addons = wrapper.firstElementChild;
			if ( warning ) warning.before( addons );
			else modal.querySelector( '.oe-modal__body' ).prepend( addons );
		}
		patchModal();
	}

	function scheduleRender() {
		if ( scheduled ) return;
		scheduled = true;
		queueMicrotask( function () {
			scheduled = false;
			renderEnhancements();
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		const suggestion = event.target.closest( '[data-wowrestro-suggestion]' );
		if ( ! suggestion ) return;
		event.preventDefault();
		const target = document.querySelector( '[data-add="' + Number( suggestion.dataset.wowrestroSuggestion ) + '"]' );
		if ( ! target ) return;
		const modal = suggestion.closest( '.oe-modal__box' );
		const close = modal && modal.querySelector( '[data-close]' );
		if ( close ) close.click();
		productId = 0;
		selected = {};
		validationAnnounced = false;
		setTimeout( function () { target.click(); }, 0 );
	} );

	document.addEventListener( 'click', function ( event ) {
		const open = event.target.closest( '[data-add]' );
		if ( open ) {
			const nextId = Number( open.dataset.add || 0 );
			if ( nextId !== productId ) {
				productId = nextId;
				const product = currentProduct();
				if ( product ) initializeSelection( product );
			}
			scheduleRender();
		}

		if ( event.target.closest( '[data-opt],[data-mqty]' ) ) scheduleRender();
		if ( event.target.closest( '[data-close]' ) ) queueMicrotask( function () { productId = 0; selected = {}; validationAnnounced = false; } );
	} );

	document.addEventListener( 'click', function ( event ) {
		const add = event.target.closest( '[data-modaladd]' );
		const product = currentProduct();
		const modal = add && add.closest( '.oe-modal__box' );
		if ( ! add || ! product || ! modal || validSelection( product ) ) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		validationAnnounced = true;
		syncValidation( product, modal, true );
	}, true );

	document.addEventListener( 'change', function ( event ) {
		const input = event.target.closest( '[data-wowrestro-addon-option]' );
		if ( ! input ) return;
		const product = currentProduct();
		const group = product && product.modifiers.find( function ( item ) { return String( item.id ) === input.dataset.groupId; } );
		if ( ! group ) return;
		const values = selected[ group.id ] || [];
		const optionId = input.dataset.wowrestroAddonOption;
		if ( group.type === 'single' ) selected[ group.id ] = [ optionId ];
		else if ( input.checked && values.length < Number( group.max || group.options.length ) ) selected[ group.id ] = values.concat( optionId );
		else if ( ! input.checked ) selected[ group.id ] = values.filter( function ( id ) { return id !== optionId; } );
		patchModal();
	} );

	function start() {
		loadProducts()
			.then( function () {
				const product = currentProduct();
				if ( product ) initializeSelection( product );
				scheduleRender();
				return loadCartState();
			} )
			.catch( function () {} );

		const root = document.querySelector( '[data-wowrestro-menu]' ) || document.body;
		new MutationObserver( scheduleRender ).observe( root, { childList: true, subtree: true, characterData: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', start );
	else start();
} )();
