<?php
/**
 * WoWRestro Product Start
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/wowrestro-product-start.php.
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

// Apply Filter to make the Food item area width flexible
$classes = apply_filters( 'wowrestro_products_start_classes', 'wwr-col-md-9' );

?>

<div id="wowrestro-food-items" class="<?php echo esc_attr( $classes ); ?>">