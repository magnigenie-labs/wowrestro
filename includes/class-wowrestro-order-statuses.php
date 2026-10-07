<?php
/**
 * Restaurant workflow state, kept separate from WooCommerce payment state.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Order_Statuses {
	const META_STATUS  = '_wowrestro_operational_status';
	const META_HISTORY = '_wowrestro_operational_history';

	/** @var array<string,string> */
	private static $labels = array();

	/** @var array<string,string> */
	private static $legacy_labels = array();

	/** @var array<int,array<string,mixed>> Re-entrant locks held by this request. */
	private static $held_locks = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_labels' ), 1 );
		add_action( 'init', array( __CLASS__, 'install_roles' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_legacy_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_legacy_statuses' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'ensure_order' ), 30 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'ensure_order' ), 30 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'mark_new' ), 50 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'mark_new' ), 50 );
		add_action( 'woocommerce_order_status_pending', array( __CLASS__, 'mark_new' ), 20 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'mark_new' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'mark_new' ), 20 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'mark_new' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'mark_financially_closed' ), 20, 2 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'mark_financially_closed' ), 20, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'mark_financially_closed' ), 20, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'mark_financially_closed' ), 20, 2 );
		add_action( 'wowrestro_reconcile_operational_status', array( __CLASS__, 'reconcile_operational_status' ), 10, 3 );
	}

	/** Load translated workflow labels after WordPress initializes locales. */
	public static function load_labels() {
		self::$labels        = array(
			'new'              => __( 'New', 'wowrestro' ),
			'accepted'         => __( 'Accepted', 'wowrestro' ),
			'preparing'        => __( 'Preparing', 'wowrestro' ),
			'ready'            => __( 'Ready', 'wowrestro' ),
			'out_for_delivery' => __( 'Out for delivery', 'wowrestro' ),
			'completed'        => __( 'Completed', 'wowrestro' ),
			'cancelled'        => __( 'Cancelled', 'wowrestro' ),
		);
		self::$legacy_labels = array(
			'wc-wr-new'              => __( 'New', 'wowrestro' ),
			'wc-wr-accepted'         => __( 'Accepted', 'wowrestro' ),
			'wc-wr-preparing'        => __( 'Preparing', 'wowrestro' ),
			'wc-wr-ready'            => __( 'Ready', 'wowrestro' ),
			'wc-wr-out-for-delivery' => __( 'Out for delivery', 'wowrestro' ),
		);
	}

	/**
	 * Install three least-privilege restaurant roles and augment trusted Woo roles.
	 */
	public static function install_roles() {
		$staff   = get_role( 'wowrestro_staff' );
		$manager = get_role( 'wowrestro_manager' );
		$waiter  = get_role( 'wowrestro_waiter' );
		if (
			'7' === get_option( 'wowrestro_roles_version' ) &&
			$staff && $staff->has_cap( 'wowrestro_view_orders' ) && $staff->has_cap( 'wowrestro_operate_orders' ) &&
			$manager && $manager->has_cap( 'wowrestro_manage' ) &&
			$waiter && $waiter->has_cap( 'wowrestro_create_phone_orders' )
		) {
			return;
		}

		$staff_caps   = array(
			'read'                     => true,
			'view_admin_dashboard'     => true,
			'wowrestro_view_orders'    => true,
			'wowrestro_operate_orders' => true,
			'wowrestro_print_orders'   => true,
		);
		$manager_caps = array_merge(
			$staff_caps,
			array(
				'wowrestro_manage'              => true,
				'wowrestro_manage_menu'         => true,
				'wowrestro_manage_modifiers'    => true,
				'wowrestro_manage_operations'   => true,
				'wowrestro_create_phone_orders' => true,
				'wowrestro_manage_payments'     => true,
				'edit_products'                 => true,
				'edit_others_products'          => true,
				'edit_published_products'       => true,
				'edit_private_products'         => true,
				'publish_products'              => true,
				'read_private_products'         => true,
				'upload_files'                  => true,
				'manage_product_terms'          => true,
				'edit_product_terms'            => true,
				'delete_product_terms'          => true,
				'assign_product_terms'          => true,
			)
		);
		$waiter_caps  = array_merge(
			$staff_caps,
			array(
				'wowrestro_create_phone_orders' => true,
				'wowrestro_manage_tables'       => true,
			)
		);

		if ( ! $staff ) {
			$staff = add_role( 'wowrestro_staff', __( 'WowRestro Staff', 'wowrestro' ), $staff_caps );
		}
		if ( ! $manager ) {
			$manager = add_role( 'wowrestro_manager', __( 'WowRestro Manager', 'wowrestro' ), $manager_caps );
		}
		if ( ! $waiter ) {
			$waiter = add_role( 'wowrestro_waiter', __( 'WowRestro Waiter', 'wowrestro' ), $waiter_caps );
		}
		foreach ( array( array( $staff, $staff_caps ), array( $manager, $manager_caps ), array( $waiter, $waiter_caps ) ) as $role_data ) {
			$role = $role_data[0];
			if ( ! $role ) {
				continue;
			}
			foreach ( $role_data[1] as $capability => $grant ) {
				$role->add_cap( $capability, $grant );
			}
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $manager_caps as $capability => $grant ) {
					$role->add_cap( $capability, $grant );
				}
			}
		}
		update_option( 'wowrestro_roles_version', '7', false );
	}

	/** Register historical financial statuses for migration/readability only. */
	public static function register_legacy_statuses() {
		if ( ! self::$legacy_labels ) {
			self::load_labels();
		}
		foreach ( self::$legacy_labels as $status => $label ) {
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'wowrestro' ),
				)
			);
		}
	}

	/** Keep historical orders readable without using these statuses for new work. */
	public static function add_legacy_statuses( $statuses ) {
		if ( ! self::$legacy_labels && did_action( 'init' ) ) {
			self::load_labels();
		}
		$output = array();
		foreach ( $statuses as $key => $label ) {
			$output[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				foreach ( self::$legacy_labels as $legacy_key => $legacy_label ) {
					$output[ $legacy_key ] = $legacy_label;
				}
			}
		}
		return $output;
	}

	/**
	 * Mark a WooCommerce order as belonging to the restaurant without changing
	 * its financial status.
	 *
	 * @param WC_Order|int $order Order or ID.
	 * @return WC_Order|false
	 */
	public static function ensure_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$mode = sanitize_key( $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ) ?: self::woocommerce_mode( $order ) );
		$order->update_meta_data( '_wowrestro_order', 'yes' );
		$order->update_meta_data( '_wowrestro_fulfillment', $mode );
		$order->update_meta_data( '_wowrestro_mode', $mode );
		if ( ! $order->get_meta( '_wowrestro_source' ) ) {
			$order->update_meta_data( '_wowrestro_source', 'woocommerce' );
		}
		if ( ! $order->get_meta( '_wowrestro_tracking_token' ) ) {
			$order->update_meta_data( '_wowrestro_tracking_token', self::tracking_token() );
		}
		$current = self::normalize( $order->get_meta( self::META_STATUS ) );
		if ( $current ) {
			$order->update_meta_data( self::META_STATUS, $current );
		}
		$order->save();
		return $order;
	}

	/**
	 * Import active native WooCommerce orders into the restaurant queue.
	 *
	 * WooCommerce remains authoritative; WowRestro only adds its operational
	 * metadata so staff can manage those same orders from the live board.
	 *
	 * @return WC_Order[]
	 */
	public static function sync_active_orders() {
		$orders = wc_get_orders(
			array(
				'limit'   => -1,
				'status'  => array( 'pending', 'on-hold', 'processing' ),
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || 'yes' === $order->get_meta( '_wowrestro_initializing' ) ) {
				continue;
			}
			if ( self::is_restaurant_order( $order ) && self::current( $order ) ) {
				continue;
			}
			self::mark_new( $order );
		}
		return array_values( array_filter( $orders, static function ( $order ) { return $order instanceof WC_Order; } ) );
	}

	/** Infer pickup or delivery from WooCommerce shipping items. */
	private static function woocommerce_mode( $order ) {
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			if ( method_exists( $shipping, 'get_method_id' ) && 'local_pickup' !== $shipping->get_method_id() ) {
				return 'delivery';
			}
		}
		return 'pickup';
	}

	/**
	 * Put a paid or explicitly accepted unpaid order into the live queue.
	 *
	 * @param WC_Order|int $order Order or ID.
	 */
	public static function mark_new( $order, $attempt = 0 ) {
		$order = self::ensure_order( $order );
		if ( ! $order ) {
			return false;
		}
		$current = self::current( $order );
		if ( in_array( $current, array( 'cancelled', 'completed' ), true ) && $order->is_paid() && 'pending' === $order->get_meta( '_wowrestro_terminal_payment_review' ) ) {
			$order->delete_meta_data( '_wowrestro_payment_retry_reserved' );
			$order->delete_meta_data( '_wowrestro_terminal_payment_review' );
			$order->update_meta_data( '_wowrestro_terminal_payment_reviewed', 'yes' );
			$order->add_order_note( __( 'Payment was recorded after the restaurant workflow had closed. Capacity was not restored and the operational status was preserved; review payment/refund handling manually.', 'wowrestro' ) );
			$order->save();
			return true;
		}
		if ( $order->is_paid() && 'yes' === $order->get_meta( '_wowrestro_payment_retry_reserved' ) ) {
			if ( 'cancelled' === $current ) {
				$result = self::set_status( $order, 'new', __( 'Payment retry succeeded and capacity was restored.', 'wowrestro' ), true );
				if ( is_wp_error( $result ) ) {
					self::schedule_operational_reconciliation( $order->get_id(), 'payment', $attempt, $result );
					return $result;
				}
			}
			$order->delete_meta_data( '_wowrestro_payment_retry_reserved' );
			$order->save_meta_data();
			return true;
		}
		if ( $current ) {
			return true;
		}
		$result = self::set_status( $order, 'new', __( 'Order added to the WowRestro queue.', 'wowrestro' ) );
		if ( is_wp_error( $result ) ) {
			self::schedule_operational_reconciliation( $order->get_id(), 'payment', $attempt, $result );
		}
		return $result;
	}

	/**
	 * Reflect a terminal Woo payment state in the restaurant workflow.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order.
	 */
	public static function mark_financially_closed( $order_id, $order = null, $attempt = 0 ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! self::is_restaurant_order( $order ) || in_array( self::current( $order ), array( 'completed', 'cancelled' ), true ) ) {
			return true;
		}
		$result = self::set_status( $order, 'cancelled', __( 'WooCommerce payment state closed the order.', 'wowrestro' ), true );
		if ( is_wp_error( $result ) ) {
			self::schedule_operational_reconciliation( $order->get_id(), 'closed', $attempt, $result );
		}
		return $result;
	}

	/** Re-read Woo's authoritative state after a lock collision and converge. */
	public static function reconcile_operational_status( $order_id, $target = '', $attempt = 1 ) {
		unset( $target );
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || ! self::is_restaurant_order( $order ) ) {
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			self::mark_financially_closed( $order->get_id(), $order, absint( $attempt ) );
			return;
		}
		if ( $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
			self::mark_new( $order, absint( $attempt ) );
		}
	}

	/** Queue a bounded retry only for the cross-request busy-lock condition. */
	private static function schedule_operational_reconciliation( $order_id, $target, $attempt, $error ) {
		if ( ! is_wp_error( $error ) || 'wowrestro_order_busy' !== $error->get_error_code() ) {
			return;
		}
		$attempt = absint( $attempt ) + 1;
		if ( $attempt > 5 ) {
			$order = wc_get_order( absint( $order_id ) );
			if ( $order instanceof WC_Order ) {
				$order->add_order_note( __( 'WowRestro could not reconcile the operational status after five lock retries. Review this order manually.', 'wowrestro' ) );
			}
			return;
		}
		$args = array( absint( $order_id ), sanitize_key( $target ), $attempt );
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + min( 60, 5 * $attempt ), 'wowrestro_reconcile_operational_status', $args, 'wowrestro', true );
				return;
			}
		} catch ( Throwable $exception ) {
			// Fall through to WordPress Cron.
		}
		if ( ! wp_next_scheduled( 'wowrestro_reconcile_operational_status', $args ) ) {
			wp_schedule_single_event( time() + min( 60, 5 * $attempt ), 'wowrestro_reconcile_operational_status', $args );
		}
	}

	/**
	 * Persist one auditable operational transition.
	 *
	 * @return true|WP_Error
	 */
	public static function set_status( $order, $status, $reason = '', $override = false, $user_id = null, $expected_from = null ) {
		$order          = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		$original_order = $order;
		$status         = self::normalize( $status );
		if ( ! $order instanceof WC_Order || ! self::is_restaurant_order( $order ) || ! in_array( $status, self::allowed_statuses(), true ) ) {
			return new WP_Error( 'wowrestro_invalid_operational_status', __( 'Invalid restaurant status.', 'wowrestro' ) );
		}

		$order_id = $order->get_id();
		$lock     = self::acquire_order_lock( $order_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$result  = true;
		$changed = false;
		$from    = '';
		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof WC_Order ) {
				$result = new WP_Error( 'wowrestro_order_not_found', __( 'The order could not be reloaded.', 'wowrestro' ) );
			} else {
				$order = self::ensure_order( $fresh );
				if ( ! $order instanceof WC_Order ) {
					$result = new WP_Error( 'wowrestro_order_not_found', __( 'The restaurant order could not be reloaded.', 'wowrestro' ) );
				} else {
					$from = self::current( $order );
					if ( null !== $expected_from && self::normalize( $expected_from ) !== $from ) {
						$result = new WP_Error( 'wowrestro_stale_order', __( 'This order changed since it was loaded. Refresh and try again.', 'wowrestro' ), array( 'status' => 409 ) );
					} elseif ( $from !== $status ) {
						$history   = $order->get_meta( self::META_HISTORY );
						$history   = is_array( $history ) ? $history : array();
						$history[] = array(
							'status'   => $status,
							'at_gmt'   => gmdate( 'Y-m-d H:i:s' ),
							'user_id'  => null === $user_id ? get_current_user_id() : absint( $user_id ),
							'reason'   => sanitize_text_field( $reason ),
							'override' => (bool) $override,
						);
						$order->update_meta_data( '_wowrestro_order', 'yes' );
						$order->update_meta_data( self::META_STATUS, $status );
						$order->update_meta_data( self::META_HISTORY, array_slice( $history, -100 ) );
						$order->save();
						$changed = true;
					}
				}
			}
		} finally {
			self::release_order_lock( $order_id );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $original_order instanceof WC_Order && $original_order !== $order ) {
			$original_order->update_meta_data( '_wowrestro_order', 'yes' );
			$original_order->update_meta_data( self::META_STATUS, self::current( $order ) );
			$original_order->update_meta_data( self::META_HISTORY, $order->get_meta( self::META_HISTORY ) );
		}
		if ( $changed ) {
			do_action( 'wowrestro_order_transitioned', $order, $from, $status );
		}
		return true;
	}

	/** Acquire one cross-request, re-entrant order-operation lock. */
	public static function acquire_order_lock( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return new WP_Error( 'wowrestro_order_lock', __( 'The order cannot be locked.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		if ( isset( self::$held_locks[ $order_id ] ) ) {
			++self::$held_locks[ $order_id ]['count'];
			return true;
		}

		$key     = 'wowrestro_order_lock_' . $order_id;
		$state   = array(
			'owner'   => wp_generate_uuid4(),
			'expires' => time() + 300,
		);
		$current = get_option( $key, false );
		$claimed = false === $current ? add_option( $key, $state, '', false ) : false;
		if ( ! $claimed && is_array( $current ) && absint( $current['expires'] ?? 0 ) < time() ) {
			$claimed = self::compare_and_swap_option( $key, $current, $state );
		}
		if ( ! $claimed ) {
			return new WP_Error( 'wowrestro_order_busy', __( 'This order is being updated. Refresh and try again.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		self::$held_locks[ $order_id ] = array(
			'key'   => $key,
			'state' => $state,
			'count' => 1,
		);
		return true;
	}

	/** Release a lock only when this request still owns its exact value. */
	public static function release_order_lock( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! isset( self::$held_locks[ $order_id ] ) ) {
			return;
		}
		--self::$held_locks[ $order_id ]['count'];
		if ( self::$held_locks[ $order_id ]['count'] > 0 ) {
			return;
		}
		$lock = self::$held_locks[ $order_id ];
		unset( self::$held_locks[ $order_id ] );
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock['key'],
				maybe_serialize( $lock['state'] )
			)
		);
		self::clear_option_cache( $lock['key'] );
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

	private static function clear_option_cache( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	public static function current( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		return $order instanceof WC_Order ? self::normalize( $order->get_meta( self::META_STATUS ) ) : '';
	}

	public static function label( $status ) {
		if ( ! self::$labels && did_action( 'init' ) ) {
			self::load_labels();
		}
		$status = self::normalize( $status );
		return isset( self::$labels[ $status ] ) ? self::$labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
	}

	public static function labels() {
		if ( ! self::$labels && did_action( 'init' ) ) {
			self::load_labels();
		}
		return self::$labels;
	}

	/** @return string[] */
	private static function allowed_statuses() {
		return array( 'new', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled' );
	}

	/**
	 * Run a Woo order query with raw meta clauses on both HPOS and the CPT store.
	 *
	 * Woo's legacy CPT data store does not copy the raw meta_query argument into
	 * WP_Query without this documented adapter hook. Merge instead of replacing
	 * so supported arguments such as billing_email retain their generated clauses.
	 *
	 * @param array<string,mixed> $args Woo order query arguments.
	 * @return mixed
	 */
	public static function query_orders( $args ) {
		$meta = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		if ( ! $meta ) {
			return wc_get_orders( $args );
		}
		$bridge = static function ( $wp_args, $query_vars ) use ( $meta ) {
			if ( ! isset( $query_vars['meta_query'] ) || $query_vars['meta_query'] !== $meta ) {
				return $wp_args;
			}
			$existing              = isset( $wp_args['meta_query'] ) && is_array( $wp_args['meta_query'] ) ? $wp_args['meta_query'] : array();
			$wp_args['meta_query'] = $existing ? array(
				'relation' => 'AND',
				$existing,
				$meta,
			) : $meta;
			return $wp_args;
		};
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $bridge, 10, 2 );
		try {
			return wc_get_orders( $args );
		} finally {
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $bridge, 10 );
		}
	}

	/**
	 * Canonical forward-only workflow graph. Legacy keys remain readable for a
	 * single compatibility release, but are never written as Woo order statuses.
	 */
	public static function transitions( $mode = '' ) {
		$ready = 'delivery' === sanitize_key( $mode ) ? array( 'out_for_delivery' ) : array( 'completed' );
		return array(
			'new'                 => array( 'accepted' ),
			'accepted'            => array( 'preparing' ),
			'preparing'           => array( 'ready' ),
			'ready'               => $ready,
			'out_for_delivery'    => array( 'completed' ),
			'wr-new'              => array( 'wr-accepted' ),
			'wr-accepted'         => array( 'wr-preparing' ),
			'wr-preparing'        => array( 'wr-ready' ),
			'wr-ready'            => 'delivery' === sanitize_key( $mode ) ? array( 'wr-out-for-delivery' ) : array( 'completed' ),
			'wr-out-for-delivery' => array( 'completed' ),
		);
	}

	public static function next( $status, $mode ) {
		$status      = self::normalize( $status );
		$transitions = self::transitions( $mode );
		return isset( $transitions[ $status ] ) ? $transitions[ $status ] : array();
	}

	public static function is_restaurant_order( $order ) {
		return $order instanceof WC_Order && (
			'yes' === $order->get_meta( '_wowrestro_order' ) ||
			(bool) $order->get_meta( '_wowrestro_fulfillment' ) ||
			(bool) $order->get_meta( '_wowrestro_mode' )
		);
	}

	public static function normalize( $status ) {
		$status = sanitize_key( (string) $status );
		$map    = array(
			'wr-new'              => 'new',
			'wr-accepted'         => 'accepted',
			'wr-preparing'        => 'preparing',
			'wr-ready'            => 'ready',
			'wr-out-for-delivery' => 'out_for_delivery',
			'out-for-delivery'    => 'out_for_delivery',
			'wc-completed'        => 'completed',
			'wc-cancelled'        => 'cancelled',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	private static function tracking_token() {
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( Exception $exception ) {
			return strtolower( wp_generate_password( 40, false, false ) );
		}
	}
}
