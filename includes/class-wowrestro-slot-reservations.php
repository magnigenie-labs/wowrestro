<?php
/**
 * Atomic shared-kitchen slot reservations.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Slot_Reservations {
	const SCHEMA_VERSION = '1';
	const CLEANUP_HOOK   = 'wowrestro_cleanup_slot_reservations';

	/** @var bool|null */
	private static $table_ready = null;

	/** @var array<int,int> Payment-completion locks acquired by this request. */
	private static $payment_locks = array();

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'commit_checkout_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'commit_checkout_order' ), 20 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'validate_payment_retry' ), 5 );
		add_action( 'woocommerce_pre_payment_complete', array( __CLASS__, 'reconcile_before_payment' ), 5, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'reconcile_order' ), 20 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'release_payment_lock' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'release_order' ), 10, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'release_order' ), 10, 2 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_expired' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Create or upgrade the InnoDB ledger. Called by activation/migration.
	 *
	 * @return bool
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL,
			kitchen_slot_gmt datetime NOT NULL,
			slot_position smallint(5) unsigned NOT NULL,
			capacity_snapshot smallint(5) unsigned NOT NULL,
			service varchar(16) NOT NULL,
			promised_at_gmt datetime NOT NULL,
			shipping_rate_id varchar(191) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NULL DEFAULT NULL,
			hold_expires_gmt datetime NULL DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			UNIQUE KEY slot_position (kitchen_slot_gmt,slot_position),
			KEY order_id (order_id),
			KEY hold_expires_gmt (hold_expires_gmt)
		) ENGINE=InnoDB {$charset};";
		dbDelta( $sql );
		self::$table_ready = null;
		if ( ! self::table_ready() ) {
			$wpdb->query( 'ALTER TABLE ' . $table . ' ENGINE=InnoDB' );
			self::$table_ready = null;
		}
		if ( ! self::table_ready() ) {
			return false;
		}
		update_option( 'wowrestro_schema_version', self::SCHEMA_VERSION, false );
		return true;
	}

	/**
	 * Fail-closed readiness check, including the required transactional engine.
	 *
	 * @return bool
	 */
	public static function table_ready() {
		if ( null !== self::$table_ready ) {
			return self::$table_ready;
		}
		global $wpdb;
		$table    = self::table_name();
		$status   = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $status || ! isset( $status->Engine ) || 'innodb' !== strtolower( (string) $status->Engine ) ) {
			self::$table_ready = false;
			return false;
		}
		$columns  = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table );
		$indexes  = $wpdb->get_results( 'SHOW INDEX FROM ' . $table );
		$required = array( 'id', 'token', 'kitchen_slot_gmt', 'slot_position', 'capacity_snapshot', 'service', 'promised_at_gmt', 'shipping_rate_id', 'order_id', 'hold_expires_gmt', 'created_at_gmt', 'updated_at_gmt' );
		$present  = is_array( $columns ) ? wp_list_pluck( $columns, 'Field' ) : array();
		$unique   = array();
		foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
			if ( empty( $index->Non_unique ) ) {
				$unique[ $index->Key_name ][ absint( $index->Seq_in_index ) ] = $index->Column_name;
			}
		}
		foreach ( $unique as &$index_columns ) {
			ksort( $index_columns );
			$index_columns = array_values( $index_columns );
		}
		unset( $index_columns );

		self::$table_ready = ! array_diff( $required, $present )
			&& in_array( array( 'token' ), $unique, true )
			&& in_array( array( 'kitchen_slot_gmt', 'slot_position' ), $unique, true );
		return self::$table_ready;
	}

	/**
	 * Reserve one unique position in the quoted shared kitchen bucket.
	 *
	 * @return array|WP_Error
	 */
	public static function reserve( $mode, $requested_at = '', $postcode = '', $context = array() ) {
		if ( ! self::table_ready() ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'Ordering is temporarily unavailable because the slot ledger is not ready.', 'wowrestro' ) );
		}
		self::cleanup_slot( null );
		$exact = '' !== trim( (string) $requested_at ) && 'asap' !== strtolower( trim( (string) $requested_at ) );

		for ( $attempt = 0; $attempt < ( $exact ? 1 : 12 ); $attempt++ ) {
			$quote = WowRestro_Fulfillment::quote( $mode, $requested_at, $postcode, $context );
			if ( empty( $quote['available'] ) ) {
				return new WP_Error( 'wowrestro_' . sanitize_key( $quote['reason'] ?? 'no_slot' ), self::error_message( $quote['reason'] ?? 'no_slot' ), $quote );
			}
			$token = wp_generate_uuid4();
			for ( $position = 1; $position <= absint( $quote['capacity'] ); $position++ ) {
				$inserted = self::insert_reservation( $quote, $token, $position, 0 );
				if ( true === $inserted ) {
					$quote['reservation_token'] = $token;
					$quote['position']          = $position;
					return $quote;
				}
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
			}
			if ( $exact ) {
				break;
			}
		}

		return new WP_Error( 'wowrestro_slot_busy', __( 'That time was just taken. Choose another slot.', 'wowrestro' ) );
	}

	/**
	 * Count both live holds and committed orders for one shared kitchen slot.
	 *
	 * @return int|WP_Error
	 */
	public static function count_for_slot( $kitchen_slot_gmt, $exclude_token = '' ) {
		if ( ! self::table_ready() ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'The slot ledger is unavailable.', 'wowrestro' ) );
		}
		$slot = self::normalize_gmt( $kitchen_slot_gmt );
		if ( ! $slot ) {
			return new WP_Error( 'wowrestro_invalid_slot', __( 'The kitchen slot is invalid.', 'wowrestro' ) );
		}
		self::cleanup_slot( $slot );
		global $wpdb;
		if ( $exclude_token ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE kitchen_slot_gmt = %s AND token <> %s', $slot, sanitize_text_field( $exclude_token ) ) );
		} else {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE kitchen_slot_gmt = %s', $slot ) );
		}
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'The slot ledger could not be read.', 'wowrestro' ) );
		}
		return absint( $count );
	}

	/**
	 * Read occupancy for a bounded range in one query for public discovery.
	 * Atomic inserts remain authoritative when a caller later reserves a slot.
	 *
	 * @return array<string,int>|WP_Error
	 */
	public static function counts_for_range( $start_gmt, $end_gmt, $exclude_token = '' ) {
		if ( ! self::table_ready() ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'The slot ledger is unavailable.', 'wowrestro' ) );
		}
		$start = self::normalize_gmt( $start_gmt );
		$end   = self::normalize_gmt( $end_gmt );
		if ( ! $start || ! $end || $end < $start ) {
			return new WP_Error( 'wowrestro_invalid_slot_range', __( 'The kitchen slot range is invalid.', 'wowrestro' ) );
		}
		global $wpdb;
		$sql = 'SELECT kitchen_slot_gmt, COUNT(*) AS reservations FROM ' . self::table_name() . ' WHERE kitchen_slot_gmt BETWEEN %s AND %s AND (order_id IS NOT NULL OR hold_expires_gmt > UTC_TIMESTAMP())';
		$args = array( $start, $end );
		if ( $exclude_token ) {
			$sql   .= ' AND token <> %s';
			$args[] = sanitize_text_field( $exclude_token );
		}
		$sql  .= ' GROUP BY kitchen_slot_gmt';
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'The slot ledger could not be read.', 'wowrestro' ) );
		}
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->kitchen_slot_gmt ] = absint( $row->reservations );
		}
		return $counts;
	}

	/**
	 * Backwards-compatible counter. Capacity is shared across service modes.
	 */
	public static function count( $mode, $promised_at ) {
		try {
			$promise = new DateTimeImmutable( (string) $promised_at, wp_timezone() );
			$lead    = WowRestro_Fulfillment::MODE_DELIVERY === WowRestro_Fulfillment::normalize_mode( $mode ) ? absint( WowRestro_Fulfillment::settings()['delivery_lead_minutes'] ) : 0;
			$count   = self::count_for_slot( $promise->modify( '-' . $lead . ' minutes' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
			return is_wp_error( $count ) ? 0 : $count;
		} catch ( Exception $exception ) {
			return 0;
		}
	}

	/**
	 * Return one reservation row.
	 *
	 * @return object|null
	 */
	public static function get( $token ) {
		if ( ! self::table_ready() || ! $token ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE token = %s LIMIT 1', sanitize_text_field( $token ) ) );
	}

	/**
	 * Bind a checkout hold to its durable WooCommerce order.
	 *
	 * @return bool|WP_Error
	 */
	public static function bind_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wowrestro_order_not_found', __( 'The order could not be loaded.', 'wowrestro' ) );
		}
		$token = sanitize_text_field( (string) $order->get_meta( '_wowrestro_reservation_token' ) );
		if ( ! $token ) {
			return self::backfill_order( $order );
		}
		if ( ! self::table_ready() ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'The slot ledger is unavailable.', 'wowrestro' ) );
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET order_id = %d, hold_expires_gmt = NULL, updated_at_gmt = %s WHERE token = %s AND (order_id IS NULL OR order_id = %d)',
				$order->get_id(),
				current_time( 'mysql', true ),
				$token,
				$order->get_id()
			)
		);
		$row = self::get( $token );
		if ( false === $updated || ! $row || absint( $row->order_id ) !== $order->get_id() ) {
			return new WP_Error( 'wowrestro_reservation_lost', __( 'The order slot could not be committed.', 'wowrestro' ) );
		}
		$order->update_meta_data( '_wowrestro_slot_position', absint( $row->slot_position ) );
		$order->save_meta_data();
		self::clear_session_token( $token );
		return true;
	}

	/** Alias retained for existing REST/manual-order callers. */
	public static function commit_order( $order ) {
		return self::bind_order( $order );
	}

	/**
	 * Checkout hooks cannot use a returned WP_Error, so convert bind failures to
	 * an exception before WooCommerce sends a success response.
	 */
	public static function commit_checkout_order( $order ) {
		$result = self::bind_order( $order );
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		self::close_unbound_checkout_order( $order, $result );
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( $result->get_error_code(), $result->get_error_message(), 409 );
		}
		throw new Exception( esc_html( $result->get_error_message() ) );
	}

	/**
	 * Reconcile an interrupted bind, or safely backfill a future restaurant order.
	 */
	public static function reconcile_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$operational = class_exists( 'WowRestro_Order_Statuses' ) ? WowRestro_Order_Statuses::current( $order ) : '';
		if ( in_array( $operational, array( 'cancelled', 'completed' ), true ) || in_array( $order->get_meta( '_wowrestro_terminal_payment_review' ), array( 'pending', 'reviewed' ), true ) || 'yes' === $order->get_meta( '_wowrestro_terminal_payment_reviewed' ) ) {
			self::release_order( $order );
			return true;
		}
		$token = $order->get_meta( '_wowrestro_reservation_token' );
		if ( $token && self::get( $token ) ) {
			return self::bind_order( $order );
		}
		return self::backfill_order( $order );
	}

	/**
	 * Reacquire a released slot before WooCommerce submits a pay-for-order retry.
	 * WooCommerce will not invoke the gateway while an error notice is present.
	 *
	 * @param WC_Order $order Order being paid.
	 */
	public static function validate_payment_retry( $order ) {
		$result = self::ensure_payment_capacity( $order );
		if ( is_wp_error( $result ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		}
	}

	/**
	 * Fail closed before WooCommerce records payment from any gateway/webhook path.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $transaction_id Transaction identifier.
	 * @throws Exception When capacity cannot be restored.
	 */
	public static function reconcile_before_payment( $order_id, $transaction_id = '' ) {
		unset( $transaction_id );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! class_exists( 'WowRestro_Order_Statuses' ) || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) ) {
			return;
		}
		$locked = WowRestro_Order_Statuses::acquire_order_lock( $order_id );
		if ( is_wp_error( $locked ) ) {
			throw new Exception( esc_html( $locked->get_error_message() ) );
		}
		self::$payment_locks[ absint( $order_id ) ] = isset( self::$payment_locks[ absint( $order_id ) ] ) ? self::$payment_locks[ absint( $order_id ) ] + 1 : 1;
		if ( 1 === array_sum( self::$payment_locks ) ) {
			add_action( 'shutdown', array( __CLASS__, 'release_all_payment_locks' ), PHP_INT_MAX );
		}
		try {
			$result = self::ensure_payment_capacity( $order );
		} catch ( Throwable $exception ) {
			self::release_payment_lock( $order_id );
			throw new Exception( esc_html( $exception->getMessage() ) );
		}
		if ( is_wp_error( $result ) ) {
			self::release_payment_lock( $order_id );
			throw new Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/** Release the payment guard after all normal payment-complete callbacks. */
	public static function release_payment_lock( $order_id, $transaction_id = '' ) {
		unset( $transaction_id );
		$order_id = absint( $order_id );
		if ( empty( self::$payment_locks[ $order_id ] ) ) {
			return;
		}
		self::$payment_locks[ $order_id ]--;
		if ( self::$payment_locks[ $order_id ] <= 0 ) {
			unset( self::$payment_locks[ $order_id ] );
		}
		WowRestro_Order_Statuses::release_order_lock( $order_id );
	}

	/** Shutdown fallback for gateways that abort before payment_complete fires. */
	public static function release_all_payment_locks() {
		foreach ( array_keys( self::$payment_locks ) as $order_id ) {
			while ( ! empty( self::$payment_locks[ $order_id ] ) ) {
				self::release_payment_lock( $order_id );
			}
		}
	}

	/**
	 * Confirm that a restaurant order owns one live ledger allocation.
	 *
	 * @param WC_Order|int $order Order or ID.
	 * @return true|WP_Error
	 */
	public static function ensure_payment_capacity( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order || ! class_exists( 'WowRestro_Order_Statuses' ) || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) ) {
			return true;
		}
		$woo_status  = $order->get_status();
		$operational = WowRestro_Order_Statuses::current( $order );
		$terminal    = in_array( $woo_status, array( 'cancelled', 'refunded' ), true )
			|| 'completed' === $operational
			|| ( 'cancelled' === $operational && 'failed' !== $woo_status );
		if ( $terminal ) {
			$order->delete_meta_data( '_wowrestro_payment_retry_reserved' );
			$order->update_meta_data( '_wowrestro_terminal_payment_review', 'pending' );
			$order->save_meta_data();
			return true;
		}
		$token = sanitize_text_field( (string) $order->get_meta( '_wowrestro_reservation_token' ) );
		$row   = $token ? self::get( $token ) : null;
		if ( $row && absint( $row->order_id ) === $order->get_id() ) {
			self::mark_payment_retry( $order );
			return true;
		}
		$slot = self::normalize_gmt( $order->get_meta( '_wowrestro_kitchen_slot_gmt' ) );
		if ( ! $slot || strtotime( $slot . ' UTC' ) <= time() ) {
			return new WP_Error( 'wowrestro_payment_slot_expired', __( 'This order’s promised time has expired. Contact the restaurant to reschedule before paying.', 'wowrestro' ) );
		}
		$result = self::backfill_order( $order, true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wowrestro_payment_capacity', __( 'The original kitchen slot is no longer available. Contact the restaurant to reschedule before paying.', 'wowrestro' ) );
		}
		$token = sanitize_text_field( (string) $order->get_meta( '_wowrestro_reservation_token' ) );
		$row   = $token ? self::get( $token ) : null;
		if ( $row && absint( $row->order_id ) === $order->get_id() ) {
			self::mark_payment_retry( $order );
			return true;
		}
		return new WP_Error( 'wowrestro_payment_capacity', __( 'The kitchen slot could not be restored. Contact the restaurant before paying.', 'wowrestro' ) );
	}

	private static function mark_payment_retry( $order ) {
		if ( 'failed' === $order->get_status() ) {
			$order->update_meta_data( '_wowrestro_payment_retry_reserved', 'yes' );
			$order->save_meta_data();
		}
	}

	/**
	 * Add a future migrated order to the same shared ledger.
	 */
	public static function backfill_order( $order, $allow_failed = false ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( '_wowrestro_order' ) ) {
			return false;
		}
		$operational = class_exists( 'WowRestro_Order_Statuses' ) ? WowRestro_Order_Statuses::current( $order ) : '';
		$failed_retry = $allow_failed && 'failed' === $order->get_status();
		if ( ! $failed_retry && ( in_array( $operational, array( 'cancelled', 'completed' ), true ) || in_array( $order->get_meta( '_wowrestro_terminal_payment_review' ), array( 'pending', 'reviewed' ), true ) || 'yes' === $order->get_meta( '_wowrestro_terminal_payment_reviewed' ) ) ) {
			self::release_order( $order );
			return true;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) || ( 'failed' === $order->get_status() && ! $allow_failed ) ) {
			return true;
		}
		if ( ! self::table_ready() ) {
			return new WP_Error( 'wowrestro_storage_unavailable', __( 'The slot ledger is unavailable.', 'wowrestro' ) );
		}
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . self::table_name() . ' WHERE order_id = %d LIMIT 1', $order->get_id() ) );
		if ( $existing ) {
			return true;
		}

		$slot = self::normalize_gmt( $order->get_meta( '_wowrestro_kitchen_slot_gmt' ) );
		if ( ! $slot || strtotime( $slot . ' UTC' ) <= time() ) {
			return true;
		}
		$promise = self::normalize_gmt( $order->get_meta( '_wowrestro_promised_at_gmt' ) );
		if ( ! $promise ) {
			return new WP_Error( 'wowrestro_invalid_slot', __( 'The order has no valid promised time to backfill.', 'wowrestro' ) );
		}
		$slot_local = ( new DateTimeImmutable( $slot, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );
		$capacity   = max( 1, (int) apply_filters( 'wowrestro_capacity_for_slot', absint( WowRestro_Fulfillment::settings()['slot_capacity'] ), $slot_local, 'shared' ) );
		$token    = sanitize_text_field( (string) $order->get_meta( '_wowrestro_reservation_token' ) );
		$token    = $token ?: wp_generate_uuid4();
		$quote    = array(
			'kitchen_slot_gmt' => $slot,
			'promised_at_gmt'  => $promise,
			'mode'             => WowRestro_Fulfillment::normalize_mode( $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ) ),
			'shipping_rate_id' => sanitize_text_field( (string) $order->get_meta( '_wowrestro_shipping_rate_id' ) ),
			'capacity'         => $capacity,
		);
		for ( $position = 1; $position <= $capacity; $position++ ) {
			$inserted = self::insert_reservation( $quote, $token, $position, $order->get_id() );
			if ( true === $inserted ) {
				$order->update_meta_data( '_wowrestro_reservation_token', $token );
				$order->update_meta_data( '_wowrestro_slot_position', $position );
				$order->save_meta_data();
				return true;
			}
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}
		}
		return new WP_Error( 'wowrestro_capacity', __( 'The migrated order exceeds the configured capacity for its slot.', 'wowrestro' ) );
	}

	/** Reserve first, then atomically switch the order metadata and ledger row. */
	public static function reschedule_order( $order, $mode, $requested_at, $postcode = '', $context = array() ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wowrestro_order_not_found', __( 'The order could not be loaded.', 'wowrestro' ) );
		}
		$keys = array( '_wowrestro_fulfillment', '_wowrestro_shipping_rate_id', '_wowrestro_kitchen_slot_gmt', '_wowrestro_promised_at_gmt', '_wowrestro_reservation_token', '_wowrestro_mode', '_wowrestro_promised_at', '_wowrestro_slot_position' );
		$old = array();
		foreach ( $keys as $key ) {
			$old[ $key ] = $order->get_meta( $key );
		}
		$old_token = $old['_wowrestro_reservation_token'];
		$quote     = self::reserve( $mode, $requested_at, $postcode, $context );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		global $wpdb;
		$failure = null;
		$started = false !== $wpdb->query( 'START TRANSACTION' );
		if ( ! $started ) {
			self::release( '', '', $quote['reservation_token'] );
			return new WP_Error( 'wowrestro_reschedule_failed', __( 'The order could not be rescheduled because the slot ledger is unavailable.', 'wowrestro' ) );
		}
		try {
			$bound = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table_name() . ' SET order_id = %d, hold_expires_gmt = NULL, updated_at_gmt = %s WHERE token = %s AND order_id IS NULL',
					$order->get_id(),
					current_time( 'mysql', true ),
					$quote['reservation_token']
				)
			);
			if ( 1 !== $bound ) {
				throw new RuntimeException( 'new reservation bind failed' );
			}

			if ( $old_token && ! hash_equals( (string) $old_token, (string) $quote['reservation_token'] ) ) {
				$deleted = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . self::table_name() . ' WHERE token = %s AND (order_id IS NULL OR order_id = %d)',
						$old_token,
						$order->get_id()
					)
				);
				$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . self::table_name() . ' WHERE token = %s LIMIT 1', $old_token ) );
				if ( false === $deleted || null !== $remaining || '' !== $wpdb->last_error ) {
					throw new RuntimeException( 'old reservation release failed' );
				}
			}

			self::apply_quote_to_order( $order, $quote );
			$order->update_meta_data( '_wowrestro_slot_position', absint( $quote['position'] ) );
			$order->save();

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'reservation commit failed' );
			}
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			foreach ( $old as $key => $value ) {
				if ( '' === $value || null === $value ) {
					$order->delete_meta_data( $key );
				} else {
					$order->update_meta_data( $key, $value );
				}
			}
			try {
				$order->save();
			} catch ( Throwable $restore_exception ) {
				$failure = new WP_Error( 'wowrestro_reschedule_rollback', __( 'The reschedule failed and the order metadata requires manual review.', 'wowrestro' ) );
			}
			if ( ! self::release( '', '', $quote['reservation_token'] ) ) {
				$failure = new WP_Error( 'wowrestro_reschedule_rollback', __( 'The reschedule failed and its temporary slot could not be released. Review the order before retrying.', 'wowrestro' ) );
			}
			return $failure ?: new WP_Error( 'wowrestro_reschedule_failed', __( 'The order could not be rescheduled.', 'wowrestro' ) );
		}
		return $quote;
	}

	/**
	 * Compatibility signature; token is the only authoritative selector.
	 */
	public static function release( $mode = '', $promised_at = '', $token = '' ) {
		unset( $mode, $promised_at );
		if ( ! $token || ! self::table_ready() ) {
			return false;
		}
		global $wpdb;
		$token   = sanitize_text_field( $token );
		$deleted = $wpdb->delete( self::table_name(), array( 'token' => $token ), array( '%s' ) );
		if ( false === $deleted ) {
			return false;
		}
		$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . self::table_name() . ' WHERE token = %s LIMIT 1', $token ) );
		return null === $remaining && '' === $wpdb->last_error;
	}

	public static function release_order( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order || ! self::table_ready() ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->delete( self::table_name(), array( 'order_id' => $order->get_id() ), array( '%d' ) );
	}

	/**
	 * Reconcile expired checkout holds before deleting them, then prune past rows.
	 */
	public static function cleanup_expired() {
		if ( ! self::table_ready() ) {
			return false;
		}
		for ( $batch = 0; $batch < 5 && self::reconcile_expired_rows(); $batch++ ) {
			// Process bounded batches so every deletion receives an order lookup first.
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table_name() . ' WHERE order_id IS NOT NULL AND kitchen_slot_gmt < UTC_TIMESTAMP()' );
		return '' === $wpdb->last_error;
	}

	/**
	 * Apply canonical and compatibility metadata from a reservation quote.
	 */
	public static function apply_quote_to_order( $order, $quote ) {
		$order->update_meta_data( '_wowrestro_order', 'yes' );
		$order->update_meta_data( '_wowrestro_fulfillment', $quote['mode'] );
		$order->update_meta_data( '_wowrestro_shipping_rate_id', $quote['shipping_rate_id'] ?? '' );
		$order->update_meta_data( '_wowrestro_kitchen_slot_gmt', $quote['kitchen_slot_gmt'] );
		$order->update_meta_data( '_wowrestro_promised_at_gmt', $quote['promised_at_gmt'] );
		$order->update_meta_data( '_wowrestro_reservation_token', $quote['reservation_token'] );
		$order->update_meta_data( '_wowrestro_mode', $quote['mode'] );
		$order->update_meta_data( '_wowrestro_promised_at', $quote['promised_at'] );
	}

	private static function insert_reservation( $quote, $token, $position, $order_id ) {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$minutes = max( 1, absint( WowRestro_Fulfillment::settings()['hold_minutes'] ) );
		$expires = $order_id ? null : gmdate( 'Y-m-d H:i:s', time() + $minutes * MINUTE_IN_SECONDS );
		$result  = $wpdb->insert(
			self::table_name(),
			array(
				'token'              => $token,
				'kitchen_slot_gmt'   => $quote['kitchen_slot_gmt'],
				'slot_position'      => $position,
				'capacity_snapshot'  => absint( $quote['capacity'] ),
				'service'            => WowRestro_Fulfillment::normalize_mode( $quote['mode'] ),
				'promised_at_gmt'    => $quote['promised_at_gmt'],
				'shipping_rate_id'   => sanitize_text_field( $quote['shipping_rate_id'] ?? '' ),
				'order_id'           => $order_id ?: null,
				'hold_expires_gmt'   => $expires,
				'created_at_gmt'     => $now,
				'updated_at_gmt'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', $order_id ? '%d' : null, $order_id ? null : '%s', '%s', '%s' )
		);
		if ( false !== $result ) {
			return true;
		}
		$error = strtolower( (string) $wpdb->last_error );
		if ( false !== strpos( $error, 'duplicate entry' ) || false !== strpos( $error, 'unique constraint failed' ) ) {
			return false;
		}
		return new WP_Error( 'wowrestro_storage_unavailable', __( 'The kitchen slot could not be reserved.', 'wowrestro' ) );
	}

	private static function cleanup_slot( $slot ) {
		if ( ! self::table_ready() ) {
			return;
		}
		for ( $batch = 0; $batch < 5 && self::reconcile_expired_rows( $slot ); $batch++ ) {
			// Slot capacity is capped at 500; five batches cover the configured maximum.
		}
	}

	/**
	 * Look for an order carrying each expired token before any expiry delete.
	 *
	 * @param string $slot Optional normalized UTC slot.
	 */
	private static function reconcile_expired_rows( $slot = '' ) {
		global $wpdb;
		$sql = 'SELECT token FROM ' . self::table_name() . ' WHERE order_id IS NULL AND hold_expires_gmt IS NOT NULL AND hold_expires_gmt <= UTC_TIMESTAMP()';
		if ( $slot ) {
			$sql .= $wpdb->prepare( ' AND kitchen_slot_gmt = %s', $slot );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY id ASC LIMIT 100' );
		foreach ( $rows as $row ) {
			try {
				$orders = WowRestro_Order_Statuses::query_orders(
					array(
						'limit'      => 10,
						'meta_query' => array(
							array( 'key' => '_wowrestro_reservation_token', 'value' => sanitize_text_field( $row->token ) ),
						),
					)
				);
			} catch ( Throwable $exception ) {
				continue;
			}
			if ( '' !== $wpdb->last_error ) {
				continue;
			}
			$matched_order = false;
			$query_valid   = true;
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order || ! class_exists( 'WowRestro_Order_Statuses' ) || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) || ! hash_equals( (string) $row->token, (string) $order->get_meta( '_wowrestro_reservation_token' ) ) ) {
					$query_valid = false;
					continue;
				}
				$matched_order = $order;
				break;
			}
			if ( ! $query_valid ) {
				continue;
			}
			if ( $matched_order ) {
				$operational = WowRestro_Order_Statuses::current( $matched_order );
				$terminal    = in_array( $matched_order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true )
					|| in_array( $operational, array( 'cancelled', 'completed' ), true )
					|| in_array( $matched_order->get_meta( '_wowrestro_terminal_payment_review' ), array( 'pending', 'reviewed' ), true )
					|| 'yes' === $matched_order->get_meta( '_wowrestro_terminal_payment_reviewed' )
					|| 'yes' === $matched_order->get_meta( '_wowrestro_terminal_initialization_reviewed' );
				if ( $terminal ) {
					self::release( '', '', $row->token );
				} else {
					$bound = self::bind_order( $matched_order );
					if ( is_wp_error( $bound ) ) {
						continue;
					}
				}
			}
			$current = self::get( $row->token );
			if ( $current && ! $current->order_id && strtotime( $current->hold_expires_gmt . ' UTC' ) <= time() ) {
				$wpdb->delete( self::table_name(), array( 'token' => sanitize_text_field( $row->token ) ), array( '%s' ) );
			}
		}
		return count( $rows );
	}

	private static function normalize_gmt( $value ) {
		if ( ! $value ) {
			return '';
		}
		try {
			$date = $value instanceof DateTimeInterface ? new DateTimeImmutable( $value->format( DATE_ATOM ) ) : new DateTimeImmutable( (string) $value, new DateTimeZone( 'UTC' ) );
			return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return '';
		}
	}

	private static function clear_session_token( $token ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$session = WC()->session->get( 'wowrestro_reservation', array() );
		if ( is_array( $session ) && ! empty( $session['token'] ) && ! hash_equals( (string) $token, (string) $session['token'] ) ) {
			return;
		}
		foreach ( array( 'wowrestro_reservation', 'wowrestro_fulfillment', 'wowrestro_requested_at', 'wowrestro_shipping_rate_id', 'wowrestro_mode', 'wowrestro_requested', 'wowrestro_postcode', 'wowrestro_tip' ) as $key ) {
			WC()->session->__unset( $key );
		}
	}

	/**
	 * Close an unpaid orphan before surfacing checkout failure. Paid orders stay
	 * visible in their authoritative WooCommerce payment state for manual review.
	 *
	 */
	private static function close_unbound_checkout_order( $order, $error ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$message = sprintf( __( 'WowRestro could not bind kitchen capacity: %s', 'wowrestro' ), $error->get_error_message() );
		$order->update_meta_data( '_wowrestro_bind_failed', sanitize_key( $error->get_error_code() ) );
		$order->add_order_note( $message );

		if ( $order->is_paid() ) {
			$order->update_meta_data( '_wowrestro_capacity_review', 'yes' );
			$order->save_meta_data();
			return;
		}

		$token = sanitize_text_field( (string) $order->get_meta( '_wowrestro_reservation_token' ) );
		$row   = $token ? self::get( $token ) : null;
		if ( $row && ! $row->order_id ) {
			self::release( '', '', $token );
		}
		$order->save_meta_data();
		if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			$order->update_status( 'cancelled', __( 'Checkout was closed because kitchen capacity could not be committed.', 'wowrestro' ) );
		}
	}

	private static function error_message( $reason ) {
		$messages = array(
			'paused'                  => __( 'Online ordering is temporarily paused.', 'wowrestro' ),
			'invalid_time'             => __( 'Choose a valid fulfillment slot.', 'wowrestro' ),
			'outside_preorder_window' => __( 'That time is outside the preorder window.', 'wowrestro' ),
			'closed'                  => __( 'The selected service is closed at that time.', 'wowrestro' ),
			'capacity'                => __( 'The kitchen is full for that slot.', 'wowrestro' ),
			'storage_unavailable'     => __( 'Ordering is temporarily unavailable while the slot ledger is repaired.', 'wowrestro' ),
		);
		return $messages[ $reason ] ?? __( 'No fulfillment slot is currently available.', 'wowrestro' );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wowrestro_slot_reservations';
	}
}
