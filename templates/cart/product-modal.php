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

<!-- WoWRestro Modal -->
<div class="wowrestromodal micromodal-slide" id="wowrestroModal" aria-hidden="true">
  <div class="wowrestromodal-dialog" tabindex="-1" data-micromodal-close>
    <div class="wowrestromodal-container" role="dialog" aria-labelledby="wowrestroModal-title">
      
      <header class="wowrestromodal-header">
        <h5 class="wowrestromodal-title" id="wowrestroModal-title"></h5>
        <button type="button" class="modal__close" aria-label="Close" data-micromodal-close></button>
      </header>

      <div class="wowrestromodal-body" id="wowrestroModal-content"></div>

      <footer class="wowrestromodal-footer">
        <div class="wowrestro-modal-actions">
          
          <div class="wowrestro-modal-count">
            
            <div class="wowrestro-modal-minus">
              <input type="button" value="-" class="wowrestro-qty-btn wowrestro-qtyminus wwr-primary-color

">
            </div>

            <div class="wowrestro-modal-quantity">
              <input type="text" name="wowrestro-quantity" value="1" class="wowrestro-qty-input wwr-primary-color">
            </div>

            <div class="wowrestro-modal-plus">
              <input type="button" value="+" class="wowrestro-qty-btn wowrestro-qtyplus wwr-primary-color">
            </div>
          </div>

          <div class="wowrestro-modal-add-to-cart">
            <a data-item-qty="" data-product-id="" data-product-type="" data-variation-id="" data-cart-action="" data-item-key="" class="wowrestro-product-add-to-cart wwr-primary-background">
              <span class="wowrestro-cart-action-text wwr-secondary-color"></span>
              <span class="wowrestro-live-item-price"></span>
            </a>
          </div>
        </div>
      </footer>

    </div>
  </div>
</div>