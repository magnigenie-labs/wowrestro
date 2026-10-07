<?php
/**
 * Carries a site over from this plugin's former name, WooRestro.
 *
 * WooRestro stored everything under a woorestro prefix: options, meta, post types,
 * roles, the slot table, scheduled hooks and the shortcodes in page content. This
 * renames all of it in place, once, so the menu, orders, settings and staff accounts
 * survive the new name. woo and wow have the same length, so serialized values stay
 * valid. It also keeps the old REST namespace answering for staff apps built on it.
 *
 * Every "woorestro" left in this plugin is deliberate: this file and its test, the Firebase
 * project ids and the Android channel id (they belong to the push service and the installed
 * staff apps), the woorestro.com links, and the readme.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-time carry-over from the WooRestro names, plus the aliases that keep old integrations working.
 */
final class WowRestro_Former_Name {
	const DONE_OPTION = 'wowrestro_former_name_adopted';
	const OLD_PLUGIN  = 'woorestro/woorestro.php';

	/**
	 * Whether WooRestro is still running. It owns the old names and would hook checkout
	 * a second time, so WowRestro waits until it is deactivated.
	 *
	 * @return bool
	 */
	public static function old_plugin_active() {
		return defined( 'WOORESTRO_VERSION' );
	}

	/**
	 * Register the REST alias and the push-credential bridges.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'alias_rest_namespace' ), 1, 3 );
		// Push credentials are deployment config. Keep a wp-config constant or filter written for
		// WooRestro working so a site does not silently stop sending push notifications.
		add_filter( 'wowrestro_firebase_service_account', array( __CLASS__, 'old_service_account' ) );
		add_filter( 'wowrestro_firebase_service_account_path', array( __CLASS__, 'old_service_account_path' ) );
	}

	/**
	 * Fall back to a service account supplied through the old filter.
	 *
	 * @param array|null $credentials Service-account data from the new filter.
	 * @return array|null
	 */
	public static function old_service_account( $credentials ) {
		return is_array( $credentials ) ? $credentials : apply_filters( 'woorestro_firebase_service_account', null );
	}

	/**
	 * Fall back to the old constant and filter for the service-account file path.
	 *
	 * @param string|false $path Service-account file path.
	 * @return string|false
	 */
	public static function old_service_account_path( $path ) {
		if ( ! defined( 'WOWRESTRO_FIREBASE_SERVICE_ACCOUNT' ) && defined( 'WOORESTRO_FIREBASE_SERVICE_ACCOUNT' ) ) {
			$path = WOORESTRO_FIREBASE_SERVICE_ACCOUNT;
		}
		return apply_filters( 'woorestro_firebase_service_account_path', $path );
	}

	/**
	 * Deprecated: answer /woorestro/v1 with the wowrestro/v1 routes. Remove once the staff
	 * apps use the new namespace.
	 *
	 * @param mixed           $result  Pre-dispatch result, passed through.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function alias_rest_namespace( $result, $server, $request ) {
		$route = $request->get_route();
		if ( 0 === strpos( $route, '/woorestro/v1' ) ) {
			$request->set_route( '/wowrestro/v1' . substr( $route, 13 ) );
		}
		return $result;
	}

	/**
	 * Ask an administrator to deactivate WooRestro.
	 */
	public static function notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$url = wp_nonce_url( self_admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( self::OLD_PLUGIN ) ), 'deactivate-plugin_' . self::OLD_PLUGIN );
		printf(
			'<div class="notice notice-warning"><p>%1$s <a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'WowRestro replaces WooRestro and picks up its settings, menu and orders. Deactivate WooRestro to finish the switch; WowRestro stays off until then.', 'wowrestro' ),
			esc_url( $url ),
			esc_html__( 'Deactivate WooRestro', 'wowrestro' )
		);
	}

	/**
	 * Rename the stored WooRestro data to the WowRestro names. Runs once per site, before
	 * anything creates a wowrestro_ option, and is safe to run twice.
	 */
	public static function adopt() {
		global $wpdb;
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}
		$old_table = $wpdb->prefix . 'woorestro_slot_reservations';
		$new_table = $wpdb->prefix . 'wowrestro_slot_reservations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- One-time rename of this plugin's own stored data; table names come from $wpdb.
		$has_options = $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'woorestro\\_%' LIMIT 1" );
		if ( ! $has_options && ! self::table_exists( $old_table ) ) {
			update_option( self::DONE_OPTION, 'fresh', true );
			return;
		}

		$ok  = true;
		$run = static function ( $sql ) use ( $wpdb, &$ok ) {
			if ( false === $wpdb->query( $sql ) ) {
				$ok = false;
			}
		};
		// Replace $from with $to inside $column wherever it appears; $where is appended as is.
		$swap = static function ( $table, $column, $from, $to, $where = '' ) use ( $wpdb, $run ) {
			$run( $wpdb->prepare( "UPDATE {$table} SET {$column} = REPLACE({$column}, %s, %s) WHERE {$column} LIKE %s", $from, $to, '%' . $wpdb->esc_like( $from ) . '%' ) . ' ' . $where );
		};

		// Runtime state is rebuilt on demand; the legacy-detection flag gets a clearer name.
		$run( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woorestro\\_order\\_lock\\_%' OR option_name LIKE 'woorestro\\_idem\\_%' OR option_name LIKE '\\_transient\\_woorestro\\_%' OR option_name LIKE '\\_transient\\_timeout\\_woorestro\\_%'" );
		$run( "UPDATE IGNORE {$wpdb->options} SET option_name = 'wowrestro_legacy_detected' WHERE option_name = 'woorestro_wowrestro_detected'" );
		$run( "UPDATE IGNORE {$wpdb->options} SET option_name = CONCAT('wowrestro_', SUBSTRING(option_name, 11)) WHERE option_name LIKE 'woorestro\\_%'" );
		$swap( $wpdb->options, 'option_value', '/plugins/woorestro/', '/plugins/wowrestro/', "AND option_name LIKE 'wowrestro\\_%'" );

		// Post types, taxonomies and every kind of meta.
		$swap( $wpdb->posts, 'post_type', 'woorestro_', 'wowrestro_' );
		$swap( $wpdb->posts, 'post_name', 'woorestro-wowrestro-term-', 'wowrestro-wowrestro-term-' ); // Add-on groups migrated from WOWRestro 1.x are found again by this slug.
		$swap( $wpdb->term_taxonomy, 'taxonomy', 'woorestro_', 'wowrestro_' );
		foreach ( array( $wpdb->postmeta, $wpdb->usermeta, $wpdb->termmeta, $wpdb->commentmeta, $wpdb->prefix . 'wc_orders_meta', $wpdb->prefix . 'woocommerce_order_itemmeta' ) as $table ) {
			if ( self::table_exists( $table ) ) {
				$swap( $table, 'meta_key', 'woorestro_', 'wowrestro_' );
			}
		}

		// Roles and the capabilities granted to users and roles.
		$roles = $wpdb->prepare( 'AND option_name = %s', $wpdb->prefix . 'user_roles' );
		$swap( $wpdb->options, 'option_value', 'woorestro', 'wowrestro', $roles );
		$swap( $wpdb->options, 'option_value', 'WooRestro', 'WowRestro', $roles );
		$swap( $wpdb->usermeta, 'meta_value', 'woorestro', 'wowrestro', $wpdb->prepare( 'AND meta_key = %s', $wpdb->prefix . 'capabilities' ) );

		// Shortcodes, blocks and Elementor widgets already placed on pages and in widget areas.
		$swap( $wpdb->posts, 'post_content', '[woorestro_', '[wowrestro_' );
		$swap( $wpdb->posts, 'post_content', 'wp:woorestro/', 'wp:wowrestro/' );
		$swap( $wpdb->postmeta, 'meta_value', '"widgetType":"woorestro', '"widgetType":"wowrestro', "AND meta_key = '_elementor_data'" );
		$swap( $wpdb->options, 'option_value', '[woorestro_', '[wowrestro_', "AND option_name LIKE 'widget\\_%'" );
		$swap( $wpdb->options, 'option_value', 'wp:woorestro/', 'wp:wowrestro/', "AND option_name LIKE 'widget\\_%'" );

		// The kitchen slot ledger keeps its rows.
		if ( self::table_exists( $old_table ) && ! self::table_exists( $new_table ) ) {
			$run( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
		}

		// Scheduled events: WP-Cron and Action Scheduler.
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $time => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( array_keys( $hooks ) as $hook ) {
					if ( 0 === strpos( (string) $hook, 'woorestro_' ) ) {
						$cron[ $time ][ 'wowrestro_' . substr( $hook, 10 ) ] = $hooks[ $hook ];
						unset( $cron[ $time ][ $hook ] );
					}
				}
			}
			_set_cron_array( $cron );
		}
		if ( self::table_exists( $wpdb->prefix . 'actionscheduler_actions' ) ) {
			$swap( $wpdb->prefix . 'actionscheduler_actions', 'hook', 'woorestro_', 'wowrestro_' );
			$run( "UPDATE {$wpdb->prefix}actionscheduler_groups SET slug = 'wowrestro' WHERE slug = 'woorestro'" );
		}
		// phpcs:enable

		wp_cache_flush();
		wp_roles()->for_site();
		if ( $ok ) {
			update_option( self::DONE_OPTION, 'adopted', true );
		} else {
			// The next request tries again; every step above is safe to repeat.
			error_log( 'WowRestro: carrying over the WooRestro data stopped on a database error: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Whether a table exists in the current database.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
