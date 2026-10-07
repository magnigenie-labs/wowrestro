<?php
/**
 * Restaurant product opt-in and menu labels.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Products {
	const MENU_ITEM_META       = '_wowrestro_menu_item';
	const CATALOG_SYNC_OPTION  = 'wowrestro_catalog_sync_version';
	const CATALOG_SYNC_VERSION = '1';
	const EU14                 = array( 'Cereals containing gluten', 'Crustaceans', 'Eggs', 'Fish', 'Peanuts', 'Soybeans', 'Milk', 'Nuts', 'Celery', 'Mustard', 'Sesame', 'Sulphur dioxide and sulphites', 'Lupin', 'Molluscs' );

	/**
	 * Register product-editor hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'sync_new_product' ), 20 );
		add_action( 'init', array( __CLASS__, 'seed_attribute_terms' ), 30 );
		add_action( 'init', array( __CLASS__, 'sync_catalog' ), 40 );
	}

	/**
	 * Backfill WowRestro membership onto existing supported WooCommerce products.
	 *
	 * WooCommerce remains the only catalogue. This marker only makes the same
	 * product visible in WowRestro and is written once per catalogue sync version.
	 */
	public static function sync_catalog() {
		if ( self::CATALOG_SYNC_VERSION === (string) get_option( self::CATALOG_SYNC_OPTION, '' ) ) {
			return;
		}
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-time bounded catalogue backfill.
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'simple', 'variable' ),
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-time bounded catalogue backfill.
					array(
						'key'     => self::MENU_ITEM_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				$product->update_meta_data( self::MENU_ITEM_META, 'yes' );
				$product->save_meta_data();
			}
		}
		if ( count( $product_ids ) < 100 ) {
			update_option( self::CATALOG_SYNC_OPTION, self::CATALOG_SYNC_VERSION, false );
		}
	}

	/**
	 * Automatically include supported WooCommerce products created later.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public static function sync_new_product( $product_id ) {
		if ( metadata_exists( 'post', $product_id, self::MENU_ITEM_META ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product && in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) {
			$product->update_meta_data( self::MENU_ITEM_META, 'yes' );
			$product->save_meta_data();
		}
	}

	public static function install_attributes() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) || ! function_exists( 'wc_create_attribute' ) ) {
			return;
		}
		$existing = array_map(
			function ( $attribute ) {
				return $attribute->attribute_name;
			},
			wc_get_attribute_taxonomies()
		);
		foreach ( array(
			'dietary'   => __( 'Dietary', 'wowrestro' ),
			'allergens' => __( 'Allergens', 'wowrestro' ),
		) as $slug => $label ) {
			if ( ! in_array( $slug, $existing, true ) ) {
				wc_create_attribute(
					array(
						'name'         => $label,
						'slug'         => $slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					)
				);
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		update_option( 'wowrestro_seed_product_labels', 'yes', false );
	}

	public static function seed_attribute_terms() {
		if ( 'yes' !== get_option( 'wowrestro_seed_product_labels', 'no' ) ) {
			return;
		}
		$sets = array(
			'pa_dietary'   => array( 'Veg', 'Non-Veg' ),
			'pa_allergens' => self::EU14,
		);
		foreach ( $sets as $taxonomy => $labels ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue; }
			foreach ( $labels as $label ) {
				if ( ! term_exists( $label, $taxonomy ) ) {
					wp_insert_term( $label, $taxonomy ); }
			}
		}
		update_option( 'wowrestro_seed_product_labels', 'no', false );
	}

	/** Add a dedicated restaurant tab to the native WooCommerce product editor. */
	public static function product_tab( $tabs ) {
		$tabs['wowrestro'] = array(
			'label'    => __( 'WowRestro', 'wowrestro' ),
			'target'   => 'wowrestro_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	/** Render restaurant controls while WooCommerce retains product authority. */
	public static function product_panel() {
		$settings = WowRestro_Fulfillment::settings();
		?>
		<div id="wowrestro_product_data" class="panel woocommerce_options_panel hidden">
			<input type="hidden" name="wowrestro_product_panel_present" value="yes">
			<div class="options_group">
				<?php self::product_fields(); ?>
			</div>
			<div class="wowrestro-product-help">
				<p><strong><?php esc_html_e( 'WooCommerce still controls this product.', 'wowrestro' ); ?></strong> <?php esc_html_e( 'Use the Inventory, Shipping, Attributes and Linked Products tabs for stock, tax, variations, dietary/allergen terms and cross-sells.', 'wowrestro' ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Dietary attribute: %1$s · Allergen attribute: %2$s', 'wowrestro' ), $settings['dietary_attribute'], $settings['allergen_attribute'] ) ); ?>
				<?php
				if ( current_user_can( 'manage_product_terms' ) ) :
					?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=product_attributes' ) ); ?>"><?php esc_html_e( 'Manage attributes', 'wowrestro' ); ?></a><?php endif; ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Add the explicit restaurant-menu opt-in to the WooCommerce product editor.
	 */
	public static function product_fields() {
		global $post;
		$value = $post instanceof WP_Post ? get_post_meta( $post->ID, self::MENU_ITEM_META, true ) : '';
		if ( $post instanceof WP_Post && 'auto-draft' === $post->post_status ) {
			$value = 'yes';
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => self::MENU_ITEM_META,
				'label'       => __( 'WowRestro menu item', 'wowrestro' ),
				'description' => __( 'Sell this product through the restaurant menu. Menu items and regular shop products can share one WooCommerce order.', 'wowrestro' ),
				'cbvalue'     => 'yes',
				'value'       => $value,
			)
		);
	}

	/**
	 * Persist the restaurant-menu opt-in through the product CRUD object.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product_fields( $product ) {
		if ( ! isset( $_POST['wowrestro_product_panel_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-editor nonce; absence identifies quick/bulk edits.
			return;
		}
		$product->update_meta_data( self::MENU_ITEM_META, isset( $_POST[ self::MENU_ITEM_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its product-editor nonce before this hook.
	}

	/**
	 * Whether a product belongs to the restaurant catalogue.
	 *
	 * Variations inherit the setting from their parent product.
	 *
	 * @param int|WC_Product $product Product ID or object.
	 * @return bool
	 */
	public static function is_menu_item( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		return $product instanceof WC_Product
			&& in_array( $product->get_type(), array( 'simple', 'variable' ), true )
			&& 'no' !== $product->get_meta( self::MENU_ITEM_META, true );
	}

	/**
	 * Return configured dietary and allergen labels for a product.
	 *
	 * @param int|WC_Product $product Product ID or object.
	 * @return array{dietary:array<int,string>,allergens:array<int,string>}
	 */
	public static function labels( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return array(
				'dietary'   => array(),
				'allergens' => array(),
			);
		}

		$settings  = get_option( 'wowrestro_settings', array() );
		$dietary   = sanitize_key( $settings['dietary_attribute'] ?? 'pa_dietary' );
		$allergens = sanitize_key( $settings['allergen_attribute'] ?? 'pa_allergens' );

		$dietary_values  = self::attribute_values( $product, $dietary, 'dietary' );
		$dietary_values  = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'dietary_flag' ), $dietary_values ) ) ) );
		$allowed         = array_combine( array_map( 'sanitize_title', self::EU14 ), self::EU14 );
		$allowed['gluten']    = self::EU14[0];
		$allowed['sulphites'] = self::EU14[11];
		$allergen_values = array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $value ) use ( $allowed ) {
							return $allowed[ sanitize_title( $value ) ] ?? '';
						},
						self::attribute_values( $product, $allergens, 'allergens' )
					)
				)
			)
		);
		return array(
			'dietary'   => $dietary_values,
			'allergens' => $allergen_values,
		);
	}

	private static function dietary_flag( $value ) {
		$value = sanitize_title( $value );
		if ( in_array( $value, array( 'veg', 'vegetarian', 'vegan' ), true ) ) {
			return 'Veg'; }
		if ( in_array( $value, array( 'non-veg', 'non-vegetarian', 'nonveg' ), true ) ) {
			return 'Non-Veg'; }
		return '';
	}

	/**
	 * Read a product attribute into individual, display-ready values.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $attribute Configured attribute name.
	 * @param string     $fallback Legacy custom-attribute name.
	 * @return array<int,string>
	 */
	private static function attribute_values( $product, $attribute, $fallback ) {
		$value = trim( (string) $product->get_attribute( $attribute ) );
		if ( '' === $value && $attribute !== $fallback ) {
			$value = trim( (string) $product->get_attribute( $fallback ) );
		}
		if ( '' === $value ) {
			return array();
		}

		$values = preg_split( '/\s*[,|]\s*/', wp_strip_all_tags( $value ) );
		return array_values( array_unique( array_filter( array_map( 'trim', $values ) ) ) );
	}
}
