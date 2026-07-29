<?php
/**
 * Modifiers
 *
 * Registers Modifiers.
 *
 * @package WoWRestro/Classes
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomies Class.
 */
class WWRO_Modifiers {

  /**
   * Hook in methods.
   */
  public static function init() {
    add_action( 'init', array( __CLASS__, 'register_modifiers' ), 5 );
  }

  /**
   * Register core Modifiers.
   */
  public static function register_modifiers() {

    if ( ! is_blog_installed() ) {
      return;
    }
    
    do_action( 'wowrestro_register_modifiers' );

    /**
     * Add taxonomy for Product Modifier 
     *
     * @since 1.0
     * @return void
     */
    $labels = array(
      'name'                       => _x( 'Modifiers', 'Modifier General Name', 'wowrestro' ),
      'singular_name'              => _x( 'Modifier', 'Modifier Singular Name', 'wowrestro' ),
      'menu_name'                  => __( 'Modifiers', 'wowrestro' ),
      'all_items'                  => __( 'All Modifiers', 'wowrestro' ),
      'parent_item'                => __( 'Parent Modifier', 'wowrestro' ),
      'parent_item_colon'          => __( 'Parent Modifier:', 'wowrestro' ),
      'new_item_name'              => __( 'New Modifier Name', 'wowrestro' ),
      'add_new_item'               => __( 'Add New Modifier', 'wowrestro' ),
      'edit_item'                  => __( 'Edit Modifier', 'wowrestro' ),
      'update_item'                => __( 'Update Modifier', 'wowrestro' ),
      'view_item'                  => __( 'View Modifier', 'wowrestro' ),
      'separate_items_with_commas' => __( 'Separate modifiers with commas', 'wowrestro' ),
      'add_or_remove_items'        => __( 'Add or remove modifiers', 'wowrestro' ),
      'choose_from_most_used'      => __( 'Choose from the most used', 'wowrestro' ),
      'popular_items'              => __( 'Popular Modifiers', 'wowrestro' ),
      'search_items'               => __( 'Search Modifiers', 'wowrestro' ),
      'not_found'                  => __( 'Not Found', 'wowrestro' ),
      'no_terms'                   => __( 'No modifiers', 'wowrestro' ),
      'items_list'                 => __( 'Modifiers list', 'wowrestro' ),
      'items_list_navigation'      => __( 'Modifiers list navigation', 'wowrestro' ),
    );
    $args = array(
      'labels'                     => $labels,
      'hierarchical'               => true,
      'public'                     => true,
      'show_ui'                    => true,
      'show_admin_column'          => false,
      'show_in_nav_menus'          => true,
      'show_tagcloud'              => true,
      'meta_box_cb'                => false,
    );
    register_taxonomy( 'food_modifiers', array( 'product' ), $args );

    do_action( 'wowrestro_after_register_modifiers' );
  }
}

WWRO_Modifiers::init();