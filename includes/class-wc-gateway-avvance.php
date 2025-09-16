<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_Avvance extends WC_Payment_Gateway {
    public function __construct() {
        $this->id                 = 'avvance';
        $this->method_title       = __( 'U.S. Bank Avvance', 'avvance-for-woocommerce' );
        $this->method_description = __( 'U.S. Bank Avvance lets your customers split purchases into affordable installment loans with clear terms and no hidden fees.', 'avvance-for-woocommerce' );
        $this->title              = __( 'U.S. Bank Avvance', 'avvance-for-woocommerce' );
        $this->description        = __(
            'Choose U.S. Bank Avvance to apply instantly for financing. If approved, complete your purchase with flexible installment options backed by U.S. Bank.',
            'avvance-for-woocommerce'
        );
        $this->has_fields         = false;

        // Point $this->icon to your bundled asset (used by some contexts).
        $this->icon = plugins_url( 'assets/img/avvance-icon.svg', defined('AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE') ? AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE : __FILE__ );

        $this->init_form_fields();
        $this->init_settings();

        add_filter( 'woocommerce_order_actions', [ $this, 'add_sync_order_action' ] );
        add_action( 'woocommerce_order_action_avvance_sync_status', [ $this, 'action_sync_status' ] );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
        add_action( 'woocommerce_order_actions', [ $this, 'add_order_actions' ] );
        add_action( 'woocommerce_order_action_avvance_cancel_payment', [ $this, 'action_cancel_payment' ] );
    }

    /**
     * Icon for admin & checkout:
     * - In wp-admin (Payments list) Woo expects a **URL**; if not a URL it falls back to the default icon.
     * - On the classic checkout template, Woo expects **HTML** (an <img> tag).
     */
    public function get_icon() {
        // Build a plugin-relative URL to the icon file.
        $icon_url = plugins_url( 'assets/img/avvance-icon.svg', defined('AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE') ? AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE : __FILE__ );
        // If the SVG doesn’t exist, fall back to PNG.
        $icon_path_svg = plugin_dir_path( defined('AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE') ? AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE : __FILE__ ) . 'assets/img/avvance-icon.svg';
        if ( ! file_exists( $icon_path_svg ) ) {
            $icon_url = plugins_url( 'assets/img/avvance-icon.png', defined('AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE') ? AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE : __FILE__ );
        }

        // ADMIN (Payments list, wc-admin): return a **URL string**.
        if ( is_admin() ) {
            return esc_url( $icon_url );
        }

        // FRONTEND (classic checkout): return an <img>.
        $html = '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr__( 'U.S. Bank Avvance', 'avvance-for-woocommerce' ) . '" style="height:24px;width:auto;margin-left:6px;" />';
        // Allow site owners to filter/replace on the frontend if they wish.
        return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'         => [ 'title' => __( 'Enable/Disable', 'avvance-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Enable Avvance', 'avvance-for-woocommerce' ), 'default' => 'no' ],
            'environment'     => [ 'title' => __( 'Environment', 'avvance-for-woocommerce' ), 'type' => 'select', 'description' => __( 'Choose which environment to use.', 'avvance-for-woocommerce' ), 'default' => 'uat', 'options' => [ 'uat' => 'UAT', 'prod' => 'Production' ] ],
            'uat_auth_base'   => [ 'title' => __( 'UAT Auth Base URL','avvance-for-woocommerce'),  'type'=>'text', 'default'=> '' ],
            'uat_api_base'    => [ 'title' => __( 'UAT API Base URL','avvance-for-woocommerce'),   'type'=>'text', 'default'=> '' ],
            'prod_auth_base'  => [ 'title' => __( 'Prod Auth Base URL','avvance-for-woocommerce'),  'type'=>'text', 'default'=> '' ],
            'prod_api_base'   => [ 'title' => __( 'Prod API Base URL','avvance-for-woocommerce'),   'type'=>'text', 'default'=> '' ],
            'client_key'      => [ 'title' => __( 'OAuth Client Key','avvance-for-woocommerce'),    'type'=>'text', 'default'=> '' ],
            'client_secret'   => [ 'title' => __( 'OAuth Client Secret','avvance-for-woocommerce'), 'type'=>'password', 'default'=> '' ],
            'partner_id'      => [ 'title' => __( 'Partner ID','avvance-for-woocommerce'),          'type'=>'text', 'default'=> '' ],
            'merchant_id'     => [ 'title' => __( 'Merchant ID (MID)','avvance-for-woocommerce'),   'type'=>'text', 'default'=> '' ],
        ];
    }

    protected function client() {
        $env = $this->get_option('environment','uat');
        return new Avvance_Client([
            'auth_base'     => $this->get_option($env . '_auth_base'),
            'api_base'      => $this->get_option($env . '_api_base'),
            'client_key'    => $this->get_option('client_key'),
            'client_secret' => $this->get_option('client_secret'),
            'partner_id'    => $this->get_option('partner_id'),
            'merchant_id'   => $this->get_option('merchant_id'),
        ]);
    }

    public function process_payment( $order_id ) {
        $order  = wc_get_order( $order_id );
        $client = $this->client();
        $result = $client->financing_initiate( $order );

        if ( is_wp_error( $result ) || empty( $result['consumerOnboardingURL'] ) ) {
            wc_add_notice( __( 'Avvance is temporarily unavailable. Please try again or choose another method.', 'avvance-for-woocommerce' ), 'error' );
            return [ 'result' => 'failure' ];
        }

        if ( ! empty( $result['applicationGUID'] ) ) {
            $order->update_meta_data( '_avvance_application_guid', sanitize_text_field( $result['applicationGUID'] ) );
        }
        if ( ! empty( $result['partnerSessionID'] ) ) {
            $order->update_meta_data( '_avvance_partner_session', sanitize_text_field( $result['partnerSessionID'] ) );
        }
        $order->save();

        if ( function_exists('WC') && WC()->session ) {
            WC()->session->set( 'avvance_current', [
                'order_id'         => $order_id,
                'applicationGUID'  => $result['applicationGUID'] ?? '',
                'partnerSessionID' => $result['partnerSessionID'] ?? '',
                'apply_url'        => $result['consumerOnboardingURL'] ?? '',
                'ts'               => time(),
            ] );
        }

        $order->add_order_note( sprintf( 'Avvance init: %s', esc_url_raw( $result['consumerOnboardingURL'] ) ) );
        return [ 'result' => 'success', 'redirect' => $result['consumerOnboardingURL'] ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order  = wc_get_order( $order_id );
        $client = $this->client();
        $resp   = $client->refund( $order, $amount, $reason );
        if ( is_wp_error( $resp ) ) return $resp;
        $order->add_order_note( sprintf( 'Avvance refund processed: %s %s', wc_price( $amount ), $reason ) );
        return true;
    }

    public function add_order_actions( $actions ) {
        $actions['avvance_cancel_payment'] = __( 'Cancel via Avvance (Void/Refund)', 'avvance-for-woocommerce' );
        return $actions;
    }

    public function action_cancel_payment( $order ) {
        $client = $this->client();
        $status = $client->notification_status( $order );
        if ( is_wp_error( $status ) ) { $order->add_order_note( 'Avvance cancel check failed: ' . $status->get_error_message() ); return; }
        $loan_status = strtoupper( $status['eventDetails']['loanStatus']['status'] ?? '' );
        if ( $loan_status === 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED' ) {
            $resp = $client->void( $order );
            if ( is_wp_error( $resp ) ) { $order->add_order_note( 'Avvance void failed: ' . $resp->get_error_message() ); return; }
            $order->update_status( 'cancelled', 'Avvance: transaction voided' );
        } elseif ( $loan_status === 'INVOICE_PAYMENT_TRANSACTION_SETTLED' ) {
            $resp = $client->refund( $order, $order->get_total(), 'Full cancel' );
            if ( is_wp_error( $resp ) ) { $order->add_order_note( 'Avvance refund failed: ' . $resp->get_error_message() ); return; }
            $order->add_order_note( 'Avvance: full refund issued' );
            $order->update_status( 'refunded' );
        } else {
            $order->add_order_note( 'Avvance: cancel skipped; status = ' . $loan_status );
        }
    }

    public function thankyou_page( $order_id ) {
        echo '<p>' . esc_html__( 'Thank you for choosing Avvance. Your application status will update automatically.', 'avvance-for-woocommerce' ) . '</p>';
    }

    public function add_sync_order_action( $actions ) {
        $order = wc_get_order();
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return $actions;
        }
        $actions['avvance_sync_status'] = __( 'Sync Avvance Status', 'avvance-for-woocommerce' );
        return $actions;
    }

    public function action_sync_status( $order ) {
        $guid = $order->get_meta('_avvance_application_guid');
        if ( ! $guid ) { $order->add_order_note( 'Avvance sync failed: Missing application GUID.' ); return; }

        $client = $this->client();
        $status_response = $client->notification_status( $order );
        if ( is_wp_error( $status_response ) ) { $order->add_order_note( 'Avvance status sync failed: ' . $status_response->get_error_message() ); return; }

        $loan_status = strtoupper( $status_response['eventDetails']['loanStatus']['status'] ?? '' );
        switch ( $loan_status ) {
            case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
            case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
                if ( ! $order->is_paid() ) {
                    $order->payment_complete();
                    $order->add_order_note( sprintf( 'Avvance status synced: Payment complete. Loan Status: %s', $loan_status ) );
                } else {
                    $order->add_order_note( sprintf( 'Avvance status synced: Order already paid. Loan Status: %s', $loan_status ) );
                }
                break;
            case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
            case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
                $order->update_status( 'cancelled', sprintf( 'Avvance status synced: Alternate payment required. Loan Status: %s', $loan_status ) );
                break;
            case 'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION':
                $order->add_order_note( sprintf( 'Avvance status synced: Customer must take action. Loan Status: %s', $loan_status ) );
                break;
            default:
                $order->add_order_note( sprintf( 'Avvance status synced: Unhandled loan status - %s', $loan_status ) );
        }
        $order->save();
    }
}
