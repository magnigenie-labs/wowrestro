<?php
/**
 * WoWRestro frontend related functions and actions.
 *
 * @package WoWRestro/Classes
 * @since   1.0
 */

defined( 'ABSPATH' ) || exit;

class WWRO_Frontend {

  /**
   * Frontend Class Constructor
   *
   * @author Magnigenie
   * @since 1.0
   */
	public function __construct() {

    add_action( 'wp_head', array( $this, 'inline_style_sheet' ) );
    add_action( 'template_redirect', array( $this, 'store_close_message' ) );
    add_action( 'woocommerce_before_variations_form', array( $this, 'item_variations') );
    add_filter( 'woocommerce_get_item_data', array( $this, 'wowrestro_get_item_data' ), 10, 2 );
    add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items') );
    add_action( 'woocommerce_check_cart_items', array( $this, 'validate_store_time') );
    add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'wowrestro_save_cart_item_meta' ), 10, 4 );
    add_action( 'woocommerce_order_item_meta_start', array( $this, 'wowrestro_order_item_meta' ), 10, 3 );
    add_action( 'woocommerce_before_calculate_totals', array( $this, 'wowrestro_adding_custom_price' ), 99, 1);
    add_action( 'wp_footer', array( $this, 'variation_script' ), 10 );
    add_filter( 'woocommerce_checkout_fields' , array( $this, 'remove_service_type_field_vlidation' ) );

  }

  /**
   * Remove checkout field validation
   *
   * @since 1.0
   * Author WoWRestro
   */
  public function remove_service_type_field_vlidation( $post_data ) {

    $service_type = wowrestro_get_session( 'service_type' );
    if ( $service_type == 'pickup' ) {

      $post_data['billing']['billing_country']['required'] = false;
      $post_data['billing']['billing_address_1']['required'] = false;
      $post_data['billing']['billing_address_2']['required'] = false;
      $post_data['billing']['billing_company']['required'] = false;
      $post_data['billing']['billing_city']['required'] = false;
      $post_data['billing']['billing_state']['required'] = false;
      $post_data['billing']['billing_postcode']['required'] = false;

    }

    return $post_data;

  }

  /**
   * Enqueues the custom styles from Admin settings
   *
   * @since 1.0
   * Author WoWRestro
   */
  public function inline_style_sheet() {

    $primary_color_scheme = get_option( '_wowrestro_primary_color_scheme', '#9797ff' );
    $secondary_color_scheme = get_option( '_wowrestro_secondary_color_scheme', '#9797ff' );
    $user_style_sheet = get_option( '_wowrestro_user_stylesheet' );
    echo '<style id="wowrestro-inline-css">
      .wwr-primary-background {
        background-color: ' . $primary_color_scheme . ' !important;
      }
      .wwr-secondary-background {
        background-color: ' . $secondary_color_scheme . ' !important;
      }
      .wwr-primary-color {
        color: ' . $primary_color_scheme . ' !important;
      }
      .wwr-secondary-color{
        color: ' . $secondary_color_scheme . ' !important;
      }
      .wowrestromodal .nav-item.active a{
        background-color: ' . $primary_color_scheme . ' !important;
        color: ' . $secondary_color_scheme . ' !important;
      }
      .wowrestro-sidebar-menu ul li a span.wowrestro-items-count.active{
        background-color: ' . $primary_color_scheme . ' !important;
        color: ' . $secondary_color_scheme . ' !important;
      }

      .wowrestro-sidebar-menu ul li a.active{
        color: ' . $primary_color_scheme . ' !important;
      }

      .woocommerce-Price-amount.amount{
        color: ' . $primary_color_scheme . ' !important;
      }

      .wwr-mobile-category-wrap span.wp-cart-svg svg{
        fill: ' . $primary_color_scheme . ' !important;
      }

      .wp-cart-svg svg{
        fill: ' . $secondary_color_scheme . ' !important;
      }

      .wowrestro-sidebar-menu ul li a:hover{
        color: ' . $primary_color_scheme . ' !important;
      }

      .wowrestro-sidebar-menu ul li a:hover .wowrestro-items-count{
        background-color: ' . $primary_color_scheme . ' !important;
        color: ' . $secondary_color_scheme . ' !important;
      }

      .wwr-service-time-wrap input[type=radio]:checked + label{
        border-color: ' . $primary_color_scheme . ' !important;
      }

      .wwr-service-time-wrap input[type=radio]:checked + label:after{
        background: ' . $primary_color_scheme . ' !important;
      }

      .mobile-cart-wrap .woocommerce-Price-amount{
        color: ' . $secondary_color_scheme . ' !important;
      }

      #wowrestro_checkout_fields .wowrestro_co_service_type .input-radio:checked + label, #wowrestro_checkout_fields .wowrestro_co_service_type .woocommerce-input-wrapper label.active{
        background: ' . $primary_color_scheme . ' !important;
        color: ' . $secondary_color_scheme . ' !important;
      }

      .wwr-container-checkbox .wwr-control__indicator,
      .wwr-container-radio .wwr-control__indicator{
        border-color: ' . $primary_color_scheme . ' !important;
      }

      .wwr-container-checkbox input[type="checkbox"]:checked ~ .wwr-control__indicator{
        background: ' . $primary_color_scheme . ' !important;
      }

      .wwr-container-checkbox input[type="checkbox"]:checked ~ .wwr-control__indicator:after{
        border-color: ' . $secondary_color_scheme . ' !important;
      }

      .wwr-container-radio .wwr-control__indicator:after{
        background: ' . $primary_color_scheme . ' !important;
      }';


    echo  $user_style_sheet;
    echo  '</style>';

  }

  /**
   * Returns the store closed message notice
   *
   * @author Magnigenie
   * @since 1.0
   * @return string
   */
  public function store_close_message() {

    $service_type = wowrestro_get_session( 'service_type' );
    global $woocommerce;

    if ( wwro_check_store_closed( '' ) ) {

      $store_message = get_option( '_wowrestro_store_closed_message', true );
      $store_message = apply_filters( 'wowrestro_store_message', $store_message, $service_type );

      if ( is_cart() ) {
        wc_add_notice( $store_message, 'notice' );
      }
    }
  }

  /**
   * Validate cart items based on WoWRestro admin settings
   * and rules set for each item
   *
   * @since 1.0
   * @author WoWRestro
   */
  public function validate_cart_items() {

    $service_type = ( !empty( wowrestro_get_session( 'service_type' ) ) ) ? wowrestro_get_session( 'service_type' ) : 'pickup';

    $minimum_order_amount       = 0;
    $minimum_order_amount_error = '';

    if ( $service_type == 'delivery' ) {
      $minimum_order_amount = get_option( '_wowrestro_min_delivery_order_amount', true );
      $minimum_order_amount_error = get_option( '_wowrestro_min_delivery_order_amount_error', true );
      $maximum_order_amount = get_option( '_wowrestro_max_delivery_order_amount', true );
      $maximum_order_amount_error = get_option( '_wowrestro_max_delivery_order_amount_error', true );
    }

    if ( $service_type == 'pickup' ) {
      $minimum_order_amount = get_option( '_wowrestro_min_pickup_order_amount', true );
      $minimum_order_amount_error = get_option( '_wowrestro_min_pickup_order_amount_error', true );
      $maximum_order_amount = get_option( '_wowrestro_max_pickup_order_amount', true );
      $maximum_order_amount_error = get_option( '_wowrestro_max_pickup_order_amount_error', true );
    }

    $min_search_text   = array( '{service_type}', '{min_order_amount}' );
    $min_replace  = array( wwro_get_service_label($service_type), wc_price( $minimum_order_amount ) );
    $min_error_message  = str_replace( $min_search_text, $min_replace, $minimum_order_amount_error );

    $max_search_text   = array( '{service_type}', '{max_order_amount}' );
    $max_replace  = array( wwro_get_service_label($service_type), wc_price( $maximum_order_amount ) );
    $max_error_message  = str_replace( $max_search_text, $max_replace, $maximum_order_amount_error );

    if ( $minimum_order_amount > 0 && WC()->cart->total > 0 && WC()->cart->total < $minimum_order_amount ) {
      wc_add_notice( $min_error_message, 'error' );
    }

    if ( $maximum_order_amount > 0 && WC()->cart->total > 0 && WC()->cart->total > $maximum_order_amount ) {
      wc_add_notice( $max_error_message, 'error' );
    }
  }

  /**
   * Validate store time to allow orders only in
   * store opening times
   *
   * @since 1.0
   * @author WoWRestro
   */
  public function validate_store_time() {
    $service_type = wowrestro_get_session( 'service_type' );
    if ( is_checkout() ) {
      if( wwro_check_store_closed( '' ) ) {
        $store_message = get_option( '_wowrestro_store_closed_message', true );
        $store_message = apply_filters( 'wowrestro_store_message', $store_message, $service_type );
        wc_add_notice( $store_message, 'error' );
      }
    }
  }

  /**
   * Enqueue script for handelling product variations
   *
   * @since 1.0
   */
  public function variation_script() {
    global $woocommerce;
    wp_enqueue_script( 'wc-add-to-cart-variation' );
  }

  /**
   * Check is item is purchasable or not
   *
   * @since 1.0
   */
  static function wowrestro_is_purchasable( $product ) {
    return $product->is_purchasable() && $product->is_in_stock() && $product->has_enough_stock( 1 );
  }

  /**
   * Prepare attributes for variations
   *
   * @since 1.0
   */
  static function wowrestro_data_attributes( $attrs ) {

    $attrs_arr = array();
    foreach ( $attrs as $key => $attr ) {
      $attrs_arr[] = 'data-' . sanitize_title( $key ) . '="' . esc_attr( $attr ) . '"';
    }
    return implode( ' ', $attrs_arr );
  }

  /**
   * Update modifiers and special notes if any to
   * Cart Item Data
   *
   * @since 1.0
   * @author WoWRestro
   * @return array $item_data
   */
  public function wowrestro_get_item_data( $item_data, $cart_item_data  ) {

    $modifier_name = '';

    if ( isset( $cart_item_data['modifiers'] ) && !empty( $cart_item_data['modifiers'] ) ) {
      $modifier_items = $cart_item_data['modifiers'];

      foreach( $modifier_items as $modifier_item ) {

        $quantity     = isset( $modifier_item['quantity'] ) ? $modifier_item['quantity'] : 1;
        $modifier_slug   = isset( $modifier_item['modifier_item']['value'] ) ? $modifier_item['modifier_item']['value'] : '';
        $wwro_modifier_price  = isset( $modifier_item['price'] ) ? $modifier_item['price'] : 0;

        $wwro_modifier_price  = wwro_calculate_wwro_modifier_price( $wwro_modifier_price, $quantity );
        $wwro_modifier_price  = wc_price( $wwro_modifier_price );

        if ( !empty( $modifier_slug ) ) {
          $modifier_term = get_term_by( 'slug', $modifier_slug, 'food_modifiers' );

          if ( $modifier_term ) {

            $item_data[] = array(
              'key'     => ' - ' . $modifier_term->name,
              'value'   => wc_price( $wwro_modifier_price ),
              'display' => $wwro_modifier_price,
            );
          }
        }
      }
    }

    if ( isset( $cart_item_data['special_note'] ) && !empty( $cart_item_data['special_note'] ) ) {

      $item_data[] = array(
        'key'     => __( 'Special Note' , 'wowrestro' ),
        'display' => $cart_item_data['special_note'],
      );

    }

    return $item_data;
  }

  /**
   * Update modifiers and special notes to Cart item meta
   *
   * @since 1.0
   * @author WoWRestro
   */
  public function wowrestro_save_cart_item_meta( $item, $cart_item_key, $values, $order ) {

    if ( array_key_exists( 'modifiers', $values ) ) {
      $item->add_meta_data( '_modifier_items', $values['modifiers'], true );
    }

    if ( array_key_exists( 'special_note', $values ) ) {
      $item->add_meta_data( '_special_note', $values['special_note'], true );
    }
  }

  /**
   * Get modifiers and special notes cart item meta
   *
   * @since 1.0
   * @author WoWRestro
   */
  public function wowrestro_order_item_meta( $item_id, $item, $order ) {

    $item_data = '';

    $modifier_items = wwro_get_modifiers_from_meta( $item_id );

    if ( !empty( $modifier_items ) && is_array( $modifier_items ) ) {
      foreach( $modifier_items as $key => $modifier_item ) {
        $modifier_name  = isset( $modifier_item['name'] ) ? $modifier_item['name'] : '';
        $modifier_quantity = isset( $modifier_item['quantity'] ) ? $modifier_item['quantity'] : 1;
        $wwro_modifier_price = isset( $modifier_item['price'] ) ? $modifier_item['price'] : '';
        $item_data .= sprintf( '<span class="wowrestro_order_meta">%1$s - %2$s </span>', $modifier_name, $wwro_modifier_price );
      }
    }

    $special_note = wc_get_order_item_meta( $item_id, '_special_note', true );

    if ( !empty( $special_note ) ) {
      $item_data .= sprintf( '<span class="wowrestro_order_meta">Special Note : %1$s  </span>', $special_note );
    }

    return esc_html( $item_data );
  }

  /**
   * Output the Product Variations as Radio buttons
   *
   * @since 1.0
   * @author WoWRestro
   */
  public function item_variations() {

    global $product;
    global $woocommerce;

    $cart_items = $woocommerce->cart->get_cart();
    $product_id = $product->get_id();
    $cart_key = isset( $_REQUEST['cart_key'] ) ? sanitize_key( $_REQUEST['cart_key'] ) : '';
    $variation_id = '';

    if ( !empty( $cart_key ) && !empty( $cart_items ) ) {

      foreach( $cart_items as $cart_item_key => $cart_item ) {

        if ( $cart_key == $cart_item_key ) {
          $variation_id = isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : '';
        }
      }
    }

    $wowrestro_item_children = $product->get_children();

    if ( is_array( $wowrestro_item_children ) && count( $wowrestro_item_children ) > 0 ) {

      echo '<div class="wowrestro-variations wowrestro-variations-default" data-click="0" data-description="no">';

      // get custom pricing title
      $pricing_label = get_post_meta( $product_id, '_wowrestro_variation_price_label', true );
      $pricing_label = ! empty( $pricing_label ) ? $pricing_label : __( 'Choose Price Option', 'wowrestro' );

      echo '<p class="wowrestro-pricing-option-label">' . apply_filters( 'wowrestro_pricing_option_label', $pricing_label ) . '</p>';

      // show radio buttons
      foreach ( $wowrestro_item_children as $item_child ) {

          $wowrestro_child_product = wc_get_product( $item_child );

          if ( ! $wowrestro_child_product || ! $wowrestro_child_product->variation_is_visible() ) {
              continue;
          }

          if ( ! self::wowrestro_is_purchasable( $wowrestro_child_product ) ) {
              continue;
          }

          $wowrestro_child_attrs   = htmlspecialchars( json_encode( $wowrestro_child_product->get_variation_attributes() ), ENT_QUOTES, 'UTF-8' );
          $wowrestro_child_class   = 'wowrestro-variation wowrestro-variation-radio';

          $wowrestro_child_name = wc_get_formatted_variation( $wowrestro_child_product, true, false, false );

          if( $wowrestro_child_product->get_image_id() ) {
              $wowrestro_child_image      = wp_get_attachment_image_src( $wowrestro_child_product->get_image_id(), 'thumbnail' );
              $wowrestro_child_image_src  = $wowrestro_child_image[0];
              $wowrestro_child_image_src  = esc_url( apply_filters( 'wowrestro_variation_image_src', $wowrestro_child_image_src, $wowrestro_child_product ) );
          } else {
              $wowrestro_child_image_src  = esc_url( apply_filters( 'wowrestro_variation_image_src', wc_placeholder_img_src(), $wowrestro_child_product ) );
          }

          $attribute_checked = ( $variation_id == $item_child ) ? 'checked' : '';

          $data_attrs = apply_filters( 'wowrestro_item_data_attributes', array(
              'id'            => $item_child,
              'sku'           => $wowrestro_child_product->get_sku(),
              'purchasable'   => self::wowrestro_is_purchasable( $wowrestro_child_product ) ? 'yes' : 'no',
              'attrs'         => $wowrestro_child_attrs,
              'price'         => wc_get_price_to_display( $wowrestro_child_product ),
              'regular-price' => wc_get_price_to_display( $wowrestro_child_product, array( 'price' => $wowrestro_child_product->get_regular_price() ) ),
              'pricehtml'     => htmlentities( $wowrestro_child_product->get_price_html() ),
              'imagesrc'      => $wowrestro_child_image_src,
          ), $wowrestro_child_product );

          echo '<div class="' . esc_attr( $wowrestro_child_class ) . '" ' . self::wowrestro_data_attributes( $data_attrs ) . '>';

          echo apply_filters( 'wowrestro_variation_radio_selector', '<div class="wowrestro-variation-selector"><input type="radio" name="wowrestro_variation_' . $product_id . '" data-child-product="' . $item_child . '" ' . $attribute_checked . '/></div>', $product_id, $item_child );

          echo '<div class="wowrestro-variation-image"><img src="' . $wowrestro_child_image_src . '"/></div>';

          echo '<div class="wowrestro-variation-info">';
          echo '<div class="wowrestro-variation-name">' . apply_filters( 'wowrestro_variation_name', $wowrestro_child_name, $wowrestro_child_product ) . '</div>';

          echo '<div class="wowrestro-variation-price">' . apply_filters( 'wowrestro_variation_price', $wowrestro_child_product->get_price_html(), $wowrestro_child_product ) . '</div>';

          echo '</div><!-- /wowrestro-variation-info -->';
          echo '</div><!-- /wowrestro-variation -->';
      }

      echo '</div><!-- /wowrestro-variations -->';
    }
  }

  /**
   * Cart/Checkout price calculations considering the
   * Modifiers in cart item meta for each product
   *
   * @since 1.0
   * @author WoWRestro
   */
  public function wowrestro_adding_custom_price( $cart ) {

    if ( is_admin() && ! defined( 'DOING_AJAX' ) )
    	return;


    if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 )
    	return;

    $action         = !empty( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
    $modifier_data  = !empty( $_POST['modifier_data'] ) && is_array( $_POST['modifier_data'] ) ? array_filter( wp_unslash( (array) $_POST['modifier_data'] ) ) : [];
    $cart_action 		= !empty( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
    $item_key    		= !empty( $_POST['item_key'] ) ? sanitize_key( $_POST['item_key'] ) : '';

    foreach ( $cart->get_cart() as $key => $cart_item ) {

    	$wwro_modifier_prices = 0;

      if ( 'product_update_cart' == $cart_action ) {
        if ( !empty( $item_key ) && $key == $item_key ) {
          $product_id   	= !empty( $cart_item['product_id'] ) ? $cart_item['product_id'] : '';
          $quantity     	= !empty( $cart_item['quantity'] ) ? $cart_item['quantity'] : 1;
          $product      	= wc_get_product( $product_id );
          $modifier_items = wwro_format_modifiers( $modifier_data, $quantity, $product );
          $modifier_items = !empty( $modifier_items['modifiers'] ) ? $modifier_items['modifiers'] : array() ;
          $cart_item['modifiers'] = $modifier_items;
        }
      }

      if ( isset( $cart_item['modifiers'] ) && !empty( $cart_item['modifiers'] ) ) {
        foreach( $cart_item['modifiers'] as $modifier_items ) {
          $wwro_modifier_price  = $modifier_items['raw_price'];
          $modifier_quantity 		= $modifier_items['quantity'];
          $wwro_modifier_prices = $wwro_modifier_prices + $wwro_modifier_price;
        }
      }

    	$product_price 	= $cart_item['data']->get_price();
    	$total_price	  = $product_price + $wwro_modifier_prices;
    	$cart_item['data']->set_price( round( $total_price, wc_get_price_decimals() ) );
    }
  }

}

new WWRO_Frontend();
