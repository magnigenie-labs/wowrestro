<?php
/**
 * Simple product add to cart
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

$display_image = get_option( '_wowrestro_popup_enable_image', 'yes' );

?>

<div class="modal-content-wrapper wwr-row">

  <?php 

  if( 'yes' === $display_image ) :

    $image_option = get_option( '_wowrestro_listing_item_image_display' );

      ?>
        <div class="wwr-col-lg-12 wwr-col-md-12 wwr-col-sm-12 wwr-col-xs-12 product-thumbnail-wrapper">
          <?php 
          
            $thumbnail_id   = $product->get_image_id();
            $thumbnail_size = 150;
            $thumbnail_src  = wp_get_attachment_image_src( $thumbnail_id, 'full' );
            if ( $image_option != 'hide' && !empty( $thumbnail_src[0] ) ) {
              ?>
                <div class="wwr-product-image-container" style="background-image: url(<?php echo esc_url( $thumbnail_src[0] ); ?>);">
                </div>
              <?php 
            } 
          ?>
        </div>
      <?php 

  else :
  endif;

  ?>

  
  <div class="wwr-col-lg-12 wwr-col-md-12 wwr-col-sm-12 wwr-col-xs-12 product-content">
    <?php 
    
    $short_description = apply_filters( 'wowrestro_short_description', $product->get_short_description() );
    global $product;
    $product_type = $product->get_type();
    
    echo "<div class='wwr-modal-item-description'>$short_description</div>";
    if ( $product_type == 'simple' ) {
      ?>

      <span class="simple-food-price">
        <p class="<?php echo esc_attr( apply_filters( 'wowrestro-food-item-price', 'price' ) ); ?> woocommerce-Price-amount amount"><?php echo get_woocommerce_currency_symbol(); echo esc_html( $product->get_price() ); ?></p>
      </span>
      <?php

    }
    if( 'variable' == $product->get_type() ) {
      do_action( 'wowrestro_variable_data' ); 
    }
    
    do_action( 'wowrestro_food_modifiers', $product, $cart_key ); 

    ?>
    
  </div>
</div>