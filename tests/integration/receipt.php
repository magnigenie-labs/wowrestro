<?php
/**
 * Read-only receipt/email/admin regression checks against an existing local order.
 * wp --path=/path/to/local/site eval-file /path/to/tests/integration/receipt.php ORDER_ID
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use WP-CLI on the isolated local test installation.' );
}
$wr_receipt_order = wc_get_order( absint( $args[0] ?? 0 ) );
if ( ! $wr_receipt_order ) {
	throw new RuntimeException( 'Pass the ID of a local pickup order containing an add-on.' );
}
$wr_receipt_checks = array();
$wr_receipt_assert = static function ( $condition, $message ) use ( &$wr_receipt_checks ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$wr_receipt_checks[] = $message;
};
$wr_receipt_render = static function ( $order ) {
	$wowrestro_hooks = false;
	ob_start();
	include WOWRESTRO_PATH . 'includes/views/order-received.php';
	return ob_get_clean();
};
$wr_receipt_contact = static function ( $html ) {
	preg_match( '/<div class="oe-addr__lines oe-addr__contact">(.*?)<\/div>/s', $html, $match );
	return html_entity_decode( strip_tags( $match[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
};
$wr_receipt_items = array_values( $wr_receipt_order->get_items() );
$wr_receipt_item = $wr_receipt_items[0];
$wr_receipt_snapshot = $wr_receipt_item->get_meta( '_wowrestro_modifier_snapshot', true );
$wr_receipt_label = $wr_receipt_snapshot[0]['options'][0]['label'] ?? '';
$wr_receipt_assert( '' !== $wr_receipt_label, 'Existing order retains its original private add-on snapshot' );
$wr_receipt_html = $wr_receipt_render( $wr_receipt_order );
$wr_receipt_assert( 1 === substr_count( $wr_receipt_html, esc_html( $wr_receipt_label ) ), 'Custom pickup receipt displays the saved add-on exactly once' );
$wr_receipt_profile = WowRestro_Onboarding::profile();
$wr_receipt_assert( ! empty( $wr_receipt_profile['email'] ), 'Restaurant contact email is configured for the pickup test' );
$wr_receipt_assert( false !== strpos( $wr_receipt_contact( $wr_receipt_html ), $wr_receipt_profile['email'] ), 'Pickup address card displays the restaurant email' );
$wr_receipt_assert( false === strpos( $wr_receipt_contact( $wr_receipt_html ), $wr_receipt_order->get_billing_email() ), 'Pickup address card does not substitute the customer email' );

// Changes to this clone are never saved; no fixture or global setting is mutated.
$wr_delivery_order = clone $wr_receipt_order;
$wr_delivery_order->read_meta_data( true );
$wr_delivery_order->update_meta_data( '_wowrestro_fulfillment', 'delivery' );
$wr_receipt_assert( false !== strpos( $wr_receipt_contact( $wr_receipt_render( $wr_delivery_order ) ), $wr_receipt_order->get_billing_email() ), 'Delivery address card retains the customer contact' );
$wr_native_meta = wc_display_item_meta( $wr_receipt_item, array( 'echo' => false ) );
$wr_receipt_assert( 1 === substr_count( $wr_native_meta, esc_html( $wr_receipt_label ) ), 'Native customer order details display the saved add-on once' );
$wr_email_html = wc_get_email_order_items( $wr_receipt_order, array( 'plain_text' => false ) );
$wr_receipt_assert( 1 === substr_count( $wr_email_html, esc_html( $wr_receipt_label ) ), 'Actual WooCommerce HTML email items display the saved add-on once' );
$wr_email_plain = wc_get_email_order_items( $wr_receipt_order, array( 'plain_text' => true ) );
$wr_receipt_assert( 1 === substr_count( $wr_email_plain, $wr_receipt_label ), 'Actual WooCommerce plain-text email items display the saved add-on once' );
ob_start();
do_action( 'woocommerce_after_order_itemmeta', $wr_receipt_item->get_id(), $wr_receipt_item, $wr_receipt_item->get_product() );
$wr_admin_meta = ob_get_clean();
$wr_receipt_assert( 1 === substr_count( $wr_admin_meta, esc_html( $wr_receipt_label ) ), 'Native admin order details display the saved add-on once' );
$wr_hidden_keys = apply_filters( 'woocommerce_hidden_order_itemmeta', array() );
$wr_receipt_assert( in_array( '_wowrestro_modifier_snapshot', $wr_hidden_keys, true ) && in_array( '_wowrestro_special_note', $wr_hidden_keys, true ), 'Private structured metadata is not exposed as editable string fields' );

$wr_note_item = clone $wr_receipt_item;
$wr_note_item->read_meta_data( true );
$wr_note_item->update_meta_data( '_wowrestro_special_note', 'QA no onions <script>alert(1)</script>' );
$wr_note_meta = wc_display_item_meta( $wr_note_item, array( 'echo' => false ) );
$wr_receipt_assert( false !== strpos( $wr_note_meta, 'QA no onions' ), 'Private item instructions are displayed with saved choices' );
$wr_receipt_assert( false === strpos( $wr_note_meta, '<script>' ), 'Item instructions do not emit executable markup' );
$wr_apostrophe_order = clone $wr_receipt_order;
$wr_apostrophe_order->read_meta_data( true );
$wr_apostrophe_item = clone $wr_receipt_item;
$wr_apostrophe_item->read_meta_data( true );
$wr_apostrophe_item->update_meta_data( '_wowrestro_modifier_snapshot', array( array( 'name' => "Chef's extras", 'options' => array( array( 'label' => "Don't add onions", 'price' => 0 ) ) ) ) );
$wr_apostrophe_item->update_meta_data( '_wowrestro_special_note', "Don't forget the sauce" );
$wr_apostrophe_order->add_item( $wr_apostrophe_item );
$wr_plain_email = new WC_Email_Customer_Processing_Order();
$wr_plain_email->object = $wr_apostrophe_order;
$wr_plain_email->email_type = 'plain';
$wr_plain_content = $wr_plain_email->get_content();
$wr_receipt_assert( false !== strpos( $wr_plain_content, "Chef's extras: Don't add onions" ), 'Final WooCommerce plain email preserves apostrophes in add-on names and choices' );
$wr_receipt_assert( false !== strpos( $wr_plain_content, "Don't forget the sauce" ), 'Final WooCommerce plain email preserves apostrophes in item instructions' );
$wr_review_order = clone $wr_receipt_order;
$wr_review_order->read_meta_data( true );
$wr_review_order->update_meta_data( '_wowrestro_capacity_review', 'yes' );
$wr_review_order->set_date_paid( time() );
$wr_review_html = $wr_receipt_render( $wr_review_order );
$wr_receipt_assert( false !== strpos( $wr_review_html, 'requires review' ) && false !== strpos( $wr_review_html, 'Your payment was received' ), 'Late paid order clearly explains that fulfillment needs review' );
$wr_receipt_assert( false === strpos( $wr_review_html, 'The kitchen has it.' ) && false === strpos( $wr_review_html, 'Promised for' ), 'Capacity-review receipt does not confirm unavailable fulfillment' );
$wr_receipt_assert( false === strpos( $wr_review_html, 'data-oe-tracker' ) && false === strpos( $wr_review_html, 'Pickup time' ), 'Capacity-review receipt omits the obsolete tracker and pickup time' );
$wr_review_order->set_date_paid( null );
$wr_receipt_assert( false !== strpos( $wr_receipt_render( $wr_review_order ), 'before trying to pay again' ), 'Unpaid capacity-review receipt does not claim payment was received' );
$wr_review_order->set_status( 'refunded' );
$wr_refunded_review_html = $wr_receipt_render( $wr_review_order );
$wr_receipt_assert( false !== strpos( $wr_refunded_review_html, ' refunded' ) && false === strpos( $wr_refunded_review_html, 'requires review' ), 'An actual refund takes precedence over a durable capacity-review flag' );
foreach ( array( 'failed', 'cancelled', 'refunded' ) as $wr_financial_state ) {
	$wr_failed_order = clone $wr_receipt_order;
	$wr_failed_order->read_meta_data( true );
	$wr_failed_order->set_status( $wr_financial_state );
	$wr_failed_html = $wr_receipt_render( $wr_failed_order );
	$wr_receipt_assert( false === strpos( $wr_failed_html, 'The kitchen has it.' ) && false === strpos( $wr_failed_html, ' confirmed' ), 'Financial ' . $wr_financial_state . ' receipt never claims success' );
	$wr_receipt_assert( false === strpos( $wr_failed_html, 'data-oe-tracker' ) && false === strpos( $wr_failed_html, 'Pickup time' ), 'Financial ' . $wr_financial_state . ' receipt omits the obsolete promise' );
	$wr_receipt_assert( ( 'failed' === $wr_financial_state ) === ( false !== strpos( $wr_failed_html, 'href="' . esc_url( $wr_failed_order->get_checkout_payment_url() ) . '"' ) ), 'Only a failed payable order offers the native payment retry link' );
}
if ( ! WC()->session ) { WC()->initialize_session(); }
if ( ! WC()->cart ) { wc_load_cart(); }
$wr_original_cart = WC()->cart->get_cart_contents();
try {
	WC()->cart->set_cart_contents( array() );
	$wr_receipt_assert( false !== strpos( $wr_receipt_render( $wr_receipt_order ), "sessionStorage.removeItem('wowrestroCheckout')" ), 'Accepted receipt clears its browser draft when the cart is empty' );
	foreach ( array( 'pending', 'failed', 'cancelled' ) as $wr_retry_state ) {
		$wr_retry_order = clone $wr_receipt_order;
		$wr_retry_order->read_meta_data( true );
		$wr_retry_order->set_status( $wr_retry_state );
		$wr_receipt_assert( false === strpos( $wr_receipt_render( $wr_retry_order ), "sessionStorage.removeItem('wowrestroCheckout')" ), 'Financial ' . $wr_retry_state . ' preserves browser details for retry' );
	}
	WC()->cart->set_cart_contents( array( 'qa-other-cart' => array( 'data' => new WC_Product_Simple(), 'quantity' => 1 ) ) );
	$wr_receipt_assert( false === strpos( $wr_receipt_render( $wr_receipt_order ), "sessionStorage.removeItem('wowrestroCheckout')" ), 'Opening an old receipt does not clear another active cart draft' );
} finally {
	WC()->cart->set_cart_contents( $wr_original_cart );
}
$wr_reloaded = wc_get_order( $wr_receipt_order->get_id() );
$wr_receipt_assert( $wr_reloaded->get_total() === $wr_receipt_order->get_total(), 'Receipt rendering does not change the saved order total' );
$wr_receipt_assert( 'pickup' === WowRestro_Storefront::order_mode( $wr_reloaded ), 'Receipt tests do not modify the saved fulfillment mode' );
WP_CLI::success( 'Receipt integration checks passed: ' . count( $wr_receipt_checks ) );
echo wp_json_encode( $wr_receipt_checks, JSON_PRETTY_PRINT ) . "\n";
