<?php
/**
 * WowRestro uninstall routine.
 *
 * Orders and their immutable line-item snapshots are always preserved.
 *
 * @package WowRestro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wowrestro_run_migration_batch' );
wp_clear_scheduled_hook( 'wowrestro_continue_migration_batch' );
wp_clear_scheduled_hook( 'wowrestro_cleanup_slot_reservations' );
wp_clear_scheduled_hook( 'wowrestro_expire_phone_order' );
wp_clear_scheduled_hook( 'wowrestro_finalize_phone_order' );
wp_clear_scheduled_hook( 'wowrestro_reconcile_operational_status' );
if ( function_exists( '_get_cron_array' ) ) {
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		foreach ( array( 'wowrestro_expire_phone_order', 'wowrestro_finalize_phone_order', 'wowrestro_reconcile_operational_status' ) as $hook ) {
			foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
				wp_unschedule_event( $timestamp, $hook, (array) ( $event['args'] ?? array() ) );
			}
		}
	}
}
delete_option( 'wowrestro_migration_batch_lock' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wowrestro_run_migration_batch', array(), 'wowrestro' );
	as_unschedule_all_actions( 'wowrestro_continue_migration_batch', array(), 'wowrestro' );
	as_unschedule_all_actions( 'wowrestro_expire_phone_order', array(), 'wowrestro' );
	as_unschedule_all_actions( 'wowrestro_finalize_phone_order', null, 'wowrestro' );
	as_unschedule_all_actions( 'wowrestro_reconcile_operational_status', null, 'wowrestro' );
}

global $wpdb;
// Cross-request locks and replay indexes are runtime coordination state, not store data.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wowrestro_order_lock_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wowrestro_idem_' ) . '%' ) );

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( array( 'wowrestro_view_orders', 'wowrestro_operate_orders', 'wowrestro_print_orders', 'wowrestro_manage', 'wowrestro_manage_menu', 'wowrestro_manage_modifiers', 'wowrestro_manage_operations', 'wowrestro_create_phone_orders', 'wowrestro_manage_payments', 'wowrestro_manage_tables' ) as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}
remove_role( 'wowrestro_manager' );
remove_role( 'wowrestro_staff' );
remove_role( 'wowrestro_waiter' );
delete_option( 'wowrestro_roles_version' );

if ( 'yes' === get_option( 'wowrestro_remove_data_on_uninstall', 'no' ) ) {
	$modifier_ids = get_posts(
		array(
			'post_type'      => 'wowrestro_mod_group',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $modifier_ids as $modifier_id ) {
		wp_delete_post( $modifier_id, true );
	}
	foreach ( array( 'wowrestro_booking', 'wowrestro_table_sess' ) as $post_type ) {
		foreach ( get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	delete_post_meta_by_key( '_wowrestro_menu_item' );
	delete_post_meta_by_key( '_wowrestro_modifier_group_ids' );
	delete_post_meta_by_key( '_wowrestro_modifier_option_allowlists' );

	$table = $wpdb->prefix . 'wowrestro_slot_reservations';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit uninstall opt-in and a fixed prefixed table name.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	foreach (
		array(
			'wowrestro_settings',
			'wowrestro_version',
			'wowrestro_upgrade_from',
			'wowrestro_schema_version',
			'wowrestro_data_version',
			'wowrestro_migration_state',
			'wowrestro_legacy_migrated',
			'wowrestro_legacy_settings_backup',
			'wowrestro_legacy_delivery_report',
			'wowrestro_legacy_detected',
			'wowrestro_restaurant_profile',
			'wowrestro_reservation_settings',
			'wowrestro_onboarding_pending',
			'wowrestro_onboarding_complete',
			'wowrestro_menu_page_id',
			'wowrestro_roles_version',
			'wowrestro_remove_data_on_uninstall',
			'wowrestro_qr_codes',
			'wowrestro_orders_changed',
			'wowrestro_seed_product_labels',
			'wowrestro_flush_rewrite',
			'wowrestro_former_name_adopted',
		) as $option
	) {
		delete_option( $option );
	}
}

flush_rewrite_rules();
