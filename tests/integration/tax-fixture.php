<?php
/** Temporary tax/coupon setup for the disposable browser QA site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) {
	throw new RuntimeException( 'Local WP-CLI QA only.' );
}
$saved = get_option( 'wr_qa_tax_fixture', array() );
if ( 'restore' === ( $args[0] ?? '' ) ) {
	if ( $saved ) {
		foreach ( $saved['options'] as $key => $value ) { update_option( $key, $value ); }
		WC_Tax::_delete_tax_rate( $saved['rate'] );
		( new WC_Coupon( $saved['coupon'] ) )->delete( true );
		delete_option( 'wr_qa_tax_fixture' );
	}
	WP_CLI::success( 'Tax fixture restored.' );
	return;
}
if ( $saved ) { throw new RuntimeException( 'Restore the existing fixture first.' ); }
$options = array();
foreach ( array( 'woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_display_shop' => 'excl', 'woocommerce_tax_display_cart' => 'excl', 'woocommerce_tax_based_on' => 'shipping', 'woocommerce_enable_coupons' => 'yes' ) as $key => $value ) {
	$options[ $key ] = get_option( $key );
	update_option( $key, $value );
}
$rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'GB', 'tax_rate_state' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'QA VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
$coupon = new WC_Coupon();
$coupon->set_code( 'qa-ten-percent' );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->save();
update_option( 'wr_qa_tax_fixture', array( 'options' => $options, 'rate' => $rate, 'coupon' => $coupon->get_id() ) );
WC_Cache_Helper::get_transient_version( 'shipping', true );
WP_CLI::success( '20% GB tax and qa-ten-percent coupon enabled.' );
