<?php
/**
 * The template for displaying WoWRestro search bar
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/content-search.php.
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
?>
<div class="wwr-row">
  
  
  
  <!-- Search bar start -->
  <div class="wwr-col-md-6 wwr-col-sm-12">
    <div class="wowrestro-search-container">
      <input class="wowrestro-food-search" type="text" placeholder="<?php esc_html_e( 'Search dishes..', 'wowrestro'); ?>" name="wowrestro-search">
      <i class="wowrestro-icon-search"></i>
    </div>
  </div>
  <!-- Search bar end -->
</div>