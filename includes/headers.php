<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Return an Avvance_Client configured from the active gateway’s settings.
 * Works in both classic and Blocks flows.
 *
 * @return Avvance_Client
 */
function avvance_get_client() {
    // Prefer the live gateway instance (has parsed settings)
    $settings = [];
    if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
        $gws = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gws['avvance'] ) && is_object( $gws['avvance'] ) ) {
            // Most WC gateways store settings here
            $settings = is_array( $gws['avvance']->settings ?? null )
                ? $gws['avvance']->settings
                : [];
        }
    }
    // Fallback to options if gateway instance not available
    if ( empty( $settings ) ) {
        $settings = get_option( 'woocommerce_avvance_settings', [] );
    }

    // Optional: normalize keys your client expects
    // e.g. $settings = avvance_normalize_settings($settings);

    return new Avvance_Client( $settings ); // <-- your client expects exactly one arg
}
