<?php
/**
 * Reusable, server-validated menu-item modifiers.
 *
 * @package WowRestro
 */

defined( 'ABSPATH' ) || exit;

final class WowRestro_Modifiers {
	const POST_TYPE            = 'wowrestro_mod_group';
	const PRODUCT_GROUPS_META  = '_wowrestro_modifier_group_ids';
	const PRODUCT_OPTION_ALLOWLISTS_META = '_wowrestro_modifier_option_allowlists';
	const TYPE_META            = '_wowrestro_modifier_type';
	const MIN_META             = '_wowrestro_modifier_min';
	const MAX_META             = '_wowrestro_modifier_max';
	const OPTIONS_META         = '_wowrestro_modifier_options';
	const SCHEMA_VERSION_META  = '_wowrestro_modifier_schema_version';
	const SCHEMA_VERSION       = 1;

	/**
	 * Register modifier data and editor hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_group' ), 10, 2 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_groups' ), 30 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'product_fields' ), 15 );
	}

	/**
	 * Render assigned add-ons inside the native WooCommerce add-to-cart form.
	 */
	public static function product_fields() {
		global $product;

		if ( ! $product instanceof WC_Product || ! class_exists( 'WowRestro_Products' ) || ! WowRestro_Products::is_menu_item( $product ) ) {
			return;
		}

		$groups = self::groups_for_product( $product );
		if ( ! $groups ) {
			return;
		}

		wp_enqueue_style( 'wowrestro-storefront' );
		wp_enqueue_script( 'wowrestro-storefront' );
		?>
		<div class="wowrestro-product-addons" data-wowrestro-product-addons>
			<h3><?php esc_html_e( 'Add-ons', 'wowrestro' ); ?></h3>
			<?php
			foreach ( $groups as $group ) :
				$group_id = absint( $group['id'] );
				$single   = 'single' === $group['type'];
				$required = absint( $group['min'] ) > 0;
				$hint_id  = 'wowrestro-addon-hint-' . $product->get_id() . '-' . $group_id;
				?>
				<fieldset class="wowrestro-product-addons__group" data-addon-min="<?php echo esc_attr( absint( $group['min'] ) ); ?>" data-addon-max="<?php echo esc_attr( absint( $group['max'] ) ); ?>">
					<legend>
						<span>
							<?php echo esc_html( $group['name'] ); ?>
							<?php if ( $required ) : ?>
								*
							<?php endif; ?>
						</span>
						<small id="<?php echo esc_attr( $hint_id ); ?>">
						<?php
						if ( $single ) {
							echo $required ? esc_html__( 'Choose one', 'wowrestro' ) : esc_html__( 'Optional', 'wowrestro' );
						} elseif ( $required ) {
							/* translators: 1: minimum choices, 2: maximum choices. */
							echo esc_html( sprintf( __( 'Choose %1$d–%2$d', 'wowrestro' ), absint( $group['min'] ), absint( $group['max'] ) ) );
						} else {
							echo esc_html__( 'Choose any', 'wowrestro' );
						}
						?>
						</small>
					</legend>
					<div class="wowrestro-product-addons__options">
					<?php
					foreach ( $group['options'] as $option ) :
						$input_id = 'wowrestro-addon-' . $product->get_id() . '-' . $group_id . '-' . sanitize_html_class( $option['id'] );
						?>
						<label for="<?php echo esc_attr( $input_id ); ?>" class="wowrestro-product-addons__option">
							<input
								id="<?php echo esc_attr( $input_id ); ?>"
								type="<?php echo esc_attr( $single ? 'radio' : 'checkbox' ); ?>"
								name="wowrestro_modifiers[<?php echo esc_attr( $group_id ); ?>][]"
								value="<?php echo esc_attr( $option['id'] ); ?>"
								aria-describedby="<?php echo esc_attr( $hint_id ); ?>"
								<?php if ( ! empty( $option['default'] ) ) : ?>
									checked
								<?php endif; ?>
								<?php if ( $single && $required ) : ?>
									required
								<?php endif; ?>
							>
							<span><?php echo esc_html( $option['label'] ); ?></span>
							<?php if ( (float) $option['price'] > 0 ) : ?>
								<strong>+<?php echo wp_kses_post( wc_price( $option['price'] ) ); ?></strong>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Register the private modifier-group editor.
	 */
	public static function register_post_type() {
		$capabilities = array_fill_keys(
			array(
				'edit_post',
				'read_post',
				'delete_post',
				'edit_posts',
				'edit_others_posts',
				'publish_posts',
				'read_private_posts',
				'delete_posts',
				'delete_private_posts',
				'delete_published_posts',
				'delete_others_posts',
				'edit_private_posts',
				'edit_published_posts',
				'create_posts',
			),
			'wowrestro_manage_modifiers'
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Add-on groups', 'wowrestro' ),
					'singular_name' => __( 'Add-on group', 'wowrestro' ),
					'add_new_item'  => __( 'Add add-on group', 'wowrestro' ),
					'edit_item'     => __( 'Edit add-on group', 'wowrestro' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'page-attributes' ),
				'capabilities'        => $capabilities,
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Add the schema editor.
	 *
	 * @param WP_Post $post Modifier group.
	 */
	public static function add_meta_box( $post ) {
		add_meta_box( 'wowrestro-modifier-options', __( 'Selection rules and options', 'wowrestro' ), array( __CLASS__, 'group_editor' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Render the deliberately simple modifier-group editor.
	 *
	 * @param WP_Post $post Modifier group.
	 */
	public static function group_editor( $post ) {
		$type    = get_post_meta( $post->ID, self::TYPE_META, true ) ?: 'single';
		$minimum = absint( get_post_meta( $post->ID, self::MIN_META, true ) );
		$maximum = absint( get_post_meta( $post->ID, self::MAX_META, true ) );
		$options = self::sanitize_options( get_post_meta( $post->ID, self::OPTIONS_META, true ) );
		wp_nonce_field( 'wowrestro_save_modifier_group', 'wowrestro_modifier_nonce' );
		?>
		<p><label for="wowrestro-modifier-type"><strong><?php esc_html_e( 'Selection type', 'wowrestro' ); ?></strong></label><br>
		<select id="wowrestro-modifier-type" name="wowrestro_modifier_type"><option value="single" <?php selected( $type, 'single' ); ?>><?php esc_html_e( 'Choose one', 'wowrestro' ); ?></option><option value="multiple" <?php selected( $type, 'multiple' ); ?>><?php esc_html_e( 'Choose multiple', 'wowrestro' ); ?></option></select></p>
		<p><label><?php esc_html_e( 'Minimum selections', 'wowrestro' ); ?> <input type="number" min="0" name="wowrestro_modifier_min" value="<?php echo esc_attr( $minimum ); ?>"></label> <label><?php esc_html_e( 'Maximum selections', 'wowrestro' ); ?> <input type="number" min="0" name="wowrestro_modifier_max" value="<?php echo esc_attr( $maximum ); ?>"></label></p>
		<p><label for="wowrestro-modifier-options"><strong><?php esc_html_e( 'Options', 'wowrestro' ); ?></strong></label></p>
		<textarea id="wowrestro-modifier-options" class="large-text code" rows="10" name="wowrestro_modifier_options"><?php echo esc_textarea( self::options_text( $options ) ); ?></textarea>
		<p class="description"><?php esc_html_e( 'One per line: Label | price | default | stable ID. Use “yes” for a default. IDs are generated when omitted; keep them unchanged after orders exist.', 'wowrestro' ); ?></p>
		<?php
	}

	/**
	 * Save a group after WordPress and WooCommerce capability checks.
	 *
	 * @param int     $post_id Group ID.
	 * @param WP_Post $post Group post.
	 */
	public static function save_group( $post_id, $post ) {
		if ( ! isset( $_POST['wowrestro_modifier_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wowrestro_modifier_nonce'] ) ), 'wowrestro_save_modifier_group' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'wowrestro_manage_modifiers' ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$type    = isset( $_POST['wowrestro_modifier_type'] ) ? sanitize_key( wp_unslash( $_POST['wowrestro_modifier_type'] ) ) : 'single';
		$minimum = isset( $_POST['wowrestro_modifier_min'] ) ? absint( wp_unslash( $_POST['wowrestro_modifier_min'] ) ) : 0;
		$maximum = isset( $_POST['wowrestro_modifier_max'] ) ? absint( wp_unslash( $_POST['wowrestro_modifier_max'] ) ) : 0;
		$raw     = isset( $_POST['wowrestro_modifier_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wowrestro_modifier_options'] ) ) : '';
		self::save_schema( $post_id, $type, $minimum, $maximum, $raw );
	}

	/**
	 * Persist one normalized modifier schema from either admin UI.
	 *
	 * @param int   $post_id Group ID.
	 * @param mixed $type Selection type.
	 * @param mixed $minimum Minimum selections.
	 * @param mixed $maximum Maximum selections.
	 * @param mixed $raw Options input.
	 */
	public static function save_schema( $post_id, $type, $minimum, $maximum, $raw ) {
		$type     = 'multiple' === sanitize_key( $type ) ? 'multiple' : 'single';
		$minimum  = absint( $minimum );
		$maximum  = absint( $maximum );
		$existing = get_post_meta( $post_id, self::OPTIONS_META, true );
		$options  = self::sanitize_options( $raw, $existing );

		if ( 'single' === $type ) {
			$minimum = min( 1, $minimum );
			$maximum = 1;
			$default_seen = false;
			foreach ( $options as &$option ) {
				if ( ! empty( $option['default'] ) && ! $default_seen ) {
					$default_seen = true;
				} else {
					$option['default'] = false;
				}
			}
			unset( $option );
		} else {
			$minimum = min( count( $options ), $minimum );
			$maximum = 0 === $maximum ? count( $options ) : min( count( $options ), max( $minimum, $maximum ) );
			$defaults = 0;
			foreach ( $options as &$option ) {
				if ( ! empty( $option['default'] ) && ++$defaults > $maximum ) {
					$option['default'] = false;
				}
			}
			unset( $option );
		}

		update_post_meta( $post_id, self::TYPE_META, $type );
		update_post_meta( $post_id, self::MIN_META, $minimum );
		update_post_meta( $post_id, self::MAX_META, $maximum );
		update_post_meta( $post_id, self::OPTIONS_META, $options );
		update_post_meta( $post_id, self::SCHEMA_VERSION_META, self::SCHEMA_VERSION );
	}

	/**
	 * Add modifier-group assignments to the product editor.
	 */
	public static function product_groups_field() {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$selected = self::assigned_group_ids( $post->ID );
		$groups   = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			)
		);
		?>
		<p class="form-field"><label for="wowrestro-modifier-groups"><?php esc_html_e( 'Add-on groups', 'wowrestro' ); ?></label><select id="wowrestro-modifier-groups" class="wc-enhanced-select" multiple="multiple" name="wowrestro_modifier_group_ids[]" data-placeholder="<?php esc_attr_e( 'Choose add-on groups', 'wowrestro' ); ?>" style="width:50%">
		<?php foreach ( $groups as $group ) : ?>
			<option value="<?php echo esc_attr( $group->ID ); ?>" <?php selected( in_array( $group->ID, $selected, true ) ); ?>><?php echo esc_html( $group->post_title ); ?></option>
		<?php endforeach; ?>
		</select><span class="description"><?php esc_html_e( 'Applied in this order on the menu and product page.', 'wowrestro' ); ?></span></p>
		<?php
	}

	/**
	 * Save product-to-group assignments.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product_groups( $product ) {
		if ( ! isset( $_POST['wowrestro_product_panel_present'], $_POST['wowrestro_modifier_group_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-editor nonce; absence identifies quick/bulk edits.
			return;
		}
		$raw = isset( $_POST['wowrestro_modifier_group_ids'] ) ? (array) wp_unslash( $_POST['wowrestro_modifier_group_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its product-editor nonce before this hook.
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		$product->update_meta_data( self::PRODUCT_GROUPS_META, $ids );
	}

	/**
	 * Return normalized groups assigned to a product.
	 *
	 * @param int|WC_Product $product Product.
	 * @return array<int,array<string,mixed>>
	 */
	public static function groups_for_product( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$groups     = array();
		$allowlists = self::product_option_allowlists( $product->get_id() );
		foreach ( self::assigned_group_ids( $product->get_id() ) as $group_id ) {
			$group = self::group( $group_id );
			if ( ! $group ) {
				continue;
			}
			if ( $product->get_id() === absint( get_post_meta( $group_id, '_wowrestro_inline_addon_product_id', true ) ) ) {
				$group['name'] = __( 'Add-ons', 'wowrestro' );
			}
			if ( ! empty( $allowlists[ $group_id ] ) ) {
				$allowed          = array_flip( $allowlists[ $group_id ] );
				$group['options'] = array_values(
					array_filter(
						$group['options'],
						static function ( $option ) use ( $allowed ) {
							return isset( $allowed[ $option['id'] ] );
						}
					)
				);
				if ( ! $group['options'] ) {
					continue;
				}
				$group['min'] = min( $group['min'], count( $group['options'] ) );
				$group['max'] = min( $group['max'], count( $group['options'] ) );
			}
			$groups[] = $group;
		}
		return $groups;
	}

	/**
	 * Read one group in its canonical public schema.
	 *
	 * @param int $group_id Group ID.
	 * @return array<string,mixed>|null
	 */
	public static function group( $group_id ) {
		$post = get_post( absint( $group_id ) );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$options = self::sanitize_options( get_post_meta( $post->ID, self::OPTIONS_META, true ) );
		if ( ! $options ) {
			return null;
		}
		$type = 'multiple' === get_post_meta( $post->ID, self::TYPE_META, true ) ? 'multiple' : 'single';
		$min  = min( count( $options ), absint( get_post_meta( $post->ID, self::MIN_META, true ) ) );
		$max  = absint( get_post_meta( $post->ID, self::MAX_META, true ) );
		$max  = 'single' === $type ? 1 : ( 0 === $max ? count( $options ) : min( count( $options ), max( $min, $max ) ) );

		return array(
			'id'      => $post->ID,
			'name'    => get_the_title( $post ),
			'type'    => $type,
			'min'     => $min,
			'max'     => $max,
			'options' => $options,
		);
	}

	/**
	 * Validate submitted option IDs and rebuild prices from the server schema.
	 *
	 * @param int|WC_Product $product Product.
	 * @param mixed          $submitted Submitted group/option IDs.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function validate_selection( $product, $submitted ) {
		$groups = self::groups_for_product( $product );
		if ( is_string( $submitted ) ) {
			$decoded   = json_decode( $submitted, true );
			$submitted = is_array( $decoded ) ? $decoded : array();
		}
		$submitted = is_array( $submitted ) ? $submitted : array();

		$allowed_ids = array_map( 'strval', wp_list_pluck( $groups, 'id' ) );
		foreach ( array_keys( $submitted ) as $submitted_group_id ) {
			if ( ! in_array( (string) absint( $submitted_group_id ), $allowed_ids, true ) && array_filter( (array) $submitted[ $submitted_group_id ] ) ) {
				return new WP_Error( 'wowrestro_modifier_group', __( 'One of the selected add-on groups is not available for this item.', 'wowrestro' ) );
			}
		}

		$snapshots = array();
		foreach ( $groups as $group ) {
			$was_submitted = array_key_exists( $group['id'], $submitted ) || array_key_exists( (string) $group['id'], $submitted );
			$selected      = $was_submitted ? (array) ( $submitted[ $group['id'] ] ?? $submitted[ (string) $group['id'] ] ) : wp_list_pluck(
				array_filter(
					$group['options'],
					static function ( $option ) {
						return ! empty( $option['default'] );
					}
				),
				'id'
			);
			$selected = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'option_id_from_input' ), $selected ) ) ) );
			$count    = count( $selected );
			if ( $count < $group['min'] || $count > $group['max'] || ( 'single' === $group['type'] && $count > 1 ) ) {
				return new WP_Error(
					'wowrestro_modifier_count',
					sprintf( __( 'Choose between %1$d and %2$d options for %3$s.', 'wowrestro' ), $group['min'], $group['max'], $group['name'] )
				);
			}

			$option_map = array();
			foreach ( $group['options'] as $option ) {
				$option_map[ $option['id'] ] = $option;
			}
			foreach ( $selected as $option_id ) {
				if ( ! isset( $option_map[ $option_id ] ) ) {
					return new WP_Error( 'wowrestro_modifier_option', __( 'One of the selected modifier options is no longer available.', 'wowrestro' ) );
				}
			}
			$options = array();
			foreach ( $group['options'] as $option ) {
				if ( ! in_array( $option['id'], $selected, true ) ) {
					continue;
				}
				$options[] = array(
					'id'    => $option['id'],
					'label' => $option['label'],
					'price' => (float) $option['price'],
				);
			}
			if ( $options ) {
				$snapshots[] = array(
					'id'      => $group['id'],
					'name'    => $group['name'],
					'type'    => $group['type'],
					'options' => $options,
				);
			}
		}

		return $snapshots;
	}

	/**
	 * Add all trusted modifier prices in a normalized snapshot.
	 *
	 * @param array<int,array<string,mixed>> $snapshots Modifier snapshots.
	 * @return float
	 */
	public static function snapshot_total( $snapshots ) {
		$total = 0.0;
		foreach ( (array) $snapshots as $group ) {
			foreach ( (array) ( $group['options'] ?? array() ) as $option ) {
				$total += max( 0, (float) ( $option['price'] ?? 0 ) );
			}
		}
		return (float) wc_format_decimal( $total, wc_get_price_decimals() );
	}

	/**
	 * Convert snapshots back to the ID-only shape used for revalidation.
	 *
	 * @param array<int,array<string,mixed>> $snapshots Snapshots.
	 * @return array<int,array<int,string>>
	 */
	public static function snapshot_selection( $snapshots ) {
		$selection = array();
		foreach ( (array) $snapshots as $group ) {
			$group_id = absint( $group['id'] ?? 0 );
			if ( ! $group_id ) {
				continue;
			}
			$selection[ $group_id ] = array_values( array_filter( wp_list_pluck( (array) ( $group['options'] ?? array() ), 'id' ) ) );
		}
		return $selection;
	}

	/**
	 * Sanitize editor input while preserving existing stable IDs by ID or label.
	 *
	 * @param string|array<mixed> $raw Raw options.
	 * @param array<mixed>        $existing Existing options.
	 * @return array<int,array{id:string,label:string,price:float,default:bool}>
	 */
	public static function sanitize_options( $raw, $existing = array() ) {
		$existing = is_array( $existing ) ? $existing : array();
		$by_label = array();
		foreach ( $existing as $option ) {
			if ( ! empty( $option['id'] ) && ! empty( $option['label'] ) ) {
				$by_label[ sanitize_title( $option['label'] ) ] = sanitize_key( $option['id'] );
			}
		}

		$rows = array();
		if ( is_string( $raw ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				if ( '' !== trim( $line ) ) {
					$parts  = array_map( 'trim', explode( '|', $line, 4 ) );
					$rows[] = array(
						'label'   => $parts[0] ?? '',
						'price'   => $parts[1] ?? 0,
						'default' => $parts[2] ?? '',
						'id'      => $parts[3] ?? '',
					);
				}
			}
		} elseif ( is_array( $raw ) ) {
			$rows = $raw;
		}

		$options = array();
		$used    = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( $row['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_key( $row['id'] ?? '' );
			if ( ! $id && isset( $by_label[ sanitize_title( $label ) ] ) ) {
				$id = $by_label[ sanitize_title( $label ) ];
			}
			if ( ! $id || isset( $used[ $id ] ) ) {
				$id = self::new_option_id();
			}
			$used[ $id ] = true;
			$default      = $row['default'] ?? false;
			$options[]    = array(
				'id'      => substr( $id, 0, 64 ),
				'label'   => $label,
				'price'   => max( 0, (float) wc_format_decimal( $row['price'] ?? 0, wc_get_price_decimals() ) ),
				'default' => true === $default || in_array( strtolower( (string) $default ), array( '1', 'yes', 'true', 'default', '*' ), true ),
			);
		}
		return $options;
	}

	/**
	 * Return IDs assigned directly to a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,int>
	 */
	public static function assigned_group_ids( $product_id ) {
		$ids = get_post_meta( absint( $product_id ), self::PRODUCT_GROUPS_META, true );
		if ( is_string( $ids ) ) {
			$ids = preg_split( '/[\s,]+/', $ids );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Normalize product-specific stable option ID allow-lists.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,array<int,string>>
	 */
	private static function product_option_allowlists( $product_id ) {
		$raw   = get_post_meta( absint( $product_id ), self::PRODUCT_OPTION_ALLOWLISTS_META, true );
		$lists = array();
		foreach ( is_array( $raw ) ? $raw : array() as $group_id => $option_ids ) {
			$group_id = absint( $group_id );
			$ids      = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $option_ids ) ) ) );
			if ( $group_id && $ids ) {
				$lists[ $group_id ] = $ids;
			}
		}
		return $lists;
	}

	/**
	 * Normalize option input from HTML forms and API clients.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	private static function option_id_from_input( $value ) {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? '';
		}
		return sanitize_key( $value );
	}

	/**
	 * Serialize options for the line editor without changing their IDs.
	 *
	 * @param array<int,array<string,mixed>> $options Options.
	 * @return string
	 */
	public static function options_text( $options ) {
		$lines = array();
		foreach ( $options as $option ) {
			$lines[] = implode( ' | ', array( $option['label'], wc_format_decimal( $option['price'] ), ! empty( $option['default'] ) ? 'yes' : 'no', $option['id'] ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Generate an opaque option identifier.
	 *
	 * @return string
	 */
	private static function new_option_id() {
		return 'wr_' . str_replace( '-', '', wp_generate_uuid4() );
	}
}
