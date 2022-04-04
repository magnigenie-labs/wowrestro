<?php
/**
 * Metaboxes
 *
 * Metaboxes for Post Type Product
 * Metaboxes for Taxonomy Product Category
 * Metaboxes for Taxonomy Product Modifier
 *
 * @package WoWRestro/Classes
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Metaboxes Class.
 */
class WWRO_Metaboxes {

  /**
   * Hook in methods.
   */
  public function __construct() {

    add_action( 'food_modifiers_edit_form_fields', array(  $this, 'wwro_modifier_choice' ) );
    add_action( 'food_modifiers_edit_form_fields', array(  $this, 'wwro_modifier_price' ) );
    add_action( 'edited_food_modifiers', array( $this, 'wwro_save_modifier_options' ) );
    add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
    add_filter( 'woocommerce_product_data_tabs', array( $this, 'wwro_tab' ) );
    add_action( 'woocommerce_product_data_panels', array( $this, 'wwro_tab_content' ) );
    add_action( 'woocommerce_process_product_meta', array( $this, 'wwro_save_custom_meta' ), 10, 2 );
    add_action( 'product_type_selector', array( $this, 'wwro_product_type' ) );
    add_action( 'product_type_options', array( $this, 'wwro_product_options' ) );
    add_filter( 'woocommerce_products_admin_list_table_filters', array( $this, 'wwro_table_filter') );
    add_action( 'woocommerce_product_query', array( $this, 'wowrestro_show_hide_food_items' ) );

  }

  /**
   * Hide / Show food items on shop page
   * 
   * @since 1.0
   * @param obj Term Object
   * @return object
   */
 public function wowrestro_show_hide_food_items( $query ) {
    
    if( is_shop() || is_page('shop') ) {

      if ( get_option( '_wowrestro_overwite_shop_page', 'default' ) == 'only_food_item' ) {
        $wowrestro_overwite_shop_page = 'LIKE';
      } else if ( get_option( '_wowrestro_overwite_shop_page', 'default' ) == 'only_shop' ) {
        $wowrestro_overwite_shop_page = 'NOT EXISTS';
      } else {
        $wowrestro_overwite_shop_page = '';
      }

      // Get any existing meta query
      $meta_query = $query->get( 'meta_query');
      
      if ( !empty( $wowrestro_overwite_shop_page ) ) {
        // Define an additional meta query 
        $query->set( 'meta_query', array( array(
            'key'     => '_food_item',
            'value'   => 'yes',
            'compare' => $wowrestro_overwite_shop_page,
        ) ) );
      }

    }

    return $query;

  }

  /**
   * Modifier Item type.
   * 
   * @since 1.0
   * @param obj Term Object
   * @return void
   */
  public static function wwro_modifier_choice( $term ) {

    if ( $term->parent !== '0' )
      return;

    $choice = get_term_meta( $term->term_id, '_wowrestro_modifier_selection_option', true ); ?>
    
    <tr class="form-field">
      <th scope="row" valign="top">
        <label for="modifier_selection">
          <?php esc_html_e( 'Selection Choice', 'wowrestro' ); ?>
        </label>
      </th>
      <td>
        <select name="modifier_selection" required="required">
          <option <?php selected( $choice, 'single' ); ?> value="single"><?php esc_html_e( 'Single', 'wowrestro' ); ?></option>
          <option <?php selected( $choice, 'multiple' ); ?>  value="multiple"><?php esc_html_e( 'Multiple', 'wowrestro' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Please choose how you want this modifier to be associated with the WOWRestro item.', 'wowrestro' ); ?></p>
      </td>
    </tr> <?php

  }

  /**
   * Item Modifier Price.
   * 
   * @since 1.0
   * @param obj Term Object
   * @return void
   */
  public static function wwro_modifier_price( $term ) {

    if ( $term->parent == 0 )
      return;
    
    $price = get_term_meta( $term->term_id, '_wowrestro_modifier_item_price', true );

    ?>
      <tr class="form-field">
        <th scope="row" valign="top">
          <label for="wwro_modifier_price"><?php esc_html_e( 'Price', 'wowrestro' ); ?></label>
        </th>
        <td>
          <input type="number" step=".01" name="wwro_modifier_price" size="25" value="<?php echo esc_attr( $price ); ?>" required />
          <p class="description"><?php esc_html_e( 'Add a Price for this modifier item.', 'wowrestro' ); ?></p>
        </td>
      </tr> 
    <?php

  }

  /**
   * Update Modifier data
   * 
   * @since 1.0
   * @param int Term ID
   * @return void
   */
  public static function wwro_save_modifier_options( $term_id ) {

    if ( isset( $_POST['modifier_selection'] ) ) {
      update_term_meta( $term_id, '_wowrestro_modifier_selection_option', sanitize_text_field( $_POST['modifier_selection'] ) );
    }

    if ( isset( $_POST['wwro_modifier_price'] ) ) {
      update_term_meta( $term_id, '_wowrestro_modifier_item_price', sanitize_text_field( $_POST['wwro_modifier_price'] ) );
    }
  }

  /**
   * Create new product data tab for Food options
   * 
   * @since 1.0
   * @param arr List of Existing Tabs
   * @return void
   */
  public static function wwro_tab( $tabs ) {

    $sales_type = get_option( '_wowrestro_sales_type' );

    if ( $sales_type == 'all_product' ) {
      $classes = array( 'show_if_food_item' );
    }else{
      $classes = array( 'show_if_food_item', 'show_if_simple', 'show_if_variable', 'show_if_grouped', 'show_if_external' );
    }

    // Add custom tab for food options
    $tabs['food-options'] = array(
      'label'    => __( 'WOWRestro Item Options', 'wowrestro' ),
      'target'   => 'food_product_options',
      'class'    => array( 'show_if_food_item' ),
      'priority' => 16,
    );

    return $tabs;

  }

  /**
   * Tab content area for the new tab created for Food options
   * 
   * @since 1.0
   * @return void
   */
  public static function wwro_tab_content() {

    include_once dirname( __FILE__ ) . '/admin/views/html-product-data-food-options.php';

  }

  /**
   * Save custom meta fields when product is saved
   * 
   * @since 1.0
   * @param int Post ID
   * @param obj Post Object
   * @return void
   */
  public static function wwro_save_custom_meta( $post_id, $post ) {

    if ( !empty( $_POST['_wowrestro_variation_price_label'] ) ) {
      update_post_meta( $post_id, '_wowrestro_variation_price_label', sanitize_text_field( $_POST['_wowrestro_variation_price_label'] ) );
    }
    if ( !empty( $_POST['_wowrestro_food_item_type'] ) ) {
      update_post_meta( $post_id, '_wowrestro_food_item_type', sanitize_text_field( $_POST['_wowrestro_food_item_type'] ) );
    }

    if ( !empty( $_POST['_food_item'] ) && ( $_POST['_food_item'] == 'yes' || $_POST['_food_item'] == 'on' ) ) {
      update_post_meta( $post_id, '_food_item', 'yes' );
    } 

    if ( !empty( $_POST['modifier_category'] ) ) {
      $modifier_categories        = isset( $_POST['modifier_category'] ) ? (array) $_POST['modifier_category'] : [];
      $modifier_category_items    = isset( $_POST['modifier_category_item'] ) ? (array) $_POST['modifier_category_item'] : [];

      $food_modifiers             = array_merge( array_unique( $modifier_categories ), array_unique( $modifier_category_items ) );
      
      // Get the post meta values
      $item_modifier_categories = get_post_meta( $post_id, 'modifier_categories', true );
      $item_modifier_category_items = get_post_meta( $post_id, 'modifier_category_items', true );
      $food_item_modifier_categories = get_post_meta( $post_id, 'food_item_modifier_categories', true );

      
      wp_set_post_terms( $post_id, $food_modifiers, "food_modifiers" );

      update_post_meta( $post_id, 'modifier_categories', array_unique( $modifier_categories ) );
      update_post_meta( $post_id, 'modifier_category_items', array_unique( $modifier_category_items ) );
      update_post_meta( $post_id, 'food_item_modifier_categories', $food_modifiers );
      
      // Save the food modifier categories to post meta
      if ( ! empty( $item_modifier_categories ) ) {
        update_post_meta( $post_id, 'modifier_categories', array_unique( $modifier_categories ) );
        update_post_meta( $post_id, 'modifier_category_items', array_unique( $modifier_category_items ) );
        update_post_meta( $post_id, 'food_item_modifier_categories', $food_modifiers );

        $removed_modifier_categories = array_diff( $item_modifier_categories, $modifier_categories );
        $removed_modifier_category_items = array_diff( $item_modifier_category_items, $modifier_category_items );
        $removed_food_modifiers = array_merge( array_unique( $removed_modifier_categories ), array_unique( $removed_modifier_category_items ) );

        wp_remove_object_terms( $post_id, $removed_food_modifiers, "food_modifiers" );
      }
    }

  }

  /**
   * Filter product types
   * 
   * @since 1.0
   * @param arr List of types
   * @return void
   */
  public static function wwro_product_type( $types ) {

    $sales_type = get_option( '_wowrestro_sales_type' );

    if ( $sales_type == 'only_food_item' ) {
      $types['simple']   = __( 'Simple WOWRestro Item', 'wowrestro' );
      $types['variable'] = __( 'Variable WOWRestro Item', 'wowrestro' );
    }

    $other_types_setting = get_option( '_wowrestro_adv_keep_other_product_types' );
    if( empty($other_types_setting) || $other_types_setting == 'no' ):
      unset( $types['grouped'] );
      unset( $types['external'] );
    endif;

    return $types;

  }

  /**
   * Remove product options like downloadable, Virtual
   * 
   * @since 1.0
   * @param arr Array of options available
   * @return void
   */
  public static function wwro_product_options( $options ) {
    
    $other_types_setting = get_option( '_wowrestro_adv_keep_other_product_types' );
    if( empty( $other_types_setting ) || $other_types_setting == 'no' ):
    
      if( isset( $options[ 'virtual' ] ) ) {
        unset( $options[ 'virtual' ] );
      }
      if( isset( $options[ 'downloadable' ] ) ) {
        unset( $options[ 'downloadable' ] );
      }

    endif;

    $sales_type = get_option( '_wowrestro_sales_type' );
    if ( $sales_type == 'all_product' ) {
      $options['food_item'] = array(
        'id'            => '_food_item',
        'wrapper_class' => '',
        'class'         => array( 'show_if_simple', 'show_if_variable' ),
        'label'         => __( 'WOWRestro Item', 'woocommerce' ),
        'description'   => __( 'Set this product as a WOWRestro item.', 'woocommerce' ),
        'default'       => 'no',
      );
    }

    return $options;

  }

  /**
   * Remove other product types from product listing filter
   * 
   * @since 1.0
   * @param arr Array of Existing Filters
   * @return void
   */
  public static function wwro_table_filter( $filters ) {

    $other_types_setting = get_option( '_wowrestro_adv_keep_other_product_types' );
    if( empty($other_types_setting) || $other_types_setting == 'no' ): 

      if( isset( $filters[ 'product_type' ] ) ) {
        $filters[ 'product_type' ] = array( __CLASS__, 'product_type_filter_callback' );
      }
    endif;
    
    return $filters;

  }

  /**
   * Call bac function to remove other product options 
   * from the product filters dropdown
   * 
   * @since 1.0
   * @param int Term ID
   * @return void
   */
  public static function product_type_filter_callback() {

    $current_product_type = isset( $_REQUEST['product_type'] ) ? wc_clean( wp_unslash( $_REQUEST['product_type'] ) ) : false;
    $output = '<select name="product_type" id="dropdown_product_type"><option value="">Filter by product type</option>';
   
    foreach ( wc_get_product_types() as $value => $label ) {
      $output .= '<option value="' . esc_attr( $value ) . '" ';
      $output .= selected( $value, $current_product_type, false );
      $output .= '>' . esc_html( $label ) . '</option>';
    }
   
    $output .= '</select>';
    echo $output;

  }
  
}

new WWRO_Metaboxes();