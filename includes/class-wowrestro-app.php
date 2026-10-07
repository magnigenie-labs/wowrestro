<?php
/**
 * The WowRestro app screen: mounts the approved design over the whole admin page
 * and feeds it WooCommerce data.
 *
 * The design is a single client-rendered surface covering every WowRestro route.
 * Its markup lives in views/app-template.php and is interpreted by
 * assets/js/wowrestro-app.js; this class supplies the record sets so the screens
 * show the store's real menu, orders and settings rather than the design's
 * sample data.
 *
 * Records WooCommerce owns are read and written through WooCommerce CRUD and
 * WordPress taxonomy APIs while staff stay inside the WowRestro workspace.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mounts the WowRestro app screen.
 */
final class WowRestro_App {
	const PAGE                      = 'wowrestro-app';
	const NUTRITION_META            = '_wowrestro_nutrition';
	const LABEL_COLOR_META          = '_wowrestro_label_color';
	const LABEL_ICON_META           = '_wowrestro_label_icon';
	const INLINE_ADDON_GROUP_META   = '_wowrestro_inline_addon_group_id';
	const INLINE_ADDON_PRODUCT_META = '_wowrestro_inline_addon_product_id';
	const QR_OPTION                 = 'wowrestro_qr_codes';

	/**
	 * Register the app screen.
	 *
	 * The menu entry itself is registered by WowRestro_Admin::menu(), which owns
	 * the whole WowRestro menu and makes this the landing screen.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/** Register the authenticated settings endpoint used by the workspace tabs. */
	public static function rest_routes() {
		register_rest_route(
			'wowrestro/v1',
			'/workspace/menu',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'menu_snapshot' ),
				'permission_callback' => function () {
					return current_user_can( 'wowrestro_view_orders' );
				},
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/workspace/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_configuration' ),
				'permission_callback' => function () {
					return current_user_can( WowRestro_Settings::CAPABILITY );
				},
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/workspace/qr-code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_qr_code' ),
				'permission_callback' => function () {
					return current_user_can( WowRestro_Settings::CAPABILITY );
				},
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/workspace/qr-code/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_qr_code' ),
				'permission_callback' => function () {
					return current_user_can( WowRestro_Settings::CAPABILITY );
				},
			)
		);
		foreach ( array( 'reservation', 'location' ) as $record ) {
			$permission = static function () use ( $record ) {
				$capability = 'reservation' === $record ? 'wowrestro_manage_operations' : 'wowrestro_manage_menu';
				return current_user_can( $capability ) || current_user_can( 'manage_woocommerce' );
			};
			register_rest_route(
				'wowrestro/v1',
				'/workspace/' . $record,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_' . $record ),
					'permission_callback' => $permission,
				)
			);
			register_rest_route(
				'wowrestro/v1',
				'/workspace/' . $record . '/(?P<id>[\d]+)',
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_' . $record ),
					'permission_callback' => $permission,
				)
			);
		}
		foreach (
			array(
				'/workspace/product'   => 'save_product',
				'/workspace/category'  => 'save_category',
				'/workspace/modifier'  => 'save_modifier',
				'/workspace/brand'     => 'save_brand',
				'/workspace/label'     => 'save_label',
				'/workspace/nutrition' => 'save_nutrition',
			) as $route => $callback
		) {
			register_rest_route(
				'wowrestro/v1',
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => function () {
						return current_user_can( 'wowrestro_manage_menu' );
					},
				)
			);
		}
	}

	/**
	 * Build a link to a route inside the persistent WowRestro workspace.
	 *
	 * @param string $route Internal workspace route.
	 * @return string
	 */
	public static function url( $route = '/' ) {
		$args = array( 'page' => self::PAGE );
		if ( '/' !== $route ) {
			$args['route'] = $route;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/** Whether the current request is the app screen. */
	private static function is_app_screen() {
		$screen = get_current_screen();
		return $screen && false !== strpos( $screen->id, self::PAGE );
	}

	/** Add the app under the WowRestro menu. */
	public static function menu() {
		add_submenu_page(
			WowRestro_Admin::PAGE,
			__( 'WowRestro Workspace', 'wowrestro' ),
			__( 'Workspace', 'wowrestro' ),
			'wowrestro_view_orders',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Give the app screen the full canvas.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::is_app_screen() ? $classes . ' wowrestro-app-page' : $classes;
	}

	/** Load the app's own stylesheet, fonts and runtime. */
	public static function assets() {
		if ( ! self::is_app_screen() ) {
			return;
		}
		wp_enqueue_style( 'wowrestro-app', WOWRESTRO_URL . 'assets/css/wowrestro-app.css', array(), WOWRESTRO_VERSION );
		wp_enqueue_script( 'wowrestro-qrcode', WOWRESTRO_URL . 'assets/js/lib/qrcode-generator/qrcode.js', array(), '2.0.4', true );
		wp_enqueue_script( 'wowrestro-app', WOWRESTRO_URL . 'assets/js/wowrestro-app.js', array( 'wowrestro-qrcode' ), WOWRESTRO_VERSION, true );
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		wp_localize_script( 'wowrestro-app', 'wowRestroApp', self::settings() );
	}

	/**
	 * Everything the runtime needs: record sets, currency and REST access.
	 *
	 * @return array
	 */
	private static function settings() {
		$can_manage       = current_user_can( 'wowrestro_manage' ) || current_user_can( 'manage_woocommerce' );
		$can_manage_staff = current_user_can( 'list_users' ) && current_user_can( 'promote_users' );
		$route            = isset( $_GET['route'] ) ? sanitize_text_field( wp_unslash( $_GET['route'] ) ) : '/'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only workspace route.
		if ( '/' !== substr( $route, 0, 1 ) ) {
			$route = '/';
		}
		$legacy_modules  = '/modules' === $route;
		$route           = $legacy_modules ? '/settings?tab=food-menu' : $route;
		$requested_group = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only workspace section.
		$requested_group = $legacy_modules && ! $requested_group ? 'menu-checkout' : $requested_group;
		$allowed_groups  = array( 'dashboard', 'food-ordering', 'reservations', 'menu-checkout', 'service-options', 'people-products', 'alerts-insights', 'settings' );
		$initial_group   = in_array( $requested_group, $allowed_groups, true ) ? $requested_group : self::group_for_route( $route );
		if ( ! $can_manage ) {
			$route         = '/live-orders';
			$initial_group = 'alerts-insights';
		}
		$profile = WowRestro_Onboarding::profile();
		return array(
			// The design's sample rows are never shown; an empty collection gets
			// the design's own empty state instead.
			'strict'         => true,
			'restUrl'        => esc_url_raw( rest_url( 'wowrestro/v1' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'currency'       => self::currency(),
			'version'        => WOWRESTRO_VERSION,
			'initialRoute'   => $route,
			'initialGroup'   => $initial_group,
			'canManage'      => $can_manage,
			'canManageStaff' => $can_manage_staff,
			'menuTemplates'  => WowRestro_Storefront::templates(),
			'assets'         => array(
				'qrBackground' => WOWRESTRO_URL . 'assets/images/qr-table-card-food-v1.jpg',
				'mark'         => add_query_arg( 'ver', WOWRESTRO_VERSION, WOWRESTRO_URL . 'assets/images/wowrestro-logo.png' ),
				'brandLogo'    => $profile['logo_url'],
			),
			'links'          => self::links(),
			'data'           => $can_manage ? self::data() : self::operations_data(),
		);
	}

	/**
	 * Resolve the left-rail section for a deep-linked workspace route.
	 *
	 * @param string $route Internal workspace route.
	 * @return string
	 */
	private static function group_for_route( $route ) {
		if ( 0 === strpos( $route, '/food-menu' ) || '/live-orders' === $route || 0 === strpos( $route, '/qr-code' ) ) {
			return 'food-ordering';
		}
		if ( 0 === strpos( $route, '/reservations' ) || in_array( $route, array( '/settings?tab=reservation-rules', '/settings?tab=customization', '/settings?tab=reservation-message', '/settings?tab=payment-settings' ), true ) ) {
			return 'reservations';
		}
		if ( '/staff-roles' === $route ) {
			return 'people-products';
		}
		if ( 0 === strpos( $route, '/settings' ) || in_array( $route, array( '/setup-wizard', '/diagnostics', '/shortcodes' ), true ) ) {
			return 'settings';
		}
		return 'dashboard';
	}

	/**
	 * WooCommerce currency formatting, so the app prints totals the way the rest
	 * of the store does.
	 *
	 * @return array
	 */
	private static function currency() {
		return array(
			'symbol'            => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'position'          => 0 === strpos( (string) get_option( 'woocommerce_currency_pos' ), 'right' ) ? 'right' : 'left',
			'decimals'          => wc_get_price_decimals(),
			'decimalSeparator'  => wc_get_price_decimal_separator(),
			'thousandSeparator' => wc_get_price_thousand_separator(),
		);
	}

	/**
	 * Destinations outside the Food Menu workspace, such as shipping zones.
	 *
	 * @return array
	 */
	private static function links() {
		$menu_page_id = absint( get_option( 'wowrestro_menu_page_id', 0 ) );
		$menu_url     = $menu_page_id ? get_permalink( $menu_page_id ) : home_url( '/' );
		return array(
			'orders'     => self::url( '/live-orders' ),
			'dashboard'  => self::url(),
			'settings'   => admin_url( 'admin.php?page=wowrestro-setup' ),
			'onboarding' => admin_url( 'admin.php?page=' . WowRestro_Onboarding::PAGE ),
			'shipping'   => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
			'users'      => admin_url( 'users.php' ),
			'userNew'    => admin_url( 'user-new.php' ),
			'analytics'  => admin_url( 'admin.php?page=wowrestro-analytics' ),
			'menu'       => $menu_url ? $menu_url : home_url( '/' ),
		);
	}

	/**
	 * The record sets the design renders.
	 *
	 * @return array
	 */
	private static function data() {
		$dashboard = WowRestro_Admin::dashboard_data();
		return array_merge(
			array(
				'siteName'     => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'pro'          => false,
				'statusColors' => self::status_colors(),
				'metrics'      => self::metrics( $dashboard ),
				'orders'       => self::orders( $dashboard['recent_orders'] ),
				'reservations' => self::reservations_data(),
				'locations'    => self::locations_data(),
				'qrcodes'      => self::qr_codes(),
				'automations'  => array(),
				'staffRoles'   => self::staff_roles_data(),
				'config'       => self::configuration(),
				'diagnostics'  => self::diagnostics_data(),
			),
			self::menu_data()
		);
	}

	/** Minimal bootstrap for operational Staff and Waiter accounts. */
	private static function operations_data() {
		return array(
			'siteName'     => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'pro'          => false,
			'statusColors' => self::status_colors(),
		);
	}

	/** Restaurant roles displayed in the workspace, including current membership. */
	private static function staff_roles_data() {
		$counts = count_users();
		$roles  = isset( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
		return array(
			array(
				'key'         => 'wowrestro_manager',
				'name'        => __( 'Manager', 'wowrestro' ),
				'description' => __( 'Full restaurant workspace, menu, operations, payments and settings.', 'wowrestro' ),
				'access'      => __( 'Full access', 'wowrestro' ),
				'members'     => absint( isset( $roles['wowrestro_manager'] ) ? $roles['wowrestro_manager'] : 0 ),
				'icon'        => 'ph ph-crown-simple',
				'color'       => '#7B3BFF',
			),
			array(
				'key'         => 'wowrestro_staff',
				'name'        => __( 'Staff', 'wowrestro' ),
				'description' => __( 'Live Orders access for accepting, preparing, completing and printing orders.', 'wowrestro' ),
				'access'      => __( 'Order operations', 'wowrestro' ),
				'members'     => absint( isset( $roles['wowrestro_staff'] ) ? $roles['wowrestro_staff'] : 0 ),
				'icon'        => 'ph ph-user-gear',
				'color'       => '#2B4BFF',
			),
			array(
				'key'         => 'wowrestro_waiter',
				'name'        => __( 'Waiter', 'wowrestro' ),
				'description' => __( 'Live Orders, phone orders and table-service access without management settings.', 'wowrestro' ),
				'access'      => __( 'Orders and tables', 'wowrestro' ),
				'members'     => absint( isset( $roles['wowrestro_waiter'] ) ? $roles['wowrestro_waiter'] : 0 ),
				'icon'        => 'ph ph-tray',
				'color'       => '#00A63E',
			),
		);
	}

	private static function reservations_data() {
		$groups = array();
		$ids    = get_posts(
			array(
				'post_type'      => WowRestro_Reservations::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_wowrestro_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$date              = get_post_meta( $id, '_wowrestro_date', true );
			$status            = get_post_meta( $id, '_wowrestro_status', true ) ?: 'pending';
			$groups[ $date ][] = array(
				'id'      => $id,
				'invoice' => 'WR-' . $id,
				'name'    => get_post_meta( $id, '_wowrestro_name', true ),
				'email'   => get_post_meta( $id, '_wowrestro_email', true ),
				'phone'   => get_post_meta( $id, '_wowrestro_phone', true ),
				'date'    => $date,
				'time'    => get_post_meta( $id, '_wowrestro_time', true ),
				'endTime' => get_post_meta( $id, '_wowrestro_end_time', true ),
				'guests'  => absint( get_post_meta( $id, '_wowrestro_guests', true ) ),
				'status'  => ucfirst( $status ),
				'location'=> get_post_meta( $id, '_wowrestro_location', true ),
				'notes'   => get_post_meta( $id, '_wowrestro_notes', true ),
				'food'    => 'yes' === get_post_meta( $id, '_wowrestro_food', true ) ? 'Yes' : 'No',
				'payment' => get_post_meta( $id, '_wowrestro_payment', true ) ?: 'N/A',
			);
		}
		$output = array();
		foreach ( $groups as $date => $rows ) {
			$timestamp = strtotime( $date . ' 12:00:00' );
			$output[]  = array(
				'date' => $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $date,
				'rows' => $rows,
			);
		}
		return $output;
	}

	private static function locations_data() {
		$terms = get_terms(
			array(
				'taxonomy'   => WowRestro_Locations::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array(); }
		return array_map(
			function ( $term ) {
				$ordering     = 'no' !== get_term_meta( $term->term_id, 'wowrestro_ordering', true );
				$reservations = 'no' !== get_term_meta( $term->term_id, 'wowrestro_reservations', true );
				$modules      = array_filter( array( $ordering ? __( 'Ordering', 'wowrestro' ) : '', $reservations ? __( 'Reservation', 'wowrestro' ) : '' ) );
				return array(
					'id'               => $term->term_id,
					'name'             => $term->name,
					'address'          => get_term_meta( $term->term_id, 'wowrestro_address', true ) ?: '-',
					'phone'            => get_term_meta( $term->term_id, 'wowrestro_phone', true ) ?: '-',
					'email'            => get_term_meta( $term->term_id, 'wowrestro_email', true ),
					'imageId'          => absint( get_term_meta( $term->term_id, 'wowrestro_image_id', true ) ),
					'imageUrl'         => wp_get_attachment_image_url( absint( get_term_meta( $term->term_id, 'wowrestro_image_id', true ) ), 'medium' ) ?: '',
					'latitude'         => get_term_meta( $term->term_id, 'wowrestro_latitude', true ),
					'longitude'        => get_term_meta( $term->term_id, 'wowrestro_longitude', true ),
					'ordering'         => $ordering,
					'reservations'     => $reservations,
					'overrideSchedule' => 'yes' === get_term_meta( $term->term_id, 'wowrestro_override_schedule', true ),
					'modules'          => $modules ? implode( ', ', $modules ) : '-',
					'status'           => 'draft' === get_term_meta( $term->term_id, 'wowrestro_status', true ) ? 'Draft' : 'Published',
				);
			},
			$terms
		);
	}

	/** Canonical menu records shared by the initial page and live synchronization. */
	private static function menu_data() {
		return array(
			'items'      => self::items(),
			'categories' => self::terms( 'product_cat' ),
			'brands'     => self::brands(),
			'labels'     => self::labels(),
			'allergens'  => self::allergens(),
			'modifiers'  => self::modifier_groups(),
		);
	}

	/** Return a fresh WooCommerce-backed menu snapshot for the workspace. */
	public static function menu_snapshot() {
		$response = rest_ensure_response( self::menu_data() );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/** Site Health-backed checks displayed inside the persistent workspace. */
	private static function diagnostics_data() {
		$tests = array( WowRestro_Diagnostics::checkout_test(), WowRestro_Diagnostics::configuration_test(), WowRestro_Diagnostics::ledger_test() );
		$debug = WowRestro_Diagnostics::debug_information( array() );
		$rows  = array();
		foreach ( $tests as $test ) {
			$rows[] = array(
				'label'       => wp_strip_all_tags( $test['label'] ),
				'description' => wp_strip_all_tags( $test['description'] ),
				'status'      => sanitize_key( $test['status'] ),
			);
		}
		return array(
			'checks'      => $rows,
			'environment' => array_values( $debug['wowrestro']['fields'] ),
		);
	}

	/** Settings and profile values shown by the workspace. */
	private static function configuration() {
		return array(
			'profile'      => WowRestro_Onboarding::profile(),
			'settings'     => WowRestro_Fulfillment::settings(),
			'reservations' => WowRestro_Reservations::settings(),
		);
	}

	/**
	 * Persist supported workspace settings through the canonical sanitizer.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_configuration( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$profile_input = isset( $payload['profile'] ) && is_array( $payload['profile'] ) ? $payload['profile'] : null;
		if ( null !== $profile_input ) {
			$email = isset( $profile_input['email'] ) && is_scalar( $profile_input['email'] ) ? sanitize_email( wp_unslash( (string) $profile_input['email'] ) ) : '';
			$phone = isset( $profile_input['phone'] ) && is_scalar( $profile_input['phone'] ) ? sanitize_text_field( wp_unslash( (string) $profile_input['phone'] ) ) : '';
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'wowrestro_invalid_contact_email', __( 'Enter a valid contact email address.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			if ( ! self::valid_phone( $phone ) ) {
				return new WP_Error( 'wowrestro_invalid_contact_phone', __( 'Enter a valid phone number containing 7 to 15 digits.', 'wowrestro' ), array( 'status' => 400 ) );
			}
		}
		$current = WowRestro_Fulfillment::settings();
		$posted  = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();
		$allowed = array(
			'pickup_enabled',
			'delivery_enabled',
			'asap_enabled',
			'prep_minutes',
			'delivery_lead_minutes',
			'slot_interval',
			'slot_capacity',
			'hold_minutes',
			'preorder_days',
			'date_overrides',
			'menu_template',
			'menu_layout',
			'menu_page_size',
			'dietary_attribute',
			'allergen_attribute',
			'tips_enabled',
			'tip_presets',
			'custom_tips',
			'tips_taxable',
			'tips_tax_class',
			'late_grace_minutes',
			'email_updates',
			'orders_paused',
			'service_hours',
			'shipping_rate_modes',
			'shipping_rate_minimums',
		);
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $posted ) ) {
				$current[ $key ] = $posted[ $key ];
			}
		}
		update_option( 'wowrestro_settings', WowRestro_Settings::sanitize( $current ), false );

		if ( null !== $profile_input ) {
			$profile = WowRestro_Onboarding::profile();
			$input   = $profile_input;
			foreach ( array( 'name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $key ) {
				if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) ) {
					$profile[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
				}
			}
			if ( array_key_exists( 'email', $input ) && is_scalar( $input['email'] ) ) {
				$profile['email'] = sanitize_email( wp_unslash( $input['email'] ) );
			}
			if ( array_key_exists( 'logo_id', $input ) && is_scalar( $input['logo_id'] ) ) {
				$profile['logo_id'] = absint( $input['logo_id'] );
				unset( $profile['logo_url'] );
			}
			WowRestro_Onboarding::save_profile( $profile );
		}
		if ( isset( $payload['reservations'] ) && is_array( $payload['reservations'] ) ) {
			WowRestro_Reservations::save_settings( $payload['reservations'] );
		}

		return rest_ensure_response( self::configuration() );
	}

	/** Accept common international phone formatting while enforcing a useful length. */
	private static function valid_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		return 1 === preg_match( '/^\+?[0-9\s().-]+$/', (string) $phone ) && strlen( $digits ) >= 7 && strlen( $digits ) <= 15;
	}

	/** Return the small, single-location QR registry used by the workspace. */
	private static function qr_codes() {
		$stored = get_option( self::QR_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rows   = array();
		foreach ( $stored as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) || empty( $record['url'] ) ) {
				continue;
			}
			$created   = sanitize_text_field( $record['created_gmt'] ?? '' );
			$image_id  = absint( $record['background_id'] ?? 0 );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
			$rows[]    = array(
				'id'            => absint( $record['id'] ),
				'name'          => sanitize_text_field( $record['name'] ?? '' ),
				'url'           => esc_url_raw( $record['url'] ),
				'size'          => in_array( absint( $record['size'] ?? 256 ), array( 128, 256, 512 ), true ) ? absint( $record['size'] ) : 256,
				'backgroundId'  => $image_id,
				'backgroundUrl' => $image_url ? esc_url_raw( $image_url ) : WOWRESTRO_URL . 'assets/images/qr-table-card-food-v1.jpg',
				'createdGmt'    => $created,
				'created'       => $created ? wp_date( get_option( 'date_format' ), strtotime( $created . ' UTC' ) ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Create a validated QR destination without using a remote QR service.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_qr_code( WP_REST_Request $request ) {
		$input    = (array) $request->get_json_params();
		$name     = sanitize_text_field( $input['name'] ?? '' );
		$url      = esc_url_raw( $input['url'] ?? '', array( 'http', 'https' ) );
		$parts    = $url ? wp_parse_url( $url ) : false;
		$size     = absint( $input['size'] ?? 256 );
		$image_id = absint( $input['backgroundId'] ?? 0 );
		if ( '' === $name ) {
			return new WP_Error( 'wowrestro_qr_name', __( 'Enter a table or QR code name.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'wowrestro_qr_url', __( 'Enter a complete http or https page URL.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $size, array( 128, 256, 512 ), true ) ) {
			$size = 256;
		}
		if ( $image_id && ( ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $image_id ) ) ) {
			return new WP_Error( 'wowrestro_qr_background', __( 'Choose a valid background image from the Media Library.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$stored = get_option( self::QR_OPTION, array() );
		$stored = is_array( $stored ) ? array_values( $stored ) : array();
		$name   = substr( $name, 0, 100 );
		foreach ( $stored as $record ) {
			if ( is_array( $record ) && (string) ( $record['name'] ?? '' ) === $name && (string) ( $record['url'] ?? '' ) === $url && absint( $record['size'] ?? 0 ) === $size && absint( $record['background_id'] ?? 0 ) === $image_id ) {
				foreach ( self::qr_codes() as $row ) {
					if ( absint( $record['id'] ?? 0 ) === $row['id'] ) {
						return rest_ensure_response( $row );
					}
				}
			}
		}
		$ids      = wp_list_pluck( $stored, 'id' );
		$id       = $ids ? max( array_map( 'absint', $ids ) ) + 1 : 1;
		$stored[] = array(
			'id'            => $id,
			'name'          => $name,
			'url'           => $url,
			'size'          => $size,
			'background_id' => $image_id,
			'created_gmt'   => current_time( 'mysql', true ),
		);
		update_option( self::QR_OPTION, $stored, false );
		$rows = self::qr_codes();
		foreach ( $rows as $row ) {
			if ( $id === $row['id'] ) {
				return rest_ensure_response( $row );
			}
		}
		return new WP_Error( 'wowrestro_qr_save', __( 'The QR code could not be saved.', 'wowrestro' ), array( 'status' => 500 ) );
	}

	/**
	 * Delete one QR registry record. Existing printed codes still resolve to their URL.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_qr_code( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$stored = get_option( self::QR_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$next   = array_values(
			array_filter(
				$stored,
				static function ( $record ) use ( $id ) {
					return ! is_array( $record ) || absint( $record['id'] ?? 0 ) !== $id;
				}
			)
		);
		if ( count( $next ) === count( $stored ) ) {
			return new WP_Error( 'wowrestro_qr_missing', __( 'QR code not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		update_option( self::QR_OPTION, $next, false );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/** Save a reservation without leaving the WowRestro workspace. */
	public static function save_reservation( WP_REST_Request $request ) {
		$input = (array) $request->get_json_params();
		$id    = absint( $input['id'] ?? 0 );
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wowrestro_reservation_forbidden', __( 'You cannot edit this reservation.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		$result = WowRestro_Reservations::save_workspace( $id, $input );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'reservations' => self::reservations_data() ) );
	}

	/** Delete a reservation without opening its native CPT editor. */
	public static function delete_reservation( WP_REST_Request $request ) {
		$id   = absint( $request['id'] );
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || WowRestro_Reservations::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'wowrestro_reservation_missing', __( 'Reservation not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wowrestro_reservation_forbidden', __( 'You cannot delete this reservation.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		wp_delete_post( $id, true );
		return rest_ensure_response( array( 'reservations' => self::reservations_data() ) );
	}

	/** Create or update a restaurant location taxonomy term. */
	public static function save_location( WP_REST_Request $request ) {
		$input = (array) $request->get_json_params();
		$id    = absint( $input['id'] ?? 0 );
		$name  = sanitize_text_field( $input['name'] ?? '' );
		$address = sanitize_text_field( $input['address'] ?? '' );
		$email_input = trim( (string) ( $input['email'] ?? '' ) );
		if ( ! $name || ! $address ) {
			return new WP_Error( 'wowrestro_location_required', __( 'Enter a restaurant name and location address.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( '' !== $email_input && ! is_email( $email_input ) ) {
			return new WP_Error( 'wowrestro_location_email', __( 'Enter a valid contact email address.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$custom_coordinates = wc_string_to_bool( $input['customCoordinates'] ?? false );
		$latitude_input  = trim( (string) ( $input['latitude'] ?? '' ) );
		$longitude_input = trim( (string) ( $input['longitude'] ?? '' ) );
		if ( ! $custom_coordinates ) {
			$latitude_input = '';
			$longitude_input = '';
		}
		if ( $custom_coordinates && ( '' === $latitude_input || '' === $longitude_input ) ) {
			return new WP_Error( 'wowrestro_location_coordinates_required', __( 'Enter both latitude and longitude coordinates.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( ( '' !== $latitude_input && ( ! is_numeric( $latitude_input ) || (float) $latitude_input < -90 || (float) $latitude_input > 90 ) ) || ( '' !== $longitude_input && ( ! is_numeric( $longitude_input ) || (float) $longitude_input < -180 || (float) $longitude_input > 180 ) ) ) {
			return new WP_Error( 'wowrestro_location_coordinates', __( 'Enter valid numeric latitude and longitude coordinates.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$latitude  = (float) $latitude_input;
		$longitude = (float) $longitude_input;
		if ( $id && ! term_exists( $id, WowRestro_Locations::TAXONOMY ) ) {
			return new WP_Error( 'wowrestro_location_missing', __( 'Location not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		$result = $id ? wp_update_term( $id, WowRestro_Locations::TAXONOMY, array( 'name' => $name ) ) : wp_insert_term( $name, WowRestro_Locations::TAXONOMY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = absint( $result['term_id'] );
		update_term_meta( $id, 'wowrestro_address', $address );
		update_term_meta( $id, 'wowrestro_phone', sanitize_text_field( $input['phone'] ?? '' ) );
		update_term_meta( $id, 'wowrestro_email', sanitize_email( $email_input ) );
		$image_id = absint( $input['imageId'] ?? 0 );
		update_term_meta( $id, 'wowrestro_image_id', $image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0 );
		update_term_meta( $id, 'wowrestro_latitude', '' === $latitude_input ? '' : (string) $latitude );
		update_term_meta( $id, 'wowrestro_longitude', '' === $longitude_input ? '' : (string) $longitude );
		update_term_meta( $id, 'wowrestro_status', 'Draft' === ( $input['status'] ?? '' ) ? 'draft' : 'publish' );
		update_term_meta( $id, 'wowrestro_ordering', wc_string_to_bool( $input['ordering'] ?? false ) ? 'yes' : 'no' );
		update_term_meta( $id, 'wowrestro_reservations', wc_string_to_bool( $input['reservations'] ?? false ) ? 'yes' : 'no' );
		update_term_meta( $id, 'wowrestro_override_schedule', wc_string_to_bool( $input['overrideSchedule'] ?? false ) ? 'yes' : 'no' );
		return rest_ensure_response( array( 'locations' => self::locations_data() ) );
	}

	/** Delete a restaurant location taxonomy term. */
	public static function delete_location( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! term_exists( $id, WowRestro_Locations::TAXONOMY ) ) {
			return new WP_Error( 'wowrestro_location_missing', __( 'Location not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		$result = wp_delete_term( $id, WowRestro_Locations::TAXONOMY );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'locations' => self::locations_data() ) );
	}

	/**
	 * Create or update a simple or variable WooCommerce restaurant product.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_product( WP_REST_Request $request ) {
		$input            = (array) $request->get_json_params();
		$id               = absint( $input['id'] ?? 0 );
		$type             = 'variable' === sanitize_key( $input['type'] ?? '' ) ? 'variable' : 'simple';
		$product          = $id ? wc_get_product( $id ) : ( 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple() );
		$variation_inputs = (array) ( $input['variations'] ?? array() );
		if ( ! $product instanceof WC_Product || ( $id && ! $product->is_type( array( 'simple', 'variable' ) ) ) ) {
			return new WP_Error( 'wowrestro_product_type', __( 'This product type cannot be edited in the WowRestro workspace.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wowrestro_forbidden', __( 'You cannot edit this product.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		if ( '' === trim( (string) ( $input['name'] ?? '' ) ) ) {
			return new WP_Error( 'wowrestro_product_name', __( 'Enter an item name.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$has_new_variation   = false;
		$new_variation_names = array();
		foreach ( $variation_inputs as $variation_input ) {
			$name = is_array( $variation_input ) && ! absint( $variation_input['id'] ?? 0 ) ? sanitize_text_field( $variation_input['name'] ?? '' ) : '';
			if ( '' !== $name ) {
				$has_new_variation     = true;
				$new_variation_names[] = $name;
			}
		}
		if ( 'variable' === $type && ( ! $id || $product->is_type( 'simple' ) ) && ! $has_new_variation ) {
			return new WP_Error( 'wowrestro_product_variations', __( 'Add at least one variation.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( $id && $product->is_type( 'variable' ) && $has_new_variation ) {
			$variation_attributes = array_filter(
				$product->get_attributes(),
				static function ( $attribute ) {
					return $attribute instanceof WC_Product_Attribute && $attribute->get_variation();
				}
			);
			if ( count( $variation_attributes ) > 1 ) {
				return new WP_Error( 'wowrestro_product_variation_attributes', __( 'Simple variation entry supports products with one variation attribute.', 'wowrestro' ), array( 'status' => 400 ) );
			}
		}
		$converted_to_variable = $id && 'variable' === $type && $product->is_type( 'simple' );
		$converted_to_simple   = $id && 'simple' === $type && $product->is_type( 'variable' );
		try {
			if ( $converted_to_variable || $converted_to_simple ) {
				$converted = wp_set_object_terms( $id, $type, 'product_type' );
				if ( is_wp_error( $converted ) ) {
					throw new Exception( $converted->get_error_message() );
				}
				clean_post_cache( $id );
				wc_delete_product_transients( $id );
				$product = $converted_to_variable ? new WC_Product_Variable( $id ) : new WC_Product_Simple( $id );
			}
			$product->set_name( sanitize_text_field( $input['name'] ?? '' ) );
			$product->set_sku( sanitize_text_field( $input['sku'] ?? '' ) );
			$product->set_short_description( wp_kses_post( $input['description'] ?? '' ) );
			if ( $product->is_type( 'simple' ) ) {
				$regular_price = wc_format_decimal( $input['price'] ?? 0 );
				$sale_price    = '' === (string) ( $input['sale'] ?? '' ) ? '' : wc_format_decimal( $input['sale'] );
				$product->set_regular_price( $regular_price );
				$product->set_sale_price( $sale_price );
				$product->set_price( '' !== $sale_price ? $sale_price : $regular_price );
			}
			$product->set_stock_status( 'Out of stock' === (string) ( $input['stock'] ?? '' ) ? 'outofstock' : 'instock' );
			$product->set_status( 'Draft' === (string) ( $input['status'] ?? '' ) ? 'draft' : 'publish' );
			$image_id = absint( $input['imageId'] ?? 0 );
			if ( $image_id && ( ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $image_id ) ) ) {
				return new WP_Error( 'wowrestro_product_image', __( 'Choose a valid image from the Media Library.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			$product->set_image_id( $image_id );
			$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
			$product->save();
			$category = sanitize_text_field( $input['category'] ?? '' );
			$term     = $category ? get_term_by( 'name', $category, 'product_cat' ) : false;
			$product->set_category_ids( $term instanceof WP_Term ? array( $term->term_id ) : array() );
			$labels           = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $input['labels'] ?? array() ) ) ) );
			$dietary_taxonomy = self::ensure_attribute_taxonomy( WowRestro_Fulfillment::settings()['dietary_attribute'], __( 'Dietary', 'wowrestro' ) );
			self::set_attribute_terms( $product, $dietary_taxonomy, $labels );
			$brand          = sanitize_text_field( $input['brand'] ?? '' );
			$brand_taxonomy = self::brand_taxonomy( '' !== $brand );
			if ( $brand_taxonomy ) {
				if ( 0 === strpos( $brand_taxonomy, 'pa_' ) ) {
					self::set_attribute_terms( $product, $brand_taxonomy, $brand ? array( $brand ) : array() );
				} else {
					wp_set_object_terms( $product->get_id(), $brand ? array( $brand ) : array(), $brand_taxonomy, false );
				}
			}
			$cross_sells = array_values( array_diff( array_filter( array_map( 'absint', (array) ( $input['crossSells'] ?? array() ) ) ), array( $product->get_id() ) ) );
			$cross_sells = array_values( array_filter( $cross_sells, 'wc_get_product' ) );
			$product->set_cross_sell_ids( $cross_sells );
			$current_groups   = WowRestro_Modifiers::assigned_group_ids( $product->get_id() );
			$requested_groups = array_key_exists( 'modifierGroups', $input )
				? array_values( array_filter( array_map( 'absint', (array) $input['modifierGroups'] ) ) )
				: $current_groups;
			if ( array_key_exists( 'quickAddons', $input ) && current_user_can( 'wowrestro_manage_modifiers' ) ) {
				$quick_addons = array();
				foreach ( (array) ( $input['quickAddons'] ?? array() ) as $addon ) {
					if ( ! is_array( $addon ) ) {
						continue;
					}
					$label = sanitize_text_field( $addon['name'] ?? '' );
					if ( '' !== $label ) {
						$quick_addons[] = array(
							'id'      => sanitize_key( $addon['id'] ?? '' ),
							'label'   => $label,
							'price'   => wc_format_decimal( $addon['price'] ?? 0 ),
							'default' => false,
						);
					}
				}
				$inline_group_ids = self::inline_addon_group_ids( $product );
				$requested_groups = array_values( array_diff( $requested_groups, $inline_group_ids ) );
				if ( $quick_addons ) {
					$group_id = self::inline_addon_group_id( $product );
					$post     = array(
						'post_type'   => WowRestro_Modifiers::POST_TYPE,
						/* translators: %s: menu item name. */
						'post_title'  => sprintf( __( '%s Add-ons', 'wowrestro' ), $product->get_name() ),
						'post_status' => 'publish',
					);
					if ( $group_id ) {
						$post['ID'] = $group_id;
					}
					$group_id = wp_insert_post( $post, true );
					if ( is_wp_error( $group_id ) ) {
						return $group_id;
					}
					update_post_meta( $group_id, self::INLINE_ADDON_PRODUCT_META, $product->get_id() );
					$product->update_meta_data( self::INLINE_ADDON_GROUP_META, $group_id );
					WowRestro_Modifiers::save_schema( $group_id, 'multiple', 0, count( $quick_addons ), $quick_addons );
					$requested_groups[] = $group_id;
				} else {
					$product->delete_meta_data( self::INLINE_ADDON_GROUP_META );
				}
			}
			$valid_groups = $requested_groups ? get_posts(
				array(
					'post_type'      => WowRestro_Modifiers::POST_TYPE,
					'post_status'    => array( 'publish', 'draft' ),
					'post__in'       => $requested_groups,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			) : array();
			$product->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array_values( array_map( 'absint', $valid_groups ) ) );
			$product->save();
			if ( $product->is_type( 'variable' ) ) {
				$new_attribute_key    = 'option';
				$new_attribute_values = array();
				if ( ! $id || $converted_to_variable ) {
					$options   = array_values( array_unique( $new_variation_names ) );
					$attribute = new WC_Product_Attribute();
					$attribute->set_name( __( 'Option', 'wowrestro' ) );
					$attribute->set_options( $options );
					$attribute->set_visible( true );
					$attribute->set_variation( true );
					$product->set_attributes( array( $attribute ) );
					$product->save();
					foreach ( $options as $option ) {
						$new_attribute_values[ $option ] = $option;
					}
				} elseif ( $has_new_variation ) {
					$attributes    = $product->get_attributes();
					$attribute_key = '';
					$attribute     = null;
					foreach ( $attributes as $key => $candidate ) {
						if ( $candidate instanceof WC_Product_Attribute && $candidate->get_variation() ) {
							$attribute_key = $key;
							$attribute     = $candidate;
							break;
						}
					}
					if ( ! $attribute ) {
						$attribute = new WC_Product_Attribute();
						$attribute->set_name( __( 'Option', 'wowrestro' ) );
						$attribute->set_visible( true );
						$attribute->set_variation( true );
						$attribute_key = 'option';
					}
					if ( $attribute->is_taxonomy() ) {
						$taxonomy = $attribute->get_name();
						$term_ids = array();
						foreach ( $new_variation_names as $name ) {
							$term = get_term_by( 'name', $name, $taxonomy );
							if ( ! $term ) {
								$created = wp_insert_term( $name, $taxonomy );
								if ( is_wp_error( $created ) ) {
									throw new Exception( $created->get_error_message() );
								}
								$term = get_term( $created['term_id'], $taxonomy );
							}
							$term_ids[]                    = $term->term_id;
							$new_attribute_values[ $name ] = $term->slug;
						}
						$assigned = wp_set_object_terms( $product->get_id(), $term_ids, $taxonomy, true );
						if ( is_wp_error( $assigned ) ) {
							throw new Exception( $assigned->get_error_message() );
						}
						$attribute->set_options( array_values( array_unique( array_merge( $attribute->get_options(), $term_ids ) ) ) );
						$new_attribute_key = $taxonomy;
					} else {
						$attribute->set_options( array_values( array_unique( array_merge( $attribute->get_options(), $new_variation_names ) ) ) );
						$new_attribute_key = sanitize_title( $attribute->get_name() );
						foreach ( $new_variation_names as $name ) {
							$new_attribute_values[ $name ] = $name;
						}
					}
					$attributes[ $attribute_key ] = $attribute;
					$product->set_attributes( $attributes );
					$product->save();
				}
				foreach ( $variation_inputs as $variation_input ) {
					if ( ! is_array( $variation_input ) ) {
						continue;
					}
					$variation_id = absint( $variation_input['id'] ?? 0 );
					$variation    = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
					if ( ! $variation instanceof WC_Product_Variation || ( $variation_id && ( $product->get_id() !== $variation->get_parent_id() || ! current_user_can( 'edit_post', $variation_id ) ) ) ) {
						continue;
					}
					if ( ! $variation_id ) {
						$name = sanitize_text_field( $variation_input['name'] ?? '' );
						if ( '' === $name ) {
							continue;
						}
						$variation->set_parent_id( $product->get_id() );
						$variation->set_attributes( array( $new_attribute_key => $new_attribute_values[ $name ] ?? $name ) );
						$variation->set_status( 'publish' );
					}
					$variation->set_sku( sanitize_text_field( $variation_input['sku'] ?? $variation->get_sku() ) );
					$regular_price = wc_format_decimal( $variation_input['price'] ?? 0 );
					$sale_price    = '' === (string) ( $variation_input['sale'] ?? '' ) ? '' : wc_format_decimal( $variation_input['sale'] );
					$variation->set_regular_price( $regular_price );
					$variation->set_sale_price( $sale_price );
					$variation->set_price( '' !== $sale_price ? $sale_price : $regular_price );
					$variation->set_stock_status( 'Out of stock' === (string) ( $variation_input['stock'] ?? '' ) ? 'outofstock' : 'instock' );
					$variation->save();
				}
				WC_Product_Variable::sync( $product->get_id() );
				$product = wc_get_product( $product->get_id() );
			}
		} catch ( Exception $error ) {
			return new WP_Error( 'wowrestro_product_save', $error->getMessage(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::product_row( $product ) );
	}

	/** Create or update a product brand while remaining in WowRestro. */
	public static function save_brand( WP_REST_Request $request ) {
		$taxonomy = self::brand_taxonomy( true );
		if ( ! $taxonomy ) {
			return new WP_Error( 'wowrestro_brand_taxonomy', __( 'The brand attribute could not be created.', 'wowrestro' ), array( 'status' => 500 ) );
		}
		return self::save_term( (array) $request->get_json_params(), $taxonomy );
	}

	/** Create or update a dietary label and its WowRestro badge presentation. */
	public static function save_label( WP_REST_Request $request ) {
		$input    = (array) $request->get_json_params();
		$name     = sanitize_text_field( $input['name'] ?? '' );
		if ( ! in_array( sanitize_title( $name ), array( 'veg', 'non-veg' ), true ) ) {
			return new WP_Error( 'wowrestro_dietary_flag', __( 'Dietary flags are limited to Veg and Non-Veg.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$input['name'] = 'veg' === sanitize_title( $name ) ? 'Veg' : 'Non-Veg';
		$taxonomy = self::ensure_attribute_taxonomy( WowRestro_Fulfillment::settings()['dietary_attribute'], __( 'Dietary', 'wowrestro' ) );
		$result   = self::save_term( $input, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = $result->get_data();
		update_term_meta( $data['id'], self::LABEL_COLOR_META, sanitize_hex_color( $input['color'] ?? '' ) ?: '#2B4BFF' );
		update_term_meta( $data['id'], self::LABEL_ICON_META, self::sanitize_icon( $input['icon'] ?? '' ) );
		return rest_ensure_response( self::label_row( get_term( $data['id'], $taxonomy ), $taxonomy ) );
	}

	/** Save nutrition fields and allergen terms for one restaurant product. */
	public static function save_nutrition( WP_REST_Request $request ) {
		$input   = (array) $request->get_json_params();
		$id      = absint( $input['id'] ?? 0 );
		$product = $id ? wc_get_product( $id ) : false;
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wowrestro_nutrition_product', __( 'Choose a restaurant menu item you can edit.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		$nutrition = array();
		foreach ( array( 'serving', 'calories', 'protein', 'carbs', 'fat', 'note' ) as $key ) {
			$nutrition[ $key ] = sanitize_textarea_field( $input[ $key ] ?? '' );
		}
		$allowed   = array_combine( array_map( 'sanitize_title', WowRestro_Products::EU14 ), WowRestro_Products::EU14 );
		$allergens = array_values( array_unique( array_filter( array_map( function ( $value ) use ( $allowed ) { return $allowed[ sanitize_title( $value ) ] ?? ''; }, (array) ( $input['allergens'] ?? array() ) ) ) ) );
		$taxonomy  = self::ensure_attribute_taxonomy( WowRestro_Fulfillment::settings()['allergen_attribute'], __( 'Allergens', 'wowrestro' ) );
		if ( ! $taxonomy ) {
			return new WP_Error( 'wowrestro_allergen_taxonomy', __( 'The configured allergen attribute is unavailable.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		try {
			self::set_attribute_terms( $product, $taxonomy, $allergens );
			$product->update_meta_data( self::NUTRITION_META, $nutrition );
			$product->save();
		} catch ( Exception $error ) {
			return new WP_Error( 'wowrestro_nutrition_save', $error->getMessage(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::product_row( $product ) );
	}

	/**
	 * Create or update a WooCommerce product category.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_category( WP_REST_Request $request ) {
		$input = (array) $request->get_json_params();
		$id    = absint( $input['id'] ?? 0 );
		$name  = sanitize_text_field( $input['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'wowrestro_category_name', __( 'Enter a category name.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( $id && ( ! current_user_can( 'edit_term', $id ) || ! term_exists( $id, 'product_cat' ) ) ) {
			return new WP_Error( 'wowrestro_category_forbidden', __( 'You cannot edit this category.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		$parent = sanitize_text_field( $input['parent'] ?? '' );
		$parent = $parent && '-' !== $parent ? get_term_by( 'name', $parent, 'product_cat' ) : false;
		$args   = array(
			'slug'        => sanitize_title( $input['slug'] ?? $name ),
			'description' => sanitize_textarea_field( $input['description'] ?? '' ),
			'parent'      => $parent instanceof WP_Term ? $parent->term_id : 0,
		);
		$result = $id ? wp_update_term( $id, 'product_cat', array_merge( $args, array( 'name' => $name ) ) ) : wp_insert_term( $name, 'product_cat', $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = get_term( absint( $result['term_id'] ), 'product_cat' );
		return rest_ensure_response( self::term_row( $term, 'product_cat' ) );
	}

	/** Save a taxonomy term shared by the internal brand and label editors. */
	private static function save_term( $input, $taxonomy ) {
		$id   = absint( $input['id'] ?? 0 );
		$name = sanitize_text_field( $input['name'] ?? '' );
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) || '' === $name ) {
			return new WP_Error( 'wowrestro_term_name', __( 'Enter a name.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( $id && ( ! current_user_can( 'edit_term', $id ) || ! term_exists( $id, $taxonomy ) ) ) {
			return new WP_Error( 'wowrestro_term_forbidden', __( 'You cannot edit this record.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		$args   = array(
			'slug'        => sanitize_title( $input['slug'] ?? $name ),
			'description' => sanitize_textarea_field( $input['description'] ?? '' ),
		);
		$result = $id ? wp_update_term( $id, $taxonomy, array_merge( $args, array( 'name' => $name ) ) ) : wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::term_row( get_term( absint( $result['term_id'] ), $taxonomy ), $taxonomy ) );
	}

	/** Resolve the site's product-brand taxonomy, creating a native attribute when absent. */
	private static function brand_taxonomy( $create = false ) {
		foreach ( array( 'product_brand', 'pa_brand', 'pwb-brand', 'yith_product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}
		return $create ? self::ensure_attribute_taxonomy( 'pa_brand', __( 'Brand', 'wowrestro' ) ) : '';
	}

	/** Ensure a configured global WooCommerce attribute is ready in this request. */
	private static function ensure_attribute_taxonomy( $taxonomy, $label ) {
		$taxonomy = sanitize_key( $taxonomy );
		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}
		if ( 0 !== strpos( $taxonomy, 'pa_' ) || ! function_exists( 'wc_create_attribute' ) ) {
			return '';
		}
		$slug = substr( $taxonomy, 3 );
		if ( ! wc_attribute_taxonomy_id_by_name( $taxonomy ) ) {
			$created = wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $created ) ) {
				return '';
			}
			delete_transient( 'wc_attribute_taxonomies' );
		}
		register_taxonomy(
			$taxonomy,
			array( 'product' ),
			array(
				'hierarchical' => false,
				'show_ui'      => true,
				'query_var'    => true,
				'rewrite'      => false,
				'labels'       => array( 'name' => $label ),
			)
		);
		return taxonomy_exists( $taxonomy ) ? $taxonomy : '';
	}

	/** Assign global attribute terms and keep the WooCommerce product attribute map in sync. */
	private static function set_attribute_terms( $product, $taxonomy, $names ) {
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$assigned = wp_set_object_terms( $product->get_id(), $names, $taxonomy, false );
		if ( is_wp_error( $assigned ) ) {
			throw new Exception( $assigned->get_error_message() );
		}
		$term_ids   = wp_get_object_terms( $product->get_id(), $taxonomy, array( 'fields' => 'ids' ) );
		$term_ids   = is_wp_error( $term_ids ) ? array() : $term_ids;
		$attributes = $product->get_attributes();
		if ( $term_ids ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( array_map( 'absint', $term_ids ) );
			$attribute->set_position( count( $attributes ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[ $taxonomy ] = $attribute;
		} else {
			unset( $attributes[ $taxonomy ] );
		}
		$product->set_attributes( $attributes );
	}

	/** Keep label icon meta limited to bundled Phosphor class names. */
	private static function sanitize_icon( $icon ) {
		$icon = sanitize_text_field( $icon );
		return preg_match( '/^ph(?:-fill)? ph-[a-z0-9-]+$/', $icon ) ? $icon : 'ph ph-tag';
	}

	/**
	 * Create or update a reusable modifier group.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_modifier( WP_REST_Request $request ) {
		if ( ! current_user_can( 'wowrestro_manage_modifiers' ) ) {
			return new WP_Error( 'wowrestro_forbidden', __( 'You cannot manage add-on groups.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		$input = (array) $request->get_json_params();
		$id    = absint( $input['id'] ?? 0 );
		if ( $id && ( WowRestro_Modifiers::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) ) {
			return new WP_Error( 'wowrestro_modifier_forbidden', __( 'You cannot edit this add-on group.', 'wowrestro' ), array( 'status' => 403 ) );
		}
		if ( '' === trim( (string) ( $input['name'] ?? '' ) ) ) {
			return new WP_Error( 'wowrestro_modifier_name', __( 'Enter an add-on group name.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$post = array(
			'post_type'   => WowRestro_Modifiers::POST_TYPE,
			'post_title'  => sanitize_text_field( $input['name'] ?? '' ),
			'post_status' => 'Draft' === (string) ( $input['status'] ?? '' ) ? 'draft' : 'publish',
		);
		if ( $id ) {
			$post['ID'] = $id;
		}
		$id = wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		WowRestro_Modifiers::save_schema( $id, $input['type'] ?? 'single', $input['min'] ?? 0, $input['max'] ?? 0, $input['options'] ?? '' );
		return rest_ensure_response( self::modifier_group_row( get_post( $id ) ) );
	}

	/**
	 * Status chip colours keyed by the translated label the app renders, so the
	 * design's chips stay meaningful in any locale.
	 *
	 * @return array
	 */
	private static function status_colors() {
		$map    = array(
			'new'              => '#2B4BFF',
			'accepted'         => '#7B3BFF',
			'preparing'        => '#f0b100',
			'ready'            => '#00a63e',
			'out_for_delivery' => '#00B5D8',
			'completed'        => '#00c950',
			'cancelled'        => '#fb2c36',
		);
		$colors = array();
		foreach ( $map as $status => $color ) {
			$colors[ WowRestro_Order_Statuses::label( $status ) ] = $color;
		}
		return $colors;
	}

	/**
	 * Dashboard metric cards, mirroring the native dashboard's figures.
	 *
	 * @param array $data Dashboard data.
	 * @return array
	 */
	private static function metrics( $data ) {
		$revenue = self::change( $data['today_revenue'], $data['yesterday_revenue'] );
		$orders  = self::change( $data['today_orders'], $data['yesterday_orders'] );
		return array(
			self::metric(
				__( 'Revenue today', 'wowrestro' ),
				html_entity_decode( wp_strip_all_tags( wc_price( $data['today_revenue'] ) ), ENT_QUOTES, 'UTF-8' ),
				'ph-fill ph-money-wavy',
				'#2B4BFF',
				$revenue,
				__( 'Paid restaurant orders created today, minus refunds.', 'wowrestro' )
			),
			self::metric(
				__( 'Orders today', 'wowrestro' ),
				(string) $data['today_orders'],
				'ph-fill ph-receipt',
				'#6FA8FF',
				$orders,
				__( 'Restaurant orders created today, paid or not.', 'wowrestro' )
			),
			self::metric(
				__( 'Active orders', 'wowrestro' ),
				(string) count( $data['active_orders'] ),
				'ph-fill ph-fire',
				'#00B5D8',
				null,
				__( 'Orders waiting or in progress in the kitchen queue.', 'wowrestro' )
			),
			self::metric(
				__( 'Menu items', 'wowrestro' ),
				(string) $data['menu_items'],
				'ph-fill ph-fork-knife',
				'#7B3BFF',
				null,
				__( 'WooCommerce products marked as restaurant menu items.', 'wowrestro' )
			),
		);
	}

	/**
	 * Build one metric card payload in the shape the design's markup binds to.
	 *
	 * @param string     $title   Card label.
	 * @param string     $value   Headline figure.
	 * @param string     $icon    Phosphor class.
	 * @param string     $bg      Icon circle colour.
	 * @param float|null $change  Percentage change, or null for no chip.
	 * @param string     $tooltip Explanation.
	 * @return array
	 */
	private static function metric( $title, $value, $icon, $bg, $change, $tooltip ) {
		$has = null !== $change;
		return array(
			'title'        => $title,
			'value'        => $value,
			'hasBreakdown' => false,
			'hasChange'    => $has,
			'change'       => $has ? ( $change > 0 ? '+' : '' ) . number_format_i18n( $change, 1 ) . '%' : '',
			'chipBg'       => $has && $change < 0 ? '#ffe2e2' : '#dcfce7',
			'chipColor'    => $has && $change < 0 ? '#c10007' : '#008236',
			'icon'         => $icon,
			'iconBg'       => $bg,
			'tooltip'      => $tooltip,
		);
	}

	/**
	 * Percentage change, or null when the baseline is zero and no percentage is
	 * defined.
	 *
	 * @param float $current  Current period.
	 * @param float $previous Previous period.
	 * @return float|null
	 */
	private static function change( $current, $previous ) {
		if ( (float) $previous <= 0.0 ) {
			return null;
		}
		return ( (float) $current - (float) $previous ) / (float) $previous * 100;
	}

	/**
	 * Recent restaurant orders in the shape the design's order tables bind to.
	 *
	 * @param WC_Order[] $orders Orders.
	 * @return array
	 */
	private static function orders( $orders ) {
		$rows = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$status = WowRestro_Order_Statuses::current( $order );
			$rows[] = array(
				'id'       => $order->get_order_number(),
				'customer' => trim( $order->get_formatted_billing_full_name() ),
				'type'     => 'delivery' === $order->get_meta( '_wowrestro_fulfillment' ) ? __( 'Delivery', 'wowrestro' ) : __( 'Pickup', 'wowrestro' ),
				'items'    => $order->get_item_count(),
				'total'    => (float) $order->get_total(),
				'time'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ),
				'status'   => WowRestro_Order_Statuses::label( $status ? $status : 'new' ),
				'editUrl'  => $order->get_edit_order_url(),
			);
		}
		return $rows;
	}

	/**
	 * Supported WooCommerce products managed as menu items in the workspace.
	 *
	 * @return array
	 */
	private static function items() {
		$products = wc_get_products(
			array(
				'limit'  => -1,
				'status' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'type'   => array( 'simple', 'variable' ),
			)
		);
		$rows     = array();
		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$rows[] = self::product_row( $product );
		}
		return $rows;
	}

	/**
	 * Normalize one WooCommerce product for the workspace.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function product_row( $product ) {
		$terms          = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$labels         = WowRestro_Products::labels( $product );
		$brand_taxonomy = self::brand_taxonomy();
		$brand_terms    = $brand_taxonomy ? wp_get_post_terms( $product->get_id(), $brand_taxonomy, array( 'fields' => 'names' ) ) : array();
		$nutrition      = $product->get_meta( self::NUTRITION_META, true );
		$nutrition      = is_array( $nutrition ) ? $nutrition : array();
		$is_variable    = $product->is_type( 'variable' );
		$variations     = array();
		if ( $is_variable ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}
				$variations[] = array(
					'id'    => $variation->get_id(),
					'name'  => wc_get_formatted_variation( $variation, true, false, false ) ?: sprintf( __( 'Variation #%d', 'wowrestro' ), $variation->get_id() ),
					'sku'   => $variation->get_sku( 'edit' ),
					'price' => (string) $variation->get_regular_price(),
					'sale'  => (string) $variation->get_sale_price(),
					'stock' => $variation->is_in_stock() ? __( 'In stock', 'wowrestro' ) : __( 'Out of stock', 'wowrestro' ),
				);
			}
		}
		return array(
			'id'             => $product->get_id(),
			'name'           => html_entity_decode( $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'description'    => wp_strip_all_tags( $product->get_short_description() ),
			'category'       => is_wp_error( $terms ) || ! $terms ? '' : $terms[0],
			'brand'          => is_wp_error( $brand_terms ) || ! $brand_terms ? '' : $brand_terms[0],
			'price'          => (float) ( $is_variable ? $product->get_variation_regular_price( 'min' ) : $product->get_regular_price() ),
			'sale'           => (float) ( $is_variable ? $product->get_variation_sale_price( 'min' ) : $product->get_sale_price() ),
			'sku'            => $product->get_sku(),
			'stock'          => $product->is_in_stock() ? __( 'In stock', 'wowrestro' ) : __( 'Out of stock', 'wowrestro' ),
			'labels'         => $labels['dietary'],
			'allergens'      => $labels['allergens'],
			'status'         => 'publish' === $product->get_status() ? __( 'Published', 'wowrestro' ) : __( 'Draft', 'wowrestro' ),
			'sold'           => (int) $product->get_total_sales(),
			'nutrition'      => ! empty( array_filter( $nutrition ) ) || ! empty( $labels['dietary'] ) || ! empty( $labels['allergens'] ),
			'serving'        => sanitize_text_field( $nutrition['serving'] ?? '' ),
			'calories'       => sanitize_text_field( $nutrition['calories'] ?? '' ),
			'protein'        => sanitize_text_field( $nutrition['protein'] ?? '' ),
			'carbs'          => sanitize_text_field( $nutrition['carbs'] ?? '' ),
			'fat'            => sanitize_text_field( $nutrition['fat'] ?? '' ),
			'dietNote'       => sanitize_textarea_field( $nutrition['note'] ?? '' ),
			'imageId'        => $product->get_image_id(),
			'imageUrl'       => $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) : '',
			'type'           => $is_variable ? 'variable' : 'simple',
			'variations'     => $variations,
			'crossSells'     => array_values( array_map( 'absint', $product->get_cross_sell_ids() ) ),
			'icon'           => 'ph ph-bowl-food',
			'color'          => '#2B4BFF',
			'editable'       => true,
			'modifierGroups' => WowRestro_Modifiers::assigned_group_ids( $product->get_id() ),
			'quickAddons'    => self::inline_addons( $product ),
		);
	}

	/**
	 * Find automatically managed inline add-on groups assigned to one product.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int,int>
	 */
	private static function inline_addon_group_ids( $product ) {
		$assigned = WowRestro_Modifiers::assigned_group_ids( $product->get_id() );
		$stored   = absint( $product->get_meta( self::INLINE_ADDON_GROUP_META, true ) );
		/* translators: %s: menu item name. */
		$title    = sprintf( __( '%s Add-ons', 'wowrestro' ), $product->get_name() );
		$matches  = array();
		foreach ( $assigned as $group_id ) {
			$marked = absint( get_post_meta( $group_id, self::INLINE_ADDON_PRODUCT_META, true ) ) === $product->get_id();
			if ( $stored === $group_id || $marked || $title === get_the_title( $group_id ) ) {
				$matches[] = absint( $group_id );
			}
		}
		return array_values( array_unique( $matches ) );
	}

	/**
	 * Return the reusable internal group used by the item's inline add-on editor.
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private static function inline_addon_group_id( $product ) {
		$stored = absint( $product->get_meta( self::INLINE_ADDON_GROUP_META, true ) );
		if ( $stored && WowRestro_Modifiers::POST_TYPE === get_post_type( $stored ) ) {
			return $stored;
		}
		$matches = self::inline_addon_group_ids( $product );
		return $matches ? (int) end( $matches ) : 0;
	}

	/**
	 * Hydrate inline add-on names and prices for both create and edit forms.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int,array{id:string,name:string,price:string}>
	 */
	private static function inline_addons( $product ) {
		$group_id = self::inline_addon_group_id( $product );
		if ( ! $group_id || ! in_array( $group_id, WowRestro_Modifiers::assigned_group_ids( $product->get_id() ), true ) ) {
			return array();
		}
		$group = WowRestro_Modifiers::group( $group_id );
		if ( ! $group ) {
			return array();
		}
		return array_values(
			array_map(
				static function ( $option ) {
					return array(
						'id'    => sanitize_key( $option['id'] ?? '' ),
						'name'  => sanitize_text_field( $option['label'] ?? '' ),
						'price' => (string) wc_format_decimal( $option['price'] ?? 0 ),
					);
				},
				(array) ( $group['options'] ?? array() )
			)
		);
	}

	/**
	 * Product terms in the design's category shape.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	private static function terms( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		$rows  = array();
		if ( is_wp_error( $terms ) ) {
			return $rows;
		}
		foreach ( $terms as $term ) {
			$rows[] = self::term_row( $term, $taxonomy );
		}
		return $rows;
	}

	/**
	 * Normalize one product term for the workspace.
	 *
	 * @param WP_Term $term Term.
	 * @param string  $taxonomy Taxonomy.
	 * @return array
	 */
	private static function term_row( $term, $taxonomy ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'parent'      => $term->parent ? get_term_field( 'name', $term->parent, $taxonomy ) : '',
			'products'    => (int) $term->count,
			'description' => wp_strip_all_tags( $term->description ),
			'editUrl'     => get_edit_term_link( $term->term_id, $taxonomy ),
		);
	}

	/**
	 * Brands from the site's native or global WooCommerce brand taxonomy.
	 *
	 * @return array
	 */
	private static function brands() {
		$taxonomy = self::brand_taxonomy();
		return $taxonomy ? self::terms( $taxonomy ) : array();
	}

	/** Product labels from the configured WooCommerce dietary attribute. */
	private static function labels() {
		$taxonomy = WowRestro_Fulfillment::settings()['dietary_attribute'];
		$rows     = taxonomy_exists( $taxonomy ) ? self::terms( $taxonomy ) : array();
		foreach ( $rows as $index => $row ) {
			$rows[ $index ] = self::label_row( get_term( $row['id'], $taxonomy ), $taxonomy );
		}
		return $rows;
	}

	/** Allergen terms available to the product nutrition editor. */
	private static function allergens() {
		$taxonomy = WowRestro_Fulfillment::settings()['allergen_attribute'];
		return taxonomy_exists( $taxonomy ) ? self::terms( $taxonomy ) : array();
	}

	/** Add persisted presentation metadata to a dietary term row. */
	private static function label_row( $term, $taxonomy ) {
		$row          = self::term_row( $term, $taxonomy );
		$row['color'] = sanitize_hex_color( get_term_meta( $term->term_id, self::LABEL_COLOR_META, true ) ) ?: '#2B4BFF';
		$row['icon']  = self::sanitize_icon( get_term_meta( $term->term_id, self::LABEL_ICON_META, true ) );
		return $row;
	}

	/** Reusable add-on groups exposed in the WowRestro workspace. */
	private static function modifier_groups() {
		$posts = get_posts(
			array(
				'post_type'      => WowRestro_Modifiers::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Private post type with a deliberately small record set.
					array(
						'key'     => self::INLINE_ADDON_PRODUCT_META,
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		$rows  = array();
		foreach ( $posts as $post ) {
			$rows[] = self::modifier_group_row( $post );
		}
		return $rows;
	}

	/**
	 * Normalize one modifier group for the workspace.
	 *
	 * @param WP_Post $post Modifier group.
	 * @return array
	 */
	private static function modifier_group_row( $post ) {
		$options = WowRestro_Modifiers::sanitize_options( get_post_meta( $post->ID, WowRestro_Modifiers::OPTIONS_META, true ) );
		$type    = 'multiple' === get_post_meta( $post->ID, WowRestro_Modifiers::TYPE_META, true ) ? 'multiple' : 'single';
		$rows    = array_map(
			static function ( $option ) {
				return array(
					'id'      => sanitize_key( $option['id'] ?? '' ),
					'label'   => sanitize_text_field( $option['label'] ?? '' ),
					'price'   => wc_format_decimal( $option['price'] ?? 0 ),
					'default' => ! empty( $option['default'] ),
				);
			},
			$options
		);
		return array(
			'id'          => $post->ID,
			'name'        => get_the_title( $post ),
			'type'        => 'multiple' === $type ? __( 'Multiple items', 'wowrestro' ) : __( 'Single item', 'wowrestro' ),
			'typeKey'     => $type,
			'min'         => absint( get_post_meta( $post->ID, WowRestro_Modifiers::MIN_META, true ) ),
			'max'         => absint( get_post_meta( $post->ID, WowRestro_Modifiers::MAX_META, true ) ),
			'options'     => count( $options ),
			'optionRows'  => $rows,
			'optionsText' => WowRestro_Modifiers::options_text( $options ),
			'status'      => 'publish' === $post->post_status ? __( 'Published', 'wowrestro' ) : __( 'Draft', 'wowrestro' ),
			'editUrl'     => get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/** Render the mount point and the design's template. */
	public static function page() {
		if ( ! current_user_can( 'wowrestro_view_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to view WowRestro.', 'wowrestro' ) );
		}
		echo '<div id="wowrestro-app"></div>';
		echo '<template id="wowrestro-app-template">';
		require WOWRESTRO_PATH . 'includes/views/app-template.php';
		echo '</template>';
	}
}
