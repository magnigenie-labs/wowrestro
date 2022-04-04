<?php
/**
 * WoWRestro Admin Functions
 *
 * @package  WoWRestro/Admin/Functions
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Display a FoodOrder help tip.
 *
 * @since 1.0
 *
 * @param  string $tip  Help tip text.
 * @param  bool   $allow_html Allow sanitized HTML if true or escape.
 * @return string
 */
function wowrestro_help_tip( $tip, $allow_html = false ) {
  $tip = esc_attr( $tip );
  return '<span class="wowrestrohelp-tip" data-tip="' . $tip . '"></span>';
}

function is_valid_license( $key ) {

  // data to send in our API request
  $api_params = array(
    'edd_action' => 'activate_license',
    'item_id'    => '2138',
    'item_name'  => 'All Access',
    'license'    => $key,
    'url'        => 'https://www.woorestro.com/'
  );

  // Call the custom API.
  $response = wp_remote_post( 'https://www.woorestro.com', array( 'timeout' => 15, 'sslverify' => false, 'body' => $api_params ) );

  // make sure the response came back okay
  if ( is_wp_error( $response )
    || 200 !== wp_remote_retrieve_response_code( $response ) ) {

    if ( is_wp_error( $response ) ) {
      $message = $response->get_error_message();
    }
    else {
      $message = __( 'An error occurred, please try again.' );
    }

  } else {

    $license_data = json_decode( wp_remote_retrieve_body( $response ) );

    if ( false === $license_data->success ) {

      switch( $license_data->error ) {

          case 'expired' :

            $message = sprintf(
              __( 'Your license key expired on %s.' ),
              date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
            );
            break;

          case 'revoked' :

            $message = __( 'Your license key has been disabled.' );
            break;

          case 'missing' :

            $message = __( 'Invalid license.' );
            break;

          case 'invalid' :
          case 'site_inactive' :

            $message = __( 'Your license is not active for this URL.' );
            break;

          case 'item_name_mismatch' :

            $message = sprintf( __( 'This appears to be an invalid license key for %s.' ), $name );
            break;

          case 'no_activations_left':

            $message = __( 'Your license key has reached its activation limit.' );
            break;

          default :

            $message = __( 'An error occurred, please try again.' );
            break;
      }
    }
  }

  // Check if anything passed on a message constituting a failure
  if ( ! empty( $message ) )
    $return = array( 'status' => 'error', 'message' => $message );
  else {
    $return = array( 'status' => 'updated', 'message' => 'Your license is successfully activated.' );
  }

  return $return;

}