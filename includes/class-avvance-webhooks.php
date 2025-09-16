<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Avvance_Webhooks {
	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'avvance/v1', '/webhook', [
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => '__return_true', // TODO: lock down when you add signature verification
			] );
		} );

		// Front-end notice for alternate payment (cart/checkout)
		add_action( 'wp', [ __CLASS__, 'maybe_show_altpay_notice' ] );
	}

	public static function maybe_show_altpay_notice() {
		if ( isset( $_GET['avvance'] ) && $_GET['avvance'] === 'altpay' && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				__( 'You are not eligible for Avvance financing. Please choose another payment method. You will receive an email about your application decision.', 'avvance-for-woocommerce' ),
				'error'
			);
		}
	}

	/**
	 * Webhook endpoint: receives LOAN_STATUS_DETAILS and updates the order.
	 * Idempotent: only applies when status changed since last update.
	 */
	public static function handle( WP_REST_Request $request ) {
		$raw  = $request->get_body();
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_body', 'Invalid JSON', [ 'status' => 400 ] );
		}

		// (Optional) TODO: verify signature header(s) per your Webhook Auth Spec before proceeding.

		$event = $data['eventName'] ?? '';
		if ( $event !== 'LOAN_STATUS_DETAILS' ) {
			return new WP_REST_Response( [ 'ignored' => true ], 200 );
		}

		$details = $data['eventDetails'] ?? [];
		$psid    = sanitize_text_field( $details['partnerSessionId'] ?? '' );
		$guid    = sanitize_text_field( $details['applicationGUID']  ?? '' );
		$status  = strtoupper( $details['loanStatus']['status'] ?? '' );

		// Find the order: prefer GUID, fall back to partner session id.
		$order = null;
		if ( $guid ) {
			$orders = wc_get_orders( [
				'limit'      => 1,
				'meta_key'   => '_avvance_application_guid',
				'meta_value' => $guid,
				'return'     => 'objects',
			] );
			if ( $orders ) $order = $orders[0];
		}
		if ( ! $order && $psid ) {
			$orders = wc_get_orders( [
				'limit'      => 1,
				'meta_key'   => '_avvance_partner_session',
				'meta_value' => $psid,
				'return'     => 'objects',
			] );
			if ( $orders ) $order = $orders[0];
		}
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
		}

		// Idempotency: only act if status changed.
		$last_status = strtoupper( (string) $order->get_meta( '_avvance_last_status' ) );
		if ( $status === $last_status ) {
			return new WP_REST_Response( [ 'ok' => true, 'noop' => true ], 200 );
		}

		// Persist GUID if webhook brings it first.
		if ( $guid && ! $order->get_meta( '_avvance_application_guid' ) ) {
			$order->update_meta_data( '_avvance_application_guid', $guid );
		}

		// Simple dedupe guard (5s) to avoid racing multiple identical webhooks.
		$lock_key = 'avvance_wb_lock_' . $order->get_id();
		if ( get_transient( $lock_key ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'locked' => true ], 202 );
		}
		set_transient( $lock_key, 1, 5 );

		switch ( $status ) {
			case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
				if ( ! $order->is_paid() ) {
					$order->payment_complete(); // sets paid date & status
				}
				$order->add_order_note( 'Avvance: loan authorized (order confirmed).' );
				break;

			case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
				$order->add_order_note( 'Avvance: settlement notification (no status change).' );
				break;

			case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
			case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
				if ( ! $order->is_paid() ) {
					$order->update_status( 'cancelled', 'Avvance: alternate payment required' );
				}
				// Clear any session hints so shopper can pick another method
				if ( WC()->session ) {
					WC()->session->__unset( 'avvance_application_guid' );
					WC()->session->__unset( 'avvance_onboarding_url' );
				}
				break;

			case 'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION':
				$order->add_order_note( 'Avvance: customer must take action (e.g., credit freeze).' );
				break;

			default:
				$order->add_order_note( 'Avvance webhook received: ' . $status );
		}

		$order->update_meta_data( '_avvance_last_status', $status );
		$order->save();

		return new WP_REST_Response( [ 'ok' => true, 'status' => $status ], 200 );
	}
}

/**
 * Admin order action: “Avvance: Check status now”
 * Lets support manually sync the status using Notification Sync.
 */
add_filter( 'woocommerce_order_actions', function ( $actions ) {
	$actions['avvance_check_status'] = __( 'Avvance: Check status now', 'avvance-for-woocommerce' );
	return $actions;
} );

add_action( 'woocommerce_order_action_avvance_check_status', function( $order ) {
	try {
		$guid = $order->get_meta( '_avvance_application_guid' );
		if ( ! $guid ) { throw new Exception( 'Missing Avvance Application GUID' ); }

		$client = avvance_get_client();
		$resp   = $client->notification_status( $order ); // your client expects WC_Order

		// NEW: handle client/network/API errors
		if ( is_wp_error( $resp ) ) {
			$order->add_order_note( 'Avvance manual sync error (API): ' . $resp->get_error_message() );
			return;
		}

		$status = strtoupper( $resp['eventDetails']['loanStatus']['status'] ?? '' );

		switch ( $status ) {
			case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
				if ( ! $order->is_paid() ) { $order->payment_complete(); }
				$order->add_order_note( 'Avvance manual sync: Authorized.' );
				break;

			case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
			case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
				$order->update_status( 'cancelled', 'Avvance manual sync: Denied/System Error.' );
				break;

			case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
				$order->add_order_note( 'Avvance manual sync: Settled.' );
				break;

			default:
				$order->add_order_note( 'Avvance manual sync: Still pending (' . ( $status ?: 'UNKNOWN' ) . ').' );
		}

		$order->update_meta_data( '_avvance_last_status', $status ?: 'UNKNOWN' );
		$order->save();

	} catch ( Throwable $e ) {
		$order->add_order_note( 'Avvance manual sync error: ' . $e->getMessage() );
	}
} );



