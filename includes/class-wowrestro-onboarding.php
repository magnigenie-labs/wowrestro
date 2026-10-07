<?php
/**
 * Guided restaurant setup for new WowRestro installations.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

/** Own the clean-room, backend-only restaurant onboarding workflow. */
final class WowRestro_Onboarding {
	const PAGE            = 'wowrestro-onboarding';
	const PROFILE_OPTION  = 'wowrestro_restaurant_profile';
	const COMPLETE_OPTION = 'wowrestro_onboarding_complete';

	/** Register the backend-only onboarding flow. */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 30 );
		add_action( 'admin_post_wowrestro_save_onboarding', array( __CLASS__, 'save' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/** Send a first-time administrator to setup once, without trapping them there. */
	public static function maybe_redirect() {
		if ( 'yes' !== get_option( 'wowrestro_onboarding_pending', 'no' ) || ! current_user_can( WowRestro_Settings::CAPABILITY ) || wp_doing_ajax() ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) || isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only suppresses an optional redirect.
			return;
		}
		update_option( 'wowrestro_onboarding_pending', 'no', false );
		// The explicit step keeps the admin route adapter from sending this first visit to the workspace checklist.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=1' ) );
		exit;
	}

	/** Add a page-specific class so the wizard can use the full admin canvas. */
	public static function body_class( $classes ) {
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		return self::PAGE === $page ? $classes . ' wowrestro-onboarding-page' : $classes;
	}

	/** Render four guided setup steps followed by a completion screen. */
	public static function page() {
		if ( ! current_user_can( WowRestro_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to set up WowRestro.', 'wowrestro' ) );
		}
		$step     = self::requested_step();
		$profile  = self::profile();
		$settings = WowRestro_Fulfillment::settings();
		$titles   = array(
			1 => array( __( 'Set up your restaurant', 'wowrestro' ), __( 'Start with the business details WooCommerce needs for restaurant ordering.', 'wowrestro' ) ),
			2 => array( __( 'Set your weekly schedule', 'wowrestro' ), __( 'Tell WowRestro when the kitchen accepts orders and define the selectable time interval.', 'wowrestro' ) ),
			3 => array( __( 'Set up online food ordering', 'wowrestro' ), __( 'Choose fulfillment options and set the promises your kitchen can reliably keep.', 'wowrestro' ) ),
			4 => array( __( 'Connect with WooCommerce', 'wowrestro' ), __( 'Use WooCommerce products for the menu and create the page customers will order from.', 'wowrestro' ) ),
			5 => array( __( 'Congratulations!', 'wowrestro' ), __( 'WowRestro is configured for online food ordering. Finish the launch tasks below.', 'wowrestro' ) ),
		);
		?>
		<div class="wrap wowrestro-onboarding">
			<header class="wowrestro-onboarding__brand"><span class="dashicons dashicons-store" aria-hidden="true"></span><strong><?php esc_html_e( 'WowRestro', 'wowrestro' ); ?></strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WowRestro_Admin::PAGE ) ); ?>"><?php esc_html_e( 'Exit setup', 'wowrestro' ); ?></a></header>
			<main class="wowrestro-onboarding__card">
				<h1><?php echo esc_html( $titles[ $step ][0] ); ?></h1>
				<p class="wowrestro-onboarding__intro"><?php echo esc_html( $titles[ $step ][1] ); ?></p>
				<?php
				if ( $step < 5 ) :
					?>
					<div class="wowrestro-onboarding__progress-row"><div class="wowrestro-onboarding__progress" role="progressbar" aria-label="<?php esc_attr_e( 'Setup progress', 'wowrestro' ); ?>" aria-valuemin="1" aria-valuemax="4" aria-valuenow="<?php echo esc_attr( $step ); ?>"><span style="width:<?php echo esc_attr( $step * 25 ); ?>%"></span></div><p class="wowrestro-onboarding__count"><?php echo esc_html( sprintf( __( '%1$d/%2$d', 'wowrestro' ), $step, 4 ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence selects a fixed message. ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Complete the required fields before continuing.', 'wowrestro' ); ?></p></div>
				<?php endif; ?>
				<?php
				if ( 5 === $step ) :
					self::completion_step( $profile, $settings );
				else :
					?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wowrestro_save_onboarding">
					<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>">
					<?php wp_nonce_field( 'wowrestro_onboarding_step_' . $step ); ?>
					<?php
					switch ( $step ) {
						case 1:
							self::basics_step( $profile );
							break;
						case 2:
							self::operations_step( $settings );
							break;
						case 3:
							self::services_step( $settings );
							break;
						default:
							self::menu_step();
					}
					?>
					<div class="wowrestro-onboarding__actions">
						<?php
						if ( $step > 1 ) :
							?>
							<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=' . ( $step - 1 ) ) ); ?>"><?php esc_html_e( 'Back', 'wowrestro' ); ?></a><?php endif; ?>
						<button class="button button-primary button-large" type="submit"><?php esc_html_e( 'Continue', 'wowrestro' ); ?></button>
					</div>
				</form>
				<?php endif; ?>
			</main>
		</div>
		<?php
	}

	/** Validate and persist the active step. */
	public static function save() {
		if ( ! current_user_can( WowRestro_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to set up WowRestro.', 'wowrestro' ) );
		}
		$step = isset( $_POST['step'] ) && is_scalar( $_POST['step'] ) ? min( 4, max( 1, absint( $_POST['step'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The step selects the nonce action verified immediately below.
		check_admin_referer( 'wowrestro_onboarding_step_' . $step );
		$valid = true;
		if ( 1 === $step ) {
			$valid = self::save_basics();
		} elseif ( 2 === $step ) {
			self::save_operations();
		} elseif ( 3 === $step ) {
			$valid = self::save_services();
		} else {
			self::save_menu();
			update_option( self::COMPLETE_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
			update_option( 'wowrestro_onboarding_pending', 'no', false );
		}
		self::redirect_step( $valid ? $step + 1 : $step, ! $valid );
	}

	/** Return normalized restaurant profile data. */
	public static function profile() {
		$stored          = (array) get_option( self::PROFILE_OPTION, array() );
		$default_country = explode( ':', (string) get_option( 'woocommerce_default_country', '' ), 2 );
		$logo_id         = absint( $stored['logo_id'] ?? 0 );
		if ( ! $logo_id ) {
			$logo_id = absint( get_theme_mod( 'custom_logo', 0 ) );
		}
		if ( ! $logo_id ) {
			$logo_id = absint( get_option( 'site_icon', 0 ) );
		}
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		$logo_url = $logo_url ? $logo_url : add_query_arg( 'ver', WOWRESTRO_VERSION, WOWRESTRO_URL . 'assets/images/wowrestro-logo.png' );
		return array(
			'name'      => html_entity_decode( (string) ( $stored['name'] ?? get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'logo_id'   => $logo_id,
			'logo_url'  => esc_url_raw( $logo_url ),
			'address_1' => (string) get_option( 'woocommerce_store_address', $stored['address_1'] ?? '' ),
			'address_2' => (string) get_option( 'woocommerce_store_address_2', $stored['address_2'] ?? '' ),
			'city'      => (string) get_option( 'woocommerce_store_city', $stored['city'] ?? '' ),
			'postcode'  => (string) get_option( 'woocommerce_store_postcode', $stored['postcode'] ?? '' ),
			'country'   => (string) ( $default_country[0] ?? ( $stored['country'] ?? '' ) ),
			'state'     => (string) ( $default_country[1] ?? ( $stored['state'] ?? '' ) ),
			'email'     => (string) ( $stored['email'] ?? get_option( 'admin_email', '' ) ),
			'phone'     => (string) get_option( 'woocommerce_store_phone', $stored['phone'] ?? '' ),
		);
	}

	/**
	 * Save the restaurant profile and keep WooCommerce store details authoritative.
	 *
	 * @param array $profile Sanitized restaurant profile.
	 */
	public static function save_profile( $profile ) {
		update_option( self::PROFILE_OPTION, $profile, false );
		update_option( 'woocommerce_store_address', $profile['address_1'] ?? '', false );
		update_option( 'woocommerce_store_address_2', $profile['address_2'] ?? '', false );
		update_option( 'woocommerce_store_city', $profile['city'] ?? '', false );
		update_option( 'woocommerce_store_postcode', $profile['postcode'] ?? '', false );
		$country = (string) ( $profile['country'] ?? '' );
		$state   = (string) ( $profile['state'] ?? '' );
		update_option( 'woocommerce_default_country', $country . ( $state ? ':' . $state : '' ), false );
		update_option( 'woocommerce_store_phone', $profile['phone'] ?? '', false );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- save() verifies the step nonce before calling these private persistence helpers.
	/** Save restaurant identity and mirror structured address data to WooCommerce. */
	private static function save_basics() {
		$profile = array(
			'name'      => self::posted_text( 'name' ),
			'address_1' => self::posted_text( 'address_1' ),
			'address_2' => self::posted_text( 'address_2' ),
			'city'      => self::posted_text( 'city' ),
			'postcode'  => self::posted_text( 'postcode' ),
			'country'   => strtoupper( substr( self::posted_text( 'country' ), 0, 2 ) ),
			'state'     => strtoupper( substr( self::posted_text( 'state' ), 0, 8 ) ),
			'email'     => isset( $_POST['email'] ) && is_scalar( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '',
			'phone'     => self::posted_text( 'phone' ),
		);
		if ( ! $profile['name'] || ! $profile['address_1'] || ! $profile['city'] || ! $profile['postcode'] || ! $profile['country'] ) {
			return false;
		}
		self::save_profile( $profile );
		return true;
	}

	/** Save supported fulfillment choices without inventing a second shipping system. */
	private static function save_services() {
		$pickup   = isset( $_POST['pickup_enabled'] );
		$delivery = isset( $_POST['delivery_enabled'] );
		if ( ! $pickup && ! $delivery ) {
			return false;
		}
		$settings                          = get_option( 'wowrestro_settings', array() );
		$settings                          = is_array( $settings ) ? $settings : array();
		$settings['pickup_enabled']        = $pickup ? 'yes' : 'no';
		$settings['delivery_enabled']      = $delivery ? 'yes' : 'no';
		$settings['asap_enabled']          = isset( $_POST['asap_enabled'] ) ? 'yes' : 'no';
		$settings['tips_enabled']          = isset( $_POST['tips_enabled'] ) ? 'yes' : 'no';
		$settings['prep_minutes']          = min( 240, max( 5, self::posted_int( 'prep_minutes', 30 ) ) );
		$settings['delivery_lead_minutes'] = min( 240, self::posted_int( 'delivery_lead_minutes', 15 ) );
		$settings['slot_capacity']         = min( 500, max( 1, self::posted_int( 'slot_capacity', 8 ) ) );
		$settings['preorder_days']         = min( 90, self::posted_int( 'preorder_days', 7 ) );
		update_option( 'wowrestro_settings', $settings, false );
		return true;
	}

	/** Save the shared kitchen schedule and capacity controls. */
	private static function save_operations() {
		$settings                  = get_option( 'wowrestro_settings', array() );
		$settings                  = is_array( $settings ) ? $settings : array();
		$settings['slot_interval'] = min( 120, max( 5, self::posted_int( 'slot_interval', 15 ) ) );
		$days                      = isset( $_POST['days'] ) && is_array( $_POST['days'] ) ? wp_unslash( $_POST['days'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each supported day and period is validated below.
		foreach ( range( 1, 7 ) as $day ) {
			$enabled = isset( $days[ $day ]['enabled'] ) && 'yes' === $days[ $day ]['enabled'] ? 'yes' : 'no';
			$periods = self::clean_periods( $days[ $day ]['periods'] ?? '11:00-22:00' );
			foreach ( array( WowRestro_Fulfillment::MODE_PICKUP, WowRestro_Fulfillment::MODE_DELIVERY ) as $mode ) {
				$settings['service_hours'][ $mode ][ $day ] = array(
					'enabled' => $enabled,
					'periods' => $periods,
				);
			}
			$parts                            = explode( '-', explode( ',', $periods )[0], 2 );
			$settings['weekly_hours'][ $day ] = array(
				'enabled' => $enabled,
				'open'    => $parts[0],
				'close'   => $parts[1],
			);
		}
		$settings['open_time']  = $settings['weekly_hours'][1]['open'];
		$settings['close_time'] = $settings['weekly_hours'][1]['close'];
		update_option( 'wowrestro_settings', $settings, false );
	}

	/** Add selected native WooCommerce products and optionally create the order page. */
	private static function save_menu() {
		$product_ids = array();
		if ( isset( $_POST['menu_items'] ) && is_array( $_POST['menu_items'] ) ) {
			foreach ( wp_unslash( $_POST['menu_items'] ) as $product_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only scalar IDs survive the following guard and absint().
				if ( is_scalar( $product_id ) ) {
					$product_ids[] = absint( $product_id );
				}
			}
		}
		$product_ids = array_slice( array_unique( array_filter( $product_ids ) ), 0, 200 );
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product && in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) {
				$product->update_meta_data( WowRestro_Products::MENU_ITEM_META, 'yes' );
				$product->save();
			}
		}
		if ( isset( $_POST['create_menu_page'] ) ) {
			self::ensure_menu_page();
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/** Create one shortcode-backed ordering page and reuse it on later runs. */
	private static function ensure_menu_page() {
		$page_id = absint( get_option( 'wowrestro_menu_page_id', 0 ) );
		if ( $page_id && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id;
		}
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Order Online', 'wowrestro' ),
				'post_content' => '[wowrestro_menu]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'wowrestro_menu_page_id', absint( $page_id ), false );
		}
		return $page_id;
	}

	/** First step fields. */
	private static function basics_step( $profile ) {
		$countries = class_exists( 'WC_Countries' ) ? ( new WC_Countries() )->get_countries() : array();
		?>
		<fieldset><legend><?php esc_html_e( 'Restaurant type', 'wowrestro' ); ?> <span class="required">*</span></legend><div class="wowrestro-onboarding__choices">
			<label class="is-selected"><input type="radio" name="restaurant_type" value="food_ordering" checked><span class="dashicons dashicons-food"></span><strong><?php esc_html_e( 'Food ordering', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Take WooCommerce orders for pickup or delivery.', 'wowrestro' ); ?></small></label>
			<div><span class="dashicons dashicons-calendar-alt"></span><strong><?php esc_html_e( 'Reservations', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Add the reservation shortcode to any page after setup.', 'wowrestro' ); ?></small></div>
		</div></fieldset>
		<label><?php esc_html_e( 'Restaurant name', 'wowrestro' ); ?> <span class="required">*</span><input class="large-text" required name="name" value="<?php echo esc_attr( $profile['name'] ); ?>"></label>
		<label><?php esc_html_e( 'Street address', 'wowrestro' ); ?> <span class="required">*</span><input class="large-text" required name="address_1" value="<?php echo esc_attr( $profile['address_1'] ); ?>"></label>
		<label><?php esc_html_e( 'Address line 2', 'wowrestro' ); ?><input class="large-text" name="address_2" value="<?php echo esc_attr( $profile['address_2'] ); ?>"></label>
		<div class="wowrestro-onboarding__fields"><label><?php esc_html_e( 'City', 'wowrestro' ); ?> <span class="required">*</span><input required name="city" value="<?php echo esc_attr( $profile['city'] ); ?>"></label><label><?php esc_html_e( 'Postal code', 'wowrestro' ); ?> <span class="required">*</span><input required name="postcode" value="<?php echo esc_attr( $profile['postcode'] ); ?>"></label></div>
		<div class="wowrestro-onboarding__fields"><label><?php esc_html_e( 'Country', 'wowrestro' ); ?> <span class="required">*</span><select required name="country"><option value=""><?php esc_html_e( 'Choose a country', 'wowrestro' ); ?></option>
		<?php
		foreach ( $countries as $code => $name ) :
			?>
			<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $profile['country'], $code ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'State / region code', 'wowrestro' ); ?><input name="state" value="<?php echo esc_attr( $profile['state'] ); ?>"></label></div>
		<div class="wowrestro-onboarding__fields"><label><?php esc_html_e( 'Order email', 'wowrestro' ); ?><input type="email" name="email" value="<?php echo esc_attr( $profile['email'] ); ?>"></label><label><?php esc_html_e( 'Contact number', 'wowrestro' ); ?><input type="tel" name="phone" value="<?php echo esc_attr( $profile['phone'] ); ?>"></label></div>
		<?php
	}

	/** Fulfillment step fields. */
	private static function services_step( $settings ) {
		?>
		<div class="wowrestro-onboarding__feature"><span class="dashicons dashicons-cart"></span><span><strong><?php esc_html_e( 'Online ordering', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'The WowRestro menu, cart validation and WooCommerce checkout are active.', 'wowrestro' ); ?></small></span><span class="wowrestro-badge is-menu"><?php esc_html_e( 'Included', 'wowrestro' ); ?></span></div>
		<fieldset><legend><?php esc_html_e( 'Order fulfillment', 'wowrestro' ); ?> <span class="required">*</span></legend><div class="wowrestro-onboarding__choices">
			<label><input type="checkbox" name="pickup_enabled" <?php checked( 'yes', $settings['pickup_enabled'] ); ?>><span class="dashicons dashicons-store"></span><strong><?php esc_html_e( 'Pickup', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Customers collect orders from the restaurant.', 'wowrestro' ); ?></small></label>
			<label><input type="checkbox" name="delivery_enabled" <?php checked( 'yes', $settings['delivery_enabled'] ); ?>><span class="dashicons dashicons-location-alt"></span><strong><?php esc_html_e( 'Delivery', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'WooCommerce shipping zones decide coverage and price.', 'wowrestro' ); ?></small></label>
		</div></fieldset>
		<div class="wowrestro-onboarding__toggles"><label><input type="checkbox" name="asap_enabled" <?php checked( 'yes', $settings['asap_enabled'] ); ?>> <span><strong><?php esc_html_e( 'Offer ASAP ordering', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'WowRestro finds the next available kitchen slot.', 'wowrestro' ); ?></small></span></label><label><input type="checkbox" name="tips_enabled" <?php checked( 'yes', $settings['tips_enabled'] ); ?>> <span><strong><?php esc_html_e( 'Enable tips', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Use the configured 10%, 15%, 20% and custom tip options.', 'wowrestro' ); ?></small></span></label></div>
		<h2><?php esc_html_e( 'Kitchen promises', 'wowrestro' ); ?></h2>
		<div class="wowrestro-onboarding__fields wowrestro-onboarding__fields--three"><label><?php esc_html_e( 'Preparation', 'wowrestro' ); ?><input type="number" min="5" max="240" name="prep_minutes" value="<?php echo esc_attr( $settings['prep_minutes'] ); ?>"><small><?php esc_html_e( 'Minutes', 'wowrestro' ); ?></small></label><label><?php esc_html_e( 'Slot capacity', 'wowrestro' ); ?><input type="number" min="1" max="500" name="slot_capacity" value="<?php echo esc_attr( $settings['slot_capacity'] ); ?>"><small><?php esc_html_e( 'Orders per shared slot', 'wowrestro' ); ?></small></label><label><?php esc_html_e( 'Preorder window', 'wowrestro' ); ?><input type="number" min="0" max="90" name="preorder_days" value="<?php echo esc_attr( $settings['preorder_days'] ); ?>"><small><?php esc_html_e( 'Days ahead', 'wowrestro' ); ?></small></label></div>
		<label><?php esc_html_e( 'Delivery lead time', 'wowrestro' ); ?><input type="number" min="0" max="240" name="delivery_lead_minutes" value="<?php echo esc_attr( $settings['delivery_lead_minutes'] ); ?>"><small><?php esc_html_e( 'Minutes after kitchen-ready time', 'wowrestro' ); ?></small></label>
		<?php
	}

	/** Schedule and capacity step fields. */
	private static function operations_step( $settings ) {
		$days = array(
			1 => __( 'Monday', 'wowrestro' ),
			2 => __( 'Tuesday', 'wowrestro' ),
			3 => __( 'Wednesday', 'wowrestro' ),
			4 => __( 'Thursday', 'wowrestro' ),
			5 => __( 'Friday', 'wowrestro' ),
			6 => __( 'Saturday', 'wowrestro' ),
			7 => __( 'Sunday', 'wowrestro' ),
		);
		?>
		<label><?php esc_html_e( 'Time interval', 'wowrestro' ); ?><select name="slot_interval">
		<?php
		foreach ( array( 10, 15, 20, 30, 45, 60 ) as $minutes ) :
			?>
			<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( absint( $settings['slot_interval'] ), $minutes ); ?>><?php echo esc_html( sprintf( __( '%d minutes', 'wowrestro' ), $minutes ) ); ?></option><?php endforeach; ?></select><small><?php esc_html_e( 'Controls pickup and delivery slot spacing.', 'wowrestro' ); ?></small></label>
		<h2><?php esc_html_e( 'Weekly opening hours', 'wowrestro' ); ?></h2><p><?php esc_html_e( 'Use comma-separated periods for split shifts, for example 11:00-14:00,17:00-22:00.', 'wowrestro' ); ?></p>
		<div class="wowrestro-onboarding__hours">
		<?php
		foreach ( $days as $day => $label ) :
			$row = $settings['service_hours'][ WowRestro_Fulfillment::MODE_PICKUP ][ $day ];
			?>
			<label><input type="hidden" name="days[<?php echo esc_attr( $day ); ?>][enabled]" value="no"><input type="checkbox" name="days[<?php echo esc_attr( $day ); ?>][enabled]" value="yes" <?php checked( 'yes', $row['enabled'] ); ?>><strong><?php echo esc_html( $label ); ?></strong><input aria-label="<?php echo esc_attr( sprintf( __( '%s opening hours', 'wowrestro' ), $label ) ); ?>" name="days[<?php echo esc_attr( $day ); ?>][periods]" value="<?php echo esc_attr( $row['periods'] ); ?>"></label><?php endforeach; ?></div>
		<?php
	}

	/** Product selection step fields. */
	private static function menu_step() {
		$products = wc_get_products(
			array(
				'limit'   => 100,
				'status'  => array( 'publish', 'draft', 'private' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		?>
		<div class="wowrestro-onboarding__feature"><span class="dashicons dashicons-yes-alt"></span><span><strong><?php esc_html_e( 'WooCommerce is connected', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Products, variations, stock, taxes, shipping, payments and orders remain native WooCommerce data.', 'wowrestro' ); ?></small></span><span class="wowrestro-badge is-menu"><?php esc_html_e( 'Active', 'wowrestro' ); ?></span></div>
		<h2><?php esc_html_e( 'Choose restaurant products', 'wowrestro' ); ?></h2>
		<?php
		if ( $products ) :
			?>
			<div class="wowrestro-onboarding__products">
			<?php
			foreach ( $products as $product ) :
				$status = get_post_status_object( $product->get_status() );
				?>
			<label><input type="checkbox" name="menu_items[]" value="<?php echo esc_attr( $product->get_id() ); ?>" <?php checked( WowRestro_Products::is_menu_item( $product ) ); ?>><span><strong><?php echo esc_html( $product->get_name() ); ?></strong><small><?php echo wp_kses_post( $product->get_price_html() ? $product->get_price_html() : __( 'Price not set', 'wowrestro' ) ); ?> · <?php echo esc_html( $status ? $status->label : $product->get_status() ); ?></small></span></label><?php endforeach; ?></div>
			<?php
else :
	?>
	<div class="wowrestro-onboarding__empty"><span class="dashicons dashicons-products"></span><h2><?php esc_html_e( 'No WooCommerce products yet', 'wowrestro' ); ?></h2><p><?php esc_html_e( 'Finish the wizard, then add dishes using the native product editor.', 'wowrestro' ); ?></p></div><?php endif; ?>
		<label class="wowrestro-onboarding__menu-page"><input type="checkbox" name="create_menu_page" <?php checked( ! get_option( 'wowrestro_menu_page_id' ) ); ?>> <span><strong><?php esc_html_e( 'Create an Order Online page', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Publishes one page using the existing WowRestro Menu shortcode.', 'wowrestro' ); ?></small></span></label>
		<p><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product&wowrestro_new=1' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Add a product in a new tab', 'wowrestro' ); ?></a></p>
		<?php
	}

	/** Show the clean-room completion state and native next steps. */
	private static function completion_step( $profile, $settings ) {
		$menu    = wc_get_products(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'paginate'   => true,
				'meta_key'   => WowRestro_Products::MENU_ITEM_META,
				'meta_value' => 'yes',
			)
		);
		$count   = is_object( $menu ) ? absint( $menu->total ) : count( (array) $menu );
		$rates   = WowRestro_Shipping::configured_rates();
		$page_id = absint( get_option( 'wowrestro_menu_page_id', 0 ) );
		?>
		<div class="wowrestro-onboarding__complete-icon"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></div>
		<p class="wowrestro-onboarding__success"><?php echo esc_html( sprintf( __( '%s is ready for the final launch checks.', 'wowrestro' ), $profile['name'] ) ); ?></p>
		<div class="wowrestro-onboarding__review"><div class="is-ready"><span class="dashicons dashicons-yes-alt"></span><span><strong><?php esc_html_e( 'WooCommerce connected', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Native products, checkout and orders are available.', 'wowrestro' ); ?></small></span></div><div class="<?php echo $count ? 'is-ready' : 'needs-attention'; ?>"><span class="dashicons <?php echo esc_attr( $count ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span><span><strong><?php esc_html_e( 'Food menu', 'wowrestro' ); ?></strong><small><?php echo esc_html( sprintf( _n( '%d restaurant product selected', '%d restaurant products selected', $count, 'wowrestro' ), $count ) ); ?></small></span></div><div class="<?php echo 'yes' !== $settings['delivery_enabled'] || $rates ? 'is-ready' : 'needs-attention'; ?>"><span class="dashicons <?php echo esc_attr( 'yes' !== $settings['delivery_enabled'] || $rates ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span><span><strong><?php esc_html_e( 'Delivery shipping', 'wowrestro' ); ?></strong><small><?php echo esc_html( $rates ? __( 'WooCommerce shipping rate found.', 'wowrestro' ) : __( 'Add a shipping-zone rate before testing delivery.', 'wowrestro' ) ); ?></small></span></div></div>
		<h2><?php esc_html_e( 'Next steps to get started', 'wowrestro' ); ?></h2>
		<div class="wowrestro-onboarding__next-steps">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&wowrestro_menu_item=yes' ) ); ?>"><span class="dashicons dashicons-food"></span><span><strong><?php esc_html_e( 'Add or edit food items', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Set prices, photos, variations and stock.', 'wowrestro' ); ?></small></span></a>
			<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ); ?>"><span class="dashicons dashicons-category"></span><span><strong><?php esc_html_e( 'Create menu categories', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Organize dishes such as pizza, pasta or drinks.', 'wowrestro' ); ?></small></span></a>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . WowRestro_Modifiers::POST_TYPE ) ); ?>"><span class="dashicons dashicons-list-view"></span><span><strong><?php esc_html_e( 'Configure add-on groups', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'Add sizes, toppings and extras.', 'wowrestro' ); ?></small></span></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>"><span class="dashicons dashicons-location"></span><span><strong><?php esc_html_e( 'Review shipping zones', 'wowrestro' ); ?></strong><small><?php esc_html_e( 'WooCommerce controls pickup and delivery rates.', 'wowrestro' ); ?></small></span></a>
		</div>
		<div class="wowrestro-onboarding__actions"><a class="button" href="<?php echo esc_url( $page_id && get_permalink( $page_id ) ? get_permalink( $page_id ) : admin_url( 'admin.php?page=wowrestro-setup' ) ); ?>"><?php echo esc_html( $page_id ? __( 'View Order Online page', 'wowrestro' ) : __( 'Open advanced setup', 'wowrestro' ) ); ?></a><a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=' . WowRestro_Admin::PAGE . '&onboarding=complete' ) ); ?>"><?php esc_html_e( 'Go to dashboard', 'wowrestro' ); ?></a></div>
		<?php
	}

	/** Keep wizard steps inside their known range. */
	private static function requested_step() {
		return isset( $_GET['step'] ) ? min( 5, max( 1, absint( $_GET['step'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- These readers are called only from save() after nonce verification.
	private static function posted_text( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/** Read a bounded numeric form scalar without allowing array coercion. */
	private static function posted_int( $key, $default ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : absint( $default );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	private static function clean_periods( $value ) {
		$clean = array();
		$value = is_scalar( $value ) ? $value : '';
		foreach ( preg_split( '/\s*,\s*/', sanitize_text_field( (string) $value ) ) as $period ) {
			if ( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d$/', $period ) ) {
				$parts = explode( '-', $period, 2 );
				if ( $parts[1] > $parts[0] ) {
					$clean[] = $period;
				}
			}
		}
		return $clean ? implode( ',', array_slice( $clean, 0, 3 ) ) : '11:00-22:00';
	}

	private static function redirect_step( $step, $error = false ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE . '&step=' . min( 5, max( 1, absint( $step ) ) ) );
		wp_safe_redirect( $error ? add_query_arg( 'error', 'required', $url ) : $url );
		exit;
	}
}
