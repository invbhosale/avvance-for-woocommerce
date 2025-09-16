<?php
// ...your existing helpers.php code above...

if ( ! function_exists( 'avvance_get_client' ) ) {
	/**
	 * Returns an Avvance_Client configured from the gateway settings.
	 * Keep this in the GLOBAL namespace (no namespace; callable as avvance_get_client()).
	 *
	 * @return Avvance_Client
	 */
	function avvance_get_client() {
		$settings = [];

		// Prefer the live gateway instance (has parsed settings)
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gws = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gws['avvance'] ) && is_object( $gws['avvance'] ) ) {
				$settings = is_array( $gws['avvance']->settings ?? null )
					? $gws['avvance']->settings
					: [];
			}
		}

		// Fallback to saved options if gateway instance not available
		if ( empty( $settings ) ) {
			$settings = get_option( 'woocommerce_avvance_settings', [] );
		}

		// (Optional) Normalize expected keys here if your client expects specific names:
		// $settings = array_merge([
		//   'api_key'     => '',
		//   'merchant_id' => '',
		//   'base_url'    => '',
		//   'partner_id'  => '',
		//   'environment' => '',
		// ], $settings);

		return new Avvance_Client( $settings ); // your client’s __construct($settings)
	}
}
