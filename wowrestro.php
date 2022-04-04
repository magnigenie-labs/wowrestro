<?php
/**
 * Plugin Name: WOWRestro
 * Description: WOWRestro is an Ordering system for WooCommerce.
 * Version: 1.3
 * Author: MagniGenie
 * Text Domain: wowrestro
 * Domain Path: /languages/
 *
 * WC requires at least: 3.0
 * WC tested up to: 5.4.1
 *
 * @package WoWRestro
 */

defined( 'ABSPATH' ) || exit;

// Define WOORESTRO_PLUGIN_FILE.
if ( ! defined( 'WWRO_PLUGIN_FILE' ) ) {
  define( 'WWRO_PLUGIN_FILE', __FILE__ );
}

// include required file
if ( ! class_exists( 'WWRO_Required' ) ){
  include_once dirname( __FILE__) . '/includes/class-wowrestro-required.php';
}

// Include the main WoWRestro class.
if ( ! class_exists( 'WowRestro', false ) ) {
  include_once dirname( WWRO_PLUGIN_FILE ) . '/includes/class-wowrestro.php';
}

/**
 * Returns the main instance of WoWRestro.
 *
 * @since  1.0
 * @return WoWRestro
 */
function WowRestro() {
  return WowRestro::instance();
}

// Global for backwards compatibility.
$GLOBALS['wowrestro'] = WowRestro();
