<?php
/**
 * A475: Google Wallet ids no longer follow home_url(). The site part of class/object ids is fixed
 * at the first use (or taken from passes issued before), updates use the ids stored with the pass,
 * and a 404 from the Wallet API is logged instead of passing silently.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\WalletPasses\GoogleProvider;
use PassCaptain\WalletPasses\Service;

global $wpdb;
$p = $wpdb->prefix;

/** Google provider whose Wallet API calls are recorded and answered with a fixed status. */
final class A475_Google extends GoogleProvider {
	public $calls = array();
	public $status = 200;
	public function issuer_id() { return '3388000000022'; }
	public function api( $method, $path, array $body ) { $this->calls[] = array( $method, $path, $body ); return array( 'code' => $this->status, 'body' => null ); }
}
$a475_ticket = array(
	'ticket_id' => 1, 'public_id' => 'abcdef0123456789', 'generation' => 1, 'occurrence_id' => 77, 'event_name' => 'A475', 'ticket_name' => 'Standard',
	'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'ends_at' => null, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'locale' => 'de_DE',
	'voided' => false, 'code' => str_repeat( 'a', 64 ), 'seat' => '', 'attendee_name' => '', 'occurrence_status' => 'scheduled', 'event_status' => 'published',
);

echo "A475: stable Google Wallet ids\n";
$wpdb->query( "DELETE FROM {$p}wpcap_wallet_passes WHERE provider='google'" );
unset( $GLOBALS['wpcap_test_options'][ GoogleProvider::SITE_KEY_OPTION ] );
// An install from before: passes were issued under the hash of an earlier address.
$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => 999001, 'provider' => 'google', 'provider_ref' => '3388000000022.wpcap_0badc0de_o5', 'serial_number' => '3388000000022.wpcap_0badc0de_tx_g1', 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'remote_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a475 = new A475_Google();
check( 'A475: the site key of passes issued before is kept (not today\'s home_url hash)', '0badc0de' === GoogleProvider::site_key() && '3388000000022.wpcap_0badc0de_o9' === $a475->class_id( 9 ) );
check( 'A475: the key is stored once', '0badc0de' === ( $GLOBALS['wpcap_test_options'][ GoogleProvider::SITE_KEY_OPTION ] ?? '' ) );
check( 'A475: object ids use the same stored key', 0 === strpos( $a475->object_id( $a475_ticket ), '3388000000022.wpcap_0badc0de_t' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_wallet_passes WHERE provider='google'" );
check( 'A475: the stored key survives without pass rows (domain change after the update)', '0badc0de' === GoogleProvider::site_key() );
unset( $GLOBALS['wpcap_test_options'][ GoogleProvider::SITE_KEY_OPTION ] );
check( 'A475: a fresh install derives the key from the home URL as before and stores it', substr( hash( 'sha256', home_url( '/' ) ), 0, 8 ) === GoogleProvider::site_key() && isset( $GLOBALS['wpcap_test_options'][ GoogleProvider::SITE_KEY_OPTION ] ) );

// Updates address the ids stored with the pass, even when they differ from the computed ones.
$a475_row = array( 'serial_number' => '3388000000022.wpcap_11112222_tabc_g1', 'provider_ref' => '3388000000022.wpcap_11112222_o77', 'status' => 'active', 'remote_at' => '2026-11-01 10:00:00' ); // Saved by the buyer (A566).
$a475->update_pass( $a475_ticket, $a475_row );
$a475_patch = $a475->calls[0] ?? array( '', '', array() );
check( 'A475: object update uses the stored object id and class id', 'PATCH' === $a475_patch[0] && 'eventTicketObject/' . rawurlencode( $a475_row['serial_number'] ) === $a475_patch[1] && $a475_row['provider_ref'] === ( $a475_patch[2]['classId'] ?? '' ) );
$a475->calls = array();
$a475->update_class( $a475_ticket + array( 'google_class_id' => $a475_row['provider_ref'] ) );
check( 'A475: class update uses the stored class id', 'eventTicketClass/' . rawurlencode( $a475_row['provider_ref'] ) === ( $a475->calls[0][1] ?? '' ) );

// 404 answers are logged.
$GLOBALS['wpcap_test_options'][ Service::LOG_OPTION ] = array();
$a475->status = 404;
$a475->calls = array();
check( 'A475: a missing class still counts as done', true === $a475->update_class( $a475_ticket ) );
$a475->revoke_pass( $a475_row );
$a475_log = array_column( (array) get_option( Service::LOG_OPTION, array() ), 'message', 'code' );
$a475_codes = array_column( (array) get_option( Service::LOG_OPTION, array() ), 'code' );
check( 'A475: 404 of class and object are written to the wallet log (without issuer number)', 2 === count( array_keys( $a475_codes, 'google_not_found', true ) ) && false === strpos( implode( ' ', array_column( (array) get_option( Service::LOG_OPTION, array() ), 'message' ) ), '3388000000022' ) );

// class_ticket() hands the stored class id to the class update (Service::process()).
$a475_type = make_type( 5, 10, 0 );
$a475_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a475_type ) );
$GLOBALS['wpcap_test_transients'] = array();
$a475_order = PassCaptain\Orders::create( array( 'email' => 'a475@example.com', 'items' => array( array( 'ticket_type_id' => $a475_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a475-' . uniqid() ) );
$a475_tid = is_wp_error( $a475_order ) ? 0 : (int) ( tickets( $a475_order['order_id'] )[0]->ticket_id ?? 0 );
$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $a475_tid, 'provider' => 'google', 'provider_ref' => '3388000000022.wpcap_11112222_o' . $a475_occ, 'serial_number' => '3388000000022.wpcap_11112222_tq_g1', 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'remote_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a475_method = new ReflectionMethod( Service::class, 'class_ticket' );
$a475_method->setAccessible( true );
$a475_class_ticket = $a475_method->invoke( null, $a475_occ );
check( 'A475: the class data source carries the stored class id', is_array( $a475_class_ticket ) && '3388000000022.wpcap_11112222_o' . $a475_occ === ( $a475_class_ticket['google_class_id'] ?? '' ) );
$wpdb->query( "DELETE FROM {$p}wpcap_wallet_passes WHERE provider='google'" );
$GLOBALS['wpcap_test_options'][ Service::LOG_OPTION ] = array();
