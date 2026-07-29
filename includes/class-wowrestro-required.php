<?php

/**
 * WoWRestro Dependency
 *
 * @package WoWRestro
 * @since 1.0
 */

defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'WWRO_Required' ) ) {

  class WWRO_Required {

    private static $active_plugins;

    public static function init() {
      self::$active_plugins = (array) get_option( 'active_plugins', array() );
      
      if ( is_multisite() ) {
        self::$active_plugins = array_merge( self::$active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
      }
    }

    /**
    * Check woocommerce exist
    * @return Boolean
    */
    public static function woocommerce_active_check() {
      if ( !self::$active_plugins ) {
        self::init();
      }
      
      return in_array( 'woocommerce/woocommerce.php', self::$active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', self::$active_plugins );
    }

    /**
    * Check if woocommerce active
    * @return Boolean
    */
    public static function is_woocommerce_active() {
      return self::woocommerce_active_check();
    }
        
    /**
    * Check wowrestro exist
    * @return Boolean
    */
    public static function wowrestro_active_check() {
    
      if ( !self::$active_plugins ) {
        self::init();
      }

      return in_array( 'wowrestro/wowrestro.php', self::$active_plugins) || array_key_exists('wowrestro/wowrestro.php', self::$active_plugins);
    }

    /**
    * Check if WoWRestro active
    * @return Boolean
    */
    public static function is_wowrestro_active() {
      return self::wowrestro_active_check();
    }
  }
}