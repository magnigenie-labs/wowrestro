<?php
/**
 * WoWRestro Product
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/content-listing-details.php.
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

global $product;
global $shortcode_args;

$button_class = wwro_check_store_closed( '' ) ? 'wowrestro-store-closed' : 'wowrestro-product-modal';

$image_option = get_option( '_wowrestro_listing_item_image_display' );
$img_item_wrap = '';
if( 'small' === $image_option ) {
	$img_item_wrap = 'small-img-wrap';
} else if( 'hide' === $image_option ) {
	$img_item_wrap = 'no-img-wrap';
}

?>

<div itemscope="" itemtype="http://schema.org/Product" class="wowrestro-food-item-container button-add-to-cart <?php echo esc_attr__( $button_class, 'wowrestro' ); ?> <?php echo esc_attr( $img_item_wrap ); ?>" data-product-id="<?php echo esc_attr__( $product->get_id(), 'wowrestro' ); ?>" data-term-id="<?php echo esc_attr__( $term_id, 'wowrestro' ); ?>">

  <div class="wowrestro-food-item-summery">
    <?php do_action( 'wowrestro_product_summary' ); ?>
    <img class="item-loader" height="50" width="50" src="<?php echo esc_url( plugins_url( 'assets/images/loaderR.gif', WWRO_PLUGIN_FILE ) ); ?>" style="height: 20px; width: 20px;">
  </div>
  <?php do_action( 'wowrestro_before_product_summary' ); ?>

  <?php do_action( 'wowrestro_after_product_summary' ); ?>

</div>