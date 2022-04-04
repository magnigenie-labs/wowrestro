<?php
/**
 * Load assets
 *
 * @package WoWRestro/Admin
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'WWRO_Admin_Assets', false ) ) :

  /**
   * WWRO_Admin_Assets Class.
   */
  class WWRO_Admin_Assets {

    /**
     * Hook in tabs.
     */
    public function __construct() {
      add_filter( 'woocommerce_screen_ids', array( $this, 'woocommerce_screen') );
      add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
      add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
    }

    /**
     * Add settings page Screen ID to WooCommerce screen IDs
     */
    public function woocommerce_screen( $screens ) {
      $screens[] = 'toplevel_page_wowrestro-settings';
      return apply_filters( 'admin_setting_screens', $screens );
    }

    /**
     * Enqueue styles.
     */
    public function admin_styles() {
      
      global $wp_scripts;

      $screen    = get_current_screen();
      $screen_id = $screen ? $screen->id : '';

      $version = defined( 'WWRO_VERSION' ) ? WWRO_VERSION : '1.0';

      // Register admin styles.
      wp_register_style( 'wowrestro_admin_menu_styles', WWRO_PLUGIN_URL . 'assets/css/menu.css', array(), $version );
      wp_register_style( 'jquery_timepicker', WWRO_PLUGIN_URL . 'assets/css/jquery.timepicker.css', array(), $version );
      wp_register_style( 'wowrestro_admin_styles', WWRO_PLUGIN_URL . 'assets/css/admin.css', array(), $version );

      // Sitewide menu CSS.
      wp_enqueue_style( 'wp-color-picker' );
      wp_enqueue_style( 'jquery_timepicker' );
      wp_enqueue_style( 'wowrestro_admin_menu_styles' );
      wp_enqueue_style( 'wowrestro_admin_styles' );
    }

    /**
     * Enqueue scripts.
     */
    public function admin_scripts() {
      
      global $wp_query, $post;

      $version = defined( 'WWRO_VERSION' ) ? WWRO_VERSION : '1.0';

      $screen       = get_current_screen();
      $post_type    = $screen->post_type;
      $screen_id    = $screen ? $screen->id : '';
      $wc_screen_id = sanitize_title( __( 'wowrestro', 'wowrestro' ) );

      wp_register_script( 'jquery-timepicker', WWRO_PLUGIN_URL . 'assets/js/admin/jquery.timepicker.js', array( 'jquery' ), '1.11.14', true );
      wp_register_script( 'jquery-tiptip', WWRO_PLUGIN_URL . 'assets/js/jquery-tiptip/jquery.tipTip.js', array( 'jquery' ), '1.0.0', true );
      wp_register_script( 'jquery-toast', WWRO_PLUGIN_URL . 'assets/js/public/jquery.toast.js', array( 'jquery' ), WWRO_VERSION, true );
      wp_register_script( 'wowrestro-admin', WWRO_PLUGIN_URL . 'assets/js/admin/wowrestro-admin.js', array( 'jquery', 'jquery-blockui', 'jquery-ui-sortable', 'jquery-ui-widget', 'jquery-ui-core', 'jquery-tiptip', 'jquery-timepicker' ), $version );

      if( $screen_id == 'toplevel_page_wowrestro-settings' || $post_type == 'product' ) {
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_script( 'jquery-timepicker' );
        wp_enqueue_script( 'jquery-tiptip' );
        wp_enqueue_script( 'jquery-toast' );
        wp_enqueue_script( 'wowrestro-admin' );
        wp_localize_script( 'wowrestro-admin', 'wwro_ajax', array( 
          'is_admin' => is_admin(),
          'ajaxurl' => admin_url( 'admin-ajax.php' ) ,
          'notification_duration' => get_option( '_wowrestro_notification_length' ),
          'enable_order_notification' => get_option( '_wowrestro_enable_notification' ),
          'loopsound' => get_option( '_wowrestro_notification_sound_loop' ),
        ) );
      }
    }
  }

endif;

return new WWRO_Admin_Assets();