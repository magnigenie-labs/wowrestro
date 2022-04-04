<?php
	if ( empty( $parent_food_modifiers_id ) ) return;
	
	$parent_food_modifiers = get_term( $parent_food_modifiers_id );
    $args = array(
        'orderby'       => 'name', 
        'order'         => 'ASC',
        'hide_empty'    => false, 
        'child_of'      => $parent_food_modifiers_id,
    );
	$food_items = get_terms( 'food_modifiers', $args);

	// Get food modifier items
	$modifier_category_items = get_post_meta( $item_id, 'modifier_category_items', true );
?>
<div class="modifier-category-content-right-header">
	<h4><?php echo esc_html( $parent_food_modifiers->name ); ?></h4>
</div>
<div class="modifier-category-inner-content">
	<ul class="modifier-category-item-list">
		<?php  
			foreach ( $food_items as $key => $food_item ) {
				$checked = ( is_array( $modifier_category_items ) && in_array(  $food_item->term_id, $modifier_category_items ) ) ? 'checked' : '';
				echo '<li class="modifier-category-item"><label><input value="' . $food_item->term_id . '" type="checkbox" name="modifier_category_item[]" ' . $checked . '> ' . $food_item->name . '</label></li>';
			}
		?>
	</ul>
</div>