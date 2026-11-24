<?php
/**
 * Scriptable fiscal adapter "x5_test" of zzzzz_x5_fiscal_reconcile.php and its helpers (x5_*), shared
 * with the box office and fiscal modules that use them (zzzzz_x5_pos_devices.php, zzzzz_y8_small_bugs.php,
 * zzzzzz_a425_fiscal.php), so these also run alone (WPCAP_TEST_ONLY). Loading the file registers the
 * adapter (filter wpcap_fiscal_adapters) and resets $GLOBALS['x5_fiscal'] once (require_once); in a
 * full run that happens where zzzzz_x5_fiscal_reconcile.php used to do it.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Fiscalization\AdapterInterface;
use PassCaptain\Modules\Fiscalization as Fiscal;

/** Scriptable test adapter: records every call, answers from $GLOBALS['x5_fiscal']. */
final class X5_Fiscal_Adapter implements AdapterInterface {
	public function id() { return 'x5_test'; }
	public function interface_version() { return AdapterInterface::VERSION; }
	public function countries() { return array( 'AT', 'DE' ); }
	public function capabilities() { return array( 'receipt_submit', 'operation_status', 'receipt_qr_payload' ); }
	public function health_check( array $seller, array $configuration ) { return array( 'ok' => true ); }
	public function query_status( array $document, array $seller, array $configuration ) {
		$GLOBALS['x5_fiscal']['calls'][] = array( 'query', $document['idempotency_key'], (int) $seller['seller_id'] );
		return isset( $GLOBALS['x5_fiscal']['refs'][ $document['idempotency_key'] ] ) ? array( 'status' => 'submitted', 'reference' => $GLOBALS['x5_fiscal']['refs'][ $document['idempotency_key'] ], 'receipt' => array( 'qr_payload' => 'QR-' . $document['idempotency_key'] ) ) : array( 'status' => 'not_found' );
	}
	public function submit( array $document, array $seller, array $configuration ) {
		$GLOBALS['x5_fiscal']['calls'][] = array( 'submit', $document['idempotency_key'], (int) $seller['seller_id'] );
		$GLOBALS['x5_fiscal']['docs'][ $document['idempotency_key'] ] = $document;
		$fail = $GLOBALS['x5_fiscal']['fail'][ (int) $seller['seller_id'] ] ?? false;
		if ( $fail ) { return new WP_Error( 'provider_transport_error', 'down' ); }
		$ref = 'REF-' . substr( $document['idempotency_key'], 0, 8 );
		$GLOBALS['x5_fiscal']['refs'][ $document['idempotency_key'] ] = $ref; // Provider is idempotent per key.
		return array( 'reference' => $ref, 'receipt' => array( 'qr_payload' => 'QR-' . $document['idempotency_key'], 'fields' => array( 'Belegnummer' => $ref ) ) );
	}
}
$GLOBALS['x5_fiscal'] = array( 'calls' => array(), 'docs' => array(), 'refs' => array(), 'fail' => array() );
add_filter( 'wpcap_fiscal_adapters', static function ( $adapters ) { $adapters = (array) $adapters; $adapters['x5_test'] = new X5_Fiscal_Adapter(); return $adapters; }, 20, 1 );
function x5_calls( $kind = null ) { return array_values( array_filter( $GLOBALS['x5_fiscal']['calls'], static fn( $c ) => null === $kind || $c[0] === $kind ) ); }
function x5_rest( $method, array $params, array $body = array() ) {
	$r = new WP_REST_Request( $method );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	$r->set_body( json_encode( $body ) );
	return $r;
}
function x5_seller( $name, $country = 'AT' ) {
	$r = Fiscal::rest_save_seller( x5_rest( 'POST', array(), array( 'legal_name' => $name, 'country_code' => $country, 'tax_number' => 'ATU' . random_int( 10000000, 99999999 ) ) ) );
	return is_wp_error( $r ) ? 0 : (int) $r->get_data()['seller_id'];
}
function x5_profile( $seller_id, $channel, $status = 'active', $location = 0, $register = '' ) {
	return Fiscal::save_profile( $seller_id, array( 'channel' => $channel, 'environment' => 'production', 'status' => $status, 'ruleset_key' => 'at_rksv', 'ruleset_version' => '2026-1', 'legal_reference' => 'Prüfung X5', 'review_attested' => 1, 'location_id' => $location, 'register_id' => $register ) );
}
function x5_docs( $order_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d ORDER BY seller_id", $order_id ) );
}
