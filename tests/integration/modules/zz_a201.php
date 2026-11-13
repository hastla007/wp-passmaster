<?php
/**
 * A201: report caches with per-site/per-event generation keys. Every mutation (order, payment,
 * refund, check-in, ticket type, occurrence, event) invalidates the cached REST event report of its
 * event only; unknown writes invalidate the site; writes inside a transaction take effect after COMMIT.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Reports\Cache;
use PassCaptain\Rest\Controller;

global $wpdb;
$p = $wpdb->prefix;

Cache::reset();
Cache::register();

$a201_event_of = static function ( $type ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
};
$a201_occ_of = static function ( $type ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
};
$a201_report = static function ( $event ) {
	$r = new WP_REST_Request( 'GET' );
	$r->set_param( 'event_id', $event );
	$r->set_param( 'from', gmdate( 'Y-m-d', time() - 86400 ) );
	$r->set_param( 'to', gmdate( 'Y-m-d', time() + 86400 ) );
	$res = Controller::event_report( $r );
	$data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
	$sold = 0;
	foreach ( $data['sales_by_currency'] ?? array() as $row ) { $sold += (int) $row['tickets_sold']; }
	return array( 'sold' => $sold, 'checkins' => (int) ( $data['checkins'] ?? 0 ) );
};
$a201_key = static function ( $event ) { return Cache::key( 'rest_event_report', $event, array( 'x' => 1 ) ); };
// Runs one harmless statement, so a bump that is due after the previous statement happens.
$a201_tick = static function () use ( $wpdb ) { $wpdb->get_var( 'SELECT 1' ); };

$a201_type_a = make_type( 50, 10, 0 );
$a201_type_b = make_type( 50, 10, 0 );
$a201_ev_a = $a201_event_of( $a201_type_a );
$a201_ev_b = $a201_event_of( $a201_type_b );
$a201_tick();

echo "A201: cache keys are site- and event-scoped\n";
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
check( 'key differs per event and is stable without changes', $ka !== $kb && $ka === $a201_key( $a201_ev_a ) && 0 === strpos( $ka, 'wpcap_rep_' ) && strlen( $ka ) <= 172 );
check( 'key differs per parameters and report name', $ka !== Cache::key( 'rest_event_report', $a201_ev_a, array( 'x' => 2 ) ) && $ka !== Cache::key( 'other', $a201_ev_a, array( 'x' => 1 ) ) );
$a201_prefix = $wpdb->prefix;
$wpdb->prefix = 'wpa201site2_';
$k_other_site = $a201_key( $a201_ev_a );
$wpdb->prefix = $a201_prefix;
check( 'key differs per site (table prefix) for the same event id', $k_other_site !== $ka && $ka === $a201_key( $a201_ev_a ) );

echo "A201: cached value is served until a change\n";
$a201_calls = 0;
$a201_compute = static function () use ( &$a201_calls ) { return ++$a201_calls; };
$v1 = Cache::remember( 'a201_probe', $a201_ev_a, array(), $a201_compute );
$v2 = Cache::remember( 'a201_probe', $a201_ev_a, array(), $a201_compute );
check( 'second read is served from the cache', 1 === $v1 && 1 === $v2 && 1 === $a201_calls );
$GLOBALS['wpcap_test_filters']['wpcap_report_cache_ttl'] = 0;
Cache::remember( 'a201_probe', $a201_ev_a, array(), $a201_compute );
unset( $GLOBALS['wpcap_test_filters']['wpcap_report_cache_ttl'] );
check( 'TTL 0 disables caching (filter wpcap_report_cache_ttl)', 2 === $a201_calls && 300 === Cache::ttl() );
$GLOBALS['wpcap_test_filters']['wpcap_report_cache_ttl'] = 99999;
check( 'TTL is capped (safety net stays short)', Cache::MAX_TTL === Cache::ttl() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_report_cache_ttl'] );
check( 'a WP_Error is not cached', is_wp_error( Cache::remember( 'a201_err', $a201_ev_a, array(), static fn() => new WP_Error( 'x', 'y' ) ) ) && 7 === Cache::remember( 'a201_err', $a201_ev_a, array(), static fn() => 7 ) );

echo "A201: every mutation invalidates the event report\n";
$r0 = $a201_report( $a201_ev_a );
$rb0 = $a201_report( $a201_ev_b );
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );

// Order (free order: paid at once).
$a201_o1 = Orders::create( array( 'email' => 'a201-1@example.com', 'items' => array( array( 'ticket_type_id' => $a201_type_a, 'quantity' => 2 ) ), 'idempotency_key' => 'a201-o1' ) );
$r1 = $a201_report( $a201_ev_a );
check( 'order: report of event A shows the new tickets at once (no stale 5-minute value)', ! is_wp_error( $a201_o1 ) && $r0['sold'] + 2 === $r1['sold'] && $ka !== $a201_key( $a201_ev_a ) );
check( 'order on A leaves the cache key of event B untouched', $kb === $a201_key( $a201_ev_b ) && $rb0 === $a201_report( $a201_ev_b ) );

// Payment.
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET price_minor=1500 WHERE ticket_type_id=$a201_type_a" );
$a201_o2 = Orders::create( array( 'email' => 'a201-2@example.com', 'items' => array( array( 'ticket_type_id' => $a201_type_a, 'quantity' => 1 ) ), 'idempotency_key' => 'a201-o2' ) );
$r2 = $a201_report( $a201_ev_a );
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
Orders::confirm_manual_payment( $a201_o2['order_id'] );
$r3 = $a201_report( $a201_ev_a );
check( 'payment: confirming a pending order invalidates and the paid tickets appear', $r1['sold'] === $r2['sold'] && $r2['sold'] + 1 === $r3['sold'] && $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );

// Refund.
$ka = $a201_key( $a201_ev_a );
Orders::mark_refunded( $a201_o2['order_id'], 'a201' );
$r4 = $a201_report( $a201_ev_a );
check( 'refund: refunded order disappears from the cached report', $r3['sold'] - 1 === $r4['sold'] && $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => (int) $a201_o1['order_id'], 'amount_minor' => 0, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '', 'status' => 'requested', 'reason' => 'a201', 'idempotency_key' => 'a201-r1', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a201_refund_id = (int) $wpdb->insert_id;
$a201_tick();
$ka2 = $a201_key( $a201_ev_a );
$wpdb->update( "{$p}wpcap_refunds", array( 'status' => 'completed' ), array( 'refund_id' => $a201_refund_id ) );
$a201_tick();
check( 'refund rows: insert (order_id) and status change (refund_id) invalidate event A only', $ka !== $ka2 && $ka2 !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );

// Check-in.
$a201_codes = ticket_code_for( $a201_o1['order_id'] );
$ka = $a201_key( $a201_ev_a );
$a201_ci = PassCaptain\Checkin::perform( $a201_codes[0] ?? '', array( 'idempotency_key' => 'a201-ci-1' ) );
$r5 = $a201_report( $a201_ev_a );
check( 'check-in: invalidates and the cached report counts it', ! is_wp_error( $a201_ci ) && $r4['checkins'] + 1 === $r5['checkins'] && $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );

// Ticket type, occurrence, event (admin edits write directly).
$ka = $a201_key( $a201_ev_a );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'name' => 'Ermäßigt' ), array( 'ticket_type_id' => $a201_type_a ) );
$a201_tick();
check( 'ticket type change invalidates event A only', $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$wpdb->update( "{$p}wpcap_occurrences", array( 'location' => 'Graz' ), array( 'occurrence_id' => $a201_occ_of( $a201_type_a ) ) );
$a201_tick();
check( 'occurrence change invalidates event A only', $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'Konzert neu' ), array( 'event_id' => $a201_ev_a ) );
$a201_tick();
check( 'event change invalidates event A only', $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a201_occ_of( $a201_type_a ), 'name' => 'Neu', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 5, 'max_per_order' => 5, 'status' => 'active' ) );
$a201_new_type = (int) $wpdb->insert_id;
$a201_tick();
check( 'new ticket type (INSERT with occurrence_id) invalidates event A only', $a201_new_type > 0 && $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );

// Moving a ticket type to another event: both events (the old one resolved before the write).
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a201_occ_of( $a201_type_b ) ), array( 'ticket_type_id' => $a201_new_type ) );
$a201_tick();
check( 'moving a ticket type to event B invalidates the old and the new event', $ka !== $a201_key( $a201_ev_a ) && $kb !== $a201_key( $a201_ev_b ) );

// Deleting rows: resolved before the statement runs.
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
$wpdb->delete( "{$p}wpcap_ticket_types", array( 'ticket_type_id' => $a201_new_type ) );
$a201_tick();
check( 'deleting a ticket type of B invalidates B (resolved before the row is gone)', $kb !== $a201_key( $a201_ev_b ) && $ka === $a201_key( $a201_ev_a ) );

echo "A201: transactions, unknown writes, extensions\n";
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
$wpdb->query( 'START TRANSACTION' );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'name' => 'Tx' ), array( 'ticket_type_id' => $a201_type_a ) );
$a201_tick();
$k_in_tx = $a201_key( $a201_ev_a );
$wpdb->query( 'COMMIT' );
$a201_tick();
check( 'write inside a transaction bumps only after COMMIT (no re-caching of uncommitted state)', $ka === $k_in_tx && $ka !== $a201_key( $a201_ev_a ) && $kb === $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$wpdb->query( "UPDATE {$p}wpcap_orders SET updated_at=updated_at WHERE status='a201-none'" );
$a201_tick();
check( 'write without determinable rows invalidates the whole site', $ka !== $a201_key( $a201_ev_a ) && $kb !== $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status=status WHERE ticket_type_id=$a201_type_a OR order_id=0" );
$a201_tick();
check( 'OR in the WHERE clause is treated as undeterminable (site)', $ka !== $a201_key( $a201_ev_a ) && $kb !== $a201_key( $a201_ev_b ) );
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
Cache::note_query( "INSERT INTO {$p}wpcap_orders (public_id,status,customer_email) VALUES ('a201x','pending_payment','x@example.com')" );
Cache::note_query( "INSERT INTO {$p}wpcap_audit (action) VALUES ('x')" );
Cache::note_query( "SELECT * FROM {$p}wpcap_orders WHERE order_id=1" );
check( 'bare order INSERT, unwatched tables and SELECTs bump nothing', array() === array_filter( Cache::pending() ) && $ka === $a201_key( $a201_ev_a ) );
Cache::note_query( "INSERT INTO `{$p}wpcap_tickets` (`order_id`, `ticket_type_id`, `attendee_name`) VALUES (0, $a201_type_b, 'a, b \\' c), (d'), (0, $a201_type_a, 'x''y')" );
$a201_pending = Cache::pending();
$a201_pending = reset( $a201_pending ) ?: array();
sort( $a201_pending );
$a201_expect = array( $a201_ev_a, $a201_ev_b );
sort( $a201_expect );
check( 'multi-row INSERT with quoted commas/parentheses resolves every row', $a201_expect === $a201_pending );
Cache::flush();
check( 'flush bumps the noted events', $ka !== $a201_key( $a201_ev_a ) && $kb !== $a201_key( $a201_ev_b ) && array() === Cache::pending() );
$ka = $a201_key( $a201_ev_a );
$kb = $a201_key( $a201_ev_b );
do_action( 'wpcap_report_cache_invalidate', $a201_ev_b );
check( 'action wpcap_report_cache_invalidate (own writers) bumps one event', $ka === $a201_key( $a201_ev_a ) && $kb !== $a201_key( $a201_ev_b ) );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => 0, 'event_id' => $a201_ev_a, 'user_id' => 0, 'result' => 'invalid', 'idempotency_key' => 'a201-state', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$a201_insert_id = $wpdb->insert_id;
Cache::remember( 'a201_state', $a201_ev_a, array(), static fn() => 1 );
check( 'a flush between two caller statements keeps $wpdb->insert_id', $a201_insert_id > 0 && $a201_insert_id === $wpdb->insert_id );

echo "A201: indexes for the report range queries (schema 3.10.6)\n";
$a201_keys = static function ( $table ) use ( $wpdb ) {
	$out = array();
	foreach ( $wpdb->get_results( "SHOW INDEX FROM $table" ) as $row ) { $out[ $row->Key_name ][ (int) $row->Seq_in_index ] = $row->Column_name; }
	return $out;
};
check( 'refunds: KEY status_updated (status, updated_at) for completed refunds per period', array( 1 => 'status', 2 => 'updated_at' ) === ( $a201_keys( "{$p}wpcap_refunds" )['status_updated'] ?? null ) );
check( 'credit notes: KEY issued_at for correction documents per period', array( 1 => 'issued_at' ) === ( $a201_keys( "{$p}wpcap_credit_notes" )['issued_at'] ?? null ) );
$a201_plan = $wpdb->get_row( "EXPLAIN SELECT r.refund_id FROM {$p}wpcap_refunds r WHERE r.status='completed' AND r.updated_at >= '2026-05-01 00:00:00' AND r.updated_at < '2026-06-01 00:00:00'" );
check( 'refund range query can use status_updated', false !== strpos( (string) $a201_plan->possible_keys, 'status_updated' ) );
$a201_plan = $wpdb->get_row( "EXPLAIN SELECT c.credit_note_id FROM {$p}wpcap_credit_notes c WHERE c.issued_at >= '2026-05-01 00:00:00' AND c.issued_at < '2026-06-01 00:00:00'" );
check( 'credit note range query can use issued_at', false !== strpos( (string) $a201_plan->possible_keys, 'issued_at' ) );
check( 'schema version bumped for the new indexes', version_compare( PassCaptain\Installer::DB_VERSION, '3.10.6', '>=' ) );
