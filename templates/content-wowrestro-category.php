<?php
/**
 * The template for displaying WoWRestro category within loops
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/content-wowrestro_cat.php.
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
?>

<li class="wowrestro-category-menu-li <?php echo esc_attr( $category->slug ); ?>">
  
  <?php
  
  /**
   * wowrestro_before_subcategory hook.
   *
   */
  do_action( 'wowrestro_before_subcategory', $category );

  /**
   * wowrestro_before_subcategory_title hook.
   */
  do_action( 'wowrestro_before_subcategory_title', $category );

  /**
   * wowrestro_shop_loop_subcategory_title hook.
   */
  do_action( 'wowrestro_subcategory_title', $category );

  /**
   * wowrestro_after_subcategory_title hook.
   */
  do_action( 'wowrestro_after_subcategory_title', $category );

  /**
   * wowrestro_after_subcategory hook.
   */
  do_action( 'wowrestro_after_subcategory', $category );
  
  ?>

</li>