<?php
/**
 * WoWRestro Misc Settings
 *
 * @package WoWRestro/Admin
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Settings_Misc', false ) ) {
  return new WWRO_Settings_Misc();
}

/**
 * WWRO_Settings_Misc.
 */
class WWRO_Settings_Misc extends WWRO_Settings_Page {

  /**
   * Constructor.
   */
  public function __construct() {
    
    $this->id    = 'misc';
    $this->label = __( 'Misc', 'wowrestro' );

    parent::__construct();
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

    $settings = apply_filters(
      
      'wowrestro_misc_settings',
      
      array(

        array(
          'title'     => __( 'Advanced Settings', 'wowrestro' ),
          'type'      => 'title',
          'id'        => 'misc_options',
        ),

        array(
          'title'     => __( 'Include Other Product Types', 'wowrestro' ),
          'desc'      => __( 'Keep other product options like <i>Grouped</i>, <i>External</i>, <i>Virtual</i> etc.', 'wowrestro' ),
          'id'        => '_wowrestro_adv_keep_other_product_types',
          'default'   => 'no',
          'type'      => 'checkbox',
        ),

        array(
          'title'     => __( 'Purge Settings', 'wowrestro' ),
          'desc'      => __( 'Remove WoWRestro data when plugin is deactivated.', 'wowrestro' ),
          'id'        => '_wowrestro_adv_remove_data_on_uninstall',
          'default'   => 'no',
          'type'      => 'checkbox',
        ),
        
        array(
          'type'      => 'sectionend',
          'id'        => 'misc_options',
        ),
      )
    );

    return apply_filters( 'wowrestro_get_settings_' . $this->id, $settings, $current_section );
  }
}

return new WWRO_Settings_Misc();