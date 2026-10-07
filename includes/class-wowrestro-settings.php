<?php
/**
 * Native WowRestro setup and operational settings.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Settings {
	const CAPABILITY = 'wowrestro_manage';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'option_page_capability_wowrestro', array( __CLASS__, 'settings_capability' ) );
		add_action( 'admin_post_wowrestro_sample_menu', array( __CLASS__, 'create_sample_menu' ) );
		add_action( 'admin_post_wowrestro_import_csv', array( __CLASS__, 'import_csv' ) );
		add_action( 'admin_post_wowrestro_save_menu_products', array( __CLASS__, 'save_menu_products' ) );
	}

	public static function register() {
		register_setting(
			'wowrestro',
			'wowrestro_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => WowRestro_Fulfillment::defaults(),
			)
		);
		register_setting(
			'wowrestro',
			'wowrestro_remove_data_on_uninstall',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default'           => 'no',
			)
		);
	}

	public static function settings_capability() {
		return self::CAPABILITY;
	}

	public static function sanitize_checkbox( $value ) {
		return self::checkbox_enabled( $value ) ? 'yes' : 'no';
	}

	/**
	 * Sanitize the versioned settings shape without discarding extension keys.
	 */
	public static function sanitize( $input ) {
		$input                          = is_array( $input ) ? $input : array();
		$clean                          = WowRestro_Fulfillment::settings();
		$clean['pickup_enabled']        = self::checkbox_enabled( $input['pickup_enabled'] ?? false ) ? 'yes' : 'no';
		$clean['delivery_enabled']      = self::checkbox_enabled( $input['delivery_enabled'] ?? false ) ? 'yes' : 'no';
		$clean['asap_enabled']          = self::checkbox_enabled( $input['asap_enabled'] ?? false ) ? 'yes' : 'no';
		$clean['prep_minutes']          = min( 240, max( 5, absint( $input['prep_minutes'] ?? 30 ) ) );
		$clean['delivery_lead_minutes'] = min( 240, absint( $input['delivery_lead_minutes'] ?? 15 ) );
		$clean['slot_interval']         = min( 120, max( 5, absint( $input['slot_interval'] ?? 15 ) ) );
		$clean['slot_capacity']         = min( 500, max( 1, absint( $input['slot_capacity'] ?? 8 ) ) );
		$clean['hold_minutes']          = min( 30, max( 5, absint( $input['hold_minutes'] ?? 10 ) ) );
		$clean['preorder_days']         = min( 90, max( 0, absint( $input['preorder_days'] ?? 7 ) ) );
		$clean['date_overrides']        = self::clean_overrides( $input['date_overrides'] ?? '' );
		if ( array_key_exists( 'holidays', $input ) ) {
			$clean['holidays'] = self::clean_holidays( $input['holidays'] );
		}
		$menu_template                  = $input['menu_template'] ?? ( $clean['menu_template'] ?? 1 );
		$menu_layout                    = sanitize_key( $input['menu_layout'] ?? ( $clean['menu_layout'] ?? 'tabs' ) );
		$clean['menu_template']         = min( 36, max( 1, absint( $menu_template ) ) );
		$clean['menu_layout']           = in_array( $menu_layout, array( 'list', 'tabs' ), true ) ? $menu_layout : 'tabs';
		$clean['menu_page_size']        = min( 100, max( 12, absint( $input['menu_page_size'] ?? 30 ) ) );
		$clean['dietary_attribute']     = self::clean_attribute( $input['dietary_attribute'] ?? 'pa_dietary', 'pa_dietary' );
		$clean['allergen_attribute']    = self::clean_attribute( $input['allergen_attribute'] ?? 'pa_allergens', 'pa_allergens' );
		$clean['tips_enabled']          = self::checkbox_enabled( $input['tips_enabled'] ?? false ) ? 'yes' : 'no';
		$clean['tip_presets']           = self::clean_percentages( $input['tip_presets'] ?? '10,15,20' );
		$clean['custom_tips']           = self::checkbox_enabled( $input['custom_tips'] ?? false ) ? 'yes' : 'no';
		$clean['tips_taxable']          = self::checkbox_enabled( $input['tips_taxable'] ?? false ) ? 'yes' : 'no';
		$clean['tips_tax_class']        = sanitize_title( $input['tips_tax_class'] ?? '' );
		$clean['late_grace_minutes']    = min( 60, absint( $input['late_grace_minutes'] ?? 5 ) );
		$clean['email_updates']         = self::checkbox_enabled( $input['email_updates'] ?? false ) ? 'yes' : 'no';
		$clean['orders_paused']         = self::checkbox_enabled( $input['orders_paused'] ?? false ) ? 'yes' : 'no';

		$service_hours = array();
		foreach ( array( WowRestro_Fulfillment::MODE_PICKUP, WowRestro_Fulfillment::MODE_DELIVERY ) as $mode ) {
			foreach ( range( 1, 7 ) as $day ) {
				$row                            = isset( $input['service_hours'][ $mode ][ $day ] ) && is_array( $input['service_hours'][ $mode ][ $day ] ) ? $input['service_hours'][ $mode ][ $day ] : array();
				$service_hours[ $mode ][ $day ] = array(
					'enabled' => self::checkbox_enabled( $row['enabled'] ?? false ) ? 'yes' : 'no',
					'periods' => self::clean_periods( $row['periods'] ?? '11:00-22:00', '11:00-22:00' ),
				);
			}
		}
		$clean['service_hours'] = $service_hours;

		// Keep 0.2 readers safe until the compatibility mirrors are removed.
		foreach ( range( 1, 7 ) as $day ) {
			$periods                       = explode( ',', $service_hours[ WowRestro_Fulfillment::MODE_PICKUP ][ $day ]['periods'] );
			$parts                         = explode( '-', reset( $periods ), 2 );
			$clean['weekly_hours'][ $day ] = array(
				'enabled' => $service_hours[ WowRestro_Fulfillment::MODE_PICKUP ][ $day ]['enabled'],
				'open'    => $parts[0] ?? '11:00',
				'close'   => $parts[1] ?? '22:00',
			);
		}
		$clean['open_time']  = $clean['weekly_hours'][1]['open'];
		$clean['close_time'] = $clean['weekly_hours'][1]['close'];

		$clean['shipping_rate_modes']    = array();
		$clean['shipping_rate_minimums'] = array();
		$rate_modes                      = isset( $input['shipping_rate_modes'] ) && is_array( $input['shipping_rate_modes'] ) ? $input['shipping_rate_modes'] : array();
		$minimums                        = isset( $input['shipping_rate_minimums'] ) && is_array( $input['shipping_rate_minimums'] ) ? $input['shipping_rate_minimums'] : array();
		foreach ( array_unique( array_merge( array_keys( $rate_modes ), array_keys( $minimums ) ) ) as $rate_id ) {
			$key  = sanitize_text_field( wp_unslash( (string) $rate_id ) );
			$mode = isset( $rate_modes[ $rate_id ] ) ? sanitize_key( $rate_modes[ $rate_id ] ) : '';
			if ( ! $key || ! in_array( $mode, array( 'pickup', 'delivery' ), true ) ) {
				continue;
			}
			$clean['shipping_rate_modes'][ $key ]    = $mode;
			$clean['shipping_rate_minimums'][ $key ] = wc_format_decimal( $minimums[ $rate_id ] ?? 0 );
		}

		// Legacy delivery rules are retained for migration reports, never checkout.
		$stored = get_option( 'wowrestro_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( array( 'delivery_fee', 'minimum_order', 'delivery_postcodes', 'delivery_zones' ) as $legacy_key ) {
			if ( array_key_exists( $legacy_key, $stored ) ) {
				$clean[ $legacy_key ] = $stored[ $legacy_key ];
			}
		}
		return $clean;
	}

	/** Treat canonical "no" values as disabled during programmatic updates. */
	private static function checkbox_enabled( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'true', 'yes' ), true );
	}

	public static function page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WowRestro.', 'wowrestro' ) );
		}
		$settings        = WowRestro_Fulfillment::settings();
		$days            = array(
			1 => __( 'Monday', 'wowrestro' ),
			2 => __( 'Tuesday', 'wowrestro' ),
			3 => __( 'Wednesday', 'wowrestro' ),
			4 => __( 'Thursday', 'wowrestro' ),
			5 => __( 'Friday', 'wowrestro' ),
			6 => __( 'Saturday', 'wowrestro' ),
			7 => __( 'Sunday', 'wowrestro' ),
		);
		$rates           = class_exists( 'WowRestro_Shipping' ) ? WowRestro_Shipping::configured_rates() : array();
		$product_query   = wc_get_products(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'status'     => array( 'publish', 'draft' ),
				'paginate'   => true,
				'meta_key'   => '_wowrestro_menu_item',
				'meta_value' => 'yes',
			)
		);
		$product_count   = is_object( $product_query ) ? absint( $product_query->total ) : count( $product_query );
		$menu_products   = wc_get_products(
			array(
				'limit'   => 250,
				'status'  => array( 'publish', 'draft', 'private' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		$modifier_groups = get_posts(
			array(
				'post_type'      => WowRestro_Modifiers::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		?>
		<div class="wrap wowrestro-setup">
			<h1><?php esc_html_e( 'Set up WowRestro', 'wowrestro' ); ?></h1>
			<p><?php esc_html_e( 'Configure the promises your kitchen can keep, then place a test order. WooCommerce remains authoritative for shipping, tax, stock and payment.', 'wowrestro' ); ?></p>
			<ol><li><?php esc_html_e( 'Confirm services, shipping methods and weekly hours.', 'wowrestro' ); ?></li><li><?php echo esc_html( sprintf( _n( '%d restaurant product is ready to use.', '%d restaurant products are ready to use.', $product_count, 'wowrestro' ), $product_count ) ); ?></li><li><?php esc_html_e( 'Complete a test order before opening online ordering.', 'wowrestro' ); ?></li></ol>
			<form method="post" action="options.php">
				<?php settings_fields( 'wowrestro' ); ?>
				<h2><?php esc_html_e( 'Ordering and capacity', 'wowrestro' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
				<tr><th><?php esc_html_e( 'Services', 'wowrestro' ); ?></th><td>
					<label><input type="checkbox" name="wowrestro_settings[pickup_enabled]" <?php checked( 'yes', $settings['pickup_enabled'] ); ?>> <?php esc_html_e( 'Pickup', 'wowrestro' ); ?></label><br>
					<label><input type="checkbox" name="wowrestro_settings[delivery_enabled]" <?php checked( 'yes', $settings['delivery_enabled'] ); ?>> <?php esc_html_e( 'Delivery', 'wowrestro' ); ?></label><br>
					<label><input type="checkbox" name="wowrestro_settings[asap_enabled]" <?php checked( 'yes', $settings['asap_enabled'] ); ?>> <?php esc_html_e( 'Offer ASAP', 'wowrestro' ); ?></label>
				</td></tr>
				<tr><th><label for="wr-prep"><?php esc_html_e( 'Preparation time', 'wowrestro' ); ?></label></th><td><input id="wr-prep" type="number" min="5" max="240" name="wowrestro_settings[prep_minutes]" value="<?php echo esc_attr( $settings['prep_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'wowrestro' ); ?></td></tr>
				<tr><th><label for="wr-delivery-lead"><?php esc_html_e( 'Delivery lead', 'wowrestro' ); ?></label></th><td><input id="wr-delivery-lead" type="number" min="0" max="240" name="wowrestro_settings[delivery_lead_minutes]" value="<?php echo esc_attr( $settings['delivery_lead_minutes'] ); ?>"> <?php esc_html_e( 'minutes after the kitchen-ready slot', 'wowrestro' ); ?></td></tr>
				<tr><th><label for="wr-interval"><?php esc_html_e( 'Slot interval', 'wowrestro' ); ?></label></th><td><input id="wr-interval" type="number" min="5" max="120" step="5" name="wowrestro_settings[slot_interval]" value="<?php echo esc_attr( $settings['slot_interval'] ); ?>"> <?php esc_html_e( 'minutes', 'wowrestro' ); ?></td></tr>
				<tr><th><label for="wr-capacity"><?php esc_html_e( 'Shared slot capacity', 'wowrestro' ); ?></label></th><td><input id="wr-capacity" type="number" min="1" max="500" name="wowrestro_settings[slot_capacity]" value="<?php echo esc_attr( $settings['slot_capacity'] ); ?>"><p class="description"><?php esc_html_e( 'Pickup and delivery consume the same kitchen pool.', 'wowrestro' ); ?></p></td></tr>
				<tr><th><label for="wr-hold"><?php esc_html_e( 'Checkout hold', 'wowrestro' ); ?></label></th><td><input id="wr-hold" type="number" min="5" max="30" name="wowrestro_settings[hold_minutes]" value="<?php echo esc_attr( $settings['hold_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'wowrestro' ); ?></td></tr>
				<tr><th><label for="wr-preorder"><?php esc_html_e( 'Preorder window', 'wowrestro' ); ?></label></th><td><input id="wr-preorder" type="number" min="0" max="90" name="wowrestro_settings[preorder_days]" value="<?php echo esc_attr( $settings['preorder_days'] ); ?>"> <?php esc_html_e( 'days', 'wowrestro' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Rush control', 'wowrestro' ); ?></th><td><label><input type="checkbox" name="wowrestro_settings[orders_paused]" <?php checked( 'yes', $settings['orders_paused'] ); ?>> <?php esc_html_e( 'Pause new promises', 'wowrestro' ); ?></label></td></tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Service hours', 'wowrestro' ); ?></h2>
				<p><?php esc_html_e( 'Use comma-separated periods for split shifts, for example 11:00-14:00,17:00-22:00. Times use the WordPress timezone.', 'wowrestro' ); ?></p>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Day', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Pickup', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Delivery', 'wowrestro' ); ?></th></tr></thead><tbody>
				<?php foreach ( $days as $day => $label ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th>
					<?php
					foreach ( array( 'pickup', 'delivery' ) as $mode ) :
						$row = $settings['service_hours'][ $mode ][ $day ];
						?>
				<td><label><input type="checkbox" name="wowrestro_settings[service_hours][<?php echo esc_attr( $mode ); ?>][<?php echo esc_attr( $day ); ?>][enabled]" <?php checked( 'yes', $row['enabled'] ); ?>> <?php esc_html_e( 'Open', 'wowrestro' ); ?></label><br><input class="regular-text code" name="wowrestro_settings[service_hours][<?php echo esc_attr( $mode ); ?>][<?php echo esc_attr( $day ); ?>][periods]" value="<?php echo esc_attr( $row['periods'] ); ?>"></td>
				<?php endforeach; ?>
				</tr>
				<?php endforeach; ?>
				</tbody></table>
				<table class="form-table" role="presentation"><tbody><tr><th><label for="wr-holidays"><?php esc_html_e( 'Holidays', 'wowrestro' ); ?></label></th><td><input id="wr-holidays" class="large-text code" name="wowrestro_settings[holidays]" value="<?php echo esc_attr( $settings['holidays'] ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated closed dates in YYYY-MM-DD format.', 'wowrestro' ); ?></p></td></tr><tr><th><label for="wr-overrides"><?php esc_html_e( 'Date overrides', 'wowrestro' ); ?></label></th><td><textarea id="wr-overrides" class="large-text code" rows="5" name="wowrestro_settings[date_overrides]"><?php echo esc_textarea( $settings['date_overrides'] ); ?></textarea><p class="description"><?php esc_html_e( 'One per line: YYYY-MM-DD | pickup, delivery or all | closed or time periods.', 'wowrestro' ); ?></p></td></tr></tbody></table>

				<h2><?php esc_html_e( 'WooCommerce shipping methods', 'wowrestro' ); ?></h2>
				<?php if ( $rates ) : ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Zone and method', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Fulfillment', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Minimum order', 'wowrestro' ); ?></th></tr></thead><tbody>
					<?php
					foreach ( $rates as $rate_id => $label ) :
						$mode = $settings['shipping_rate_modes'][ $rate_id ] ?? WowRestro_Shipping::mode_for_rate( $rate_id );
						?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?><br><code><?php echo esc_html( $rate_id ); ?></code></th><td><select name="wowrestro_settings[shipping_rate_modes][<?php echo esc_attr( $rate_id ); ?>]"><option value="pickup" <?php selected( 'pickup', $mode ); ?>><?php esc_html_e( 'Pickup', 'wowrestro' ); ?></option><option value="delivery" <?php selected( 'delivery', $mode ); ?>><?php esc_html_e( 'Delivery', 'wowrestro' ); ?></option></select></td><td><input type="number" min="0" step="0.01" name="wowrestro_settings[shipping_rate_minimums][<?php echo esc_attr( $rate_id ); ?>]" value="<?php echo esc_attr( $settings['shipping_rate_minimums'][ $rate_id ] ?? 0 ); ?>"></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php else : ?>
				<p><?php esc_html_e( 'No enabled shipping methods were found. Add local pickup and/or delivery rates in WooCommerce shipping zones.', 'wowrestro' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>"><?php esc_html_e( 'Open shipping settings', 'wowrestro' ); ?></a></p>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Menu and checkout', 'wowrestro' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
				<tr><th><label for="wr-menu-size"><?php esc_html_e( 'Menu items per page', 'wowrestro' ); ?></label></th><td><input id="wr-menu-size" type="number" min="12" max="100" name="wowrestro_settings[menu_page_size]" value="<?php echo esc_attr( $settings['menu_page_size'] ); ?>"></td></tr>
				<tr><th><label for="wr-dietary"><?php esc_html_e( 'Dietary attribute', 'wowrestro' ); ?></label></th><td><input id="wr-dietary" name="wowrestro_settings[dietary_attribute]" value="<?php echo esc_attr( $settings['dietary_attribute'] ); ?>"></td></tr>
				<tr><th><label for="wr-allergens"><?php esc_html_e( 'Allergen attribute', 'wowrestro' ); ?></label></th><td><input id="wr-allergens" name="wowrestro_settings[allergen_attribute]" value="<?php echo esc_attr( $settings['allergen_attribute'] ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Tips', 'wowrestro' ); ?></th><td><label><input type="checkbox" name="wowrestro_settings[tips_enabled]" <?php checked( 'yes', $settings['tips_enabled'] ); ?>> <?php esc_html_e( 'Enable tips', 'wowrestro' ); ?></label><p><label><?php esc_html_e( 'Percentage presets', 'wowrestro' ); ?> <input name="wowrestro_settings[tip_presets]" value="<?php echo esc_attr( $settings['tip_presets'] ); ?>"></label></p><label><input type="checkbox" name="wowrestro_settings[custom_tips]" <?php checked( 'yes', $settings['custom_tips'] ); ?>> <?php esc_html_e( 'Allow a custom amount', 'wowrestro' ); ?></label><br><label><input type="checkbox" name="wowrestro_settings[tips_taxable]" <?php checked( 'yes', $settings['tips_taxable'] ); ?>> <?php esc_html_e( 'Tips are taxable', 'wowrestro' ); ?></label><p><label><?php esc_html_e( 'Tip tax class slug', 'wowrestro' ); ?> <input name="wowrestro_settings[tips_tax_class]" value="<?php echo esc_attr( $settings['tips_tax_class'] ); ?>"></label></p></td></tr>
				<tr><th><?php esc_html_e( 'Customer updates', 'wowrestro' ); ?></th><td><label><input type="checkbox" name="wowrestro_settings[email_updates]" <?php checked( 'yes', $settings['email_updates'] ); ?>> <?php esc_html_e( 'Allow opt-in operational emails', 'wowrestro' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Uninstall', 'wowrestro' ); ?></th><td><label><input type="checkbox" name="wowrestro_remove_data_on_uninstall" value="yes" <?php checked( 'yes', get_option( 'wowrestro_remove_data_on_uninstall', 'no' ) ); ?>> <?php esc_html_e( 'Permanently remove WowRestro data when the plugin is deleted', 'wowrestro' ); ?></label><p class="description"><?php esc_html_e( 'Off by default. Deactivation never removes data.', 'wowrestro' ); ?></p></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Save WowRestro settings', 'wowrestro' ) ); ?>
			</form>

			<hr><h2><?php esc_html_e( 'Restaurant menu products', 'wowrestro' ); ?></h2>
			<p><?php esc_html_e( 'Managers can mark existing simple or variable products for the restaurant menu, publish imported drafts, and attach add-on groups without access to unrelated WooCommerce settings.', 'wowrestro' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wowrestro_save_menu_products"><?php wp_nonce_field( 'wowrestro_save_menu_products' ); ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Product', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Restaurant item', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Published', 'wowrestro' ); ?></th><th><?php esc_html_e( 'Add-on groups', 'wowrestro' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $menu_products as $product ) :
					$assigned = WowRestro_Modifiers::assigned_group_ids( $product->get_id() );
					?>
				<tr><th scope="row"><?php echo esc_html( $product->get_name() ); ?><input type="hidden" name="product_ids[]" value="<?php echo esc_attr( $product->get_id() ); ?>"></th>
				<td><label><input type="checkbox" name="menu_items[<?php echo esc_attr( $product->get_id() ); ?>]" value="yes" <?php checked( 'yes', $product->get_meta( '_wowrestro_menu_item', true ) ); ?>> <?php esc_html_e( 'Include', 'wowrestro' ); ?></label></td>
				<td><label><input type="checkbox" name="published[<?php echo esc_attr( $product->get_id() ); ?>]" value="yes" <?php checked( 'publish', $product->get_status() ); ?>> <?php esc_html_e( 'Publish', 'wowrestro' ); ?></label></td>
				<td><select multiple name="modifier_groups[<?php echo esc_attr( $product->get_id() ); ?>][]" style="min-width:16rem">
					<?php
					foreach ( $modifier_groups as $group ) :
						?>
						<option value="<?php echo esc_attr( $group->ID ); ?>" <?php selected( in_array( $group->ID, $assigned, true ) ); ?>><?php echo esc_html( $group->post_title ); ?></option><?php endforeach; ?>
				</select></td></tr>
				<?php endforeach; ?>
				<?php
				if ( ! $menu_products ) :
					?>
					<tr><td colspan="4"><?php esc_html_e( 'No simple or variable WooCommerce products exist yet.', 'wowrestro' ); ?></td></tr><?php endif; ?>
				</tbody></table>
				<?php submit_button( __( 'Save restaurant products', 'wowrestro' ), 'secondary' ); ?>
			</form>

			<hr><h2><?php esc_html_e( 'Create restaurant products', 'wowrestro' ); ?></h2>
			<p><?php esc_html_e( 'Imported and sample products are drafts and are explicitly marked as WowRestro menu items.', 'wowrestro' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wowrestro_import_csv"><?php wp_nonce_field( 'wowrestro_import_csv' ); ?><input type="file" name="menu_csv" accept=".csv,text/csv" required> <?php submit_button( __( 'Import CSV as drafts', 'wowrestro' ), 'secondary', 'submit', false ); ?></form>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wowrestro_sample_menu' ), 'wowrestro_sample_menu' ) ); ?>"><?php esc_html_e( 'Create sample drafts', 'wowrestro' ); ?></a></p>
		</div>
		<?php
	}

	public static function create_sample_menu() {
		self::authorize( 'wowrestro_sample_menu' );
		$samples = array(
			'Margherita Pizza'  => '14.50',
			'Rigatoni Pomodoro' => '16.00',
			'Caesar Salad'      => '10.50',
			'Tiramisu'          => '7.50',
		);
		foreach ( $samples as $name => $price ) {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( 'draft' );
			$product->set_regular_price( $price );
			$product->set_short_description( __( 'WowRestro sample dish. Add a photo, review the price and publish when ready.', 'wowrestro' ) );
			$product->update_meta_data( '_wowrestro_menu_item', 'yes' );
			$product->save();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wowrestro-setup&wowrestro_samples=created' ) );
		exit;
	}

	/** Save the restaurant boundary, publication state and modifier assignments. */
	public static function save_menu_products() {
		if ( ! current_user_can( 'wowrestro_manage_menu' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage restaurant products.', 'wowrestro' ) );
		}
		check_admin_referer( 'wowrestro_save_menu_products' );
		$product_ids  = isset( $_POST['product_ids'] ) ? array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) ) ) ), 0, 500 ) : array();
		$menu_items   = isset( $_POST['menu_items'] ) && is_array( $_POST['menu_items'] ) ? wc_clean( wp_unslash( $_POST['menu_items'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes this bounded selection map.
		$published    = isset( $_POST['published'] ) && is_array( $_POST['published'] ) ? wc_clean( wp_unslash( $_POST['published'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes this bounded selection map.
		$assignments  = isset( $_POST['modifier_groups'] ) && is_array( $_POST['modifier_groups'] ) ? wc_clean( wp_unslash( $_POST['modifier_groups'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes this bounded selection map.
		$valid_groups = get_posts(
			array(
				'post_type'      => WowRestro_Modifiers::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$valid_groups = array_map( 'absint', $valid_groups );
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) {
				continue;
			}
			$product->update_meta_data( '_wowrestro_menu_item', isset( $menu_items[ $product_id ] ) ? 'yes' : 'no' );
			$product->update_meta_data( WowRestro_Modifiers::PRODUCT_GROUPS_META, array_values( array_intersect( $valid_groups, array_map( 'absint', (array) ( $assignments[ $product_id ] ?? array() ) ) ) ) );
			$product->set_status( isset( $published[ $product_id ] ) ? 'publish' : 'draft' );
			$product->save();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wowrestro-setup&menu_products=saved' ) );
		exit;
	}

	public static function import_csv() {
		self::authorize( 'wowrestro_import_csv' );
		$upload   = isset( $_FILES['menu_csv'] ) && is_array( $_FILES['menu_csv'] ) ? $_FILES['menu_csv'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verifies the nonce; each upload field is validated before use.
		$tmp_name = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
		$error    = isset( $upload['error'] ) && is_scalar( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( ! $tmp_name || UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wowrestro-setup&import=failed' ) );
			exit;
		}
		$filename = sanitize_file_name( isset( $upload['name'] ) && is_scalar( $upload['name'] ) ? (string) $upload['name'] : '' );
		$size     = isset( $upload['size'] ) && is_scalar( $upload['size'] ) ? absint( $upload['size'] ) : 0;
		if ( 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) || 0 === $size || $size > 2 * MB_IN_BYTES ) {
			wp_die( esc_html__( 'Upload a CSV file smaller than 2 MB.', 'wowrestro' ) );
		}
		$handle  = fopen( $tmp_name, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A verified PHP upload stream is read once and never persisted.
		$headers = $handle ? fgetcsv( $handle, 0, ',', '"', '' ) : false;
		$headers = is_array( $headers ) ? array_map( 'sanitize_key', $headers ) : array();
		$count   = 0;
		while ( $handle && ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false && $count < 500 ) {
			$values = array_slice( array_pad( $row, count( $headers ), '' ), 0, count( $headers ) );
			$data   = $headers ? array_combine( $headers, $values ) : false;
			if ( ! is_array( $data ) || empty( $data['name'] ) ) {
				continue;
			}
			$product = new WC_Product_Simple();
			$product->set_name( sanitize_text_field( $data['name'] ) );
			$product->set_regular_price( wc_format_decimal( $data['price'] ?? 0 ) );
			$product->set_short_description( wp_kses_post( $data['description'] ?? '' ) );
			$product->set_status( 'draft' );
			$product->update_meta_data( '_wowrestro_menu_item', 'yes' );
			if ( ! empty( $data['category'] ) ) {
				$term = term_exists( sanitize_text_field( $data['category'] ), 'product_cat' );
				$term = $term ?: wp_insert_term( sanitize_text_field( $data['category'] ), 'product_cat' );
				if ( ! is_wp_error( $term ) ) {
					$product->set_category_ids( array( absint( is_array( $term ) ? $term['term_id'] : $term ) ) );
				}
			}
			$product->save();
			++$count;
		}
		if ( $handle ) {
			fclose( $handle );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wowrestro-setup&imported=' . $count ) );
		exit;
	}

	private static function authorize( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wowrestro' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function clean_attribute( $value, $fallback ) {
		$value = sanitize_key( (string) $value );
		if ( ! $value ) {
			return $fallback;
		}
		return 0 === strpos( $value, 'pa_' ) ? $value : 'pa_' . $value;
	}

	private static function clean_percentages( $value ) {
		$values = array();
		foreach ( preg_split( '/[\s,]+/', (string) $value ) as $percentage ) {
			$percentage = (float) wc_format_decimal( $percentage );
			if ( $percentage > 0 && $percentage <= 100 ) {
				$values[] = wc_format_decimal( $percentage );
			}
		}
		$values = array_values( array_unique( $values ) );
		return $values ? implode( ',', $values ) : '10,15,20';
	}

	private static function clean_holidays( $value ) {
		$dates = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $value ) ), array( __CLASS__, 'valid_date' ) );
		return implode( ',', array_values( array_unique( $dates ) ) );
	}

	private static function clean_overrides( $configured ) {
		$clean = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $configured ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( 3 !== count( $parts ) || ! self::valid_date( $parts[0] ) || ! in_array( sanitize_key( $parts[1] ), array( 'pickup', 'delivery', 'all' ), true ) ) {
				continue;
			}
			$periods = 'closed' === strtolower( $parts[2] ) ? 'closed' : self::clean_periods( $parts[2], '' );
			if ( $periods ) {
				$clean[] = $parts[0] . ' | ' . sanitize_key( $parts[1] ) . ' | ' . $periods;
			}
		}
		return implode( "\n", $clean );
	}

	private static function clean_periods( $configured, $fallback ) {
		$periods = array();
		foreach ( preg_split( '/\s*,\s*/', sanitize_text_field( (string) $configured ) ) as $period ) {
			if ( ! preg_match( '/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', trim( $period ), $match ) || ! self::valid_time( $match[1] ) || ! self::valid_time( $match[2] ) || $match[2] <= $match[1] ) {
				continue;
			}
			$periods[] = array( $match[1], $match[2] );
		}
		usort(
			$periods,
			function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);
		$clean = array();
		$end   = '';
		foreach ( $periods as $period ) {
			if ( $end && $period[0] < $end ) {
				continue;
			}
			$clean[] = implode( '-', $period );
			$end     = $period[1];
		}
		return $clean ? implode( ',', $clean ) : $fallback;
	}

	private static function valid_time( $value ) {
		return (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	private static function valid_date( $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return false;
		}
		return checkdate( (int) $match[2], (int) $match[3], (int) $match[1] );
	}
}
