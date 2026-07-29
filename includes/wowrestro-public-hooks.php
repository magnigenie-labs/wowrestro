<?php
/**
 * WoWRestro Template Hooks
 *
 * Action/filter hooks used for WoWRestro functions/templates.
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

# Category Loop Items
add_action( 'wowrestro_subcategory_title', 'wwro_template_loop_category_title', 10 );

# Product Loop items
add_action( 'wowrestro_before_product_summary', 'wwro_template_show_images' , 10 );
add_action( 'wowrestro_product_summary', 'wwro_product_title', 5 );
add_action( 'wowrestro_product_summary', 'wwro_product_short_description', 10 );
add_action( 'wowrestro_product_summary', 'wwro_product_price', 15 );

# WoWRestro Cart
add_action( 'wp_footer', 'wwro_footer_cart', 10 );

# Add variation data to popup
add_action( 'wowrestro_variable_data', 'woocommerce_template_single_add_to_cart', 25 );

# Order confermation page modifier data
add_action( 'woocommerce_order_item_meta_end', 'wwro_thankyou_modifier_details', 10, 3 );

# Disaplying available Modifiers to Choose
add_action( 'wowrestro_food_modifiers', 'wwro_render_food_modifiers', 10, 2 );