<?php
// phpcs:ignoreFile
/**
 * A531: editing a ticket type – optimistic locking with a revision of the editable fields (a form
 * opened on an older state is refused instead of overwriting another admin's change) and check +
 * update under the row lock that sales take as well.
 */
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Queries;

echo "A531: ticket type edits – lost update and race\n";
global $wpdb;
$p = $wpdb->prefix;
$a531_type = make_type( 20, 10, 2000 );
$a531_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a531_type" );
$a531_in = static fn( array $extra ) => array_merge( array( 'ticket_type_id' => $a531_type, 'occurrence_id' => $a531_occ, 'name' => 'Standard', 'currency' => 'EUR', 'price' => '20,00', 'tax_rate' => '0', 'max_per_order' => 10, 'capacity' => 20, 'status' => 'active' ), $extra );

// Two admins open the form on the same state.
$a531_rev = EventsPage::type_revision( Queries::ticket_type( $a531_type ) );
$a531_first = EventsPage::save_ticket_type( $a531_in( array( 'revision' => $a531_rev, 'price' => '25,00' ) ) );
check( 'A531: the first save with the current revision succeeds', ! is_wp_error( $a531_first ) && 2500 === (int) Queries::ticket_type( $a531_type )->price_minor );
$a531_second = EventsPage::save_ticket_type( $a531_in( array( 'revision' => $a531_rev, 'capacity' => 30 ) ) );
$a531_now = Queries::ticket_type( $a531_type );
check( 'A531: the second save on the old state is refused (no lost update)', is_wp_error( $a531_second ) && 'wpcap_type_changed' === $a531_second->get_error_code() && 409 === ( $a531_second->get_error_data()['status'] ?? 0 ) && 2500 === (int) $a531_now->price_minor && 20 === (int) $a531_now->capacity );
check( 'A531: sales counters are not part of the revision', EventsPage::type_revision( $a531_now ) === EventsPage::type_revision( (object) array_merge( (array) $a531_now, array( 'sold_qty' => 99, 'held_qty' => 7 ) ) ) );
$a531_third = EventsPage::save_ticket_type( $a531_in( array( 'revision' => EventsPage::type_revision( $a531_now ), 'price' => '25,00', 'capacity' => 30 ) ) );
check( 'A531: saving again with the new revision works', ! is_wp_error( $a531_third ) && 30 === (int) Queries::ticket_type( $a531_type )->capacity );
check( 'A531: callers without a revision (API, siblings) are not blocked', ! is_wp_error( EventsPage::save_ticket_type( $a531_in( array( 'price' => '25,00', 'capacity' => 31 ) ) ) ) );
check( 'A531: the transaction is closed after a refused save', ! is_wp_error( EventsPage::save_ticket_type( $a531_in( array( 'price' => '25,00', 'capacity' => 32 ) ) ) ) && 32 === (int) $wpdb->get_var( "SELECT capacity FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a531_type" ) );

// The check runs under the row lock: while a sale holds the type row, the edit waits (and gives up cleanly).
$a531_db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$a531_db->query( 'START TRANSACTION' );
$a531_db->query( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a531_type FOR UPDATE" );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=1' );
$a531_start = microtime( true );
$a531_blocked = EventsPage::save_ticket_type( $a531_in( array( 'price' => '25,00', 'capacity' => 40 ) ) );
$a531_waited = microtime( true ) - $a531_start;
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=50' );
$a531_db->query( 'ROLLBACK' );
$a531_db->close();
check( 'A531: an edit waits for the lock of a running sale and reports "busy" instead of saving', is_wp_error( $a531_blocked ) && 'wpcap_type_busy' === $a531_blocked->get_error_code() && $a531_waited >= 0.9 && 32 === (int) $wpdb->get_var( "SELECT capacity FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a531_type" ) );
check( 'A531: afterwards the edit goes through', ! is_wp_error( EventsPage::save_ticket_type( $a531_in( array( 'price' => '25,00', 'capacity' => 40 ) ) ) ) && 40 === (int) $wpdb->get_var( "SELECT capacity FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a531_type" ) );
