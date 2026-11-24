<?php
/**
 * PC-9: one documented contract for wpcap_event_cancelled – fires once per event cancellation with
 * (int $event_id, array $context: occurrence_ids, run_id), after every date of the event is cancelled and
 * every ticket of it revoked; a resumed or repeated cancellation does not fire again; docs/HOOKS.md has
 * one entry. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;
$pc9_hooks_before = $GLOBALS['wpcap_test_hooks'];
$pc9_calls = array();
$pc9_order = array();
add_action( 'wpcap_occurrence_cancelled', static function ( $occurrence_id ) use ( &$pc9_order ) { $pc9_order[] = 'occurrence:' . (int) $occurrence_id; }, 1, 1 );
add_action( 'wpcap_event_cancelled', static function ( ...$args ) use ( &$pc9_calls, &$pc9_order, $wpdb, $p ) {
	$event_id = (int) $args[0];
	$pc9_order[] = 'event:' . $event_id;
	$pc9_calls[] = array(
		'args' => $args,
		'valid_tickets' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND t.status='valid'", $event_id ) ),
		'open_dates' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d AND status<>'cancelled'", $event_id ) ),
	);
}, 1, 2 );

echo "PC-9: wpcap_event_cancelled after the dates were cancelled and the tickets revoked\n";
$pc9_type = make_type( 10, 10, 1500 );
$pc9_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pc9_type" );
$pc9_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$pc9_occ" );
$pc9_o = Orders::create( array( 'email' => 'pc9@example.com', 'items' => array( array( 'ticket_type_id' => $pc9_type, 'quantity' => 3 ) ), 'idempotency_key' => 'pc9-' . uniqid() ) );
Orders::confirm_manual_payment( $pc9_o['order_id'] );
$pc9_result = Orders::cancel_event( $pc9_event, 'PC-9' );
check( 'PC-9: the cancellation run completed in the request', ! is_wp_error( $pc9_result ) && ! empty( $pc9_result['complete'] ) );
check( 'PC-9: wpcap_event_cancelled fired exactly once', 1 === count( $pc9_calls ) );
$pc9_c = $pc9_calls[0] ?? array( 'args' => array(), 'valid_tickets' => -1, 'open_dates' => -1 );
check( 'PC-9: arguments are int $event_id and array $context with occurrence_ids and run_id', 2 === count( $pc9_c['args'] ) && $pc9_event === $pc9_c['args'][0] && is_array( $pc9_c['args'][1] ) && in_array( $pc9_occ, $pc9_c['args'][1]['occurrence_ids'] ?? array(), true ) && array_key_exists( 'run_id', $pc9_c['args'][1] ) && is_int( $pc9_c['args'][1]['run_id'] ) );
check( 'PC-9: when it fires, every date is cancelled and no ticket of the event is still valid', 0 === $pc9_c['open_dates'] && 0 === $pc9_c['valid_tickets'] && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=" . (int) $pc9_o['order_id'] . " AND status='revoked'" ) );
check( 'PC-9: it fires after wpcap_occurrence_cancelled of its date', array( 'occurrence:' . $pc9_occ, 'event:' . $pc9_event ) === array_values( array_intersect( $pc9_order, array( 'occurrence:' . $pc9_occ, 'event:' . $pc9_event ) ) ) );
$pc9_resumed = Orders::resume_event_cancellation( $pc9_event );
PassCaptain\Admin\EventsPage::cancel_event( array( 'event_id' => $pc9_event, 'reason' => 'nochmal', 'confirm' => '1' ) );
check( 'PC-9: resuming or cancelling the cancelled event again does not fire it a second time', ! is_wp_error( $pc9_resumed ) && 1 === count( $pc9_calls ) );

$pc9_hooks = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
check( 'PC-9: docs/HOOKS.md documents wpcap_event_cancelled in one table row of the hook list (plus the suite catalogue)', 1 === preg_match_all( '/^\| `wpcap_event_cancelled` \| `int \$event_id, array \$context`/m', $pc9_hooks ) && 1 === preg_match_all( '/^\| `wpcap_event_cancelled` \| 1\.5\.0 \|/m', $pc9_hooks ) && 0 === preg_match( '/^\| `wpcap_event_cancelled` \| `int \$event_id` \|/m', $pc9_hooks ) );
check( 'PC-9: Orders::cancel_event() no longer fires the action right after the commit', 0 === substr_count( (string) file_get_contents( WPCAP_PATH . 'src/Orders.php' ) . (string) file_get_contents( WPCAP_PATH . 'src/Orders/Cancellation.php' ), "do_action( 'wpcap_event_cancelled'" ) && 1 === substr_count( (string) file_get_contents( WPCAP_PATH . 'src/BulkRuns.php' ), "do_action( 'wpcap_event_cancelled'" ) );
$GLOBALS['wpcap_test_hooks'] = $pc9_hooks_before;
