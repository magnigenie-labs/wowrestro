<?php
/**
 * WoWRestro Start
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/wowrestro-header.php.
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $shortcode_args;

// Service Modal display option 
$service_modal_option = get_option( '_wowrestro_service_modal_option', 'auto' );
?>
<div id="wowrestro" class="wwr-container">
	<div class="wwr-row">
		<div class="wowrestro-header-service-wrap">
			<div class="wwr-col-md-6 wwr-col-sm-12 wowrestro-service-wrap">
				<?php if ( $service_modal_option != 'hide' && !wwro_check_store_closed( '' ) ) : ?>
				  <?php echo wowrestro_service_time( $service_modal_option ); ?>
				<?php endif; ?>
				<?php wwro_store_meassge( '' ); ?>
			</div>
			<?php 
				if ( $shortcode_args['show_search'] != 'no' ) {
				  wwro_get_template( 'content-search.php' );
				}
			?>
		</div>