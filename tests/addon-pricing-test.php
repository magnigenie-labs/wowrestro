<?php
/**
 * Add-on save/edit/cart regression checks. Run: php tests/addon-pricing-test.php
 *
 * The plugin code runs unchanged against in-memory WordPress/WooCommerce adapters.
 * These checks do not replace integration tests against a running WooCommerce site.
 */

define( 'ABSPATH', __DIR__ . '/' );
$test_meta = array();
$test_posts = array();
$test_precision = 2;
$test_checks = 0;

function __( $text, $domain = '' ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_title( $value ) { return sanitize_key( str_replace( ' ', '-', $value ) ); }
function wp_generate_uuid4() { static $id = 0; return '00000000-0000-4000-8000-' . str_pad( (string) ++$id, 12, '0', STR_PAD_LEFT ); }
function wc_get_price_decimals() { return $GLOBALS['test_precision']; }
function wc_format_decimal( $value, $precision = false ) {
	// Model WooCommerce's decimal formatting contract for ordinary numeric inputs.
	$value = (float) str_replace( ',', '.', (string) $value );
	return false === $precision ? (string) $value : number_format( $value, $precision, '.', '' );
}
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['test_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['test_meta'][ $id ][ $key ] = $value; }
function get_post( $id ) { return $GLOBALS['test_posts'][ $id ] ?? null; }
function get_post_type( $id ) { return get_post( $id )->post_type ?? null; }
function get_the_title( $post ) { return $post->post_title; }
function current_user_can( $capability, ...$args ) { return true; }
function get_edit_post_link( $id, $context = '' ) { return '/post.php?post=' . $id; }
function rest_ensure_response( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_list_pluck( $rows, $field ) { return array_map( static function ( $row ) use ( $field ) { return $row[ $field ]; }, $rows ); }
function wp_insert_post( $data, $wp_error = false ) {
	$id = $data['ID'] ?? count( $GLOBALS['test_posts'] ) + 100;
	$post = new WP_Post();
	$post->ID = $id;
	$post->post_type = $data['post_type'];
	$post->post_status = $data['post_status'];
	$post->post_title = $data['post_title'];
	$GLOBALS['test_posts'][ $id ] = $post;
	return $id;
}
class WP_Post { public $ID; public $post_type; public $post_status; public $post_title; }
class WP_REST_Request {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_json_params() { return $this->data; }
}
class WP_Error {
	public $code;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WC_Product {
	private $price;
	public function __construct( $price ) { $this->price = $price; }
	public function get_id() { return 7; }
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_price() { return $this->price; }
	public function set_price( $price ) { $this->price = $price; }
}
class WC_Cart {
	public $cart_contents;
	public function get_cart() { return $this->cart_contents; }
}
class WowRestro_Products {
	public static function is_menu_item( $product ) { return $product instanceof WC_Product; }
}

require dirname( __DIR__ ) . '/includes/class-wowrestro-modifiers.php';
require dirname( __DIR__ ) . '/includes/class-wowrestro-cart.php';
require dirname( __DIR__ ) . '/includes/class-wowrestro-app.php';

function check( $condition, $message ) {
	++$GLOBALS['test_checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function same_price( $expected, $actual, $message ) { check( abs( $expected - (float) $actual ) < 0.000001, $message ); }

// Exercise the actual workspace REST callback, persistence, and editor response.
$saved = WowRestro_App::save_modifier( new WP_REST_Request( array(
	'name' => 'Extras', 'type' => 'multiple', 'min' => 0, 'max' => 0,
	'options' => array(
		array( 'label' => 'Cheese', 'price' => '1.25' ),
		array( 'label' => 'Sauce', 'price' => '0.10' ),
		array( 'label' => 'Onions', 'price' => '0' ),
	),
) ) );
check( ! is_wp_error( $saved ), 'Decimal add-ons should save successfully.' );
same_price( 1.25, $saved['optionRows'][0]['price'], 'Workspace response must preserve decimal prices.' );
same_price( 0.1, $saved['optionRows'][1]['price'], 'Small decimal prices must not truncate to zero.' );
same_price( 0, $saved['optionRows'][2]['price'], 'Free options must remain free.' );
$group_id = $saved['id'];
$original_ids = array_column( $saved['optionRows'], 'id' );

$edited_options = $saved['optionRows'];
$edited_options[0]['label'] = 'Extra cheese';
$edited_options[0]['price'] = '2.35';
$edited = WowRestro_App::save_modifier( new WP_REST_Request( array(
	'id' => $group_id, 'name' => 'Extras', 'type' => 'multiple', 'min' => 0, 'max' => 0,
	'options' => $edited_options,
) ) );
check( $original_ids === array_column( $edited['optionRows'], 'id' ), 'Editing a label or price must retain option IDs used by carts.' );
same_price( 2.35, $edited['optionRows'][0]['price'], 'Updating an existing add-on must preserve decimals.' );
$canonical = WowRestro_Modifiers::group( $group_id );
$text = WowRestro_Modifiers::options_text( $canonical['options'] );
check( $canonical['options'] === WowRestro_Modifiers::sanitize_options( $text ), 'Native text editor roundtrip must preserve IDs, defaults, and prices.' );

// Selected IDs must get current server prices, even if a client sends a price.
update_post_meta( 7, WowRestro_Modifiers::PRODUCT_GROUPS_META, array( $group_id ) );
$product = new WC_Product( 10.50 );
$selection = array( $group_id => array( array( 'id' => $original_ids[0], 'price' => 0 ), $original_ids[1] ) );
$prepared = WowRestro_Cart::prepare_cart_item_data( $product, $selection );
check( ! is_wp_error( $prepared ), 'Valid decimal options must be available to the cart.' );
same_price( 2.45, WowRestro_Modifiers::snapshot_total( $prepared['wowrestro_modifier_snapshot'] ), 'Cart modifier delta must use server prices.' );
$cart = new WC_Cart();
$cart->cart_contents = array( 'line' => array_merge( $prepared, array( 'data' => $product ) ) );
WowRestro_Cart::price_modifiers( $cart );
same_price( 12.95, $product->get_price(), 'Cart unit price must include decimal options.' );
WowRestro_Cart::price_modifiers( $cart );
same_price( 12.95, $product->get_price(), 'Repeated totals calculation must not add options twice.' );

// A changed price must refresh without accumulating the old modifier delta.
$edited_options[0]['price'] = '1.15';
WowRestro_Modifiers::save_schema( $group_id, 'multiple', 0, 0, $edited_options );
WowRestro_Cart::price_modifiers( $cart );
same_price( 11.75, $product->get_price(), 'Existing cart must refresh changed decimal prices.' );
$invalid = WowRestro_Modifiers::validate_selection( $product, array( $group_id => array( 'deleted-option' ) ) );
check( is_wp_error( $invalid ), 'Missing options must fail validation.' );

// Respect stores configured with three decimal places or no fractional currency.
foreach ( array( 3 => 1.235, 0 => 1.0 ) as $precision => $expected ) {
	$test_precision = $precision;
	$rows = WowRestro_Modifiers::sanitize_options( array( array( 'label' => 'Precision', 'price' => '1.2346' ) ) );
	same_price( $expected, $rows[0]['price'], 'Configured currency precision must be applied.' );
}

echo 'Passed ' . $test_checks . " add-on pricing checks.\n";
