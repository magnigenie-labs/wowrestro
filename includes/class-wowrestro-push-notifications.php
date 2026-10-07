<?php
/**
 * Authenticated staff-device registration and Firebase Cloud Messaging.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;


/**
 * Registers staff devices and sends order notifications through FCM HTTP v1.
 */
final class WowRestro_Push_Notifications {
	const DEVICES_OPTION  = 'wowrestro_push_devices';
	const TOKEN_TRANSIENT = 'wowrestro_fcm_access_token';
	// Channel id created by the installed native staff app, so it keeps the name the app was built with.
	const ANDROID_CHANNEL = 'woorestro_new_orders_v1';
	const ANDROID_SOUND   = 'slow_spring';
	const IOS_SOUND       = 'slow_spring.caf';

	/**
	 * Successful sends in the current request.
	 *
	 * @var array<string,bool>
	 */
	private static $sent = array();

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wowrestro_order_transitioned', array( __CLASS__, 'queue_order_push' ), 20, 3 );
		add_action( 'wowrestro_send_order_push', array( __CLASS__, 'send_order_push' ), 10, 3 );
	}

	/**
	 * Registers device-management REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'wowrestro/v1',
			'/operations/devices',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'register_device' ),
					'permission_callback' => array( 'WowRestro_REST_API', 'can_view' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'unregister_device' ),
					'permission_callback' => array( 'WowRestro_REST_API', 'can_view' ),
				),
			)
		);
	}

	/**
	 * Registers or refreshes an FCM token for the current staff user.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function register_device( $request ) {
		$token = self::valid_token( $request->get_param( 'token' ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$platform = sanitize_key( (string) $request->get_param( 'platform' ) );
		if ( ! in_array( $platform, array( 'android', 'ios', 'web' ), true ) ) {
			return new WP_Error( 'wowrestro_push_platform', __( 'Choose iOS, Android or Web.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		$devices         = self::devices();
		$key             = hash( 'sha256', $token );
		$devices[ $key ] = array(
			'token'      => $token,
			'platform'   => $platform,
			'location'   => sanitize_title( (string) $request->get_param( 'location' ) ),
			'user_id'    => get_current_user_id(),
			'updated_at' => time(),
		);
		uasort(
			$devices,
			static function ( $a, $b ) {
				return (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 );
			}
		);
		update_option( self::DEVICES_OPTION, array_slice( $devices, 0, 500, true ), false );
		return rest_ensure_response( array( 'registered' => true ) );
	}

	/**
	 * Removes an FCM token owned by the current staff user.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unregister_device( $request ) {
		$token = self::valid_token( $request->get_param( 'token' ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$devices = self::devices();
		$key     = hash( 'sha256', $token );
		if ( isset( $devices[ $key ] ) ) {
			$owner = absint( $devices[ $key ]['user_id'] ?? 0 );
			if ( get_current_user_id() !== $owner && ! WowRestro_REST_API::can_manage() ) {
				return new WP_Error( 'wowrestro_push_forbidden', __( 'This device belongs to another operator.', 'wowrestro' ), array( 'status' => 403 ) );
			}
			unset( $devices[ $key ] );
			update_option( self::DEVICES_OPTION, $devices, false );
		}
		return rest_ensure_response( array( 'registered' => false ) );
	}

	/**
	 * Sends a notification whenever an order changes restaurant status.
	 *
	 * This stays inline so a kitchen sees the alert as soon as checkout saves
	 * the order. Action Scheduler can wait for its next runner and made live
	 * alerts arrive roughly 30 seconds late.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $from  Previous normalized status.
	 * @param string   $to    New normalized status.
	 * @return void
	 */
	public static function queue_order_push( $order, $from, $to ) {
		$from = WowRestro_Order_Statuses::normalize( $from );
		$to   = WowRestro_Order_Statuses::normalize( $to );
		if ( ! $order instanceof WC_Order || ! $to || $from === $to ) {
			return;
		}
		self::send_order_push( $order->get_id(), $from, $to );
	}

	/**
	 * Sends a queued new-order notification to matching staff devices.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous normalized status.
	 * @param string $to       New normalized status.
	 * @return bool Whether every matching device accepted the message.
	 */
	public static function send_order_push( $order_id, $from = '', $to = 'new' ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || ! WowRestro_Order_Statuses::is_restaurant_order( $order ) ) {
			return false;
		}
		$send_key = absint( $order_id ) . '|' . sanitize_key( (string) $from ) . '|' . sanitize_key( (string) $to );
		$location = sanitize_title( (string) $order->get_meta( WowRestro_Locations::ORDER_META ) );
		$mode     = $order->get_meta( '_wowrestro_fulfillment' );
		if ( ! $mode ) {
			$mode = $order->get_meta( '_wowrestro_mode' );
		}
		$mode         = sanitize_key( (string) ( $mode ? $mode : 'pickup' ) );
		$to           = WowRestro_Order_Statuses::normalize( $to );
		$is_new       = 'new' === $to;
		$status_label = WowRestro_Order_Statuses::label( $to );
		if ( $is_new ) {
			/* translators: %s: order number. */
			$title = sprintf( __( 'New order #%s', 'wowrestro' ), $order->get_order_number() );
			$body  = sprintf( '%1$s · %2$s', ucfirst( $mode ), wp_strip_all_tags( $order->get_formatted_order_total() ) );
		} else {
			/* translators: %s: order number. */
			$title = sprintf( __( 'Order #%s updated', 'wowrestro' ), $order->get_order_number() );
			/* translators: %s: restaurant order status. */
			$body = sprintf( __( 'Status: %s', 'wowrestro' ), $status_label );
		}
		$payload = array(
			'title' => $title,
			'body'  => $body,
			'data'  => array(
				'type'     => $is_new ? 'new_order' : 'order_updated',
				'order_id' => (string) $order->get_id(),
				'status'   => $to,
				'from'     => $from,
				'route'    => '/orders',
				'title'    => $title,
				'body'     => $body,
				'link'     => WowRestro_App::url( '/live-orders' ),
			),
		);
		$devices = self::devices();
		uasort(
			$devices,
			static function ( $a, $b ) {
				return ( 'web' === ( $a['platform'] ?? '' ) ) <=> ( 'web' === ( $b['platform'] ?? '' ) );
			}
		);
		$all_sent = true;
		foreach ( $devices as $key => $device ) {
			if ( $location && ! empty( $device['location'] ) && $location !== $device['location'] ) {
				continue;
			}
			$device_send_key = $send_key . '|' . $key;
			if ( isset( self::$sent[ $device_send_key ] ) ) {
				continue;
			}
			$result = self::send_message( (string) ( $device['token'] ?? '' ), $payload, (string) ( $device['platform'] ?? '' ) );
			if ( is_wp_error( $result ) ) {
				$all_sent = false;
				if ( 'wowrestro_fcm_unregistered' === $result->get_error_code() ) {
					self::remove_device( $key );
				}
			} else {
				self::$sent[ $device_send_key ] = true;
			}
		}
		return $all_sent;
	}

	/**
	 * Sends one FCM HTTP v1 message.
	 *
	 * @param string $token    Device token.
	 * @param array  $payload  Notification title, body, and string data.
	 * @param string $platform Device platform.
	 * @return true|WP_Error
	 */
	public static function send_message( $token, $payload, $platform = '' ) {
		$token = self::valid_token( $token );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$credentials = self::credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$access_token = self::access_token( $credentials );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}
		$data    = array_map( 'strval', (array) ( $payload['data'] ?? array() ) );
		$message = array(
			'token'   => $token,
			'data'    => $data,
			'android' => array(
				'priority'     => 'HIGH',
				'notification' => array(
					'title'                  => sanitize_text_field( (string) ( $payload['title'] ?? '' ) ),
					'body'                   => sanitize_text_field( (string) ( $payload['body'] ?? '' ) ),
					'channel_id'             => self::ANDROID_CHANNEL,
					'sound'                  => self::ANDROID_SOUND,
					'notification_priority' => 'PRIORITY_MAX',
					'visibility'             => 'PUBLIC',
					'default_vibrate_timings' => true,
				),
			),
			'apns'    => array(
				'headers' => array(
					'apns-priority'  => '10',
					'apns-push-type' => 'alert',
				),
				'payload' => array(
					'aps' => array(
						'alert'             => array(
							'title' => sanitize_text_field( (string) ( $payload['title'] ?? '' ) ),
							'body'  => sanitize_text_field( (string) ( $payload['body'] ?? '' ) ),
						),
						'sound'             => self::IOS_SOUND,
						'content-available' => 1,
					),
				),
			),
		);
		if ( 'web' === $platform ) {
			$message['webpush'] = array(
				'headers'     => array( 'Urgency' => 'high' ),
				'fcm_options' => array( 'link' => WowRestro_App::url( '/live-orders' ) ),
			);
		} else {
			$message['notification'] = array(
				'title' => sanitize_text_field( (string) ( $payload['title'] ?? '' ) ),
				'body'  => sanitize_text_field( (string) ( $payload['body'] ?? '' ) ),
			);
		}
		$body     = array( 'message' => $message );
		$url      = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $credentials['project_id'] ) . '/messages:send';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return true;
		}
		$error = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( $error['error']['details'] ?? array() ) as $detail ) {
			if ( 'UNREGISTERED' === ( $detail['errorCode'] ?? '' ) ) {
				return new WP_Error( 'wowrestro_fcm_unregistered', __( 'The device token expired.', 'wowrestro' ) );
			}
		}
		return new WP_Error( 'wowrestro_fcm_send', __( 'Firebase rejected the notification.', 'wowrestro' ), array( 'status' => $status ) );
	}

	/**
	 * Exchanges a signed service-account JWT for a short-lived OAuth token.
	 *
	 * @param array $credentials Validated service-account credentials.
	 * @return string|WP_Error
	 */
	private static function access_token( $credentials ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached ) {
			return $cached;
		}
		$now       = time();
		$header    = self::base64url(
			wp_json_encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		);
		$claims    = self::base64url(
			wp_json_encode(
				array(
					'iss'   => $credentials['client_email'],
					'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
					'aud'   => $credentials['token_uri'],
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$unsigned  = $header . '.' . $claims;
		$signature = '';
		if ( ! function_exists( 'openssl_sign' ) || ! openssl_sign( $unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'wowrestro_fcm_sign', __( 'The Firebase service account could not sign a request.', 'wowrestro' ) );
		}
		$response = wp_remote_post(
			$credentials['token_uri'],
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $unsigned . '.' . self::base64url( $signature ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = sanitize_text_field( (string) ( $data['access_token'] ?? '' ) );
		if ( ! $token ) {
			return new WP_Error( 'wowrestro_fcm_auth', __( 'Firebase authentication failed.', 'wowrestro' ) );
		}
		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, absint( $data['expires_in'] ?? 3600 ) - 60 ) );
		return $token;
	}

	/**
	 * Loads service-account credentials from a protected local file or filter.
	 *
	 * @return array|WP_Error
	 */
	private static function credentials() {
		$credentials = apply_filters( 'wowrestro_firebase_service_account', null );
		if ( is_array( $credentials ) ) {
			return self::validate_credentials( $credentials );
		}
		$path = defined( 'WOWRESTRO_FIREBASE_SERVICE_ACCOUNT' ) ? WOWRESTRO_FIREBASE_SERVICE_ACCOUNT : getenv( 'GOOGLE_APPLICATION_CREDENTIALS' );
		$path = apply_filters( 'wowrestro_firebase_service_account_path', $path );
		if ( ! is_string( $path ) || ! is_readable( $path ) || filesize( $path ) > 65536 ) {
			return new WP_Error( 'wowrestro_fcm_credentials', __( 'Configure a readable Firebase service-account file outside the public web root.', 'wowrestro' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This is a validated local file path, never a URL.
		$data = json_decode( file_get_contents( $path ), true );
		return self::validate_credentials( is_array( $data ) ? $data : array() );
	}

	/**
	 * Validates the service-account fields used for OAuth.
	 *
	 * @param array $credentials Candidate credentials.
	 * @return array|WP_Error
	 */
	private static function validate_credentials( $credentials ) {
		foreach ( array( 'project_id', 'client_email', 'private_key', 'token_uri' ) as $key ) {
			if ( empty( $credentials[ $key ] ) || ! is_string( $credentials[ $key ] ) ) {
				return new WP_Error( 'wowrestro_fcm_credentials', __( 'The Firebase service-account file is invalid.', 'wowrestro' ) );
			}
		}
		if ( 'https' !== wp_parse_url( $credentials['token_uri'], PHP_URL_SCHEME ) ) {
			return new WP_Error( 'wowrestro_fcm_credentials', __( 'The Firebase token endpoint must use HTTPS.', 'wowrestro' ) );
		}
		return $credentials;
	}

	/**
	 * Validates a device registration token.
	 *
	 * @param mixed $token Candidate token.
	 * @return string|WP_Error
	 */
	private static function valid_token( $token ) {
		$token = trim( (string) $token );
		if ( strlen( $token ) < 20 || strlen( $token ) > 4096 || ! preg_match( '/^[A-Za-z0-9_:\-]+$/', $token ) ) {
			return new WP_Error( 'wowrestro_push_token', __( 'The device token is invalid.', 'wowrestro' ), array( 'status' => 400 ) );
		}
		return $token;
	}

	/**
	 * Gets registered devices.
	 *
	 * @return array
	 */
	private static function devices() {
		$devices = get_option( self::DEVICES_OPTION, array() );
		return is_array( $devices ) ? $devices : array();
	}

	/**
	 * Removes a registered device by its hashed option key.
	 *
	 * @param string $key Hashed device token.
	 * @return void
	 */
	private static function remove_device( $key ) {
		$devices = self::devices();
		unset( $devices[ $key ] );
		update_option( self::DEVICES_OPTION, $devices, false );
	}

	/**
	 * Encodes a JWT segment with URL-safe base64.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function base64url( $value ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT requires URL-safe base64 encoding.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}
