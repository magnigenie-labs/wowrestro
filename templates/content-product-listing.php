<?php
/**
 * The template for displaying product listings
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
$get_all_categories = get_terms( $category_args );

if ( is_array( $get_all_categories ) ) {

  foreach( $get_all_categories as $wowrestro_category ) {
    
    $term_id = $wowrestro_category->term_id;
    $excluded_products = get_option( '_wowrestro_exclude_products', array() );

    $args = array(
      'post_type'       => 'product',
      'posts_per_page'  => -1,
      'post__not_in'    => $excluded_products,
      'post_status'     => 'publish',
    );

    $args['tax_query'][] = array(
      'taxonomy' => 'product_cat',
      'field'    => 'term_id',
      'terms'    => array( $term_id ) ,
    );

    $args['meta_query'][] = array(
      'key'     => '_food_item',
      'value'   => 'yes',
      'compare' => 'LIKE',
    );

    $query = apply_filters( 'wowrestro_get_products', $args );

    $wowrestro_products = new WP_Query( $query );

    if ( $wowrestro_products->have_posts() ) :

      wwro_listing_start( $echo = true, $term_id );

      // Set template columns 
      $template_classes = 'wwr-col-lg-6 wwr-col-md-6 wwr-col-sm-12 wwr-col-xs-12';

      echo '<div class="wwr-food-item-wrap">';

      while ( $wowrestro_products->have_posts() ) : $wowrestro_products->the_post();

        $product = wc_get_product( get_the_ID() );

        echo '<div class="' . apply_filters( 'wowrestro_template_columns', $template_classes ) . '">';

        wwro_get_template(
          'content-listing-details.php',
          array(
            'product' => $product,
            'term_id' => $term_id,
          )
        );

        echo '</div>';

      endwhile;

      echo '</div>';

      wwro_listing_end( $echo = true, $term_id );

      wp_reset_postdata();

    endif;
  }
}