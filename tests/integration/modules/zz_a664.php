<?php
// phpcs:ignoreFile
/**
 * A664: the core runs alone – every core flow works without the add-on pass-captain-pro and without
 * network access, and the core never calls a licence server (decision 13.11.2026, docs/FREE-PREMIUM.md).
 *
 * Static part: the shipped core does not know the add-on (no class, function, slug or "Requires Plugins"
 * dependency on it) and contains no licence host. Runtime part: with every outgoing HTTP request blocked
 * and recorded, checkout (free and paid), payment, issuance with mail and PDF, transfer, check-in,
 * refund, occurrence change, event cancellation, cron work (jobs, messages, webhooks, review outbox)
 * succeed and not a single request leaves the site.
 */

use PassCaptain\Orders;

require_once dirname( __DIR__, 2 ) . '/shipped-files.php';

echo "A664: the core runs alone\n";
$a664_root = dirname( __DIR__, 3 );
$a664_hits = array();
// Code only (string literals and names of PHP files; comments may explain the add-on): the core neither
// loads, checks for nor calls the add-on or a licence server. Licence code itself: tests/license-guard.php.
$a664_scan = static function ( $code ) {
	$lines = array();
	foreach ( token_get_all( (string) $code ) as $tok ) {
		if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) { continue; }
		if ( preg_match( '/\bpass-captain-pro\b|PassCaptainPro|PassCaptain\\\\Pro\\\\|license[_-]?captain|\bwplc\b/i', $tok[1] ) ) { $lines[] = $tok[2]; }
	}
	return $lines;
};
check( 'A664: the add-on detector finds code references and ignores comments', array( 2, 3 ) === $a664_scan( "<?php // pass-captain-pro is the add-on\nif ( class_exists( 'PassCaptainPro\\Plugin' ) ) {}\nis_plugin_active( 'pass-captain-pro/pass-captain-pro.php' );\n" ) );
foreach ( wpcap_shipped_files( $a664_root ) as $a664_file ) {
	if ( '.php' !== substr( $a664_file, -4 ) || 0 === strpos( $a664_file, 'vendor/' ) ) { continue; }
	foreach ( $a664_scan( file_get_contents( $a664_root . '/' . $a664_file ) ) as $a664_line ) { $a664_hits[] = $a664_file . ':' . $a664_line; }
}
check( 'A664: no shipped core file references the add-on or a licence server' . ( $a664_hits ? ' – ' . implode( ', ', array_slice( $a664_hits, 0, 5 ) ) : '' ), ! $a664_hits );
check( 'A664: the core plugin header requires no other plugin', ! preg_match( '/^[ \t\/*#@]*Requires Plugins\s*:/mi', (string) file_get_contents( $a664_root . '/pass-captain.php' ) ) );
check( 'A664: no add-on is loaded in this run and the add-on API has no listeners', ! class_exists( 'PassCaptainPro\\Plugin', false ) && ! has_action( 'wpcap_loaded' ) );

// Every outgoing request is recorded and refused (no network): pre_http_request answers before any transport.
$a664_hooks_before = $GLOBALS['wpcap_test_hooks'];
$a664_requests = array();
$GLOBALS['wpcap_test_http_requests'] = array();
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$a664_requests ) {
	$a664_requests[] = $url;
	return new WP_Error( 'http_request_failed', 'A664: no network' );
}, 1, 3 );
PassCaptain\Review\Events::register();
unset( $GLOBALS['wpcap_test_filters'][ PassCaptain\Review\Contract::FILTER ] );
$GLOBALS['wpcap_test_transients'] = array();
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

$a664_ok = array();
$a664_free_type = make_type( 10, 10, 0 );
$a664_free = Orders::create( array( 'email' => 'alone-free@example.com', 'items' => array( array( 'ticket_type_id' => $a664_free_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a664-free-' . uniqid() ) );
$a664_ok['free checkout issues tickets'] = ! is_wp_error( $a664_free ) && 1 === count( tickets( $a664_free['order_id'], 'valid' ) );
$a664_type = make_type( 10, 10, 2500 );
$a664_paid = Orders::create( array( 'email' => 'alone-paid@example.com', 'items' => array( array( 'ticket_type_id' => $a664_type, 'quantity' => 3 ) ), 'idempotency_key' => 'a664-paid-' . uniqid() ) );
$a664_ok['paid checkout (bank transfer) holds capacity'] = ! is_wp_error( $a664_paid ) && 'pending' === $a664_paid['payment_status'];
$a664_confirm = Orders::confirm_manual_payment( $a664_paid['order_id'] );
$a664_tickets = tickets( $a664_paid['order_id'] );
$a664_mail = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'alone-paid@example.com' === $m['to'] && ! empty( $m['files'] ) ) );
$a664_ok['payment confirmation issues the tickets and mails them with PDF'] = ! is_wp_error( $a664_confirm ) && 3 === count( $a664_tickets ) && $a664_mail && 0 === strpos( (string) reset( $a664_mail[0]['files'] ), '%PDF' );
$a664_order = Orders::get( $a664_paid['order_id'] );
$a664_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $a664_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $a664_paid['order_id'] ) );
$a664_ok['ticket transfer'] = ! is_wp_error( Orders::transfer_ticket( $a664_order->public_id, $a664_token, $a664_tickets[2]->public_id, 'alone-friend@example.com' ) );
$a664_refund = PassCaptain\Refunds::request( $a664_paid['order_id'], array( (int) $a664_tickets[1]->ticket_id ), 'Gast verhindert', 'a664-r-' . uniqid() );
$a664_ok['refund of a ticket'] = is_array( $a664_refund ) && 'revoked' === $wpdb->get_var( 'SELECT status FROM ' . $p . 'wpcap_tickets WHERE ticket_id=' . (int) $a664_tickets[1]->ticket_id );
$a664_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a664_type" );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 7200 ) ), array( 'occurrence_id' => $a664_occ ) );
$a664_in = PassCaptain\Checkin::perform_ticket( (int) $a664_tickets[0]->ticket_id, array( 'source' => 'admin', 'idempotency_key' => 'a664-in-' . uniqid() ) );
$a664_ok['check-in'] = ! is_wp_error( $a664_in ) && 'checked_in' === ( $a664_in['status'] ?? '' );
$a664_ok['ticket PDF'] = 0 === strpos( PassCaptain\TicketPdf::render( array( array( 'event' => 'E', 'ticket' => 'T', 'when' => 'W', 'token' => str_repeat( 'c', 64 ) ) ) ), '%PDF' );
do_action( 'wpcap_occurrence_changed', $a664_occ, array( 'location' => array( 'A', 'B' ) ), 9 );
$a664_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a664_occ" );
$a664_cancel = Orders::cancel_event( $a664_event );
$a664_ok['event cancellation'] = ! is_wp_error( $a664_cancel ) && 'cancelled' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_events WHERE event_id=$a664_event" );
// Background work of the core: jobs, message queue, webhooks (none subscribed here), review outbox (no provider).
PassCaptain\Jobs::process_due();
PassCaptain\Communications::process_due();
PassCaptain\Webhooks\Dispatcher::process();
$a664_ok['review outbox is filled and waits without a provider (no delivery attempt)'] = 0 === PassCaptain\Suite\Outbox::deliver() && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PassCaptain\Suite\Outbox::table() . " WHERE contract='review' AND status='pending'" ) > 0;
foreach ( $a664_ok as $a664_name => $a664_result ) { check( "A664: without add-on and network – $a664_name", $a664_result ); }
check( 'A664: not a single outgoing HTTP request (in particular none to a licence server)' . ( $a664_requests ? ' – ' . implode( ', ', array_slice( $a664_requests, 0, 3 ) ) : '' ), ! $a664_requests && ! $GLOBALS['wpcap_test_http_requests'] );

$GLOBALS['wpcap_test_hooks'] = $a664_hooks_before;
