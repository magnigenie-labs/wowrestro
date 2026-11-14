<?php
/**
 * Run against an isolated real WordPress/WooCommerce installation:
 * wp --path=/path/to/local/wordpress eval-file /path/to/wowrestro/tests/integration/regression.php
 *
 * Creates QA records and restores settings/deletes those records on completion.
 * No WordPress or WooCommerce functions are mocked. Browser checkout is tested separately.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Run only with WP-CLI on an isolated local installation.' );
}
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) || ! class_exists( 'WowRestro_App' ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'A localhost WordPress installation with WooCommerce and WowRestro active is required.' );
}

$wr_qa_checks = array();
$wr_qa_posts = array();
$wr_qa_orders = array();
$wr_qa_users = array();
$wr_qa_options = array();
$wr_qa_original_user = get_current_user_id();
foreach ( array( 'wowrestro_settings', 'woocommerce_calc_taxes', 'woocommerce_price_num_decimals', 'timezone_string' ) as $key ) {
	$wr_qa_options[ $key ] = get_option( $key, null );
}
$wr_qa_assert = static function ( $condition, $message ) use ( &$wr_qa_checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	$wr_qa_checks[] = $message;
};
$wr_qa_request = static function ( $route, $body, $method = 'POST' ) {
	$request = new WP_REST_Request( $method, '/wowrestro/v1' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
};
$wr_qa_now = static function () { return new DateTimeImmutable( '2026-11-16 08:00:00', wp_timezone() ); };
$wr_qa_mail = static function () { return true; };
add_filter( 'pre_wp_mail', $wr_qa_mail );
add_filter( 'wowrestro_now', $wr_qa_now );

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$wr_qa_assert( ! empty( $admins ), 'Administrator exists' );
	$admin_id = (int) reset( $admins );
	wp_set_current_user( $admin_id );
	$wr_qa_assert( current_user_can( 'wowrestro_manage' ) && current_user_can( 'wowrestro_manage_modifiers' ), 'Plugin capabilities are installed on administrator role' );
	$wr_qa_assert( WowRestro_Slot_Reservations::table_ready(), 'Actual slot-reservation database table is ready' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_price_num_decimals', 2 );
	update_option( 'timezone_string', 'Asia/Kolkata' );
	$settings = WowRestro_Fulfillment::defaults();
	$settings['delivery_enabled'] = 'yes';
	$settings['delivery_lead_minutes'] = 0;
	$settings['preorder_days'] = 0;
	$settings['late_grace_minutes'] = 0;
	$settings['service_hours']['pickup'][1] = array( 'enabled' => 'yes', 'periods' => '10:00-11:00,17:00-18:00' );
	$settings['service_hours']['delivery'][1] = array( 'enabled' => 'yes', 'periods' => '12:00-13:00' );
	$settings['service_hours']['pickup'][2] = array( 'enabled' => 'no', 'periods' => '09:00-12:00' );
	$response = $wr_qa_request( '/workspace/settings', array( 'settings' => $settings ) );
	$wr_qa_assert( 200 === $response->get_status(), 'Administrator saves service settings through actual REST dispatch' );
	$saved = $response->get_data()['settings'];
	$stored = get_option( 'wowrestro_settings' );
	$wr_qa_assert( $settings['service_hours'] === $stored['service_hours'], 'Pickup/delivery split hours persist in the actual database' );
	foreach ( array( 'preorder_days', 'delivery_lead_minutes', 'late_grace_minutes' ) as $key ) {
		$wr_qa_assert( 0 === $stored[ $key ], 'Zero setting persists: ' . $key );
	}
	$pickup = WowRestro_Fulfillment::available_slots( 'pickup', '2026-11-16' );
	$slot_times = static function ( $slots ) { return array_map( static function ( $slot ) { return ( new DateTimeImmutable( $slot['value'] ) )->setTimezone( wp_timezone() )->format( 'H:i' ); }, $slots ); };
	$wr_qa_assert( array( '10:00', '10:15', '10:30', '10:45', '17:00', '17:15', '17:30', '17:45' ) === $slot_times( $pickup ), 'Actual capacity queries return only the two pickup shifts in the site timezone' );
	$delivery = WowRestro_Fulfillment::available_slots( 'delivery', '2026-11-16' );
	$wr_qa_assert( array( '12:00', '12:15', '12:30', '12:45' ) === $slot_times( $delivery ), 'Delivery slots use their independently saved hours' );
	$wr_qa_assert( empty( WowRestro_Fulfillment::available_slots( 'pickup', '2026-11-17' ) ), 'Closed day offers no slots' );
	$before = get_option( 'wowrestro_settings' );
	$response = $wr_qa_request( '/workspace/settings', array( 'settings' => array( 'service_hours' => array( 'pickup' => array( 1 => array( 'periods' => '10:00-12:00,11:00-13:00' ) ) ) ) ) );
	$wr_qa_assert( 400 === $response->get_status(), 'Overlapping hours fail with REST HTTP 400' );
	$wr_qa_assert( $before === get_option( 'wowrestro_settings' ), 'Rejected hours do not modify database settings' );
	$response = $wr_qa_request( '/workspace/settings', array( 'settings' => array( 'service_hours' => array( 'pickup' => array( 3 => array( 'enabled' => 'no' ) ) ) ) ) );
	$wr_qa_assert( 200 === $response->get_status() && $before['service_hours']['delivery'] === get_option( 'wowrestro_settings' )['service_hours']['delivery'], 'Partial schedule edits preserve delivery in the database' );

	// Actual REST route permission callbacks run for anonymous and subscriber users.
	$subscriber = wp_insert_user( array( 'user_login' => 'wr-qa-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 24, true ), 'role' => 'subscriber', 'user_email' => 'qa-' . wp_generate_password( 8, false ) . '@example.test' ) );
	$wr_qa_assert( ! is_wp_error( $subscriber ), 'Temporary subscriber created' );
	$wr_qa_users[] = $subscriber;
	foreach ( array( 0, $subscriber ) as $user_id ) {
		wp_set_current_user( $user_id );
		foreach ( array( '/workspace/settings', '/workspace/modifier', '/workspace/product' ) as $route ) {
			$response = $wr_qa_request( $route, array() );
			$wr_qa_assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'REST denies ' . ( $user_id ? 'subscriber' : 'anonymous' ) . ' access to ' . $route );
		}
	}
	wp_set_current_user( $admin_id );

	$modifier = array( 'name' => 'QA Decimal extras', 'type' => 'single', 'min' => 1, 'max' => 1, 'options' => array( array( 'id' => 'qa_cheese', 'label' => 'QA Extra cheese', 'price' => '1.25' ), array( 'id' => 'qa_sauce', 'label' => 'QA Free sauce', 'price' => '0' ) ), 'status' => 'Published' );
	$response = $wr_qa_request( '/workspace/modifier', $modifier );
	$wr_qa_assert( 200 === $response->get_status(), 'Decimal add-on created through actual REST dispatch' );
	$group = $response->get_data();
	$group_id = $group['id'];
	$wr_qa_posts[] = $group_id;
	$modifier['id'] = $group_id;
	$wr_qa_assert( 1.25 === (float) $group['optionRows'][0]['price'], 'Add-on REST response retains decimals' );
	$wr_qa_assert( 1.25 === (float) WowRestro_Modifiers::group( $group_id )['options'][0]['price'], 'Add-on database reload retains decimals' );

	$simple = new WC_Product_Simple();
	$simple->set_name( 'QA Decimal pizza' );
	$simple->set_status( 'publish' );
	$simple->set_regular_price( '12.50' );
	$simple->set_virtual( true );
	$simple->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
	$simple->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array( $group_id ) );
	$wr_qa_posts[] = $simple->save();
	$parent = new WC_Product_Variable();
	$parent->set_name( 'QA Variable pizza' );
	$parent->set_status( 'publish' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array( 'Large' ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent->set_attributes( array( $attribute ) );
	$parent->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
	$parent->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array( $group_id ) );
	$wr_qa_posts[] = $parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_status( 'publish' );
	$variation->set_attributes( array( 'size' => 'Large' ) );
	$variation->set_regular_price( '15.00' );
	$variation->set_virtual( true );
	$wr_qa_posts[] = $variation->save();
	WC_Product_Variable::sync( $parent->get_id() );
	$selection = array( $group_id => array( 'qa_cheese' ) );
	$wr_qa_assert( is_wp_error( WowRestro_Modifiers::validate_selection( $simple, array() ) ), 'Missing required option is rejected against real product metadata' );
	$wr_qa_assert( is_wp_error( WowRestro_Modifiers::validate_selection( $simple, array( $group_id => array( 'unknown' ) ) ) ), 'Unknown option is rejected against real product metadata' );
	$wr_qa_assert( 1.25 === WowRestro_Modifiers::snapshot_total( WowRestro_Modifiers::validate_selection( $variation, $selection ) ), 'Variation inherits parent add-on group and decimal price' );
	if ( ! WC()->session ) { WC()->initialize_session(); }
	if ( ! WC()->customer ) { WC()->customer = new WC_Customer( 0, true ); }
	WC()->cart = new WC_Cart();
	WC()->cart->empty_cart();
	$line = WowRestro_Cart::prepare_cart_item_data( $simple, $selection, 'QA no onions' );
	$key = WC()->cart->add_to_cart( $simple->get_id(), 2, 0, array(), $line );
	$wr_qa_assert( false !== $key, 'Real WooCommerce cart accepts configured simple product' );
	$line = WowRestro_Cart::prepare_cart_item_data( $variation, $selection );
	$variation_key = WC()->cart->add_to_cart( $parent->get_id(), 1, $variation->get_id(), array( 'attribute_size' => 'Large' ), $line );
	$wr_qa_assert( false !== $variation_key, 'Real WooCommerce cart accepts configured variation' );
	WC()->cart->calculate_totals();
	$wr_qa_assert( abs( 43.75 - (float) WC()->cart->get_total( 'edit' ) ) < 0.00001, 'Real cart totals equal (12.50 + 1.25) × 2 + (15.00 + 1.25)' );
	WC()->cart->calculate_totals();
	$wr_qa_assert( abs( 43.75 - (float) WC()->cart->get_total( 'edit' ) ) < 0.00001, 'Repeated real cart totals do not double-charge add-ons' );
	$order = new WC_Order();
	$order->set_created_via( 'wowrestro-local-qa' );
	$order->set_status( 'pending' );
	$wr_qa_orders[] = $order->save();
	WC()->checkout()->create_order_line_items( $order, WC()->cart );
	$order->calculate_totals();
	$order->save();
	$reloaded_order = wc_get_order( $order->get_id() );
	$wr_qa_assert( abs( 43.75 - (float) $reloaded_order->get_total() ) < 0.00001, 'WooCommerce checkout line-item creation persists exact decimal order total' );
	$items = array_values( $reloaded_order->get_items() );
	$wr_qa_assert( count( $items ) === 2, 'Order reload contains both simple and variation lines' );
	foreach ( $items as $item ) {
		$wr_qa_assert( 1.25 === WowRestro_Modifiers::snapshot_total( $item->get_meta( '_wowrestro_modifier_snapshot', true ) ), 'Order line persists trusted decimal add-on snapshot' );
	}
	$wr_qa_assert( 'QA no onions' === $items[0]->get_meta( '_wowrestro_special_note', true ), 'Order line retains special instructions' );
	$modifier['options'][0]['price'] = '1.50';
	$response = $wr_qa_request( '/workspace/modifier', $modifier );
	$wr_qa_assert( 200 === $response->get_status() && $group['optionRows'][0]['id'] === $response->get_data()['optionRows'][0]['id'], 'Editing an add-on price preserves its stable option ID' );
	WC()->cart->calculate_totals();
	$wr_qa_assert( abs( 44.50 - (float) WC()->cart->get_total( 'edit' ) ) < 0.00001, 'Open real cart revalidates edited add-on prices before totals' );
	$wr_qa_assert( abs( 43.75 - (float) wc_get_order( $order->get_id() )->get_total() ) < 0.00001, 'Previously saved order price remains immutable after an add-on edit' );

	// Exercise the actual product edit route against persisted WC objects.
	$product_body = array( 'id' => $simple->get_id(), 'name' => 'QA Decimal pizza', 'type' => 'simple', 'price' => '12.50', 'sale' => '0', 'stockStatus' => 'outofstock', 'postStatus' => 'draft', 'modifierGroups' => array( $group_id ) );
	$response = $wr_qa_request( '/workspace/product', $product_body );
	$wr_qa_assert( 200 === $response->get_status(), 'Actual product edit route saves zero sale and canonical statuses' );
	$edited = wc_get_product( $simple->get_id() );
	$wr_qa_assert( '0' === $edited->get_sale_price( 'edit' ), 'WooCommerce product reload retains zero sale price' );
	$wr_qa_assert( 'outofstock' === $edited->get_stock_status() && 'draft' === $edited->get_status(), 'WooCommerce product reload retains stock/publication states' );
	$wr_qa_assert( '0' === $response->get_data()['salePrice'], 'Product API preserves zero sale as an editable string' );
	$product_body['sale'] = '';
	$product_body['stockStatus'] = 'onbackorder';
	$product_body['postStatus'] = 'private';
	$response = $wr_qa_request( '/workspace/product', $product_body );
	$wr_qa_assert( 200 === $response->get_status() && '' === $response->get_data()['salePrice'], 'Empty sale price remains distinguishable from zero through REST and database' );
	$wr_qa_assert( 'onbackorder' === $response->get_data()['stockStatus'] && 'private' === $response->get_data()['postStatus'], 'Backorder/private values roundtrip through actual WooCommerce CRUD' );
} finally {
	if ( WC()->cart ) { WC()->cart->empty_cart(); }
	foreach ( $wr_qa_orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) { $order->delete( true ); }
	}
	foreach ( array_reverse( $wr_qa_posts ) as $id ) { wp_delete_post( $id, true ); }
	if ( $wr_qa_users ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $wr_qa_users as $id ) { wp_delete_user( $id ); }
	}
	foreach ( $wr_qa_options as $key => $value ) {
		if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); }
	}
	remove_filter( 'wowrestro_now', $wr_qa_now );
	remove_filter( 'pre_wp_mail', $wr_qa_mail );
	wp_set_current_user( $wr_qa_original_user );
}
WP_CLI::success( 'Real WordPress/WooCommerce integration checks passed: ' . count( $wr_qa_checks ) );
echo wp_json_encode( array( 'checks' => $wr_qa_checks, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT ) . "\n";
