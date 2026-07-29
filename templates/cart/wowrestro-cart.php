<?php
/**
 * The template for displaying prduct cart
 *
 * This template can be overridden by copying it to yourtheme/wowrestro
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

global $woocommerce;

$items = $woocommerce->cart->get_cart();

$cart_count = count( $items );
$cart_class = $cart_count ? 'content' : 'empty';

// Cart display option
$hide_cart = get_option( '_wowrestro_listing_hide_cart_area', 'no' );

?>

<?php if( 'no' == $hide_cart ) : ?>

  <!-- Fade body when the cart is expanded -->
  <dir class="wowrestro-body-fade"></dir>
  
  <!-- Complete Cart View -->
  <div class="wowrestro-cart-expanded <?php echo esc_attr( $cart_class ); ?>">
    <div class="wwr-container">

      <div class="wowrestro-cart-expanded-header">
        <p class="wowrestro-cart-expanded-header-title"><?php esc_html_e('Your Order', 'wowrestro'); ?></p>
        <span class="wowrestro-close-cart-icon"><i class="wowrestro-icon-close"></i></span>
      </div>

      <?php if ( $cart_count ) : ?>
        
        <div class="wowrestro-cart-content-area">
          
          <div class="cart-content">
            <?php wwro_get_template( 'cart/cart-contents.php' ); //cart with items ?>
          </div>
          <div class="cart-content-total">
            <?php wwro_get_template( 'cart/cart-totals.php' ); ?>
          </div>

        </div>

        <div class="cart-content-checkout">
          <div class="wwr-text-center wowrestro-cart-purchase-actions">
            <button class="wowrestro-proceed-to-checkout desktop-cart-btn wwr-primary-background wwr-secondary-color">
              <?php echo __( 'Checkout' , 'wowrestro' ); ?>
            </button>
          </div>
        </div>

      <?php else: ?>

        <?php wwro_get_template( 'cart/empty-cart.php' ); //empty cart ?>

      <?php endif; ?>

    </div>
    
  </div>

  <!-- Cart Overview Area -->
  <div class="wowrestro-cart-overview wwr-primary-background <?php echo ( $cart_count == 0 ) ? 'd-none' : ''; ?>">
    <div class="wwr-container">
      <div class="wowrestro-cart-overview-row">
        
        <div class="mobile-cart-wrap">
          <div class="wowrestro-cart-overview-description wwr-text-left wwr-secondary-color wowrestro-cart-toggle">
           <span class="wwr-cart-icon"> <i class="wowrestro-icon-shopping-cart"></i>
            </span><span class="wwr-sep-line-dek">|</span>
            <span class="wwr-count-txt">
              <?php 
                if ( $cart_count > 1 ) :
                  echo sprintf( __( ' %s Items', 'wowrestro' ), $cart_count );
                else:
                  echo sprintf( __( ' %s Item', 'wowrestro' ), $cart_count );
                endif;
              ?>
            </span>
            </span><span class="wwr-sep-line-mob">|</span>
            <span class="wwr-price"><?php wc_cart_totals_subtotal_html() ?></span>
          </div>
          <div class="wwr-text-right wowrestro-cart-purchase-actions-mobile">
            <button class="wwr-btn-md wwr-btn-primary wowrestro-proceed-to-checkout"><span class="wwr-view-cart-txt wwr-secondary-color"><?php echo __( 'Checkout' , 'wowrestro' ); ?></span>
              <span class="wp-cart-svg">
              <svg version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
                 viewBox="0 0 321.2 321.2" xml:space="preserve">
              <g><g>
              <path d="M306.4,313.2l-24-223.6c-0.4-3.6-3.6-6.4-7.2-6.4h-44.4V69.6c0-38.4-31.2-69.6-69.6-69.6c-38.4,0-69.6,31.2-69.6,69.6
              v13.6H46c-3.6,0-6.8,2.8-7.2,6.4l-24,223.6c-0.4,2,0.4,4,1.6,5.6c1.2,1.6,3.2,2.4,5.2,2.4h278c2,0,4-0.8,5.2-2.4
              C306,317.2,306.8,315.2,306.4,313.2z M223.6,123.6c3.6,0,6.4,2.8,6.4,6.4c0,3.6-2.8,6.4-6.4,6.4c-3.6,0-6.4-2.8-6.4-6.4
              C217.2,126.4,220,123.6,223.6,123.6z M106,69.6c0-30.4,24.8-55.2,55.2-55.2c30.4,0,55.2,24.8,55.2,55.2v13.6H106V69.6z
               M98.8,123.6c3.6,0,6.4,2.8,6.4,6.4c0,3.6-2.8,6.4-6.4,6.4c-3.6,0-6.4-2.8-6.4-6.4C92.4,126.4,95.2,123.6,98.8,123.6z M30,306.4
              L52.4,97.2h39.2v13.2c-8,2.8-13.6,10.4-13.6,19.2c0,11.2,9.2,20.4,20.4,20.4c11.2,0,20.4-9.2,20.4-20.4c0-8.8-5.6-16.4-13.6-19.2
              V97.2h110.4v13.2c-8,2.8-13.6,10.4-13.6,19.2c0,11.2,9.2,20.4,20.4,20.4c11.2,0,20.4-9.2,20.4-20.4c0-8.8-5.6-16.4-13.6-19.2V97.2
              H270l22.4,209.2H30z"/>
              </g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g><g></g></svg>
            </span>
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

<?php endif; ?>