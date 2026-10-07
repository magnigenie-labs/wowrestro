<?php
/**
 * Classic and Store API checkout integration.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Checkout {
	/** @var bool */
	private static $store_update_registered = false;

	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_fields' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_update' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'classic_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_fields' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_classic_mode' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout' ), 999, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_store_api_fields' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_order_details' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_assets' ), 20 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'classic_promise_preview' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api_update();
		}
	}

	/**
	 * Keep the existing Checkout Blocks presentation; backend validation is shared.
	 */
	public static function register_block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'wowrestro/fulfillment-mode',
				'label'    => __( 'How would you like your order?', 'wowrestro' ),
				'location' => 'order',
				'type'     => 'select',
				'required' => true,
				'options'  => self::mode_options( true ),
			)
		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'wowrestro/status-updates',
				'label'    => __( 'Send me restaurant status updates by email', 'wowrestro' ),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'wowrestro/requested-time',
				'label'    => __( 'Requested time (optional)', 'wowrestro' ),
				'location' => 'order',
				'type'     => 'text',
				'required' => false,
			)
		);
	}

	/** Register the formal namespaced Store API cart-update callback. */
	public static function register_store_api_update() {
		if ( self::$store_update_registered || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'wowrestro',
				'callback'  => array( __CLASS__, 'store_api_update' ),
			)
		);
		self::$store_update_registered = true;
	}

	/**
	 * Synchronize fulfillment state sent by Cart/Checkout Blocks.
	 *
	 * @param array $data Extension data.
	 */
	public static function store_api_update( $data ) {
		$data = is_array( $data ) ? $data : array();
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			self::throw_store_error( 'wowrestro_session', __( 'Your checkout session is unavailable. Refresh and try again.', 'wowrestro' ), 409 );
		}
		$mode      = $data['fulfillment'] ?? $data['mode'] ?? WC()->session->get( 'wowrestro_fulfillment', 'pickup' );
		$requested = $data['requested_at'] ?? $data['requested_time'] ?? $data['slot'] ?? WC()->session->get( 'wowrestro_requested_at', '' );
		$rate_id   = sanitize_text_field( (string) ( $data['shipping_rate_id'] ?? WC()->session->get( 'wowrestro_shipping_rate_id', '' ) ) );
		$requested = sanitize_text_field( (string) $requested );
		if ( strlen( $requested ) > 64 ) {
			self::throw_store_error( 'wowrestro_invalid_time', __( 'Choose a valid fulfillment time.', 'wowrestro' ), 400 );
		}
		// A mode without a rate, as the Olive & Ember order flow sends, moves the cart onto a rate that serves it.
		if ( ( isset( $data['fulfillment'] ) || isset( $data['mode'] ) ) && empty( $data['shipping_rate_id'] ) && class_exists( 'WowRestro_Shipping' ) ) {
			$chosen  = WowRestro_Shipping::choose_rate_for_mode( WowRestro_Fulfillment::normalize_mode( $mode ) );
			$rate_id = $chosen ? $chosen : $rate_id;
		}
		self::set_session_state( $mode, $requested, $rate_id );
		// The Olive & Ember order flow sends the typed postcode so the classic checkout's promise preview matches it.
		if ( isset( $data['postcode'] ) ) {
			WC()->session->set( 'wowrestro_postcode', substr( sanitize_text_field( (string) $data['postcode'] ), 0, 20 ) );
		}
		if ( array_key_exists( 'tip', $data ) && class_exists( 'WowRestro_Cart' ) ) {
			$tip = WowRestro_Cart::set_tip( $data['tip'] );
			if ( is_wp_error( $tip ) ) {
				self::throw_store_error( 'wowrestro_tip', $tip->get_error_message(), 400 );
			}
		}
	}

	public static function classic_fields( $fields ) {
		$fields['order']['wowrestro_fulfillment_mode'] = array(
			'type'     => 'select',
			'label'    => __( 'How would you like your order?', 'wowrestro' ),
			'required' => true,
			'options'  => self::mode_options( false ),
			'priority' => 5,
		);
		$fields['order']['wowrestro_requested_time'] = array(
			'type'        => 'text',
			'label'       => __( 'Requested time', 'wowrestro' ),
			'placeholder' => __( 'ASAP', 'wowrestro' ),
			'required'    => false,
			'priority'    => 6,
		);
		$fields['order']['wowrestro_status_updates'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'Send me restaurant status updates by email', 'wowrestro' ),
			'required' => false,
			'priority' => 7,
		);
		return $fields;
	}

	/**
	 * Reserve during final validation so classic checkout cannot create an unpromised order.
	 */
	public static function validate_classic_checkout( $data, $errors ) {
		if ( method_exists( $errors, 'has_errors' ) && $errors->has_errors() ) {
			return;
		}
		$result = self::reserve_checkout( is_array( $data ) ? $data : array() );
		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
	}

	/** Apply the already validated hold to the classic order. */
	public static function save_classic_fields( $order, $data ) {
		if ( ! self::is_restaurant_context( $order ) ) {
			return;
		}
		$posted = is_array( $data ) ? $data : array();
		foreach ( array( 'wowrestro_fulfillment_mode', 'wowrestro_requested_time', 'wowrestro_status_updates', 'shipping_method', 'shipping_postcode', 'billing_postcode' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies checkout before this hook.
				$posted[ $key ] = wc_clean( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}
		$quote = self::current_reservation();
		if ( is_wp_error( $quote ) || ! $quote ) {
			$quote = self::reserve_checkout( $posted );
		}
		if ( is_wp_error( $quote ) ) {
			throw new Exception( esc_html( $quote->get_error_message() ) );
		}
		self::apply_quote_to_order( $order, $quote, ! empty( $posted['wowrestro_status_updates'] ) );
	}

	/** Reserve and validate during Store API order construction. */
	public static function save_store_api_fields( $order, $request ) {
		if ( ! self::is_restaurant_context( $order ) ) {
			return;
		}
		$additional = $request->get_param( 'additional_fields' );
		$additional = is_array( $additional ) ? $additional : array();
		$data       = array(
			'wowrestro_fulfillment_mode' => $additional['wowrestro/fulfillment-mode'] ?? self::session_get( 'wowrestro_fulfillment', 'pickup' ),
			'wowrestro_requested_time'   => $additional['wowrestro/requested-time'] ?? self::session_get( 'wowrestro_requested_at', '' ),
			'shipping_postcode'          => $order->get_shipping_postcode(),
			'billing_postcode'           => $order->get_billing_postcode(),
		);
		$rate_id = class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::order_rate_id( $order ) : '';
		if ( $rate_id ) {
			$data['shipping_method'] = array( $rate_id );
		}
		$quote = self::reserve_checkout( $data, $order );
		if ( is_wp_error( $quote ) ) {
			self::throw_store_error( $quote->get_error_code(), $quote->get_error_message(), 409 );
		}
		self::apply_quote_to_order( $order, $quote, ! empty( $additional['wowrestro/status-updates'] ) );
	}

	/** Capture the checkout AJAX state; selected Woo rate overrides the preference. */
	public static function capture_classic_mode( $posted_data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		parse_str( $posted_data, $data );
		$rate_id = self::posted_rate_id( $data );
		$mode    = sanitize_key( $data['wowrestro_fulfillment_mode'] ?? 'pickup' );
		if ( $rate_id && class_exists( 'WowRestro_Shipping' ) ) {
			$mode = WowRestro_Shipping::mode_for_rate( $rate_id );
		}
		self::set_session_state( $mode, sanitize_text_field( $data['wowrestro_requested_time'] ?? '' ), $rate_id );
		$postcode = self::postcode_from_posted_data( $data );
		if ( $postcode ) {
			WC()->session->set( 'wowrestro_postcode', $postcode );
		}
	}

	/**
	 * Non-reserving cart validation; final checkout validation always runs again.
	 */
	public static function validate_cart() {
		if ( ! self::is_restaurant_context() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( class_exists( 'WowRestro_Legacy_Migration' ) && method_exists( 'WowRestro_Legacy_Migration', 'checkout_blocked' ) && WowRestro_Legacy_Migration::checkout_blocked() ) {
			wc_add_notice( __( 'Complete the WOWRestro 1.x migration, and deactivate that plugin if it is still active, before accepting orders.', 'wowrestro' ), 'error' );
			return;
		}
		$rate_id = class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::selected_rate_id() : '';
		$mode    = self::session_get( 'wowrestro_fulfillment', self::session_get( 'wowrestro_mode', 'pickup' ) );
		$minimum = 0.0;
		if ( $rate_id && class_exists( 'WowRestro_Shipping' ) ) {
			$mode    = WowRestro_Shipping::mode_for_rate( $rate_id );
			$minimum = WowRestro_Shipping::minimum_for_rate( $rate_id );
		}
		$requested = self::session_get( 'wowrestro_requested_at', self::session_get( 'wowrestro_requested', '' ) );
		$context   = array( 'shipping_rate_id' => $rate_id );
		$reserved  = self::session_get( 'wowrestro_reservation', array() );
		if ( is_array( $reserved ) && ! empty( $reserved['token'] ) ) {
			$context['reservation_token'] = $reserved['token'];
		}
		$quote = WowRestro_Fulfillment::quote( $mode, $requested, '', $context );
		if ( empty( $quote['available'] ) ) {
			wc_add_notice( self::availability_message( $quote['reason'] ?? '' ), 'error' );
			return;
		}
		if ( $minimum > 0 && self::cart_merchandise_total() < $minimum ) {
			wc_add_notice( self::minimum_message( $mode, $minimum ), 'error' );
		}
	}

	/** Public session helper for the storefront/Store API adapter. */
	public static function set_session_state( $mode, $requested = '', $shipping_rate_id = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		$mode      = WowRestro_Fulfillment::normalize_mode( $mode );
		$requested = sanitize_text_field( (string) $requested );
		$rate_id   = sanitize_text_field( (string) $shipping_rate_id );
		$old_mode  = WowRestro_Fulfillment::normalize_mode( self::session_get( 'wowrestro_fulfillment', self::session_get( 'wowrestro_mode', 'pickup' ) ) );
		$old_rate  = sanitize_text_field( (string) self::session_get( 'wowrestro_shipping_rate_id', '' ) );
		$old_time  = self::reservation_intent( self::session_get( 'wowrestro_requested_at', self::session_get( 'wowrestro_requested', '' ) ) );
		if ( $mode !== $old_mode || $rate_id !== $old_rate || self::reservation_intent( $requested ) !== $old_time ) {
			self::release_session_reservation();
		}
		WC()->session->set( 'wowrestro_fulfillment', $mode );
		WC()->session->set( 'wowrestro_requested_at', $requested );
		WC()->session->set( 'wowrestro_shipping_rate_id', $rate_id );
		WC()->session->set( 'wowrestro_mode', $mode );
		WC()->session->set( 'wowrestro_requested', $requested );
		return true;
	}

	/**
	 * Preserve the existing no-op method for third-party callbacks; Woo rates own fees.
	 */
	public static function add_delivery_fee( $cart ) {
		unset( $cart );
	}

	private static function reserve_checkout( $data, $order = null ) {
		if ( ! self::is_restaurant_context( $order ) ) {
			return true;
		}
		if ( class_exists( 'WowRestro_Legacy_Migration' ) && method_exists( 'WowRestro_Legacy_Migration', 'checkout_blocked' ) && WowRestro_Legacy_Migration::checkout_blocked() ) {
			self::release_session_reservation();
			return new WP_Error( 'wowrestro_migration_required', __( 'Complete the WOWRestro 1.x migration, and deactivate that plugin if it is still active, before accepting orders.', 'wowrestro' ) );
		}

		$rate_id = self::posted_rate_id( $data );
		if ( ! $rate_id && $order instanceof WC_Order && class_exists( 'WowRestro_Shipping' ) ) {
			$rate_id = WowRestro_Shipping::order_rate_id( $order );
		}
		$shipping = class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::checkout_context( $rate_id ) : array( 'shipping_rate_id' => '', 'mode' => 'pickup', 'minimum_order' => 0 );
		if ( is_wp_error( $shipping ) ) {
			self::release_session_reservation();
			return $shipping;
		}
		$preferred = WowRestro_Fulfillment::normalize_mode( $data['wowrestro_fulfillment_mode'] ?? self::session_get( 'wowrestro_fulfillment', 'pickup' ) );
		$mode      = ! empty( $shipping['shipping_rate_id'] ) ? $shipping['mode'] : $preferred;
		$requested = array_key_exists( 'wowrestro_requested_time', $data ) ? sanitize_text_field( (string) $data['wowrestro_requested_time'] ) : self::session_get( 'wowrestro_requested_at', '' );
		$postcode  = self::postcode_from_posted_data( $data );
		self::set_session_state( $mode, $requested, $shipping['shipping_rate_id'] );

		$minimum = max( 0.0, (float) $shipping['minimum_order'] );
		$total   = $order instanceof WC_Order ? max( 0.0, (float) $order->get_subtotal() - (float) $order->get_discount_total() ) : self::cart_merchandise_total();
		if ( $minimum > 0 && $total < $minimum ) {
			self::release_session_reservation();
			return new WP_Error( 'wowrestro_minimum_order', self::minimum_message( $mode, $minimum ) );
		}

		$context = array( 'shipping_rate_id' => $shipping['shipping_rate_id'] );
		$current = self::current_reservation( $mode, $requested, $shipping['shipping_rate_id'], true );
		if ( ! is_wp_error( $current ) && $current ) {
			return $current;
		}
		self::release_session_reservation();
		$quote = WowRestro_Slot_Reservations::reserve( $mode, $requested, $postcode, $context );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set(
				'wowrestro_reservation',
				array(
					'token'            => $quote['reservation_token'],
					'mode'             => $quote['mode'],
					'requested_at'     => $requested,
					'promised_at'      => $quote['promised_at'],
					'promised_at_gmt'  => $quote['promised_at_gmt'],
					'kitchen_slot_gmt' => $quote['kitchen_slot_gmt'],
					'shipping_rate_id' => $quote['shipping_rate_id'],
					'quote'             => $quote,
				)
			);
		}
		return $quote;
	}

	private static function current_reservation( $mode = '', $requested = null, $rate_id = '', $revalidate = false ) {
		$session = self::session_get( 'wowrestro_reservation', array() );
		if ( ! is_array( $session ) || empty( $session['token'] ) ) {
			return false;
		}
		$row = WowRestro_Slot_Reservations::get( $session['token'] );
		if ( ! $row || $row->order_id || ! $row->hold_expires_gmt || strtotime( $row->hold_expires_gmt . ' UTC' ) <= time() ) {
			return false;
		}
		if ( $mode && ( $mode !== $row->service || (string) $rate_id !== (string) $row->shipping_rate_id ) ) {
			return false;
		}
		if ( null !== $requested && self::reservation_intent( $requested ) !== self::reservation_intent( $session['requested_at'] ?? '' ) ) {
			return false;
		}
		if ( null !== $requested && '' !== trim( (string) $requested ) && 'asap' !== strtolower( trim( (string) $requested ) ) ) {
			$requested_gmt = self::requested_gmt( $requested );
			if ( ! $requested_gmt || $requested_gmt !== $row->promised_at_gmt ) {
				return false;
			}
		}
		$quote = isset( $session['quote'] ) && is_array( $session['quote'] ) ? $session['quote'] : self::quote_from_row( $row );
		if ( $revalidate ) {
			$promise = ( new DateTimeImmutable( $row->promised_at_gmt, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s\Z' );
			$quote   = WowRestro_Fulfillment::quote(
				$row->service,
				$promise,
				'',
				array( 'shipping_rate_id' => $row->shipping_rate_id, 'reservation_token' => $row->token )
			);
			if ( empty( $quote['available'] ) || absint( $row->slot_position ) > absint( $quote['capacity'] ) ) {
				return new WP_Error( 'wowrestro_reservation_invalid', self::availability_message( $quote['reason'] ?? 'capacity' ) );
			}
		}
		$quote['reservation_token'] = $row->token;
		$quote['position']          = absint( $row->slot_position );
		return $quote;
	}

	private static function quote_from_row( $row ) {
		$timezone = wp_timezone();
		$slot     = ( new DateTimeImmutable( $row->kitchen_slot_gmt, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
		$promise  = ( new DateTimeImmutable( $row->promised_at_gmt, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
		return array(
			'available'        => true,
			'mode'             => $row->service,
			'kitchen_slot_gmt' => $row->kitchen_slot_gmt,
			'promised_at_gmt'  => $row->promised_at_gmt,
			'kitchen_slot'     => $slot->format( DATE_ATOM ),
			'promised_at'      => $promise->format( DATE_ATOM ),
			'promised_label'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $promise->getTimestamp(), $timezone ),
			'capacity'         => absint( $row->capacity_snapshot ),
			'shipping_rate_id' => $row->shipping_rate_id,
			'minimum_order'    => class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::minimum_for_rate( $row->shipping_rate_id ) : 0,
		);
	}

	private static function apply_quote_to_order( $order, $quote, $status_opt_in ) {
		WowRestro_Slot_Reservations::apply_quote_to_order( $order, $quote );
		$order->update_meta_data( '_wowrestro_source', $order->get_meta( '_wowrestro_source' ) ?: 'checkout' );
		$order->update_meta_data( '_wowrestro_payment_flow', $order->get_meta( '_wowrestro_payment_flow' ) ?: 'online' );
		$order->update_meta_data( '_wowrestro_status_opt_in', $status_opt_in ? 'yes' : 'no' );
	}

	private static function release_session_reservation() {
		$session = self::session_get( 'wowrestro_reservation', array() );
		if ( is_array( $session ) && ! empty( $session['token'] ) ) {
			WowRestro_Slot_Reservations::release( '', '', $session['token'] );
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'wowrestro_reservation' );
		}
	}

	private static function is_restaurant_context( $order = null ) {
		if ( function_exists( 'WC' ) && WC()->cart && class_exists( 'WowRestro_Cart' ) ) {
			return WowRestro_Cart::has_restaurant_items( WC()->cart );
		}
		if ( $order instanceof WC_Order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product && class_exists( 'WowRestro_Products' ) && WowRestro_Products::is_menu_item( $product ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function posted_rate_id( $data ) {
		$rates = isset( $data['shipping_method'] ) ? $data['shipping_method'] : array();
		$rates = is_array( $rates ) ? $rates : array( $rates );
		return 1 === count( array_filter( $rates ) ) ? sanitize_text_field( (string) reset( $rates ) ) : '';
	}

	private static function requested_gmt( $requested ) {
		try {
			$value = sanitize_text_field( (string) $requested );
			$date  = preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/i', $value ) ? new DateTimeImmutable( $value ) : new DateTimeImmutable( $value, wp_timezone() );
			return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return '';
		}
	}

	/** Normalize blank/ASAP and equivalent exact timestamps for hold reuse. */
	private static function reservation_intent( $requested ) {
		$requested = trim( sanitize_text_field( (string) $requested ) );
		if ( '' === $requested || 'asap' === strtolower( $requested ) ) {
			return 'asap';
		}
		$gmt = self::requested_gmt( $requested );
		return $gmt ? 'exact:' . $gmt : 'invalid:' . $requested;
	}

	private static function cart_merchandise_total() {
		return function_exists( 'WC' ) && WC()->cart ? max( 0.0, (float) WC()->cart->get_cart_contents_total() ) : 0.0;
	}

	private static function postcode_from_posted_data( $data ) {
		foreach ( array( 'shipping_postcode', 'billing_postcode' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $data[ $key ] ) );
			}
		}
		return '';
	}

	private static function session_get( $key, $default = '' ) {
		return function_exists( 'WC' ) && WC()->session ? WC()->session->get( $key, $default ) : $default;
	}

	private static function minimum_message( $mode, $minimum ) {
		return sprintf( __( 'The minimum %1$s order is %2$s.', 'wowrestro' ), $mode, wp_strip_all_tags( wc_price( $minimum ) ) );
	}

	private static function availability_message( $reason ) {
		$messages = array(
			'paused'                  => __( 'Online ordering is temporarily paused.', 'wowrestro' ),
			'asap_disabled'           => __( 'Choose a scheduled fulfillment time.', 'wowrestro' ),
			'invalid_time'            => __( 'Choose a valid fulfillment slot.', 'wowrestro' ),
			'closed'                  => __( 'The restaurant is closed at that requested time.', 'wowrestro' ),
			'outside_preorder_window' => __( 'That requested time is outside the preorder window.', 'wowrestro' ),
			'capacity'                => __( 'The kitchen is full for the requested period. Choose another time.', 'wowrestro' ),
			'storage_unavailable'     => __( 'Ordering is temporarily unavailable while the slot ledger is repaired.', 'wowrestro' ),
			'pickup_disabled'         => __( 'Pickup is not currently available.', 'wowrestro' ),
			'delivery_disabled'       => __( 'Delivery is not currently available.', 'wowrestro' ),
		);
		return $messages[ $reason ] ?? __( 'That fulfillment option is not currently available.', 'wowrestro' );
	}

	private static function throw_store_error( $code, $message, $status ) {
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( sanitize_key( $code ), $message, absint( $status ) );
		}
		throw new Exception( esc_html( $message ) );
	}

	public static function checkout_assets() {
		if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		wp_enqueue_style( 'wowrestro-storefront', WOWRESTRO_URL . 'assets/css/storefront.css', array(), WOWRESTRO_VERSION );
		wp_enqueue_style( 'wowrestro-checkout', WOWRESTRO_URL . 'assets/css/checkout.css', array(), WOWRESTRO_VERSION );
		wp_enqueue_script( 'wowrestro-checkout', WOWRESTRO_URL . 'assets/js/checkout.js', array(), WOWRESTRO_VERSION, true );
		wp_localize_script( 'wowrestro-checkout', 'WowRestroCheckout', array(
			'availabilityUrl' => rest_url( 'wowrestro/v1/availability' ),
			'labels'          => array(
				'checking'    => __( 'Checking the next available time…', 'wowrestro' ),
				'promise'     => __( 'Promised for', 'wowrestro' ),
				'deliveryFee' => __( 'Delivery fee', 'wowrestro' ),
				'paused'      => __( 'Online ordering is temporarily paused.', 'wowrestro' ),
				'unavailable' => __( 'That service or requested time is unavailable.', 'wowrestro' ),
			),
		) );
	}

	public static function classic_promise_preview() {
		$mode      = self::session_get( 'wowrestro_fulfillment', self::session_get( 'wowrestro_mode', 'pickup' ) );
		$requested = self::session_get( 'wowrestro_requested_at', self::session_get( 'wowrestro_requested', '' ) );
		$postcode  = self::session_get( 'wowrestro_postcode', '' );
		$rate_id   = class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::selected_rate_id() : '';
		$context   = array( 'shipping_rate_id' => $rate_id );
		$reserved  = self::session_get( 'wowrestro_reservation', array() );
		if ( is_array( $reserved ) && ! empty( $reserved['token'] ) ) {
			$context['reservation_token'] = $reserved['token'];
		}
		$quote = WowRestro_Fulfillment::quote( $mode, $requested, $postcode, $context );
		echo '<div class="wowrestro-promise-preview" data-wowrestro-promise aria-live="polite">';
		if ( ! empty( $quote['available'] ) ) {
			echo '<strong>' . esc_html__( 'Your promised time', 'wowrestro' ) . '</strong><span>' . esc_html( $quote['promised_label'] ) . '</span>';
		} elseif ( 'postcode_required' !== ( $quote['reason'] ?? '' ) ) {
			echo '<span>' . esc_html( self::availability_message( $quote['reason'] ?? '' ) ) . '</span>';
		}
		echo '</div>';
	}

	private static function mode_options( $for_blocks ) {
		$settings = WowRestro_Fulfillment::settings();
		$options  = array();
		if ( 'yes' === $settings['pickup_enabled'] ) {
			$options['pickup'] = __( 'Pickup', 'wowrestro' );
		}
		if ( 'yes' === $settings['delivery_enabled'] ) {
			$options['delivery'] = __( 'Delivery', 'wowrestro' );
		}
		if ( ! $options ) {
			$options['pickup'] = __( 'Pickup', 'wowrestro' );
		}
		if ( ! $for_blocks ) {
			return $options;
		}
		$block_options = array();
		foreach ( $options as $value => $label ) {
			$block_options[] = array( 'value' => $value, 'label' => $label );
		}
		return $block_options;
	}

	public static function admin_order_details( $order ) {
		$mode    = $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' );
		$promise = $order->get_meta( '_wowrestro_promised_at' );
		$status  = class_exists( 'WowRestro_Order_Statuses' ) ? WowRestro_Order_Statuses::current( $order ) : '';
		if ( ! $mode ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'WowRestro fulfillment', 'wowrestro' ) . '</strong><br>';
		echo esc_html( ucfirst( $mode ) ) . ( $promise ? ' · ' . esc_html( $promise ) : '' );
		if ( $status ) {
			echo '<br>' . esc_html__( 'Kitchen status:', 'wowrestro' ) . ' ' . esc_html( WowRestro_Order_Statuses::label( $status ) );
		}
		echo '</p>';
	}
}
