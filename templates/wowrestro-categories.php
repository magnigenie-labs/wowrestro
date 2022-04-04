<?php
/**
 * The template for displaying product category
 *
 * This template can be overridden by copying it to yourtheme/wowrestro
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

$exclude_categories = wwro_get_exclude_categories();

$category_args = array(
  'taxonomy'    => 'product_cat',
  'hide_empty'  => true,
  'include'     => $category_ids,
  'exclude'     => $exclude_categories,
);


$category_args = apply_filters( 'wowrestro_categories',  $category_args );
$get_categories = get_terms( $category_args );

if ( $get_categories ) {
  
  wwro_category_start();

  foreach ( $get_categories as $category ) {

    wwro_get_template(
      'content-wowrestro-category.php',
      array(
        'category' => $category,
      )
    );
    
  }
  wwro_category_end();
}