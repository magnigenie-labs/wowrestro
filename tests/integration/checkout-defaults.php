<?php
/** Run with WP-CLI eval-file against an isolated local WordPress/WooCommerce site. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Run only with WP-CLI on an isolated localhost installation.' );
}
if ( ! WC()->session ) {
	WC()->initialize_session();
}
$qa_session = WC()->session->get_session_data();
$qa_count = 0;
$qa_assert = static function ( $condition, $message ) use ( &$qa_count ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$qa_count;
};

try {
	$requested = '2026-11-16T12:00:00Z';
	WC()->session->set( 'wowrestro_fulfillment', 'delivery' );
	WC()->session->set( 'wowrestro_requested_at', $requested );
	$fields = WowRestro_Checkout::classic_fields( array() );
	$qa_assert( 'delivery' === $fields['order']['wowrestro_fulfillment_mode']['default'], 'Classic checkout defaults to the menu mode.' );
	$qa_assert( $requested === $fields['order']['wowrestro_requested_time']['default'], 'Classic checkout retains the selected slot.' );
	$time = $fields['order']['wowrestro_requested_time'];
	$time['return'] = true;
	$html = woocommerce_form_field( 'wowrestro_requested_time', $time, null );
	$qa_assert( false !== strpos( $html, 'value="' . $requested . '"' ), 'WooCommerce actually renders the scheduled time.' );
	$html = woocommerce_form_field( 'wowrestro_requested_time', $time, '' );
	$qa_assert( false === strpos( $html, $requested ), 'An explicit blank remains ASAP rather than restoring the default.' );

	$controller = Automattic\WooCommerce\Blocks\Package::container()->get( Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
	$draft = new WC_Order();
	$draft->set_status( 'checkout-draft' );
	$values = $controller->get_all_fields_from_object( $draft, 'other' );
	$qa_assert( 'delivery' === $values['wowrestro/fulfillment-mode'], 'Blocks draft defaults to the selected mode.' );
	$qa_assert( $requested === $values['wowrestro/requested-time'], 'Blocks draft defaults to the selected slot.' );
	$customer = new WC_Customer();
	$values = $controller->get_all_fields_from_object( $customer, 'other' );
	$qa_assert( $requested === $values['wowrestro/requested-time'], 'Blocks guest checkout receives its session slot.' );
	$draft->update_meta_data( '_wc_other/wowrestro/requested-time', '' );
	$values = $controller->get_all_fields_from_object( $draft, 'other' );
	$qa_assert( '' === $values['wowrestro/requested-time'], 'Blocks keeps an explicitly cleared slot as ASAP.' );
	$order = new WC_Order();
	$order->set_status( 'processing' );
	$values = $controller->get_all_fields_from_object( $order, 'other' );
	$qa_assert( ! isset( $values['wowrestro/requested-time'] ), 'Existing order displays do not inherit a different cart slot.' );
	$qa_assert( null === apply_filters( 'woocommerce_get_default_value_for_wowrestro/requested-time', null, 'billing', $customer ), 'Defaults do not leak into address groups.' );

	WowRestro_Checkout::capture_classic_mode( 'billing_postcode=SW1A+1AA' );
	$qa_assert( 'delivery' === WC()->session->get( 'wowrestro_fulfillment' ) && $requested === WC()->session->get( 'wowrestro_requested_at' ), 'Partial classic updates preserve the current menu selection.' );
	WowRestro_Checkout::capture_classic_mode( 'wowrestro_fulfillment_mode=pickup&wowrestro_requested_time=' );
	$qa_assert( 'pickup' === WC()->session->get( 'wowrestro_fulfillment' ) && '' === WC()->session->get( 'wowrestro_requested_at' ), 'Explicit classic edits can change the mode and request ASAP.' );
	WP_CLI::success( $qa_count . ' native checkout default checks passed.' );
} finally {
	foreach ( array_keys( WC()->session->get_session_data() ) as $key ) {
		WC()->session->__unset( $key );
	}
	foreach ( $qa_session as $key => $value ) {
		WC()->session->set( $key, maybe_unserialize( $value ) );
	}
}
