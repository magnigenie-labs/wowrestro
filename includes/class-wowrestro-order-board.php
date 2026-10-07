<?php
/**
 * Live operations board.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render and hydrate the live kitchen board.
 */
final class WowRestro_Order_Board {
	/** Register admin-only hooks. */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Load board assets on the orders screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'wowrestro_page_wowrestro-orders', 'toplevel_page_wowrestro-orders', 'woocommerce_page_wowrestro-orders', 'toplevel_page_wowrestro-app' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wowrestro-board', WOWRESTRO_URL . 'assets/css/order-board.css', array(), WOWRESTRO_VERSION );
		wp_enqueue_style( 'wowrestro-icons', WOWRESTRO_URL . 'assets/css/wowrestro-icons.css', array(), WOWRESTRO_VERSION );
		wp_enqueue_style( 'wowrestro-redesign', WOWRESTRO_URL . 'assets/css/wowrestro-redesign.css', array( 'wowrestro-admin', 'wowrestro-icons' ), WOWRESTRO_VERSION );
		// assets/js/order-board.js stays frozen at its 0.2.0 bytes for
		// bin/verify-frontend-unchanged.sh; the redesigned board ships beside it.
		wp_enqueue_script( 'wowrestro-firebase-app', 'https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js', array(), '10.14.1', true );
		wp_enqueue_script( 'wowrestro-firebase-messaging', 'https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js', array( 'wowrestro-firebase-app' ), '10.14.1', true );
		wp_enqueue_script( 'wowrestro-shell', WOWRESTRO_URL . 'assets/js/wowrestro-shell.js', array(), WOWRESTRO_VERSION, true );
		wp_enqueue_script( 'wowrestro-board', WOWRESTRO_URL . 'assets/js/order-board-redesign.js', array( 'wowrestro-shell', 'wowrestro-firebase-messaging' ), WOWRESTRO_VERSION, true );
		$products     = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
				'type'    => array( 'simple', 'variable' ),
			)
		);
		$product_data = array();
		foreach ( $products as $product ) {
			if ( ! $product->is_in_stock() ) {
				continue;
			}
			$variations = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
						continue;
					}
					$variations[] = array(
						'id'    => $variation->get_id(),
						'name'  => $variation->get_name(),
						'price' => html_entity_decode( wp_strip_all_tags( wc_price( $variation->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
					);
				}
				if ( ! $variations ) {
					continue;
				}
			} elseif ( ! $product->is_purchasable() ) {
				continue;
			}
			$product_data[] = array(
				'id'         => $product->get_id(),
				'name'       => $product->get_name(),
				'type'       => $product->get_type(),
				'price'      => html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
				'variations' => $variations,
				'modifiers'  => class_exists( 'WowRestro_Modifiers' ) ? WowRestro_Modifiers::groups_for_product( $product ) : array(),
			);
		}

		$statuses = array();
		foreach ( array( 'new', 'accepted', 'preparing', 'ready', 'out_for_delivery' ) as $state ) {
			$statuses[] = array( $state, WowRestro_Order_Statuses::label( $state ) );
		}

		$location = class_exists( 'WowRestro_Locations' ) ? WowRestro_Locations::current() : '';
		wp_localize_script(
			'wowrestro-board',
			'WowRestroBoard',
			array(
				'devicesUrl'       => rest_url( 'wowrestro/v1/operations/devices' ),
				'queueUrl'         => add_query_arg(
					array_filter(
						array(
							'per_page' => 50,
							'period'   => 'today',
							'location' => $location,
						)
					),
					rest_url( 'wowrestro/v1/operations/orders' )
				),
				'operationsUrl'    => rest_url( 'wowrestro/v1/operations/orders/' ),
				'manualUrl'        => add_query_arg( 'legacy_page_id', wp_generate_uuid4(), rest_url( 'wowrestro/v1/orders/manual' ) ),
				'createUrl'        => rest_url( 'wowrestro/v1/operations/orders' ),
				'quoteUrl'         => rest_url( 'wowrestro/v1/operations/quote' ),
				'statusUrl'        => rest_url( 'wowrestro/v1/operations/pause' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'firebase'         => array(
					'config'           => array(
						'apiKey'            => 'AIzaSyCPLF7XTg3VObNQIK_ZrsywMK7WA7XhxX4',
						'authDomain'        => 'woo-restro.firebaseapp.com',
						'projectId'         => 'woo-restro',
						'storageBucket'     => 'woo-restro.firebasestorage.app',
						'messagingSenderId' => '174492569623',
						'appId'             => '1:174492569623:web:41c153d582c8b3f2f9cc52',
					),
					'vapidKey'         => 'BIrS-mVVRQQ48inrAZh0vfOLujZg60qg_wEAuLJi795_AwUBmqPQ-JzP_DQ7U_RROPJ8eKiXVF8E6UFpD6Qoy_A',
					'serviceWorkerUrl' => add_query_arg( 'ver', WOWRESTRO_VERSION, WOWRESTRO_URL . 'assets/js/firebase-messaging-sw.js' ),
					'liveOrdersUrl'    => WowRestro_App::url( '/live-orders' ),
					'location'         => $location,
				),
				'products'         => $product_data,
				'statuses'         => $statuses,
				'canOperate'       => current_user_can( 'wowrestro_operate_orders' ) || current_user_can( 'manage_woocommerce' ),
				'transitionLabels' => array(
					'accepted'         => __( 'Accept order', 'wowrestro' ),
					'preparing'        => __( 'Start preparing', 'wowrestro' ),
					'ready'            => __( 'Mark as ready', 'wowrestro' ),
					'out_for_delivery' => __( 'Out for delivery', 'wowrestro' ),
					'completed'        => __( 'Complete', 'wowrestro' ),
					'cancelled'        => __( 'Cancel', 'wowrestro' ),
				),
				'labels'           => array(
					'empty'           => __( 'No active orders in the queue right now.', 'wowrestro' ),
					'calm'            => __( 'The kitchen is calm.', 'wowrestro' ),
					'noMatch'         => __( 'Nothing matches that filter.', 'wowrestro' ),
					'noMatchCopy'     => __( 'Try another status or clear the search.', 'wowrestro' ),
					'error'           => __( 'Could not refresh orders. Retrying…', 'wowrestro' ),
					'updated'         => __( 'Live · updated just now', 'wowrestro' ),
					'updating'        => __( 'Updating…', 'wowrestro' ),
					'upToDate'        => __( 'Live · no changes', 'wowrestro' ),
					'paused'          => __( 'Ordering paused', 'wowrestro' ),
					'open'            => __( 'Ordering open', 'wowrestro' ),
					'pause'           => __( 'Pause ordering', 'wowrestro' ),
					'resume'          => __( 'Resume ordering', 'wowrestro' ),
					'soundOn'         => __( 'Sound alerts on', 'wowrestro' ),
					'soundOff'        => __( 'Enable sound alerts', 'wowrestro' ),
					'liveAlerts'      => __( 'Enable live alerts', 'wowrestro' ),
					'liveAlertsError' => __( 'Live alerts unavailable', 'wowrestro' ),
					'liveAlertsRetry' => __( 'Click to retry the Firebase connection.', 'wowrestro' ),
					'delivery'        => __( 'Delivery', 'wowrestro' ),
					'pickup'          => __( 'Pickup', 'wowrestro' ),
					'late'            => __( 'Late', 'wowrestro' ),
					'promise'         => __( 'Promise', 'wowrestro' ),
					'asap'            => __( 'ASAP', 'wowrestro' ),
					'orderTime'       => __( 'Order time', 'wowrestro' ),
					'serviceTime'     => __( 'Service time', 'wowrestro' ),
					'notScheduled'    => __( 'Not scheduled', 'wowrestro' ),
					'all'             => __( 'All', 'wowrestro' ),
					'printTicket'     => __( 'Print ticket', 'wowrestro' ),
					'noAction'        => __( 'No action available', 'wowrestro' ),
					'noActionHint'    => __( 'This order has no restaurant workflow status yet, so there is nothing to advance to. Open Diagnostics or re-save the order.', 'wowrestro' ),
					'orderDetails'    => __( 'Order details', 'wowrestro' ),
					'order'           => __( 'Order', 'wowrestro' ),
					'close'           => __( 'Close', 'wowrestro' ),
					'workflow'        => __( 'Workflow', 'wowrestro' ),
					'items'           => __( 'Items', 'wowrestro' ),
					'kitchenNote'     => __( 'Kitchen note', 'wowrestro' ),
					'totals'          => __( 'Totals', 'wowrestro' ),
					'subtotal'        => __( 'Subtotal', 'wowrestro' ),
					'shipping'        => __( 'Shipping', 'wowrestro' ),
					'discount'        => __( 'Discount', 'wowrestro' ),
					'tax'             => __( 'Tax', 'wowrestro' ),
					'total'           => __( 'Total', 'wowrestro' ),
					'billing'         => __( 'Billing address', 'wowrestro' ),
					'deliveryAddress' => __( 'Delivery address', 'wowrestro' ),
					'history'         => __( 'History', 'wowrestro' ),
					'menuItem'        => __( 'Menu item', 'wowrestro' ),
					'chooseItem'      => __( 'Choose an item', 'wowrestro' ),
					'quantity'        => __( 'Quantity', 'wowrestro' ),
					'variation'       => __( 'Variation', 'wowrestro' ),
					'chooseVariation' => __( 'Choose a variation', 'wowrestro' ),
					'itemNote'        => __( 'Item instructions', 'wowrestro' ),
					'removeItem'      => __( 'Remove item', 'wowrestro' ),
					'checking'        => __( 'Checking availability and WooCommerce service methods…', 'wowrestro' ),
					'chooseRate'      => __( 'Choose a service method', 'wowrestro' ),
					'noRate'          => __( 'No shipping method required for pickup', 'wowrestro' ),
					'rateReady'       => __( 'Service is available. Confirm the WooCommerce method, then create the order.', 'wowrestro' ),
					'pickupReady'     => __( 'Pickup is available. Create the order when ready.', 'wowrestro' ),
					'quoteFirst'      => __( 'Check availability before creating the order.', 'wowrestro' ),
					'creating'        => __( 'Creating order…', 'wowrestro' ),
					/* translators: %s: order number. */
					'created'         => __( 'Phone order #%s created.', 'wowrestro' ),
					'manualError'     => __( 'Could not create the phone order.', 'wowrestro' ),
				),
			)
		);
	}

	/** Render the live operations board shell; orders are hydrated over REST. */
	public static function page() {
		$paused = 'yes' === WowRestro_Fulfillment::settings()['orders_paused'];
		?>
		<div class="wrap wowrestro-board-page wra-root wowrestro-live-board">
			<div class="wra-main">
			<?php
			if ( class_exists( 'WowRestro_Locations' ) ) {
				echo WowRestro_Locations::selector(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the selector renderer.
			}
			?>

			<?php WowRestro_Admin::shell_topbar( 'orders', $paused ); ?>

			<div class="wra-head">
				<div>
					<h1 class="wra-title"><?php esc_html_e( 'Live Orders', 'wowrestro' ); ?></h1>
					<p class="wra-boardstatus" data-wowrestro-status aria-live="polite"></p>
				</div>
				<div class="wra-head__actions">
					<?php if ( current_user_can( 'wowrestro_manage_operations' ) ) : ?>
					<button class="wra-btn" type="button" data-wowrestro-pause aria-pressed="<?php echo $paused ? 'true' : 'false'; ?>"><i class="<?php echo $paused ? 'ph-fill ph-play-circle' : 'ph-fill ph-pause-circle'; ?>" aria-hidden="true"></i><?php echo esc_html( $paused ? __( 'Resume ordering', 'wowrestro' ) : __( 'Pause ordering', 'wowrestro' ) ); ?></button>
					<?php endif; ?>
					<button class="wra-btn" type="button" data-wowrestro-refresh><i class="ph ph-arrow-clockwise" aria-hidden="true"></i><?php esc_html_e( 'Refresh orders', 'wowrestro' ); ?></button>
					<button class="wra-btn" type="button" data-wowrestro-alerts aria-pressed="false"><i class="ph ph-speaker-simple-slash" aria-hidden="true"></i><?php esc_html_e( 'Enable sound alerts', 'wowrestro' ); ?></button>
					<button class="wra-btn" type="button" data-wowrestro-print onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i><?php esc_html_e( 'Print board', 'wowrestro' ); ?></button>
				</div>
			</div>

			<div class="wra-bar wra-rise wra-rise--1">
				<div class="wra-bar__pills" data-wowrestro-filters></div>
				<div class="wra-bar__end">
					<span class="wra-search">
						<i class="ph ph-magnifying-glass" aria-hidden="true"></i>
						<input type="search" data-wowrestro-search placeholder="<?php esc_attr_e( 'Search order # or customer', 'wowrestro' ); ?>" aria-label="<?php esc_attr_e( 'Search orders', 'wowrestro' ); ?>">
					</span>
				</div>
			</div>

			<?php if ( current_user_can( 'wowrestro_create_phone_orders' ) || current_user_can( 'manage_woocommerce' ) ) : ?>
			<details class="wra-manual">
				<summary><i class="ph-bold ph-plus" aria-hidden="true"></i><?php esc_html_e( 'Add phone order', 'wowrestro' ); ?></summary>
				<div class="wra-manual__body">
					<form data-wowrestro-manual-form>
						<div class="wra-manual__grid">
							<label><?php esc_html_e( 'Customer name', 'wowrestro' ); ?><input type="text" name="customer" required></label>
							<label><?php esc_html_e( 'Phone', 'wowrestro' ); ?><input name="phone" type="tel"></label>
							<label><?php esc_html_e( 'Email', 'wowrestro' ); ?><input name="email" type="email"></label>
							<label><?php esc_html_e( 'Service', 'wowrestro' ); ?>
								<select name="mode">
									<option value="pickup"><?php esc_html_e( 'Pickup', 'wowrestro' ); ?></option>
									<option value="delivery"><?php esc_html_e( 'Delivery', 'wowrestro' ); ?></option>
									<option value="dinein"><?php esc_html_e( 'Dine-in', 'wowrestro' ); ?></option>
								</select>
							</label>
							<label data-wowrestro-service-field="dinein"><?php esc_html_e( 'Table', 'wowrestro' ); ?><input type="text" name="table"></label>
							<label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Address', 'wowrestro' ); ?><input type="text" name="address_1" autocomplete="street-address"></label>
							<label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Address line 2', 'wowrestro' ); ?><input type="text" name="address_2"></label>
							<label data-wowrestro-service-field="delivery"><?php esc_html_e( 'City', 'wowrestro' ); ?><input type="text" name="city" autocomplete="address-level2"></label>
							<label data-wowrestro-service-field="delivery"><?php esc_html_e( 'State / region', 'wowrestro' ); ?><input type="text" name="state" autocomplete="address-level1"></label>
							<label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Postcode', 'wowrestro' ); ?><input type="text" name="postcode"></label>
							<label data-wowrestro-service-field="delivery"><?php esc_html_e( 'Country code', 'wowrestro' ); ?><input type="text" name="country" maxlength="2"></label>
							<label><?php esc_html_e( 'Requested time', 'wowrestro' ); ?><input name="requested_at" type="datetime-local"></label>
							<label><?php esc_html_e( 'Payment', 'wowrestro' ); ?><select name="payment_flow"><option value="pay_later"><?php esc_html_e( 'Pay later', 'wowrestro' ); ?></option><option value="payment_link"><?php esc_html_e( 'Send payment link', 'wowrestro' ); ?></option></select></label>
						</div>
						<div class="wra-manual__items" data-wowrestro-manual-items></div>
						<div class="wra-manual__actions">
							<button type="button" class="wra-btn wra-btn--sm" data-wowrestro-add-item><?php esc_html_e( 'Add another item', 'wowrestro' ); ?></button>
						</div>
						<label><?php esc_html_e( 'WooCommerce service method', 'wowrestro' ); ?><select name="shipping_rate_id" data-wowrestro-shipping-rate disabled><option value=""><?php esc_html_e( 'Check availability first', 'wowrestro' ); ?></option></select></label>
						<label class="wra-manual__note"><?php esc_html_e( 'Kitchen note', 'wowrestro' ); ?><textarea name="note" rows="2"></textarea></label>
						<label class="wra-manual__consent"><input type="checkbox" name="status_opt_in" value="1"> <?php esc_html_e( 'Customer consented to status updates', 'wowrestro' ); ?></label>
						<p class="wra-manual__message" data-wowrestro-manual-message aria-live="polite"></p>
						<div class="wra-manual__actions">
							<button class="wra-btn" type="button" data-wowrestro-quote><?php esc_html_e( 'Check availability', 'wowrestro' ); ?></button><button class="wra-btn wra-btn--primary" type="submit"><?php esc_html_e( 'Create WooCommerce order', 'wowrestro' ); ?></button>
						</div>
					</form>
				</div>
			</details>
			<?php endif; ?>

			<div class="wra-tickets" data-wowrestro-board>
				<p><?php esc_html_e( 'Loading active orders…', 'wowrestro' ); ?></p>
			</div>

			</div>
		</div>
		<?php
	}
}
