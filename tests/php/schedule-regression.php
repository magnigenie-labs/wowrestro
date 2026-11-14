<?php
/**
 * Standalone regression checks for workspace schedule persistence and fulfillment.
 * Run: php tests/php/schedule-regression.php
 * WordPress storage/formatting and slot counts are stubbed; production classes run unchanged.
 */

define( 'ABSPATH', __DIR__ );
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

$options = array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i' );
$checks  = 0;

function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_title( $value ) { return sanitize_key( $value ); }
function wp_unslash( $value ) { return $value; }
function wc_format_decimal( $value ) { return (string) $value; }
function __( $value, $domain = '' ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return $value; }
function wp_timezone() { return new DateTimeZone( 'Asia/Kolkata' ); }
function wp_date( $format, $timestamp, $timezone ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format ); }
function apply_filters( $name, $value, ...$args ) {
	return 'wowrestro_now' === $name ? new DateTimeImmutable( '2026-11-16 08:00:00', wp_timezone() ) : $value;
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class WP_REST_Request {
	private $payload;
	public function __construct( $payload ) { $this->payload = $payload; }
	public function get_json_params() { return $this->payload; }
}
class WowRestro_Onboarding { public static function profile() { return array(); } }
class WowRestro_Reservations { public static function settings() { return array(); } }
class WowRestro_Slot_Reservations {
	public static function table_ready() { return true; }
	public static function counts_for_range( ...$args ) { return array(); }
	public static function count_for_slot( ...$args ) { return 0; }
}

require dirname( __DIR__, 2 ) . '/includes/class-wowrestro-fulfillment.php';
require dirname( __DIR__, 2 ) . '/includes/class-wowrestro-settings.php';
require dirname( __DIR__, 2 ) . '/includes/class-wowrestro-app.php';

function expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['checks']++;
}
function save_hours( $hours ) {
	return WowRestro_App::save_configuration( new WP_REST_Request( array( 'settings' => array( 'service_hours' => $hours ) ) ) );
}

$options['wowrestro_settings'] = WowRestro_Fulfillment::defaults();
$hours = $options['wowrestro_settings']['service_hours'];
$hours['pickup'][1]['periods'] = '17:00 - 18:00, 10:00-11:00';
$hours['delivery'][1]['periods'] = '12:00-13:00';
$hours['pickup'][2] = array( 'enabled' => 'no', 'periods' => '09:00-12:00' );
$result = save_hours( $hours );
expect( ! is_wp_error( $result ), 'Valid split shifts should save without requiring a profile update.' );
expect( '10:00-11:00,17:00-18:00' === $result['settings']['service_hours']['pickup'][1]['periods'], 'Split shifts should normalize and survive a reload.' );
expect( '12:00-13:00' === $result['settings']['service_hours']['delivery'][1]['periods'], 'Delivery hours must remain independent.' );
expect( $hours['pickup'][2] === $result['settings']['service_hours']['pickup'][2], 'Closed days must retain their configured hours.' );
expect( array( 'enabled' => 'yes', 'open' => '10:00', 'close' => '11:00' ) === $result['settings']['weekly_hours'][1], 'Legacy mirrors must track saved pickup hours.' );

$before = $options['wowrestro_settings'];
$result = save_hours( array( 'pickup' => array( 3 => array( 'enabled' => 'no' ) ) ) );
expect( ! is_wp_error( $result ), 'A partial day update should save.' );
expect( 'no' === $result['settings']['service_hours']['pickup'][3]['enabled'], 'A partial update should change the requested day.' );
expect( $before['service_hours']['delivery'] === $result['settings']['service_hours']['delivery'], 'A partial pickup update must preserve delivery.' );
expect( $before['service_hours']['pickup'][1] === $result['settings']['service_hours']['pickup'][1], 'A partial update must preserve other pickup days.' );

$invalid_periods = array( '', '09:00', '09:00-09:00', '23:00-02:00', '25:00-26:00', '09:60-10:00', '09:00-11:00,10:00-12:00', '09:00-10:00,', array( '09:00-10:00' ) );
foreach ( $invalid_periods as $periods ) {
	$before = $options['wowrestro_settings'];
	$result = save_hours( array( 'pickup' => array( 1 => array( 'periods' => $periods ) ) ) );
	expect( is_wp_error( $result ) && 400 === $result->data['status'], 'Invalid enabled hours should return a validation error.' );
	expect( $before === $options['wowrestro_settings'], 'Invalid hours must not modify stored configuration.' );
}
foreach ( array( null, 'bad', array( 'pickup' => null ), array( 'pickup' => array( 1 => null ) ), array( 'pickup' => array( 2 => array( 'enabled' => 'no', 'periods' => array() ) ) ) ) as $invalid_shape ) {
	$before = $options['wowrestro_settings'];
	expect( is_wp_error( save_hours( $invalid_shape ) ), 'Malformed schedule data must be rejected.' );
	expect( $before === $options['wowrestro_settings'], 'Malformed schedule data must leave settings untouched.' );
}
$adjacent = $hours;
$adjacent['pickup'][1]['periods'] = '10:00-11:00,11:00-12:00';
expect( true === WowRestro_Settings::validate_service_hours( $adjacent ), 'Adjacent periods must be accepted.' );

$pickup = WowRestro_Fulfillment::available_slots( 'pickup', '2026-11-16' );
$pickup_times = array_map( function ( $slot ) { return ( new DateTimeImmutable( $slot['value'] ) )->setTimezone( wp_timezone() )->format( 'H:i' ); }, $pickup );
expect( array( '10:00', '10:15', '10:30', '10:45', '17:00', '17:15', '17:30', '17:45' ) === $pickup_times, 'Pickup slots must use edited split shifts, exclude the break and closing boundary, and use the WordPress timezone.' );
$delivery = WowRestro_Fulfillment::available_slots( 'delivery', '2026-11-16' );
expect( 4 === count( $delivery ) && '12:00' === $delivery[0]['time_label'] && '12:45' === $delivery[3]['time_label'], 'Delivery slots must use their separate saved hours.' );
expect( array() === WowRestro_Fulfillment::available_slots( 'pickup', '2026-11-17' ), 'A disabled day must offer no slots.' );
expect( 'closed' === WowRestro_Fulfillment::quote( 'pickup', '2026-11-16T13:00:00+05:30' )['reason'], 'Exact promises during the split-shift break must be rejected.' );
expect( ! empty( WowRestro_Fulfillment::quote( 'pickup', '2026-11-16T17:00:00+05:30' )['available'] ), 'An exact promise at the second opening must be accepted.' );

$options['wowrestro_settings']['holidays'] = '2026-11-16';
expect( array() === WowRestro_Fulfillment::available_slots( 'pickup', '2026-11-16' ), 'A holiday must suppress weekly slots after settings change in the same request.' );
$options['wowrestro_settings']['date_overrides'] = '2026-11-16 | pickup | 19:00-20:00';
$overridden = WowRestro_Fulfillment::available_slots( 'pickup', '2026-11-16' );
expect( 4 === count( $overridden ) && '19:00' === $overridden[0]['time_label'], 'A service-specific date override must take precedence over the holiday.' );
expect( array() === WowRestro_Fulfillment::available_slots( 'delivery', '2026-11-16' ), 'A pickup date override must not reopen delivery.' );

echo 'Schedule regressions passed (' . $checks . " checks).\n";
