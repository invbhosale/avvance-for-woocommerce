<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST endpoints used by the Blocks checkout flow:
 * - POST /avvance/v1/initiate : create a Woo order + Avvance application
 * - GET  /avvance/v1/status   : poll Avvance Notification Sync and update order
 */
class Avvance_REST_Controller {

	public function register_routes() {
		register_rest_route( 'avvance/v1', '/initiate', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'initiate' ],
		] );

		register_rest_route( 'avvance/v1', '/status', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'guid' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'status' ],
		] );
	}

	/**
	 * Create pending Woo order from current cart, call Avvance to create application,
	 * save GUID (and token/partnerSession if present), and return onboarding URL.
	 */
	public function initiate( WP_REST_Request $r ) {
		try {
			// Basic guard: must have a cart with items.
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return new WP_REST_Response( [ 'error' => 'Cart is empty.' ], 400 );
			}

			// 1) Create a pending order mirroring the cart.
			$order = wc_create_order();
			foreach ( WC()->cart->get_cart() as $key => $values ) {
				/** @var WC_Product $product */
				$product  = $values['data'];
				$quantity = (int) $values['quantity'];
				$order->add_product( $product, $quantity );
			}
			$order->set_payment_method( 'avvance' );
			$order->calculate_totals();

			// 2) Call Avvance
			if ( ! function_exists( 'avvance_get_client' ) ) {
				require_once __DIR__ . '/helpers.php';
			}
			$client  = avvance_get_client(); // <-- constructor args handled inside
			$payload = method_exists( $client, 'build_payload_from_order' )
				? $client->build_payload_from_order( $order )
				: []; // if you don't use this helper, build in create_application

			$resp = $client->create_application( $payload );

			$guid   = isset( $resp['applicationGuid'] ) ? (string) $resp['applicationGuid'] : '';
			$url    = isset( $resp['onboardingUrl'] )   ? esc_url_raw( $resp['onboardingUrl'] ) : '';
			$token  = isset( $resp['token'] )           ? (string) $resp['token'] : '';
			$psid   = isset( $resp['partnerSessionId'] )? (string) $resp['partnerSessionId'] : '';

			if ( $guid === '' || $url === '' ) {
				throw new Exception( 'Missing GUID or onboarding URL from Avvance.' );
			}

			// 3) Persist identifiers on the order
			$order->update_meta_data( '_avvance_application_guid', $guid );
			if ( $token !== '' ) { $order->update_meta_data( '_avvance_notification_token', $token ); }
			if ( $psid  !== '' ) { $order->update_meta_data( '_avvance_partner_session', $psid ); }
			$order->save();

			// 4) Optional session hints for cart fallback UX
			if ( WC()->session ) {
				WC()->session->set( 'avvance_application_guid', $guid );
				WC()->session->set( 'avvance_onboarding_url', $url );
			}

			return new WP_REST_Response( [
				'guid'             => $guid,
				'onboardingUrl'    => $url,
				'orderId'          => $order->get_id(),
				'orderReceivedUrl' => $order->get_checkout_order_received_url(),
			], 200 );

		} catch ( Throwable $e ) {
			return new WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}

	/**
	 * Poll Avvance Notification Sync and update the order.
	 * Returns:
	 *  - { authorized:true, orderReceivedUrl } when Authorized
	 *  - { altPayment:true } when denied/system error
	 *  - { pending:true } otherwise
	 */
	public function status( WP_REST_Request $r ) {
		$guid = $r->get_param( 'guid' );

		try {
			$orders = wc_get_orders( [
				'limit'      => 1,
				'meta_key'   => '_avvance_application_guid',
				'meta_value' => $guid,
				'return'     => 'objects',
			] );
			$order = $orders ? $orders[0] : null;
			if ( ! $order ) {
				// If the order isn't found, tell the client to offer alt payment.
				return new WP_REST_Response( [ 'altPayment' => true ], 200 );
			}

			// Notification Sync
if ( ! function_exists( 'avvance_get_client' ) ) {
	require_once __DIR__ . '/helpers.php';
}
$client = avvance_get_client();
$resp   = $client->notification_status( $order ); // your client expects WC_Order

// NEW: gracefully handle API errors during polling
if ( is_wp_error( $resp ) ) {
	$error_msg = $resp->get_error_message();
	$order->add_order_note( 'Avvance sync API error: ' . $error_msg );
	// Keep the client polling (don't hard fail), but return the message for logging/UI if needed
	return new WP_REST_Response( [ 'pending' => true, 'error' => $error_msg ], 200 );
}

$status = strtoupper( $resp['eventDetails']['loanStatus']['status'] ?? '' );

switch ( $status ) {
	case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
		if ( ! $order->is_paid() ) {
			$order->payment_complete();
		}
		$order->update_meta_data( '_avvance_last_status', $status );
		$order->save();

		return new WP_REST_Response( [
			'authorized'       => true,
			'orderReceivedUrl' => $order->get_checkout_order_received_url(),
		], 200 );

	case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
	case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
		if ( ! $order->is_paid() ) {
			$order->update_status( 'cancelled', 'Avvance sync: Denied/System Error.' );
		}
		$order->update_meta_data( '_avvance_last_status', $status );
		$order->save();

		if ( WC()->session ) {
			WC()->session->__unset( 'avvance_application_guid' );
			WC()->session->__unset( 'avvance_onboarding_url' );
		}

		return new WP_REST_Response( [ 'altPayment' => true ], 200 );

	case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
		$order->add_order_note( 'Avvance sync: Settled (merchant settlement).' );
		$order->update_meta_data( '_avvance_last_status', $status );
		$order->save();
		return new WP_REST_Response( [ 'pending' => true ], 200 );

	default:
		$order->update_meta_data( '_avvance_last_status', $status ?: 'PENDING' );
		$order->save();
		return new WP_REST_Response( [ 'pending' => true ], 200 );
}

		} catch ( Throwable $e ) {
			return new WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}
}
