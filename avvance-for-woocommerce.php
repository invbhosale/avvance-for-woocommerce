<?php
/*
 * Plugin Name: Avvance for WooCommerce
 * Plugin URI:  https://example.com/avvance-for-woocommerce
 * Description: Avvance payment method with redirect checkout, webhooks, refunds/voids, and cart resume fallback.
 * Version:     1.3.0
 * Requires at least: 6.6
 * Tested up to: 6.8.2
 * Requires PHP: 8.1
 * Author:      U.S. Bank Avvance
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: avvance-for-woocommerce
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AVVANCE_FOR_WOOCOMMERCE_PLUGIN_FILE', __FILE__ );

/**
 * CRUCIAL: declare compatibility so the editor warning goes away,
 * and mark HPOS (custom order tables) as supported.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Main plugin bootstrap.
 */
final class Avvance_For_WooCommerce {
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
	}

	public function init_plugin() {
		// i18n
		load_plugin_textdomain( 'avvance-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Only proceed if WooCommerce is active.
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p><strong>Avvance for WooCommerce</strong> requires WooCommerce to be active.</p></div>';
			} );
			return;
		}

		$this->load_files();
		$this->setup_hooks();
		$this->register_blocks_integration(); // <-- important: proper Blocks registration
	}

	protected function load_files() {
		require_once __DIR__ . '/includes/class-wc-gateway-avvance.php';
		require_once __DIR__ . '/includes/class-avvance-client.php';
		require_once __DIR__ . '/includes/class-avvance-settings.php';
		require_once __DIR__ . '/includes/class-avvance-webhooks.php';
		require_once __DIR__ . '/includes/class-avvance-cart-resume.php';
		require_once __DIR__ . '/includes/class-avvance-logger.php';
		require_once __DIR__ . '/includes/helpers.php';

		// Load REST controller regardless of Blocks so your JS can call it.
		if ( file_exists( __DIR__ . '/includes/class-avvance-rest-controller.php' ) ) {
			require_once __DIR__ . '/includes/class-avvance-rest-controller.php';
		}
	}

	protected function setup_hooks() {
		// Classic gateway registration.
		add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway' ] );
// Ensure icons are displayed on checkout if theme/plugins disabled them.
add_filter( 'woocommerce_gateway_icon', function( $icon, $gateway_id ) {
    if ( 'avvance' === $gateway_id && empty( $icon ) ) {
        $icon = WC()->payment_gateways()->payment_gateways()['avvance']->get_icon();
    }
    return $icon;
}, 10, 2 );

		// Webhooks + cart resume.
		add_action( 'plugins_loaded', [ 'Avvance_Webhooks', 'init' ] );
		add_action( 'init', [ 'Avvance_Cart_Resume', 'init' ] );

		// If you have a REST controller class, register its routes on rest_api_init.
		if ( class_exists( 'Avvance_REST_Controller' ) ) {
			add_action( 'rest_api_init', function () {
				$controller = new \Avvance_REST_Controller();
				if ( method_exists( $controller, 'register_routes' ) ) {
					$controller->register_routes();
				}
			} );
		}
	}

	/**
	 * Modern Blocks registration (mirrors Affirm).
	 * Uses AbstractPaymentMethodType and the registry action.
	 */
	protected function register_blocks_integration() {
		add_action( 'woocommerce_blocks_loaded', function () {
			// Correct base class for modern Woo Blocks:
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

				// Require your Blocks integration class file (your actual path/name).
				$blocks_file = __DIR__ . '/includes/class-wc-gateway-avvance-blocks.php';
				if ( file_exists( $blocks_file ) ) {
					require_once $blocks_file;

					// Register with the Blocks registry.
					add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
						// IMPORTANT: instantiate the correct class. Adjust namespace/class to match your file.
						$instance = new \Avvance\Blocks\Payment_Method(); // must extend AbstractPaymentMethodType
						$registry->register( $instance );
					} );

				} else {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[Avvance] Blocks file missing: includes/class-wc-gateway-avvance-blocks.php' );
					}
				}
			}
		} );
	}

	public function add_gateway( $methods ) {
		if ( class_exists( 'WC_Gateway_Avvance' ) ) {
			$methods[] = 'WC_Gateway_Avvance';
		}
		return $methods;
	}
}

new Avvance_For_WooCommerce();
