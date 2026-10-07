<?php
/**
 * WooCommerce shipping is the source of truth for fulfillment.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Shipping {
	/**
	 * Resolve a WooCommerce rate to a restaurant fulfillment mode.
	 *
	 * @param string                 $rate_id Full WooCommerce rate ID.
	 * @param WC_Shipping_Rate|null $rate Rate object, when available.
	 * @return string
	 */
	public static function mode_for_rate( $rate_id, $rate = null ) {
		$rate_id   = sanitize_text_field( (string) $rate_id );
		$method_id = $rate instanceof WC_Shipping_Rate ? $rate->get_method_id() : self::method_id( $rate_id );
		$settings  = WowRestro_Fulfillment::settings();
		$overrides = isset( $settings['shipping_rate_modes'] ) && is_array( $settings['shipping_rate_modes'] ) ? $settings['shipping_rate_modes'] : array();
		$mode      = isset( $overrides[ $rate_id ] ) ? sanitize_key( $overrides[ $rate_id ] ) : '';

		if ( ! in_array( $mode, array( WowRestro_Fulfillment::MODE_PICKUP, WowRestro_Fulfillment::MODE_DELIVERY ), true ) ) {
			$mode = isset( $overrides[ $method_id ] ) ? sanitize_key( $overrides[ $method_id ] ) : '';
		}
		if ( ! in_array( $mode, array( WowRestro_Fulfillment::MODE_PICKUP, WowRestro_Fulfillment::MODE_DELIVERY ), true ) ) {
			$mode = 'local_pickup' === $method_id ? WowRestro_Fulfillment::MODE_PICKUP : WowRestro_Fulfillment::MODE_DELIVERY;
		}

		$filtered = sanitize_key( (string) apply_filters( 'wowrestro_fulfillment_mode_for_rate', $mode, $rate_id, $rate ) );
		return in_array( $filtered, array( WowRestro_Fulfillment::MODE_PICKUP, WowRestro_Fulfillment::MODE_DELIVERY ), true ) ? $filtered : $mode;
	}

	/**
	 * Move each shipping package onto a rate that serves the mode, so a delivery/pickup switch changes the cart total.
	 *
	 * @param string $mode Fulfillment mode.
	 * @return string The chosen rate when the cart ships as one package, otherwise empty.
	 */
	public static function choose_rate_for_mode( $mode ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return '';
		}
		WC()->cart->calculate_shipping();
		$packages = WC()->shipping()->get_packages();
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		foreach ( $packages as $index => $package ) {
			$rates   = $package['rates'] ?? array();
			$current = $chosen[ $index ] ?? '';
			if ( isset( $rates[ $current ] ) && self::mode_for_rate( $current, $rates[ $current ] ) === $mode ) {
				continue;
			}
			foreach ( $rates as $rate_id => $rate ) {
				if ( self::mode_for_rate( $rate_id, $rate ) === $mode ) {
					$chosen[ $index ] = $rate_id;
					break;
				}
			}
		}
		WC()->session->set( 'chosen_shipping_methods', $chosen );
		return 1 === count( $packages ) ? (string) reset( $chosen ) : '';
	}

	/**
	 * Return the configured minimum for a shipping-rate instance.
	 *
	 * @param string $rate_id Full WooCommerce rate ID.
	 * @return float
	 */
	public static function minimum_for_rate( $rate_id ) {
		$settings = WowRestro_Fulfillment::settings();
		$minimums = isset( $settings['shipping_rate_minimums'] ) && is_array( $settings['shipping_rate_minimums'] ) ? $settings['shipping_rate_minimums'] : array();
		$value    = isset( $minimums[ $rate_id ] ) ? $minimums[ $rate_id ] : ( $minimums[ self::method_id( $rate_id ) ] ?? 0 );
		return max( 0.0, (float) apply_filters( 'wowrestro_minimum_for_shipping_rate', (float) $value, $rate_id ) );
	}

	/**
	 * Return the single chosen shipping rate from the customer session.
	 *
	 * @return string
	 */
	public static function selected_rate_id() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
		return is_array( $chosen ) && 1 === count( $chosen ) ? sanitize_text_field( (string) reset( $chosen ) ) : '';
	}

	/**
	 * Return a full rate ID from an order's single shipping item.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_rate_id( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$items = $order->get_items( 'shipping' );
		if ( 1 !== count( $items ) ) {
			return '';
		}
		$item       = reset( $items );
		$method_id  = $item->get_method_id();
		$instance_id = absint( $item->get_instance_id() );
		return $instance_id ? $method_id . ':' . $instance_id : $method_id;
	}

	/**
	 * Validate the one-package beta boundary and selected method.
	 *
	 * @param string $posted_rate Optional posted rate ID.
	 * @return array|WP_Error
	 */
	public static function checkout_context( $posted_rate = '' ) {
		$packages = array();
		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			$packages = WC()->shipping()->get_packages();
		}
		if ( count( $packages ) > 1 ) {
			return new WP_Error( 'wowrestro_multiple_packages', __( 'WowRestro currently supports one shipping package per restaurant order.', 'wowrestro' ) );
		}

		$rate_id = sanitize_text_field( (string) $posted_rate );
		if ( ! $rate_id ) {
			$rate_id = self::selected_rate_id();
		}
		$rate = null;
		if ( $packages ) {
			$package = reset( $packages );
			$rates   = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			if ( ! $rate_id || ! isset( $rates[ $rate_id ] ) ) {
				return new WP_Error( 'wowrestro_shipping_required', __( 'Choose an available pickup or delivery method.', 'wowrestro' ) );
			}
			$rate = $rates[ $rate_id ];
		}

		return array(
			'shipping_rate_id' => $rate_id,
			'mode'             => $rate_id ? self::mode_for_rate( $rate_id, $rate ) : WowRestro_Fulfillment::MODE_PICKUP,
			'minimum_order'    => $rate_id ? self::minimum_for_rate( $rate_id ) : 0.0,
		);
	}

	/**
	 * List configured zone methods for the native settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function configured_rates() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$rates = array();
		$zones = WC_Shipping_Zones::get_zones();
		$zones[0] = array(
			'zone_name'        => __( 'Locations not covered by your other zones', 'woocommerce' ),
			'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods( true ),
		);
		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'yes' !== $method->enabled ) {
					continue;
				}
				$rate_id           = $method->id . ':' . absint( $method->instance_id );
				$rates[ $rate_id ] = sprintf( '%1$s - %2$s', wp_strip_all_tags( $zone['zone_name'] ), wp_strip_all_tags( $method->get_title() ) );
			}
		}
		return $rates;
	}

	private static function method_id( $rate_id ) {
		$parts = explode( ':', (string) $rate_id, 2 );
		return sanitize_key( $parts[0] );
	}
}
