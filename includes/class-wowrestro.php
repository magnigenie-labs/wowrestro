<?php
/**
 * WoWRestro
 *
 * @package WoWRestro
 * @since   1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WoWRestro Class.
 *
 * @class WoWRestro
 */
final class WOWRestro {

	/**
	 * WoWRestro version.
	 *
	 * @var string
	 */
	public $version = '1.3';

	/**
	 * The single instance of the class.
	 *
	 * @var WoWRestro
	 * @since 1.0
	 */
	protected static $_instance = null;

	/**
	 * WoWRestro Instance.
	 *
	 * Ensures only one instance of WoWRestro is loaded or can be loaded.
	 *
	 * @since 1.0
	 * @static
	 * @return WoWRestro - instance.
	 */
	public static function instance() {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;

	}

	/**
	 * WoWRestro Constructor.
	 */
	public function __construct() {

		if ( WWRO_Required::is_woocommerce_active() ) {
    		$this->define_constants();
      		$this->includes();
      		$this->init_hooks();
      		do_action( 'wowrestro_loaded' );
    	} else {
    		add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
    	}

	}

	/**
	 * Define WoWRestro Constants.
	 * @since 1.0
	 */
	private function define_constants() {

		$this->define( 'WWRO_ABSPATH', dirname( WWRO_PLUGIN_FILE ) . '/' );
		$this->define( 'WWRO_PLUGIN_BASENAME', plugin_basename( WWRO_PLUGIN_FILE ) );
		$this->define( 'WWRO_PLUGIN_URL', plugin_dir_url( WWRO_PLUGIN_FILE ) );
		$this->define( 'WWRO_VERSION', $this->version );

	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string      $key  Constant name.
	 * @param string|bool $value Constant value.
	 * @since 1.0
	 */
	private function define( $key, $value ) {

		if ( ! defined( $key ) ) {
			define( $key, $value );
		}

	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 1.0
	 */
	private function init_hooks() {

		register_activation_hook( WWRO_PLUGIN_FILE, array( 'WWRO_Install', 'install' ) );
		register_deactivation_hook( WWRO_PLUGIN_FILE, array( 'WWRO_Uninstall', 'deactivate' ) );

		add_action( 'init', array( 'WWRO_Shortcodes', 'init' ) );
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
		add_action( 'after_setup_theme', array( $this, 'include_public_functions' ), 11 );

	}

	/**
	 * Include required core files used in admin and on the public.
	 * @since 1.0
	 */
	public function includes() {

		/**
		 * Core classes.
		 */
		include_once WWRO_ABSPATH . 'includes/wowrestro-core-functions.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-modifiers.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-metaboxes.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-install.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-uninstall.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-ajax.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-shortcodes.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-services.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-license-handler.php';

		if ( is_admin() ) {
			$this->admin_includes();
		}

		if ( ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' ) ) {
			$this->public_includes();
		}

	}

	/**
	 * Include required public files.
	 * @since 1.0
	 */
	public function public_includes() {

		include_once WWRO_ABSPATH . 'includes/class-wowrestro-public.php';
		include_once WWRO_ABSPATH . 'includes/class-wowrestro-public-scripts.php';
		include_once WWRO_ABSPATH . 'includes/wowrestro-public-hooks.php';

	}

	/**
	* Include required admin files.
	* @since 1.0
	*/
	public function admin_includes() {

		include_once WWRO_ABSPATH . 'includes/admin/class-wowrestro-admin.php';

	}

	/**
	 * Include required public functions.
	 * @since 1.0
	 */
	public function include_public_functions() {

		include_once WWRO_ABSPATH . 'includes/wowrestro-public-functions.php';

	}

	/**
	 * Load Localisation files.
	 *
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
	 * @since 1.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain( 'wowrestro', false, dirname( plugin_basename( WWRO_PLUGIN_FILE ) ). '/languages/' );

	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 * @since 1.0
	 */
	public function plugin_url() {

		return untrailingslashit( plugins_url( '/', WWRO_PLUGIN_FILE ) );

	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 * @since 1.0
	 */
	public function plugin_path() {

		return untrailingslashit( plugin_dir_path( WWRO_PLUGIN_FILE ) );

	}

	/**
	 * Get Ajax URL.
	 *
	 * @return string
	 * @since 1.0
	 */
	public function ajax_url() {

		return admin_url( 'admin-ajax.php', 'relative' );

	}

	/**
 	 * Display admin notice
 	 * @since 1.0
  	 */
	public function admin_notices() {

		echo '<div class="error"><p>';
		_e( 'WOWRestro requires <a href="'.admin_url( 'plugin-install.php?s=WooCommerce&tab=search&type=term', 'admin' ).'">WooCommerce</a> to be installed &amp; active!', 'wowrestro' );
		echo '</p></div>';

	}

}
