<?php
/**
 * Compatibility and configuration checks exposed through Site Health.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Diagnostics {
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	public static function register_tests( $tests ) {
		$tests['direct']['wowrestro_checkout']      = array( 'label' => __( 'WowRestro checkout compatibility', 'wowrestro' ), 'test' => array( __CLASS__, 'checkout_test' ) );
		$tests['direct']['wowrestro_configuration'] = array( 'label' => __( 'WowRestro restaurant configuration', 'wowrestro' ), 'test' => array( __CLASS__, 'configuration_test' ) );
		$tests['direct']['wowrestro_ledger']        = array( 'label' => __( 'WowRestro capacity ledger', 'wowrestro' ), 'test' => array( __CLASS__, 'ledger_test' ) );
		return $tests;
	}

	public static function checkout_test() {
		$blocks = class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' ) && function_exists( 'woocommerce_store_api_register_update_callback' );
		return self::result(
			$blocks ? 'good' : 'critical',
			$blocks ? __( 'Classic and Block checkout adapters are available', 'wowrestro' ) : __( 'WooCommerce must be updated for Checkout Blocks', 'wowrestro' ),
			$blocks ? __( 'WowRestro can synchronize fulfillment through the WooCommerce Store API.', 'wowrestro' ) : __( 'WowRestro requires WooCommerce 8.9 or newer.', 'wowrestro' ),
			'wowrestro_checkout'
		);
	}

	public static function configuration_test() {
		$settings      = WowRestro_Fulfillment::settings();
		$products      = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wowrestro_menu_item',
				'meta_value'     => 'yes',
			)
		);
		$service_ready = 'yes' === $settings['pickup_enabled'] || 'yes' === $settings['delivery_enabled'];
		$menu_ready    = ! empty( $products );
		$ready         = $service_ready && $menu_ready;
		if ( ! $service_ready && ! $menu_ready ) {
			$description = __( 'Enable pickup or delivery and add at least one WowRestro menu item.', 'wowrestro' );
			$action_url  = WowRestro_App::url( '/setup-wizard' );
			$action_text = __( 'Finish WowRestro setup', 'wowrestro' );
		} elseif ( ! $service_ready ) {
			$description = __( 'Enable pickup or delivery. Your menu item setup is already complete.', 'wowrestro' );
			$action_url  = WowRestro_App::url( '/settings?tab=pickup' );
			$action_text = __( 'Configure fulfillment', 'wowrestro' );
		} else {
			$description = __( 'Add at least one WowRestro menu item. Pickup or delivery is already enabled.', 'wowrestro' );
			$action_url  = WowRestro_App::url( '/food-menu/items' );
			$action_text = __( 'Manage menu items', 'wowrestro' );
		}
		return self::result(
			$ready ? 'good' : 'recommended',
			$ready ? __( 'WowRestro has an active service and menu', 'wowrestro' ) : __( 'WowRestro setup needs attention', 'wowrestro' ),
			$ready ? __( 'At least one restaurant product and one fulfillment service are active.', 'wowrestro' ) : $description,
			'wowrestro_configuration',
			'<p><a href="' . esc_url( $action_url ) . '">' . esc_html( $action_text ) . '</a></p>'
		);
	}

	public static function ledger_test() {
		$ready = WowRestro_Slot_Reservations::table_ready();
		return self::result(
			$ready ? 'good' : 'critical',
			$ready ? __( 'Shared capacity ledger is ready', 'wowrestro' ) : __( 'Shared capacity ledger is unavailable', 'wowrestro' ),
			$ready ? __( 'Atomic pickup and delivery reservations can be recorded in InnoDB.', 'wowrestro' ) : __( 'Deactivate and reactivate WowRestro, then confirm that MySQL can create the InnoDB ledger.', 'wowrestro' ),
			'wowrestro_ledger'
		);
	}

	private static function result( $status, $label, $description, $test, $actions = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'WowRestro', 'wowrestro' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}

	public static function debug_information( $info ) {
		$settings = WowRestro_Fulfillment::settings();
		$hpos     = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$info['wowrestro'] = array(
			'label'  => __( 'WowRestro', 'wowrestro' ),
			'fields' => array(
				'version'          => array( 'label' => __( 'Version', 'wowrestro' ), 'value' => WOWRESTRO_VERSION ),
				'schema_version'   => array( 'label' => __( 'Schema version', 'wowrestro' ), 'value' => (string) get_option( 'wowrestro_schema_version', 'not installed' ) ),
				'data_version'     => array( 'label' => __( 'Data version', 'wowrestro' ), 'value' => (string) get_option( 'wowrestro_data_version', 'pending' ) ),
					'ledger'           => array( 'label' => __( 'Capacity ledger', 'wowrestro' ), 'value' => WowRestro_Slot_Reservations::table_ready() ? __( 'Ready (InnoDB)', 'wowrestro' ) : __( 'Unavailable', 'wowrestro' ) ),
				'hpos'             => array( 'label' => __( 'HPOS active', 'wowrestro' ), 'value' => $hpos ? __( 'Yes', 'wowrestro' ) : __( 'No', 'wowrestro' ) ),
				'orders_paused'    => array( 'label' => __( 'Ordering paused', 'wowrestro' ), 'value' => 'yes' === $settings['orders_paused'] ? __( 'Yes', 'wowrestro' ) : __( 'No', 'wowrestro' ) ),
				'wordpress_zone'   => array( 'label' => __( 'Timezone', 'wowrestro' ), 'value' => wp_timezone_string() ),
			),
		);
		return $info;
	}
}
