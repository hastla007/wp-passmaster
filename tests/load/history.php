<?php
/**
 * History benchmark (A429, A430, A444): seeds a long sales history (default 500,000 reservations
 * and orders, 100,000 jobs, seat holds and payment events) and measures the checkout capacity
 * check, the capacity reconcile cron and the hourly cleanup (Retention::housekeeping()).
 *
 * A438: `seed-admin` adds the history of the admin side on top of `seed` (one ticket per paid order,
 * check-ins of the past dates, webhook deliveries, refunds, Mollie/Stripe references, an organizer
 * with settled months, reseller API orders); `admin` measures the Mollie webhook lookup, ticket
 * list, check-in log and (with `purge`) the webhook log cleanup, `reports` the sales report,
 * accounting/DATEV export (also streamed), occurrence/organizer settlement and reseller statement
 * (time, queries, peak memory), `bulk` a batch PDF and the cancellation of the busiest date (changes
 * the data: run it last).
 *
 * Usage:  php tests/load/history.php seed|seed-admin|measure|raw|cleanup|admin [purge]|reports|bulk|drop
 *         WPP6_N=100000 php tests/load/history.php seed     (smaller data set; 30 s per order, so
 *         WPP6_N=1000000 is about one year of sales)
 * DB connection as for the integration tests; tables use the prefix WPCAP_TEST_PREFIX (default
 * "wphist_"), `seed` recreates them, `drop` removes them. Results: docs/TESTING.md.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile
if ( false === getenv( 'WPCAP_TEST_PREFIX' ) ) { putenv( 'WPCAP_TEST_PREFIX=wphist_' ); }
require dirname( __DIR__ ) . '/integration/bootstrap.php';

global $wpdb;
$p = $wpdb->prefix;
$mode = $argv[1] ?? 'measure';
$N = (int) ( getenv( 'WPP6_N' ) ?: 500000 );

function t( callable $fn, $runs = 5 ) {
	$best = INF; $res = null;
	for ( $i = 0; $i < $runs; $i++ ) { $s = microtime( true ); $res = $fn(); $best = min( $best, microtime( true ) - $s ); }
	return array( round( $best * 1000, 1 ), $res );
}
function explain( $sql ) {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql ) as $r ) { $out[] = sprintf( '%s:%s key=%s rows=%s', $r->table, $r->type, $r->key ?? '-', $r->rows ); }
	return implode( ' | ', $out );
}

if ( 'drop' === $mode ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . '%' ) ) as $tbl ) { $wpdb->query( "DROP TABLE `$tbl`" ); }
	echo "dropped\n";
	exit;
}

if ( 'seed' === $mode ) {
	wpcap_test_install();
	$now = time();
	$wpdb->query( "SET SESSION sql_log_bin=0" );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Bench', 'slug' => 'bench', 'description' => '', 'status' => 'published', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$event = (int) $wpdb->insert_id;
	$types = array();
	for ( $o = 0; $o < 60; $o++ ) {
		$start = $now + ( $o - 50 ) * 7 * DAY_IN_SECONDS; // 50 past, 10 upcoming
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'timezone' => 'Europe/Vienna', 'location' => 'X', 'capacity' => 100000, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		for ( $k = 0; $k < 3; $k++ ) {
			$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'T' . $k, 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 100000, 'max_per_order' => 10, 'status' => 'active' ) );
			$types[] = (int) $wpdb->insert_id;
		}
	}
	$hot = end( $types ); // upcoming type with a long history (on-sale since months)
	$wpdb->query( "CREATE TEMPORARY TABLE seq (n int unsigned PRIMARY KEY)" );
	$wpdb->query( 'INSERT INTO seq VALUES ' . implode( ',', array_map( static fn( $i ) => "($i)", range( 0, 999 ) ) ) );
	$wpdb->query( 'START TRANSACTION' );
	$batch = 1000;
	$typecount = count( $types );
	for ( $b = 0; $b < $N / $batch; $b++ ) {
		$base = $b * $batch;
		// Orders: one per reservation; 85 % paid, 15 % expired.
		$wpdb->query( "INSERT INTO {$p}wpcap_orders (order_id,public_id,idempotency_key,customer_email,status,payment_status,currency,price_snapshot,created_at,updated_at)
			SELECT $base+n+1, MD5(CONCAT('o',$base+n)), SHA2(CONCAT('k',$base+n),256), CONCAT('b',$base+n,'@example.com'),
			IF(($base+n)%20<17,'complete','expired'), IF(($base+n)%20<17,'paid','expired'), 'EUR','{}',
			FROM_UNIXTIME($now - 200*86400 + ($base+n)*30), FROM_UNIXTIME($now - 200*86400 + ($base+n)*30) FROM seq" );
		// Hot type gets 40 % of the history, the rest spread over all types.
		$wpdb->query( "INSERT INTO {$p}wpcap_order_items (order_id,ticket_type_id,quantity,unit_price_minor,line_total_minor,counted_qty)
			SELECT $base+n+1, IF(($base+n)%5<2,$hot,ELT(1+(($base+n)%$typecount),". implode( ',', $types ) . ")), 1, 1000, 1000, IF(($base+n)%20<17,1,0) FROM seq" );
		$wpdb->query( "INSERT INTO {$p}wpcap_reservations (order_id,ticket_type_id,quantity,expires_at,status,counted_qty)
			SELECT $base+n+1, IF(($base+n)%5<2,$hot,ELT(1+(($base+n)%$typecount),". implode( ',', $types ) . ")), 1,
			FROM_UNIXTIME($now - 200*86400 + ($base+n)*30 + 900), IF(($base+n)%20<17,'converted',IF(($base+n)%20<19,'expired','released')), 0 FROM seq" );
		if ( 0 === $b % 50 ) { $wpdb->query( 'COMMIT' ); $wpdb->query( 'START TRANSACTION' ); }
	}
	$wpdb->query( 'COMMIT' );
	// 300 live holds on the hot type.
	$wpdb->query( "UPDATE {$p}wpcap_reservations SET status='active',counted_qty=quantity,expires_at=" . "FROM_UNIXTIME($now+600)" . " ORDER BY reservation_id DESC LIMIT 300" );
	$wpdb->query( "UPDATE {$p}wpcap_orders o JOIN {$p}wpcap_reservations r ON r.order_id=o.order_id SET o.status='pending_payment',o.payment_status='pending' WHERE r.status='active'" );
	$wpdb->query( "UPDATE {$p}wpcap_order_items oi JOIN {$p}wpcap_reservations r ON r.order_id=oi.order_id SET oi.counted_qty=0 WHERE r.status='active'" );
	// Counters from the source tables, then "ready".
	foreach ( $wpdb->get_col( "SELECT occurrence_id FROM {$p}wpcap_occurrences" ) as $occ ) { PassCaptain\CapacityCounters::rebuild_occurrence( (int) $occ ); }
	// Jobs, seat holds, payment events history.
	$wpdb->query( "INSERT INTO {$p}wpcap_jobs (idempotency_key,job_type,payload,run_after,status,attempts,created_at,updated_at) SELECT SHA2(CONCAT('j',a.n*1000+b.n),256),'ticket_mail_retry','{}',FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),'complete',1,FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170) FROM seq a JOIN seq b WHERE a.n<100" );
	$wpdb->query( "INSERT INTO {$p}wpcap_seat_holds (occurrence_id,seat_id,order_id,request_key,expires_at,status) SELECT 1,b.n+1,a.n*1000+b.n+1,SHA2(CONCAT('s',a.n*1000+b.n),256),FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),IF(b.n%3=0,'sold','expired') FROM seq a JOIN seq b WHERE a.n<100" );
	$wpdb->query( "INSERT INTO {$p}wpcap_payment_events (provider,provider_event_id,event_type,order_id,status,created_at,updated_at) SELECT 'stripe',CONCAT('evt_',a.n*1000+b.n),'paid',a.n*1000+b.n+1,'processed',FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170),FROM_UNIXTIME($now-200*86400+(a.n*1000+b.n)*170) FROM seq a JOIN seq b WHERE a.n<100" );
	$wpdb->query( "ANALYZE TABLE {$p}wpcap_reservations, {$p}wpcap_orders, {$p}wpcap_order_items, {$p}wpcap_jobs, {$p}wpcap_seat_holds, {$p}wpcap_payment_events" );
	echo 'seeded: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations" ) . " reservations, hot type $hot\n";
	exit;
}

/**
 * A438: years of operation on top of `seed` – one ticket per paid order, check-ins of the past dates
 * (plus rejected scans), webhook deliveries, completed refunds, Mollie/Stripe payment references, an
 * organizer owning the event with all but the last two months settled, and reseller API orders.
 */
if ( 'seed-admin' === $mode ) {
	$now = time();
	$wpdb->query( "SET SESSION sql_log_bin=0" );
	$event = (int) $wpdb->get_var( "SELECT MIN(event_id) FROM {$p}wpcap_events" );
	// The sales history ends yesterday (seed spreads it from 200 days ago in 30 s steps).
	$shift = max( 0, $now - DAY_IN_SECONDS - (int) $wpdb->get_var( "SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM {$p}wpcap_orders" ) );
	$wpdb->query( "UPDATE {$p}wpcap_orders SET created_at=DATE_ADD(created_at,INTERVAL $shift SECOND),updated_at=DATE_ADD(updated_at,INTERVAL $shift SECOND)" );
	$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider=IF(order_id%10<3,'mollie','stripe'),provider_reference=IF(order_id%10<3,CONCAT('tr_',LPAD(order_id,10,'0')),CONCAT('pi_',MD5(order_id))),total_minor=1000,subtotal_minor=1000 WHERE payment_status='paid'" );
	$wpdb->query( "INSERT INTO {$p}wpcap_tickets (public_id,order_id,ticket_type_id,token_hash,attendee_email,status,issued_at)
		SELECT MD5(CONCAT('t',oi.order_id)),oi.order_id,oi.ticket_type_id,SHA2(CONCAT('tk',oi.order_id),256),'',IF(oc.starts_at<NOW(),'checked_in','valid'),ord.created_at
		FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences oc ON oc.occurrence_id=tt.occurrence_id WHERE ord.payment_status='paid'" );
	// Check-ins: every ticket of a past date once, every 10th also rejected once ("already_checked_in").
	$wpdb->query( "INSERT INTO {$p}wpcap_checkins (ticket_id,event_id,user_id,result,source,created_at)
		SELECT t.ticket_id,$event,1,'checked_in','app',DATE_ADD(oc.starts_at,INTERVAL t.ticket_id%7200 SECOND) FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences oc ON oc.occurrence_id=tt.occurrence_id WHERE t.status='checked_in'" );
	$wpdb->query( "INSERT INTO {$p}wpcap_checkins (ticket_id,event_id,user_id,result,source,created_at)
		SELECT ticket_id,event_id,1,'already_checked_in','app',DATE_ADD(created_at,INTERVAL 60 SECOND) FROM {$p}wpcap_checkins WHERE checkin_id%10=0" );
	// Webhook deliveries: one per paid order over the whole history, all delivered.
	$wpdb->insert( "{$p}wpcap_webhook_subscriptions", array( 'url' => 'https://example.com/hook', 'events' => '*', 'secret' => 's', 'status' => 'active', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$sub = (int) $wpdb->insert_id;
	$wpdb->query( "INSERT INTO {$p}wpcap_webhook_deliveries (subscription_id,event_id,event_type,payload,status,attempts,response_code,next_attempt_at,delivered_at,created_at,updated_at)
		SELECT $sub,CONCAT('evt_',order_id),'order.paid','{}','succeeded',1,200,created_at,created_at,created_at,created_at FROM {$p}wpcap_orders WHERE payment_status='paid'" );
	// Completed refunds: 3 % of the paid orders, one day after the sale.
	$wpdb->query( "INSERT INTO {$p}wpcap_refunds (order_id,idempotency_key,amount_minor,tax_minor,currency,ticket_ids,status,source,created_at,updated_at)
		SELECT order_id,SHA2(CONCAT('r',order_id),256),1000,0,'EUR','[]','completed','admin',DATE_ADD(created_at,INTERVAL 1 DAY),DATE_ADD(created_at,INTERVAL 1 DAY) FROM {$p}wpcap_orders WHERE payment_status='paid' AND order_id%33=0" );
	$wpdb->query( "UPDATE {$p}wpcap_orders o INNER JOIN {$p}wpcap_refunds r ON r.order_id=o.order_id SET o.payment_status='refunded',o.refunded_minor=1000" );
	// Organizer of the event (settlement start before the history), everything older than two months settled.
	$partner = PassCaptain\Partners\Repository::save( array( 'name' => 'Bench Organizer', 'currency' => 'EUR', 'commission_bp' => 500, 'fee_per_ticket_minor' => 50, 'fee_tax_rate_bp' => 2000, 'settlement_start' => gmdate( 'Y-m-d', $now - 400 * DAY_IN_SECONDS ) ) );
	PassCaptain\Partners\Repository::set_events( $partner, array( $event ) );
	$wpdb->query( "UPDATE {$p}wpcap_partner_event_history SET valid_from=NULL WHERE partner_id=" . (int) $partner );
	$cut = gmdate( 'Y-m-01 00:00:00', strtotime( gmdate( 'Y-m-01', $now ) . ' -1 month' ) );
	$wpdb->insert( "{$p}wpcap_partner_settlements", array( 'partner_id' => $partner, 'period' => gmdate( 'Y-m', strtotime( $cut ) - DAY_IN_SECONDS ), 'period_start' => '2000-01-01 00:00:00', 'period_end' => $cut, 'status' => 'finalized', 'currency' => 'EUR', 'totals' => '{}', 'created_at' => $cut, 'updated_at' => $cut ) );
	$settlement = (int) $wpdb->insert_id;
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_partner_settlement_lines (settlement_id,partner_id,kind,item_key,source_key,order_id,item_id,event_id,occurred_at,tickets,gross_minor)
		SELECT %d,%d,'sale',CONCAT('sale:',oi.item_id),CONCAT('sale:',oi.item_id),oi.order_id,oi.item_id,%d,o.created_at,1,1000 FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE o.payment_status IN ('paid','refunded') AND o.created_at < %s", $settlement, $partner, $event, $cut ) );
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_partner_settlement_lines (settlement_id,partner_id,kind,item_key,source_key,order_id,refund_id,item_id,event_id,occurred_at,tickets,gross_minor)
		SELECT %d,%d,'refund',CONCAT('refund:',r.refund_id,':',oi.item_id),CONCAT('refund:',r.refund_id,':',oi.item_id),r.order_id,r.refund_id,oi.item_id,%d,r.updated_at,-1,-1000 FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_order_items oi ON oi.order_id=r.order_id WHERE r.updated_at < %s", $settlement, $partner, $event, $cut ) );
	// Reseller API orders: every 10th paid order was sold by the organizer through the API.
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_partner_orders (order_id,partner_id,mode,confirmed_at,created_at) SELECT order_id,%d,'sell',created_at,created_at FROM {$p}wpcap_orders WHERE payment_status IN ('paid','refunded') AND order_id%%10=5", $partner ) );
	$wpdb->query( "ANALYZE TABLE {$p}wpcap_orders, {$p}wpcap_tickets, {$p}wpcap_checkins, {$p}wpcap_webhook_deliveries, {$p}wpcap_refunds, {$p}wpcap_partner_settlement_lines, {$p}wpcap_partner_orders" );
	foreach ( array( 'wpcap_tickets', 'wpcap_checkins', 'wpcap_webhook_deliveries', 'wpcap_refunds', 'wpcap_partner_settlement_lines', 'wpcap_partner_orders' ) as $tbl ) { echo "$tbl: " . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}$tbl" ) . "\n"; }
	echo "partner $partner\n";
	exit;
}

/** Runs $fn once (after a warm-up unless $warm is false): ms, queries, peak memory delta (MB), result. */
function measure_op( callable $fn, $warm = true ) {
	global $wpdb;
	if ( $warm ) { $fn(); }
	gc_collect_cycles();
	memory_reset_peak_usage();
	$base = memory_get_usage();
	$q = $wpdb->num_queries;
	$s = microtime( true );
	$res = $fn();
	return array( round( ( microtime( true ) - $s ) * 1000, 1 ), $wpdb->num_queries - $q, round( ( memory_get_peak_usage() - $base ) / 1048576, 1 ), $res );
}
function report_op( $label, array $m, $extra = '' ) {
	printf( "%-44s %9.1f ms %7d queries %7.1f MB %s\n", $label, $m[0], $m[1], $m[2], $extra );
}

if ( 'admin' === $mode ) {
	// A431, A432, A433, A437: Mollie webhook lookup, ticket list, check-in log, webhook cleanup.
	report_op( 'Mollie known_payment (unknown id)', measure_op( static fn() => PassCaptain\Mollie\MollieGateway::known_payment( 'tr_Unknown1234' ) ) );
	report_op( 'Mollie known_payment (legacy order ref)', measure_op( static fn() => PassCaptain\Mollie\MollieGateway::known_payment( 'tr_0000000010' ) ) );
	foreach ( array( 1, 50 ) as $page ) {
		$m = measure_op( static fn() => PassCaptain\Admin\Queries::search_tickets( array(), null, $page, 30 ) );
		report_op( "Ticket list page $page (30 rows)", $m, 'total=' . $m[3]['total'] . ' checked_in=' . count( array_filter( $m[3]['rows'], static fn( $r ) => null !== $r->last_checkin_at ) ) );
	}
	$m = measure_op( static fn() => PassCaptain\Admin\Queries::search_tickets( array( 'status' => 'checked_in' ), null, 1, 30 ) );
	report_op( 'Ticket list status=checked_in', $m, 'total=' . $m[3]['total'] );
	foreach ( array( 1, 100 ) as $page ) {
		$m = measure_op( static fn() => PassCaptain\Admin\Queries::checkin_log( array(), null, $page, 50 ) );
		report_op( "Check-in log page $page (50 rows)", $m, 'total=' . $m[3]['total'] );
	}
	$event = (int) $wpdb->get_var( "SELECT MIN(event_id) FROM {$p}wpcap_events" );
	$m = measure_op( static fn() => PassCaptain\Admin\Queries::checkin_log( array( 'event_id' => $event, 'result' => 'checked_in', 'date_from' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), null, 1, 50 ) );
	report_op( 'Check-in log event+result+30 days', $m, 'total=' . $m[3]['total'] );
	report_op( 'Check-in result filter values', measure_op( static fn() => PassCaptain\Admin\Queries::checkin_results() ) );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
	echo 'webhook deliveries older than 30 days: ' . $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_webhook_deliveries WHERE updated_at<%s", $cutoff ) ) . "\n";
	if ( in_array( 'purge', $argv, true ) ) {
		$runs = 0;
		do {
			$m = measure_op( static fn() => PassCaptain\Webhooks\Dispatcher::purge_old(), false );
			report_op( 'Webhook purge_old() run ' . ( ++$runs ), $m, 'deleted=' . wp_json_encode( $m[3] ) );
		} while ( $runs < 30 && $m[3] > 0 );
	}
	exit;
}

if ( 'reports' === $mode ) {
	// A434, A435: sales report, accounting/DATEV export, occurrence settlement, organizer settlement, reseller statement.
	$month = gmdate( 'Y-m', time() - 32 * DAY_IN_SECONDS );
	$from = $month . '-01';
	$to = gmdate( 'Y-m-t', strtotime( $from ) );
	$m = measure_op( static fn() => PassCaptain\Reports\Data::build( array( 'from' => $from, 'to' => $to ), null ), false );
	report_op( "Sales report $month", $m, 'tickets=' . $m[3]['total']['tickets'] );
	$m = measure_op( static fn() => PassCaptain\Invoices::export_rows( $from, $to ), false );
	report_op( "Accounting export $month", $m, 'rows=' . count( $m[3] ) );
	$m = measure_op( static fn() => PassCaptain\Invoices::datev_rows( $from, $to ), false );
	report_op( "DATEV batch $month", $m, 'rows=' . count( $m[3] ) );
	if ( method_exists( PassCaptain\Invoices::class, 'export_stream' ) ) {
		$m = measure_op( static function () use ( $from, $to ) { $n = 0; PassCaptain\Invoices::export_stream( $from, $to, false, static function ( $row ) use ( &$n ) { ++$n; } ); return $n; }, false );
		report_op( "Accounting export streamed $month", $m, 'rows=' . $m[3] );
	}
	$busy = (int) $wpdb->get_var( "SELECT tt.occurrence_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id GROUP BY tt.occurrence_id ORDER BY COUNT(*) DESC LIMIT 1" );
	$m = measure_op( static fn() => PassCaptain\Reports\Settlement::build( $busy ), false );
	report_op( "Occurrence settlement #$busy", $m, 'tickets=' . ( $m[3]['total']['tickets'] ?? '?' ) );
	$partner = PassCaptain\Partners\Repository::get( (int) $wpdb->get_var( "SELECT MIN(partner_id) FROM {$p}wpcap_partners" ) );
	if ( $partner ) {
		$current = gmdate( 'Y-m' );
		$m = measure_op( static fn() => PassCaptain\Partners\Settlements::compute( $partner, $current ), false );
		report_op( "Organizer settlement $current", $m, 'lines=' . count( $m[3]['lines'] ) );
		$m = measure_op( static fn() => PassCaptain\Partners\Statement::compute( $partner, $month ), false );
		report_op( "Reseller statement $month", $m, 'lines=' . count( $m[3]['lines'] ) );
	}
	exit;
}

if ( 'bulk' === $mode ) {
	// Mass operations on the seeded history (changes the data: run last, then `drop`). Batch PDF of an
	// upcoming date without new codes, then the cancellation of the date with the most tickets
	// (request part with its time budget, then the background jobs until the run is complete).
	$by_occ = static fn( $order ) => $wpdb->get_row( "SELECT tt.occurrence_id,COUNT(*) AS n FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.status='valid' AND o.starts_at>UTC_TIMESTAMP() GROUP BY tt.occurrence_id ORDER BY n $order LIMIT 1" );
	$small = $by_occ( 'ASC' );
	$print_max = (int) ( getenv( 'WPP6_PRINT' ) ?: 2000 );
	if ( $small && PassCaptain\Printing\BatchJob::ready() ) {
		add_filter( 'wpcap_print_batch_max', static fn() => max( 20000, (int) $small->n ) );
		$m = measure_op( static fn() => PassCaptain\Printing\BatchJob::create( (int) $small->occurrence_id, 'ticket', false ), false );
		report_op( "Batch PDF create (#{$small->occurrence_id}, {$small->n} tickets)", $m );
		$batch = (int) $m[3];
		if ( $batch > 0 ) {
			$done = 0; $runs = 0; $s = microtime( true );
			do { $r = PassCaptain\Printing\BatchJob::step( $batch ); ++$runs; $b = PassCaptain\Printing\BatchJob::get( $batch ); $done = (int) $b->done; } while ( 'more' === $r && $done < $print_max );
			printf( "Batch PDF render: %d tickets in %d steps, %.1f s (%.1f ms/ticket), peak %.1f MB, status %s\n", $done, $runs, microtime( true ) - $s, ( microtime( true ) - $s ) * 1000 / max( 1, $done ), memory_get_peak_usage() / 1048576, $b->status );
			PassCaptain\Printing\BatchJob::cancel( $batch );
		}
	}
	$big = $by_occ( 'DESC' );
	if ( $big ) {
		$m = measure_op( static fn() => PassCaptain\OccurrenceCancellation::handle( (int) $big->occurrence_id ), false );
		report_op( "Cancel date #{$big->occurrence_id} ({$big->n} tickets), request", $m, wp_json_encode( array_intersect_key( (array) $m[3], array_flip( array( 'cancelled_orders', 'revoked', 'notified', 'complete' ) ) ) ) );
		$s = microtime( true ); $q = $wpdb->num_queries; $batches = 0;
		do {
			$n = PassCaptain\Jobs::process_due( 50 );
			++$batches;
			$run = PassCaptain\BulkRuns::occurrence_run( (int) $big->occurrence_id );
			$summary = $run ? PassCaptain\BulkRuns::summary( $run ) : array();
		} while ( empty( $summary['complete'] ) && $n > 0 && microtime( true ) - $s < 900 );
		printf( "Cancel date background: %.1f s, %d job batches, %d queries, summary %s\n", microtime( true ) - $s, $batches, $wpdb->num_queries - $q, wp_json_encode( array_intersect_key( $summary, array_flip( array( 'cancelled_orders', 'revoked', 'notified', 'complete' ) ) ) ) );
	}
	exit;
}

update_option( PassCaptain\CapacityCounters::OPTION, array( 'state' => 'ready', 'since' => time() ) );
$hot = (int) $wpdb->get_var( "SELECT MAX(ticket_type_id) FROM {$p}wpcap_ticket_types" );
$type = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$hot" );
$occ = $wpdb->get_row( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=" . (int) $type->occurrence_id );

if ( 'measure' === $mode ) {
	echo 'reservations: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations" ) . ', of hot type: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE ticket_type_id=$hot" ) . "\n";
	$now = gmdate( 'Y-m-d H:i:s' );
	echo "EXPLAIN type check:  " . explain( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $hot, $now ) ) . "\n";
	echo "EXPLAIN occ check:   " . explain( $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.counted_qty>0 AND (r.status<>'active' OR r.expires_at <= %s) WHERE tt.occurrence_id=%d", $now, $occ->occurrence_id ) ) . "\n";
	$tl = implode( ',', $wpdb->get_col( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ->occurrence_id ) );
	echo "EXPLAIN occ (IN):    " . explain( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($tl) AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $now ) ) . "\n";
	$m = new ReflectionMethod( PassCaptain\Orders::class, 'capacity_error' );
	$m->setAccessible( true );
	$type->capacity = 10000000; $occ->capacity = 10000000;
	list( $ms, $res ) = t( static fn() => $m->invoke( null, $type, $occ, 1, 0 ), 5 );
	echo "capacity_error (type + occurrence): {$ms} ms (result '" . $res . "')\n";
	list( $ms, $res ) = t( static fn() => PassCaptain\CapacityCounters::used_for_types( array( $hot ), 0, $now ), 5 );
	echo "used_for_types: {$ms} ms\n";
	add_filter( 'wpcap_capacity_reconcile_confirm_us', static fn() => 0 );
	list( $ms, $res ) = t( static fn() => PassCaptain\CapacityCounters::reconcile(), 3 );
	echo "reconcile (cron): {$ms} ms report=" . wp_json_encode( array_intersect_key( $res, array_flip( array( 'state', 'resynced_orders', 'repaired_occurrences', 'complete' ) ) ) ) . "\n";
	list( $ms, $res ) = t( static fn() => PassCaptain\CapacityCounters::reconcile( true ), 1 );
	echo "reconcile --all: {$ms} ms\n";
	exit;
}

if ( 'raw' === $mode ) {
	$now = gmdate( 'Y-m-d H:i:s' );
	$tl = implode( ',', $wpdb->get_col( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ->occurrence_id ) );
	foreach ( array(
		'type'      => $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=%d AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $hot, $now ),
		'occ join'  => $wpdb->prepare( "SELECT COALESCE(SUM(r.counted_qty),0) FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_reservations r ON r.ticket_type_id=tt.ticket_type_id AND r.counted_qty>0 AND (r.status<>'active' OR r.expires_at <= %s) WHERE tt.occurrence_id=%d", $now, $occ->occurrence_id ),
		'occ IN'    => $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($tl) AND counted_qty>0 AND (status<>'active' OR expires_at <= %s)", $now ),
	) as $k => $sql ) {
		list( $ms ) = t( static fn() => $wpdb->get_var( $sql ), 5 );
		echo "$k: $ms ms  " . explain( $sql ) . "\n";
	}
	exit;
}

if ( 'cleanup' === $mode ) {
	if ( ! method_exists( PassCaptain\Privacy\Retention::class, 'housekeeping' ) ) { echo "no housekeeping\n"; exit; }
	foreach ( array( 'wpcap_reservations', 'wpcap_jobs', 'wpcap_seat_holds', 'wpcap_payment_events' ) as $tbl ) { echo "$tbl before: " . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}$tbl" ) . "\n"; }
	$runs = 0; $total = microtime( true );
	do {
		$s = microtime( true );
		$r = PassCaptain\Privacy\Retention::housekeeping( null, 10.0 );
		++$runs;
		printf( "run %d: %.1f s %s\n", $runs, microtime( true ) - $s, wp_json_encode( $r ) );
	} while ( empty( $r['complete'] ) && $runs < 20 );
	printf( "total %.1f s\n", microtime( true ) - $total );
	foreach ( array( 'wpcap_reservations', 'wpcap_jobs', 'wpcap_seat_holds', 'wpcap_payment_events' ) as $tbl ) { echo "$tbl after: " . $wpdb->get_var( "SELECT COUNT(*) FROM {$p}$tbl" ) . "\n"; }
	exit;
}
