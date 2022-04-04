<?php
/**
 * The template for displaying product popup wowrestromodal
 *
 * This template can be overridden by copying it to yourtheme/wowrestro
 *
 * @package WoWRestro/Templates
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
$enable_delivery = ( get_option( 'enable_delivery' ) == 'yes' ) ? true : false;
$enable_pickup   = ( get_option( 'enable_pickup' ) == 'yes' ) ? true : false ;

$service_type = wowrestro_get_session( 'service_type' );
$service_time = wowrestro_get_session( 'service_time' );
$service_modal_option = get_option( '_wowrestro_service_modal_option', 'auto' );

if ( ( ! isset( $service_type ) || ! isset( $service_time ) ) && in_array( $service_modal_option, array( 'hide', 'auto' ) ) ) {
  // Get defalut value
  $service_type = get_option( '_wowrestro_default_selected_service', 'pickup' );
}

?>

<?php if ( $enable_delivery && $enable_pickup ) : ?>
<ul class="nav nav-tabs" id="wowrestroTab" role="tablist">

  <li class="nav-item wwr-pickup <?php echo ( empty( $service_type ) || $service_type == 'pickup' ) ? 'active' : '' ?>">
    <a class="nav-link wwr-primary-color" id="pickup-tab" data-toggle="tab" href="#pickup" role="tab" aria-controls="pickup" aria-selected="true">
      <?php echo wwro_get_service_label( 'pickup' ); ?>
    </a>
  </li>

  <li class="nav-item wwr-delivery <?php echo ( $service_type == 'delivery' ) ? 'active' : '' ?>">
    <a class="nav-link wwr-primary-color" id="delivery-tab" data-toggle="tab" href="#delivery" role="tab" aria-controls="delivery" aria-selected="false">
      <?php echo wwro_get_service_label( 'delivery' ); ?>
    </a>
  </li>

</ul>
<?php endif; ?>

<div class="tab-content">

  <div class="service-type-options">
    <?php if ( $enable_pickup ) : ?>
      
      <div class="tab-pane <?php echo ( empty( $service_type ) || $service_type == 'pickup' ) ? 'active' : '' ?> " data-service-type="pickup" id="pickup" role="tabpanel" aria-labelledby="pickup-tab">

        <?php
          if ( wwro_check_store_closed( 'pickup' ) ) :
            wwro_store_meassge( 'pickup' );
          else : 
            ?>

              <?php echo __( 'When would you like your order?', 'wowrestro' ); ?>
                            
              <div class="wwr-oreder-service-settings">
                <div class="wwr-oreder-service-settings-options">
                    <?php wwro_render_asap( 'pickup' ); ?>
                    <?php wwro_render_later( 'pickup' ); ?>
                </div>
              </div>

              <?php $p_hide_div = ( $service_time == 'asap' && $service_type == 'pickup' ) ? 'd-none' : '' ?>
              
              <?php do_action( 'wowrestro_before_service_time', 'pickup' ); ?>
              
              <div class="wwr-store-timing <?php echo esc_attr( $p_hide_div ); ?>">

                <?php $get_store_hours = apply_filters( 'wowrestro_pickup_store_hours' , wwro_get_store_timing( 'pickup' ) ); ?>

                <?php wwro_render_service_hours( $get_store_hours, 'pickup' ); ?>

              </div>

              <?php do_action( 'wowrestro_after_service_time', 'pickup' ); ?>
            <?php
          endif;
        ?>

      </div>

    <?php endif; ?>

    <?php if ( $enable_delivery ) : ?>

      <div class="tab-pane <?php echo ( $service_type == 'delivery' ) ? 'active' : '' ?> " data-service-type="delivery" id="delivery" role="tabpanel" aria-labelledby="delivery-tab">

        <?php
          if ( wwro_check_store_closed( 'delivery' ) ) :
            wwro_store_meassge( 'delivery' );
          else : 
            ?>
              <?php echo __( 'When would you like your order?', 'wowrestro' ); ?>
              
              <div class="wwr-oreder-service-settings">
                <div class="wwr-oreder-service-settings-options">
                    <?php wwro_render_asap( 'delivery' ); ?>
                    <?php wwro_render_later( 'delivery' ); ?>
                </div>
              </div>

              <?php $d_hide_div = ( $service_time == 'asap' && $service_type == 'delivery' ) ? 'd-none' : '' ?>

              <?php do_action( 'wowrestro_before_service_time', 'delivery' ); ?>

              <div class="wwr-store-timing <?php echo esc_attr( $d_hide_div ); ?>">

                <?php $get_store_hours = apply_filters( 'wowrestro_delivery_store_hours' , wwro_get_store_timing( 'delivery' ) ); ?>

                <?php wwro_render_service_hours( $get_store_hours, 'delivery' ); ?>
                
              </div>
              
              <?php do_action( 'wowrestro_after_service_time', 'delivery' ); ?>
            <?php
          endif;
        ?>

      </div>

    <?php endif; ?>
  </div>

</div>