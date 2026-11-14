# Regression checks

Run from the plugin root:

```sh
php tests/php/schedule-regression.php
php tests/addon-pricing-test.php
php tests/product-roundtrip-test.php
```

These execute production PHP classes with in-memory WordPress/WooCommerce adapters. They cover schedule persistence and slot generation, add-on prices and cart totals, and product price/stock/status preservation. They do not exercise a real WordPress database or checkout.

For native browser input events, responsive forms, save/reload and route rendering, see [the browser fixture](browser/README.md). The browser script deliberately types decimals slowly enough to expose input replacement between keystrokes.

Before deploying, check the package on a staging WordPress/WooCommerce installation with the site's theme, checkout and other extensions. The local adapters cannot establish compatibility with those integrations.
