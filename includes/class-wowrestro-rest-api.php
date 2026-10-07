<?php
/**
 * Public ordering and authenticated restaurant-operation REST contracts.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_REST_API {
	const PHONE_PAYMENT_TTL          = 1800;
	const PHONE_IDEMPOTENCY_LOCK_TTL = 900;
	const LEGACY_SEQUENCE_COOKIE     = 'wowrestro_manual_sequence';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'prime_legacy_manual_sequence' ) );
		add_action( 'wowrestro_expire_phone_order', array( __CLASS__, 'expire_phone_order' ) );
		add_action( 'wowrestro_finalize_phone_order', array( __CLASS__, 'finalize_phone_order_event' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'wowrestro/v1',
			'/menu',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'menu' ),
				'permission_callback' => '__return_true',
				'args'                => self::catalog_args(),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'mode'             => array(
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'pickup',
					),
					'requested_at'     => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'postcode'         => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'shipping_rate_id' => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'date'             => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/tracking/(?P<token>[A-Za-z0-9_-]{24,80})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'tracking_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wowrestro/v1',
			'/operations/session',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'session' ),
				'permission_callback' => array( __CLASS__, 'can_login_app' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/catalog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'catalog' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => self::catalog_args(),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'operations_quote' ),
				'permission_callback' => array( __CLASS__, 'can_create' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'orders' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'phone_order' ),
					'permission_callback' => array( __CLASS__, 'can_create' ),
				),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'order_detail' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/orders/(?P<id>\d+)/transition',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'transition' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/orders/(?P<id>\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cancel_order' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/orders/(?P<id>\d+)/reschedule',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reschedule_order' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/orders/(?P<id>\d+)/payment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'record_payment' ),
				'permission_callback' => array( __CLASS__, 'can_payments' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/pause',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'operations_status' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_operations_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		// 0.2 adapters retained for the existing order board and tracking links.
		register_rest_route(
			'wowrestro/v1',
			'/operations/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'queue' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/operations/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'operations_status' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_operations_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/orders/(?P<id>\d+)/transition',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'legacy_transition' ),
				'permission_callback' => array( __CLASS__, 'can_operate' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/orders/manual',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'manual_order' ),
				'permission_callback' => array( __CLASS__, 'can_create' ),
			)
		);
		register_rest_route(
			'wowrestro/v1',
			'/tracking/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'tracking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key' => array(
						'required'          => true,
						'sanitize_callback' => 'wc_clean',
					),
				),
			)
		);
	}

	private static function catalog_args() {
		return array(
			'page'     => array(
				'sanitize_callback' => 'absint',
				'default'           => 1,
			),
			'per_page' => array(
				'sanitize_callback' => 'absint',
				'default'           => 30,
			),
			'search'   => array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			),
			'category' => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_category_param' ),
				'default'           => '',
			),
			'location' => array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_category_param' ),
				'default'           => '',
			),
		);
	}

	/**
	 * Sanitize a category without passing REST's request object to
	 * sanitize_title() as its optional fallback title.
	 *
	 * @param mixed $value Requested category slug.
	 * @return string
	 */
	public static function sanitize_category_param( $value ) {
		return is_scalar( $value ) ? sanitize_title( (string) $value ) : '';
	}

	public static function can_view() {
		return current_user_can( 'wowrestro_view_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Restrict mobile app sessions to WordPress administrators.
	 *
	 * @return bool
	 */
	public static function can_login_app() {
		return current_user_can( 'manage_options' );
	}

	public static function can_operate() {
		return current_user_can( 'wowrestro_operate_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function can_manage() {
		return current_user_can( 'wowrestro_manage_operations' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function can_create() {
		return current_user_can( 'wowrestro_create_phone_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function can_payments() {
		return current_user_can( 'wowrestro_manage_payments' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function session() {
		$user = wp_get_current_user();
		return self::private_response(
			array(
				'id'           => $user->ID,
				'name'         => $user->display_name,
				'restaurant'   => WowRestro_Onboarding::profile(),
				'can_operate'  => self::can_operate(),
				'can_manage'   => self::can_manage(),
				'can_create'   => self::can_create(),
				'can_payments' => self::can_payments(),
			)
		);
	}

	public static function menu( $request ) {
		return self::public_response( self::catalog_data( $request, false ) );
	}

	public static function catalog( $request ) {
		return self::private_response( self::catalog_data( $request, true ) );
	}

	private static function catalog_data( $request, $operations ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( $operations ? 100 : 60, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$args     = array(
			'status'   => 'publish',
			'type'     => array( 'simple', 'variable' ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);
		if ( ! $operations ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The public menu must exclude products explicitly removed from WowRestro.
				'relation' => 'OR',
				array(
					'key'   => WowRestro_Products::MENU_ITEM_META,
					'value' => 'yes',
				),
				array(
					'key'     => WowRestro_Products::MENU_ITEM_META,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( $search ) {
			$args['search'] = '*' . $search . '*';
		}
		$category = sanitize_title( (string) $request->get_param( 'category' ) );
		if ( $category ) {
			$args['category'] = array( $category );
		}
		$location       = sanitize_title( (string) $request->get_param( 'location' ) );
		$empty_location = false;
		if ( $location && class_exists( 'WowRestro_Locations' ) ) {
			$term            = get_term_by( 'slug', $location, WowRestro_Locations::TAXONOMY );
			$location_ids    = $term ? get_objects_in_term( $term->term_id, WowRestro_Locations::TAXONOMY ) : array();
			$empty_location  = is_wp_error( $location_ids ) || ! $location_ids;
			if ( ! $empty_location ) {
				$args['include'] = array_map( 'absint', $location_ids );
			}
		}
		$result   = $empty_location ? array() : wc_get_products( $args );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : (array) $result;
		$data     = array();
		foreach ( $products as $product ) {
			if ( ! $operations && ! self::is_menu_item( $product ) ) {
				continue;
			}
			$data[] = self::prepare_product( $product, $operations );
		}
		return array(
			'products'    => $data,
			'page'        => $page,
			'total'       => is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : count( $data ),
			'total_pages' => is_object( $result ) && isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : 1,
		);
	}

	private static function prepare_product( $product, $operations ) {
		$labels = class_exists( 'WowRestro_Products' ) ? WowRestro_Products::labels( $product ) : array(
			'dietary'   => array(),
			'allergens' => array(),
		);
		$locations = class_exists( 'WowRestro_Locations' ) ? wp_get_post_terms( $product->get_id(), WowRestro_Locations::TAXONOMY, array( 'fields' => 'slugs' ) ) : array();
		$locations = is_wp_error( $locations ) ? array() : array_values( $locations );
		$data   = array(
			'id'          => $product->get_id(),
			'name'        => $product->get_name(),
			'type'        => $product->get_type(),
			'description' => wp_strip_all_tags( $product->get_short_description() ),
			'price'       => (float) $product->get_price(),
			'price_label' => wp_strip_all_tags( $product->get_price_html() ),
			'image'       => $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '',
			'categories'  => self::product_categories( $product ),
			'locations'   => $locations,
			'dietary'     => $labels['dietary'],
			'allergens'   => $labels['allergens'],
			'cross_sells' => self::menu_cross_sells( $product ),
			'in_stock'    => $product->is_in_stock(),
			'variations'  => array(),
			'modifiers'   => class_exists( 'WowRestro_Modifiers' ) ? WowRestro_Modifiers::groups_for_product( $product ) : array(),
		);
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation && $variation->is_purchasable() ) {
					$data['variations'][] = array(
						'id'          => $variation->get_id(),
						'name'        => $variation->get_name(),
						'attributes'  => $variation->get_variation_attributes(),
						'price'       => (float) $variation->get_price(),
						'price_label' => wp_strip_all_tags( wc_price( $variation->get_price() ) ),
						'in_stock'    => $variation->is_in_stock(),
					);
				}
			}
		}
		if ( $operations ) {
			$data['sku'] = $product->get_sku();
		}
		return $data;
	}

	private static function product_categories( $product ) {
		$terms = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		return is_wp_error( $terms ) ? array() : array_values( $terms );
	}

	private static function menu_cross_sells( $product ) {
		return array_values(
			array_filter(
				array_map( 'absint', $product->get_cross_sell_ids() ),
				function ( $product_id ) {
					return self::is_menu_item( $product_id );
				}
			)
		);
	}

	public static function availability( $request ) {
		$context = array( 'shipping_rate_id' => sanitize_text_field( (string) $request->get_param( 'shipping_rate_id' ) ) );
		$quote   = WowRestro_Fulfillment::quote( $request['mode'], $request['requested_at'], $request['postcode'], $context );
		if ( ! empty( $request['date'] ) && method_exists( 'WowRestro_Fulfillment', 'available_slots' ) ) {
			$quote['slots'] = WowRestro_Fulfillment::available_slots( $request['mode'], $request['date'], $context['shipping_rate_id'] );
		}
		return self::public_response( self::prepare_quote( $quote ), 200, true );
	}

	public static function operations_quote( $request ) {
		$lines = self::validate_lines( $request->get_param( 'items' ) );
		if ( is_wp_error( $lines ) ) {
			return self::error_status( $lines, 400 );
		}
		$addresses = self::addresses_from_request( $request );
		$rates     = self::shipping_rates( $lines, $addresses['shipping'] );
		if ( is_wp_error( $rates ) ) {
			return $rates;
		}
		$rate_id = sanitize_text_field( (string) $request->get_param( 'shipping_rate_id' ) );
		$mode    = sanitize_key( (string) ( $request->get_param( 'mode' ) ?: 'pickup' ) );
		if ( 'dinein' === $mode ) {
			$mode = 'pickup';
		}
		if ( ! in_array( $mode, array( 'pickup', 'delivery' ), true ) ) {
			return new WP_Error( 'wowrestro_mode', __( 'Choose pickup, delivery or dine-in.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( $rate_id ) {
			$selected = self::find_rate( $rates, $rate_id );
			if ( ! $selected ) {
				return new WP_Error( 'wowrestro_shipping_rate', __( 'The selected shipping method is unavailable for this address.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			$mode = $selected['mode'];
		}
		$quote                  = WowRestro_Fulfillment::quote(
			$mode,
			sanitize_text_field( (string) $request->get_param( 'requested_at' ) ),
			$addresses['shipping']['postcode'],
			array( 'shipping_rate_id' => $rate_id )
		);
		$data                   = self::prepare_quote( $quote );
		$data['shipping_rates'] = self::public_rates( $rates );
		return self::private_response( $data );
	}

	private static function prepare_quote( $quote ) {
		if ( ! empty( $quote['available'] ) ) {
			$quote['delivery_fee_label']  = wp_strip_all_tags( wc_price( (float) ( $quote['delivery_fee'] ?? 0 ) ) );
			$quote['minimum_order_label'] = wp_strip_all_tags( wc_price( (float) ( $quote['minimum_order'] ?? 0 ) ) );
		} elseif ( empty( $quote['message'] ) ) {
			$messages = array(
				'paused'                  => __( 'Online ordering is temporarily paused.', 'wowrestro' ),
				'asap_disabled'           => __( 'Choose a scheduled fulfillment time.', 'wowrestro' ),
				'invalid_time'            => __( 'Choose a valid fulfillment time.', 'wowrestro' ),
				'closed'                  => __( 'The restaurant is closed at that requested time.', 'wowrestro' ),
				'outside_preorder_window' => __( 'Choose a later requested time, or clear it to use ASAP.', 'wowrestro' ),
				'capacity'                => __( 'The kitchen is full for that time. Choose another time.', 'wowrestro' ),
				'storage_unavailable'     => __( 'Ordering is temporarily unavailable while the slot ledger is repaired.', 'wowrestro' ),
				'pickup_disabled'         => __( 'Pickup is not currently available.', 'wowrestro' ),
				'delivery_disabled'       => __( 'Delivery is not currently available.', 'wowrestro' ),
			);
			$quote['message'] = $messages[ sanitize_key( (string) ( $quote['reason'] ?? '' ) ) ] ?? __( 'That fulfillment option is not currently available.', 'wowrestro' );
		}
		return $quote;
	}

	public static function orders( $request ) {
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$page     = self::cursor_page( $request->get_param( 'cursor' ) );
		$status   = WowRestro_Order_Statuses::normalize( $request->get_param( 'status' ) );
		if ( $status && 'all' !== $status && ! isset( WowRestro_Order_Statuses::labels()[ $status ] ) ) {
			return new WP_Error( 'wowrestro_status_filter', __( 'Choose a valid restaurant status.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$states = 'all' === $status ? array_keys( WowRestro_Order_Statuses::labels() ) : ( $status ? array( $status ) : array( 'new', 'accepted', 'preparing', 'ready', 'out_for_delivery' ) );
		$mode   = sanitize_key( (string) $request->get_param( 'mode' ) );
		if ( $mode && ! in_array( $mode, array( 'pickup', 'delivery' ), true ) ) {
			return new WP_Error( 'wowrestro_mode_filter', __( 'Choose pickup or delivery.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$location = sanitize_title( (string) $request->get_param( 'location' ) );
		$period   = sanitize_key( (string) $request->get_param( 'period' ) );
		if ( $period && 'today' !== $period ) {
			return new WP_Error( 'wowrestro_order_period', __( 'The live order board only supports today.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$today_start = current_datetime()->setTime( 0, 0, 0 )->getTimestamp();
		$tomorrow    = current_datetime()->setTime( 0, 0, 0 )->modify( '+1 day' )->getTimestamp();
		$orders   = array_merge( self::orders_for_states( $states ), WowRestro_Order_Statuses::sync_active_orders() );
		$orders   = array_values(
			array_reduce(
				$orders,
				static function ( $unique, $order ) {
					if ( $order instanceof WC_Order ) {
						$unique[ $order->get_id() ] = $order;
					}
					return $unique;
				},
				array()
			)
		);
		$orders   = array_values(
			array_filter(
				$orders,
				function ( $order ) use ( $states, $mode, $search, $location, $period, $today_start, $tomorrow ) {
					if ( ! $order instanceof WC_Order || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) || 'yes' === $order->get_meta( '_wowrestro_initializing' ) ) {
						return false;
					}
					$created = $order->get_date_created();
					if ( 'today' === $period && ( ! $created || $created->getTimestamp() < $today_start || $created->getTimestamp() >= $tomorrow ) ) {
						return false;
					}
					if ( ! in_array( WowRestro_Order_Statuses::current( $order ), $states, true ) ) {
						return false;
					}
					$order_mode = sanitize_key( (string) ( $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ) ) );
					if ( $mode && $order_mode !== $mode ) {
						return false;
					}
					if ( $location && $location !== $order->get_meta( WowRestro_Locations::ORDER_META ) ) {
						return false;
					}
					if ( $search ) {
						$haystack = strtolower( implode( ' ', array( $order->get_id(), $order->get_order_number(), $order->get_formatted_billing_full_name(), $order->get_billing_phone(), $order->get_billing_email() ) ) );
						return false !== strpos( $haystack, strtolower( $search ) );
					}
					return true;
				}
			)
		);
		usort( $orders, array( __CLASS__, 'sort_orders' ) );
		$total  = count( $orders );
		$max    = max( 1, (int) ceil( $total / $per_page ) );
		$orders = array_slice( $orders, ( $page - 1 ) * $per_page, $per_page );
		$data   = array_map( array( __CLASS__, 'prepare_order' ), $orders );
		$etag   = '"' . md5( wp_json_encode( array( $data, self::paused() ) ) ) . '"';
		if ( trim( (string) $request->get_header( 'if-none-match' ) ) === $etag ) {
			return self::private_response( null, 304, $etag );
		}
		return self::private_response(
			array(
				'orders'       => $data,
				'cursor'       => $page < $max ? self::encode_cursor( $page + 1 ) : '',
				'total'        => $total,
				'paused'       => self::paused(),
				'generated_at' => gmdate( DATE_ATOM ),
			),
			200,
			$etag
		);
	}

	/** Load active operational IDs from the authoritative Woo order meta store. */
	private static function orders_for_states( $states ) {
		global $wpdb;
		$values = $states;
		foreach ( $states as $state ) {
			if ( in_array( $state, array( 'new', 'accepted', 'preparing', 'ready' ), true ) ) {
				$values[] = 'wr-' . $state;
			} elseif ( 'out_for_delivery' === $state ) {
				$values[] = 'wr-out-for-delivery';
			}
		}
		$values = array_values( array_unique( array_filter( array_map( 'sanitize_key', $values ) ) ) );
		if ( ! $values ) {
			return array();
		}
		$hpos         = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table        = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id_column    = $hpos ? 'order_id' : 'post_id';
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
		$sql          = "SELECT DISTINCT {$id_column} FROM {$table} WHERE meta_key = %s AND meta_value IN ({$placeholders})";
		$ids          = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( WowRestro_Order_Statuses::META_STATUS ), $values ) ) );
		return array_values( array_filter( array_map( 'wc_get_order', array_map( 'absint', $ids ) ) ) );
	}

	public static function queue( $request ) {
		$request->set_param( 'per_page', 50 );
		$response = self::orders( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 304 === $response->get_status() ) {
			return $response;
		}
		$data           = $response->get_data();
		$data['orders'] = array_map( array( __CLASS__, 'legacy_order' ), $data['orders'] );
		$data['cursor'] = gmdate( DATE_ATOM );
		$response->set_data( $data );
		return $response;
	}

	public static function order_detail( $request ) {
		$order = self::restaurant_order( $request['id'] );
		return is_wp_error( $order ) ? $order : self::private_response( self::prepare_order( $order, true ) );
	}

	/**
	 * Format money for a JSON client.
	 *
	 * wc_price() emits HTML entities for currency symbols. Stripping tags leaves
	 * the raw entity, which renders literally as "&#8377;448.00" wherever the
	 * response is written with textContent, so decode to real UTF-8 here.
	 *
	 * @param string $html Price markup from WooCommerce.
	 * @return string
	 */
	private static function money_text( $html ) {
		return html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
	}

	public static function prepare_order( $order, $detail = false ) {
		$status  = WowRestro_Order_Statuses::current( $order );
		$mode    = sanitize_key( $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ) ?: 'pickup' );
		$promise = $order->get_meta( '_wowrestro_promised_at_gmt' ) ?: $order->get_meta( '_wowrestro_promised_at' );
		$created = $order->get_date_created();
		$items   = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'id'        => $item->get_id(),
				'name'      => $item->get_name(),
				'quantity'  => $item->get_quantity(),
				'total'     => self::money_text( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ),
				'modifiers' => (array) $item->get_meta( '_wowrestro_modifier_snapshot', true ),
				'note'      => (string) $item->get_meta( '_wowrestro_special_note', true ),
			);
		}
		$data = array(
			'id'                  => $order->get_id(),
			'number'              => $order->get_order_number(),
			'status'              => $status,
			'status_label'        => $status ? WowRestro_Order_Statuses::label( $status ) : __( 'Status not set', 'wowrestro' ),
			'payment_status'      => $order->get_status(),
			'payment_label'       => wc_get_order_status_name( $order->get_status() ),
			'payment_due'         => self::payment_due( $order ),
			'source'              => $order->get_meta( '_wowrestro_source' ),
			'location'            => $order->get_meta( '_wowrestro_location' ),
			'dining_type'         => $order->get_meta( '_wowrestro_dining_type' ),
			'table'               => $order->get_meta( '_wowrestro_table' ),
			'customer'            => $order->get_formatted_billing_full_name() ?: __( 'Guest', 'wowrestro' ),
			'mode'                => $mode,
			'kitchen_slot_gmt'    => $order->get_meta( '_wowrestro_kitchen_slot_gmt' ),
			'promised_at_gmt'     => $order->get_meta( '_wowrestro_promised_at_gmt' ),
			'promised_at'         => $promise,
			'promised_at_local'   => $order->get_meta( '_wowrestro_promised_at' ),
			'promised_label'      => self::date_label( $promise ),
			'service_label'       => self::date_label( $promise, 'M j, g:i a' ),
			'created_label'       => $created ? wp_date( 'M j, g:i a', $created->getTimestamp(), wp_timezone() ) : '',
			'is_late'             => self::is_late( $promise, $status ),
			'phone'               => $order->get_billing_phone(),
			'note'                => $order->get_customer_note(),
			'created_at'          => $created ? $created->format( DATE_ATOM ) : '',
			'total'               => self::money_text( $order->get_formatted_order_total() ),
			'items'               => $items,
			'allowed_transitions' => WowRestro_Order_Statuses::next( $status, $mode ),
			'revision'            => self::order_revision( $order ),
		);
		if ( $detail ) {
			// Only the detail response carries the money breakdown; the queue payload
			// stays as small as it was.
			$currency                    = array( 'currency' => $order->get_currency() );
			$data['subtotal']            = self::money_text( wc_price( $order->get_subtotal(), $currency ) );
			$data['shipping_total']      = self::money_text( wc_price( $order->get_shipping_total(), $currency ) );
			$data['tax_total']           = self::money_text( wc_price( $order->get_total_tax(), $currency ) );
			$data['discount_total']      = self::money_text( wc_price( $order->get_discount_total(), $currency ) );
			$data['has_shipping']        = (float) $order->get_shipping_total() > 0;
			$data['has_discount']        = (float) $order->get_discount_total() > 0;
			$data['email']               = $order->get_billing_email();
			$data['billing_address']     = wp_strip_all_tags( $order->get_formatted_billing_address() );
			$data['shipping_address']    = wp_strip_all_tags( $order->get_formatted_shipping_address() );
			$data['shipping_rate_id']    = $order->get_meta( '_wowrestro_shipping_rate_id' );
			$data['payment_flow']        = $order->get_meta( '_wowrestro_payment_flow' );

			$data['payment_method']       = $order->get_payment_method();
			$data['payment_method_title'] = $order->get_payment_method_title();

			$data['transaction_id'] = $order->get_transaction_id();
			$data['currency']       = $order->get_currency();
			$data['fees']           = array_values(
				array_map(
					static function ( $fee ) use ( $currency ) {
						return array(
							'name'  => $fee->get_name(),
							'total' => self::money_text( wc_price( $fee->get_total(), $currency ) ),
							'tax'   => self::money_text( wc_price( $fee->get_total_tax(), $currency ) ),
						);
					},
					$order->get_items( 'fee' )
				)
			);

			$data['payment_expires_gmt'] = $order->get_meta( '_wowrestro_payment_expires_gmt' );
			$data['history']             = self::history( $order, true );
			if ( self::can_payments() && $data['payment_due'] ) {
				$data['payment_url'] = $order->get_checkout_payment_url();
			}
		}
		return $data;
	}

	private static function legacy_order( $order ) {
		$map = array(
			'new'              => 'wr-new',
			'accepted'         => 'wr-accepted',
			'preparing'        => 'wr-preparing',
			'ready'            => 'wr-ready',
			'out_for_delivery' => 'wr-out-for-delivery',
		);
		if ( isset( $map[ $order['status'] ] ) ) {
			$order['status'] = $map[ $order['status'] ];
		}
		if ( ! empty( $order['promised_at_local'] ) ) {
			$order['promised_at'] = $order['promised_at_local'];
		}
		unset( $order['allowed_transitions'], $order['revision'], $order['status_label'], $order['payment_status'], $order['payment_label'], $order['payment_due'], $order['promised_label'], $order['promised_at_local'] );
		return $order;
	}

	public static function transition( $request ) {
		$order = self::restaurant_order( $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( false !== strpos( (string) $request->get_route(), '/operations/orders/' ) && ! $request->get_param( 'revision' ) ) {
			return new WP_Error( 'wowrestro_revision_required', __( 'Refresh the order before changing its status.', 'wowrestro' ), array( 'status' => 428 ) );
		}
		$order_id = $order->get_id();
		$locked   = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$order = self::restaurant_order( $order_id );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$stale = self::check_revision( $order, $request->get_param( 'revision' ) );
			if ( is_wp_error( $stale ) ) {
				return $stale;
			}
			$current  = WowRestro_Order_Statuses::current( $order );
			$next     = WowRestro_Order_Statuses::normalize( $request['status'] );
			$mode     = $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' );
			$allowed  = WowRestro_Order_Statuses::next( $current, $mode );
			$override = rest_sanitize_boolean( $request->get_param( 'override' ) );
			$reason   = self::limit_text( $request->get_param( 'reason' ), 200 );
			$valid    = in_array( $next, $allowed, true );
			if ( ! $valid && ( ! $override || ! self::can_manage() || ! $reason || ! isset( WowRestro_Order_Statuses::labels()[ $next ] ) ) ) {
				return new WP_Error( 'wowrestro_invalid_transition', __( 'That status transition is not allowed.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			if ( 'delivery' === $mode && 'new' !== $next && 'cancelled' !== $next && 'yes' === $order->get_meta( '_wowrestro_delivery_address_incomplete' ) ) {
				if ( ! $order->get_shipping_address_1() ) {
					return new WP_Error( 'wowrestro_delivery_address_incomplete', __( 'Confirm and save the delivery street address before accepting this order.', 'wowrestro' ), array( 'status' => 409 ) );
				}
				$order->delete_meta_data( '_wowrestro_delivery_address_incomplete' );
				$order->save_meta_data();
			}
			$result = WowRestro_Order_Statuses::set_status( $order, $next, $reason ?: __( 'Restaurant workflow updated.', 'wowrestro' ), ! $valid, null, $current );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$order = wc_get_order( $order_id );
			if ( 'completed' === $next && $order->is_paid() && 'completed' !== $order->get_status() ) {
				$order->update_status( 'completed', __( 'Restaurant fulfillment completed.', 'wowrestro' ) );
			} elseif ( 'cancelled' === $next && ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', sprintf( __( 'Cancelled by restaurant: %s. Refunds must be handled separately.', 'wowrestro' ), $reason ) );
			}
			if ( in_array( $next, array( 'completed', 'cancelled' ), true ) ) {
				self::release_order( $order );
			}
			// The transition endpoint owns this staff action. Calling push here is
			// a safe fallback if a host drops the action callback; successful hook
			// sends are de-duplicated inside the notification service.
			WowRestro_Push_Notifications::send_order_push( $order_id, $current, $next );
			return self::private_response( self::prepare_order( $order, true ) );
		} finally {
			WowRestro_Order_Statuses::release_order_lock( $order_id );
		}
	}

	public static function legacy_transition( $request ) {
		$response = self::transition( $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response->set_data( self::legacy_order( $response->get_data() ) );
		return $response;
	}

	public static function cancel_order( $request ) {
		$order  = self::restaurant_order( $request['id'] );
		$reason = self::limit_text( $request->get_param( 'reason' ), 200 );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! $request->get_param( 'revision' ) ) {
			return new WP_Error( 'wowrestro_revision_required', __( 'Refresh the order before cancelling it.', 'wowrestro' ), array( 'status' => 428 ) );
		}
		if ( ! $reason ) {
			return new WP_Error( 'wowrestro_cancel_reason', __( 'A cancellation reason is required.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$order_id = $order->get_id();
		$locked   = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$order = self::restaurant_order( $order_id );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$stale = self::check_revision( $order, $request->get_param( 'revision' ) );
			if ( is_wp_error( $stale ) ) {
				return $stale;
			}
			$current = WowRestro_Order_Statuses::current( $order );
			$result  = WowRestro_Order_Statuses::set_status( $order, 'cancelled', $reason, true, null, $current );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$order = wc_get_order( $order_id );
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', sprintf( __( 'Cancelled by restaurant: %s. Refunds must be handled separately.', 'wowrestro' ), $reason ) );
			}
			self::release_order( $order );
			return self::private_response( self::prepare_order( $order, true ) );
		} finally {
			WowRestro_Order_Statuses::release_order_lock( $order_id );
		}
	}

	public static function reschedule_order( $request ) {
		$order = self::restaurant_order( $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! $request->get_param( 'revision' ) ) {
			return new WP_Error( 'wowrestro_revision_required', __( 'Refresh the order before rescheduling it.', 'wowrestro' ), array( 'status' => 428 ) );
		}
		$stale = self::check_revision( $order, $request->get_param( 'revision' ) );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		$failed_payment = 'failed' === $order->get_status() && ! $order->is_paid();
		$operational    = WowRestro_Order_Statuses::current( $order );
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) || 'completed' === $operational || ( 'cancelled' === $operational && ! $failed_payment ) ) {
			return new WP_Error( 'wowrestro_reschedule_closed', __( 'A closed order cannot be rescheduled.', 'wowrestro' ), array( 'status' => 409 ) );
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		if ( ! $reason ) {
			return new WP_Error( 'wowrestro_reschedule_reason', __( 'A reschedule reason is required.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( self::text_length( $reason ) > 200 ) {
			return new WP_Error( 'wowrestro_reschedule_reason', __( 'The reschedule reason cannot exceed 200 characters.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$mode      = sanitize_key( (string) $request->get_param( 'mode' ) );
		$requested = sanitize_text_field( (string) $request->get_param( 'requested_at' ) );
		if ( ! in_array( $mode, array( 'pickup', 'delivery' ), true ) ) {
			return new WP_Error( 'wowrestro_mode', __( 'Choose pickup or delivery.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		if ( ! $requested || strlen( $requested ) > 64 ) {
			return new WP_Error( 'wowrestro_invalid_time', __( 'Choose a valid requested time.', 'wowrestro' ), array( 'status' => 400 ) );
		}

		$params         = $request->get_params();
		$rate_supplied  = array_key_exists( 'shipping_rate_id', $params );
		$requested_rate = sanitize_text_field( (string) $request->get_param( 'shipping_rate_id' ) );
		$existing_rate  = sanitize_text_field( (string) ( $order->get_meta( '_wowrestro_shipping_rate_id' ) ?: ( class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::order_rate_id( $order ) : '' ) ) );
		$rate_id        = $rate_supplied ? $requested_rate : $existing_rate;
		if ( $rate_supplied && ! $rate_id ) {
			return new WP_Error( 'wowrestro_shipping_rate', __( 'Choose a valid pickup or delivery method.', 'wowrestro' ), array( 'status' => 400 ) );
		}

		$lines       = self::order_lines_for_shipping( $order );
		$destination = self::order_shipping_destination( $order );
		$rates       = $lines ? self::shipping_rates( $lines, $destination ) : array();
		if ( is_wp_error( $rates ) ) {
			return self::error_status( $rates, 409 );
		}
		$selected = $rate_id ? self::find_rate( $rates, $rate_id ) : null;
		if ( $rate_id && $lines && ! $selected ) {
			return new WP_Error( 'wowrestro_shipping_rate', __( 'That shipping method is unavailable for this order address.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		if ( $selected && $selected['mode'] !== $mode ) {
			return new WP_Error( 'wowrestro_shipping_mode', __( 'The shipping method does not match the selected fulfillment mode.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		if ( ( 'delivery' === $mode || self::lines_need_shipping( $lines ) ) && ! $rate_id ) {
			return new WP_Error( 'wowrestro_shipping_required', __( 'Choose an available pickup or delivery method.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		if ( $rate_supplied && ! $lines ) {
			return new WP_Error( 'wowrestro_shipping_unverifiable', __( 'The shipping method cannot be verified for this historical order.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		$merchandise_total = max( 0.0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
		if ( $selected && class_exists( 'WowRestro_Shipping' ) && $merchandise_total < WowRestro_Shipping::minimum_for_rate( $rate_id ) ) {
			return new WP_Error( 'wowrestro_minimum_order', __( 'The order no longer meets that shipping method minimum.', 'wowrestro' ), array( 'status' => 409 ) );
		}

		$order_id = $order->get_id();
		$locked   = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$order = self::restaurant_order( $order_id );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$stale = self::check_revision( $order, $request->get_param( 'revision' ) );
			if ( is_wp_error( $stale ) ) {
				return $stale;
			}
			$failed_payment = 'failed' === $order->get_status() && ! $order->is_paid();
			$operational    = WowRestro_Order_Statuses::current( $order );
			if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) || 'completed' === $operational || ( 'cancelled' === $operational && ! $failed_payment ) ) {
				return new WP_Error( 'wowrestro_reschedule_closed', __( 'A closed order cannot be rescheduled.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			$shipping_change = $rate_supplied && $rate_id !== $existing_rate;
			$shipping_backup = null;
			if ( $shipping_change ) {
				$shipping_backup = self::replace_order_shipping_rate( $order, $selected );
				if ( is_wp_error( $shipping_backup ) ) {
					return $shipping_backup;
				}
			}
			$old_promise = $order->get_meta( '_wowrestro_promised_at_gmt' ) ?: $order->get_meta( '_wowrestro_promised_at' );
			$result      = WowRestro_Slot_Reservations::reschedule_order( $order, $mode, $requested, $destination['postcode'], array( 'shipping_rate_id' => $rate_id ) );
			if ( is_wp_error( $result ) ) {
				if ( $shipping_backup && ! self::restore_order_shipping_rate( $order, $shipping_backup ) ) {
					$order->add_order_note( __( 'WowRestro could not restore the prior shipping item after a failed reschedule. Review this order manually.', 'wowrestro' ) );
					return new WP_Error( 'wowrestro_reschedule_rollback', __( 'The reschedule failed and the shipping item requires manual review.', 'wowrestro' ), array( 'status' => 500 ) );
				}
				return self::error_status( $result, 409 );
			}
			$new_promise = $order->get_meta( '_wowrestro_promised_at_gmt' ) ?: $order->get_meta( '_wowrestro_promised_at' );
			if ( $failed_payment ) {
				$order->update_meta_data( '_wowrestro_payment_retry_reserved', 'yes' );
			}
			$order->add_order_note( sprintf( __( 'WowRestro rescheduled fulfillment from %1$s to %2$s. Reason: %3$s', 'wowrestro' ), $old_promise ?: __( 'unscheduled', 'wowrestro' ), $new_promise, $reason ) );
			$order->save();
			return self::private_response( self::prepare_order( $order, true ) );
		} finally {
			WowRestro_Order_Statuses::release_order_lock( $order_id );
		}
	}

	public static function record_payment( $request ) {
		$order = self::restaurant_order( $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$order_id = $order->get_id();
		$locked   = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$order = self::restaurant_order( $order_id );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			if ( $order->is_paid() ) {
				return self::private_response( self::prepare_order( $order, true ) );
			}
			if ( ! self::payment_due( $order ) ) {
				return new WP_Error( 'wowrestro_payment_closed', __( 'A closed order cannot be marked paid.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			$capacity = WowRestro_Slot_Reservations::ensure_payment_capacity( $order );
			if ( is_wp_error( $capacity ) ) {
				return self::error_status( $capacity, 409 );
			}
			$transaction_id = self::limit_text( $request->get_param( 'transaction_id' ), 100 );
			if ( ! $order->payment_complete( $transaction_id ) ) {
				return new WP_Error( 'wowrestro_payment_failed', __( 'WooCommerce could not record the payment. Review the order notes and try again.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			$order = wc_get_order( $order_id );
			$order->update_meta_data( '_wowrestro_payment_recorded_by', get_current_user_id() );
			$order->add_order_note( __( 'External payment recorded by a WowRestro manager.', 'wowrestro' ) );
			$order->save();
			return self::private_response( self::prepare_order( $order, true ) );
		} finally {
			WowRestro_Order_Statuses::release_order_lock( $order_id );
		}
	}

	public static function operations_status() {
		return self::private_response( array( 'paused' => self::paused() ) );
	}

	public static function update_operations_status( $request ) {
		$settings                  = WowRestro_Fulfillment::settings();
		$settings['orders_paused'] = rest_sanitize_boolean( $request->get_param( 'paused' ) ) ? 'yes' : 'no';
		update_option( 'wowrestro_settings', $settings, false );
		return self::operations_status();
	}

	public static function phone_order( $request, $legacy = false ) {
		$idempotency = trim( (string) ( $request->get_header( 'idempotency-key' ) ?: $request->get_param( 'idempotency_key' ) ) );
		if ( ! $idempotency && $legacy ) {
			$idempotency = self::legacy_idempotency_key( $request );
		}
		if ( strlen( $idempotency ) < 8 || strlen( $idempotency ) > 128 ) {
			return new WP_Error( 'wowrestro_idempotency_key', __( 'Supply an Idempotency-Key between 8 and 128 characters.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$user_id          = get_current_user_id();
		$lock_key         = 'wowrestro_idem_' . md5( $user_id . '|' . $idempotency );
		$idempotency_hash = hash( 'sha256', $user_id . '|' . $idempotency );
		$lock_state       = array(
			'created_at' => time(),
			'owner'      => wp_generate_uuid4(),
			'phase'      => 'building',
		);
		$stored           = get_option( $lock_key, false );
		$existing         = self::find_idempotent_order( $idempotency_hash, $user_id, true );
		if ( $existing ) {
			$mapping   = array(
				'created_at' => time(),
				'order_id'   => $existing->get_id(),
			);
			$recovered = false === $stored ? add_option( $lock_key, $mapping, '', false ) : self::compare_and_swap_option( $lock_key, $stored, $mapping );
			$current   = $recovered ? $mapping : get_option( $lock_key, false );
			if ( ! is_array( $current ) || absint( $current['order_id'] ?? 0 ) !== $existing->get_id() ) {
				return new WP_Error( 'wowrestro_order_in_progress', __( 'This phone order is already being finalized. Retry with the same key.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			self::finalize_phone_order( $existing );
			$existing                  = wc_get_order( $existing->get_id() );
			$data                      = self::prepare_order( $existing, true );
			$data['idempotent_replay'] = true;
			return self::private_response( $legacy ? self::legacy_order( $data ) : $data );
		}

		$partial = false;
		if ( is_array( $stored ) && ! empty( $stored['order_id'] ) ) {
			$candidate = wc_get_order( absint( $stored['order_id'] ) );
			if ( $candidate instanceof WC_Order && WowRestro_Order_Statuses::is_restaurant_order( $candidate ) ) {
				if ( ! hash_equals( $idempotency_hash, (string) $candidate->get_meta( '_wowrestro_idempotency_key' ) ) || absint( $candidate->get_meta( '_wowrestro_created_by' ) ) !== $user_id ) {
					return new WP_Error( 'wowrestro_idempotency_conflict', __( 'This idempotency key is mapped to a different order.', 'wowrestro' ), array( 'status' => 409 ) );
				}
				$partial = $candidate;
			}
		}
		if ( ! $partial ) {
			$partial = self::find_idempotent_order( $idempotency_hash, $user_id, false );
		}
		if ( $partial && 'yes' === $partial->get_meta( '_wowrestro_idempotency_complete' ) ) {
			$mapping   = array(
				'created_at' => time(),
				'order_id'   => $partial->get_id(),
			);
			$recovered = false === $stored ? add_option( $lock_key, $mapping, '', false ) : self::compare_and_swap_option( $lock_key, $stored, $mapping );
			$current   = $recovered ? $mapping : get_option( $lock_key, false );
			if ( ! is_array( $current ) || absint( $current['order_id'] ?? 0 ) !== $partial->get_id() ) {
				return new WP_Error( 'wowrestro_order_in_progress', __( 'This phone order is already being finalized. Retry with the same key.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			self::finalize_phone_order( $partial );
			$data                      = self::prepare_order( wc_get_order( $partial->get_id() ), true );
			$data['idempotent_replay'] = true;
			return self::private_response( $legacy ? self::legacy_order( $data ) : $data );
		}
		$stored_stale = is_array( $stored ) && time() - absint( $stored['created_at'] ?? 0 ) > self::PHONE_IDEMPOTENCY_LOCK_TTL;
		if ( $partial && 'committed' === $partial->get_meta( '_wowrestro_initialization_phase' ) ) {
			$resume_state = array(
				'created_at' => time(),
				'owner'      => wp_generate_uuid4(),
				'phase'      => 'committed',
				'order_id'   => $partial->get_id(),
			);
			$claimed      = false === $stored ? add_option( $lock_key, $resume_state, '', false ) : ( $stored_stale ? self::compare_and_swap_option( $lock_key, $stored, $resume_state ) : false );
			if ( ! $claimed ) {
				return new WP_Error( 'wowrestro_order_in_progress', __( 'This phone order is being finalized. Retry with the same key.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			self::schedule_phone_finalization( $partial->get_id(), 5 );
			$result = self::finalize_phone_order( $partial );
			if ( is_wp_error( $result ) ) {
				self::make_phone_finalization_resumable( $lock_key, $resume_state, $partial );
				return self::error_status( $result, 409 );
			}
			$mapping = array(
				'created_at' => time(),
				'order_id'   => $partial->get_id(),
			);
			self::compare_and_swap_option( $lock_key, $resume_state, $mapping );
			$data                      = self::prepare_order( wc_get_order( $partial->get_id() ), true );
			$data['idempotent_replay'] = true;
			return self::private_response( $legacy ? self::legacy_order( $data ) : $data );
		}
		if ( $partial && false !== $stored && ! $stored_stale ) {
			return new WP_Error( 'wowrestro_order_in_progress', __( 'This phone order has not finished initializing. Retry with the same key.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		$claimed = false === $stored ? add_option( $lock_key, $lock_state, '', false ) : false;
		if ( ! $claimed && is_array( $stored ) && ( ! empty( $stored['order_id'] ) || $stored_stale ) ) {
			$claimed = self::compare_and_swap_option( $lock_key, $stored, $lock_state );
		}
		if ( ! $claimed ) {
			return new WP_Error( 'wowrestro_order_in_progress', __( 'This phone order is already being created. Retry with the same key.', 'wowrestro' ), array( 'status' => 409 ) );
		}

		$order                      = false;
		$committed                  = false;
		$legacy_delivery_incomplete = false;
		$reservation                = array();
		try {
			if ( $partial ) {
				$partial_status = WowRestro_Order_Statuses::current( $partial );
				if ( $partial->is_paid() || ( $partial_status && 'new' !== $partial_status ) ) {
					throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_incomplete_order', __( 'An interrupted phone order was already acted on and requires manual review.', 'wowrestro' ), array( 'status' => 409 ) ) );
				}
				self::rollback_phone_order( $partial, array( 'reservation_token' => $partial->get_meta( '_wowrestro_reservation_token' ) ) );
				if ( wc_get_order( $partial->get_id() ) ) {
					throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_incomplete_order', __( 'An interrupted phone order requires manual review before this key can be retried.', 'wowrestro' ), array( 'status' => 409 ) ) );
				}
			}
			$payment_flow = sanitize_key( (string) $request->get_param( 'payment_flow' ) );
			if ( ! $payment_flow ) {
				$payment_flow = 'pay_later';
			}
			if ( ! in_array( $payment_flow, array( 'payment_link', 'pay_later' ), true ) ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_payment_flow', __( 'Choose payment link or pay later.', 'wowrestro' ), array( 'status' => 400 ) ) );
			}
			$lines = self::validate_lines( $request->get_param( 'items' ) );
			if ( is_wp_error( $lines ) ) {
				throw new WowRestro_REST_Exception( $lines );
			}
			$addresses = self::addresses_from_request( $request );
			$rates     = self::shipping_rates( $lines, $addresses['shipping'] );
			if ( is_wp_error( $rates ) ) {
				throw new WowRestro_REST_Exception( $rates );
			}
			$rate_id = sanitize_text_field( (string) $request->get_param( 'shipping_rate_id' ) );
			$mode    = sanitize_key( (string) ( $request->get_param( 'mode' ) ?: 'pickup' ) );
			$dine_in = 'dinein' === $mode;
			if ( $dine_in ) {
				$mode = 'pickup';
			}
			if ( ! in_array( $mode, array( 'pickup', 'delivery' ), true ) ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_mode', __( 'Choose pickup, delivery or dine-in.', 'wowrestro' ), array( 'status' => 400 ) ) );
			}
			if ( $dine_in && ! self::limit_text( $request->get_param( 'table' ), 80 ) ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_table', __( 'Choose a table for a dine-in order.', 'wowrestro' ), array( 'status' => 400 ) ) );
			}
			if ( ! $legacy && ! $rate_id && self::first_rate_for_mode( $rates, $mode ) ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_shipping_rate_required', __( 'Choose an available WooCommerce shipping method.', 'wowrestro' ), array( 'status' => 400 ) ) );
			}
			$selected_rate = $rate_id ? self::find_rate( $rates, $rate_id ) : self::first_rate_for_mode( $rates, $mode );
			if ( $rate_id && ! $selected_rate ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_shipping_rate', __( 'The selected shipping method is unavailable for this address.', 'wowrestro' ), array( 'status' => 409 ) ) );
			}
			if ( $selected_rate ) {
				$rate_id = $selected_rate['id'];
				$mode    = $selected_rate['mode'];
			} elseif ( 'delivery' === $mode ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_shipping_required', __( 'Choose an available pickup or delivery method.', 'wowrestro' ), array( 'status' => 409 ) ) );
			}
			if ( 'delivery' === $mode && ! $addresses['shipping']['postcode'] ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_delivery_address', __( 'A delivery postcode is required.', 'wowrestro' ), array( 'status' => 400 ) ) );
			}
			if ( 'delivery' === $mode && ! $addresses['shipping']['address_1'] ) {
				if ( ! $legacy ) {
					throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_delivery_address', __( 'A delivery address and postcode are required.', 'wowrestro' ), array( 'status' => 400 ) ) );
				}
				$legacy_delivery_incomplete = true;
			}

			$requested   = sanitize_text_field( (string) $request->get_param( 'requested_at' ) );
			$context     = array( 'shipping_rate_id' => $rate_id );
			$reservation = WowRestro_Slot_Reservations::reserve( $mode, $requested, $addresses['shipping']['postcode'], $context );
			if ( is_wp_error( $reservation ) ) {
				throw new WowRestro_REST_Exception( $reservation );
			}
			$minimum  = (float) ( $reservation['minimum_order'] ?? ( $rate_id && class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::minimum_for_rate( $rate_id ) : 0 ) );
			$subtotal = self::line_subtotal( $lines );
			if ( $minimum > 0 && $subtotal < $minimum ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_minimum_order', sprintf( __( 'The minimum order is %s.', 'wowrestro' ), wp_strip_all_tags( wc_price( $minimum ) ) ), array( 'status' => 400 ) ) );
			}

			$order = wc_create_order( array( 'created_via' => 'wowrestro-phone' ) );
			if ( is_wp_error( $order ) ) {
				throw new WowRestro_REST_Exception( $order );
			}
			$order->update_meta_data( '_wowrestro_order', 'yes' );
			$order->update_meta_data( '_wowrestro_source', 'phone' );
			$location = sanitize_title( (string) $request->get_param( 'location' ) );
			if ( $location && term_exists( $location, WowRestro_Locations::TAXONOMY ) ) {
				$order->update_meta_data( WowRestro_Locations::ORDER_META, $location );
			}
			if ( $dine_in ) {
				$order->update_meta_data( '_wowrestro_dining_type', 'dine_in' );
				$order->update_meta_data( '_wowrestro_table', self::limit_text( $request->get_param( 'table' ), 80 ) );
			}
			$order->update_meta_data( '_wowrestro_idempotency_key', $idempotency_hash );
			$order->update_meta_data( '_wowrestro_created_by', $user_id );
			$order->update_meta_data( '_wowrestro_initializing', 'yes' );
			$order->update_meta_data( '_wowrestro_initialization_phase', 'building' );
			$order->save_meta_data();
			$building_state = array_merge( $lock_state, array( 'order_id' => $order->get_id() ) );
			if ( ! self::compare_and_swap_option( $lock_key, $lock_state, $building_state ) ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_idempotency_conflict', __( 'The phone-order key changed ownership before the order was initialized.', 'wowrestro' ), array( 'status' => 409 ) ) );
			}
			$lock_state = $building_state;
			self::apply_addresses( $order, $addresses );
			foreach ( $lines as $line ) {
				$item_id = $order->add_product( $line['product'], $line['quantity'] );
				$item    = $item_id ? $order->get_item( $item_id ) : false;
				if ( ! $item ) {
					throw new Exception( 'Unable to add an order item.' );
				}
				$delta = $line['modifier_total'] * $line['quantity'];
				if ( $delta > 0 ) {
					$item->set_subtotal( (float) $item->get_subtotal() + $delta );
					$item->set_total( (float) $item->get_total() + $delta );
				}
				if ( $line['modifiers'] ) {
					$item->add_meta_data( '_wowrestro_modifier_snapshot', $line['modifiers'], true );
				}
				if ( $line['note'] ) {
					$item->add_meta_data( '_wowrestro_special_note', $line['note'], true );
				}
				$item->save();
			}
			if ( $selected_rate ) {
				self::add_shipping_item( $order, $selected_rate );
			}
			$order->set_customer_note( self::limit_text( $request->get_param( 'note' ), 500 ) );
			$order->update_meta_data( '_wowrestro_fulfillment', $reservation['mode'] );
			$order->update_meta_data( '_wowrestro_mode', $reservation['mode'] );
			$order->update_meta_data( '_wowrestro_shipping_rate_id', $rate_id );
			$order->update_meta_data( '_wowrestro_kitchen_slot_gmt', $reservation['kitchen_slot_gmt'] ?? '' );
			$order->update_meta_data( '_wowrestro_promised_at_gmt', $reservation['promised_at_gmt'] ?? '' );
			$order->update_meta_data( '_wowrestro_promised_at', $reservation['promised_at'] ?? '' );
			$order->update_meta_data( '_wowrestro_reservation_token', $reservation['reservation_token'] );
			$order->update_meta_data( '_wowrestro_status_opt_in', rest_sanitize_boolean( $request->get_param( 'status_opt_in' ) ) ? 'yes' : 'no' );
			$order->update_meta_data( '_wowrestro_payment_flow', $payment_flow );
			$order->update_meta_data( '_wowrestro_send_payment_link', false !== $request->get_param( 'send_payment_link' ) ? 'yes' : 'no' );
			if ( $legacy_delivery_incomplete ) {
				$order->update_meta_data( '_wowrestro_delivery_address_incomplete', 'yes' );
				$order->add_order_note( __( 'The legacy phone-order form did not collect a street address. Confirm and save the delivery address before accepting this order.', 'wowrestro' ) );
			}
			if ( 'payment_link' === $payment_flow ) {
				$payment_ttl = self::phone_payment_ttl();
				$order->update_meta_data( '_wowrestro_payment_expires_gmt', gmdate( 'Y-m-d H:i:s', time() + $payment_ttl ) );
				$order->set_payment_method_title( __( 'Payment link', 'wowrestro' ) );
			} else {
				$order->set_payment_method( 'wowrestro_pay_later' );
				$order->set_payment_method_title( __( 'Pay later', 'wowrestro' ) );
			}
			$order->calculate_taxes();
			$order->calculate_totals();
			$order->save();
			$bound = WowRestro_Slot_Reservations::commit_order( $order );
			if ( is_wp_error( $bound ) ) {
				throw new WowRestro_REST_Exception( $bound );
			}
			if ( 'payment_link' === $payment_flow ) {
				$order->set_status( 'pending' );
				$order->save();
				self::reserve_stock( $order, $payment_ttl );
			} else {
				self::reserve_stock( $order, self::phone_payment_ttl() );
			}
			$order->update_meta_data( '_wowrestro_initialization_phase', 'committed' );
			$order->save_meta_data();
			$committed = true;
			self::schedule_phone_finalization( $order->get_id(), 5 );
			$committed_state = array(
				'created_at' => time(),
				'owner'      => $lock_state['owner'],
				'phase'      => 'committed',
				'order_id'   => $order->get_id(),
			);
			if ( ! self::compare_and_swap_option( $lock_key, $lock_state, $committed_state ) ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_idempotency_conflict', __( 'The phone order could not be committed safely. Retry with the same idempotency key.', 'wowrestro' ), array( 'status' => 409 ) ) );
			}
			$lock_state = $committed_state;
			$finalized  = self::finalize_phone_order( $order );
			if ( is_wp_error( $finalized ) ) {
				self::make_phone_finalization_resumable( $lock_key, $lock_state, $order );
				return self::error_status( $finalized, 409 );
			}
			$mapping   = array(
				'created_at' => time(),
				'order_id'   => $order->get_id(),
			);
			$finalized = self::compare_and_swap_option( $lock_key, $lock_state, $mapping );
			if ( ! $finalized ) {
				$current   = get_option( $lock_key, false );
				$finalized = is_array( $current ) && absint( $current['order_id'] ?? 0 ) === $order->get_id();
			}
			if ( ! $finalized ) {
				throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_idempotency_conflict', __( 'The phone order could not be finalized safely. Retry with the same idempotency key.', 'wowrestro' ), array( 'status' => 409 ) ) );
			}
			$order               = wc_get_order( $order->get_id() );
			$data                = self::prepare_order( $order, true );
			$data['payment_url'] = 'payment_link' === $payment_flow ? $order->get_checkout_payment_url() : '';
			return self::private_response( $legacy ? self::legacy_order( $data ) : $data, 201 );
		} catch ( WowRestro_REST_Exception $exception ) {
			if ( ! $committed ) {
				self::delete_option_if_value( $lock_key, $lock_state );
				self::rollback_phone_order( $order, $reservation );
			} else {
				self::make_phone_finalization_resumable( $lock_key, $lock_state, $order );
			}
			return self::error_status( $exception->get_wp_error(), 400 );
		} catch ( Throwable $exception ) {
			if ( ! $committed ) {
				self::delete_option_if_value( $lock_key, $lock_state );
				self::rollback_phone_order( $order, $reservation );
			} else {
				self::make_phone_finalization_resumable( $lock_key, $lock_state, $order );
			}
			return new WP_Error( 'wowrestro_phone_order', __( 'The phone order could not be created.', 'wowrestro' ), array( 'status' => 500 ) );
		}
	}

	public static function manual_order( $request ) {
		$response = self::phone_order( $request, true );
		if ( $response instanceof WP_REST_Response && $response->get_status() >= 200 && $response->get_status() < 300 ) {
			self::attach_legacy_sequence_cookie( $response, self::new_legacy_sequence() );
		}
		return $response;
	}

	/** Resume a durable phone order after an interrupted request. */
	public static function finalize_phone_order_event( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$result = self::finalize_phone_order( $order );
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		$attempts = absint( $order->get_meta( '_wowrestro_finalization_attempts' ) ) + 1;
		$order->update_meta_data( '_wowrestro_finalization_attempts', $attempts );
		$order->add_order_note( sprintf( __( 'WowRestro phone-order finalization retry %1$d failed: %2$s', 'wowrestro' ), $attempts, $result->get_error_message() ) );
		$order->save();
		if ( $attempts < 10 ) {
			self::schedule_phone_finalization( $order->get_id(), min( 300, 30 * $attempts ), true );
		}
	}

	/** Complete hookful status/stock work only after the idempotency mapping is durable. */
	private static function finalize_phone_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wowrestro_order_not_found', __( 'The phone order could not be reloaded.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		$order_id = $order->get_id();
		$locked   = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ( 'committed' !== $order->get_meta( '_wowrestro_initialization_phase' ) && 'yes' !== $order->get_meta( '_wowrestro_idempotency_complete' ) ) ) {
				return new WP_Error( 'wowrestro_phone_order_incomplete', __( 'The phone order has not reached its durable commit point.', 'wowrestro' ), array( 'status' => 409 ) );
			}
			$operational = WowRestro_Order_Statuses::current( $order );
			$terminal    = in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) || in_array( $operational, array( 'cancelled', 'completed' ), true );
			if ( $terminal ) {
				if ( 'terminal_review' !== $order->get_meta( '_wowrestro_initialization_phase' ) ) {
					$order->update_meta_data( '_wowrestro_initialization_phase', 'terminal_review' );
					$order->update_meta_data( '_wowrestro_idempotency_complete', 'yes' );
					$order->update_meta_data( '_wowrestro_terminal_initialization_reviewed', 'yes' );
					$order->delete_meta_data( '_wowrestro_initializing' );
					$order->add_order_note( __( 'WowRestro stopped interrupted phone-order finalization because the order had already reached a terminal state. The order was not returned to the live queue.', 'wowrestro' ) );
					$order->save();
					self::release_order( $order );
				}
				return true;
			}
			if ( 'yes' === $order->get_meta( '_wowrestro_idempotency_complete' ) ) {
				self::finish_phone_postprocessing( $order );
				return true;
			}

			$status = WowRestro_Order_Statuses::set_status( $order, 'new', __( 'Phone order created.', 'wowrestro' ) );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			$order = wc_get_order( $order_id );
			if ( 'pay_later' === $order->get_meta( '_wowrestro_payment_flow' ) ) {
				if ( 'pending' === $order->get_status() ) {
					$order->update_status( 'on-hold', __( 'Phone order is awaiting payment.', 'wowrestro' ) );
					$order = wc_get_order( $order_id );
				}
				if ( ! $order->is_paid() && ! in_array( $order->get_status(), array( 'on-hold', 'processing' ), true ) ) {
					return new WP_Error( 'wowrestro_phone_order_state', __( 'The phone order changed financial state before initialization completed.', 'wowrestro' ), array( 'status' => 409 ) );
				}
				wc_reduce_stock_levels( $order_id );
				if ( function_exists( 'wc_release_stock_for_order' ) ) {
					wc_release_stock_for_order( $order );
				}
			}

			$order = wc_get_order( $order_id );
			$order->update_meta_data( '_wowrestro_idempotency_complete', 'yes' );
			$order->update_meta_data( '_wowrestro_initialization_phase', 'complete' );
			$order->delete_meta_data( '_wowrestro_initializing' );
			$order->delete_meta_data( '_wowrestro_finalization_attempts' );
			$order->save_meta_data();
			self::finish_phone_postprocessing( $order );
			return true;
		} catch ( Throwable $exception ) {
			return new WP_Error( 'wowrestro_phone_order_finalize', __( 'The durable phone order still needs finalization.', 'wowrestro' ), array( 'status' => 503 ) );
		} finally {
			WowRestro_Order_Statuses::release_order_lock( $order_id );
		}
	}

	/** Schedule one recovery attempt; a running failed attempt may force its successor. */
	private static function schedule_phone_finalization( $order_id, $delay = 5, $force = false ) {
		$args = array( absint( $order_id ) );
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				if ( $force || ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'wowrestro_finalize_phone_order', $args, 'wowrestro' ) ) {
					as_schedule_single_action( time() + max( 1, absint( $delay ) ), 'wowrestro_finalize_phone_order', $args, 'wowrestro', false );
				}
				return;
			}
		} catch ( Throwable $exception ) {
			// Fall through to WordPress Cron.
		}
		if ( $force || ! wp_next_scheduled( 'wowrestro_finalize_phone_order', $args ) ) {
			wp_schedule_single_event( time() + max( 1, absint( $delay ) ), 'wowrestro_finalize_phone_order', $args );
		}
	}

	/** Leave a failed durable order mapped and immediately eligible for recovery. */
	private static function make_phone_finalization_resumable( $lock_key, $state, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$resumable = array(
			'created_at' => 0,
			'phase'      => 'committed',
			'order_id'   => $order->get_id(),
		);
		self::compare_and_swap_option( $lock_key, $state, $resumable );
		self::schedule_phone_finalization( $order->get_id(), 30 );
	}

	/** Idempotent expiry and optional invoice work for a complete payment-link order. */
	private static function finish_phone_postprocessing( $order ) {
		if ( ! $order instanceof WC_Order || 'payment_link' !== $order->get_meta( '_wowrestro_payment_flow' ) ) {
			return;
		}
		$expires = strtotime( (string) $order->get_meta( '_wowrestro_payment_expires_gmt' ) . ' UTC' );
		self::schedule_phone_expiry( $order->get_id(), max( 1, $expires ? $expires - time() : self::phone_payment_ttl() ) );
		if ( 'yes' === $order->get_meta( '_wowrestro_send_payment_link' ) && 'yes' !== $order->get_meta( '_wowrestro_payment_link_sent' ) && self::send_invoice( $order ) ) {
			$order->update_meta_data( '_wowrestro_payment_link_sent', 'yes' );
			$order->save_meta_data();
		}
	}

	public static function expire_phone_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'payment_link' !== $order->get_meta( '_wowrestro_payment_flow' ) || $order->is_paid() || 'pending' !== $order->get_status() ) {
			return;
		}
		$order_id = $order->get_id();
		$locked   = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			self::schedule_phone_expiry( $order_id, 60, true );
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || 'payment_link' !== $order->get_meta( '_wowrestro_payment_flow' ) || $order->is_paid() || 'pending' !== $order->get_status() ) {
				return;
			}
			$expires = strtotime( (string) $order->get_meta( '_wowrestro_payment_expires_gmt' ) . ' UTC' );
			if ( $expires && $expires > time() ) {
				self::schedule_phone_expiry( $order_id, $expires - time(), true );
				return;
			}
			WowRestro_Order_Statuses::set_status( $order, 'cancelled', __( 'Payment link expired.', 'wowrestro' ) );
			$order->update_status( 'cancelled', __( 'WowRestro payment link expired before payment.', 'wowrestro' ) );
			self::release_order( $order );
		} finally {
			WowRestro_Order_Statuses::release_order_lock( $order_id );
		}
	}

	public static function tracking_token( $request ) {
		$token  = sanitize_text_field( (string) $request['token'] );
		$orders = WowRestro_Order_Statuses::query_orders(
			array(
				'limit'      => 1,
				'status'     => self::woocommerce_statuses(),
				'meta_query' => array(
					array(
						'key'   => '_wowrestro_tracking_token',
						'value' => $token,
					),
				),
			)
		);
		$order  = $orders ? reset( $orders ) : false;
		if ( ! $order instanceof WC_Order || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) || ! hash_equals( (string) $token, (string) $order->get_meta( '_wowrestro_tracking_token' ) ) ) {
			return new WP_Error( 'wowrestro_tracking_not_found', __( 'Order not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		return self::public_response( self::tracking_data( $order ), 200, true );
	}

	public static function tracking( $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		$key   = wc_clean( $request['key'] );
		if ( ! $order || ! $order->get_meta( '_wowrestro_tracking_token' ) || ! hash_equals( (string) $order->get_order_key(), (string) $key ) || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) ) {
			return new WP_Error( 'wowrestro_tracking_not_found', __( 'Order not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		$data                = self::tracking_data( $order );
		$data['id']          = $order->get_id();
		$data['status']      = $order->get_status();
		$data['label']       = wc_get_order_status_name( $order->get_status() );
		$data['promised_at'] = $order->get_meta( '_wowrestro_promised_at' ) ?: $data['promised_at'];
		return self::public_response( $data, 200, true );
	}

	private static function tracking_data( $order ) {
		$status = WowRestro_Order_Statuses::current( $order );
		return array(
			'number'             => $order->get_order_number(),
			'operational_status' => $status,
			'operational_label'  => WowRestro_Order_Statuses::label( $status ),
			'payment_status'     => $order->get_status(),
			'payment_label'      => wc_get_order_status_name( $order->get_status() ),
			'payment_due'        => self::payment_due( $order ),
			'mode'               => $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ),
			'promised_at'        => $order->get_meta( '_wowrestro_promised_at_gmt' ) ?: $order->get_meta( '_wowrestro_promised_at' ),
			'timeline'           => self::history( $order, false ),
			'paid_at'            => $order->get_date_paid() ? $order->get_date_paid()->format( DATE_ATOM ) : '',
		);
	}

	private static function validate_lines( $raw_lines ) {
		if ( ! is_array( $raw_lines ) || ! $raw_lines ) {
			return new WP_Error( 'wowrestro_missing_items', __( 'Add at least one item.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$lines = array();
		foreach ( array_slice( $raw_lines, 0, 100 ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$product_id   = absint( $raw['product_id'] ?? 0 );
			$variation_id = absint( $raw['variation_id'] ?? 0 );
			$product      = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ( $product->is_type( 'variable' ) && ! $variation_id ) ) {
				return new WP_Error( 'wowrestro_invalid_item', __( 'One of the selected products cannot be purchased.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			if ( $product_id && $product_id !== $parent_id && $product_id !== $product->get_id() ) {
				return new WP_Error( 'wowrestro_invalid_variation', __( 'A selected variation does not belong to that product.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			if ( ! self::is_supported_phone_product( $product ) ) {
				return new WP_Error( 'wowrestro_unsupported_item', __( 'Phone orders can contain only supported WooCommerce products.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			$quantity = min( 999, max( 1, absint( $raw['quantity'] ?? 1 ) ) );
			if ( $product->managing_stock() && ! $product->backorders_allowed() && ! $product->has_enough_stock( $quantity ) ) {
				return new WP_Error( 'wowrestro_out_of_stock', sprintf( __( '%s does not have enough stock.', 'wowrestro' ), $product->get_name() ), array( 'status' => 409 ) );
			}
			$modifiers = array();
			if ( class_exists( 'WowRestro_Modifiers' ) ) {
				$modifiers = WowRestro_Modifiers::validate_selection( $parent_id, $raw['modifiers'] ?? array() );
				if ( is_wp_error( $modifiers ) ) {
					return $modifiers;
				}
			} elseif ( ! empty( $raw['modifiers'] ) ) {
				return new WP_Error( 'wowrestro_modifiers_unavailable', __( 'Product modifiers are temporarily unavailable.', 'wowrestro' ), array( 'status' => 503 ) );
			}
			$note = sanitize_textarea_field( (string) ( $raw['note'] ?? '' ) );
			if ( self::text_length( $note ) > 500 ) {
				return new WP_Error( 'wowrestro_item_note_length', __( 'Item instructions cannot exceed 500 characters.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			$lines[] = array(
				'product'        => $product,
				'quantity'       => $quantity,
				'modifiers'      => $modifiers,
				'modifier_total' => class_exists( 'WowRestro_Modifiers' ) ? WowRestro_Modifiers::snapshot_total( $modifiers ) : 0,
				'note'           => $note,
			);
		}
		return $lines ?: new WP_Error( 'wowrestro_invalid_items', __( 'No valid products were supplied.', 'wowrestro' ), array( 'status' => 400 ) );
	}

	/** Whether a staff-created phone order may contain this WooCommerce product. */
	private static function is_supported_phone_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		return $product instanceof WC_Product && in_array( $product->get_type(), array( 'simple', 'variable' ), true );
	}

	private static function addresses_from_request( $request ) {
		$billing  = self::sanitize_address( $request->get_param( 'billing' ) );
		$shipping = self::sanitize_address( $request->get_param( 'shipping' ) );
		$customer = $request->get_param( 'customer' );
		$name     = is_array( $customer ) ? '' : self::limit_text( $customer, 100 );
		if ( is_array( $customer ) ) {
			$billing = self::merge_address( $billing, self::sanitize_address( $customer ) );
		}
		if ( $name && ! $billing['first_name'] ) {
			$parts                 = preg_split( '/\s+/', $name, 2 );
			$billing['first_name'] = $parts[0];
			$billing['last_name']  = $parts[1] ?? '';
		}
		$billing['phone'] = $billing['phone'] ?: sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$billing['email'] = $billing['email'] ?: sanitize_email( (string) $request->get_param( 'email' ) );
		$postcode         = sanitize_text_field( (string) $request->get_param( 'postcode' ) );
		if ( $postcode && ! $shipping['postcode'] ) {
			$shipping['postcode'] = $postcode;
		}
		$shipping = self::merge_address( $shipping, $billing );
		return array(
			'billing'  => $billing,
			'shipping' => $shipping,
		);
	}

	private static function merge_address( $primary, $fallback ) {
		foreach ( $fallback as $key => $value ) {
			if ( empty( $primary[ $key ] ) ) {
				$primary[ $key ] = $value;
			}
		}
		return $primary;
	}

	private static function sanitize_address( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array_fill_keys( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ), '' );
		foreach ( $out as $key => $unused ) {
			$out[ $key ] = 'email' === $key ? sanitize_email( $raw[ $key ] ?? '' ) : sanitize_text_field( $raw[ $key ] ?? '' );
		}
		if ( ! $out['country'] && function_exists( 'WC' ) && WC()->countries ) {
			$out['country'] = WC()->countries->get_base_country();
		}
		return $out;
	}

	private static function apply_addresses( $order, $addresses ) {
		$order->set_address( $addresses['billing'], 'billing' );
		$order->set_address( $addresses['shipping'], 'shipping' );
	}

	private static function shipping_rates( $lines, $destination ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$contents = array();
		$cost     = 0.0;
		foreach ( $lines as $index => $line ) {
			$line_total         = isset( $line['line_total'] ) ? (float) $line['line_total'] : ( (float) $line['product']->get_price() + (float) $line['modifier_total'] ) * $line['quantity'];
			$cost              += $line_total;
			$contents[ $index ] = array(
				'data'              => $line['product'],
				'product_id'        => $line['product']->get_id(),
				'variation_id'      => $line['product']->is_type( 'variation' ) ? $line['product']->get_id() : 0,
				'quantity'          => $line['quantity'],
				'line_total'        => $line_total,
				'line_subtotal'     => $line_total,
				'line_tax'          => 0,
				'line_subtotal_tax' => 0,
			);
		}
		$package = array(
			'contents'        => $contents,
			'contents_cost'   => $cost,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => 0 ),
			'destination'     => $destination,
		);
		$zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
		$rates   = array();
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			foreach ( (array) $method->get_rates_for_package( $package ) as $rate ) {
				if ( ! $rate instanceof WC_Shipping_Rate ) {
					continue;
				}
				$rates[] = array(
					'id'         => $rate->get_id(),
					'label'      => $rate->get_label(),
					'cost'       => (float) $rate->get_cost(),
					'cost_label' => self::money_text( wc_price( $rate->get_cost() ) ),
					'mode'       => class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::mode_for_rate( $rate->get_id(), $rate ) : ( 'local_pickup' === $rate->get_method_id() ? 'pickup' : 'delivery' ),
					'rate'       => $rate,
				);
			}
		}
		return $rates;
	}

	private static function public_rates( $rates ) {
		return array_map(
			function ( $rate ) {
				unset( $rate['rate'] );
				return $rate;
			},
			$rates
		);
	}

	private static function find_rate( $rates, $rate_id ) {
		foreach ( $rates as $rate ) {
			if ( hash_equals( (string) $rate['id'], (string) $rate_id ) ) {
				return $rate;
			}
		}
		return null;
	}

	private static function order_lines_for_shipping( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$lines[] = array(
				'product'        => $product,
				'quantity'       => max( 1, absint( $item->get_quantity() ) ),
				'modifier_total' => 0,
				'line_total'     => (float) $item->get_total(),
			);
		}
		return $lines;
	}

	private static function order_shipping_destination( $order ) {
		$destination = array();
		foreach ( array( 'country', 'state', 'postcode', 'city', 'address_1', 'address_2' ) as $field ) {
			$getter                = 'get_shipping_' . $field;
			$billing_getter        = 'get_billing_' . $field;
			$destination[ $field ] = $order->$getter() ?: $order->$billing_getter();
		}
		if ( ! $destination['country'] && function_exists( 'WC' ) && WC()->countries ) {
			$destination['country'] = WC()->countries->get_base_country();
		}
		return $destination;
	}

	private static function first_rate_for_mode( $rates, $mode ) {
		foreach ( $rates as $rate ) {
			if ( $mode === $rate['mode'] ) {
				return $rate;
			}
		}
		return null;
	}

	private static function add_shipping_item( $order, $selected ) {
		$rate = $selected['rate'];
		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( $rate->get_label() );
		$item->set_method_id( $rate->get_method_id() );
		$item->set_instance_id( $rate->get_instance_id() );
		$item->set_total( $rate->get_cost() );
		$item->set_taxes( $rate->get_taxes() );
		$order->add_item( $item );
	}

	private static function replace_order_shipping_rate( $order, $selected ) {
		$items = $order->get_items( 'shipping' );
		if ( count( $items ) > 1 ) {
			return new WP_Error( 'wowrestro_multiple_packages', __( 'WowRestro can reschedule only one-package orders.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		$item   = $items ? reset( $items ) : new WC_Order_Item_Shipping();
		$backup = array(
			'added'        => ! $items,
			'item_id'      => $item->get_id(),
			'method_title' => $item->get_method_title(),
			'method_id'    => $item->get_method_id(),
			'instance_id'  => $item->get_instance_id(),
			'total'        => $item->get_total(),
			'taxes'        => $item->get_taxes(),
		);
		$rate   = $selected['rate'];
		try {
			$item->set_method_title( $rate->get_label() );
			$item->set_method_id( $rate->get_method_id() );
			$item->set_instance_id( $rate->get_instance_id() );
			$item->set_total( $rate->get_cost() );
			$item->set_taxes( $rate->get_taxes() );
			if ( $backup['added'] ) {
				$order->add_item( $item );
			}
			$order->calculate_taxes();
			$order->calculate_totals();
			$order->save();
			$backup['item_id'] = $item->get_id();
			return $backup;
		} catch ( Throwable $exception ) {
			$backup['item_id'] = $item->get_id();
			self::restore_order_shipping_rate( $order, $backup );
			return new WP_Error( 'wowrestro_shipping_update', __( 'The order shipping method could not be updated.', 'wowrestro' ), array( 'status' => 500 ) );
		}
	}

	private static function restore_order_shipping_rate( $order, $backup ) {
		try {
			if ( ! empty( $backup['added'] ) ) {
				$order->remove_item( absint( $backup['item_id'] ) );
			} else {
				$item = $order->get_item( absint( $backup['item_id'] ) );
				if ( $item instanceof WC_Order_Item_Shipping ) {
					$item->set_method_title( $backup['method_title'] );
					$item->set_method_id( $backup['method_id'] );
					$item->set_instance_id( $backup['instance_id'] );
					$item->set_total( $backup['total'] );
					$item->set_taxes( $backup['taxes'] );
				}
			}
			$order->calculate_taxes();
			$order->calculate_totals();
			$order->save();
			return true;
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	private static function line_subtotal( $lines ) {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$total += ( (float) $line['product']->get_price() + (float) $line['modifier_total'] ) * $line['quantity'];
		}
		return $total;
	}

	private static function payment_due( $order ) {
		return ! $order->is_paid() && in_array( $order->get_status(), array( 'pending', 'on-hold', 'failed' ), true );
	}

	private static function rollback_phone_order( $order, $reservation ) {
		if ( is_array( $reservation ) && ! empty( $reservation['reservation_token'] ) ) {
			WowRestro_Slot_Reservations::release( '', '', $reservation['reservation_token'] );
		}
		if ( $order instanceof WC_Order ) {
			try {
				$data_store    = $order->get_data_store();
				$stock_reduced = method_exists( $data_store, 'get_stock_reduced' ) ? $data_store->get_stock_reduced( $order->get_id() ) : wc_string_to_bool( $order->get_meta( '_order_stock_reduced' ) );
				if ( $stock_reduced && function_exists( 'wc_increase_stock_levels' ) ) {
					wc_increase_stock_levels( $order->get_id() );
				}
				if ( function_exists( 'wc_release_stock_for_order' ) ) {
					wc_release_stock_for_order( $order );
				}
			} catch ( Throwable $exception ) {
				// Continue deleting the incomplete order; Woo stock tools are idempotent.
			}
			$order->delete( true );
		}
	}

	private static function release_order( $order ) {
		if ( method_exists( 'WowRestro_Slot_Reservations', 'release_order' ) ) {
			WowRestro_Slot_Reservations::release_order( $order );
		}
	}

	private static function reserve_stock( $order, $ttl ) {
		if ( ! function_exists( 'wc_reserve_stock_for_order' ) ) {
			throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_stock_reservation_unavailable', __( 'Stock reservation is temporarily unavailable.', 'wowrestro' ), array( 'status' => 503 ) ) );
		}
		$minutes  = max( 1, (int) ceil( absint( $ttl ) / MINUTE_IN_SECONDS ) );
		$callback = function ( $current, $held_order ) use ( $minutes, $order ) {
			$held_order = is_numeric( $held_order ) ? wc_get_order( $held_order ) : $held_order;
			return $held_order instanceof WC_Order && $held_order->get_id() === $order->get_id() ? $minutes : $current;
		};
		add_filter( 'woocommerce_order_hold_stock_minutes', $callback, 999, 2 );
		try {
			wc_reserve_stock_for_order( $order );
		} catch ( Throwable $exception ) {
			throw new WowRestro_REST_Exception( new WP_Error( 'wowrestro_stock_reservation_failed', __( 'One or more items no longer have enough stock.', 'wowrestro' ), array( 'status' => 409 ) ) );
		} finally {
			remove_filter( 'woocommerce_order_hold_stock_minutes', $callback, 999 );
		}
	}

	private static function schedule_phone_expiry( $order_id, $ttl, $force = false ) {
		$args      = array( absint( $order_id ) );
		$scheduled = false;
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				if ( $force || ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'wowrestro_expire_phone_order', $args, 'wowrestro' ) ) {
					$scheduled = (bool) as_schedule_single_action( time() + absint( $ttl ), 'wowrestro_expire_phone_order', $args, 'wowrestro', false );
				} else {
					$scheduled = true;
				}
			}
		} catch ( Throwable $exception ) {
			$scheduled = false;
		}
		if ( ! $scheduled && ( $force || ! wp_next_scheduled( 'wowrestro_expire_phone_order', $args ) ) ) {
			wp_schedule_single_event( time() + absint( $ttl ), 'wowrestro_expire_phone_order', $args );
		}
	}

	private static function phone_payment_ttl() {
		return max( 300, absint( apply_filters( 'wowrestro_phone_payment_ttl', self::PHONE_PAYMENT_TTL ) ) );
	}

	private static function send_invoice( $order ) {
		if ( ! is_email( $order->get_billing_email() ) || ! function_exists( 'WC' ) ) {
			return false;
		}
		try {
			$emails = WC()->mailer()->get_emails();
			if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
				$emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
				return true;
			}
		} catch ( Throwable $exception ) {
			// The durable order and payment URL remain available for a manager to resend.
		}
		return false;
	}

	private static function restaurant_order( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) ) {
			return new WP_Error( 'wowrestro_order_not_found', __( 'Order not found.', 'wowrestro' ), array( 'status' => 404 ) );
		}
		return $order;
	}

	private static function find_idempotent_order( $digest, $user_id, $complete_only = true ) {
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => '_wowrestro_idempotency_key',
				'value' => sanitize_text_field( $digest ),
			),
			array(
				'key'   => '_wowrestro_created_by',
				'value' => absint( $user_id ),
				'type'  => 'NUMERIC',
			),
		);
		if ( $complete_only ) {
			$meta_query[] = array(
				'key'   => '_wowrestro_idempotency_complete',
				'value' => 'yes',
			);
		}
		$orders = WowRestro_Order_Statuses::query_orders(
			array(
				'limit'      => 10,
				'status'     => self::woocommerce_statuses(),
				'meta_query' => $meta_query,
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) ) {
				continue;
			}
			if ( ! hash_equals( (string) sanitize_text_field( $digest ), (string) $order->get_meta( '_wowrestro_idempotency_key' ) ) || absint( $order->get_meta( '_wowrestro_created_by' ) ) !== absint( $user_id ) || ( $complete_only && 'yes' !== $order->get_meta( '_wowrestro_idempotency_complete' ) ) ) {
				continue;
			}
			return $order;
		}
		return false;
	}

	private static function is_menu_item( $product ) {
		if ( class_exists( 'WowRestro_Products' ) ) {
			return WowRestro_Products::is_menu_item( $product );
		}
		$product = is_numeric( $product ) ? wc_get_product( absint( $product ) ) : $product;
		return $product instanceof WC_Product && 'yes' === $product->get_meta( '_wowrestro_menu_item', true );
	}

	private static function history( $order, $include_reason ) {
		$history = $order->get_meta( WowRestro_Order_Statuses::META_HISTORY );
		$data    = array();
		foreach ( is_array( $history ) ? $history : array() as $event ) {
			$status = WowRestro_Order_Statuses::normalize( $event['status'] ?? '' );
			$row    = array(
				'status' => $status,
				'label'  => WowRestro_Order_Statuses::label( $status ),
				'at_gmt' => sanitize_text_field( $event['at_gmt'] ?? '' ),
			);
			if ( $include_reason ) {
				$row['reason']   = sanitize_text_field( $event['reason'] ?? '' );
				$row['user_id']  = absint( $event['user_id'] ?? 0 );
				$row['override'] = ! empty( $event['override'] );
			}
			$data[] = $row;
		}
		return $data;
	}

	private static function is_late( $promise, $status ) {
		if ( ! $promise || in_array( $status, array( 'ready', 'out_for_delivery', 'completed', 'cancelled' ), true ) ) {
			return false;
		}
		try {
			$timezone = false !== strpos( $promise, 'T' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$when     = new DateTimeImmutable( $promise, $timezone );
			$grace    = absint( WowRestro_Fulfillment::settings()['late_grace_minutes'] ?? 5 );
			return time() > $when->modify( '+' . $grace . ' minutes' )->getTimestamp();
		} catch ( Exception $exception ) {
			return false;
		}
	}

	private static function date_label( $value, $format = '' ) {
		if ( ! $value ) {
			return '';
		}
		try {
			$timezone = false !== strpos( $value, 'T' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$date     = new DateTimeImmutable( $value, $timezone );
			return wp_date( $format ?: get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp(), wp_timezone() );
		} catch ( Exception $exception ) {
			return '';
		}
	}

	private static function order_revision( $order ) {
		$modified = $order->get_date_modified();
		return md5( $order->get_id() . '|' . WowRestro_Order_Statuses::current( $order ) . '|' . ( $modified ? $modified->getTimestamp() : 0 ) );
	}

	private static function compare_and_swap_option( $key, $expected, $replacement ) {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				$key,
				maybe_serialize( $expected )
			)
		);
		self::clear_option_cache( $key );
		return 1 === $updated;
	}

	private static function delete_option_if_value( $key, $expected ) {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize( $expected )
			)
		);
		self::clear_option_cache( $key );
		return 1 === $deleted;
	}

	private static function clear_option_cache( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function check_revision( $order, $revision ) {
		if ( $revision && ! hash_equals( self::order_revision( $order ), sanitize_text_field( (string) $revision ) ) ) {
			return new WP_Error( 'wowrestro_stale_order', __( 'This order changed since it was loaded. Refresh and try again.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		return true;
	}

	private static function sort_orders( $left, $right ) {
		$left_new  = 'new' === WowRestro_Order_Statuses::current( $left );
		$right_new = 'new' === WowRestro_Order_Statuses::current( $right );
		if ( $left_new !== $right_new ) {
			return $left_new ? -1 : 1;
		}
		if ( $left_new ) {
			$left_created  = $left->get_date_created();
			$right_created = $right->get_date_created();
			$left_time     = $left_created ? $left_created->getTimestamp() : 0;
			$right_time    = $right_created ? $right_created->getTimestamp() : 0;
			return $left_time === $right_time ? $right->get_id() <=> $left->get_id() : $right_time <=> $left_time;
		}
		$left_time  = strtotime( (string) ( $left->get_meta( '_wowrestro_promised_at_gmt' ) ?: $left->get_meta( '_wowrestro_promised_at' ) ) ) ?: PHP_INT_MAX;
		$right_time = strtotime( (string) ( $right->get_meta( '_wowrestro_promised_at_gmt' ) ?: $right->get_meta( '_wowrestro_promised_at' ) ) ) ?: PHP_INT_MAX;
		return $left_time === $right_time ? $left->get_id() <=> $right->get_id() : $left_time <=> $right_time;
	}

	private static function paused() {
		$settings = WowRestro_Fulfillment::settings();
		return 'yes' === ( $settings['orders_paused'] ?? 'no' );
	}

	private static function woocommerce_statuses() {
		$statuses = array_map(
			function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			array_keys( wc_get_order_statuses() )
		);
		return array_values( array_unique( array_merge( $statuses, array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded', 'wr-new', 'wr-accepted', 'wr-preparing', 'wr-ready', 'wr-out-for-delivery' ) ) ) );
	}

	private static function cursor_page( $cursor ) {
		if ( ! $cursor ) {
			return 1;
		}
		$decoded = json_decode( base64_decode( strtr( (string) $cursor, '-_', '+/' ), true ), true );
		return is_array( $decoded ) && ! empty( $decoded['page'] ) ? max( 1, absint( $decoded['page'] ) ) : 1;
	}

	private static function encode_cursor( $page ) {
		return rtrim( strtr( base64_encode( wp_json_encode( array( 'page' => absint( $page ) ) ) ), '+/', '-_' ), '=' );
	}

	/** Give the unchanged 0.2 board a per-browser, retry-safe request identity. */
	private static function legacy_idempotency_key( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		unset( $payload['idempotency_key'], $payload['_locale'] );
		$page_id = self::limit_text( $request->get_param( 'legacy_page_id' ), 80 );
		if ( $page_id ) {
			$payload['legacy_page_id'] = $page_id;
		}
		$payload     = self::canonicalize_idempotency_value( $payload );
		$fingerprint = hash( 'sha256', (string) wp_json_encode( $payload ) );
		return 'legacy-' . hash( 'sha256', self::legacy_sequence() . '|' . $fingerprint );
	}

	/** Prime the HttpOnly sequence before the existing board submits an order. */
	public static function prime_legacy_manual_sequence() {
		if ( headers_sent() || ! self::can_create() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'wowrestro-orders' !== $page || self::legacy_cookie_sequence() ) {
			return;
		}
		$value = self::new_legacy_sequence();
		setcookie(
			self::LEGACY_SEQUENCE_COOKIE,
			$value,
			array(
				'expires'  => time() + MONTH_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::LEGACY_SEQUENCE_COOKIE ] = $value;
	}

	private static function legacy_sequence() {
		$sequence = self::legacy_cookie_sequence();
		if ( $sequence ) {
			return $sequence;
		}
		return substr( hash_hmac( 'sha256', 'wowrestro-manual-' . get_current_user_id(), wp_salt( 'nonce' ) ), 0, 40 );
	}

	private static function legacy_cookie_sequence() {
		$value = isset( $_COOKIE[ self::LEGACY_SEQUENCE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::LEGACY_SEQUENCE_COOKIE ] ) ) : '';
		return preg_match( '/^[A-Za-z0-9_-]{32,80}$/', $value ) ? $value : '';
	}

	private static function new_legacy_sequence() {
		try {
			return bin2hex( random_bytes( 24 ) );
		} catch ( Exception $exception ) {
			return str_replace( '-', '', wp_generate_uuid4() );
		}
	}

	private static function attach_legacy_sequence_cookie( $response, $value ) {
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? '; Domain=' . COOKIE_DOMAIN : '';
		$secure = is_ssl() ? '; Secure' : '';
		$cookie = rawurlencode( self::LEGACY_SEQUENCE_COOKIE ) . '=' . rawurlencode( $value )
			. '; Expires=' . gmdate( 'D, d M Y H:i:s', time() + MONTH_IN_SECONDS ) . ' GMT; Max-Age=' . MONTH_IN_SECONDS
			. '; Path=' . $path . $domain . $secure . '; HttpOnly; SameSite=Lax';
		$response->header( 'Set-Cookie', $cookie );
	}

	private static function canonicalize_idempotency_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$keys       = array_keys( $value );
		$sequential = $value && $keys === range( 0, count( $value ) - 1 );
		if ( ! $sequential ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_idempotency_value( $item );
		}
		return $value;
	}

	private static function limit_text( $value, $length ) {
		$value = sanitize_textarea_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private static function text_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}

	private static function error_status( $error, $default ) {
		if ( ! is_wp_error( $error ) ) {
			return $error;
		}
		$code = $error->get_error_code();
		$data = $error->get_error_data( $code );
		if ( is_array( $data ) && ! empty( $data['status'] ) ) {
			return $error;
		}
		$status = $default;
		if ( false !== strpos( $code, 'storage' ) || false !== strpos( $code, 'unavailable' ) ) {
			$status = 503;
		} elseif ( false !== strpos( $code, 'slot' ) || false !== strpos( $code, 'capacity' ) || false !== strpos( $code, 'stock' ) ) {
			$status = 409;
		}
		$error->add_data( array( 'status' => $status ), $code );
		return $error;
	}

	private static function public_response( $data, $status = 200, $no_store = false ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', $no_store ? 'no-store, private' : 'public, max-age=30' );
		return $response;
	}

	private static function private_response( $data, $status = 200, $etag = '' ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Vary', 'Cookie, X-WP-Nonce' );
		if ( $etag ) {
			$response->header( 'ETag', $etag );
		}
		return $response;
	}
}

/**
 * Internal exception carrying a client-safe WP_Error through cleanup paths.
 */
final class WowRestro_REST_Exception extends Exception {
	/** @var WP_Error */
	private $wp_error;

	public function __construct( $wp_error ) {
		$this->wp_error = $wp_error instanceof WP_Error ? $wp_error : new WP_Error( 'wowrestro_phone_order', __( 'The phone order could not be created.', 'wowrestro' ) );
		parent::__construct( $this->wp_error->get_error_message() );
	}

	public function get_wp_error() {
		return $this->wp_error;
	}
}
