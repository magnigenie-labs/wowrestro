<?php
/**
 * WoWRestro WWRO_AJAX. AJAX Event Handlers.
 *
 * @class   WWRO_AJAX
 * @package WoWRestro/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * WWRO_Ajax class.
 */
class WWRO_AJAX {

  /**
   * Hook in ajax handlers.
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function init() {
    add_action( 'init', array( __CLASS__, 'define_ajax' ), 0 );
    add_action( 'template_redirect', array( __CLASS__, 'do_wowrestro_ajax' ), 0 );
    self::add_ajax_events();
  }

  /**
   * Set WR AJAX constant and headers.
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function define_ajax() {
    // phpcs:disable
    if ( ! empty( $_GET['wowrestro-ajax'] ) ) {
      wowrestro_maybe_define_constant( 'DOING_AJAX', true );
      wowrestro_maybe_define_constant( 'WWRO_DOING_AJAX', true );
      $GLOBALS['wpdb']->hide_errors();
    }
  }

  /**
   * Check for WR Ajax request and fire action.
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function do_wowrestro_ajax() {
    global $wp_query;

    if ( ! empty( $_GET['wowrestro-ajax'] ) ) {
      $wp_query->set( 'wowrestro-ajax', sanitize_text_field( wp_unslash( $_GET['wowrestro-ajax'] ) ) );
    }

    $action = $wp_query->get( 'wowrestro-ajax' );

    if ( $action ) {
      self::wowrestro_ajax_headers();
      $action = sanitize_text_field( $action );
      do_action( 'wowrestro_ajax_' . $action );
      wp_die();
    }
  }

  /**
   * Send headers for WR Ajax Requests.
   *
   * @author Magnigenie
   * @since 1.0
   */
  private static function wowrestro_ajax_headers() {
    if ( ! headers_sent() ) {
      send_origin_headers();
      send_nosniff_header();
      wowrestro_nocache_headers();
      header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
      header( 'X-Robots-Tag: noindex' );
      status_header( 200 );
    } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
      headers_sent( $file, $line );
      trigger_error( "wowrestro_ajax_headers cannot set headers - headers already sent by {$file} on line {$line}", E_USER_NOTICE );
    }
  }

  /**
   * AJAX Hook in methods
   * Uses WordPress ajax handlers
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function add_ajax_events() {

    $ajax_events_nopriv = array(
      'show_product_modal',
      'add_to_cart',
      'empty_cart',
      'product_remove_cart',
      'product_update_cart',
      'update_service_time',
      'validate_proceed_checkout',
      'update_service_time_option_checkout'
    );

    foreach ( $ajax_events_nopriv as $ajax_event ) {
      add_action( 'wp_ajax_' . $ajax_event, array( __CLASS__, $ajax_event ) );
      add_action( 'wp_ajax_nopriv_' . $ajax_event, array( __CLASS__, $ajax_event ) );
    }

    $ajax_events = array(
      'add_food_modifiers',
    );

    foreach ( $ajax_events as $ajax_event ) {
      add_action( 'wp_ajax_wowrestro_' . $ajax_event, array( __CLASS__, $ajax_event ) );
    }
  }

  /**
   * Add Modifier Item to Product Row
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function add_food_modifiers() {

    check_ajax_referer( 'add-modifier', 'security' );

    ob_start();

    if ( !current_user_can( 'edit_products' ) || !isset( $_POST['taxonomy'], $_POST['i'] ) ) {
      wp_die( -1 );
    }

    $i             = absint( $_POST['i'] );
    $metabox_class = array();
    $taxonomy      = sanitize_text_field( $_POST['taxonomy'] );
    $modifier      = get_term_by( 'slug', $taxonomy, 'food_modifiers');

    if( $modifier ) {
      $metabox_class[] = 'taxonomy';
      $metabox_class[] = $modifier->slug;
    }

    include 'admin/views/html-product-modifier.php';
    wp_die();
  }

  /**
   * Show Product Modal with Variations and Modifiers
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function show_product_modal() {

     if (!is_admin()){
      check_ajax_referer( 'product-modal', 'security' );
     }

    global $product;

    ob_start();

    $product_id = isset( $_POST['product_id'] ) ? absint($_POST['product_id']) : '';
    $cart_key   = isset( $_POST['cart_key'] ) ? sanitize_key( $_POST['cart_key'] ) : '';

    // Set default values if its a add to cart call
    $item_quantity  = 1;
    $item_action    = 'add_to_cart';
    $button_text    = wwro_modal_add_to_cart_text();
    $item_key       = '';
    $variation_id   = '';
    $special_note   = '';

    if( !empty( $cart_key ) ) {

      $cart_product   = WC()->cart->get_cart_item( $cart_key );

      // Update values if it's and Edit Cart Call
      $item_quantity  = absint( $cart_product['quantity'] );
      $item_action    = 'product_update_cart';
      $button_text    = wwro_modal_update_cart_text();
      $item_key       = $cart_key;
      $variation_id   = isset( $cart_product['variation_id'] ) ? $cart_product['variation_id'] : '';
      $special_note   = isset( $cart_product['special_note'] ) ? $cart_product['special_note'] : '';
    }

    $product  = wc_get_product( $product_id );
    $title    = $product->get_name();

    wwro_get_template(
      'single-product/add-to-cart/item.php',
      array(
        'product'   => $product,
        'cart_key'  => $cart_key,
      )
    );

    $content = ob_get_contents();

    ob_get_clean();

    $response = array(
      'content'       => $content,
      'title'         => $title,
      'product_id'    => $product->get_id(),
      'product_type'  => $product->get_type(),
      'product_qty'   => $item_quantity,
      'action'        => $item_action,
      'action_text'   => $button_text,
      'item_key'      => $item_key,
      'variation_id'  => $variation_id,
      'special_note'  => $special_note,
    );

    echo wp_send_json( $response );
    wp_die();
  }

  /***
   * Ajax Add to Cart
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function add_to_cart() {

     if (!is_admin()){

      check_ajax_referer( 'add-to-cart', 'security' );
     }


    global $woocommerce;

    $product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : '';
    $quantity       = isset( $_POST['quantity'] ) ? absint( $_POST['quantity']  ) : 1;
    $modifier_data  = isset( $_POST['modifier_data'] ) && is_array( $_POST['modifier_data'] ) ? array_filter( wp_unslash( (array) $_POST['modifier_data'] ) ) : array();
    $special_note   = isset( $_POST['special_note'] ) ? sanitize_text_field( $_POST['special_note'] ) : '';

    $status = $product_name = '';

    $is_valid = apply_filters( 'validate_add_to_cart', true, $_POST );

    if ( !empty( $product_id ) && 'product' == get_post_type( $product_id ) && $is_valid ) {

      $product = wc_get_product( $product_id );
      $product_name = $product->get_name();

      $modifier_items  = wwro_format_modifiers( $modifier_data, $quantity, $product );

      if ( !empty( $special_note ) && is_array( $modifier_items ) ) {
        $modifier_items['special_note'] = $special_note;
      }

      if ( 'simple' == $product->get_type() ) {
        $cart_response = WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $modifier_items );
      }

      if ( 'variable' == $product->get_type() ) {

        $variation_data = isset( $_POST['postdata'] ) ? (array) $_POST['postdata'] : array();
        $variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : '';

        if ( !empty( $variation_id ) ) {

          $variations = new WC_Product_Variable( $product_id );
          $variations = $variations->get_available_variations();
          $available_variation_ids = array();

          if ( is_array( $variations ) ) {
            $available_variation_ids = wp_list_pluck( $variations, 'variation_id' );
          }

          if ( in_array( $variation_id, $available_variation_ids ) ) {

            $selected_variations = array();

            foreach( $variation_data as $key => $variation_attr ) {
              if( strpos( $variation_attr['name'], 'attribute' ) !== false ) {
                $selected_variations[ $variation_attr['name'] ] = $variation_attr['value'];
              }
            }

            $cart_response = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $selected_variations, $modifier_items );
     
          }
        }
      }

      if ( !empty( $cart_response ) ) {
        $status = 'success';
      } else {
        $status = 'error';
      }
    }

    $cart_contents = wwro_get_cart_contents();

    $response = array(
      'success_message' => sprintf( __( '%s added to cart', 'wowrestro' ), $product_name ),
      'status'          => $status,
      'product_name'    => $product_name,
      'cart_content'    => $cart_contents,
    );

    $response = apply_filters( 'wowrestro_cart_data', $response, $_POST, $is_valid );

    echo wp_send_json( $response );
    wp_die();
  }

  /**
   * Ajax update cart item
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function product_update_cart() {
     if (!is_admin()){

      check_ajax_referer( 'update-cart-item', 'security' );
     }

    global $woocommerce;

    $product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : '';
    $quantity       = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
    $item_key       = isset( $_POST['item_key'] ) ? sanitize_key( $_POST['item_key'] ) : '';
    $modifier_data  = ( isset( $_POST['modifier_data'] ) && is_array( $_POST['modifier_data'] ) ) ? $_POST['modifier_data'] : array();
    $variation_id   = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : '';
    $special_note   = isset( $_POST['special_note'] ) ? sanitize_text_field( $_POST['special_note'] ) : '';

    $cart = WC()->cart->cart_contents;

    $product_name = '';

    $is_valid = apply_filters( 'validate_add_to_cart', true, $_POST );
    
    if ( !empty( $product_id ) && !empty( $item_key ) && $is_valid ) {
   

      $product = wc_get_product( $product_id );
      $product_name = $product->get_name();

      $modifier_items  = wwro_format_modifiers( $modifier_data, $quantity, $product );


      if ( !empty( $special_note ) && is_array( $modifier_items ) ) {
        $modifier_items['special_note'] = $special_note;
      }

      if ( $product->get_type() == 'variable' ) {

        $variation_data = isset( $_POST['postdata'] ) ? (array) $_POST['postdata'] : array();
        $cart_variation_id = $cart[$item_key]['variation_id'];

        if ( !empty( $variation_id ) ) {

          if ( $cart_variation_id == $variation_id ) {

            // Update quantity
            $cart_response = $woocommerce->cart->set_quantity( $item_key, $quantity );

            WC()->cart->cart_contents[$item_key]['special_note'] = $modifier_items['special_note'];

            // Update Item modifiers
            WC()->cart->cart_contents[$item_key]['modifiers'] = $modifier_items['modifiers'];

          } else {

            // Lets delete the item first
            WC()->cart->remove_cart_item( $item_key );

            // Now add the new item
            $variations = new WC_Product_Variable( $product_id );
            $variations = $variations->get_available_variations();
            $available_variation_ids = array();

            if ( is_array( $variations ) ) {
              $available_variation_ids = wp_list_pluck( $variations, 'variation_id' );
            }

            if ( in_array( $variation_id, $available_variation_ids ) ) {

              $selected_variations = array();

              foreach( $variation_data as $key => $variation_attr ) {
                if( strpos( $variation_attr['name'], 'attribute' ) !== false ) {
                  $selected_variations[ $variation_attr['name'] ] = $variation_attr['value'];
                }
              }
            }

            $cart_response = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $selected_variations, $modifier_items );
          }
        }

      } else {

        // Update Quantity
        $cart_response = $woocommerce->cart->set_quantity( $item_key, $quantity );

        // Update Item modifiers
        WC()->cart->cart_contents[$item_key]['modifiers'] = $modifier_items['modifiers'];

        //Update special instruction
        $special_note = isset( $modifier_items['special_note'] ) ? $modifier_items['special_note'] : '';

        WC()->cart->cart_contents[$item_key]['special_note'] = $special_note;
      }
      if ( $cart_response ) {
        $status = 'success';
      } else {
        $status = 'error';
      }
    }

    // Updating Woocommerce Cart and Totals
    WC()->cart->calculate_totals();

    $cart_contents = wwro_get_cart_contents();

    $response = array(
      'success_message' => sprintf( __( '%s updated in cart', 'wowrestro' ), $product_name ),
      'status'          => $status,
      'product_name'    => $product_name,
      'cart_content'    => $cart_contents,
    );

   $response = apply_filters( 'wowrestro_cart_data', $response ,$_POST ,$is_valid );

    echo wp_send_json( $response );
    wp_die();
  }

  /**
   * Remove Product From Cart
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function product_remove_cart() {

     if (!is_admin()){

       check_ajax_referer( 'product-remove-cart', 'security' );
     }

    global $woocommerce;

    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : '';
    $cart_key = isset( $_POST['cart_key'] ) ? sanitize_key( $_POST['cart_key'] ) : '';

    $status = 'error';

    if ( !empty( $product_id ) && !empty( $cart_key ) ) {

      $product = wc_get_product( $product_id );
      $product_name = $product->get_name();
      WC()->cart->remove_cart_item( $cart_key );

      $status = 'success';
    }

    $cart_contents = wwro_get_cart_contents();

    $response = array(
      'status'       => $status,
      'cart_content' => $cart_contents,
      'message'      =>  sprintf( __( '%s has been removed from cart', 'wowrestro' ), $product_name ),
    );

    $response = apply_filters( 'wowrestro_cart_data', $response ,$_POST ,true );

    echo wp_send_json( $response );
    wp_die();
  }

  /**
   * Empty Cart
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function empty_cart() {
    
     if (!is_admin()){
      check_ajax_referer( 'empty-cart', 'security' );
     }

    ob_start();

    global $woocommerce;

    $woocommerce->cart->empty_cart();

    // Empty the session for Service
    wowrestro_remove_session( 'service_type' );
    wowrestro_remove_session( 'service_time' );

    $cart_contents = wwro_get_cart_contents();

    $response = array(
      'status'       => 'success',
      'cart_content' => $cart_contents,
    );

    $response = apply_filters( 'wowrestro_cart_data', $response ,$_POST ,true );

    echo wp_send_json( $response );
    wp_die();
  }

  /**
   * Update Service Type and Time
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function update_service_time() {

    $service_type       = !empty( $_POST['selected_service'] ) ? sanitize_text_field( $_POST['selected_service'] ) : 'pickup';
    $service_time       = !empty( $_POST['selected_time'] ) ? sanitize_text_field( $_POST['selected_time'] ) : '';
    $service_timestamp  = !empty( $_POST['selected_timestamp'] ) ? sanitize_text_field( $_POST['selected_timestamp'] ) : '';

    if ( !empty( $service_type ) && !empty( $service_time ) ) {
      wowrestro_set_session( 'service_type', $service_type );
      wowrestro_set_session( 'service_time', $service_time );
      wowrestro_set_session( 'service_timestamp', $service_timestamp );
    }

    $service_time = ( $service_time == "asap" ) ? get_option( '_wowrestro_asap_text' ) : $service_time;

    $response = array(
      'status'        => 'success',
      'service_type'  => wwro_get_service_label( $service_type ),
      'service_time'  => $service_time,
    );

    $response = apply_filters( 'wowrestro_check_service_slot', $response, $_POST );
  
    echo wp_send_json( $response );
    wp_die();
  }

  /**
   * Validate Proceed to Checkout
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function validate_proceed_checkout() {

    $get_response = wwro_pre_validate_order();
    wp_send_json( $get_response );
    wp_die();
  }

  /**
   * Update service time option on checkout
   * 
   * @since 1.0
   */
  public function update_service_time_option_checkout() {

    $service_time_option  = isset( $_POST['service_time_option'] ) ? sanitize_text_field( $_POST['service_time_option'] ) : '';
    $service_time         = isset( $_POST['service_time'] ) ? sanitize_text_field( $_POST['service_time'] ) : '';
    $service_type         = isset( $_POST['service_type'] ) ? sanitize_text_field( $_POST['service_type'] ) : '';

    $time_format = wwro_get_store_time_format();

    if ( !empty( $service_time_option ) ) {
      if ( $service_time_option == 'asap' ) {
        wowrestro_remove_session( 'service_time' );
        wowrestro_remove_session( 'service_formated_date' );
        wowrestro_remove_session( 'service_raw_date' );
        wowrestro_set_session( 'service_time', 'asap' );
      }
    }

    if ( !empty( $service_time ) ) {
      $interval           = apply_filters( 'service_time_inteval', get_option( $service_type . '_time_interval' ), $service_type );
      $service_timestamp  = $service_time;

      if( strpos( $service_time, ' - ' ) !== false ){
        $service_time_array = explode( "-", $service_time );
        $start_interval     = current( $service_time_array );
        $end_interval       = end( $service_time_array );    
        $store_adj_time     = date( $time_format, strtotime( trim( $end_interval  ) ) );
        $service_time       = date( $time_format, strtotime( trim( $start_interval ) ) );
      } else {
        $store_adj_time = date( $time_format, strtotime( $service_time .' +' . $interval .' minutes' ) );
      }
      
      $service_time     = date( $time_format, strtotime( $service_time ) );
      $service_time_val = apply_filters( 'wowrestro_service_time_checkout', $service_time . ' - ' . $store_adj_time,  $service_time, $store_adj_time );

      wowrestro_remove_session( 'service_time' );
      wowrestro_set_session( 'service_time', $service_time_val );
      wowrestro_set_session( 'service_timestamp', $service_timestamp );
    }

    wp_send_json( [ 'status' => 'success' ] );
    wp_die();
  }

}

WWRO_AJAX::init();
