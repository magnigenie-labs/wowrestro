<?php
/** Real workflow, tracking and repeated-checkout persistence regression. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Use WP-CLI on an isolated localhost installation.' );
}
$wr_ops_checks = array();
$wr_ops_assert = static function ( $condition, $message ) use ( &$wr_ops_checks ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$wr_ops_checks[] = $message;
};
$wr_ops_request = static function ( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/wowrestro/v1/' . $route );
	foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
	return rest_do_request( $request );
};
$wr_ops_original_user = get_current_user_id();
$wr_ops_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $wr_ops_admins ) { throw new RuntimeException( 'The local site requires an administrator.' ); }
add_filter( 'pre_wp_mail', '__return_true' );
$wr_ops_order = null;
try {
	wp_set_current_user( $wr_ops_admins[0] );
	$wr_ops_order = wc_create_order( array( 'status' => 'checkout-draft', 'created_via' => 'store-api' ) );
	if ( is_wp_error( $wr_ops_order ) ) { throw new RuntimeException( $wr_ops_order->get_error_message() ); }
	$wr_ops_order->set_billing_first_name( 'QA Operations' );
	$wr_ops_order->set_billing_email( 'qa-operations@example.test' );
	$wr_ops_order->set_billing_phone( '02079460000' );
	$wr_ops_order->set_billing_address_1( '10 Test Street' );
	$wr_ops_order->set_billing_city( 'London' );
	$wr_ops_order->set_billing_country( 'GB' );
	$wr_ops_order->set_payment_method( 'bacs' );
	$wr_ops_order->update_meta_data( '_wowrestro_fulfillment', 'pickup' );
	$wr_ops_item = new WC_Order_Item_Product();
	$wr_ops_item->set_name( 'QA add-on meal' );
	$wr_ops_item->set_quantity( 1 );
	$wr_ops_item->set_subtotal( '13.75' );
	$wr_ops_item->set_total( '13.75' );
	$wr_ops_item->add_meta_data( '_wowrestro_modifier_snapshot', array( array( 'name' => 'Extras', 'options' => array( array( 'label' => 'Extra cheese', 'price' => '1.25' ) ) ) ) );
	$wr_ops_order->add_item( $wr_ops_item );
	$wr_ops_order->calculate_totals( false );
	$wr_ops_order->set_status( 'pending' );
	$wr_ops_order->save();
	$wr_ops_token = $wr_ops_order->get_meta( '_wowrestro_tracking_token' );
	$wr_ops_assert( '' !== $wr_ops_token, 'Pending financial transition initializes the original checkout order object' );
	// Blocks continues saving this object after its financial status hooks run.
	WowRestro_Order_Statuses::ensure_order( $wr_ops_order );
	WowRestro_Order_Statuses::mark_new( $wr_ops_order );
	$wr_ops_order->save();
	$wr_ops_order->update_status( 'on-hold' );
	$wr_ops_order->save();
	$wr_ops_order->read_meta_data( true );
	foreach ( array( '_wowrestro_operational_status', '_wowrestro_operational_history', '_wowrestro_tracking_token' ) as $key ) {
		$count = count( array_filter( $wr_ops_order->get_meta_data(), static function ( $meta ) use ( $key ) { return $key === $meta->key; } ) );
		$wr_ops_assert( 1 === $count, 'Repeated checkout saves retain exactly one ' . $key . ' row' );
	}
	$wr_ops_assert( $wr_ops_token === $wr_ops_order->get_meta( '_wowrestro_tracking_token' ), 'Checkout status changes preserve the issued tracking token' );
	foreach ( array( '_wowrestro_operational_status', '_wowrestro_operational_history', '_wowrestro_tracking_token' ) as $key ) {
		$wr_ops_order->add_meta_data( $key, $wr_ops_order->get_meta( $key ), false );
	}
	$wr_ops_order->save();
	WowRestro_Order_Statuses::ensure_order( $wr_ops_order );
	$wr_ops_order->read_meta_data( true );
	foreach ( array( '_wowrestro_operational_status', '_wowrestro_operational_history', '_wowrestro_tracking_token' ) as $key ) {
		$wr_ops_assert( 1 === count( array_filter( $wr_ops_order->get_meta_data(), static function ( $meta ) use ( $key ) { return $key === $meta->key; } ) ), 'Existing duplicate rows are normalized for ' . $key );
	}
	$wr_ops_order->update_meta_data( '_qa_other_plugin_pending', 'preserve me' );
	WowRestro_Order_Statuses::set_status( $wr_ops_order, 'new' );
	$wr_ops_order->save();
	$wr_ops_assert( 'preserve me' === wc_get_order( $wr_ops_order->get_id() )->get_meta( '_qa_other_plugin_pending' ), 'Workflow synchronization preserves unrelated pending checkout metadata' );
	$wr_ops_query_notices = array();
	$wr_ops_notice = static function ( $function, $message ) use ( &$wr_ops_query_notices ) { $wr_ops_query_notices[] = $function . ': ' . $message; };
	add_action( 'doing_it_wrong_run', $wr_ops_notice, 10, 2 );
	try {
		$matches = WowRestro_Order_Statuses::query_orders( array( 'return' => 'ids', 'limit' => 5, 'billing_email' => 'qa-operations@example.test', 'meta_query' => array( array( 'key' => '_qa_other_plugin_pending', 'value' => 'preserve me' ) ) ) );
		$nonmatches = WowRestro_Order_Statuses::query_orders( array( 'return' => 'ids', 'limit' => 5, 'billing_email' => 'other@example.test', 'meta_query' => array( array( 'key' => '_qa_other_plugin_pending', 'value' => 'preserve me' ) ) ) );
	} finally { remove_action( 'doing_it_wrong_run', $wr_ops_notice, 10 ); }
	$wr_ops_assert( in_array( $wr_ops_order->get_id(), $matches, true ) && ! $nonmatches, 'Order metadata queries retain native billing filters on the active datastore' );
	$wr_ops_assert( ! $wr_ops_query_notices, 'Order metadata queries do not trigger unsupported-argument notices' );
	$wr_ops_route = 'operations/orders/' . $wr_ops_order->get_id();
	$detail = $wr_ops_request( 'GET', $wr_ops_route );
	$wr_ops_assert( 200 === $detail->get_status(), 'Administrator can retrieve the actual operations detail endpoint' );
	$detail = $detail->get_data();
	$wr_ops_assert( 'Extra cheese' === $detail['items'][0]['modifiers'][0]['options'][0]['label'], 'Operations detail preserves selected add-on options' );
	$wr_ops_assert( '02079460000' === $detail['phone'] && 'qa-operations@example.test' === $detail['email'], 'Operations detail returns the customer contact' );
	$wr_ops_assert( false !== strpos( $detail['billing_address'], '10 Test Street, London' ), 'Operations address preserves readable line separators' );
	$wr_ops_assert( false !== strpos( $detail['total'], '13.75' ), 'Operations detail uses the saved order total' );
	wp_set_current_user( 0 );
	$wr_ops_assert( 401 === $wr_ops_request( 'GET', $wr_ops_route )->get_status(), 'Anonymous visitor cannot read staff order details' );
	$wr_ops_assert( 404 === $wr_ops_request( 'GET', 'tracking/' . $wr_ops_order->get_id(), array( 'key' => 'invalid' ) )->get_status(), 'Customer tracking rejects an incorrect order key' );
	wp_set_current_user( $wr_ops_admins[0] );
	$wr_ops_assert( 428 === $wr_ops_request( 'POST', $wr_ops_route . '/transition', array( 'status' => 'accepted' ) )->get_status(), 'Workflow mutation requires the displayed revision' );
	$wr_ops_assert( 409 === $wr_ops_request( 'POST', $wr_ops_route . '/transition', array( 'status' => 'completed', 'revision' => $detail['revision'] ) )->get_status(), 'Workflow rejects skipping from new directly to completed' );
	$stale_revision = $detail['revision'];
	foreach ( array( 'accepted', 'preparing', 'ready', 'completed' ) as $next ) {
		wp_set_current_user( $wr_ops_admins[0] );
		$response = $wr_ops_request( 'POST', $wr_ops_route . '/transition', array( 'status' => $next, 'revision' => $detail['revision'] ) );
		$wr_ops_assert( 200 === $response->get_status(), 'Operations endpoint transitions pickup to ' . $next );
		$detail = $response->get_data();
		$wr_ops_assert( $next === $detail['status'], 'Operations response reports ' . $next );
		if ( 'accepted' === $next ) {
			$wr_ops_assert( 409 === $wr_ops_request( 'POST', $wr_ops_route . '/transition', array( 'status' => 'preparing', 'revision' => $stale_revision ) )->get_status(), 'Stale board revision cannot overwrite a newer transition' );
		}
		wp_set_current_user( 0 );
		$tracking = $wr_ops_request( 'GET', 'tracking/' . $wr_ops_order->get_id(), array( 'key' => $wr_ops_order->get_order_key() ) );
		$wr_ops_assert( 200 === $tracking->get_status() && $next === $tracking->get_data()['operational_status'], 'Customer tracking observes ' . $next );
		$wr_ops_assert( false !== strpos( $tracking->get_data()['tracker'], 'data-status="' . $next . '"' ), 'Rendered customer tracker marks ' . $next );
	}
	$wr_ops_order = wc_get_order( $wr_ops_order->get_id() );
	$wr_ops_assert( 'on-hold' === $wr_ops_order->get_status(), 'Completing an unpaid BACS pickup preserves its unpaid financial state' );
	$wr_ops_assert( 5 === count( $wr_ops_order->get_meta( '_wowrestro_operational_history' ) ), 'History records initialization and each valid transition exactly once' );
	$wr_ops_assert( false !== strpos( $tracking->get_data()['tracker'], 'Collected' ), 'Completed pickup tracking displays Collected' );
	WP_CLI::success( 'Operations integration checks passed: ' . count( $wr_ops_checks ) );
	echo wp_json_encode( $wr_ops_checks, JSON_PRETTY_PRINT ) . "\n";
} finally {
	if ( $wr_ops_order instanceof WC_Order ) { $wr_ops_order->delete( true ); }
	wp_set_current_user( $wr_ops_original_user );
	remove_filter( 'pre_wp_mail', '__return_true' );
}
