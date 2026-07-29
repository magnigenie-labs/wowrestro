<?php
/**
 * Admin View: Food Store Product Tab
 *
 * @package FoodStore
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

echo '<div id="food_product_options" class="panel woocommerce_options_panel hidden">';

woocommerce_wp_text_input( array(
	'id' 			=> '_wowrestro_variation_price_label',
	'placeholder' 	=> __( 'Enter Variation Pricing Label', 'wowrestro' ),
	'value'			=> get_post_meta( get_the_ID(), '_wowrestro_variation_price_label', true ),
	'label'			=> '',
	'wrapper_class' => 'show_if_variable',
	'desc_tip'		=> true,
	'description'	=> __( 'Customized text to display on price selection label.', 'wowrestro' )
) );

if ( get_option( '_wowrestro_include_veg_non_veg' ) == 'yes' ) {
	woocommerce_wp_radio( array(
		'id'			=> '_wowrestro_food_item_type',
		'label'			=> '',
		'value'			=> get_post_meta( get_the_ID(), '_wowrestro_food_item_type', true ),
		'options'		=> array(
			'na' 		=> __( 'N/A', 'wowrestro' ),
			'veg' 		=> __( 'Vegetarian', 'wowrestro' ),
			'nonveg' 	=> __( 'Non Vegiterian', 'wowrestro' )
		)
	) );
}

$sales_type = get_option( '_wowrestro_sales_type' );

if ( $sales_type == 'only_food_item' ) {
	woocommerce_wp_hidden_input( array(
		'id' 			=> '_food_item',
		'value'			=> 'yes',
		'class' 		=> 'show_if_food_item',
	) );
}

?>
<div class="options_group">
	<div class="modifier-header modifier-new-category">
		<h4><?php esc_html_e( 'Modifiers', 'wowrestro' ); ?></h4>
		<button class="create-new-modifier-category-btn"><?php esc_html_e( 'Create New Modifier', 'wowrestro' ); ?></button>
	</div>
	<div class="wwr-loads text hidden"></div>
	<div class="wwr-loads text hidden"></div>
	<div class="wwr-loads text hidden"></div>
	<div class="wwr-loads text hidden"></div>
	<div class="modifier-content">
		<div class="modifier-content-list-wrap">
			<input type="hidden" name="removed_modifier_category_ids" class="removed-modifier-category-ids" value="">
			<input type="hidden" name="removed_modifier_item_ids" class="removed-modifier-item-ids" value="">
			<div class="modifier-category-block">
				<?php
					$item_id = ( !empty( $_GET['post'] ) ) ? sanitize_text_field( $_GET['post'] ) : '';

					// Get modifier food items
					$food_item_modifier_categories = '';
					if ( !empty( $item_id ) ) {
						$food_item_modifier_categories = get_post_meta( $item_id, 'modifier_categories', true );
					}

					if ( empty( $food_item_modifier_categories ) ) {
						?>
							<div class="modifier-category-content">
								<button class="remove-modifier-category hidden">&#x2716;</button>
								<div class="modifier-category-content-left">
									<div class="modifier-category-content-left-wrap">
										<select name="modifier_category[]" class="modifier-category-select">
											<option value=""><?php esc_html_e( 'Select a Modifier', 'wowrestro' ) ?></option>
											<?php
												$modifier_categories = get_terms( array( 'taxonomy' => 'food_modifiers', 'parent' => 0, 'hide_empty' => false ) );

												foreach ( $modifier_categories as $key => $modifier_category ) {
													echo "<option value='" . $modifier_category->term_id . "'>" . $modifier_category->name . "</option>";
												}
											?>
										</select>
									</div>
								</div>
								<div class="modifier-category-content-right">
									<div class="modifier-category-content-right-wrap">
										<div class="modifier-category-inner-content-wrap hidden">
											<?php 
											   	// Include Modifier list
												include_once 'html-food-modifier-items-list.php';
											?>
										</div>
										<div class="modifier-category-inner-content-empty">
											<?php esc_html_e( 'Please Select a Modifier first.', 'wowrestro' ); ?>
										</div>
									</div>
								</div>
							</div>
						<?php
					}else{
						foreach ( $food_item_modifier_categories as $key => $food_item_modifier_category ) {
							?>
								<div class="modifier-category-content">
									<button class="remove-modifier-category <?php echo ( $key < 1 ) ? 'hidden' : '' ?>">X</button>
									<div class="modifier-category-content-left">
										<div class="modifier-category-content-left-wrap">
											<select name="modifier_category[]" class="modifier-category-select">
												<option value=""><?php esc_html_e( 'Select a Modifier', 'wowrestro' ); ?></option>
												<?php
													$modifier_categories = get_terms( array( 'taxonomy' => 'food_modifiers', 'parent' => 0, 'hide_empty' => false ) );

													foreach ( $modifier_categories as $key => $modifier_category ) {
														echo "<option value='" . $modifier_category->term_id . "' " . selected( $modifier_category->term_id, $food_item_modifier_category ) . " >" . $modifier_category->name . "</option>";
													}
												?>
											</select>
										</div>
									</div>
									<div class="modifier-category-content-right">
										<div class="modifier-category-content-right-wrap">
											<div class="modifier-category-inner-content-wrap">
												<?php 
												   	// Include Modifier list
													$parent_food_modifiers_id = $food_item_modifier_category;
													include 'html-food-modifier-items-list.php';
												?>
											</div>
											<div class="modifier-category-inner-content-empty hidden">
												<?php esc_html_e( 'Please Select a Modifier first.', 'wowrestro' ); ?>
											</div>
										</div>
									</div>
								</div>
							<?php
						}
					}
				?>
			</div>
			<div class="add-new-modifier-category">
				<button class="add-modifier-category-btn"><?php esc_html_e( 'Add New', 'wowrestro' ); ?></button>
			</div>
		</div>
		<div class="add-new-modifier-content-wrap hidden">
			<div class="new-modifier-category-block">
				<div class="modifier-header">
					<h4><?php esc_html_e( 'Create New Modifier', 'wowrestro' ); ?></h4>
					<div class="wowrestro-modifier-close"><span class="close-create-new-modifier-block">X</span></div>
					
				</div>
				<div class="new-modifier-category-content">
					<div class="modifier-category-content-left">
						<div class="modifier-category-content-left-wrap">
							<table class="food-modifier-category">
								<tr>
									<th><?php esc_html_e( 'Modifier:', 'wowrestro' ); ?></th>
									<th><?php esc_html_e( 'Type:', 'wowrestro' ); ?></th>
									<th></th>
								</tr>
								<tr class="new-modifier-category-row">
									<td>
										<input type="text" class="food-modifier-category-name-input" name="food_modifiers_name" placeholder="Modifier name">
									</td>
									<td>
										<select class="food-modifier-category-type-select" name="food_modifiers_type">
											<option value="single"><?php esc_html_e( 'Single', 'wowrestro' ); ?></option>
											<option value="multiple"><?php esc_html_e( 'Multiple', 'wowrestro' ); ?></option>
										</select>
									</td>
								</tr>
							</table>
						</div>
					</div>
					<div class="modifier-category-content-right">
						<div class="modifier-category-content-right-wrap">
							<table class="food-modifier-items">
								<tr>
									<th><?php esc_html_e( 'Modifier Items', 'wowrestro' ); ?></th>
									<th><?php esc_html_e( 'Price', 'wowrestro' ); ?></th>
									<th></th>
								</tr>
								<tr class="new-modifier-item-row">
									<td>
										<input class="food-modifier-item-name" type="text" name="food_modifier_item_name[]" placeholder="Item name">
									</td>
									<td>
										<span class="wowrestro-currency-sym"><?php echo get_woocommerce_currency_symbol(); ?></span>
										 <input class="food-modifier-item-price" type="number" min="0.00" max="10000.00" step="0.01" name="food_modifier_item_price[]" placeholder="0.00">
									</td>
									<td class="remove-modifier-item"><span class="wow-ad-close">&#x2716;</span></td>
								</tr>
							</table>
							<div class="add-new-food-item-btn">
								<button class="add-new-modifier-item-btn"><?php esc_html_e( 'Add New', 'wowrestro' ); ?></button>
							</div>
						</div>
					</div>
				</div>
				</form>
			</div>
			<div class="add-new-modifier-category">
				<?php $nonce = wp_create_nonce("add_modifier_category"); ?>
				<button class="add-new-modifier-category-btn" data-nonce="<?php echo $nonce; ?>"><?php esc_html_e( 'Add', 'wowrestro' ); ?></button>
			</div>
		</div>
	</div>
</div>
<?php
echo '</div>';
?>