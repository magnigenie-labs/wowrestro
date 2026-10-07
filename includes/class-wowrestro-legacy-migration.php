<?php
/**
 * Resumable, non-destructive upgrades from WooRestro 0.2 (this plugin's former name)
 * and WOWRestro 1.x (the earlier plugin on wordpress.org, whose data keeps its original
 * names: the _wowrestro_ meta, the food_modifiers taxonomy and the [wowrestro] shortcode).
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Legacy_Migration {
	const DATA_VERSION = '3';
	const BATCH_SIZE   = 100;
	const ACTION       = 'wowrestro_run_migration_batch';
	const CONTINUE_ACTION = 'wowrestro_continue_migration_batch';
	const LOCK_OPTION  = 'wowrestro_migration_batch_lock';
	const LOCK_TTL     = 1800;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_legacy_taxonomy' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
		add_action( self::ACTION, array( __CLASS__, 'run_batch' ) );
		add_action( self::CONTINUE_ACTION, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_wowrestro_migrate_legacy', array( __CLASS__, 'start' ) );
	}

	/**
	 * Install the custom slot schema before any data backfill starts.
	 *
	 * @return bool
	 */
	public static function install_schema() {
		if ( ! class_exists( 'WowRestro_Slot_Reservations' ) || ! method_exists( 'WowRestro_Slot_Reservations', 'install' ) ) {
			return false;
		}
		if ( (string) get_option( 'wowrestro_schema_version', '' ) === (string) WowRestro_Slot_Reservations::SCHEMA_VERSION && WowRestro_Slot_Reservations::table_ready() ) {
			return true;
		}
		return false !== WowRestro_Slot_Reservations::install();
	}

	public static function maybe_schedule() {
		if ( self::DATA_VERSION === (string) get_option( 'wowrestro_data_version', '' ) ) {
			return;
		}
		self::ensure_state();
		$state = get_option( 'wowrestro_migration_state', array() );
		if ( is_array( $state ) && 'blocked' === ( $state['phase'] ?? '' ) ) {
			return;
		}
		self::schedule_next();
	}

	public static function start() {
		if ( ! current_user_can( 'wowrestro_manage' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wowrestro' ) );
		}
		check_admin_referer( 'wowrestro_migrate_legacy' );
		$lock = self::acquire_batch_lock();
		if ( $lock ) {
			try {
				delete_option( 'wowrestro_migration_state' );
				self::ensure_state();
				self::run_locked_batch();
			} finally {
				self::release_batch_lock( $lock );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wowrestro-setup&migration=started' ) );
		exit;
	}

	private static function ensure_state() {
		$state = get_option( 'wowrestro_migration_state', array() );
		if ( is_array( $state ) && ! empty( $state['phase'] ) && 'complete' !== $state['phase'] ) {
			return;
		}

		$legacy_options = array();
		foreach ( self::legacy_setting_keys() as $key ) {
			$value = get_option( $key, null );
			if ( null !== $value ) {
				$legacy_options[ $key ] = $value;
			}
		}
		if ( $legacy_options && false === get_option( 'wowrestro_legacy_settings_backup', false ) ) {
			add_option( 'wowrestro_legacy_settings_backup', $legacy_options, '', false );
		}
		if ( self::has_wowrestro_data() ) {
			update_option( 'wowrestro_legacy_detected', 'yes', false );
		}

		update_option(
			'wowrestro_migration_state',
			array(
				'phase'    => 'settings',
				'page'     => 1,
					'migrated' => 0,
					'skipped'  => 0,
					'errors'   => array(),
					'blocking_errors' => array(),
			),
			false
		);
	}

	public static function run_batch() {
		$lock = self::acquire_batch_lock();
		if ( ! $lock ) {
			return;
		}
		try {
			self::run_locked_batch();
		} finally {
			self::release_batch_lock( $lock );
		}
	}

	/** Run one migration page while the caller owns the batch lease. */
	private static function run_locked_batch() {
		if ( ! self::install_schema() ) {
			self::record_error( __( 'The slot reservation table could not be installed.', 'wowrestro' ), true );
			return;
		}

		$state = get_option( 'wowrestro_migration_state', array() );
		if ( ! is_array( $state ) || empty( $state['phase'] ) || 'complete' === $state['phase'] ) {
			return;
		}

		switch ( $state['phase'] ) {
			case 'settings':
				$state['phase'] = self::migrate_settings( $state ) ? 'modifiers' : 'blocked';
				break;
			case 'modifiers':
				if ( self::migrate_modifier_groups( $state ) ) {
					$state['phase'] = 'products';
					$state['page']  = 1;
				} else {
					$state['phase'] = 'blocked';
				}
				break;
			case 'products':
				$products_complete = self::migrate_products( $state );
				if ( ! empty( $state['blocking_errors'] ) ) {
					$state['phase'] = 'blocked';
				} elseif ( $products_complete ) {
					$state['phase'] = 'orders';
					$state['page']  = 1;
				}
				break;
			case 'orders':
				if ( self::migrate_orders( $state ) ) {
					if ( ! empty( $state['blocking_errors'] ) ) {
						$state['phase'] = 'blocked';
					} else {
						$state['phase'] = 'complete';
						update_option( 'wowrestro_data_version', self::DATA_VERSION, false );
						update_option( 'wowrestro_legacy_migrated', 'yes', false );
					}
				}
				break;
		}

		update_option( 'wowrestro_migration_state', $state, false );
		if ( ! in_array( $state['phase'], array( 'complete', 'blocked' ), true ) ) {
			self::schedule_next();
		}
	}

	private static function migrate_settings( &$state ) {
		$settings = WowRestro_Fulfillment::settings();
		$legacy_open  = get_option( '_wowrestro_open_time', null );
		$legacy_close = get_option( '_wowrestro_close_time', null );
		$map      = array(
			'pickup_enabled'       => array( 'enable_pickup', 'bool' ),
			'delivery_enabled'     => array( 'enable_delivery', 'bool' ),
			'prep_minutes'         => array( '_wowrestro_food_prepation_time', 'int' ),
			'slot_interval'        => array( 'pickup_time_interval', 'int' ),
			'minimum_order'        => array( '_wowrestro_min_delivery_order_amount', 'decimal' ),
			'open_time'            => array( '_wowrestro_open_time', 'time' ),
			'close_time'           => array( '_wowrestro_close_time', 'time' ),
		);
		foreach ( $map as $target => $source ) {
			$value = get_option( $source[0], null );
			if ( null === $value || '' === $value ) {
				continue;
			}
			switch ( $source[1] ) {
				case 'bool':
					$settings[ $target ] = wc_string_to_bool( $value ) ? 'yes' : 'no';
					break;
				case 'int':
					$settings[ $target ] = max( 0, absint( $value ) );
					break;
				case 'decimal':
					$settings[ $target ] = wc_format_decimal( $value );
					break;
				case 'time':
					$settings[ $target ] = self::normalize_time( $value, $settings[ $target ] );
					break;
			}
		}
		$pickup_interval   = get_option( 'pickup_time_interval', null );
		$delivery_interval = get_option( 'delivery_time_interval', null );
		if ( ( null === $pickup_interval || '' === $pickup_interval ) && null !== $delivery_interval && '' !== $delivery_interval ) {
			$settings['slot_interval'] = min( 120, max( 5, absint( $delivery_interval ) ) );
		}

		// WOWRestro used one daily window. Preserve it in both service schedules;
		// top-level compatibility mirrors are not consulted by the 0.3 scheduler.
		if ( null !== $legacy_open || null !== $legacy_close ) {
			$open   = self::normalize_time( null !== $legacy_open ? $legacy_open : $settings['open_time'], $settings['open_time'] );
			$close  = self::normalize_time( null !== $legacy_close ? $legacy_close : $settings['close_time'], $settings['close_time'] );
			$period = $open < $close ? $open . '-' . $close : '11:00-22:00';
			foreach ( array( WowRestro_Fulfillment::MODE_PICKUP, WowRestro_Fulfillment::MODE_DELIVERY ) as $mode ) {
				foreach ( range( 1, 7 ) as $day ) {
					$settings['service_hours'][ $mode ][ $day ] = array( 'enabled' => 'yes', 'periods' => $period );
				}
			}
			foreach ( range( 1, 7 ) as $day ) {
				$settings['weekly_hours'][ $day ] = array( 'enabled' => 'yes', 'open' => $open, 'close' => $close );
			}
			$settings['open_time']  = $open;
			$settings['close_time'] = $close;
		}

		// These values are evidence for the migration report only. WooCommerce
		// shipping zones, methods, costs and coverage are never mutated here.
		if ( false === get_option( 'wowrestro_legacy_delivery_report', false ) ) {
			$report = array(
					'minimum_order'     => get_option( '_wowrestro_min_delivery_order_amount', $settings['minimum_order'] ),
					'delivery_fee'      => $settings['delivery_fee'],
					'delivery_postcodes'=> $settings['delivery_postcodes'],
					'delivery_zones'    => $settings['delivery_zones'],
					'pickup_slot_interval'   => $pickup_interval,
					'delivery_slot_interval' => $delivery_interval,
				);
			add_option( 'wowrestro_legacy_delivery_report', $report, '', false );
			if ( ! self::option_value_matches( 'wowrestro_legacy_delivery_report', $report ) ) {
				return self::block_state( $state, __( 'The legacy delivery settings report could not be saved.', 'wowrestro' ) );
			}
		}
		unset( $settings['_schedule_key'] );
		update_option( 'wowrestro_settings', $settings, false );
		if ( ! self::option_value_matches( 'wowrestro_settings', $settings ) ) {
			return self::block_state( $state, __( 'WowRestro settings could not be saved during migration.', 'wowrestro' ) );
		}
		return true;
	}

	/**
	 * WOWRestro 1.x registered food_modifiers. When this plugin has replaced it in
	 * the same folder, its terms are still in the database but nothing registers
	 * the taxonomy, so register it here until the migration has finished.
	 */
	public static function register_legacy_taxonomy() {
		global $wpdb;
		if ( taxonomy_exists( 'food_modifiers' ) || self::DATA_VERSION === (string) get_option( 'wowrestro_data_version', '' ) ) {
			return;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", 'food_modifiers' ) ) ) {
			register_taxonomy(
				'food_modifiers',
				'product',
				array(
					'hierarchical' => true,
					'public'       => false,
					'rewrite'      => false,
					'show_ui'      => false,
				)
			);
		}
	}

	private static function migrate_modifier_groups( &$state ) {
		if ( ! class_exists( 'WowRestro_Modifiers' ) || ! post_type_exists( WowRestro_Modifiers::POST_TYPE ) ) {
			if ( 'yes' === get_option( 'wowrestro_legacy_detected', 'no' ) ) {
				return self::block_state( $state, __( 'WOWRestro 1.x add-on groups could not be migrated because the add-on group type is missing.', 'wowrestro' ) );
			}
			return true;
		}
		if ( ! taxonomy_exists( 'food_modifiers' ) ) {
			// No WOWRestro 1.x plugin and no terms left in the database: nothing to migrate.
			return true;
		}
		$parents = get_terms(
			array(
				'taxonomy'   => 'food_modifiers',
				'hide_empty' => false,
				'parent'     => 0,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $parents ) ) {
			return self::block_state( $state, $parents->get_error_message() );
		}
		foreach ( $parents as $parent ) {
			$source_id = 'wowrestro:term:' . absint( $parent->term_id );
			$selection = sanitize_key( get_term_meta( $parent->term_id, '_wowrestro_modifier_selection_option', true ) );
			$type      = 'multiple' === $selection ? 'multiple' : 'single';
			$children  = get_terms(
				array(
					'taxonomy'   => 'food_modifiers',
					'hide_empty' => false,
					'parent'     => $parent->term_id,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				)
			);
			if ( is_wp_error( $children ) ) {
				return self::block_state(
					$state,
					sprintf(
						/* translators: 1: legacy modifier group name, 2: database error. */
						__( 'WOWRestro modifier group "%1$s" could not be read: %2$s', 'wowrestro' ),
						$parent->name,
						$children->get_error_message()
					)
				);
			}
			$options = array();
			foreach ( $children as $child ) {
				$options[] = array(
					'id'      => 'wow-' . absint( $child->term_id ),
					'label'   => sanitize_text_field( $child->name ),
					'price'   => max( 0, (float) wc_format_decimal( get_term_meta( $child->term_id, '_wowrestro_modifier_item_price', true ) ) ),
					'default' => wc_string_to_bool( self::first_term_meta( $child->term_id, array( '_wowrestro_modifier_item_default', '_modifier_item_default' ) ) ),
				);
			}
			if ( ! $options ) {
				$state['skipped']++;
				continue;
			}
			$options = WowRestro_Modifiers::sanitize_options( $options );
			if ( count( $options ) !== count( $children ) ) {
				return self::block_state(
					$state,
					sprintf(
						/* translators: %s: legacy modifier group name. */
						__( 'WOWRestro modifier group "%s" contains options that could not be normalized.', 'wowrestro' ),
						$parent->name
					)
				);
			}
			$minimum = absint( self::first_term_meta( $parent->term_id, array( '_wowrestro_modifier_min', '_modifier_min' ) ) );
			$maximum = absint( self::first_term_meta( $parent->term_id, array( '_wowrestro_modifier_max', '_modifier_max' ) ) );
			$required = wc_string_to_bool( self::first_term_meta( $parent->term_id, array( '_wowrestro_modifier_required', '_modifier_required' ) ) );
			if ( $required && 0 === $minimum ) {
				$minimum = 1;
			}
			$minimum = min( count( $options ), $minimum );
			$maximum = 'single' === $type ? 1 : ( $maximum ? min( count( $options ), max( $minimum, $maximum ) ) : count( $options ) );
			$config = array(
				'_wowrestro_source_id'                    => $source_id,
				WowRestro_Modifiers::TYPE_META            => $type,
				WowRestro_Modifiers::MIN_META             => $minimum,
				WowRestro_Modifiers::MAX_META             => $maximum,
				WowRestro_Modifiers::OPTIONS_META         => $options,
				WowRestro_Modifiers::SCHEMA_VERSION_META  => WowRestro_Modifiers::SCHEMA_VERSION,
			);
			$group_id = self::modifier_group_for_source( $source_id );
			$created  = false;
			if ( ! $group_id ) {
				$group_id = self::modifier_group_for_legacy_term( $parent->term_id );
				$claimed  = $group_id ? (string) get_post_meta( $group_id, '_wowrestro_source_id', true ) : '';
				if ( $claimed && $source_id !== $claimed ) {
					return self::block_state(
						$state,
						sprintf(
							/* translators: %s: legacy modifier group name. */
							__( 'WOWRestro modifier group "%s" conflicts with an existing migration record.', 'wowrestro' ),
							$parent->name
						)
					);
				}
			}
			if ( ! $group_id ) {
				$group_id = wp_insert_post(
					array(
						'post_type'   => WowRestro_Modifiers::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => sanitize_text_field( $parent->name ),
						'post_name'   => self::modifier_group_slug( $parent->term_id ),
					),
					true
				);
				if ( is_wp_error( $group_id ) || ! $group_id ) {
					$message = is_wp_error( $group_id ) ? $group_id->get_error_message() : __( 'WordPress did not create the record.', 'wowrestro' );
					return self::block_state(
						$state,
						sprintf(
							/* translators: 1: legacy modifier group name, 2: database error. */
							__( 'WOWRestro modifier group "%1$s" could not be created: %2$s', 'wowrestro' ),
							$parent->name,
							$message
						)
					);
				}
				$created = true;
			}

			$changed = $created || ! self::post_config_matches( $group_id, $config );
			foreach ( $config as $meta_key => $meta_value ) {
				if ( ! self::write_post_meta( $group_id, $meta_key, $meta_value ) ) {
					return self::block_state(
						$state,
						sprintf(
							/* translators: 1: legacy modifier group name, 2: metadata key. */
							__( 'WOWRestro modifier group "%1$s" could not save configuration %2$s.', 'wowrestro' ),
							$parent->name,
							$meta_key
						)
					);
				}
			}
			$state[ $changed ? 'migrated' : 'skipped' ]++;
		}
		return true;
	}

	/**
	 * Process one page. Returns true after the final page.
	 */
	private static function migrate_products( &$state ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => self::BATCH_SIZE,
				'paged'          => max( 1, absint( $state['page'] ) ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		$upgrade_from = (string) get_option( 'wowrestro_upgrade_from', '' );
		foreach ( $query->posts as $product_id ) {
			$changed = false;
			if ( ! metadata_exists( 'post', $product_id, '_wowrestro_menu_item' ) ) {
				$is_wow = wc_string_to_bool( get_post_meta( $product_id, '_food_item', true ) );
				$is_v02 = $upgrade_from && version_compare( $upgrade_from, '0.3.0', '<' ) && 'publish' === get_post_status( $product_id );
				if ( $is_wow || $is_v02 ) {
					if ( ! self::write_post_meta( $product_id, '_wowrestro_menu_item', 'yes' ) ) {
						self::block_state(
							$state,
							sprintf( __( 'Product #%d could not be marked as a WowRestro menu item.', 'wowrestro' ), $product_id )
						);
						wp_reset_postdata();
						return false;
					}
					$changed = true;
				}
			}
			$assignments = self::migrate_product_modifier_assignments( $product_id );
			if ( is_wp_error( $assignments ) ) {
				self::block_state(
					$state,
					sprintf( __( 'Product #%1$d modifier assignments could not be migrated: %2$s', 'wowrestro' ), $product_id, $assignments->get_error_message() )
				);
				wp_reset_postdata();
				return false;
			}
			if ( $assignments ) {
				$changed = true;
			}
			$state[ $changed ? 'migrated' : 'skipped' ]++;
		}
		$done = $state['page'] >= max( 1, absint( $query->max_num_pages ) );
		$state['page']++;
		wp_reset_postdata();
		return $done;
	}

	private static function migrate_product_modifier_assignments( $product_id ) {
		if ( ! class_exists( 'WowRestro_Modifiers' ) || metadata_exists( 'post', $product_id, WowRestro_Modifiers::PRODUCT_GROUPS_META ) ) {
			return false;
		}
		// WOWRestro 1.3.1 stores parent IDs and selected child IDs in separate,
		// flat arrays. Its older combined field is used only as a fallback.
		$category_ids = self::integer_list( get_post_meta( $product_id, 'modifier_categories', true ) );
		if ( ! $category_ids ) {
			$category_ids = self::integer_list( get_post_meta( $product_id, '_wowrestro_modifier_categories', true ) );
		}
		$child_ids           = array();
		$child_source_exists = false;
		foreach ( array( 'modifier_category_items', 'food_item_modifier_category_items', '_wowrestro_modifier_category_items' ) as $child_key ) {
			if ( metadata_exists( 'post', $product_id, $child_key ) ) {
				$child_ids           = self::nested_integer_list( get_post_meta( $product_id, $child_key, true ) );
				$child_source_exists = true;
				break;
			}
		}
		$unresolved_groups  = array();
		$unresolved_options = array();
		$parent_source_exists = ! empty( $category_ids );
		if ( ! $category_ids || ! $child_source_exists ) {
			$combined = self::integer_list( get_post_meta( $product_id, 'food_item_modifier_categories', true ) );
			foreach ( $combined as $term_id ) {
				$term = get_term( $term_id, 'food_modifiers' );
				if ( ! $term instanceof WP_Term ) {
					$unresolved_groups[] = $term_id;
					continue;
				}
				if ( $term->parent && ! $child_source_exists ) {
					$child_ids[] = $term_id;
				} elseif ( ! $term->parent && ! $parent_source_exists ) {
					$category_ids[] = $term_id;
				}
			}
		}
		$category_ids = array_values( array_unique( array_map( 'absint', $category_ids ) ) );
		$child_ids    = array_values( array_unique( array_map( 'absint', $child_ids ) ) );
		if ( ! $category_ids && ! $unresolved_groups ) {
			return false;
		}
		$children_by_parent = array();
		foreach ( $child_ids as $child_id ) {
			$child = get_term( $child_id, 'food_modifiers' );
			if ( ! $child instanceof WP_Term || ! $child->parent || ! in_array( absint( $child->parent ), $category_ids, true ) ) {
				$unresolved_options[] = '?:' . $child_id;
				continue;
			}
			$children_by_parent[ absint( $child->parent ) ][] = $child_id;
		}
		$group_ids   = array();
		$allowlists  = array();
		foreach ( $category_ids as $term_id ) {
			$group_id = self::modifier_group_for_source( 'wowrestro:term:' . absint( $term_id ) );
			if ( ! $group_id ) {
				$unresolved_groups[] = absint( $term_id );
				continue;
			}
			$allowed     = array();
			$raw_allowed = $children_by_parent[ $term_id ] ?? array();
			if ( ! $raw_allowed ) {
				continue;
			}
			$group_ids[] = $group_id;
			$options     = get_post_meta( $group_id, WowRestro_Modifiers::OPTIONS_META, true );
			$option_ids  = is_array( $options ) ? array_map( 'strval', wp_list_pluck( $options, 'id' ) ) : array();
			foreach ( self::integer_list( $raw_allowed ) as $child_id ) {
				$option_id = 'wow-' . $child_id;
				if ( ! in_array( $option_id, $option_ids, true ) ) {
					$unresolved_options[] = absint( $term_id ) . ':' . absint( $child_id );
					continue;
				}
				$allowed[] = $option_id;
			}
			if ( $allowed ) {
				$allowlists[ $group_id ] = array_values( array_unique( $allowed ) );
			}
		}
		if ( $unresolved_groups || $unresolved_options ) {
			$details = array();
			if ( $unresolved_groups ) {
				$details[] = sprintf( __( 'groups: %s', 'wowrestro' ), implode( ', ', array_slice( array_unique( $unresolved_groups ), 0, 20 ) ) );
			}
			if ( $unresolved_options ) {
				$details[] = sprintf( __( 'options: %s', 'wowrestro' ), implode( ', ', array_slice( array_unique( $unresolved_options ), 0, 20 ) ) );
			}
			return new WP_Error(
				'wowrestro_assignment_source_missing',
				sprintf( __( 'Legacy modifier sources could not be resolved (%s).', 'wowrestro' ), implode( '; ', $details ) )
			);
		}
		if ( ! $group_ids ) {
			return false;
		}
		$group_ids = array_values( array_unique( array_map( 'absint', $group_ids ) ) );
		if ( $allowlists && defined( 'WowRestro_Modifiers::PRODUCT_OPTION_ALLOWLISTS_META' ) ) {
			if ( ! self::write_post_meta( $product_id, WowRestro_Modifiers::PRODUCT_OPTION_ALLOWLISTS_META, $allowlists ) ) {
				return new WP_Error( 'wowrestro_assignment_allowlist_write_failed', __( 'The modifier option allow-list could not be saved.', 'wowrestro' ) );
			}
		}
		// Store groups last: this is the commit marker used to distinguish a
		// completed assignment from an interrupted allow-list write.
		if ( ! self::write_post_meta( $product_id, WowRestro_Modifiers::PRODUCT_GROUPS_META, $group_ids ) ) {
			return new WP_Error( 'wowrestro_assignment_groups_write_failed', __( 'The modifier groups could not be saved.', 'wowrestro' ) );
		}
		return true;
	}

	private static function migrate_orders( &$state ) {
		$statuses = array_map(
			function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			array_keys( wc_get_order_statuses() )
		);
		$statuses = array_values(
			array_unique(
				array_merge(
					$statuses,
					array( 'wr-new', 'wr-accepted', 'wr-preparing', 'wr-ready', 'wr-out-for-delivery' )
				)
			)
		);
		$query = wc_get_orders(
			array(
				'limit'    => self::BATCH_SIZE,
				'page'     => max( 1, absint( $state['page'] ) ),
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'status'   => $statuses,
			)
		);
		$orders = is_object( $query ) && isset( $query->orders ) ? $query->orders : array();
		foreach ( $orders as $order ) {
			$changed = self::migrate_order( $order );
			if ( is_wp_error( $changed ) ) {
				$message = sprintf( __( 'Order #%1$d could not be migrated safely: %2$s', 'wowrestro' ), $order->get_id(), $changed->get_error_message() );
				$state['errors'][]          = sanitize_text_field( $message );
				$state['blocking_errors'][] = sanitize_text_field( $message );
				$state['skipped']++;
				continue;
			}
			$state[ $changed ? 'migrated' : 'skipped' ]++;
		}
		$state['errors']          = array_slice( array_values( array_unique( $state['errors'] ?? array() ) ), -100 );
		$state['blocking_errors'] = array_slice( array_values( array_unique( $state['blocking_errors'] ?? array() ) ), -100 );
		$max_pages = is_object( $query ) && isset( $query->max_num_pages ) ? absint( $query->max_num_pages ) : 1;
		$done      = $state['page'] >= max( 1, $max_pages );
		$state['page']++;
		return $done;
	}

	private static function migrate_order( $order ) {
		$changed        = false;
		$legacy_service = $order->get_meta( '_wowrestro_service_type' );
		$mode           = $order->get_meta( '_wowrestro_fulfillment' );
		if ( ! $mode ) {
			$mode = $order->get_meta( '_wowrestro_mode' ) ?: $legacy_service;
			$mode = false !== strpos( strtolower( (string) $mode ), 'deliver' ) ? 'delivery' : 'pickup';
			if ( $order->get_meta( '_wowrestro_mode' ) || $legacy_service ) {
				$order->update_meta_data( '_wowrestro_fulfillment', $mode );
				$order->update_meta_data( '_wowrestro_mode', $mode );
				$order->update_meta_data( '_wowrestro_order', 'yes' );
				$changed = true;
			}
		}
		if ( $mode && 'yes' !== $order->get_meta( '_wowrestro_order' ) ) {
			$order->update_meta_data( '_wowrestro_order', 'yes' );
			$changed = true;
		}

		$promise = $order->get_meta( '_wowrestro_promised_at' );
		if ( ! $promise ) {
			$date = $order->get_meta( '_wowrestro_service_date' );
			$time = $order->get_meta( '_wowrestro_service_time' );
			if ( $date && $time && ! self::is_legacy_asap( $time ) ) {
				$parsed = self::parse_legacy_service_datetime( $date, $time );
				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}
				$promise = $parsed->format( DATE_ATOM );
			}
		}
		if ( $promise && ! $order->get_meta( '_wowrestro_promised_at_gmt' ) ) {
			try {
				$local = ( new DateTimeImmutable( sanitize_text_field( $promise ), wp_timezone() ) )->setTimezone( wp_timezone() );
				$parse_errors = DateTimeImmutable::getLastErrors();
				if ( is_array( $parse_errors ) && ( ! empty( $parse_errors['warning_count'] ) || ! empty( $parse_errors['error_count'] ) ) ) {
					throw new UnexpectedValueException( 'invalid legacy promised time' );
				}
				$gmt   = $local->setTimezone( new DateTimeZone( 'UTC' ) );
				$lead  = 'delivery' === $mode ? absint( WowRestro_Fulfillment::settings()['delivery_lead_minutes'] ) : 0;
				$order->update_meta_data( '_wowrestro_promised_at', $local->format( DATE_ATOM ) );
				$order->update_meta_data( '_wowrestro_promised_at_gmt', $gmt->format( 'Y-m-d H:i:s' ) );
				$order->update_meta_data( '_wowrestro_kitchen_slot_gmt', $gmt->modify( '-' . $lead . ' minutes' )->format( 'Y-m-d H:i:s' ) );
				$changed = true;
			} catch ( Exception $exception ) {
				return new WP_Error( 'wowrestro_invalid_legacy_promise', __( 'The legacy promised time is invalid and needs manual correction.', 'wowrestro' ) );
			}
		}

		$legacy_status       = $order->get_status();
		$stored_operational  = sanitize_key( (string) $order->get_meta( '_wowrestro_operational_status' ) );
		$legacy_operational  = $stored_operational ?: ( 0 === strpos( $legacy_status, 'wr-' ) ? $legacy_status : '' );
		if ( ! $legacy_operational && 'yes' === $order->get_meta( '_wowrestro_order' ) ) {
			if ( 'completed' === $legacy_status ) {
				$legacy_operational = 'completed';
			} elseif ( in_array( $legacy_status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
				$legacy_operational = 'cancelled';
			} else {
				// WOWRestro used ordinary Woo payment states, so an active migrated
				// order must be seeded into the restaurant queue explicitly.
				$legacy_operational = 'new';
			}
		}
		if ( $legacy_operational ) {
			$operational = class_exists( 'WowRestro_Order_Statuses' ) ? WowRestro_Order_Statuses::normalize( $legacy_operational ) : str_replace( '-', '_', preg_replace( '/^(?:wc-)?wr-/', '', $legacy_operational ) );
			if ( ! in_array( $operational, array( 'new', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled' ), true ) ) {
				return new WP_Error( 'wowrestro_invalid_legacy_status', __( 'The legacy restaurant workflow status is invalid and needs manual correction.', 'wowrestro' ) );
			}
			if ( $stored_operational !== $operational ) {
				$order->update_meta_data( '_wowrestro_operational_status', $operational );
				$changed = true;
			}
			if ( ! $order->get_meta( '_wowrestro_operational_history' ) ) {
				$order->update_meta_data(
					'_wowrestro_operational_history',
					array(
						array(
							'status'   => $operational,
							'at_gmt'   => gmdate( 'Y-m-d H:i:s' ),
							'user_id'  => 0,
							'reason'   => __( 'Migrated from the legacy restaurant workflow.', 'wowrestro' ),
							'override' => false,
						),
					)
				);
				$changed = true;
			}
		}
		if ( 'yes' === $order->get_meta( '_wowrestro_order' ) && ! $order->get_meta( '_wowrestro_source' ) ) {
			$order->update_meta_data( '_wowrestro_source', $legacy_service ? 'wowrestro' : 'web' );
			$order->update_meta_data( '_wowrestro_payment_flow', 'online' );
			$changed = true;
		}
		if ( 'yes' === $order->get_meta( '_wowrestro_order' ) && ! $order->get_meta( '_wowrestro_tracking_token' ) ) {
			$order->update_meta_data( '_wowrestro_tracking_token', self::opaque_token() );
			$changed = true;
		}

		foreach ( $order->get_items() as $item ) {
			$legacy_note = $item->get_meta( '_special_note' );
			if ( ! $item->get_meta( '_wowrestro_special_note' ) && $legacy_note ) {
				$note = self::limit_text( sanitize_textarea_field( $legacy_note ), 500 );
				$item->update_meta_data( '_wowrestro_special_note', $note );
				$item->update_meta_data( '_wowrestro_item_note', $note );
				$item->save();
				$changed = true;
			}
			$legacy_modifiers = $item->get_meta( '_modifier_items' );
			if ( ! $item->get_meta( '_wowrestro_modifier_snapshot' ) && $legacy_modifiers ) {
				$item->update_meta_data( '_wowrestro_modifier_snapshot', self::legacy_modifier_snapshot( $legacy_modifiers ) );
				$item->update_meta_data( '_wowrestro_modifiers', wc_clean( $legacy_modifiers ) );
				$item->save();
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
		if ( 'yes' === $order->get_meta( '_wowrestro_order' ) && method_exists( 'WowRestro_Slot_Reservations', 'backfill_order' ) ) {
			$backfilled = WowRestro_Slot_Reservations::backfill_order( $order );
			if ( is_wp_error( $backfilled ) ) {
				return $backfilled;
			}
		}
		return $changed;
	}

	/**
	 * Acquire an atomic, expiring lease for migration state changes.
	 *
	 * The add_option() call wins the uncontended race. Expired leases are replaced with
	 * one compare-and-swap query so two recovery requests cannot both proceed.
	 *
	 * @return array<string,mixed>|false
	 */
	private static function acquire_batch_lock() {
		$lock = array(
			'token'   => self::opaque_token(),
			'expires' => time() + self::LOCK_TTL,
		);
		if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
			return $lock;
		}

		$current = get_option( self::LOCK_OPTION, null );
		if ( is_array( $current ) && absint( $current['expires'] ?? 0 ) >= time() ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One atomic compare-and-swap is required for lease recovery.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $lock ),
				self::LOCK_OPTION,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === $updated ? $lock : false;
	}

	/** Release only the exact lease acquired by this request. */
	private static function release_batch_lock( $lock ) {
		if ( ! is_array( $lock ) || empty( $lock['token'] ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Owner-safe release must compare the full lease value.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_OPTION,
				maybe_serialize( $lock )
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	private static function schedule_next() {
		$current = current_action();
		$running_batch = in_array( $current, array( self::ACTION, self::CONTINUE_ACTION ), true );
		$next = self::ACTION === $current ? self::CONTINUE_ACTION : self::ACTION;
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			$primary_scheduled  = as_has_scheduled_action( self::ACTION, array(), 'wowrestro' );
			$continue_scheduled = as_has_scheduled_action( self::CONTINUE_ACTION, array(), 'wowrestro' );
			if ( ! $running_batch && ( $primary_scheduled || $continue_scheduled ) ) {
				return;
			}
			if ( ! as_has_scheduled_action( $next, array(), 'wowrestro' ) ) {
				as_schedule_single_action( time() + 5, $next, array(), 'wowrestro', true );
			}
			return;
		}
		$primary_scheduled  = wp_next_scheduled( self::ACTION );
		$continue_scheduled = wp_next_scheduled( self::CONTINUE_ACTION );
		if ( ! $running_batch && ( $primary_scheduled || $continue_scheduled ) ) {
			return;
		}
		if ( ! wp_next_scheduled( $next ) ) {
			wp_schedule_single_event( time() + 5, $next );
		}
	}

	public static function checkout_blocked() {
		return self::wowrestro_active() || ( 'yes' === get_option( 'wowrestro_legacy_detected', 'no' ) && self::DATA_VERSION !== (string) get_option( 'wowrestro_data_version', '' ) );
	}

	public static function notice() {
		if ( ! current_user_can( 'wowrestro_manage' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( self::wowrestro_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WOWRestro 1.x and this plugin must not process checkout together. Complete migration, then deactivate WOWRestro 1.x.', 'wowrestro' ) . '</p></div>';
		}
		$state = get_option( 'wowrestro_migration_state', array() );
		if ( is_array( $state ) && 'blocked' === ( $state['phase'] ?? '' ) ) {
			$url             = wp_nonce_url( admin_url( 'admin-post.php?action=wowrestro_migrate_legacy' ), 'wowrestro_migrate_legacy' );
			$errors          = isset( $state['blocking_errors'] ) && is_array( $state['blocking_errors'] ) ? $state['blocking_errors'] : array();
			$error           = $errors ? sanitize_text_field( (string) end( $errors ) ) : '';
			$diagnostics_url = admin_url( 'admin.php?page=wowrestro-diagnostics' );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WowRestro migration stopped because one or more records could not be migrated safely.', 'wowrestro' );
			if ( $error ) {
				echo ' <strong>' . esc_html__( 'Last error:', 'wowrestro' ) . '</strong> ' . esc_html( $error );
			}
			echo ' <a href="' . esc_url( $diagnostics_url ) . '">' . esc_html__( 'Open diagnostics', 'wowrestro' ) . '</a> <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Retry migration', 'wowrestro' ) . '</a></p></div>';
		} elseif ( is_array( $state ) && ! empty( $state['phase'] ) && 'complete' !== $state['phase'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( 'WowRestro migration is running: %1$s (%2$d migrated, %3$d skipped).', 'wowrestro' ), sanitize_text_field( $state['phase'] ), absint( $state['migrated'] ), absint( $state['skipped'] ) ) ) . '</p></div>';
		} elseif ( self::has_legacy_data() && self::DATA_VERSION !== (string) get_option( 'wowrestro_data_version', '' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=wowrestro_migrate_legacy' ), 'wowrestro_migrate_legacy' );
			echo '<div class="notice notice-info"><p>' . esc_html__( 'WowRestro found legacy restaurant data.', 'wowrestro' ) . ' <a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Start safe migration', 'wowrestro' ) . '</a></p></div>';
		}
	}

	private static function record_error( $message, $blocking = false ) {
		$state = get_option( 'wowrestro_migration_state', array() );
		$state = is_array( $state ) ? $state : array();
		$state['errors']   = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();
		$state['errors'][] = sanitize_text_field( $message );
		$state['errors']   = array_slice( $state['errors'], -20 );
		if ( $blocking ) {
			$state['blocking_errors']   = isset( $state['blocking_errors'] ) && is_array( $state['blocking_errors'] ) ? $state['blocking_errors'] : array();
			$state['blocking_errors'][] = sanitize_text_field( $message );
			$state['blocking_errors']   = array_slice( $state['blocking_errors'], -20 );
			$state['phase']             = 'blocked';
		}
		update_option( 'wowrestro_migration_state', $state, false );
	}

	/** Add one fatal phase error to the in-memory batch state. */
	private static function block_state( &$state, $message ) {
		$message                  = sanitize_text_field( $message );
		$state['errors']          = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();
		$state['blocking_errors'] = isset( $state['blocking_errors'] ) && is_array( $state['blocking_errors'] ) ? $state['blocking_errors'] : array();
		$state['errors'][]          = $message;
		$state['blocking_errors'][] = $message;
		$state['errors']          = array_slice( array_values( array_unique( $state['errors'] ) ), -100 );
		$state['blocking_errors'] = array_slice( array_values( array_unique( $state['blocking_errors'] ) ), -100 );
		return false;
	}

	private static function integer_list( $value ) {
		if ( ! is_array( $value ) ) {
			$value = preg_split( '/[\s,|]+/', (string) $value );
		}
		$ids = array_map( 'absint', $value );
		return array_values( array_filter( $ids ) );
	}

	/** Flatten legacy selected-ID arrays without treating associative parent keys as IDs. */
	private static function nested_integer_list( $value ) {
		if ( ! is_array( $value ) ) {
			return self::integer_list( $value );
		}
		$ids = array();
		array_walk_recursive(
			$value,
			function ( $item ) use ( &$ids ) {
				$id = absint( $item );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		);
		return array_values( array_unique( $ids ) );
	}

	/** Match WOWRestro's configurable ASAP display label as well as its default. */
	private static function is_legacy_asap( $value ) {
		$normalize = static function ( $text ) {
			return strtolower( trim( sanitize_text_field( (string) $text ) ) );
		};
		$current = $normalize( $value );
		return 'asap' === $current || $normalize( get_option( '_wowrestro_asap_text', 'ASAP' ) ) === $current;
	}

	/** Parse the source's site-formatted service date and time without ambiguity. */
	private static function parse_legacy_service_datetime( $date, $time ) {
		$date       = sanitize_text_field( (string) $date );
		$time_parts = preg_split( '/\s+(?:-|\x{2013}|\x{2014})\s+/u', sanitize_text_field( (string) $time ), 2 );
		$time       = trim( (string) reset( $time_parts ) );
		$date_format = (string) get_option( 'date_format', 'Y-m-d' );
		$time_formats = array_values( array_unique( array_filter( array( (string) get_option( 'time_format', 'g:i a' ), 'H:i', 'H:i:s', 'g:i a', 'g:i A', 'h:i A' ) ) ) );
		foreach ( $time_formats as $time_format ) {
			$parsed = DateTimeImmutable::createFromFormat( '!' . $date_format . ' ' . $time_format, $date . ' ' . $time, wp_timezone() );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $parsed instanceof DateTimeImmutable && ( false === $errors || ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) ) ) {
				return $parsed;
			}
		}
		try {
			$parsed = new DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
			$errors = DateTimeImmutable::getLastErrors();
			if ( false === $errors || ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) ) {
				return $parsed;
			}
		} catch ( Exception $exception ) {
			// Return the same safe migration error below.
		}
		return new WP_Error( 'wowrestro_invalid_legacy_promise', __( 'The legacy promised time is invalid and needs manual correction.', 'wowrestro' ) );
	}

	private static function modifier_group_for_source( $source_id ) {
		if ( ! class_exists( 'WowRestro_Modifiers' ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => WowRestro_Modifiers::POST_TYPE,
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wowrestro_source_id',
				'meta_value'     => sanitize_text_field( $source_id ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return $ids ? absint( reset( $ids ) ) : 0;
	}

	/** Recover a group created just before an interrupted source-meta write. */
	private static function modifier_group_for_legacy_term( $term_id ) {
		if ( ! class_exists( 'WowRestro_Modifiers' ) ) {
			return 0;
		}
		$post = get_page_by_path( self::modifier_group_slug( $term_id ), OBJECT, WowRestro_Modifiers::POST_TYPE );
		return $post instanceof WP_Post ? absint( $post->ID ) : 0;
	}

	private static function modifier_group_slug( $term_id ) {
		return 'wowrestro-wowrestro-term-' . absint( $term_id );
	}

	/** Return true when every stored value already equals the desired config. */
	private static function post_config_matches( $post_id, $config ) {
		foreach ( $config as $meta_key => $meta_value ) {
			if ( ! self::meta_values_match( get_post_meta( $post_id, $meta_key, true ), $meta_value ) ) {
				return false;
			}
		}
		return true;
	}

	/** Write and read back metadata so filtered or failed writes cannot pass. */
	private static function write_post_meta( $post_id, $meta_key, $meta_value ) {
		update_post_meta( $post_id, $meta_key, $meta_value );
		return self::meta_values_match( get_post_meta( $post_id, $meta_key, true ), $meta_value );
	}

	/** Verify an option write without mistaking an unchanged value for failure. */
	private static function option_value_matches( $option_name, $expected ) {
		$missing = new stdClass();
		$actual  = get_option( $option_name, $missing );
		return $missing !== $actual && self::meta_values_match( $actual, $expected );
	}

	private static function meta_values_match( $actual, $expected ) {
		if ( is_array( $actual ) || is_array( $expected ) || is_object( $actual ) || is_object( $expected ) ) {
			return wp_json_encode( $actual ) === wp_json_encode( $expected );
		}
		return (string) $actual === (string) $expected;
	}

	private static function first_term_meta( $term_id, $keys ) {
		foreach ( $keys as $key ) {
			if ( metadata_exists( 'term', $term_id, $key ) ) {
				return get_term_meta( $term_id, $key, true );
			}
		}
		return '';
	}

	private static function opaque_token() {
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( Exception $exception ) {
			return strtolower( wp_generate_password( 40, false, false ) );
		}
	}

	private static function limit_text( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length ) : substr( (string) $text, 0, $length );
	}

	/**
	 * Preserve legacy modifier choices as one immutable, plain-data snapshot.
	 * The original order-item metadata remains untouched alongside this copy.
	 */
	private static function legacy_modifier_snapshot( $legacy ) {
		$legacy  = maybe_unserialize( $legacy );
		$options = array();
		self::collect_legacy_modifier_options( $legacy, $options );
		return $options ? array(
			array(
				'id'        => 'wowrestro-legacy',
				'source_id' => 'wowrestro:_modifier_items',
				'name'      => __( 'Legacy modifiers', 'wowrestro' ),
				'type'      => 'multiple',
				'options'   => array_slice( $options, 0, 100 ),
			),
		) : array();
	}

	private static function collect_legacy_modifier_options( $value, &$options ) {
		if ( count( $options ) >= 100 ) {
			return;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			$label = sanitize_text_field( (string) $value );
			if ( '' !== $label ) {
				$options[] = array( 'id' => 'wow-' . substr( hash( 'sha256', $label . ':' . count( $options ) ), 0, 20 ), 'label' => $label, 'price' => 0.0 );
			}
			return;
		}

		// Exact WOWRestro 1.3.1 order-line shape. Structural quantity/price
		// fields are not modifier labels; the selected child slug is authoritative.
		if ( isset( $value['modifier_item'] ) ) {
			$modifier_item = is_object( $value['modifier_item'] ) ? get_object_vars( $value['modifier_item'] ) : $value['modifier_item'];
			if ( is_array( $modifier_item ) ) {
				$slug = sanitize_title( (string) ( $modifier_item['value'] ?? '' ) );
				if ( $slug ) {
					$term  = get_term_by( 'slug', $slug, 'food_modifiers' );
					$label = $term instanceof WP_Term ? sanitize_text_field( $term->name ) : sanitize_text_field( ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
					$price = $value['raw_price'] ?? $value['price'] ?? 0;
					$options[] = array(
						'id'    => $term instanceof WP_Term ? 'wow-' . absint( $term->term_id ) : 'wow-' . substr( hash( 'sha256', $slug ), 0, 20 ),
						'label' => $label,
						'price' => max( 0, (float) wc_format_decimal( $price ) ),
					);
					return;
				}
			}
		}

		$label = sanitize_text_field( $value['label'] ?? $value['name'] ?? $value['title'] ?? $value['modifier_name'] ?? '' );
		if ( '' !== $label ) {
			$price = $value['price'] ?? $value['amount'] ?? $value['modifier_price'] ?? 0;
			$id    = sanitize_key( $value['id'] ?? $value['term_id'] ?? '' );
			$options[] = array(
				'id'    => $id ? 'wow-' . substr( $id, 0, 48 ) : 'wow-' . substr( hash( 'sha256', $label . ':' . count( $options ) ), 0, 20 ),
				'label' => $label,
				'price' => max( 0, (float) wc_format_decimal( $price ) ),
			);
			return;
		}

		foreach ( $value as $key => $child ) {
			if ( ! is_int( $key ) && is_numeric( $child ) ) {
				$label = sanitize_text_field( (string) $key );
				if ( '' !== $label ) {
					$options[] = array( 'id' => 'wow-' . substr( hash( 'sha256', $label . ':' . count( $options ) ), 0, 20 ), 'label' => $label, 'price' => max( 0, (float) wc_format_decimal( $child ) ) );
				}
				continue;
			}
			self::collect_legacy_modifier_options( $child, $options );
		}
	}

	public static function has_legacy_data() {
		$upgrade_from = (string) get_option( 'wowrestro_upgrade_from', '' );
		$installed    = (string) get_option( 'wowrestro_version', '' );
		return ( $upgrade_from && version_compare( $upgrade_from, '0.3.0', '<' ) ) || ( $installed && version_compare( $installed, '0.3.0', '<' ) ) || self::has_wowrestro_data() || self::has_legacy_orders();
	}

	private static function has_wowrestro_data() {
		if ( self::wowrestro_active() || taxonomy_exists( 'food_modifiers' ) || false !== get_option( '_wowrestro_open_time', false ) ) {
			return true;
		}
		global $wpdb;
		$term = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", 'food_modifiers' ) );
		if ( $term ) {
			return true;
		}
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_food_item',
				'meta_value'     => array( 'yes', '1', 'true' ),
				'meta_compare'   => 'IN',
				'no_found_rows'  => true,
			)
		);
		return ! empty( $products ) || self::has_legacy_orders( true );
	}

	/** Detect legacy restaurant orders through Woo's active CPT or HPOS store. */
	private static function has_legacy_orders( $wowrestro_only = false ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$statuses = array_map(
			function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			array_keys( wc_get_order_statuses() )
		);
		$statuses = array_values(
			array_unique(
				array_merge( $statuses, array( 'wr-new', 'wr-accepted', 'wr-preparing', 'wr-ready', 'wr-out-for-delivery' ) )
			)
		);
		$keys = array( '_wowrestro_service_type', '_wowrestro_service_date', '_wowrestro_service_time' );
		if ( ! $wowrestro_only ) {
			$keys = array_merge( array( '_wowrestro_mode', '_wowrestro_promised_at' ), $keys );
		}
		$meta_query = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'compare' => 'EXISTS',
			);
		}
		try {
			$order_ids = WowRestro_Order_Statuses::query_orders(
				array(
					'limit'      => 10,
					'return'     => 'ids',
					'status'     => $statuses,
					'meta_query' => $meta_query,
				)
			);
			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( absint( $order_id ) );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				foreach ( $keys as $key ) {
					if ( '' !== $order->get_meta( $key ) ) {
						return true;
					}
				}
			}
			return false;
		} catch ( Throwable $exception ) {
			// A failed discovery query must never make activation skip migration.
			return true;
		}
	}

	/**
	 * Whether the WOWRestro 1.x plugin is running next to this one. This plugin shares
	 * its name, constants and folder, so only 1.x's own constant can identify it.
	 */
	private static function wowrestro_active() {
		return defined( 'WWRO_PLUGIN_FILE' );
	}

	private static function legacy_setting_keys() {
		return array(
			'enable_pickup',
			'enable_delivery',
			'_wowrestro_food_prepation_time',
			'pickup_time_interval',
			'delivery_time_interval',
			'_wowrestro_min_delivery_order_amount',
			'_wowrestro_open_time',
			'_wowrestro_close_time',
			'_wowrestro_asap_text',
			'wowrestro_settings',
		);
	}

	private static function normalize_time( $value, $fallback ) {
		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? $fallback : gmdate( 'H:i', $timestamp );
	}
}
