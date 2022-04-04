<?php
/**
 * Setup menus in WP admin.
 *
 * @package WoWRestro\Admin
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Admin_Menus', false ) ) {
  return new WWRO_Admin_Menus();
}

/**
 * WWRO_Admin_Menus Class.
 */
class WWRO_Admin_Menus {

  /**
   * Hook in tabs.
   */
  public function __construct() {
    
    // Add menus.
    add_action( 'admin_menu', array( $this, 'wowrestro_menu' ), 60 );

    // Handle saving settings earlier than load-{page} hook to avoid race conditions in conditional menus.
    add_action( 'wp_loaded', array( $this, 'save_settings' ) );
  }

  /**
   * Add menu item.
   */
  public function wowrestro_menu() {

    add_menu_page( __( 'WOWRestro', 'wowrestro' ), __( 'WOWRestro', 'wowrestro' ), 'manage_woocommerce', 'wowrestro-settings', array( $this, 'wowrestro_settings_page' ), null, '55.5' );
  }

  /**
   * Init the status page.
   */
  public function wowrestro_settings_page() {
    WWRO_Admin_Settings::output();
  }

  /**
   * Handle saving of settings.
   *
   * @return void
   */
  public function save_settings() {
    global $current_tab, $current_section;

    // We should only save on the settings page.
    if ( ! is_admin() || ! isset( $_GET['page'] ) || 'wowrestro-settings' !== $_GET['page'] ) { 
      return;
    }

    // Include settings pages.
    WWRO_Admin_Settings::get_settings_pages();

    // Get current tab/section.
    $current_tab     = empty( $_GET['tab'] ) ? 'general' : sanitize_title( wp_unslash( $_GET['tab'] ) );
    $current_section = empty( $_REQUEST['section'] ) ? '' : sanitize_title( wp_unslash( $_REQUEST['section'] ) );

    // Save settings if data has been posted.
    if ( '' !== $current_section && apply_filters( "wowrestro_save_settings_{$current_tab}_{$current_section}", ! empty( $_POST['save'] ) ) ) {
      WWRO_Admin_Settings::save();
    } elseif ( '' === $current_section && apply_filters( "wowrestro_save_settings_{$current_tab}", ! empty( $_POST['save'] ) ) ) {
      WWRO_Admin_Settings::save();
    }
  }
}
return new WWRO_Admin_Menus();