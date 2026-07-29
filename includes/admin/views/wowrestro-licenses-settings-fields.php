<?php
$wowrestro_licenses_setting = get_option( 'wowrestro_licenses_setting', true );
?>
<div class="woocommerce-dashboard-section">
	<?php $addon_license = isset( $wowrestro_licenses_setting['all_access'] ) ? $wowrestro_licenses_setting['all_access'] : ''; ?>
	<?php $is_valid_license = is_valid_license( $addon_license ); ?>
	<?php $class = ( $is_valid_license['status'] == 'error' ) ? 'error' : 'success'; ?>
	<div class="addon-card" >
		<div class="postbox-header is-size-medium addon-card-head">
			<?php echo __( 'All Access', 'wowrestro' ); ?>
		</div>
		<div class="addon-license">
			<input type="text" name="wowrestro_licenses_setting[all_access]" value="<?php echo $addon_license ?>" placeholder="<?php esc_html_e( 'Enter your All Access license key here', 'wowrestro' ); ?>">
		</div>
		<?php if ( !empty( $addon_license ) ): ?>
			<div class="<?php echo esc_attr__( $class, 'wowrestro' ); ?>">
				<p><?php echo $is_valid_license['message']; ?></p>
			</div>
		<?php endif ?>
		<div class="subsubsub addon-card-footer">
			<p><?php echo __( 'To receive updates, please enter your valid Manual Purchases license key.', 'wowrestro' ); ?></p>
		</div>
	</div>
</div>