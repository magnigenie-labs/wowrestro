<?php
/**
 * WoWRestro services related functions and actions.
 *
 * @package WoWRestro/Classes
 * @since   1.0
 */

defined( 'ABSPATH' ) || exit;

class WWRO_Services {

	public $default_type;

    /** 
     * Constructor of Services Class
     */
	public function __construct() {

        $default_selected_service = get_option( '_wowrestro_default_selected_service', 'pickup' );

		$this->default_type = $default_selected_service;

        add_action( 'wp_enqueue_scripts', array( $this, 'wowrestro_checkout_enqueue' ) );
		add_action( 'woocommerce_checkout_order_review', array( $this, 'wowrestro_checkout_fields' ), 1 );
        add_action( 'woocommerce_checkout_process', array( $this, 'wowrestro_process_checkout_fields' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'wowrestro_save_services_meta' ) );
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'wowrestro_admin_services_meta' ) );
        add_action( 'woocommerce_order_details_before_order_table_items', array( $this, 'wowrestro_receipt_services_meta') );
        add_action( 'woocommerce_email_before_order_table', array ( $this, 'wowrestro_email_services_meta' ) );
        add_action( 'woocommerce_checkout_update_order_review', array( $this, 'wowrestro_update_service_option_checkout' ), 10 );
        add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'wowrestro_update_service_time_checkout' ) );
        add_action( 'wowrestro_service_time_option_wrap_before', array( $this, 'wowrestro_service_time_option_wrap_before_html' ) );
        add_action( 'wowrestro_service_time_option_wrap_after', array( $this, 'wowrestro_service_time_option_wrap_after_html' ) );

	}

    /**
     *  Add html to service time wrap before
     */
    public function wowrestro_service_time_option_wrap_before_html() {
        echo "<div class='wwr-service-time-wrap wwr-checkout-service-tm'>";
    }
    /**
     *  Add html to service time wrap after
     */
    public function wowrestro_service_time_option_wrap_after_html() {
        echo "</div>";
    }

    /**
     * Update sevice details on checkout
     * 
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    function wowrestro_update_service_option_checkout( $posted_data ) {

        global $woocommerce;

        $time_format  = wwro_get_store_time_format();

        // Parsing posted data on checkout
        $vars = explode( '&', $posted_data );
        $post = array();
        foreach ( $vars as $k => $value ){
            $v = explode( '=', urldecode( $value ) );
            $post[$v[0]] = $v[1];
        }

        if ( $post['wowrestro_hidden_field'] == 'no' ) return;

        $service_type       = isset( $post['wowrestro_service_type'] ) ? sanitize_text_field( $post['wowrestro_service_type'] ) : '';
        $service_option     = isset( $post['wowrestro_service_time_option'] ) ? sanitize_text_field( $post['wowrestro_service_time_option'] ) : '';

        $interval           = get_option( $service_type . '_time_interval' );
        $service_time       = isset( $post['wowrestro_service_time'] ) ? sanitize_text_field( $post['wowrestro_service_time'] ) : '';
        $service_timestamp  = $service_time;

        if( strpos( $service_time, ' - ' ) !== false ){
            $service_time_array = explode( "-", $service_time );
            $start_interval     = current( $service_time_array );
            $end_interval       = end( $service_time_array );    
            $store_adj_time     = date( $time_format, strtotime( trim( $end_interval  ) ) );
            $service_time       = date( $time_format, strtotime( trim( $start_interval ) ) );
         } else {
            $store_adj_time = date( $time_format, strtotime( $service_time .' +' . $interval .' minutes' ) );
         }    
        
        $service_time   = date( $time_format, strtotime( $service_time ) );

        $service_time_val = $service_time . ' - ' . $store_adj_time;

        if ( !empty( $service_time ) ) {
            wowrestro_remove_session( 'service_time' );
            wowrestro_set_session( 'service_time', $service_time_val );
            wowrestro_set_session( 'service_timestamp', $service_timestamp );
        }
        if ( !empty( $service_type ) ) {
            wowrestro_remove_session( 'service_type' );
            wowrestro_set_session( 'service_type', $service_type );
        }

        if ( $service_option == 'asap' ) {
            wowrestro_remove_session( 'service_time' );
            wowrestro_set_session( 'service_time', 'asap' );
        }

    }

    /**
     * Send time and date service type is changed on checkout
     * 
     * @author WoWRestro
     * @since 1.0
     * @return array
     */
    public function wowrestro_update_service_time_checkout( $response ) {

        // Parsing posted data on checkout
        $vars = explode( '&', $_POST['post_data'] );
        $post = array();
        foreach ( $vars as $k => $value ){
            $v = explode( '=', urldecode( $value ) );
            $post[$v[0]] = $v[1];
        }

        if ( !empty( $post['wowrestro_hidden_field'] ) && $post['wowrestro_hidden_field'] == 'no' ) return $response;

        $service_type  = isset( $post['wowrestro_service_type'] ) ? $post['wowrestro_service_type'] : '';
        wowrestro_set_session( 'service_type', $service_type );

        $service_hours = $this->get_service_times( $service_type );

        $response['store_hours'] = array_values( $service_hours );

        return $response;

    }
    
    /**
     * Redirect based on Service type scripts
     * in checkout page
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    public function wowrestro_checkout_enqueue() {

        global $woocommerce;

        $checkout_url   = wc_get_checkout_url();
        $pickup_url     = $checkout_url . '?type=pickup';
        $delivery_url   = $checkout_url . '?type=delivery';

        if( is_checkout() ) {

            wp_enqueue_script('jquery-ui-datepicker');

            $service_date = 'jQuery(function($){

                $(document).on("change", "input[name=\'wowrestro_service_type\']", function(e) {
                    e.preventDefault();
                    if( $(this).val() == \'pickup\' ){
                        $(\'body\').addClass( \'checkout-service-pickup-option\' );
                    }else{
                        $(\'body\').removeClass( \'checkout-service-pickup-option\' );
                    }
                    $("#wowrestro_hidden_field").val("yes");
                    $(document.body).trigger("update_checkout");
                });
                $(document).on(\'change\', \'.wowrestro-service-time-option\', function(e){
                    e.preventDefault();
                    if ( $(this).val() == \'asap\' ) {
                        $(\'.wowrestro_co_service_time\').addClass(\'d-none\');
                    } else {
                        $(\'.wowrestro_co_service_time\').removeClass(\'d-none\');
                    }
                });

            });';

            wp_add_inline_script('jquery-ui-datepicker', $service_date);
        }
    }

    /**
     * Show available service type and time options in 
     * Checkout page. loaded from session if set already.
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
	public function wowrestro_checkout_fields() {


        echo "<div id='wowrestro_checkout_fields'>";
        $service_type       = wowrestro_get_session( 'service_type' );
        $service_time       = wowrestro_get_session( 'service_time' );
        $service_time_stamp = wowrestro_get_session( 'service_timestamp' );

		$show_service = wwro_get_available_services();

		if( ! empty( $service_type ) && $service_type == 'delivery' ) {
            $default = 'delivery';
        } elseif( ! empty( $service_type ) && $service_type == 'pickup' ) {
            $default = 'pickup';
        } else {
            $default = $this->default_type;
        }

        do_action( 'wowrestro_before_checkout_fields' );

        $service_time_option = ( $service_time == 'asap' ) ? 'asap' : 'later';
        $hide_div = ( $service_time == 'asap' ) ? 'd-none' : '';

        if( $show_service == 'all' ) {

        	woocommerce_form_field( 'wowrestro_service_type', array(
        		'type'          => 'radio',
        		'required'		=>'true',
        		'class'         => array( 'wowrestro_co_service_type' ),
        		'label'         => __( 'Service Type','wowrestro' ),
        		'default'		=> $default,
        		'checked'		=> 'checked',
        		'options'		=> array(
                	'pickup'    => __( wwro_get_service_label( 'pickup' ),'wowrestro' ),
                    'delivery'  => __( wwro_get_service_label( 'delivery' ),'wowrestro' ),
            	)
        	) );
        
        } else if( $show_service == 'delivery' ) {

        	woocommerce_form_field( 'wowrestro_service_type', array(
        		'type'          => 'radio',
        		'required'		=>'true',
        		'class'         => array( 'wowrestro_co_service_type delivery_only' ),
        		'label'         => __( 'Service Type', 'wowrestro' ),
        		'default'		=> 'delivery',
        		'checked'		=> 'checked',
        		'options'		=> array(
                	'delivery'  => __( wwro_get_service_label( 'delivery' ),'wowrestro' ),
            	)
        	) );
        
        } else if( $show_service == 'pickup' ) {

        	woocommerce_form_field( 'wowrestro_service_type', array(
        		'type'          => 'radio',
        		'required'		=>'true',
        		'class'         => array( 'wowrestro_co_service_type pickup_only' ),
        		'label'         => __( 'Service Type', 'wowrestro' ),
        		'default'		=> 'pickup',
        		'checked'		=> 'checked',
        		'options'		=> array(
                	'pickup'    => __( wwro_get_service_label( 'pickup' ),'wowrestro' ),
            	)
        	) );
        }

        $asap_text = get_option( '_wowrestro_asap_text', __( 'ASAP', 'wowrestro' ) );
        $later_text = get_option( '_wowrestro_later_text', __( 'Later', 'wowrestro' ) );

        $asap_option = get_option( '_wowrestro_enable_asap', 'yes' );
        if ( !empty( $asap_option ) && $asap_option == 'yes' ) {
            do_action( 'wowrestro_service_time_option_wrap_before' );
            woocommerce_form_field( 'wowrestro_service_time_option', array(
                'type'          => 'radio',
                'required'      =>'true',
                'label'         => __( 'When would you like your order?', 'wowrestro' ),
                'default'       => $service_time_option,
                'checked'       => 'checked',
                'input_class'   => array( 'wowrestro-service-time-option' ),
                'options'       => array(
                    'asap'  => $asap_text,
                    'later' => $later_text,
                )
            ) );
            do_action( 'wowrestro_service_time_option_wrap_after' );
        }

        do_action( 'wowrestro_after_checkout_service_option' );

        woocommerce_form_field( 'wowrestro_service_time', array(
            'type'          => 'select',
            'required'      => 'true',
            'default'       => $service_time,
            'class'         => array( 'wowrestro_co_service_time ' . $hide_div ),
            'input_class'   => array( 'input-text' ),
            'label'         => __( 'Time', 'wowrestro' ),
            'options'       => $this->get_service_times( $default )
        ) );

        woocommerce_form_field( 'wowrestro_hidden_field', array(
            'type'          => 'hidden',
            'default'       => 'no',
            'class'         => array( 'wowrestro_hidden_field' )
        ) );

        do_action( 'wowrestro_after_checkout_fields' );
        
        echo "</div>";
	}

    /**
     * Get the list of available service dates
     *
     * @author WoWRestro
     * @since 1.0
     * @return arr $date_range
     */
    public function get_service_dates( $service_type ) {

        $wowrestro_delivery_days  = 'all';
        $wowrestro_pickup_days    = 'all';
        $wowrestro_preorder_days  = 1;

        $wowrestro_no_pickup_days = array();
        $wowrestro_no_dlvery_days = array();
        $wowrestro_holidays       = array();

        $current_date = date_i18n( 'Y-m-d');
        $preorder_date = date_i18n( 'Y-m-d', strtotime( '+' . $wowrestro_preorder_days . ' days' ) );

        $date_range = $formatted_date = $raw_date = [];
        $date_range = $this->create_date_range( $current_date, $preorder_date );

        return $date_range;
    }

    /**
     * Prepare the date range from available dates 
     *
     * @author WoWRestro
     * @since 1.0
     * @return arr $range
     */
    public function create_date_range( $startDate, $endDate ) {

        $begin = new DateTime( $startDate );
        $end = new DateTime( $endDate );

        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod( $begin, $interval, $end );

        $range = [];

        foreach( $date_range as $date ) {

            $raw_date = $date->format( 'Y-m-d' );
            $formatted_date = $date->format( get_option( 'date_format' ) );
            $range[$raw_date] = $formatted_date;
        }

        return $range;
    }

    /**
     * Get available service time strings based on admin settings
     *
     * @author WoWRestro
     * @since 1.0
     * @return arr $timings
     */
    public function get_service_times( $service_type ) {

        $service_hours = '';
        if ( $service_type == 'pickup' ) {
            $service_hours = apply_filters( 'wowrestro_pickup_store_hours' , wwro_get_store_timing( 'pickup' ) );            
        } else {
            $service_hours = apply_filters( 'wowrestro_delivery_store_hours' , wwro_get_store_timing( 'delivery' ) );
        }

        $get_store_hours    = $service_hours;
        $time_format        = wwro_get_store_time_format();
        $interval           = apply_filters( 'service_time_inteval', get_option( $service_type . '_time_interval' ), $service_type );
        $timeslot_mode      = apply_filters( 'wowrestro_timeslot_mode', 'single' );

        $timings = [];

        if ( !empty( $get_store_hours ) && is_array( $get_store_hours ) ) :
            $current_date   = current_time( 'Y-m-d' );
            $count = 0;
            $get_store_hours = array_unique( $get_store_hours );
            $get_store_hours = array_values( $get_store_hours );


            foreach( $get_store_hours as $store_time ) :
                $loop_time   = date( $time_format, $store_time );
                $loop_time_2 = '';
                $sep         = '';

                $day_number  = current_time( 'd' );
                $break_array = [];
                $break_array = apply_filters( 'wowrestro_disabled_times', $break_array, $service_type, $day_number );
                if ( in_array( $loop_time, $break_array ) ) {
                    $count++;
                    continue;
                }
                
                if ( $count + 1 < count( $get_store_hours ) ) {
                  $loop_time_2 = !empty( $get_store_hours[$count + 1] ) ? date( $time_format, $get_store_hours[$count + 1] ) : '';
                  $sep         = !empty( $get_store_hours[$count + 1] ) ? ' - ' : '';
                }
                
                $timeslot = apply_filters( 'wowrestro_disabled_adjacent_timeslot', null, $service_type, $loop_time );

                if ( $timeslot_mode == 'single' ) {
                  $display_format = $loop_time;
                } else if ( $timeslot_mode == 'multiple' ) {
                  $display_format = $loop_time . $sep . $loop_time_2;
                }

                if ( $timeslot != $loop_time ) {
                    $timings[$display_format]  = $display_format;
                }
                $count++;
            endforeach;
        endif;

        return $timings;
    }

    /**
     * Check for Service type and Time fields when order is processed
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    public function wowrestro_process_checkout_fields() {

        if ( empty( $_POST['wowrestro_service_time'] ) || ! isset( $_POST['wowrestro_service_time'] ) )
            wc_add_notice( __( 'Please choose service time for your Order.', 'wowrestro' ), 'error' );

        if ( empty( $_POST['wowrestro_service_type'] ) || ! isset( $_POST['wowrestro_service_type'] ) )
            wc_add_notice( __( 'Please choose service type for your Order.', 'wowrestro' ), 'error' );
        
    }

    /**
     * Save service details to Order Meta
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    public function wowrestro_save_services_meta( $order_id ) {

      $wowrestro_service_date = !empty( $_POST['wowrestro_service_date'] ) ? sanitize_text_field( $_POST['wowrestro_service_date'] ) : wowrestro_local_date( date('Y-m-d') );

      update_post_meta( $order_id, '_wowrestro_service_date', $wowrestro_service_date );

        if ( ! empty( $_POST['wowrestro_service_type'] ) ) {
            update_post_meta( $order_id, '_wowrestro_service_type', sanitize_text_field( $_POST['wowrestro_service_type'] ) );
        }

        if ( ! empty( $_POST['wowrestro_service_time'] ) ) {
         

           $service_time = sanitize_text_field( $_POST['wowrestro_service_time'] );

           $service_time_val = apply_filters( 'wowrestro_update_service_time', $service_time );

            update_post_meta( $order_id, '_wowrestro_service_time', $service_time_val );
            update_post_meta( $order_id, '_wowrestro_service_timestamp', strtotime( $_POST['wowrestro_service_time'] ) );
        }

        if ( $_POST['wowrestro_service_time_option'] == 'asap' ) {
            $asap_text = get_option( '_wowrestro_asap_text', __( 'ASAP', 'wowrestro' ) );
            update_post_meta( $order_id, '_wowrestro_service_time', sanitize_text_field( $asap_text ) );
        }

        // Empty the session for Service
        wowrestro_remove_session( 'service_type' );
        wowrestro_remove_session( 'service_time' );

    }

    /**
     * Display service details in admin order from order meta
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    public function wowrestro_admin_services_meta( $order ) {

        $order_id   = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();
        $service    = get_post_meta( $order_id, '_wowrestro_service_type', true );

        echo '<p> <strong>' . __( 'Time', 'wowrestro' ) . ' : </strong> ' . get_post_meta( $order_id, '_wowrestro_service_time', true ) . '</p>';
        echo '<p> <strong>' . __( 'Service Type', 'wowrestro' ) . ' : </strong> ' . wwro_get_service_label( $service ) . '</p>';

        do_action( 'wowrestro_history_service_date', $order_id );
    }

    /**
     * Display service details in Order Receipt from order meta
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    public function wowrestro_receipt_services_meta( $order ) {

        $order_id   = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();
        $service    = get_post_meta( $order_id, '_wowrestro_service_type', true );
        
        echo '<p> <strong>' . wwro_get_service_label( $service ) . __( ' Time : ', 'wowrestro' ) . ' </strong>' . get_post_meta( $order_id, '_wowrestro_service_time', true ) . '</p>';

        do_action( 'wowrestro_update_service_date', $service, $order_id );
    }

    /**
     * Display service details in Email Notification from order meta
     *
     * @author WoWRestro
     * @since 1.0
     * @return void
     */
    public function wowrestro_email_services_meta( $order ) {

        $order_id   = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();
        $service    = get_post_meta( $order_id, '_wowrestro_service_type', true );

        echo '<p> <strong>' . wwro_get_service_label( $service ) . __( ' Time : ', 'wowrestro' ) . ' </strong>' . get_post_meta( $order_id, '_wowrestro_service_time', true ) . '</p>';
    }
}

new WWRO_Services();