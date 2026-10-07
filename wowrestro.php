<?php
/**
 * Plugin Name:       WowRestro
 * Plugin URI:        https://woorestro.com/
 * Description:       Restaurant ordering and fulfillment operations for WooCommerce.
 * Version:           2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.9
 * WC tested up to: 10.9
 * Author:            WowRestro
 * Author URI:        https://woorestro.com/
 * Text Domain:       wowrestro
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

define( 'WOWRESTRO_VERSION', '2.0' );
define( 'WOWRESTRO_FILE', __FILE__ );
define( 'WOWRESTRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOWRESTRO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility before WooCommerce initializes its feature controller.
 */
function wowrestro_declare_woocommerce_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOWRESTRO_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOWRESTRO_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'wowrestro_declare_woocommerce_compatibility' );

/**
 * Create safe defaults without adding products or changing checkout behavior.
 */
function wowrestro_activate() {
	if ( WowRestro_Former_Name::old_plugin_active() ) {
		return; // WooRestro still owns the old names; wowrestro_boot() asks for it to be deactivated.
	}
	WowRestro_Former_Name::adopt();
	foreach ( array( 'WowRestro_Locations', 'WowRestro_Reservations', 'WowRestro_Dining' ) as $registrar ) {
		if ( class_exists( $registrar ) && method_exists( $registrar, 'register' ) ) {
			call_user_func( array( $registrar, 'register' ) );
		}
	}
	$previous = (string) get_option( 'wowrestro_version', '' );
	if ( $previous && version_compare( $previous, '2.0', '<' ) ) {
		update_option( 'wowrestro_upgrade_from', $previous, false );
	}
	$defaults = WowRestro_Fulfillment::defaults();
	$current  = get_option( 'wowrestro_settings', array() );
	update_option( 'wowrestro_settings', wp_parse_args( is_array( $current ) ? $current : array(), $defaults ), false );
	if ( class_exists( 'WowRestro_Slot_Reservations' ) && method_exists( 'WowRestro_Slot_Reservations', 'install' ) ) {
		WowRestro_Slot_Reservations::install();
	}
	if ( class_exists( 'WowRestro_Order_Statuses' ) && method_exists( 'WowRestro_Order_Statuses', 'install_roles' ) ) {
		WowRestro_Order_Statuses::install_roles();
	}
	if ( class_exists( 'WowRestro_Products' ) && method_exists( 'WowRestro_Products', 'install_attributes' ) ) {
		WowRestro_Products::install_attributes();
	}
	if ( ! $previous && ! WowRestro_Legacy_Migration::has_legacy_data() ) {
		update_option( 'wowrestro_data_version', WowRestro_Legacy_Migration::DATA_VERSION, false );
		update_option( 'wowrestro_onboarding_pending', 'yes', false );
	}
	update_option( 'wowrestro_version', WOWRESTRO_VERSION, false );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wowrestro_activate' );

/**
 * Flush staff/tracking rewrite rules when the plugin is disabled.
 */
function wowrestro_deactivate() {
	wp_clear_scheduled_hook( 'wowrestro_cleanup_slot_reservations' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wowrestro_deactivate' );

require_once WOWRESTRO_PATH . 'includes/class-wowrestro-plugin.php';

/**
 * Start after all extensions load so WooCommerce APIs are available.
 */
function wowrestro_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'WowRestro requires WooCommerce to be installed and active.', 'wowrestro' ) . '</p></div>';
			}
		);
		return;
	}

	if ( WowRestro_Former_Name::old_plugin_active() ) {
		add_action( 'admin_notices', array( 'WowRestro_Former_Name', 'notice' ) );
		return;
	}
	WowRestro_Former_Name::adopt();

	WowRestro_Plugin::instance()->init();
	$installed = (string) get_option( 'wowrestro_version', '' );
	if ( WOWRESTRO_VERSION !== $installed ) {
		if ( $installed && version_compare( $installed, '0.3.0', '<' ) ) {
			update_option( 'wowrestro_upgrade_from', $installed, false );
		}
		if ( method_exists( 'WowRestro_Slot_Reservations', 'install' ) ) {
			WowRestro_Slot_Reservations::install();
		}
		if ( class_exists( 'WowRestro_Order_Statuses' ) && method_exists( 'WowRestro_Order_Statuses', 'install_roles' ) ) {
			WowRestro_Order_Statuses::install_roles();
		}
		if ( method_exists( 'WowRestro_Products', 'install_attributes' ) ) {
			WowRestro_Products::install_attributes();
		}
		update_option( 'wowrestro_flush_rewrite', 'yes', false );
		update_option( 'wowrestro_version', WOWRESTRO_VERSION, false );
	}
}
add_action( 'plugins_loaded', 'wowrestro_boot', 20 );
