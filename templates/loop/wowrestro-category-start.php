<?php
/**
 * WoWRestro Category Start
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/wowrestro-category-start.php.
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

// Apply Filter to make the Food item area width flexible
$classes = apply_filters( 'wowrestro_sidebar_start_classes', 'wwr-col-md-3' );

// Service Modal display option 
$service_modal_option = get_option( '_wowrestro_service_modal_option', 'auto' );

?>
<div id="wowrestro-sticky-sidebar" class="<?php echo esc_attr( $classes ); ?> d-none">

  	<div class="wowrestro-sidebar-menu">
    	<ul>