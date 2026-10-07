<?php
/**
 * Native WowRestro administration dashboard and WooCommerce screen helpers.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Admin {
	const PAGE = 'wowrestro-dashboard';

	/** Register admin-only hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_pages' ), 40 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'render_shell_navigation' ) );
		add_action( 'admin_post_wowrestro_toggle_pause', array( __CLASS__, 'toggle_pause' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WOWRESTRO_FILE ), array( __CLASS__, 'plugin_links' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'product_columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column' ), 20, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'product_filter' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_product_query' ) );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'product_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_product_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'product_bulk_notice' ) );

		add_filter( 'manage_' . WowRestro_Modifiers::POST_TYPE . '_posts_columns', array( __CLASS__, 'modifier_columns' ) );
		add_action( 'manage_' . WowRestro_Modifiers::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'modifier_column' ), 10, 2 );
	}

	/**
	 * Build the WowRestro admin navigation around native WooCommerce editors.
	 *
	 * The Workspace app is the landing screen, so clicking WowRestro opens the
	 * approved design. The operational dashboard keeps its own slug and its own
	 * submenu entry, which also means every existing link and bookmark to
	 * page=wowrestro-dashboard still resolves.
	 */
	public static function menu() {
		$parent = WowRestro_App::PAGE;
		add_menu_page( __( 'WowRestro Workspace', 'wowrestro' ), __( 'WowRestro', 'wowrestro' ), 'wowrestro_view_orders', $parent, array( 'WowRestro_App', 'page' ), 'dashicons-store', 56 );
		add_submenu_page( $parent, __( 'WowRestro Workspace', 'wowrestro' ), __( 'Workspace', 'wowrestro' ), 'wowrestro_view_orders', $parent, array( 'WowRestro_App', 'page' ) );
		add_submenu_page( $parent, __( 'WowRestro Setup Wizard', 'wowrestro' ), __( 'Setup Wizard', 'wowrestro' ), WowRestro_Settings::CAPABILITY, WowRestro_Onboarding::PAGE, array( 'WowRestro_Onboarding', 'page' ) );

		// Keep historical bookmarks and advanced-management links functional
		// without restoring the duplicate submenu that replaced the workspace.
		add_submenu_page( null, __( 'WowRestro Dashboard', 'wowrestro' ), __( 'WowRestro Dashboard', 'wowrestro' ), 'wowrestro_view_orders', self::PAGE, array( __CLASS__, 'dashboard' ) );
		add_submenu_page( null, __( 'WowRestro Orders', 'wowrestro' ), __( 'WowRestro Orders', 'wowrestro' ), 'wowrestro_view_orders', 'wowrestro-orders', array( 'WowRestro_Order_Board', 'page' ) );
		add_submenu_page( null, __( 'WowRestro Setup', 'wowrestro' ), __( 'WowRestro Setup', 'wowrestro' ), WowRestro_Settings::CAPABILITY, 'wowrestro-setup', array( 'WowRestro_Settings', 'page' ) );
		add_submenu_page( null, __( 'WowRestro Diagnostics', 'wowrestro' ), __( 'WowRestro Diagnostics', 'wowrestro' ), WowRestro_Settings::CAPABILITY, 'wowrestro-diagnostics', array( __CLASS__, 'diagnostics' ) );
	}

	/** Load one small stylesheet only on related backend screens. */
	public static function assets() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$plugin_page  = false !== strpos( $screen->id, 'wowrestro' );
		$product_page = in_array( $screen->id, array( 'edit-product', 'product' ), true );
		$section      = self::current_section();
		if ( $section || $plugin_page || $product_page || WowRestro_Modifiers::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'wowrestro-admin', WOWRESTRO_URL . 'assets/css/admin.css', array(), WOWRESTRO_VERSION );
		}
		if ( 'edit-product' === $screen->id ) {
			wp_enqueue_style( 'wowrestro-product-list', WOWRESTRO_URL . 'assets/css/product-list.css', array( 'wowrestro-admin' ), WOWRESTRO_VERSION );
		}
		if ( 'wowrestro_page_' . WowRestro_Onboarding::PAGE === $screen->id ) {
			wp_enqueue_style( 'wowrestro-onboarding', WOWRESTRO_URL . 'assets/css/onboarding.css', array( 'wowrestro-admin' ), WOWRESTRO_VERSION );
		}
		if ( $section && 'onboarding' !== $section ) {
			wp_enqueue_style( 'wowrestro-dashboard-shell', WOWRESTRO_URL . 'assets/css/dashboard.css', array( 'wowrestro-admin' ), WOWRESTRO_VERSION );
		}
		// The redesign only restyles the two screens WowRestro renders itself. The
		// native product and category editors keep their WooCommerce styling.
		if ( 'dashboard' === $section ) {
			wp_enqueue_style( 'wowrestro-icons', WOWRESTRO_URL . 'assets/css/wowrestro-icons.css', array(), WOWRESTRO_VERSION );
			wp_enqueue_style( 'wowrestro-redesign', WOWRESTRO_URL . 'assets/css/wowrestro-redesign.css', array( 'wowrestro-admin', 'wowrestro-dashboard-shell', 'wowrestro-icons' ), WOWRESTRO_VERSION );
			wp_enqueue_script( 'wowrestro-shell', WOWRESTRO_URL . 'assets/js/wowrestro-shell.js', array(), WOWRESTRO_VERSION, true );
		}
	}

	/** Add the appropriate full-canvas class to WowRestro-managed screens. */
	public static function body_class( $classes ) {
		$section = self::current_section();
		if ( 'dashboard' === $section ) {
			return $classes . ' wowrestro-dashboard-page';
		}
		if ( $section && 'onboarding' !== $section ) {
			return $classes . ' wowrestro-shell-page';
		}
		return $classes;
	}

	/** Render the persistent sidebar around WowRestro pages and native WooCommerce editors. */
	public static function render_shell_navigation() {
		$section = self::current_section();
		if ( ! $section || in_array( $section, array( 'dashboard', 'onboarding' ), true ) ) {
			return;
		}
		self::dashboard_navigation( true );
	}

	/** Add direct entry points from the Plugins screen. */
	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( WowRestro_App::url() ) . '">' . esc_html__( 'Dashboard', 'wowrestro' ) . '</a>' );
		$links[] = '<a href="' . esc_url( WowRestro_App::url( '/setup-wizard' ) ) . '">' . esc_html__( 'Setup Wizard', 'wowrestro' ) . '</a>';
		return $links;
	}

	/** Keep historical WowRestro URLs inside the persistent workspace. */
	public static function redirect_legacy_pages() {
		if ( wp_doing_ajax() || 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route adapter.
		$map  = array(
			self::PAGE              => '/',
			'wowrestro-orders'      => '/live-orders',
			'wowrestro-setup'       => '/settings?tab=general',
			'wowrestro-diagnostics' => '/diagnostics',
		);
		// A plain wizard URL opens the workspace checklist once setup is no longer pending; a URL with a step
		// (the first visit after activation, the next steps, the finish screen) stays on the guided setup.
		if ( WowRestro_Onboarding::PAGE === $page && 'yes' !== get_option( 'wowrestro_onboarding_pending', 'no' ) && ! isset( $_GET['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route adapter.
			$map[ $page ] = '/setup-wizard';
		}
		if ( isset( $map[ $page ] ) ) {
			wp_safe_redirect( WowRestro_App::url( $map[ $page ] ) );
			exit;
		}
	}

	/**
	 * Render the shared redesign shell: brand, live state and clock.
	 *
	 * Navigation lives in the persistent left sidebar (dashboard_navigation), so
	 * this row deliberately carries no tabs - two navs would compete.
	 *
	 * @param string $current Active section key, kept for future per-section chrome.
	 * @param bool   $paused  Whether ordering is paused.
	 */
	public static function shell_topbar( $current, $paused ) {
		unset( $current );
		?>
		<div class="wra-topbar">
			<div class="wra-brandpill">
				<span class="wra-brandpill__mark"><i class="ph-fill ph-fork-knife" aria-hidden="true"></i></span>
				<strong class="wra-brandpill__name"><?php esc_html_e( 'WowRestro', 'wowrestro' ); ?></strong>
				<span class="wra-brandpill__rule"></span>
				<span class="wra-brandpill__store"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
			</div>
			<div class="wra-topbar__end">
				<span class="wra-live<?php echo $paused ? ' is-paused' : ''; ?>" data-wowrestro-live>
					<span class="wra-live__dot"></span>
					<span data-wowrestro-live-label><?php echo esc_html( $paused ? __( 'Ordering paused', 'wowrestro' ) : __( 'Ordering open', 'wowrestro' ) ); ?></span>
				</span>
				<span class="wra-clock" data-wowrestro-clock></span>
			</div>
		</div>
		<?php
	}

	/** Render the manager/staff overview without replacing WooCommerce data screens. */
	public static function dashboard() {
		if ( ! current_user_can( 'wowrestro_view_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to view WowRestro.', 'wowrestro' ) );
		}
		$data      = self::dashboard_data();
		$settings  = WowRestro_Fulfillment::settings();
		$paused    = 'yes' === $settings['orders_paused'];
		$checklist = self::checklist( $data );
		$ready     = count(
			array_filter(
				$checklist,
				function ( $check ) {
					return ! empty( $check['ready'] );
				}
			)
		);
		$percent   = $checklist ? (int) round( $ready / count( $checklist ) * 100 ) : 0;
		?>
		<div class="wrap wowrestro-dashboard-shell">
			<?php self::dashboard_navigation(); ?>
			<main class="wowrestro-dashboard-shell__content wra-root is-overview">
			<div class="wra-main">

			<div class="wra-pagehead">
				<h1 class="wra-pagehead__title"><?php esc_html_e( 'Dashboard', 'wowrestro' ); ?></h1>
				<div class="wra-pagehead__actions">
					<?php self::shell_topbar( 'dashboard', $paused ); ?>
					<?php if ( current_user_can( 'wowrestro_manage_operations' ) ) : ?>
					<a class="wra-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wowrestro_toggle_pause' ), 'wowrestro_toggle_pause' ) ); ?>"><i class="<?php echo $paused ? 'ph-fill ph-play-circle' : 'ph-fill ph-pause-circle'; ?>" aria-hidden="true"></i><?php echo esc_html( $paused ? __( 'Resume ordering', 'wowrestro' ) : __( 'Pause ordering', 'wowrestro' ) ); ?></a>
					<?php endif; ?>
					<a class="wra-btn wra-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wowrestro-orders' ) ); ?>"><i class="ph-fill ph-lightning" aria-hidden="true"></i><?php esc_html_e( 'Open live orders', 'wowrestro' ); ?></a>
				</div>
			</div>

			<div class="wra-overview">

			<div class="wra-panel wra-rise">
				<div class="wra-panel__head">
					<div>
						<h2 class="wra-card__h"><?php esc_html_e( 'Dashboard overview', 'wowrestro' ); ?></h2>
						<p class="wra-card__sub"><?php esc_html_e( 'Run the restaurant from WooCommerce products and orders, with WowRestro controlling fulfillment and kitchen workflow.', 'wowrestro' ); ?></p>
					</div>
				</div>
				<div class="wra-metrics">
					<?php
					self::metric(
						array(
							'title'    => __( 'Revenue today', 'wowrestro' ),
							'value'    => html_entity_decode( wp_strip_all_tags( wc_price( $data['today_revenue'] ) ), ENT_QUOTES, 'UTF-8' ),
							'tooltip'  => __( 'Paid restaurant orders created today, minus refunds.', 'wowrestro' ),
							'icon'     => 'ph-fill ph-currency-dollar',
							'accent'   => '#2b4bff',
							'change'   => self::change( $data['today_revenue'], $data['yesterday_revenue'] ),
							'footnote' => __( 'since yesterday', 'wowrestro' ),
						)
					);
					self::metric(
						array(
							'title'    => __( 'Orders today', 'wowrestro' ),
							'value'    => $data['today_orders'],
							'tooltip'  => __( 'Restaurant orders created today, paid or not.', 'wowrestro' ),
							'icon'     => 'ph-fill ph-receipt',
							'accent'   => '#6fa8ff',
							'change'   => self::change( $data['today_orders'], $data['yesterday_orders'] ),
							'footnote' => __( 'since yesterday', 'wowrestro' ),
						)
					);
					self::metric(
						array(
							'title'    => __( 'Active orders', 'wowrestro' ),
							'value'    => count( $data['active_orders'] ),
							'tooltip'  => __( 'Orders waiting or in progress in the kitchen queue.', 'wowrestro' ),
							'icon'     => 'ph-fill ph-fire',
							'accent'   => '#00b5d8',
							'footnote' => sprintf(
								/* translators: %d: number of orders due within thirty minutes. */
								_n( '%d due in 30 minutes', '%d due in 30 minutes', $data['due_soon'], 'wowrestro' ),
								(int) $data['due_soon']
							),
						)
					);
					self::metric(
						array(
							'title'    => __( 'Unpaid active', 'wowrestro' ),
							'value'    => $data['unpaid'],
							'tooltip'  => __( 'Active orders whose payment is still outstanding in WooCommerce.', 'wowrestro' ),
							'icon'     => 'ph-fill ph-warning-circle',
							'accent'   => $data['unpaid'] ? '#e7000b' : '#7b3bff',
							'footnote' => __( 'payment remains in WooCommerce', 'wowrestro' ),
						)
					);
					self::metric(
						array(
							'title'    => __( 'Menu items', 'wowrestro' ),
							'value'    => $data['menu_items'],
							'tooltip'  => __( 'WooCommerce products marked as restaurant menu items.', 'wowrestro' ),
							'icon'     => 'ph-fill ph-fork-knife',
							'accent'   => '#00a63e',
							'footnote' => sprintf(
								/* translators: %d: number of modifier groups. */
								_n( '%d add-on group', '%d add-on groups', $data['modifier_groups'], 'wowrestro' ),
								(int) $data['modifier_groups']
							),
						)
					);
					?>
				</div>
			</div>

			<div class="wra-dash">
				<section class="wra-card wra-card--clip wra-rise wra-rise--2">
					<div class="wra-queue__head">
						<div>
							<h2 class="wra-card__h"><?php esc_html_e( 'Kitchen queue', 'wowrestro' ); ?></h2>
							<p class="wra-card__sub"><?php esc_html_e( 'Sorted by promised time', 'wowrestro' ); ?></p>
						</div>
						<a class="wra-btn wra-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=wowrestro-orders' ) ); ?>"><?php esc_html_e( 'Open board', 'wowrestro' ); ?><i class="ph-bold ph-arrow-right" aria-hidden="true"></i></a>
					</div>
					<?php self::orders_table( $data['active_orders'] ? array_slice( $data['active_orders'], 0, 8 ) : $data['recent_orders'] ); ?>
				</section>

				<aside class="wra-card wra-card--pad wra-rise wra-rise--3">
					<div class="wra-check__head">
						<div>
							<h2 class="wra-card__h"><?php esc_html_e( 'Setup checklist', 'wowrestro' ); ?></h2>
							<p class="wra-card__sub">
							<?php
							printf(
								/* translators: 1: number of ready checks, 2: total number of checks. */
								esc_html__( '%1$d of %2$d checks are ready', 'wowrestro' ),
								(int) $ready,
								count( $checklist )
							);
							?>
							</p>
						</div>
						<span class="wra-ring" style="background:conic-gradient(#2f6bff <?php echo (int) $percent; ?>%, #e8eefb 0)"><span><?php echo (int) $percent; ?>%</span></span>
					</div>
					<ul class="wra-check__list">
					<?php foreach ( $checklist as $check ) : ?>
						<li class="wra-check__item">
							<span class="wra-check__chip<?php echo $check['ready'] ? '' : ' is-todo'; ?>"><i class="<?php echo esc_attr( $check['ready'] ? 'ph-fill ph-check-circle' : 'ph-fill ph-warning' ); ?>" aria-hidden="true"></i></span>
							<span class="wra-check__id">
								<strong class="wra-check__label"><?php echo esc_html( $check['label'] ); ?></strong>
								<small class="wra-check__desc"><?php echo esc_html( $check['description'] ); ?></small>
							</span>
						</li>
					<?php endforeach; ?>
					</ul>
					<?php if ( current_user_can( WowRestro_Settings::CAPABILITY ) ) : ?>
					<div class="wra-check__foot">
						<a class="wra-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . WowRestro_Onboarding::PAGE . '&step=1' ) ); ?>"><?php esc_html_e( 'Setup wizard', 'wowrestro' ); ?></a>
						<a class="wra-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=wowrestro-diagnostics' ) ); ?>"><?php esc_html_e( 'Diagnostics', 'wowrestro' ); ?></a>
					</div>
					<?php endif; ?>
				</aside>
			</div>

			<?php if ( current_user_can( 'wowrestro_manage_menu' ) ) : ?>
			<section class="wra-card wra-card--pad wra-rise wra-rise--4">
				<h2 class="wra-card__h"><?php esc_html_e( 'Menu management', 'wowrestro' ); ?></h2>
				<p class="wra-card__sub" style="margin-bottom:16px"><?php esc_html_e( 'Jump straight into the WooCommerce editors WowRestro extends.', 'wowrestro' ); ?></p>
				<div class="wra-tiles">
					<?php
					self::tile( admin_url( 'post-new.php?post_type=product&wowrestro_new=1' ), 'ph-bold ph-plus', __( 'Add menu item', 'wowrestro' ) );
					self::tile( admin_url( 'edit.php?post_type=product&wowrestro_menu_item=yes' ), 'ph-fill ph-package', __( 'Edit menu items', 'wowrestro' ) );
					self::tile( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ), 'ph-fill ph-folders', __( 'Categories', 'wowrestro' ) );
					self::tile( admin_url( 'edit.php?post_type=product&page=product_attributes' ), 'ph-fill ph-leaf', __( 'Dietary attributes', 'wowrestro' ) );
					self::tile( admin_url( 'admin.php?page=wc-settings&tab=shipping' ), 'ph-fill ph-map-pin', __( 'Shipping zones', 'wowrestro' ) );
					?>
				</div>
			</section>
			<?php endif; ?>

			</div>
			</div>
			</main>
		</div>
		<?php
	}

	/**
	 * Render one overview metric card.
	 *
	 * Accepts title, value, tooltip (shown on hovering the title), icon (a
	 * Phosphor class present in the bundled subset), accent (hex, fills the icon
	 * circle), change (percentage, or null for no chip) and footnote.
	 *
	 * @param array $args Card fields.
	 */
	private static function metric( $args ) {
		$args   = array_merge(
			array(
				'title'    => '',
				'value'    => '',
				'tooltip'  => '',
				'icon'     => 'ph ph-chart-bar',
				'accent'   => '#2b4bff',
				'change'   => null,
				'footnote' => '',
			),
			$args
		);
		$accent = preg_match( '/^#[0-9a-f]{6}$/i', (string) $args['accent'] ) ? $args['accent'] : '#2b4bff';
		$change = $args['change'];
		$state  = null === $change ? '' : ( $change > 0 ? 'is-up' : ( $change < 0 ? 'is-down' : 'is-flat' ) );
		?>
		<article class="wra-metric">
			<span class="wra-metric__icon" style="background:<?php echo esc_attr( $accent ); ?>"><i class="<?php echo esc_attr( $args['icon'] ); ?>" aria-hidden="true"></i></span>
			<div class="wra-metric__top">
				<h3 class="wra-metric__title<?php echo $args['tooltip'] ? ' has-tip' : ''; ?>"<?php echo $args['tooltip'] ? ' title="' . esc_attr( $args['tooltip'] ) . '"' : ''; ?>><?php echo esc_html( $args['title'] ); ?></h3>
			</div>
			<strong class="wra-metric__value"><?php echo esc_html( $args['value'] ); ?></strong>
			<div class="wra-metric__foot">
				<?php if ( null !== $change ) : ?>
				<span class="wra-metric__chip <?php echo esc_attr( $state ); ?>">
					<?php
					printf(
						/* translators: %s: signed percentage change, for example "+12.4". */
						esc_html__( '%s%%', 'wowrestro' ),
						esc_html( ( $change > 0 ? '+' : '' ) . number_format_i18n( $change, 1 ) )
					);
					?>
				</span>
				<?php endif; ?>
				<?php if ( $args['footnote'] ) : ?>
				<span><?php echo esc_html( $args['footnote'] ); ?></span>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Render one quick-action tile linking into a native WooCommerce editor.
	 *
	 * @param string $url   Destination admin URL.
	 * @param string $icon  Dashicons class.
	 * @param string $label Visible tile label.
	 */
	private static function tile( $url, $icon, $label ) {
		?>
		<a class="wra-tile" href="<?php echo esc_url( $url ); ?>"><span class="wra-tile__mark"><i class="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i></span><span class="wra-tile__label"><?php echo esc_html( $label ); ?></span></a>
		<?php
	}

	/** Return dashboard metrics using WooCommerce CRUD for HPOS compatibility. */
	public static function dashboard_data() {
		WowRestro_Order_Statuses::sync_active_orders();
		$restaurant = array(
			'key'   => '_wowrestro_order',
			'value' => 'yes',
		);
		$active     = WowRestro_Order_Statuses::query_orders(
			array(
				'limit'      => -1,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array(
					'relation' => 'AND',
					$restaurant,
					array(
						'key'     => WowRestro_Order_Statuses::META_STATUS,
						'value'   => array( 'new', 'accepted', 'preparing', 'ready', 'out_for_delivery' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$start      = current_datetime()->setTime( 0, 0, 0 )->getTimestamp();
		$today      = WowRestro_Order_Statuses::query_orders(
			array(
				'limit'        => -1,
				'date_created' => '>=' . $start,
				'meta_query'   => array( $restaurant ),
			)
		);
		// Yesterday's same window backs the change chips on the overview cards.
		// Without it the design's "since last period" figure would be invented.
		$prev_start = $start - DAY_IN_SECONDS;
		$yesterday  = WowRestro_Order_Statuses::query_orders(
			array(
				'limit'        => -1,
				'date_created' => $prev_start . '...' . ( $start - 1 ),
				'meta_query'   => array( $restaurant ),
			)
		);
		$recent     = WowRestro_Order_Statuses::query_orders(
			array(
				'limit'      => 8,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( $restaurant ),
			)
		);
		$active     = array_values(
			array_filter(
				(array) $active,
				static function ( $order ) {
					return $order instanceof WC_Order;
				}
			)
		);
		$today      = array_values(
			array_filter(
				(array) $today,
				static function ( $order ) {
					return $order instanceof WC_Order;
				}
			)
		);
		$recent     = array_values(
			array_filter(
				(array) $recent,
				static function ( $order ) {
					return $order instanceof WC_Order;
				}
			)
		);
		$yesterday  = array_values(
			array_filter(
				(array) $yesterday,
				static function ( $order ) {
					return $order instanceof WC_Order;
				}
			)
		);
		usort(
			$active,
			static function ( $left, $right ) {
				$left_time  = self::promise_timestamp( $left );
				$right_time = self::promise_timestamp( $right );
				$left_time  = $left_time ? $left_time : PHP_INT_MAX;
				$right_time = $right_time ? $right_time : PHP_INT_MAX;
				return $left_time === $right_time ? $left->get_id() <=> $right->get_id() : $left_time <=> $right_time;
			}
		);
		$now    = time();
		$soon   = $now + 30 * MINUTE_IN_SECONDS;
		$due    = 0;
		$unpaid = 0;
		foreach ( $active as $order ) {
			$promise = self::promise_timestamp( $order );
			$due    += $promise && $promise <= $soon ? 1 : 0;
			$unpaid += $order->is_paid() ? 0 : 1;
		}
		$revenue      = self::paid_total( $today );
		$prev_revenue = self::paid_total( $yesterday );
		$menu         = wc_get_products(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'status'     => array( 'publish', 'draft', 'private' ),
				'paginate'   => true,
				'meta_key'   => WowRestro_Products::MENU_ITEM_META,
				'meta_value' => 'yes',
			)
		);
		$counts       = wp_count_posts( WowRestro_Modifiers::POST_TYPE );
		return array(
			'today_orders'      => count( $today ),
			'today_revenue'     => $revenue,
			'yesterday_orders'  => count( $yesterday ),
			'yesterday_revenue' => $prev_revenue,
			'active_orders'     => $active,
			'recent_orders'     => $recent,
			'due_soon'          => $due,
			'unpaid'            => $unpaid,
			'menu_items'        => is_object( $menu ) ? absint( $menu->total ) : count( (array) $menu ),
			'modifier_groups'   => $counts ? absint( $counts->publish ) + absint( $counts->draft ) : 0,
		);
	}

	/**
	 * Sum the paid, non-refunded total of a set of orders.
	 *
	 * @param WC_Order[] $orders Orders to total.
	 * @return float
	 */
	private static function paid_total( $orders ) {
		$total = 0.0;
		foreach ( $orders as $order ) {
			if ( $order->is_paid() ) {
				$total += max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
			}
		}
		return $total;
	}

	/**
	 * Percentage change between two periods, or null when there is no baseline.
	 *
	 * A zero baseline has no defined percentage change, so the card shows no
	 * chip rather than a fabricated "+100%".
	 *
	 * @param float $current  Current period value.
	 * @param float $previous Previous period value.
	 * @return float|null
	 */
	private static function change( $current, $previous ) {
		if ( (float) $previous <= 0.0 ) {
			return null;
		}
		return ( (float) $current - (float) $previous ) / (float) $previous * 100;
	}

	/** Return setup state used by the dashboard and tests. */
	public static function checklist( $data = array() ) {
		$settings = WowRestro_Fulfillment::settings();
		$rates    = WowRestro_Shipping::configured_rates();
		return array(
			array(
				'ready'       => ! empty( $data['menu_items'] ),
				'label'       => __( 'Restaurant menu', 'wowrestro' ),
				'description' => ! empty( $data['menu_items'] ) ? sprintf( _n( '%d item is configured.', '%d items are configured.', $data['menu_items'], 'wowrestro' ), $data['menu_items'] ) : __( 'Add or mark at least one WooCommerce product.', 'wowrestro' ),
			),
			array(
				'ready'       => 'yes' === $settings['pickup_enabled'] || 'yes' === $settings['delivery_enabled'],
				'label'       => __( 'Fulfillment services', 'wowrestro' ),
				'description' => __( 'Pickup or delivery must be enabled.', 'wowrestro' ),
			),
			array(
				'ready'       => ! empty( $rates ),
				'label'       => __( 'WooCommerce shipping', 'wowrestro' ),
				'description' => ! empty( $rates ) ? sprintf( _n( '%d enabled rate found.', '%d enabled rates found.', count( $rates ), 'wowrestro' ), count( $rates ) ) : __( 'Add an enabled shipping-zone rate.', 'wowrestro' ),
			),
			array(
				'ready'       => WowRestro_Slot_Reservations::table_ready(),
				'label'       => __( 'Capacity ledger', 'wowrestro' ),
				'description' => __( 'Atomic reservations require the InnoDB ledger.', 'wowrestro' ),
			),
			array(
				'ready'       => WowRestro_Legacy_Migration::DATA_VERSION === (string) get_option( 'wowrestro_data_version', '' ),
				'label'       => __( 'Data migration', 'wowrestro' ),
				'description' => __( 'Legacy product and order backfills must finish.', 'wowrestro' ),
			),
		);
	}

	/** Toggle the global pause with a normal WordPress nonce-protected action. */
	public static function toggle_pause() {
		if ( ! current_user_can( 'wowrestro_manage_operations' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ordering availability.', 'wowrestro' ) );
		}
		check_admin_referer( 'wowrestro_toggle_pause' );
		$settings                  = get_option( 'wowrestro_settings', array() );
		$settings                  = is_array( $settings ) ? $settings : array();
		$settings['orders_paused'] = 'yes' === ( $settings['orders_paused'] ?? 'no' ) ? 'no' : 'yes';
		update_option( 'wowrestro_settings', $settings, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&pause=' . $settings['orders_paused'] ) );
		exit;
	}

	/** Render Site Health-backed checks inside WowRestro. */
	public static function diagnostics() {
		if ( ! current_user_can( WowRestro_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view WowRestro diagnostics.', 'wowrestro' ) );
		}
		$tests = array( WowRestro_Diagnostics::checkout_test(), WowRestro_Diagnostics::configuration_test(), WowRestro_Diagnostics::ledger_test() );
		$info  = WowRestro_Diagnostics::debug_information( array() );
		$info  = $info['wowrestro']['fields'];
		?>
		<div class="wrap wowrestro-admin"><div class="wowrestro-admin__header"><div><p class="wowrestro-admin__eyebrow"><?php esc_html_e( 'System status', 'wowrestro' ); ?></p><h1><?php esc_html_e( 'WowRestro Diagnostics', 'wowrestro' ); ?></h1><p><?php esc_html_e( 'Resolve critical checks before accepting live restaurant orders.', 'wowrestro' ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Open WordPress Site Health', 'wowrestro' ); ?></a></div>
		<div class="wowrestro-admin__columns"><section class="wowrestro-admin__panel"><h2><?php esc_html_e( 'Checks', 'wowrestro' ); ?></h2><ul class="wowrestro-admin__checks">
		<?php
		foreach ( $tests as $test ) :
			?>
			<li class="is-<?php echo esc_attr( $test['status'] ); ?>"><span class="dashicons <?php echo 'good' === $test['status'] ? 'ph-fill ph-check-circle' : 'ph-fill ph-warning'; ?>"></span><div><strong><?php echo esc_html( $test['label'] ); ?></strong><p><?php echo esc_html( wp_strip_all_tags( $test['description'] ) ); ?></p><?php echo wp_kses_post( $test['actions'] ); ?></div></li><?php endforeach; ?>
		</ul></section><aside class="wowrestro-admin__panel"><h2><?php esc_html_e( 'Environment', 'wowrestro' ); ?></h2><table class="widefat striped"><tbody>
		<?php
		foreach ( $info as $field ) :
			?>
			<tr><th><?php echo esc_html( $field['label'] ); ?></th><td><?php echo esc_html( $field['value'] ); ?></td></tr><?php endforeach; ?></tbody></table></aside></div></div>
		<?php
	}

	/** Add restaurant state to the native WooCommerce product table. */
	public static function product_columns( $columns ) {
		$output = array();
		foreach ( $columns as $key => $label ) {
			$output[ $key ] = $label;
			if ( 'name' === $key ) {
				$output['wowrestro_menu'] = __( 'Restaurant', 'wowrestro' );
			}
		}
		return $output;
	}

	public static function product_column( $column, $post_id ) {
		if ( 'wowrestro_menu' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! WowRestro_Products::is_menu_item( $product ) ) {
			echo '<span class="wowrestro-badge is-muted">' . esc_html__( 'No', 'wowrestro' ) . '</span>';
			return;
		}
		$count = count( WowRestro_Modifiers::assigned_group_ids( $post_id ) );
		echo '<span class="wowrestro-badge is-menu">' . esc_html__( 'Menu item', 'wowrestro' ) . '</span><small>' . esc_html( sprintf( _n( '%d add-on group', '%d add-on groups', $count, 'wowrestro' ), $count ) ) . '</small>';
	}

	/** Add a restaurant-only selector above the native product table. */
	public static function product_filter() {
		global $typenow;
		if ( 'product' !== $typenow ) {
			return;
		}
		$value = isset( $_GET['wowrestro_menu_item'] ) && is_scalar( $_GET['wowrestro_menu_item'] ) ? sanitize_key( wp_unslash( (string) $_GET['wowrestro_menu_item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		?>
		<label class="screen-reader-text" for="filter-by-wowrestro-menu"><?php esc_html_e( 'Filter by restaurant menu status', 'wowrestro' ); ?></label><select id="filter-by-wowrestro-menu" name="wowrestro_menu_item"><option value=""><?php esc_html_e( 'All products', 'wowrestro' ); ?></option><option value="yes" <?php selected( 'yes', $value ); ?>><?php esc_html_e( 'Restaurant menu items', 'wowrestro' ); ?></option><option value="no" <?php selected( 'no', $value ); ?>><?php esc_html_e( 'Not on restaurant menu', 'wowrestro' ); ?></option></select>
		<?php
	}

	/** Apply the restaurant selector without altering frontend product queries. */
	public static function filter_product_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		$value = isset( $_GET['wowrestro_menu_item'] ) && is_scalar( $_GET['wowrestro_menu_item'] ) ? sanitize_key( wp_unslash( (string) $_GET['wowrestro_menu_item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( 'yes' === $value ) {
			self::add_product_meta_clause(
				$query,
				array(
					'key'   => WowRestro_Products::MENU_ITEM_META,
					'value' => 'yes',
				)
			);
		} elseif ( 'no' === $value ) {
			self::add_product_meta_clause(
				$query,
				array(
					'relation' => 'OR',
					array(
						'key'     => WowRestro_Products::MENU_ITEM_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => WowRestro_Products::MENU_ITEM_META,
						'value'   => 'yes',
						'compare' => '!=',
					),
				)
			);
		}
	}

	public static function product_bulk_actions( $actions ) {
		if ( current_user_can( 'wowrestro_manage_menu' ) ) {
			$actions['wowrestro_add_menu']    = __( 'Add to restaurant menu', 'wowrestro' );
			$actions['wowrestro_remove_menu'] = __( 'Remove from restaurant menu', 'wowrestro' );
		}
		return $actions;
	}

	/** Persist menu membership through WooCommerce product CRUD. */
	public static function handle_product_bulk_action( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'wowrestro_add_menu', 'wowrestro_remove_menu' ), true ) || ! current_user_can( 'wowrestro_manage_menu' ) ) {
			return $redirect;
		}
		$updated = 0;
		foreach ( array_slice( array_unique( array_map( 'absint', (array) $post_ids ) ), 0, 500 ) as $post_id ) {
			$product = wc_get_product( $post_id );
			if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) {
				continue;
			}
			$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'wowrestro_add_menu' === $action ? 'yes' : 'no' );
			$product->save_meta_data();
			++$updated;
		}
		return add_query_arg(
			array(
				'wowrestro_bulk_updated' => $updated,
				'wowrestro_bulk_action'  => $action,
			),
			$redirect
		);
	}

	public static function product_bulk_notice() {
		$count  = isset( $_GET['wowrestro_bulk_updated'] ) && is_scalar( $_GET['wowrestro_bulk_updated'] ) ? absint( $_GET['wowrestro_bulk_updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core verified the bulk-action nonce before redirecting.
		$action = isset( $_GET['wowrestro_bulk_action'] ) && is_scalar( $_GET['wowrestro_bulk_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['wowrestro_bulk_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $count || ! in_array( $action, array( 'wowrestro_add_menu', 'wowrestro_remove_menu' ), true ) ) {
			return;
		}
		$message = 'wowrestro_add_menu' === $action ? _n( '%d product added to the restaurant menu.', '%d products added to the restaurant menu.', $count, 'wowrestro' ) : _n( '%d product removed from the restaurant menu.', '%d products removed from the restaurant menu.', $count, 'wowrestro' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( $message, $count ) ) . '</p></div>';
	}

	public static function modifier_columns( $columns ) {
		$output = array();
		foreach ( $columns as $key => $label ) {
			$output[ $key ] = $label;
			if ( 'title' === $key ) {
				$output['wowrestro_type']    = __( 'Selection', 'wowrestro' );
				$output['wowrestro_rules']   = __( 'Rules', 'wowrestro' );
				$output['wowrestro_options'] = __( 'Options', 'wowrestro' );
			}
		}
		return $output;
	}

	public static function modifier_column( $column, $post_id ) {
		if ( 'wowrestro_type' === $column ) {
			echo esc_html( 'multiple' === get_post_meta( $post_id, WowRestro_Modifiers::TYPE_META, true ) ? __( 'Choose multiple', 'wowrestro' ) : __( 'Choose one', 'wowrestro' ) );
		} elseif ( 'wowrestro_rules' === $column ) {
			echo esc_html( sprintf( __( 'Min %1$d · Max %2$d', 'wowrestro' ), absint( get_post_meta( $post_id, WowRestro_Modifiers::MIN_META, true ) ), absint( get_post_meta( $post_id, WowRestro_Modifiers::MAX_META, true ) ) ) );
		} elseif ( 'wowrestro_options' === $column ) {
			echo esc_html( count( WowRestro_Modifiers::sanitize_options( get_post_meta( $post_id, WowRestro_Modifiers::OPTIONS_META, true ) ) ) );
		}
	}

	/** Render a focused restaurant navigation shell without replacing native editors. */
	private static function dashboard_navigation( $fixed = false ) {
		$current = self::current_section();
		?>
		<aside class="wowrestro-dashboard-shell__sidebar<?php echo $fixed ? ' wowrestro-dashboard-shell__sidebar--fixed' : ''; ?>">
			<div class="wowrestro-dashboard-shell__brand"><i class="ph ph-storefront" aria-hidden="true"></i><strong><?php esc_html_e( 'WowRestro', 'wowrestro' ); ?></strong></div>
			<nav aria-label="<?php esc_attr_e( 'WowRestro dashboard', 'wowrestro' ); ?>">
				<a class="<?php echo esc_attr( self::navigation_class( 'dashboard', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url() ); ?>"><i class="ph ph-gauge"></i><?php esc_html_e( 'Dashboard', 'wowrestro' ); ?></a>
				<p><?php esc_html_e( 'Food ordering', 'wowrestro' ); ?></p>
				<a class="<?php echo esc_attr( self::navigation_class( 'orders', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url( '/live-orders' ) ); ?>"><i class="ph ph-receipt"></i><?php esc_html_e( 'Live orders', 'wowrestro' ); ?></a>
				<?php if ( current_user_can( 'wowrestro_manage_menu' ) ) : ?>
				<a class="<?php echo esc_attr( self::navigation_class( 'menu_items', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url( '/food-menu/items' ) ); ?>"><i class="ph ph-fork-knife"></i><?php esc_html_e( 'Menu items', 'wowrestro' ); ?></a>
				<a class="<?php echo esc_attr( self::navigation_class( 'categories', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url( '/food-menu/categories' ) ); ?>"><i class="ph ph-folders"></i><?php esc_html_e( 'Categories', 'wowrestro' ); ?></a>
				<?php endif; ?>
				<?php if ( current_user_can( WowRestro_Settings::CAPABILITY ) ) : ?>
				<p><?php esc_html_e( 'Configuration', 'wowrestro' ); ?></p>
				<a class="<?php echo esc_attr( self::navigation_class( 'onboarding', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url( '/setup-wizard' ) ); ?>"><i class="ph-fill ph-magic-wand"></i><?php esc_html_e( 'Setup wizard', 'wowrestro' ); ?></a>
				<a class="<?php echo esc_attr( self::navigation_class( 'settings', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url( '/settings?tab=general' ) ); ?>"><i class="ph ph-gear-six"></i><?php esc_html_e( 'Settings', 'wowrestro' ); ?></a>
				<a class="<?php echo esc_attr( self::navigation_class( 'diagnostics', $current ) ); ?>" href="<?php echo esc_url( WowRestro_App::url( '/diagnostics' ) ); ?>"><i class="ph-fill ph-stethoscope"></i><?php esc_html_e( 'Diagnostics', 'wowrestro' ); ?></a>
				<?php endif; ?>
			</nav>
			<a class="wowrestro-dashboard-shell__return" href="<?php echo esc_url( admin_url() ); ?>"><span class="dashicons dashicons-wordpress"></span><?php esc_html_e( 'Return to WordPress', 'wowrestro' ); ?></a>
		</aside>
		<?php
	}

	/** Identify which WowRestro navigation section owns the current admin screen. */
	private static function current_section() {
		$page  = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$pages = array(
			self::PAGE                 => 'dashboard',
			'wowrestro-orders'         => 'orders',
			WowRestro_Onboarding::PAGE => 'onboarding',
			'wowrestro-setup'          => 'settings',
			'wowrestro-diagnostics'    => 'diagnostics',
		);
		if ( isset( $pages[ $page ] ) ) {
			return $pages[ $page ];
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return '';
		}
		if ( WowRestro_Modifiers::POST_TYPE === $screen->post_type ) {
			return 'modifiers';
		}
		if ( 'product_cat' === $screen->taxonomy ) {
			return 'categories';
		}
		if ( 'product' !== $screen->post_type ) {
			return '';
		}

		$menu_filter = isset( $_GET['wowrestro_menu_item'] ) && is_scalar( $_GET['wowrestro_menu_item'] ) ? sanitize_key( wp_unslash( (string) $_GET['wowrestro_menu_item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$new_value   = isset( $_GET['wowrestro_new'] ) && is_scalar( $_GET['wowrestro_new'] ) ? sanitize_key( wp_unslash( (string) $_GET['wowrestro_new'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		$is_new      = '1' === $new_value;
		if ( 'yes' === $menu_filter || $is_new ) {
			return 'menu_items';
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		return $post_id && WowRestro_Products::is_menu_item( wc_get_product( $post_id ) ) ? 'menu_items' : '';
	}

	/** Return the active navigation class without duplicating comparisons in the template. */
	private static function navigation_class( $section, $current ) {
		return $section === $current ? 'is-current' : '';
	}

	private static function add_product_meta_clause( $query, $clause ) {
		$existing = (array) $query->get( 'meta_query' );
		$query->set(
			'meta_query',
			$existing ? array(
				'relation' => 'AND',
				$existing,
				$clause,
			) : array( $clause )
		);
	}

	/**
	 * Render the kitchen queue rows.
	 *
	 * @param WC_Order[] $orders Orders to list.
	 */
	private static function orders_table( $orders ) {
		?>
		<div class="wra-queue__cols">
			<span><?php esc_html_e( 'Order', 'wowrestro' ); ?></span>
			<span><?php esc_html_e( 'Promise', 'wowrestro' ); ?></span>
			<span><?php esc_html_e( 'Workflow', 'wowrestro' ); ?></span>
			<span><?php esc_html_e( 'Payment', 'wowrestro' ); ?></span>
			<span style="text-align:right"><?php esc_html_e( 'Total', 'wowrestro' ); ?></span>
		</div>
		<?php
		foreach ( $orders as $order ) :
			$status   = WowRestro_Order_Statuses::current( $order );
			$status   = $status ? $status : 'new';
			$editable = current_user_can( 'edit_shop_order', $order->get_id() );
			?>
			<div class="wra-queue__row">
				<span>
					<?php if ( $editable ) : ?>
					<a class="wra-queue__num" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
					<?php else : ?>
					<strong class="wra-queue__num">#<?php echo esc_html( $order->get_order_number() ); ?></strong>
					<?php endif; ?>
					<small class="wra-queue__mode"><?php echo esc_html( 'delivery' === $order->get_meta( '_wowrestro_fulfillment' ) ? __( 'Delivery', 'wowrestro' ) : __( 'Pickup', 'wowrestro' ) ); ?></small>
				</span>
				<span class="wra-queue__when"><?php echo esc_html( self::promise_label( $order ) ); ?></span>
				<span><span class="wra-chip wra-s-<?php echo esc_attr( $status ); ?>"><span class="wra-chip__dot"></span><?php echo esc_html( WowRestro_Order_Statuses::label( $status ) ); ?></span></span>
				<span class="wra-queue__pay"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
				<span class="wra-queue__total"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
			</div>
			<?php
		endforeach;
		if ( ! $orders ) :
			?>
			<div class="wra-queue__row"><span class="wra-queue__mode"><?php esc_html_e( 'No restaurant orders yet.', 'wowrestro' ); ?></span></div>
			<?php
		endif;
	}

	private static function promise_timestamp( $order ) {
		$value = trim( (string) $order->get_meta( '_wowrestro_promised_at_gmt' ) );
		return $value ? strtotime( $value . ' UTC' ) : 0;
	}

	private static function promise_label( $order ) {
		$timestamp = self::promise_timestamp( $order );
		return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, wp_timezone() ) : __( 'ASAP / not set', 'wowrestro' );
	}
}
