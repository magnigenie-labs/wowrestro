<?php
/**
 * Restaurant schedules, shared capacity and promise times.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Fulfillment {
	const MODE_PICKUP       = 'pickup';
	const MODE_DELIVERY     = 'delivery';
	const MAX_PREORDER_DAYS = 90;
	const ASAP_SCAN_LIMIT   = 26500;

	/**
	 * Return normalized plugin settings while retaining 0.2 compatibility.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$defaults = self::defaults();
		$stored   = get_option( 'wowrestro_settings', array() );
		$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
		$settings['weekly_hours'] = self::normalize_weekly_hours( $settings['weekly_hours'], $defaults['weekly_hours'] );

		$service_hours = isset( $stored['service_hours'] ) && is_array( $stored['service_hours'] ) ? $stored['service_hours'] : array();
		foreach ( array( self::MODE_PICKUP, self::MODE_DELIVERY ) as $mode ) {
			if ( empty( $service_hours[ $mode ] ) || ! is_array( $service_hours[ $mode ] ) ) {
				$service_hours[ $mode ] = self::legacy_service_hours( $settings['weekly_hours'] );
			}
			$service_hours[ $mode ] = self::normalize_service_hours( $service_hours[ $mode ], self::legacy_service_hours( $settings['weekly_hours'] ) );
		}
		$settings['service_hours']          = $service_hours;
		$settings['shipping_rate_modes']    = is_array( $settings['shipping_rate_modes'] ) ? $settings['shipping_rate_modes'] : array();
		$settings['shipping_rate_minimums'] = is_array( $settings['shipping_rate_minimums'] ) ? $settings['shipping_rate_minimums'] : array();
		$settings['preorder_days']          = min( self::MAX_PREORDER_DAYS, max( 0, absint( $settings['preorder_days'] ) ) );
		$settings['_schedule_key']          = md5( wp_json_encode( array( $settings['service_hours'], $settings['date_overrides'], $settings['holidays'] ) ) );
		return $settings;
	}

	/**
	 * Quote ASAP or validate one exact customer promise time.
	 *
	 * @param string $mode Fulfillment mode.
	 * @param string $requested_at Customer promise time or `asap`.
	 * @param string $postcode Deprecated compatibility argument; Woo zones own delivery coverage.
	 * @param array  $context Optional shipping_rate_id/minimum_order context.
	 * @return array<string,mixed>
	 */
	public static function quote( $mode, $requested_at = '', $postcode = '', $context = array() ) {
		unset( $postcode );
		$settings = self::settings();
		$context  = is_array( $context ) ? $context : array();
		$rate_id  = isset( $context['shipping_rate_id'] ) ? sanitize_text_field( $context['shipping_rate_id'] ) : '';
		if ( ! $rate_id && class_exists( 'WowRestro_Shipping' ) ) {
			$rate_id = WowRestro_Shipping::selected_rate_id();
		}
		$mode = self::normalize_mode( $mode );
		if ( $rate_id && class_exists( 'WowRestro_Shipping' ) ) {
			$mode = self::normalize_mode( WowRestro_Shipping::mode_for_rate( $rate_id ) );
		}

		$unavailable = self::service_error( $mode, $settings );
		if ( $unavailable ) {
			return self::filtered_quote( array( 'available' => false, 'reason' => $unavailable, 'mode' => $mode ), $mode, $requested_at );
		}
		if ( ! class_exists( 'WowRestro_Slot_Reservations' ) || ! WowRestro_Slot_Reservations::table_ready() ) {
			return self::filtered_quote( array( 'available' => false, 'reason' => 'storage_unavailable', 'mode' => $mode ), $mode, $requested_at );
		}

		$timezone = wp_timezone();
		$utc      = new DateTimeZone( 'UTC' );
		$now      = self::now();
		$interval = max( 5, absint( $settings['slot_interval'] ) );
		$lead     = self::MODE_DELIVERY === $mode ? absint( $settings['delivery_lead_minutes'] ) : 0;
		$earliest = self::round_up( self::add_minutes( $now, absint( $settings['prep_minutes'] ) ), $interval );
		$latest   = $now->modify( '+' . max( 0, absint( $settings['preorder_days'] ) ) . ' days' )->setTime( 23, 59, 59 );
		$exact    = '' !== trim( (string) $requested_at ) && 'asap' !== strtolower( trim( (string) $requested_at ) );
		if ( ! $exact && 'yes' !== $settings['asap_enabled'] ) {
			return self::filtered_quote( array( 'available' => false, 'reason' => 'asap_disabled', 'mode' => $mode ), $mode, $requested_at );
		}

		if ( $exact ) {
			$promise = self::parse_customer_time( $requested_at, $timezone );
			if ( ! $promise ) {
				return self::filtered_quote( array( 'available' => false, 'reason' => 'invalid_time', 'mode' => $mode ), $mode, $requested_at );
			}
			$slot = self::add_minutes( $promise, -$lead );
			$held = false;
			if ( ! empty( $context['reservation_token'] ) ) {
				$reservation = WowRestro_Slot_Reservations::get( sanitize_text_field( $context['reservation_token'] ) );
				$held = $reservation && $reservation->kitchen_slot_gmt === $slot->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			}
			if ( ( $slot < $earliest && ! $held ) || $promise > $latest ) {
				return self::filtered_quote( array( 'available' => false, 'reason' => 'outside_preorder_window', 'mode' => $mode ), $mode, $requested_at );
			}
			if ( ! self::is_interval_boundary( $slot, $interval ) ) {
				return self::filtered_quote( array( 'available' => false, 'reason' => 'invalid_time', 'mode' => $mode ), $mode, $requested_at );
			}
			if ( ! self::is_open_at( $promise, $mode, $settings ) ) {
				return self::filtered_quote( array( 'available' => false, 'reason' => 'closed', 'mode' => $mode ), $mode, $requested_at );
			}
			return self::quote_slot( $mode, $slot, $promise, $rate_id, true, $settings, $requested_at, $utc, $context );
		}

		$attempts  = self::scan_attempts( $earliest, $latest, $interval, 96 );
		$counts    = WowRestro_Slot_Reservations::counts_for_range(
			$earliest->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$latest->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			sanitize_text_field( $context['reservation_token'] ?? '' )
		);
		if ( is_wp_error( $counts ) ) {
			return self::filtered_quote( array( 'available' => false, 'reason' => 'storage_unavailable', 'mode' => $mode ), $mode, $requested_at );
		}
		$context['_slot_counts'] = $counts;
		$slot                    = $earliest;
		$saw_capacity = false;
		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$promise = self::add_minutes( $slot, $lead );
			$promise = self::move_to_open_time( $promise, $mode, $settings, $latest );
			if ( ! $promise || $promise > $latest ) {
				break;
			}
			$slot = self::round_up( self::add_minutes( $promise, -$lead ), $interval );
			$promise = self::add_minutes( $slot, $lead );
			if ( ! self::is_open_at( $promise, $mode, $settings ) ) {
				$slot = self::next_boundary( $slot, $interval );
				continue;
			}
			$quote = self::quote_slot( $mode, $slot, $promise, $rate_id, false, $settings, $requested_at, $utc, $context );
			if ( ! empty( $quote['available'] ) ) {
				return $quote;
			}
			if ( 'capacity' !== ( $quote['reason'] ?? '' ) ) {
				return $quote;
			}
			$saw_capacity = true;
			$slot = self::next_boundary( $slot, $interval );
		}

		return self::filtered_quote( array( 'available' => false, 'reason' => $saw_capacity ? 'capacity' : 'closed', 'mode' => $mode ), $mode, $requested_at );
	}

	/**
	 * Return selectable exact promises. Values are UTC RFC3339 for unambiguous checkout input.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function available_slots( $mode, $date = '', $shipping_rate_id = '', $limit = 96 ) {
		$settings = self::settings();
		$mode     = self::normalize_mode( $mode );
		if ( $shipping_rate_id && class_exists( 'WowRestro_Shipping' ) ) {
			$mode = self::normalize_mode( WowRestro_Shipping::mode_for_rate( $shipping_rate_id ) );
		}
		if ( self::service_error( $mode, $settings ) ) {
			return array();
		}
		$now      = self::now();
		$interval = max( 5, absint( $settings['slot_interval'] ) );
		$lead     = self::MODE_DELIVERY === $mode ? absint( $settings['delivery_lead_minutes'] ) : 0;
		$cursor   = self::add_minutes( self::round_up( self::add_minutes( $now, absint( $settings['prep_minutes'] ) ), $interval ), $lead );
		$latest   = $now->modify( '+' . absint( $settings['preorder_days'] ) . ' days' )->setTime( 23, 59, 59 );
		$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? $date : '';
		$limit    = min( 200, max( 1, absint( $limit ) ) );
		$slots    = array();
		if ( $date ) {
			try {
				$requested_day = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
			} catch ( Exception $exception ) {
				return array();
			}
			if ( $requested_day->format( 'Y-m-d' ) !== $date || $requested_day < $now->setTime( 0, 0, 0 ) || $requested_day > $latest ) {
				return array();
			}
			if ( $cursor < $requested_day ) {
				$cursor = self::add_minutes( self::round_up( self::add_minutes( $requested_day, -$lead ), $interval ), $lead );
			}
			$day_end = $requested_day->setTime( 23, 59, 59 );
			if ( $day_end < $latest ) {
				$latest = $day_end;
			}
		}
		$attempts = self::scan_attempts( $cursor, $latest, $interval, 96 );
		$counts = WowRestro_Slot_Reservations::counts_for_range(
			self::add_minutes( $cursor, -$lead )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			self::add_minutes( $latest, -$lead )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
		);
		if ( is_wp_error( $counts ) ) {
			return array();
		}
		$utc = new DateTimeZone( 'UTC' );

		for ( $attempt = 0; $attempt < $attempts && $cursor <= $latest && count( $slots ) < $limit; $attempt++ ) {
			if ( ( ! $date || $date === $cursor->format( 'Y-m-d' ) ) && self::is_open_at( $cursor, $mode, $settings ) ) {
				$value = $cursor->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' );
				$quote = self::quote_slot(
					$mode,
					self::add_minutes( $cursor, -$lead ),
					$cursor,
					$shipping_rate_id,
					true,
					$settings,
					$value,
					$utc,
					array( '_slot_counts' => $counts )
				);
				if ( ! empty( $quote['available'] ) ) {
					$slots[] = array(
						'value'           => $value,
						'promised_at_gmt' => $quote['promised_at_gmt'],
						'promised_at'     => $quote['promised_at'],
						'label'           => $quote['promised_label'],
						'time_label'      => wp_date( get_option( 'time_format' ), $cursor->getTimestamp(), wp_timezone() ),
						'remaining'       => $quote['remaining'],
					);
				}
			}
			$cursor = self::add_minutes( self::next_boundary( self::add_minutes( $cursor, -$lead ), $interval ), $lead );
		}
		return $slots;
	}

	/**
	 * Normalize untrusted mode values.
	 */
	public static function normalize_mode( $mode ) {
		return self::MODE_DELIVERY === sanitize_key( (string) $mode ) ? self::MODE_DELIVERY : self::MODE_PICKUP;
	}

	/**
	 * Compatibility counter; capacity is shared, so mode is intentionally ignored.
	 */
	public static function count_orders_for_slot( $slot, $mode, $interval ) {
		unset( $interval );
		if ( ! $slot instanceof DateTimeImmutable || ! class_exists( 'WowRestro_Slot_Reservations' ) ) {
			return 0;
		}
		$lead = self::MODE_DELIVERY === self::normalize_mode( $mode ) ? absint( self::settings()['delivery_lead_minutes'] ) : 0;
		$gmt  = self::add_minutes( $slot, -$lead )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$count = WowRestro_Slot_Reservations::count_for_slot( $gmt );
		return is_wp_error( $count ) ? 0 : absint( $count );
	}

	/**
	 * Deprecated compatibility shim. WooCommerce shipping zones own coverage and cost.
	 */
	public static function delivery_zone_for_postcode( $postcode, $settings = null ) {
		unset( $postcode, $settings );
		return array();
	}

	/**
	 * Parse legacy rules for migration/reporting only; never used for checkout decisions.
	 */
	public static function delivery_zones( $configured ) {
		$zones = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $configured ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) >= 2 && $parts[0] && $parts[1] ) {
				$zones[] = array( 'name' => sanitize_text_field( $parts[0] ), 'patterns' => sanitize_text_field( $parts[1] ) );
			}
		}
		return $zones;
	}

	public static function defaults() {
		$weekly = array();
		$hours  = array();
		foreach ( range( 1, 7 ) as $day ) {
			$weekly[ $day ] = array( 'enabled' => 'yes', 'open' => '11:00', 'close' => '22:00' );
			$hours[ $day ]  = array( 'enabled' => 'yes', 'periods' => '11:00-22:00' );
		}
		return array(
			'pickup_enabled'        => 'yes',
			'delivery_enabled'      => 'yes',
			'asap_enabled'          => 'yes',
			'prep_minutes'          => 30,
			'delivery_lead_minutes' => 15,
			'slot_interval'         => 15,
			'slot_capacity'         => 8,
			'hold_minutes'          => 10,
			'preorder_days'         => 7,
			'service_hours'         => array( self::MODE_PICKUP => $hours, self::MODE_DELIVERY => $hours ),
			'date_overrides'        => '',
			'shipping_rate_modes'   => array(),
			'shipping_rate_minimums'=> array(),
			'menu_template'         => 1,
			'menu_layout'           => 'tabs',
			'menu_page_size'        => 30,
			'dietary_attribute'     => 'pa_dietary',
			'allergen_attribute'    => 'pa_allergens',
			'tips_enabled'          => 'no',
			'tip_presets'           => '10,15,20',
			'custom_tips'           => 'yes',
			'tips_taxable'          => 'no',
			'tips_tax_class'        => '',
			'late_grace_minutes'    => 5,
			'email_updates'         => 'yes',
			'orders_paused'         => 'no',
			'weekly_hours'          => $weekly,
			'holidays'              => '',
			'open_time'             => '11:00',
			'close_time'            => '22:00',
			'delivery_fee'          => 0,
			'minimum_order'         => 0,
			'delivery_postcodes'    => '',
			'delivery_zones'        => '',
		);
	}

	private static function quote_slot( $mode, $slot, $promise, $rate_id, $exact, $settings, $requested_at, $utc, $context ) {
		$capacity = max( 1, (int) apply_filters( 'wowrestro_capacity_for_slot', absint( $settings['slot_capacity'] ), $slot, 'shared' ) );
		$slot_gmt = $slot->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$count    = isset( $context['_slot_counts'] ) && is_array( $context['_slot_counts'] ) ? absint( $context['_slot_counts'][ $slot_gmt ] ?? 0 ) : WowRestro_Slot_Reservations::count_for_slot( $slot_gmt, sanitize_text_field( $context['reservation_token'] ?? '' ) );
		if ( is_wp_error( $count ) ) {
			return self::filtered_quote( array( 'available' => false, 'reason' => 'storage_unavailable', 'mode' => $mode ), $mode, $requested_at );
		}
		if ( $count >= $capacity ) {
			return self::filtered_quote( array( 'available' => false, 'reason' => 'capacity', 'mode' => $mode ), $mode, $requested_at );
		}
		$minimum = isset( $settings['shipping_rate_minimums'][ $rate_id ] ) ? (float) $settings['shipping_rate_minimums'][ $rate_id ] : 0.0;
		if ( $rate_id && class_exists( 'WowRestro_Shipping' ) ) {
			$minimum = WowRestro_Shipping::minimum_for_rate( $rate_id );
		}
		$quote = array(
			'available'        => true,
			'mode'             => $mode,
			'exact'            => (bool) $exact,
			'kitchen_slot_gmt' => $slot_gmt,
			'promised_at_gmt'  => $promise->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'kitchen_slot'     => $slot->format( DATE_ATOM ),
			'promised_at'      => $promise->format( DATE_ATOM ),
			'promised_label'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $promise->getTimestamp(), wp_timezone() ),
			'capacity'         => $capacity,
			'orders_in_slot'   => absint( $count ),
			'remaining'        => max( 0, $capacity - absint( $count ) ),
			'shipping_rate_id' => $rate_id,
			'minimum_order'    => max( 0.0, $minimum ),
			'delivery_fee'     => 0.0,
			'delivery_zone'    => '',
		);
		return self::filtered_quote( $quote, $mode, $requested_at );
	}

	private static function filtered_quote( $quote, $mode, $requested_at ) {
		return apply_filters( 'wowrestro_fulfillment_quote', $quote, $mode, $requested_at );
	}

	private static function service_error( $mode, $settings ) {
		if ( 'yes' === $settings['orders_paused'] ) {
			return 'paused';
		}
		if ( self::MODE_PICKUP === $mode && 'yes' !== $settings['pickup_enabled'] ) {
			return 'pickup_disabled';
		}
		if ( self::MODE_DELIVERY === $mode && 'yes' !== $settings['delivery_enabled'] ) {
			return 'delivery_disabled';
		}
		return '';
	}

	private static function now() {
		$now = apply_filters( 'wowrestro_now', new DateTimeImmutable( 'now', wp_timezone() ) );
		if ( $now instanceof DateTimeInterface ) {
			return ( new DateTimeImmutable( $now->format( 'Y-m-d H:i:s.uP' ) ) )->setTimezone( wp_timezone() );
		}
		try {
			return new DateTimeImmutable( (string) $now, wp_timezone() );
		} catch ( Exception $exception ) {
			return new DateTimeImmutable( 'now', wp_timezone() );
		}
	}

	private static function parse_customer_time( $value, $timezone ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( ! $value || strlen( $value ) > 40 ) {
			return false;
		}
		try {
			if ( preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/i', $value ) ) {
				return ( new DateTimeImmutable( $value ) )->setTimezone( $timezone );
			}
			$date = new DateTimeImmutable( $value, $timezone );
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::\d{2})?$/', $value, $match ) && $date->format( 'Y-m-d H:i' ) !== $match[1] . ' ' . $match[2] ) {
				return false;
			}
			return $date;
		} catch ( Exception $exception ) {
			return false;
		}
	}

	private static function round_up( $time, $interval ) {
		$seconds = (int) $time->format( 's' );
		if ( $seconds > 0 || (int) $time->format( 'u' ) > 0 ) {
			$time = $time->setTimestamp( $time->getTimestamp() + 60 - $seconds );
		}
		while ( ! self::is_interval_boundary( $time, $interval ) ) {
			$time = self::add_minutes( $time, 1 );
		}
		return $time;
	}

	private static function next_boundary( $time, $interval ) {
		return self::round_up( self::add_minutes( $time, 1 ), $interval );
	}

	private static function add_minutes( $time, $minutes ) {
		return $time->setTimestamp( $time->getTimestamp() + ( (int) $minutes * 60 ) );
	}

	private static function scan_attempts( $earliest, $latest, $interval, $minimum ) {
		$seconds = max( 0, $latest->getTimestamp() - $earliest->getTimestamp() );
		$needed  = (int) ceil( $seconds / ( max( 1, (int) $interval ) * 60 ) ) + 4;
		return min( self::ASAP_SCAN_LIMIT, max( (int) $minimum, $needed ) );
	}

	private static function is_interval_boundary( $slot, $interval ) {
		$minutes = (int) $slot->format( 'H' ) * 60 + (int) $slot->format( 'i' );
		return 0 === $minutes % $interval && 0 === (int) $slot->format( 's' );
	}

	private static function move_to_open_time( $candidate, $mode, $settings, $latest ) {
		for ( $day = 0; $day <= absint( $settings['preorder_days'] ) + 2; $day++ ) {
			foreach ( self::periods_for_date( $candidate, $mode, $settings ) as $period ) {
				$open  = self::at_time( $candidate, $period[0] );
				$close = self::at_time( $candidate, $period[1] );
				if ( $candidate < $open ) {
					return $open <= $latest ? $open : false;
				}
				if ( $candidate >= $open && $candidate < $close ) {
					return $candidate;
				}
			}
			$candidate = $candidate->modify( '+1 day' )->setTime( 0, 0, 0 );
			if ( $candidate > $latest ) {
				break;
			}
		}
		return false;
	}

	private static function is_open_at( $promise, $mode, $settings ) {
		foreach ( self::periods_for_date( $promise, $mode, $settings ) as $period ) {
			if ( $promise >= self::at_time( $promise, $period[0] ) && $promise < self::at_time( $promise, $period[1] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function periods_for_date( $date, $mode, $settings ) {
		static $cache = array();
		$key = ( $settings['_schedule_key'] ?? '' ) . '|' . $mode . '|' . $date->format( 'Y-m-d' );
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}
		$override = self::date_override( $date->format( 'Y-m-d' ), $mode, $settings['date_overrides'] );
		if ( null !== $override ) {
			$cache[ $key ] = self::parse_periods( $override );
			return $cache[ $key ];
		}
		$holidays = preg_split( '/[\s,]+/', (string) ( $settings['holidays'] ?? '' ) );
		if ( in_array( $date->format( 'Y-m-d' ), $holidays, true ) ) {
			$cache[ $key ] = array();
			return $cache[ $key ];
		}
		$row = $settings['service_hours'][ $mode ][ (int) $date->format( 'N' ) ] ?? array();
		if ( empty( $row['enabled'] ) || 'no' === $row['enabled'] ) {
			$cache[ $key ] = array();
			return $cache[ $key ];
		}
		$cache[ $key ] = self::parse_periods( $row['periods'] ?? '' );
		return $cache[ $key ];
	}

	private static function date_override( $date, $mode, $configured ) {
		$all = null;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $configured ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( 3 !== count( $parts ) || $date !== $parts[0] ) {
				continue;
			}
			if ( $mode === sanitize_key( $parts[1] ) ) {
				return $parts[2];
			}
			if ( 'all' === sanitize_key( $parts[1] ) ) {
				$all = $parts[2];
			}
		}
		return $all;
	}

	private static function parse_periods( $configured ) {
		if ( 'closed' === strtolower( trim( (string) $configured ) ) ) {
			return array();
		}
		$periods = array();
		foreach ( preg_split( '/\s*,\s*/', (string) $configured ) as $period ) {
			if ( preg_match( '/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', trim( $period ), $match ) && self::valid_time( $match[1] ) && self::valid_time( $match[2] ) && $match[2] > $match[1] ) {
				$periods[] = array( $match[1], $match[2] );
			}
		}
		usort( $periods, function ( $a, $b ) { return strcmp( $a[0], $b[0] ); } );
		return $periods;
	}

	private static function at_time( $date, $time ) {
		$parts = array_map( 'absint', explode( ':', $time ) );
		return $date->setTime( $parts[0] ?? 0, $parts[1] ?? 0, 0 );
	}

	private static function valid_time( $value ) {
		return (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $value );
	}

	private static function normalize_weekly_hours( $configured, $fallback ) {
		$configured = is_array( $configured ) ? $configured : array();
		foreach ( range( 1, 7 ) as $day ) {
			$configured[ $day ] = wp_parse_args( isset( $configured[ $day ] ) && is_array( $configured[ $day ] ) ? $configured[ $day ] : array(), $fallback[ $day ] );
		}
		return $configured;
	}

	private static function legacy_service_hours( $weekly ) {
		$hours = array();
		foreach ( range( 1, 7 ) as $day ) {
			$row           = isset( $weekly[ $day ] ) && is_array( $weekly[ $day ] ) ? $weekly[ $day ] : array();
			$hours[ $day ] = array(
				'enabled' => ! empty( $row['enabled'] ) && 'no' !== $row['enabled'] ? 'yes' : 'no',
				'periods' => ( $row['open'] ?? '11:00' ) . '-' . ( $row['close'] ?? '22:00' ),
			);
		}
		return $hours;
	}

	private static function normalize_service_hours( $configured, $fallback ) {
		foreach ( range( 1, 7 ) as $day ) {
			$row = isset( $configured[ $day ] ) && is_array( $configured[ $day ] ) ? $configured[ $day ] : array();
			$configured[ $day ] = wp_parse_args( $row, $fallback[ $day ] );
		}
		return $configured;
	}
}
