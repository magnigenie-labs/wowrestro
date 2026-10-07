<?php
/**
 * Consent-aware customer operational emails.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Notifications {
	public static function init() {
		add_action( 'wowrestro_order_transitioned', array( __CLASS__, 'dispatch' ), 10, 3 );
		add_action( 'wowrestro_order_transitioned', array( __CLASS__, 'mark_change' ), 1 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'mark_change' ), 40 );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat' ), 10, 2 );
	}

	public static function mark_change() {
		update_option( 'wowrestro_orders_changed', sprintf( '%.6F', microtime( true ) ), false );
	}

	public static function heartbeat( $response, $data ) {
		if ( ( current_user_can( 'wowrestro_view_orders' ) || current_user_can( 'manage_woocommerce' ) ) && ! empty( $data['wowrestro_orders'] ) ) {
			$response['wowrestro_orders'] = (string) get_option( 'wowrestro_orders_changed', '0' );
		}
		return $response;
	}

	public static function dispatch( $order, $from, $to ) {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( '_wowrestro_status_opt_in' ) ) {
			return;
		}
		$to = WowRestro_Order_Statuses::normalize( $to );
		if ( $to === $order->get_meta( '_wowrestro_last_notified_status' ) ) {
			return;
		}

		$payload = array(
			'order_id'      => $order->get_id(),
			'order_number'  => $order->get_order_number(),
			'from'          => WowRestro_Order_Statuses::normalize( $from ),
			'to'            => $to,
			'status_label'  => WowRestro_Order_Statuses::label( $to ),
			'mode'          => $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ),
			'promised_at'   => $order->get_meta( '_wowrestro_promised_at_gmt' ) ?: $order->get_meta( '_wowrestro_promised_at' ),
			'email'         => $order->get_billing_email(),
			'customer_name' => $order->get_formatted_billing_full_name(),
			'tracking_url'  => self::tracking_url( $order ),
			'opted_in'      => true,
		);

		$settings = WowRestro_Fulfillment::settings();
		if ( 'yes' === ( $settings['email_updates'] ?? 'yes' ) && is_email( $payload['email'] ) ) {
			self::send_email( $payload );
		}

		/** Allow an explicitly configured, consent-aware email adapter to observe the event. */
		do_action( 'wowrestro_notification_dispatch', $payload, $order );
		$order->update_meta_data( '_wowrestro_last_notified_status', $to );
		$order->save_meta_data();
	}

	private static function send_email( $payload ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return;
		}
		$subject = sprintf( __( 'Order #%1$s is now %2$s', 'wowrestro' ), $payload['order_number'], $payload['status_label'] );
		$name    = $payload['customer_name'] ?: __( 'there', 'wowrestro' );
		$body    = '<p>' . esc_html( sprintf( __( 'Hello %1$s, your order #%2$s is now %3$s.', 'wowrestro' ), $name, $payload['order_number'], $payload['status_label'] ) ) . '</p>';
		if ( $payload['tracking_url'] ) {
			$body .= '<p><a href="' . esc_url( $payload['tracking_url'] ) . '">' . esc_html__( 'Track your order', 'wowrestro' ) . '</a></p>';
		}
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $subject, $body );
		$mailer->send( $payload['email'], $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	private static function tracking_url( $order ) {
		$token = sanitize_text_field( (string) $order->get_meta( '_wowrestro_tracking_token' ) );
		$url   = $token ? rest_url( 'wowrestro/v1/tracking/' . rawurlencode( $token ) ) : '';
		return (string) apply_filters( 'wowrestro_tracking_url', $url, $token, $order );
	}
}
