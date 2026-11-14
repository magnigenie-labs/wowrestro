=== WowRestro - Restaurant Ordering & Operations for WooCommerce ===
Contributors: magnigenie, sagarseth9, devashu0011
Tags: restaurant, food ordering, online ordering, pickup, delivery
Donate link: https://paypal.me/magnigeeks
Requires PHP: 7.4
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Transform WooCommerce into a commission-free restaurant ordering powerhouse with app-like menus, QR dine-in, live kitchen order board, and table reservations.

== Description ==

**WowRestro** is a complete, commission-free restaurant online ordering and kitchen operations system built specifically for WooCommerce. 

Say goodbye to hefty 15–30% aggregator commissions. With WowRestro, you own your customer relationships, keep 100% of your profits, and deliver a slick, lightning-fast ordering experience that keeps hungry diners coming back for more.

Whether you run a bustling pizzeria, trendy cafe, bakery, cloud kitchen, food truck, bar, or multi-location restaurant chain, WowRestro equips you with an enterprise-grade digital ordering suite right inside WordPress.

👉 [Live Demo](https://www.woorestro.com/#demo)
---

### 🚀 Why WowRestro Boosts Customer Engagement & Sales

* **App-Like Speed & Flow**: Powered by the WooCommerce Store API, customers browse categories, configure toppings, and checkout in seconds without annoying full-page reloads.
* **Frictionless Mobile-First Experience**: Over 80% of food orders happen on smartphones. WowRestro is engineered from the ground up for thumb-friendly mobile ordering.
* **Irresistible Food Customization**: Visual modifier popups make upselling toppings, crusts, dips, sizes, and combos effortless.
* **Contactless Dine-In QR Ordering**: Seat guests, let them scan their table QR code, browse your interactive digital menu, and order directly to their table.
* **Real-Time Customer Order Tracking**: Keep diners excited and informed with live visual progress updates from "Order Placed" to "Out for Delivery".

---

### 🌟 Key Features Built for Modern Restaurants

#### 📱 High-Converting Menu Storefront (36 Modern Templates)
* **Single-Page Ordering App**: Fast, responsive menu layout with sticky categories, search bar, and live floating cart.
* **36 Designer Templates**: Tailored layouts for pizza places, burger joints, sushi bars, bakeries, cafes, and fine dining.
* **Gutenberg Block & Elementor Widget**: Embed your interactive food menu anywhere with drag-and-drop ease.
* **Dietary Badges & Info**: Highlight Veg, Non-Veg, Vegan, Gluten-Free, or Spicy items at a glance.

#### 🍕 Advanced Modifiers, Add-ons & Option Groups
* **Custom Modifier Groups**: Add checkboxes, radio buttons, or quantity selectors for extras and add-ons.
* **Selection Limits**: Enforce required minimums and maximums (e.g., "Choose 1 sauce", "Pick up to 3 toppings").
* **Pre-Selected Defaults**: Guide customer choices with default selections to speed up checkout.
* **Immutable Snapshots**: Order modifiers are permanently locked into the receipt, preventing discrepancies.

#### 🧑‍🍳 Real-Time Kitchen Order Board (KDS)
* **Live Operational Board**: Separate order operations into New, Accepted, Preparing, Ready, Out for Delivery, and Completed.
* **Push Notifications & Sound Alerts**: Real-time Firebase Cloud Messaging (FCM HTTP v1) alerts your kitchen staff instantly the moment an order arrives.
* **Manual / Phone Order Entry**: Take walk-in or phone orders straight from the order board.
* **Staff Access Roles**: Assign dedicated `Kitchen Manager`, `Kitchen Staff`, or `Waiter` roles without exposing sensitive WordPress admin settings.

#### 🪑 Dine-In QR Table Ordering
* **Contactless Digital Table Menus**: Print automatic QR codes for each table.
* **Automatic Table Tracking**: Orders placed via table QR code are tagged with the table number and session automatically.
* **Fast Re-Ordering**: Guests can order extra drinks or desserts throughout their meal.

#### 📅 Table Reservations Management
* **Interactive Booking Widget**: Embed table reservations with `[wowrestro_reservations]`.
* **Guest Counts & Slot Capacities**: Control available seats and time slots to prevent overbooking.
* **Booking Confirmation & Statuses**: Manage pending, confirmed, seated, and cancelled reservations.

#### ⏱️ Smart Kitchen Capacity & Slot Reservations
* **Avoid Kitchen Overwhelm**: Cap the maximum number of orders per time slot (e.g., max 10 orders every 15 minutes).
* **Atomic InnoDB Ledger**: Concurrency-safe slot reservations ensure duplicate slot claims never occur during peak lunch and dinner rushes.
* **Delivery & Pickup Windows**: Flexible lead times, advance ordering dates, and service-specific schedules.

#### 🏪 Multi-Store & Category-Specific Pages
* **Dedicated Store Pages**: Run independent ordering pages for sub-brands (e.g., `/bakery/` or `/cocktail-bar/`) with their own branding, colours, header, and clean confirmation page (`/confirmation/`).
* **Store Switcher Bar**: Seamlessly navigate between locations or concepts using `[wowrestro_store_switcher]`.
* **Shared WooCommerce Cart**: Smart cart handling ensures orders remain isolated to the correct storefront.

#### 📍 Multi-Location Branch Support
* Assign menu items, operational hours, and orders to specific restaurant branches.
* Allow customers to choose their closest branch using `[wowrestro_location_selector]`.

#### 💳 Seamless Checkout, Tipping & Tracking
* **Clean Confirmation URLs**: Polished `/confirmation/` experience that securely restores the customer's order session without ugly query keys.
* **Tip Suggestions**: Preset percentage buttons (10%, 15%, 20%) and custom tip fields at checkout.
* **HPOS Compatible**: 100% compatible with WooCommerce High-Performance Order Storage and Checkout Blocks.
* **Zero jQuery Bloat**: Built with vanilla JavaScript and lightweight SVG icons for peak speed and Google Core Web Vitals scores.

---

### 📋 Shortcode Quick Reference

Embed WowRestro anywhere on your WordPress site using these flexible shortcodes:

* `[wowrestro]` – Displays the default online ordering menu.
* `[wowrestro template="2" layout="tabs"]` – Displays template 2 with tabbed category navigation.
* `[wowrestro category="pizza,pasta" show_search="no"]` – Restricts the menu to specific categories.
* `[wowrestro_menu category="bakery" name="Artisan Bakery" header="store"]` – Builds a branded store page.
* `[wowrestro_store_switcher label="Our Brands" brand="Main Kitchen"]` – Adds a navigation switcher between store pages.
* `[wowrestro_reservations]` – Embeds the table reservation booking form.
* `[wowrestro_tracking]` – Displays customer order tracking and status lookup.
* `[wowrestro_staff_panel]` – Staff ordering terminal for dine-in and POS table orders.
* `[wowrestro_location_selector]` – Branch switcher for multi-location venues.

---

### 💡 Ideal For Every Food Business

* Restaurants & Bistros
* Pizzerias & Italian Trattorias
* Burger Joints & Fast Food
* Cafes, Coffee Shops & Bakeries
* Ghost Kitchens & Cloud Kitchens
* Bars, Pubs & Breweries
* Food Trucks & Pop-up Eateries
* Catering & Meal Prep Services

---
== Installation ==

= Minimum Requirements =
* WordPress 6.2 or higher
* WooCommerce 8.9 or higher
* PHP 7.4 or higher (PHP 8.1+ recommended)

= Automatic Installation =
1. Log in to your WordPress Dashboard.
2. Go to **Plugins > Add New**.
3. Search for **WowRestro**.
4. Click **Install Now** and then **Activate**.
5. The 4-step setup wizard will guide you through your business hours, service types (Delivery / Pickup / Dine-in), and ordering page setup.

= Manual Installation =
1. Download the plugin zip file from WordPress.org.
2. Go to **Plugins > Add New > Upload Plugin** in your WordPress admin.
3. Choose the zip file and click **Install Now**.
4. Activate the plugin and follow the onboarding wizard.

== Frequently Asked Questions ==

= Does WowRestro charge any commission on orders? =
No! WowRestro is 100% commission-free. Unlike Deliveroo, UberEats, or DoorDash that take 20–30% of every sale, you keep all of your revenue and customer data.

= Can customers order on mobile without installing an app? =
Yes! WowRestro provides a Progressive Web App (PWA) style single-page experience directly in mobile browsers. Customers browse menus, pick toppings, and pay instantly without downloading anything from the app store.

= How do the food modifiers and add-ons work? =
Under **WowRestro > Modifier Groups**, you can create reusable modifier sets (e.g., "Choose Crust", "Extra Toppings", "Choice of Dressing"). You can configure single-choice radio buttons, multi-choice checkboxes, minimum/maximum selection rules, and prices for each choice. Then assign them to any food product with a single click.

= Can I prevent kitchen overload during peak rush hours? =
Yes! WowRestro features built-in Kitchen Capacity and Slot Reservations. You can define how many orders your kitchen can handle in each 15, 30, or 60-minute window. Once a slot reaches capacity, customers are prompted to select the next available time slot.

= How does Dine-In QR Table Ordering work? =
Each dining table can have its own QR code generated from the WowRestro dining settings. When a guest scans the QR code with their phone camera, the menu opens with their table number pre-selected. Their order is sent straight to the Kitchen Order Board tagged with their table number.

= How do kitchen staff get notified of new orders? =
Staff can keep the **Kitchen Order Board** open on any tablet, iPad, or computer screen. WowRestro uses real-time push notifications (Firebase FCM) and audible chimes so the kitchen is alerted immediately when a new order lands.

= Can I use WowRestro with page builders like Elementor or the Block Editor? =
Yes! WowRestro includes both a native Gutenberg Block and an Elementor Widget for the menu, plus flexible shortcodes for full customization in any theme or builder.

= Is it compatible with WooCommerce HPOS and Checkout Blocks? =
Yes! WowRestro is fully declared compatible with WooCommerce High-Performance Order Storage (HPOS) and both classic checkout and the new Cart & Checkout Blocks.

= Can I migrate my settings from WooRestro 1.x? =
Yes! WowRestro 2.0 includes an automatic, non-destructive legacy migration tool that safely upgrades your settings, legacy modifiers, and order history.

== Screenshots ==

1. Interactive mobile-first online food ordering menu with category tabs
2. Food item modifier popup with toppings, choices, and quantity selectors
3. Live Kitchen Order Board (KDS) with audio chimes and real-time order columns
4. Dine-in QR code table ordering flow for contactless table service
5. Table reservation booking widget with guest count and time slot selection
6. Order confirmation and live visual order tracking timeline
7. Multi-store page builder with custom branding, hero headers, and themes
8. Kitchen capacity and time slot reservation settings
9. Delivery and pickup service scheduling rules
10. Modifier Group creator with min/max rules and pricing
11. Guided 4-step onboarding wizard for quick restaurant launch
12. HPOS-compatible restaurant sales analytics and performance reports

== Changelog ==

= 2.0 =
* Major Release: Complete architectural modernization for high-performance restaurant operations.
* Added: WooCommerce High-Performance Order Storage (HPOS) and Cart & Checkout Blocks compatibility.
* Added: Live Kitchen Order Board with real-time Firebase Cloud Messaging (FCM HTTP v1) push notifications and audio alerts.
* Added: Operational order statuses (New, Accepted, Preparing, Ready, Out for Delivery, Completed, Cancelled) decoupled from payment statuses.
* Added: Atomic shared-kitchen capacity and slot reservations ledger with InnoDB transactional safety.
* Added: Single-page ordering storefront app powered by WooCommerce Store API, including 36 modern menu templates.
* Added: Store pages. An ordering page whose menu shortcode sets a product category, `[wowrestro_menu category="bakery"]`, runs as its own store: it gets its own checkout and confirmation under its page (/bakery/checkout/, /bakery/confirmation/), and orders, receipts and email links remember the store. The shortcode can also set the store's `name`, switcher `label`, `tagline`, `hero` (two lines split by |), menu `heading`, `note`, colours (`accent`, `ink`, `paper`), corner `radius` in pixels, `type="shop"` (the receipt talks about the store, not the kitchen) and `header="store"` (a store header with a live cart pill and a store footer in place of the theme's). All store pages share one WooCommerce cart: a store's menu drops dishes added in another store, and /checkout/ or another store's checkout sends the cart to its own store.
* Added: `[wowrestro_store_switcher label="" brand="" brand_url="" cta="" cta_url=""]`, a bar linking the store pages. On a store page with `header="store"` it sits above the store header.
* Added: Modern Gutenberg menu block and Elementor widget for flexible menu embedding.
* Added: Reusable Modifier Groups custom post type (`wowrestro_mod_group`) with min/max selection bounds, single/multi choices, default options, and immutable order snapshots.
* Added: Table reservations management system (`[wowrestro_reservations]`) with slot capacity, guest limits, and deposit support.
* Added: Dine-in QR code table ordering (`[wowrestro_staff_panel]`) with automatic table session tracking.
* Added: Multi-location / branch restaurant support (`[wowrestro_location_selector]`).
* Added: Least-privilege restaurant staff roles (`wowrestro_manager`, `wowrestro_staff`, `wowrestro_waiter`).
* Added: In-board manual / phone order entry with item selection, custom delivery/pickup timing, and payment collection.
* Added: Tip calculation support (percentage presets and custom flat amounts) on cart and checkout.
* Added: Live customer order tracking page and shortcode (`[wowrestro_tracking]`) with progress updates on Thank You page and emails.
* Added: The `wowrestro_design_config` filter. Through it a menu page can keep to one product category (`category`, its sub-categories become the tabs), and change the hero line (`hero`, "First line|second line"), the menu heading (`heading`) and the line under the menu (`note`).
* Added: The order flow fires a `wowrestro:cart` event on the document with the item count and the formatted total whenever the cart changes, for a header cart badge outside the menu.
* Added: Comprehensive REST API under `wowrestro/v1` namespace for menu, availability, operations, devices, and tracking.
* Added: Resumable, non-destructive legacy data migration tool for seamless upgrades from WOWRestro 1.x and WooRestro.
* Added: Guided 4-step onboarding wizard for first-time restaurant setup.
* Added: Built-in HPOS-safe restaurant sales analytics and operational reporting.
* Added: Site Health diagnostic checks for checkout compatibility, configuration, and capacity ledger health.
* Changed: After checkout the confirmation opens at /confirmation/ (or a site's own /…/confirmation/), with no order number or key in the address. A keyed link, after checkout or from an email, is remembered in that browser's session and redirects to the clean address, which shows that order and survives a refresh. Keyed links still work everywhere, so emails open on any device; links a payment gateway added its own parameters to are left alone so it can finish the payment. With no order in the session, /confirmation/ goes back to the menu.
* Changed: Restaurant orders confirm at clean confirmation routes instead of /checkout/order-received/{order}/?key=…, while maintaining gateway thank-you hooks, conversion tracking, and guest email check behavior. Old links keep working. Sites on plain permalinks keep WooCommerce's address.
* Changed: The QR table card photo is recompressed (same size and look), which brings the plugin zip under the common 2 MB upload limit.
* Changed: The order flow's text (menu, item dialog, cart, checkout and confirmation) uses Poppins on every template, in place of each template's own fonts.
* Changed: The WooRestro 0.3 demo's old design files are no longer loaded if a site still enqueues them.
* Fixed: Every order into a part-booked kitchen slot wrote one "WordPress database error Duplicate entry" line to the PHP error log for each position already taken. Those collisions are how a slot is claimed safely, so they are no longer logged; a real database fault while reserving still is.
* Fixed: After activating the plugin (a new install, or switching over from WooRestro), /confirmation/ pages returned "not found" until Settings > Permalinks was opened. Activation now adds the route before WordPress rebuilds its rules.
* Fixed: Switching over from WooRestro by activation left a migration stopped by the settings check stopped; activation now restarts it, as updating already did.
* Fixed: The data migration stopped with "settings could not be saved during migration" whenever it ran in an admin request (Retry migration, or the background runner on admin-ajax). It compared the saved settings with the unsanitized value, and the settings sanitizer always reshapes it. A migration stopped by this runs again after the update.
* Fixed: Template 1 tinted every button in the order panel alike, so Checkout, Place order and the options dialog's Add looked disabled. They are solid in the template's colour again.
* Fixed: Selected times, the chosen payment method, step numbers, quantity steppers and card hovers used a fixed orange instead of the template's colour.
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

= 2.0 =
Major release 2.0 brings HPOS compatibility, Checkout Blocks support, a live Kitchen Order Board, slot reservations, store pages, clean confirmation URLs, and a redesigned modifier engine. An automatic, non-destructive data migration wizard will run on first activation to migrate your 1.x settings, modifiers, and orders.

= 1.3.1 =
Please take a backup of your site before updating the plugin.
