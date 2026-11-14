<?php
/** Local integration fixture: wp eval-file tests/integration/addon-pagination-seed.php */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 'Local WP-CLI only.' );
}

function wr_pagination_category( $slug, $name ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term ) {
		return $term->term_id;
	}
	$created = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}
	return $created['term_id'];
}

$padding_category = wr_pagination_category( 'wr-pagination-padding', 'Pagination QA padding' );
$target_category  = wr_pagination_category( 'wr-pagination-target', 'Pagination QA target' );
for ( $i = 1; $i <= 64; $i++ ) {
	$sku = sprintf( 'wr-pagination-padding-%02d', $i );
	$product = new WC_Product_Simple( wc_get_product_id_by_sku( $sku ) );
	$product->set_name( sprintf( 'A Pagination QA padding %02d', $i ) );
	$product->set_sku( $sku );
	$product->set_regular_price( '1' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_category_ids( array( $padding_category ) );
	$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
	$product->save();
}
$groups = get_posts( array( 'post_type' => WowRestro_Modifiers::POST_TYPE, 'name' => 'wr-pagination-required-extra', 'post_status' => 'any', 'numberposts' => 1 ) );
$group_id = wp_insert_post( array(
	'ID' => $groups ? $groups[0]->ID : 0,
	'post_type' => WowRestro_Modifiers::POST_TYPE,
	'post_status' => 'publish',
	'post_title' => 'Pagination required extra',
	'post_name' => 'wr-pagination-required-extra',
) );
WowRestro_Modifiers::save_schema( $group_id, 'single', 1, 1, array( array( 'id' => 'decimal-extra', 'label' => 'Required decimal extra', 'price' => '1.25', 'default' => false ) ) );
$target = new WC_Product_Simple( wc_get_product_id_by_sku( 'wr-pagination-target' ) );
$target->set_name( 'ZZ Pagination required extra dish' );
$target->set_sku( 'wr-pagination-target' );
$target->set_regular_price( '12.50' );
$target->set_status( 'publish' );
$target->set_catalog_visibility( 'visible' );
$target->set_category_ids( array( $target_category ) );
$target->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
$target->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array( $group_id ) );
$target->save();
$page = get_page_by_path( 'addon-pagination-qa' );
$page_id = wp_insert_post( array(
	'ID' => $page ? $page->ID : 0,
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Add-on pagination QA',
	'post_name' => 'addon-pagination-qa',
	'post_content' => '[wowrestro_menu category="wr-pagination-target"]',
) );
$catalog_page = get_page_by_path( 'addon-pagination-catalog-qa' );
$catalog_page_id = wp_insert_post( array(
	'ID' => $catalog_page ? $catalog_page->ID : 0,
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'Add-on catalog visibility QA',
	'post_name' => 'addon-pagination-catalog-qa',
	'post_content' => '[wowrestro_menu]',
) );
echo wp_json_encode( array( 'product_id' => $target->get_id(), 'group_id' => $group_id, 'page_id' => $page_id, 'menu_url' => get_permalink( $page_id ), 'catalog_url' => get_permalink( $catalog_page_id ), 'expected_price' => '13.75' ) ) . "\n";
