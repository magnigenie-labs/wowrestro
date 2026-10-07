<?php
/**
 * Table reservations with native WordPress records and capacity-aware slots.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Reservations {
	const POST_TYPE      = 'wowrestro_booking';
	const NONCE          = 'wowrestro_reservation';
	const SETTINGS_OPTION = 'wowrestro_reservation_settings';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_post_wowrestro_reservation', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_nopriv_wowrestro_reservation', array( __CLASS__, 'submit' ) );
		add_shortcode( 'wowrestro_reservations', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_on_page' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'sync_payment_status' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'sync_payment_status' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/** Return normalized reservation rules and form customization. */
	public static function settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		return self::sanitize_settings( is_array( $stored ) ? $stored : array() );
	}

	/** Persist reservation rules from the WowRestro workspace. */
	public static function save_settings( $input ) {
		$clean = self::sanitize_settings( is_array( $input ) ? $input : array() );
		update_option( self::SETTINGS_OPTION, $clean, false );
		return $clean;
	}

	/** Safe reservation defaults used by both admin and storefront. */
	private static function default_settings() {
		return array(
			'advance_minutes'      => 30,
			'future_days'          => 730,
			'default_status'       => 'pending',
			'blocking_statuses'    => array( 'pending', 'confirmed' ),
			'min_guests'           => 1,
			'max_guests'           => 100,
			'seat_capacity'        => 100,
			'interval'             => 30,
			'booking_amount'       => '0',
			'booking_per_guest'    => 'no',
			'local_payment'        => 'yes',
			'woocommerce_payment'  => 'yes',
			'pending_message_enabled'   => 'yes',
			'confirmed_message_enabled' => 'yes',
			'pending_message'      => __( 'Thanks! We received your reservation request and will confirm it shortly.', 'wowrestro' ),
			'confirmed_message'    => __( 'Your table is confirmed. We look forward to seeing you!', 'wowrestro' ),
			'button_label'         => __( 'Book a table', 'wowrestro' ),
			'confirmation_label'   => __( 'Confirm Booking', 'wowrestro' ),
			'cancellation_label'   => __( 'Request Cancellation', 'wowrestro' ),
			'fields'               => array(
				'location' => array( 'label' => __( 'Restaurant location', 'wowrestro' ), 'placeholder' => __( 'Choose a location', 'wowrestro' ), 'visible' => 'yes', 'required' => 'no' ),
				'date'     => array( 'label' => __( 'Date', 'wowrestro' ), 'placeholder' => '', 'visible' => 'yes', 'required' => 'yes' ),
				'time'     => array( 'label' => __( 'Time', 'wowrestro' ), 'placeholder' => __( 'Choose a time', 'wowrestro' ), 'visible' => 'yes', 'required' => 'yes' ),
				'name'     => array( 'label' => __( 'Name', 'wowrestro' ), 'placeholder' => __( 'Your name', 'wowrestro' ), 'visible' => 'yes', 'required' => 'yes' ),
				'email'    => array( 'label' => __( 'Email', 'wowrestro' ), 'placeholder' => __( 'you@example.com', 'wowrestro' ), 'visible' => 'yes', 'required' => 'yes' ),
				'phone'    => array( 'label' => __( 'Phone', 'wowrestro' ), 'placeholder' => '', 'visible' => 'yes', 'required' => 'no' ),
				'guests'   => array( 'label' => __( 'Guests', 'wowrestro' ), 'placeholder' => '', 'visible' => 'yes', 'required' => 'yes' ),
				'notes'    => array( 'label' => __( 'Additional information', 'wowrestro' ), 'placeholder' => '', 'visible' => 'yes', 'required' => 'no' ),
			),
			'custom_fields'        => array(),
		);
	}

	/** Sanitize rules and cap customizable fields to a predictable shape. */
	private static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$input    = wp_parse_args( $input, $defaults );
		$clean    = $defaults;
		$clean['advance_minutes'] = min( 10080, absint( $input['advance_minutes'] ) );
		$clean['future_days'] = min( 730, max( 1, absint( $input['future_days'] ) ) );
		$clean['min_guests'] = min( 100, max( 1, absint( $input['min_guests'] ) ) );
		$clean['max_guests'] = min( 500, max( $clean['min_guests'], absint( $input['max_guests'] ) ) );
		$clean['seat_capacity'] = min( 1000, max( $clean['max_guests'], absint( $input['seat_capacity'] ) ) );
		$clean['interval'] = min( 120, max( 5, absint( $input['interval'] ) ) );
		$clean['booking_amount'] = wc_format_decimal( $input['booking_amount'] ?? 0 );
		foreach ( array( 'booking_per_guest', 'local_payment', 'woocommerce_payment', 'pending_message_enabled', 'confirmed_message_enabled' ) as $key ) {
			$clean[ $key ] = self::enabled( $input[ $key ] ?? false ) ? 'yes' : 'no';
		}
		$status = sanitize_key( $input['default_status'] ?? 'pending' );
		$clean['default_status'] = in_array( $status, array( 'pending', 'confirmed' ), true ) ? $status : 'pending';
		$blocking = isset( $input['blocking_statuses'] ) && is_array( $input['blocking_statuses'] ) ? array_map( 'sanitize_key', $input['blocking_statuses'] ) : $defaults['blocking_statuses'];
		$clean['blocking_statuses'] = array_values( array_intersect( array_keys( self::statuses() ), array_unique( $blocking ) ) );
		foreach ( array( 'pending_message', 'confirmed_message', 'button_label', 'confirmation_label', 'cancellation_label' ) as $key ) {
			$clean[ $key ] = sanitize_text_field( $input[ $key ] ?? $defaults[ $key ] );
		}
		$posted_fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		foreach ( $defaults['fields'] as $key => $field ) {
			$posted = isset( $posted_fields[ $key ] ) && is_array( $posted_fields[ $key ] ) ? $posted_fields[ $key ] : array();
			$clean['fields'][ $key ] = array(
				'label'       => sanitize_text_field( $posted['label'] ?? $field['label'] ),
				'placeholder' => sanitize_text_field( $posted['placeholder'] ?? $field['placeholder'] ),
				'visible'     => in_array( $key, array( 'date', 'time' ), true ) || self::enabled( $posted['visible'] ?? $field['visible'] ) ? 'yes' : 'no',
				'required'    => in_array( $key, array( 'date', 'time' ), true ) || self::enabled( $posted['required'] ?? $field['required'] ) ? 'yes' : 'no',
			);
		}
		$clean['custom_fields'] = array();
		$types = array( 'text', 'select', 'textarea', 'radio', 'checkbox' );
		$posted_custom = isset( $input['custom_fields'] ) && is_array( $input['custom_fields'] ) ? array_slice( $input['custom_fields'], 0, 20 ) : array();
		foreach ( $posted_custom as $index => $field ) {
			if ( ! is_array( $field ) || ! sanitize_text_field( $field['label'] ?? '' ) ) {
				continue;
			}
			$type    = sanitize_key( $field['type'] ?? 'text' );
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : preg_split( '/\s*,\s*/', (string) ( $field['options'] ?? '' ) );
			$options = array_values( array_filter( array_map( 'sanitize_text_field', array_slice( $options, 0, 30 ) ) ) );
			$clean['custom_fields'][] = array(
				'key'         => 'custom_' . ( $index + 1 ) . '_' . sanitize_key( $field['label'] ),
				'type'        => in_array( $type, $types, true ) ? $type : 'text',
				'label'       => sanitize_text_field( $field['label'] ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'options'     => $options,
				'required'    => self::enabled( $field['required'] ?? false ) ? 'yes' : 'no',
			);
		}
		return $clean;
	}

	/** Interpret admin checkbox values consistently. */
	private static function enabled( $value ) {
		return true === $value || 1 === $value || in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'true', 'yes' ), true );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Reservations', 'wowrestro' ),
					'singular_name' => __( 'Reservation', 'wowrestro' ),
					'add_new_item'  => __( 'Add reservation', 'wowrestro' ),
					'edit_item'     => __( 'Edit reservation', 'wowrestro' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				// Bookings carry guest names and times; wp/v2 would list published ones to anyone.
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
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
			)
		);
		foreach ( array( 'name', 'email', 'phone', 'date', 'time', 'status', 'location', 'notes', 'payment', 'custom_fields' ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				'_wowrestro_' . $key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_edit' ),
				)
			);
		}
		register_post_meta(
			self::POST_TYPE,
			'_wowrestro_guests',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			'_wowrestro_order_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			)
		);
	}

	public static function can_edit() {
		return current_user_can( 'wowrestro_manage_operations' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function meta_box() {
		add_meta_box( 'wowrestro-reservation', __( 'Reservation details', 'wowrestro' ), array( __CLASS__, 'fields' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function fields( $post ) {
		wp_nonce_field( self::NONCE, 'wowrestro_reservation_nonce' );
		$settings = self::settings();
		$fields = array(
			'name'     => array( __( 'Guest name', 'wowrestro' ), 'text' ),
			'email'    => array( __( 'Email', 'wowrestro' ), 'email' ),
			'phone'    => array( __( 'Phone', 'wowrestro' ), 'tel' ),
			'guests'   => array( __( 'Guests', 'wowrestro' ), 'number' ),
			'date'     => array( __( 'Date', 'wowrestro' ), 'date' ),
			'time'     => array( __( 'Time', 'wowrestro' ), 'time' ),
			'location' => array( __( 'Location', 'wowrestro' ), 'text' ),
			'notes'    => array( __( 'Notes', 'wowrestro' ), 'text' ),
		);
		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $field ) {
			$value = get_post_meta( $post->ID, '_wowrestro_' . $key, true );
			echo '<tr><th><label for="wr-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td><input class="regular-text" id="wr-' . esc_attr( $key ) . '" name="wowrestro_reservation[' . esc_attr( $key ) . ']" type="' . esc_attr( $field[1] ) . '" value="' . esc_attr( $value ) . '"' . ( 'guests' === $key ? ' min="' . esc_attr( $settings['min_guests'] ) . '" max="' . esc_attr( $settings['max_guests'] ) . '"' : '' ) . '></td></tr>';
		}
		$status = get_post_meta( $post->ID, '_wowrestro_status', true );
		$status = $status ? $status : 'pending';
		echo '<tr><th><label for="wr-status">' . esc_html__( 'Status', 'wowrestro' ) . '</label></th><td><select id="wr-status" name="wowrestro_reservation[status]">';
		foreach ( self::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		$custom = json_decode( (string) get_post_meta( $post->ID, '_wowrestro_custom_fields', true ), true );
		foreach ( is_array( $custom ) ? $custom : array() as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function save( $post_id, $post ) {
		unset( $post );
		if ( wp_is_post_revision( $post_id ) || ! isset( $_POST['wowrestro_reservation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wowrestro_reservation_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input  = isset( $_POST['wowrestro_reservation'] ) && is_array( $_POST['wowrestro_reservation'] ) ? wc_clean( wp_unslash( $_POST['wowrestro_reservation'] ) ) : array();
		$before = get_post_meta( $post_id, '_wowrestro_status', true );
		self::save_meta( $post_id, $input );
		$after = get_post_meta( $post_id, '_wowrestro_status', true );
		if ( $before && $after !== $before ) {
			self::email( $post_id, true );
		}
	}

	private static function save_meta( $post_id, $input ) {
		$settings = self::settings();
		foreach ( array( 'name', 'phone', 'location', 'notes' ) as $key ) {
			update_post_meta( $post_id, '_wowrestro_' . $key, sanitize_text_field( $input[ $key ] ?? '' ) );
		}
		update_post_meta( $post_id, '_wowrestro_email', sanitize_email( $input['email'] ?? '' ) );
		update_post_meta( $post_id, '_wowrestro_guests', min( $settings['max_guests'], max( $settings['min_guests'], absint( $input['guests'] ?? $settings['min_guests'] ) ) ) );
		update_post_meta( $post_id, '_wowrestro_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $input['date'] ?? '' ) ) ? $input['date'] : '' );
		update_post_meta( $post_id, '_wowrestro_time', preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) ( $input['time'] ?? '' ) ) ? $input['time'] : '' );
		update_post_meta( $post_id, '_wowrestro_end_time', preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) ( $input['end_time'] ?? '' ) ) ? $input['end_time'] : '' );
		$status = sanitize_key( $input['status'] ?? 'pending' );
		update_post_meta( $post_id, '_wowrestro_status', isset( self::statuses()[ $status ] ) ? $status : 'pending' );
	}

	/**
	 * Create or update a reservation from the WowRestro workspace.
	 *
	 * @param int   $post_id Existing reservation ID, or zero to create one.
	 * @param array $input   Workspace reservation fields.
	 * @return int|WP_Error
	 */
	public static function save_workspace( $post_id, $input ) {
		$current = array();
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
				return new WP_Error( 'wowrestro_reservation_missing', __( 'Reservation not found.', 'wowrestro' ), array( 'status' => 404 ) );
			}
			foreach ( array( 'name', 'email', 'phone', 'guests', 'date', 'time', 'end_time', 'status', 'location', 'notes' ) as $key ) {
				$current[ $key ] = get_post_meta( $post_id, '_wowrestro_' . $key, true );
			}
		}
		$input = wp_parse_args( $input, $current );
		$name  = sanitize_text_field( $input['name'] ?? '' );
		$email = sanitize_email( $input['email'] ?? '' );
		$phone = sanitize_text_field( $input['phone'] ?? '' );
		$date  = sanitize_text_field( $input['date'] ?? '' );
		$time  = sanitize_text_field( $input['time'] ?? '' );
		$end_time = sanitize_text_field( $input['end_time'] ?? '' );
		$guests = absint( $input['guests'] ?? 0 );
		$settings = self::settings();
		if ( ! $end_time && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$end_time = gmdate( 'H:i', strtotime( '1970-01-01 ' . $time . ' UTC +' . absint( $settings['interval'] ) . ' minutes' ) );
		}
		if ( ! $name || ! is_email( $email ) || ! $phone || $guests < $settings['min_guests'] || $guests > $settings['max_guests'] || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end_time ) || $end_time <= $time ) {
			return new WP_Error( 'wowrestro_reservation_invalid', __( 'Complete the guest details and enter a valid reservation date, start time and end time.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$status = sanitize_key( $input['status'] ?? $settings['default_status'] );
		$location = sanitize_text_field( $input['location'] ?? '' );
		if ( in_array( $status, $settings['blocking_statuses'], true ) && self::reserved_guests( $date, $time, $location, $post_id ) + $guests > $settings['seat_capacity'] ) {
			return new WP_Error( 'wowrestro_reservation_capacity', __( 'This time slot does not have enough remaining seat capacity.', 'wowrestro' ), array( 'status' => 409 ) );
		}
		$input['name']  = $name;
		$input['email'] = $email;
		$input['phone'] = $phone;
		$input['date']  = $date;
		$input['time']  = $time;
		$input['end_time'] = $end_time;
		$input['guests'] = $guests;
		$result         = wp_insert_post(
			array(
				'ID'          => $post_id,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name . ' - ' . $date . ' ' . $time,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$before = $post_id ? get_post_meta( $result, '_wowrestro_status', true ) : '';
		self::save_meta( $result, $input );
		if ( array_key_exists( 'payment', $input ) ) {
			update_post_meta( $result, '_wowrestro_payment', sanitize_text_field( $input['payment'] ) );
		}
		if ( array_key_exists( 'food', $input ) ) {
			update_post_meta( $result, '_wowrestro_food', wc_string_to_bool( $input['food'] ) ? 'yes' : 'no' );
		}
		$after = get_post_meta( $result, '_wowrestro_status', true );
		if ( ! $post_id || $before !== $after ) {
			self::email( $result, (bool) $post_id );
		}
		return $result;
	}

	/** Classic themes print <head> before the content renders, so a page holding the form loads its styles up front. */
	public static function enqueue_on_page() {
		$post = is_singular() ? get_post() : null;
		if ( $post && has_shortcode( $post->post_content, 'wowrestro_reservations' ) ) {
			self::enqueue_assets();
		}
	}

	private static function enqueue_assets() {
		wp_enqueue_style( 'wowrestro-reservations', WOWRESTRO_URL . 'assets/css/reservations.css', array(), WOWRESTRO_VERSION );
		wp_enqueue_script( 'wowrestro-reservations', WOWRESTRO_URL . 'assets/js/reservations.js', array(), WOWRESTRO_VERSION, true );
	}

	public static function shortcode( $attributes = array() ) {
		$settings   = self::settings();
		$attributes = shortcode_atts(
			array(
				'capacity' => $settings['seat_capacity'],
				'interval' => $settings['interval'],
				'location' => '',
			),
			$attributes,
			'wowrestro_reservations'
		);
		$earliest   = new DateTimeImmutable( '+' . absint( $settings['advance_minutes'] ) . ' minutes', wp_timezone() );
		$date       = isset( $_GET['wr_reservation_date'] ) ? sanitize_text_field( wp_unslash( $_GET['wr_reservation_date'] ) ) : $earliest->format( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slots      = self::slots( $date, absint( $attributes['interval'] ), absint( $attributes['capacity'] ), sanitize_title( $attributes['location'] ) );
		self::enqueue_assets();
		$fixed_location = sanitize_title( $attributes['location'] );
		$fields         = $settings['fields'];
		$locations      = get_terms( array( 'taxonomy' => WowRestro_Locations::TAXONOMY, 'hide_empty' => false ) );
		$locations      = is_wp_error( $locations ) ? array() : array_values( array_filter( $locations, function ( $term ) { return 'no' !== get_term_meta( $term->term_id, 'wowrestro_reservations', true ) && 'draft' !== get_term_meta( $term->term_id, 'wowrestro_status', true ); } ) );
		$required       = function ( $key ) use ( $fields ) { return 'yes' === $fields[ $key ]['required'] ? ' required' : ''; };
		// The visible label; a required field adds a mark that screen readers skip, since the input says "required" itself.
		$label = function ( $text, $is_required = false ) {
			return '<span class="wowrestro-reservation-label">' . esc_html( $text ) . ( $is_required ? '<span class="wowrestro-reservation-req" aria-hidden="true">*</span>' : '' ) . '</span>';
		};
		$errors = array(
			'invalid' => __( 'We couldn\'t book that. Check your details and choose one of the available times.', 'wowrestro' ),
			'full'    => __( 'That time just filled up. Please choose another time.', 'wowrestro' ),
			'save'    => __( 'Your booking couldn\'t be saved. Please try again.', 'wowrestro' ),
			'payment' => __( 'Online payment couldn\'t be started. Try again, or choose to pay at the restaurant.', 'wowrestro' ),
		);
		$error = isset( $_GET['wr_error'] ) ? sanitize_key( wp_unslash( $_GET['wr_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		?>
		<div class="wowrestro-reservation">
		<?php
		if ( isset( $_GET['wr_reserved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status       = sanitize_key( wp_unslash( $_GET['wr_status'] ?? 'pending' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_confirmed = 'confirmed' === $status;
			$enabled      = $is_confirmed ? $settings['confirmed_message_enabled'] : $settings['pending_message_enabled'];
			$message      = $is_confirmed ? $settings['confirmed_message'] : $settings['pending_message'];
			if ( 'yes' === $enabled ) {
				echo '<p class="wowrestro-reservation-notice is-success" role="status">' . esc_html( $message ) . '</p>';
			}
		} elseif ( $error ) {
			// A failed booking used to reload the form with no word of what went wrong.
			echo '<p class="wowrestro-reservation-notice is-error" role="alert" tabindex="-1">' . esc_html( $errors[ $error ] ?? $errors['invalid'] ) . '</p>';
		}
		?>
		<form class="wowrestro-reservation-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wowrestro_reservation">
			<input type="hidden" name="capacity" value="<?php echo esc_attr( absint( $attributes['capacity'] ) ); ?>">
			<?php if ( $fixed_location || 'yes' !== $fields['location']['visible'] ) : ?>
			<input type="hidden" name="location" value="<?php echo esc_attr( $fixed_location ); ?>">
			<?php endif; ?>
			<input type="hidden" name="redirect" value="<?php echo esc_url( remove_query_arg( array( 'wr_reserved', 'wr_status', 'wr_error' ) ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<div class="wowrestro-reservation-row">
				<?php if ( ! $fixed_location && 'yes' === $fields['location']['visible'] && $locations ) : ?>
				<label class="wowrestro-reservation-field"><?php echo $label( $fields['location']['label'], 'yes' === $fields['location']['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?><select name="location"<?php echo esc_attr( $required( 'location' ) ); ?>><option value=""><?php echo esc_html( $fields['location']['placeholder'] ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo esc_attr( $location->slug ); ?>"><?php echo esc_html( $location->name ); ?></option><?php endforeach; ?></select></label>
				<?php endif; ?>
				<?php if ( 'yes' === $fields['guests']['visible'] ) : ?>
				<label class="wowrestro-reservation-field"><?php echo $label( $fields['guests']['label'], 'yes' === $fields['guests']['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input name="guests" type="number" inputmode="numeric" min="<?php echo esc_attr( $settings['min_guests'] ); ?>" max="<?php echo esc_attr( $settings['max_guests'] ); ?>" value="<?php echo esc_attr( min( $settings['max_guests'], max( 2, $settings['min_guests'] ) ) ); ?>"<?php echo esc_attr( $required( 'guests' ) ); ?>></label>
				<?php else : ?>
				<input type="hidden" name="guests" value="<?php echo esc_attr( $settings['min_guests'] ); ?>">
				<?php endif; ?>
				<label class="wowrestro-reservation-field"><?php echo $label( $fields['date']['label'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input name="date" type="date" min="<?php echo esc_attr( $earliest->format( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+' . absint( $settings['future_days'] ) . ' days' ) ) ); ?>" value="<?php echo esc_attr( $date ); ?>" data-wowrestro-reservation-date required></label>
				<label class="wowrestro-reservation-field"><?php echo $label( $fields['time']['label'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><select name="time" required><option value=""><?php echo esc_html( $slots ? $fields['time']['placeholder'] : __( 'No times left on this day', 'wowrestro' ) ); ?></option>
				<?php
				foreach ( $slots as $slot ) :
					?>
					<option value="<?php echo esc_attr( $slot['time'] ); ?>"><?php echo esc_html( $slot['label'] ); ?></option><?php endforeach; ?></select></label>
			</div>
			<div class="wowrestro-reservation-row">
				<?php if ( 'yes' === $fields['name']['visible'] ) : ?><label class="wowrestro-reservation-field"><?php echo $label( $fields['name']['label'], 'yes' === $fields['name']['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input name="name" autocomplete="name" placeholder="<?php echo esc_attr( $fields['name']['placeholder'] ); ?>"<?php echo esc_attr( $required( 'name' ) ); ?>></label><?php endif; ?>
				<?php if ( 'yes' === $fields['email']['visible'] ) : ?><label class="wowrestro-reservation-field"><?php echo $label( $fields['email']['label'], 'yes' === $fields['email']['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input name="email" type="email" autocomplete="email" placeholder="<?php echo esc_attr( $fields['email']['placeholder'] ); ?>"<?php echo esc_attr( $required( 'email' ) ); ?>></label><?php endif; ?>
				<?php if ( 'yes' === $fields['phone']['visible'] ) : ?><label class="wowrestro-reservation-field"><?php echo $label( $fields['phone']['label'], 'yes' === $fields['phone']['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><input name="phone" type="tel" autocomplete="tel" placeholder="<?php echo esc_attr( $fields['phone']['placeholder'] ); ?>"<?php echo esc_attr( $required( 'phone' ) ); ?>></label><?php endif; ?>
			</div>
			<?php if ( 'yes' === $fields['notes']['visible'] ) : ?><label class="wowrestro-reservation-field"><?php echo $label( $fields['notes']['label'], 'yes' === $fields['notes']['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><textarea name="notes" rows="3" maxlength="500" placeholder="<?php echo esc_attr( $fields['notes']['placeholder'] ); ?>"<?php echo esc_attr( $required( 'notes' ) ); ?>></textarea></label><?php endif; ?>
			<?php foreach ( $settings['custom_fields'] as $field ) : ?>
				<?php if ( in_array( $field['type'], array( 'radio', 'checkbox' ), true ) ) : ?>
			<fieldset class="wowrestro-reservation-options"><legend><?php echo $label( $field['label'], 'yes' === $field['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></legend><?php self::render_custom_field( $field ); ?></fieldset>
				<?php else : ?>
			<label class="wowrestro-reservation-field"><?php echo $label( $field['label'], 'yes' === $field['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php self::render_custom_field( $field ); ?></label>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( (float) $settings['booking_amount'] > 0 && ( 'yes' === $settings['local_payment'] || 'yes' === $settings['woocommerce_payment'] ) ) : ?>
			<p class="wowrestro-reservation-fee"><?php echo wp_kses_post( sprintf( 'yes' === $settings['booking_per_guest'] ? __( 'Booking amount: %s per guest', 'wowrestro' ) : __( 'Booking amount: %s', 'wowrestro' ), wc_price( $settings['booking_amount'] ) ) ); ?></p>
			<label class="wowrestro-reservation-field"><?php echo $label( __( 'Payment', 'wowrestro' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><select name="reservation_payment"><?php if ( 'yes' === $settings['local_payment'] ) : ?><option value="local"><?php esc_html_e( 'Pay at the restaurant', 'wowrestro' ); ?></option><?php endif; ?><?php if ( 'yes' === $settings['woocommerce_payment'] ) : ?><option value="woocommerce"><?php esc_html_e( 'Pay online with WooCommerce', 'wowrestro' ); ?></option><?php endif; ?></select></label>
			<?php endif; ?>
			<button type="submit" class="wowrestro-reservation-submit"><?php echo esc_html( $settings['button_label'] ); ?></button>
		</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function submit() {
		check_admin_referer( self::NONCE );
		$settings = self::settings();
		$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) ), home_url( '/' ) );
		$input    = array(
			'name'     => sanitize_text_field( wp_unslash( $_POST['name'] ?? __( 'Guest', 'wowrestro' ) ) ),
			'email'    => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'    => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'guests'   => min( $settings['max_guests'], max( $settings['min_guests'], absint( $_POST['guests'] ?? $settings['min_guests'] ) ) ),
			'date'     => sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ),
			'time'     => sanitize_text_field( wp_unslash( $_POST['time'] ?? '' ) ),
			'location' => sanitize_title( wp_unslash( $_POST['location'] ?? '' ) ),
			'notes'    => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'status'   => $settings['default_status'],
		);
		$custom   = self::submitted_custom_fields( $settings['custom_fields'] );
		$capacity = min( 1000, max( 1, absint( $_POST['capacity'] ?? $settings['seat_capacity'] ) ) );
		$payment  = sanitize_key( wp_unslash( $_POST['reservation_payment'] ?? 'local' ) );
		$allowed_payments = array();
		if ( 'yes' === $settings['local_payment'] ) {
			$allowed_payments[] = 'local';
		}
		if ( 'yes' === $settings['woocommerce_payment'] ) {
			$allowed_payments[] = 'woocommerce';
		}
		$invalid_payment = (float) $settings['booking_amount'] > 0 && $allowed_payments && ! in_array( $payment, $allowed_payments, true );
		$missing_required = false;
		foreach ( array( 'location', 'email', 'phone', 'notes' ) as $key ) {
			if ( 'yes' === $settings['fields'][ $key ]['visible'] && 'yes' === $settings['fields'][ $key ]['required'] && ! $input[ $key ] ) {
				$missing_required = true;
			}
		}
		$location = $input['location'] ? get_term_by( 'slug', $input['location'], WowRestro_Locations::TAXONOMY ) : false;
		$invalid_location = $input['location'] && ( ! $location || 'no' === get_term_meta( $location->term_id, 'wowrestro_reservations', true ) || 'draft' === get_term_meta( $location->term_id, 'wowrestro_status', true ) );
		$available_times = wp_list_pluck( self::slots( $input['date'], $settings['interval'], $capacity, $input['location'] ), 'time' );
		if ( is_wp_error( $custom ) || $missing_required || $invalid_location || $invalid_payment || ! $input['name'] || ( $input['email'] && ! is_email( $input['email'] ) ) || ( 'woocommerce' === $payment && ! is_email( $input['email'] ) ) || ! self::valid_slot( $input['date'], $input['time'] ) || ! in_array( $input['time'], $available_times, true ) ) {
			wp_safe_redirect( add_query_arg( 'wr_error', 'invalid', $redirect ) );
			exit;
		}
		$lock   = 'wowrestro_booking_' . md5( $input['date'] . '|' . $input['time'] . '|' . $input['location'] );
		$locked = self::acquire_lock( $lock );
		if ( ! $locked || self::reserved_guests( $input['date'], $input['time'], $input['location'] ) + $input['guests'] > $capacity ) {
			if ( $locked ) {
				delete_option( $lock ); }
			wp_safe_redirect( add_query_arg( 'wr_error', 'full', $redirect ) );
			exit;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $input['name'] . ' - ' . $input['date'] . ' ' . $input['time'],
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			self::save_meta( $post_id, $input );
			update_post_meta( $post_id, '_wowrestro_custom_fields', wp_json_encode( $custom ) );
		}
		delete_option( $lock );
		if ( is_wp_error( $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'wr_error', 'save', $redirect ) );
			exit; }
		if ( 'woocommerce' === $payment && 'yes' === $settings['woocommerce_payment'] && (float) $settings['booking_amount'] > 0 ) {
			$amount      = (float) $settings['booking_amount'] * ( 'yes' === $settings['booking_per_guest'] ? $input['guests'] : 1 );
			$payment_url = self::create_payment_order( $post_id, $input, $amount );
			if ( is_wp_error( $payment_url ) ) {
				wp_delete_post( $post_id, true );
				wp_safe_redirect( add_query_arg( 'wr_error', 'payment', $redirect ) );
				exit;
			}
			self::email( $post_id, false );
			wp_safe_redirect( $payment_url );
			exit;
		}
		update_post_meta( $post_id, '_wowrestro_payment', 'yes' === $settings['local_payment'] ? __( 'Pay at restaurant', 'wowrestro' ) : __( 'No payment required', 'wowrestro' ) );
		self::email( $post_id, false );
		wp_safe_redirect( add_query_arg( array( 'wr_reserved' => $post_id, 'wr_status' => $settings['default_status'] ), $redirect ) );
		exit;
	}

	/** Render one sanitized custom field on the public reservation form. */
	private static function render_custom_field( $field ) {
		$name        = 'wr_custom[' . $field['key'] . ']';
		$required    = 'yes' === $field['required'] ? ' required' : '';
		$placeholder = $field['placeholder'];
		if ( 'textarea' === $field['type'] ) {
			echo '<textarea name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . esc_attr( $required ) . '></textarea>';
			return;
		}
		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( $name ) . '"' . esc_attr( $required ) . '><option value="">' . esc_html( $placeholder ) . '</option>';
			foreach ( $field['options'] as $option ) {
				echo '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
			}
			echo '</select>';
			return;
		}
		if ( in_array( $field['type'], array( 'radio', 'checkbox' ), true ) ) {
			foreach ( $field['options'] as $option ) {
				$option_name = 'checkbox' === $field['type'] ? $name . '[]' : $name;
				$choice_required = 'radio' === $field['type'] ? $required : '';
				echo '<span class="wowrestro-reservation-choice"><label><input type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $option ) . '"' . esc_attr( $choice_required ) . '> ' . esc_html( $option ) . '</label></span>';
			}
			return;
		}
		echo '<input name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . esc_attr( $required ) . '>';
	}

	/** Validate and label public custom-field values for the reservation snapshot. */
	private static function submitted_custom_fields( $fields ) {
		$posted = isset( $_POST['wr_custom'] ) && is_array( $_POST['wr_custom'] ) ? wp_unslash( $_POST['wr_custom'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The public submit nonce is verified before this helper runs.
		$clean  = array();
		foreach ( $fields as $field ) {
			$value = $posted[ $field['key'] ] ?? '';
			if ( is_array( $value ) ) {
				$value = array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
			} else {
				$value = sanitize_text_field( $value );
			}
			if ( 'yes' === $field['required'] && empty( $value ) ) {
				return new WP_Error( 'wowrestro_required_field', __( 'Complete all required reservation fields.', 'wowrestro' ) );
			}
			if ( in_array( $field['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
				$values = is_array( $value ) ? $value : array_filter( array( $value ) );
				if ( array_diff( $values, $field['options'] ) ) {
					return new WP_Error( 'wowrestro_invalid_field', __( 'Choose a valid reservation option.', 'wowrestro' ) );
				}
			}
			if ( ! empty( $value ) ) {
				$clean[ $field['label'] ] = $value;
			}
		}
		return $clean;
	}

	/** Create a fee-only WooCommerce order and let configured gateways collect the booking amount. */
	private static function create_payment_order( $reservation_id, $input, $amount ) {
		if ( ! function_exists( 'wc_create_order' ) || $amount <= 0 ) {
			return new WP_Error( 'wowrestro_payment_unavailable', __( 'WooCommerce payment is unavailable.', 'wowrestro' ) );
		}
		$order = wc_create_order( array( 'customer_id' => get_current_user_id() ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( __( 'Restaurant reservation', 'wowrestro' ) );
		$fee->set_amount( $amount );
		$fee->set_total( $amount );
		$order->add_item( $fee );
		$order->set_billing_first_name( $input['name'] );
		$order->set_billing_email( $input['email'] );
		$order->set_billing_phone( $input['phone'] );
		$order->update_meta_data( '_wowrestro_reservation_id', $reservation_id );
		$order->calculate_totals( false );
		$order->save();
		update_post_meta( $reservation_id, '_wowrestro_order_id', $order->get_id() );
		update_post_meta( $reservation_id, '_wowrestro_payment', __( 'WooCommerce payment pending', 'wowrestro' ) );
		return $order->get_checkout_payment_url();
	}

	/** Mark a reservation paid when its authoritative WooCommerce order is paid. */
	public static function sync_payment_status( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$reservation_id = absint( $order->get_meta( '_wowrestro_reservation_id' ) );
		if ( $reservation_id && self::POST_TYPE === get_post_type( $reservation_id ) ) {
			update_post_meta( $reservation_id, '_wowrestro_payment', __( 'Paid through WooCommerce', 'wowrestro' ) );
		}
	}

	private static function acquire_lock( $key ) {
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true; }
		if ( time() - absint( get_option( $key, 0 ) ) > 15 ) {
			delete_option( $key );
			return add_option( $key, time(), '', 'no' ); }
		return false;
	}

	private static function valid_slot( $date, $time ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return false; }
		try {
			$slot = new DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
		} catch ( Exception $e ) {
			return false; }
		$settings = self::settings();
		return $slot->format( 'Y-m-d H:i' ) === $date . ' ' . $time && $slot->getTimestamp() >= strtotime( '+' . absint( $settings['advance_minutes'] ) . ' minutes' ) && $slot->getTimestamp() <= strtotime( '+' . absint( $settings['future_days'] ) . ' days' );
	}

	public static function slots( $date, $interval = 30, $capacity = 20, $location = '' ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array(); }
		try {
			$day = new DateTimeImmutable( $date, wp_timezone() );
		} catch ( Exception $e ) {
			return array(); }
		$fulfillment = WowRestro_Fulfillment::settings();
		$settings    = self::settings();
		if ( in_array( $date, preg_split( '/[\s,]+/', (string) $fulfillment['holidays'] ), true ) ) {
			return array(); }
		$row = $fulfillment['service_hours']['pickup'][ (int) $day->format( 'N' ) ] ?? array();
		if ( 'yes' !== ( $row['enabled'] ?? 'no' ) ) {
			return array(); }
		$slots    = array();
		$interval = min( 120, max( 5, $interval ) );
		foreach ( preg_split( '/\s*,\s*/', (string) ( $row['periods'] ?? '' ) ) as $period ) {
			if ( ! preg_match( '/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $period, $match ) ) {
				continue; }
			for ( $at = new DateTimeImmutable( $date . ' ' . $match[1], wp_timezone() ), $end = new DateTimeImmutable( $date . ' ' . $match[2], wp_timezone() ); $at < $end; $at = $at->modify( '+' . $interval . ' minutes' ) ) {
				$used = self::reserved_guests( $date, $at->format( 'H:i' ), $location );
				if ( $at->getTimestamp() >= strtotime( '+' . absint( $settings['advance_minutes'] ) . ' minutes' ) && $at->getTimestamp() <= strtotime( '+' . absint( $settings['future_days'] ) . ' days' ) && $used < $capacity ) {
					$slots[] = array(
						'time'  => $at->format( 'H:i' ),
						'label' => wp_date( get_option( 'time_format' ), $at->getTimestamp(), wp_timezone() ) . ' · ' . ( $capacity - $used ) . ' ' . __( 'seats left', 'wowrestro' ),
					); }
			}
		}
		return $slots;
	}

	private static function reserved_guests( $date, $time, $location, $exclude_id = 0 ) {
		if ( ! self::settings()['blocking_statuses'] ) {
			return 0;
		}
		$meta = array(
			'relation' => 'AND',
			array(
				'key'   => '_wowrestro_date',
				'value' => $date,
			),
			array(
				'key'   => '_wowrestro_time',
				'value' => $time,
			),
			array( 'key' => '_wowrestro_status', 'value' => self::settings()['blocking_statuses'], 'compare' => 'IN' ),
		);
		if ( $location ) {
			$meta[] = array(
				'key'   => '_wowrestro_location',
				'value' => $location,
			); }
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta,
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded reservation slot lookup.
		if ( $exclude_id ) {
			$ids = array_values( array_diff( $ids, array( absint( $exclude_id ) ) ) );
		}
		return array_sum(
			array_map(
				function ( $id ) {
					return absint( get_post_meta( $id, '_wowrestro_guests', true ) );
				},
				$ids
			)
		);
	}

	private static function email( $post_id, $updated ) {
		$email   = get_post_meta( $post_id, '_wowrestro_email', true );
		$status  = get_post_meta( $post_id, '_wowrestro_status', true );
		$name    = get_post_meta( $post_id, '_wowrestro_name', true );
		$date    = get_post_meta( $post_id, '_wowrestro_date', true ) . ' ' . get_post_meta( $post_id, '_wowrestro_time', true );
		/* translators: %s: reservation status. */
		$subject = $updated ? sprintf( __( 'Reservation update: %s', 'wowrestro' ), self::statuses()[ $status ] ?? $status ) : __( 'Reservation request received', 'wowrestro' );
		/* translators: 1: guest name, 2: reservation date and time, 3: status. */
		$message = sprintf( __( 'Hello %1$s, your reservation for %2$s is %3$s.', 'wowrestro' ), $name, $date, self::statuses()[ $status ] ?? $status );
		$custom  = json_decode( (string) get_post_meta( $post_id, '_wowrestro_custom_fields', true ), true );
		foreach ( is_array( $custom ) ? $custom : array() as $label => $value ) {
			$message .= "\n" . sanitize_text_field( $label ) . ': ' . sanitize_text_field( is_array( $value ) ? implode( ', ', $value ) : $value );
		}
		if ( is_email( $email ) ) {
			wp_mail( $email, $subject, $message ); }
		if ( ! $updated ) {
			wp_mail( get_option( 'admin_email' ), __( 'New restaurant reservation', 'wowrestro' ), $message ); }
	}

	private static function statuses() {
		return array(
			'pending'   => __( 'Pending', 'wowrestro' ),
			'confirmed' => __( 'Confirmed', 'wowrestro' ),
			'seated'    => __( 'Seated', 'wowrestro' ),
			'completed' => __( 'Completed', 'wowrestro' ),
			'cancelled' => __( 'Cancelled', 'wowrestro' ),
			'rejected'  => __( 'Rejected', 'wowrestro' ),
		); }
	public static function columns( $columns ) {
		return array(
			'cb'        => $columns['cb'],
			'title'     => __( 'Guest', 'wowrestro' ),
			'wr_when'   => __( 'Date / time', 'wowrestro' ),
			'wr_guests' => __( 'Guests', 'wowrestro' ),
			'wr_status' => __( 'Status', 'wowrestro' ),
			'date'      => $columns['date'],
		); }
	public static function column( $column, $post_id ) {
		if ( 'wr_when' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_wowrestro_date', true ) . ' ' . get_post_meta( $post_id, '_wowrestro_time', true ) );
		} elseif ( 'wr_guests' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_wowrestro_guests', true ) );
		} elseif ( 'wr_status' === $column ) {
			$s = get_post_meta( $post_id, '_wowrestro_status', true );
			echo esc_html( self::statuses()[ $s ] ?? $s ); } }
}
