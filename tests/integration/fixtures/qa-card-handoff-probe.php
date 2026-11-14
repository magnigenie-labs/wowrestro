<?php
// Local state-transfer probe only. This gateway cannot process payments.
if ( 'local' !== wp_get_environment_type() || '1' !== ( $_COOKIE['wr_handoff_probe'] ?? '' ) ) { return; }
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return; }
	class WR_QA_Card_Handoff_Probe extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'wr_handoff_probe';
			$this->enabled = 'yes';
			$this->title = 'QA card handoff probe (no payments)';
			$this->method_title = $this->title;
			$this->has_fields = true;
			$this->supports = array( 'products' );
		}
		public function payment_fields() { echo '<p>Local state-transfer check. No card data or payments.</p>'; }
		public function process_payment( $order_id ) {
			wc_add_notice( 'This local handoff probe cannot process payments.', 'error' );
			return array( 'result' => 'failure' );
		}
	}
	add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) { $gateways[] = 'WR_QA_Card_Handoff_Probe'; return $gateways; } );
}, 15 );
add_action( 'woocommerce_blocks_loaded', static function () {
	class WR_QA_Card_Handoff_Block extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
		protected $name = 'wr_handoff_probe';
		public function initialize() {}
		public function is_active() { return true; }
		public function get_payment_method_script_handles() {
			wp_register_script( 'wr-qa-card-handoff', plugins_url( 'qa-card-handoff-probe.js', __FILE__ ), array( 'wc-blocks-registry', 'wp-element', 'wc-settings' ), '1', true );
			return array( 'wr-qa-card-handoff' );
		}
	}
	add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ) { $registry->register( new WR_QA_Card_Handoff_Block() ); } );
} );
