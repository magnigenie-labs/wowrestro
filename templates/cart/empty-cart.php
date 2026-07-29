<?php
/**
 * The template for displaying empty cart with message
 *
 * This template can be overridden by copying it to yourtheme/wowrestro
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
?>
<div class="wowrestro-empty-cart-container">
	<div class="wowrestro-empty-cart-image">
		<img src="<?php echo plugins_url( 'assets/images/empty-cart.png', WWRO_PLUGIN_FILE ); ?>">
	</div>
	<div class="wowrestro-empty-cart-text">
		<?php echo wwro_empty_cart_message(); ?>
	</div>
</div>