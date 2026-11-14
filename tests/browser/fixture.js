/* Local UI fixture only: these mock REST responses do not run PHP validation. */
(function () {
	'use strict';
	const storageKey = 'wowrestro-browser-fixture-v1';
	const params = new URLSearchParams(location.search);
	const copy = value => JSON.parse(JSON.stringify(value));
	const hours = mode => Object.fromEntries(Array.from({ length: 7 }, (_, day) => [day + 1, {
		enabled: day === 6 ? 'no' : 'yes',
		periods: mode === 'pickup' ? '11:00-14:00,17:00-22:00' : '12:00-15:00,18:00-21:00'
	}]));
	const seed = {
		config: {
			settings: {
				pickup_enabled: 'yes', delivery_enabled: 'yes', asap_enabled: 'yes',
				service_hours: { pickup: hours('pickup'), delivery: hours('delivery') },
				prep_minutes: 30, slot_interval: 15, slot_capacity: 8, hold_minutes: 10,
				preorder_days: 0, delivery_lead_minutes: 0, late_grace_minutes: 0,
				date_overrides: '', holidays: '', menu_page_size: 30, menu_template: 1,
				menu_layout: 'tabs', tips_enabled: 'no', custom_tips: 'no', tips_taxable: 'no',
				tip_presets: '10,15,20', dietary_attribute: 'pa_dietary', allergen_attribute: 'pa_allergens', email_updates: 'no'
			},
			profile: { name: '', logo_id: 0, logo_url: '', email: '', phone: '', address_1: '', address_2: '', city: '', state: '', postcode: '', country: '' },
			reservations: { advance_minutes: 0, future_days: 30, min_guests: 1, max_guests: 8, seat_capacity: 30, interval: 30, default_status: 'pending', booking_amount: '0', fields: {} }
		},
		categories: [{ id: 1, name: 'Pizza', slug: 'pizza', parent: '-', items: 2, order: 1, status: 'Active' }],
		brands: [], labels: [], allergens: [],
		modifiers: [{
			id: 100, name: 'Pizza extras', type: 'Single item', typeKey: 'single', min: 0, max: 1, options: 1,
			optionRows: [{ id: 'cheese', label: 'Extra cheese', price: '1.25', default: false }],
			optionsText: 'Extra cheese | 1.25 | no | cheese', status: 'Published', editUrl: ''
		}],
		items: [{
			id: 7, name: 'Free sale pizza', description: '', category: 'Pizza', brand: '', sku: 'PZ-007',
			price: 12.5, sale: 0, regularPrice: '12.50', salePrice: '0', stock: 'Out of stock', stockStatus: 'outofstock',
			status: 'Published', postStatus: 'publish', labels: [], allergens: [], sold: 0, nutrition: false,
			imageId: 0, imageUrl: '', type: 'simple', variations: [], crossSells: [],
			modifierGroups: [], quickAddons: [], icon: 'ph ph-pizza', color: '#2B4BFF', editable: true
		}, {
			id: 8, name: 'Variable pizza', description: '', category: 'Pizza', brand: '', sku: 'PZ-008',
			price: 12.5, sale: 0, regularPrice: '', salePrice: '', stock: 'Out of stock', stockStatus: 'outofstock',
			status: 'Published', postStatus: 'publish', labels: [], allergens: [], sold: 0, nutrition: false,
			imageId: 0, imageUrl: '', type: 'variable', crossSells: [], modifierGroups: [100], quickAddons: [],
			variations: [{ id: 80, name: 'Large', sku: 'PZ-008-L', price: '12.50', sale: '0', stock: 'Out of stock', stockStatus: 'outofstock' }],
			icon: 'ph ph-pizza', color: '#2B4BFF', editable: true
		}],
		metrics: [], reservations: [], locations: [], qrcodes: [], automations: [], staffRoles: [], orders: []
	};
	if (params.has('reset')) {
		localStorage.removeItem(storageKey);
		const url = new URL(location.href);
		url.searchParams.delete('reset');
		history.replaceState({}, '', url);
	}
	let state;
	try { state = JSON.parse(localStorage.getItem(storageKey)); } catch (error) { /* Reset malformed fixture state. */ }
	if (!state) state = copy(seed);
	const persist = () => localStorage.setItem(storageKey, JSON.stringify(state));
	persist();
	const route = params.get('route') || '/settings?tab=schedule';
	const group = params.get('section') || (route.startsWith('/food-menu') ? 'food-ordering' : 'settings');
	window.wowRestroApp = {
		strict: true, restUrl: '/__fixture__', nonce: 'local-fixture', version: 'browser-fixture',
		initialRoute: route, initialGroup: group, canManage: true, canManageStaff: false,
		currency: { symbol: '£', position: 'left', decimals: 2, decimalSeparator: '.', thousandSeparator: ',' },
		assets: { mark: '/assets/images/wowrestro-logo.png', qrBackground: '/assets/images/qr-table-card-food-v1.jpg' },
		links: { menu: location.origin + '/tests/browser/' },
		data: Object.assign(copy(state), { siteName: 'Browser fixture' })
	};
	const fixture = window.wowRestroFixture = {
		storageKey,
		requests: [],
		read: () => copy(state),
		reset: () => { localStorage.removeItem(storageKey); location.reload(); },
		failNext: null
	};
	const nativeFetch = window.fetch.bind(window);
	const response = (payload, status = 200) => Promise.resolve(new Response(JSON.stringify(payload), {
		status, headers: { 'Content-Type': 'application/json' }
	}));
	const upsert = (collection, row) => {
		const index = state[collection].findIndex(item => item.id === row.id);
		if (index === -1) state[collection].push(row); else state[collection][index] = row;
		persist();
		return response(row);
	};
	window.fetch = (url, options = {}) => {
		const path = new URL(url, location.href).pathname;
		if (!path.startsWith('/__fixture__/')) return nativeFetch(url, options);
		const body = options.body ? JSON.parse(options.body) : null;
		fixture.requests.push({ path, method: options.method || 'GET', body: copy(body) });
		if (fixture.failNext) {
			const failure = fixture.failNext;
			fixture.failNext = null;
			return typeof failure === 'object'
				? response({ message: failure.message, data: failure.data || {} }, failure.status || 500)
				: response({ message: String(failure) }, 500);
		}
		if (path === '/__fixture__/workspace/menu') return response(copy(state));
		if (path === '/__fixture__/workspace/settings') {
			['settings', 'profile', 'reservations'].forEach(key => {
				if (body[key]) state.config[key] = Object.assign({}, state.config[key], body[key]);
			});
			persist();
			return response(copy(state.config));
		}
		if (path === '/__fixture__/workspace/modifier') {
			const id = Number(body.id) || Math.max(100, ...state.modifiers.map(row => row.id)) + 1;
			const rows = body.options.map((option, index) => Object.assign({}, option, { id: option.id || 'fixture_' + id + '_' + index, price: String(option.price || 0) }));
			return upsert('modifiers', {
				id, name: body.name, type: body.type === 'multiple' ? 'Multiple items' : 'Single item', typeKey: body.type,
				min: body.min, max: body.type === 'single' ? 1 : body.max || rows.length, options: rows.length,
				optionRows: rows, optionsText: '', status: body.status, editUrl: ''
			});
		}
		if (path === '/__fixture__/workspace/product') {
			const id = Number(body.id) || Math.max(8, ...state.items.map(row => row.id)) + 1;
			const previous = state.items.find(row => row.id === id) || copy(seed.items[0]);
			const stockStatus = body.stockStatus || (body.stock === 'Out of stock' ? 'outofstock' : body.stock === 'onbackorder' ? 'onbackorder' : 'instock');
			return upsert('items', Object.assign({}, previous, body, {
				id, regularPrice: String(body.price), salePrice: String(body.sale), price: Number(body.price), sale: Number(body.sale),
				stockStatus, stock: stockStatus === 'outofstock' ? 'Out of stock' : 'In stock',
				postStatus: body.postStatus || (body.status === 'Draft' ? 'draft' : 'publish'),
				status: (body.postStatus === 'draft' || body.status === 'Draft') ? 'Draft' : 'Published',
				variations: (body.variations || []).map(variation => {
					const original = previous.variations.find(row => row.id === variation.id) || {};
					return Object.assign({}, original, variation, { stock: variation.stockStatus === 'outofstock' ? 'Out of stock' : 'In stock' });
				})
			}));
		}
		return response({ message: 'Mock route not implemented: ' + path }, 501);
	};
}());
