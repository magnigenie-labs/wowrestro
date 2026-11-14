<?php
/**
 * Product editor roundtrip checks. Run: php tests/product-roundtrip-test.php
 *
 * Executes the real workspace callbacks with in-memory WordPress/WooCommerce
 * adapters. A running WooCommerce integration environment is still required
 * to verify stock reconciliation, scheduled sales and database behavior.
 */

define( 'ABSPATH', __DIR__ . '/' );
$test_products = array();
$test_checks = 0;

function __( $text, $domain = '' ) {
	$translations = array( 'Draft' => 'Brouillon', 'Published' => 'Publié', 'In stock' => 'En stock', 'Out of stock' => 'Rupture de stock' );
	return $translations[ $text ] ?? $text;
}
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses_post( $value ) { return (string) $value; }
function wc_format_decimal( $value ) { return (string) $value; }
function wp_get_post_terms( $id, $taxonomy, $args ) { return array(); }
function taxonomy_exists( $taxonomy ) { return false; }
function current_user_can( $capability, ...$args ) { return true; }
function rest_ensure_response( $value ) { return $value; }
function wc_get_product( $id ) { return $GLOBALS['test_products'][ $id ] ?? false; }
function wc_get_formatted_variation( $variation, ...$args ) { return 'Large'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	public $code;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; }
}
class WP_REST_Request {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_json_params() { return $this->data; }
}
class WC_Product {
	public $type = 'simple';
	public $data;
	public function __construct( $id = 7 ) {
		$this->data = array( 'id' => $id, 'name' => 'Dish', 'regular_price' => '12.50', 'sale_price' => '', 'stock_status' => 'outofstock', 'status' => 'draft', 'sku' => '', 'short_description' => '', 'image_id' => 0, 'cross_sell_ids' => array(), 'attributes' => array(), 'children' => array(), 'total_sales' => 0 );
	}
	public function is_type( $type ) { return in_array( $this->type, (array) $type, true ); }
	public function is_in_stock() { return 'outofstock' !== $this->data['stock_status']; }
	public function get_meta( $key, $single = true ) { return ''; }
	public function update_meta_data( $key, $value ) {}
	public function save() { return $this->data['id']; }
	public function get_variation_regular_price( $min ) { return '12.50'; }
	public function get_variation_sale_price( $min ) { return '12.50'; }
	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'get_' ) ) { return $this->data[ substr( $name, 4 ) ] ?? ''; }
		if ( 0 === strpos( $name, 'set_' ) ) { $this->data[ substr( $name, 4 ) ] = $args[0]; return; }
		throw new RuntimeException( 'Unexpected product method: ' . $name );
	}
}
class WC_Product_Variable extends WC_Product {
	public $type = 'variable';
	public static function sync( $id ) {}
}
class WC_Product_Variation extends WC_Product { public $type = 'variation'; }
class WowRestro_Products {
	const MENU_ITEM_META = '_wowrestro_menu_item';
	public static function labels( $product ) { return array( 'dietary' => array(), 'allergens' => array() ); }
}
class WowRestro_Modifiers {
	const PRODUCT_GROUPS_META = '_wowrestro_modifier_groups';
	public static function assigned_group_ids( $id ) { return array(); }
}
class WowRestro_Fulfillment {
	public static function settings() { return array( 'dietary_attribute' => 'pa_dietary' ); }
}

require dirname( __DIR__ ) . '/includes/class-wowrestro-app.php';

function check( $condition, $message ) {
	++$GLOBALS['test_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function product_row( $product ) {
	$method = new ReflectionMethod( 'WowRestro_App', 'product_row' );
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	return $method->invoke( null, $product );
}
function save_product( $product, $changes = array() ) {
	$GLOBALS['test_products'][ $product->get_id() ] = $product;
	return WowRestro_App::save_product( new WP_REST_Request( array_merge( array(
		'id' => $product->get_id(), 'type' => $product->type, 'name' => 'Updated dish',
		'price' => $product->get_regular_price(), 'sale' => $product->get_sale_price(),
		'stockStatus' => $product->get_stock_status(), 'postStatus' => $product->get_status(),
	), $changes ) ) );
}

// Empty prices must stay distinguishable from a legitimate zero price.
foreach ( array( '', '0', '0.10', '12.50', '1.234' ) as $amount ) {
	$product = new WC_Product();
	$product->set_regular_price( $amount );
	$product->set_sale_price( $amount );
	$row = product_row( $product );
	check( $amount === $row['regularPrice'], 'Editable regular price must preserve ' . var_export( $amount, true ) );
	check( $amount === $row['salePrice'], 'Editable sale price must preserve ' . var_export( $amount, true ) );
	$saved = save_product( $product, array( 'price' => $row['regularPrice'], 'sale' => $row['salePrice'] ) );
	check( ! is_wp_error( $saved ) && $amount === $saved['salePrice'], 'Sale price must survive read/edit/save unchanged.' );
}

// Translated labels are presentation only; saved status keys remain canonical.
foreach ( array( 'instock', 'outofstock', 'onbackorder' ) as $stock ) {
	foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $status ) {
		$product = new WC_Product();
		$product->set_stock_status( $stock );
		$product->set_status( $status );
		$row = product_row( $product );
		check( $stock === $row['stockStatus'] && $status === $row['postStatus'], 'Canonical stock/status must survive serialization in a translated locale.' );
		$saved = save_product( $product, array( 'stock' => $row['stock'], 'status' => $row['status'] ) );
		check( $stock === $saved['stockStatus'] && $status === $saved['postStatus'], 'Editing a translated product must not change stock or publish it.' );
	}
}

// An already-open legacy workspace can still submit translated display values.
$product = new WC_Product();
$saved = save_product( $product, array( 'stockStatus' => '', 'postStatus' => '', 'stock' => __( 'Out of stock' ), 'status' => __( 'Draft' ) ) );
check( 'outofstock' === $saved['stockStatus'] && 'draft' === $saved['postStatus'], 'Legacy translated labels must not restock or publish a draft.' );
$saved = save_product( $product, array( 'stockStatus' => '', 'postStatus' => '', 'stock' => 'Out of stock', 'status' => 'Draft' ) );
check( 'outofstock' === $saved['stockStatus'] && 'draft' === $saved['postStatus'], 'Legacy English labels remain accepted.' );

// The same stock protection applies to existing variations.
$parent = new WC_Product_Variable();
$parent->data['children'] = array( 8 );
$variation = new WC_Product_Variation( 8 );
$variation->data['parent_id'] = 7;
$test_products[8] = $variation;
foreach ( array( 'instock', 'outofstock', 'onbackorder' ) as $stock ) {
	$variation->set_stock_status( $stock );
	$row = product_row( $parent );
	check( $stock === $row['variations'][0]['stockStatus'], 'Variation stock must serialize as a stable key.' );
	$saved = save_product( $parent, array( 'variations' => array( array( 'id' => 8, 'price' => '12.50', 'sale' => '0', 'stockStatus' => $stock ) ) ) );
	check( ! is_wp_error( $saved ) && $stock === $variation->get_stock_status(), 'Saving a variation must retain stock status.' );
	check( '0' === $variation->get_sale_price(), 'Saving a variation must preserve a zero sale price.' );
}
$saved = save_product( $parent, array( 'variations' => array( array( 'id' => 8, 'price' => '12.50', 'sale' => '', 'stock' => __( 'Out of stock' ) ) ) ) );
check( 'outofstock' === $variation->get_stock_status(), 'Legacy translated variation stock must remain out of stock.' );

echo 'Product roundtrip checks passed: ' . $test_checks . PHP_EOL;
