<?php
/**
 * Store pages: an ordering page whose menu keeps to one product category runs as its own store.
 *
 *   [wowrestro_menu category="bakery" name="Bakery" label="Bakery" tagline="Fresh every morning" hero="Baked this morning|ready when you are"
 *     heading="Everything we bake" note="..." accent="#a16207" ink="#2b1d16" paper="#fff6ed" radius="16" type="shop" header="store"]
 *
 * A store has its own checkout and confirmation under its page (/bakery/checkout/, /bakery/confirmation/)
 * and wears its name and colours there too. A cart belongs to the store its dishes come from, and a
 * store's menu drops dishes added in another store, since every store shares one WooCommerce cart.
 * header="store" swaps the theme's header and footer for a store header with a cart pill, and
 * [wowrestro_store_switcher] links the stores (by label, else page title).
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Stores {
	/** Store page IDs and their paths, kept fresh as pages are saved. */
	const OPTION = 'wowrestro_store_pages';

	/** The store page an order was placed from. */
	const META = '_wowrestro_store_page';

	/** What a store page's menu shortcode can set. */
	const ATTS = array( 'category', 'name', 'label', 'tagline', 'hero', 'heading', 'note', 'accent', 'ink', 'paper', 'radius', 'type', 'header', 'template' );

	/** @var array|null */
	private static $current = null;

	/** @var bool */
	private static $resolved = false;

	/** @var array<int,int[]> */
	private static $terms = array();

	public static function init() {
		add_action( 'save_post_page', array( __CLASS__, 'refresh' ) );
		add_action( 'deleted_post', array( __CLASS__, 'refresh' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_var' ) );
		add_action( 'init', array( __CLASS__, 'routes' ) );
		add_filter( 'wowrestro_design_config', array( __CLASS__, 'design_config' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route_cart' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'remember_store' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'remember_store' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'pre_title' ), 20 );
		add_filter( 'gettext_wowrestro', array( __CLASS__, 'shop_words' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 100 );
		add_action( 'wp_body_open', array( __CLASS__, 'top' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 5 );
		add_shortcode( 'wowrestro_store_switcher', array( __CLASS__, 'switcher_shortcode' ) );
	}

	/**
	 * Store page IDs mapped to their paths, in page order.
	 *
	 * @return array<int,string>
	 */
	public static function pages() {
		$pages = get_option( self::OPTION );
		if ( ! is_array( $pages ) ) {
			$pages = self::refresh();
		}
		return $pages;
	}

	/**
	 * Find the store pages again. A changed set or path rebuilds the rewrite rules on the next request.
	 *
	 * @return array<int,string>
	 */
	public static function refresh() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A cheap candidate list; each page is parsed below.
		$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%category=%' AND ( post_content LIKE '%[wowrestro_menu%' OR post_content LIKE '%[wowrestro %' ) ORDER BY post_parent, menu_order, ID" );
		$pages = array();
		foreach ( $ids as $id ) {
			if ( '' !== ( self::page_atts( $id )['category'] ?? '' ) ) {
				$pages[ (int) $id ] = (string) get_page_uri( $id );
			}
		}
		if ( get_option( self::OPTION ) !== $pages ) {
			update_option( self::OPTION, $pages, true );
			update_option( 'wowrestro_flush_rewrite', 'yes', false );
		}
		self::$resolved = false;
		return $pages;
	}

	/**
	 * The store settings in a page's menu shortcode.
	 *
	 * @param int $page_id Page.
	 * @return array<string,string>
	 */
	public static function page_atts( $page_id ) {
		$content = (string) get_post_field( 'post_content', $page_id );
		if ( ! preg_match( '/' . get_shortcode_regex( array( 'wowrestro_menu', 'wowrestro' ) ) . '/', $content, $match ) ) {
			return array();
		}
		$atts = shortcode_parse_atts( $match[3] );
		return is_array( $atts ) ? array_map( 'strval', array_intersect_key( $atts, array_flip( self::ATTS ) ) ) : array();
	}

	/**
	 * A store by its page.
	 *
	 * @param int $page_id Page.
	 * @return array|null
	 */
	public static function store( $page_id ) {
		$pages = self::pages();
		$atts  = isset( $pages[ $page_id ] ) ? self::page_atts( $page_id ) : array();
		if ( '' === ( $atts['category'] ?? '' ) ) {
			return null;
		}
		$store          = array_merge( array_fill_keys( self::ATTS, '' ), $atts );
		$store['id']    = (int) $page_id;
		$store['label'] = '' !== $store['label'] ? $store['label'] : wp_strip_all_tags( get_the_title( $page_id ) );
		$store['name']  = '' !== $store['name'] ? $store['name'] : $store['label'];
		$store['url']   = home_url( '/' . $pages[ $page_id ] . '/' );
		foreach ( array( 'accent', 'ink', 'paper' ) as $colour ) {
			$store[ $colour ] = (string) sanitize_hex_color( $store[ $colour ] );
		}
		return $store;
	}

	/**
	 * The store this request belongs to: a store page, its checkout and confirmation, or the plain
	 * checkout and confirmation of a store's cart or order.
	 *
	 * @return array|null
	 */
	public static function current() {
		if ( self::$resolved || ! did_action( 'wp' ) ) {
			return self::$current;
		}
		self::$resolved = true;
		$id             = 0;
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$id = absint( get_query_var( 'wowrestro_store' ) );
			if ( ! $id && is_wc_endpoint_url( 'order-received' ) ) {
				$order = WowRestro_Storefront::received_order();
				$id    = $order ? self::order_store( $order ) : 0;
			}
			$id = $id ? $id : self::cart_store();
		} elseif ( is_page() ) {
			$id = get_queried_object_id();
		}
		self::$current = $id ? self::store( $id ) : null;
		return self::$current;
	}

	/** Whether this request is a store's menu, not the checkout it leads to. */
	public static function on_menu() {
		return self::current() && ! ( function_exists( 'is_checkout' ) && is_checkout() );
	}

	/**
	 * A store's category and its sub-categories.
	 *
	 * @param int $page_id Store page.
	 * @return int[]
	 */
	private static function term_ids( $page_id ) {
		if ( ! isset( self::$terms[ $page_id ] ) ) {
			$term                    = get_term_by( 'slug', self::page_atts( $page_id )['category'] ?? '', 'product_cat' );
			self::$terms[ $page_id ] = $term ? array_merge( array( $term->term_id ), array_map( 'intval', (array) get_term_children( $term->term_id, 'product_cat' ) ) ) : array();
		}
		return self::$terms[ $page_id ];
	}

	/**
	 * The store a product is sold in.
	 *
	 * @param int $product_id Product.
	 * @return int Store page, or 0.
	 */
	public static function product_store( $product_id ) {
		foreach ( array_keys( self::pages() ) as $page_id ) {
			$terms = self::term_ids( $page_id );
			if ( $terms && has_term( $terms, 'product_cat', $product_id ) ) {
				return $page_id;
			}
		}
		return 0;
	}

	/**
	 * The store the cart belongs to. Its dishes say it; the session's copy of the menu URL lags behind
	 * when the header cart pill is used after switching stores. An empty cart falls back to that copy.
	 *
	 * @return int Store page, or 0.
	 */
	public static function cart_store() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! self::pages() ) {
			return 0;
		}
		if ( ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$page_id = self::product_store( $item['product_id'] );
				if ( $page_id ) {
					return $page_id;
				}
			}
			return 0;
		}
		$menu = untrailingslashit( WC()->session ? (string) WC()->session->get( 'wowrestro_menu_url', '' ) : '' );
		foreach ( self::pages() as $page_id => $path ) {
			if ( $menu && untrailingslashit( home_url( '/' . $path ) ) === $menu ) {
				return $page_id;
			}
		}
		return 0;
	}

	/**
	 * The store an order was placed from.
	 *
	 * @param WC_Order $order Order.
	 * @return int Store page, or 0.
	 */
	public static function order_store( $order ) {
		$page_id = absint( $order->get_meta( self::META ) );
		if ( ! $page_id ) {
			// Orders from the woorestro.com demo plugin kept the store's path instead.
			$page_id = (int) array_search( (string) $order->get_meta( '_wowrestro_demo_store' ), self::pages(), true );
		}
		return isset( self::pages()[ $page_id ] ) ? $page_id : 0;
	}

	/**
	 * Where an order's confirmation lives: its store's page, or '' for the site's own.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_base( $order ) {
		$store = self::store( self::order_store( $order ) );
		return $store ? $store['url'] : '';
	}

	public static function query_var( $vars ) {
		$vars[] = 'wowrestro_store';
		return $vars;
	}

	/**
	 * Each store's checkout and confirmation are WooCommerce's checkout page underneath, as the site's
	 * /confirmation/ is, so gateways, thank-you hooks and the order-key check still run.
	 */
	public static function routes() {
		$checkout = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
		if ( $checkout <= 0 ) {
			return;
		}
		foreach ( self::pages() as $page_id => $path ) {
			$query = 'index.php?page_id=' . $checkout . '&wowrestro_store=' . $page_id;
			$start = '^' . preg_quote( $path, '#' );
			add_rewrite_rule( $start . '/checkout/?$', $query, 'top' );
			add_rewrite_rule( $start . '/confirmation/([0-9]+)/?$', $query . '&order-received=$matches[1]', 'top' );
			add_rewrite_rule( $start . '/confirmation/?$', $query . '&wowrestro_receipt=1', 'top' );
		}
	}

	/**
	 * The store's name, menu category, copy and checkout for the order flow.
	 *
	 * @param array $config window.WOWRESTRO.
	 * @return array
	 */
	public static function design_config( $config ) {
		$store = self::current();
		if ( ! $store ) {
			return $config;
		}
		$config['storeName']   = $store['name'];
		$config['checkoutUrl'] = $store['url'] . 'checkout/';
		foreach ( array( 'tagline', 'category', 'hero', 'heading', 'note' ) as $key ) {
			if ( '' !== $store[ $key ] ) {
				$config[ $key ] = $store[ $key ];
			}
		}
		return $config;
	}

	/**
	 * A store's menu keeps only its own dishes in the cart. A cart checks out at its own store's
	 * address: /checkout/, another store's checkout or an empty one send the visitor where it belongs.
	 */
	public static function route_cart() {
		$store = self::current();
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! self::pages() ) {
			return;
		}
		// "Back to the menu", "Order again" and the checkout's skin read the session's menu, which can
		// still name the store visited before; on a store's own pages it is that store.
		if ( $store && WC()->session ) {
			WC()->session->set( 'wowrestro_menu_url', $store['url'] );
			WC()->session->set( 'wowrestro_menu_template', min( 36, absint( $store['template'] ) ) );
		}
		if ( $store && self::on_menu() ) {
			$terms = self::term_ids( $store['id'] );
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				if ( $terms && ! has_term( $terms, 'product_cat', $item['product_id'] ) ) {
					WC()->cart->remove_cart_item( $key );
				}
			}
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! is_wc_endpoint_url() ) {
			$asked = absint( get_query_var( 'wowrestro_store' ) );
			$from  = self::cart_store();
			if ( $store && $asked && WC()->cart->is_empty() ) {
				wp_safe_redirect( $store['url'] );
				exit;
			}
			if ( $from !== $asked && ! WC()->cart->is_empty() ) {
				$from_store = self::store( $from );
				$query      = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_QUERY );
				$to         = $from_store ? $from_store['url'] . 'checkout/' : wc_get_checkout_url();
				wp_safe_redirect( $query ? $to . '?' . $query : $to );
				exit;
			}
		}
	}

	/**
	 * The order remembers its store, so its confirmation and email links open that store.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function remember_store( $order ) {
		$page_id = $order instanceof WC_Order ? self::cart_store() : 0;
		if ( $page_id ) {
			$order->update_meta_data( self::META, $page_id );
			$order->save_meta_data();
		}
	}

	public static function body_class( $classes ) {
		$store = self::current();
		if ( $store ) {
			$classes[] = 'wowrestro-store';
			if ( 'store' === $store['header'] ) {
				$classes[] = 'wowrestro-store-chrome';
			}
		}
		return $classes;
	}

	public static function title_parts( $parts ) {
		$store = self::current();
		if ( ! $store ) {
			return $parts;
		}
		if ( self::on_menu() ) {
			return array_filter( array( 'title' => $store['name'], 'tagline' => $store['tagline'] ) );
		}
		$parts['site'] = $store['name'];
		return $parts;
	}

	/** WooCommerce's block order-confirmation template names the tab itself, before the parts above. */
	public static function pre_title( $title ) {
		$store = self::current();
		return $title && $store && ! self::on_menu() ? esc_html( $title ) . ' &#8211; ' . esc_html( $store['name'] ) : $title;
	}

	/**
	 * Stores that don't cook (type="shop"): the receipt and its live tracker talk about the store.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @return string
	 */
	public static function shop_words( $translation, $text ) {
		static $words = null;
		if ( null === $words ) {
			$words = array(); // The __() calls below come back through this filter.
			$words = array(
				'The kitchen has it.'              => __( 'We are on it.', 'wowrestro' ),
				'The kitchen has your order.'      => __( 'The store has your order.', 'wowrestro' ),
				'The kitchen accepted your order.' => __( 'The store accepted your order.', 'wowrestro' ),
				'Waiting on the kitchen.'          => __( 'Waiting on the store.', 'wowrestro' ),
				'Your food is being cooked now.'   => __( 'We are preparing your order now.', 'wowrestro' ),
				'Cooked.'                          => __( 'Prepared.', 'wowrestro' ),
				'Contact the kitchen and quote order <b>#%s</b>. Changes are possible until the ticket is on the pass.' => __( 'Contact the store and quote order <b>#%s</b>. Changes are possible until we start on it.', 'wowrestro' ),
				'This order was cancelled. Contact the restaurant if that is unexpected.' => __( 'This order was cancelled. Contact the store if that is unexpected.', 'wowrestro' ),
			);
		}
		if ( ! isset( $words[ $text ] ) ) {
			return $translation;
		}
		$store = self::current();
		$store = $store ? $store : self::store( self::tracking_store() );
		return $store && 'shop' === $store['type'] ? $words[ $text ] : $translation;
	}

	/** On the receipt tracker's REST refresh, the store of the order it tracks. */
	private static function tracking_store() {
		static $page_id = null;
		if ( null === $page_id ) {
			$route   = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			$order   = preg_match( '#^/wowrestro/v1/tracking/(\d+)#', $route, $match ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $match[1] ) ) : null;
			$page_id = $order ? self::order_store( $order ) : 0;
		}
		return $page_id;
	}

	/** The store's colours on the order flow and its dialog; with header="store", on the whole page. */
	public static function styles() {
		$store = self::current();
		if ( ! $store ) {
			return;
		}
		wp_enqueue_style( 'wowrestro-stores', WOWRESTRO_URL . 'assets/css/wowrestro-stores.css', array( 'wowrestro-templates' ), WOWRESTRO_VERSION );
		$vars = '';
		foreach ( array( 'accent' => '--wr-red,--oe-ember,--oe-ember-soft', 'ink' => '--wr-ink,--oe-ink,--oe-fg', 'paper' => '--wr-paper,--oe-bg' ) as $colour => $names ) {
			foreach ( $store[ $colour ] ? explode( ',', $names ) : array() as $name ) {
				$vars .= $name . ':' . $store[ $colour ] . '!important;';
			}
		}
		$vars .= '' !== $store['radius'] ? '--wr-radius:' . absint( $store['radius'] ) . 'px!important;' : '';
		$chrome = 'store' === $store['header'];
		$css    = $vars ? ( $chrome ? 'body.wowrestro-store,' : '' ) . 'body.wowrestro-store .wowrestro-menu,body.wowrestro-store .oe-modalroot{' . $vars . '}' : '';
		if ( $chrome && $store['paper'] ) {
			$css .= 'html:has(body.wowrestro-store-chrome),body.wowrestro-store-chrome{background:' . $store['paper'] . '}';
		}
		if ( $css ) {
			wp_add_inline_style( 'wowrestro-stores', $css );
		}
	}

	/** header="store": the store switcher (when the page holds one) and the store header, above the page. */
	public static function top() {
		$store = self::current();
		if ( ! $store || 'store' !== $store['header'] ) {
			return;
		}
		$switcher = self::page_switcher_atts( $store['id'] );
		if ( null !== $switcher ) {
			echo self::switcher_html( $switcher, $store['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in switcher_html().
		}
		$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
		$logo  = wp_get_attachment_image_url( absint( get_theme_mod( 'custom_logo' ) ), 'thumbnail' );
		$logo  = $logo ? $logo : get_site_icon_url( 96 );
		?>
		<header class="oe-header wowrestro-store-bar"><div class="oe-shell oe-header__in">
			<a class="oe-brand" href="<?php echo esc_url( $store['url'] ); ?>"><span class="oe-brand__mark"<?php echo $logo ? ' style="background-image:url(' . esc_url( $logo ) . ')"' : ''; ?>></span><span class="oe-brand__text"><span class="oe-brand__name"><?php echo esc_html( $store['name'] ); ?></span><?php if ( $store['tagline'] ) : ?><span class="oe-brand__sub"><?php echo esc_html( $store['tagline'] ); ?></span><?php endif; ?></span></a>
			<nav class="oe-nav" aria-label="<?php esc_attr_e( 'Store', 'wowrestro' ); ?>"><a href="<?php echo esc_url( $store['url'] ); ?>"<?php echo self::on_menu() ? ' class="is-on" aria-current="page"' : ''; ?>><?php esc_html_e( 'Menu', 'wowrestro' ); ?></a><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php esc_html_e( 'My account', 'wowrestro' ); ?></a></nav>
			<a class="oe-cartpill" href="<?php echo esc_url( $count ? $store['url'] . 'checkout/' : $store['url'] ); ?>" aria-label="<?php echo esc_attr( $count ? sprintf( /* translators: %d: items in the order */ __( 'Checkout, %d items', 'wowrestro' ), $count ) : __( 'Your order is empty', 'wowrestro' ) ); ?>"><span class="oe-cartpill__label"><?php echo esc_html( $count ? wp_strip_all_tags( WC()->cart->get_total() ) : __( 'Your order', 'wowrestro' ) ); ?></span><span class="oe-cartpill__count"><?php echo absint( $count ); ?></span></a>
		</div></header>
		<?php
	}

	/** header="store": the store's footer, and the cart pill following the order flow. */
	public static function footer() {
		$store = self::current();
		if ( ! $store || 'store' !== $store['header'] ) {
			return;
		}
		$address = WowRestro_Storefront::store_address();
		?>
		<footer class="oe-footer wowrestro-store-bar"><div class="oe-shell oe-footer__in"><span class="oe-footer__name"><?php echo esc_html( $store['name'] ); ?></span><span><?php echo esc_html( ( $address ? $address . ' · ' : '' ) . __( 'Ordering by WowRestro', 'wowrestro' ) ); ?></span></div></footer>
		<script>
		document.addEventListener( 'wowrestro:cart', function ( event ) {
			var pill = document.querySelector( '.wowrestro-store-bar .oe-cartpill' );
			var d = event.detail;
			if ( ! pill ) return;
			pill.querySelector( '.oe-cartpill__count' ).textContent = d.count;
			pill.querySelector( '.oe-cartpill__label' ).textContent = d.count ? d.total : <?php echo wp_json_encode( __( 'Your order', 'wowrestro' ) ); ?>;
			pill.href = d.count ? <?php echo wp_json_encode( $store['url'] . 'checkout/' ); ?> : <?php echo wp_json_encode( $store['url'] ); ?>;
			pill.setAttribute( 'aria-label', d.count ? <?php echo wp_json_encode( __( 'Checkout', 'wowrestro' ) ); ?> + ', ' + d.count + ', ' + d.total : <?php echo wp_json_encode( __( 'Your order is empty', 'wowrestro' ) ); ?> );
		} );
		</script>
		<?php
	}

	/**
	 * [wowrestro_store_switcher label="" brand="" brand_url="" cta="" cta_url=""]: a bar linking the stores.
	 * On a store page with header="store" it sits above the store header instead of in the content.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function switcher_shortcode( $atts ) {
		$store = self::current();
		if ( $store && 'store' === $store['header'] ) {
			return '';
		}
		wp_enqueue_style( 'wowrestro-stores', WOWRESTRO_URL . 'assets/css/wowrestro-stores.css', array(), WOWRESTRO_VERSION );
		return self::switcher_html( is_array( $atts ) ? $atts : array(), $store ? $store['id'] : 0 );
	}

	/**
	 * The switcher shortcode's attributes on a store page, or null when it has none.
	 *
	 * @param int $page_id Store page.
	 * @return array|null
	 */
	private static function page_switcher_atts( $page_id ) {
		$content = (string) get_post_field( 'post_content', $page_id );
		if ( ! preg_match( '/' . get_shortcode_regex( array( 'wowrestro_store_switcher' ) ) . '/', $content, $match ) ) {
			return null;
		}
		$atts = shortcode_parse_atts( $match[3] );
		return is_array( $atts ) ? $atts : array();
	}

	/**
	 * @param array $atts    Shortcode attributes.
	 * @param int   $current Current store page.
	 * @return string
	 */
	private static function switcher_html( $atts, $current ) {
		$atts = shortcode_atts(
			array(
				'label'     => '',
				'brand'     => get_bloginfo( 'name' ),
				'brand_url' => '/',
				'cta'       => '',
				'cta_url'   => '',
			),
			$atts,
			'wowrestro_store_switcher'
		);
		$link = static function ( $url ) {
			return 0 === strpos( $url, '/' ) ? home_url( $url ) : $url;
		};
		$html = '<nav class="oe-switcher" aria-label="' . esc_attr__( 'Stores', 'wowrestro' ) . '"><div class="oe-shell oe-switcher__in">';
		$html .= '<a class="oe-switcher__home" href="' . esc_url( $link( $atts['brand_url'] ) ) . '">' . esc_html( $atts['brand'] ) . '</a>';
		$html .= $atts['label'] ? '<span class="oe-switcher__label">' . esc_html( $atts['label'] ) . '</span>' : '';
		$html .= '<div class="oe-switcher__list">';
		foreach ( array_keys( self::pages() ) as $page_id ) {
			$store = self::store( $page_id );
			if ( $store ) {
				$html .= '<a href="' . esc_url( $store['url'] ) . '"' . ( $page_id === $current ? ' class="is-on" aria-current="page"' : '' ) . '>' . esc_html( $store['label'] ) . '</a>';
			}
		}
		$html .= '</div>';
		$html .= $atts['cta'] && $atts['cta_url'] ? '<a class="oe-switcher__cta" href="' . esc_url( $link( $atts['cta_url'] ) ) . '">' . esc_html( $atts['cta'] ) . '</a>' : '';
		return $html . '</div></nav>';
	}
}
