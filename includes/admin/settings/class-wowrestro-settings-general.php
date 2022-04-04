<?php
/**
 * WoWRestro General Settings
 *
 * @package WoWRestro/Admin
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Settings_General', false ) ) {
  return new WWRO_Settings_General();
}

/**
 * WWRO_Settings_General.
 */
class WWRO_Settings_General extends WWRO_Settings_Page {

  /**
   * Constructor.
   */
  public function __construct() {
    
    $this->id     = 'general';
    $this->label  = __( 'General', 'wowrestro' );

    parent::__construct();
  }

  /**
   * Get settings array.
   *
   * @return array
   */
  public function get_settings() {

    $settings = apply_filters(
      'wowrestro_general_settings',
      array(

        array(
          'title'   => __( 'General Options', 'wowrestro' ),
          'type'    => 'title',
          'desc'    => '',
          'id'      => 'general_options',
        ),

        array(
          'title'   => __( 'Override Shop Page', 'wowrestro' ),
          'desc'    => __( 'Change WooCommerce default shop page.', 'wowrestro' ),
          'id'      => '_wowrestro_overwite_shop_page',
          'default' => 'default',
          'type'    => 'select',
          'options'   => array(
            'default'         => __( 'Use Default Shop page.', 'wowrestro' ),
            'only_food_item'  => __( 'Show Only WOWRestro item on shop page.', 'wowrestro' ),
            'only_shop'       => __( 'Remove WOWRestro items from shop page.', 'wowrestro' ),
          ),
          'class'     => 'wc-enhanced-select',
        ),

        array(
          'title'   => __( 'Shop Sales type', 'wowrestro' ),
          'desc'    => __( 'Choose what you want to sale on your website.', 'wowrestro' ),
          'id'      => '_wowrestro_sales_type',
          'default' => 'all_product',
          'type'    => 'select',
          'options'   => array(
            'all_product'     => __( 'Sale all types of product.', 'wowrestro' ),
            'only_food_item'  => __( 'Sale only WOWRestro item.', 'wowrestro' ),
          ),
          'class'     => 'wc-enhanced-select',
        ),

        array(
          'title'   => __( 'Veg / Non Veg option for WOWRestro items', 'wowrestro' ),
          'desc'    => __( 'Check this box if you want to include veg / non veg option to your WOWRestro items.', 'wowrestro' ),
          'id'      => '_wowrestro_include_veg_non_veg',
          'default' => 'yes',
          'type'    => 'checkbox',
        ),

        array(
          'title'     => __( 'Exclude Categories', 'wowrestro' ),
          'desc'      => __( 'This would exclude the categories from the frontend categories menu and items under those.', 'wowrestro' ),
          'id'        => '_wowrestro_exclude_categories',
          'type'      => 'multiselect',
          'desc_tip'  =>  true,
          'options'   => $this->wowrestro_get_wc_categories(),
          'class'     => 'wc-enhanced-select',
        ),

        array(
          'title'     => __( 'Exclude Products', 'wowrestro' ),
          'desc'      => __( 'This would exclude the products from the frontend items.', 'wowrestro' ),
          'id'        => '_wowrestro_exclude_products',
          'type'      => 'multiselect',
          'desc_tip'  =>  true,
          'options'   => $this->wowrestro_get_wc_products(),
          'class'     => 'wc-enhanced-select',
        ),

        array(
          'type' => 'sectionend',
          'id'   => 'general_options',
        ),

      )
    );

    return apply_filters( 'wowrestro_get_settings_' . $this->id, $settings );
  }

  /**
   * List out the WooCommerce Categories for multiselect option
   *
   * @return array categories 
   */
  public function wowrestro_get_wc_categories() {
    
    $terms = get_terms( array(
      'taxonomy' => 'product_cat',
      'hide_empty' => false,
      'fields' => 'id=>name'
    ));

    if ( !is_wp_error( $terms ) ) 
      return $terms;
    else
      return array('Select Categories');
  }

  /**
   * List out the WooCommerce products for multiselect option
   *
   * @return array products 
   */
  public function wowrestro_get_wc_products() {
    
    $posts = get_posts( array(
      'post_type' => 'product',
      'post_status' => 'publish',
    ));

    $posts = wp_list_pluck( $posts, 'post_title', 'ID' );

    if( !is_wp_error( $posts ) ) 
      return $posts;
    else
      return array('Select Posts');
  }

  /**
   * Output the settings.
   */
  public function output() {
    
    $settings = $this->get_settings();
    WWRO_Admin_Settings::output_fields( $settings );
  }

  /**
   * Save settings.
   */
  public function save() {
    
    $settings = $this->get_settings();
    WWRO_Admin_Settings::save_fields( $settings );
  }
}

return new WWRO_Settings_General();