<?php
/**
 * Single Product Price
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/single-product/price.php.
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}
global $product;
$product_type = $product->get_type();
if ( $product_type == 'variable' ) {
	global $post;
	$product = wc_get_product( $post->ID );
	echo $product->get_price_html();
}
else{
?>
<p class="<?php echo esc_attr( apply_filters( 'wowrestro-food-item-price', 'woocommerce-Price-amount amount' ) ); ?>"><?php echo get_woocommerce_currency_symbol(); echo esc_html( $product->get_price() ); ?></p>
<?php
}
?>