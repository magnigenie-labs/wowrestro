<?php
/**
 * WoWRestro Notification Settings
 *
 * @package WoWRestro/Admin
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Settings_Notification', false ) ) {

  return new WWRO_Settings_Notification();

}

/**
 * WWRO_Settings_Notification.
 */
class WWRO_Settings_Notification extends WWRO_Settings_Page {

  /**
   * Constructor.
   */
  public function __construct() {
    
    $this->id     = 'notification';
    $this->label  = __( 'Notification', 'wowrestro' );

    parent::__construct();

  }

  /**
   * Get settings array.
   *
   * @return array
   */
  public function get_settings() {

    $settings = apply_filters(
      'wowrestro_notification_settings',
      array(

        array(
          'title'   => __( 'Notification Options', 'wowrestro' ),
          'type'    => 'title',
          'desc'    => '',
          'id'      => 'notification_options',
        ),

        array(
          'title'   => __( 'Enable Notification', 'wowrestro' ),
          'id'      => '_wowrestro_enable_notification',
          'default' => 'yes',
          'desc'    => __( 'Enable push notification', 'wowrestro' ),
          'option'  => __( 'Enable push notification', 'wowrestro' ),
          'type'    => 'checkbox',
        ),

        array(
          'title'   => __( 'Title', 'wowrestro' ),
          'desc'    => __( 'Enter notification title.', 'wowrestro' ),
          'id'      => '_wowrestro_notification_title',
          'type'    => 'text',
        ),

        array(
          'title'   => __( 'Description', 'wowrestro' ),
          'id'      => '_wowrestro_notification_description',
          'default' => '{order_id} - Order Id, {service_type} - Service Type, {payment_status} - Payment Status',
          'desc'    => 'Enter notification description. Available place holder {order_id} - Order Id, {service_type} - Service Type, {payment_status} - Payment Status',
          'type'    => 'textarea',
        ),

        array(
          'title'     => __( 'Notification sound', 'wowrestro' ),
          'desc'      => __( 'Select mp3 file for the notification sound..', 'wowrestro' ),
          'id'        => '_wowrestro_notification_sound',
          'type'      => 'upload',
        ),

        array(
          'title'     => __( 'Play notification sound in loop', 'wowrestro' ),
          'desc'      => __( 'Enable this if you want the notificaiton sound to not stop until the notification duration.', 'wowrestro' ),
          'id'        => '_wowrestro_notification_sound_loop',
          'type'      => 'checkbox',
          'default'   => 'yes',
          'options'   => __( 'Enable this if you want the notificaiton sound to not stop until the notification duration.', 'wowrestro' ),
        ),

        array(
          'title'     => __( 'Notification Icon', 'wowrestro' ),
          'desc'      => __( 'Select an image to use as the notification icon.', 'wowrestro' ),
          'id'        => '_wowrestro_notification_icon',
          'type'      => 'upload',
        ),

        array(
          'title'     => __( 'Notification Length', 'wowrestro' ),
          'desc'      => __( 'Time in seconds, "0" = Default notification length', 'wowrestro' ),
          'id'        => '_wowrestro_notification_length',
          'type'      => 'text',
        ),

        array(
          'type' => 'sectionend',
          'id'   => 'notification_options',
        ),

      )
    );

    return apply_filters( 'wowrestro_get_settings_' . $this->id, $settings );

  }

  /**
   * Output the settings.
   */
  public function output() {
    
    $settings = $this->get_settings();
    WWRO_Admin_Settings::output_fields( $settings );

  }

  /**
   * Save settings.
   */
  public function save() {
    
    $settings = $this->get_settings();
    WWRO_Admin_Settings::save_fields( $settings );

  }

}

return new WWRO_Settings_Notification();