
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Avvance_Client {
    protected $auth_base; protected $api_base; protected $client_key; protected $client_secret; protected $partner_id; protected $merchant_id;
    public function __construct( $cfg ) {
        $this->auth_base     = untrailingslashit( $cfg['auth_base'] ?? '' );
        $this->api_base      = untrailingslashit( $cfg['api_base'] ?? '' );
        $this->client_key    = $cfg['client_key'] ?? '';
        $this->client_secret = $cfg['client_secret'] ?? '';
        $this->partner_id    = $cfg['partner_id'] ?? '';
        $this->merchant_id   = $cfg['merchant_id'] ?? '';
    }
    protected function token_key(){ return 'avvance_token_' . md5( $this->auth_base . '|' . $this->client_key ); }
    protected function get_token(){
        $cached = get_transient( $this->token_key() );
        if ( $cached ) return $cached;
        $auth = base64_encode( $this->client_key . ':' . $this->client_secret );
        $res  = wp_remote_post( $this->auth_base . '/auth/oauth2/v1/token', [
            'headers' => [ 'Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => [ 'grant_type' => 'client_credentials' ],
            'timeout' => 20,
        ] );
        if ( is_wp_error( $res ) ) return $res;
        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( $code >= 200 && $code < 300 && ! empty( $body['accessToken'] ) ) {
            $ttl = max( 60, intval( $body['expiresIn'] ?? 600 ) - 60 );
            set_transient( $this->token_key(), $body['accessToken'], $ttl );
            return $body['accessToken'];
        }
        return new WP_Error( 'avvance_auth', 'Token request failed', [ 'code' => $code, 'body' => $body ] );
    }
    protected function headers( $extra = [] ){
        $token = $this->get_token();
        if ( is_wp_error( $token ) ) return $token;
        $headers = [ 'Authorization' => 'Bearer ' . $token, 'partner-ID' => $this->partner_id, 'Correlation-ID' => wp_generate_uuid4() ];
        return array_merge( $headers, $extra );
    }
    protected function request( $method, $path, $body = null, $extra_headers = [] ){
        $headers = $this->headers( $extra_headers );
        if ( is_wp_error( $headers ) ) return $headers;
        $args = [ 'method' => $method, 'timeout' => 25, 'headers' => $headers ];
        if ( $body !== null ) { $args['body'] = wp_json_encode( $body ); $args['headers']['Content-Type'] = 'application/json'; }
        $url = $this->api_base . $path;
        $res = wp_remote_request( $url, $args );
        if ( is_wp_error( $res ) ) return $res;
        $code = wp_remote_retrieve_response_code( $res );
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( $code >= 200 && $code < 300 ) return $data;
        return new WP_Error( 'avvance_http_error', 'HTTP ' . $code, [ 'body' => $data ] );
    }

    public function financing_initiate( WC_Order $order ){
        $partnerSessionId = wp_generate_uuid4();
        $order->update_meta_data( '_avvance_partner_session', $partnerSessionId );
        $order->update_meta_data( '_avvance_merchant_id', $this->merchant_id );
        $order->save();
        $payload = [
            'partnerSessionId'      => $partnerSessionId,
            'merchantId'            => $this->merchant_id,
            'invoiceAmount'         => (float) $order->get_total(),
            'invoiceId'             => (string) $order->get_order_number(),
            'merchantTransactionId' => (string) $order->get_order_key(),
            'purchaseDescription'   => sprintf( 'Woo order #%s', $order->get_id() ),
            'consumer'              => [
                'email'       => $order->get_billing_email(),
                'firstName'   => $order->get_billing_first_name(),
                'lastName'    => $order->get_billing_last_name(),
                'mobilePhone' => preg_replace('/\D+/', '', $order->get_billing_phone() ),
                'billingAddress'  => [
                    'street1' => $order->get_billing_address_1(),
                    'street2' => $order->get_billing_address_2(),
                    'city'    => $order->get_billing_city(),
                    'state'   => $order->get_billing_state(),
                    'postalCode' => $order->get_billing_postcode(),
                    'countryCode'=> $order->get_billing_country(),
                ],
                'shippingAddress' => [
                    'street1' => $order->get_shipping_address_1(),
                    'street2' => $order->get_shipping_address_2(),
                    'city'    => $order->get_shipping_city(),
                    'state'   => $order->get_shipping_state(),
                    'postalCode' => $order->get_shipping_postcode(),
                    'countryCode'=> $order->get_shipping_country(),
                ],
            ],
            'partnerReturnErrorUrl' => wc_get_cart_url() . '?avvance=altpay',
            'metadata'              => [ [ 'key' => 'wc_order_id', 'value' => (string) $order->get_id() ] ],
        ];
        return $this->request( 'POST', '/poslp/services/avvance-loan/v1/create', $payload );
    }

    public function notification_status( WC_Order $order ){
        $guid = $order->get_meta('_avvance_application_guid');
        if ( ! $guid ) return new WP_Error('avvance_missing_guid','Missing application GUID');
        $headers = [ 'merchantId' => $this->merchant_id ];
        $path    = '/poslp/services/avvance-loan/v1/notification-status?notificationId=' . rawurlencode( $guid );
        return $this->request( 'GET', $path, null, $headers );
    }

    public function void( WC_Order $order ){
        $payload = [ 'merchantId' => $this->merchant_id, 'partnerSessionId' => $order->get_meta('_avvance_partner_session') ];
        return $this->request( 'POST', '/poslp/services/avvance-loan/v1/void', $payload );
    }

    public function refund( WC_Order $order, $amount, $reason = '' ){
        $payload = [ 'merchantId' => $this->merchant_id, 'partnerSessionId' => $order->get_meta('_avvance_partner_session'), 'refundAmount' => (float) $amount ];
        return $this->request( 'POST', '/poslp/services/avvance-loan/v1/refund', $payload );
    }
}
