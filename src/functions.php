<?php
/**
 * Global convenience functions of the public add-on API (thin wrappers around PassCaptain\Api\*).
 * Loaded by pass-captain.php; guarded so an add-on may ship identical fallbacks.
 *
 * @package PassCaptain
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'wpcap_get_invoice' ) ) {
	/**
	 * Invoice document as a stable array (contract version 1), see PassCaptain\Api\Invoices::get().
	 *
	 * @param int    $id   Document id (invoice_id, credit_note_id or settlement_id).
	 * @param string $type invoice|correction|settlement.
	 * @return array<string,mixed>|null
	 */
	function wpcap_get_invoice( $id, $type = 'invoice' ) {
		return PassCaptain\Api\Invoices::get( $id, $type );
	}
}

if ( ! function_exists( 'wpcap_confirm_pos_payment' ) ) {
	/**
	 * Confirms the card-terminal payment of a box office sale (e.g. from a verified provider webhook),
	 * see PassCaptain\BoxOffice\Service::confirm_device_payment(). Idempotent; books the sale once.
	 *
	 * @param int|string $order_id  Order ID or the sale_key passed to DeviceGatewayInterface::start().
	 * @param string     $reference Payment reference of the provider.
	 * @param array      $payment   Optional (contract 1.1): amount_minor and currency actually charged;
	 *                              a difference to the order is never booked (amount_mismatch).
	 * @return true|WP_Error
	 */
	function wpcap_confirm_pos_payment( $order_id, $reference, $payment = array() ) {
		return PassCaptain\BoxOffice\Service::confirm_device_payment( (string) $order_id, (string) $reference, (array) $payment );
	}
}

if ( ! function_exists( 'wpcap_api_request_can' ) ) {
	/**
	 * Checks the developer API key of a REST request for a scope (and event), see PassCaptain\Api\Auth::request_can().
	 *
	 * @param WP_REST_Request $request  REST request with header X-WPCAP-Key.
	 * @param string          $scope    Scope, e.g. "orders.write".
	 * @param int|null        $event_id Event; null = parameter event_id of the request.
	 * @return true|WP_Error
	 */
	function wpcap_api_request_can( WP_REST_Request $request, $scope, $event_id = null ) {
		return PassCaptain\Api\Auth::request_can( $request, $scope, $event_id );
	}
}
