<?php
/**
 * The template for displaying product image
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/single-product/
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
global $product;

$post_thumbnail_id 		= $product->get_image_id();
$thumbnail_src      	= wp_get_attachment_image_src( $post_thumbnail_id, 'woocommerce_thumbnail' );
$thumbnail_src 			= isset( $thumbnail_src[0] ) ? esc_url( $thumbnail_src[0] ) : '';
$product_food_type  	= get_post_meta( $product->get_id(), '_wowrestro_food_item_type', true );
$include_veg_non_veg 	= get_option( '_wowrestro_include_veg_non_veg' );
$image_option			= get_option( '_wowrestro_listing_item_image_display' );


if( 'small' === $image_option ) {
	$wwro_width = '65';
	$wwro_height = '65';
} else if( 'medium' === $image_option ) {
	$wwro_width = '125';
	$wwro_height = '125';
}
?>

<?php if( $image_option !== 'hide' ) : ?>
	<div class="wowrestro-food-item-image-container">

		<?php if( !empty( $product_food_type ) && $include_veg_non_veg == 'yes' && $product_food_type != 'na' ) : ?>
			<div class="wowrestro-food-item-type <?php echo esc_attr( $product_food_type ); ?>">
		        <div></div>
		    </div>
		<?php endif; ?>

		<?php if ( $thumbnail_src ) : ?>

			<?php 

			$lazy_loading = get_option( '_wowrestro_enable_lazy_loading', 'yes' );
			if( 'yes' === $lazy_loading ) {
				$lazy_load_class = 'wowrestro-lazy-load';
				$image_attr = 'data-src="' . $thumbnail_src . '"';
			} else {
				$lazy_load_class = '';
				$image_attr = 'src="' . $thumbnail_src . '"';
			}

			?>
	  		<img class="<?php echo esc_attr( $lazy_load_class ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" <?php echo $image_attr; ?> style="width: <?php echo esc_html( $wwro_width ) . 'px'; ?>;height: <?php echo esc_html( $wwro_height ) . 'px'; ?>;" />
	  	
	  	<?php endif; ?>
	</div>
<?php endif; ?>