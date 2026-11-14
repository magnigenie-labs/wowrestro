<?php
/** Real WooCommerce cart/REST checks with process-local settings and shipping-rate filters. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Run only through WP-CLI on an isolated localhost installation.' );
}
$checks = array();
$orders = array();
$product = null;
$assert = static function ( $condition, $message, $details = null ) use ( &$checks ) {
	if ( ! $condition ) throw new RuntimeException( $message . ( null !== $details ? ': ' . wp_json_encode( $details ) : '' ) );
	$checks[] = $message;
};
$settings_filter = static function () {
	$settings = WowRestro_Fulfillment::defaults();
	$settings['delivery_enabled'] = 'yes';
	$settings['prep_minutes'] = 0;
	$settings['delivery_lead_minutes'] = 0;
	$settings['slot_capacity'] = 1000;
	foreach ( array( 'pickup', 'delivery' ) as $mode ) {
		for ( $day = 0; $day <= 6; $day++ ) $settings['service_hours'][ $mode ][ $day ] = array( 'enabled' => 'yes', 'periods' => '00:00-23:59' );
	}
	return $settings;
};
$available = 'pickup';
$rates_filter = static function () use ( &$available ) {
	$rates = array();
	if ( 'delivery' !== $available ) $rates['local_pickup:999'] = new WC_Shipping_Rate( 'local_pickup:999', 'QA pickup', 0, array(), 'local_pickup', 999 );
	if ( 'pickup' !== $available ) $rates['flat_rate:999'] = new WC_Shipping_Rate( 'flat_rate:999', 'QA delivery', 3.50, array(), 'flat_rate', 999 );
	return $rates;
};
$capture_order = static function ( $order ) use ( &$orders ) { if ( $order->get_id() ) $orders[] = $order->get_id(); };
add_filter( 'pre_option_wowrestro_settings', $settings_filter );
add_filter( 'woocommerce_package_rates', $rates_filter, 999 );
add_filter( 'woocommerce_cart_ready_to_calc_shipping', '__return_true' );
add_filter( 'pre_wp_mail', '__return_true' );
add_action( 'woocommerce_store_api_checkout_update_order_from_request', $capture_order, 1 );
try {
	if ( ! WC()->session ) WC()->initialize_session();
	WC()->customer = new WC_Customer( 0, true );
	WC()->customer->set_shipping_country( 'GB' );
	WC()->customer->set_shipping_postcode( 'SW1A 1AA' );
	WC()->customer->set_calculated_shipping( true );
	if ( ! WC()->cart ) WC()->initialize_cart();
	WC()->cart->empty_cart();
	$product = new WC_Product_Simple();
	$product->set_name( 'QA shipping-mode regression' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( '20.00' );
	$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
	$product->save();
	$key = WC()->cart->add_to_cart( $product->get_id() );
	$assert( (bool) $key, 'Physical QA product enters the WooCommerce cart', wc_get_notices( 'error' ) );
	WC()->cart->calculate_totals();
	WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:999' ) );
	$assert( '' === WowRestro_Shipping::choose_rate_for_mode( 'delivery' ), 'Unserved delivery does not return the previous pickup rate' );
	WowRestro_Checkout::store_api_update( array( 'mode' => 'delivery' ) );
	$assert( '' === WC()->session->get( 'wowrestro_shipping_rate_id' ), 'Mode update clears stale plugin shipping-rate state' );
	// Reproduce an existing pickup selection surviving a later address/cart update.
	WC()->session->set( 'chosen_shipping_methods', array( 'local_pickup:999' ) );
	WC()->cart->calculate_totals();
	$assert( 'local_pickup:999' === WowRestro_Shipping::selected_rate_id(), 'A valid WooCommerce pickup selection is present before final validation' );
	$context = WowRestro_Shipping::checkout_context( 'local_pickup:999', 'delivery' );
	$assert( is_wp_error( $context ) && 'wowrestro_shipping_unavailable' === $context->get_error_code(), 'Explicit delivery rejects automatic pickup fallback' );
	$native = WowRestro_Shipping::checkout_context( 'local_pickup:999' );
	$assert( ! is_wp_error( $native ) && 'pickup' === $native['mode'], 'Explicit native pickup remains authoritative' );
	$address = array( 'first_name' => 'Shipping', 'last_name' => 'QA', 'email' => 'shipping-qa@example.test', 'phone' => '07700900123', 'address_1' => '10 Test Street', 'address_2' => '', 'city' => 'London', 'state' => '', 'postcode' => 'SW1A 1AA', 'country' => 'GB', 'company' => '' );
	$body = array( 'billing_address' => $address, 'shipping_address' => $address, 'payment_method' => 'cod', 'additional_fields' => array( 'wowrestro/fulfillment-mode' => 'delivery', 'wowrestro/requested-time' => '', 'wowrestro/status-updates' => false ), 'extensions' => array( 'wowrestro' => array( 'fulfillment_mode' => 'delivery' ) ) );
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	$assert( 409 === $response->get_status() && 'wowrestro_shipping_unavailable' === ( $data['code'] ?? '' ), 'Actual Store API checkout rejects unavailable custom delivery with HTTP 409', $data );
	$assert( false !== strpos( $data['message'], 'Delivery is not available' ), 'Customer receives an actionable delivery error' );
	// The native checkout has no explicit custom-mode extension: its selected rate wins.
	$body['extensions'] = new stdClass();
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	$assert( 200 === $response->get_status(), 'Native Store API checkout still succeeds', $data );
	$order = wc_get_order( $data['order_id'] );
	$orders[] = $order->get_id();
	$assert( 'pickup' === $order->get_meta( '_wowrestro_fulfillment' ), 'Native order preserves the intentionally selected pickup mode' );
	$available = 'delivery';
	WC()->shipping()->reset_shipping();
	WC()->session->__unset( 'shipping_for_package_0' );
	WC()->cart->add_to_cart( $product->get_id() );
	WowRestro_Checkout::store_api_update( array( 'mode' => 'delivery' ) );
	WC()->cart->calculate_totals();
	$assert( 'flat_rate:999' === WowRestro_Shipping::selected_rate_id(), 'Matching delivery rate is selected when available' );
	$reverse = WowRestro_Shipping::checkout_context( 'flat_rate:999', 'pickup' );
	$assert( is_wp_error( $reverse ) && 'wowrestro_shipping_unavailable' === $reverse->get_error_code(), 'Explicit pickup cannot silently become delivery' );
	$body['extensions'] = array( 'wowrestro' => array( 'fulfillment_mode' => 'delivery' ) );
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	$assert( 200 === $response->get_status(), 'Actual custom delivery checkout succeeds when the selected rate matches', $data );
	$order = wc_get_order( $data['order_id'] );
	$orders[] = $order->get_id();
	$assert( 'delivery' === $order->get_meta( '_wowrestro_fulfillment' ) && 'flat_rate:999' === $order->get_meta( '_wowrestro_shipping_rate_id' ) && 23.50 === (float) $order->get_total(), 'Custom delivery order keeps the correct mode, shipping rate and total' );
} finally {
	WowRestro_Checkout::set_session_state( 'pickup', '', '' );
	if ( WC()->cart ) WC()->cart->empty_cart();
	$draft = WC()->session ? WC()->session->get( 'store_api_draft_order' ) : 0;
	if ( $draft ) $orders[] = $draft;
	foreach ( array_unique( $orders ) as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) { WowRestro_Slot_Reservations::release_order( $order ); $order->delete( true ); }
	}
	if ( $product && $product->get_id() ) $product->delete( true );
	remove_filter( 'pre_option_wowrestro_settings', $settings_filter );
	remove_filter( 'woocommerce_package_rates', $rates_filter, 999 );
	remove_filter( 'woocommerce_cart_ready_to_calc_shipping', '__return_true' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_action( 'woocommerce_store_api_checkout_update_order_from_request', $capture_order, 1 );
}
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) . "\n";
