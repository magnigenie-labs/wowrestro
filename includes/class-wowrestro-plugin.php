<?php
/**
 * Plugin bootstrap.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

require_once WOWRESTRO_PATH . 'includes/class-wowrestro-fulfillment.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-slot-reservations.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-shipping.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-products.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-modifiers.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-cart.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-order-statuses.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-checkout.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-rest-api.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-storefront.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-settings.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-order-board.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-admin.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-app.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-onboarding.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-notifications.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-push-notifications.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-legacy-migration.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-diagnostics.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-privacy.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-locations.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-reservations.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-dining.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-analytics.php';
require_once WOWRESTRO_PATH . 'includes/class-wowrestro-former-name.php';

final class WowRestro_Plugin {
	/** @var WowRestro_Plugin|null */
	private static $instance = null;

	/**
	 * Return the singleton.
	 *
	 * @return WowRestro_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register every product surface.
	 */
	public function init() {
		load_plugin_textdomain( 'wowrestro', false, dirname( plugin_basename( WOWRESTRO_FILE ) ) . '/languages' );

		WowRestro_Products::init();
		WowRestro_Modifiers::init();
		WowRestro_Cart::init();
		WowRestro_Order_Statuses::init();
		WowRestro_Slot_Reservations::init();
		WowRestro_Checkout::init();
		WowRestro_REST_API::init();
		WowRestro_Storefront::init();
		WowRestro_Settings::init();
		WowRestro_Order_Board::init();
		WowRestro_Admin::init();
		WowRestro_App::init();
		WowRestro_Onboarding::init();
		WowRestro_Notifications::init();
		WowRestro_Push_Notifications::init();
		WowRestro_Legacy_Migration::init();
		WowRestro_Diagnostics::init();
		WowRestro_Privacy::init();
		WowRestro_Locations::init();
		WowRestro_Reservations::init();
		WowRestro_Dining::init();
		WowRestro_Analytics::init();
		WowRestro_Former_Name::init();

		add_action( 'admin_init', array( $this, 'add_privacy_policy' ) );
	}

	/**
	 * Supply suggested privacy-policy copy through WordPress core.
	 */
	public function add_privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'WowRestro', 'wowrestro' ),
			wp_kses_post(
				'<p>' . __( 'WowRestro stores fulfillment mode, promised time, delivery instructions and operational status on WooCommerce orders. This information is used to prepare, deliver and communicate the status of an order. WowRestro does not send personal data to an external service unless the store owner separately configures a notification integration.', 'wowrestro' ) . '</p>'
			)
		);
	}
}
