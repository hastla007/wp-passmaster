<?php
/**
 * A510: the delayed deletion of Apple Wallet registrations after a privacy erasure is recorded in an
 * option, so a deactivation (which removes the cron event) cannot drop it; the daily retention run
 * deletes whatever is due, the cron event remains the fast path and clears its entries.
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Privacy;
use PassCaptain\Privacy\Retention;

global $wpdb;
$p = $wpdb->prefix;
echo "A510: pending wallet registration purges survive a deactivation\n";

$x2_a510_now = gmdate( 'Y-m-d H:i:s' );
$x2_a510_reg = static function ( $ticket_id ) use ( $wpdb, $p, $x2_a510_now ) {
	$serial = 'x2a510-' . $ticket_id;
	$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $ticket_id, 'provider' => 'apple', 'serial_number' => $serial, 'status' => 'revoked', 'updated_at' => $x2_a510_now ) );
	$wpdb->insert( "{$p}wpcap_wallet_registrations", array( 'device_library_id' => 'dev-' . $ticket_id, 'pass_type_id' => 'pass.x', 'serial_number' => $serial, 'push_token' => 'tok-' . $ticket_id, 'created_at' => $x2_a510_now, 'updated_at' => $x2_a510_now ) );
};
$x2_a510_left = static fn( $ticket_id ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='x2a510-" . (int) $ticket_id . "'" );
$x2_a510_reg( 9905101 );
$x2_a510_reg( 9905102 );
delete_option( Privacy::WALLET_PURGE_OPTION );

$x2_a510_erase = new ReflectionMethod( Privacy::class, 'erase_ticket_details' );
$x2_a510_erase->setAccessible( true );
$GLOBALS['wpcap_test_cron'] = array();
$x2_a510_erase->invoke( null, array( 9905101, 9905102 ), false );
$x2_a510_pending = Privacy::pending_wallet_purges();
check( 'A510: the delayed purge is recorded (due in about an hour), registrations stay for the revocation push', isset( $x2_a510_pending[9905101], $x2_a510_pending[9905102] ) && $x2_a510_pending[9905101] > time() + 3000 && 1 === $x2_a510_left( 9905101 ) );

// Deactivation removes the cron event; the next retention run after the due time still deletes them.
$GLOBALS['wpcap_test_cron'] = array();
Retention::run( time() + 600 );
check( 'A510: a retention run before the due time keeps the registrations', 1 === $x2_a510_left( 9905101 ) && 2 === count( Privacy::pending_wallet_purges() ) );
$x2_a510_counts = Retention::run( time() + 2 * HOUR_IN_SECONDS );
check( 'A510: the retention run deletes due registrations and clears the record', 0 === $x2_a510_left( 9905101 ) && 0 === $x2_a510_left( 9905102 ) && array() === Privacy::pending_wallet_purges() && 2 === (int) ( $x2_a510_counts['wallet_purges'] ?? 0 ) );

// The cron fast path clears its entries as well.
$x2_a510_reg( 9905103 );
$x2_a510_erase->invoke( null, array( 9905103 ), false );
Privacy::purge_wallet_registrations( array( 9905103 ) );
check( 'A510: the cron purge removes its tickets from the record', 0 === $x2_a510_left( 9905103 ) && array() === Privacy::pending_wallet_purges() );

$wpdb->query( "DELETE FROM {$p}wpcap_wallet_passes WHERE serial_number LIKE 'x2a510-%'" );
delete_option( Privacy::WALLET_PURGE_OPTION );
