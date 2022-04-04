<?php
/**
 * Deactivation related functions and actions.
 *
 * @package WoWRestro/Classes
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WWRO_Uninstall Class.
 */
class WWRO_Uninstall {

  /**
   * Deactivated WR.
   */
  public static function deactivate() {

    // Fire the remove action if selected in settings
    $should_remove = get_option( '_wowrestro_adv_remove_data_on_uninstall' );
    if( !empty( $should_remove ) && $should_remove == 'yes' )
      self::remove_options();
  }

  /**
   * Default options.
   *
   * Removes the default options used on the settings page.
   */
  private static function remove_options() {
    
    // Include settings so that we can run through defaults.
    include_once dirname( __FILE__ ) . '/admin/class-wowrestro-admin-settings.php';

    $settings = WWRO_Admin_Settings::get_settings_pages();

    foreach ( $settings as $section ) {
      if ( ! method_exists( $section, 'get_settings' ) ) {
        continue;
      }
      $subsections = array_unique( array_merge( array( '' ), array_keys( $section->get_sections() ) ) );

      foreach ( $subsections as $subsection ) {
        foreach ( $section->get_settings( $subsection ) as $value ) {
          if ( isset( $value['id'] ) ) {
            delete_option( $value['id']);
          }
        }
      }
    }
  }
}