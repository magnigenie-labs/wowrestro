# Real WordPress/WooCommerce integration

With WordPress, WooCommerce and this checkout of WowRestro active on an isolated local site:

```sh
wp --path=/path/to/wordpress eval-file /path/to/wowrestro/tests/integration/regression.php
```

The script requires `WP_ENVIRONMENT_TYPE=local` and a `localhost` or `127.0.0.1` site URL. Run it before browser tests because it temporarily changes service/tax settings. It restores those settings and deletes its QA products, user and order in a `finally` block.

It runs real WordPress REST dispatch, permission callbacks, database reads/writes, WooCommerce product/cart calculations and checkout line-item creation. No WordPress or WooCommerce APIs are mocked. Outgoing mail is suppressed for this process.

Coverage includes split pickup/delivery schedules, slot availability, invalid and partial updates, anonymous/subscriber permissions, decimal add-ons, required/unknown options, simple/variable cart totals, repeated calculations, saved order snapshots/instructions, price updates, zero sale prices, stock status and publication status.

HTTP checkout, payment selection and confirmation-page behavior are separate browser checks; this script alone does not exercise the complete customer checkout page.

After completing a pickup checkout with an add-on and configuring the restaurant contact email, verify its receipt and native/email output without changing the order:

```sh
wp --path=/path/to/wordpress eval-file /path/to/wowrestro/tests/integration/receipt.php ORDER_ID
```

This renders the actual receipt and WooCommerce HTML/plain-text email item templates, verifies native customer/admin metadata, checks pickup versus delivery contacts, and confirms that rendering leaves the stored order unchanged.

Additional real integration suites:

```sh
wp eval-file tests/integration/checkout-defaults.php
wp eval-file tests/integration/operations.php
wp eval-file tests/integration/shipping-mode-regression.php
wp eval-file tests/integration/legacy-order-discovery.php
```

The operations and main regression suites also run against legacy CPT storage using a process-local filter; this leaves the shared site's HPOS setting unchanged:

```sh
wp eval 'add_filter("pre_option_woocommerce_custom_orders_table_enabled", static function () { return "no"; }); require "/absolute/path/to/wowrestro/tests/integration/regression.php";'
```

Browser scripts are async functions accepted by `playwright-cli run-code`. They use real HTTP endpoints and sessions. `seed-browser.php` creates the disposable menu/products and changes local shop settings; do not run it on a live store. The pagination fixture scripts seed additional test products. The prefill script delays an unchanged, real cart response to reproduce the editing race.

For `checkout-tax-browser.js`, enable `tax-fixture.php` first and always run `tax-fixture.php restore` afterward. It checks two £13.75 configured items, 10% discount, £3.50 delivery and 20% VAT, then places the £33.90 COD order. `checkout-release-browser.js` places a fresh pickup order and verifies required extras, receipt content, reload persistence and an empty cart.

Browser checkout scripts retain their QA orders for inspection. Outbound mail is disabled by the local-only MU plugin; email templates are rendered and checked separately. COD and bank transfer are tested, with no real payment processing.

Check operations and customer tracking on either WooCommerce datastore:

```sh
wp --path=/path/to/wordpress eval-file /path/to/wowrestro/tests/integration/operations.php
```

This creates and deletes its own unpaid pickup order, leaves settings unchanged, exercises actual staff/public REST routes through all pickup statuses, verifies revision and permission checks, and checks repeated checkout saves, existing duplicate metadata repair, stable tracking tokens, unrelated checkout metadata and datastore-compatible order queries.

`receipt-email-verification.js` exports a browser helper accepting `(page, keyedReceiptUrl, billingEmail, expectedTextArray, screenshotPath)`. Use an English-language local guest order older than WooCommerce's ten-minute verification grace period, with distinctive expected text such as its selected add-on, restaurant email and `Collected` status. It opens a fresh anonymous context, verifies that the wrong email is denied, then that the correct email displays the receipt, and checks that neither verification POST is redirected. The equivalent flow was exercised through the Playwright CLI on local order 93.

Card handoff checks are separate from processor sandbox payments. Copy `fixtures/qa-card-handoff-probe.php` and `.js` to the disposable site's `wp-content/mu-plugins/`, then run `card-handoff-browser.js` followed by `card-handoff-failure-browser.js` with the Playwright CLI. The gateway is only visible to the test cookie and always refuses payment processing. These scripts check real Store API updates and both native checkout forms: contact/address, kitchen note, scheduled time, opt-in, gateway selection, and failure handling. The successful handoff sends PUT to prepare checkout and never POST to process payment. One unchanged real PUT response is captured before navigation; failure tests explicitly inject a 503 response.

`wp eval-file tests/integration/card-handoff.php` checks the classic checkout note/preference adapter and cleanup. These tests do not establish Stripe, 3DS or webhook success; those require separate actual sandbox transactions.
