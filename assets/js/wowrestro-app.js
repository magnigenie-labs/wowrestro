/**
 * WowRestro app runtime.
 *
 * The approved design ships its markup with a small set of template directives
 * (see includes/views/app-template.php). This interprets them; the 5 MB design
 * bundler and its authoring runtime are not shipped.
 *
 * Directives in use:
 *   <sc-for list="{{ path }}" as="name">   repeat children, no wrapper element
 *   <sc-if value="{{ path }}">             render children when truthy
 *   {{ path }}                             interpolate in text and attributes
 *   sc-camel-on-click|on-input|on-change   bind a handler from the scope
 *   style-hover="css"                      hover-only declarations
 *
 * Every expression in the design's markup is a plain dot path or a boolean
 * literal, so there is no expression language to implement.
 *
 * @package WowRestro
 */

/* global wp */
( function () {
	'use strict';

	var ROOT = document.getElementById( 'wowrestro-app' );
	var TEMPLATE = document.getElementById( 'wowrestro-app-template' );
	if ( ! ROOT || ! TEMPLATE ) {
		return;
	}

	var settings = window.wowRestroApp || {};

	// Shared by the component below: server bootstrap, currency and REST access.
	var WR = {
		boot: settings.data || {},
		activeRequests: 0,
		controlRequests: new WeakMap(),
		eventContext: null,

		/** Show one consistent loading state for every asynchronous app action. */
		startLoading: function ( source ) {
			var control = source && source.closest ? source.closest( 'button, a, [role="button"], input[type="button"], input[type="submit"]' ) : null;
			WR.activeRequests += 1;
			ROOT.setAttribute( 'aria-busy', 'true' );

			if ( ! control ) {
				return null;
			}

			var current = WR.controlRequests.get( control );
			if ( current ) {
				current.count += 1;
				return control;
			}

			WR.controlRequests.set( control, {
				count: 1,
				disabled: 'disabled' in control ? control.disabled : false,
				ariaBusy: control.getAttribute( 'aria-busy' ),
				ariaDisabled: control.getAttribute( 'aria-disabled' )
			} );
			control.classList.add( 'wcf-event-loading' );
			control.setAttribute( 'aria-busy', 'true' );
			control.setAttribute( 'aria-disabled', 'true' );
			if ( 'disabled' in control ) {
				control.disabled = true;
			}
			return control;
		},

		/** Clear a request without hiding loaders that belong to parallel work. */
		stopLoading: function ( control ) {
			WR.activeRequests = Math.max( 0, WR.activeRequests - 1 );
			if ( 0 === WR.activeRequests ) {
				ROOT.removeAttribute( 'aria-busy' );
			}

			if ( ! control ) {
				return;
			}
			var current = WR.controlRequests.get( control );
			if ( ! current ) {
				return;
			}
			current.count -= 1;
			if ( current.count > 0 ) {
				return;
			}

			WR.controlRequests.delete( control );
			control.classList.remove( 'wcf-event-loading' );
			if ( null === current.ariaBusy ) {
				control.removeAttribute( 'aria-busy' );
			} else {
				control.setAttribute( 'aria-busy', current.ariaBusy );
			}
			if ( null === current.ariaDisabled ) {
				control.removeAttribute( 'aria-disabled' );
			} else {
				control.setAttribute( 'aria-disabled', current.ariaDisabled );
			}
			if ( 'disabled' in control ) {
				control.disabled = current.disabled;
			}
		},

		/** Track non-REST promises such as clipboard and generated downloads. */
		trackPromise: function ( promise, source ) {
			var control = WR.startLoading( source );
			Promise.resolve( promise ).then( function () {
				WR.stopLoading( control );
			}, function () {
				WR.stopLoading( control );
			} );
			return promise;
		},

		/** Make an authenticated request and own its global loading lifecycle. */
		request: function ( path, options, showLoading ) {
			var context = WR.eventContext;
			if ( context ) {
				context.hasRequest = true;
			}
			var tracksLoading = false !== showLoading;
			var control = tracksLoading ? WR.startLoading( context ? context.control : null ) : null;
			var request;
			try {
				request = fetch( ( settings.restUrl || '' ) + path, options );
			} catch ( error ) {
				if ( tracksLoading ) {
					WR.stopLoading( control );
				}
				return Promise.reject( error );
			}

			return request.then( function ( response ) {
				return response.json().catch( function () { return {}; } ).then( function ( payload ) {
					if ( ! response.ok ) {
						throw new Error( payload.message || ( 'HTTP ' + response.status ) );
					}
					return payload;
				} );
			} ).then( function ( payload ) {
				if ( tracksLoading ) {
					WR.stopLoading( control );
				}
				return payload;
			}, function ( error ) {
				if ( tracksLoading ) {
					WR.stopLoading( control );
				}
				throw error;
			} );
		},

		/**
		 * Format money the way WooCommerce is configured to, not as US dollars.
		 *
		 * @param {number} value Amount.
		 * @return {string} Formatted amount.
		 */
		money: function ( value ) {
			var c = settings.currency || {};
			var decimals = typeof c.decimals === 'number' ? c.decimals : 2;
			var n = Number( value );
			if ( ! isFinite( n ) ) {
				n = 0;
			}
			var parts = Math.abs( n ).toFixed( decimals ).split( '.' );
			parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousandSeparator || ',' );
			var body = parts.join( c.decimalSeparator || '.' );
			var symbol = c.symbol || '';
			var out = 'left' === ( c.position || 'left' ) ? symbol + body : body + symbol;
			return ( n < 0 ? '-' : '' ) + out;
		},

		/**
		 * POST to a WowRestro REST route with the admin nonce.
		 *
		 * @param {string} path Route below wowrestro/v1.
		 * @param {Object} body Payload.
		 * @return {Promise} Resolves with the parsed response.
		 */
		post: function ( path, body ) {
			return WR.request( path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce || ''
				},
				body: JSON.stringify( body || {} )
			} );
		},

		/** Read a fresh WowRestro REST resource with the authenticated admin session. */
		get: function ( path, runtimeOptions ) {
			return WR.request( path, {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { 'X-WP-Nonce': settings.nonce || '' }
			}, ! runtimeOptions || false !== runtimeOptions.loading );
		},

		/** Delete a WowRestro workspace resource with the authenticated admin session. */
		delete: function ( path ) {
			return WR.request( path, {
				method: 'DELETE',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': settings.nonce || '' }
			} );
		},

		/** Render a QR code entirely in the browser; no customer data leaves WordPress. */
		qrCanvas: function ( value, size ) {
			if ( ! value || 'function' !== typeof window.qrcode ) {
				return null;
			}
			try {
				var code = window.qrcode( 0, 'M' );
				code.addData( String( value ), 'Byte' );
				code.make();
				var canvas = document.createElement( 'canvas' );
				var requested = Number( size );
				var width = Number.isFinite( requested ) ? Math.min( 2048, Math.max( 128, Math.round( requested ) ) ) : 256;
				var modules = code.getModuleCount();
				var pixels = Math.max( 1, Math.floor( width / ( modules + 8 ) ) );
				var grid = pixels * ( modules + 8 );
				var offset = Math.floor( ( width - grid ) / 2 );
				var context = canvas.getContext( '2d' );
				canvas.width = width;
				canvas.height = width;
				context.fillStyle = '#fff';
				context.fillRect( 0, 0, width, width );
				context.fillStyle = '#000';
				for ( var row = 0; row < modules; row += 1 ) {
					for ( var column = 0; column < modules; column += 1 ) {
						if ( code.isDark( row, column ) ) {
							context.fillRect( offset + ( column + 4 ) * pixels, offset + ( row + 4 ) * pixels, pixels, pixels );
						}
					}
				}
				return canvas;
			} catch ( error ) {
				return null;
			}
		},

		qrDataUrl: function ( value, size ) {
			var canvas = WR.qrCanvas( value, size );
			return canvas ? canvas.toDataURL( 'image/png' ) : '';
		},

		qrHost: function ( value ) {
			try {
				return new URL( value ).hostname.replace( /^www\./, '' );
			} catch ( error ) {
				return '';
			}
		},

		qrDisplayUrl: function ( value ) {
			try {
				var parsed = new URL( value );
				return ( parsed.host + ( '/' === parsed.pathname ? '' : parsed.pathname ) ).replace( /\/$/, '' );
			} catch ( error ) {
				return '';
			}
		},

		loadImage: function ( url ) {
			return new Promise( function ( resolve, reject ) {
				var image = new Image();
				image.onload = function () { resolve( image ); };
				image.onerror = function () { reject( new Error( 'Could not load the table-card artwork.' ) ); };
				image.src = url;
			} );
		},

		drawImageCover: function ( context, image, width, height ) {
			var sourceRatio = image.width / image.height;
			var targetRatio = width / height;
			var sourceWidth = sourceRatio > targetRatio ? image.height * targetRatio : image.width;
			var sourceHeight = sourceRatio > targetRatio ? image.height : image.width / targetRatio;
			context.drawImage( image, ( image.width - sourceWidth ) / 2, ( image.height - sourceHeight ) / 2, sourceWidth, sourceHeight, 0, 0, width, height );
		},

		drawImageContain: function ( context, image, x, y, width, height ) {
			var scale = Math.min( width / image.width, height / image.height );
			var drawWidth = image.width * scale;
			var drawHeight = image.height * scale;
			context.drawImage( image, x + ( width - drawWidth ) / 2, y + ( height - drawHeight ) / 2, drawWidth, drawHeight );
		},

		copyText: function ( value ) {
			if ( navigator.clipboard && window.isSecureContext ) {
				return navigator.clipboard.writeText( value );
			}
			var input = document.createElement( 'textarea' );
			input.value = value;
			input.style.position = 'fixed';
			input.style.opacity = '0';
			document.body.appendChild( input );
			input.select();
			document.execCommand( 'copy' );
			input.remove();
			return Promise.resolve();
		},

		openQr: function ( record ) {
			var opened = window.open( record.url, '_blank', 'noopener,noreferrer' );
			if ( opened ) {
				opened.opener = null;
			}
		},

		downloadQr: function ( record ) {
			var scale = 256 === Number( record.size ) ? 1 : ( 512 === Number( record.size ) ? 1.5 : 0.75 );
			var qr = WR.qrCanvas( record.url, 420 * scale );
			if ( ! qr ) {
				return Promise.reject( new Error( 'Could not generate this QR code.' ) );
			}
			var backgroundUrl = record.backgroundUrl || appAssets.qrBackground || '';
			var brandLogoUrl = restaurantProfile.logo_url || appAssets.brandLogo || appAssets.mark || '';
			var brandLabel = String( restaurantProfile.name || WR.boot.siteName || 'Restaurant' ).slice( 0, 18 );
			return Promise.all( [ WR.loadImage( backgroundUrl ), WR.loadImage( brandLogoUrl ) ] ).then( function ( images ) {
				var card = document.createElement( 'canvas' );
				var name = String( record.name || 'Table' ).slice( 0, 32 ).toUpperCase();
				var displayUrl = WR.qrDisplayUrl( record.url ).slice( 0, 52 );
				card.width = 1200 * scale;
				card.height = 1800 * scale;
				var context = card.getContext( '2d' );
				context.scale( scale, scale );
				WR.drawImageCover( context, images[ 0 ], 1200, 1800 );
				context.fillStyle = 'rgba(3,4,28,.48)';
				context.fillRect( 0, 0, 1200, 1800 );
				context.fillStyle = 'rgba(255,255,255,.96)';
				context.beginPath();
				context.roundRect( 72, 72, 160, 160, 36 );
				context.fill();
				WR.drawImageContain( context, images[ 1 ], 92, 85, 120, 90 );
				context.fillStyle = '#17172a';
				context.textAlign = 'center';
				context.font = '700 18px Inter, Arial, sans-serif';
				context.fillText( brandLabel, 152, 207 );
				context.fillStyle = 'rgba(7,8,31,.78)';
				context.strokeStyle = 'rgba(255,255,255,.32)';
				context.lineWidth = 2;
				context.beginPath();
				context.roundRect( 830, 84, 298, 86, 43 );
				context.fill();
				context.stroke();
				context.fillStyle = '#ffffff';
				context.textAlign = 'center';
				context.font = '700 34px Inter, Arial, sans-serif';
				context.fillText( name, 979, 140 );
				context.fillStyle = '#aab5ff';
				context.font = '700 30px Inter, Arial, sans-serif';
				context.fillText( 'S C A N   T O   O R D E R', 600, 835 );
				context.fillStyle = '#ffffff';
				context.beginPath();
				context.roundRect( 350, 880, 500, 500, 36 );
				context.fill();
				context.drawImage( qr, 390, 920, 420, 420 );
				context.fillStyle = '#d2d4df';
				context.font = '400 31px Inter, Arial, sans-serif';
				context.fillText( 'Browse the full menu, order and pay from', 600, 1505 );
				context.fillText( 'your phone.', 600, 1550 );
				context.fillStyle = '#ffffff';
				context.font = '650 29px Inter, Arial, sans-serif';
				context.fillText( displayUrl, 600, 1655 );
				var link = document.createElement( 'a' );
				link.href = card.toDataURL( 'image/png' );
				link.download = String( record.name || 'wowrestro-menu' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ) + '-table-card.png';
				link.click();
			} );
		},

		/** Return a configured admin destination outside the Food Menu workspace. */
		link: function ( key ) {
			return ( settings.links || {} )[ key ] || '#';
		},

		/** Leave the app for an explicitly external configuration screen. */
		goto: function ( url ) {
			if ( url ) {
				window.location.href = url;
			}
		},

		/**
		 * Leave the app for a named native editor.
		 *
		 * @param {string} key Link key from the bootstrap.
		 */
		open: function ( key ) {
			WR.goto( WR.link( key ) );
		},

		/** Open the native WordPress media picker without leaving WowRestro. */
		pickImage: function ( callback, label ) {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			var imageLabel = String( label || 'image' ).toLowerCase();
			var frame = window.wp.media( { title: 'Choose ' + imageLabel, button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var image = frame.state().get( 'selection' ).first().toJSON();
				callback( { id: image.id, url: ( image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url ) } );
			} );
			frame.open();
		}
	};

	/**
	 * Server record set for a design collection, falling back to the design's
	 * own sample when the plugin has nothing to show for it.
	 *
	 * @param {string} key      Collection name.
	 * @param {Array}  fallback Design sample.
	 * @return {Array} Records to render.
	 */
	function boot( key, fallback ) {
		var value = WR.boot[ key ];
		return Array.isArray( value ) ? value : ( settings.strict ? [] : fallback );
	}

	var appConfig = WR.boot.config || {};
	var storeSettings = appConfig.settings || {};
	var restaurantProfile = appConfig.profile || {};
	var reservationSettings = appConfig.reservations || {};
	var appAssets = settings.assets || {};
	function enabled( key ) {
		return storeSettings[ key ] === 'yes';
	}
	function groupForRoute( route ) {
		if (route.indexOf('/food-menu') === 0 || route === '/live-orders' || route.indexOf('/qr-code') === 0) return 'food-ordering';
		if (route.indexOf('/reservations') === 0 || ['/settings?tab=reservation-rules', '/settings?tab=customization', '/settings?tab=reservation-message', '/settings?tab=payment-settings'].indexOf(route) > -1) return 'reservations';
		if (route.indexOf('/location') === 0) return 'service-options';
		if (route.indexOf('/settings') === 0 || ['/setup-wizard', '/diagnostics', '/integrations', '/shortcodes'].indexOf(route) > -1) return 'settings';
		return 'dashboard';
	}

	var EVENTS = {
		'sc-camel-on-click': 'click',
		'sc-camel-on-input': 'input',
		'sc-camel-on-change': 'change'
	};
	var VALUE_TAGS = { INPUT: 1, SELECT: 1, TEXTAREA: 1 };

	/* Scope is a stack of frames; a sc-for pushes { as: item } on top of it. */
	function lookup( path, scope ) {
		path = path.trim();
		if ( 'true' === path ) {
			return true;
		}
		if ( 'false' === path ) {
			return false;
		}
		var parts = path.split( '.' );
		var cur;
		for ( var i = scope.length - 1; i >= 0; i-- ) {
			if ( parts[ 0 ] in scope[ i ] ) {
				cur = scope[ i ][ parts[ 0 ] ];
				break;
			}
		}
		for ( var j = 1; j < parts.length && null !== cur && undefined !== cur; j++ ) {
			cur = cur[ parts[ j ] ];
		}
		return cur;
	}

	// A lone "{{ path }}" keeps the value's type (handlers, arrays, booleans);
	// anything else is string interpolation.
	function evaluate( text, scope ) {
		var whole = /^\{\{([^}]*)\}\}$/.exec( text );
		if ( whole ) {
			return lookup( whole[ 1 ], scope );
		}
		return text.replace( /\{\{([^}]*)\}\}/g, function ( _, p ) {
			var v = lookup( p, scope );
			return null === v || undefined === v ? '' : String( v );
		} );
	}

	function applyHover( el, css ) {
		var decls = css.split( ';' ).filter( Boolean ).map( function ( d ) {
			var at = d.indexOf( ':' );
			return [ d.slice( 0, at ).trim(), d.slice( at + 1 ).trim() ];
		} );
		var before = decls.map( function ( pair ) {
			return [ pair[ 0 ], el.style.getPropertyValue( pair[ 0 ] ) ];
		} );
		el.addEventListener( 'mouseenter', function () {
			decls.forEach( function ( p ) {
				el.style.setProperty( p[ 0 ], p[ 1 ] );
			} );
		} );
		el.addEventListener( 'mouseleave', function () {
			before.forEach( function ( p ) {
				el.style.setProperty( p[ 0 ], p[ 1 ] );
			} );
		} );
	}

	/* `key` is the path of child indices down the template - stable across
	   re-renders, which is what lets focus survive one (see render). */
	function build( node, scope, out, key ) {
		if ( node.nodeType === Node.TEXT_NODE ) {
			var text = evaluate( node.nodeValue, scope );
			if ( '' !== text ) {
				out.appendChild( document.createTextNode( text ) );
			}
			return;
		}
		if ( node.nodeType !== Node.ELEMENT_NODE ) {
			return;
		}

		var tag = node.tagName.toLowerCase();

		if ( 'sc-if' === tag ) {
			if ( evaluate( node.getAttribute( 'value' ), scope ) ) {
				children( node, scope, out, key );
			}
			return;
		}
		if ( 'sc-for' === tag ) {
			var list = evaluate( node.getAttribute( 'list' ), scope ) || [];
			var as = node.getAttribute( 'as' );
			list.forEach( function ( item, i ) {
				var frame = {};
				frame[ as ] = item;
				scope.push( frame );
				children( node, scope, out, key + '/' + i );
				scope.pop();
			} );
			return;
		}

		// Table tags and <select> ship as sc-raw-* so the HTML parser leaves the
		// directives inside them alone (a real <table> foster-parents any
		// <sc-for> out of itself at parse time). Restore the real tag now.
		if ( 0 === tag.indexOf( 'sc-raw-' ) ) {
			tag = tag.slice( 7 );
		}

		var el = document.createElement( tag );
		el.dataset.wrKey = key;
		Array.prototype.forEach.call( node.attributes, function ( attr ) {
			var name = attr.name;
			if ( EVENTS[ name ] ) {
				var fn = evaluate( attr.value, scope );
				if ( 'function' === typeof fn ) {
					( function ( handler, eventName, control ) {
						el.addEventListener( eventName, function ( event ) {
							var previous = WR.eventContext;
							var context = { control: control, hasRequest: false };
							var result;
							WR.eventContext = context;
							try {
								result = handler( event );
							} finally {
								WR.eventContext = previous;
							}
							if ( result && 'function' === typeof result.then && ! context.hasRequest ) {
								WR.trackPromise( result, control );
							}
							return result;
						} );
					} )( fn, EVENTS[ name ], el );
				}
			} else if ( 'style-hover' === name ) {
				// deferred: inline style must be set first, so the "before"
				// snapshot reflects the real starting value
				el.dataset.wrHover = attr.value;
			} else if ( 'value' === name && VALUE_TAGS[ el.tagName ] ) {
				el.value = evaluate( attr.value, scope );
			} else {
				el.setAttribute( name, evaluate( attr.value, scope ) );
			}
		} );
		if ( undefined !== el.dataset.wrHover ) {
			applyHover( el, el.dataset.wrHover );
			delete el.dataset.wrHover;
		}
		children( node, scope, el, key );
		// A select cannot resolve its value until its option children exist.
		if ( 'SELECT' === el.tagName && node.hasAttribute( 'value' ) ) {
			el.value = evaluate( node.getAttribute( 'value' ), scope );
		}
		out.appendChild( el );
	}

	function children( node, scope, out, key ) {
		Array.prototype.forEach.call( node.childNodes, function ( c, i ) {
			build( c, scope, out, key + '.' + i );
		} );
	}

	/* ---------- component base ---------- */

	var scheduled = false;

	function rerender() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		requestAnimationFrame( function () {
			scheduled = false;
			render();
		} );
	}

	function DCLogic() {}
	DCLogic.prototype.setState = function ( patch ) {
		var next = 'function' === typeof patch ? patch( this.state ) : patch;
		this.state = Object.assign( {}, this.state, next );
		rerender();
	};


	class Component extends DCLogic {
	  _formDirty = false;

	  state = {
	    route: settings.initialRoute || '/',
	    group: settings.initialGroup || 'dashboard',
	    banner: true,
	    search: '',
	    statusFilter: '',
	    filtersOpen: false,
	    statusOptionsOpen: false,
	    selected: [],
	    listSearch: '',
	    toast: '',
	    fieldErrors: {},
	    form: {},
	    deletedReservations: [],
	    reservationStatus: {},
	    reservations: boot('reservations', []),
	    locations: boot('locations', [
	      { id: 1, name: 'Downtown Flagship', address: '18 Kingsway, Manchester', phone: '+44 161 555 0142', modules: 'Ordering, Reservation', status: 'Published' },
	      { id: 2, name: 'Northern Quarter', address: '7 Tib Street, Manchester', phone: '+44 161 555 0177', modules: 'Ordering', status: 'Published' },
	      { id: 3, name: 'Airport Kiosk', address: 'T2 Departures, MAN', phone: '+44 161 555 0190', modules: 'Ordering', status: 'Draft' }
	    ]),
	    qrcodes: boot('qrcodes', []),
	    qrPreview: null,
	    confirm: null,
	    categories: boot('categories', [
	      { id: 1, name: 'Starters', slug: 'starters', parent: '-', items: 8, order: 1, status: 'Active' },
	      { id: 2, name: 'Pizza', slug: 'pizza', parent: '-', items: 12, order: 2, status: 'Active' },
	      { id: 3, name: 'Pasta', slug: 'pasta', parent: '-', items: 7, order: 3, status: 'Active' },
	      { id: 4, name: 'Salads', slug: 'salads', parent: '-', items: 5, order: 4, status: 'Active' },
	      { id: 5, name: 'Desserts', slug: 'desserts', parent: '-', items: 6, order: 5, status: 'Active' },
	      { id: 6, name: 'Drinks', slug: 'drinks', parent: '-', items: 9, order: 6, status: 'Draft' }
	    ]),
	    brands: boot('brands', [
	      { id: 1, name: 'Olive & Ember Kitchen', slug: 'olive-ember-kitchen', items: 31, description: 'Everything cooked in our own kitchen.' },
	      { id: 2, name: 'Stonebaked Co.', slug: 'stonebaked-co', items: 12, description: 'Sourdough bases fired at 400°C.' },
	      { id: 3, name: 'Cold Press Bar', slug: 'cold-press-bar', items: 9, description: 'Juices, cold brew and kombucha.' }
	    ]),
	    labels: boot('labels', [
	      { id: 1, name: 'Vegan', color: '#00a63e', icon: 'ph ph-leaf', products: 14 },
	      { id: 2, name: 'Spicy', color: '#e7000b', icon: 'ph ph-fire', products: 9 },
	      { id: 3, name: 'Gluten Free', color: '#00B5D8', icon: 'ph ph-grains', products: 11 },
	      { id: 4, name: "Chef's Pick", color: '#2B4BFF', icon: 'ph ph-star', products: 6 },
	      { id: 5, name: 'New', color: '#9333e9', icon: 'ph ph-sparkle', products: 4 }
	    ]),
	    allergens: boot('allergens', []),
	    modifiers: boot('modifiers', []),
	    items: boot('items', [
	      { id: 1, name: 'Margherita Pizza', category: 'Pizza', brand: 'Stonebaked Co.', price: 14.5, sale: 0, sku: 'PZ-001', stock: 'In stock', labels: ['Chef\u2019s Pick'], status: 'Published', sold: 86, nutrition: true, icon: 'ph ph-pizza', color: '#FF9900' },
	      { id: 2, name: 'Truffle Mushroom Pizza', category: 'Pizza', brand: 'Stonebaked Co.', price: 18, sale: 16.2, sku: 'PZ-004', stock: 'In stock', labels: ['Chef\u2019s Pick', 'New'], status: 'Published', sold: 61, nutrition: true, icon: 'ph ph-pizza', color: '#FF5D87' },
	      { id: 3, name: 'Alfredo Fettuccine', category: 'Pasta', brand: 'Olive & Ember Kitchen', price: 16, sale: 0, sku: 'PA-002', stock: 'In stock', labels: [], status: 'Published', sold: 54, nutrition: true, icon: 'ph ph-bowl-food', color: '#9333E9' },
	      { id: 4, name: 'Smoky Chicken Wings', category: 'Starters', brand: 'Olive & Ember Kitchen', price: 11, sale: 0, sku: 'ST-007', stock: 'In stock', labels: ['Spicy'], status: 'Published', sold: 47, nutrition: false, icon: 'ph ph-fire', color: '#e7000b' },
	      { id: 5, name: 'Caesar Salad', category: 'Salads', brand: 'Olive & Ember Kitchen', price: 8.5, sale: 0, sku: 'SL-001', stock: 'In stock', labels: ['Gluten Free'], status: 'Published', sold: 33, nutrition: true, icon: 'ph ph-leaf', color: '#00a63e' },
	      { id: 6, name: 'Garden Falafel Bowl', category: 'Salads', brand: 'Olive & Ember Kitchen', price: 12.5, sale: 11, sku: 'SL-004', stock: 'In stock', labels: ['Vegan', 'Gluten Free'], status: 'Published', sold: 28, nutrition: false, icon: 'ph ph-bowl-food', color: '#00bba7' },
	      { id: 7, name: 'Tiramisu', category: 'Desserts', brand: 'Olive & Ember Kitchen', price: 9, sale: 0, sku: 'DS-003', stock: 'Low stock', labels: [], status: 'Published', sold: 39, nutrition: false, icon: 'ph ph-cake', color: '#f0b100' },
	      { id: 8, name: 'Cold Brew Tonic', category: 'Drinks', brand: 'Cold Press Bar', price: 5.5, sale: 0, sku: 'DR-005', stock: 'In stock', labels: ['Vegan'], status: 'Draft', sold: 21, nutrition: false, icon: 'ph ph-coffee', color: '#7B3BFF' }
	    ]),
	    automations: boot('automations', [
	      { id: 1, name: 'Reservation confirmation', trigger: 'Reservation confirmed', audience: 'Customer', sent: 412, enabled: true },
	      { id: 2, name: 'Table reminder – 2 hours before', trigger: 'Reservation upcoming', audience: 'Customer', sent: 289, enabled: true },
	      { id: 3, name: 'New order alert', trigger: 'Order placed', audience: 'Admin', sent: 1204, enabled: true },
	      { id: 4, name: 'Win-back – no visit in 60 days', trigger: 'Customer inactive', audience: 'Customer', sent: 64, enabled: false }
	    ]),
	    staffRoles: boot('staffRoles', [
	      { key: 'wowrestro_manager', name: 'Manager', description: 'Full restaurant workspace, menu, operations, payments and settings.', access: 'Full access', members: 1, icon: 'ph ph-crown-simple', color: '#7B3BFF' },
	      { key: 'wowrestro_staff', name: 'Staff', description: 'Live Orders access for accepting, preparing, completing and printing orders.', access: 'Order operations', members: 0, icon: 'ph ph-user-gear', color: '#2B4BFF' },
	      { key: 'wowrestro_waiter', name: 'Waiter', description: 'Live Orders, phone orders and table-service access without management settings.', access: 'Orders and tables', members: 0, icon: 'ph ph-tray', color: '#00A63E' }
	    ]),
	    toggles: {
	      tipping: enabled('tips_enabled'), mini_cart: false, pickup: enabled('pickup_enabled'), delivery: enabled('delivery_enabled'),
	      reservation: true, table_layout: false, dine_in: false,
	      location: true, qr_code: true, discount: false,
	      auto_accept: true, live_sound: true, browser_notify: false,
	      cart_search: true, cart_count: true, show_category_filter: true,
	      auto_confirm: reservationSettings.default_status === 'confirmed', reservation_payment: Number(reservationSettings.booking_amount || 0) > 0,
	      msg_pending: reservationSettings.pending_message_enabled !== 'no', msg_confirmed: reservationSettings.confirmed_message_enabled !== 'no', msg_cancelled: false,
	      res_booking_per_guest: reservationSettings.booking_per_guest === 'yes', res_local_payment: reservationSettings.local_payment !== 'no', res_wc_payment: reservationSettings.woocommerce_payment !== 'no',
	      custom_tip: enabled('custom_tips'), tips_taxable: enabled('tips_taxable'), asap: enabled('asap_enabled'),
	      email_updates: enabled('email_updates'), free_delivery: false
	    },
	    schedule: {
	      Monday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[1] && storeSettings.service_hours.pickup[1].enabled === 'yes'),
	      Tuesday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[2] && storeSettings.service_hours.pickup[2].enabled === 'yes'),
	      Wednesday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[3] && storeSettings.service_hours.pickup[3].enabled === 'yes'),
	      Thursday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[4] && storeSettings.service_hours.pickup[4].enabled === 'yes'),
	      Friday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[5] && storeSettings.service_hours.pickup[5].enabled === 'yes'),
	      Saturday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[6] && storeSettings.service_hours.pickup[6].enabled === 'yes'),
	      Sunday: !!(storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[7] && storeSettings.service_hours.pickup[7].enabled === 'yes')
	    },
	    integrations: { mailchimp: true, fluentcrm: false, zapier: true, pabbly: false, zohoflow: false, mailpoet: false },
	    values: {
	      rest_name: restaurantProfile.name || '', rest_logo: { id: Number(restaurantProfile.logo_id || 0), url: restaurantProfile.logo_url || appAssets.brandLogo || appAssets.mark || '' }, rest_email: restaurantProfile.email || '', rest_phone: restaurantProfile.phone || '',
	      rest_address_1: restaurantProfile.address_1 || '', rest_address_2: restaurantProfile.address_2 || '',
	      rest_city: restaurantProfile.city || '', rest_state: restaurantProfile.state || '', rest_postcode: restaurantProfile.postcode || '', rest_country: restaurantProfile.country || '',
	      prep_time: String(storeSettings.prep_minutes || 30), slot_interval: String(storeSettings.slot_interval || 15),
	      slot_capacity: String(storeSettings.slot_capacity || 8), hold_minutes: String(storeSettings.hold_minutes || 10),
	      preorder_days: String(storeSettings.preorder_days || 7), delivery_lead: String(storeSettings.delivery_lead_minutes || 15),
	      tip_presets: storeSettings.tip_presets || '10,15,20', menu_per_page: String(storeSettings.menu_page_size || 30),
	      menu_template: String(storeSettings.menu_template || 1), menu_layout: storeSettings.menu_layout || 'tabs',
	      dietary_attribute: storeSettings.dietary_attribute || 'pa_dietary', allergen_attribute: storeSettings.allergen_attribute || 'pa_allergens',
	      late_grace: String(storeSettings.late_grace_minutes || 5), date_overrides: storeSettings.date_overrides || '',
	      res_advance_minutes: String(reservationSettings.advance_minutes === undefined ? 30 : reservationSettings.advance_minutes),
	      res_future_days: String(reservationSettings.future_days || 730), res_default_status: reservationSettings.default_status || 'pending', res_min_guests: String(reservationSettings.min_guests || 1),
	      res_max_guests: String(reservationSettings.max_guests || 100), res_seat_capacity: String(reservationSettings.seat_capacity || 100),
	      res_interval: String(reservationSettings.interval || 30), res_blocking_statuses: reservationSettings.blocking_statuses || ['pending', 'confirmed'],
	      res_booking_amount: String(reservationSettings.booking_amount || '0'), res_pending_message: reservationSettings.pending_message || 'Thanks! We received your reservation request and will confirm it shortly.',
	      res_confirmed_message: reservationSettings.confirmed_message || 'Your table is confirmed. We look forward to seeing you!',
	      res_button_label: reservationSettings.button_label || 'Book a table',
	      res_confirmation_label: reservationSettings.confirmation_label || 'Confirm Booking',
	      res_cancellation_label: reservationSettings.cancellation_label || 'Request Cancellation',
	      res_custom_fields: reservationSettings.custom_fields || []
	    }
	  };

	  /* ---------- navigation ---------- */
	  go(route, group) {
	    if (route !== this.state.route && !this.canLeaveForm()) return false;
	    if (settings.canManage === false) {
	      route = '/live-orders';
	      group = 'alerts-insights';
	    }
	    const nextGroup = group || groupForRoute(route);
	    this.setState(s => {
	      const values = {};
	      Object.keys(s.values).forEach(k => { if (!/^(i|v|c|b|lb|n|r|l|q|a)_/.test(k)) values[k] = s.values[k]; });
	      return { route, group: nextGroup, listSearch: '', form: {}, selected: [], values };
	    });
	    if (typeof window !== 'undefined' && window.history && window.URL) {
	      const url = new URL(window.location.href);
	      if (route === '/') url.searchParams.delete('route');
	      else url.searchParams.set('route', route);
	      url.searchParams.set('section', nextGroup);
	      if (url.toString() !== window.location.href) window.history.pushState({}, '', url.toString());
	    }
	    if (typeof window !== 'undefined' && route.indexOf('/food-menu') === 0) window.setTimeout(() => this.syncMenu(), 0);
	    if (typeof window !== 'undefined') window.scrollTo(0, 0);
	    this._formDirty = false;
	    return true;
	  }
	  isFormRoute(route) { return /\/(?:create|update)(?:\/|$)/.test(route || this.state.route || ''); }
	  canLeaveForm() {
	    return !this._formDirty || typeof window === 'undefined' || window.confirm('Discard your unsaved changes?');
	  }
	  askDelete(title, message, fn) { this.setState({ confirm: { title, message, fn } }); }
	  nextId(list) { return list.reduce((m, x) => Math.max(m, x.id), 0) + 1; }
	  slugify(s) { return String(s).toLowerCase().replace(/['\u2019]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
	  toast(msg) {
	    this.setState({ toast: msg });
	    clearTimeout(this._t);
	    this._t = setTimeout(() => this.setState({ toast: '' }), 2600);
	  }
	  val(key, fallback) {
	    const v = this.state.values[key];
	    return v === undefined ? (fallback === undefined ? '' : fallback) : v;
	  }
	  setVal(key, v) {
	    if (this.isFormRoute()) this._formDirty = true;
	    this.setState(s => ({
	      values: Object.assign({}, s.values, { [key]: v }),
	      fieldErrors: Object.assign({}, s.fieldErrors, { [key]: '' })
	    }));
	  }
	  isOn(key) { return !!this.state.toggles[key]; }
	  toggle(key) {
	    if (this.isFormRoute()) this._formDirty = true;
	    this.setState(s => ({ toggles: Object.assign({}, s.toggles, { [key]: !s.toggles[key] }) }));
	  }
	  syncMenu() {
	    const route = this.state.route || '/';
	    if (this._menuSyncing || document.hidden || route === '/live-orders' || /\/(?:create|update)(?:\/|$)/.test(route)) return Promise.resolve();
	    this._menuSyncing = true;
	    return WR.get('/workspace/menu', { loading: false }).then(snapshot => {
	      const next = {};
	      ['items', 'categories', 'brands', 'labels', 'allergens', 'modifiers'].forEach(key => {
	        if (Array.isArray(snapshot[key])) next[key] = snapshot[key];
	      });
	      const changed = Object.keys(next).some(key => JSON.stringify(next[key]) !== JSON.stringify(this.state[key]));
	      if (changed) this.setState(next);
	      this._menuSyncing = false;
	    }).catch(() => { this._menuSyncing = false; });
	  }
	  reservationFieldSettings() {
	    const fields = reservationSettings.fields || {};
	    return ['location', 'date', 'time', 'name', 'email', 'phone', 'guests', 'notes'].reduce((result, key) => {
	      const current = fields[key] || {};
	      result[key] = {
	        label: this.val('res_field_' + key + '_label', current.label || key),
	        placeholder: this.val('res_field_' + key + '_placeholder', current.placeholder || ''),
	        visible: this.val('res_field_' + key + '_visible', current.visible !== 'no') ? 'yes' : 'no',
	        required: this.val('res_field_' + key + '_required', current.required === 'yes') ? 'yes' : 'no'
	      };
	      return result;
	    }, {});
	  }
	  saveSettings() {
	    const st = this.state;
	    const email = String(this.val('rest_email', '')).trim();
	    const phone = String(this.val('rest_phone', '')).trim();
	    const digits = phone.replace(/\D/g, '');
	    const errors = {};
	    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.rest_email = 'Enter a valid contact email address.';
	    if (!/^\+?[0-9\s().-]+$/.test(phone) || digits.length < 7 || digits.length > 15) errors.rest_phone = 'Enter a valid phone number containing 7 to 15 digits.';
	    if (Object.keys(errors).length) {
	      this.setState({ fieldErrors: errors });
	      this.toast('Check the contact email and phone number');
	      return;
	    }
	    const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
	    const hours = JSON.parse(JSON.stringify(storeSettings.service_hours || { pickup: {}, delivery: {} }));
	    hours.pickup = hours.pickup || {};
	    hours.delivery = hours.delivery || {};
	    days.forEach((day, index) => {
	      const key = index + 1;
	      hours.pickup[key] = hours.pickup[key] || { enabled: 'no', periods: '11:00-22:00' };
	      hours.pickup[key].enabled = st.schedule[day] ? 'yes' : 'no';
	    });
	    const body = {
	      profile: {
	        name: this.val('rest_name'), logo_id: Number((this.val('rest_logo', { id: 0 }) || {}).id || 0), email: this.val('rest_email'), phone: this.val('rest_phone'),
	        address_1: this.val('rest_address_1'), address_2: this.val('rest_address_2'), city: this.val('rest_city'),
	        state: this.val('rest_state'), postcode: this.val('rest_postcode'), country: this.val('rest_country')
	      },
	      settings: {
	        pickup_enabled: this.isOn('pickup') ? 'yes' : 'no', delivery_enabled: this.isOn('delivery') ? 'yes' : 'no',
	        asap_enabled: this.isOn('asap') ? 'yes' : 'no', prep_minutes: this.val('prep_time'),
	        delivery_lead_minutes: this.val('delivery_lead'), slot_interval: this.val('slot_interval'),
	        slot_capacity: this.val('slot_capacity'), hold_minutes: this.val('hold_minutes'), preorder_days: this.val('preorder_days'),
	        date_overrides: this.val('date_overrides'), tips_enabled: this.isOn('tipping') ? 'yes' : 'no',
	        tip_presets: this.val('tip_presets'), custom_tips: this.isOn('custom_tip') ? 'yes' : 'no',
	        tips_taxable: this.isOn('tips_taxable') ? 'yes' : 'no', menu_page_size: this.val('menu_per_page'),
	        menu_template: this.val('menu_template'), menu_layout: this.val('menu_layout'),
	        dietary_attribute: this.val('dietary_attribute'), allergen_attribute: this.val('allergen_attribute'),
	        email_updates: this.isOn('email_updates') ? 'yes' : 'no', late_grace_minutes: this.val('late_grace'),
	        service_hours: hours
	      },
	      reservations: {
	        advance_minutes: this.val('res_advance_minutes'), future_days: this.val('res_future_days'),
	        default_status: this.val('res_default_status', 'pending'), blocking_statuses: this.val('res_blocking_statuses', []),
	        min_guests: this.val('res_min_guests'), max_guests: this.val('res_max_guests'), seat_capacity: this.val('res_seat_capacity'), interval: this.val('res_interval'),
	        booking_amount: this.val('res_booking_amount'), booking_per_guest: this.isOn('res_booking_per_guest') ? 'yes' : 'no',
	        local_payment: this.isOn('res_local_payment') ? 'yes' : 'no', woocommerce_payment: this.isOn('res_wc_payment') ? 'yes' : 'no',
	        pending_message_enabled: this.isOn('msg_pending') ? 'yes' : 'no', confirmed_message_enabled: this.isOn('msg_confirmed') ? 'yes' : 'no',
	        pending_message: this.val('res_pending_message'), confirmed_message: this.val('res_confirmed_message'), button_label: this.val('res_button_label'),
	        confirmation_label: this.val('res_confirmation_label'), cancellation_label: this.val('res_cancellation_label'),
	        fields: this.reservationFieldSettings(), custom_fields: this.val('res_custom_fields', [])
	      }
	    };
	    WR.post('/workspace/settings', body).then(result => {
	      appConfig = result || appConfig;
	      storeSettings = appConfig.settings || storeSettings;
	      restaurantProfile = appConfig.profile || restaurantProfile;
	      reservationSettings = appConfig.reservations || reservationSettings;
	      appAssets.brandLogo = restaurantProfile.logo_url || appAssets.mark || '';
	      this.toast('Settings saved');
	    }).catch(error => this.toast(error && error.message ? error.message : 'Settings could not be saved'));
	  }

	  /* ---------- nav data ---------- */
	  pro() { return WR.boot.pro === true; }

	  foodOrderingItems() {
	    return [
	      { title: 'Food Menu', url: '/food-menu', branch: '/food-menu', icon: 'ph ph-bowl-food', section: 'Daily Ops', children: [
	        { title: 'Menu Items', url: '/food-menu/items', icon: 'ph ph-hamburger', count: this.state.items.length },
	        { title: 'Categories', url: '/food-menu/categories', icon: 'ph ph-folders', count: this.state.categories.length },
	        { title: 'Add-ons', url: '/food-menu/addons', icon: 'ph ph-plus-circle', count: this.state.modifiers.length }
	      ]},
	      { title: 'Live Orders', url: '/live-orders', icon: 'ph ph-bell-ringing', section: 'Daily Ops' },
	      { title: 'QR Codes', url: '/qr-code', icon: 'ph ph-qr-code', section: 'Ordering Tools' }
	    ];
	  }
	  reservationItems() {
	    return [
	      { title: 'Reservations List', url: '/reservations', icon: 'ph ph-calendar-dots', section: 'Daily Ops' },
	      { title: 'Reservation Rules', url: '/settings?tab=reservation-rules', icon: 'ph ph-sliders-horizontal', section: 'Rules & Customization' },
	      { title: 'Customization', url: '/settings?tab=customization', icon: 'ph ph-paint-brush-broad', section: 'Rules & Customization' },
	      { title: 'Message', url: '/settings?tab=reservation-message', icon: 'ph ph-chat-teardrop-text', section: 'Rules & Customization' },
	      { title: 'Payment Settings', url: '/settings?tab=payment-settings', icon: 'ph ph-credit-card', section: 'Rules & Customization' }
	    ];
	  }
	  settingsItems() {
	    return [
	      { title: 'Setup Wizard', url: '/setup-wizard', icon: 'ph ph-magic-wand', section: 'Configuration' },
	      { title: 'General', url: '/settings?tab=general', icon: 'ph ph-gear-six', section: 'General' },
	      { title: 'Diagnostics', url: '/diagnostics', icon: 'ph ph-stethoscope', section: 'Developer' }
	    ];
	  }
	  menuCheckoutItems() {
	    return [
	      { title: 'Menu Display', url: '/settings?tab=food-menu', icon: 'ph ph-layout', section: 'Menu' },
	      { title: 'Shortcodes', url: '/shortcodes', icon: 'ph ph-code', section: 'Menu' },
	      { title: 'Tipping', url: '/settings?tab=tipping', icon: 'ph ph-coins', section: 'Checkout' }
	    ];
	  }
	  serviceOptionsItems() {
	    return [
	      { title: 'Weekly Schedule', url: '/settings?tab=schedule', icon: 'ph ph-calendar-blank', section: 'Fulfillment' },
	      { title: 'Pickup', url: '/settings?tab=pickup', icon: 'ph ph-storefront', section: 'Fulfillment' },
	      { title: 'Delivery & Zones', url: '/settings?tab=delivery', icon: 'ph ph-moped', section: 'Fulfillment' },
	      { title: 'Locations', url: '/location', icon: 'ph ph-map-pin-line', section: 'Bookings & Branches' },
	      { title: 'QR & Dine-in', url: '/qr-code', icon: 'ph ph-qr-code', section: 'Bookings & Branches' }
	    ];
	  }
	  peopleProductItems() {
	    return [
	      { title: 'Staff Roles', url: '/staff-roles', icon: 'ph ph-users-three', section: 'People' },
	      { title: 'Nutrition & Allergens', url: '/food-menu/nutrition', icon: 'ph ph-heartbeat', section: 'Product Information' },
	      { title: 'Dietary Labels', url: '/food-menu/labels', icon: 'ph ph-leaf', section: 'Product Information' }
	    ];
	  }
	  alertsInsightsItems() {
	    return [
	      { title: 'Live Orders', url: '/live-orders', icon: 'ph ph-bell-ringing', section: 'Alerts' },
	      { title: 'Alert Settings', url: '/settings?tab=notifications', icon: 'ph ph-speaker-high', section: 'Alerts' },
	      { title: 'Analytics', url: '/analytics-native', icon: 'ph ph-chart-line-up', section: 'Insights', external: 'analytics' }
	    ];
	  }
	  groups() {
	    if (settings.canManage === false) {
	      return [
	        { id: 'alerts-insights', title: 'Live Orders', icon: 'ph ph-bell-ringing', url: '/live-orders' }
	      ];
	    }
	    return [
	      { id: 'dashboard', title: 'Dashboard', icon: 'ph ph-squares-four', url: '/' },
	      { id: 'food-ordering', title: 'Food Ordering', icon: 'ph ph-bowl-food', children: this.foodOrderingItems() },
	      { id: 'reservations', title: 'Reservations', icon: 'ph ph-calendar-check', children: this.reservationItems() },
	      { id: 'menu-checkout', title: 'Menu & Checkout', icon: 'ph ph-shopping-cart-simple', children: this.menuCheckoutItems() },
	      { id: 'service-options', title: 'Service Options', icon: 'ph ph-calendar-dots', children: this.serviceOptionsItems() },
	      { id: 'people-products', title: 'People & Product Information', icon: 'ph ph-users-three', children: this.peopleProductItems() },
	      { id: 'alerts-insights', title: 'Alerts & Insights', icon: 'ph ph-chart-line-up', children: this.alertsInsightsItems() },
	      { id: 'settings', title: 'Settings', icon: 'ph ph-gear', children: this.settingsItems() }
	    ];
	  }

	  tabOf(route) {
	    const i = route.indexOf('tab=');
	    return i === -1 ? '' : route.slice(i + 4);
	  }

	  titleFor(route) {
	    const tab = this.tabOf(route);
	    const tabs = {
	      general: 'General Settings', schedule: 'Restaurant Schedule', pickup: 'Pickup Settings', advanced: 'Advanced Setup',
	      delivery: 'Delivery Settings', 'mini-cart': 'Mini Cart Settings', tipping: 'Tipping Settings',
	      'food-menu': 'Food Menu Settings', notifications: 'Live Notifications',
	      'reservation-rules': 'Settings', customization: 'Settings',
	      'reservation-message': 'Settings', 'payment-settings': 'Settings',
	      'version-control': 'Version Control'
	    };
	    if (tab) return tabs[tab] || 'Settings';
	    const map = {
	      '/': 'Dashboard', '/food-menu': 'Food Menu', '/live-orders': 'Live Orders',
	      '/setup-wizard': 'Setup Wizard', '/diagnostics': 'Diagnostics',
	      '/reservations': 'Reservations', '/reservations/create': 'Create Reservation',
	      '/location': 'Locations', '/location/create': 'Create Location',
	      '/qr-code': 'QR Codes', '/qr-code/create': 'Create QR Code',
	      '/automations': 'Email Automations', '/automations/create': 'New Automation',
	      '/integrations': 'Integrations', '/shortcodes': 'Shortcodes',
	      '/about-us': 'About Us', '/discount': 'Discounts', '/timed-product': 'Time Based Product',
	      '/receipt-layout': 'Receipt Layout', '/dine-in': 'Dine In', '/table-layout': 'Table Layout',
	      '/license': 'License', '/staff-roles': 'Staff Roles'
	    };
	    const menuMap = {
	      '/food-menu/items': 'Menu Items', '/food-menu/items/create': 'Add Menu Item',
	      '/food-menu/categories': 'Food Categories', '/food-menu/categories/create': 'Add Category',
	      '/food-menu/addons': 'Add-ons', '/food-menu/addons/create': 'Add Add-on',
	      '/food-menu/brands': "Menu's Brands", '/food-menu/brands/create': 'Add Brand',
	      '/food-menu/labels': 'Product Labels', '/food-menu/labels/create': 'Add Product Label',
	      '/food-menu/nutrition': 'Nutrition & Allergens'
	    };
	    if (map[route]) return map[route];
	    if (menuMap[route]) return menuMap[route];
	    if (route.indexOf('/food-menu/items/update/') === 0) return 'Edit Menu Item';
	    if (route.indexOf('/food-menu/categories/update/') === 0) return 'Edit Category';
	    if (route.indexOf('/food-menu/addons/update/') === 0) return 'Edit Add-on';
	    if (route.indexOf('/food-menu/brands/update/') === 0) return 'Edit Brand';
	    if (route.indexOf('/food-menu/labels/update/') === 0) return 'Edit Product Label';
	    if (route.indexOf('/food-menu/nutrition/update/') === 0) return 'Nutrition & Allergen Info';
	    if (route.indexOf('/reservations/update/') === 0) return 'Edit Reservation';
	    if (route.indexOf('/location/update/') === 0) return 'Edit Location';
	    return 'WowRestro';
	  }

	  money(n) { return WR.money(n); }
	  labelBy(name) { return this.state.labels.find(l => l.name === name) || { name, color: '#a5a9be', icon: 'ph ph-tag' }; }
	  nutritionOf(it) {
	    return {
	      calories: it.calories || '',
	      allergens: Array.isArray(it.allergens) ? it.allergens : []
	    };
	  }
	  allergenList() {
	    const saved = this.state.allergens.map(a => a.name);
	    return saved.length ? saved : ['Gluten', 'Dairy', 'Egg', 'Fish', 'Shellfish', 'Nuts', 'Peanuts', 'Soy', 'Sesame', 'Celery', 'Mustard', 'Sulphites'];
	  }
	  stockDot(s) { return { 'In stock': '#00c950', 'Low stock': '#f0b100', 'Out of stock': '#fb2c36' }[s] || '#99a1af'; }

	  /* ---------- data ---------- */
	  reservationData() {
	    return this.state.reservations || [];
	  }

	  findReservation(id) {
	    let found = null;
	    this.reservationData().forEach(g => g.rows.forEach(r => { if (r.id === id) found = r; }));
	    return found;
	  }
	  statusDot(s) {
	    if (s === 'Confirmed') return '#00c950';
	    if (s === 'Cancelled') return '#fb2c36';
	    return '#f0b100';
	  }
	  orderData() {
	    return WR.boot.orders || [];
	  }

	  orderDot(s) {
	    // WowRestro's operational statuses are translatable, so their colours
	    // come from the server rather than being keyed off English labels.
	    return (WR.boot.statusColors || {})[s] || '#99a1af';
	  }

	  /* ---------- settings definitions ---------- */
	  settingsFor(tab) {
	    const T = (key, help) => ({ kind: 'toggle', key, help });
	    const S = (key, label, options, def, span) => ({ kind: 'select', key, label, options, def, span });
	    const I = (key, label, def, placeholder, type, span, required) => ({ kind: 'input', key, label, def, placeholder, type, span, required });
	    const C = (key, label, def, suffix, prefix, tooltip, span) => ({ kind: 'compound', key, label, def, suffix, prefix, tooltip, type: 'number', span });
	    const A = (key, label, def, span) => ({ kind: 'textarea', key, label, def, span });

	    const defs = {
	      general: [
	        { title: 'Restaurant Information', description: 'Store details shared with WooCommerce orders, shipping and customer emails.', fields: [
	          { kind: 'media', key: 'rest_logo', label: 'Restaurant logo', def: { id: Number(restaurantProfile.logo_id || 0), url: restaurantProfile.logo_url || appAssets.brandLogo || appAssets.mark || '' }, fit: 'contain', span: '1 / -1', help: 'Used throughout the WowRestro workspace and on QR table cards.' },
	          I('rest_name', 'Restaurant name', ''),
	          I('rest_email', 'Contact email', '', 'orders@example.com', 'email', undefined, true),
	          I('rest_phone', 'Phone number', '', '+91 98765 43210', 'tel', undefined, true),
	          I('rest_address_1', 'Address line 1', ''),
	          I('rest_address_2', 'Address line 2', ''),
	          I('rest_city', 'City', ''),
	          I('rest_state', 'State / county', ''),
	          I('rest_postcode', 'Postcode', ''),
	          I('rest_country', 'Country code', '', 'e.g. GB')
	        ]},
	        { title: 'Order Behaviour', description: 'Shared preparation and promise controls.', fields: [
	          I('prep_time', 'Default preparation time (minutes)', '30', '', 'number'),
	          { kind: 'toggle', key: 'asap', label: 'Offer ASAP', help: 'Allow WowRestro to offer the earliest available slot' }
	        ]}
	      ],
	      schedule: [
	        { title: 'Restaurant Schedule', description: 'Open days for pickup. Existing split-shift times are preserved when saved.', fields: [
	          { kind: 'schedule', span: '1 / -1' }
	        ]},
	        { title: 'Date Overrides', description: 'One rule per line, for example 2026-12-25 | all | closed or 2026-12-31 | pickup | 11:00-15:00.', fields: [
	          A('date_overrides', 'Overrides and closures', '', '1 / -1')
	        ]}
	      ],
	      pickup: [
	        { title: 'Pickup', description: 'Let customers collect their order from the restaurant.', switchable: true, key: 'pickup', fields: [
	          I('slot_interval', 'Time slot interval (minutes)', '15', '', 'number'),
	          I('slot_capacity', 'Shared orders per slot', '8', '', 'number'),
	          I('hold_minutes', 'Checkout hold (minutes)', '10', '', 'number'),
	          I('preorder_days', 'Preorder window (days)', '7', '', 'number')
	        ]}
	      ],
	      delivery: [
	        { title: 'Delivery', description: 'WooCommerce shipping zones and rates decide delivery availability and price.', switchable: true, key: 'delivery', fields: [
	          I('delivery_lead', 'Delivery lead after kitchen-ready time (minutes)', '15', '', 'number'),
	          { kind: 'note', value: 'WooCommerce shipping zones and rates remain the source of truth for delivery areas, methods and fees.', span: '1 / -1' },
	          { kind: 'link', label: 'Open WooCommerce shipping', span: '1 / -1', onClick: () => WR.open('shipping') }
	        ]}
	      ],
	      'mini-cart': [
	        { title: 'Mini Cart', description: 'A floating cart that follows the customer across the menu.', switchable: true, key: 'mini_cart', fields: [
	          S('cart_style', 'Cart style', ['Style 1 - side drawer', 'Style 2 - floating bubble'], 'Style 1 - side drawer'),
	          S('cart_position', 'Position', ['Bottom right', 'Bottom left', 'Middle right'], 'Bottom right'),
	          { kind: 'toggle', key: 'cart_count', label: 'Show item count', help: 'Display a badge with the number of items' },
	          { kind: 'toggle', key: 'cart_search', label: 'Show quick search', help: 'Add a search field inside the cart drawer' }
	        ]}
	      ],
	      tipping: [
	        { title: 'Tipping', description: 'Let customers add a tip at checkout.', switchable: true, key: 'tipping', fields: [
	          I('tip_presets', 'Percentage presets', '10,15,20'),
	          { kind: 'toggle', key: 'custom_tip', label: 'Allow custom tip', help: 'Customers can type their own amount' },
	          { kind: 'toggle', key: 'tips_taxable', label: 'Tax tips', help: 'Disabled by default; WooCommerce tax rules remain authoritative' }
	        ]}
	      ],
	      'food-menu': [
	        { title: 'Menu Display', description: 'Choose the default WowRestro menu appearance. Shortcodes, Gutenberg and Elementor can override it per menu.', fields: [
	          S('menu_template', 'Default template', Array.from({ length: 36 }, (_, i) => ({ value: String(i + 1), label: (settings.menuTemplates || {})[i + 1] || 'Template ' + (i + 1) })), '1'),
	          S('menu_layout', 'Default display', [{ value: 'tabs', label: 'Category tabs' }, { value: 'list', label: 'List' }], 'tabs'),
	          I('menu_per_page', 'Products per page', '30', '', 'number'),
	          I('dietary_attribute', 'Dietary attribute', 'pa_dietary'),
	          I('allergen_attribute', 'Allergen attribute', 'pa_allergens')
	        ]}
	      ],
	      notifications: [
	        { title: 'Order Updates', description: 'Customer and staff operational notifications.', fields: [
	          { kind: 'toggle', key: 'email_updates', label: 'Customer status emails', help: 'Send WooCommerce mailer updates for operational status changes' },
	          I('late_grace', 'Late warning grace (minutes)', '5', '', 'number'),
	          { kind: 'note', value: 'Firebase new-order alerts refresh Live Orders immediately, while staff actions and the manual button refresh from WooCommerce directly.', span: '1 / -1' }
	        ]}
	      ],
	      advanced: [
	        { title: 'Advanced Setup', description: 'Shipping-rate overrides, per-service split hours, migration tools and diagnostics remain available in the canonical setup screen.', fields: [
	          { kind: 'note', value: 'Use the Open Advanced Setup button for low-level WooCommerce shipping and migration controls.', span: '1 / -1' }
	        ]}
	      ],
	      'reservation-rules': [
	        { title: 'Reservations', description: 'Configure reservation settings and fields for your booking flow.', fields: [
	          C('res_advance_minutes', 'Advance Reservation', '30', 'Minutes', '', 'How far ahead a guest must book.'),
	          S('res_future_days', 'Maximum Future Reservation', [
	            { value: '30', label: '30 days' }, { value: '60', label: '60 days' }, { value: '90', label: '90 days' },
	            { value: '180', label: '180 days' }, { value: '365', label: '365 days' }, { value: '730', label: 'Any Time' }
	          ], '730'),
	          S('res_default_status', 'Reservation Status', [{ value: 'pending', label: 'Pending' }, { value: 'confirmed', label: 'Confirmed' }], reservationSettings.default_status || 'pending'),
	          { kind: 'multiselect', key: 'res_blocking_statuses', label: 'Statuses That Block Time Slots', tooltip: 'Reservations with these statuses consume the available seat capacity.', def: reservationSettings.blocking_statuses || ['pending', 'confirmed'], options: [
	            { value: 'pending', label: 'Pending', icon: 'ph ph-clock' }, { value: 'confirmed', label: 'Confirmed', icon: 'ph ph-check-circle' },
	            { value: 'seated', label: 'Seated', icon: 'ph ph-chair' }, { value: 'completed', label: 'Completed', icon: 'ph ph-flag-checkered' },
	            { value: 'cancelled', label: 'Cancelled', icon: 'ph ph-x-circle' }, { value: 'rejected', label: 'Rejected', icon: 'ph ph-prohibit' }
	          ] },
	          C('res_min_guests', 'Minimum guests', '1', 'People', '', 'Smallest party size accepted.'),
	          C('res_max_guests', 'Maximum guests', '100', 'People', '', 'Largest party size accepted in one reservation.'),
	          C('res_booking_amount', 'Booking Amount', reservationSettings.booking_amount || '0', '', (settings.currency || {}).symbol || '', 'Amount charged through WooCommerce when a booking requires payment.'),
	          C('res_seat_capacity', 'Total seat capacity', '100', 'People', '', 'Maximum guests accepted for the same time slot.'),
	          C('res_interval', 'Reservation slot interval', '30', 'Minutes', '', 'Distance between reservation start times.'),
	          { kind: 'toggle', key: 'res_booking_per_guest', label: 'Multiply booking amount by the total number of guests', help: 'Charge the booking amount once for each guest', span: '1 / -1' },
	          { kind: 'featuretoggle', key: 'res_wc_payment', title: 'Deposit at Booking', badge: 'Included', icon: 'ph ph-wallet', description: 'Collect the configured booking amount through WooCommerce when a guest books.', actionLabel: 'Configure Payment', span: '1 / -1', onAction: () => this.go('/settings?tab=payment-settings', 'reservations') },
	          { kind: 'note', value: 'Reservation availability uses the global WowRestro schedule and the slot interval above.', span: '1 / -1' }
	        ]}
	      ],
	      customization: [
	        { title: 'Booking Info', description: 'Choose which fields guests see, then edit customer-facing labels and placeholders.', fields: [
	          { kind: 'reservationfields', span: '1 / -1' },
	          { kind: 'repeater', key: 'res_custom_fields', span: '1 / -1', def: reservationSettings.custom_fields || [], allowEmpty: true, addLabel: 'Add New Custom Field', columns: [
	            { key: 'label', label: 'Field label', placeholder: 'e.g. Occasion' },
	            { key: 'type', label: 'Field type', options: ['text', 'select', 'textarea', 'radio', 'checkbox'] },
	            { key: 'placeholder', label: 'Placeholder', placeholder: 'Optional helper text' },
	            { key: 'options', label: 'Choices', placeholder: 'Comma separated' },
	            { key: 'required', label: 'Required', options: ['no', 'yes'] }
	          ] }
	        ]}
	      ],
	      'reservation-message': [
	        { title: 'Reservation Message Settings', description: 'Set the messages shown to guests after a reservation request.', fields: [
	          { kind: 'messagecard', key: 'msg_pending', title: 'Pending Message', description: 'Shown when a reservation request is received but not yet confirmed.', messageKey: 'res_pending_message', placeholder: 'Type your message here...', span: '1 / -1' },
	          { kind: 'messagecard', key: 'msg_confirmed', title: 'Confirmed Message', description: 'Shown when the reservation is confirmed.', messageKey: 'res_confirmed_message', placeholder: 'Type your message here...', span: '1 / -1' },
	          { kind: 'buttonlabels', title: 'Reservation Button Text', description: 'Set the labels used for the booking, confirmation and cancellation actions.', span: '1 / -1', fields: [
	            { key: 'res_button_label', label: 'Form Button', def: reservationSettings.button_label || 'Book a table' },
	            { key: 'res_confirmation_label', label: 'Confirmation Button', def: reservationSettings.confirmation_label || 'Confirm Booking' },
	            { key: 'res_cancellation_label', label: 'Cancellation Button', def: reservationSettings.cancellation_label || 'Request Cancellation' }
	          ] }
	        ]}
	      ],
	      'payment-settings': [
	        { title: 'Payment Settings', description: 'Choose how guests can pay reservation charges. WooCommerce remains authoritative for online gateways, taxes and payment status.', fields: [
	          { kind: 'paymenttoggle', key: 'res_local_payment', title: 'Enable Local Payment', description: 'Allow guests to pay at the restaurant instead of online.', span: '1 / -1' },
	          { kind: 'paymenttoggle', key: 'res_wc_payment', title: 'Enable WooCommerce Payments', description: 'Create a WooCommerce order and use the payment gateways already enabled in WooCommerce.', span: '1 / -1' }
	        ]}
	      ],
	      'version-control': [
	        { title: 'Version Control', description: 'Roll back to a previous WowRestro build if an update causes trouble.', fields: [
	          { kind: 'note', value: 'You are running WowRestro ' + (settings.version || '') + '. Version rollback is not available in this beta.', span: '1 / -1' },
	          S('rollback_version', 'Roll back to', ['3.2.0', '3.1.4', '3.1.0', '3.0.7'], '3.2.0')
	        ]}
	      ]
	    };
	    return defs[tab] || defs.general;
	  }

	  buildFields(list, prefix) {
	    return list.map((f, i) => {
	      const key = f.key || (prefix + '_' + i);
	      const fieldId = 'wr-' + String(prefix + '-' + i + '-' + key).replace(/[^a-z0-9_-]+/gi, '-').toLowerCase();
	      const base = {
	        id: fieldId, errorId: fieldId + '-error', helpId: fieldId + '-help', describedBy: f.help ? fieldId + '-help' : '',
	        label: f.label || '', hasLabel: !!f.label && !f.hideLabel, hasHiddenLabel: !!f.label && !!f.hideLabel, span: f.span || 'auto',
	        isInput: false, isSelect: false, isTextarea: false, isToggle: false,
	        isSchedule: false, isFieldList: false, isNote: false, isLink: false, isQrPreview: false,
	        isChips: false, isMultiSelect: false, isCompound: false, isFeatureToggle: false, isSwatch: false, isIconPick: false, isMedia: false, isMediaDropzone: false, isRepeater: false,
	        isReservationFields: false, isMessageCard: false, isButtonLabels: false, isPaymentToggle: false,
	        chips: [], swatches: [], icons: [],
	        multiOptions: [], menuCaret: 'ph ph-caret-down', hasPrefix: false, hasSuffix: false, prefix: '', suffix: '', hasTooltip: !!f.tooltip, tooltip: f.tooltip || '',
	        featureIcon: '', featureTitle: '', featureBadge: '', featureDescription: '', actionLabel: '', hasAction: false,
	        repeaterRows: [], addLabel: '', reservationRows: [], buttonFields: [],
	        cardTitle: '', cardDescription: '', messageValue: '', messagePlaceholder: '',
	        mediaUrl: '', mediaEmpty: true, mediaButton: 'Choose image', mediaTextColor: '#62697a', mediaIconBg: '#E9EDFF',
	        hasHelp: !!f.help && f.kind !== 'toggle', help: f.help || '', value: '', placeholder: f.placeholder || '',
	        hasError: false, error: '', border: '#a5a9be', ariaInvalid: 'false', ariaRequired: f.required ? 'true' : 'false', isRequired: !!f.required,
	        inputType: 'text', options: [], items: [], days: [],
	        trackBg: '#e6e6f0', knobLeft: '2px', toggleLabel: '', ariaChecked: 'false', onInput: () => {}, onToggle: () => {}, onAdd: () => {}, onClick: () => {}
	      };
	      if (f.kind === 'input') {
	        const error = this.state.fieldErrors[key] || '';
	        return Object.assign(base, {
	          isInput: true, inputType: f.type || 'text', value: this.val(key, f.def),
	          hasError: !!error, error, border: error ? '#dc2626' : '#a5a9be', ariaInvalid: error ? 'true' : 'false',
	          describedBy: [f.help ? fieldId + '-help' : '', error ? fieldId + '-error' : ''].filter(Boolean).join(' '),
	          onInput: (e) => this.setVal(key, e.target.value)
	        });
	      }
	      if (f.kind === 'compound') {
	        return Object.assign(base, {
	          isCompound: true, isInput: false, inputType: f.type || 'text', value: this.val(key, f.def),
	          hasPrefix: !!f.prefix, hasSuffix: !!f.suffix, prefix: f.prefix || '', suffix: f.suffix || '',
	          onInput: (e) => this.setVal(key, e.target.value)
	        });
	      }
	      if (f.kind === 'select') {
	        return Object.assign(base, {
	          isSelect: true, isInput: false, value: this.val(key, f.def),
	          options: f.options.map(o => typeof o === 'object' ? o : ({ value: o, label: o })),
	          onInput: (e) => this.setVal(key, e.target.value)
	        });
	      }
	      if (f.kind === 'textarea') {
	        return Object.assign(base, {
	          isTextarea: true, isInput: false, value: this.val(key, f.def),
	          onInput: (e) => this.setVal(key, e.target.value)
	        });
	      }
	      if (f.kind === 'repeater') {
	        const rows = this.val(key, f.def || [{}]);
	        const columns = f.columns || [];
	        return Object.assign(base, {
	          isRepeater: true, isInput: false, addLabel: f.addLabel || 'Add row',
	          repeaterRows: rows.map((row, rowIndex) => ({
	            canRemove: !!f.allowEmpty || rows.length > 1,
	            cells: columns.map(column => ({
	              label: column.label, value: Array.isArray(row[column.key]) ? row[column.key].join(', ') : (row[column.key] || ''), placeholder: column.placeholder || '', inputType: column.type || 'text',
	              isSelect: Array.isArray(column.options), isInput: !Array.isArray(column.options), options: (column.options || []).map(option => ({ value: option, label: option })),
	              onInput: event => {
	                const value = event.target.value;
	                this._formDirty = true;
	                this.setState(state => {
	                  const current = state.values[key] === undefined ? (f.def || [{}]) : state.values[key];
	                  const next = current.map(item => Object.assign({}, item));
	                  next[rowIndex][column.key] = value;
	                  return {
	                    values: Object.assign({}, state.values, { [key]: next }),
	                    fieldErrors: Object.assign({}, state.fieldErrors, { [key]: '' })
	                  };
	                });
	              }
	            })),
	            onRemove: () => {
	              this._formDirty = true;
	              this.setState(state => {
	                const current = state.values[key] === undefined ? (f.def || [{}]) : state.values[key];
	                return { values: Object.assign({}, state.values, { [key]: current.filter((row, index) => index !== rowIndex) }) };
	              });
	            }
	          })),
	          onAdd: () => {
	            this._formDirty = true;
	            this.setState(state => {
	              const current = state.values[key] === undefined ? (f.def || [{}]) : state.values[key];
	              const row = columns.reduce((item, column) => Object.assign(item, { [column.key]: Array.isArray(column.options) ? column.options[0] : '' }), {});
	              return { values: Object.assign({}, state.values, { [key]: current.concat([row]) }) };
	            });
	          }
	        });
	      }
	      if (f.kind === 'reservationfields') {
	        const meta = {
	          location: ['Location', 'Select location', 'ph ph-map-pin'], date: ['Date', 'Date input', 'ph ph-calendar-blank'],
	          time: ['Time', 'Time input', 'ph ph-clock'], name: ['Guest name', 'Text input', 'ph ph-user'],
	          email: ['Email', 'Email input', 'ph ph-envelope'], phone: ['Phone', 'Text input', 'ph ph-phone'],
	          guests: ['Guests', 'Number input', 'ph ph-users'], notes: ['Additional information', 'Textarea', 'ph ph-note-pencil']
	        };
	        const stored = reservationSettings.fields || {};
	        const reservationRows = Object.keys(meta).map(fieldKey => {
	          const current = stored[fieldKey] || {};
	          const locked = fieldKey === 'date' || fieldKey === 'time';
	          const visibleKey = 'res_field_' + fieldKey + '_visible';
	          const requiredKey = 'res_field_' + fieldKey + '_required';
	          const visible = locked || !!this.val(visibleKey, current.visible !== 'no');
	          const required = locked || !!this.val(requiredKey, current.required === 'yes');
	          const editing = this.state.editingReservationField === fieldKey;
	          return {
	            key: fieldKey, label: this.val('res_field_' + fieldKey + '_label', current.label || meta[fieldKey][0]), type: meta[fieldKey][1], icon: meta[fieldKey][2],
	            locked, lockIcon: locked ? 'ph ph-lock-key' : 'ph ph-dots-six-vertical', editing,
	            trackBg: visible ? '#2B4BFF' : '#d9dce5', knobLeft: visible ? '22px' : '2px', ariaChecked: visible ? 'true' : 'false',
	            requiredTrackBg: required ? '#2B4BFF' : '#d9dce5', requiredKnobLeft: required ? '22px' : '2px', requiredAriaChecked: required ? 'true' : 'false',
	            labelValue: this.val('res_field_' + fieldKey + '_label', current.label || meta[fieldKey][0]), placeholderValue: this.val('res_field_' + fieldKey + '_placeholder', current.placeholder || ''),
	            onToggle: () => { if (!locked) this.setVal(visibleKey, !visible); },
	            onRequired: () => { if (!locked) this.setVal(requiredKey, !required); },
	            onEdit: () => this.setState({ editingReservationField: editing ? '' : fieldKey }),
	            onLabel: event => this.setVal('res_field_' + fieldKey + '_label', event.target.value),
	            onPlaceholder: event => this.setVal('res_field_' + fieldKey + '_placeholder', event.target.value)
	          };
	        });
	        return Object.assign(base, { isReservationFields: true, isInput: false, hasLabel: false, reservationRows });
	      }
	      if (f.kind === 'messagecard') {
	        const on = this.isOn(f.key);
	        return Object.assign(base, {
	          isMessageCard: true, isInput: false, hasLabel: false, cardTitle: f.title, cardDescription: f.description,
	          messageValue: this.val(f.messageKey, ''), messagePlaceholder: f.placeholder || '',
	          trackBg: on ? '#2B4BFF' : '#d9dce5', knobLeft: on ? '22px' : '2px', ariaChecked: on ? 'true' : 'false',
	          toggleLabel: 'Enable ' + f.title, onToggle: () => this.toggle(f.key), onInput: event => this.setVal(f.messageKey, event.target.value)
	        });
	      }
	      if (f.kind === 'buttonlabels') {
	        return Object.assign(base, {
	          isButtonLabels: true, isInput: false, hasLabel: false, cardTitle: f.title, cardDescription: f.description,
	          buttonFields: (f.fields || []).map(field => ({ label: field.label, value: this.val(field.key, field.def), onInput: event => this.setVal(field.key, event.target.value) }))
	        });
	      }
	      if (f.kind === 'paymenttoggle') {
	        const on = this.isOn(f.key);
	        return Object.assign(base, {
	          isPaymentToggle: true, isInput: false, hasLabel: false, cardTitle: f.title, cardDescription: f.description,
	          trackBg: on ? '#2B4BFF' : '#d9dce5', knobLeft: on ? '22px' : '2px', ariaChecked: on ? 'true' : 'false',
	          toggleLabel: f.title, onToggle: () => this.toggle(f.key)
	        });
	      }
	      if (f.kind === 'toggle') {
	        const formToggle = f.def !== undefined;
	        const on = formToggle ? !!this.val(key, f.def) : this.isOn(f.key);
	        return Object.assign(base, {
	          isToggle: true, isInput: false, help: f.help || '', toggleLabel: f.label || 'Toggle setting', ariaChecked: on ? 'true' : 'false',
	          trackBg: on ? '#2B4BFF' : '#e6e6f0', knobLeft: on ? '22px' : '2px',
	          onToggle: () => formToggle ? this.setVal(key, !on) : this.toggle(f.key)
	        });
	      }
	      if (f.kind === 'schedule') {
	        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((d, index) => {
	          const on = !!this.state.schedule[d];
	          const row = storeSettings.service_hours && storeSettings.service_hours.pickup && storeSettings.service_hours.pickup[index + 1];
	          return {
	            label: d, hours: on ? ((row && row.periods) || '11:00-22:00') : 'Closed',
	            timeColor: on ? '#414454' : '#a5a9be',
	            trackBg: on ? '#2B4BFF' : '#e6e6f0', knobLeft: on ? '20px' : '2px', ariaChecked: on ? 'true' : 'false',
	            onToggle: () => this.setState(s => ({ schedule: Object.assign({}, s.schedule, { [d]: !s.schedule[d] }) }))
	          };
	        });
	        return Object.assign(base, { isSchedule: true, isInput: false, days });
	      }
	      if (f.kind === 'fieldlist') {
	        const items = [
	          { label: 'Date', type: 'Date', required: true },
	          { label: 'Time', type: 'Time', required: true },
	          { label: 'Number of guests', type: 'Number', required: true },
	          { label: 'Full name', type: 'Text', required: true },
	          { label: 'Email address', type: 'Email', required: true },
	          { label: 'Phone number', type: 'Text', required: false },
	          { label: 'Special request', type: 'Textarea', required: false }
	        ];
	        return Object.assign(base, { isFieldList: true, isInput: false, items, onAdd: () => this.toast('Custom field editor opened') });
	      }
	      if (f.kind === 'chips') {
	        const picked = this.val(key, f.def || []);
	        const chips = (f.options || []).map(o => {
	          const value = o.value === undefined ? o.label : o.value;
	          const on = picked.indexOf(value) > -1;
	          return {
	            label: o.label, icon: o.icon || 'ph ph-tag',
	            bg: on ? (o.color || '#2B4BFF') : '#fff',
	            color: on ? '#fff' : (o.color || '#525266'),
	            border: on ? (o.color || '#2B4BFF') : '#e5e5e5',
	            onToggle: () => this.setVal(key, on ? picked.filter(x => x !== value) : picked.concat([value]))
	          };
	        });
	        return Object.assign(base, { isChips: true, isInput: false, chips });
	      }
	      if (f.kind === 'multiselect') {
	        const picked = this.val(key, f.def || []);
	        const multiOptions = (f.options || []).map(o => {
	          const value = o.value === undefined ? o.label : o.value;
	          const on = picked.indexOf(value) > -1;
	          return {
	            label: o.label, icon: o.icon || 'ph ph-tag', selected: on, visible: on || this.state.statusOptionsOpen,
	            bg: on ? '#f1f2f6' : '#fff', color: on ? '#252525' : '#6b7280', border: on ? '#d9dce5' : '#e5e7eb',
	            onToggle: () => this.setVal(key, on ? picked.filter(x => x !== value) : picked.concat([value]))
	          };
	        });
	        return Object.assign(base, { isMultiSelect: true, isInput: false, multiOptions, menuCaret: this.state.statusOptionsOpen ? 'ph ph-caret-up' : 'ph ph-caret-down', onClick: () => this.setState(s => ({ statusOptionsOpen: !s.statusOptionsOpen })) });
	      }
	      if (f.kind === 'featuretoggle') {
	        const formToggle = f.def !== undefined;
	        const on = formToggle ? !!this.val(key, f.def) : this.isOn(f.key);
	        return Object.assign(base, {
	          isFeatureToggle: true, isInput: false, hasLabel: false,
	          featureIcon: f.icon || 'ph ph-sparkle', featureTitle: f.title || '', featureBadge: f.badge || '', featureDescription: f.description || '',
	          actionLabel: f.actionLabel || '', hasAction: !!f.actionLabel, onClick: f.onAction || (() => {}),
	          toggleLabel: f.title || 'Toggle feature', ariaChecked: on ? 'true' : 'false', trackBg: on ? '#2B4BFF' : '#d9dce5', knobLeft: on ? '22px' : '2px',
	          onToggle: () => formToggle ? this.setVal(key, !on) : this.toggle(f.key)
	        });
	      }
	      if (f.kind === 'swatch') {
	        const cur = this.val(key, f.def);
	        const swatches = (f.options || []).map(c => ({
	          value: c, ring: cur === c ? '3px #fff, 0 0 0 5px ' + c : '0 transparent',
	          checkOpacity: cur === c ? '1' : '0',
	          onPick: () => this.setVal(key, c)
	        }));
	        return Object.assign(base, { isSwatch: true, isInput: false, swatches, value: cur });
	      }
	      if (f.kind === 'iconpick') {
	        const cur = this.val(key, f.def);
	        const icons = (f.options || []).map(ic => ({
	          icon: ic,
	          bg: cur === ic ? '#E9EDFF' : '#fff',
	          border: cur === ic ? '#2B4BFF' : '#e5e5e5',
	          color: cur === ic ? '#2B4BFF' : '#525266',
	          onPick: () => this.setVal(key, ic)
	        }));
	        return Object.assign(base, { isIconPick: true, isInput: false, icons, value: cur });
	      }
	      if (f.kind === 'media' || f.kind === 'media-dropzone') {
	        const cur = this.val(key, f.def || { id: 0, url: '' });
	        return Object.assign(base, {
	          isMedia: f.kind === 'media', isMediaDropzone: f.kind === 'media-dropzone', isInput: false, mediaUrl: cur.url || '', mediaEmpty: !cur.url,
	          mediaFit: f.fit || 'cover', mediaTextColor: cur.url ? '#fff' : '#62697a', mediaIconBg: cur.url ? 'rgba(255,255,255,.92)' : '#E9EDFF',
	          mediaButton: cur.url ? 'Replace image' : 'Choose image',
	          onPick: () => WR.pickImage(image => this.setVal(key, image), f.label),
	          onRemove: () => this.setVal(key, { id: 0, url: '' })
	        });
	      }
	      if (f.kind === 'note') {
	        return Object.assign(base, { isNote: true, isInput: false, value: f.value });
	      }
	      if (f.kind === 'link') {
	        return Object.assign(base, { isLink: true, isInput: false, hasLabel: false, onClick: f.onClick });
	      }
	      if (f.kind === 'qrpreview') {
	        const url = this.val('q_url', (settings.links || {}).menu || '');
	        const name = String(this.val('q_name', '') || 'Your table');
	        const background = this.val('q_background', { id: 0, url: appAssets.qrBackground || '' });
	        const image = WR.qrDataUrl(url, 256);
	        return Object.assign(base, {
	          isQrPreview: true, isInput: false, mediaUrl: image, mediaEmpty: !image,
	          qrName: name, qrBrand: this.val('rest_name', restaurantProfile.name || WR.boot.siteName || 'Restaurant'), qrHost: WR.qrHost(url),
	          qrUrl: WR.qrDisplayUrl(url), qrBackground: background.url || appAssets.qrBackground || '', qrMark: (this.val('rest_logo', {}) || {}).url || restaurantProfile.logo_url || appAssets.brandLogo || appAssets.mark || '',
	          help: image ? 'Preview updates as you edit the page URL.' : 'Enter a valid page URL to generate a preview.', hasHelp: true
	        });
	      }
	      return base;
	    });
	  }

	  /* ---------- render ---------- */
	  renderVals() {
	    const st = this.state;
	    const route = st.route;
	    const tab = this.tabOf(route);
	    const pro = this.pro();
	    const groups = this.groups();
	    const activeGroup = groups.find(g => g.id === st.group) || groups[0];
	    const hasSecondary = !!(activeGroup.children && activeGroup.children.length);

	    const railItems = groups.map(g => {
	      const active = g.url ? route.indexOf(g.url) === 0 && (g.url !== '/' || route === '/') : st.group === g.id;
	      return {
	        title: g.title, icon: g.icon,
	        bg: active ? '#ffffff' : 'transparent',
	        color: active ? '#2B4BFF' : 'rgba(37,37,37,.75)',
	        onClick: () => g.url ? this.go(g.url, g.id) : this.go(g.children[0].url, g.id)
	      };
	    });

	    let prevSection = null;
	    const secondaryItems = (activeGroup.children || []).map((it, i) => {
	      const newSection = it.section && it.section !== prevSection;
	      prevSection = it.section;
	      const inBranch = route === it.url || (it.url !== '/' && route.indexOf(it.url + '/') === 0);
	      const kids = it.children || [];
	      const kidActive = kids.filter(k => route === k.url || route.indexOf(k.url + '/') === 0).length > 0;
	      const branchOpen = inBranch || kidActive || (it.branch && route.indexOf(it.branch) === 0);
	      const active = inBranch && !kidActive;
	      return {
	        title: it.title, icon: it.icon, section: it.section,
	        firstSection: newSection && i === 0, laterSection: newSection && i > 0,
	        active, showDot: active && kids.length === 0, isPro: !!it.pro && !pro,
	        hasChildren: kids.length > 0,
	        expanded: kids.length > 0 && branchOpen,
	        caret: branchOpen ? 'ph ph-caret-down' : 'ph ph-caret-right',
	        children: kids.map(k => {
	          const on = k.exact ? route === k.url : (route === k.url || route.indexOf(k.url + '/') === 0);
	          return {
	            title: k.title, count: String(k.count === undefined ? '' : k.count),
	            hasCount: k.count !== undefined,
	            icon: on ? k.icon.replace('ph ph-', 'ph-fill ph-') : k.icon,
	            bg: on ? '#E9EDFF' : 'transparent',
	            color: on ? '#2B4BFF' : '#525266',
	            weight: on ? '600' : '400',
	            onClick: () => this.go(k.url, activeGroup.id)
	          };
	        }),
	        bg: branchOpen ? '#f8f8f8' : 'transparent',
	        color: branchOpen ? '#2B4BFF' : '#252525',
	        onClick: () => it.external ? WR.open(it.external) : this.go(it.url, activeGroup.id)
	      };
	    });

	    /* dashboard */
	    // Figures come from WooCommerce via the server bootstrap; the design's
	    // sample numbers are gone. A metric the plugin cannot compute is omitted
	    // rather than shown with an invented value.
	    const metrics = (WR.boot.metrics || []).map(m => Object.assign({ hasIcon: true }, m));

	    const plain = (text, color, weight) => ({ isPlain: true, isBadge: false, isStacked: false, isActions: false, text, color: color || 'inherit', weight: weight || '400' });
	    const badge = (text, dot) => ({ isBadge: true, isPlain: false, isStacked: false, isActions: false, text, dot });
	    const stacked = (text, sub) => ({ isStacked: true, isPlain: false, isBadge: false, isActions: false, text, sub });
	    // A row WooCommerce owns gets no delete button: destructive edits belong
	    // in the native editor, not in a screen that only holds a local copy.
	    const actions = (onEdit, onDelete) => ({ isActions: true, isPlain: false, isBadge: false, isStacked: false, onEdit, onDelete: onDelete || (() => {}), hasDelete: !!onDelete });
	    const blank = { isPlain: false, isBadge: false, isStacked: false, isActions: false, isAvatar: false, isChip: false, isChips: false, isPrice: false, isQrThumb: false, isQrActions: false };
	    const qrThumb = (record) => Object.assign({}, blank, { isQrThumb: true, mediaUrl: WR.qrDataUrl(record.url, 128), text: record.name, sub: ({ 128: 'Compact card', 256: 'Standard card', 512: 'Large card' })[record.size] || 'Standard card' });
	    const qrActions = (onPreview, onOpen, onCopy, onDownload, onDelete) => Object.assign({}, blank, { isQrActions: true, onPreview, onOpen, onCopy, onDownload, onDelete });
	    const avatar = (text, sub, icon, color, image) => Object.assign({}, blank, { isAvatar: true, text, sub, icon, dot: color, image: image || '', hasImage: !!image, noImage: !image });
	    const chip = (text, color, icon) => Object.assign({}, blank, { isChip: true, text, dot: color, bg: color + '1f', icon: icon || '', hasIcon: !!icon });
	    const chips = (list) => Object.assign({}, blank, { isChips: true, chipsEmpty: list.length === 0, chips: list.map(c => ({ label: c.label, color: c.color, bg: c.color + '1f', icon: c.icon })) });
	    const price = (text, sub) => Object.assign({}, blank, { isPrice: true, text, sub: sub || '', hasSale: !!sub });

	    const widgets = [
	      { title: 'Food Orders', tooltip: 'Displays your most recent food orders placed through your restaurant.',
	        onViewAll: () => this.go('/live-orders', 'food-ordering'),
	        columns: [{ title: 'Order Id' }, { title: 'Customer' }, { title: 'Items' }, { title: 'Total' }, { title: 'Status' }],
	        rows: this.orderData().map(o => ({ cells: [
	          plain('#' + o.id, '#2B4BFF', '500'), plain(o.customer), plain(String(o.items)),
	          plain(this.money(o.total), 'rgba(0,0,0,.95)', '500'), badge(o.status, this.orderDot(o.status))
	        ] })) },
	      { title: 'Top-selling menu items', tooltip: 'WooCommerce lifetime units sold for restaurant menu products.',
	        onViewAll: () => this.go('/food-menu', 'food-ordering'),
	        columns: [{ title: 'Food Item' }, { title: 'Category' }, { title: 'Units sold' }, { title: 'Current price' }],
	        rows: st.items.slice().sort((a, b) => b.sold - a.sold).slice(0, 6).map(it => ({ cells: [
	          plain(it.name, 'inherit', '500'), plain(it.category), plain(String(it.sold)),
	          plain(this.money(it.sale || it.price), '#101828', '500')
	        ] })) }
	    ];
	    widgets.forEach(widget => {
	      widget.empty = widget.rows.length === 0;
	      widget.emptyDescription = widget.title === 'Food Orders'
	        ? 'Orders will appear here after a customer checks out.'
	        : 'Published menu items will appear here after they record sales.';
	    });

	    /* reservations */
	    const q = st.search.trim().toLowerCase();
	    const totalReservations = this.reservationData().reduce((total, group) => total + group.rows.filter(r => st.deletedReservations.indexOf(r.id) === -1).length, 0);
	    const groupsData = this.reservationData()
	      .map(g => ({ date: g.date, rows: g.rows.filter(r =>
	        st.deletedReservations.indexOf(r.id) === -1 &&
	        (!q || r.name.toLowerCase().indexOf(q) > -1 || r.email.toLowerCase().indexOf(q) > -1) &&
	        (!st.statusFilter || (st.reservationStatus[r.id] || r.status) === st.statusFilter)) }))
	      .filter(g => g.rows.length);

	    const cycle = { Pending: 'Confirmed', Confirmed: 'Cancelled', Cancelled: 'Pending' };
	    const reservationGroups = groupsData.map(g => ({
	      date: g.date,
	      countLabel: g.rows.length + (g.rows.length > 1 ? ' reservations' : ' reservation'),
	      reservations: g.rows.map(r => {
	        const sel = st.selected.indexOf(r.id) > -1;
	        const status = st.reservationStatus[r.id] || r.status;
	        return {
	          time: r.time, invoice: r.invoice, name: r.name, email: r.email,
	          food: r.food, foodColor: r.food === 'Yes' ? '#00a63e' : '#e7000b',
	          guests: String(r.guests), payment: r.payment, status,
	          statusDot: this.statusDot(status),
	          rowBg: sel ? '#F4F6FF' : 'transparent',
	          checkBg: sel ? '#2B4BFF' : '#fff',
	          check: sel ? '✓' : '',
	          selectedAria: sel ? 'true' : 'false',
	          onOpen: () => this.go('/reservations/update/' + r.id, 'reservations'),
	          onToggle: (e) => { e.stopPropagation(); this.setState(s => ({ selected: s.selected.indexOf(r.id) > -1 ? s.selected.filter(x => x !== r.id) : s.selected.concat([r.id]) })); },
	          cycleStatus: (e) => {
	            e.stopPropagation();
	            const next = cycle[status];
	            WR.post('/workspace/reservation', { id: r.id, status: next }).then(result => {
	              this.setState({ reservations: result.reservations || [], reservationStatus: {} });
	              this.toast('Reservation ' + r.invoice + ' marked ' + next);
	            }).catch(error => this.toast(error && error.message ? error.message : 'Could not update reservation'));
	          },
	          onDelete: (e) => {
	            e.stopPropagation();
	            this.askDelete('Delete ' + r.invoice + '?', 'This permanently removes the reservation from WowRestro.', () =>
	              WR.delete('/workspace/reservation/' + r.id).then(result => {
	                this.setState({ reservations: result.reservations || [], deletedReservations: [] });
	                this.toast('Reservation deleted');
	              }).catch(error => this.toast(error && error.message ? error.message : 'Could not delete reservation'))
	            );
	          }
	        };
	      })
	    }));
	    const totalShown = reservationGroups.reduce((a, g) => a + g.reservations.length, 0);

	    const statusFilters = ['Pending', 'Confirmed', 'Cancelled'].map(label => {
	      const on = st.statusFilter === label;
	      return {
	        label, dot: this.statusDot(label),
	        bg: on ? '#E9EDFF' : '#fff', border: on ? '#2B4BFF' : '#a5a9be', color: on ? '#2B4BFF' : '#414454',
	        onClick: () => this.setState(s => ({ statusFilter: s.statusFilter === label ? '' : label }))
	      };
	    });

	    /* settings */
	    const settingsDefs = this.settingsFor(tab);
	    const settingsSections = settingsDefs.map((sec, i) => {
	      const on = sec.switchable ? this.isOn(sec.key) : true;
	      return {
	        title: sec.title, description: sec.description, hasDescription: !!sec.description,
	        switchable: !!sec.switchable, open: on,
	        toggleLabel: 'Enable ' + sec.title, ariaChecked: on ? 'true' : 'false',
	        dividerColor: on ? '#e5e5e5' : 'transparent',
	        trackBg: on ? '#2B4BFF' : '#e6e6f0', knobLeft: on ? '22px' : '2px',
	        onToggle: () => this.toggle(sec.key),
	        fields: this.buildFields(sec.fields, tab + i)
	      };
	    });
	    const reservationSettingsPage = ['reservation-rules', 'customization', 'reservation-message', 'payment-settings'].indexOf(tab) > -1;
	    const settingsIntro = tab === 'customization' ? {
	      title: 'Form Customization',
	      description: "Customize the reservation form fields to better suit your restaurant's needs."
	    } : {
	      title: 'Reservation Settings',
	      description: 'Manage the core settings that control availability, time slots, default status, booking charges, guest limits and table capacity.'
	    };

	    /* list pages */
	    const ls = st.listSearch.trim().toLowerCase();
	    let listCfg = null;
	    if (route === '/food-menu/items') {
	      const rows = st.items.filter(it => !ls || it.name.toLowerCase().indexOf(ls) > -1 || it.sku.toLowerCase().indexOf(ls) > -1 || it.category.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search items by name, SKU or category...', addLabel: 'Add Menu Item', hasAdd: true,
	        onAdd: () => this.go('/food-menu/items/create', st.group),
	        columns: ['Food item', 'Category', 'Price', 'Labels', 'Stock', 'Status', 'Actions'],
	        rows: rows.map(it => ({ cells: [
	          avatar(it.name, it.sku + ' · ' + it.sold + ' sold', it.icon, it.color, it.imageUrl),
	          plain(it.category),
	          it.sale ? price(this.money(it.sale), this.money(it.price)) : price(this.money(it.price)),
	          chips(it.labels.map(n => { const l = this.labelBy(n); return { label: l.name, color: l.color, icon: l.icon }; })),
	          badge(it.stock, this.stockDot(it.stock)),
	          badge(it.status, it.status === 'Published' ? '#00c950' : '#f0b100'),
	          actions(() => this.go('/food-menu/items/update/' + it.id, st.group), null)
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' item' : ' items'),
	        emptyTitle: 'No WooCommerce products found', emptyDescription: 'Create a simple or variable product here or in WooCommerce and it will appear in this list automatically.'
	      };
	    } else if (route === '/food-menu/categories') {
	      const rows = st.categories.filter(c => !ls || c.name.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search categories...', addLabel: 'Add Category', hasAdd: true,
	        onAdd: () => this.go('/food-menu/categories/create', st.group),
	        columns: ['Category', 'Slug', 'Parent', 'Products', 'Actions'],
	        rows: rows.map(c => ({ cells: [
	          plain(c.name, '#101828', '500'), plain(c.slug, '#6b7280'), plain(c.parent, '#6b7280'),
	          plain(String(c.products || 0)),
	          actions(() => this.go('/food-menu/categories/update/' + c.id, st.group), null)
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' category' : ' categories'),
	        emptyTitle: 'No categories found', emptyDescription: 'Group your menu into Starters, Pizza, Drinks and more.'
	      };
	    } else if (route === '/food-menu/addons') {
	      const rows = st.modifiers.filter(group => {
	        const optionNames = (group.optionRows || []).map(option => option.label).join(' ');
	        return !ls || group.name.toLowerCase().indexOf(ls) > -1 || optionNames.toLowerCase().indexOf(ls) > -1;
	      });
	      listCfg = {
	        placeholder: 'Search add-ons or options...', addLabel: 'Add Add-on', hasAdd: true,
	        onAdd: () => this.go('/food-menu/addons/create', st.group),
	        columns: ['Add-on group', 'Selection', 'Options', 'Status', 'Actions'],
	        rows: rows.map(group => ({ cells: [
	          stacked(group.name, (group.optionRows || []).map(option => option.label).join(', ') || 'No options yet'),
	          chip(group.type, group.typeKey === 'single' ? '#2B4BFF' : '#7B3BFF'),
	          plain(String(group.options)),
	          badge(group.status, group.status === 'Published' ? '#00c950' : '#f0b100'),
	          actions(() => this.go('/food-menu/addons/update/' + group.id, st.group), null)
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' add-on group' : ' add-on groups'),
	        emptyTitle: 'No add-on groups found', emptyDescription: 'Create a reusable group, choose Single or Multiple selection, then add priced options.'
	      };
	    } else if (route === '/food-menu/brands') {
	      const rows = st.brands.filter(b => !ls || b.name.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search brands...', addLabel: 'Add Brand', hasAdd: true,
	        onAdd: () => this.go('/food-menu/brands/create', st.group),
	        columns: ['Brand', 'Slug', 'Description', 'Items', 'Actions'],
	        rows: rows.map(b => ({ cells: [
	          plain(b.name, '#101828', '500'), plain(b.slug, '#6b7280'), plain(b.description || '-', '#6b7280'), plain(String(b.products || 0)),
	          actions(() => this.go('/food-menu/brands/update/' + b.id, st.group), null)
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' brand' : ' brands'),
	        emptyTitle: 'No brands found', emptyDescription: 'Brands appear in menu filters and on product cards.'
	      };
	    } else if (route === '/food-menu/labels') {
	      const rows = st.labels.filter(l => !ls || l.name.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search labels...', addLabel: 'Add Label', hasAdd: true,
	        onAdd: () => this.go('/food-menu/labels/create', st.group),
	        columns: ['Label', 'Colour', 'Products', 'Actions'],
	        rows: rows.map(l => ({ cells: [
	          chip(l.name, l.color, l.icon), plain(l.color.toUpperCase(), '#6b7280'), plain(String(l.products)),
	          actions(() => this.go('/food-menu/labels/update/' + l.id, st.group), null)
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' label' : ' labels'),
	        emptyTitle: 'No labels yet', emptyDescription: 'Create badges like Vegan or Spicy and attach them to any item.'
	      };
	    } else if (route === '/food-menu/nutrition') {
	      const rows = st.items.filter(it => !ls || it.name.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search items...', addLabel: '', hasAdd: false, onAdd: () => {},
	        columns: ['Food item', 'Category', 'Dietary', 'Allergens', 'Info', 'Actions'],
	        rows: rows.map(it => {
	          const n = this.nutritionOf(it);
	          const done = !!(n.calories || n.allergens.length);
	          return { cells: [
	            avatar(it.name, it.sku, it.icon, it.color, it.imageUrl), plain(it.category),
	            chips((it.labels || []).map(a => ({ label: a, color: '#00a63e', icon: 'ph ph-leaf' }))),
	            chips(n.allergens.map(a => ({ label: a, color: '#e7000b', icon: 'ph ph-warning' }))),
	            badge(done || (it.labels || []).length ? 'Configured' : 'Missing', done || (it.labels || []).length ? '#00c950' : '#99a1af'),
	            actions(() => this.go('/food-menu/nutrition/update/' + it.id, st.group), null)
	          ] };
	        }),
	        count: rows.length + ' products',
	        emptyTitle: 'No products found', emptyDescription: 'Add a menu item, then manage its dietary, allergen and nutrition details here.'
	      };
	    } else if (route === '/staff-roles') {
	      const rows = st.staffRoles.filter(role => !ls || role.name.toLowerCase().indexOf(ls) > -1 || role.description.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search roles...', addLabel: 'Add Staff Member', hasAdd: settings.canManageStaff === true,
	        onAdd: () => WR.open('userNew'),
	        columns: ['Role', 'Access', 'Members', 'Actions'],
	        rows: rows.map(role => ({ cells: [
	          avatar(role.name, role.description, role.icon, role.color),
	          chip(role.access, role.color, 'ph ph-shield-check'),
	          plain(String(role.members), '#101828', '600'),
	          settings.canManageStaff === true ? actions(() => WR.open('users'), null) : plain('WordPress administrator required', '#6b7280')
	        ] })),
	        notice: settings.canManageStaff === true ? '' : 'Staff accounts are managed by a WordPress administrator. Ask an administrator to add users and assign WowRestro roles.',
	        count: rows.length + (rows.length === 1 ? ' role' : ' roles'),
	        emptyTitle: 'No staff roles found', emptyDescription: 'WowRestro repairs its Manager, Staff and Waiter roles automatically on the next page load.'
	      };
	    } else if (route === '/live-orders') {
	      const rows = this.orderData().filter(o => !ls || o.customer.toLowerCase().indexOf(ls) > -1 || String(o.id).indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search by order id or customer...', addLabel: '', hasAdd: false, onAdd: () => {},
	        columns: ['Order', 'Customer', 'Type', 'Items', 'Total', 'Placed', 'Status', 'Actions'],
	        rows: rows.map(o => ({ cells: [
	          plain('#' + o.id, '#2B4BFF', '500'), plain(o.customer), plain(o.type), plain(String(o.items)),
	          plain(this.money(o.total), '#101828', '500'), plain(o.time, '#6b7280'),
	          badge(o.status, this.orderDot(o.status)),
	          actions(() => WR.goto(o.editUrl), null)
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' order' : ' orders'),
	        emptyTitle: 'No orders found', emptyDescription: 'New orders will appear here once customers place them.'
	      };
	    } else if (route === '/location') {
	      const rows = st.locations.filter(l => !ls || l.name.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search locations...', addLabel: 'Add Location', hasAdd: true,
	        onAdd: () => this.go('/location/create', 'service-options'),
	        columns: ['Restaurant name', 'Address', 'Phone', 'Modules', 'Status', 'Actions'],
	        rows: rows.map(l => ({ cells: [
	          avatar(l.name, l.address, 'ph ph-map-pin', '#2B4BFF', l.imageUrl), plain(l.address, '#6b7280'), plain(l.phone, '#6b7280'), plain(l.modules),
	          badge(l.status, l.status === 'Published' ? '#00c950' : '#f0b100'),
	          actions(() => this.go('/location/update/' + l.id, 'service-options'),
	                  () => this.askDelete('Delete ' + l.name + '?', 'Menus, schedules and reservations attached to this branch stop working immediately.',
	                    () => WR.delete('/workspace/location/' + l.id).then(result => {
	                      this.setState({ locations: result.locations || [] });
	                      this.toast('Location deleted');
	                    }).catch(error => this.toast(error && error.message ? error.message : 'Could not delete location'))))
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' location' : ' locations'),
	        emptyTitle: 'No locations found', emptyDescription: 'Add a branch to run separate menus, schedules and reservations.'
	      };
	    } else if (route === '/qr-code') {
	      const rows = st.qrcodes.filter(qr => !ls || qr.name.toLowerCase().indexOf(ls) > -1 || qr.url.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search QR codes...', addLabel: 'Create QR Code', hasAdd: true,
	        onAdd: () => this.go('/qr-code/create', st.group),
	        columns: ['Table card', 'Destination', 'Created', 'Actions'],
	        rows: rows.map(qr => ({ cells: [
	          qrThumb(qr), stacked(WR.qrHost(qr.url) || 'Menu page', qr.url), plain(qr.created || '-'),
	          qrActions(
	            () => this.setState({ qrPreview: qr }),
	            () => WR.openQr(qr),
	            () => WR.copyText(qr.url).then(() => this.toast('Menu link copied')).catch(() => this.toast('Could not copy the menu link')),
	            () => WR.downloadQr(qr).then(() => this.toast('Print-ready table card downloaded')).catch(error => this.toast(error.message)),
	            () => this.askDelete('Delete ' + qr.name + '?', 'The saved QR image is removed. Printed copies will still open the same page URL.',
	              () => WR.delete('/workspace/qr-code/' + qr.id).then(() => {
	                this.setState(s => ({ qrcodes: s.qrcodes.filter(x => x.id !== qr.id) }));
	                this.toast('QR code deleted');
	              }).catch(error => this.toast(error.message)))
	          )
	        ] })),
	        count: rows.length + (rows.length === 1 ? ' QR code' : ' QR codes'),
	        emptyTitle: 'No table cards yet', emptyDescription: 'Create a branded QR table card that guests can scan to open your menu.'
	      };
	    } else if (route === '/automations') {
	      const rows = st.automations.filter(a => !ls || a.name.toLowerCase().indexOf(ls) > -1);
	      listCfg = {
	        placeholder: 'Search automations...', addLabel: 'New Automation', hasAdd: true,
	        onAdd: () => this.go('/automations/create', 'settings'),
	        columns: ['Automation', 'Trigger', 'Audience', 'Sent', 'Status', 'Actions'],
	        rows: rows.map(a => ({ cells: [
	          plain(a.name, '#101828', '500'), plain(a.trigger, '#6b7280'), plain(a.audience), plain(String(a.sent)),
	          badge(a.enabled ? 'Active' : 'Paused', a.enabled ? '#00c950' : '#99a1af'),
	          actions(() => { this.setState(s => ({ automations: s.automations.map(x => x.id === a.id ? Object.assign({}, x, { enabled: !x.enabled }) : x) })); this.toast(a.name + (a.enabled ? ' paused' : ' activated')); },
	                  () => this.askDelete('Delete ' + a.name + '?', 'Guests already queued for this email will not receive it.',
	                    () => { this.setState(s => ({ automations: s.automations.filter(x => x.id !== a.id) })); this.toast('Automation deleted'); }))
	        ] })),
	        count: rows.length + ' automations',
	        emptyTitle: 'No automations found', emptyDescription: 'Create an automation to email guests at the right moment.'
	      };
	    } else if (route === '/discount') {
	      listCfg = {
	        placeholder: 'Search discounts...', addLabel: 'Add Discount', hasAdd: true, onAdd: () => this.toast('Discount editor opened'),
	        columns: ['Name', 'Type', 'Value', 'Applies to', 'Status', 'Actions'],
	        rows: [
	          ['Weekday lunch 15%', 'Percentage', '15%', 'Lunch menu', 'Active'],
	          ['Free delivery weekend', 'Shipping', 'Free', 'Orders over $35', 'Active'],
	          ['First order $5 off', 'Fixed', '$5.00', 'New customers', 'Paused']
	        ].map(d => ({ cells: [
	          plain(d[0], '#101828', '500'), plain(d[1]), plain(d[2]), plain(d[3], '#6b7280'),
	          badge(d[4], d[4] === 'Active' ? '#00c950' : '#99a1af'),
	          actions(() => this.toast('Editing ' + d[0]), () => this.toast(d[0] + ' deleted'))
	        ] })),
	        count: '3 discounts', emptyTitle: 'No discounts', emptyDescription: 'Create a rule to reward regulars.'
	      };
	    } else if (route === '/timed-product') {
	      listCfg = {
	        placeholder: 'Search timed products...', addLabel: 'Add Rule', hasAdd: true, onAdd: () => this.toast('Rule editor opened'),
	        columns: ['Rule', 'Products', 'Days', 'Window', 'Status', 'Actions'],
	        rows: [
	          ['Breakfast menu', '12 items', 'Mon – Fri', '7:00 am – 11:00 am', 'Active'],
	          ['Weekend brunch', '8 items', 'Sat – Sun', '9:00 am – 2:00 pm', 'Active'],
	          ['Late night bites', '6 items', 'Thu – Sat', '10:00 pm – 1:00 am', 'Paused']
	        ].map(d => ({ cells: [
	          plain(d[0], '#101828', '500'), plain(d[1]), plain(d[2]), plain(d[3], '#6b7280'),
	          badge(d[4], d[4] === 'Active' ? '#00c950' : '#99a1af'),
	          actions(() => this.toast('Editing ' + d[0]), () => this.toast(d[0] + ' deleted'))
	        ] })),
	        count: '3 rules', emptyTitle: 'No timed products', emptyDescription: 'Show menu items only during certain hours.'
	      };
	    }

	    /* form pages */
	    let formCfg = null;
	    const resId = route.indexOf('/reservations/update/') === 0 ? Number(route.split('/').pop()) : 0;
	    const locId = route.indexOf('/location/update/') === 0 ? Number(route.split('/').pop()) : 0;

	    const idFrom = (p) => route.indexOf(p) === 0 ? Number(route.split('/').pop()) : 0;
	    const itemId = idFrom('/food-menu/items/update/');
	    const catId = idFrom('/food-menu/categories/update/');
	    const addonId = idFrom('/food-menu/addons/update/');
	    const brandId = idFrom('/food-menu/brands/update/');
	    const labelId = idFrom('/food-menu/labels/update/');
	    const nutId = idFrom('/food-menu/nutrition/update/');
	    const catNames = st.categories.map(c => c.name);
	    const brandNames = st.brands.map(b => b.name);

	    if (route === '/food-menu/addons/create' || addonId) {
	      const addon = addonId ? st.modifiers.find(group => group.id === addonId) : null;
	      const d = {
	        name: addon ? addon.name : '', type: addon ? addon.typeKey : 'single',
	        min: addon ? String(addon.min) : '0', max: addon ? String(addon.max) : '1',
	        status: addon ? addon.status : 'Published',
	        options: addon && addon.optionRows && addon.optionRows.length ? addon.optionRows.map(option => Object.assign({}, option)) : [{ label: '', price: '' }]
	      };
	      const addonType = this.val('a_type', d.type);
	      const selectionFields = addonType === 'multiple' ? [
	        { kind: 'input', key: 'a_min', label: 'Minimum selections', def: d.min, type: 'number', help: 'Use 0 when the group is optional.' },
	        { kind: 'input', key: 'a_max', label: 'Maximum selections', def: d.max, type: 'number', help: 'Use 0 for no maximum.' }
	      ] : [
	        { kind: 'select', key: 'a_required', label: 'Customer requirement', options: ['Optional', 'Required'], def: Number(d.min) > 0 ? 'Required' : 'Optional', span: '1 / -1' }
	      ];
	      formCfg = {
	        submitLabel: addonId ? 'Update Add-on' : 'Add Add-on',
	        message: addonId ? 'Add-on group updated' : 'Add-on group added',
	        back: '/food-menu/addons', group: st.group,
	        sections: [
	          { title: 'Add-on', description: 'Create one reusable add-on, then assign it to any variable menu item.', fields: [
	            { kind: 'input', key: 'a_name', label: 'Add-on name', def: d.name, placeholder: 'e.g. Pizza extras', required: true },
	            { kind: 'select', key: 'a_type', label: 'Selection type', options: [{ label: 'Single item', value: 'single' }, { label: 'Multiple items', value: 'multiple' }], def: addonType },
	            { kind: 'select', key: 'a_status', label: 'Status', options: ['Published', 'Draft'], def: d.status, span: '1 / -1' }
	          ]},
	          { title: 'Selection Rules', description: addonType === 'single' ? 'Customers can choose one option from this group.' : 'Customers can choose more than one option from this group.', fields: selectionFields },
	          { title: 'Add-on Options', description: 'Each option is a child of this group and carries its own additional price.', fields: [
	            { kind: 'repeater', key: 'a_options', label: 'Options', span: '1 / -1', addLabel: 'Add option', allowEmpty: true,
	              def: d.options, columns: [
	                { key: 'label', label: 'Add-on name', placeholder: 'e.g. Extra cheese' },
	                { key: 'price', label: 'Additional price', placeholder: '0.00', type: 'number' }
	              ], help: 'Prices are added to the selected variation price.' }
	          ]}
	        ],
	        onSubmit: () => {
	          const name = String(this.val('a_name', d.name) || '').trim();
	          const type = this.val('a_type', d.type);
	          const options = (this.val('a_options', d.options) || []).filter(option => String(option.label || '').trim()).map(option => ({
	            id: option.id || '', label: String(option.label).trim(), price: option.price || '', default: !!option.default
	          }));
	          if (!name) return Promise.reject(new Error('Enter an add-on group name.'));
	          if (!options.length) return Promise.reject(new Error('Add at least one priced or free add-on option.'));
	          const min = type === 'single' ? (this.val('a_required', Number(d.min) > 0 ? 'Required' : 'Optional') === 'Required' ? 1 : 0) : Number(this.val('a_min', d.min) || 0);
	          const max = type === 'single' ? 1 : Number(this.val('a_max', d.max) || 0);
	          if (max > 0 && min > max) return Promise.reject(new Error('Maximum selections must be greater than or equal to minimum selections.'));
	          return WR.post('/workspace/modifier', { id: addonId, name, type, min, max, options, status: this.val('a_status', d.status) }).then(saved => this.setState(state => ({
	            modifiers: addonId ? state.modifiers.map(group => group.id === addonId ? saved : group) : state.modifiers.concat([saved])
	          })));
	        }
	      };
	    } else if (route === '/food-menu/items/create' || itemId) {
	      const it = itemId ? st.items.find(x => x.id === itemId) : null;
	      const d = {
	        name: it ? it.name : '', sku: it ? it.sku : '', category: it ? it.category : catNames[0],
	        price: it ? String(it.price) : '',
	        sale: it && it.sale ? String(it.sale) : '', stock: it ? it.stock : 'In stock',
	        status: it ? it.status : 'Published', labels: it ? it.labels.slice() : [],
	        brand: it ? it.brand : '', crossSells: it ? (it.crossSells || []).slice() : [],
	        image: it ? { id: it.imageId || 0, url: it.imageUrl || '' } : { id: 0, url: '' },
	        type: it ? it.type : 'simple', variations: it ? (it.variations || []) : [],
	        quickAddons: it ? (it.quickAddons || []).map(addon => Object.assign({}, addon)) : [],
	        modifierGroups: it ? (it.modifierGroups || []).slice() : [],
	        desc: it && it.description ? it.description : ''
	      };
	      const productType = this.val('i_type', d.type);
	      const showVariationSections = productType === 'variable';
	      const showAddonSection = productType === 'variable';
	      const variationSections = showVariationSections ? d.variations.map(v => ({
	        title: v.name || ('Variation #' + v.id), description: 'Price, SKU and availability save directly to this WooCommerce variation.', fields: [
	          { kind: 'input', key: 'v_sku_' + v.id, label: 'SKU', def: v.sku || '', placeholder: 'Optional' },
	          { kind: 'input', key: 'v_price_' + v.id, label: 'Regular price', def: v.price || '', placeholder: '0.00', type: 'number' },
	          { kind: 'input', key: 'v_sale_' + v.id, label: 'Sale price', def: v.sale || '', placeholder: 'Optional', type: 'number' },
	          { kind: 'select', key: 'v_stock_' + v.id, label: 'Stock status', options: ['In stock', 'Out of stock'], def: v.stock || 'In stock' }
	        ]
	      })) : [];
	      const formSections = [
	        { title: 'Item Details', description: 'Saved as a WooCommerce product, so it shows up in every food menu shortcode.', fields: [
	          { kind: 'input', key: 'i_name', label: 'Item name', def: d.name, placeholder: 'e.g. Margherita Pizza' },
	          { kind: 'input', key: 'i_sku', label: 'SKU', def: d.sku, placeholder: 'e.g. PZ-001' }
	        ].concat([
	          { kind: 'select', key: 'i_type', label: 'Product type', options: [{ label: 'Simple item', value: 'simple' }, { label: 'Variable item', value: 'variable' }], def: productType }
	        ]).concat([
	          { kind: 'select', key: 'i_category', label: 'Food category', options: catNames, def: d.category },
	          { kind: 'select', key: 'i_brand', label: 'Brand', options: [{ label: 'No brand', value: '' }].concat(brandNames.map(name => ({ label: name, value: name }))), def: d.brand },
	          { kind: 'media', key: 'i_image', label: 'Menu item image', def: d.image, span: '1 / -1', help: 'Choose or replace the image from the WordPress Media Library without leaving WowRestro.' },
	          { kind: 'textarea', key: 'i_desc', label: 'Short description', def: d.desc, placeholder: 'One or two lines shown on the menu card.', span: '1 / -1' }
	        ])},
	        { title: productType === 'variable' ? 'Parent Availability' : 'Pricing & Stock', description: productType === 'variable' ? 'Variation prices are managed in the sections below.' : 'Leave the sale price empty to sell at the regular price.', fields: (productType === 'variable' ? [
	          { kind: 'select', key: 'i_stock', label: 'Parent stock status', options: ['In stock', 'Out of stock'], def: d.stock },
	          { kind: 'select', key: 'i_status', label: 'Status', options: ['Published', 'Draft'], def: d.status }
	        ] : [
	          { kind: 'input', key: 'i_price', label: 'Regular price', def: d.price, placeholder: '0.00', type: 'number' },
	          { kind: 'input', key: 'i_sale', label: 'Sale price', def: d.sale, placeholder: 'Optional', type: 'number' },
	          { kind: 'select', key: 'i_stock', label: 'Stock status', options: ['In stock', 'Out of stock'], def: d.stock },
	          { kind: 'select', key: 'i_status', label: 'Status', options: ['Published', 'Draft'], def: d.status }
	        ])}
	      ];
	      if (showVariationSections) {
	        formSections.push(...variationSections);
	        formSections.push({ title: itemId ? 'Add Variations' : 'Variations', description: itemId ? 'Add more WooCommerce variations without leaving WowRestro.' : 'Add optional variation choices here; entering one automatically saves this as a variable item.', fields: [
	          { kind: 'repeater', key: 'i_variations', label: 'Variation options', span: '1 / -1', addLabel: 'Add variation',
	            def: [{ name: '', price: '', sale: '', sku: '' }], columns: [
	              { key: 'name', label: 'Variation name', placeholder: 'e.g. Large' },
	              { key: 'price', label: 'Regular price', placeholder: '0.00', type: 'number' },
	              { key: 'sale', label: 'Sale price', placeholder: 'Optional', type: 'number' },
	              { key: 'sku', label: 'SKU', placeholder: 'Optional' }
	            ], help: 'Add one clearly named row for each size or option.' }
	        ]});
	      }
	      if (showAddonSection) {
	        formSections.push({ title: 'Add-ons', description: 'Assign reusable add-on groups. Create and price their options from Food Menu → Add-ons.', fields: [
	          { kind: 'chips', key: 'i_modifier_groups', label: 'Add-on groups', span: '1 / -1', def: d.modifierGroups,
	            options: st.modifiers.map(group => ({ label: group.name, value: group.id, color: group.typeKey === 'single' ? '#2B4BFF' : '#7B3BFF', icon: group.typeKey === 'single' ? 'ph ph-radio-button' : 'ph ph-check-square' })) },
	          { kind: 'note', label: '', span: '1 / -1', value: st.modifiers.length ? 'Selected groups appear on the menu item in the order shown above.' : 'No reusable add-on groups exist yet. Save this item, then create one from Food Menu → Add-ons.' }
	        ]});
	      }
	      formCfg = {
	        submitLabel: itemId ? 'Update Item' : 'Publish Item',
	        message: itemId ? 'Menu item updated' : 'Menu item published',
	        back: '/food-menu/items', group: st.group,
	        sections: formSections.concat([
	          { title: 'Product Labels', description: 'Badges render on the menu card and inside the details popup.', fields: [
	            { kind: 'chips', key: 'i_labels', label: 'Labels', def: d.labels, span: '1 / -1',
	              options: st.labels.map(l => ({ label: l.name, color: l.color, icon: l.icon })) }
	          ]},
	          { title: 'Order Bumps', description: 'Suggest related menu items using native WooCommerce cross-sells.', fields: [
	            { kind: 'chips', key: 'i_cross_sells', label: 'Suggested items', def: d.crossSells, span: '1 / -1',
	              options: st.items.filter(x => x.id !== itemId).map(x => ({ label: x.name, value: x.id, color: '#2B4BFF', icon: 'ph ph-plus-circle' })) }
	          ]}
	        ]),
	        onSubmit: () => {
	          const image = this.val('i_image', d.image) || { id: 0, url: '' };
	          const newVariations = showVariationSections ? (this.val('i_variations', []) || []).filter(row => String(row.name || '').trim()) : [];
          const rec = {
	            id: itemId,
	            name: this.val('i_name', d.name) || 'Untitled item',
	            sku: this.val('i_sku', d.sku),
	            category: this.val('i_category', d.category),
	            price: this.val('i_price', d.price),
	            sale: this.val('i_sale', d.sale),
	            stock: this.val('i_stock', d.stock),
	            status: this.val('i_status', d.status),
	            brand: this.val('i_brand', d.brand),
	            labels: this.val('i_labels', d.labels),
	            crossSells: this.val('i_cross_sells', d.crossSells),
	            modifierGroups: this.val('i_modifier_groups', d.modifierGroups),
	            imageId: Number(image.id) || 0,
	            type: newVariations.length ? 'variable' : productType,
	            variations: (itemId ? d.variations.map(v => ({
	              id: v.id, sku: this.val('v_sku_' + v.id, v.sku || ''),
	              price: this.val('v_price_' + v.id, v.price || ''), sale: this.val('v_sale_' + v.id, v.sale || ''),
	              stock: this.val('v_stock_' + v.id, v.stock || 'In stock')
	            })) : []).concat(newVariations.map(row => ({ id: 0, name: String(row.name).trim(), price: row.price || '', sale: row.sale || '', sku: row.sku || '', stock: 'In stock' }))),
	            description: this.val('i_desc', d.desc)
	          };
	          return WR.post('/workspace/product', rec).then(saved => this.setState(s => ({
	            items: itemId ? s.items.map(x => x.id === itemId ? saved : x) : s.items.concat([saved])
	          })));
	        }
	      };
	    } else if (route === '/food-menu/categories/create' || catId) {
	      const c = catId ? st.categories.find(x => x.id === catId) : null;
	      const d = {
	        name: c ? c.name : '', slug: c ? c.slug : '', parent: c ? c.parent : '-',
	        desc: c && c.description ? c.description : ''
	      };
	      formCfg = {
	        submitLabel: catId ? 'Update Category' : 'Add Category',
	        message: catId ? 'Category updated' : 'Category added',
	        back: '/food-menu/categories', group: st.group,
	        sections: [
	          { title: 'Category', description: 'Categories drive the filter tabs above your menu and the category shortcodes.', fields: [
	            { kind: 'input', key: 'c_name', label: 'Category name', def: d.name, placeholder: 'e.g. Wood Fired Pizza' },
	            { kind: 'input', key: 'c_slug', label: 'Slug', def: d.slug, placeholder: 'Leave empty to generate from the name', help: 'Used in menu URLs and shortcode attributes.' },
	            { kind: 'select', key: 'c_parent', label: 'Parent category', options: ['-'].concat(catNames.filter(n => n !== d.name)), def: d.parent },
	            { kind: 'textarea', key: 'c_desc', label: 'Description', def: d.desc, placeholder: 'Optional text shown above the category on the menu.', span: '1 / -1' }
	          ]}
	        ],
	        onSubmit: () => {
	          const name = this.val('c_name', d.name) || 'Untitled category';
	          const rec = {
	            id: catId,
	            name, slug: this.val('c_slug', d.slug) || this.slugify(name),
	            parent: this.val('c_parent', d.parent), description: this.val('c_desc', d.desc)
	          };
	          return WR.post('/workspace/category', rec).then(saved => this.setState(s => ({
	            categories: catId ? s.categories.map(x => x.id === catId ? saved : x) : s.categories.concat([saved])
	          })));
	        }
	      };
	    } else if (route === '/food-menu/brands/create' || brandId) {
	      const b = brandId ? st.brands.find(x => x.id === brandId) : null;
	      const d = { name: b ? b.name : '', slug: b ? b.slug : '', description: b ? b.description : '' };
	      formCfg = {
	        submitLabel: brandId ? 'Update Brand' : 'Add Brand',
	        message: brandId ? 'Brand updated' : 'Brand added',
	        back: '/food-menu/brands', group: st.group,
	        sections: [
	          { title: 'Brand', description: 'Stored as a WooCommerce product attribute, so brands work in menu filters straight away.', fields: [
	            { kind: 'input', key: 'b_name', label: 'Brand name', def: d.name, placeholder: 'e.g. Stonebaked Co.' },
	            { kind: 'input', key: 'b_slug', label: 'Slug', def: d.slug, placeholder: 'Leave empty to generate from the name' },
	            { kind: 'textarea', key: 'b_desc', label: 'Description', def: d.description, placeholder: 'Shown on the brand filter tooltip.', span: '1 / -1' }
	          ]}
	        ],
	        onSubmit: () => {
	          const name = this.val('b_name', d.name) || 'Untitled brand';
	          const rec = { id: brandId, name, slug: this.val('b_slug', d.slug) || this.slugify(name), description: this.val('b_desc', d.description) };
	          return WR.post('/workspace/brand', rec).then(saved => this.setState(s => ({
	            brands: brandId ? s.brands.map(x => x.id === brandId ? saved : x) : s.brands.concat([saved]),
	            items: brandId && d.name !== saved.name ? s.items.map(it => Object.assign({}, it, { brand: it.brand === d.name ? saved.name : it.brand })) : s.items
	          })));
	        }
	      };
	    } else if (route === '/food-menu/labels/create' || labelId) {
	      const l = labelId ? st.labels.find(x => x.id === labelId) : null;
	      const d = { name: l ? l.name : '', color: l ? l.color : '#2B4BFF', icon: l ? l.icon : 'ph ph-leaf' };
	      formCfg = {
	        submitLabel: labelId ? 'Update Label' : 'Add Label',
	        message: labelId ? 'Label updated' : 'Label added',
	        back: '/food-menu/labels', group: st.group,
	        sections: [
	          { title: 'Product Label', description: 'Pick a colour and an icon - the badge renders on every menu card and details popup.', fields: [
	            { kind: 'input', key: 'lb_name', label: 'Label name', def: d.name, placeholder: 'e.g. Vegan' },
	            { kind: 'swatch', key: 'lb_color', label: 'Badge colour', def: d.color,
	              options: ['#2B4BFF', '#00a63e', '#e7000b', '#00B5D8', '#9333e9', '#f0b100', '#00bba7', '#1d222b'] },
	            { kind: 'iconpick', key: 'lb_icon', label: 'Icon', def: d.icon, span: '1 / -1',
	              options: ['ph ph-leaf', 'ph ph-fire', 'ph ph-grains', 'ph ph-star', 'ph ph-sparkle', 'ph ph-heart', 'ph ph-cow', 'ph ph-fish', 'ph ph-carrot', 'ph ph-snowflake'] }
	          ]}
	        ],
	        onSubmit: () => {
	          const rec = {
	            id: labelId,
	            name: this.val('lb_name', d.name) || 'New label',
	            color: this.val('lb_color', d.color), icon: this.val('lb_icon', d.icon)
	          };
	          return WR.post('/workspace/label', rec).then(saved => this.setState(s => ({
	            labels: labelId ? s.labels.map(x => x.id === labelId ? saved : x) : s.labels.concat([saved]),
	            items: labelId ? s.items.map(it => Object.assign({}, it, { labels: it.labels.map(n => n === d.name ? saved.name : n) })) : s.items
	          })));
	        }
	      };
	    } else if (nutId) {
	      const it = st.items.find(x => x.id === nutId) || st.items[0];
	      const n = this.nutritionOf(it);
	      const d = {
	        calories: n.calories, allergens: n.allergens.slice(),
	        serving: it.serving || '1 portion', protein: it.protein || '', carbs: it.carbs || '', fat: it.fat || '',
	        note: it.dietNote || ''
	      };
	      formCfg = {
	        submitLabel: 'Save Nutrition Info', message: 'Nutrition info saved for ' + it.name,
	        back: '/food-menu/nutrition', group: st.group,
	        sections: [
	          { title: it.name + ' - Nutrition', description: 'Values render inside the product card and the details popup on the frontend.', fields: [
	            { kind: 'input', key: 'n_serving', label: 'Serving size', def: d.serving, placeholder: 'e.g. 1 portion (320g)' },
	            { kind: 'input', key: 'n_calories', label: 'Calories', def: d.calories, placeholder: 'e.g. 820 kcal' },
	            { kind: 'input', key: 'n_protein', label: 'Protein', def: d.protein, placeholder: 'e.g. 28 g' },
	            { kind: 'input', key: 'n_carbs', label: 'Carbohydrates', def: d.carbs, placeholder: 'e.g. 96 g' },
	            { kind: 'input', key: 'n_fat', label: 'Fat', def: d.fat, placeholder: 'e.g. 24 g' }
	          ]},
	          { title: 'Allergens', description: 'Anything you tick is shown as a warning badge next to the item.', fields: [
	            { kind: 'chips', key: 'n_allergens', label: 'Contains', def: d.allergens, span: '1 / -1',
	              options: this.allergenList().map(a => ({ label: a, color: '#e7000b', icon: 'ph ph-warning' })) },
	            { kind: 'textarea', key: 'n_note', label: 'Kitchen note', def: d.note, placeholder: 'e.g. Prepared in a kitchen that handles nuts.', span: '1 / -1' }
	          ]}
	        ],
	        onSubmit: () => {
	          const rec = {
	            id: it.id,
	            serving: this.val('n_serving', d.serving), calories: this.val('n_calories', d.calories),
	            protein: this.val('n_protein', d.protein), carbs: this.val('n_carbs', d.carbs),
	            fat: this.val('n_fat', d.fat), allergens: this.val('n_allergens', d.allergens),
	            note: this.val('n_note', d.note)
	          };
	          return WR.post('/workspace/nutrition', rec).then(saved => this.setState(s => ({
	            items: s.items.map(x => x.id === it.id ? saved : x),
	            allergens: saved.allergens.reduce((list, name) => list.some(a => a.name === name) ? list : list.concat([{ id: 0, name }]), s.allergens)
	          })));
	        }
	      };
	    } else if (route === '/reservations/create' || resId) {
	      const r = resId ? this.findReservation(resId) : null;
	      const today = new Date().toISOString().slice(0, 10);
	      const activeLocations = st.locations.filter(location => location.reservations && location.status === 'Published');
	      const createFields = [
	        { kind: 'input', key: 'r_date', label: 'Date *', def: today, type: 'date', span: '1 / -1' },
	        { kind: 'input', key: 'r_time', label: 'From when? *', def: '19:00', type: 'time', span: '1 / -1' },
	        { kind: 'input', key: 'r_end_time', label: 'Until? *', def: '20:00', type: 'time', span: '1 / -1' },
	        { kind: 'input', key: 'r_name', label: 'Your Name *', def: '', placeholder: 'Name here', span: '1 / -1' },
	        { kind: 'input', key: 'r_email', label: 'Your Email *', def: '', placeholder: 'Email here', type: 'email', span: '1 / -1' },
	        { kind: 'input', key: 'r_phone', label: 'How can we contact you? *', def: '', placeholder: 'Phone Number here', type: 'tel', span: '1 / -1' },
	        { kind: 'input', key: 'r_guests', label: 'Total Guests *', def: String(Math.max(2, Number(reservationSettings.min_guests || 1))), placeholder: 'Number of Guests', type: 'number', span: '1 / -1' }
	      ];
	      if (activeLocations.length) createFields.push({
	        kind: 'select', key: 'r_location', label: 'Reservation Location *',
	        options: activeLocations.map(location => location.name), def: activeLocations[0].name, span: '1 / -1'
	      });
	      createFields.push({ kind: 'textarea', key: 'r_note', label: 'Additional Information', def: '', placeholder: 'Enter Your Message here', span: '1 / -1' });
	      formCfg = {
	        submitLabel: resId ? 'Update Reservation' : 'Create Reservation',
	        message: resId ? 'Reservation updated' : 'Reservation created',
	        back: '/reservations', group: 'reservations', headerAction: !resId,
	        sections: resId ? [
	          { title: 'Reservation Details', description: 'Date, time and party size for this booking.', fields: [
	            { kind: 'input', key: 'r_date', label: 'Date', def: r ? r.date : today, type: 'date' },
	            { kind: 'input', key: 'r_time', label: 'From when?', def: r ? r.time : '19:00', type: 'time' },
	            { kind: 'input', key: 'r_end_time', label: 'Until?', def: r ? (r.endTime || '') : '20:00', type: 'time' },
	            { kind: 'input', key: 'r_guests', label: 'Number of guests', def: r ? String(r.guests) : '2', type: 'number' },
	            { kind: 'select', key: 'r_location', label: 'Location', options: st.locations.map(l => l.name), def: r ? r.location : (st.locations[0] ? st.locations[0].name : '') }
	          ]},
	          { title: 'Customer', description: 'Who the table is booked for.', fields: [
	            { kind: 'input', key: 'r_name', label: 'Full name', def: r ? r.name : '', placeholder: 'e.g. Amelia Hartley' },
	            { kind: 'input', key: 'r_email', label: 'Email address', def: r ? r.email : '', placeholder: 'name@mail.com', type: 'email' },
	            { kind: 'input', key: 'r_phone', label: 'Phone number', def: r ? r.phone : '', placeholder: '+44 7700 900000' },
	            { kind: 'select', key: 'r_status', label: 'Status', options: ['Pending', 'Confirmed', 'Cancelled'], def: r ? (st.reservationStatus[resId] || r.status) : 'Pending' }
	          ]},
	          { title: 'Extras', description: 'Food pre-order, payment and anything the kitchen should know.', fields: [
	            { kind: 'select', key: 'r_payment', label: 'Payment method', options: ['Stripe', 'PayPal', 'Cash on arrival', 'N/A'], def: r ? r.payment : 'Stripe' },
	            { kind: 'toggle', key: 'r_food', label: 'Pre-order food', help: 'Attach menu items to this reservation', def: r ? r.food === 'Yes' : false },
	            { kind: 'textarea', key: 'r_note', label: 'Special request', def: r ? r.notes : '', span: '1 / -1' }
	          ]}
	        ] : [{
	          title: 'Reservation Details',
	          description: 'Fill out the form below to create a new reservation. Provide guest details, select the desired date and time, and confirm the booking.',
	          fields: createFields
	        }],
	        onSubmit: () => {
	          const start = String(this.val('r_time', '') || '');
	          const end = String(this.val('r_end_time', '') || '');
	          const required = [this.val('r_date', ''), start, end, this.val('r_name', ''), this.val('r_email', ''), this.val('r_phone', ''), this.val('r_guests', '')];
	          if (required.some(value => !String(value || '').trim())) return Promise.reject(new Error('Complete all required reservation details.'));
	          if (end <= start) return Promise.reject(new Error('The reservation end time must be after the start time.'));
	          return WR.post('/workspace/reservation', {
	          id: resId, date: this.val('r_date'), time: start, end_time: end, guests: this.val('r_guests'),
	          location: this.val('r_location', r ? r.location : ''), name: this.val('r_name'), email: this.val('r_email'),
	          phone: this.val('r_phone'), status: resId ? this.val('r_status').toLowerCase() : (reservationSettings.default_status || 'pending'), payment: resId ? this.val('r_payment') : 'N/A',
	          food: this.val('r_food', false), notes: this.val('r_note')
	        }).then(result => this.setState({ reservations: result.reservations || [], reservationStatus: {}, deletedReservations: [] }));
	        }
	      };
	    } else if (route === '/location/create' || locId) {
	      const l = locId ? st.locations.find(x => x.id === locId) : null;
	      const customCoordinates = !!this.val('l_custom_coords', !!(l && (l.latitude || l.longitude)));
	      const locationFields = [
	        { kind: 'input', key: 'l_name', label: 'Restaurant Name *', def: l ? l.name : '', placeholder: "Your restaurant's name", span: '1 / -1' },
	        { kind: 'input', key: 'l_address', label: 'Location *', def: l ? l.address : '', placeholder: 'Search or enter address', span: '1 / -1' },
	        { kind: 'link', label: 'Get current location', span: '1 / -1', onClick: () => {
	          if (!navigator.geolocation) { this.toast('Location access is not available in this browser'); return; }
	          navigator.geolocation.getCurrentPosition(position => {
	            this.setState(state => ({ values: Object.assign({}, state.values, {
	              l_custom_coords: true, l_latitude: position.coords.latitude.toFixed(6), l_longitude: position.coords.longitude.toFixed(6)
	            }) }));
	            this.toast('Current coordinates added');
	          }, () => this.toast('Allow location access, then try again'), { enableHighAccuracy: true, timeout: 10000 });
	        } },
	        { kind: 'toggle', key: 'l_custom_coords', label: 'Use custom latitude and longitude', help: 'Turn this on when the map pin is not accurate.', def: customCoordinates, span: '1 / -1' }
	      ];
	      if (customCoordinates) locationFields.push(
	        { kind: 'input', key: 'l_latitude', label: 'Latitude', def: l ? l.latitude : '', placeholder: 'e.g. 53.4808' },
	        { kind: 'input', key: 'l_longitude', label: 'Longitude', def: l ? l.longitude : '', placeholder: 'e.g. -2.2426' }
	      );
	      locationFields.push(
	        { kind: 'media-dropzone', key: 'l_image', label: 'Location Image', def: { id: l ? Number(l.imageId || 0) : 0, url: l ? (l.imageUrl || '') : '' }, span: '1 / -1', help: 'PNG, JPG or WEBP from the WordPress Media Library.' },
	        { kind: 'input', key: 'l_phone', label: 'Phone number', def: l ? l.phone : '', placeholder: '+44 161 555 0000' },
	        { kind: 'input', key: 'l_email', label: 'Contact email', def: l ? l.email : '', placeholder: 'branch@mail.com', type: 'email' },
	        { kind: 'select', key: 'l_status', label: 'Status', options: ['Published', 'Draft'], def: l ? l.status : 'Published', span: '1 / -1' },
	        { kind: 'featuretoggle', key: 'l_override_schedule', title: 'Override Default Weekly Global Schedule', badge: 'Free', icon: 'ph ph-calendar-blank', description: 'Use a location-specific weekly schedule instead of the global restaurant schedule.', def: l ? l.overrideSchedule : false, span: '1 / -1' },
	        { kind: 'featuretoggle', key: 'l_ordering', title: 'Online Ordering', badge: 'WooCommerce', icon: 'ph ph-shopping-cart', description: 'Accept pickup and delivery orders for this location. Products, stock and orders remain managed by WooCommerce.', def: l ? l.ordering : true, span: '1 / -1' },
	        { kind: 'featuretoggle', key: 'l_reservation', title: 'Reservations', badge: 'Included', icon: 'ph ph-calendar-check', description: 'Accept table reservations for this location through WowRestro.', def: l ? l.reservations : true, span: '1 / -1' }
	      );
	      formCfg = {
	        submitLabel: locId ? 'Update Location' : 'Create Location',
	        message: locId ? 'Location updated' : 'Location created',
	        back: '/location', group: 'service-options', headerAction: !locId,
	        sections: [{ title: 'Basic Information', description: 'Provide the basic information for your restaurant location.', fields: locationFields }],
	        onSubmit: () => {
	          if (!String(this.val('l_name', '') || '').trim() || !String(this.val('l_address', '') || '').trim()) return Promise.reject(new Error('Enter the restaurant name and location address.'));
	          return WR.post('/workspace/location', {
	          imageId: Number((this.val('l_image', { id: 0 }) || {}).id || 0),
	          id: locId, name: this.val('l_name'), phone: this.val('l_phone'), address: this.val('l_address'),
	          latitude: customCoordinates ? this.val('l_latitude') : '', longitude: customCoordinates ? this.val('l_longitude') : '', customCoordinates,
	          email: this.val('l_email'), status: this.val('l_status'), ordering: this.val('l_ordering', true),
	          reservations: this.val('l_reservation', true), overrideSchedule: this.val('l_override_schedule', false)
	        }).then(result => this.setState({ locations: result.locations || [] }));
	        }
	      };
	    } else if (route === '/qr-code/create') {
	      formCfg = {
	        submitLabel: 'Create Table Card', message: 'Table card created', back: '/qr-code', group: st.group,
	        onSubmit: () => {
	          const name = String(this.val('q_name', '')).trim();
	          const url = String(this.val('q_url', (settings.links || {}).menu || '')).trim();
	          if (!name) throw new Error('Enter a table or QR code name.');
	          if (!/^https?:\/\//i.test(url)) throw new Error('Enter a complete http or https page URL.');
	          const background = this.val('q_background', { id: 0, url: appAssets.qrBackground || '' });
	          return WR.post('/workspace/qr-code', { name, url, size: Number(this.val('q_size', '256')), backgroundId: Number(background.id || 0) }).then(record => {
	            this.setState(s => ({ qrcodes: s.qrcodes.some(qr => qr.id === record.id) ? s.qrcodes.map(qr => qr.id === record.id ? record : qr) : s.qrcodes.concat([record]) }));
	          });
	        },
	        sections: [
	          { title: 'Table Card', description: 'Create a clear, high-contrast card guests can scan from the table.', fields: [
	            { kind: 'input', key: 'q_name', label: 'Table or placement name', def: '', placeholder: 'e.g. Table 04' },
	            { kind: 'input', key: 'q_url', label: 'Menu destination', def: (settings.links || {}).menu || '', placeholder: 'https://example.com/order-online' },
	            { kind: 'select', key: 'q_size', label: 'Download size', options: [
	              { value: '128', label: 'Compact - 900 × 1350' }, { value: '256', label: 'Standard - 1200 × 1800' }, { value: '512', label: 'Large - 1800 × 2700' }
	            ], def: '256' },
	            { kind: 'media', key: 'q_background', label: 'Card background', def: { id: 0, url: appAssets.qrBackground || '' }, span: '1 / -1' },
	            { kind: 'qrpreview', key: 'q_preview', label: 'Guest-facing preview', span: '1 / -1' }
	          ]}
	        ]
	      };
	    } else if (route === '/automations/create') {
	      formCfg = {
	        submitLabel: 'Save Automation', message: 'Automation saved', back: '/automations', group: 'settings',
	        sections: [
	          { title: 'Automation', description: 'Pick the moment and the message.', fields: [
	            { kind: 'input', key: 'a_name', label: 'Automation name', def: '', placeholder: 'e.g. Table reminder' },
	            { kind: 'select', key: 'a_trigger', label: 'Trigger', options: ['Reservation confirmed', 'Reservation upcoming', 'Reservation cancelled', 'Order placed', 'Order completed', 'Customer inactive'], def: 'Reservation confirmed' },
	            { kind: 'select', key: 'a_audience', label: 'Send to', options: ['Customer', 'Admin', 'Kitchen'], def: 'Customer' },
	            { kind: 'select', key: 'a_delay', label: 'Delay', options: ['Immediately', '1 hour before', '2 hours before', '1 day before'], def: 'Immediately' }
	          ]},
	          { title: 'Email', description: 'Subject and body sent to the recipient.', fields: [
	            { kind: 'input', key: 'a_subject', label: 'Subject', def: '', placeholder: 'Your table at {{restaurant}} is confirmed', span: '1 / -1' },
	            { kind: 'textarea', key: 'a_body', label: 'Message', def: 'Hi {{customer_name}},\n\nYour table for {{guests}} on {{date}} at {{time}} is confirmed. See you soon!', span: '1 / -1' }
	          ]}
	        ]
	      };
	    }

	    const formSections = formCfg ? formCfg.sections.map((s, i) => ({
	      title: s.title, description: s.description, fields: this.buildFields(s.fields, 'form' + i)
	    })) : [];

	    /* integrations */
	    const igDefs = [
	      { key: 'mailchimp', name: 'Mailchimp', color: '#f0b100', description: 'Push reservation and order customers into a Mailchimp audience.' },
	      { key: 'fluentcrm', name: 'FluentCRM', color: '#00B5D8', description: 'Tag contacts inside FluentCRM whenever a booking is confirmed.' },
	      { key: 'zapier', name: 'Zapier', color: '#ff6900', description: 'Trigger thousands of apps from WowRestro reservation and order events.' },
	      { key: 'pabbly', name: 'Pabbly Connect', color: '#9333e9', description: 'Automate workflows without writing a line of code.' },
	      { key: 'zohoflow', name: 'Zoho Flow', color: '#e7000b', description: 'Connect WowRestro to the Zoho suite and 800+ other apps.' },
	      { key: 'mailpoet', name: 'MailPoet', color: '#00bba7', description: 'Send newsletters to guests straight from WordPress.' }
	    ];
	    const integrations = igDefs.map(ig => {
	      const on = !!st.integrations[ig.key];
	      return {
	        name: ig.name, description: ig.description, color: ig.color, initial: ig.name.charAt(0),
	        status: on ? 'Connected' : 'Not connected', statusColor: on ? '#10b981' : '#a5a9be', ariaChecked: on ? 'true' : 'false',
	        trackBg: on ? '#2B4BFF' : '#e6e6f0', knobLeft: on ? '22px' : '2px',
	        onToggle: () => { this.setState(s => ({ integrations: Object.assign({}, s.integrations, { [ig.key]: !s.integrations[ig.key] }) })); this.toast(ig.name + (on ? ' disconnected' : ' connected')); },
	        onConfigure: () => this.toast(ig.name + ' settings opened')
	      };
	    });

	    /* shortcodes */
	    const scDefs = [
	      { name: 'Restaurant Menu', code: '[wowrestro_menu]', description: 'Show the restaurant menu with optional template, layout and location attributes.' },
	      { name: 'Reservations', code: '[wowrestro_reservations]', description: 'Show the capacity-aware table reservation form.' },
	      { name: 'Location Selector', code: '[wowrestro_location_selector]', description: 'Let customers choose a restaurant branch.' },
	      { name: 'Staff Order Panel', code: '[wowrestro_staff_panel]', description: 'Show the protected frontend order panel to WowRestro staff roles.' },
	      { name: 'Order Tracking', code: '[wowrestro_tracking]', description: 'Let customers securely check restaurant order progress.' },
	      { name: 'Legacy Menu Alias', code: '[wowrestro]', description: 'Compatibility alias for sites migrating from WOWRestro.' }
	    ];
	    const shortcodes = scDefs.map(sc => Object.assign({}, sc, {
	      onCopy: () => {
	        if (typeof navigator !== 'undefined' && navigator.clipboard) navigator.clipboard.writeText(sc.code);
	        this.toast('Copied ' + sc.code);
	      }
	    }));

	    const submitCurrentForm = () => {
	      if (!formCfg) return;
	      Promise.resolve(formCfg.onSubmit ? formCfg.onSubmit() : null).then(() => {
	        this._formDirty = false;
	        this.toast(formCfg.message);
	        this.go(formCfg.back, formCfg.group);
	      }).catch(error => this.toast(error && error.message ? error.message : 'Could not save changes'));
	    };

	    /* header actions */
	    const headerActions = [];
	    const isSettings = tab !== '';
	    if (isSettings) headerActions.push({
	      label: 'Save Changes', icon: 'ph ph-check', bg: '#2B4BFF', color: '#fff', border: '#2B4BFF',
	      onClick: () => this.saveSettings()
	    });
	    if (formCfg && formCfg.headerAction) headerActions.push({
	      label: formCfg.submitLabel, icon: 'ph ph-check', bg: '#2B4BFF', color: '#fff', border: '#2B4BFF', onClick: submitCurrentForm
	    });

	    const diagnosticData = WR.boot.diagnostics || { checks: [], environment: [] };
	    const diagnosticChecks = (diagnosticData.checks || []).map(check => ({
	      label: check.label, description: check.description,
	      icon: check.status === 'good' ? 'ph-fill ph-check-circle' : (check.status === 'critical' ? 'ph-fill ph-x-circle' : 'ph-fill ph-warning-circle'),
	      color: check.status === 'good' ? '#00a63e' : (check.status === 'critical' ? '#e7000b' : '#b7791f'),
	      bg: check.status === 'good' ? '#dcfce7' : (check.status === 'critical' ? '#fee2e2' : '#fef3c7'),
	      status: check.status === 'good' ? 'Ready' : (check.status === 'critical' ? 'Critical' : 'Needs attention')
	    }));
	    const diagnosticEnvironment = (diagnosticData.environment || []).map(row => ({ label: row.label || '', value: String(row.value || '') }));
	    const setupSteps = [
	      { title: 'Restaurant profile', description: 'Name, contact details and address used for ordering.', ready: !!this.val('rest_name', restaurantProfile.name || ''), icon: 'ph ph-storefront', onClick: () => this.go('/settings?tab=general', 'settings') },
	      { title: 'Menu items', description: st.items.length ? st.items.length + ' restaurant products are ready.' : 'Add at least one restaurant product.', ready: st.items.length > 0, icon: 'ph ph-hamburger', onClick: () => this.go('/food-menu/items', 'food-ordering') },
	      { title: 'Fulfillment services', description: (st.toggles.pickup || st.toggles.delivery) ? 'Pickup or delivery is enabled.' : 'Enable pickup or delivery.', ready: st.toggles.pickup || st.toggles.delivery, icon: 'ph ph-moped', onClick: () => this.go('/settings?tab=pickup', 'food-ordering') },
	      { title: 'Restaurant schedule', description: Object.values(st.schedule).some(Boolean) ? 'At least one service day is open.' : 'Configure kitchen service hours.', ready: Object.values(st.schedule).some(Boolean), icon: 'ph ph-calendar-blank', onClick: () => this.go('/settings?tab=schedule', 'settings') },
	      { title: 'System diagnostics', description: diagnosticChecks.every(check => check.status !== 'Critical') ? 'Required platform checks are passing.' : 'A required check needs attention.', ready: diagnosticChecks.every(check => check.status !== 'Critical'), icon: 'ph ph-stethoscope', onClick: () => this.go('/diagnostics', 'settings') }
	    ].map(step => Object.assign({}, step, {
	      status: step.ready ? 'Complete' : 'Action needed',
	      color: step.ready ? '#00a63e' : '#b7791f',
	      bg: step.ready ? '#dcfce7' : '#fef3c7',
	      statusIcon: step.ready ? 'ph-fill ph-check-circle' : 'ph-fill ph-warning-circle'
	    }));

	    const proRoutes = ['/discount', '/timed-product', '/receipt-layout', '/dine-in', '/table-layout', '/license'];
	    const isProLocked = proRoutes.indexOf(route) > -1 && !pro;

	    return {
	      siteName: WR.boot.siteName || 'WowRestro',
	      brandName: this.val('rest_name', restaurantProfile.name || WR.boot.siteName || 'Restaurant'),
	      brandLogo: (this.val('rest_logo', {}) || {}).url || restaurantProfile.logo_url || appAssets.brandLogo || appAssets.mark || '',
	      version: settings.version || '',
	      railItems, hasSecondary,
	      secondaryTitle: activeGroup.title,
	      secondaryItems,
	      pageTitle: this.titleFor(route),
	      headerActions,
	      showBack: !!formCfg,
	      goBack: () => formCfg ? this.go(formCfg.back, formCfg.group) : this.go('/', 'dashboard'),
	      goAbout: () => this.go('/about-us', st.group),

	      isDashboard: route === '/',
	      isFoodMenu: route === '/food-menu',
	      isLiveOrders: route === '/live-orders',
	      showPageHeader: route !== '/live-orders',
	      isSetupWizard: route === '/setup-wizard',
	      isDiagnostics: route === '/diagnostics',
	      isReservations: route === '/reservations',
	      isSettings: isSettings && !isProLocked,
	      isList: !!listCfg && route !== '/live-orders' && !isProLocked,
	      isForm: !!formCfg,
	      isIntegrations: route === '/integrations',
	      isShortcodes: route === '/shortcodes',
	      isAbout: route === '/about-us',
	      isProLocked,

	      bannerVisible: false && st.banner,
	      dismissBanner: () => this.setState({ banner: false }),
	      metrics, widgets,

	      menuSections: [
	        { title: 'Food Menu Items', icon: 'ph ph-hamburger', count: st.items.length + (st.items.length === 1 ? ' item' : ' items'), url: '/food-menu/items', description: 'Every supported WooCommerce product appears here with its live image, price, variation, stock and publication status.', buttonText: 'Add & Manage Products' },
	        { title: 'Food Categories', icon: 'ph ph-folders', count: st.categories.length + (st.categories.length === 1 ? ' category' : ' categories'), url: '/food-menu/categories', description: 'Organize the menu with WooCommerce product categories used by lists, tabs and shortcodes.', buttonText: 'Add & Manage Categories' },
	        { title: 'Menu Brands', icon: 'ph ph-bookmarks', count: st.brands.length + (st.brands.length === 1 ? ' brand' : ' brands'), url: '/food-menu/brands', description: 'Create and assign WooCommerce product brands without leaving WowRestro.', buttonText: 'Add & Manage Brands' },
	        { title: 'Product Labels', icon: 'ph ph-tag-chevron', count: st.labels.length + (st.labels.length === 1 ? ' label' : ' labels'), url: '/food-menu/labels', description: 'Manage dietary badges and the Veg or Non-Veg information shown with menu items.', buttonText: 'Manage Product Labels' },
	        { title: 'Nutrition & Allergens', icon: 'ph ph-heartbeat', url: '/food-menu/nutrition', description: 'Add EU-14 allergen and nutrition details to WooCommerce products from one focused list.', buttonText: 'Add Nutrition Info' }
	      ].map(s => Object.assign({}, s, { hasCount: !!s.count, count: s.count || '', onClick: () => this.go(s.url, 'food-ordering') })),
	      menuInstructions: [
	        { icon: 'ph ph-arrows-clockwise', title: 'WooCommerce stays synchronized', description: 'Products, images, prices, variations, stock and order data are read from and saved to WooCommerce automatically.', action: 'Manage products', onClick: () => this.go('/food-menu/items', 'food-ordering') },
	        { icon: 'ph ph-layout', title: 'Menus work everywhere', description: 'The same product data powers WowRestro list and tab layouts, shortcodes, Gutenberg blocks and Elementor widgets.', action: 'Menu display settings', onClick: () => this.go('/settings?tab=food-menu', 'menu-checkout') },
	        { icon: 'ph ph-map-pin-line', title: 'Multi-location ready', description: 'Assign menu products to branches and keep per-location ordering inside the WowRestro workspace.', action: 'Manage locations', onClick: () => this.go('/location', 'service-options') },
	        { icon: 'ph ph-shield-check', title: 'Customer information is clear', description: 'EU-14 allergens, nutrition, product labels and Veg or Non-Veg flags remain attached to the WooCommerce product.', action: 'Product information', onClick: () => this.go('/food-menu/nutrition', 'people-products') }
	      ],

	      reservationColumns: [
	        { title: 'Date & Time' }, { title: 'Invoice' }, { title: 'Customer Info' }, { title: 'Food Order' },
	        { title: 'Guests' }, { title: 'Payment Method' }, { title: 'Status' }, { title: 'Actions' }
	      ],
	      reservationGroups,
	      noResults: totalShown === 0,
	      paginationLabel: totalShown ? 'Showing 1 to ' + totalShown + ' of ' + totalReservations + ' reservations' : 'Showing 0 of ' + totalReservations + ' reservations',
	      reservationHasPages: totalShown > 0,
	      searchValue: st.search,
	      onSearch: (e) => this.setState({ search: e.target.value }),
	      filtersOpen: st.filtersOpen,
	      toggleFilters: () => this.setState(s => ({ filtersOpen: !s.filtersOpen })),
	      statusFilters,
	      filterApplied: !!(st.statusFilter || st.search),
	      clearFilters: () => this.setState({ statusFilter: '', search: '' }),
	      hasSelection: st.selected.length > 0,
	      selectedCount: String(st.selected.length),
	      deleteSelected: () => {
	        const ids = st.selected.slice();
	        this.askDelete('Delete selected reservations?', 'This permanently removes ' + ids.length + ' reservation' + (ids.length === 1 ? '' : 's') + ' from WowRestro.', () =>
	          ids.reduce((request, id) => request.then(() => WR.delete('/workspace/reservation/' + id)), Promise.resolve({ reservations: st.reservations })).then(latest => {
	            this.setState({ reservations: latest.reservations || [], selected: [], deletedReservations: [] });
	            this.toast('Selected reservations deleted');
	          }).catch(error => this.toast(error && error.message ? error.message : 'Could not delete reservations'))
	        );
	      },
	      goCreateReservation: () => this.go('/reservations/create', 'reservations'),

	      settingsSections,
	      settingsMaxWidth: reservationSettingsPage ? '960px' : 'none',
	      settingsHasIntro: tab === 'reservation-rules' || tab === 'customization',
	      settingsIntroTitle: settingsIntro.title,
	      settingsIntroDescription: settingsIntro.description,
	      settingsIntroLink: 'Get shortcode',
	      settingsIntroSuffix: 'to display the reservation form',
	      openReservationShortcode: () => this.go('/shortcodes', 'settings'),
	      setupSteps,
	      setupComplete: setupSteps.filter(step => step.ready).length + ' of ' + setupSteps.length + ' complete',
	      diagnosticChecks,
	      diagnosticEnvironment,

	      listSearch: st.listSearch,
	      onListSearch: (e) => this.setState({ listSearch: e.target.value }),
	      listSearchPlaceholder: listCfg ? listCfg.placeholder : '',
	      listHasAdd: listCfg ? listCfg.hasAdd : false,
	      listAddLabel: listCfg ? listCfg.addLabel : '',
	      listOnAdd: listCfg ? listCfg.onAdd : () => {},
	      listColumns: listCfg ? listCfg.columns.map(c => ({ title: c })) : [],
	      listRows: listCfg ? listCfg.rows : [],
	      listEmpty: !!listCfg && listCfg.rows.length === 0,
	      listEmptyTitle: listCfg ? listCfg.emptyTitle : '',
	      listEmptyDescription: listCfg ? listCfg.emptyDescription : '',
	      listCountLabel: listCfg ? listCfg.count : '',
	      listHasNotice: !!(listCfg && listCfg.notice),
	      listNotice: listCfg ? (listCfg.notice || '') : '',

	      formSections,
	      formMaxWidth: route === '/qr-code/create' ? 'none' : '960px',
	      formSubmitLabel: formCfg ? formCfg.submitLabel : 'Save',
	      formFooterVisible: !(formCfg && formCfg.headerAction),
	      submitForm: submitCurrentForm,

	      confirmVisible: !!st.confirm,
	      confirmTitle: st.confirm ? st.confirm.title : '',
	      confirmMessage: st.confirm ? st.confirm.message : '',
	      stopPropagation: (e) => e.stopPropagation(),
	      confirmCancel: () => this.setState({ confirm: null }),
	      confirmAccept: () => { const c = st.confirm; this.setState({ confirm: null }); if (c && c.fn) c.fn(); },

	      qrPreviewVisible: !!st.qrPreview,
	      qrPreviewName: st.qrPreview ? st.qrPreview.name : '',
	      qrPreviewUrl: st.qrPreview ? WR.qrDisplayUrl(st.qrPreview.url) : '',
	      qrPreviewCode: st.qrPreview ? WR.qrDataUrl(st.qrPreview.url, 512) : '',
	      qrPreviewBackground: st.qrPreview ? (st.qrPreview.backgroundUrl || appAssets.qrBackground || '') : '',
	      qrPreviewMark: (this.val('rest_logo', {}) || {}).url || restaurantProfile.logo_url || appAssets.brandLogo || appAssets.mark || '',
	      qrPreviewBrand: this.val('rest_name', restaurantProfile.name || WR.boot.siteName || 'Restaurant'),
	      qrPreviewClose: () => this.setState({ qrPreview: null }),

	      integrations, shortcodes,
	      aboutCards: [
	        { icon: 'ph ph-book-open', title: 'Documentation', description: 'Step by step guides for every module, from pickup slots to QR codes.' },
	        { icon: 'ph ph-lifebuoy', title: 'Support', description: 'Open a ticket and the team replies within one business day.' },
	        { icon: 'ph ph-lightbulb', title: 'Feature requests', description: 'Vote on the roadmap and tell us what your restaurant needs next.' },
	        { icon: 'ph ph-star', title: 'Leave a review', description: 'Enjoying WowRestro? A review on WordPress.org helps other restaurants find it.' }
	      ],

	      toastVisible: !!st.toast,
	      toastMessage: st.toast
	    };
	  }
	}

	/* ---------- mount ---------- */
	if (settings.initialRoute === '/analytics-native') {
		WR.open('analytics');
		return;
	}

	var app = new Component();
	WR.app = app;
	window.wowRestroAppInstance = app;
	window.addEventListener('popstate', function () {
		if (!app.canLeaveForm()) {
			window.history.forward();
			return;
		}
		app._formDirty = false;
		var params = new URL(window.location.href).searchParams;
		var route = params.get('route') || '/';
		var requestedGroup = params.get('section');
		if ( route === '/modules' ) {
			route = '/settings?tab=food-menu';
			requestedGroup = requestedGroup || 'menu-checkout';
		}
		if ( settings.canManage === false ) {
			route = '/live-orders';
			requestedGroup = 'alerts-insights';
		}
		var group = app.groups().some(function ( item ) { return item.id === requestedGroup; }) ? requestedGroup : groupForRoute(route);
		app.setState({ route: route, group: group, listSearch: '', form: {}, selected: [] });
	});
	window.addEventListener('beforeunload', function (event) {
		if (!app._formDirty) return;
		event.preventDefault();
		event.returnValue = '';
	});

	function render() {
		// A full re-render replaces the focused field, so carry focus and caret
		// across on the template key - otherwise typing in any form loses the
		// cursor after the first character.
		var live = document.activeElement;
		var focused = live && ROOT.contains( live ) ? live.dataset.wrKey : null;
		var caret = focused && VALUE_TAGS[ live.tagName ] ? [ live.selectionStart, live.selectionEnd ] : null;

		var frag = document.createDocumentFragment();
		children( TEMPLATE.content, [ app.renderVals() ], frag, '' );
		ROOT.replaceChildren( frag );
		if ( window.WowRestroInitBoard ) {
			window.WowRestroInitBoard();
		}

		if ( focused ) {
			var again = ROOT.querySelector( '[data-wr-key="' + focused + '"]' );
			if ( again ) {
				again.focus();
				if ( caret && null !== caret[ 0 ] ) {
					try {
						again.setSelectionRange( caret[ 0 ], caret[ 1 ] );
					} catch ( e ) {
						/* not a text field */
					}
				}
			}
		}
	}

	render();
	setInterval( function () { app.syncMenu(); }, 30000 );
	window.addEventListener( 'focus', function () { app.syncMenu(); } );
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			app.syncMenu();
		}
	} );

	/* Self-check: walks every route the navigation exposes and asserts the
	   directives still interpret. Runs automatically with ?wrselftest=1, and is
	   callable as window.wowRestroSelfTest() from the console. */
	function runSelfTest() {
		var fails = [];
		var check = function ( ok, message ) {
			if ( ! ok ) {
				fails.push( message );
			}
		};
		var routes = [];
		app.groups().forEach( function ( g ) {
			if ( g.url ) {
				routes.push( [ g.url, g.id ] );
			}
			( g.children || [] ).forEach( function ( c ) {
				if ( c.external ) {
					return;
				}
				routes.push( [ c.url, g.id ] );
				( c.children || [] ).forEach( function ( k ) {
					routes.push( [ k.url, g.id ] );
				} );
			} );
		} );

		var start = app.state.route;
		var group = app.state.group;
		routes.forEach( function ( pair ) {
			app.state = Object.assign( {}, app.state, { route: pair[ 0 ], group: pair[ 1 ] } );
			try {
				render();
			} catch ( e ) {
				check( false, 'threw on ' + pair[ 0 ] + ': ' + e.message );
				return;
			}
			check( ROOT.querySelectorAll( '*' ).length > 40, 'rendered empty: ' + pair[ 0 ] );
			check( -1 === ROOT.innerHTML.indexOf( '{{' ), 'unresolved interpolation on ' + pair[ 0 ] );
			check( ! ROOT.querySelector( 'sc-if, sc-for, sc-raw-table, sc-raw-td' ), 'directive leaked on ' + pair[ 0 ] );
		} );
		app.state = Object.assign( {}, app.state, { route: start, group: group } );
		render();

		var result = { routes: routes.length, failures: fails };
		if ( fails.length ) {
			window.console.error( '[wowrestro] self-test failed', fails );
		} else {
			window.console.log( '[wowrestro] self-test ok across ' + routes.length + ' routes' );
		}
		return result;
	}

	window.wowRestroSelfTest = runSelfTest;
	if ( /[?&]wrselftest=1/.test( window.location.search ) ) {
		runSelfTest();
	}
}() );
