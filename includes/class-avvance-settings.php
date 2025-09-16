
<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Avvance_Settings {
    public static function get_gateway() {
        if ( ! function_exists('WC') ) return null;
        $pgs = WC()->payment_gateways();
        return isset($pgs->payment_gateways()['avvance']) ? $pgs->payment_gateways()['avvance'] : null;
    }
    public static function get( $key, $default = '' ) {
        $gw = self::get_gateway();
        return $gw ? $gw->get_option( $key, $default ) : $default;
    }
}
