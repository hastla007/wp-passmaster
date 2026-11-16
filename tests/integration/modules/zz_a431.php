<?php
/**
 * A431: the Mollie webhook counts the per-IP limit before it asks the database, and the lookup of a
 * payment id never scans wpcap_orders (primary key of wpcap_mollie_payments, else index provider_ref).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Installer;
use PassCaptain\Mollie\MollieGateway;

global $wpdb;
$p = $wpdb->prefix;

echo "A431: Mollie webhook lookup and rate limit\n";
$a431_cols = array_map( static fn( $r ) => $r->Column_name . ( null !== $r->Sub_part ? '(' . $r->Sub_part . ')' : '' ), (array) $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_orders WHERE Key_name='provider_ref'" ) );
check( 'schema 3.10.18: orders KEY provider_ref (payment_provider, provider_reference(64))', version_compare( Installer::DB_VERSION, '3.10.18', '>=' ) && array( 'payment_provider', 'provider_reference(64)' ) === $a431_cols );

// A few hundred orders of other providers, one Mollie order from before wpcap_mollie_payments existed.
$a431_now = gmdate( 'Y-m-d H:i:s' );
$a431_values = array();
for ( $i = 0; $i < 400; $i++ ) {
	$a431_values[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', md5( 'a431-' . $i ), hash( 'sha256', 'a431-' . $i ), "a431-$i@example.com", 'complete', 'paid', 'EUR', '{}', 0 === $i % 2 ? 'stripe' : 'paypal', 'pi_a431_' . $i, $a431_now, $a431_now );
}
$wpdb->query( "INSERT INTO {$p}wpcap_orders (public_id,idempotency_key,customer_email,status,payment_status,currency,price_snapshot,payment_provider,provider_reference,created_at,updated_at) VALUES " . implode( ',', $a431_values ) );
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => md5( 'a431-legacy' ), 'idempotency_key' => hash( 'sha256', 'a431-legacy' ), 'customer_email' => 'a431-legacy@example.com', 'status' => 'pending_payment', 'payment_status' => 'pending', 'currency' => 'EUR', 'price_snapshot' => '{}', 'payment_provider' => 'mollie', 'provider_reference' => 'tr_A431Legacy', 'created_at' => $a431_now, 'updated_at' => $a431_now ) );
$wpdb->query( "ANALYZE TABLE {$p}wpcap_orders" );

$a431_log = array();
$a431_spy = static function ( $sql ) use ( &$a431_log ) { $a431_log[] = $sql; return $sql; };
$a431_hook = static function ( $id ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/mollie/webhook' );
	$req->set_body( 'id=' . $id );
	return ( new MollieGateway() )->verify_webhook( $req );
};
$a431_db_reads = static fn( array $log ) => array_values( array_filter( $log, static fn( $sql ) => (bool) preg_match( '/^\s*SELECT\b.*\b' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . 'wpcap_(orders|mollie_payments)\b/is', $sql ) ) );

add_filter( 'query', $a431_spy );
$a431_unknown = $a431_hook( 'tr_A431Unknown9' );
remove_filter( 'query', $a431_spy );
$a431_plans = array();
foreach ( $a431_db_reads( $a431_log ) as $sql ) {
	foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql ) as $row ) { $a431_plans[] = array( 'table' => (string) $row->table, 'type' => (string) $row->type, 'key' => (string) $row->key ); }
}
if ( array_filter( $a431_plans, static fn( $pl ) => in_array( $pl['type'], array( 'ALL', 'index' ), true ) ) ) { fwrite( STDERR, 'A431 plans: ' . json_encode( $a431_plans ) . "\n" ); }
check( 'unknown id: acknowledged as ignored', ! is_wp_error( $a431_unknown ) && 'ignored' === $a431_unknown['type'] );
check( 'unknown id: lookup by primary key and provider_ref, never a scan of the orders',
	2 === count( $a431_plans )
	&& ! array_filter( $a431_plans, static fn( $pl ) => in_array( $pl['type'], array( 'ALL', 'index' ), true ) )
	&& in_array( 'provider_ref', array_column( $a431_plans, 'key' ), true ) );
check( 'legacy Mollie order (no wpcap_mollie_payments row) is still known', MollieGateway::known_payment( 'tr_A431Legacy' ) && ! MollieGateway::known_payment( 'pi_a431_2' ) );

// Per-IP limit before the database: a client over its limit gets 429 without any lookup.
$GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_ip_limit'] = 0;
$a431_log = array();
add_filter( 'query', $a431_spy );
$a431_throttled = $a431_hook( 'tr_A431Unknown8' );
remove_filter( 'query', $a431_spy );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_ip_limit'] );
check( 'over the per-IP limit: 429 for unknown ids too, no lookup in orders/mollie_payments', is_wp_error( $a431_throttled ) && 429 === $a431_throttled->get_error_data()['status'] && array() === $a431_db_reads( $a431_log ) );

// The global limit only counts known ids: random ids cannot block the real webhooks.
$GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_global_limit'] = 0;
$a431_random = $a431_hook( 'tr_A431Unknown7' );
$a431_known = $a431_hook( 'tr_A431Legacy' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_mollie_webhook_global_limit'] );
check( 'global limit: unknown id still acknowledged, known id throttled (429, no API call)', ! is_wp_error( $a431_random ) && 'ignored' === $a431_random['type'] && is_wp_error( $a431_known ) && 429 === $a431_known->get_error_data()['status'] );

$wpdb->query( "DELETE FROM {$p}wpcap_orders WHERE customer_email LIKE 'a431-%'" );
