<?php
/**
 * WoWRestro Product Listing Start
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/wowrestro-product-listing-start.php.
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

$category_name = '';

if ( !empty( $category_id ) ) {
	$term_details 	= get_term_by( 'id', $category_id, 'product_cat' );
	$category_name 	= $term_details->name;
	$category_title = $term_details->slug;
	$category_desc 	= $term_details->description;
}

?>

<!--  Food Category Menu -->
<div id="<?php echo esc_attr( $category_title ); ?>_start" class="wowrestro-category-title-container not-in-search in-search"  data-category-title="<?php echo esc_attr( $category_title ); ?>" data-term-id="<?php echo esc_attr( $category_id ); ?>" >
	
	<?php apply_filters( 'wowrestro_category_menu_start_title_before', $category_title ); ?>

	<h3 id="<?php echo esc_attr( $category_title ); ?>" class="wowrestro-category-title wwr-primary-color"><?php echo esc_attr( $category_name ); ?></h3>
	<span class="wowrestro-category-short-desciption"><?php echo esc_html( $category_desc ); ?></span>

	<?php apply_filters( 'wowrestro_category_menu_start_title_after', $category_title ); ?>

</div>