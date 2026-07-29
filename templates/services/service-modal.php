<?php
/**
 * The template for displaying product popup wowrestromodal
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

<!-- Service Modal -->
<div class="wowrestromodal micromodal-slide" id="wowrestroServiceModal" aria-hidden="true">
  <div class="wowrestromodal-dialog" tabindex="-1" data-micromodal-close>
    <div class="wowrestromodal-container" role="dialog" aria-labelledby="wowrestroServiceModal-title">
      
      <header class="wowrestromodal-header">
        <h5 class="wowrestromodal-title" id="wowrestroServiceModal-title">
          <?php esc_html_e( 'Your order setting', 'wowrestro' ); ?>
        </h5>
        <button type="button" class="modal__close" aria-label="Close" data-micromodal-close></button>
      </header>

      <div class="wowrestromodal-body" id="wowrestroServiceModal-content">
        <div class="wowrestro-service-modal-container">
          <?php wwro_get_template( 'services/service-types.php' ); ?>
          <button type="button" class="wowrestro-update-service wwr-primary-background" data-add-item=''>
           <span class="wowrestro-update-text wwr-secondary-color"><?php esc_html_e( 'Update', 'wowrestro' ); ?></span>
          </button>
        </div>
      </div>

    </div>
  </div>
</div>