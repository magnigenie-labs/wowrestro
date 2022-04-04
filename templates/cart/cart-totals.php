<?php
/**
 * WoWRestro Cart Totals
 *
 * This template can be overridden by copying it to yourtheme/wowrestro/cart/cart-totals.php
 *
 * @package     WoWRestro/Templates
 * @version     1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
} 


?>

<div class="wowrestro-cart-totals-container">

	<?php do_action( 'wowrestro_before_cart_totals' ); ?>

	<div><hr></div>

	<?php do_action( 'wowrestro_cart_totals_before_order_total' ); ?>

	<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
		<div class="wowrestro-fee">
			<div class="wwr-cart-fee-title wwr-text-left"><?php echo esc_html( $fee->name ); ?></div>
			<div class="wwr-cart-fee-value wwr-text-right"><?php wc_cart_totals_fee_html( $fee ); ?></div>
		</div>
	<?php endforeach; ?>

	<div class="wowrestro-cart-totals-item order-total">
		<div class="wowrestro-cart-totals-item-left"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></div>
		<div class="wwr-text-right" data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>"><?php wc_cart_totals_subtotal_html(); ?></div>
	</div>

	<?php do_action( 'wowrestro_cart_totals_after_order_total' ); ?>

	<?php do_action( 'wowrestro_after_cart_totals' ); ?>

</div>