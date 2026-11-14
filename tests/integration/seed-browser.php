<?php
/** Seed a disposable local WordPress/WooCommerce site for real browser checkout tests. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Run only with WP-CLI on a disposable local test site.' );
}
wp_set_current_user( 1 );
foreach ( array(
	'timezone_string' => 'Asia/Kolkata', 'permalink_structure' => '/%postname%/',
	'woocommerce_currency' => 'GBP', 'woocommerce_price_num_decimals' => 2,
	'woocommerce_calc_taxes' => 'no', 'woocommerce_prices_include_tax' => 'no',
	'woocommerce_default_customer_address' => 'base', 'woocommerce_enable_guest_checkout' => 'yes',
	'woocommerce_enable_signup_and_login_from_checkout' => 'no', 'woocommerce_coming_soon' => 'no',
	'woocommerce_allowed_countries' => 'all', 'woocommerce_ship_to_countries' => '',
	'wowrestro_onboarding_pending' => 'no',
) as $key => $value ) {
	update_option( $key, $value );
}
WowRestro_Onboarding::save_profile( array(
	'name' => 'QA Kitchen', 'email' => 'kitchen@example.test', 'phone' => '+442079460123',
	'address_1' => '10 Test Street', 'address_2' => '', 'city' => 'London',
	'state' => '', 'postcode' => 'SW1A 1AA', 'country' => 'GB', 'logo_id' => 0,
) );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Cash on delivery', 'description' => 'Pay on collection or delivery.', 'enable_for_virtual' => 'yes', 'enable_for_methods' => array() ) );
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Bank transfer', 'description' => 'Local test bank transfer.' ) );
$settings = WowRestro_Fulfillment::settings();
foreach ( array( 'pickup', 'delivery' ) as $mode ) {
	foreach ( range( 1, 7 ) as $day ) {
		$settings['service_hours'][ $mode ][ $day ] = array( 'enabled' => 'yes', 'periods' => '00:00-23:59' );
	}
}
$settings = array_merge( $settings, array( 'pickup_enabled' => 'yes', 'delivery_enabled' => 'yes', 'asap_enabled' => 'yes', 'prep_minutes' => 5, 'delivery_lead_minutes' => 0, 'slot_interval' => 15, 'preorder_days' => 7, 'slot_capacity' => 50, 'holidays' => '', 'date_overrides' => '', 'orders_paused' => 'no', 'tips_enabled' => 'yes', 'custom_tips' => 'yes' ) );
update_option( 'wowrestro_settings', WowRestro_Settings::sanitize( $settings ) );
$zone = new WC_Shipping_Zone( 0 );
foreach ( $zone->get_shipping_methods() as $method ) {
	$zone->delete_shipping_method( $method->instance_id );
}
$pickup = $zone->add_shipping_method( 'local_pickup' );
$delivery = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_local_pickup_' . $pickup . '_settings', array( 'title' => 'Pickup at QA Kitchen', 'cost' => '0', 'tax_status' => 'taxable' ) );
update_option( 'woocommerce_flat_rate_' . $delivery . '_settings', array( 'title' => 'QA Delivery', 'cost' => '3.50', 'tax_status' => 'taxable' ) );
WC_Cache_Helper::get_transient_version( 'shipping', true );
WC_Install::create_pages();
$pages = array();
foreach ( array( 'qa-menu' => array( 'QA Menu', '[wowrestro_menu]' ), 'qa-reservations' => array( 'QA Reservations', '[wowrestro_reservations]' ) ) as $slug => $data ) {
	$existing = get_page_by_path( $slug );
	$pages[ $slug ] = wp_insert_post( array( 'ID' => $existing ? $existing->ID : 0, 'post_title' => $data[0], 'post_name' => $slug, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $data[1] ) );
}
update_option( 'wowrestro_menu_page_id', $pages['qa-menu'] );
$term = term_exists( 'qa-browser', 'product_cat' );
$category = $term ? (int) $term['term_id'] : (int) wp_insert_term( 'QA Browser', 'product_cat', array( 'slug' => 'qa-browser' ) )['term_id'];
$group = wp_insert_post( array( 'post_type' => WowRestro_Modifiers::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'QA Decimal Extras' ) );
WowRestro_Modifiers::save_schema( $group, 'single', 1, 1, array( array( 'id' => 'qa-cheese', 'label' => 'Extra cheese', 'price' => '1.25' ), array( 'id' => 'qa-free', 'label' => 'No extra', 'price' => '0' ) ) );
$products = array();
foreach ( array( 'QA Decimal Pizza' => '12.50', 'QA Plain Soup' => '4.25' ) as $name => $price ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( $price );
	$product->set_category_ids( array( $category ) );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 100 );
	$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
	if ( 'QA Decimal Pizza' === $name ) { $product->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array( $group ) ); }
	$products[ $name ] = $product->save();
}
$variable = new WC_Product_Variable();
$variable->set_name( 'QA Variable Pizza' );
$variable->set_status( 'publish' );
$variable->set_category_ids( array( $category ) );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Large' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
$variable->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array( $group ) );
$products['QA Variable Pizza'] = $variable->save();
foreach ( array( 'Small' => '10.00', 'Large' => '14.25' ) as $size => $price ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_attributes( array( 'size' => $size ) );
	$variation->set_regular_price( $price );
	$variation->set_status( 'publish' );
	$variation->set_stock_status( 'instock' );
	$products[ 'QA ' . $size ] = $variation->save();
}
WC_Product_Variable::sync( $variable->get_id() );
WowRestro_Storefront::confirmation_route();
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules();
WP_CLI::line( wp_json_encode( array( 'products' => $products, 'group' => $group, 'pages' => $pages, 'pickup' => 'local_pickup:' . $pickup, 'delivery' => 'flat_rate:' . $delivery, 'checkout' => wc_get_checkout_url() ), JSON_PRETTY_PRINT ) );
