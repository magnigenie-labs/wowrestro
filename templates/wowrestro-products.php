<?php
/**
 * The template for displaying products
 *
 * This template can be overridden by copying it to yourtheme/wowrestro
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

wwro_product_start();

wwro_get_template( 'content-product-listing.php' ,
  array(
    'shortcode_args' => $shortcode_args,
    'category_ids'   => $category_ids
  )
);

wwro_product_end();