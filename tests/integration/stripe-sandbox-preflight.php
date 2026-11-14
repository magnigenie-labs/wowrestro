<?php
/** Check the real Stripe test connection without printing credentials or creating payments. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Run only on the disposable local QA site.' );
}
if ( ! class_exists( 'WC_Stripe_API' ) ) {
	WP_CLI::error( 'The official WooCommerce Stripe gateway is not active.' );
}
$wr_stripe_settings = get_option( 'woocommerce_stripe_settings', array() );
if ( 'yes' !== ( $wr_stripe_settings['testmode'] ?? '' ) || ! WC_Stripe_Mode::is_test() ) {
	WP_CLI::error( 'Stripe must be in test mode before running payment tests.' );
}
$wr_stripe_public = (string) ( $wr_stripe_settings['test_publishable_key'] ?? '' );
$wr_stripe_secret = (string) ( $wr_stripe_settings['test_secret_key'] ?? '' );
if ( 0 !== strpos( $wr_stripe_public, 'pk_test_' ) || ( 0 !== strpos( $wr_stripe_secret, 'sk_test_' ) && 0 !== strpos( $wr_stripe_secret, 'rk_test_' ) ) ) {
	WP_CLI::error( 'Connect a Stripe sandbox/test account in WooCommerce payment settings. Test credentials are missing.' );
}
WC_Stripe_API::set_secret_key_for_mode( 'test' );
$wr_stripe_account = WC_Stripe_API::request( array(), 'account', 'GET' );
if ( is_wp_error( $wr_stripe_account ) || ! empty( $wr_stripe_account->error ) || empty( $wr_stripe_account->id ) ) {
	WP_CLI::error( 'The real Stripe test API did not validate the configured account. Check the connection in WooCommerce settings.' );
}
WP_CLI::success( 'Real Stripe test API connection verified. No payment created and no credentials printed.' );
WP_CLI::line( wp_json_encode( array(
	'test_mode' => true,
	'gateway_version' => defined( 'WC_STRIPE_VERSION' ) ? WC_STRIPE_VERSION : '',
	'webhook_secret_configured' => ! empty( $wr_stripe_settings['test_webhook_secret'] ),
) ) );
