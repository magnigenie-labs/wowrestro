<?php
/**
 * WoWRestro Styling Settings
 *
 * @package WoWRestro/Admin
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WWRO_Settings_Styling', false ) ) {
  return new WWRO_Settings_Styling();
}

/**
 * WWRO_Settings_Styling.
 */
class WWRO_Settings_Styling extends WWRO_Settings_Page {

  /**
   * Constructor.
   */
  public function __construct() {
    
    $this->id    = 'style';
    $this->label = __( 'Style', 'wowrestro' );

    parent::__construct();
  }

  /**
   * Get sections.
   *
   * @return array
   */
  public function get_sections() {
    
    $sections = array(
      'stylesheet' => __( 'Customize', 'wowrestro' ),
    );
    return apply_filters( 'wowrestro_get_sections_' . $this->id, $sections );
  }

  /**
   * Output the settings.
   */
  public function output() {
    
    global $current_section;

    $settings = $this->get_settings( $current_section );
    WWRO_Admin_Settings::output_fields( $settings );
  }

  /**
   * Save settings.
   */
  public function save() {
    
    global $current_section;

    $settings = $this->get_settings( $current_section );
    WWRO_Admin_Settings::save_fields( $settings );

    if ( $current_section ) {
      do_action( 'wowrestro_update_options_' . $this->id . '_' . $current_section );
    }

  }

  /**
   * Get settings array.
   *
   * @param string $current_section Current section name.
   * @return array
   */
  public function get_settings( $current_section = '' ) {

    $settings = apply_filters(
      
      'wowrestro_style_settings',
      
      array(

        array(
          'title'     => __( 'Style Settings', 'wowrestro' ),
          'type'      => 'title',
          'id'        => 'style_options',
        ),

        array(
          'title'     => __( 'Select Primary Color', 'wowrestro' ),
          'desc'      => __( 'Change color for Links, Buttons and Active Classes etc.', 'wowrestro' ),
          'id'        => '_wowrestro_primary_color_scheme',
          'default'   => '#267dc9',
          'type'      => 'text',
          'class'     => 'wowrestro-colorpicker',
        ),

        array(
          'title'     => __( 'Select Secondary Color', 'wowrestro' ),
          'desc'      => __( 'Change for Button/Link Hovers, Borders etc.', 'wowrestro' ),
          'id'        => '_wowrestro_secondary_color_scheme',
          'default'   => '#000000',
          'type'      => 'text',
          'class'     => 'wowrestro-colorpicker',
        ),

        array(
          'title'     => __( 'Include item count for category', 'wowrestro' ),
          'desc'      => __( 'Displays the number of items available for the category.', 'wowrestro' ),
          'id'        => '_wowrestro_listing_show_sidebar_count',
          'default'   => 'no',
          'type'      => 'checkbox',
        ),

        array(
          'title'     => __( 'WOWRestro Item Image', 'wowrestro' ),
          'desc'      => __( 'Please select how would you like to show the item images on frontend.', 'wowrestro' ),
          'id'        => '_wowrestro_listing_item_image_display',
          'default'   => 'medium',
          'type'      => 'select',
          'options'   => array(
            'medium'    => __( 'Medium Image', 'wowrestro' ),
            'small'     => __( 'Small Image', 'wowrestro' ),
            'hide'      => __( 'Hide Image Completely', 'wowrestro' ),
          ),
          'desc_tip'  => true,
          'class'     => 'wc-enhanced-select',
        ),

        array(
          'title'     => __( 'Include Lazy Load', 'wowrestro' ),
          'desc'      => __( 'Item image will be loaded once you scroll to it. Loads your items page faster.', 'wowrestro' ),
          'id'        => '_wowrestro_enable_lazy_loading',
          'default'   => 'yes',
          'type'      => 'checkbox',
        ),

        array(
          'title'     => __( 'Show Image in Popup', 'wowrestro' ),
          'desc'      => __( 'Display item image/gallery in popup.', 'wowrestro' ),
          'id'        => '_wowrestro_popup_enable_image',
          'default'   => 'yes',
          'type'      => 'checkbox',
        ),
        
        array(
          'type'      => 'sectionend',
          'id'        => 'style_options',
        ),
      )
    );

    return apply_filters( 'wowrestro_get_settings_' . $this->id, $settings, $current_section );
  }
}

return new WWRO_Settings_Styling();