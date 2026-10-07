/* Olive & Ember order flow - cloned from the design and driven by real
 * WooCommerce data through the Store API:
 *   menu → cart → checkout → order received, all on one page.
 *
 * Every value shown (products, images, categories, prices, currency, gateways,
 * coupons, tax, free-delivery threshold, order status) comes from WooCommerce.
 */
(function () {
  var CFG = window.WOWRESTRO || {};
  var API = CFG.storeApi;
  var root = document.querySelector('.wowrestro-menu');
  if (!API || !root || root.dataset.oe) return;
  root.dataset.oe = '1';

  // The menu template sets these; CSS does the rest of each ordering style.
  // Grouped menus show one section per category (the category nav scrolls to
  // it), and a drawer or sheet cart slides in instead of sitting beside the menu.
  var GROUPED = root.dataset.group === '1';
  var DRAWER = !!root.dataset.cart && root.dataset.cart !== 'side';

  var nonce = CFG.nonce || '';
  var cart = null;
  var products = [];
  var cats = [];
  var popular = null;

  var TODAY = CFG.today || new Date().toISOString().slice(0, 10);   // the store's date

  var S = {
    screen: 'menu',
    cat: 'all',
    query: '',
    service: 'delivery',
    slot: '',                 // '' = as soon as possible; otherwise a slot value from the plugin
    schedule: false,          // the customer picks a day and time instead
    day: '',                  // store-local day the time list shows
    daySlots: null,           // that day's open slots, null while they load
    slotsFor: '',             // mode|day the list belongs to
    quote: null,              // live /wowrestro/v1/availability response
    quoteFor: '',             // the slot that quote answers
    baseAt: '',               // earliest promise for the current mode/postcode
    statusOptIn: false,
    modal: null, sel: {}, mqty: 1,
    variants: null,        // variation id -> live price, keyed while the modal is open
    variantsFor: null,     // product id the variants belong to
    payment: '',
    coupon: '', couponMsg: '', couponOk: false,
    fields: { email: '', name: '', phone: '', address: '', city: '', pin: '', state: '' },
    note: '',
    touched: false,        // a place-order attempt shows every field's problem
    seen: {},              // fields left with something typed show theirs
    serverErrors: {},      // what WooCommerce rejected, by field, until it is edited
    busy: false, error: '',
    pendingAdd: null,      // product id being added
    pendingLine: null,     // cart line key being changed
    cartOpen: false,       // drawer/sheet cart templates
    order: null
  };

  /* ---------------- helpers ---------------- */

  var esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  };
  var el = function (tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; };
  // WooCommerce returns messages with HTML entities already applied; decode once
  // so escaping for output doesn't show &quot; to the customer.
  var decode = function (v) { var d = document.createElement('textarea'); d.innerHTML = String(v == null ? '' : v); return d.value; };

  var CUR = { symbol: '', minor: 2, decimal: '.', thousand: ',' };
  if (CFG.currency) {
    CUR.symbol = CFG.currency.symbol || '';
    CUR.minor = CFG.currency.minor != null ? CFG.currency.minor : 2;
    CUR.decimal = CFG.currency.decimal || '.';
    CUR.thousand = CFG.currency.thousand != null ? CFG.currency.thousand : ',';
  }
  function money(minor) {
    var n = parseInt(minor, 10);
    if (isNaN(n)) return '';
    var neg = n < 0; n = Math.abs(n);
    var s = String(n).padStart(CUR.minor + 1, '0');
    var whole = CUR.minor ? s.slice(0, -CUR.minor) : s;
    var frac = CUR.minor ? s.slice(-CUR.minor) : '';
    whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, CUR.thousand);
    return (neg ? '−' : '') + CUR.symbol + whole + (frac ? CUR.decimal + frac : '');
  }
  var minorOf = function (v) { var n = parseInt(v, 10); return isNaN(n) ? 0 : n; };

  function apiUrl(path) {
    var i = path.indexOf('?');
    if (i === -1) return API + path;
    var base = API + path.slice(0, i);
    return base + (base.indexOf('?') === -1 ? '?' : '&') + path.slice(i + 1);
  }
  function api(path, method, body) {
    var opts = { method: method || 'GET', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } };
    if (nonce) { opts.headers.Nonce = nonce; opts.headers['X-WC-Store-API-Nonce'] = nonce; }
    if (body) opts.body = JSON.stringify(body);
    return fetch(apiUrl(path), opts).then(function (res) {
      var fresh = res.headers.get('Nonce');
      if (fresh) nonce = fresh;
      return res.json().then(function (data) {
        if (!res.ok) {
          var err = new Error(errorText(data, res.status));
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  // WordPress reports a rejected field as "Invalid parameter(s): billing_address";
  // the reason itself ("The provided phone number is not valid") is in data.params.
  function errorText(data, status) {
    var params = data && data.data && data.data.params;
    if (params) return Object.keys(params).map(function (k) { return decode(params[k]); }).join(' ');
    return decode((data && data.message) || ('Request failed (' + status + ')'));
  }

  // Point WooCommerce's address complaints at the field they are about.
  var ERROR_FIELD = {
    invalid_email: 'email', invalid_phone: 'phone', invalid_postcode: 'pin', invalid_state: 'state',
    email: 'email', phone: 'phone', postcode: 'pin', state: 'state', city: 'city', address_1: 'address',
    first_name: 'name', last_name: 'name'
  };
  function serverFieldErrors(data) {
    var out = {};
    var details = (data && data.data && data.data.details) || {};
    Object.keys(details).forEach(function (param) {
      var d = details[param] || {};
      [d].concat(d.additional_errors || []).forEach(function (x) {
        var key = ERROR_FIELD[(x.data && x.data.key) || ''] || ERROR_FIELD[x.code];
        if (key && !out[key]) out[key] = decode(x.message);
      });
    });
    return out;
  }

  // The menu plugin exposes its own REST namespace beside the Store API.
  function pluginUrl(path) {
    var base = (CFG.pluginApi || '').replace(/\/$/, '');
    var i = path.indexOf('?');
    var route = i === -1 ? path : path.slice(0, i);
    var url = base + '/' + route;
    if (i === -1) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + path.slice(i + 1);
  }
  function pluginApi(path) {
    return fetch(pluginUrl(path), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  // Responses can land out of order (switch mode fast, or type a postcode while
  // a request is in flight); only the newest one may write to state.
  var quoteSeq = 0;
  // Keep the server's fee logic in step with the mode the customer picked.
  // WowRestro's Store API update callback stores it and answers with the cart.
  function syncMode() {
    return api('cart/extensions', 'POST', { namespace: 'wowrestro', data: { mode: S.service, postcode: S.fields.pin.trim(), requested_at: S.slot || '' } })
      .then(function (d) { cart = d; render(); })
      .catch(function () {});
  }

  function refreshQuote() {
    var seq = ++quoteSeq;
    var pin = S.fields.pin.trim();
    var asked = S.slot;
    return pluginApi('availability?mode=' + encodeURIComponent(S.service) + '&postcode=' + encodeURIComponent(pin) +
        (asked ? '&requested_at=' + encodeURIComponent(asked) : ''))
      .then(function (q) {
        if (seq !== quoteSeq) return;
        S.quote = q;
        S.quoteFor = asked;
        // Only an unrequested (ASAP) quote defines where the slot ladder starts;
        // otherwise picking 20:15 would rebuild the list around 20:15 and the
        // selection would appear to jump.
        if (!S.slot) S.baseAt = (q && q.promised_at) ? q.promised_at : '';
        render();
      })
      .catch(function () {});
  }

  // Scheduled times are the plugin's own open slots, in the store's timezone, so a
  // customer in another timezone still books the time the kitchen means.
  // Days are store-local YYYY-MM-DD strings, stepped in UTC so no clock change moves them.
  function shiftDay(ymd, n) {
    var p = ymd.split('-');
    return new Date(Date.UTC(+p[0], p[1] - 1, +p[2] + n)).toISOString().slice(0, 10);
  }
  var LAST_DAY = shiftDay(TODAY, CFG.preorderDays || 0);
  function dayOptions() {
    var out = [];
    for (var d = TODAY, i = 0; d <= LAST_DAY; d = shiftDay(d, 1), i++) {
      var p = d.split('-');
      out.push({ value: d, label: i === 0 ? 'Today' : (i === 1 ? 'Tomorrow'
        : new Date(Date.UTC(+p[0], p[1] - 1, +p[2])).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' })) });
    }
    return out;
  }

  var daySeq = 0;
  // Clears the list before the request goes out, so no one picks from the previous
  // day's times. `auto` moves past closed days, for when the picker opens rather
  // than when the customer picks a day.
  function loadDay(auto) {
    var key = S.service + '|' + S.day;
    if (S.slotsFor === key && S.daySlots) return pickSlot();
    var seq = ++daySeq;
    S.slotsFor = key;
    S.daySlots = null;
    return pluginApi('availability?mode=' + encodeURIComponent(S.service) + '&date=' + encodeURIComponent(S.day))
      .then(function (q) {
        if (seq !== daySeq) return;
        S.daySlots = (q && q.slots) || [];
        if (auto && !S.daySlots.length && S.day < LAST_DAY) {
          S.day = shiftDay(S.day, 1);
          return loadDay(true);
        }
        return pickSlot();
      })
      .catch(function () { if (seq === daySeq) { S.daySlots = []; render(); } });
  }
  // Keep the chosen time when the day still has it, otherwise take the day's first.
  function pickSlot() {
    var slots = S.daySlots || [];
    if (!slots.some(function (s) { return s.value === S.slot; })) S.slot = slots.length ? slots[0].value : '';
    render();
    return refreshQuote();
  }

  function pickWhen(later) {
    if (S.schedule === later) return;
    S.schedule = later;
    if (!later) {
      S.slot = ''; S.baseAt = '';
      render();
      return refreshQuote();
    }
    // Open on the day the kitchen can next serve.
    S.day = S.day || (S.baseAt ? S.baseAt.slice(0, 10) : TODAY);
    var times = loadDay(true);
    render();
    return times;
  }

  // A new mode starts again from as soon as possible, or from the first open time
  // when the store takes scheduled orders only.
  function restartTime() {
    S.slot = ''; S.baseAt = ''; S.day = ''; S.slotsFor = ''; S.daySlots = null;
    S.schedule = CFG.asap === false;
    if (!S.schedule) return refreshQuote();
    S.day = TODAY;
    return loadDay(true);
  }

  function quoteFeeMinor() {
    if (!S.quote || !isDelivery() || !S.quote.available) return 0; // unknown until the postcode lands
    return Math.round((S.quote.delivery_fee || 0) * Math.pow(10, CUR.minor));
  }
  function minimumMinor() {
    if (!S.quote || !isDelivery()) return 0;
    return Math.round((S.quote.minimum_order || 0) * Math.pow(10, CUR.minor));
  }
  // WowRestro charges delivery through the selected WooCommerce shipping rate
  // (plus any cart fees), so once the cart carries rates, its totals are what
  // the customer will actually pay.
  function cartHasPluginFee() {
    return !!(cart && ((cart.fees || []).length || (cart.shipping_rates || []).length));
  }
  function payableMinor() {
    // The server adds the delivery fee once the mode is synced; only fall back
    // to the quoted fee while that round-trip is still in flight.
    return grandMinor() + (cartHasPluginFee() || !isDelivery() ? 0 : quoteFeeMinor());
  }
  function belowMinimum() {
    var min = minimumMinor();
    return min > 0 && cartCount() > 0 && subtotalMinor() < min;
  }

  var img = function (p) { return p && p.images && p.images.length ? p.images[0].src : ''; };
  var bg = function (src) { return src ? ' style="background-image:url(' + esc(src) + ')"' : ''; };
  var svc = function () { return S.service; };
  var isDelivery = function () { return S.service === 'delivery'; };
  var slotLabel = function () { return isDelivery() ? 'Delivery time' : 'Pickup time'; };

  function payments() {
    var allowed = (cart && cart.payment_methods) || [];
    var list = (CFG.payments || []).filter(function (p) { return !allowed.length || allowed.indexOf(p.id) !== -1; });
    return list.length ? list : (CFG.payments || []);
  }
  // Gateways that collect card details cannot be driven from a custom form;
  // those hand off to the WooCommerce checkout instead.
  var OFFSITE = ['cod', 'bacs', 'cheque'];
  var needsWooCheckout = function (id) { return OFFSITE.indexOf(id) === -1; };

  function cartCount() { return cart ? cart.items_count : 0; }
  function canCheckout() {
    return cartCount() > 0 && !belowMinimum() && !quoteBlocked() && !(S.schedule && !S.slot);
  }
  function subtotalMinor() { return cart ? minorOf(cart.totals.total_items) : 0; }
  function grandMinor() { return cart ? minorOf(cart.totals.total_price) : 0; }
  function shippingMinor() { return cart ? minorOf(cart.totals.total_shipping) : 0; }
  function taxMinor() { return cart ? minorOf(cart.totals.total_tax) : 0; }
  function feesMinor() {
    if (!cart) return 0;
    return (cart.fees || []).reduce(function (sum, f) { return sum + minorOf(f.totals.total); }, 0);
  }
  function discountMinor() { return cart ? minorOf(cart.totals.total_discount) : 0; }
  function freeOverMinor() { return CFG.freeShippingMin ? Math.round(CFG.freeShippingMin * Math.pow(10, CUR.minor)) : 0; }

  function qtyOf(productId) {
    if (!cart) return 0;
    return cart.items.filter(function (i) { return i.id === productId; }).reduce(function (s, i) { return s + i.quantity; }, 0);
  }
  function lineFor(productId) {
    return cart ? cart.items.filter(function (i) { return i.id === productId; })[0] : null;
  }

  /* ---------------- shell ---------------- */

  // The page keeps the theme's own header, main menu and footer; the order flow
  // lives inside the content, and a bar at the foot of the screen reaches the order.
  root.innerHTML = '';
  var screen = el('div', 'oe-screen');
  var modalRoot = el('div', 'oe-modalroot');
  root.appendChild(screen);
  // The dialog lives on <body>, outside the menu, so it takes the menu's
  // colours and skin with it.
  ['style', 'data-skin', 'data-tone'].forEach(function (a) {
    if (root.hasAttribute(a)) modalRoot.setAttribute(a, root.getAttribute(a));
  });
  document.body.appendChild(modalRoot);

  // Screen changes start at the top of the order flow, not the top of the page.
  function toTop() { root.scrollIntoView(); }

  // Rebuilding a region drops focus; put it back on the control with the same id.
  function keepFocus(region, build) {
    var a = document.activeElement;
    var id = a && a.id && region.contains(a) ? a.id : '';
    build();
    var back = id && document.getElementById(id);
    if (back && back !== document.activeElement) back.focus();
  }

  /* ---------------- menu screen ---------------- */

  function visibleProducts() {
    var q = S.query.trim().toLowerCase();
    var list = products;
    if (q) {
      return list.filter(function (p) {
        var hay = (p.name + ' ' + (p.short_description || '') + ' ' + p.categories.map(function (c) { return c.name; }).join(' ')).toLowerCase();
        return hay.indexOf(q) !== -1;
      });
    }
    if (S.cat === 'all') return list;
    return list.filter(function (p) { return p.categories.some(function (c) { return c.slug === S.cat; }); });
  }

  function itemCard(p) {
    var n = qtyOf(p.id);
    var meta = p.has_options ? 'Size & extras' : (p.categories[0] ? p.categories[0].name : '');
    var isPopular = popular && popular.id === p.id;
    return '<article class="oe-card" data-card="' + p.id + '">' +
      '<div class="oe-card__img" role="img" aria-label="' + esc(p.name) + '"' + bg(img(p)) + '>' +
        (isPopular ? '<span class="oe-tag">Popular</span>' : '') +
      '</div>' +
      '<div class="oe-card__body">' +
        '<div class="oe-card__top">' +
          '<span class="oe-card__name">' + esc(p.name) + '</span>' +
          '<span class="oe-card__price">' + money(p.prices.price) + '</span>' +
        '</div>' +
        '<div class="oe-card__desc">' + esc(stripTags(p.short_description || p.description || '')) + '</div>' +
        '<div class="oe-card__foot" data-foot="' + p.id + '">' +
          '<span class="oe-card__meta">' + esc(meta) + '</span>' +
          footControl(p, n) +
        '</div>' +
      '</div>' +
    '</article>';
  }

  // One place decides what the card's action looks like, including while a
  // request is in flight - the button must answer the tap immediately.
  function footControl(p, n) {
    var busyHere = S.pendingAdd === p.id;
    if (busyHere && n === 0) {
      return '<button type="button" class="oe-add is-busy" disabled aria-live="polite">' +
        '<span class="oe-spin"></span>Adding…</button>';
    }
    if (n > 0) {
      return '<div class="oe-step' + (busyHere ? ' is-busy' : '') + '">' +
        '<button type="button" data-qty="' + p.id + '" data-delta="-1" aria-label="One fewer ' + esc(p.name) + '"' + (busyHere ? ' disabled' : '') + '>−</button>' +
        '<span aria-live="polite">' + n + '</span>' +
        '<button type="button" data-qty="' + p.id + '" data-delta="1" aria-label="One more ' + esc(p.name) + '"' + (busyHere ? ' disabled' : '') + '>+</button>' +
      '</div>';
    }
    return '<button type="button" class="oe-add" data-add="' + p.id + '" aria-label="' +
      esc((p.has_options ? 'Customise ' : 'Add ') + p.name) + '">' + (p.has_options ? 'Customise' : 'Add') + '</button>';
  }

  function stripTags(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    return (d.textContent || '').trim();
  }

  // Variable products carry their chosen attributes in `variation`; add-on
  // style plugins use `item_data`. Show whichever the line actually has.
  function lineOptions(i) {
    var parts = (i.variation || []).map(function (v) { return v.value; })
      .concat((i.item_data || []).map(function (d) { return d.value; }));
    return parts.filter(Boolean).join(' · ');
  }

  function cartLines() {
    if (!cart || !cart.items.length) return '';
    return cart.items.map(function (i) {
      i = Object.assign({}, i, { name: decode(i.name) });
      var opts = lineOptions(i);
      return '<div class="oe-line" data-line="' + esc(i.key) + '">' +
        '<div class="oe-line__img"' + bg(i.images && i.images[0] ? i.images[0].thumbnail : '') + '></div>' +
        '<div class="oe-line__body">' +
          '<div class="oe-line__top">' +
            '<span class="oe-line__name">' + esc(i.name) + '</span>' +
            '<span class="oe-line__total" data-role="lineTotal">' + money(i.totals.line_total) + '</span>' +
          '</div>' +
          '<div class="oe-line__opts">' + esc(opts || (money(i.prices.price) + ' each')) + '</div>' +
          '<div class="oe-line__ctrl">' +
            '<div class="oe-step oe-step--sm">' +
              '<button type="button" data-key="' + esc(i.key) + '" data-delta="-1" aria-label="One fewer ' + esc(i.name) + '">−</button>' +
              '<span data-role="lineQty">' + i.quantity + '</span>' +
              '<button type="button" data-key="' + esc(i.key) + '" data-delta="1" aria-label="One more ' + esc(i.name) + '">+</button>' +
            '</div>' +
            '<button type="button" class="oe-remove" data-remove="' + esc(i.key) + '" aria-label="Remove ' + esc(i.name) + '">Remove</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  // As soon as possible, or a day and time the customer picks. The order panel and
  // the checkout both use it, so the time can be changed right up to placing the order.
  function timeBlock() {
    var chip = function (value, label, on) {
      return '<button type="button" id="oe-when-' + value + '" data-when="' + value + '" aria-pressed="' + on + '"' + (on ? ' class="is-on"' : '') + '>' + label + '</button>';
    };
    var picker = '';
    if (S.schedule) {
      var slots = S.daySlots;
      picker = '<div class="oe-when">' +
        '<div class="oe-field"><label for="oe-day">Day</label><select id="oe-day" data-day>' +
          dayOptions().map(function (d) {
            return '<option value="' + d.value + '"' + (d.value === S.day ? ' selected' : '') + '>' + esc(d.label) + '</option>';
          }).join('') +
        '</select></div>' +
        '<div class="oe-field"><label for="oe-time">Time</label><select id="oe-time" data-time' + (slots && slots.length ? '' : ' disabled') + '>' +
          (!slots ? '<option>Loading…</option>'
            : (!slots.length ? '<option>No times left this day</option>'
            : slots.map(function (s) {
              return '<option value="' + esc(s.value) + '"' + (s.value === S.slot ? ' selected' : '') + '>' + esc(s.time_label || s.label) + '</option>';
            }).join(''))) +
        '</select></div>' +
      '</div>';
    }
    var note = '';
    if (S.schedule && S.daySlots && !S.daySlots.length) {
      note = '<div class="oe-slots__hint">Pick another day.</div>';
    } else if (!S.schedule && !S.baseAt) {
      // Never let the section go blank - say why there is no time yet.
      note = '<div class="oe-slots__hint">' +
        (awaitingPostcode() ? 'Add your ' + lower(FIELD.pin.label) + ' to see delivery times.'
          : (quoteBlocked() ? 'No times available right now.' : 'Loading times…')) +
      '</div>';
    } else if (S.quote && S.quote.available && S.quote.promised_label && S.quoteFor === S.slot) {
      // Only once the time on screen is the one quoted: a new pick hides the old promise until its answer lands.
      note = '<div class="oe-slots__promise">' + (S.slot ? 'Confirmed for ' : 'Promised ') + esc(S.quote.promised_label) + '</div>';
    }
    return '<div class="oe-slots">' +
      '<div class="oe-slots__label">' + slotLabel() + '</div>' +
      '<div class="oe-slots__list">' +
        (CFG.asap === false ? '' : chip('asap', 'As soon as possible', !S.schedule)) +
        chip('later', 'Schedule', S.schedule) +
      '</div>' +
      picker + note +
    '</div>';
  }

  // Waiting on a postcode is a prompt, not a failure - it must never block the
  // menu or the checkout button before the customer has typed one.
  function awaitingPostcode() {
    return !!(S.quote && S.quote.reason === 'postcode_required');
  }
  function quoteBlocked() {
    return !!(S.quote && S.quote.available === false && !awaitingPostcode());
  }
  function unavailableBlock() {
    if (awaitingPostcode()) {
      return '<div class="oe-slots__promise" style="margin:16px 24px 0">Add your postcode at checkout to confirm delivery time and fee.</div>';
    }
    if (!quoteBlocked()) return '';
    var why = {
      paused: 'The kitchen has paused new orders.',
      pickup_disabled: 'Pickup is switched off right now.',
      delivery_disabled: 'Delivery is switched off right now.',
      closed: 'The kitchen is closed at that time.',
      outside_delivery_zone: "We don't deliver to that postcode yet - try pickup.",
      capacity: 'That slot is fully booked. Pick another time.'
    }[S.quote.reason] || 'That time is not available.';
    return '<div class="oe-error" style="margin:16px 24px 0">' + esc(why) + '</div>';
  }

  function minimumBlock() {
    if (!belowMinimum()) return '';
    return '<div class="oe-error" style="margin:16px 24px 0">Delivery orders start at ' +
      money(minimumMinor()) + ' - add ' + money(minimumMinor() - subtotalMinor()) + ' more, or switch to pickup.</div>';
  }

  function freeBar() {
    var target = freeOverMinor();
    if (!target || !isDelivery() || !cartCount()) return '';
    var left = target - subtotalMinor();
    if (left <= 0) return '';
    var pct = Math.min(100, Math.round((subtotalMinor() / target) * 100));
    return '<div class="oe-freebar">' +
      '<div class="oe-freebar__text">Add ' + money(left) + ' more for free delivery.</div>' +
      '<div class="oe-freebar__track"><div class="oe-freebar__fill" style="width:' + pct + '%"></div></div>' +
    '</div>';
  }

  function totalsBlock(withTax) {
    if (!cart) return '';
    var rows = '<div><span>Subtotal</span><b data-total="subtotal">' + money(cart.totals.total_items) + '</b></div>';
    if (discountMinor()) rows += '<div><span>Discount</span><span class="is-free" data-total="discount">−' + money(discountMinor()) + '</span></div>';
    if (isDelivery()) {
      var fee = cartHasPluginFee() ? shippingMinor() + feesMinor() : quoteFeeMinor();
      // (pickup never shows a fee - see the branch below)
      rows += '<div><span>Delivery</span>' + (fee ? '<b data-total="fee">' + money(fee) + '</b>' : '<span class="is-free" data-total="fee">Free</span>') + '</div>';
    } else {
      rows += '<div><span>Pickup</span><span class="is-free" data-total="fee">Free</span></div>';
    }
    if (withTax && taxMinor()) rows += '<div><span>Taxes</span><b data-total="tax">' + money(taxMinor()) + '</b></div>';
    rows += '<div class="oe-totals__grand"><span>Total</span><span data-total="grand">' + money(payableMinor()) + '</span></div>';
    return '<div class="oe-totals">' + rows + '</div>';
  }

  // The menu screen is built once, then each region is refreshed only when its
  // own data changes. Rebuilding everything on every state change restarted
  // image loads and animations, which looked like the page reloading.
  var menuNodes = null;
  var sigs = { hero: '', list: '', panel: '', qty: '', numbers: '', bar: '' };

  function buildMenuSkeleton() {
    var panel = '<aside class="oe-panel" data-region="panel"></aside>';
    screen.innerHTML =
      '<div class="oe-shell oe-hero" data-region="hero"></div>' +
      '<div class="oe-shell oe-two">' +
        '<div data-region="list"></div>' +
        (DRAWER
          ? '<div class="oe-cartscrim" data-cart-close></div>' +
            '<div class="oe-cartwrap" id="oe-cart" role="dialog" aria-modal="true" aria-label="Your order" tabindex="-1">' + panel + '</div>'
          : panel) +
      '</div>' +
      '<div class="oe-cartbar" data-region="bar"></div>';
    menuNodes = {
      hero: screen.querySelector('[data-region="hero"]'),
      list: screen.querySelector('[data-region="list"]'),
      panel: screen.querySelector('[data-region="panel"]'),
      bar: screen.querySelector('[data-region="bar"]')
    };
    sigs = { hero: '', list: '', panel: '', qty: '', numbers: '', bar: '' };
  }

  // A bar at the foot of the screen once the order has items: it opens the drawer
  // or sheet, and on side-panel templates, whose order drops below the menu on
  // narrow screens, it goes straight to checkout.
  function barHtml() {
    if (!cartCount()) return '';
    return '<button type="button" class="oe-cartbar__btn" ' +
      (DRAWER ? 'data-cart-open aria-controls="oe-cart" aria-expanded="' + S.cartOpen + '"' : 'data-go="checkout"') + '>' +
      '<span class="oe-cartbar__count">' + cartCount() + '</span>' +
      '<span class="oe-cartbar__label">' + (DRAWER ? 'View your order' : 'Checkout') + '</span>' +
      '<span class="oe-cartbar__total">' + money(payableMinor()) + '</span>' +
    '</button>';
  }

  // Delivery or pickup: in the hero, and again at checkout so it can still change there.
  function serviceToggle() {
    var btn = function (mode, label) {
      var on = S.service === mode;
      return '<button type="button" id="oe-svc-' + mode + '" data-svc="' + mode + '" aria-pressed="' + on + '"' + (on ? ' class="is-on"' : '') + '>' + label + '</button>';
    };
    return '<div class="oe-toggle" role="group" aria-label="Delivery or pickup">' + btn('delivery', 'Delivery') + btn('pickup', 'Pickup') + '</div>';
  }

  function heroHtml() {
    return '<div>' +
        '<div class="oe-badge"><i></i>' + esc(CFG.storeName) + '</div>' +
        '<h2 class="oe-hero__title">Order direct<br><em>from our kitchen</em></h2>' +
        (CFG.tagline ? '<p class="oe-hero__lede">' + esc(CFG.tagline) + '</p>' : '') +
        '<div class="oe-hero__row">' +
          serviceToggle() +
          (CFG.address ? '<div class="oe-eta">' + (isDelivery() ? 'Delivering from ' : 'Collect from ') + esc(CFG.address) + '</div>' : '') +
        '</div>' +
      '</div>' +
      '<div class="oe-heroart"' + bg(img(popular)) + '>' +
        (popular ? '<div class="oe-heroart__cap">' +
          '<div><div class="oe-eyebrow">Most ordered</div><div class="oe-heroart__name">' + esc(popular.name) + '</div></div>' +
          '<div class="oe-heroart__price">' + money(popular.prices.price) + '</div>' +
        '</div>' : '') +
      '</div>';
  }

  function listHtml() {
    var list = visibleProducts();
    var q = S.query.trim();
    var title = q ? 'Search results' : (S.cat === 'all' ? 'Everything we make' : (catName(S.cat) || 'Menu'));
    return '<div class="oe-listhead">' +
        '<div><div class="oe-eyebrow">The menu</div><h2>' + esc(title) + '</h2></div>' +
        '<input type="text" class="oe-search" id="oe-search" placeholder="Search the menu" value="' + esc(S.query) + '">' +
      '</div>' +
      '<div class="oe-cats">' +
        '<button type="button" data-cat="all"' + (S.cat === 'all' && !q ? ' class="is-on"' : '') + '><span>All</span><small>' + products.length + '</small></button>' +
        cats.map(function (c) {
          // The category photo only shows where the template draws categories as tiles.
          var photo = c.image ? (c.image.thumbnail || c.image.src) : '';
          return '<button type="button" data-cat="' + esc(c.slug) + '"' + (S.cat === c.slug && !q ? ' class="is-on"' : '') +
            (photo ? ' style="--cat-img:url(' + esc(photo) + ')"' : '') + '>' +
            '<span>' + esc(c.name) + '</span><small>' + c.count + '</small></button>';
        }).join('') +
      '</div>' +
      (list.length
        ? (GROUPED ? '<div class="oe-groups">' + groupsHtml(list) + '</div>' : '<div class="oe-grid">' + list.map(itemCard).join('') + '</div>')
        : '<div class="oe-empty"><div class="oe-empty__title">Nothing on the menu matches that.</div>' +
          '<button type="button" data-clear>Show the full menu</button></div>') +
      '<div class="oe-note">' +
        '<div>Allergen and dietary information is available on request - ask the kitchen before ordering.</div>' +
        '<span class="oe-note__by">Powered by WowRestro</span>' +
      '</div>';
  }

  // One section per category, in the store's category order; a dish in two
  // categories shows in both, as it does when filtering.
  function groupsHtml(list) {
    var placed = {};
    var html = cats.map(function (c) {
      var items = list.filter(function (p) { return p.categories.some(function (x) { return x.slug === c.slug; }); });
      items.forEach(function (p) { placed[p.id] = true; });
      return items.length ? sectionHtml(c.slug, c.name, items) : '';
    }).join('');
    var rest = list.filter(function (p) { return !placed[p.id]; });
    return html + (rest.length ? sectionHtml('more', 'More', rest) : '');
  }

  function sectionHtml(slug, name, items) {
    // Spotlight templates feature a section's first dish, so lead with one that has a photo.
    var lead = root.dataset.layout === 'spotlight' ? items.findIndex(function (p) { return img(p); }) : -1;
    if (lead > 0) items = [items[lead]].concat(items.slice(0, lead), items.slice(lead + 1));
    return '<section class="oe-sec" id="oe-sec-' + esc(slug) + '" data-sec="' + esc(slug) + '">' +
      '<div class="oe-sec__head"><h3 class="oe-sec__title">' + esc(name) + '</h3><span class="oe-sec__count">' + items.length + '</span></div>' +
      '<div class="oe-grid">' + items.map(itemCard).join('') + '</div>' +
    '</section>';
  }

  function panelHtml() {
    return '<div class="oe-panel__head">' +
        '<span class="oe-panel__title">Your order</span>' +
        '<span class="oe-panel__svc">' + (isDelivery() ? 'Delivery' : 'Pickup') + '</span>' +
        (DRAWER ? '<button type="button" class="oe-cartclose" data-cart-close aria-label="Close your order">×</button>' : '') +
      '</div>' +
      (S.error ? '<div class="oe-error" style="margin:16px 24px 0">' + esc(S.error) + '</div>' : '') +
      (cartCount()
        ? '<div class="oe-panel__scroll">' +
            '<div class="oe-lines">' + cartLines() + '</div>' +
            timeBlock() + unavailableBlock() + minimumBlock() + freeBar() + totalsBlock(false) +
          '</div>' +
          '<div class="oe-panel__pay">' +
            '<button type="button" class="oe-cta" data-go="checkout"' + (canCheckout() ? '' : ' disabled') + '>' +
              (belowMinimum() ? 'Minimum ' + money(minimumMinor()) + ' for delivery' : 'Checkout · ' + money(payableMinor())) + '</button>' +
            '<div class="oe-fineprint">Secure WooCommerce checkout · your gateways, your customer data</div>' +
          '</div>'
        : '<div class="oe-panel__empty">' +
            '<div class="oe-empty__art">' +
              '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true">' +
                // takeaway bag: body, folded top, handle, steam
                '<path class="oe-art-bag" d="M14 24h36l-3.2 30.5a4 4 0 0 1-4 3.5H21.2a4 4 0 0 1-4-3.5L14 24Z"/>' +
                '<path class="oe-art-line" d="M14 24h36"/>' +
                '<path class="oe-art-line" d="M24 24v-5a8 8 0 0 1 16 0v5"/>' +
                '<path class="oe-art-steam" d="M27 9c0-2.5 3-2.5 3-5"/>' +
                '<path class="oe-art-steam" d="M34 9c0-2.5 3-2.5 3-5"/>' +
              '</svg>' +
            '</div>' +
            '<div class="oe-empty__title">Your order is empty</div>' +
            "<div>Pick something from the menu and it'll appear here.</div>" +
          '</div>');
  }

  function timeSig() { return [S.schedule, S.day, S.daySlots ? S.daySlots.length : -1].join(','); }

  function cartSignature() {
    if (!cart) return 'none';
    return cart.items.map(function (i) { return i.key + ':' + i.quantity + ':' + i.totals.line_total; }).join('|') +
      '#' + cart.totals.total_price + '#' + (cart.fees || []).length;
  }

  function renderMenu() {
    if (!menuNodes || !screen.querySelector('[data-region="list"]')) buildMenuSkeleton();

    var quoteSig = S.quote ? [S.quote.available, S.quote.reason, S.quote.promised_at, S.quote.delivery_fee, S.quote.minimum_order].join(',') : '';
    var heroSig = [S.service, popular && popular.id, CFG.storeName].join('|');
    var listSig = [S.query, S.cat, products.length, cats.length, popular && popular.id].join('|');
    var structureSig = [
      cart ? cart.items.map(function (i) { return i.key; }).join(',') : 'none',
      S.service, S.slot, timeSig(), quoteSig, S.error, belowMinimum(), canCheckout(),
      !!discountMinor(), !!taxMinor(), cartHasPluginFee()
    ].join('|');
    var numbersSig = cartSignature();

    if (heroSig !== sigs.hero) {
      keepFocus(menuNodes.hero, function () { menuNodes.hero.innerHTML = heroHtml(); });
      sigs.hero = heroSig;
    }

    if (listSig !== sigs.list) {
      var search = document.getElementById('oe-search');
      var hadFocus = search && document.activeElement === search;
      var caret = hadFocus ? search.selectionStart : null;
      menuNodes.list.innerHTML = listHtml();
      sigs.list = listSig;
      if (hadFocus) {
        var again = document.getElementById('oe-search');
        if (again) { again.focus(); again.setSelectionRange(caret, caret); }
      }
      spied = '';
      spySections();
    }

    if (menuNodes.bar) {
      var bar = barHtml();
      if (bar !== sigs.bar) { menuNodes.bar.innerHTML = bar; sigs.bar = bar; }
    }

    if (structureSig !== sigs.panel) {
      keepFocus(menuNodes.panel, function () { menuNodes.panel.innerHTML = panelHtml(); });
      sigs.panel = structureSig;
      sigs.numbers = numbersSig;
    } else if (numbersSig !== sigs.numbers) {
      // Same lines, different quantities: rewrite only the numbers so the panel
      // never blinks.
      patchPanelNumbers();
      sigs.numbers = numbersSig;
    }

    // Quantities change often; patch just those footers so the grid - and its
    // images and hover transitions - are never torn down.
    patchCardFooters();
  }

  function markLineBusy(key) {
    if (!menuNodes) return;
    var row = menuNodes.panel.querySelector('[data-line="' + key.replace(/"/g, '\\"') + '"]');
    if (row) row.classList.add('is-busy');
  }

  function patchPanelNumbers() {
    if (!menuNodes || !cart) return;
    cart.items.forEach(function (i) {
      var row = menuNodes.panel.querySelector('[data-line="' + i.key.replace(/"/g, '\\"') + '"]');
      if (!row) return;
      row.classList.toggle('is-busy', S.pendingLine === i.key);   // the change landed; the row takes taps again
      var qty = row.querySelector('[data-role="lineQty"]');
      var tot = row.querySelector('[data-role="lineTotal"]');
      if (qty && qty.textContent !== String(i.quantity)) qty.textContent = i.quantity;
      var t = money(i.totals.line_total);
      if (tot && tot.textContent !== t) tot.textContent = t;
    });
    // Totals are text-patched by role; a changed row set is a structure change
    // and is handled by the branch above instead.
    var set = function (role, value) {
      var n = menuNodes.panel.querySelector('[data-total="' + role + '"]');
      if (n && n.textContent !== value) n.textContent = value;
    };
    set('subtotal', money(cart.totals.total_items));
    set('grand', money(payableMinor()));
    if (discountMinor()) set('discount', '−' + money(discountMinor()));
    if (isDelivery()) {
      var fee = cartHasPluginFee() ? shippingMinor() + feesMinor() : quoteFeeMinor();
      set('fee', fee ? money(fee) : 'Free');
    }
    var cta = menuNodes.panel.querySelector('.oe-cta');
    if (cta) {
      var label = belowMinimum() ? 'Minimum ' + money(minimumMinor()) + ' for delivery' : 'Checkout · ' + money(payableMinor());
      if (cta.textContent !== label) cta.textContent = label;
    }
  }

  function patchCardFooters() {
    if (!menuNodes) return;
    var sig = cartSignature() + '|' + S.pendingAdd + '|' + S.pendingLine;
    if (sig === sigs.qty) return;
    sigs.qty = sig;
    products.forEach(function (p) {
      var feet = menuNodes.list.querySelectorAll('[data-foot="' + p.id + '"]');   // grouped menus can show a dish twice
      if (!feet.length) return;
      var n = qtyOf(p.id);
      var meta = p.has_options ? 'Size & extras' : (p.categories[0] ? p.categories[0].name : '');
      var next = '<span class="oe-card__meta">' + esc(meta) + '</span>' + footControl(p, n);
      feet.forEach(function (foot) { if (foot.innerHTML !== next) foot.innerHTML = next; });
    });
  }

  /* ---------------- template behaviours ---------------- */

  // Grouped menus: the category nav jumps to a section and follows the scroll.
  function markCat(slug) {
    if (!menuNodes) return;
    menuNodes.list.querySelectorAll('.oe-cats [data-cat]').forEach(function (b) {
      var on = b.dataset.cat === slug;
      b.classList.toggle('is-on', on);
      if (on) {
        b.setAttribute('aria-current', 'true');
        // Keep the active chip in view on a sideways-scrolling nav, without
        // touching the page scroll.
        var nav = b.parentNode;
        if (nav.scrollWidth > nav.clientWidth) {
          nav.scrollLeft += b.getBoundingClientRect().left - nav.getBoundingClientRect().left - (nav.clientWidth - b.offsetWidth) / 2;
        }
      } else {
        b.removeAttribute('aria-current');
      }
    });
  }

  function jumpTo(slug) {
    if (S.query) { S.query = ''; renderMenu(); }
    var target = slug === 'all' ? menuNodes.list : document.getElementById('oe-sec-' + slug);
    if (!target) return;
    spied = slug;
    markCat(slug);
    target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  }

  // A section is current once its top passes just under the sticky nav; at the
  // very bottom the last one is, since a short last section may never get there.
  var spied = '';
  function spySections() {
    if (!GROUPED || !menuNodes || S.screen !== 'menu') return;
    var secs = menuNodes.list.querySelectorAll('.oe-sec');
    var line = 160;   // the sticky category nav, plus the admin bar when there is one
    var at = 'all';
    secs.forEach(function (sec) { if (sec.getBoundingClientRect().top <= line) at = sec.dataset.sec; });
    if (secs.length && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) at = secs[secs.length - 1].dataset.sec;
    if (at !== spied) { spied = at; markCat(at); }
  }
  var spyQueued = false;
  if (GROUPED) {
    window.addEventListener('scroll', function () {
      if (spyQueued) return;
      spyQueued = true;
      requestAnimationFrame(function () { spyQueued = false; spySections(); });
    }, { passive: true });
  }

  // Drawer and sheet templates. The page stops scrolling behind an open cart or
  // dialog, and focus goes into the cart and back to whatever opened it.
  var cartOpener = null;
  function lockScroll() { document.body.style.overflow = (S.modal || S.cartOpen) ? 'hidden' : ''; }
  function setCartOpen(open) {
    if (!DRAWER || S.cartOpen === open) return;
    S.cartOpen = open;
    root.classList.toggle('is-cart-open', open);
    root.querySelectorAll('[data-cart-open]').forEach(function (b) { b.setAttribute('aria-expanded', String(open)); });
    lockScroll();
    var wrap = document.getElementById('oe-cart');
    if (open) {
      cartOpener = document.activeElement;
      if (wrap) wrap.focus();
    } else if (cartOpener && document.contains(cartOpener)) {
      cartOpener.focus();
    }
    if (!open) cartOpener = null;
  }

  function trapCartFocus(e) {
    var wrap = document.getElementById('oe-cart');
    var f = wrap ? [].filter.call(wrap.querySelectorAll('button,a[href],input,select,textarea'), function (n) { return !n.disabled && n.offsetParent; }) : [];
    if (!f.length) { e.preventDefault(); return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && (document.activeElement === first || document.activeElement === wrap)) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function catName(slug) {
    var c = cats.filter(function (x) { return x.slug === slug; })[0];
    return c ? c.name : '';
  }

  /* ---------------- checkout screen ---------------- */

  // What each detail asks for. The state and postcode labels follow the store's
  // country the way WooCommerce words them.
  var LABELS = CFG.labels || {};
  var FIELD = {
    email: { label: 'Email address', type: 'email', ac: 'email', wide: true },
    name: { label: 'Full name', ac: 'name' },
    phone: { label: 'Phone', type: 'tel', ac: 'tel' },
    address: { label: 'Street address', ac: 'street-address', wide: true },
    city: { label: 'City', ac: 'address-level2' },
    state: { label: LABELS.state || 'State', ac: 'address-level1' },
    pin: { label: LABELS.pin || 'Postcode', ac: 'postal-code' }
  };
  var DETAILS = ['email', 'name', 'phone', 'address', 'city', 'state', 'pin'];
  var ADDRESS = ['address', 'city', 'state', 'pin'];
  // "ZIP Code" keeps its capitals; "Postcode" reads "postcode" mid-sentence.
  function lower(s) { return /^[A-Z][a-z]/.test(s) ? s.charAt(0).toLowerCase() + s.slice(1) : s; }
  var EMAIL = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
  var PHONE = /^[\s#0-9_\-+\/().]+$/;   // the characters WooCommerce accepts in a phone number

  // One rule per detail, shared by the inline messages and the place-order check,
  // so a bad email or phone is caught before WooCommerce rejects the order.
  function fieldError(key) {
    if (ADDRESS.indexOf(key) !== -1 && !isDelivery()) return '';
    if (key === 'state' && !Object.keys(CFG.states || {}).length) return '';
    if (S.serverErrors[key]) return S.serverErrors[key];
    var v = String(S.fields[key] || '').trim();
    if (!v) {
      return {
        email: 'Enter your email address.', name: 'Enter your name.', phone: 'Enter your phone number.',
        address: 'Enter your street address.', city: 'Enter your city.',
        state: 'Choose your ' + lower(FIELD.state.label) + '.', pin: 'Enter your ' + lower(FIELD.pin.label) + '.'
      }[key];
    }
    if (key === 'email' && !EMAIL.test(v)) return 'Enter a valid email address, like name@example.com.';
    if (key === 'phone' && (!PHONE.test(v) || v.replace(/\D/g, '').length < 6)) return 'Enter a valid phone number.';
    return '';
  }
  // A problem shows once the customer leaves the field with something typed, or tries to place the order.
  function shownError(key) { return S.touched || S.seen[key] ? fieldError(key) : ''; }
  function detailsValid() { return DETAILS.every(function (k) { return !fieldError(k); }); }

  function fieldShell(key, control, err) {
    return '<div class="oe-field' + (FIELD[key].wide ? ' oe-field--wide' : '') + (err ? ' is-missing' : '') + '">' +
      '<label for="oe-f-' + key + '">' + esc(FIELD[key].label) + '</label>' + control +
      '<div class="oe-field__msg" id="oe-f-' + key + '-msg">' + esc(err) + '</div>' +
    '</div>';
  }
  function controlAttrs(key, err) {
    return ' id="oe-f-' + key + '" data-field="' + key + '" autocomplete="' + FIELD[key].ac + '" aria-describedby="oe-f-' + key + '-msg"' +
      (err ? ' aria-invalid="true"' : '');
  }
  function field(key) {
    var err = shownError(key);
    return fieldShell(key, '<input type="' + (FIELD[key].type || 'text') + '"' + controlAttrs(key, err) + ' value="' + esc(S.fields[key]) + '">', err);
  }
  function stateField() {
    var states = CFG.states || {};
    var keys = Object.keys(states);
    if (!keys.length) return '';
    var err = shownError('state');
    return fieldShell('state', '<select' + controlAttrs('state', err) + '>' +
      '<option value="">Choose…</option>' +
      keys.map(function (k) {
        return '<option value="' + esc(k) + '"' + (S.fields.state === k ? ' selected' : '') + '>' + esc(states[k]) + '</option>';
      }).join('') +
    '</select>', err);
  }

  // Updates one field's message in place, so typing never rebuilds the form under the caret.
  function patchField(key) {
    var control = document.getElementById('oe-f-' + key);
    if (!control) return;
    var err = shownError(key);
    control.closest('.oe-field').classList.toggle('is-missing', !!err);
    if (err) control.setAttribute('aria-invalid', 'true'); else control.removeAttribute('aria-invalid');
    var msg = document.getElementById('oe-f-' + key + '-msg');
    if (msg && msg.textContent !== err) msg.textContent = err;
  }
  function focusFirstInvalid() {
    var key = DETAILS.filter(function (k) { return fieldError(k); })[0];
    var control = key && document.getElementById('oe-f-' + key);
    if (control) control.focus();
  }

  // The button stays pressable while details are missing: pressing it points at them.
  function placeLabel() {
    return S.busy ? 'Placing…'
      : (!cartCount() ? 'Your order is empty'
      : (belowMinimum() ? 'Minimum ' + money(minimumMinor()) + ' for delivery'
      : (S.schedule && !S.slot ? 'Choose a time'
      : (quoteBlocked() ? (S.slot ? 'Pick another time' : (isDelivery() ? 'Not available for that address' : 'Not available right now'))
      : 'Place order · ' + money(payableMinor())))));
  }

  var checkoutSig = '';
  function renderCheckout(force) {
    var pays = payments();
    if (!S.payment && pays.length) S.payment = pays[0].id;
    var sig = [cartSignature(), S.service, S.slot, timeSig(), S.payment, S.busy, S.error, S.touched,
      Object.keys(S.serverErrors).join(','),
      S.quote ? [S.quote.available, S.quote.reason, S.quote.promised_at].join(',') : '',
      S.couponMsg, S.couponOk].join('|');
    // Skip the rebuild when nothing visible changed, so typing never wipes the form.
    if (!force && sig === checkoutSig && screen.querySelector('[data-place]')) return;
    checkoutSig = sig;

    var active = document.activeElement;
    var activeId = active && active.id && screen.contains(active) ? active.id : '';
    var caret = activeId && typeof active.selectionStart === 'number' ? active.selectionStart : null;

    screen.innerHTML =
      '<div class="oe-shell oe-checkout">' +
        '<div class="oe-checkout__head">' +
          '<div>' +
            '<button type="button" class="oe-back" data-go="menu">← Back to the menu</button>' +
            '<h2 class="oe-h1">Checkout</h2>' +
          '</div>' +
        '</div>' +
        '<div class="oe-two" style="padding:0">' +
          '<div class="oe-sections">' +
            '<section class="oe-section">' +
              '<div class="oe-section__head"><span class="oe-section__num">1</span><span class="oe-section__title">Contact</span></div>' +
              '<div class="oe-fields">' + field('email') + field('name') + field('phone') + '</div>' +
            '</section>' +
            '<section class="oe-section">' +
              '<div class="oe-section__head"><span class="oe-section__num">2</span><span class="oe-section__title">Delivery or pickup</span>' +
                serviceToggle() + '</div>' +
              (isDelivery() ? '<div class="oe-fields">' + field('address') + field('city') + stateField() + field('pin') + '</div>' : '') +
              timeBlock() +
            '</section>' +
            '<section class="oe-section">' +
              '<div class="oe-section__head"><span class="oe-section__num">3</span><span class="oe-section__title">Payment</span></div>' +
              (pays.length
                ? '<div class="oe-pays">' + pays.map(function (p) {
                    return '<button type="button" class="oe-pay' + (S.payment === p.id ? ' is-on' : '') + '" data-pay="' + esc(p.id) + '">' +
                      '<span class="oe-pay__mark"></span>' +
                      '<span style="flex:1;min-width:0">' +
                        '<span class="oe-pay__name">' + esc(p.title) + '</span>' +
                        (p.description ? '<span class="oe-pay__desc">' + esc(p.description) + '</span>' : '') +
                      '</span>' +
                    '</button>';
                  }).join('') + '</div>'
                : '<div class="oe-error">This store has no payment method enabled.</div>') +
              '<div style="margin-top:22px">' +
                '<label class="oe-slots__label" for="oe-note">Notes for the kitchen</label>' +
                '<div class="oe-field"><textarea rows="2" id="oe-note" data-note placeholder="Allergies, spice level, doorbell instructions…">' + esc(S.note) + '</textarea></div>' +
              '</div>' +
              // The plugin registers this as a real checkout field; offer it
              // rather than silently sending "no".
              '<label class="oe-checkbox">' +
                '<input type="checkbox" data-optin' + (S.statusOptIn ? ' checked' : '') + '>' +
                '<span>Email me status updates for this order</span>' +
              '</label>' +
            '</section>' +
          '</div>' +

          '<aside class="oe-panel">' +
            '<div class="oe-panel__head"><span class="oe-panel__title">Order summary</span></div>' +
            '<div class="oe-lines">' + cartLines() + '</div>' +
            '<div class="oe-coupon">' +
              '<div class="oe-coupon__row">' +
                '<input type="text" id="oe-coupon" data-coupon placeholder="Coupon code" aria-label="Coupon code" value="' + esc(S.coupon) + '">' +
                '<button type="button" data-applycoupon>Apply</button>' +
              '</div>' +
              (S.couponMsg ? '<div class="oe-coupon__msg ' + (S.couponOk ? 'is-ok' : 'is-bad') + '">' + esc(S.couponMsg) + '</div>' : '') +
            '</div>' +
            totalsBlock(true) +
            '<div class="oe-panel__pay">' +
              (S.error ? '<div class="oe-error" role="alert">' + esc(S.error) + '</div>' : '') +
              (belowMinimum() ? '<div class="oe-error">Delivery orders start at ' + money(minimumMinor()) + '.</div>' : '') +
              unavailableBlock().replace(' style="margin:16px 24px 0"', '') +
              '<button type="button" class="oe-cta" data-place' + (canCheckout() && !S.busy ? '' : ' disabled') + '>' + placeLabel() + '</button>' +
              '<div class="oe-fineprint">Your order goes straight into WooCommerce - real order, real confirmation email.</div>' +
            '</div>' +
          '</aside>' +
        '</div>' +
      '</div>';

    if (activeId) {
      var back = document.getElementById(activeId);
      if (back) {
        back.focus();
        if (caret != null && back.setSelectionRange) { try { back.setSelectionRange(caret, caret); } catch (e) {} }
      }
    }
  }

  /* ---------------- order received ---------------- */

  // The plugin's own order statuses, in the order it transitions them.
  var STAGES = [
    { keys: ['wr-new', 'pending', 'processing', 'on-hold'], title: 'Order received', now: 'Sent to the kitchen just now.', done: 'Confirmed.', wait: 'Waiting to be sent.' },
    { keys: ['wr-accepted'], title: 'Accepted', now: 'The kitchen accepted your order.', done: 'Accepted.', wait: 'Waiting on the kitchen.' },
    { keys: ['wr-preparing'], title: 'Preparing', now: 'Your food is being cooked now.', done: 'Cooked.', wait: 'Not started yet.' },
    { keys: ['wr-ready'], title: 'Ready', pickupTitle: 'Ready for collection', now: 'Ready now.', done: 'Ready.', wait: 'Not ready yet.' },
    { keys: ['wr-out-for-delivery'], title: 'Out for delivery', pickupTitle: 'Collected', now: 'On its way to you.', done: 'Handed to the rider.', wait: 'Not dispatched yet.' },
    { keys: ['completed'], title: 'Delivered', pickupTitle: 'Collected', now: 'Delivered - enjoy.', done: 'Delivered.', wait: 'Not delivered yet.' }
  ];

  function trackOrder() {
    var o = S.order;
    if (!o || !o.key) return;
    pluginApi('tracking/' + encodeURIComponent(o.id) + '?key=' + encodeURIComponent(o.key)).then(function (t) {
      if (!t || !t.status) return;
      S.order.status = t.status;
      S.order.statusLabel = t.label || '';
      if (S.screen === 'done') render();
    }).catch(function () {});
  }

  function renderDone() {
    var o = S.order;
    var at = 0;
    STAGES.forEach(function (s, i) { if (s.keys.indexOf(o.status) !== -1) at = i; });
    var f = S.fields;
    var first = f.name.trim().split(/\s+/)[0] || 'there';

    screen.innerHTML =
      '<div class="oe-shell oe-done">' +
        '<div style="text-align:center">' +
          '<div class="oe-done__tick">✓</div>' +
          '<div class="oe-done__kicker">Order #' + esc(o.id) + ' confirmed</div>' +
          '<h2 class="oe-done__title">Thank you, ' + esc(first) + '.<br><em>The kitchen has it.</em></h2>' +
          '<p class="oe-done__lede">' +
            "We've sent your confirmation to " + esc(f.email.trim()) + '. ' +
            (isDelivery()
              ? 'Your food is on its way to ' + esc(f.address.trim() || 'your address') + '.'
              : 'Collect from ' + esc(CFG.address || 'the kitchen') + ' when you\'re ready.') +
          '</p>' +
        '</div>' +

        '<div class="oe-meta">' +
          [{ label: 'Order', value: '#' + o.id },
           { label: 'Placed', value: o.date },
           { label: 'Total', value: o.total },
           { label: 'Payment', value: o.payment }].map(function (m) {
            return '<div class="oe-meta__cell"><div class="oe-meta__label">' + esc(m.label) + '</div>' +
              '<div class="oe-meta__value">' + esc(m.value) + '</div></div>';
          }).join('') +
        '</div>' +

        '<div class="oe-tracker">' +
          '<div class="oe-tracker__head">' +
            '<span class="oe-tracker__title">' + (isDelivery() ? 'Tracking your delivery' : 'Your collection') + '</span>' +
            '<span class="oe-tracker__eta">' + esc(o.statusLabel || o.promised || '') + '</span>' +
          '</div>' +
          STAGES.map(function (s, i) {
            var done = i < at, now = i === at, last = i === STAGES.length - 1;
            var title = (!isDelivery() && s.pickupTitle) ? s.pickupTitle : s.title;
            return '<div class="oe-tl' + (done ? ' is-done' : '') + (now ? ' is-now' : '') + '">' +
              '<div class="oe-tl__rail"><span class="oe-tl__dot"></span>' + (last ? '' : '<span class="oe-tl__line"></span>') + '</div>' +
              '<div style="padding-bottom:' + (last ? '0' : '22px') + '">' +
                '<div class="oe-tl__title">' + esc(title) + '</div>' +
                '<div class="oe-tl__body">' + esc(now ? s.now : (done ? s.done : s.wait)) + '</div>' +
              '</div>' +
            '</div>';
          }).join('') +
        '</div>' +

        '<div class="oe-done__cols">' +
          '<div class="oe-recap">' +
            '<div class="oe-recap__head">Order details</div>' +
            '<div class="oe-recap__lines">' + o.lines.map(function (l) {
              return '<div class="oe-recap__line">' +
                '<div class="oe-recap__img"' + bg(l.img) + '></div>' +
                '<div style="flex:1;min-width:0">' +
                  '<div class="oe-recap__name">' + esc(l.qty + ' × ' + l.name) + '</div>' +
                  '<div class="oe-recap__sub">' + esc(l.sub) + '</div>' +
                '</div>' +
                '<div class="oe-recap__price">' + esc(l.total) + '</div>' +
              '</div>';
            }).join('') + '</div>' +
            '<div class="oe-recap__rows">' +
              o.rows.map(function (r) { return '<div><span>' + esc(r.label) + '</span><b>' + esc(r.value) + '</b></div>'; }).join('') +
              '<div class="oe-totals__grand" style="display:flex;justify-content:space-between">' +
                '<span style="font-family:Newsreader,Georgia,serif;font-size:20px">Total paid</span>' +
                '<span style="font-family:Newsreader,Georgia,serif;font-size:28px">' + esc(o.total) + '</span>' +
              '</div>' +
            '</div>' +
          '</div>' +
          '<div class="oe-side">' +
            '<div class="oe-addr">' +
              '<div class="oe-addr__label">' + (isDelivery() ? 'Delivering to' : 'Collection from') + '</div>' +
              '<div class="oe-addr__name">' + esc(isDelivery() ? f.name : CFG.storeName) + '</div>' +
              '<div class="oe-addr__lines">' + esc(isDelivery() ? [f.address, f.city, f.pin].filter(Boolean).join(', ') : (CFG.address || '')) + '</div>' +
              '<div class="oe-addr__lines" style="margin-top:10px">' + esc([f.phone, f.email].filter(Boolean).join(' · ')) + '</div>' +
            '</div>' +
            '<div class="oe-help">' +
              '<div class="oe-help__title">Something wrong?</div>' +
              '<div class="oe-help__body">Contact the kitchen and quote order <b>#' + esc(o.id) + '</b>. Changes are possible until the ticket is on the pass.</div>' +
              '<button type="button" class="oe-again" data-go="menu">Order again</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  /* ---------------- item modal ---------------- */

  // Variable products: the customer picks a term per attribute, which resolves
  // to one WooCommerce variation with its own price and stock.
  function openOptions(p) {
    S.modal = p;
    S.mqty = 1;
    S.error = '';
    S.sel = {};
    (p.attributes || []).forEach(function (a) {
      if (a.has_variations && a.terms && a.terms.length) S.sel[a.name] = a.terms[0].name;
    });
    S.variants = null;
    S.variantsFor = p.id;
    render();

    var ids = (p.variations || []).map(function (v) { return v.id; });
    if (!ids.length) { S.variants = {}; return patchModal(); }
    // The products collection filters variations out, so each one is fetched by
    // id; they are small and the result is cached for as long as the modal lives.
    Promise.all(ids.map(function (id) {
      return api('products/' + id).catch(function () { return null; });
    })).then(function (list) {
      if (S.variantsFor !== p.id) return;
      var map = {};
      list.forEach(function (v) {
        if (v && v.id) map[v.id] = { price: v.prices.price, inStock: v.is_in_stock, purchasable: v.is_purchasable };
      });
      S.variants = map;
      patchModal();
    });
  }

  function matchedVariation() {
    var p = S.modal;
    if (!p || !p.variations) return null;
    var picked = S.sel;
    var hit = p.variations.filter(function (v) {
      return (v.attributes || []).every(function (a) { return picked[a.name] === a.value; });
    })[0];
    return hit || null;
  }

  function modalUnitMinor() {
    var p = S.modal;
    if (!p) return 0;
    var v = matchedVariation();
    if (v && S.variants && S.variants[v.id]) return minorOf(S.variants[v.id].price);
    return minorOf(p.prices.price);
  }

  function optionGroups() {
    var p = S.modal;
    if (!p || !p.attributes || !p.attributes.length) return '';
    return p.attributes.filter(function (a) { return a.has_variations && a.terms && a.terms.length; }).map(function (a) {
      return '<div class="oe-group">' +
        '<div class="oe-group__head">' +
          '<span class="oe-group__name">' + esc(a.name) + '</span>' +
          '<span class="oe-group__hint">Choose one</span>' +
        '</div>' +
        a.terms.map(function (t) {
          var on = S.sel[a.name] === t.name;
          // Price shown per term once the variation prices have loaded.
          var probe = {};
          Object.keys(S.sel).forEach(function (k) { probe[k] = S.sel[k]; });
          probe[a.name] = t.name;
          var v = (p.variations || []).filter(function (vv) {
            return (vv.attributes || []).every(function (x) { return probe[x.name] === x.value; });
          })[0];
          var price = v && S.variants && S.variants[v.id] ? minorOf(S.variants[v.id].price) : null;
          var base = modalUnitMinor();
          var delta = price != null ? price - base : null;
          var label = delta == null || (on && delta === 0) ? '' :
            (delta === 0 ? 'Included' : (delta > 0 ? '+' + money(delta) : money(delta)));
          var out = v && S.variants && S.variants[v.id] && !S.variants[v.id].inStock;
          return '<button type="button" class="oe-opt' + (on ? ' is-on' : '') + '" data-opt="' + esc(a.name) + '" data-value="' + esc(t.name) + '"' + (out ? ' disabled' : '') + '>' +
            '<span class="oe-opt__mark" style="border-radius:50%"></span>' +
            '<span class="oe-opt__name">' + esc(t.name) + (out ? ' - sold out' : '') + '</span>' +
            '<span class="oe-opt__price">' + esc(label) + '</span>' +
          '</button>';
        }).join('') +
      '</div>';
    }).join('');
  }

  var modalBuiltFor = null;

  // Update the live dialog in place: option states, price labels, the add
  // button. Rebuilding would reload the hero image and reset the scroll.
  function patchModal() {
    var p = S.modal;
    if (!p || !modalRoot.querySelector('.oe-modal__box')) return renderModal();

    modalRoot.querySelectorAll('[data-opt]').forEach(function (btn) {
      var on = S.sel[btn.dataset.opt] === btn.dataset.value;
      btn.classList.toggle('is-on', on);
    });

    var base = modalUnitMinor();
    modalRoot.querySelectorAll('[data-opt]').forEach(function (btn) {
      var priceEl = btn.querySelector('.oe-opt__price');
      if (!priceEl) return;
      var probe = {};
      Object.keys(S.sel).forEach(function (k) { probe[k] = S.sel[k]; });
      probe[btn.dataset.opt] = btn.dataset.value;
      var v = (p.variations || []).filter(function (vv) {
        return (vv.attributes || []).every(function (x) { return probe[x.name] === x.value; });
      })[0];
      var info = v && S.variants ? S.variants[v.id] : null;
      var price = info ? minorOf(info.price) : null;
      var on = S.sel[btn.dataset.opt] === btn.dataset.value;
      var delta = price != null ? price - base : null;
      priceEl.textContent = delta == null || (on && delta === 0) ? ''
        : (delta === 0 ? 'Included' : (delta > 0 ? '+' + money(delta) : money(delta)));
      var out = info && !info.inStock;
      btn.disabled = !!out;
      var nameEl = btn.querySelector('.oe-opt__name');
      if (nameEl) {
        var want = btn.dataset.value + (out ? ' - sold out' : '');
        if (nameEl.textContent !== want) nameEl.textContent = want;
      }
    });

    var qtyEl = modalRoot.querySelector('.oe-step--lg span');
    if (qtyEl && qtyEl.textContent !== String(S.mqty)) qtyEl.textContent = S.mqty;

    var variation = matchedVariation();
    var soldOut = !!(variation && S.variants && S.variants[variation.id] && !S.variants[variation.id].inStock);
    var waiting = p.has_options && !S.variants;
    var unresolved = p.has_options && !variation;
    var add = modalRoot.querySelector('[data-modaladd]');
    if (add) {
      add.disabled = soldOut || unresolved || waiting || !!S.pendingAdd;
      add.textContent = waiting ? 'Loading options…'
        : (soldOut ? 'Sold out'
        : (S.pendingAdd ? 'Adding…' : 'Add · ' + money(modalUnitMinor() * S.mqty)));
    }

    var warn = modalRoot.querySelector('.oe-modal__warn');
    var msg = unresolved && !waiting ? 'That combination is unavailable - pick another option.' : '';
    if (warn) { warn.textContent = msg; warn.hidden = !msg; }
  }

  function renderModal() {
    var p = S.modal;
    if (!p) { modalRoot.innerHTML = ''; modalBuiltFor = null; lockScroll(); return; }
    if (modalBuiltFor === p.id && modalRoot.querySelector('.oe-modal__box')) return patchModal();
    modalBuiltFor = p.id;
    lockScroll();
    var variation = matchedVariation();
    var unit = modalUnitMinor() * S.mqty;
    var soldOut = !!(variation && S.variants && S.variants[variation.id] && !S.variants[variation.id].inStock);
    var waiting = p.has_options && !S.variants;
    var unresolved = p.has_options && !variation;

    modalRoot.innerHTML =
      '<div class="oe-modal">' +
        '<div class="oe-modal__scrim" data-close></div>' +
        '<div class="oe-modal__box" role="dialog" aria-modal="true">' +
          '<div class="oe-modal__hero"' + bg(img(p)) + '>' +
            '<button type="button" class="oe-modal__close" data-close>×</button>' +
            '<div class="oe-modal__cap">' +
              '<div class="oe-modal__name">' + esc(p.name) + '</div>' +
              '<div class="oe-modal__desc">' + esc(stripTags(p.short_description || '')) + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="oe-modal__body">' +
            (S.error ? '<div class="oe-error">' + esc(S.error) + '</div>' : '') +
            optionGroups() +
            '<div class="oe-error oe-modal__warn"' + (unresolved && !waiting ? '' : ' hidden') + '>' +
              (unresolved && !waiting ? 'That combination is unavailable - pick another option.' : '') + '</div>' +
            '<div class="oe-modal__foot">' +
              '<div class="oe-step oe-step--lg">' +
                '<button type="button" data-mqty="-1">−</button><span>' + S.mqty + '</span><button type="button" data-mqty="1">+</button>' +
              '</div>' +
              '<button type="button" class="oe-modal__add" data-modaladd' +
                ((soldOut || unresolved || waiting || S.pendingAdd) ? ' disabled' : '') + '>' +
                (waiting ? 'Loading options…' : (soldOut ? 'Sold out' : (S.pendingAdd ? 'Adding…' : 'Add · ' + money(unit)))) +
              '</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  /* ---------------- render dispatch ---------------- */

  function render() {
    if (S.screen !== 'menu') menuNodes = null;
    if (S.screen === 'checkout') renderCheckout();
    else if (S.screen === 'done' && S.order) renderDone();
    else renderMenu();
    renderModal();
  }

  /* ---------------- actions ---------------- */

  function setCart(data) { cart = data; render(); }
  function fail(e) { S.busy = false; S.error = e.message; render(); }

  function addProduct(p, qty) {
    if (S.pendingAdd) return;          // ignore double taps on the same card
    S.busy = true;
    S.pendingAdd = p.id;
    S.error = '';
    patchCardFooters();                // instant feedback, before the round trip
    renderModal();
    api('cart/add-item', 'POST', { id: p.id, quantity: qty || 1 })
      .then(function (d) {
        var first = !cartCount();
        S.busy = false; S.pendingAdd = null; S.error = ''; S.modal = null;
        setCart(d);
        // WooCommerce keeps no session for a guest with an empty cart, so a pickup
        // or delivery choice made before the first dish was lost and WooCommerce
        // picked its default rate. Send the choice again now the cart has an item.
        if (first) syncMode();
      })
      .catch(function (e) { S.pendingAdd = null; fail(e); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-go],[data-add],[data-qty],[data-key],[data-remove],[data-when],[data-svc],[data-cat],[data-clear],[data-pay],[data-applycoupon],[data-place],[data-close],[data-mqty],[data-modaladd],[data-opt],[data-cart-open],[data-cart-close]');
    if (!t || !root.contains(t) && !modalRoot.contains(t)) return;
    var d = t.dataset;

    if (d.cartOpen !== undefined) return setCartOpen(!S.cartOpen);
    if (d.cartClose !== undefined) return setCartOpen(false);
    if (d.go) {
      if (d.go === 'checkout' && !cartCount()) return;
      setCartOpen(false);
      S.screen = d.go;
      checkoutSig = '';
      if (d.go === 'menu') { S.order = null; S.error = ''; }
      render();
      return toTop();
    }
    if (d.svc) {
      if (S.service === d.svc) return;
      S.service = d.svc;
      var times = restartTime();
      render();
      return Promise.all([times, syncMode()]);
    }
    if (d.cat) {
      if (GROUPED) return jumpTo(d.cat);
      S.cat = d.cat; S.query = ''; return render();
    }
    if (d.clear !== undefined) { S.query = ''; S.cat = 'all'; return render(); }
    if (d.when) return pickWhen(d.when === 'later');
    if (d.pay) { S.payment = d.pay; return render(); }

    if (d.add) {
      var p = products.filter(function (x) { return String(x.id) === d.add; })[0];
      if (!p) return;
      if (p.has_options) return openOptions(p);
      return addProduct(p, 1);
    }
    if (d.opt) {
      if (S.sel[d.opt] === d.value) return;
      S.sel[d.opt] = d.value;
      return patchModal();       // never rebuild the dialog under the customer
    }
    if (d.qty) {
      var line = lineFor(parseInt(d.qty, 10));
      if (!line || S.pendingAdd) return;
      var q = line.quantity + parseInt(d.delta, 10);
      S.pendingAdd = parseInt(d.qty, 10);
      patchCardFooters();
      return (q <= 0 ? api('cart/remove-item', 'POST', { key: line.key })
                     : api('cart/update-item', 'POST', { key: line.key, quantity: q }))
        .then(function (c) { S.pendingAdd = null; setCart(c); })
        .catch(function (e) { S.pendingAdd = null; fail(e); });
    }
    if (d.key) {
      var item = cart.items.filter(function (i) { return i.key === d.key; })[0];
      if (!item || S.pendingLine) return;
      var nq = item.quantity + parseInt(d.delta, 10);
      S.pendingLine = item.key;
      markLineBusy(item.key);
      return (nq <= 0 ? api('cart/remove-item', 'POST', { key: item.key })
                      : api('cart/update-item', 'POST', { key: item.key, quantity: nq }))
        .then(function (c) { S.pendingLine = null; setCart(c); })
        .catch(function (e) { S.pendingLine = null; fail(e); });
    }
    if (d.remove) {
      if (S.pendingLine) return;
      S.pendingLine = d.remove;
      markLineBusy(d.remove);
      return api('cart/remove-item', 'POST', { key: d.remove })
        .then(function (c) { S.pendingLine = null; setCart(c); })
        .catch(function (e) { S.pendingLine = null; fail(e); });
    }

    if (d.close !== undefined) { S.modal = null; S.error = ''; return render(); }
    if (d.mqty) { S.mqty = Math.max(1, S.mqty + parseInt(d.mqty, 10)); return patchModal(); }
    if (d.modaladd !== undefined) {
      var mp = S.modal;
      if (!mp) return;
      if (!mp.has_options) return addProduct(mp, S.mqty);
      var v = matchedVariation();
      if (!v) { S.error = 'Pick an option for each choice above.'; return renderModal(); }
      return addVariation(mp, v, S.mqty);
    }

    if (d.applycoupon !== undefined) return applyCoupon();
    if (d.place !== undefined) return placeOrder();
  });

  var pinTimer = null;
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.dataset || !root.contains(t)) return;
    if (t.dataset.field === 'state') {
      S.fields.state = t.value;
      S.seen.state = true;
      delete S.serverErrors.state;
      return patchField('state');
    }
    if (t.dataset.optin !== undefined) { S.statusOptIn = !!t.checked; return; }
    if (t.dataset.day !== undefined) {
      S.day = t.value;
      var times = loadDay(false);
      render();
      return times;
    }
    if (t.dataset.time !== undefined) { S.slot = t.value; render(); return refreshQuote(); }
  });

  // A field says what's wrong once the customer leaves it with something typed.
  document.addEventListener('focusout', function (e) {
    var key = e.target.dataset && e.target.dataset.field;
    if (!key || !root.contains(e.target) || !String(S.fields[key]).trim()) return;
    S.seen[key] = true;
    patchField(key);
  });

  function addVariation(parent, variation, qty) {
    if (S.pendingAdd) return;
    S.pendingAdd = parent.id;
    S.error = '';
    renderModal();
    // WooCommerce wants the variation id plus the chosen attributes.
    api('cart/add-item', 'POST', {
      id: variation.id,
      quantity: qty || 1,
      variation: (variation.attributes || []).map(function (a) { return { attribute: a.name, value: a.value }; })
    }).then(function (d) {
      var first = !cartCount();
      S.pendingAdd = null; S.modal = null; S.variants = null; S.variantsFor = null; S.error = '';
      setCart(d);
      if (first) syncMode();   // see addProduct
    }).catch(function (e) { S.pendingAdd = null; S.error = e.message; renderModal(); });
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.id === 'oe-search') { S.query = t.value; return renderMenu(); }
    if (t.dataset.field) {
      var key = t.dataset.field;
      S.fields[key] = t.value;
      delete S.serverErrors[key];
      // Typing must not rebuild the form; a message already showing follows along.
      patchField(key);
      // Postcode drives the delivery zone, fee and minimum.
      if (key === 'pin') {
        clearTimeout(pinTimer);
        // A new postcode can move the earliest promise; a scheduled time stays and is re-checked.
        if (!S.schedule) { S.baseAt = ''; S.slot = ''; }
        pinTimer = setTimeout(function () { refreshQuote().then(syncMode); }, 500);
      }
      return;
    }
    if (t.dataset.optin !== undefined) { S.statusOptIn = !!t.checked; return; }
    if (t.dataset.coupon !== undefined) { S.coupon = t.value; return; }
    if (t.dataset.note !== undefined) { S.note = t.value; }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && S.modal) { S.modal = null; render(); }
    else if (e.key === 'Escape' && S.cartOpen) setCartOpen(false);
    else if (e.key === 'Tab' && S.cartOpen && !S.modal) trapCartFocus(e);
  });

  function applyCoupon() {
    var code = S.coupon.trim();
    if (!code) return;
    api('cart/apply-coupon', 'POST', { code: code })
      .then(function (d) {
        S.couponOk = true;
        S.couponMsg = code.toUpperCase() + ' applied.';
        setCart(d);
      })
      .catch(function (err) {
        S.couponOk = false;
        S.couponMsg = err.message;
        render();
      });
  }

  var trackTimer = null;

  function placeOrder() {
    if (S.busy || !canCheckout()) return;
    if (!detailsValid()) {
      S.touched = true;
      S.error = 'Check the highlighted details.';
      render();
      return focusFirstInvalid();
    }

    // Card gateways need WooCommerce's own payment fields.
    if (needsWooCheckout(S.payment)) {
      syncMode().then(function () { window.location.href = CFG.checkoutUrl; });
      return;
    }

    S.busy = true; S.error = '';
    render();
    // WooCommerce checks the cart against the session's mode and time before it reads
    // the order, so a pickup switch still in flight would be billed as a delivery.
    syncMode().then(submitOrder);
  }

  function submitOrder() {
    var f = S.fields;
    var parts = f.name.trim().split(/\s+/);
    var store = CFG.store || {};
    // Collection orders have no customer address, but WooCommerce still
    // validates city/state/postcode - bill them to the store's own address.
    var place = isDelivery()
      ? { address_1: f.address.trim(), address_2: '', city: f.city.trim(), state: f.state || '', postcode: f.pin.trim(), country: CFG.country || '' }
      : { address_1: store.address_1 || '', address_2: store.address_2 || '', city: store.city || '', state: store.state || '', postcode: store.postcode || '', country: store.country || CFG.country || '' };

    var address = {
      first_name: parts[0] || '',
      last_name: parts.slice(1).join(' ') || parts[0] || '',
      email: f.email.trim(),
      phone: f.phone.trim(),
      address_1: place.address_1,
      address_2: place.address_2,
      city: place.city,
      state: place.state,
      postcode: place.postcode,
      country: place.country,
      company: ''
    };
    var note = S.note.trim();
    var chosen = payments().filter(function (p) { return p.id === S.payment; })[0];
    var snapshot = {
      total: money(grandMinor()),
      lines: cart.items.map(function (i) {
        return {
          qty: i.quantity, name: decode(i.name),
          img: i.images && i.images[0] ? i.images[0].thumbnail : '',
          sub: lineOptions(i) || money(i.prices.price) + ' each',
          total: money(i.totals.line_total)
        };
      }),
      rows: [
        { label: 'Subtotal', value: money(subtotalMinor()) },
        { label: isDelivery() ? 'Delivery' : 'Pickup', value: shippingMinor() ? money(shippingMinor()) : 'Free' }
      ].concat(taxMinor() ? [{ label: 'Taxes', value: money(taxMinor()) }] : [])
       .concat(discountMinor() ? [{ label: 'Discount', value: '−' + money(discountMinor()) }] : [])
       .concat([{ label: slotLabel(), value: (S.quote && S.quote.promised_label) || 'As soon as possible' }])
    };

    api('checkout', 'POST', {
      billing_address: address,
      shipping_address: address,
      payment_method: S.payment,
      customer_note: note,
      // The menu plugin registers these as required checkout fields; they drive
      // the delivery fee, the slot reservation and the kitchen queue.
      additional_fields: {
        'wowrestro/fulfillment-mode': S.service,
        'wowrestro/requested-time': S.slot || '',
        'wowrestro/status-updates': !!S.statusOptIn
      },
      extensions: {}
    }).then(function (res) {
      S.busy = false;
      S.order = {
        id: res.order_id,
        key: res.order_key || '',
        status: res.status || 'wr-new',
        promised: S.quote && S.quote.promised_label ? S.quote.promised_label : '',
        payment: chosen ? chosen.title : '',
        date: new Date().toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }),
        total: snapshot.total,
        lines: snapshot.lines,
        rows: snapshot.rows
      };
      S.screen = 'done';
      toTop();
      clearInterval(trackTimer);
      trackTimer = setInterval(function () {
        if (S.screen !== 'done') return clearInterval(trackTimer);
        trackOrder();
      }, 20000);
      api('cart').then(function (d) { cart = d; render(); }).catch(render);
    }).catch(function (e) {
      // WooCommerce still has the last word on an address; mark the field it rejected.
      S.serverErrors = serverFieldErrors(e.data);
      if (DETAILS.some(function (k) { return S.serverErrors[k] && fieldError(k); })) {
        S.touched = true;
        e = new Error('Check the highlighted details.');
      }
      fail(e);
      focusFirstInvalid();
    });
  }

  /* ---------------- boot ---------------- */

  // The Store API sends names HTML-encoded ("Burrata &amp; Tomato"); decode
  // once so escaping for output doesn't print the entity.
  function named(x) { x.name = decode(x.name); return x; }

  render();
  root.dataset.oeReady = '1';   // reveals the design, hides the boot spinner

  api('products?per_page=100').then(function (list) {
    products = (list || []).filter(function (p) { return p.is_purchasable; }).map(named);
    render();
  }).catch(function () {});

  api('products/categories?per_page=50').then(function (list) {
    cats = (list || []).filter(function (c) { return c.count > 0; }).map(named);
    render();
  }).catch(function () {});

  api('products?per_page=1&orderby=popularity').then(function (list) {
    popular = (list || [])[0] ? named(list[0]) : null;
    render();
  }).catch(function () {});

  api('cart').then(function (d) { cart = d; render(); syncMode(); }).catch(function () {});
  restartTime();
})();
