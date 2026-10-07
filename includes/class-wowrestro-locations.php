<?php
/**
 * Branch taxonomy, storefront selection and order snapshots.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Locations {
	const TAXONOMY   = 'wowrestro_location';
	const ORDER_META = '_wowrestro_location';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'hide_native_menu' ), 999 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		add_action( 'wp_loaded', array( __CLASS__, 'capture_selection' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_location' ), 5 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_order_location' ), 5 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_location' ) );
		add_shortcode( 'wowrestro_location_selector', array( __CLASS__, 'selector' ) );
	}

	/** Keep location management inside the WowRestro workspace. */
	public static function hide_native_menu() {
		remove_submenu_page( 'edit.php?post_type=product', 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=product' );
	}

	/** Flush new location/CPT routes once after an upgrade. */
	public static function maybe_flush_rewrite() {
		if ( 'yes' === get_option( 'wowrestro_flush_rewrite', 'no' ) ) {
			flush_rewrite_rules();
			delete_option( 'wowrestro_flush_rewrite' );
		}
	}

	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			'product',
			array(
				'labels'            => array(
					'name'          => __( 'Locations', 'wowrestro' ),
					'singular_name' => __( 'Location', 'wowrestro' ),
				),
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => array( 'slug' => 'restaurant-location' ),
			)
		);
	}

	public static function capture_selection() {
		if ( ! isset( $_GET['wr_location'] ) || ! function_exists( 'WC' ) || ! WC()->session ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only catalogue preference.
			return;
		}
		$slug = sanitize_title( wp_unslash( $_GET['wr_location'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $slug || term_exists( $slug, self::TAXONOMY ) ) {
			WC()->session->set( 'wowrestro_location', $slug );
		}
	}

	public static function current() {
		$requested = isset( $_GET['wr_location'] ) ? sanitize_title( wp_unslash( $_GET['wr_location'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $requested && term_exists( $requested, self::TAXONOMY ) ) {
			return $requested;
		}
		return function_exists( 'WC' ) && WC()->session ? sanitize_title( (string) WC()->session->get( 'wowrestro_location', '' ) ) : '';
	}

	public static function selector( $attributes = array() ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
			return '';
		}
		$current = self::current();
		ob_start();
		?>
		<form class="wowrestro-location-selector" method="get">
			<?php if ( is_admin() && isset( $_GET['page'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve read-only admin route. ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['page'] ) ) ); ?>">
			<?php endif; ?>
			<label><?php esc_html_e( 'Restaurant location', 'wowrestro' ); ?>
				<select name="wr_location" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All locations', 'wowrestro' ); ?></option>
					<?php foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit"><?php esc_html_e( 'Choose', 'wowrestro' ); ?></button></noscript>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function save_order_location( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$slug = self::current();
		if ( $slug ) {
			$order->update_meta_data( self::ORDER_META, $slug );
		}
	}

	public static function order_location( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_meta( self::ORDER_META ) ) {
			return;
		}
		$term = get_term_by( 'slug', $order->get_meta( self::ORDER_META ), self::TAXONOMY );
		echo '<p><strong>' . esc_html__( 'Restaurant location:', 'wowrestro' ) . '</strong> ' . esc_html( $term ? $term->name : $order->get_meta( self::ORDER_META ) ) . '</p>';
	}
}
