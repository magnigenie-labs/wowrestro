<?php
/**
 * Menu block, shortcode and customer tracking.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Storefront {
	/**
	 * Whether this request prints the Olive & Ember order flow.
	 *
	 * @var bool
	 */
	private static $design = false;

	/**
	 * Whether the order-confirmation template already printed the receipt.
	 *
	 * @var bool
	 */
	private static $receipt_printed = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_shortcode( 'wowrestro_menu', array( __CLASS__, 'menu_shortcode' ) );
		add_shortcode( 'wowrestro', array( __CLASS__, 'menu_shortcode' ) );
		add_shortcode( 'wowrestro_tracking', array( __CLASS__, 'tracking_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'design_body_class' ) );
		add_action( 'wp_footer', array( __CLASS__, 'design_noscript' ) );
		add_filter( 'pre_render_block', array( __CLASS__, 'checkout_block' ), 10, 2 );
		add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'checkout_shortcode' ), 10, 2 );
		add_filter( 'wc_get_template', array( __CLASS__, 'thankyou_template' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'confirmation_route' ) );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( __CLASS__, 'confirmation_url' ), 10, 2 );
		add_filter( 'query_vars', array( __CLASS__, 'receipt_query_var' ) );
		add_filter( 'request', array( __CLASS__, 'receipt_request' ) );
		add_filter( 'woocommerce_thankyou_order_key', array( __CLASS__, 'receipt_key' ) );
		add_action( 'template_redirect', array( __CLASS__, 'clean_receipt_url' ), 1 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_tracking_link' ), 20, 4 );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_elementor_widget' ) );
	}

	public static function register_assets() {
		wp_register_style( 'wowrestro-storefront', WOWRESTRO_URL . 'assets/css/storefront.css', array(), WOWRESTRO_VERSION );
		wp_register_style( 'wowrestro-features', WOWRESTRO_URL . 'assets/css/features.css', array( 'wowrestro-storefront' ), WOWRESTRO_VERSION );
		wp_register_script( 'wowrestro-storefront', WOWRESTRO_URL . 'assets/js/storefront.js', array(), WOWRESTRO_VERSION, true );
		// The order flow runs after storefront.js, whose fetch wrapper adds WowRestro add-ons and suggestions to it.
		wp_register_style( 'wowrestro-design', WOWRESTRO_URL . 'assets/css/wowrestro-design.css', array( 'wowrestro-features' ), WOWRESTRO_VERSION );
		wp_register_script( 'wowrestro-design', WOWRESTRO_URL . 'assets/js/wowrestro-design.js', array( 'wowrestro-storefront' ), WOWRESTRO_VERSION, true );
		wp_register_style( 'wowrestro-templates', WOWRESTRO_URL . 'assets/css/wowrestro-templates.css', array( 'wowrestro-design' ), WOWRESTRO_VERSION );
		// Classic themes print <head> before the content renders, so start the design from the page content.
		if ( ! doing_action( 'wp_enqueue_scripts' ) ) {
			return;
		}
		if ( self::holds_menu() || self::is_restaurant_checkout() ) {
			self::enqueue_design();
			// The template's fonts go in <head> too; a shortcode that picks another template adds its own.
			self::enqueue_template_fonts( self::template_number() );
		} elseif ( self::received_order() || ( is_singular() && has_shortcode( (string) get_post_field( 'post_content' ), 'wowrestro_tracking' ) ) ) {
			self::enqueue_receipt();
		}
	}

	/**
	 * The Olive & Ember order flow through the Store API: the menu and cart on the menu page,
	 * then the checkout on WooCommerce's checkout page.
	 */
	private static function enqueue_design() {
		wp_enqueue_style( 'wowrestro-design' );
		wp_enqueue_style( 'wowrestro-templates' );
		wp_enqueue_script( 'wowrestro-design' );
		if ( self::$design ) {
			return;
		}
		self::$design = true;
		// The body class also covers menus that render after a classic theme printed <body>.
		wp_add_inline_script( 'wowrestro-design', 'window.WOWRESTRO=' . wp_json_encode( self::design_config() ) . ';document.body.classList.add("wowrestro-design");', 'before' );
	}

	/** The order-received page and the tracking shortcode are server-rendered: the design's styles without its script. */
	private static function enqueue_receipt() {
		wp_enqueue_style( 'wowrestro-templates' );
		self::enqueue_template_fonts( self::template_number() );
		self::$design = true;
	}

	/**
	 * Settings for window.WOWRESTRO; storefront.js reads pluginApi and currency from it too.
	 * Optional keys a site can add through the wowrestro_design_config filter: `category` (a
	 * product category slug the menu keeps to), `hero` ("First line|second line"), `heading`
	 * (the menu's title) and `note` (the line under the menu).
	 *
	 * @return array
	 */
	private static function design_config() {
		$countries = WC()->countries;
		$country   = $countries->get_base_country();
		$store     = array(
			'address_1' => $countries->get_base_address(),
			'address_2' => $countries->get_base_address_2(),
			'city'      => $countries->get_base_city(),
			'state'     => $countries->get_base_state(),
			'postcode'  => $countries->get_base_postcode(),
			'country'   => $country,
		);
		$payments  = array();
		foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
			$payments[] = array(
				'id'          => $gateway->id,
				'title'       => wp_strip_all_tags( $gateway->get_title() ),
				'description' => wp_strip_all_tags( $gateway->get_description() ),
			);
		}
		$settings = WowRestro_Fulfillment::settings();
		// WooCommerce's own words for the store's country: "ZIP Code" and "State" in the US, "Postcode" and "County" in the UK.
		$fields = $countries->get_address_fields( $country, 'billing_' );
		// The checkout page opens on the pickup or delivery choice and time made on the menu.
		$session   = WC()->session;
		$mode      = $session ? (string) $session->get( 'wowrestro_fulfillment', '' ) : '';
		$requested = $session ? (string) $session->get( 'wowrestro_requested_at', '' ) : '';
		$slot      = null;
		if ( '' !== $requested && 'asap' !== strtolower( $requested ) && false !== strtotime( $requested ) ) {
			$slot = array(
				'value' => $requested,
				'day'   => wp_date( 'Y-m-d', strtotime( $requested ) ),
			);
		}
		$config = array(
			'storeApi'        => rest_url( 'wc/store/v1/' ),
			'pluginApi'       => rest_url( 'wowrestro/v1' ),
			'nonce'           => wp_create_nonce( 'wc_store_api' ),
			'today'           => wp_date( 'Y-m-d' ),
			'preorderDays'    => absint( $settings['preorder_days'] ),
			'asap'            => 'yes' === $settings['asap_enabled'],
			'country'         => $country,
			'states'          => $countries->get_states( $country ) ? $countries->get_states( $country ) : array(),
			'labels'          => array(
				'state' => wp_strip_all_tags( $fields['billing_state']['label'] ?? '' ),
				'pin'   => wp_strip_all_tags( $fields['billing_postcode']['label'] ?? '' ),
			),
			'store'           => $store,
			'address'         => self::store_address(),
			'storeName'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'tagline'         => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'checkoutUrl'     => wc_get_checkout_url(),
			'menuUrl'         => self::menu_url(),
			'mode'            => in_array( $mode, array( 'pickup', 'delivery' ), true ) ? $mode : '',
			'slot'            => $slot,
			// ponytail: the free-delivery progress bar stays off; feed it the zone's free_shipping min_amount if a store needs it.
			'freeShippingMin' => 0,
			'payments'        => $payments,
			'tips'            => array(
				'enabled' => WowRestro_Cart::tips_enabled(),
				'presets' => WowRestro_Cart::tip_presets(),
				'custom'  => 'yes' === ( $settings['custom_tips'] ?? 'no' ),
			),
			'currency'        => array(
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'minor'    => wc_get_price_decimals(),
				'decimal'  => wc_get_price_decimal_separator(),
				'thousand' => wc_get_price_thousand_separator(),
			),
		);
		return (array) apply_filters( 'wowrestro_design_config', $config );
	}

	/**
	 * Whether the current page's content holds the menu shortcode or block.
	 *
	 * @return bool
	 */
	private static function holds_menu() {
		$post = is_singular() ? get_post() : null;
		return $post && ( has_shortcode( $post->post_content, 'wowrestro_menu' ) || has_shortcode( $post->post_content, 'wowrestro' ) || has_block( 'wowrestro/menu', $post ) );
	}

	/**
	 * WooCommerce's checkout page is the restaurant checkout while the cart holds menu dishes.
	 * `?wowrestro_gateway=` keeps WooCommerce's own form for gateways that collect card details.
	 *
	 * @return bool
	 */
	public static function is_restaurant_checkout() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() && ! isset( $_GET['wowrestro_gateway'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
			&& WC()->cart && class_exists( 'WowRestro_Cart' ) && WowRestro_Cart::has_restaurant_items( WC()->cart );
	}

	/**
	 * The checkout block on the checkout page, and the order blocks of WooCommerce's order-confirmation
	 * template on block themes. The first of those becomes the receipt through WooCommerce's own
	 * order-received handler, which checks the order key and asks guests to confirm their email
	 * before it loads checkout/thankyou.php; the receipt stands in for the rest.
	 *
	 * @param string|null $pre   Short-circuited output.
	 * @param array       $block Parsed block.
	 * @return string|null
	 */
	public static function checkout_block( $pre, $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( 'woocommerce/checkout' === $name ) {
			return self::is_restaurant_checkout() ? self::render_checkout() : $pre;
		}
		$order_block = 0 === strpos( $name, 'woocommerce/order-confirmation-' ) && 'woocommerce/order-confirmation-create-account' !== $name;
		$addresses   = 'core/columns' === $name && false !== strpos( (string) ( $block['attrs']['className'] ?? '' ), 'wc-block-order-confirmation-address-wrapper' );
		if ( ( ! $order_block && ! $addresses ) || ! self::received_order() ) {
			return $pre;
		}
		if ( self::$receipt_printed ) {
			return '';
		}
		self::$receipt_printed = true;
		return '<div class="alignwide">' . do_shortcode( '[woocommerce_checkout]' ) . '</div>';
	}

	/**
	 * [woocommerce_checkout] on classic checkout pages.
	 *
	 * @param false|string $pre Short-circuited output.
	 * @param string       $tag Shortcode tag.
	 * @return false|string
	 */
	public static function checkout_shortcode( $pre, $tag ) {
		return 'woocommerce_checkout' === $tag && self::is_restaurant_checkout() ? self::render_checkout() : $pre;
	}

	/** An empty order flow root; wowrestro-design.js builds the checkout into it from the Store API cart. */
	private static function render_checkout() {
		self::enqueue_design();
		$template = self::template_number();
		self::enqueue_template_fonts( $template );
		$classic = add_query_arg( 'wowrestro_gateway', 'classic', wc_get_checkout_url() );
		return '<section class="wowrestro-menu wr-template-' . $template . '" data-screen="checkout"' . self::root_attributes( $template, false ) . '>' .
			'<noscript><p>' . esc_html__( 'This checkout needs JavaScript.', 'wowrestro' ) . ' <a href="' . esc_url( $classic ) . '">' . esc_html__( 'Use the standard checkout', 'wowrestro' ) . '</a></p></noscript></section>';
	}

	/**
	 * Data attributes that give the order flow a template's skin.
	 *
	 * @param int  $template Template number.
	 * @param bool $menu     Whether the menu's layout, nav and cart style apply too.
	 * @return string Escaped attributes, each with a leading space.
	 */
	public static function root_attributes( $template, $menu = true ) {
		$spec = self::template_specs()[ $template ];
		$out  = ' data-template="' . esc_attr( $template ) . '"';
		// Template 1 keeps the original inline palette; every other template takes its skin from wowrestro-templates.css.
		if ( ! $spec['skin'] ) {
			return $out . ' style="--wr-red:#d9281a;--wr-ink:#171713;--wr-paper:#f7f2e9;--wr-radius:0px;--oe-ember:#d9281a;--oe-ember-soft:#d9281a;--oe-bg:#f7f2e9;--oe-ink:#171713;--oe-fg:#171713;--oe-panel:#fff;--oe-dim:#6b7280;--oe-dim2:#6b7280;--oe-mute:#52525b;--oe-mute2:#6b7280;--oe-line:rgba(23,23,19,.14);--oe-line-soft:rgba(23,23,19,.08)"';
		}
		$data = array(
			'skin' => $spec['skin'],
			'tone' => $spec['dark'] ? 'dark' : '',
		);
		if ( $menu ) {
			$data += array(
				'layout' => $spec['layout'],
				'nav'    => $spec['nav'],
				'cart'   => $spec['cart'],
				'hero'   => $spec['hero'],
				'group'  => in_array( $spec['layout'], array( 'board', 'carousel', 'rows', 'compact', 'spotlight' ), true ) ? '1' : '',
			);
		}
		foreach ( array_filter( $data ) as $key => $value ) {
			$out .= ' data-' . $key . '="' . esc_attr( $value ) . '"';
		}
		return $out;
	}

	/**
	 * The template of the menu the customer ordered from, so the checkout and receipt wear its skin.
	 *
	 * @return int
	 */
	public static function template_number() {
		$template = function_exists( 'WC' ) && WC()->session ? absint( WC()->session->get( 'wowrestro_menu_template', 0 ) ) : 0;
		return min( 36, max( 1, $template ?: absint( WowRestro_Fulfillment::settings()['menu_template'] ?? 1 ) ) );
	}

	/**
	 * The menu the customer ordered from, then the setup wizard's ordering page, then the home page.
	 *
	 * @return string
	 */
	public static function menu_url() {
		$url = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get( 'wowrestro_menu_url', '' ) : '';
		if ( $url ) {
			return $url;
		}
		$page = absint( get_option( 'wowrestro_menu_page_id', 0 ) );
		return $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : home_url( '/' );
	}

	public static function store_address() {
		$countries = WC()->countries;
		return implode( ', ', array_filter( array( $countries->get_base_address(), $countries->get_base_city(), $countries->get_base_postcode() ) ) );
	}

	/**
	 * The restaurant order on WooCommerce's order-received page, once its key checks out.
	 *
	 * @return WC_Order|null
	 */
	public static function received_order() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return null;
		}
		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
		$key   = self::receipt_key( isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's order key is the credential.
		return self::is_receipt_order( $order ) && hash_equals( $order->get_order_key(), (string) $key ) ? $order : null;
	}

	/**
	 * The order the clean /confirmation/ address shows: the one this browser last opened by its key.
	 *
	 * @return array{id:int,key:string}|null
	 */
	private static function session_receipt() {
		$receipt = function_exists( 'WC' ) && WC()->session ? WC()->session->get( 'wowrestro_receipt' ) : null;
		return is_array( $receipt ) && ! empty( $receipt['id'] ) && ! empty( $receipt['key'] ) ? $receipt : null;
	}

	public static function receipt_query_var( $vars ) {
		$vars[] = 'wowrestro_receipt';
		return $vars;
	}

	/**
	 * The clean address carries no order, so it becomes the order-received endpoint for the session's order.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function receipt_request( $vars ) {
		$receipt = empty( $vars['wowrestro_receipt'] ) ? null : self::session_receipt();
		if ( $receipt ) {
			$vars['order-received'] = (string) absint( $receipt['id'] );
		}
		return $vars;
	}

	/**
	 * WooCommerce reads the order key from ?key=; on the clean address it comes from the session.
	 *
	 * @param string $key Order key from the request.
	 * @return string
	 */
	public static function receipt_key( $key ) {
		$receipt = '' === (string) $key && get_query_var( 'wowrestro_receipt' ) ? self::session_receipt() : null;
		return $receipt ? (string) $receipt['key'] : (string) $key;
	}

	/**
	 * A keyed confirmation link (after checkout, in an email) is remembered in this browser's
	 * session and opens at the clean address, /confirmation/ or a site's own /…/confirmation/.
	 * Links a gateway added its own parameters to stay as they are, so it can finish the payment.
	 */
	public static function clean_receipt_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed and matched below.
		if ( get_query_var( 'wowrestro_receipt' ) ) {
			// No order in this browser: back to the page above, /demo/bakery/ for /demo/bakery/confirmation/.
			if ( ! is_wc_endpoint_url( 'order-received' ) ) {
				$parent = (string) preg_replace( '#confirmation/?$#', '', (string) wp_parse_url( $uri, PHP_URL_PATH ) );
				wp_safe_redirect( untrailingslashit( $parent ) !== untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ? $parent : self::menu_url() );
				exit;
			}
			return;
		}
		$order = self::received_order();
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $args );
		if ( ! $order || array( 'key' ) !== array_keys( $args ) || ! WC()->session
			|| ! preg_match( '#^(/.*?)confirmation/' . $order->get_id() . '/?$#', (string) wp_parse_url( $uri, PHP_URL_PATH ), $match )
			|| ! self::has_receipt_route( $match[1] . 'confirmation/' ) ) {
			return;
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( 'wowrestro_receipt', array( 'id' => $order->get_id(), 'key' => $order->get_order_key() ) );
		nocache_headers();
		wp_safe_redirect( $match[1] . 'confirmation/' );
		exit;
	}

	/** Whether a rewrite rule serves the clean receipt at this path. */
	private static function has_receipt_route( $path ) {
		global $wp_rewrite;
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = ltrim( $path, '/' );
		$path = $home && 0 === strpos( $path, $home . '/' ) ? substr( $path, strlen( $home ) + 1 ) : $path;
		foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $regex => $query ) {
			if ( false !== strpos( $query, 'wowrestro_receipt=' ) && preg_match( '#^' . $regex . '#', $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * /confirmation/{order}/ is WooCommerce's order-received page at its own address. The request is
	 * the checkout page with the order-received endpoint, so gateways, thank-you hooks and the guest
	 * email check run exactly as they do on /checkout/order-received/, which keeps working too.
	 */
	public static function confirmation_route() {
		$checkout = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
		if ( $checkout > 0 ) {
			add_rewrite_rule( '^confirmation/([0-9]+)/?$', 'index.php?page_id=' . $checkout . '&order-received=$matches[1]', 'top' );
			add_rewrite_rule( '^confirmation/?$', 'index.php?page_id=' . $checkout . '&wowrestro_receipt=1', 'top' );
		}
	}

	/**
	 * Restaurant orders confirm at /confirmation/{order}/?key=…, or under their store's page: after
	 * checkout, from gateways and in emails.
	 *
	 * @param string   $url   Order-received URL.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function confirmation_url( $url, $order ) {
		if ( ! get_option( 'permalink_structure' ) || ! $order instanceof WC_Order || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) || wc_get_page_id( 'checkout' ) <= 0 ) {
			return $url;
		}
		$base = WowRestro_Stores::order_base( $order );
		return add_query_arg( 'key', $order->get_order_key(), ( $base ? $base : home_url( '/' ) ) . 'confirmation/' . $order->get_id() . '/' );
	}

	/** Failed payments keep WooCommerce's own page, which offers to pay again. */
	private static function is_receipt_order( $order ) {
		return $order instanceof WC_Order && WowRestro_Order_Statuses::is_restaurant_order( $order ) && ! $order->has_status( 'failed' );
	}

	/**
	 * Restaurant orders get the order flow's receipt in place of checkout/thankyou.php.
	 *
	 * @param string $template Located template.
	 * @param string $name     Template name.
	 * @param array  $args     Template arguments.
	 * @return string
	 */
	public static function thankyou_template( $template, $name, $args ) {
		return 'checkout/thankyou.php' === $name && self::is_receipt_order( $args['order'] ?? null ) ? WOWRESTRO_PATH . 'includes/views/order-received.php' : $template;
	}

	public static function order_mode( $order ) {
		return ( $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ) ) === 'delivery' ? 'delivery' : 'pickup';
	}

	public static function promised_label( $order ) {
		$promise = $order->get_meta( '_wowrestro_promised_at' );
		if ( ! $promise ) {
			return '';
		}
		try {
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), ( new DateTimeImmutable( $promise ) )->getTimestamp(), wp_timezone() );
		} catch ( Exception $exception ) {
			return $promise;
		}
	}

	/**
	 * Where the kitchen is with an order. The receipt refreshes it through the tracking route.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function tracker_html( $order ) {
		$delivery = 'delivery' === self::order_mode( $order );
		$status   = WowRestro_Order_Statuses::current( $order );
		if ( 'cancelled' === $status || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			return '<div class="oe-tracker" data-oe-tracker data-status="cancelled" data-final aria-live="polite"><div class="oe-tracker__head"><span class="oe-tracker__title">' . esc_html__( 'Order cancelled', 'wowrestro' ) . '</span></div>' .
				'<p class="oe-tl__body">' . esc_html__( 'This order was cancelled. Contact the restaurant if that is unexpected.', 'wowrestro' ) . '</p></div>';
		}
		// Title, then the line shown while current, once done, and while still to come.
		$stages = array(
			'new'       => array( __( 'Order received', 'wowrestro' ), __( 'The kitchen has your order.', 'wowrestro' ), __( 'Received.', 'wowrestro' ), '' ),
			'accepted'  => array( __( 'Accepted', 'wowrestro' ), __( 'The kitchen accepted your order.', 'wowrestro' ), __( 'Accepted.', 'wowrestro' ), __( 'Waiting on the kitchen.', 'wowrestro' ) ),
			'preparing' => array( __( 'Preparing', 'wowrestro' ), __( 'Your food is being cooked now.', 'wowrestro' ), __( 'Cooked.', 'wowrestro' ), __( 'Not started yet.', 'wowrestro' ) ),
			'ready'     => $delivery
				? array( __( 'Ready', 'wowrestro' ), __( 'Packed and waiting for the rider.', 'wowrestro' ), __( 'Ready.', 'wowrestro' ), __( 'Not ready yet.', 'wowrestro' ) )
				: array( __( 'Ready for collection', 'wowrestro' ), __( 'Ready now - come and collect it.', 'wowrestro' ), __( 'Ready.', 'wowrestro' ), __( 'Not ready yet.', 'wowrestro' ) ),
		);
		if ( $delivery ) {
			$stages['out_for_delivery'] = array( __( 'Out for delivery', 'wowrestro' ), __( 'On its way to you.', 'wowrestro' ), __( 'Handed to the rider.', 'wowrestro' ), __( 'Not dispatched yet.', 'wowrestro' ) );
			$stages['completed']        = array( __( 'Delivered', 'wowrestro' ), __( 'Delivered - enjoy.', 'wowrestro' ), __( 'Delivered.', 'wowrestro' ), __( 'Not delivered yet.', 'wowrestro' ) );
		} else {
			$stages['completed'] = array( __( 'Collected', 'wowrestro' ), __( 'Collected - enjoy.', 'wowrestro' ), __( 'Collected.', 'wowrestro' ), __( 'Not collected yet.', 'wowrestro' ) );
		}
		$status  = isset( $stages[ $status ] ) ? $status : ( $order->has_status( 'completed' ) ? 'completed' : 'new' );
		$at      = array_search( $status, array_keys( $stages ), true );
		$promise = self::promised_label( $order );
		$html    = '<div class="oe-tracker" data-oe-tracker data-status="' . esc_attr( $status ) . '"' . ( 'completed' === $status ? ' data-final' : '' ) . ' aria-live="polite">' .
			'<div class="oe-tracker__head"><span class="oe-tracker__title">' . esc_html( $delivery ? __( 'Tracking your delivery', 'wowrestro' ) : __( 'Your collection', 'wowrestro' ) ) . '</span>' .
			( $promise ? '<span class="oe-tracker__eta">' . esc_html( sprintf( __( 'Promised for %s', 'wowrestro' ), $promise ) ) . '</span>' : '' ) . '</div><ol class="oe-tl-list">';
		$index = 0;
		foreach ( $stages as $stage ) {
			$state = $index < $at ? 'is-done' : ( $index === $at ? 'is-now' : '' );
			$line  = $index < $at ? $stage[2] : ( $index === $at ? $stage[1] : $stage[3] );
			$html .= '<li class="oe-tl ' . $state . '"' . ( $index === $at ? ' aria-current="step"' : '' ) . '><span class="oe-tl__rail"><span class="oe-tl__dot"></span><span class="oe-tl__line"></span></span>' .
				'<span class="oe-tl__text"><span class="oe-tl__title">' . esc_html( $stage[0] ) . '</span><span class="oe-tl__body">' . esc_html( $line ) . '</span></span></li>';
			++$index;
		}
		return $html . '</ol></div>';
	}

	/**
	 * Scope wowrestro-design.css to pages that print the order flow.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function design_body_class( $classes ) {
		if ( self::$design ) {
			$classes[] = 'wowrestro-design';
		}
		return $classes;
	}

	/** Without JavaScript the plugin's own menu stays usable instead of the loading state. */
	public static function design_noscript() {
		if ( self::$design ) {
			echo '<noscript><style id="oe-noscript">.wowrestro-design .wowrestro-menu:not([data-oe-ready]) > *{opacity:1!important;animation:none!important}.wowrestro-design .wowrestro-menu:not([data-oe-ready])::after{display:none!important}</style></noscript>';
		}
	}

	public static function register_block() {
		self::register_assets();
		wp_register_script( 'wowrestro-menu-block', WOWRESTRO_URL . 'assets/js/menu-block.js', array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor' ), WOWRESTRO_VERSION, true );
		wp_localize_script( 'wowrestro-menu-block', 'wowRestroMenuTemplates', self::templates() );
		register_block_type( WOWRESTRO_PATH . 'blocks/menu', array( 'render_callback' => array( __CLASS__, 'render_menu' ) ) );
	}

	public static function menu_shortcode( $attributes ) {
		return self::render_menu( $attributes );
	}

	public static function render_menu( $attributes = array() ) {
		wp_enqueue_style( 'wowrestro-storefront' );
		wp_enqueue_style( 'wowrestro-features' );
		wp_enqueue_script( 'wowrestro-storefront' );
		self::enqueue_design();
		$settings = WowRestro_Fulfillment::settings();

		$attributes = shortcode_atts(
			array(
				'template' => '',
				'layout'   => '',
				'location' => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			'wowrestro_menu'
		);
		$template   = min( 36, max( 1, absint( $attributes['template'] ?: ( $settings['menu_template'] ?? 1 ) ) ) );
		$layout     = sanitize_key( $attributes['layout'] ?: ( $settings['menu_layout'] ?? 'tabs' ) );
		$layout     = in_array( $layout, array( 'list', 'tabs' ), true ) ? $layout : 'tabs';
		$location   = sanitize_title( $attributes['location'] ?: ( class_exists( 'WowRestro_Locations' ) ? WowRestro_Locations::current() : '' ) );
		$page       = isset( $_GET['wowrestro_page'] ) ? max( 1, absint( $_GET['wowrestro_page'] ) ) : 1;
		$category   = isset( $_GET['wowrestro_category'] ) ? sanitize_title( wp_unslash( $_GET['wowrestro_category'] ) ) : '';
		$args       = array(
			'status'     => 'publish',
			'meta_key'   => WowRestro_Products::MENU_ITEM_META,
			'meta_value' => 'yes',
			'limit'      => max( 12, absint( $settings['menu_page_size'] ) ),
			'page'       => $page,
			'paginate'   => true,
			'orderby'    => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		);
		self::enqueue_template_fonts( $template );
		if ( $category ) {
			$args['category'] = array( $category );
		}
		$empty_location = false;
		if ( $location && class_exists( 'WowRestro_Locations' ) ) {
			$term            = get_term_by( 'slug', $location, WowRestro_Locations::TAXONOMY );
			$location_ids    = $term ? get_objects_in_term( $term->term_id, WowRestro_Locations::TAXONOMY ) : array();
			$empty_location  = is_wp_error( $location_ids ) || ! $location_ids;
			if ( ! $empty_location ) {
				$args['include'] = array_map( 'absint', $location_ids );
			}
		}
		$query       = $empty_location ? array() : wc_get_products( $args );
		$products    = is_object( $query ) ? $query->products : $query;
		$total_pages = is_object( $query ) ? absint( $query->max_num_pages ) : 1;
		$categories  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'menu_order',
			)
		);

		ob_start();
		?>
		<section class="wowrestro-menu wowrestro-menu--<?php echo esc_attr( $layout ); ?> wr-template-<?php echo esc_attr( $template ); ?>" data-wowrestro-menu<?php echo self::root_attributes( $template ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by root_attributes(). ?>>
			<?php
			if ( class_exists( 'WowRestro_Locations' ) ) {
				echo WowRestro_Locations::selector(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the selector renderer.
			}
			?>
			<header class="wowrestro-menu__header">
				<div><span><?php esc_html_e( 'Order direct', 'wowrestro' ); ?></span><h2><?php esc_html_e( 'Our menu', 'wowrestro' ); ?></h2></div>
				<label><span class="screen-reader-text"><?php esc_html_e( 'Search this page', 'wowrestro' ); ?></span><input type="search" placeholder="<?php esc_attr_e( 'Search dishes on this page', 'wowrestro' ); ?>" data-wowrestro-search></label>
			</header>
			<?php if ( ! is_wp_error( $categories ) && $categories ) : ?>
			<nav class="wowrestro-menu__categories" aria-label="<?php esc_attr_e( 'Menu categories', 'wowrestro' ); ?>">
				<a class="<?php echo $category ? '' : 'is-active'; ?>" href="<?php echo esc_url( remove_query_arg( array( 'wowrestro_category', 'wowrestro_page' ) ) ); ?>"><?php esc_html_e( 'All', 'wowrestro' ); ?></a>
				<?php foreach ( $categories as $term ) : ?>
					<a class="<?php echo $category === $term->slug ? 'is-active' : ''; ?>" href="<?php
					echo esc_url(
						add_query_arg(
							array(
								'wowrestro_category' => $term->slug,
								'wowrestro_page'     => false,
							)
						)
					);
					?>"><?php echo esc_html( $term->name ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php endif; ?>
			<div class="wowrestro-menu__products">
			<?php
			if ( ! $products ) :
				?>
				<p class="wowrestro-menu__empty"><?php esc_html_e( 'No dishes are available in this category yet.', 'wowrestro' ); ?></p><?php endif; ?>
			<?php
			foreach ( $products as $product ) :
				$labels             = WowRestro_Products::labels( $product );
				$dietary            = implode( ', ', $labels['dietary'] );
				$allergen           = implode( ', ', $labels['allergens'] );
				$nutrition          = $product->get_meta( '_wowrestro_nutrition', true );
				$nutrition          = is_array( $nutrition ) ? $nutrition : array();
				$search             = strtolower( $product->get_name() . ' ' . wp_strip_all_tags( $product->get_short_description() ) . ' ' . $dietary );
				$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
				?>
				<article class="wowrestro-product" data-search="<?php echo esc_attr( $search ); ?>" data-categories="<?php echo esc_attr( implode( ' ', is_wp_error( $product_categories ) ? array() : $product_categories ) ); ?>">
					<a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?></a>
					<div><h3><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3><p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 18 ) ); ?></p>
					<?php
					if ( $dietary ) :
						?>
						<span class="wowrestro-product__label"><?php echo esc_html( $dietary ); ?></span><?php endif; ?>
					<?php
					if ( $allergen ) :
						?>
						<span class="wowrestro-product__allergens"><?php echo esc_html( sprintf( __( 'Allergens: %s', 'wowrestro' ), $allergen ) ); ?></span><?php endif; ?>
					<?php
					if ( ! empty( $nutrition['calories'] ) ) :
						?>
						<span class="wowrestro-product__nutrition"><?php echo esc_html( sprintf( __( '%s kcal', 'wowrestro' ), $nutrition['calories'] ) ); ?></span><?php endif; ?>
					<strong><?php echo wp_kses_post( $product->get_price_html() ); ?></strong></div>
					<?php
					if ( $product->is_in_stock() ) :
						$previous_product   = $GLOBALS['product'] ?? null;
						$GLOBALS['product'] = $product;
						woocommerce_template_loop_add_to_cart();
						if ( $previous_product instanceof WC_Product ) {
							$GLOBALS['product'] = $previous_product;
						} else {
							unset( $GLOBALS['product'] );
						}
					else :
							?>
						<span class="wowrestro-product__sold-out"><?php esc_html_e( 'Sold out', 'wowrestro' ); ?></span><?php endif; ?>
				</article>
			<?php endforeach; ?>
			</div>
			<?php if ( $total_pages > 1 ) : ?>
			<nav class="wowrestro-menu__pagination" aria-label="<?php esc_attr_e( 'Menu pages', 'wowrestro' ); ?>">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'     => add_query_arg( 'wowrestro_page', '%#%' ),
							'format'   => '',
							'current'  => $page,
							'total'    => $total_pages,
							'add_args' => $category ? array( 'wowrestro_category' => $category ) : array(),
						)
					)
				);
				?>
																</nav>
			<?php endif; ?>
			<a class="wowrestro-menu__cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>" aria-label="<?php esc_attr_e( 'View your WooCommerce cart', 'wowrestro' ); ?>"><span><?php esc_html_e( 'View order', 'wowrestro' ); ?></span><strong data-wowrestro-cart-count><?php echo esc_html( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?></strong></a>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function templates() {
		$templates = array();
		foreach ( self::template_specs() as $number => $spec ) {
			/* translators: 1: template number, 2: template name */
			$templates[ $number ] = sprintf( __( 'Template %1$d - %2$s', 'wowrestro' ), $number, $spec['name'] );
		}
		return $templates;
	}

	/**
	 * Each template is an ordering style: how dishes are laid out, how categories are picked, where
	 * the order sits (a side panel, a drawer, or a bottom sheet), what heads the page, and a skin whose
	 * colours and type live in wowrestro-templates.css under the same slug. Every layout comes with all
	 * three carts. Template 1 is the original Olive & Ember design and has no skin.
	 *
	 * @return array[] Keyed by template number.
	 */
	public static function template_specs() {
		// Name, skin, layout, category nav, cart, hero, Google Fonts families, dark.
		$rows  = array(
			1  => array( __( 'Classic Cards', 'wowrestro' ), '', 'cards', 'tabs', 'side', 'feature', '' ),
			2  => array( __( 'Editorial List', 'wowrestro' ), 'editorial', 'ledger', 'tabs', 'side', 'center', 'Fraunces:ital,wght@0,400;0,500;1,400|Inter:wght@400;500;600' ),
			3  => array( __( 'Modern Tiles', 'wowrestro' ), 'tiles', 'tiles', 'pills', 'drawer', 'banner', 'Outfit:wght@500;600;700|Work+Sans:wght@400;500;600' ),
			4  => array( __( 'Menu Card', 'wowrestro' ), 'menucard', 'board', 'segment', 'side', 'center', 'Geist:wght@400;500;600;700' ),
			5  => array( __( 'Delivery Rows', 'wowrestro' ), 'delivery', 'rows', 'pills', 'sheet', 'banner', 'DM+Sans:wght@400;500;700' ),
			6  => array( __( 'Midnight Grill', 'wowrestro' ), 'grill', 'cards', 'pills', 'drawer', 'split', 'Barlow+Condensed:wght@600;700|Barlow:wght@400;500;600', true ),
			7  => array( __( 'Story Feed', 'wowrestro' ), 'story', 'feed', 'segment', 'side', 'center', 'Onest:wght@400;500;700' ),
			8  => array( __( 'Snack Bar', 'wowrestro' ), 'bite', 'tiles', 'blocks', 'sheet', 'feature', 'Funnel+Display:wght@500;700|Inter:wght@400;500;600' ),
			9  => array( __( 'Noir', 'wowrestro' ), 'noir', 'magazine', 'tabs', 'side', 'center', 'Gloock|Inter:wght@400;500;600', true ),
			10 => array( __( 'Pastel Bakery', 'wowrestro' ), 'bakery', 'circles', 'pills', 'drawer', 'split', 'Fredoka:wght@500;600|Nunito:wght@400;600;700' ),
			11 => array( __( 'Showcase Rows', 'wowrestro' ), 'showcase', 'carousel', 'tabs', 'side', 'banner', 'Sora:wght@600;700|Inter:wght@400;500;600', true ),
			12 => array( __( 'Bento Box', 'wowrestro' ), 'bento', 'bento', 'segment', 'drawer', 'feature', 'Plus+Jakarta+Sans:wght@400;500;700;800' ),
			13 => array( __( 'Quick Order', 'wowrestro' ), 'quick', 'compact', 'segment', 'side', 'compact', 'Albert+Sans:wght@400;500;700' ),
			14 => array( __( 'Farm Table', 'wowrestro' ), 'farm', 'spotlight', 'blocks', 'drawer', 'banner', 'Young+Serif|Figtree:wght@400;500;700' ),
			15 => array( __( 'After Dark', 'wowrestro' ), 'afterdark', 'board', 'tabs', 'drawer', 'compact', 'Space+Grotesk:wght@500;600;700|Inter:wght@400;500;600', true ),
			16 => array( __( 'Spice Route', 'wowrestro' ), 'spice', 'rows', 'sidebar', 'side', 'feature', 'Gabarito:wght@600;800|Mukta:wght@400;500;700' ),
			17 => array( __( 'Nordic Light', 'wowrestro' ), 'nordic', 'feed', 'tabs', 'drawer', 'compact', 'Hanken+Grotesk:wght@400;500;600;700' ),
			18 => array( __( 'Kiosk', 'wowrestro' ), 'kiosk', 'tiles', 'sidebar', 'side', 'compact', 'Rubik:wght@400;500;700;800' ),
			19 => array( __( 'Mediterranean', 'wowrestro' ), 'med', 'magazine', 'pills', 'drawer', 'split', 'DM+Serif+Display|Mulish:wght@400;500;700' ),
			20 => array( __( 'Neon Night', 'wowrestro' ), 'neon', 'bento', 'pills', 'sheet', 'poster', 'Syne:wght@700;800|Space+Grotesk:wght@400;500', true ),
			21 => array( __( 'Juice Bar', 'wowrestro' ), 'juice', 'circles', 'blocks', 'side', 'banner', 'Unbounded:wght@500;700|Nunito:wght@400;600;700' ),
			22 => array( __( 'Taqueria', 'wowrestro' ), 'taqueria', 'carousel', 'pills', 'drawer', 'poster', 'Big+Shoulders+Display:wght@700;800|Work+Sans:wght@400;500;600' ),
			23 => array( __( 'Coffee Bar', 'wowrestro' ), 'coffee', 'compact', 'sidebar', 'drawer', 'compact', 'Schibsted+Grotesk:wght@400;500;700' ),
			24 => array( __( 'Ramen Bar', 'wowrestro' ), 'ramen', 'ledger', 'segment', 'drawer', 'poster', 'Dela+Gothic+One|Zen+Kaku+Gothic+New:wght@400;500;700', true ),
			25 => array( __( 'Express Grid', 'wowrestro' ), 'express', 'cards', 'sidebar', 'sheet', 'compact', 'Nunito+Sans:wght@400;600;800' ),
			26 => array( __( 'Garden Green', 'wowrestro' ), 'garden', 'bento', 'blocks', 'side', 'center', 'Instrument+Serif:ital@0;1|Instrument+Sans:wght@400;500;600' ),
			27 => array( __( 'Sushi Bar', 'wowrestro' ), 'sushi', 'rows', 'tabs', 'drawer', 'split', 'Urbanist:wght@500;700;800|Inter:wght@400;500;600', true ),
			28 => array( __( 'Poke Bowl', 'wowrestro' ), 'poke', 'feed', 'pills', 'sheet', 'banner', 'Lexend:wght@400;500;700' ),
			29 => array( __( 'Canteen', 'wowrestro' ), 'canteen', 'board', 'pills', 'sheet', 'banner', 'Familjen+Grotesk:wght@500;700|Inter:wght@400;500;600' ),
			30 => array( __( 'Brunch Club', 'wowrestro' ), 'brunch', 'spotlight', 'tabs', 'side', 'feature', 'Epilogue:wght@400;500;700;800' ),
			31 => array( __( 'Food Hall', 'wowrestro' ), 'hall', 'carousel', 'blocks', 'sheet', 'center', 'Bricolage+Grotesque:wght@600;800|Figtree:wght@400;500;700' ),
			32 => array( __( 'Glass Lounge', 'wowrestro' ), 'glass', 'magazine', 'segment', 'sheet', 'banner', 'Red+Hat+Display:wght@500;700|Inter:wght@400;500;600', true ),
			33 => array( __( 'Boba Bar', 'wowrestro' ), 'boba', 'circles', 'segment', 'sheet', 'center', 'M+PLUS+Rounded+1c:wght@500;700;800' ),
			34 => array( __( 'Catering Sheet', 'wowrestro' ), 'catering', 'compact', 'tabs', 'sheet', 'compact', 'IBM+Plex+Sans:wght@400;500;600|IBM+Plex+Mono:wght@500' ),
			35 => array( __( 'Swiss Grid', 'wowrestro' ), 'swiss', 'ledger', 'sidebar', 'sheet', 'poster', 'Inter+Tight:wght@500;700;800|Inter:wght@400;500' ),
			36 => array( __( 'Pizzeria', 'wowrestro' ), 'pizzeria', 'spotlight', 'pills', 'sheet', 'feature', 'Anton|Inter:wght@400;500;600' ),
		);
		$specs = array();
		foreach ( $rows as $number => $row ) {
			$specs[ $number ] = array_combine( array( 'name', 'skin', 'layout', 'nav', 'cart', 'hero', 'fonts', 'dark' ), array_pad( $row, 8, false ) );
		}
		return $specs;
	}

	/**
	 * Google Fonts for a template's skin; only the template on the page loads its fonts.
	 *
	 * @param int $template Template number.
	 */
	private static function enqueue_template_fonts( $template ) {
		$spec = self::template_specs()[ $template ] ?? null;
		if ( $spec && $spec['fonts'] ) {
			// ponytail: Google Fonts CDN like the base design's @import; self-host if a store needs GDPR-strict fonts.
			wp_enqueue_style( 'wowrestro-fonts-' . $spec['skin'], 'https://fonts.googleapis.com/css2?family=' . str_replace( '|', '&family=', $spec['fonts'] ) . '&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URLs are versioned by their query.
		}
	}

	public static function register_elementor_widget( $manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once WOWRESTRO_PATH . 'includes/class-wowrestro-elementor-widget.php';
		$manager->register( new WowRestro_Elementor_Menu_Widget() );
	}

	public static function tracking_shortcode() {
		wp_enqueue_style( 'wowrestro-storefront' );
		$order_id  = isset( $_GET['wowrestro_order'] ) ? absint( $_GET['wowrestro_order'] ) : 0;
		$order_key = isset( $_GET['wowrestro_key'] ) ? wc_clean( wp_unslash( $_GET['wowrestro_key'] ) ) : '';
		if ( $order_id && $order_key ) {
			$order = wc_get_order( $order_id );
			if ( self::is_receipt_order( $order ) && $order->get_meta( '_wowrestro_tracking_token' ) && hash_equals( $order->get_order_key(), $order_key ) ) {
				return self::receipt_html( $order );
			}
		}
		ob_start();
		?>
		<form class="wowrestro-tracking-form" method="get"><label><?php esc_html_e( 'Order number', 'wowrestro' ); ?><input name="wowrestro_order" inputmode="numeric" required></label><label><?php esc_html_e( 'Order key from your receipt', 'wowrestro' ); ?><input name="wowrestro_key" required></label><button><?php esc_html_e( 'Track order', 'wowrestro' ); ?></button></form>
		<?php
		return ob_get_clean();
	}

	public static function email_tracking_link( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $order->get_meta( '_wowrestro_mode' ) || ! $order->get_meta( '_wowrestro_tracking_token' ) ) {
			return;
		}
		$url  = $order->get_checkout_order_received_url();
		$text = __( 'Track your restaurant order', 'wowrestro' );
		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . ': ' . esc_url( $url ) . "\n";
		} else {
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
		}
	}

	/**
	 * The receipt for the tracking shortcode, without the thank-you hooks the order-received page fires.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function receipt_html( $order ) {
		self::enqueue_receipt();
		$wowrestro_hooks = false;
		ob_start();
		include WOWRESTRO_PATH . 'includes/views/order-received.php';
		return ob_get_clean();
	}
}
