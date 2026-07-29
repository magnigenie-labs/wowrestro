<?php
/**
 * Handle public scripts
 *
 * @package wowrestro/Classes
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Frontend scripts class.
 */
class WWRO_Public_Scripts {

  /**
   * Contains an array of script handles registered by WR.
   *
   * @var array
   */
  private static $scripts = array();

  /**
   * Contains an array of script handles registered by WR.
   *
   * @var array
   */
  private static $styles = array();

  /**
   * Contains an array of script handles localized by WR.
   *
   * @var array
   */
  private static $wp_localize_scripts = array();

  /**
   * Hook in methods.
   */
  public static function init() {
    add_action( 'wp_enqueue_scripts', array( __CLASS__, 'load_scripts' ) );
  }

  /**
   * Get styles for the public.
   *
   * @author Magnigenie
   * @since 1.0
   * @return array
   */
  public static function get_styles() {
    return apply_filters(
      'wowrestro_enqueue_styles',
      array(
        'wowrestro-bootstrap'   => array(
          'src'     => self::get_asset_url( 'assets/css/wowrestro-bootstrap.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => false,
        ),
        'wowrestro-base'   => array(
          'src'     => self::get_asset_url( 'assets/css/wowrestro-base.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => false,
        ),
        'jquery-toast'   => array(
          'src'     => self::get_asset_url( 'assets/css/jquery.toast.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => false,
        ),
        'wowrestro-modal'     => array(
          'src'     => self::get_asset_url( 'assets/css/wowrestro-modal.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => false,
        ),
        'wowrestro-icons'     => array(
          'src'     => self::get_asset_url( 'assets/css/wowrestro-icons.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => true,
        ),
        'wowrestro-general'     => array(
          'src'     => self::get_asset_url( 'assets/css/wowrestro-style.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => false,
        ),
        'wowrestro-responsive'  => array(
          'src'     => self::get_asset_url( 'assets/css/wowrestro-responsive.css' ),
          'deps'    => '',
          'version' => WWRO_VERSION,
          'media'   => 'all',
          'has_rtl' => false,
        ),
      )
    );
  
  }

  /**
   * Register all WR scripts.
   *
   * @author Magnigenie
   * @since 1.0
   */
  private static function register_scripts() {

    $register_scripts = array(
      'wowrestro-bootstrap'     => array(
        'src'     => self::get_asset_url( 'assets/js/public/wowrestro-bootstrap.js' ),
        'deps'    => array( 'jquery' ),
        'version' => WWRO_VERSION,
      ),
      'wowrestro-modal'     => array(
        'src'     => self::get_asset_url( 'assets/js/public/wowrestro-modal.js' ),
        'deps'    => array( 'jquery' ),
        'version' => WWRO_VERSION,
      ),
      'wowrestro-sticky'   => array(
        'src'     => self::get_asset_url( 'assets/js/public/wowrestro-sticky.js' ),
        'deps'    => array( 'jquery' ),
        'version' => WWRO_VERSION,
      ),
      'jquery-toast'  => array(
        'src'     => self::get_asset_url( 'assets/js/public/jquery.toast.js' ),
        'deps'    => array( 'jquery' ),
        'version' => WWRO_VERSION,
      ),
      'wowrestro-quantity-changer'  => array(
        'src'     => self::get_asset_url( 'assets/js/public/quantity-changer.js' ),
        'deps'    => array( 'jquery' ),
        'version' => WWRO_VERSION,
      ),
      'wowrestro'           => array(
        'src'     => self::get_asset_url( 'assets/js/public/wowrestro.js' ),
        'deps'    => array( 'jquery' ),
        'version' => WWRO_VERSION,
      ),
    );

    // Check Laze Load Settings
    $register_scripts['wowrestro-lazy-load'] = array(
      'src'     => self::get_asset_url( 'assets/js/public/wowrestro-lozad.js' ),
      'deps'    => array(),
      'version' => WWRO_VERSION,
    );

    foreach ( $register_scripts as $name => $props ) {
      self::register_script( $name, $props['src'], $props['deps'], $props['version'] );
    }
    
  }

  /**
   * Return asset URL.
   *
   * @author Magnigenie
   * @since 1.0
   * @param string $path Assets path.
   * @return string
   */
  private static function get_asset_url( $path ) {
    return apply_filters( 'wowrestro_get_asset_url', plugins_url( $path, WWRO_PLUGIN_FILE ), $path );
  }

  /**
   * Register a script for use.
   *
   * @uses   wp_register_script()
   * @param  string   $handle    Name of the script. Should be unique.
   * @param  string   $path      Full URL of the script, or path of the script relative to the WordPress root directory.
   * @param  string[] $deps      An array of registered script handles this script depends on.
   * @param  string   $version   String specifying script version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
   * @param  boolean  $in_footer Whether to enqueue the script before </body> instead of in the <head>. Default 'false'.
   */
  private static function register_script( $handle, $path, $deps = array( 'jquery' ), $version = WWRO_VERSION, $in_footer = true ) {
    self::$scripts[] = $handle;
    wp_register_script( $handle, $path, $deps, $version, $in_footer );
  }

  /**
   * Register and enqueue a script for use.
   *
   * @uses   wp_enqueue_script()
   * @param  string   $handle    Name of the script. Should be unique.
   * @param  string   $path      Full URL of the script, or path of the script relative to the WordPress root directory.
   * @param  string[] $deps      An array of registered script handles this script depends on.
   * @param  string   $version   String specifying script version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
   * @param  boolean  $in_footer Whether to enqueue the script before </body> instead of in the <head>. Default 'false'.
   */
  private static function enqueue_script( $handle, $path = '', $deps = array( 'jquery' ), $version = WWRO_VERSION, $in_footer = true ) {
    if ( ! in_array( $handle, self::$scripts, true ) && $path ) {
      self::register_script( $handle, $path, $deps, $version, $in_footer );
    }
    wp_enqueue_script( $handle );
  }

  /**
   * Register a style for use.
   *
   * @uses   wp_register_style()
   * @param  string   $handle  Name of the stylesheet. Should be unique.
   * @param  string   $path    Full URL of the stylesheet, or path of the stylesheet relative to the WordPress root directory.
   * @param  string[] $deps    An array of registered stylesheet handles this stylesheet depends on.
   * @param  string   $version String specifying stylesheet version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
   * @param  string   $media   The media for which this stylesheet has been defined. Accepts media types like 'all', 'print' and 'screen', or media queries like '(orientation: portrait)' and '(max-width: 640px)'.
   * @param  boolean  $has_rtl If has RTL version to load too.
   */
  private static function register_style( $handle, $path, $deps = array(), $version = WWRO_VERSION, $media = 'all', $has_rtl = false ) {
    self::$styles[] = $handle;
    wp_register_style( $handle, $path, $deps, $version, $media );

    if ( $has_rtl ) {
      wp_style_add_data( $handle, 'rtl', 'replace' );
    }
  }

  /**
   * Register and enqueue a styles for use.
   *
   * @uses   wp_enqueue_style()
   * @param  string   $handle  Name of the stylesheet. Should be unique.
   * @param  string   $path    Full URL of the stylesheet, or path of the stylesheet relative to the WordPress root directory.
   * @param  string[] $deps    An array of registered stylesheet handles this stylesheet depends on.
   * @param  string   $version String specifying stylesheet version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
   * @param  string   $media   The media for which this stylesheet has been defined. Accepts media types like 'all', 'print' and 'screen', or media queries like '(orientation: portrait)' and '(max-width: 640px)'.
   * @param  boolean  $has_rtl If has RTL version to load too.
   */
  private static function enqueue_style( $handle, $path = '', $deps = array(), $version = WWRO_VERSION, $media = 'all', $has_rtl = false ) {
    if ( ! in_array( $handle, self::$styles, true ) && $path ) {
      self::register_style( $handle, $path, $deps, $version, $media, $has_rtl );
    }
    wp_enqueue_style( $handle );
  }

  /**
   * Register/Enqueue public scripts
   *
   * @author Magnigenie
   * @since 1.0
   */
  public static function load_scripts() {
    
    global $post;

    wp_enqueue_script( 'jquery-ui-core');

    self::register_scripts();

    // CSS Styles.
    $enqueue_styles = self::get_styles();
    if ( $enqueue_styles ) {
      foreach ( $enqueue_styles as $handle => $args ) {
        if ( ! isset( $args['has_rtl'] ) ) {
          $args['has_rtl'] = false;
        }

        self::enqueue_style( $handle, $args['src'], $args['deps'], $args['version'], $args['media'], $args['has_rtl'] );
      }
    }

    self::enqueue_script( 'wowrestro-bootstrap' );
    self::enqueue_script( 'wowrestro' );
    self::enqueue_script( 'wowrestro-modal' );
    self::enqueue_script( 'wowrestro-sticky' );
    self::enqueue_script( 'jquery-toast' );
    self::enqueue_script( 'wowrestro-quantity-changer' );

    // Checking admin settings for lazy loading
    $lazy_loading = get_option( '_wowrestro_enable_lazy_loading', 'yes' );
    if ( 'yes' === $lazy_loading )
      self::enqueue_script( 'wowrestro-lazy-load' );

    $params = array(

      // URLs
      'ajaxurl'               => WOWRestro()->ajax_url(),
      'cart_url'              => wc_get_cart_url(),
      'checkout_url'          => wc_get_checkout_url(),

      // Admin Options  
      'sticky_category_list'  => 'yes',
      'item_title_popup'      => 'no',
      'service_modal_option'  => get_option( '_wowrestro_service_modal_option', 'auto' ),
      
      //Create Nonce
      'product_modal_nonce'   => wp_create_nonce( 'product-modal' ),
      'add_to_cart_nonce'     => wp_create_nonce( 'add-to-cart' ),
      'update_cart_nonce'     => wp_create_nonce( 'update-cart-item' ),
      'empty_cart_nonce'      => wp_create_nonce( 'empty-cart' ),
      'remove_item_nonce'     => wp_create_nonce( 'product-remove-cart' ),

      //Messages
      'cart_success_message'  => esc_html__( 'Item added to cart', 'wowrestro' ),
      'cart_empty_message'    => esc_html__( 'Cart has been cleared', 'wowrestro' ),
      'cart_process_message'  => wwro_cart_processing_message(),
      'add_to_cart_text'      => wwro_modal_add_to_cart_text(),
      'store_closed_message'  => get_option( '_wowrestro_store_closed_message', true ),
      'empty_service_time'    => __( 'Please select a service time', 'wowrestro' ),
      'variation_error'       => __( 'Please select a variation.', 'wowrestro' ),

      // Service Pptions
      'service_type'          => wowrestro_get_session( 'service_type' ),
      'service_time'          => wowrestro_get_session( 'service_time' ),
    );

    wp_localize_script( 'wowrestro', 'wowrestro_script', $params );
  }

  /**
   * Localize public scripts
   *
   * @author Magnigenie
   * @since 1.0
   */
  private static function localize_script( $handle ) {
    if ( ! in_array( $handle, self::$wp_localize_scripts, true ) && wp_script_is( $handle ) ) {
      $data = self::get_script_data( $handle );

      if ( ! $data ) {
        return;
      }

      $name = str_replace( '-', '_', $handle ) . '_params';
      self::$wp_localize_scripts[] = $handle;
      wp_localize_script( $handle, $name, apply_filters( $name, $data ) );
    }
  }
}

WWRO_Public_Scripts::init();