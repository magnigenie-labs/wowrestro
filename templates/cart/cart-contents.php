<?php
/**
 * WoWRestro Cart Items
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/cart/cart-contents.php
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
} 
?>

<div class="wowrestro-cart-item-container">

  <?php 

  foreach( WC()->cart->get_cart() as $cart_item_key => $cart_item ) : 

    $include_veg_non_veg  = get_option( '_wowrestro_include_veg_non_veg' );

    $_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
    $product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

    $quantity       = isset( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1;
    $food_type      = get_post_meta( $product_id, '_wowrestro_food_item_type', true );
    $get_modifiers  = wwro_get_formatted_modifiers( $cart_item ); 
    $variation_id   = isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : '';
    $special_note   = isset( $cart_item['special_note'] ) ? $cart_item['special_note'] : '';
    
    ?>

    <div class="wwr-row wowrestro-cart-item">
      
      <div class="wowrestro-cart-item-left">
        <div class="wowrestro-cart-item-title">
          <?php if( ! empty( $food_type ) && $include_veg_non_veg != 'yes' && $food_type != 'na' ) : ?>
            <div class="wowrestro-food-item-type <?php echo esc_html( $food_type ); ?>">
              <div></div>
            </div>
          <?php endif; ?>

          <p><?php echo esc_html( $_product->get_name() ); ?></p>
        </div>

        <?php 
          if ( 'variable' == $_product->get_type() ) : 
          
            $variation_name = ''; 
            $variations = isset( $cart_item['variation'] ) ? $cart_item['variation'] : array();

            if ( is_array( $variations ) && !empty( $variations ) ) : 
              ?>
                <?php $variation_name = implode(' / ', $variations); ?>
                <div class="wowrestro-cart-modifier variations">
                  <p class="wowrestro-cart-variation-item">- <?php echo esc_html( $variation_name ); ?></p>
                </div>
              <?php 
            endif; 
          endif; 
        ?>

        <?php if ( !empty( $get_modifiers ) ) : ?>
          <div class="wowrestro-cart-modifier">
            <?php echo $get_modifiers; ?>
          </div>
        <?php endif; ?>

        <?php if ( !empty( $special_note ) ) : ?>
          <div class="wowrestro-special-instruction">- <?php echo sprintf( __( 'Special Note : %s', 'wowrestro' ), $special_note ); ?></div>
        <?php endif; ?>
      </div>

      <div class="wwr-text-right">
        
        <span class="wowrestro-cart-item-price">
          <?php echo esc_html( $quantity ) . '&nbsp;&times;&nbsp;'; ?>
          <?php echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key ); // PHPCS: XSS ok. ?>
        </span>

        <span class="wowrestro-cart-actions wowrestro-cart-item-edit" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" data-variation-id="<?php echo esc_attr( $variation_id ); ?>" data-product-id="<?php echo esc_attr( $product_id ); ?>">
        <i class="wowrestro-icon-pencil"></i>
        </span>

        <span class="wowrestro-cart-actions wowrestro-cart-item-delete" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">
        <i class="wowrestro-icon-trash-o"></i>
        </span>
      </div>
    </div>

  <?php endforeach; ?>

</div>