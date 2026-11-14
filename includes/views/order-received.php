<?php
/**
 * Order received for restaurant orders: WooCommerce's thank-you page in the order flow's design.
 *
 * Loaded through the wc_get_template filter in place of checkout/thankyou.php, so it keeps the
 * order-received URL that gateways return to and still fires their thank-you hooks. The
 * [wowrestro_tracking] shortcode includes it with $wowrestro_hooks set to false.
 *
 * @package WowRestro
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

$wowrestro_hooks    = $wowrestro_hooks ?? true;
$wowrestro_delivery = 'delivery' === WowRestro_Storefront::order_mode( $order );
$wowrestro_profile  = class_exists( 'WowRestro_Onboarding' ) ? WowRestro_Onboarding::profile() : array();
$wowrestro_template = WowRestro_Storefront::template_number();
$wowrestro_capacity_review = 'yes' === $order->get_meta( '_wowrestro_capacity_review' ) && ! $order->has_status( 'refunded' );
$wowrestro_failed   = $order->has_status( 'failed' );
$wowrestro_closed   = $order->has_status( array( 'cancelled', 'refunded' ) );
$wowrestro_attention = $wowrestro_capacity_review || $wowrestro_failed || $wowrestro_closed;
$wowrestro_promise  = $wowrestro_attention ? '' : WowRestro_Storefront::promised_label( $order );
$wowrestro_first    = $order->get_billing_first_name();
$wowrestro_extra    = '';
$wowrestro_heading = $order->has_status( array( 'pending', 'on-hold' ) ) ? __( 'Order #%s placed - awaiting payment', 'wowrestro' ) : __( 'Order #%s confirmed', 'wowrestro' );
$wowrestro_title = __( 'The kitchen has it.', 'wowrestro' );
$wowrestro_message = '';
if ( $wowrestro_capacity_review ) {
	$wowrestro_heading = __( 'Order #%s requires review', 'wowrestro' );
	$wowrestro_title = __( 'Please contact the restaurant.', 'wowrestro' );
	$wowrestro_message = $order->get_date_paid() ? __( 'Your payment was received, but your fulfillment time could not be confirmed. Contact the restaurant to arrange fulfillment or discuss a refund.', 'wowrestro' ) : __( 'Your fulfillment time could not be confirmed. Contact the restaurant before trying to pay again.', 'wowrestro' );
} elseif ( $wowrestro_failed ) {
	$wowrestro_heading = __( 'Order #%s payment failed', 'wowrestro' );
	$wowrestro_title = __( 'Payment was not completed.', 'wowrestro' );
	$wowrestro_message = __( 'Your payment could not be completed. Retry payment or contact the restaurant for help.', 'wowrestro' );
} elseif ( $wowrestro_closed ) {
	$wowrestro_heading = $order->has_status( 'refunded' ) ? __( 'Order #%s refunded', 'wowrestro' ) : __( 'Order #%s cancelled', 'wowrestro' );
	$wowrestro_title = __( 'This order is closed.', 'wowrestro' );
	$wowrestro_message = __( 'Contact the restaurant if you have questions about this order or its payment.', 'wowrestro' );
}

if ( $wowrestro_hooks ) {
	do_action( 'woocommerce_before_thankyou', $order->get_id() );
	// Gateways print their instructions here (bank details for transfers); the order table is this page's own.
	ob_start();
	do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
	remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
	do_action( 'woocommerce_thankyou', $order->get_id() );
	add_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
	$wowrestro_extra = ob_get_clean();
}

$wowrestro_rows = $order->get_order_item_totals();
unset( $wowrestro_rows['payment_method'], $wowrestro_rows['order_total'] );
if ( $wowrestro_promise ) {
	$wowrestro_rows['wowrestro_time'] = array(
		'label' => $wowrestro_delivery ? __( 'Delivery time', 'wowrestro' ) : __( 'Pickup time', 'wowrestro' ),
		'value' => esc_html( $wowrestro_promise ),
	);
}

if ( $wowrestro_delivery ) {
	$wowrestro_place_name  = $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name();
	$wowrestro_place_lines = implode( ', ', array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_postcode() ) ) );
} else {
	$wowrestro_store       = WowRestro_Stores::current();
	$wowrestro_place_name  = $wowrestro_store ? $wowrestro_store['name'] : ( $wowrestro_profile['name'] ?? get_bloginfo( 'name' ) );
	$wowrestro_place_lines = WowRestro_Storefront::store_address();
}
$wowrestro_phone = (string) ( $wowrestro_profile['phone'] ?? '' );
$wowrestro_contact = $wowrestro_delivery
	? array( $order->get_billing_phone(), $order->get_billing_email() )
	: array( $wowrestro_phone, $wowrestro_profile['email'] ?? '' );
?>
<section class="wowrestro-menu wowrestro-receipt wr-template-<?php echo esc_attr( $wowrestro_template ); ?>" data-oe="1" data-oe-ready="1"<?php echo WowRestro_Storefront::root_attributes( $wowrestro_template, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by root_attributes(). ?>>
	<div class="oe-screen">
		<div class="oe-shell oe-done">
			<div class="oe-done__head">
				<div class="oe-done__tick" aria-hidden="true"><?php echo $wowrestro_attention ? '!' : '&#10003;'; ?></div>
				<div class="oe-done__kicker">
					<?php
					/* translators: %s: order number */
					echo esc_html( sprintf( $wowrestro_heading, $order->get_order_number() ) );
					?>
				</div>
				<h2 class="oe-done__title">
					<?php echo esc_html( $wowrestro_first ? sprintf( __( 'Thank you, %s.', 'wowrestro' ), $wowrestro_first ) : __( 'Thank you.', 'wowrestro' ) ); ?><br>
					<em><?php echo esc_html( $wowrestro_title ); ?></em>
				</h2>
				<p class="oe-done__lede">
					<?php
					if ( $wowrestro_attention ) {
						echo esc_html( $wowrestro_message );
					} else {
						if ( $order->get_billing_email() ) {
							echo esc_html( sprintf( __( 'We have sent your confirmation to %s.', 'wowrestro' ), $order->get_billing_email() ) ) . ' ';
						}
						echo esc_html(
							$wowrestro_delivery
								? sprintf( __( 'We will bring it to %s.', 'wowrestro' ), $order->get_shipping_address_1() ?: __( 'your address', 'wowrestro' ) )
								: sprintf( __( 'Collect it from %s.', 'wowrestro' ), $wowrestro_place_lines ?: $wowrestro_place_name )
						);
					}
					?>
				</p>
				<?php if ( $wowrestro_failed && ! $wowrestro_capacity_review && $order->needs_payment() ) : ?>
					<a class="oe-again" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Retry payment', 'wowrestro' ); ?></a>
				<?php endif; ?>
			</div>

			<dl class="oe-meta">
				<div class="oe-meta__cell"><dt class="oe-meta__label"><?php esc_html_e( 'Order', 'wowrestro' ); ?></dt><dd class="oe-meta__value">#<?php echo esc_html( $order->get_order_number() ); ?></dd></div>
				<div class="oe-meta__cell"><dt class="oe-meta__label"><?php esc_html_e( 'Placed', 'wowrestro' ); ?></dt><dd class="oe-meta__value"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></dd></div>
				<div class="oe-meta__cell"><dt class="oe-meta__label"><?php esc_html_e( 'Total', 'wowrestro' ); ?></dt><dd class="oe-meta__value"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></dd></div>
				<div class="oe-meta__cell"><dt class="oe-meta__label"><?php esc_html_e( 'Payment', 'wowrestro' ); ?></dt><dd class="oe-meta__value"><?php echo esc_html( $order->get_payment_method_title() ?: '-' ); ?></dd></div>
			</dl>

			<?php
			if ( '' !== trim( wp_strip_all_tags( $wowrestro_extra ) ) ) {
				echo '<div class="oe-instructions">' . $wowrestro_extra . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output of WooCommerce's thank-you hooks, printed as WooCommerce prints it.
			} else {
				echo $wowrestro_extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Invisible hook output such as tracking scripts.
			}
			if ( ! $wowrestro_attention ) {
				echo WowRestro_Storefront::tracker_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by tracker_html().
			}
			?>

			<div class="oe-done__cols">
				<div class="oe-recap">
					<div class="oe-recap__head"><?php esc_html_e( 'Order details', 'wowrestro' ); ?></div>
					<div class="oe-recap__lines">
						<?php
						foreach ( $order->get_items() as $wowrestro_item ) :
							$wowrestro_product = $wowrestro_item->get_product();
							$wowrestro_image   = $wowrestro_product ? wp_get_attachment_image_url( $wowrestro_product->get_image_id(), 'woocommerce_gallery_thumbnail' ) : '';
							$wowrestro_options = array();
							foreach ( $wowrestro_item->get_formatted_meta_data() as $wowrestro_meta ) {
								$wowrestro_options[] = wp_strip_all_tags( $wowrestro_meta->display_value );
							}
							foreach ( WowRestro_Cart::order_item_display_data( $wowrestro_item ) as $wowrestro_option ) {
								$wowrestro_options[] = $wowrestro_option['value'];
							}
							?>
							<div class="oe-recap__line">
								<div class="oe-recap__img"<?php echo $wowrestro_image ? ' style="background-image:url(' . esc_url( $wowrestro_image ) . ')"' : ''; ?>></div>
								<div class="oe-recap__text">
									<div class="oe-recap__name"><?php echo esc_html( $wowrestro_item->get_quantity() . ' × ' . $wowrestro_item->get_name() ); ?></div>
									<?php if ( $wowrestro_options ) : ?>
										<div class="oe-recap__sub"><?php echo esc_html( implode( ' · ', array_filter( array_map( 'trim', $wowrestro_options ) ) ) ); ?></div>
									<?php endif; ?>
								</div>
								<div class="oe-recap__price"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $wowrestro_item ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="oe-recap__rows">
						<?php foreach ( $wowrestro_rows as $wowrestro_row ) : ?>
							<div><span><?php echo esc_html( rtrim( wp_strip_all_tags( $wowrestro_row['label'] ), ': ' ) ); ?></span><b><?php echo wp_kses_post( $wowrestro_row['value'] ); ?></b></div>
						<?php endforeach; ?>
						<div class="oe-recap__grand"><span><?php echo esc_html( $order->get_date_paid() ? __( 'Total paid', 'wowrestro' ) : __( 'Total', 'wowrestro' ) ); ?></span><span><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span></div>
					</div>
				</div>
				<div class="oe-side">
					<div class="oe-addr">
						<div class="oe-addr__label"><?php echo esc_html( $wowrestro_attention ? ( $wowrestro_delivery ? __( 'Order address', 'wowrestro' ) : __( 'Restaurant', 'wowrestro' ) ) : ( $wowrestro_delivery ? __( 'Delivering to', 'wowrestro' ) : __( 'Collect from', 'wowrestro' ) ) ); ?></div>
						<div class="oe-addr__name"><?php echo esc_html( $wowrestro_place_name ); ?></div>
						<div class="oe-addr__lines"><?php echo esc_html( $wowrestro_place_lines ); ?></div>
						<div class="oe-addr__lines oe-addr__contact"><?php echo esc_html( implode( ' · ', array_filter( $wowrestro_contact ) ) ); ?></div>
					</div>
					<div class="oe-help">
						<div class="oe-help__title"><?php esc_html_e( 'Something wrong?', 'wowrestro' ); ?></div>
						<div class="oe-help__body">
							<?php
							/* translators: %s: order number */
							echo wp_kses( sprintf( __( 'Contact the kitchen and quote order <b>#%s</b>. Changes are possible until the ticket is on the pass.', 'wowrestro' ), esc_html( $order->get_order_number() ) ), array( 'b' => array() ) );
							if ( $wowrestro_phone ) {
								echo ' <a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $wowrestro_phone ) ) . '">' . esc_html( sprintf( __( 'Call %s', 'wowrestro' ), $wowrestro_phone ) ) . '</a>';
							}
							?>
						</div>
						<a class="oe-again" href="<?php echo esc_url( WowRestro_Storefront::menu_url() ); ?>"><?php esc_html_e( 'Order again', 'wowrestro' ); ?></a>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
<?php
// Native card checkout bypasses the custom form's success callback. Clear its
// browser draft only after acceptance, and never while another cart is active.
$wowrestro_accepted = $order->has_status( array( 'processing', 'completed' ) )
	|| ( $order->has_status( 'on-hold' ) && in_array( $order->get_payment_method(), array( 'bacs', 'cheque', 'cod' ), true ) );
if ( $wowrestro_accepted && function_exists( 'WC' ) && WC()->cart && WC()->cart->is_empty() ) :
?>
<script>try { sessionStorage.removeItem('wowrestroCheckout'); } catch (e) {}</script>
<?php endif; ?>
<?php if ( ! $wowrestro_attention && $order->get_meta( '_wowrestro_tracking_token' ) ) : ?>
<script>
( function () {
	// The kitchen moves the order along; refresh the tracker while this page stays open.
	var url = <?php echo wp_json_encode( add_query_arg( 'key', $order->get_order_key(), rest_url( 'wowrestro/v1/tracking/' . $order->get_id() ) ) ); ?>;
	var timer = setInterval( function () {
		var tracker = document.querySelector( '[data-oe-tracker]' );
		if ( ! tracker || tracker.hasAttribute( 'data-final' ) ) return clearInterval( timer );
		if ( document.hidden ) return;
		fetch( url, { credentials: 'same-origin' } ).then( function ( r ) { return r.ok ? r.json() : null; } ).then( function ( data ) {
			var next = document.createElement( 'div' );
			next.innerHTML = data && data.tracker ? data.tracker : '';
			next = next.firstElementChild;
			if ( ! next || next.dataset.status === tracker.dataset.status ) return;
			// Same live region, new contents, so the change is announced.
			tracker.innerHTML = next.innerHTML;
			tracker.dataset.status = next.dataset.status;
			if ( next.hasAttribute( 'data-final' ) ) tracker.setAttribute( 'data-final', '' );
		} ).catch( function () {} );
	}, 20000 );
} )();
</script>
<?php endif; ?>
