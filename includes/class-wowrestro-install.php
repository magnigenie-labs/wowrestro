<?php
/**
 * Installation related functions and actions.
 *
 * @package WoWRestro/Classes
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WWRO_Install Class.
 */
class WWRO_Install {

  /**
   * Hook in tabs.
   */
  public static function init() {
    add_action( 'init', array( __CLASS__, 'install' ), 5 );
    add_filter( 'plugin_action_links_' . WWRO_PLUGIN_BASENAME, array( __CLASS__, 'plugin_action_links' ) );
    add_action( 'wp', array( __CLASS__, 'wowrestro_create_item_page' ) );
  }

  /**
   * Install WR.
   */
  public static function install() {
    if ( ! is_blog_installed() ) {
      return;
    }

    self::create_options();
    do_action( 'wowrestro_installed' );
  }

  public static function wowrestro_create_item_page() {

    if ( is_admin() && 0 === post_exists( "Order Online", '', '', "page" ) ) {
      $post = array(     
       'post_content'   => '[wowrestro]',
       'post_title'     =>'Order Online',
       'post_status'    =>  'publish' ,
       'post_type'      =>  'page'
      );

      wp_insert_post( $post );
    }

  }

  /**
   * Default options.
   *
   * Sets up the default options used on the settings page.
   */
  private static function create_options() {
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
          if ( isset( $value['default'] ) && isset( $value['id'] ) ) {
            $autoload = isset( $value['autoload'] ) ? (bool) $value['autoload'] : true;
            add_option( $value['id'], $value['default'], '', ( $autoload ? 'yes' : 'no' ) );
          }
        }
      }
    }
  }

  /**
   * Show action links on the plugin screen.
   *
   * @param mixed $links Plugin Action links.
   *
   * @return array
   */
  public static function plugin_action_links( $links ) {
    $action_links = array(
      'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=wowrestro-settings' ) ) . '" aria-label="' . esc_attr__( 'View WOWRestro settings', 'wowrestro' ) . '">' . esc_html__( 'Settings', 'wowrestro' ) . '</a>',
    );

    return array_merge( $action_links, $links );
  }
}

WWRO_Install::init();