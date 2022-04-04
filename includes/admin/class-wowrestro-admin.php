<?php
/**
 * WoWRestro Admin
 *
 * @class    WWRO_Admin
 * @package  WoWRestro/Admin
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

/**
 * WWRO_Admin class.
 */
class WWRO_Admin {

  /**
   * Constructor.
   */
  public function __construct() {
    add_action( 'init', array( $this, 'includes' ) );
    add_action( 'admin_init', array( $this, 'buffer' ), 1 );
    add_action( 'admin_head', array( $this, 'inline_style_sheet' ) );
    add_action( 'woocommerce_before_order_itemmeta', array( $this, 'wowrestro_admin_order_line_item' ), 10, 3 );
    add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'wowrestro_hide_special_note' ), 10 );
    add_filter( 'manage_edit-shop_order_columns', array( $this , 'wowrestro_order_services_column' ), 99 );
    add_action( 'manage_shop_order_posts_custom_column', array( $this, 'wowrestro_add_services_data' ), 10  );
    add_action( 'wp_ajax_add_food_category', array( $this, 'wowrestro_add_food_category' ) );
    add_action( 'wp_ajax_select_modifier_category', array( $this, 'wowrestro_select_modifier_category' ) );
    add_action( 'wp_ajax_wowrestro_check_new_orders', array( $this, 'wowrestro_check_new_orders' ) );
    add_action( 'init', array( $this, 'wowrestro_check_extensions_update' ), 10, 1 );
  }

  /**
   * Check all extensions for updates
   * @since 1.2
   */
  public function wowrestro_check_extensions_update() {

    if ( !is_admin() || wp_doing_ajax() ) return;

    $wowrestro_licenses_setting = get_option( 'wowrestro_licenses_setting', true );
    $addon_license              = isset( $wowrestro_licenses_setting['all_access'] ) ? $wowrestro_licenses_setting['all_access'] : '';

    $list = array();

    $addons_list = apply_filters( 'wowresto_addon_list', $list );

    if ( !empty( $addons_list ) ) {

      foreach ( $addons_list as $key => $ext ) {
        
        if ( isset( $ext['path'] ) && ( $ext['path']== plugin_basename( WWRO_PLUGIN_FILE ) ) ) continue ;
        if( isset( $ext['path'] ) && isset( $ext['version'] ) ){

          new WOWResto_License( $ext['path'] , '' , $ext['version'], 'MagniGenie', $addon_license );
        }


      }

    }

  }

  /**
   * Output buffering allows admin screens to make redirects later on.
   */
  public function buffer() {
    ob_start();
  }

  /**
   * Enqueues the custom styles for Admin settings
   *
   * @since 1.0
   * Author WoWRestro
   */
  public function inline_style_sheet() {

    $sales_type = get_option( '_wowrestro_sales_type' );
    if ( $sales_type == 'all_product' ) {
      $display_food_option = 'none';
    }else{
      $display_food_option = 'block';
    }


    echo  '<style id="wowrestro-inline-css">
      #woocommerce-product-data .wc-tabs .food-options_tab{
        display: ' . $display_food_option . ';
      }';

    echo  '</style>';

  }

  /**
   * Include any classes we need within admin.
   */
  public function includes() {
    include_once dirname( __FILE__ ) . '/wowrestro-admin-functions.php';
    include_once dirname( __FILE__ ) . '/class-wowrestro-admin-menus.php';
    include_once dirname( __FILE__ ) . '/class-wowrestro-admin-assets.php';
  }

  /**
   * Add modifiers as line items to WooCommerce Order
   *
   * @since 1.0
   * @param int $item_id
   * @param obj $item
   * @param obj $product
   *
   * @return array $item_data
   */
  public function wowrestro_admin_order_line_item( $item_id, $item, $product ) {

    $item_data = '';
    
    $modifier_items = wwro_get_modifiers_from_meta( $item_id );

    if ( !empty( $modifier_items ) && is_array( $modifier_items ) ) {
      foreach( $modifier_items as $key => $modifier_item ) {
        $modifier_name  = isset( $modifier_item['name'] ) ? $modifier_item['name'] : '';
        $wwro_modifier_price = isset( $modifier_item['price'] ) ? $modifier_item['price'] : '';
        $item_data .= sprintf( '<span class="wowrestro_order_meta">%1$s - %2$s </span>', $modifier_name, $wwro_modifier_price );
      }
    }

    $special_note = wc_get_order_item_meta( $item_id, '_special_note', true );

    if ( !empty( $special_note ) ) {
      $item_data .= sprintf( '<span class="wowrestro_order_meta">Special Note : %1$s  </span>', $special_note );
    }

    echo $item_data;

  }

  /**
   * Restric special note to be shown at some places
   *
   * @author Magnigenie
   * @since 1.0
   * @param array $hidden_items
   */
  public function wowrestro_hide_special_note( $hidden_items ) {

    array_push( $hidden_items, '_special_note' );

    return $hidden_items;
  }

  /**
   * Setup service columns for Order Listing Page
   *
   * @author Magnigenie
   * @since 1.0
   * @param array $columns
   *
   * @return array $columns
   */
  public function wowrestro_order_services_column( $columns ) {

    $columns['service_type'] = __( 'Service Type', 'wowrestro' );
    $columns['service_time'] = __( 'Service Time', 'wowrestro' );
    return $columns;
  }

  /** 
   * Add services data to the services columns
   * mentioned in Order Listing page
   *
   * @author Magnigenie
   * @since 1.0
   * @param array $columns
   */
  public function wowrestro_add_services_data( $column ) {
    
    global $post;

    $order_id = $post->ID;

    if ( 'service_type' === $column ) {
      $service_type = get_post_meta( $order_id, '_wowrestro_service_type', true );
      $service_type = !empty( $service_type ) ? $service_type : 'pickup';
      echo wwro_get_service_label($service_type);
    }

    if ( 'service_time' === $column ) {
      $service_time = get_post_meta( $order_id, '_wowrestro_service_time', true );
      if ( empty( $service_time ) ) {
        $time_format  = wwro_get_store_time_format();
        $service_time = get_the_time( 'U', $order_id );
        $service_time = date( $time_format, $service_time );
      }
      echo esc_html( $service_time );
    }
  }

  /**
   * Ajax function to Add Food Modifier category
   *
   * @author Magnigenie
   * @since 1.0
   */
  public function wowrestro_add_food_category() {

    if ( !wp_verify_nonce( $_POST['nonce'], "add_modifier_category"))
       die();

    $modifier_category_name     = ( isset( $_POST['modifier_category_name'] ) ) ? sanitize_text_field( $_POST['modifier_category_name'] ) : '';
    $modifier_category_name_arr = explode( ' ', $modifier_category_name );
    
    if( !term_exists( $modifier_category_name, 'food_modifiers' ) ) {

      $parent_term_id = wp_insert_term(
          $modifier_category_name,
          'food_modifiers',
          array(
            'slug' => implode( '-', $modifier_category_name_arr ),
            'parent' => 0
          )
      );

      update_term_meta( $parent_term_id['term_id'], '_wowrestro_modifier_selection_option', sanitize_text_field( $_POST['modifier_category_type'] ) );

      $modifier_item_names = isset( $_POST['modifier_item_names'] ) ? array_filter( wp_unslash( (array) $_POST['modifier_item_names'] ) ) : '';

      foreach ( $modifier_item_names as $key => $modifier_item_name ) {
        
        if ( !empty( $modifier_item_name ) ) {
          $modifier_item_name_arr = explode( ' ', $modifier_item_name);
          $term_item_id = wp_insert_term(
            $modifier_item_name,
            'food_modifiers',
            array(
              'slug' => implode( '-', $modifier_item_name_arr ),
              'parent' => $parent_term_id['term_id'],
            )
          );

          // Update term meta
          update_term_meta( $term_item_id['term_id'], '_wowrestro_modifier_item_price', $_POST['modifier_item_price'][$key] );

        }

      }

      wp_send_json_success( [ 'food_category_id' => $parent_term_id['term_id'], 'food_category_name' => $modifier_category_name ] );

    }else{ 

      wp_send_json_error( [ 'message' => 'Category Already Exists' ] );

    }

    die();

  }

  /**
   * Get the modifier items from modifiers
   *
   * @author Magnigenie
   * @since 1.0
   */
  public function wowrestro_select_modifier_category() {

    ob_start();
    $parent_food_modifiers_id = isset( $_POST['modifier_category_id'] ) ? sanitize_text_field( $_POST['modifier_category_id'] ) : '';

    include_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/html-food-modifier-items-list.php';
    $html = ob_get_clean();

    wp_send_json_success( [ 'html' => $html ] );

    die();

  }

  /**
   * Check new order admin
   *
   * @author Magnigenie
   * @since 1.0
   */
  public function wowrestro_check_new_orders(){

    $last_order = get_option( 'wwro_last_order_id' );

    $query = new WC_Order_Query( array(
        'limit' => 1,
    ) );
    $orders = $query->get_orders();
    $order = $orders[0];

    if( is_array( $orders ) && $order->get_id() != $last_order ) {

      $order_id = $order->get_id();

      $placeholder = array( '{order_id}' => $order_id );

      $service_type = get_post_meta( $order_id, '_wowrestro_delivery_type', true );

      if ( !empty( $service_type ) ) {
        $service_type = ucfirst( $service_type );
      }

      $payment_status = $order->get_status();

      if ( $payment_status == 'publish' ) {
        $payment_status = 'Paid';
      }

      $payment_status = ucfirst( $payment_status );

      $search = array( '{order_id}', '{service_type}', '{payment_status}' );

      $replace = array( $order_id, $service_type, $payment_status );

      $body = get_option( '_wowrestro_notification_description' );
      $body = str_replace( $search, $replace, $body );

      $notification = array(
        'title' => get_option( '_wowrestro_notification_title' ),
        'body'  => $body,
        'icon'  => get_option( '_wowrestro_notification_icon' ),
        'sound' => get_option( '_wowrestro_notification_sound' ),
        'url'   => admin_url( 'post.php?post=' . $order_id . '&action=edit' )
      );
      update_option( 'wwro_last_order_id', $order_id  );
      wp_send_json( $notification );
    }
    wp_die();

  }

}
return new WWRO_Admin();