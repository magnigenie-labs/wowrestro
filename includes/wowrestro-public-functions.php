<?php
/**
 * WoWRestro Template
 *
 * Functions for the templating system.
 *
 * @package  WoWRestro\Functions
 * @version 1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wwro_header' ) ) {

  /**
   * Output the header for WoWRestro.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_header( $echo = true ) {
    
    ob_start();

    wwro_get_template( 'wowrestro-header.php' );

    $container_start = apply_filters( 'wowrestro_header', ob_get_clean() );

    if ( $echo ) {
      echo $container_start;
    } else {
      return $container_start;
    }

  }

}

if ( ! function_exists( 'wwro_footer' ) ) {

  /**
   * Output the footer for WoWRestro.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_footer( $echo = true ) {
    
    ob_start();

    wwro_get_template( 'wowrestro-footer.php' );

    $container_end = apply_filters( 'wowrestro_footer', ob_get_clean() );

    if ( $echo ) {
      echo $container_end;
    } else {
      return $container_end;
    }

  }

}

if ( ! function_exists( 'wwro_category_start' ) ) {

  /**
   * Output the start of a WoWRestro category.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_category_start( $echo = true ) {
    
    ob_start();

    wwro_get_template( 'loop/wowrestro-category-start.php' );

    $container_start = apply_filters( 'wowrestro_category_start', ob_get_clean() );

    if ( $echo ) {
      echo $container_start;
    } else {
      return $container_start;
    }

  }

}

if ( ! function_exists( 'wwro_category_end' ) ) {

  /**
   * Output the end of a WoWRestro category.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_category_end( $echo = true ) {
    ob_start();

    wwro_get_template( 'loop/wowrestro-category-end.php' );

    $container_end = apply_filters( 'wowrestro_category_end', ob_get_clean() );

    if ( $echo ) {
      echo $container_end;
    } else {
      return $container_end;
    }

  }

}

if ( ! function_exists( 'wwro_product_start' ) ) {

  /**
   * Output the start of a WoWRestro products.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_product_start( $echo = true ) {
    ob_start();

    wwro_get_template( 'loop/wowrestro-product-start.php' );

    $container_start = apply_filters( 'wowrestro_product_start', ob_get_clean() );

    if ( $echo ) {
      echo $container_start;
    } else {
      return $container_start;
    }

  }

}

if ( ! function_exists( 'wwro_product_end' ) ) {

  /**
   * Output the end of a WoWRestro products.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_product_end( $echo = true ) {
    ob_start();

    wwro_get_template( 'loop/wowrestro-product-end.php' );

    $container_end = apply_filters( 'wowrestro_product_end', ob_get_clean() );

    if ( $echo ) {
      echo $container_end;
    } else {
      return $container_end;
    }

  }

}

if ( ! function_exists( 'wwro_listing_start' ) ) {

  /**
   * Output the start of a WoWRestro products listings.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_listing_start( $echo = true, $term_id = '' ) {
    ob_start();

    wwro_get_template( 'loop/wowrestro-product-listing-start.php', array(
      'category_id' => $term_id,
    ) );

    $container_start = apply_filters( 'wowrestro_product_listing_start', ob_get_clean() );

    if ( $echo ) {
      echo $container_start;
    } else {
      return $container_start;
    }

  }

}

if ( ! function_exists( 'wwro_listing_end' ) ) {

  /**
   * Output the end of a WoWRestro products.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_listing_end( $echo = true, $term_id = '' ) {
    ob_start();

    wwro_get_template( 'loop/wowrestro-product-listing-end.php', array(
      'category_id' => $term_id,
    ) );

    $container_end = apply_filters( 'wowrestro_product_listing_end', ob_get_clean() );

    if ( $echo ) {
      echo $container_end;
    } else {
      return $container_end;
    }

  }

}

if ( ! function_exists( 'wwro_template_loop_category_title' ) ) {

  /**
   * Show the subcategory title in the product loop.
   *
   * @param object $category Category object.
   * @since 1.0
   */
  function wwro_template_loop_category_title( $category ) {

    $show_count = get_option( '_wowrestro_listing_show_sidebar_count', 'no' );
    ?>
    <a class="wowrestro-loop-category__title" data-category-title="<?php echo esc_attr( $category->slug ); ?>" href="javascript:void(0);">
      <?php
      echo esc_html( $category->name );
      if ( $category->count > 0 && 'yes' === $show_count ) {
        echo apply_filters( 'wowrestro_subcategory_count_html', ' <span class="wowrestro-items-count">' . esc_html( $category->count ) . '</span>', $category );
      }
      ?>
    </a>
    <?php

  }

}

if ( ! function_exists( 'wwro_product_title' ) ) {

  /**
   * Output the product title.
   * @since 1.0
   */
  function wwro_product_title() {
    wwro_get_template( 'single-product/title.php' );
  }

}

if ( ! function_exists( 'wwro_template_show_images' ) ) {

  /**
   * Output the product image.
   * @since 1.0
   */
  function wwro_template_show_images() {
    wwro_get_template( 'single-product/product-image.php' );
  }

}

if ( ! function_exists( 'wwro_product_price' ) ) {

  /**
   * Output the product price.
   * @since 1.0
   */
  function wwro_product_price() {
   // ob_start();
      wwro_get_template( 'single-product/price.php' );
    return;
  }
}

if ( ! function_exists( 'wwro_product_short_description' ) ) {

  /**
   * Output the product short description.
   * @since 1.0
   */
  function wwro_product_short_description() {
    //ob_start();
      wwro_get_template( 'single-product/short-description.php' );
    return;
  }
}


if ( ! function_exists( 'wwro_footer_cart' ) ) {

  /**
   * Output the footer cart.
   * @since 1.0
   */
  function wwro_footer_cart() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'wowrestro') ) {
      wwro_cart_start();
      wwro_get_template( 'cart/wowrestro-cart.php' );
      wwro_cart_end();
      wwro_get_template( 'cart/product-modal.php' );
      wwro_get_template( 'services/service-modal.php' );
    }    
  }
}

if ( ! function_exists( 'wwro_cart_start' ) ) {

  /**
   * Output the start of a WoWRestro cart.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_cart_start( $echo = true ) {
    
    ob_start();

    wwro_get_template( 'cart/cart-start.php' );

    $cart_container_start = apply_filters( 'wowrestro_cart_start', ob_get_clean() );

    if ( $echo ) {
      echo $cart_container_start;
    } else {
      return $cart_container_start;
    }
  }
}

if ( ! function_exists( 'wwro_cart_end' ) ) {

  /**
   * Output the end of a WoWRestro cart.
   *
   * @param bool $echo Should echo?.
   * @return string
   * @since 1.0
   */
  function wwro_cart_end( $echo = true ) {
    
    ob_start();

    wwro_get_template( 'cart/cart-end.php' );

    $cart_container_end = apply_filters( 'wowrestro_cart_end', ob_get_clean() );

    if ( $echo ) {
      echo $cart_container_end;
    } else {
      return $cart_container_end;
    }
  }
}

if ( ! function_exists( 'wwro_render_food_modifiers' ) ) {

  /**
   * Output the end of a WoWRestro cart.
   *
   * @param obj string.
   * @return html
   * @since 1.0
   */
  function wwro_render_food_modifiers( $product, $cart_key ) {
    
    ob_start();

    wwro_get_template( 'single-product/add-to-cart/modifiers.php', 
      array(
        'product'   => $product,
        'cart_key'  => $cart_key,
      )
    );
   
    $modifier_data = ob_get_clean();
    
    echo $modifier_data;
  }
}

if ( !function_exists( 'wwro_thankyou_modifier_details' ) ) {

  /**
   * Output the modifier data on thankyou page
   * 
   * @param int $item_id
   * @param object $item
   * @param object $order
   * 
   * @return html
   * @since 1.1
   */
  function wwro_thankyou_modifier_details( $item_id, $item, $order ) {

    ob_start();

    $modifier_items = wwro_get_modifiers_from_meta( $item_id );

    if ( !empty( $modifier_items ) && is_array( $modifier_items ) ) {
      foreach( $modifier_items as $key => $modifier_item ) {
        $modifier_name  = isset( $modifier_item['name'] ) ? $modifier_item['name'] : '';
        $wwro_modifier_price = isset( $modifier_item['price'] ) ? $modifier_item['price'] : '';

        ?>
          <dl class="variation">
            <dt class="variation-<?php echo ( str_replace( ' ', '', $modifier_name ) ); ?>"> - <?php echo $modifier_name; ?>: </dt>
            <dd class="variation-<?php echo ( str_replace( ' ', '', $modifier_name ) ); ?>">
              <p><span class="woocommerce-Price-amount amount"><?php echo $wwro_modifier_price; ?></p>
            </dd>
          </dl>
        <?php

      }
    }

    $html = ob_get_clean();

    echo apply_filters( 'wowrestro_thankyou_modifier_data', $html, $modifier_items );
    
  }


}
