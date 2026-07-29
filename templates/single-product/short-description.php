<?php
/**
 * The template for displaying product short description
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/single-product/
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
global $post;

$short_description = apply_filters( 'wowrestro_short_description', $post->post_excerpt );

if ( empty( $short_description  ) ) {
  return;
}

?>
<div class="wowrestro-food-item-description">
  	<?php echo esc_html( $short_description ); ?>
</div>