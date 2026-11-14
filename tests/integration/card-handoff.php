<?php
/** Session/default adapter checks only; no processor or payment is simulated. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Run only on the disposable local site.' );
}
if ( ! WC()->session ) { WC()->initialize_session(); }
if ( ! WC()->cart ) { wc_load_cart(); }
$wr_handoff_original = WC()->session->get( 'wowrestro_checkout_handoff', null );
$wr_handoff_checks = array();
$wr_handoff_assert = static function ( $ok, $message ) use ( &$wr_handoff_checks ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	$wr_handoff_checks[] = $message;
};
try {
	WowRestro_Checkout::store_api_update( array( 'checkout_handoff' => array( 'note' => "Don't ring <b>twice</b>\nLeave at the door", 'status_updates' => true ) ) );
	$wr_handoff_assert( "Don't ring twice\nLeave at the door" === WowRestro_Checkout::classic_handoff_value( null, 'order_comments' ), 'Classic notes retain apostrophes and newlines while removing HTML' );
	$wr_handoff_assert( true === WowRestro_Checkout::classic_handoff_value( null, 'wowrestro_status_updates' ), 'Classic opt-in retains true' );
	$wr_handoff_assert( null === WowRestro_Checkout::classic_handoff_value( null, 'billing_email' ), 'Adapter does not override native contact handling' );
	$wr_handoff_assert( 'Another extension' === WowRestro_Checkout::classic_handoff_value( 'Another extension', 'order_comments' ), 'Existing checkout field filters keep precedence' );
	WowRestro_Checkout::store_api_update( array( 'checkout_handoff' => array( 'note' => '', 'status_updates' => false ) ) );
	$wr_handoff_assert( '' === WowRestro_Checkout::classic_handoff_value( null, 'order_comments' ), 'Cleared notes remain empty' );
	$wr_handoff_assert( false === WowRestro_Checkout::classic_handoff_value( null, 'wowrestro_status_updates' ), 'Explicit false opt-in is not restored to true' );
	WowRestro_Checkout::store_api_update( array( 'checkout_handoff' => array( 'note' => array( 'invalid' ), 'status_updates' => 'false' ) ) );
	$wr_handoff_assert( '' === WowRestro_Checkout::classic_handoff_value( null, 'order_comments' ), 'Malformed note data is safely ignored' );
	$wr_handoff_assert( false === WowRestro_Checkout::classic_handoff_value( null, 'wowrestro_status_updates' ), 'String false does not accidentally opt in' );
	WowRestro_Checkout::clear_handoff();
	$wr_handoff_assert( null === WowRestro_Checkout::classic_handoff_value( null, 'order_comments' ), 'Clearing the cart removes the handoff note default' );
	$wr_handoff_assert( null === WowRestro_Checkout::classic_handoff_value( null, 'wowrestro_status_updates' ), 'Clearing the cart removes the handoff preference' );
	WP_CLI::success( count( $wr_handoff_checks ) . ' card handoff session checks passed.' );
	echo wp_json_encode( $wr_handoff_checks, JSON_PRETTY_PRINT ) . "\n";
} finally {
	WC()->session->set( 'wowrestro_checkout_handoff', $wr_handoff_original );
}
