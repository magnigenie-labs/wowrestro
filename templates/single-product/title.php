<?php
/**
 * Single Product title
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/single-product/title.php.
 *
 * @package    WoWRestro/Templates
 * @version    1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

$food_type  			= get_post_meta( get_the_id(), '_wowrestro_food_item_type', true );
$image_option			= get_option( '_wowrestro_listing_item_image_display' );
$include_veg_non_veg 	= get_option( '_wowrestro_include_veg_non_veg' );

the_title( '<a href="javascript:void(0);" class="wowrestro-food-item-title wwr-primary-color">', '</a>' );
if( $image_option === 'hide' ) :
	if( !empty( $food_type ) && $include_veg_non_veg == 'yes' && $food_type != 'na' ) : ?>
		<div class="wowrestro-food-item-type <?php echo esc_attr( $food_type ); ?>">
	        <div></div>
	    </div>
	<?php endif;
endif;