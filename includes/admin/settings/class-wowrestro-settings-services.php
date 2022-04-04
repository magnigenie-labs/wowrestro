<?php
/**
 * WoWRestro Services Settings
 *
 * @package WoWRestro/Admin
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Settings_Services', false ) ) {
  return new WWRO_Settings_Services();
}

/**
 * WWRO_Settings_Services.
 */
class WWRO_Settings_Services extends WWRO_Settings_Page {

  /**
   * Constructor.
   */
  public function __construct() {
    
    $this->id    = 'services';
    $this->label = __( 'Services', 'wowrestro' );

    parent::__construct();
  }

  /**
   * Get sections.
   *
   * @return array
   */
  public function get_sections() {
    
    $sections = array(
      ''           => __( 'General', 'wowrestro' ),
      'pickup'     => __( 'Pickup Service', 'wowrestro' ),
      'delivery'   => __( 'Delivery Service', 'wowrestro' ),
    );
    return apply_filters( 'wowrestro_get_sections_' . $this->id, $sections );
  }

  /**
   * Output the settings.
   */
  public function output() {
    
    global $current_section;

    $settings = $this->get_settings( $current_section );
    WWRO_Admin_Settings::output_fields( $settings );
  }

  /**
   * Save settings.
   */
  public function save() {
    
    global $current_section;

    $settings = $this->get_settings( $current_section );
    WWRO_Admin_Settings::save_fields( $settings );

    if ( $current_section ) {
      do_action( 'wowrestro_update_options_' . $this->id . '_' . $current_section );
    }

  }

  /**
   * Get settings array.
   *
   * @param string $current_section Current section name.
   * @return array
   */
  public function get_settings( $current_section = '' ) {

    $default_selected_service = get_option( '_wowrestro_default_selected_service' );

    $enable_delivery = $enable_pickup = $is_default = '';

    if ( 'pickup' === $current_section ) {

      if ( $default_selected_service == 'pickup' ) {
        $is_default = __( 'Pickup Service is <b><i>Enabled</i></b> by default.', 'wowrestro' );
        $enable_pickup = 'yes';
      }
      
      $settings = apply_filters(
        
        'wowrestro_pickup_settings',
        
        array(

          array(
            'title'     => __( 'Pickup Service', 'wowrestro' ),
            'type'      => 'title',
            'id'        => 'service_pickup_options',
            'desc'      => $is_default,
          ),

          array(
            'title'     => __( 'Enable Pickup', 'wowrestro' ),
            'type'      => 'checkbox',
            'id'        => 'enable_pickup',
            'desc'      => __( 'Enable Pickup Service', 'wowrestro' ),
            'default'   => $enable_pickup,
          ),

          array(
            'title'     => __( 'Pickup Label', 'wowrestro' ),
            'id'        => '_wowrestro_pickup_label',
            'type'      => 'text',
            'default'   => __( 'Pickup', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Pickup Time Interval', 'wowrestro' ),
            'type'      => 'number',
            'default'   => 30,
            'id'        => 'pickup_time_interval',
            'desc_tip'  => __( 'Enter pickup time interval in mins', 'wowrestro' ),
            'custom_attributes' => array(
              'min'   => 1,
              'max'   => 60,
              'step'  => 5,
            ),
          ),

          array(
            'title'     => __( 'Minimum Order Amount', 'wowrestro' ),
            'id'        => '_wowrestro_min_pickup_order_amount',
            'type'      => 'number',
            'desc_tip'  => __( 'Minimum order amount for pickup service.', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Minimum Order Amount Error', 'wowrestro' ),
            'id'        => '_wowrestro_min_pickup_order_amount_error',
            'type'      => 'textarea',
            'css'       => 'min-width: 50%; height: 75px;',
            'default'   => __( 'Minimum order price for {service_type} is {min_order_amount}', 'wowrestro' ),
            'desc_tip'  => __( 'Error message for minimum order amount. You can use variable {service_type} and {min_order_amount}', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Maximum Order Amount', 'wowrestro' ),
            'id'        => '_wowrestro_max_pickup_order_amount',
            'type'      => 'number',
            'desc_tip'  => __( 'Maximum order amount for pickup service.', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Maximum Order Amount Error', 'wowrestro' ),
            'id'        => '_wowrestro_max_pickup_order_amount_error',
            'type'      => 'textarea',
            'css'       => 'min-width: 50%; height: 75px;',
            'default'   => __( 'Maximum order price for {service_type} is {max_order_amount}', 'wowrestro' ),
            'desc_tip'  => __( 'Error message for maximum order amount. You can use variable {service_type} and {max_order_amount}', 'wowrestro' ),
          ),

          array(
            'type'      => 'sectionend',
            'id'        => 'service_pickup_options',
          ),

        )
      );
    
    } else if( 'delivery' === $current_section ) {

      if ( $default_selected_service == 'delivery' ){
        $is_default = __( 'Delivery Service is <b><i>Enabled</i></b> by default.', 'wowrestro' );
        $enable_delivery = 'yes';
      }
      
      $settings = apply_filters(
        
        'wowrestro_service_settings',
        
        array(

          array(
            'title'     => __( 'Delivery Service', 'wowrestro' ),
            'type'      => 'title',
            'id'        => 'delivery_options',
            'desc'      => $is_default,
          ),

          array(
            'title'     => __( 'Enable Delivery', 'wowrestro' ),
            'type'      => 'checkbox',
            'id'        => 'enable_delivery',
            'desc'      => __( 'Enable Delivery Service', 'wowrestro' ),
            'default'   => $enable_delivery,
          ),

          array(
            'title'     => __( 'Delivery Label', 'wowrestro' ),
            'id'        => '_wowrestro_delivery_label',
            'type'      => 'text',
            'default'   => __( 'Delivery', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Delivery Time Interval', 'wowrestro' ),
            'type'      => 'number',
            'default'   => 30,
            'id'        => 'delivery_time_interval',
            'desc_tip'  => __( 'Enter delivery time interval in mins', 'wowrestro' ),
            'custom_attributes' => array(
              'min'   => 1,
              'max'   => 60,
              'step'  => 5,
            ),
          ),

          array(
            'title'     => __( 'Minimum Order Amount', 'wowrestro' ),
            'type'      => 'number',
            'default'   => '',
            'css'       => 'width:100px;',
            'id'        => '_wowrestro_min_delivery_order_amount',
            'desc_tip'  => __( 'Enter Minimum order amount for delivery service', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Minimum Order Amount Error', 'wowrestro' ),
            'id'        => '_wowrestro_min_delivery_order_amount_error',
            'type'      => 'textarea',
            'css'       => 'min-width: 50%; height: 75px;',
            'default'   => __( 'Minimum order price for {service_type} is {min_order_amount}', 'wowrestro' ),
            'desc_tip'  => __( 'Error message for minimum order amount. You can use variable {service_type} and {min_order_amount}', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Maximum Order Amount', 'wowrestro' ),
            'type'      => 'number',
            'default'   => '',
            'css'       => 'width:100px;',
            'id'        => '_wowrestro_max_delivery_order_amount',
            'desc_tip'  => __( 'Enter Maximum order amount for delivery service', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Maximum Order Amount Error', 'wowrestro' ),
            'id'        => '_wowrestro_max_delivery_order_amount_error',
            'type'      => 'textarea',
            'css'       => 'min-width: 50%; height: 75px;',
            'default'   => __( 'Maximum order price for {service_type} is {max_order_amount}', 'wowrestro' ),
            'desc_tip'  => __( 'Error message for maximum order amount. You can use variable {service_type} and {max_order_amount}', 'wowrestro' ),
          ),

          array(
            'type'      => 'sectionend',
            'id'        => 'service_options',
          ),
        )
      );
    
    } else {
      
      $settings = apply_filters(
        
        'wowrestro_service_settings',
        
        array(

          array(
            'title'     => __( 'Service Settings', 'wowrestro' ),
            'type'      => 'title',
            'id'        => 'service_options',
          ),

          array(
            'title'     => __( 'Store Open Time', 'wowrestro' ),
            'type'      => 'text',
            'css'       => 'width:100px;',
            'class'     => 'wowrestro_service_time',
            'id'        => '_wowrestro_open_time',
            'default'   => '07:00am',
            'desc_tip'  => __( 'Set store open time.', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Store Close Time', 'wowrestro' ),
            'type'      => 'text',
            'css'       => 'width:100px;',
            'class'     => 'wowrestro_service_time',
            'id'        => '_wowrestro_close_time',
            'default'   => '10:00pm',
            'desc_tip'  => __( 'Set store close time.', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Cooking Time', 'wowrestro' ),
            'id'        => '_wowrestro_food_prepation_time',
            'type'      => 'number',
            'desc_tip'  => __( 'Set WOWRestro item preparation time', 'wowrestro' ),
            'default'   => 0,
            'css'       => 'min-width: 100px;',
            'custom_attributes' => array(
              'min'   => 1,
              'max'   => 60,
              'step'  => 5,
            ),
          ),

          array(
            'title'     => __( 'Store Closed Message', 'wowrestro' ),
            'id'        => '_wowrestro_store_closed_message',
            'default'   => __( 'Sorry, We not available right now.', 'wowrestro' ),
            'type'      => 'textarea',
            'css'       => 'min-width: 50%; height: 75px;',
            'desc_tip'  => __( 'Set message for users to see when your store is closed.', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Default Selected Service', 'wowrestro' ),
            'type'      => 'radio',
            'id'        => '_wowrestro_default_selected_service',
            'options'   => array( 
              'pickup'   => __( 'Pickup', 'wowrestro' ), 
              'delivery' => __( 'Delivery', 'wowrestro' ),
            ),
            'default'   => 'delivery',
            'desc_tip'  => __( 'Set default selected service type.', 'wowrestro' ),
          ),

          array(
            'title'     => __( 'Enable ASAP option', 'wowrestro' ),
            'desc'      => __( 'Check this box if you want to add ASAP option on your time slot.', 'wowrestro' ),
            'id'        => '_wowrestro_enable_asap',
            'default'   => 'yes',
            'type'      => 'checkbox',
          ),

          array(
            'title'     => __( 'ASAP label ', 'wowrestro' ),
            'desc'      => __( 'Add the as soon as possible text to show on service popup', 'wowrestro' ),
            'id'        => '_wowrestro_asap_text',
            'default'   => 'ASAP',
            'type'      => 'text',
          ),

          array(
            'title'     => __( 'Order later label', 'wowrestro' ),
            'desc'      => __( 'Add the later text to show on service popup', 'wowrestro' ),
            'id'        => '_wowrestro_later_text',
            'default'   => 'Later',
            'type'      => 'text',
          ),

          array(
            'title'     => __( 'Service Popup Settings', 'wowrestro' ),
            'type'      => 'radio',
            'id'        => '_wowrestro_service_modal_option',
            'options'   => array( 
              'hide'          => __( 'Hide completely on items page.', 'wowrestro' ), 
              'auto'          => __( 'Set available service type and time.', 'wowrestro' ),
              'auto_modal'    => __( 'Open modal once items page is loaded.', 'wowrestro' ),
              'manual_modal'  => __( 'Open modal when add to cart is clicked.', 'wowrestro' ),
            ),
            'default'   => 'auto',
            'desc_tip'  => __( 'Choose how you want your customers to select the Service Settings.', 'wowrestro' ),
          ),
          
          array(
            'type'      => 'sectionend',
            'id'        => 'service_options',
          ),
        )
      );
    }

    return apply_filters( 'wowrestro_get_settings_' . $this->id, $settings, $current_section );
  }
}
return new WWRO_Settings_Services();