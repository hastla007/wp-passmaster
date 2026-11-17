<?php
// phpcs:ignoreFile
/**
 * A519: mass mails (occurrence changes/cancellations, reminders, event cancellation runs) pick the
 * language of the recipient's newest order, not MAX(locale) (alphabetically last).
 */

use PassCaptain\Notifications;
use PassCaptain\Orders;

echo "A519: recipient language of mass mails = newest order\n";
global $wpdb;
$a519_type = make_type( 10, 10, 0 );
$a519_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $a519_type ) );
foreach ( array( 'nl_NL', 'de_DE' ) as $a519_i => $a519_locale ) {
	Orders::create( array( 'email' => 'x4-a519@example.com', 'locale' => $a519_locale, 'items' => array( array( 'ticket_type_id' => $a519_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x4-a519-' . $a519_i . '-' . uniqid() ) );
}
Orders::create( array( 'email' => 'x4-a519b@example.com', 'locale' => 'fr_FR', 'items' => array( array( 'ticket_type_id' => $a519_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x4-a519-b-' . uniqid() ) );
$a519_c = Orders::create( array( 'email' => 'x4-a519b@example.com', 'items' => array( array( 'ticket_type_id' => $a519_type, 'quantity' => 1 ) ), 'idempotency_key' => 'x4-a519-c-' . uniqid() ) );
$wpdb->update( "{$wpdb->prefix}wpcap_orders", array( 'locale' => '' ), array( 'order_id' => (int) $a519_c['order_id'] ) ); // e.g. an order of an older version
$a519_m = new ReflectionMethod( Notifications::class, 'recipients' );
$a519_m->setAccessible( true );
$a519_rows = array_column( array_map( static fn( $r ) => (array) $r, $a519_m->invoke( null, $a519_occ ) ), 'locale', 'email' );
check( 'A519: the newest order decides (de_DE after nl_NL), not the alphabetically last locale', 'de_DE' === ( $a519_rows['x4-a519@example.com'] ?? null ) );
check( 'A519: an order without locale does not erase the language of an earlier one', 'fr_FR' === ( $a519_rows['x4-a519b@example.com'] ?? null ) );
$a519_src = (string) file_get_contents( WPCAP_PATH . 'src/Modules/Reminders.php' ) . file_get_contents( WPCAP_PATH . 'src/BulkRuns.php' ) . file_get_contents( WPCAP_PATH . 'src/Notifications.php' );
check( 'A519: no recipient query uses MAX(ord.locale) any more', false === strpos( $a519_src, 'MAX(ord.locale)' ) && 2 <= substr_count( $a519_src, 'Notifications::LATEST_LOCALE_SQL' ) );
