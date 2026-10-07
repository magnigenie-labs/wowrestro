<?php
/**
 * HPOS-safe restaurant sales analytics.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

/** Build restaurant-only admin and mobile sales reports. */
final class WowRestro_Analytics {
	/** Register analytics surfaces. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** Register the authenticated mobile report route. */
	public static function register_routes() {
		register_rest_route(
			'wowrestro/v1',
			'/operations/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_data' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'location' => array(
						'sanitize_callback' => array( 'WowRestro_REST_API', 'sanitize_category_param' ),
						'default'           => '',
					),
					'period'   => array(
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'today',
					),
					'start'    => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'end'      => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/** Whether the current user may read restaurant reports. */
	public static function can_view() {
		return current_user_can( 'wowrestro_manage_operations' ) || current_user_can( 'manage_woocommerce' );
	}

	/** Add analytics to the WowRestro admin menu. */
	public static function menu() {
		add_submenu_page( 'wowrestro-app', __( 'WowRestro Analytics', 'wowrestro' ), __( 'Analytics', 'wowrestro' ), 'wowrestro_manage_operations', 'wowrestro-analytics', array( __CLASS__, 'page' ) );
	}

	/**
	 * Build a report ending today.
	 *
	 * @param int    $days Number of days to include.
	 * @param string $location Optional location slug.
	 * @return array<string,mixed>
	 */
	public static function data( $days = 30, $location = '' ) {
		$days  = min( 365, max( 1, absint( $days ) ) );
		$end   = new DateTimeImmutable( 'tomorrow', wp_timezone() );
		$start = $end->modify( '-' . $days . ' days' );
		return self::period_data( $start, $end, $days, $location );
	}

	/**
	 * Aggregate paid restaurant orders in an exact store-timezone period.
	 *
	 * @param DateTimeInterface $start Period start.
	 * @param DateTimeInterface $end Period end, exclusive.
	 * @param int               $days Displayed period length.
	 * @param string            $location Optional location slug.
	 * @return array<string,mixed>
	 */
	private static function period_data( $start, $end, $days, $location = '' ) {
		$orders    = wc_get_orders(
			array(
				'limit'        => -1,
				'date_created' => $start->getTimestamp() . '...' . ( $end->getTimestamp() - 1 ),
				'status'       => array_keys( wc_get_order_statuses() ),
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);
		$orders    = array_values(
			array_filter(
				(array) $orders,
				function ( $order ) use ( $location ) {
					return $order instanceof WC_Order && WowRestro_Order_Statuses::is_restaurant_order( $order ) && ( ! $location || $location === $order->get_meta( WowRestro_Locations::ORDER_META ) );
				}
			)
		);
		$revenue   = 0.0;
		$sales     = 0;
		$customers = array();
		$products  = array();
		$daily     = array();
		foreach ( $orders as $order ) {
			if ( $order->is_paid() ) {
				++$sales;
				$net      = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
				$revenue += $net;
				$key      = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
				if ( $key ) {
					$daily[ $key ] = ( $daily[ $key ] ?? 0 ) + $net; }
				foreach ( $order->get_items() as $item ) {
					$id       = $item->get_product_id();
					$quantity = (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item->get_id() );
					$item_net = (float) $item->get_total() + (float) $order->get_total_refunded_for_item( $item->get_id() );
					if ( ! isset( $products[ $id ] ) ) {
						$product   = $item->get_product();
						$image_id = $product ? $product->get_image_id() : 0;
						if ( ! $image_id && $id ) {
							$parent   = wc_get_product( $id );
							$image_id = $parent ? $parent->get_image_id() : 0;
						}
						$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
						$products[ $id ] = array(
							'name'     => $item->get_name(),
							'quantity' => 0,
							'revenue'  => 0.0,
							'image'    => $image_url ? $image_url : '',
						); }
					$products[ $id ]['quantity'] += max( 0.0, $quantity );
					$products[ $id ]['revenue']  += max( 0.0, $item_net );
				}
				$email = strtolower( sanitize_email( $order->get_billing_email() ) );
				if ( $email ) {
					$customers[ $email ] = true; }
			}
		}
		$products = array_values(
			array_filter(
				$products,
				static function ( $product ) {
					return $product['quantity'] > 0;
				}
			)
		);
		usort(
			$products,
			function ( $a, $b ) {
				return $b['quantity'] <=> $a['quantity'];
			}
		);
		return array(
			'days'      => $days,
			'revenue'   => $revenue,
			'orders'    => $sales,
			'customers' => count( $customers ),
			'top_sales' => array_slice( $products, 0, 10 ),
			'daily'     => $daily,
		);
	}

	/**
	 * Resolve a mobile report period in the store timezone.
	 *
	 * @param WP_REST_Request $request Report filters.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function rest_period( $request ) {
		$period = sanitize_key( (string) $request->get_param( 'period' ) );
		$today  = current_datetime()->setTime( 0, 0, 0 );
		$start  = $today;
		$end    = $today->modify( '+1 day' );

		if ( 'yesterday' === $period ) {
			$start = $today->modify( '-1 day' );
			$end   = $today;
		} elseif ( 'this_month' === $period ) {
			$start = $today->modify( 'first day of this month' );
		} elseif ( 'last_month' === $period ) {
			$end   = $today->modify( 'first day of this month' );
			$start = $end->modify( '-1 month' );
		} elseif ( 'custom' === $period ) {
			$start_value = sanitize_text_field( (string) $request->get_param( 'start' ) );
			$end_value   = sanitize_text_field( (string) $request->get_param( 'end' ) );
			$start       = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_value, wp_timezone() );
			$custom_end  = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_value, wp_timezone() );
			if ( ! $start || ! $custom_end || $start->format( 'Y-m-d' ) !== $start_value || $custom_end->format( 'Y-m-d' ) !== $end_value || $custom_end < $start ) {
				return new WP_Error( 'wowrestro_invalid_report_range', __( 'Choose a valid report date range.', 'wowrestro' ), array( 'status' => 400 ) );
			}
			$end = $custom_end->modify( '+1 day' );
		} elseif ( 'today' !== $period ) {
			return new WP_Error( 'wowrestro_invalid_report_period', __( 'Choose a valid report period.', 'wowrestro' ), array( 'status' => 400 ) );
		}

		$days = max( 1, (int) $start->diff( $end )->days );
		if ( $days > 366 ) {
			return new WP_Error( 'wowrestro_report_range_too_large', __( 'Report ranges cannot exceed 366 days.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$weekly_comparison = in_array( $period, array( 'today', 'yesterday' ), true );
		$previous_start    = $weekly_comparison ? $start->modify( '-7 days' ) : $start->modify( '-' . $days . ' days' );
		$previous_end      = $weekly_comparison ? $end->modify( '-7 days' ) : $start;

		return array(
			'period'           => $period,
			'start'            => $start,
			'end'              => $end,
			'days'             => $days,
			'previous_start'   => $previous_start,
			'previous_end'     => $previous_end,
			'comparison_label' => $weekly_comparison ? 'same day last week' : 'previous period',
		);
	}

	/**
	 * Return filtered sales, a real comparison period and top sellers.
	 *
	 * @param WP_REST_Request $request Report filters.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_data( $request ) {
		$location = sanitize_title( (string) $request->get_param( 'location' ) );
		$range    = self::rest_period( $request );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$current  = self::period_data( $range['start'], $range['end'], $range['days'], $location );
		$previous = self::period_data( $range['previous_start'], $range['previous_end'], $range['days'], $location );
		$response       = rest_ensure_response(
			array(
				'period'                => $range['period'],
				'date'                  => $range['start']->format( 'Y-m-d' ),
				'start_date'            => $range['start']->format( 'Y-m-d' ),
				'end_date'              => $range['end']->modify( '-1 day' )->format( 'Y-m-d' ),
				'comparison_label'      => $range['comparison_label'],
				'total_sales'           => $current['revenue'],
				'total_orders'          => $current['orders'],
				'previous_total_sales'  => $previous['revenue'],
				'previous_total_orders' => $previous['orders'],
				'top_sellers'           => $current['top_sales'],
			)
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/** Render the WordPress analytics page. */
	public static function page() {
		if ( ! current_user_can( 'wowrestro_manage_operations' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'wowrestro' ) ); }
		$days      = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
		$location  = isset( $_GET['location'] ) ? sanitize_title( wp_unslash( $_GET['location'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data      = self::data( $days, $location );
		$locations = get_terms(
			array(
				'taxonomy'   => WowRestro_Locations::TAXONOMY,
				'hide_empty' => false,
			)
		);
		?>
		<div class="wrap wowrestro-analytics"><h1><?php esc_html_e( 'Restaurant analytics', 'wowrestro' ); ?></h1>
		<form method="get"><input type="hidden" name="page" value="wowrestro-analytics"><label><?php esc_html_e( 'Period', 'wowrestro' ); ?> <select name="days">
		<?php
		foreach ( array( 7, 30, 90, 365 ) as $period ) :
			?>
			<?php /* translators: %d: number of days in the report. */ ?>
			<option value="<?php echo esc_attr( $period ); ?>" <?php selected( $data['days'], $period ); ?>><?php echo esc_html( sprintf( _n( '%d day', '%d days', $period, 'wowrestro' ), $period ) ); ?></option><?php endforeach; ?></select></label>
		<?php
		if ( ! is_wp_error( $locations ) && $locations ) :
			?>
			<label><?php esc_html_e( 'Location', 'wowrestro' ); ?> <select name="location"><option value=""><?php esc_html_e( 'All', 'wowrestro' ); ?></option>
			<?php
			foreach ( $locations as $term ) :
				?>
			<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $location, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select></label><?php endif; ?>
		<button class="button"><?php esc_html_e( 'Apply', 'wowrestro' ); ?></button></form>
		<div class="wowrestro-analytics__metrics">
			<div><strong><?php echo wp_kses_post( wc_price( $data['revenue'] ) ); ?></strong><span><?php esc_html_e( 'Revenue', 'wowrestro' ); ?></span></div>
			<div><strong><?php echo esc_html( $data['orders'] ); ?></strong><span><?php esc_html_e( 'Orders', 'wowrestro' ); ?></span></div>
			<div><strong><?php echo esc_html( $data['customers'] ); ?></strong><span><?php esc_html_e( 'Customers', 'wowrestro' ); ?></span></div>
			<div><strong><?php echo esc_html( $data['orders'] ? wc_format_decimal( $data['revenue'] / $data['orders'], 2 ) : 0 ); ?></strong><span><?php esc_html_e( 'Average order', 'wowrestro' ); ?></span></div>
		</div>
		<h2><?php esc_html_e( 'Top-selling items', 'wowrestro' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Item', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Quantity', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Revenue', 'wowrestro' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $data['top_sales'] as $item ) :
			?>
			<tr><td><?php echo esc_html( $item['name'] ); ?></td><td><?php echo esc_html( wc_format_decimal( $item['quantity'], 0 ) ); ?></td><td><?php echo wp_kses_post( wc_price( $item['revenue'] ) ); ?></td></tr><?php endforeach; ?>
		<?php
		if ( ! $data['top_sales'] ) :
			?>
			<tr><td colspan="3"><?php esc_html_e( 'No restaurant sales in this period.', 'wowrestro' ); ?></td></tr><?php endif; ?></tbody></table></div>
		<style>.wowrestro-analytics__metrics{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:16px;margin:20px 0}.wowrestro-analytics__metrics div{background:#fff;border:1px solid #dcdcde;padding:20px}.wowrestro-analytics__metrics strong,.wowrestro-analytics__metrics span{display:block}.wowrestro-analytics__metrics strong{font-size:28px;margin-bottom:8px}@media(max-width:700px){.wowrestro-analytics__metrics{grid-template-columns:1fr 1fr}}</style>
		<?php
	}
}
