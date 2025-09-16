<?php
/**
 * Avvance – Checkout Blocks integration.
 */
namespace Avvance\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Payment_Method extends AbstractPaymentMethodType {
	/** MUST equal WC_Gateway_Avvance::$id */
	protected $name = 'avvance';

	/** @var \WC_Payment_Gateway|null */
	protected $gateway = null;

	public function initialize() {
		// Mirror classic gateway availability & settings.
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gws = WC()->payment_gateways()->payment_gateways();
			$this->gateway = isset( $gws[ $this->name ] ) ? $gws[ $this->name ] : null;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Avvance Blocks] initialize; gateway=' . ( $this->gateway ? 'yes' : 'no' ) );
		}
	}

	public function is_active() : bool {
		if ( ! $this->gateway ) { return false; }
		$enabled     = ( 'yes' === $this->gateway->enabled );
		$currency_ok = ( function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() === 'USD' );
		$active      = $enabled && $currency_ok;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Avvance Blocks] is_active=' . ( $active ? 'true' : 'false' ) . " (enabled={$this->gateway->enabled}, currency=" . get_woocommerce_currency() . ')' );
		}
		return $active;
	}

	public function get_payment_method_script_handles() : array {
		// Build output locations based on your tree:
		//   includes/Blocks/js/index.js
		//   includes/Blocks/js/index.asset.php
		$asset_path = __DIR__ . '/Blocks/js/index.asset.php'; // filesystem path
		$script_url = plugins_url( 'includes/Blocks/js/index.js', dirname( __FILE__ ) ); // public URL

		$deps = [ 'wc-blocks-registry' ]; // ensure registry is present even if asset omits it
		$ver  = '1.0.0';

		if ( file_exists( $asset_path ) ) {
			$asset = require $asset_path; // returns ['dependencies'=>[], 'version'=>'...']
			$deps  = array_unique( array_merge( $asset['dependencies'] ?? [], $deps ) );
			$ver   = $asset['version'] ?? $ver;
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Avvance Blocks] Missing asset file: ' . $asset_path );
			}
		}

		wp_register_script( 'avvance-blocks', $script_url, $deps, $ver, true );

		wp_localize_script( 'avvance-blocks', 'avvanceBlocks', [
    'initiateUrl' => rest_url( 'avvance/v1/initiate' ),
    'statusUrl'   => rest_url( 'avvance/v1/status' ), // <— added for polling
    'label'       => __( 'U.S. Bank Avvance', 'avvance-for-woocommerce' ),
    'button'      => __( 'Pay with Avvance', 'avvance-for-woocommerce' ),
    'disclosure'  => __( "To view payment options that you may qualify for, select ‘Pay with U.S. Bank Avvance’ to leave this site and enter the U.S. Bank Avvance loan application. Qualification for payment options are subject to application approval.", 'avvance-for-woocommerce' ),
] );


		return [ 'avvance-blocks' ];
	}

	public function get_payment_method_data() : array {
		$title = $this->gateway ? $this->gateway->get_title() : __( 'U.S. Bank Avvance', 'avvance-for-woocommerce' );
		$desc  = $this->gateway ? $this->gateway->get_description() : '';
		return [
			'title'       => $title,
			'description' => $desc,
		];
	}
}
