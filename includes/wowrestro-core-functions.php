<?php
/**
 * WoWRestro Core Functions
 *
 * General core functions available on both the public and admin.
 *
 * @package WoWRestro\Functions
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Get WoRestro session
 *
 * @since 1.0
 */
function wowrestro_get_session( $name ) {

  $value = '';

  // Get woocommerce session
  if ( !empty( $name ) && isset( WC()->session ) ) {
    $value = WC()->session->get( $name );
  }

  return $value;

}

/**
 * Set WoWRestro session
 *
 * @since 1.0
 */
function wowrestro_set_session( $name, $value ) {
  if ( !empty( $name ) ) {
    // Set woocommerce session
    WC()->session->set( $name , $value );
  }

}

/**
 * Remove WoWRestro session
 *
 * @since 1.0
 */
function wowrestro_remove_session( $name ) {

  if ( !empty( $name ) ) {
    // Remove woocommerce session
    WC()->session->__unset( $name );
  }

}

/**
 * Get the list of Modifiers in WoWRestro
 *
 * @since 1.0
 */
function wwro_get_modifiers( $args = array() ) {

	$defaults = array(
	    'taxonomy' => 'food_modifiers',
	    'hide_empty' => false,
	);
	$args = wp_parse_args( $args, $defaults );
	$modifiers = get_terms( $args );

	return $modifiers;

}

/**
 * Get local date from date string
 *
 * @since 1.0
 * @return string | localized date based on date string
 */
function wowrestro_local_date( $date ) {

  $date_format = apply_filters( 'wowrestro_date_format', get_option( 'date_format', true ) );
  $timestamp  = strtotime( $date );
  $local_date = empty( get_option( 'timezone_string' ) ) ? date_i18n( $date_format, $timestamp ) : wp_date( $date_format, $timestamp );
  
  return apply_filters( 'wowrestro_local_date', $local_date, $date );

}

/**
 * Get all the modifiers for selected product
 *
 * @since 1.0
 */
function wwro_get_food_modifiers( $product_id ) {

  $terms = get_the_terms( $product_id, 'food_modifiers' );

	if( $terms && !is_wp_error( $terms )) {
		return $terms;
	} else {
		return array();
	}

}

/**
 * Load template
 *
 * @param string $template_name
 * @param array $args
 * @param string $template_path
 * @param string $default_path
 * @since 1.0
 */
 function wwro_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ) {

  	if ( $args && is_array( $args ) ) {
    	extract( $args );
    }
    $located = wwro_locate_template( $template_name, $template_path, $default_path );
    include ( $located );

}

/**
 * Locate template file
 *
 * @param string $template_name
 * @param string $template_path
 * @param string $default_path
 * @return string
 * @since 1.0
 */
 function wwro_locate_template( $template_name, $template_path = '', $default_path = '' ) {

	$default_path = apply_filters( 'wowrestro_template_path', $default_path );

  if ( ! $template_path ) {
  	$template_path = 'wowrestro';
  }
  if ( ! $default_path ) {
      $default_path = WWRO_ABSPATH . 'templates/';
  }

  // Look within passed path within the theme - this is priority
  $template = locate_template( array( trailingslashit( $template_path ) . $template_name, $template_name ) );

  // Add support of third perty plugin
  $template = apply_filters( 'wwro_locate_template', $template, $template_name, $template_path, $default_path );

  // Get default template
  if ( ! $template ) {
  	$template = $default_path . $template_name;
  }
  return $template;

}

/**
 * Filter for add to cart button text.
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_add_to_cart_text() {

  return apply_filters( 'wowrestro_product_add_to_cart_text', __( 'Add', 'wowrestro' ) );

}

/**
 * Filter for add to cart button text for product modal.
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_modal_add_to_cart_text() {

  return apply_filters( 'wowrestro_modal_product_add_to_cart_text', __( 'Add To Cart', 'wowrestro' ) );

}

/**
 * Filter for update cart button text for product modal.
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_modal_update_cart_text() {

  return apply_filters( 'wowrestro_modal_product_update_cart_text', __( 'Update Cart', 'wowrestro' ) );

}

/**
 * Filter for adding to cart text
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_cart_processing_message() {

  return apply_filters( 'wowrestro_cart_processing_text', __( 'Please wait', 'wowrestro' ) );

}

/**
 * Filter to clear cart message
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_empty_cart() {

  return apply_filters( 'wwro_empty_cart_text', __( 'Clear all', 'wowrestro' ) );

}

/**
 * Show message when cart is empty
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_empty_cart_message() {

  return apply_filters( 'wwro_empty_cart_message', __( 'Your cart is empty', 'wowrestro' ) );

}

/**
 * Get an array of exclude categories
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_exclude_categories() {

  $exclude_categories = get_option( '_wowrestro_exclude_categories', true );
  $exclude_categories = is_array( $exclude_categories ) ? $exclude_categories : array();

  $args = array(
    'post_type'      => 'product',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'fields'         => 'ids',
  );

  $args['meta_query'][] = array(
    'key'     => '_food_item',
    'compare' => 'NOT EXISTS'
  );

  $products = get_posts( $args );

  $ex_non_food_cat = [];

  if ( !empty( $products ) ) {
    foreach ( $products as $key => $product ) {
      $product_cats = wp_get_post_terms( $product , 'product_cat' ) ;
      if ( !empty( $product_cats ) ) {
        foreach ( $product_cats as $key => $product_cat ) {
          $ex_non_food_cat[] = $product_cat->term_id;
        }
      }
    }
  }
 
  if ( !empty( $ex_non_food_cat ) ) {

    $ex_non_food_cat = array_unique( $ex_non_food_cat );

    $exclude_categories = array_merge( $ex_non_food_cat, $exclude_categories );

    $exclude_categories = array_unique( $exclude_categories );

  }

  $exclude_categories = apply_filters( 'wowrestro_exclude_categories', $exclude_categories );
  return $exclude_categories;

}

/**
 * Get cat contents HTML with ajax call
 *
 * @access public
 * @return void
 * @since 1.0
 */
function wwro_get_cart_contents() {

  ob_start();
  wwro_get_template( 'cart/wowrestro-cart.php' );
  return ob_get_clean();
  
}

/**
 * Show default service time when set based
 * on Admin Settings
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wowrestro_service_time( $service_modal_option ) {

  // wowrestro_remove_session('service_time');
  $service_type = wowrestro_get_session( 'service_type' );
  $service_time = wowrestro_get_session( 'service_time' );
  $service_modal_option = get_option( '_wowrestro_service_modal_option', 'auto' );

  if ( empty( $service_type ) && in_array( $service_modal_option, array( 'hide', 'auto' ) ) ) {
    $service_type = get_option( '_wowrestro_default_selected_service', 'pickup' );
    $service_time = wowrestro_get_current_available_time_slot( $service_type );
  }

  if ( $service_time == 'asap' ) {
    $service_time = get_option( '_wowrestro_asap_text', 'ASAP' );
  }

   $output = '<span class="wowrestro-service-type">'. __(wwro_get_service_label( $service_type ), 'wowrestro') .'</span> : <span class="wowrestro-service-time">'. __(strtoupper( $service_time ), 'wowrestro') .'</span> <a class="wowrestro-change-service wwr-primary-color" href="javascript:void(0)">'. __('Change?', 'wowrestro') .'</a>';

  $output = apply_filters( 'wwro_service_options_frontend', $output, $service_type, $service_time );
  if( ! empty( $service_time ) ) {
    $hidden_class = '';
  } else {
    $hidden_class = 'wwr-hidden';
  }

  $html = '';
  $html .= '<div class="' . $hidden_class . ' wowrestro-cart-service-settings">';
  $html .= $output;
  $html .= '</div>';

  return $html;
  
}

/**
 * Set woocomerce session
 */
add_action( 'woocommerce_init', 'wwro_enable_wc_session_cookie' );
function wwro_enable_wc_session_cookie() {

  if( is_admin() )
    return;

  if ( isset( WC()->session ) && ! WC()->session->has_session() ) 
    WC()->session->set_customer_session_cookie( true ); 
}

/**
 * Set default session depending upon admin settings
 *
 * @author WoWRestro
 * @since 1.0
 * @return void
 */
add_action( 'template_redirect', 'wwro_set_default_sessions', 10, 1 );
function wwro_set_default_sessions() {

  $service_modal_option = get_option( '_wowrestro_service_modal_option', 'auto' );
  $service_type = wowrestro_get_session( 'service_type' );
  $service_time = wowrestro_get_session( 'service_time' );

  if ( empty( $service_type ) && in_array( $service_modal_option, array( 'hide', 'auto' ) ) ) {

    // Get defalut value
    $default_selected_service     = get_option( '_wowrestro_default_selected_service', 'pickup' );
    $current_available_time_slot  = wowrestro_get_current_available_time_slot( $service_type );

    // Set service values
    $service_type = apply_filters( 'default_selected_service_type', $default_selected_service );
    $service_time = apply_filters( 'current_available_service_time', $current_available_time_slot );

    wowrestro_remove_session( 'service_time' );
    wowrestro_remove_session( 'service_type' );

    // Set service Session
    wowrestro_set_session( 'service_type', $service_type );
    wowrestro_set_session( 'service_time', $service_time );

  }

}

/**
 * Add class to body if wowrestro shortcode is used
 *
 * @access public
 * @return arrar
 * @since 1.0 
 */
add_filter( 'body_class', 'wwro_body_class' );
function wwro_body_class( $class ) {

    global $post;

    $service_type = wowrestro_get_session( 'service_type' );
    if ( $service_type == 'pickup' ) {
      $class[] = 'checkout-service-pickup-option';
    }

    if( isset($post->post_content) && has_shortcode( $post->post_content, 'wowrestro' ) ) {
        $class[] = 'wowrestro';
    }
    return $class;
}

/**
 * Get current current available time slot
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wowrestro_get_current_available_time_slot( $service_type ) {

  $time_format        = wwro_get_store_time_format();
  $store_open_time    = get_option( '_wowrestro_open_time' );
  $store_close_time   = get_option( '_wowrestro_close_time' );
  $prepation_time     = get_option( '_wowrestro_food_prepation_time' );

  $prepation_time     = $prepation_time * 60;

  $open_time          = strtotime( date_i18n( 'Y-m-d' ) . ' ' . $store_open_time );
  $close_time         = strtotime( date_i18n( 'Y-m-d' ) . ' ' . $store_close_time );

  $pickup_interval    = apply_filters( 'wowrestro_pickup_time_interval', get_option( 'pickup_time_interval' ) );
  $pickup_interval    = intval( $pickup_interval ) * 60;

  $delivery_interval  = apply_filters( 'wowrestro_delivery_time_interval', get_option( 'delivery_time_interval' ) );
  $delivery_interval  = intval( $delivery_interval ) * 60;

  $current_unix_time  = current_time( 'timestamp' );

  $store_hour = '';

  if ( $prepation_time > 0 ) { $current_unix_time += $prepation_time; }

  $store_timings = array();

  if ( $current_unix_time >= $open_time && $current_unix_time <= $close_time ) {
    if ( $service_type == 'delivery' ) {
      if( ( $close_time - $open_time ) > $delivery_interval )
        $store_timings = range( $open_time, $close_time, $delivery_interval );
    } else {
      if( ( $close_time - $open_time ) > $pickup_interval )
        $store_timings = range( $open_time, $close_time, $pickup_interval );
    }
  }

  if ( !empty( $store_timings ) ) {
    foreach( $store_timings as $store_time ) {
      if ( $store_time > $current_unix_time ) {
        $store_hour = $store_time;
        break;
      }
    }
  }

  $store_hour = date( $time_format, $store_hour );

  return apply_filters( 'wowrestro_current_available_time_slot', $store_hour, $store_timings, $service_type );

}

/**
 * Show store message
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_store_meassge( $service_type ) {

  if ( wwro_check_store_closed( $service_type ) ) {
    ob_start();
    $store_message = get_option( '_wowrestro_store_closed_message', true );
    $store_message = apply_filters( 'wowrestro_store_message', $store_message, $service_type );
    ?>
      <div class="wowrestro-store-close-msg wowrestro-close-msg-wrp">
        <span>
          <?php echo esc_html( $store_message ); ?>
        </span>
      </div>
    <?php
    echo ob_get_clean();
  }

}

/**
 * Check whether store is closed
 *
 * @access public
 * @return string
 * @since 1.0
 */
function wwro_check_store_closed( $service_type ) {

  $current_time     = current_time( 'timestamp' );
  $store_open_time  = get_option( '_wowrestro_open_time', true );
  $store_close_time = get_option( '_wowrestro_close_time', true );

  if ( !empty( $store_open_time ) ) {
    $store_open_time = strtotime( date_i18n( 'Y-m-d' ) . ' ' . $store_open_time );
  }

  if ( !empty( $store_close_time ) ) {
    $store_close_time = strtotime( date_i18n( 'Y-m-d' ) . ' ' . $store_close_time );
  }

  $response = false;

  if ( $current_time > $store_close_time || $current_time < $store_open_time ) {
    $response = true;
  }

  return apply_filters( 'wowrestro_check_store_closed', $response, $service_type );

}

/**
 * Verify if we need to check minimum order amount
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_check_min_order() {

  global $woocommerce;
  $pickup_min_order = get_option( '_wowrestro_min_pickup_order_amount', true );
  $cart_subtotal = $woocommerce->cart->subtotal;

  if ( !empty( $pickup_min_order ) && $pickup_min_order > $cart_subtotal ) {
    $response = true;
  }
  else {
    $response = false;
  }

  return $response;

}

/**
 * Get list of modifier selected for an item
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_term_choice( $term_id ) {

  if ( !empty( $term_id ) ) {
    $choice = get_term_meta( $term_id, '_wowrestro_modifier_selection_option', true );
    $choice = empty( $choice ) ? 'single' : $choice;
    $choice = ( $choice == 'single' ) ? 'radio' : 'checkbox';
    return apply_filters( 'wowrestro_category_choice', $choice, $term_id );
  }

}

/**
 * Get modifier price
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_wwro_modifier_price( $product, $price ) {

  $consider_tax = true;

  if ( $consider_tax ) {
    $price = 'incl' === get_option( 'woocommerce_tax_display_shop' ) ?
    wc_get_price_including_tax( $product, array(
      'qty' => 1,
      'price' => $price,
    )) :
    wc_get_price_excluding_tax( $product, array(
      'qty' => 1,
      'price' => $price,
    ));
  }

  return apply_filters( 'wowrestro_modifier_item_price', $price );

}

/**
 * Format modifiers for frontend display
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_format_modifiers( $modifiers, $quantity, $product ) {

  $modifiers_array = array();

  if ( !empty( $modifiers ) && is_array( $modifiers ) ) {

    foreach( $modifiers as $key => $modifier ) {

      $modifier_slug = isset( $modifier['value'] ) ? sanitize_text_field( $modifier['value'] ) : '';
      $modifier_data = get_term_by( 'slug', $modifier_slug, 'food_modifiers' );
      $modifier_id   = $modifier_data->term_id;
      $price         = get_term_meta( $modifier_id, '_wowrestro_modifier_item_price', true );
      $price         = !empty( $price ) ? floatval( $price ) : '0.00';

      $wwro_modifier_price = wwro_get_wwro_modifier_price( $product, $price );

      $modifiers_array['modifiers'][$key]['quantity']   = $quantity;
      $modifiers_array['modifiers'][$key]['modifier_item'] = $modifier;
      $modifiers_array['modifiers'][$key]['price']      = $wwro_modifier_price;
      $modifiers_array['modifiers'][$key]['raw_price']  = $price;
    }
  }
  return apply_filters( 'wowrestro_modifier_items', $modifiers_array );

}

/**
 * List of formatted modifiers
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_formatted_modifiers( $cart_item ) {

  $modifiers_html = '';

  if ( isset( $cart_item['modifiers'] ) && !empty( $cart_item['modifiers'] ) ) {

    foreach( $cart_item['modifiers'] as $key => $modifiers ) {

      if ( isset( $modifiers['modifier_item']['value'] ) && !empty( isset( $modifiers['modifier_item']['value'] ) ) ) {

        $modifier_item_name = $modifiers['modifier_item']['value'];
        $modifier_item = get_term_by( 'slug', $modifier_item_name, 'food_modifiers' );
        $modifier_quantity = isset( $modifiers['quantity'] ) ? $modifiers['quantity'] : 1;

        $wwro_modifier_price = isset( $modifiers['price'] ) ? $modifiers['price'] : 0 ;

        if ( !empty( $modifier_item ) ) {
          $wwro_modifier_price = isset( $modifiers['price'] ) ? wc_price( $modifiers['price'] ) : w_price( '0.00' );
          $modifiers_html .= '<p class="wowrestro-cart-modifier-item">- ' . $modifier_item->name . ' - ' . $modifier_quantity . ' &times; ' . $wwro_modifier_price .  '</p>';
        }
      }
    }
  }
  return $modifiers_html;

}

/**
 * Get Modifiers on Edit product
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_check_modifier_in_cart( $field_name, $category_slug, $cart_modifiers ) {

  $modifier_values = array();

  if ( is_array( $cart_modifiers ) && !empty( $cart_modifiers ) ) {
    foreach( $cart_modifiers as $key => $cart_modifier ) {
      if ( isset( $cart_modifier['modifier_item'] ) && !empty( $cart_modifier['modifier_item'] ) ) {
        $selected_modifier = isset( $cart_modifier['modifier_item']['value'] ) ? $cart_modifier['modifier_item']['value'] : '';

        if ( !empty( $selected_modifier ) ) {
          array_push( $modifier_values, $selected_modifier );
        }
      }
    }
  }

  $cond = in_array( $category_slug, $modifier_values ) ? true : false;
  return $cond;

}

/**
 * Sort Modifiers on public 
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_sort_modifier_categories( $modifier_categories ) {

  $parent_ids = array();
  $term_array = array();

  if ( is_array( $modifier_categories ) && !empty( $modifier_categories ) ) {
    foreach( $modifier_categories as $modifier_category ) {
      array_push( $parent_ids, $modifier_category->parent );
    }
    $parent_ids = array_unique( $parent_ids );

    foreach( $parent_ids as $parent_id ) {
      foreach( $modifier_categories as $key => $modifier_category ) {
        if ( $modifier_category->parent == $parent_id ) {
          array_push( $term_array, $modifier_categories[$key] );
        }
      }
    }
  }

  return $term_array;

}

/**
 * Get shorcode attrs for layout
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_render_shortcode_cats( $args ) {

  $category_ids = array();
  $categories   = array();

  if ( $args['category'] && $args['category'] != '' ) {
    $categories = explode( ',', $args['category'] );
  }

  if ( is_array( $categories ) && !empty( $categories ) ) {
    foreach( $categories as $category ) {
      $is_ids = is_int( $category ) && ! empty( $category );

      if ( $is_ids ) {
        $term_id = $category;
      } else {
        $term = get_term_by( 'slug', $category, 'product_cat' );
        if( ! $term ) {
          continue;
        }

        $term_id = $term->term_id;
      }
      $category_ids[] = $term_id;
    }
  }

  return $category_ids;

}

/**
 * Get list of modifiers from Order Meta
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_modifiers_from_meta( $item_id ) {

  if ( empty( $item_id ) )
    return;

  $modifier_items = wc_get_order_item_meta( $item_id, '_modifier_items', true );

  $item_data = [];

  if ( is_array( $modifier_items ) && !empty( $modifier_items ) ) {

    foreach( $modifier_items as $key => $modifier_item ) {

      $modifier_slug = isset( $modifier_item['modifier_item']['value'] ) ? $modifier_item['modifier_item']['value'] : '';
      $modifier_quantity = isset( $modifier_item['quantity'] ) ? $modifier_item['quantity'] : 1;
      $wwro_modifier_price = isset( $modifier_item['price'] ) ? $modifier_item['price'] : 0;
      $wwro_modifier_price = wwro_calculate_wwro_modifier_price( $wwro_modifier_price, $modifier_quantity );
      $wwro_modifier_price = wc_price( $wwro_modifier_price );

      if ( !empty( $modifier_slug ) ) {

        $modifier_term = get_term_by( 'slug', $modifier_slug, 'food_modifiers' );

        if ( $modifier_term ) {

          $item_data[$key]['name']  = $modifier_term->name;
          $item_data[$key]['price'] = $wwro_modifier_price;

        }
      }
    }
  }

  return $item_data;

}

/**
 * Calculate price of individual modifier in terms of quantity
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_calculate_wwro_modifier_price( $wwro_modifier_price, $modifier_quantity ) {

  $price = 0;

  if ( !empty( $modifier_quantity ) && !empty( $wwro_modifier_price ) ) {
    $price = (int) $modifier_quantity  * floatval( $wwro_modifier_price );
  }
  return $price;

}

/**
 * Gete avilable times of Delivery and Pickup
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_store_timing( $service_type ) {

  $store_open_time    = get_option( '_wowrestro_open_time' );
  $store_close_time   = get_option( '_wowrestro_close_time' );

  $open_time          = strtotime( date_i18n( 'Y-m-d' ) . ' ' . $store_open_time );
  $close_time         = strtotime( date_i18n( 'Y-m-d' ) . ' ' . $store_close_time );

  $pickup_interval    = get_option( 'pickup_time_interval' );
  $pickup_interval    = intval( $pickup_interval ) * 60;

  $delivery_interval  = get_option( 'delivery_time_interval' );
  $delivery_interval  = intval( $delivery_interval ) * 60;

  $current_unix_time  = current_time( 'timestamp' );
  $store_timings      = array();

  if ( $current_unix_time >= $open_time && $current_unix_time <= $close_time ) {

    if ( $service_type == 'delivery' ) {
      if( ( $close_time - $open_time ) > $delivery_interval )
        $store_timings = range( $open_time, $close_time, $delivery_interval );
    } else {
      if( ( $close_time - $open_time ) > $pickup_interval )
        $store_timings = range( $open_time, $close_time, $pickup_interval );
    }
  }

  $store_hours = array();

  if ( !empty( $store_timings ) ) {
    foreach( $store_timings as $store_times ) {
      if ( $store_times > $current_unix_time ) {
        $store_hours[] = $store_times;
      }
    }
  }

  return apply_filters( 'wwro_store_hours', $store_hours  );

}

/**
 * Get store timing format
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_store_time_format() {
  
  $store_time_format = get_option('time_format');

  return apply_filters( 'wowrestro_store_time_format', $store_time_format );

}

/**
 * Get available service Hours
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_render_service_hours( $get_store_hours, $service_type ) {

  $time_format  = wwro_get_store_time_format();
  $interval     = get_option( $service_type . '_time_interval' );

  $selected_time = wowrestro_get_session( 'service_time' );

  $timeslot_mode = apply_filters( 'wowrestro_timeslot_mode', 'single' );

  ob_start();
  ?>
  <select class="wowrestro-service-hours wowrestro-service-hours-<?php echo esc_attr( $service_type ); ?>" >
    <?php
    if ( !empty( $get_store_hours ) && is_array( $get_store_hours ) ) :

      $current_date   = current_time( 'Y-m-d' );

      $count = 0;
      $get_store_hours = array_unique( $get_store_hours );
      $get_store_hours = array_values( $get_store_hours );

      foreach ( $get_store_hours as $time ):

        $loop_time   = date( $time_format, $time );
        $loop_time_2 = '';
        $sep         = '';
        $day         = date( 'w' );
        $day_number  = apply_filters( 'wowrestro_current_day' , $day );
        $break_array = [];
        $break_array = apply_filters( 'wowrestro_disabled_times', $break_array, $service_type, $day_number );

        if ( in_array( $loop_time, $break_array ) ) {
          $count++;
          continue;
        }

        if ( $count + 1 < count( $get_store_hours ) ) {
          $loop_time_2 = !empty( $get_store_hours[$count + 1] ) ? date( $time_format, $get_store_hours[$count + 1] ) : '';
          $sep         = !empty( $get_store_hours[$count + 1] ) ? ' - ' : '';
        }
        $timeslot = apply_filters( 'wowrestro_disabled_adjacent_timeslot', null, $service_type, $loop_time );

        if ( $timeslot_mode == 'single' ) {
          $display_format = $loop_time;
        } else if ( $timeslot_mode == 'multiple' ) {
          $display_format = $loop_time . $sep . $loop_time_2;
        }

        if ( $timeslot != $loop_time ) {
          ?>
            <option value="<?php echo esc_attr( $display_format ); ?>" <?php selected( $selected_time, $display_format, true ); ?> >
              <?php echo esc_html( $display_format ); ?>
            </option>
          <?php
        }
        $count++;
      endforeach;
    endif;
    ?>
  </select>
  <?php
  echo ob_get_clean();

}

/**
 * Add ASAP html to service option
 *
 * @access public
 * @return html
 * @since 1.0
 */
function wwro_render_asap( $service_type ) {

  $asap_option = get_option( '_wowrestro_enable_asap', 'yes' );
  if ( !empty( $asap_option ) && $asap_option == 'yes' ) {
    $asap_text = get_option( '_wowrestro_asap_text', __( 'ASAP', 'wowrestro' ) );
    $checked = ( wowrestro_get_session( 'service_time' ) == "asap" && wowrestro_get_session( 'service_type' ) == $service_type ) ? 'checked' : '';
    ?>
      <div class="wwr-service-time-wrap">
        <input id="asap-option-<?php echo esc_attr( $service_type ); ?>" type="radio" class="service-option asap-option asap-option-<?php echo esc_attr( $service_type ); ?>" name="service_option" value="asap" <?php echo esc_attr( $checked ); ?>>
        <label for="asap-option-<?php echo esc_attr( $service_type ); ?>"><?php echo esc_html( $asap_text ); ?></label>
      </div>
    <?php
  }

}

/**
 * Add later html to service option
 *
 * @access public
 * @return html
 * @since 1.0
 */
function wwro_render_later( $service_type ) {

  $later_text = get_option( '_wowrestro_later_text', __( 'Later', 'wowrestro' ) );
  $checked = ( wowrestro_get_session( 'service_time' ) != "asap" && wowrestro_get_session( 'service_type' ) == $service_type ) ? 'checked' : '';
  $asap_option = get_option( '_wowrestro_enable_asap', 'yes' );
  if ( !empty( $asap_option ) && $asap_option == 'yes' ) {
    ?>
      <div class="wwr-service-time-wrap">
        <input id="later-option-<?php echo esc_attr( $service_type ); ?>" type="radio" class="service-option later-option later-option-<?php echo esc_attr( $service_type ); ?>" name="service_option" value="later" <?php echo esc_attr( $checked ); ?>>
        <label for="later-option-<?php echo esc_attr( $service_type ); ?>"><?php echo esc_html( $later_text ); ?></label>
      </div>
    <?php
  }

}

/**
 * Get list of available services
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_available_services() {

  $enable_delivery = ( get_option( 'enable_delivery' ) == 'yes' ) ? true : false;
  $enable_pickup   = ( get_option( 'enable_pickup' ) == 'yes' ) ? true : false ;

  if( $enable_delivery && $enable_pickup )
    return 'all';
  else if( $enable_pickup )
    return 'pickup';
  else if( $enable_delivery )
    return 'delivery';
  else
    return 'pickup';

}

/**
 * Get label to display for a particular Service Type
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_service_label( $service ) {

  $services = array(
    'pickup'    => get_option( '_wowrestro_pickup_label', __( 'Pickup', 'wowrestro' ) ),
    'delivery'  => get_option( '_wowrestro_delivery_label', __( 'Delivery', 'wowrestro' ) ),
  );

  $services = apply_filters( 'wowrestro_active_services', $services );

  if ( array_key_exists( $service, $services ) ) {
    $service = $services[$service];
  }

  return $service;

}

/**
 * Get default service details in case of Fallback
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_default_service_details() {

  $service_type = wwro_get_default_service_type();

  $current_time     = current_time( 'timestamp' );
  $preparation_time = get_option( '_wowrestro_food_prepation_time', true );
  $preparation_time = $preparation_time * 60;

  $final_time = $current_time + $preparation_time;

  $time_format = wwro_get_store_time_format();

  $service_time = date_i18n( $time_format, $final_time );

  return array(
    'service_type' => $service_type,
    'service_time' => $service_time,
  );

}

/**
 * Get default service type in case of Fallback
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_default_service_type() {

  $service_type = wowrestro_get_session( 'service_type' );

  if ( empty( $service_type ) ) {
    //check pickup is enabled or not
    $enable_pickup = get_option( 'enable_pickup', true );

    if ( $enable_pickup == 'yes' ) {
      $service_type == 'pickup';
    }
    else {
      $service_type == 'delivery';
    }
  }

  return apply_filters( 'wwro_get_default_service_type', $service_type );

}

/**
 * Validate all consitions with admin settings
 * before placing an order
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_pre_validate_order() {

  global $woocommerce;
  $service_type = wwro_get_default_service_type();

  $cart_subtotal = $woocommerce->cart->subtotal;

  if ( $service_type == 'pickup' ) {
    $min_order_amount   = get_option( '_wowrestro_min_pickup_order_amount', true );
    $min_error_message  = get_option( '_wowrestro_min_pickup_order_amount_error', true );
    $max_order_amount   = get_option( '_wowrestro_max_pickup_order_amount', true );
    $max_error_message  = get_option( '_wowrestro_max_pickup_order_amount_error', true );
  }
  else {
    $min_order_amount   = get_option( '_wowrestro_min_delivery_order_amount', true );
    $min_error_message  = get_option( '_wowrestro_min_delivery_order_amount_error', true );
    $max_order_amount   = get_option( '_wowrestro_max_delivery_order_amount', true );
    $max_error_message  = get_option( '_wowrestro_max_delivery_order_amount_error', true );
  }

  $min_search_text  = array( '{min_order_amount}', '{service_type}' );
  $min_replace      = array( wc_price( $min_order_amount ), $service_type );

  $min_error_message = str_replace( $min_search_text, $min_replace, $min_error_message );

  $max_search_text  = array( '{max_order_amount}', '{service_type}' );
  $max_replace      = array( wc_price( $max_order_amount ), $service_type );

  $max_error_message = str_replace( $max_search_text, $max_replace, $max_error_message );

  if ( $cart_subtotal == 0 ) {
    $response = array(
      'status'  => 'error',
      'message' => $min_error_message
    );
    return $response;
  }

  if ( $min_order_amount > 0 && $cart_subtotal < $min_order_amount ) {
    $response = array(
      'status'  => 'error',
      'message' => $min_error_message,
    );
  } elseif ( $max_order_amount > 0 && $cart_subtotal > $max_order_amount ) {
    $response = array(
      'status'  => 'error',
      'message' => $max_error_message,
    );
  } else {
    $response = array( 'status' => 'success' );
  }

  return $response;

}

/**
 * Get raw price of particular modifier with Tax Settings
 *
 * @access public
 * @return array
 * @since 1.0
 */
function wwro_get_modifier_raw_price( $modifier_slug ) {

  $price = 0;

  if ( !empty( $modifier_slug ) ) {
    $modifier_data = get_term_by( 'slug', $modifier_slug, 'food_modifiers' );

    if ( $modifier_data ) {
      $modifier_id   = $modifier_data->term_id;
      $price      = get_term_meta( $modifier_id, '_wowrestro_modifier_item_price', true );
    }
  }
  return $price;

}
