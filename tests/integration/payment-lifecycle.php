<?php
/** Real WooCommerce payment lifecycle checks; no processor requests or card simulation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Use WP-CLI on an isolated localhost installation.' );
}
$qa_payment_results = array();
$qa_payment_assert = static function ( $ok, $message ) use ( &$qa_payment_results ) {
	$qa_payment_results[] = array( 'pass' => (bool) $ok, 'check' => $message );
};
$qa_payment_orders = array();
$qa_payment_product = null;
add_filter( 'pre_wp_mail', '__return_true' );
$qa_payment_capacity = static function () { return 1; };
add_filter( 'wowrestro_capacity_for_slot', $qa_payment_capacity );
$qa_payment_make = static function ( $slot ) use ( &$qa_payment_orders, &$qa_payment_product ) {
	$order = wc_create_order( array( 'status' => 'checkout-draft' ) );
	$qa_payment_orders[] = $order;
	$order->set_billing_email( 'qa-payment-lifecycle@example.test' );
	$order->set_payment_method( 'qa_lifecycle_no_processor' );
	$order->add_product( $qa_payment_product, 1 );
	$order->calculate_totals( false );
	$order->update_meta_data( '_wowrestro_order', 'yes' );
	$order->update_meta_data( '_wowrestro_fulfillment', 'pickup' );
	$order->update_meta_data( '_wowrestro_kitchen_slot_gmt', $slot );
	$order->update_meta_data( '_wowrestro_promised_at_gmt', $slot );
	$order->set_status( 'pending' );
	$order->save();
	return $order;
};
$qa_payment_rows = static function ( $order ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wowrestro_slot_reservations WHERE order_id = %d', $order->get_id() ) );
};
try {
	$qa_payment_product = new WC_Product_Simple();
	$qa_payment_product->set_name( 'QA payment lifecycle product' );
	$qa_payment_product->set_status( 'publish' );
	$qa_payment_product->set_catalog_visibility( 'hidden' );
	$qa_payment_product->set_regular_price( '10.00' );
	$qa_payment_product->set_manage_stock( true );
	$qa_payment_product->set_stock_quantity( 100 );
	$qa_payment_product->save();
	$slot = gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS );
	$order = $qa_payment_make( $slot );
	$qa_payment_assert( true === WowRestro_Slot_Reservations::backfill_order( $order ), 'Pending payment owns its future kitchen slot' );
	$qa_payment_assert( 1 === $qa_payment_rows( $order ) && ! $order->get_date_paid(), 'Authentication-pending order remains unpaid with one allocation' );
	$tracking = $order->get_meta( '_wowrestro_tracking_token' );
	$order->update_status( 'failed', 'QA processor-decline lifecycle fixture; no processor was called.' );
	$qa_payment_assert( 0 === $qa_payment_rows( $order ), 'Failed payment releases kitchen capacity' );
	$qa_payment_assert( 'cancelled' === WowRestro_Order_Statuses::current( wc_get_order( $order->get_id() ) ), 'Failed payment closes restaurant workflow' );
	$retry = WowRestro_Slot_Reservations::ensure_payment_capacity( $order );
	$qa_payment_assert( true === $retry && 1 === $qa_payment_rows( $order ), 'Retry reacquires exactly one available allocation before submission' );
	$qa_payment_assert( true === $order->payment_complete( 'qa_success_after_decline' ), 'WooCommerce records successful retry completion' );
	$fresh = wc_get_order( $order->get_id() );
	$qa_payment_assert( $fresh->is_paid() && 'qa_success_after_decline' === $fresh->get_transaction_id(), 'Successful retry persists paid status and transaction' );
	$qa_payment_assert( 'new' === WowRestro_Order_Statuses::current( $fresh ) && 1 === $qa_payment_rows( $fresh ), 'Successful retry reopens fulfillment with one allocation' );
	$qa_payment_assert( $tracking === $fresh->get_meta( '_wowrestro_tracking_token' ), 'Retry preserves tracking identity' );
	$history = $fresh->get_meta( WowRestro_Order_Statuses::META_HISTORY );
	$qa_payment_assert( true === $fresh->payment_complete( 'qa_duplicate_must_not_replace_transaction' ), 'Duplicate completion is accepted idempotently' );
	$fresh = wc_get_order( $order->get_id() );
	$qa_payment_assert( 'qa_success_after_decline' === $fresh->get_transaction_id() && $history === $fresh->get_meta( WowRestro_Order_Statuses::META_HISTORY ) && 1 === $qa_payment_rows( $fresh ), 'Duplicate completion preserves transaction, history and capacity' );
	$qa_payment_assert( false === get_option( 'wowrestro_order_lock_' . $fresh->get_id(), false ), 'Duplicate completion leaves no cross-request order lock' );
	WowRestro_Slot_Reservations::release_all_payment_locks();
	$fresh->update_status( 'refunded', 'QA refund-status lifecycle fixture; no processor was called.' );
	$qa_payment_assert( 0 === $qa_payment_rows( $fresh ) && 'cancelled' === WowRestro_Order_Statuses::current( wc_get_order( $fresh->get_id() ) ), 'Refunded payment releases allocation and closes fulfillment' );
	$qa_payment_assert( true === $fresh->payment_complete( 'qa_capture_replay_after_refund' ), 'Completion replay after refund is harmless' );
	$fresh = wc_get_order( $fresh->get_id() );
	$qa_payment_assert( 'refunded' === $fresh->get_status() && 'qa_success_after_decline' === $fresh->get_transaction_id(), 'Replay cannot reverse a refund or replace its original transaction' );
	$qa_payment_assert( false === get_option( 'wowrestro_order_lock_' . $fresh->get_id(), false ), 'Refunded-order replay leaves no order lock' );

	$expired = $qa_payment_make( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
	$guard = WowRestro_Slot_Reservations::ensure_payment_capacity( $expired );
	$qa_payment_assert( is_wp_error( $guard ) && 'wowrestro_payment_slot_expired' === $guard->get_error_code(), 'Expired capacity blocks a new payment attempt before gateway submission' );
	$qa_payment_assert( true === $expired->payment_complete( 'qa_already_captured_late_callback' ), 'Late successful callback still records already captured payment' );
	$expired = wc_get_order( $expired->get_id() );
	$qa_payment_assert( $expired->is_paid() && 'qa_already_captured_late_callback' === $expired->get_transaction_id(), 'Late callback retains authoritative transaction and paid date' );
	$qa_payment_assert( 'yes' === $expired->get_meta( '_wowrestro_capacity_review' ) && 'cancelled' === WowRestro_Order_Statuses::current( $expired ), 'Late payment is flagged for staff without promising unavailable fulfillment' );
	$qa_payment_assert( 0 === $qa_payment_rows( $expired ), 'Late payment does not create expired or oversold capacity' );
	$late_history = $expired->get_meta( WowRestro_Order_Statuses::META_HISTORY );
	$expired->payment_complete( 'qa_duplicate_late_callback' );
	$expired = wc_get_order( $expired->get_id() );
	$qa_payment_assert( 'qa_already_captured_late_callback' === $expired->get_transaction_id() && $late_history === $expired->get_meta( WowRestro_Order_Statuses::META_HISTORY ) && 'cancelled' === WowRestro_Order_Statuses::current( $expired ), 'Duplicate late callback does not reopen review orders or replace their transaction' );

	$bound_expired = $qa_payment_make( gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) );
	WowRestro_Slot_Reservations::backfill_order( $bound_expired );
	$past_slot = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
	$bound_expired->update_meta_data( '_wowrestro_kitchen_slot_gmt', $past_slot );
	$bound_expired->update_meta_data( '_wowrestro_promised_at_gmt', $past_slot );
	$bound_expired->save();
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'wowrestro_slot_reservations', array( 'kitchen_slot_gmt' => $past_slot, 'promised_at_gmt' => $past_slot ), array( 'order_id' => $bound_expired->get_id() ) );
	$qa_payment_assert( 1 === $qa_payment_rows( $bound_expired ), 'Delayed authentication fixture retains its original bound ledger row' );
	$guard = WowRestro_Slot_Reservations::ensure_payment_capacity( $bound_expired );
	$qa_payment_assert( is_wp_error( $guard ) && 'wowrestro_payment_slot_expired' === $guard->get_error_code(), 'An owned bound row cannot make an expired promise payable' );
	$qa_payment_assert( true === $bound_expired->payment_complete( 'qa_late_authentication_with_bound_row' ), 'Late authentication completion records the captured transaction' );
	$bound_expired = wc_get_order( $bound_expired->get_id() );
	$qa_payment_assert( $bound_expired->is_paid() && 'yes' === $bound_expired->get_meta( '_wowrestro_capacity_review' ) && 'cancelled' === WowRestro_Order_Statuses::current( $bound_expired ) && 0 === $qa_payment_rows( $bound_expired ), 'A late authenticated order with a past bound slot requires staff review' );

	$busy_slot = gmdate( 'Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS );
	$busy = $qa_payment_make( $busy_slot );
	$qa_payment_assert( true === WowRestro_Slot_Reservations::backfill_order( $busy ), 'Original order reserves the single-capacity slot' );
	$busy->update_status( 'failed' );
	$replacement = $qa_payment_make( $busy_slot );
	$qa_payment_assert( true === WowRestro_Slot_Reservations::backfill_order( $replacement ), 'Another customer may reserve released capacity' );
	$guard = WowRestro_Slot_Reservations::ensure_payment_capacity( $busy );
	$qa_payment_assert( is_wp_error( $guard ), 'Unavailable capacity blocks a new pay-for-order attempt' );
	$qa_payment_assert( true === $busy->payment_complete( 'qa_late_capture_after_reallocation' ), 'Late capture is recorded even after released capacity was reallocated' );
	$busy = wc_get_order( $busy->get_id() );
	$qa_payment_assert( $busy->is_paid() && 'yes' === $busy->get_meta( '_wowrestro_capacity_review' ) && 'cancelled' === WowRestro_Order_Statuses::current( $busy ), 'Reallocated-slot callback requires staff review without reopening kitchen work' );
	$qa_payment_assert( 0 === $qa_payment_rows( $busy ) && 1 === $qa_payment_rows( $replacement ), 'Late callback preserves the other customer allocation' );

	$terminal = $qa_payment_make( gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) );
	WowRestro_Slot_Reservations::backfill_order( $terminal );
	$terminal->update_status( 'cancelled' );
	$qa_payment_assert( true === $terminal->payment_complete( 'qa_cancelled_order_late_capture' ), 'A cancelled-order late capture remains financially visible' );
	$terminal = wc_get_order( $terminal->get_id() );
	$qa_payment_assert( 'cancelled' === WowRestro_Order_Statuses::current( $terminal ) && 0 === $qa_payment_rows( $terminal ), 'Cancelled-order payment does not silently restore fulfillment' );
	$qa_payment_assert( 'yes' === $terminal->get_meta( '_wowrestro_terminal_payment_reviewed' ), 'Cancelled-order late capture has a staff-review audit marker' );

	$locked_order = $qa_payment_make( gmdate( 'Y-m-d H:i:s', time() + 6 * DAY_IN_SECONDS ) );
	WowRestro_Slot_Reservations::backfill_order( $locked_order );
	$other_lock = array( 'owner' => wp_generate_uuid4(), 'expires' => time() + 60 );
	add_option( 'wowrestro_order_lock_' . $locked_order->get_id(), $other_lock, '', false );
	$qa_payment_assert( true === $locked_order->payment_complete( 'qa_capture_during_staff_lock' ), 'An unrelated staff lock does not erase successful gateway payment' );
	$locked_order = wc_get_order( $locked_order->get_id() );
	$qa_payment_assert( $locked_order->is_paid() && 'yes' === $locked_order->get_meta( '_wowrestro_capacity_review' ), 'Concurrent payment preserves financial state and flags deferred review' );
	$qa_payment_assert( $other_lock === get_option( 'wowrestro_order_lock_' . $locked_order->get_id() ), 'Gateway callback does not release another request lock' );
	$qa_payment_assert( 0 === $qa_payment_rows( $locked_order ), 'Capacity-review callback releases its allocation immediately while workflow closure waits' );
	WowRestro_Slot_Reservations::backfill_order( $locked_order );
	$qa_payment_assert( 0 === $qa_payment_rows( $locked_order ), 'Direct reconciliation cannot recreate allocation for a capacity-review order' );
	delete_option( 'wowrestro_order_lock_' . $locked_order->get_id() );
	WowRestro_Order_Statuses::reconcile_operational_status( $locked_order->get_id() );
	$locked_order = wc_get_order( $locked_order->get_id() );
	$qa_payment_assert( 'cancelled' === WowRestro_Order_Statuses::current( $locked_order ) && 0 === $qa_payment_rows( $locked_order ), 'Deferred reconciliation closes unconfirmed fulfillment after lock release' );

	$failed = array_filter( $qa_payment_results, static function ( $row ) { return ! $row['pass']; } );
	echo wp_json_encode( array( 'passed' => count( $qa_payment_results ) - count( $failed ), 'failed' => count( $failed ), 'checks' => $qa_payment_results ), JSON_PRETTY_PRINT ) . "\n";
	if ( $failed ) { throw new RuntimeException( 'Payment lifecycle regression failed.' ); }
} finally {
	WowRestro_Slot_Reservations::release_all_payment_locks();
	foreach ( $qa_payment_orders as $order ) {
		delete_option( 'wowrestro_order_lock_' . $order->get_id() );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( range( 1, 5 ) as $attempt ) {
				foreach ( array( 'closed', 'payment' ) as $target ) {
					as_unschedule_all_actions( 'wowrestro_reconcile_operational_status', array( $order->get_id(), $target, $attempt ), 'wowrestro' );
				}
			}
		}
		WowRestro_Slot_Reservations::release_order( $order );
		$order->delete( true );
	}
	if ( $qa_payment_product ) { $qa_payment_product->delete( true ); }
	remove_filter( 'wowrestro_capacity_for_slot', $qa_payment_capacity );
}
