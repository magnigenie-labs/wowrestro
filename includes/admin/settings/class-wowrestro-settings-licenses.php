<?php
/**
 * WoWRestro Licenses Settings
 *
 * @package WoWRestro/Admin
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Settings_Licenses', false ) ) {
  return new WWRO_Settings_Licenses();
}

/**
 * WWRO_Settings_Licenses.
 */
class WWRO_Settings_Licenses extends WWRO_Settings_Page {

  /**
   * Constructor.
   */
  public function __construct() {
    
    $this->id     = 'licenses';
    $this->label  = __( 'Licenses', 'wowrestro' );

    // Render Licenses setting fields
    add_action( 'wowrestro_admin_field_licenses_setting', array( $this, 'wowrestro_admin_field_licenses_html' ), 10, 1 );

    // save store timing settings options
    add_action( 'wowrestro_update_option_licenses_setting', array( $this, 'save_licenses_options' ), 10, 1 );

    parent::__construct();
  }

  /**
   * Save Licenses setting data
   *
   * @since 1.0
   */
  public function save_licenses_options( $options ) {

    if ( $options['type'] == 'licenses_setting' ) {
      $wowrestro_licenses_setting = !empty( $_POST['wowrestro_licenses_setting'] ) ? $_POST['wowrestro_licenses_setting'] : '';

      $addon_license = isset( $wowrestro_licenses_setting['all_access'] ) ? $wowrestro_licenses_setting['all_access'] : '';

      update_option( 'wowrestro_licenses_setting', $wowrestro_licenses_setting );

    }

  }

  /**
   * Include Licenses setting html
   *
   * @since 1.0
   */
  public function wowrestro_admin_field_licenses_html( $value ) {
    
    require_once dirname( WWRO_PLUGIN_FILE ) . '/includes/admin/views/wowrestro-licenses-settings-fields.php';

  }

  /**
   * Get settings array.
   *
   * @return array
   */
  public function get_settings() {

    global $current_section;

    $settings = apply_filters(
      'wowrestro_licenses_settings',
      array(
        array(
          'title' => __( 'Enter your license key here', 'wowrestro' ),
          'type'  => 'title',
          'desc'  => '',
          'id'    => 'licenses_options_start',
        ),
        array(
          'title' => __( 'Licenses', 'wowrestro' ),
          'type'  => 'licenses_setting',
          'id'    => 'wowrestro_licenses',
        ),
        array(
          'type'  => 'sectionend',
          'id'    => 'licenses_options_end',
        ),
      )
    );

    return apply_filters( 'wowrestro_get_settings_' . $this->id, $settings, $current_section );
  }


}

return new WWRO_Settings_Licenses();