<?php
/**
 * Product Modifiers
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

$product_id = $product->get_id();

$cart_product   = !empty( $cart_key ) ? WC()->cart->get_cart_item( $cart_key ) : array();
$cart_modifiers = isset( $cart_product['modifiers'] ) ? $cart_product['modifiers'] : array();

if ( !empty( $product_id ) ) {
  
  $modifier_categories = wp_get_post_terms( $product_id, 'food_modifiers' );

  $modifier_child_cats = array();
  $category_name_slug = '';

  if ( !empty( $modifier_categories ) && is_array( $modifier_categories ) ) {
    
    echo '<div class="wowrestro-item-modifiers-container">';
    
    $var = '';
    $sort_modifier_categories = wwro_sort_modifier_categories( $modifier_categories );
    
    foreach( $sort_modifier_categories as $modifier_category ) {
    
      if ( $modifier_category->parent !== 0 ) {

        $parent_category  = get_term_by( 'id', $modifier_category->parent , 'food_modifiers' );
        $parent_id        = $parent_category->term_id;
        
        $parent_category_slug = $parent_category->slug;
        $parent_category_name = $parent_category->name;

        $category_slug  = $modifier_category->slug;
        $category_name  = $modifier_category->name;
        
        $category_price = get_term_meta( $modifier_category->term_id, '_wowrestro_modifier_item_price', true );
        $category_price = $category_price != '' ? wwro_get_wwro_modifier_price( $product, $category_price ) : '0.00';

        $class  = ( $var == $parent_category_name ) ? 'same' : '';
        $var    = $parent_category_name;

        $choice = wwro_get_term_choice( $parent_id );
        $field_name  = ( $choice == 'radio' ) ? $parent_category_slug : $category_slug;

        if ( $class != 'same' ) : ?>
          
          <h6 class="wowrestro-modifier-category-title"><?php echo esc_html( $parent_category_name ); ?></h6>
        
        <?php endif;

        $check_modifier_in_cart = wwro_check_modifier_in_cart( $field_name, $category_slug, $cart_modifiers );
        $selected = $check_modifier_in_cart ? 'checked' : '';

        ?>
        <div class="wowrestro-modifier-category">
          <label for="<?php echo esc_attr( $category_slug ); ?>" class="wwr-select-section wwr-container-<?php echo esc_attr( $choice ); ?>">
            <input id="<?php echo esc_attr( $category_slug ); ?>" name="<?php echo esc_attr( $field_name ); ?>" <?php echo esc_attr( $selected ); ?> type="<?php echo esc_attr( $choice ); ?>" value="<?php echo esc_attr( $category_slug );  ?>" >
           <span class="wp-addon-modal-name"><?php echo esc_attr( $category_name ); ?></span>
           <span class="wwr-control__indicator"></span>
            <span class="wwr-addon-price-md"><?php echo '&nbsp;+&nbsp;' . wc_price( $category_price ) . ''; ?></span>
          </label>
        </div><!-- wowrestro-modifier-category -->
        <?php
      }
    }
    echo '</div><!-- wowrestro-item-modifiers-container -->';

  }

  ?>
    <div class="wowrestro-special-instruction-wrapper">
      <p class="wowrestro-special-note-label">
        <?php esc_html_e( 'Extra instructions', 'wowrestro' ); ?>
      </p>
      <textarea id="special_note" name="special_note" rows="3" cols="10" placeholder="<?php esc_html_e( 'List any special requests here', 'wowrestro' ); ?>">
      </textarea>
    </div>
  
  <?php
}