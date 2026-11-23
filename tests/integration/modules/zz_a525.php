<?php
/**
 * A525: the queue of deferred box office stornos is changed only under a DB lock, and only one booking run
 * works on it at a time (two shifts opened in parallel never book a storno twice).
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\BoxOffice\Shifts;
use PassCaptain\DbLock;

echo "A525: deferred storno queue under a lock\n";
$a525_saved = get_option( Shifts::DEFERRED_OPTION, array() );
update_option( Shifts::DEFERRED_OPTION, array(), false );
check( 'A525: a storno is queued', true === Shifts::defer_refund( 990001 ) && in_array( 990001, array_map( 'intval', (array) get_option( Shifts::DEFERRED_OPTION, array() ) ), true ) );
Shifts::defer_refund( 990001 );
check( 'A525: queueing the same storno twice keeps one entry', 1 === count( array_keys( array_map( 'intval', (array) get_option( Shifts::DEFERRED_OPTION, array() ) ), 990001, true ) ) );
// A second connection holds the queue lock: the change waits and gives up instead of overwriting.
$a525_db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$a525_name = DbLock::name( Shifts::DEFERRED_LOCK );
$a525_got = '1' === (string) $a525_db->query( "SELECT GET_LOCK('" . $a525_db->real_escape_string( $a525_name ) . "',0)" )->fetch_row()[0];
$a525_start = microtime( true );
$a525_res = Shifts::defer_refund( 990002 );
check( 'A525: while another request holds the lock the queue is not overwritten (busy, logged)', $a525_got && false === $a525_res && ! in_array( 990002, array_map( 'intval', (array) get_option( Shifts::DEFERRED_OPTION, array() ) ), true ) && microtime( true ) - $a525_start >= 4 );
$a525_db->query( "SELECT RELEASE_LOCK('" . $a525_db->real_escape_string( $a525_name ) . "')" );
// A running booking run blocks a parallel one.
$a525_run = DbLock::name( Shifts::DEFERRED_LOCK . '_run' );
$a525_db->query( "SELECT GET_LOCK('" . $a525_db->real_escape_string( $a525_run ) . "',0)" );
check( 'A525: a second booking run does nothing while the first runs', 0 === Shifts::book_deferred( 1 ) && in_array( 990001, array_map( 'intval', (array) get_option( Shifts::DEFERRED_OPTION, array() ) ), true ) );
$a525_db->query( "SELECT RELEASE_LOCK('" . $a525_db->real_escape_string( $a525_run ) . "')" );
$a525_db->close();
update_option( Shifts::DEFERRED_OPTION, $a525_saved, false );
