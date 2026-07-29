<?php
/**
 * WoWRestro Product Listing End
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/wowrestro-product-listing-end.php.
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

$category_name = '';

if ( !empty( $category_id ) ) {
  $term_details = get_term_by( 'id', $category_id, 'product_cat' );
  $category_name = $term_details->name;
  $category_title = $term_details->slug;
}

?>

<div id="<?php echo esc_attr( $category_title ); ?>_end"></div>
<?php apply_filters( 'wowrestro_category_menu_end', $category_title ); ?>
<!--  Food Category Menu End -->