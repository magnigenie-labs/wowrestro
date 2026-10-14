=== WowRestro - Restaurant Ordering & Operations for WooCommerce ===
Contributors: magnigenie, sagarseth9, devashu0011
Tags: restaurant, food ordering, woocommerce, pickup, delivery
Donate link: https://paypal.me/magnigeeks
Requires PHP: 7.4
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 2.0.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

WowRestro is a complete restaurant ordering and fulfillment operations system for WooCommerce.

== Description ==

WooRestro is an online ordering system powered by WooCommerce. WooRestro allows you to offer both Takeaway/Pickup and Delivery services to your customers. Using WooRestro, you can easily create a responsive food ordering system for free.

Now you can easily convert your website visitors to customers using the WooRestro ordering interface. WooRestro helps you convert your WooCommerce store into an online takeaway/delivery system by providing a modern and responsive menu design.

Some of the possible use cases of WooRestro include online food delivery/takeaway businesses, online grocery delivery/takeaway businesses, online flower delivery/takeaway systems, laundry services, etc.

WooRestro enables you to easily add modifiers/options/add-ons for your food items directly from the product add/edit screen of WooCommerce.

The WooRestro layout is implemented on your site using the [wowrestro] shortcode. The plugin automatically creates a page called ORDER ONLINE, which contains the [wowrestro] shortcode and is used for the ordering interface presented to your customers.

= Shortcode Attributes =

* category: Comma-separated list of category slugs.
* show_search (yes, no): Allows you to show/hide the search bar.

= Shortcode Examples =

* [wowrestro]
* [wowrestro category="main-course,lunch,starters"]
* [wowrestro category="main-course,lunch,starters" show_search="no"]

= Why Use WooRestro =

* WooRestro is built with a mobile-first design approach, as most of your customers will use their mobile phones to order online.
* Powered by WooCommerce, which makes it more powerful.
* Ability to easily add modifiers for your items.
* Allows you to set items as Veg or Non-Veg.
* Support for variable products.
* Completely developer-friendly with sufficient hooks to alter any functionality of the plugin.

= WooRestro Demo =

Check out [WooRestro Demo](https://demo.wowrestro.com/)
Check out [Documentation](https://docs.wowrestro.com/)

= Need Help? =

You can use the support forum for this plugin to share any issues you are experiencing, and our support team will make sure to provide you with a quick resolution for your problems.

You can also reach out to us at [support@wowrestro.com](mailto:support@wowrestro.com)

== Installation ==

1. Login to your WordPress dashboard and navigate to Plugins > Add New.
2. Search for WooRestro.
3. Click Install and activate the plugin.
4. Once the plugin is activated, it will create a page called Order Online with the shortcode [wowrestro]. This page will list all your items.
5. After activating the plugin, you can navigate to the WooRestro menu on your dashboard and start setting up different settings for the plugin.

== Frequently Asked Questions ==

= How can I add my products to the order online page? =
You can easily add your products from the WooCommerce Products menu. You just need to make sure the product you are adding is marked as a WooRestro item.

= Does it require WooCommerce? =
Yes, the plugin is built as an extension to WooCommerce, so it's necessary to have WooCommerce installed and active before adding this plugin.

= Do I need any specific theme to make this plugin work correctly? =
No, you don't need any special theme to run this plugin. It works with all themes that support WooCommerce.

== Screenshots ==

1. WooRestro items list
2. WooRestro Order Setting
3. Simple WooRestro item with modifiers
4. Variable WooRestro item with modifiers
5. WooRestro Mobile Layout
6. WooRestro Item edit screen
7. WooRestro General Settings
8. WooRestro Service Settings
9. WooRestro Pickup Service Settings
10. WooRestro Delivery Service Settings
11. WooRestro Style Settings
12. WooRestro Notifications Settings

== Changelog ==

= 2.0.7 =
* Added: store pages. An ordering page whose menu shortcode sets a product category, `[wowrestro_menu category="bakery"]`, runs as its own store: it gets its own checkout and confirmation under its page (/bakery/checkout/, /bakery/confirmation/), and orders, receipts and email links remember the store. The shortcode can also set the store's `name`, switcher `label`, `tagline`, `hero` (two lines split by |), menu `heading`, `note`, colours (`accent`, `ink`, `paper`), corner `radius` in pixels, `type="shop"` (the receipt talks about the store, not the kitchen) and `header="store"` (a store header with a live cart pill and a store footer in place of the theme's). All store pages share one WooCommerce cart: a store's menu drops dishes added in another store, and /checkout/ or another store's checkout sends the cart to its own store.
* Added: `[wowrestro_store_switcher label="" brand="" brand_url="" cta="" cta_url=""]`, a bar linking the store pages. On a store page with `header="store"` it sits above the store header.
* Changed: the WooRestro 0.3 demo's old design files are no longer loaded if a site still enqueues them.

= 2.0.6 =
* Changed: after checkout the confirmation opens at /confirmation/ (or a site's own /…/confirmation/), with no order number or key in the address. A keyed link, after checkout or from an email, is remembered in that browser's session and redirects to the clean address, which shows that order and survives a refresh. Keyed links still work everywhere, so emails open on any device; links a payment gateway added its own parameters to are left alone so it can finish the payment. With no order in the session, /confirmation/ goes back to the menu.

= 2.0.5 =
* Fixed: every order into a part-booked kitchen slot wrote one "WordPress database error Duplicate entry" line to the PHP error log for each position already taken. Those collisions are how a slot is claimed safely, so they are no longer logged; a real database fault while reserving still is.

= 2.0.4 =
* Fixed: after activating the plugin (a new install, or switching over from WooRestro), /confirmation/ pages returned "not found" until Settings > Permalinks was opened. Activation now adds the route before WordPress rebuilds its rules.
* Fixed: switching over from WooRestro by activation left a migration stopped by the 2.0.3 bug stopped; activation now restarts it, as updating already did.

= 2.0.3 =
* Fixed: the data migration stopped with "settings could not be saved during migration" whenever it ran in an admin request (Retry migration, or the background runner on admin-ajax). It compared the saved settings with the unsanitized value, and the settings sanitizer always reshapes it. A migration stopped by this runs again after the update.

= 2.0.2 =
* Changed: restaurant orders confirm at /confirmation/{order}/?key=… instead of /checkout/order-received/{order}/?key=…, after checkout, when a gateway returns, and in emails. It is still WooCommerce's order-received page underneath, so gateway thank-you hooks, conversion tracking and the guest email check behave as before, and old links keep working. Sites on plain permalinks keep WooCommerce's address.
* Changed: the QR table card photo is recompressed (same size and look), which brings the plugin zip under the common 2 MB upload limit.
* Changed: the order flow's text (menu, item dialog, cart, checkout and confirmation) uses Poppins on every template, in place of each template's own fonts.

= 2.0.1 =
* New: the `wowrestro_design_config` filter. Through it a menu page can keep to one product category (`category`, its sub-categories become the tabs), and change the hero line (`hero`, "First line|second line"), the menu heading (`heading`) and the line under the menu (`note`).
* New: the order flow fires a `wowrestro:cart` event on the document with the item count and the formatted total whenever the cart changes, for a header cart badge outside the menu.
* Fixed: template 1 tinted every button in the order panel alike, so Checkout, Place order and the options dialog's Add looked disabled. They are solid in the template's colour again.
* Fixed: selected times, the chosen payment method, step numbers, quantity steppers and card hovers used a fixed orange instead of the template's colour.

= 2.0 =
* Major Release: Complete architectural modernization for high-performance restaurant operations.
* Added: WooCommerce High-Performance Order Storage (HPOS) and Cart & Checkout Blocks compatibility.
* Added: Live Kitchen Order Board with real-time Firebase Cloud Messaging (FCM HTTP v1) push notifications and audio alerts.
* Added: Operational order statuses (New, Accepted, Preparing, Ready, Out for Delivery, Completed, Cancelled) decoupled from payment statuses.
* Added: Atomic shared-kitchen capacity and slot reservations ledger with InnoDB transactional safety.
* Added: Single-page ordering storefront app powered by WooCommerce Store API, including 36 modern menu templates.
* Added: Modern Gutenberg menu block and Elementor widget for flexible menu embedding.
* Added: Reusable Modifier Groups custom post type (`wowrestro_mod_group`) with min/max selection bounds, single/multi choices, default options, and immutable order snapshots.
* Added: Table reservations management system (`[wowrestro_reservations]`) with slot capacity, guest limits, and deposit support.
* Added: Dine-in QR code table ordering (`[wowrestro_staff_panel]`) with automatic table session tracking.
* Added: Multi-location / branch restaurant support (`[wowrestro_location_selector]`).
* Added: Least-privilege restaurant staff roles (`wowrestro_manager`, `wowrestro_staff`, `wowrestro_waiter`).
* Added: In-board manual / phone order entry with item selection, custom delivery/pickup timing, and payment collection.
* Added: Tip calculation support (percentage presets and custom flat amounts) on cart and checkout.
* Added: Live customer order tracking page and shortcode (`[wowrestro_tracking]`) with progress updates on Thank You page and emails.
* Added: Comprehensive REST API under `wowrestro/v1` namespace for menu, availability, operations, devices, and tracking.
* Added: Resumable, non-destructive legacy data migration tool for seamless upgrades from WOWRestro 1.x and WooRestro.
* Added: Guided 4-step onboarding wizard for first-time restaurant setup.
* Added: Built-in HPOS-safe restaurant sales analytics and operational reporting.
* Added: Site Health diagnostic checks for checkout compatibility, configuration, and capacity ledger health.
* Removed: Legacy jQuery dependencies (jquery.timepicker, jquery.toast, jquery.tipTip) and outdated template files in favor of lightweight vanilla scripts and Phosphor icon fonts.

= 1.3.1 - 13th April 2022 =
* Compatibility check with latest WordPress & WooCommerce version.
* Minor bugs fixed.
* Code cleanup.

= 1.3 - 20th Jan 2022 =
* Fixed: Frontend AJAX issue fixed for admin.
* Added: Show product price in the food item page.
* Added: Product price showing in popup.
* Added: Show description in the food item page.
* Added: New hook for modal popup.
* Fixed: Frontend item text override issue fixed.
* Fixed: Timeslot issue fixed on the checkout page.
* Fixed: Presize the food item title in the frontend.
* Fixed: Style issue fixed for description and small image.
* Code optimized.

= 1.2 - 18th Nov 2021 =
* Fixed: Issue with add-on click.
* Fixed: Styling and general settings problems.
* Fixed: Issue with default service type.
* Updated: Thank you page to show all data.

= 1.1 - 16th Jun 2021 =
* Fixed security issues.
* Code cleanup.

= 1.0 - 8th Feb 2021 =
* Initial public release.

== Upgrade Notice ==

= 2.0.7 =
* Store pages: an ordering page limited to one category can run as its own store, with its own checkout, confirmation, name and colours.

= 2.0.6 =
* Order confirmations open at a clean address, /confirmation/, with no order number or key in it.

= 2.0.5 =
* Stops kitchen-slot reservations filling the PHP error log with "Duplicate entry" lines.

= 2.0.4 =
* Fixes /confirmation/ pages returning "not found" after the plugin is activated, until permalinks were saved.

= 2.0.3 =
* Fixes the data migration stopping with "settings could not be saved during migration". A stopped migration runs again after this update.

= 2.0.2 =
* Order confirmations move to their own address, /confirmation/{order}/. Links already sent to /checkout/order-received/ keep working.
* The order flow's text uses Poppins on every menu template.

= 2.0.1 =
* Template 1's Checkout and Place order buttons no longer look disabled, and its selected options use the template's own colour.

= 2.0 =
Major release 2.0 brings HPOS compatibility, Checkout Blocks support, a live Kitchen Order Board, slot reservations, and a redesigned modifier engine. An automatic, non-destructive data migration wizard will run on first activation to migrate your 1.x settings, modifiers, and orders.

= 1.3.1 =
Please take a backup of your site before updating the plugin.
