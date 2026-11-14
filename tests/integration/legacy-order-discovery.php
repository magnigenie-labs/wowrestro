<?php
/** Check actual HPOS/CPT discovery without changing the site's storage setting. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Run only on an isolated local WordPress installation.' );
}
$qa_backend = 'yes';
$qa_storage = static function () use ( &$qa_backend ) { return $qa_backend; };
$qa_no_sync = static function () { return 'no'; };
$qa_id = 0;
$qa_scope = static function ( $args ) use ( &$qa_id ) {
	$args['post__in'] = array( $qa_id );
	$args['cache_results'] = false;
	return $args;
};
$qa_queries = array();
$qa_capture = static function ( $sql ) use ( &$qa_queries ) {
	if ( false !== strpos( $sql, 'meta_key IN' ) && false !== strpos( $sql, '_wowrestro_service_type' ) ) {
		$qa_queries[] = $sql;
	}
	return $sql;
};
$qa_checks = 0;
$qa_assert = static function ( $condition, $message ) use ( &$qa_checks ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$qa_checks;
};
$qa_method = new ReflectionMethod( 'WowRestro_Legacy_Migration', 'has_legacy_orders' );
$qa_keys = array( '_wowrestro_mode', '_wowrestro_promised_at', '_wowrestro_service_type', '_wowrestro_service_date', '_wowrestro_service_time' );
add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $qa_storage );
add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', $qa_no_sync );
add_filter( 'woocommerce_order_query_args', $qa_scope );
add_filter( 'query', $qa_capture );
try {
	foreach ( array( 'yes' => 'HPOS', 'no' => 'CPT' ) as $enabled => $label ) {
		$qa_backend = $enabled;
		$order = new WC_Order();
		$order->set_status( 'pending' );
		for ( $i = 0; $i < 100; ++$i ) {
			$order->update_meta_data( '_qa_legacy_padding_' . $i, 'unrelated' );
		}
		$qa_id = $order->save();
		try {
			$qa_assert( false === $qa_method->invoke( null ), $label . ': unrelated order is not legacy data.' );
			foreach ( $qa_keys as $index => $key ) {
				$order->update_meta_data( $key, 'legacy-value' );
				$order->save();
				$qa_queries = array();
				$start = microtime( true );
				$qa_assert( true === $qa_method->invoke( null ), $label . ': detects ' . $key );
				$qa_assert( microtime( true ) - $start < 5, $label . ': discovery remains bounded with 100 metadata rows.' );
				$qa_assert( ! empty( $qa_queries ), $label . ': actual database query uses key IN.' );
				foreach ( $qa_queries as $sql ) {
					preg_match_all( '/JOIN\s+`?[^\s`]*(?:wc_orders_meta|postmeta)`?\s/i', $sql, $joins );
					$qa_assert( 1 === count( $joins[0] ), $label . ': discovery joins the metadata table once.' );
				}
				$qa_assert( ( $index >= 2 ) === $qa_method->invoke( null, true ), $label . ': WOWRestro-only detection respects key subset.' );
				$order->delete_meta_data( $key );
				$order->save();
			}
		} finally {
			$order->delete( true );
		}
	}
	WP_CLI::success( $qa_checks . ' actual HPOS/CPT legacy discovery checks passed.' );
} finally {
	remove_filter( 'query', $qa_capture );
	remove_filter( 'woocommerce_order_query_args', $qa_scope );
	remove_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', $qa_no_sync );
	remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $qa_storage );
}
