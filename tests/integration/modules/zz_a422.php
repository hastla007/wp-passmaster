<?php
// phpcs:ignoreFile
/**
 * A422: a wallet sync keeps its result per pass: only the tickets/classes that failed are queued again
 * (new job, backoff), passes already updated are not sent twice; a 429 stops further calls to that
 * provider in the run and the rest waits for Retry-After. Google is faked (HTTP queue), APNs stubbed.
 */
use PassCaptain\Orders;
use PassCaptain\WalletPasses\Service;
use PassCaptain\WalletPasses\Store;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A422: wallet sync – partial success, 429\n";
global $wpdb;
$p = $wpdb->prefix;
wpr7_wallet_enable();
$a422_type = make_type( 10, 10, 0 );
http_reset();
$a422_r = Orders::create( array( 'email' => 'a422@example.com', 'items' => array( array( 'ticket_type_id' => $a422_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a422-1' ) );
Service::flush();
$a422_ids = array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $a422_r['order_id'] ) );
foreach ( $a422_ids as $a422_id ) {
	foreach ( Store::passes( $a422_id, 'google' ) as $a422_pass ) { Store::mark_remote( 'google', $a422_pass->serial_number ); }
}
$a422_last_job = static fn() => $wpdb->get_row( "SELECT * FROM {$p}wpcap_jobs WHERE job_type='wallet_sync' ORDER BY job_id DESC LIMIT 1" );
$a422_patches = static fn() => count( array_filter( http_requests( 'eventTicketObject/' ), static fn( $q ) => in_array( $q['method'], array( 'PATCH', 'PUT' ), true ) ) );

// 1) One of three updates fails: only that ticket is queued again.
http_reset();
http_push( array(), 200, 'eventTicketObject/' );
http_push( array(), 500, 'eventTicketObject/' );
http_push( array(), 200, 'eventTicketObject/' );
$a422_before = (int) ( $a422_last_job()->job_id ?? 0 );
$a422_ok = Service::process( $a422_ids );
$a422_job = $a422_last_job();
$a422_payload = json_decode( (string) $a422_job->payload, true );
check( 'A422: a partial failure completes the run and queues a follow-up job', true === $a422_ok && (int) $a422_job->job_id > $a422_before && 'queued' === $a422_job->status && 3 === $a422_patches() );
check( 'A422: the follow-up job holds only the failed ticket, with backoff', array( $a422_ids[1] ) === $a422_payload['ticket_ids'] && 1 === $a422_payload['retry'] && strtotime( $a422_job->run_after . ' UTC' ) >= time() + 50 );
http_reset();
http_push( array(), 200, 'eventTicketObject/' );
check( 'A422: the follow-up job only updates the failed ticket', true === Service::handle_job( false, 'wallet_sync', $a422_payload ) && 1 === $a422_patches() );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=%d", $a422_job->job_id ) );

// 2) 429: no further Google calls in this run, everything left waits for Retry-After.
http_reset();
$GLOBALS['wpcap_test_http_queue'][] = array( 'body' => array( 'error' => array( 'code' => 429 ) ), 'code' => 429, 'match' => 'eventTicketObject/', 'headers' => array( 'Retry-After' => '300' ) );
http_push( array(), 200, 'eventTicketObject/' );
http_push( array(), 200, 'eventTicketObject/' );
$a422_ok = Service::process( $a422_ids, array() );
$a422_job = $a422_last_job();
$a422_payload = json_decode( (string) $a422_job->payload, true );
check( 'A422: after a 429 no further calls go to Google in the run', true === $a422_ok && 1 === $a422_patches() );
check( 'A422: the remaining tickets wait at least Retry-After', $a422_ids === $a422_payload['ticket_ids'] && strtotime( $a422_job->run_after . ' UTC' ) >= time() + 290 );
check( 'A422: the rate limit is logged on the Wallet page', false !== strpos( wp_json_encode( get_option( Service::LOG_OPTION, array() ) ), 'rate_limited' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_jobs SET status='complete' WHERE job_id=%d", $a422_job->job_id ) );

// 3) After MAX_RETRIES follow-ups the rest is given up and logged.
http_reset();
http_push( array(), 500, 'eventTicketObject/' );
$a422_before = (int) ( $a422_last_job()->job_id ?? 0 );
check( 'A422: the last follow-up gives up without a new job', true === Service::process( array( $a422_ids[0] ), array(), Service::MAX_RETRIES ) && (int) $a422_last_job()->job_id === $a422_before && false !== strpos( wp_json_encode( get_option( Service::LOG_OPTION, array() ) ), 'sync_gave_up' ) );

wpr7_wallet_disable();
http_reset();
