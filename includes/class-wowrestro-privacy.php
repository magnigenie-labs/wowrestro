<?php
/**
 * Privacy exporter and eraser integration.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Privacy {
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['wowrestro'] = array( 'exporter_friendly_name' => __( 'WowRestro order fulfillment data', 'wowrestro' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['wowrestro'] = array( 'eraser_friendly_name' => __( 'WowRestro tracking and notification data', 'wowrestro' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	public static function export( $email_address, $page = 1 ) {
		$query = self::orders_for_email( $email_address, $page );
		$data  = array();
		foreach ( $query->orders as $order ) {
			$item_data = array();
			foreach ( $order->get_items() as $item ) {
				$note      = $item->get_meta( '_wowrestro_special_note' ) ?: $item->get_meta( '_wowrestro_item_note' );
				$modifiers = $item->get_meta( '_wowrestro_modifier_snapshot' ) ?: $item->get_meta( '_wowrestro_modifiers' );
				if ( $note || $modifiers ) {
					$item_data[] = array(
						'item'      => $item->get_name(),
						'note'      => $note,
						'modifiers' => $modifiers,
					);
				}
			}
			$data[] = array(
				'group_id'    => 'wowrestro-orders',
				'group_label' => __( 'WowRestro fulfillment', 'wowrestro' ),
				'item_id'     => 'wowrestro-order-' . $order->get_id(),
				'data'        => array(
					array( 'name' => __( 'Order', 'wowrestro' ), 'value' => $order->get_order_number() ),
					array( 'name' => __( 'Order source', 'wowrestro' ), 'value' => $order->get_meta( '_wowrestro_source' ) ),
					array( 'name' => __( 'Fulfillment mode', 'wowrestro' ), 'value' => $order->get_meta( '_wowrestro_fulfillment' ) ?: $order->get_meta( '_wowrestro_mode' ) ),
					array( 'name' => __( 'Promised time', 'wowrestro' ), 'value' => $order->get_meta( '_wowrestro_promised_at' ) ),
					array( 'name' => __( 'Operational status', 'wowrestro' ), 'value' => $order->get_meta( '_wowrestro_operational_status' ) ),
					array( 'name' => __( 'Payment flow', 'wowrestro' ), 'value' => $order->get_meta( '_wowrestro_payment_flow' ) ),
					array( 'name' => __( 'Item choices and notes', 'wowrestro' ), 'value' => wp_json_encode( $item_data ) ),
					array( 'name' => __( 'Status-update consent', 'wowrestro' ), 'value' => $order->get_meta( '_wowrestro_status_opt_in' ) ),
				),
			);
		}
		return array( 'data' => $data, 'done' => $page >= absint( $query->max_num_pages ) );
	}

	public static function erase( $email_address, $page = 1 ) {
		$query   = self::orders_for_email( $email_address, $page );
		$removed = false;
		foreach ( $query->orders as $order ) {
			if ( $order->get_meta( '_wowrestro_status_opt_in' ) || $order->get_meta( '_wowrestro_last_notified_status' ) || $order->get_meta( '_wowrestro_tracking_token' ) ) {
				$order->update_meta_data( '_wowrestro_status_opt_in', 'no' );
				$order->delete_meta_data( '_wowrestro_last_notified_status' );
				$order->delete_meta_data( '_wowrestro_tracking_token' );
				$order->save();
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => ! empty( $query->orders ),
			'messages'       => ! empty( $query->orders ) ? array( __( 'Order and fulfillment records remain for store record-keeping; notification consent and public tracking access were revoked.', 'wowrestro' ) ) : array(),
			'done'           => $page >= absint( $query->max_num_pages ),
		);
	}

	private static function orders_for_email( $email_address, $page ) {
		$query = WowRestro_Order_Statuses::query_orders(
			array(
				'billing_email' => sanitize_email( $email_address ),
				'limit'         => 50,
				'page'          => max( 1, absint( $page ) ),
				'paginate'      => true,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'meta_query'    => array(
					array(
						'key'   => '_wowrestro_order',
						'value' => 'yes',
					),
				),
			)
		);
		if ( is_object( $query ) && isset( $query->orders ) ) {
			$email = sanitize_email( $email_address );
			$query->orders = array_values(
				array_filter(
					$query->orders,
					function ( $order ) use ( $email ) {
						return $order instanceof WC_Order && WowRestro_Order_Statuses::is_restaurant_order( $order ) && hash_equals( $email, sanitize_email( $order->get_billing_email() ) );
					}
				)
			);
		}
		return $query;
	}
}
