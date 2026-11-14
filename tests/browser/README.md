# Local browser fixture

Start from the plugin root with `php -S 127.0.0.1:8765 -t .`, then open:

- Schedule: <http://127.0.0.1:8765/tests/browser/>
- Edit add-on: <http://127.0.0.1:8765/tests/browser/?route=/food-menu/addons/update/100>
- Edit simple product: <http://127.0.0.1:8765/tests/browser/?route=/food-menu/items/update/7>
- Edit variable product: <http://127.0.0.1:8765/tests/browser/?route=/food-menu/items/update/8>

This loads the real plugin template, CSS, and JavaScript. Only the WordPress template helpers and REST responses are mocked. It cannot verify authentication, PHP sanitization, database persistence, checkout, or WooCommerce integration. PHP regression checks cover server behavior separately.

Settings, add-ons, and products persist in browser localStorage. Add `reset=1` to the URL or use the reset link for fresh seed data. The seed includes distinct split pickup/delivery hours, zero timing settings, a blank restaurant profile, a decimal add-on, and out-of-stock products with zero sale prices.

For browser assertions, `window.wowRestroFixture.read()` returns saved state and `.requests` records mock REST requests. Set `.failNext = 'Test save failure'` to fail the next mock request. `window.wowRestroSelfTest()` runs the app's existing route-render checks.

`regression.js` is an `async (page) => { ... }` expression for Playwright CLI's `run-code` command, without repository dependencies. It resets fixture state and checks editing, character-by-character decimal typing with rerenders between keys, save/reload, invalid input, schedule closures, and all navigation routes. Its schedule-error test injects a failure shaped like a REST response via `.failNext = { status, message, data }`; it does not simulate PHP validation.
