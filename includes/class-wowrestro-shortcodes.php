<?php
/**
 * Shortcodes
 *
 * @package WoWRestro/Classes
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * WoWRestro Shortcodes class.
 */
class WWRO_Shortcodes {

  /**
   * Init shortcodes.
   */
  public static function init() {
    $shortcodes = array(
      'wowrestro'       => __CLASS__ . '::wowrestro_page',
    );

    foreach ( $shortcodes as $shortcode => $function ) {
      add_shortcode( apply_filters( "{$shortcode}_shortcode_tag", $shortcode ), $function );
    }
  }

  /**
   * Show WoWRestro Page.
   *
   * @param array $atts Attributes.
   * @return string
   */
  public static function wowrestro_page( $atts = array() ) {

    global $shortcode_args;

    $default_args = array(
      'posts_per_page'    => -1,
      'post_type'         => 'product',
      'post_status'       => 'publish',
      'category'          => '',
      'show_search'       => 'yes',
    );

    $shortcode_args = wp_parse_args( $atts, $default_args );
    $category_ids   = wwro_render_shortcode_cats( $shortcode_args );

    ob_start();

    wwro_header();

    wwro_get_template( 'wowrestro-categories.php', 
      array(
        'shortcode_args' => $shortcode_args,
        'category_ids'   => $category_ids,
      ) 
    );

    wwro_get_template( 'wowrestro-products.php', 
      array(
        'shortcode_args' => $shortcode_args,
        'category_ids'   => $category_ids,
      )
    );

    wwro_footer();
    
    return ob_get_clean();
  } 
}