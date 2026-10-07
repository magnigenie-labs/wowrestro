<?php
/**
 * Restaurant cart integration, modifier pricing and tips.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Cart {
	const SESSION_TIP = 'wowrestro_tip';

	/**
	 * Register cart hooks shared by classic and Store API requests.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 20, 4 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_display_data' ), 20, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'restore_cart_item' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price_modifiers' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ), 5 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 5, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_line_snapshot' ), 20, 4 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_tip_fee' ), 30 );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_update' ) );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( __CLASS__, 'store_api_add_to_cart_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_classic_tip' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'checkout_tip_field' ) );
		add_action( 'woocommerce_before_cart_totals', array( __CLASS__, 'cart_tip_form' ) );
		add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'order_bumps' ) );
		add_action( 'admin_post_wowrestro_set_tip', array( __CLASS__, 'save_cart_tip' ) );
		add_action( 'admin_post_nopriv_wowrestro_set_tip', array( __CLASS__, 'save_cart_tip' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 30 );
	}

	public static function frontend_assets() {
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			wp_enqueue_style( 'wowrestro-features' );
		}
	}

	public static function capture_classic_tip( $posted_data ) {
		parse_str( (string) $posted_data, $data );
		if ( isset( $data['wowrestro_tip_choice'] ) ) {
			$flat = isset( $data['wowrestro_tip_flat'] ) ? (float) wc_format_decimal( $data['wowrestro_tip_flat'] ) : 0;
			self::set_tip( $flat > 0 ? 'custom:' . $flat : sanitize_text_field( $data['wowrestro_tip_choice'] ) );
		}
	}

	public static function checkout_tip_field() {
		if ( ! self::tips_enabled() || ! self::has_restaurant_items() ) {
			return; }
		$current = WC()->session ? self::normalize_tip( WC()->session->get( self::SESSION_TIP, 'none' ) ) : array(
			'type'  => 'none',
			'value' => 0,
		);
		$value   = is_wp_error( $current ) || 'none' === $current['type'] ? 'none' : $current['type'] . ':' . $current['value'];
		echo '<tr class="wowrestro-tip"><th>' . esc_html__( 'Tip', 'wowrestro' ) . '</th><td><select name="wowrestro_tip_choice" onchange="jQuery(document.body).trigger(\'update_checkout\')"><option value="none">' . esc_html__( 'No tip', 'wowrestro' ) . '</option>';
		foreach ( self::tip_presets() as $preset ) {
			echo '<option value="percent:' . esc_attr( $preset ) . '" ' . selected( $value, 'percent:' . $preset, false ) . '>' . esc_html( $preset . '%' ) . '</option>'; }
		$custom = 'yes' === WowRestro_Fulfillment::settings()['custom_tips'] ? ' <input name="wowrestro_tip_flat" type="number" min="0" step="0.01" placeholder="' . esc_attr__( 'Flat amount', 'wowrestro' ) . '" onchange="jQuery(document.body).trigger(\'update_checkout\')">' : '';
		echo '</select>' . $custom . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $custom contains fixed escaped markup.
	}

	public static function cart_tip_form() {
		if ( ! self::tips_enabled() || ! self::has_restaurant_items() ) {
			return; }
		?>
		<form class="wowrestro-tip" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wowrestro_set_tip"><?php wp_nonce_field( 'wowrestro_set_tip' ); ?>
			<label><?php esc_html_e( 'Add a tip', 'wowrestro' ); ?> <select name="tip"><option value="none"><?php esc_html_e( 'No tip', 'wowrestro' ); ?></option>
			<?php
			foreach ( self::tip_presets() as $preset ) :
				?>
				<option value="percent:<?php echo esc_attr( $preset ); ?>"><?php echo esc_html( $preset . '%' ); ?></option><?php endforeach; ?></select></label>
			<?php
			if ( 'yes' === WowRestro_Fulfillment::settings()['custom_tips'] ) :
				?>
				<label><?php esc_html_e( 'Flat amount', 'wowrestro' ); ?> <input name="flat" type="number" min="0" step="0.01"></label><?php endif; ?>
			<button class="button" type="submit"><?php esc_html_e( 'Apply tip', 'wowrestro' ); ?></button>
		</form>
		<?php
	}

	public static function save_cart_tip() {
		check_admin_referer( 'wowrestro_set_tip' );
		$flat = isset( $_POST['flat'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['flat'] ) ) : 0;
		$raw  = $flat > 0 ? 'custom:' . $flat : sanitize_text_field( wp_unslash( $_POST['tip'] ?? 'none' ) );
		self::set_tip( $raw );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	public static function order_bumps() {
		if ( ! self::has_restaurant_items() || ! WC()->cart ) {
			return; }
		$ids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product ) {
				$ids = array_merge( $ids, $product->get_cross_sell_ids() ); }
		}
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ), array( 'WowRestro_Products', 'is_menu_item' ) ) ) ), 0, 4 );
		if ( ! $ids ) {
			return; }
		echo '<section class="wowrestro-order-bumps"><h2>' . esc_html__( 'Add something extra?', 'wowrestro' ) . '</h2><div class="wowrestro-order-bumps__items">';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			} echo '<article><strong>' . esc_html( $product->get_name() ) . '</strong> ' . wp_kses_post( $product->get_price_html() ) . ' <a class="button" href="' . esc_url( $product->add_to_cart_url() ) . '">' . esc_html__( 'Add', 'wowrestro' ) . '</a></article>'; }
		echo '</div></section>';
	}

	/**
	 * Validate an addition before restaurant-specific configuration is applied.
	 *
	 * @param bool  $passed Existing validation result.
	 * @param int   $product_id Product ID.
	 * @param int   $quantity Quantity.
	 * @param int   $variation_id Variation ID.
	 * @param array $variations Variation attributes.
	 * @param array $cart_item_data Existing item data.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed ) {
			return false;
		}

		$product             = wc_get_product( $variation_id ?: $product_id );
		$incoming_restaurant = class_exists( 'WowRestro_Products' ) && WowRestro_Products::is_menu_item( $product );
		if ( $incoming_restaurant && class_exists( 'WowRestro_Legacy_Migration' ) && method_exists( 'WowRestro_Legacy_Migration', 'checkout_blocked' ) && WowRestro_Legacy_Migration::checkout_blocked() ) {
			self::add_notice_once( __( 'Restaurant checkout is unavailable until the WOWRestro 1.x migration is complete. Ask the store manager to finish it and to deactivate WOWRestro 1.x if it is still active.', 'wowrestro' ) );
			return false;
		}
		$boundary = self::validate_product_boundary( $product );
		if ( is_wp_error( $boundary ) ) {
			self::add_notice_once( $boundary->get_error_message() );
			return false;
		}
		if ( ! $incoming_restaurant ) {
			return true;
		}

		$note = sanitize_textarea_field( self::has_submitted_configuration() ? self::submitted_note() : '' );
		if ( self::text_length( $note ) > 500 ) {
			self::add_notice_once( __( 'Special instructions must be 500 characters or fewer.', 'wowrestro' ) );
			return false;
		}
		$selection = array_key_exists( 'wowrestro_modifier_snapshot', $cart_item_data )
			? $cart_item_data['wowrestro_modifier_snapshot']
			: ( class_exists( 'WowRestro_Modifiers' ) ? WowRestro_Modifiers::validate_selection( $product, self::has_submitted_configuration() ? self::submitted_modifiers() : array() ) : array() );
		if ( is_wp_error( $selection ) ) {
			self::add_notice_once( $selection->get_error_message() );
			return false;
		}

		return true;
	}

	/**
	 * Preserve the public cart-boundary hook while allowing WooCommerce products
	 * and restaurant menu items to share one cart.
	 *
	 * @param int|WC_Product     $product Product being added.
	 * @param WC_Cart|array|null $cart Existing cart or item array.
	 * @return true|WP_Error
	 */
	public static function validate_product_boundary( $product, $cart = null ) {
		unset( $product, $cart );
		return true;
	}

	/**
	 * Attach trusted line configuration to the cart item.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id Product ID.
	 * @param int   $variation_id Variation ID.
	 * @param int   $quantity Quantity.
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id = 0, $quantity = 1 ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! class_exists( 'WowRestro_Products' ) || ! WowRestro_Products::is_menu_item( $product ) ) {
			return $cart_item_data;
		}
		if ( array_key_exists( 'wowrestro_modifier_snapshot', $cart_item_data ) ) {
			return $cart_item_data;
		}
		$prepared = self::prepare_cart_item_data(
			$product,
			self::has_submitted_configuration() ? self::submitted_modifiers() : array(),
			self::has_submitted_configuration() ? self::submitted_note() : ''
		);
		if ( is_wp_error( $prepared ) ) {
			return $cart_item_data;
		}
		return array_merge( $cart_item_data, $prepared );
	}

	/**
	 * Attach custom-modal add-ons to a Store API add-item request.
	 *
	 * @param array           $data Store API cart data.
	 * @param WP_REST_Request $request Store API request.
	 * @return array
	 */
	public static function store_api_add_to_cart_data( $data, $request ) {
		$modifiers = $request instanceof WP_REST_Request ? $request->get_param( 'wowrestro_modifiers' ) : null;
		if ( null === $modifiers ) {
			return $data;
		}

		$product  = wc_get_product( absint( $data['variation_id'] ?? $data['id'] ?? 0 ) );
		$prepared = self::prepare_cart_item_data( $product, $modifiers );
		if ( is_wp_error( $prepared ) ) {
			if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( $prepared->get_error_code(), $prepared->get_error_message(), 400 );
			}
			return $data;
		}

		$data['cart_item_data'] = array_merge( (array) ( $data['cart_item_data'] ?? array() ), $prepared );
		return $data;
	}

	/**
	 * Show selected add-ons in classic and block-based cart surfaces.
	 *
	 * @param array $item_data Existing public line-item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_display_data( $item_data, $cart_item ) {
		foreach ( (array) ( $cart_item['wowrestro_modifier_snapshot'] ?? array() ) as $group ) {
			$options = array();
			foreach ( (array) ( $group['options'] ?? array() ) as $option ) {
				$label = sanitize_text_field( $option['label'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				$price = max( 0, (float) ( $option['price'] ?? 0 ) );
				$options[] = $price > 0
					? sprintf(
						'%1$s (+%2$s)',
						$label,
						html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
					)
					: $label;
			}
			if ( $options ) {
				$item_data[] = array(
					'key'     => sanitize_text_field( $group['name'] ?? __( 'Add-ons', 'wowrestro' ) ),
					'value'   => implode( ', ', $options ),
					'display' => implode( ', ', $options ),
				);
			}
		}

		$note = sanitize_textarea_field( $cart_item['wowrestro_item_note'] ?? '' );
		if ( '' !== $note ) {
			$item_data[] = array(
				'key'     => __( 'Item instructions', 'wowrestro' ),
				'value'   => $note,
				'display' => nl2br( esc_html( $note ) ),
			);
		}

		return $item_data;
	}

	/**
	 * Prepare a line configuration for cart or phone-order callers.
	 *
	 * @param int|WC_Product $product Product.
	 * @param mixed          $modifiers Group/option IDs.
	 * @param string         $note Customer note.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function prepare_cart_item_data( $product, $modifiers = array(), $note = '' ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product || ! WowRestro_Products::is_menu_item( $product ) ) {
			return new WP_Error( 'wowrestro_not_menu_item', __( 'This product is not available on the restaurant menu.', 'wowrestro' ) );
		}

		$note = sanitize_textarea_field( $note );
		if ( self::text_length( $note ) > 500 ) {
			return new WP_Error( 'wowrestro_note_length', __( 'Special instructions must be 500 characters or fewer.', 'wowrestro' ) );
		}
		$snapshots = WowRestro_Modifiers::validate_selection( $product, $modifiers );
		if ( is_wp_error( $snapshots ) ) {
			return $snapshots;
		}

		$configuration = array(
			'modifiers' => WowRestro_Modifiers::snapshot_selection( $snapshots ),
			'note'      => $note,
		);
		return array(
			'wowrestro_restaurant_item'   => 'yes',
			'wowrestro_modifier_snapshot' => $snapshots,
			'wowrestro_item_note'         => $note,
			'wowrestro_configuration_key' => hash( 'sha256', wp_json_encode( $configuration ) ),
			'wowrestro_base_price'        => (float) $product->get_price(),
			'wowrestro_modifier_delta'    => 0.0,
		);
	}

	/**
	 * Refresh server-owned prices and labels when restoring a cart session.
	 *
	 * @param array  $cart_item Session item.
	 * @param array  $values Stored values.
	 * @param string $cart_item_key Cart key.
	 * @return array
	 */
	public static function restore_cart_item( $cart_item, $values, $cart_item_key ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( ! $product instanceof WC_Product || ! WowRestro_Products::is_menu_item( $product ) ) {
			return $cart_item;
		}

		$selection = array_key_exists( 'wowrestro_modifier_snapshot', $values ) ? WowRestro_Modifiers::snapshot_selection( $values['wowrestro_modifier_snapshot'] ) : array();
		$prepared  = self::prepare_cart_item_data( $product, $selection, $values['wowrestro_item_note'] ?? '' );
		if ( ! is_wp_error( $prepared ) ) {
			$cart_item = array_merge( $cart_item, $prepared );
		}
		return $cart_item;
	}

	/**
	 * Rebuild modifier deltas before WooCommerce calculates coupons, taxes and totals.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function price_modifiers( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['wowrestro_restaurant_item'] ) || ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}
			$selection = WowRestro_Modifiers::snapshot_selection( $cart_item['wowrestro_modifier_snapshot'] ?? array() );
			$current   = WowRestro_Modifiers::validate_selection( $cart_item['data'], $selection );
			if ( is_wp_error( $current ) ) {
				continue;
			}
			$delta          = WowRestro_Modifiers::snapshot_total( $current );
			$current_price  = (float) $cart_item['data']->get_price();
			$stored_base    = isset( $cart_item['wowrestro_base_price'] ) ? (float) $cart_item['wowrestro_base_price'] : $current_price;
			$previous_delta = isset( $cart_item['wowrestro_modifier_delta'] ) ? (float) $cart_item['wowrestro_modifier_delta'] : 0.0;
			$previous_total = $stored_base + $previous_delta;
			$base           = abs( $current_price - $previous_total ) < 0.00001 ? $stored_base : $current_price;
			$base           = max( 0.0, (float) apply_filters( 'wowrestro_modifier_base_price', $base, $cart_item['data'], $cart_item ) );
			$cart_item['data']->set_price( $base + $delta );
			$cart->cart_contents[ $cart_item_key ]['wowrestro_base_price']        = $base;
			$cart->cart_contents[ $cart_item_key ]['wowrestro_modifier_delta']    = $delta;
			$cart->cart_contents[ $cart_item_key ]['wowrestro_modifier_snapshot'] = $current;
		}
	}

	/**
	 * Validate the complete cart at cart and checkout boundaries.
	 */
	public static function validate_cart() {
		$error = self::cart_error();
		if ( is_wp_error( $error ) ) {
			self::add_notice_once( $error->get_error_message() );
		}
	}

	/**
	 * Put cart errors directly onto classic checkout's error object.
	 *
	 * @param array    $data Posted checkout data.
	 * @param WP_Error $errors Checkout errors.
	 */
	public static function validate_checkout( $data, $errors ) {
		$error = self::cart_error();
		if ( is_wp_error( $error ) ) {
			$errors->add( $error->get_error_code(), $error->get_error_message() );
		}
	}

	/**
	 * Return the first cart-integrity error without mutating the cart.
	 *
	 * @param WC_Cart|null $cart Cart, defaults to the customer cart.
	 * @return true|WP_Error
	 */
	public static function cart_error( $cart = null ) {
		$cart = $cart instanceof WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart instanceof WC_Cart ) {
			return true;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product || ! WowRestro_Products::is_menu_item( $product ) ) {
				continue;
			}
			$note = sanitize_textarea_field( $cart_item['wowrestro_item_note'] ?? '' );
			if ( self::text_length( $note ) > 500 ) {
				return new WP_Error( 'wowrestro_note_length', __( 'Special instructions must be 500 characters or fewer.', 'wowrestro' ) );
			}
			$selection = array_key_exists( 'wowrestro_modifier_snapshot', $cart_item ) ? WowRestro_Modifiers::snapshot_selection( $cart_item['wowrestro_modifier_snapshot'] ) : array();
			$current   = WowRestro_Modifiers::validate_selection( $product, $selection );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$cart->cart_contents[ $cart_item_key ]['wowrestro_modifier_snapshot'] = $current;
		}
		return true;
	}

	/**
	 * Classify cart membership without clearing or modifying anything.
	 *
	 * @param WC_Cart|array|null $cart Cart object or item array.
	 * @return string empty|restaurant|standard|mixed
	 */
	public static function restaurant_cart_state( $cart = null ) {
		if ( $cart instanceof WC_Cart ) {
			$items = $cart->get_cart();
		} elseif ( is_array( $cart ) ) {
			$items = $cart;
		} else {
			$items = function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ? WC()->cart->get_cart() : array();
		}
		$restaurant = false;
		$standard   = false;
		foreach ( $items as $item ) {
			$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : wc_get_product( $item['variation_id'] ?? $item['product_id'] ?? 0 );
			if ( $product && WowRestro_Products::is_menu_item( $product ) ) {
				$restaurant = true;
			} else {
				$standard = true;
			}
		}

		if ( $restaurant && $standard ) {
			return 'mixed';
		}
		if ( $restaurant ) {
			return 'restaurant';
		}
		return $standard ? 'standard' : 'empty';
	}

	/**
	 * Whether a cart contains at least one restaurant menu item.
	 *
	 * Mixed carts still use WowRestro fulfillment and order metadata while
	 * WooCommerce continues to own totals, shipping, payment and checkout.
	 *
	 * @param WC_Cart|array|null $cart Cart object or item array.
	 * @return bool
	 */
	public static function has_restaurant_items( $cart = null ) {
		return in_array( self::restaurant_cart_state( $cart ), array( 'restaurant', 'mixed' ), true );
	}

	/**
	 * Copy immutable, server-built snapshots to the WooCommerce order line.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values Cart item.
	 * @param WC_Order              $order Order.
	 */
	public static function save_order_line_snapshot( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['wowrestro_restaurant_item'] ) ) {
			return;
		}
		$snapshots = (array) ( $values['wowrestro_modifier_snapshot'] ?? array() );
		$item->add_meta_data( '_wowrestro_modifier_snapshot', $snapshots, true );
		$note = sanitize_textarea_field( $values['wowrestro_item_note'] ?? '' );
		if ( $note ) {
			$item->add_meta_data( '_wowrestro_special_note', $note, true );
		}
	}

	/**
	 * Add the configured tip as a WooCommerce fee after merchandise discounts.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function add_tip_fee( $cart ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart instanceof WC_Cart ) {
			return;
		}
		$amount = self::tip_amount( $cart );
		if ( $amount <= 0 ) {
			return;
		}
		$settings  = get_option( 'wowrestro_settings', array() );
		$taxable   = 'yes' === ( $settings['tips_taxable'] ?? 'no' );
		$tax_class = $taxable ? sanitize_title( $settings['tips_tax_class'] ?? '' ) : '';
		$cart->add_fee( __( 'Tip', 'wowrestro' ), $amount, $taxable, $tax_class );
	}

	/**
	 * Store a validated tip choice in the WooCommerce customer session.
	 *
	 * @param mixed $raw percent:10, custom:5.00, none, or an equivalent object.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_tip( $raw ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return new WP_Error( 'wowrestro_tip_session', __( 'Your cart session is unavailable. Refresh the page and try again.', 'wowrestro' ) );
		}
		$choice = self::normalize_tip( $raw );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		if ( 'none' !== $choice['type'] && ! self::has_restaurant_items() ) {
			return new WP_Error( 'wowrestro_tip_cart', __( 'Tips can only be applied to a restaurant order.', 'wowrestro' ) );
		}
		WC()->session->set( self::SESSION_TIP, $choice );
		return $choice;
	}

	/**
	 * Calculate the current trusted tip amount.
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return float
	 */
	public static function tip_amount( $cart = null ) {
		$cart = $cart instanceof WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! self::tips_enabled() || ! $cart instanceof WC_Cart || ! self::has_restaurant_items( $cart ) || ! WC()->session ) {
			return 0.0;
		}
		$choice   = self::normalize_tip(
			WC()->session->get(
				self::SESSION_TIP,
				array(
					'type'  => 'none',
					'value' => 0,
				)
			)
		);
		$subtotal = max( 0, (float) $cart->get_cart_contents_total() );
		$amount   = self::tip_for_subtotal( $subtotal, $choice );
		if ( is_wp_error( $amount ) ) {
			return 0.0;
		}
		return $amount;
	}

	/**
	 * Calculate a tip against discounted merchandise only.
	 *
	 * Shipping, taxes and existing fees are deliberately absent from the base.
	 *
	 * @param float $subtotal Discounted merchandise subtotal.
	 * @param mixed $choice Validated or raw tip choice.
	 * @return float|WP_Error
	 */
	public static function tip_for_subtotal( $subtotal, $choice ) {
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		$choice = self::normalize_tip( $choice );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		$subtotal = max( 0, (float) wc_format_decimal( $subtotal, wc_get_price_decimals() ) );
		$amount   = 'percent' === $choice['type'] ? $subtotal * $choice['value'] / 100 : ( 'custom' === $choice['type'] ? $choice['value'] : 0 );
		return (float) wc_format_decimal( min( $subtotal, max( 0, $amount ) ), wc_get_price_decimals() );
	}

	/**
	 * Register the cart-update callback used by Cart and Checkout Blocks.
	 */
	public static function register_store_api_update() {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => 'wowrestro-tip',
					'callback'  => array( __CLASS__, 'store_api_update_tip' ),
				)
			);
		}
	}

	/**
	 * Handle a validated Store API cart-extension update.
	 *
	 * @param array $data Extension data.
	 */
	public static function store_api_update_tip( $data ) {
		$raw    = is_array( $data ) && array_key_exists( 'tip', $data ) ? $data['tip'] : $data;
		$result = self::set_tip( $raw );
		if ( is_wp_error( $result ) ) {
			if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wowrestro_tip', $result->get_error_message(), 400 );
			}
			throw new Exception( esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Parse and validate a tip choice against settings.
	 *
	 * @param mixed $raw Input.
	 * @return array{type:string,value:float}|WP_Error
	 */
	public static function normalize_tip( $raw ) {
		if ( is_array( $raw ) ) {
			$type  = sanitize_key( $raw['type'] ?? 'none' );
			$value = $raw['value'] ?? 0;
		} else {
			$parts = explode( ':', sanitize_text_field( (string) $raw ), 2 );
			$type  = sanitize_key( $parts[0] ?: 'none' );
			$value = $parts[1] ?? 0;
		}
		if ( in_array( $type, array( '', '0', 'none' ), true ) ) {
			return array(
				'type'  => 'none',
				'value' => 0.0,
			);
		}
		if ( ! self::tips_enabled() ) {
			return new WP_Error( 'wowrestro_tip_disabled', __( 'Tips are not enabled for this restaurant.', 'wowrestro' ) );
		}
		$value = (float) wc_format_decimal( $value, wc_get_price_decimals() );
		if ( 'percent' === $type && in_array( $value, self::tip_presets(), true ) ) {
			return array(
				'type'  => 'percent',
				'value' => $value,
			);
		}
		$settings = get_option( 'wowrestro_settings', array() );
		if ( 'custom' === $type && 'yes' === ( $settings['custom_tips'] ?? 'no' ) && $value >= 0 ) {
			return array(
				'type'  => 0 === $value ? 'none' : 'custom',
				'value' => $value,
			);
		}
		return new WP_Error( 'wowrestro_tip_invalid', __( 'Choose one of the available tip amounts.', 'wowrestro' ) );
	}

	/**
	 * Return configured percentage presets.
	 *
	 * @return array<int,float>
	 */
	public static function tip_presets() {
		$settings = get_option( 'wowrestro_settings', array() );
		$raw      = $settings['tip_presets'] ?? array( 10, 15, 20 );
		$raw      = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		$presets  = array_map( 'floatval', $raw );
		$presets  = array_filter(
			$presets,
			static function ( $value ) {
				return $value > 0 && $value <= 100;
			}
		);
		return array_values( array_unique( $presets ) );
	}

	/**
	 * Whether optional tipping is enabled.
	 *
	 * @return bool
	 */
	public static function tips_enabled() {
		$settings = get_option( 'wowrestro_settings', array() );
		return 'yes' === ( $settings['tips_enabled'] ?? 'no' );
	}

	/**
	 * Read submitted modifier IDs from all native add-to-cart surfaces.
	 *
	 * @return array
	 */
	private static function submitted_modifiers() {
		$submitted = isset( $_REQUEST['wowrestro_modifiers'] ) ? (array) wp_unslash( $_REQUEST['wowrestro_modifiers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is validated add-to-cart input, not an account mutation.
		return (array) apply_filters( 'wowrestro_submitted_modifiers', $submitted );
	}

	/**
	 * Read a submitted line note without silently accepting overlong input.
	 *
	 * @return string
	 */
	private static function submitted_note() {
		$note = isset( $_REQUEST['wowrestro_item_note'] ) ? wp_unslash( $_REQUEST['wowrestro_item_note'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is validated add-to-cart input, not an account mutation.
		return (string) apply_filters( 'wowrestro_submitted_item_note', $note );
	}

	/**
	 * Whether an integration explicitly supplied a line configuration.
	 *
	 * Existing customer-facing add-to-cart forms remain unchanged until the
	 * dedicated modifier UI is released.
	 *
	 * @return bool
	 */
	private static function has_submitted_configuration() {
		$submitted = isset( $_REQUEST['wowrestro_modifiers'] ) || isset( $_REQUEST['wowrestro_item_note'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; values are validated before use.
		return (bool) apply_filters( 'wowrestro_has_submitted_configuration', $submitted );
	}

	/**
	 * Add one WooCommerce error notice even if checkout calls validation twice.
	 *
	 * @param string $message Message.
	 */
	private static function add_notice_once( $message ) {
		if ( function_exists( 'wc_add_notice' ) && ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( $message, 'error' ) ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Unicode-aware text length with a PHP 7.4-safe fallback.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function text_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
	}
}
