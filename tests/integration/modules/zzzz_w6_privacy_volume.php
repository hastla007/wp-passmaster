<?php
/**
 * Privacy export, erasure and retention at volume (W6).
 *
 * One buyer with many orders (default 400, WPCAP_TEST_SLOW=1: 5000) across past and upcoming dates,
 * native and WooCommerce-linked orders, refunds, invoices, attendee-only tickets in other buyers'
 * orders, plus unrelated buyers and a second site (own table prefix) with the same address.
 *
 * Export and erasure are paged like the WordPress privacy tools; both must finish within a time
 * budget, keep every financial record (amounts, tax, items, refunds, invoices), defer orders with
 * valid tickets for upcoming dates (tickets stay valid) and touch nothing of other buyers or sites.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Privacy;
use PassCaptain\Privacy\Retention;

global $wpdb;
$p = $wpdb->prefix;
$w6p_slow = (bool) getenv( 'WPCAP_TEST_SLOW' );
$w6p_n = $w6p_slow ? 5000 : 400;
$w6p_budget = $w6p_slow ? 120.0 : 15.0;
$w6p_home = $p;

/** Bulk fixture for one site; returns ids and the financial checksum of the buyer's orders. */
function w6p_fixture( $email, $n, $tag ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$types = array();
	foreach ( array( 'past' => -30, 'upcoming' => 30 ) as $kind => $days ) {
		$wpdb->insert( "{$p}wpcap_events", array( 'name' => "W6 privacy $kind", 'slug' => "w6p-$tag-$kind-" . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$event = (int) $wpdb->insert_id;
		$start = gmdate( 'Y-m-d H:i:s', time() + $days * 86400 );
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $start, 'ends_at' => gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + 7200 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 1190, 'currency' => 'EUR', 'capacity' => 0, 'max_per_order' => 10, 'status' => 'active', 'tax_rate_bp' => 1900 ) );
		$types[ $kind ] = (int) $wpdb->insert_id;
	}
	$first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_id),0) FROM {$p}wpcap_orders" ) + 1;
	$rows = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$wc = 0 === $i % 5;
		$rows[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%s,%s,%d,%d,%d,%d,%s,%s,%s,%s,%s)', bin2hex( random_bytes( 16 ) ), hash( 'sha256', "$tag-$i-" . uniqid( '', true ) ), $email, hash( 'sha256', "a$i$tag" ), 'complete', 'paid', 'EUR', 2380, 2380, 380, 0 === $i % 7 ? 1190 : 0, '{}', $wc ? 'woocommerce' : 'manual', '{"company":"ACME"}', $now, $now );
	}
	foreach ( array_chunk( $rows, 500 ) as $chunk ) {
		$wpdb->query( "INSERT INTO {$p}wpcap_orders (public_id,idempotency_key,customer_email,access_token_hash,status,payment_status,currency,total_minor,subtotal_minor,tax_minor,refunded_minor,price_snapshot,payment_provider,billing,created_at,updated_at) VALUES " . implode( ',', $chunk ) );
	}
	$last = $first + $n - 1;
	$order_ids = array_map( 'intval', $wpdb->get_col( "SELECT order_id FROM {$p}wpcap_orders WHERE customer_email='" . esc_sql_w6( $email ) . "' AND order_id BETWEEN $first AND $last ORDER BY order_id" ) );
	$items = array();
	$tickets = array();
	$refunds = array();
	$invoices = array();
	foreach ( $order_ids as $k => $oid ) {
		$kind = 0 === $k % 4 ? 'upcoming' : 'past';
		$type = $types[ $kind ];
		$items[] = "($oid,$type,2,1190,2380,1900,380," . ( 0 === $k % 7 ? 1 : 0 ) . ')';
		for ( $t = 0; $t < 2; $t++ ) {
			$status = 'past' === $kind && 0 === $t ? 'checked_in' : 'valid';
			$tickets[] = $wpdb->prepare( '(%s,%d,%d,%s,%s,%s,%s)', bin2hex( random_bytes( 16 ) ), $oid, $type, hash( 'sha256', random_bytes( 16 ) ), $status, $now, 0 === $t ? $email : '' );
		}
		if ( 0 === $k % 7 ) { $refunds[] = $wpdb->prepare( '(%d,%s,1190,190,%s,%s,%s,%s,1,1,%s,%s)', $oid, hash( 'sha256', "r$oid$tag" ), 'EUR', '[]', 'w6', 'completed', $now, $now ); }
		if ( 0 === $k % 3 ) { $invoices[] = $wpdb->prepare( '(%d,%s,%s,2000,380,2380,%s,%s,%s)', $oid, "W6-$tag-$oid", 'EUR', wp_json_encode( array( 'email' => $email ) ), $now, 'issued' ); }
	}
	foreach ( array_chunk( $items, 500 ) as $c ) { $wpdb->query( "INSERT INTO {$p}wpcap_order_items (order_id,ticket_type_id,quantity,unit_price_minor,line_total_minor,tax_rate_bp,tax_minor,refunded_quantity) VALUES " . implode( ',', $c ) ); }
	foreach ( array_chunk( $tickets, 500 ) as $c ) { $wpdb->query( "INSERT INTO {$p}wpcap_tickets (public_id,order_id,ticket_type_id,token_hash,status,issued_at,attendee_email) VALUES " . implode( ',', $c ) ); }
	foreach ( array_chunk( $refunds, 500 ) as $c ) { $wpdb->query( "INSERT INTO {$p}wpcap_refunds (order_id,idempotency_key,amount_minor,tax_minor,currency,ticket_ids,reason,status,requested_by,approved_by,created_at,updated_at) VALUES " . implode( ',', $c ) ); }
	foreach ( array_chunk( $invoices, 500 ) as $c ) { $wpdb->query( "INSERT INTO {$p}wpcap_invoices (order_id,invoice_number,currency,subtotal_minor,tax_minor,total_minor,buyer_snapshot,issued_at,status) VALUES " . implode( ',', $c ) ); }
	// Another buyer's order with a ticket for this person as attendee (past date).
	$wpdb->insert( "{$p}wpcap_orders", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'idempotency_key' => hash( 'sha256', "other-$tag" . uniqid() ), 'customer_email' => "friend-$tag@example.com", 'access_token_hash' => hash( 'sha256', 'f' ), 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'EUR', 'total_minor' => 1190, 'subtotal_minor' => 1190, 'price_snapshot' => '{}', 'payment_provider' => 'manual', 'created_at' => $now, 'updated_at' => $now ) );
	$friend_order = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_tickets", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'order_id' => $friend_order, 'ticket_type_id' => $types['upcoming'], 'token_hash' => hash( 'sha256', random_bytes( 16 ) ), 'status' => 'valid', 'issued_at' => $now, 'attendee_email' => $email ) );
	$friend_ticket = (int) $wpdb->insert_id;
	return array( 'orders' => $order_ids, 'types' => $types, 'friend_order' => $friend_order, 'friend_ticket' => $friend_ticket );
}
function esc_sql_w6( $s ) { return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $s ); }
/** Amounts, tax, items, refunds and invoices of a set of orders. */
function w6p_finance( array $order_ids ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$in = $order_ids ? implode( ',', array_map( 'intval', $order_ids ) ) : '0';
	return implode( '|', array(
		$wpdb->get_var( "SELECT CONCAT(COUNT(*),':',SUM(total_minor),':',SUM(tax_minor),':',SUM(refunded_minor),':',SUM(subtotal_minor)) FROM {$p}wpcap_orders WHERE order_id IN ($in)" ),
		$wpdb->get_var( "SELECT CONCAT(COUNT(*),':',SUM(line_total_minor),':',SUM(tax_minor),':',SUM(refunded_quantity)) FROM {$p}wpcap_order_items WHERE order_id IN ($in)" ),
		$wpdb->get_var( "SELECT CONCAT(COUNT(*),':',SUM(amount_minor),':',SUM(tax_minor)) FROM {$p}wpcap_refunds WHERE order_id IN ($in)" ),
		$wpdb->get_var( "SELECT CONCAT(COUNT(*),':',SUM(total_minor),':',MD5(GROUP_CONCAT(buyer_snapshot ORDER BY invoice_id))) FROM {$p}wpcap_invoices WHERE order_id IN ($in)" ),
		$wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN ($in)" ),
	) );
}

echo "W6 privacy at volume ($w6p_n orders)\n";
$email = 'w6-volume-' . uniqid() . '@example.com';
update_option( Privacy::DEFERRED_OPTION, array() );
$t0 = microtime( true );
$fx = w6p_fixture( $email, $w6p_n, 'a' );
$noise = w6p_fixture( 'w6-other-' . uniqid() . '@example.com', 50, 'n' );
$fixture_s = microtime( true ) - $t0;
$finance_before = w6p_finance( $fx['orders'] );
$noise_before = w6p_finance( $noise['orders'] ) . $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(customer_email,access_token_hash ORDER BY order_id)) FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $noise['orders'] ) . ')' );
check( "fixture: $w6p_n orders, " . ( 2 * $w6p_n ) . ' tickets, refunds, invoices (' . round( $fixture_s, 1 ) . ' s)', count( $fx['orders'] ) === $w6p_n );

// Second site with the same address.
$w6p_b = 'w6pb_';
foreach ( $wpdb->get_col( "SHOW TABLES LIKE 'w6pb\\_%'" ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }
$wpdb->prefix = $w6p_b;
PassCaptain\Installer::flush_version_cache();
wpcap_test_install();
$fx_b = w6p_fixture( $email, 20, 'b' );
$site_b_before = w6p_finance( $fx_b['orders'] ) . $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(customer_email ORDER BY order_id)) FROM {$w6p_b}wpcap_orders" );
$wpdb->prefix = $w6p_home;
PassCaptain\Installer::flush_version_cache();

// Export, paged like the WordPress exporter.
$t0 = microtime( true );
$page = 1;
$refs = 0;
$attendee_items = 0;
do {
	$res = Privacy::export( $email, $page++ );
	foreach ( $res['data'] as $group ) {
		foreach ( $group['data'] as $row ) { if ( 'Bestellreferenz' === $row['name'] ) { ++$refs; } }
		if ( false !== strpos( (string) ( $group['group_id'] ?? '' ), 'attendee' ) ) { ++$attendee_items; }
	}
} while ( empty( $res['done'] ) && $page < 10000 );
$export_s = microtime( true ) - $t0;
check( "export pages through all $w6p_n orders within the budget (" . round( $export_s, 2 ) . " s, $page pages)", $w6p_n === $refs && $export_s < $w6p_budget );

// Erasure, paged like the WordPress eraser.
$t0 = microtime( true );
$page = 1;
$retained = false;
do {
	$res = Privacy::erase( $email, $page++ );
	$retained = $retained || ! empty( $res['items_retained'] );
} while ( empty( $res['done'] ) && $page < 10000 );
$erase_s = microtime( true ) - $t0;
$in = implode( ',', $fx['orders'] );
$left_with_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email='" . esc_sql_w6( $email ) . "'" );
$deferred = Privacy::deferred();
$upcoming_orders = array_values( array_filter( $fx['orders'], static fn( $id, $k ) => 0 === $k % 4, ARRAY_FILTER_USE_BOTH ) );
$upcoming_valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE order_id IN (" . implode( ',', $upcoming_orders ) . ") AND status='valid'" );
$past_valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.order_id IN ($in) AND tt.ticket_type_id=" . (int) $fx['types']['past'] . " AND t.status='valid'" );
check( "erasure finishes within the budget (" . round( $erase_s, 2 ) . " s, $page pages) and reports retained financial records", $erase_s < $w6p_budget && $retained );
check( 'past orders anonymised; upcoming orders deferred with their tickets still valid', $left_with_email === count( $upcoming_orders ) && count( $deferred ) === count( $upcoming_orders ) && 2 * count( $upcoming_orders ) === $upcoming_valid && 0 === $past_valid );
check( 'financial records unchanged: amounts, VAT, items, refunds, invoices (incl. buyer snapshot), ticket rows', $finance_before === w6p_finance( $fx['orders'] ) );
check( 'billing data and access links of anonymised orders removed', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE order_id IN ($in) AND billing IS NOT NULL AND customer_email<>'" . esc_sql_w6( $email ) . "'" ) );
check( "attendee data in another buyer's order removed, the ticket stays valid", '' === $wpdb->get_var( "SELECT attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $fx['friend_ticket'] ) && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $fx['friend_ticket'] ) );
check( 'other buyers untouched', $noise_before === w6p_finance( $noise['orders'] ) . $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(customer_email,access_token_hash ORDER BY order_id)) FROM {$p}wpcap_orders WHERE order_id IN (" . implode( ',', $noise['orders'] ) . ')' ) );
check( 'the same address on another site (multisite prefix) is untouched', $site_b_before === ( static function () use ( $w6p_b, $fx_b, $w6p_home ) { global $wpdb; $wpdb->prefix = $w6p_b; $v = w6p_finance( $fx_b['orders'] ) . $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(customer_email ORDER BY order_id)) FROM {$w6p_b}wpcap_orders" ); $wpdb->prefix = $w6p_home; return $v; } )() );

// After the last date the deferred orders are anonymised automatically.
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 ) . "', ends_at='" . gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) . "' WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $fx['types']['upcoming'] . ')' );
$t0 = microtime( true );
Privacy::process_deferred();
$deferred_s = microtime( true ) - $t0;
check( 'deferred orders are anonymised after their last date (' . round( $deferred_s, 2 ) . ' s), financial records still unchanged', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email='" . esc_sql_w6( $email ) . "'" ) && ! Privacy::deferred() && $finance_before === w6p_finance( $fx['orders'] ) );

// Retention run over the whole volume.
$t0 = microtime( true );
$counts = Retention::run( time() + 3 * 365 * 86400, $w6p_budget );
$retention_s = microtime( true ) - $t0;
check( 'retention run completes within its budget (' . round( $retention_s, 2 ) . ' s) and keeps financial records', ! empty( $counts['complete'] ) && $finance_before === w6p_finance( $fx['orders'] ) );

foreach ( $wpdb->get_col( "SHOW TABLES LIKE 'w6pb\\_%'" ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }
update_option( Privacy::DEFERRED_OPTION, array() );
