<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Avvance_Cart_Resume {
    public static function init(){
        add_action('woocommerce_before_cart', [__CLASS__, 'render_banner']);
        add_action('wp_ajax_avvance_check_status', [__CLASS__, 'ajax_check_status']);
        add_action('wp_ajax_nopriv_avvance_check_status', [__CLASS__, 'ajax_check_status']);
    }
    protected static function get_gateway(){
        if ( ! function_exists('WC') ) return null;
        $pgs = WC()->payment_gateways();
        return isset($pgs->payment_gateways()['avvance']) ? $pgs->payment_gateways()['avvance'] : null;
    }
    public static function render_banner(){
        if ( ! function_exists('WC') || ! WC()->session ) return;
        $data = WC()->session->get('avvance_current');
        if ( empty($data['apply_url']) || empty($data['order_id']) ) return;
        $order = wc_get_order( $data['order_id'] );
        if ( ! $order || $order->is_paid() ) return;
        $nonce = wp_create_nonce('avvance_cart');
        echo '<div class="woocommerce-info avvance-resume">'.
             esc_html__('You started an Avvance application. You can resume or check your status.', 'avvance-for-woocommerce') .
             ' <a class="button" href="'.esc_url($data['apply_url']).'">'.esc_html__('Resume application','avvance-for-woocommerce').'</a> ' .
             ' <button class="button" id="avvance-check-status" data-nonce="'.esc_attr($nonce).'">'.esc_html__('Check status','avvance-for-woocommerce').'</button>' .
             '</div>';
        echo '<script>document.addEventListener("click",function(e){if(e.target&&e.target.id==="avvance-check-status"){e.preventDefault();var n=e.target.dataset.nonce;fetch("'+admin_url('admin-ajax.php')+'?action=avvance_check_status&nonce="+n,{credentials:"same-origin"}).then(r=>r.json()).then(function(res){if(res.success&&res.data.redirect){window.location=res.data.redirect;}else if(res.success&&res.data.apply_url){window.location=res.data.apply_url;}else if(res.success&&res.data.notice){alert(res.data.notice);window.location="'+esc_js( wc_get_cart_url() )+'";}else if(res.data&&res.data.notice){alert(res.data.notice);} });}});</script>';
    }
    public static function ajax_check_status(){
        check_ajax_referer('avvance_cart','nonce');
        if ( ! function_exists('WC') || ! WC()->session ) wp_send_json_error(['notice'=>'Session missing']);
        $data = WC()->session->get('avvance_current');
        $order = isset($data['order_id']) ? wc_get_order( $data['order_id'] ) : false;
        if ( ! $order ) wp_send_json_error(['notice'=>'Order not found']);
        $gw = self::get_gateway();
        if ( ! $gw ) wp_send_json_error(['notice'=>'Gateway missing']);
        $ref = new ReflectionClass($gw); $m = $ref->getMethod('client'); $m->setAccessible(true); $client = $m->invoke($gw);
        $status = $client->notification_status( $order );
        if ( is_wp_error( $status ) ) wp_send_json_error([ 'notice' => $status->get_error_message() ]);
        $loan = strtoupper( $status['eventDetails']['loanStatus']['status'] ?? '' );
        if ( in_array( $loan, ['INVOICE_PAYMENT_TRANSACTION_AUTHORIZED','INVOICE_PAYMENT_TRANSACTION_SETTLED'], true ) ){
            if ( ! $order->is_paid() ) { $order->payment_complete(); }
            WC()->session->set('avvance_current', null);
            wp_send_json_success([ 'redirect' => $order->get_checkout_order_received_url() ]);
        }
        if ( in_array( $loan, ['APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT','SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT'], true ) ){
            WC()->session->set('avvance_current', null);
            wp_send_json_success([ 'notice' => __('You are not eligible for Avvance financing. Please choose another payment method.','avvance-for-woocommerce') ]);
        }
        $apply = $data['apply_url'] ?? '';
        if ( $apply ) wp_send_json_success([ 'apply_url' => $apply ]);
        wp_send_json_error([ 'notice' => 'Unable to resume' ]);
    }
}