<?php
/**
 * A196: privacy export, erasure and retention at scale, on multisite and for WooCommerce orders.
 *
 * - Large data: one address with 2000 orders/tickets, 6000+ check-ins (one ticket with 700),
 *   250 tickets of another buyer naming it as attendee, 120 waitlist entries, 60 passes,
 *   60 service bookings and 300 SMS consents. The exporters and erasers are driven page by page
 *   like WordPress does (page sizes, `done` only on the last page, no duplicates, nothing missing,
 *   time per page), the retention run works in bounded batches.
 * - Multisite: a second site prefix (like zz_a182.php) with data of the same address; erasing on
 *   one site never touches the other site's tables or user meta.
 * - WooCommerce: attendee payload on WooCommerce orders (`_wpcap_attendees`) in export, erasure
 *   (WooCommerce's and the plugin's) and retention.
 *
 * Included from run.php (uses check(), make_type(), make_wc_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\CapacityCounters;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as A196Woo;
use PassCaptain\Integrations\WooCommerce\Privacy as WooPrivacy;
use PassCaptain\Modules\Sms as A196Sms;
use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\Privacy\Retention;
use PassCaptain\Settings;

if ( ! function_exists( 'wc_get_orders' ) ) {
	/** WooCommerce attrappe: meta_key/meta_value and limit over the fake orders. */
	function wc_get_orders( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['wpcap_test_orders'] ?? array() as $order ) {
			if ( isset( $args['meta_key'] ) && (string) $order->get_meta( $args['meta_key'], true ) !== (string) ( $args['meta_value'] ?? '' ) ) { continue; }
			$out[] = $order;
			if ( count( $out ) >= (int) ( $args['limit'] ?? 10 ) ) { break; }
		}
		return $out;
	}
}

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_transients'] = array();
$a196_filters_before = $GLOBALS['wpcap_test_filters'] ?? array();
$a196_now = gmdate( 'Y-m-d H:i:s' );
$a196_budget = 5.0; // Seconds per exporter/eraser page (WordPress runs each page in one AJAX request).

/** Multi-row INSERT in chunks; returns the first insert id of each chunk is not needed – ids are read back. */
function a196_insert( $table, array $rows ) {
	global $wpdb;
	foreach ( array_chunk( $rows, 400 ) as $chunk ) {
		$cols = array_keys( $chunk[0] );
		$values = array();
		foreach ( $chunk as $row ) {
			$values[] = '(' . implode( ',', array_map( static fn( $v ) => null === $v ? 'NULL' : "'" . esc_sql_a196( (string) $v ) . "'", $row ) ) . ')';
		}
		$wpdb->query( "INSERT INTO $table (" . implode( ',', $cols ) . ') VALUES ' . implode( ',', $values ) );
	}
}
function esc_sql_a196( $v ) { return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $v ); }
function a196_hex( $n ) { return bin2hex( random_bytes( (int) ( $n / 2 ) ) ); }

/**
 * Drives an exporter like WordPress: pages until `done`. Returns pages, items (group|item => n),
 * per-page item counts, done flags and the slowest page.
 */
function a196_export( callable $exporter, $email, $max_pages = 200 ) {
	$out = array( 'pages' => 0, 'items' => array(), 'per_page' => array(), 'done_flags' => array(), 'max_time' => 0.0, 'data' => array() );
	for ( $page = 1; $page <= $max_pages; $page++ ) {
		$t = microtime( true );
		$r = call_user_func( $exporter, $email, $page );
		$out['max_time'] = max( $out['max_time'], microtime( true ) - $t );
		$out['pages']++;
		$out['done_flags'][] = (bool) $r['done'];
		$counts = array();
		foreach ( $r['data'] as $item ) {
			$key = $item['group_id'] . '|' . $item['item_id'];
			$out['items'][ $key ] = ( $out['items'][ $key ] ?? 0 ) + 1;
			$counts[ $item['group_id'] ] = ( $counts[ $item['group_id'] ] ?? 0 ) + 1;
			$out['data'][ $key ] = $item;
		}
		$out['per_page'][] = $counts;
		if ( $r['done'] ) { break; }
	}
	return $out;
}
/** Drives an eraser like WordPress: pages until `done`. */
function a196_erase( callable $eraser, $email, $max_pages = 200 ) {
	$out = array( 'pages' => 0, 'messages' => array(), 'removed' => false, 'retained' => false, 'done_flags' => array(), 'max_time' => 0.0 );
	for ( $page = 1; $page <= $max_pages; $page++ ) {
		$t = microtime( true );
		$r = call_user_func( $eraser, $email, $page );
		$out['max_time'] = max( $out['max_time'], microtime( true ) - $t );
		$out['pages']++;
		$out['done_flags'][] = (bool) $r['done'];
		$out['messages'] = array_merge( $out['messages'], (array) $r['messages'] );
		$out['removed'] = $out['removed'] || $r['items_removed'];
		$out['retained'] = $out['retained'] || $r['items_retained'];
		if ( $r['done'] ) { break; }
	}
	return $out;
}
/** Exactly one `done` and it is the last flag. */
function a196_done_last( array $flags ) { return $flags && end( $flags ) && 1 === count( array_filter( $flags ) ); }

/* ================================================================================ Large data */
echo "A196: privacy tools with large data\n";
$a196_email = 'a196-big@example.com';
$a196_other = 'a196-other@example.com';
$a196_n = 2000;
// One event that ended 30 days ago (nothing deferred, nothing in the retention window).
$a196_type = make_type( 10000, 10, 1000 );
$a196_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a196_type" );
$a196_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a196_occ" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s, ends_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 + 7200 ), $a196_occ ) );
$a196_t0 = microtime( true );
$a196_orders = array();
for ( $i = 0; $i < $a196_n; $i++ ) {
	$a196_orders[] = array( 'public_id' => a196_hex( 32 ), 'idempotency_key' => a196_hex( 64 ), 'customer_email' => $a196_email, 'currency' => 'EUR', 'price_snapshot' => '{}', 'status' => 'paid', 'payment_status' => 'paid', 'payment_provider' => 'manual', 'subtotal_minor' => 1000, 'total_minor' => 1000, 'created_at' => $a196_now, 'updated_at' => $a196_now );
}
// The other buyer's order whose 250 tickets name the big address as attendee.
$a196_orders[] = array( 'public_id' => a196_hex( 32 ), 'idempotency_key' => a196_hex( 64 ), 'customer_email' => $a196_other, 'currency' => 'EUR', 'price_snapshot' => '{}', 'status' => 'paid', 'payment_status' => 'paid', 'payment_provider' => 'manual', 'subtotal_minor' => 250000, 'total_minor' => 250000, 'created_at' => $a196_now, 'updated_at' => $a196_now );
$a196_first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_id),0) FROM {$p}wpcap_orders" );
a196_insert( "{$p}wpcap_orders", $a196_orders );
$a196_order_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id>%d AND customer_email=%s ORDER BY order_id", $a196_first, $a196_email ) ) );
$a196_other_order = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id>%d AND customer_email=%s", $a196_first, $a196_other ) );
$a196_items = array();
$a196_tickets = array();
foreach ( $a196_order_ids as $k => $oid ) {
	$a196_items[] = array( 'order_id' => $oid, 'ticket_type_id' => $a196_type, 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000 );
	$a196_tickets[] = array( 'public_id' => a196_hex( 32 ), 'order_id' => $oid, 'ticket_type_id' => $a196_type, 'token_hash' => a196_hex( 64 ), 'status' => 0 === $k % 2 ? 'valid' : 'checked_in', 'issued_at' => $a196_now );
}
$a196_items[] = array( 'order_id' => $a196_other_order, 'ticket_type_id' => $a196_type, 'quantity' => 250, 'unit_price_minor' => 1000, 'line_total_minor' => 250000 );
for ( $i = 0; $i < 250; $i++ ) {
	$a196_tickets[] = array( 'public_id' => a196_hex( 32 ), 'order_id' => $a196_other_order, 'ticket_type_id' => $a196_type, 'token_hash' => a196_hex( 64 ), 'status' => 'valid', 'issued_at' => $a196_now );
}
a196_insert( "{$p}wpcap_order_items", $a196_items );
$a196_ticket_first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ticket_id),0) FROM {$p}wpcap_tickets" );
a196_insert( "{$p}wpcap_tickets", $a196_tickets );
$a196_own_tickets = array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id>$a196_ticket_first AND order_id<>$a196_other_order ORDER BY ticket_id" ) );
$a196_att_tickets = array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=$a196_other_order ORDER BY ticket_id" ) );
$a196_assign = array();
foreach ( $a196_own_tickets as $k => $tid ) { $a196_assign[] = array( 'ticket_id' => $tid, 'buyer_email' => $a196_email, 'attendee_name' => 'Gast ' . $k, 'attendee_email' => '', 'created_at' => $a196_now ); }
foreach ( $a196_att_tickets as $k => $tid ) { $a196_assign[] = array( 'ticket_id' => $tid, 'buyer_email' => $a196_other, 'attendee_name' => 'Big als Gast ' . $k, 'attendee_email' => $a196_email, 'created_at' => $a196_now ); }
a196_insert( "{$p}wpcap_ticket_assignments", $a196_assign );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='" . esc_sql_a196( $a196_email ) . "' WHERE order_id=$a196_other_order" );
$a196_checkins = array();
foreach ( $a196_own_tickets as $tid ) {
	for ( $c = 0; $c < 3; $c++ ) { $a196_checkins[] = array( 'ticket_id' => $tid, 'event_id' => $a196_event, 'user_id' => 1, 'result' => 0 === $c ? 'checked_in' : 'already_checked_in', 'source' => 'web', 'created_at' => $a196_now ); }
}
$a196_heavy = $a196_own_tickets[0];
for ( $c = 0; $c < 697; $c++ ) { $a196_checkins[] = array( 'ticket_id' => $a196_heavy, 'event_id' => $a196_event, 'user_id' => 1, 'result' => 'already_checked_in', 'source' => 'app', 'created_at' => $a196_now ); }
a196_insert( "{$p}wpcap_checkins", $a196_checkins );
$a196_wl = array();
for ( $i = 0; $i < 120; $i++ ) { $a196_wl[] = array( 'event_id' => $a196_event, 'occurrence_id' => $a196_occ, 'ticket_type_id' => 9196000 + $i, 'email_hash' => hash( 'sha256', $a196_email ), 'email' => $a196_email, 'position' => $i + 1, 'quantity' => 1, 'status' => 'waiting', 'created_at' => $a196_now, 'updated_at' => $a196_now ); }
a196_insert( "{$p}wpcap_waitlist", $a196_wl );
$a196_passes = array();
for ( $i = 0; $i < 60; $i++ ) { $a196_passes[] = array( 'public_id' => a196_hex( 32 ), 'member_email' => $a196_email, 'token_hash' => a196_hex( 64 ), 'valid_from' => $a196_now, 'valid_until' => gmdate( 'Y-m-d H:i:s', time() + 86400 * 300 ), 'status' => 'active', 'created_at' => $a196_now ); }
a196_insert( "{$p}wpcap_member_passes", $a196_passes );
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $a196_event, 'name' => 'A196 Raum', 'capacity' => 100, 'status' => 'active' ) );
$a196_res = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $a196_res, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 + 3600 ), 'capacity' => 100, 'status' => 'open' ) );
$a196_slot = (int) $wpdb->insert_id;
$a196_bookings = array();
for ( $i = 0; $i < 60; $i++ ) { $a196_bookings[] = array( 'slot_id' => $a196_slot, 'order_id' => 0, 'customer_email' => $a196_email, 'idempotency_key' => a196_hex( 64 ), 'quantity' => 1, 'status' => 'booked', 'created_at' => $a196_now ); }
a196_insert( "{$p}wpcap_service_bookings", $a196_bookings );
$a196_sms = PassCaptain\Privacy::has_table( 'wpcap_sms_consents' );
if ( $a196_sms ) {
	$a196_consents = array();
	foreach ( array_slice( $a196_order_ids, 0, 300 ) as $k => $oid ) { $a196_consents[] = array( 'order_id' => $oid, 'phone' => '+43664' . str_pad( (string) $k, 7, '0', STR_PAD_LEFT ), 'purpose' => 'reminder', 'text_version' => 'v1', 'consented_at' => $a196_now ); }
	a196_insert( "{$p}wpcap_sms_consents", $a196_consents );
	$a196_msgs = array();
	foreach ( $wpdb->get_results( "SELECT consent_id,order_id FROM {$p}wpcap_sms_consents WHERE order_id IN (" . implode( ',', array_slice( $a196_order_ids, 0, 300 ) ) . ')' ) as $c ) {
		$a196_msgs[] = array( 'dedupe_key' => a196_hex( 64 ), 'consent_id' => $c->consent_id, 'order_id' => $c->order_id, 'purpose' => 'reminder', 'body' => 'Erinnerung', 'status' => 'queued', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'created_at' => $a196_now, 'updated_at' => $a196_now );
	}
	a196_insert( "{$p}wpcap_sms_messages", $a196_msgs );
}
// The bulk rows bypass the order services: bring the capacity counters in line once.
CapacityCounters::reconcile();
$a196_setup = microtime( true ) - $a196_t0;
check( "large data set up: $a196_n orders/tickets, " . count( $a196_checkins ) . ' check-ins, 250 attendee tickets, 120 waitlist, 60 passes, 60 bookings' . ( $a196_sms ? ', 300 SMS consents' : '' ) . sprintf( ' (%.1f s)', $a196_setup ), $a196_n === count( $a196_own_tickets ) && 250 === count( $a196_att_tickets ) && 6697 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id>$a196_ticket_first" ) );

// ------------------------------------------------------------------------------- Exporter
$a196_exp = a196_export( array( Privacy::class, 'export' ), $a196_email );
$a196_groups = static function ( $prefix ) use ( &$a196_exp ) { return count( array_filter( array_keys( $a196_exp['items'] ), static fn( $k ) => 0 === strpos( $k, $prefix ) ) ); };
$a196_dupes = array_filter( $a196_exp['items'], static fn( $n ) => $n > 1 );
$a196_expected_tickets = $wpdb->get_col( "SELECT CONCAT('pass-captain-tickets|ticket-',public_id) FROM {$p}wpcap_tickets WHERE ticket_id>$a196_ticket_first" );
$a196_expected_orders = $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT('pass-captain-orders|order-',public_id) FROM {$p}wpcap_orders WHERE customer_email=%s", $a196_email ) );
check( 'export: ' . $a196_exp['pages'] . ' pages (2250 tickets / 50 + final page), done only on the last', 46 === $a196_exp['pages'] && a196_done_last( $a196_exp['done_flags'] ) );
check( 'export: every page holds at most 50 entries per list', ! array_filter( $a196_exp['per_page'], static fn( $c ) => max( array_merge( array( 0 ), $c ) ) > 50 ) );
check( 'export: no entry twice across pages', ! $a196_dupes );
check( 'export: nothing missing – all 2000 orders and 2250 tickets (own + as attendee)', 2000 === $a196_groups( 'pass-captain-orders|' ) && ! array_diff( $a196_expected_orders, array_keys( $a196_exp['items'] ) ) && ! array_diff( $a196_expected_tickets, array_keys( $a196_exp['items'] ) ) );
check( 'export: 120 waitlist entries, 60 passes, 60 bookings, each in its own group', 120 === $a196_groups( 'pass-captain-waitlist|' ) && 60 === $a196_groups( 'pass-captain-passes|' ) && 60 === $a196_groups( 'pass-captain-bookings|' ) );
$a196_heavy_pub = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_tickets WHERE ticket_id=$a196_heavy" );
$a196_heavy_rows = $a196_exp['data'][ 'pass-captain-tickets|ticket-' . $a196_heavy_pub ]['data'] ?? array();
$a196_heavy_log = array_values( array_filter( $a196_heavy_rows, static fn( $r ) => 0 === strpos( $r['name'], 'Einlassprotokoll' ) ) );
check( 'export: the full admission history of a ticket with 700 check-ins (no cut at 500)', $a196_heavy_log && 700 === count( (array) json_decode( $a196_heavy_log[0]['value'], true ) ) );
check( sprintf( 'export: slowest page %.2f s (budget %.0f s)', $a196_exp['max_time'], $a196_budget ), $a196_exp['max_time'] < $a196_budget );
if ( $a196_sms ) {
	$a196_sexp = a196_export( array( A196Sms::class, 'export' ), $a196_email );
	check( 'SMS export: 300 consents in pages of 100, no duplicates, done only on the last page', 4 === $a196_sexp['pages'] && 300 === count( $a196_sexp['items'] ) && ! array_filter( $a196_sexp['items'], static fn( $n ) => $n > 1 ) && a196_done_last( $a196_sexp['done_flags'] ) && $a196_sexp['max_time'] < $a196_budget );
}

// ------------------------------------------------------------------------------- Erasers
$a196_mail_start = count( $GLOBALS['wpcap_test_mails'] );
if ( $a196_sms ) {
	$a196_serase = a196_erase( array( A196Sms::class, 'erase' ), $a196_email );
	check( 'SMS eraser: 300 consents withdrawn in pages of 100, numbers gone, queued messages skipped', 4 === $a196_serase['pages'] && a196_done_last( $a196_serase['done_flags'] ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_sms_consents WHERE order_id IN (" . implode( ',', $a196_order_ids ) . ") AND (phone<>'' OR withdrawn_at IS NULL)" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_sms_messages WHERE order_id IN (" . implode( ',', $a196_order_ids ) . ") AND status='queued'" ) && $a196_serase['max_time'] < $a196_budget );
}
$a196_er = a196_erase( array( Privacy::class, 'erase' ), $a196_email );
check( 'eraser: ' . $a196_er['pages'] . ' pages (40 × 50 orders + final), done only on the last', 41 === $a196_er['pages'] && a196_done_last( $a196_er['done_flags'] ) && $a196_er['removed'] && $a196_er['retained'] );
check( sprintf( 'eraser: slowest page %.2f s (budget %.0f s)', $a196_er['max_time'], $a196_budget ), $a196_er['max_time'] < $a196_budget );
check( 'eraser: all 2000 orders anonymised (placeholder address, no access link)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email=%s", $a196_email ) ) && $a196_n === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $a196_order_ids ) . ") AND customer_email LIKE 'deleted+%'" ) );
check( 'eraser: own tickets revoked or kept as checked in, attendee names gone', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_id IN (" . implode( ',', $a196_own_tickets ) . ") AND status='valid'" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_assignments WHERE ticket_id IN (" . implode( ',', $a196_own_tickets ) . ") AND attendee_name<>''" ) );
check( 'eraser: 250 attendee tickets of the other buyer cleared (200 + 50) and still valid', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_assignments WHERE ticket_id IN (" . implode( ',', $a196_att_tickets ) . ") AND (attendee_name<>'' OR attendee_email<>'')" ) && 250 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id=$a196_other_order AND status='valid' AND attendee_email=''" ) );
check( 'eraser: waitlist, passes and bookings of the address are gone', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$p}wpcap_waitlist WHERE email=%s)+(SELECT COUNT(*) FROM {$p}wpcap_member_passes WHERE member_email=%s)+(SELECT COUNT(*) FROM {$p}wpcap_service_bookings WHERE customer_email=%s)", $a196_email, $a196_email, $a196_email ) ) );
check( 'eraser: the other buyer\'s own order is untouched', $a196_other === Orders::get( $a196_other_order )->customer_email );
$a196_msg_counts = array_count_values( $a196_er['messages'] );
check( 'eraser: messages appear once (last page), not once per batch', $a196_er['messages'] && 1 === max( $a196_msg_counts ) && (bool) preg_grep( '/Aufbewahrungspflichten/', $a196_er['messages'] ) && count( $a196_er['messages'] ) <= 6 );
$a196_again = Privacy::erase( $a196_email, 1 );
$a196_export_after = Privacy::export( $a196_email, 1 );
check( 'eraser: a second request finds nothing; the export is empty', $a196_again['done'] && ! $a196_again['items_removed'] && ! $a196_again['items_retained'] && $a196_export_after['done'] && ! $a196_export_after['data'] );

// Deferred orders (tickets for upcoming dates): the admin is informed once per request, not per page.
$a196_future = make_type( 500, 10, 1000 );
$a196_defer_email = 'a196-defer@example.com';
$a196_frows = array();
for ( $i = 0; $i < 120; $i++ ) { $a196_frows[] = array( 'public_id' => a196_hex( 32 ), 'idempotency_key' => a196_hex( 64 ), 'customer_email' => $a196_defer_email, 'currency' => 'EUR', 'price_snapshot' => '{}', 'status' => 'paid', 'payment_status' => 'paid', 'payment_provider' => 'manual', 'subtotal_minor' => 1000, 'total_minor' => 1000, 'created_at' => $a196_now, 'updated_at' => $a196_now ); }
$a196_ffirst = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_id),0) FROM {$p}wpcap_orders" );
a196_insert( "{$p}wpcap_orders", $a196_frows );
$a196_fids = array_map( 'intval', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id>$a196_ffirst ORDER BY order_id" ) );
a196_insert( "{$p}wpcap_order_items", array_map( static fn( $oid ) => array( 'order_id' => $oid, 'ticket_type_id' => $a196_future, 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000 ), $a196_fids ) );
a196_insert( "{$p}wpcap_tickets", array_map( static fn( $oid ) => array( 'public_id' => a196_hex( 32 ), 'order_id' => $oid, 'ticket_type_id' => $a196_future, 'token_hash' => a196_hex( 64 ), 'status' => 'valid', 'issued_at' => gmdate( 'Y-m-d H:i:s' ) ), $a196_fids ) );
CapacityCounters::reconcile();
$a196_mail_start = count( $GLOBALS['wpcap_test_mails'] );
$a196_der = a196_erase( array( Privacy::class, 'erase' ), $a196_defer_email );
$a196_defer_mails = array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $a196_mail_start ), static fn( $m ) => false !== strpos( $m['subject'], 'zurückgestellt' ) ) );
check( 'deferred erasure over 3 pages: 120 orders deferred, one admin mail with the total, one message', 3 === $a196_der['pages'] && 120 === count( array_intersect( array_map( 'intval', array_keys( Privacy::deferred() ) ), $a196_fids ) ) && 1 === count( $a196_defer_mails ) && false !== strpos( $a196_defer_mails[0]['body'], '120 Bestellung' ) && 1 === count( preg_grep( '/120 Bestellung/', $a196_der['messages'] ) ) );
// Leave no deferred entries behind for later test files.
$a196_def = Privacy::deferred();
foreach ( $a196_fids as $oid ) { unset( $a196_def[ $oid ] ); }
update_option( Privacy::DEFERRED_OPTION, $a196_def );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE order_id IN (" . implode( ',', $a196_fids ) . ')' );
$wpdb->query( "UPDATE {$p}wpcap_orders SET status='cancelled',payment_status='cancelled' WHERE order_id IN (" . implode( ',', $a196_fids ) . ')' );
$wpdb->query( "DELETE FROM {$p}wpcap_order_items WHERE order_id IN (" . implode( ',', $a196_fids ) . ')' );
CapacityCounters::reconcile();

// ------------------------------------------------------------------------------- Retention batches
$a196_old = make_type( 5000, 10, 0 );
$a196_old_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a196_old" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s, ends_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 + 7200 ), $a196_old_occ ) );
$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => a196_hex( 32 ), 'idempotency_key' => a196_hex( 64 ), 'customer_email' => 'a196-ret@example.com', 'currency' => 'EUR', 'price_snapshot' => '{}', 'status' => 'paid', 'payment_status' => 'paid', 'payment_provider' => 'manual', 'created_at' => $a196_now, 'updated_at' => $a196_now ) );
$a196_ret_order = (int) $wpdb->insert_id;
$a196_rt = array();
for ( $i = 0; $i < 1300; $i++ ) { $a196_rt[] = array( 'public_id' => a196_hex( 32 ), 'order_id' => $a196_ret_order, 'ticket_type_id' => $a196_old, 'token_hash' => a196_hex( 64 ), 'status' => 'checked_in', 'attendee_email' => "ret$i@example.com", 'issued_at' => $a196_now ); }
$a196_rt_first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ticket_id),0) FROM {$p}wpcap_tickets" );
a196_insert( "{$p}wpcap_tickets", $a196_rt );
a196_insert( "{$p}wpcap_ticket_assignments", array_map( static fn( $tid ) => array( 'ticket_id' => $tid, 'buyer_email' => 'a196-ret@example.com', 'attendee_name' => 'Ret ' . $tid, 'attendee_email' => "ret$tid@example.com", 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array_map( 'intval', $wpdb->get_col( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE ticket_id>$a196_rt_first" ) ) ) );
$wpdb->insert( "{$p}wpcap_order_items", array( 'order_id' => $a196_ret_order, 'ticket_type_id' => $a196_old, 'quantity' => 1300, 'unit_price_minor' => 0, 'line_total_minor' => 0 ) );
CapacityCounters::reconcile();
$a196_sizes = array();
$a196_limits = array();
$a196_spy = static function ( $sql ) use ( &$a196_sizes, &$a196_limits ) {
	if ( preg_match_all( '/\bIN \(([0-9, ]+)\)/', $sql, $m ) ) { foreach ( $m[1] as $list ) { $a196_sizes[] = count( explode( ',', $list ) ); } }
	if ( preg_match( '/^\s*SELECT .* LIMIT (\d+)\s*$/s', $sql, $m ) ) { $a196_limits[] = (int) $m[1]; }
	return $sql;
};
add_filter( 'query', $a196_spy );
$a196_ret = Retention::run();
remove_filter( 'query', $a196_spy );
$a196_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_ticket_assignments WHERE ticket_id>$a196_rt_first AND (attendee_name<>'' OR attendee_email<>'')" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_id>$a196_rt_first AND attendee_email<>''" );
check( 'retention: 1300 attendee rows cleared in batches of at most ' . Retention::BATCH . ' (largest id list ' . max( array_merge( array( 0 ), $a196_sizes ) ) . ', largest LIMIT ' . max( array_merge( array( 0 ), $a196_limits ) ) . ')', $a196_ret['complete'] && 0 === $a196_left && $a196_ret['attendees'] >= 2600 && max( $a196_sizes ) <= Retention::BATCH && max( $a196_limits ) <= Retention::BATCH && count( array_filter( $a196_sizes, static fn( $n ) => Retention::BATCH === $n ) ) >= 4 );

/* ================================================================================ Multisite */
echo "A196: privacy tools on multisite (two site prefixes)\n";
$a196_a = $wpdb->prefix;
$a196_b = $wpdb->prefix . '2_';
foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a196_b, '_%\\' ) . '%' ) ) as $a196_t ) { $wpdb->query( "DROP TABLE `$a196_t`" ); }
foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a196_a . 'wpcap_', '_%\\' ) . '%' ) ) as $a196_t ) {
	$wpdb->query( 'CREATE TABLE `' . $a196_b . substr( $a196_t, strlen( $a196_a ) ) . "` LIKE `$a196_t`" );
}
$a196_ms_email = 'a196-ms@example.com';
$a196_seed = static function ( $prefix, $label ) use ( $wpdb, $a196_ms_email, $a196_now ) {
	$wpdb->insert( "{$prefix}wpcap_events", array( 'event_id' => 919600, 'name' => 'MS ' . $label, 'slug' => 'ms-' . strtolower( $label ) . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a196_now, 'updated_at' => $a196_now ) );
	$wpdb->insert( "{$prefix}wpcap_occurrences", array( 'occurrence_id' => 919600, 'event_id' => 919600, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => $label, 'capacity' => 0, 'status' => 'scheduled' ) );
	$wpdb->insert( "{$prefix}wpcap_ticket_types", array( 'ticket_type_id' => 919600, 'occurrence_id' => 919600, 'name' => 'MS', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
	$ids = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$wpdb->insert( "{$prefix}wpcap_orders", array( 'order_id' => 919600 + $i, 'public_id' => a196_hex( 32 ), 'idempotency_key' => a196_hex( 64 ), 'customer_email' => $a196_ms_email, 'currency' => 'EUR', 'price_snapshot' => '{}', 'status' => 'paid', 'payment_status' => 'paid', 'payment_provider' => 'manual', 'created_at' => $a196_now, 'updated_at' => $a196_now ) );
		$wpdb->insert( "{$prefix}wpcap_order_items", array( 'order_id' => 919600 + $i, 'ticket_type_id' => 919600, 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000 ) );
		$wpdb->insert( "{$prefix}wpcap_tickets", array( 'ticket_id' => 919600 + $i, 'public_id' => a196_hex( 32 ), 'order_id' => 919600 + $i, 'ticket_type_id' => 919600, 'token_hash' => a196_hex( 64 ), 'status' => 'valid', 'issued_at' => $a196_now ) );
		$wpdb->insert( "{$prefix}wpcap_ticket_assignments", array( 'ticket_id' => 919600 + $i, 'buyer_email' => $a196_ms_email, 'attendee_name' => $label . ' Gast ' . $i, 'created_at' => $a196_now ) );
		$ids[] = 919600 + $i;
	}
	$wpdb->insert( "{$prefix}wpcap_waitlist", array( 'event_id' => 919600, 'occurrence_id' => 919600, 'ticket_type_id' => 919600, 'email_hash' => hash( 'sha256', $a196_ms_email ), 'email' => $a196_ms_email, 'position' => 1, 'quantity' => 1, 'status' => 'waiting', 'created_at' => $a196_now, 'updated_at' => $a196_now ) );
	return $ids;
};
// Same ids on both sites (as on a real network, where every site counts from 1).
$a196_seed( $a196_a, 'A' );
$a196_switch = static function ( $to ) {
	global $wpdb;
	if ( $wpdb->prefix === $to ) { return; }
	$from_b = $wpdb->prefix !== $GLOBALS['a196_a'];
	$wpdb->prefix = $to;
	do_action( 'switch_blog', $from_b ? 1 : 2, $from_b ? 2 : 1, 'switch' );
};
$GLOBALS['a196_a'] = $a196_a;
$a196_switch( $a196_b );
$a196_seed( $a196_b, 'B' );
$a196_b_public = $wpdb->get_col( "SELECT public_id FROM {$a196_b}wpcap_orders ORDER BY order_id" );
$a196_switch( $a196_a );
CapacityCounters::reconcile();
$a196_ms_sql = array();
$a196_ms_spy = static function ( $sql ) use ( &$a196_ms_sql ) { $a196_ms_sql[] = $sql; return $sql; };
add_filter( 'query', $a196_ms_spy );
$a196_ms_er = a196_erase( array( Privacy::class, 'erase' ), $a196_ms_email );
remove_filter( 'query', $a196_ms_spy );
$a196_ms_count = static function ( $prefix, $table, $where ) use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}wpcap_{$table}` WHERE $where" ); };
$a196_q_email = "'" . esc_sql_a196( $a196_ms_email ) . "'";
check( 'multisite: erasure on site A anonymised site A (3 orders, names, waitlist)', $a196_ms_er['removed'] && 0 === $a196_ms_count( $a196_a, 'orders', "customer_email=$a196_q_email" ) && 0 === $a196_ms_count( $a196_a, 'ticket_assignments', "ticket_id BETWEEN 919600 AND 919602 AND attendee_name<>''" ) && 0 === $a196_ms_count( $a196_a, 'waitlist', "email=$a196_q_email" ) );
check( 'multisite: site B (same ids, same address) is untouched', 3 === $a196_ms_count( $a196_b, 'orders', "customer_email=$a196_q_email" ) && 3 === $a196_ms_count( $a196_b, 'ticket_assignments', "ticket_id BETWEEN 919600 AND 919602 AND attendee_name LIKE 'B Gast%'" ) && 3 === $a196_ms_count( $a196_b, 'tickets', "status='valid'" ) && 1 === $a196_ms_count( $a196_b, 'waitlist', "email=$a196_q_email" ) );
check( 'multisite: the eraser sent no statement to site B\'s tables or to the (network-wide) user meta', $a196_ms_sql && ! array_filter( $a196_ms_sql, static fn( $q ) => false !== strpos( $q, $a196_b ) || false !== stripos( $q, 'usermeta' ) ) );
$a196_switch( $a196_b );
$a196_ms_exp = a196_export( array( Privacy::class, 'export' ), $a196_ms_email );
$a196_ms_orders = array_values( array_filter( array_keys( $a196_ms_exp['items'] ), static fn( $k ) => 0 === strpos( $k, 'pass-captain-orders|' ) ) );
check( 'multisite: the export on site B lists site B\'s 3 orders (and only those)', 3 === count( $a196_ms_orders ) && ! array_diff( array_map( static fn( $id ) => 'pass-captain-orders|order-' . $id, $a196_b_public ), $a196_ms_orders ) );
$a196_switch( $a196_a );
$a196_ms_exp_a = Privacy::export( $a196_ms_email, 1 );
check( 'multisite: back on site A the export is empty', ! $a196_ms_exp_a['data'] && $a196_ms_exp_a['done'] );
foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $a196_b, '_%\\' ) . '%' ) ) as $a196_t ) { $wpdb->query( "DROP TABLE `$a196_t`" ); }
CapacityCounters::reconcile();

/* ================================================================================ WooCommerce */
echo "A196: WooCommerce orders in export, erasure and retention\n";
if ( ! has_action( 'woocommerce_privacy_remove_order_personal_data' ) ) { WooPrivacy::register(); }
$a196_wtype = make_type( 50, 10, 2000 );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $a196_wtype, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'purpose' => 'Namensschild', 'retention_days' => 0, 'status' => 'active' ) );
$a196_attendee = static function ( $type, $name, $email, $company ) { return array( 'ticket_type_id' => $type, 'name' => $name, 'email' => $email, 'answers' => array( 'firma' => $company ) ); };
// 1) Pending WooCommerce order: attendee payload still on the WooCommerce order.
$a196_wc1 = make_wc_order( 919601, $a196_wtype, 1, '20.00' );
$a196_wc1->email = 'a196-woo@example.com';
$a196_wc1->update_meta_data( A196Woo::ATTENDEE_META, array( $a196_attendee( $a196_wtype, 'Wanda Woo', 'wanda@example.com', 'WooCorp' ) ) );
$a196_core1 = A196Woo::reserve( $a196_wc1 );
$a196_wexp = WooPrivacy::export_order( array( array( 'name' => 'Bestellnummer', 'value' => '919601' ) ), $a196_wc1 );
check( 'WooCommerce exporter: the pending attendee payload is listed with WooCommerce\'s order data', is_int( $a196_core1 ) && 2 === count( $a196_wexp ) && false !== strpos( $a196_wexp[1]['value'], 'Wanda Woo' ) && false !== strpos( $a196_wexp[1]['value'], 'wanda@example.com' ) && false !== strpos( $a196_wexp[1]['value'], 'WooCorp' ) );
$a196_core_exp = Privacy::export( 'a196-woo@example.com', 1 );
check( 'core exporter: the WooCommerce-sourced order with its pending attendee details', false !== strpos( json_encode( $a196_core_exp['data'] ), 'Wanda Woo' ) );
// 2) Paid WooCommerce order: tickets issued, the payload moved to the tickets and deleted on the WooCommerce order.
$a196_wc2 = make_wc_order( 919602, $a196_wtype, 1, '20.00' );
$a196_wc2->email = 'a196-woo@example.com';
$a196_wc2->update_meta_data( A196Woo::ATTENDEE_META, array( $a196_attendee( $a196_wtype, 'Wendy Woo', 'wendy@example.com', 'WooTwo' ) ) );
$a196_core2 = A196Woo::reserve( $a196_wc2 );
$a196_wc2->paid = true;
A196Woo::sync_paid_order( 919602 );
$a196_wendy = p1p_export_values_a196( Privacy::export( 'wendy@example.com', 1 ) );
check( 'paid WooCommerce order: payload left the WooCommerce order, the attendee export finds the ticket', '' === $a196_wc2->get_meta( A196Woo::ATTENDEE_META, true ) && false !== strpos( $a196_wendy, 'Wendy Woo' ) && false !== strpos( $a196_wendy, 'WooTwo' ) );
// 3) WooCommerce's own eraser / order retention (woocommerce_privacy_remove_order_personal_data).
$a196_wc3 = make_wc_order( 919603, $a196_wtype, 1, '20.00' );
$a196_wc3->update_meta_data( A196Woo::ATTENDEE_META, array( $a196_attendee( $a196_wtype, 'Willa Woo', '', 'W3' ) ) );
do_action( 'woocommerce_privacy_remove_order_personal_data', $a196_wc3 );
check( 'WooCommerce eraser/retention hook removes the attendee payload', '' === $a196_wc3->get_meta( A196Woo::ATTENDEE_META, true ) );
// 4) The plugin eraser: anonymising the core order also clears the WooCommerce copy (WooCommerce's
// "remove personal data from orders" setting may be off). The paid order's event is over.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d)", gmdate( 'Y-m-d H:i:s', time() - 10 * 86400 ), $a196_wtype ) );
$a196_wc1->update_meta_data( A196Woo::ATTENDEE_META, array( $a196_attendee( $a196_wtype, 'Wanda Woo', 'wanda@example.com', 'WooCorp' ) ) );
$a196_wer = a196_erase( array( Privacy::class, 'erase' ), 'a196-woo@example.com' );
check( 'plugin eraser: both WooCommerce-sourced core orders anonymised, the payload on the pending WooCommerce order removed', $a196_wer['removed'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email='a196-woo@example.com'" ) && null === Orders::get( $a196_core1 )->attendee_data && '' === $a196_wc1->get_meta( A196Woo::ATTENDEE_META, true ) );
check( 'plugin eraser: the attendee export of the paid order is empty afterwards', false === strpos( p1p_export_values_a196( Privacy::export( 'wendy@example.com', 1 ) ), 'Wendy Woo' ) );
// 5) Retention: an unpaid WooCommerce order expired two days ago loses its attendee details in both places.
$a196_wc4 = make_wc_order( 919604, $a196_wtype, 1, '20.00' );
$a196_wc4->email = 'a196-woo4@example.com';
$a196_wc4->update_meta_data( A196Woo::ATTENDEE_META, array( $a196_attendee( $a196_wtype, 'Wilma Woo', 'wilma@example.com', 'W4' ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d)", gmdate( 'Y-m-d H:i:s', time() + 10 * 86400 ), $a196_wtype ) );
$a196_core4 = A196Woo::reserve( $a196_wc4 );
Orders::cancel_order( $a196_core4, 'a196' );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET payment_status='expired',status='expired',updated_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ), $a196_core4 ) );
$a196_had = null !== Orders::get( $a196_core4 )->attendee_data && '' !== $a196_wc4->get_meta( A196Woo::ATTENDEE_META, true );
Retention::run();
check( 'retention: unpaid WooCommerce order – attendee details cleared on the core order and on the WooCommerce order', $a196_had && null === Orders::get( $a196_core4 )->attendee_data && '' === $a196_wc4->get_meta( A196Woo::ATTENDEE_META, true ) );

$GLOBALS['wpcap_test_filters'] = $a196_filters_before;
$GLOBALS['wpcap_test_transients'] = array();
CapacityCounters::reconcile();

/** Name=value lines of an export page (like p1p_export_values() of zz_phase1_privacy.php, which runs later). */
function p1p_export_values_a196( array $export ) {
	$out = array();
	foreach ( $export['data'] as $item ) { foreach ( $item['data'] as $row ) { $out[] = $row['name'] . '=' . $row['value']; } }
	return implode( "\n", $out );
}
