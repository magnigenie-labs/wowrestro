<?php
/**
 * QR table sessions and the logged-in staff operations panel.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Dining {
	// WordPress caps post type names at 20 characters; the old 'wowrestro_table_session' never registered.
	const POST_TYPE = 'wowrestro_table_sess';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'capture_table' ), 25 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order' ), 6 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_order' ), 6 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'link_order' ), 45 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'link_order' ), 45 );
		add_shortcode( 'wowrestro_staff_panel', array( __CLASS__, 'staff_panel' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Table sessions', 'wowrestro' ),
					'singular_name' => __( 'Table session', 'wowrestro' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'wowrestro-app',
				'supports'     => array( 'title' ),
				'capabilities' => array(
					'edit_post'          => 'wowrestro_manage_operations',
					'read_post'          => 'wowrestro_view_orders',
					'delete_post'        => 'wowrestro_manage_operations',
					'edit_posts'         => 'wowrestro_manage_operations',
					'edit_others_posts'  => 'wowrestro_manage_operations',
					'publish_posts'      => 'wowrestro_manage_operations',
					'read_private_posts' => 'wowrestro_view_orders',
					'delete_posts'       => 'wowrestro_manage_operations',
					'create_posts'       => 'wowrestro_manage_operations',
				),
				'map_meta_cap' => false,
			)
		);
	}

	public static function capture_table() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$table = isset( $_GET['wr_table'] ) ? sanitize_text_field( wp_unslash( $_GET['wr_table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- QR code selection.
		if ( ! $table || strlen( $table ) > 80 ) {
			return;
		}
		$current = WC()->session->get( 'wowrestro_table_session', array() );
		if ( is_array( $current ) && ( $current['table'] ?? '' ) === $table ) {
			return;
		}
		// A scan only remembers the table; the session record is written with the first order,
		// so requests with ?wr_table= cannot fill the database. A first visit has no WooCommerce
		// session yet, so start one or the table is gone before checkout.
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( 'wowrestro_table_session', array( 'table' => $table ) );
	}

	/**
	 * Write the table session record for the visit's first order and remember it.
	 *
	 * @param string $table Table label from the QR code.
	 * @return array The session, with id and token once the record is written.
	 */
	private static function start_session( $table ) {
		$session = array( 'table' => $table );
		$token   = wp_generate_uuid4();
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: 1: table label, 2: session start date and time. */
				'post_title'  => sprintf( __( 'Table %1$s - %2$s', 'wowrestro' ), $table, wp_date( 'Y-m-d H:i' ) ),
				'meta_input'  => array(
					'_wowrestro_table'          => $table,
					'_wowrestro_session_token'  => $token,
					'_wowrestro_session_status' => 'active',
					'_wowrestro_last_seen'      => time(),
				),
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			$session = array(
				'id'    => $post_id,
				'token' => $token,
				'table' => $table,
			);
		}
		WC()->session->set( 'wowrestro_table_session', $session );
		return $session;
	}

	public static function save_order( $order ) {
		if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$session = WC()->session->get( 'wowrestro_table_session', array() );
		if ( ! is_array( $session ) || empty( $session['table'] ) ) {
			return;
		}
		if ( empty( $session['id'] ) ) {
			$session = self::start_session( $session['table'] );
		}
		$order->update_meta_data( '_wowrestro_dining_type', 'dine_in' );
		$order->update_meta_data( '_wowrestro_table', sanitize_text_field( $session['table'] ) );
		if ( ! empty( $session['id'] ) ) {
			$order->update_meta_data( '_wowrestro_table_session', absint( $session['id'] ) );
			update_post_meta( absint( $session['id'] ), '_wowrestro_last_seen', time() );
		}
	}

	/** Link the persisted WooCommerce order back to its table session. */
	public static function link_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$session_id = absint( $order->get_meta( '_wowrestro_table_session' ) );
		if ( $session_id ) {
			update_post_meta( $session_id, '_wowrestro_order_id', $order->get_id() );
			update_post_meta( $session_id, '_wowrestro_last_seen', time() );
		}
	}

	public static function staff_panel() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Sign in to open the restaurant staff panel.', 'wowrestro' ) . '</p>';
		}
		if ( ! current_user_can( 'wowrestro_view_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view restaurant orders.', 'wowrestro' ) . '</p>';
		}
		wp_enqueue_style( 'wowrestro-admin', WOWRESTRO_URL . 'assets/css/admin.css', array(), WOWRESTRO_VERSION );
		WowRestro_Order_Board::assets( 'wowrestro_page_wowrestro-orders' );
		ob_start();
		WowRestro_Order_Board::page();
		return ob_get_clean();
	}
}
