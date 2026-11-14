<?php
/** Local integration fixture for the Store API's 100-product page limit. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	exit( 'Local WP-CLI only.' );
}
$term = get_term_by( 'slug', 'wr-catalog-pagination', 'product_cat' );
$category_id = $term ? $term->term_id : wp_insert_term( 'Catalog pagination QA', 'product_cat', array( 'slug' => 'wr-catalog-pagination' ) )['term_id'];
for ( $i = 0; $i <= 101; $i++ ) {
	$sku = sprintf( 'wr-catalog-pagination-%03d', $i );
	$product = new WC_Product_Simple( wc_get_product_id_by_sku( $sku ) );
	$product->set_name( $i ? sprintf( 'Catalog pagination QA dish %03d', $i ) : 'ZZ Catalog pagination last dish' );
	$product->set_sku( $sku );
	$product->set_regular_price( '2.75' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_category_ids( array( $category_id ) );
	if ( ! $i ) $product->set_date_created( '2020-01-01T00:00:00' );
	$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
	$product->save();
}
$page = get_page_by_path( 'catalog-pagination-qa' );
$page_id = wp_insert_post( array(
	'ID' => $page ? $page->ID : 0,
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Catalog pagination QA',
	'post_name' => 'catalog-pagination-qa',
	'post_content' => '[wowrestro_menu category="wr-catalog-pagination"]',
) );
echo wp_json_encode( array( 'menu_url' => get_permalink( $page_id ), 'expected_products' => 102, 'last_sku' => 'wr-catalog-pagination-000' ) ) . "\n";
