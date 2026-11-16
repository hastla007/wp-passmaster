<?php
/**
 * W5: REST negative tests (idempotency, manipulated bodies, foreign IDs), redaction, outbound
 * HTTP hardening (SSRF with a fake resolver, redirects, size limits) and trusted-proxy handling.
 * Included from run.php (after the module tests).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\RateLimit;
use PassCaptain\Settings;
use PassCaptain\Rest\Controller;
use PassCaptain\Security\OutboundHttp;
use PassCaptain\Security\Redact;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'] );
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }

function hd_json_request( $route, array $body, array $params = array() ) {
	$r = new WP_REST_Request( 'POST', $route );
	$r->set_body( json_encode( $body ) );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}
function hd_orders() { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_orders" ); }
function hd_status( $r ) { return is_wp_error( $r ) ? (int) ( $r->get_error_data()['status'] ?? 0 ) : ( $r instanceof WP_REST_Response ? $r->get_status() : 200 ); }

echo "W5 security: REST negative tests\n";
wpcap_test_reset_transients();
Settings::update( array( 'enabled_providers' => array( 'manual' ) ) );
$hd_type = make_type( 20, 4, 1000 );
$hd_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$hd_type" );
$hd_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$hd_occ" );

// Idempotency: a repeated checkout returns the same order and holds once (client keys need >= 16
// characters since A639 / box office form keys 16-64, merged line).
$body = array( 'email' => 'hd@example.com', 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 2 ) ), 'idempotency_key' => 'hd-idem-0000000001', 'provider' => 'manual' );
$a = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', $body ) );
$b = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', $body ) );
check( 'repeated checkout request reuses the order (201 then 200) and holds once', 201 === hd_status( $a ) && 200 === hd_status( $b ) && $a->get_data()['reference'] === $b->get_data()['reference'] && 18 === Orders::availability( $hd_type ) );
$c = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array_merge( $body, array( 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 4 ) ) ) ) ) );
// Merged line: a changed cart is refused by Orders::create() already (A420 wpcap_idempotency_conflict, nothing
// booked); the controller's fingerprint check (QA-1 wpcap_idempotency_mismatch) covers provider changes.
check( 'same idempotency key with a different body does not create a second order (409, audit QA-1)', is_wp_error( $c ) && in_array( $c->get_error_code(), array( 'wpcap_idempotency_conflict', 'wpcap_idempotency_mismatch' ), true ) && 409 === hd_status( $c ) && 18 === Orders::availability( $hd_type ) );

// Manipulated bodies.
$before = hd_orders();
$bad = array(
	'negative quantity' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => -2 ) ),
	'negative quantity string' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => '-2' ) ),
	'fractional quantity' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1.5 ) ),
	'quantity with garbage' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => '2abc' ) ),
	'quantity as array' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => array( 2 ) ) ),
	'huge quantity' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1000000000 ) ),
	'above max per order' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 5 ) ),
	'negative type id' => array( array( 'ticket_type_id' => -$hd_type, 'quantity' => 1 ) ),
	'unknown type id' => array( array( 'ticket_type_id' => 99999999, 'quantity' => 1 ) ),
	'line is a string' => array( 'ticket' ),
	'items is a string' => 'all',
);
$accepted = array();
foreach ( $bad as $label => $items ) {
	wpcap_test_reset_transients();
	$r = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => 'hd-bad@example.com', 'items' => $items, 'idempotency_key' => 'hd-bad-' . md5( $label ), 'provider' => 'manual' ) ) );
	if ( ! is_wp_error( $r ) || hd_status( $r ) < 400 || hd_status( $r ) >= 500 ) { $accepted[] = $label; }
}
check( 'manipulated item lists are rejected with 4xx and create no order' . ( $accepted ? ' – accepted: ' . implode( ', ', $accepted ) : '' ), ! $accepted && $before === hd_orders() );
$draft_type = make_type( 5, 4, 1000 );
$wpdb->query( "UPDATE {$p}wpcap_events SET status='draft' WHERE event_id=(SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$draft_type))" );
wpcap_test_reset_transients();
$r = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => 'hd-draft@example.com', 'items' => array( array( 'ticket_type_id' => $draft_type, 'quantity' => 1 ) ), 'idempotency_key' => 'hd-draft', 'provider' => 'manual' ) ) );
check( 'ticket type of an unpublished event cannot be bought', is_wp_error( $r ) && $before === hd_orders() );
wpcap_test_reset_transients();
$r = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => str_repeat( 'a', 5000 ) . '@example.com', 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1 ) ), 'idempotency_key' => 'hd-long-mail', 'provider' => 'manual' ) ) );
$r2 = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => array( 'x@example.com' ), 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1 ) ), 'idempotency_key' => 'hd-arr-mail', 'provider' => 'manual' ) ) );
check( 'overlong or non-string e-mail is rejected', is_wp_error( $r ) && is_wp_error( $r2 ) && $before === hd_orders() );
wpcap_test_reset_transients();
$long_key = str_repeat( 'k', 10000 );
$r = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => 'hd-key@example.com', 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1 ) ), 'idempotency_key' => $long_key, 'provider' => 'manual' ) ) );
$r2 = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => 'hd-key2@example.com', 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1 ) ), 'idempotency_key' => $long_key . 'x', 'provider' => 'manual' ) ) );
check( 'overlong idempotency keys never collide or break storage', ( is_wp_error( $r ) || is_wp_error( $r2 ) || $r->get_data()['reference'] !== $r2->get_data()['reference'] ) && '' === (string) $wpdb->last_error );
wpcap_test_reset_transients();
$r = Controller::checkout( hd_json_request( '/wp-pass-captain/v1/checkout', array( 'email' => 'hd-prov@example.com', 'items' => array( array( 'ticket_type_id' => $hd_type, 'quantity' => 1 ) ), 'idempotency_key' => 'hd-prov', 'provider' => 'stripe' ) ) );
check( 'disabled payment provider cannot be forced through the body', is_wp_error( $r ) && 'wpcap_provider_unavailable' === $r->get_error_code() );

// Foreign IDs in the order portal: access token of order A, ticket of order B.
$oa = Orders::create( array( 'email' => 'hd-a@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 4, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'hd-portal-a' ) );
$ob = Orders::create( array( 'email' => 'hd-b@example.com', 'items' => array( array( 'ticket_type_id' => make_type( 5, 4, 0 ), 'quantity' => 1 ) ), 'idempotency_key' => 'hd-portal-b' ) );
$access_a = '';
foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) { if ( 'hd-a@example.com' === $mail['to'] && preg_match( '/access=([a-f0-9]{64})/', $mail['body'], $m ) ) { $access_a = $m[1]; break; } }
$ticket_b = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,public_id,attendee_email,status FROM {$p}wpcap_tickets WHERE order_id=%d", $ob['order_id'] ) );
$public_a = Orders::get( $oa['order_id'] )->public_id;
$t = Controller::transfer_ticket( hd_json_request( '/x', array(), array( 'public_id' => $public_a, 'access_token' => $access_a, 'ticket_public_id' => $ticket_b->public_id, 'recipient_email' => 'thief@example.com' ) ) );
$as = Controller::assign_ticket( hd_json_request( '/x', array(), array( 'public_id' => $public_a, 'access_token' => $access_a, 'ticket_public_id' => $ticket_b->public_id, 'attendee_name' => 'Thief', 'attendee_email' => 'thief@example.com' ) ) );
$after_b = $wpdb->get_row( $wpdb->prepare( "SELECT attendee_email,status FROM {$p}wpcap_tickets WHERE ticket_id=%d", $ticket_b->ticket_id ) );
check( 'order portal: a ticket of another order cannot be transferred or reassigned', '' !== $access_a && is_wp_error( $t ) && is_wp_error( $as ) && $after_b->attendee_email === $ticket_b->attendee_email && $after_b->status === $ticket_b->status );
$st = Controller::order_status( hd_json_request( '/x', array(), array( 'public_id' => $public_a, 'access_token' => strtoupper( $access_a ) . 'zz' ) ) );
$st2 = Controller::order_status( hd_json_request( '/x', array(), array( 'public_id' => $public_a, 'access_token' => array( $access_a ) ) ) );
check( 'order portal: token of the wrong type is rejected without error output', ( ! is_wp_error( $st ) || 403 === hd_status( $st ) ) && is_wp_error( $st2 ) );

// Service bookings: negative / huge quantity and repeated request.
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $hd_event, 'name' => 'HD', 'capacity' => 3, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$hd_res = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $hd_res, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 90000 ), 'capacity' => 3, 'status' => 'open' ) );
$hd_slot = (int) $wpdb->insert_id;
wpcap_test_reset_transients();
$neg = Controller::book_service_slot( hd_json_request( '/x', array( 'email' => 'hd-s@example.com', 'quantity' => -3, 'idempotency_key' => 'hd-s-neg' ), array( 'slot_id' => $hd_slot ) ) );
$huge = Controller::book_service_slot( hd_json_request( '/x', array( 'email' => 'hd-s@example.com', 'quantity' => 500, 'idempotency_key' => 'hd-s-huge' ), array( 'slot_id' => $hd_slot ) ) );
$s1 = Controller::book_service_slot( hd_json_request( '/x', array( 'email' => 'hd-s@example.com', 'quantity' => 1, 'idempotency_key' => 'hd-s-1' ), array( 'slot_id' => $hd_slot ) ) );
$s2 = Controller::book_service_slot( hd_json_request( '/x', array( 'email' => 'hd-s@example.com', 'quantity' => 1, 'idempotency_key' => 'hd-s-1' ), array( 'slot_id' => $hd_slot ) ) );
$booked = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_service_bookings WHERE slot_id=$hd_slot" );
check( 'service booking: negative and out-of-range quantities are rejected (absint() turned -3 into 3)', is_wp_error( $neg ) && is_wp_error( $huge ) );
check( 'service booking: repeated request books once', ! is_wp_error( $s1 ) && ! is_wp_error( $s2 ) && 1 === $booked );

// POS sale: repeated request and foreign/negative items (admin).
wpcap_test_reset_transients();
$pos_type = make_type( 10, 10, 0 );
$pos_body = array( 'email' => 'hd-pos@example.com', 'items' => array( array( 'ticket_type_id' => $pos_type, 'quantity' => 1 ) ), 'idempotency_key' => 'hd-pos-00000000001' );
// Merged line: the POS route sells through the box office module, which needs an open shift.
// Cash in DE/AT needs a certified register (wpcap_pos_fiscal_required): the test site confirms one.
$hd_shift = PassCaptain\BoxOffice\Service::open_session( 0 );
add_filter( 'wpcap_boxoffice_fiscal_ready', $hd_fiscal_ready = static function () { return true; } );
$p1 = Controller::pos_sale( hd_json_request( '/x', $pos_body ) );
$p2 = Controller::pos_sale( hd_json_request( '/x', $pos_body ) );
check( 'POS sale: repeated request returns the same paid order', ! is_wp_error( $p1 ) && ! is_wp_error( $p2 ) && $p1->get_data()['order_id'] === $p2->get_data()['order_id'] && 201 === hd_status( $p1 ) && 200 === hd_status( $p2 ) );
$p3 = Controller::pos_sale( hd_json_request( '/x', array_merge( $pos_body, array( 'idempotency_key' => 'hd-pos-neg-0000001', 'items' => array( array( 'ticket_type_id' => $pos_type, 'quantity' => -1 ) ) ) ) ) );
$p4 = Controller::pos_sale( hd_json_request( '/x', array_merge( $pos_body, array( 'idempotency_key' => 'hd-pos-str-0000001', 'items' => 'x' ) ) ) );
check( 'POS sale: negative quantity / malformed items are rejected', is_wp_error( $p3 ) && is_wp_error( $p4 ) );
remove_filter( 'wpcap_boxoffice_fiscal_ready', $hd_fiscal_ready );
if ( is_object( $hd_shift ) && isset( $hd_shift->session_id ) ) { PassCaptain\BoxOffice\Service::close_session( (int) $hd_shift->session_id, 0 ); }

// Check-in: repeated request with the same idempotency key counts once.
$ci_type = make_type( 5, 4, 0 );
$ci_order = Orders::create( array( 'email' => 'hd-ci@example.com', 'items' => array( array( 'ticket_type_id' => $ci_type, 'quantity' => 1 ) ), 'idempotency_key' => 'hd-ci' ) );
$ci_code = ticket_code_for( $ci_order['order_id'] )[0] ?? '';
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() + 600 ) . "' WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$ci_type)" );
$ci1 = Controller::check_in( hd_json_request( '/x', array(), array( 'token' => $ci_code, 'idempotency_key' => 'hd-scan-1' ) ) );
$ci2 = Controller::check_in( hd_json_request( '/x', array(), array( 'token' => $ci_code, 'idempotency_key' => 'hd-scan-1' ) ) );
$ci_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins c JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id WHERE t.order_id=" . (int) $ci_order['order_id'] . " AND c.result='checked_in'" );
check( 'check-in: repeated request with the same key is replayed, not counted twice', ! is_wp_error( $ci1 ) && ! is_wp_error( $ci2 ) && ! empty( $ci2->get_data()['replayed'] ) && 1 === $ci_rows );
$ci3 = Controller::check_in( hd_json_request( '/x', array(), array( 'token' => array( $ci_code ) ) ) );
check( 'check-in: token as array is rejected', is_wp_error( $ci3 ) );

// Campaign with a ticket type of another event (foreign id).
$other_type = make_type( 5, 4, 1000 );
$camp = Controller::create_campaign( hd_json_request( '/x', array( 'code' => 'HDFOREIGN', 'kind' => 'percent', 'value' => 50, 'ticket_type_id' => $other_type ), array( 'event_id' => $hd_event ) ) );
check( 'campaign: ticket type of another event is rejected', is_wp_error( $camp ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_campaigns WHERE code='HDFOREIGN'" ) );

echo "W5 security: redaction\n";
Settings::update( array( 'stripe_secret_key' => 'sk_live_HDsecretABCDEFGHIJ123', 'twilio_auth_token' => 'hdTwilioTokenValue0123456789' ) );
$text = Redact::text( 'key sk_live_HDsecretABCDEFGHIJ123 / tw hdTwilioTokenValue0123456789 / mollie live_abcdefghijklmnopqrstuvwxyz / whsec_abcdefghij1234 / pk wpcap_pk_0123456789ab_' . str_repeat( 'c', 64 ) . ' / dev 0123456789ab.' . str_repeat( 'd', 64 ) . ' / AC' . str_repeat( 'a', 32 ) . ' / Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijklmnop / ticket ' . str_repeat( 'e', 64 ) . ' / mail max.mustermann@example.org / url https://x.test/?token=abc123&x=1 / {"client_secret":"hunter2"}' );
$leaked = array_filter( array( 'sk_live_HDsecret', 'hdTwilioTokenValue', 'abcdefghijklmnopqrstuvwxyz', 'whsec_abcdefghij', str_repeat( 'c', 64 ), str_repeat( 'd', 64 ), str_repeat( 'a', 32 ), 'eyJzdWIi', str_repeat( 'e', 64 ), 'max.mustermann', 'abc123', 'hunter2' ), static fn( $needle ) => false !== strpos( $text, $needle ) );
check( 'Redact::text removes configured secrets, API keys, tokens, ticket codes, e-mails' . ( $leaked ? ' – leaked: ' . implode( ', ', $leaked ) : '' ), ! $leaked && false !== strpos( $text, 'm***@example.org' ) );
$data = Redact::data( array( 'password' => 'x', 'Authorization' => 'Basic abc', 'order_id' => 5, 'customer_email' => 'jane@example.com', 'nested' => array( 'response' => '{"access_token":"zzz-secret"}' ), 'ok' => true ) );
check( 'Redact::data masks sensitive keys and PII, keeps ids and flags', '[redacted]' === $data['password'] && '[redacted]' === $data['Authorization'] && 5 === $data['order_id'] && 'j***@example.com' === $data['customer_email'] && false === strpos( $data['nested']['response'], 'zzz-secret' ) && true === $data['ok'] );
$GLOBALS['hd_log'] = array();
add_filter( 'wpcap_error_log', $hd_logger = static function ( $send, $line ) { $GLOBALS['hd_log'][] = $line; return false; }, 10, 2 );
Redact::log( "Duplicate entry 'jane@example.com' for key with sk_live_HDsecretABCDEFGHIJ123", array( 'token' => 'abc', 'refund_id' => 7 ) );
remove_filter( 'wpcap_error_log', $hd_logger );
check( 'error log lines are redacted (DB errors can quote row values)', 1 === count( $GLOBALS['hd_log'] ) && false === strpos( $GLOBALS['hd_log'][0], 'jane@example.com' ) && false === strpos( $GLOBALS['hd_log'][0], 'HDsecret' ) && false !== strpos( $GLOBALS['hd_log'][0], '"refund_id":7' ) );
$src_logs = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() && ! str_ends_with( $file->getPathname(), 'Security/Redact.php' ) && preg_match( '/\berror_log\s*\(/', (string) file_get_contents( $file->getPathname() ) ) ) { $src_logs[] = basename( $file->getPathname() ); }
}
check( 'no direct error_log() outside the redaction helper' . ( $src_logs ? ' – ' . implode( ', ', $src_logs ) : '' ), ! $src_logs );

echo "W5 security: outbound HTTP (SSRF)\n";
$GLOBALS['wpcap_test_dns'] = array(
	'public.example' => array( '93.184.215.14' ),
	'private.example' => array( '10.1.2.3' ),
	'metadata.example' => array( '169.254.169.254' ),
	'mixed.example' => array( '93.184.215.14', '192.168.1.5' ),
	'v6loop.example' => array( '::1' ),
	'v6mapped.example' => array( '::ffff:127.0.0.1' ),
	'ula.example' => array( 'fd00::1' ),
	'cgnat.example' => array( '100.64.0.7' ),
	'nat64.example' => array( '64:ff9b::a9fe:a9fe' ),
	'nowhere.example' => array(),
	'lan.example' => array( '127.0.0.1' ),
);
$cases = array(
	'https://public.example/hook' => true,
	'https://private.example/hook' => false,
	'https://metadata.example/latest/meta-data' => false,
	'https://169.254.169.254/latest' => false,
	'https://mixed.example/' => false,
	'https://v6loop.example/' => false,
	'https://v6mapped.example/' => false,
	'https://[::ffff:10.0.0.1]/' => false,
	'https://ula.example/' => false,
	'https://cgnat.example/' => false,
	'https://nat64.example/' => false,
	'https://nowhere.example/' => false,
	'https://localhost/' => false,
	'https://db.internal/' => false,
	'http://public.example/' => false,
	'ftp://public.example/' => false,
	'https://user:pw@public.example/' => false,
	'https://0x7f.0.0.1/' => false,
	"https://public.example/\nX: y" => false,
);
$wrong = array();
foreach ( $cases as $url => $ok ) { if ( $ok !== ( true === OutboundHttp::validate_url( $url ) ) ) { $wrong[] = $url; } }
check( 'SSRF guard: https to public addresses only (private, loopback, link-local/metadata, CGNAT, ULA, mapped/NAT64 IPv6, unresolvable blocked)' . ( $wrong ? ' – wrong: ' . implode( ', ', $wrong ) : '' ), ! $wrong );
check( 'local services: http only to loopback/private and only with allow_local', true === OutboundHttp::validate_url( 'http://127.0.0.1:5618/state', array( 'allow_local' => true ) ) && true === OutboundHttp::validate_url( 'http://lan.example:5618/', array( 'allow_local' => true ) ) && true !== OutboundHttp::validate_url( 'http://public.example/', array( 'allow_local' => true ) ) && true !== OutboundHttp::validate_url( 'http://169.254.169.254/', array( 'allow_local' => true ) ) );

http_reset();
http_push( 'moved', 302, 'public.example' );
$redir = OutboundHttp::request( 'https://public.example/r', array( 'body' => '{}' ) );
http_push( str_repeat( 'x', 5000 ), 200, 'public.example' );
$big = OutboundHttp::request( 'https://public.example/big', array( 'body' => '{}' ), array( 'max_bytes' => 2048 ) );
http_push( 'not json', 200, 'public.example' );
$nojson = OutboundHttp::request( 'https://public.example/j', array(), array( 'json' => true ) );
$reqs = http_requests( 'public.example' );
check( 'redirects are not followed, responses are size-limited and JSON-validated', is_wp_error( $redir ) && 'wpcap_outbound_redirect' === $redir->get_error_code() && is_wp_error( $big ) && 'wpcap_outbound_too_large' === $big->get_error_code() && is_wp_error( $nojson ) && 3 === count( $reqs ) );
$blocked = OutboundHttp::request( 'https://private.example/x', array( 'body' => '{}' ) );
check( 'a blocked target is never contacted', is_wp_error( $blocked ) && 0 === count( http_requests( 'private.example' ) ) );

// Webhooks: creation validates DNS; DNS rebinding after creation blocks the delivery.
$GLOBALS['wpcap_test_dns']['rebind.hooks.example'] = array( '93.184.215.14' );
$wh = \PassCaptain\Webhooks\Dispatcher::create_subscription( 'https://rebind.hooks.example/in', array( 'order.paid' ) );
$wh_private = \PassCaptain\Webhooks\Dispatcher::create_subscription( 'https://private.example/in', array( 'order.paid' ) );
check( 'webhook to a host resolving to a private address is refused', ! is_wp_error( $wh ) && is_wp_error( $wh_private ) );
$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='disabled' WHERE subscription_id<>" . (int) $wh['subscription_id'] );
$GLOBALS['wpcap_test_dns']['rebind.hooks.example'] = array( '127.0.0.1' );
http_reset();
\PassCaptain\Webhooks\Dispatcher::emit( 'order.paid', 'hd-rebind', array( 'object' => 'order', 'id' => 'x' ) );
$wpdb->query( "UPDATE {$p}wpcap_webhook_deliveries SET next_attempt_at='2000-01-01 00:00:00' WHERE status='pending'" );
\PassCaptain\Webhooks\Dispatcher::process( 5 );
$err = (string) $wpdb->get_var( "SELECT last_error FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=" . (int) $wh['subscription_id'] . ' ORDER BY delivery_id DESC LIMIT 1' );
check( 'DNS rebinding to 127.0.0.1 after creation: delivery blocked, nothing sent', 0 === count( http_requests( 'rebind.hooks.example' ) ) && '' !== $err );
$GLOBALS['wpcap_test_dns']['rebind.hooks.example'] = array( '93.184.215.14' );
http_push( 'moved', 301, 'rebind.hooks.example' );
$wpdb->query( "UPDATE {$p}wpcap_webhook_deliveries SET next_attempt_at='2000-01-01 00:00:00' WHERE status='pending'" );
\PassCaptain\Webhooks\Dispatcher::process( 5 );
$row = $wpdb->get_row( "SELECT status,response_code FROM {$p}wpcap_webhook_deliveries WHERE subscription_id=" . (int) $wh['subscription_id'] . ' ORDER BY delivery_id DESC LIMIT 1' );
check( 'webhook redirect is a failed attempt, not followed', 'succeeded' !== $row->status && 301 === (int) $row->response_code && 1 === count( http_requests( 'rebind.hooks.example' ) ) );
\PassCaptain\Webhooks\Dispatcher::delete_subscription( $wh['subscription_id'] );

// Generic SMS endpoint.
$sms_save = \PassCaptain\Modules\Sms::save( array( 'provider' => 'http', 'http_endpoint' => 'https://metadata.example/send' ) );
check( 'SMS gateway endpoint resolving to a metadata address cannot be saved', is_wp_error( $sms_save ) );
Settings::update( array( 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => 'http', 'http_endpoint' => 'https://private.example/send' ) ) ) );
http_reset();
$sent = ( new \PassCaptain\Sms\HttpProvider() )->send( '+4915112345678', 'Hi', 'ref-1' );
check( 'SMS send to a (now) private target is blocked permanently without a request', is_wp_error( $sent ) && ! empty( $sent->get_error_data()['permanent'] ) && 0 === count( http_requests( 'private.example' ) ) );

// Fiscal services.
http_reset();
$fiscal_local = \PassCaptain\Fiscalization\ProviderHttp::request( 'http://127.0.0.1:5618/state', 'GET' );
Settings::update( array( 'fiscal_allow_local_service' => 1 ) );
http_push( array( 'ok' => true ), 200, '127.0.0.1' );
$fiscal_allowed = \PassCaptain\Fiscalization\ProviderHttp::request( 'http://127.0.0.1:5618/state', 'GET' );
$fiscal_remote_http = \PassCaptain\Fiscalization\ProviderHttp::request( 'http://public.example/state', 'GET' );
http_push( array( 'access_token' => 't' ), 200, 'public.example' );
\PassCaptain\Fiscalization\ProviderHttp::request( 'https://public.example/auth/token', 'POST', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'grant_type=client_credentials' );
$form = http_requests( 'public.example/auth' );
Settings::update( array( 'fiscal_allow_local_service' => 0 ) );
check( 'fiscal: local http service only after opt-in, never plain http to the internet', is_wp_error( $fiscal_local ) && ! is_wp_error( $fiscal_allowed ) && is_wp_error( $fiscal_remote_http ) );
check( 'fiscal: pre-encoded form bodies are sent as is (were JSON-encoded before)', 1 === count( $form ) && 'grant_type=client_credentials' === $form[0]['body'] );

// APNs and Google endpoints are fixed.
if ( ! class_exists( 'Hd_Apns' ) ) { class Hd_Apns extends \PassCaptain\WalletPasses\ApnsClient { public $urls = array(); protected function request( $url, array $headers, $body, array $credentials ) { $this->urls[] = $url; return array( 'status' => 200, 'body' => '' ); } } }
$apns = new Hd_Apns();
$apns->send( 'abc123', 'pass.test', array( 'cert' => 'c', 'key' => 'k' ), 'https://169.254.169.254' );
$apns->send( 'abc123', 'pass.test', array( 'cert' => 'c', 'key' => 'k' ), 'https://api.sandbox.push.apple.com' );
check( 'APNs: certificate is only presented to Apple hosts', 0 === strpos( $apns->urls[0], 'https://api.push.apple.com/' ) && 0 === strpos( $apns->urls[1], 'https://api.sandbox.push.apple.com/' ) );
$g_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $g_key, $g_pem );
Settings::update( array( 'google_wallet_service_account' => json_encode( array( 'client_email' => 'x@test.iam.gserviceaccount.com', 'private_key' => $g_pem, 'token_uri' => 'https://169.254.169.254/token' ) ) ) );
$g = \PassCaptain\WalletPasses\Credentials::google();
check( 'Google Wallet: token_uri from the JSON cannot redirect the signed assertion', ! is_wp_error( $g ) && 'https://oauth2.googleapis.com/token' === $g['token_uri'] );

echo "W5 security: trusted proxies and client address\n";
$srv = array( 'REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9' );
Settings::update( array( 'trusted_proxies' => '', 'trusted_proxy_header' => 'x_forwarded_for' ) );
check( 'without trusted proxies forwarding headers are ignored', '203.0.113.10' === RateLimit::client_ip( $srv ) );
Settings::update( array( 'trusted_proxies' => '203.0.113.0/24' ) );
check( 'request from a trusted proxy: right-most untrusted X-Forwarded-For hop is the client', '198.51.100.7' === RateLimit::client_ip( $srv ) );
check( 'spoofed left-most X-Forwarded-For entries are not used', '198.51.100.7' === RateLimit::client_ip( array_merge( $srv, array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 203.0.113.11' ) ) ) );
check( 'request from an untrusted address: header ignored even when configured', '192.0.2.50' === RateLimit::client_ip( array_merge( $srv, array( 'REMOTE_ADDR' => '192.0.2.50' ) ) ) );
check( 'garbage in the header falls back to the proxy address', '203.0.113.10' === RateLimit::client_ip( array_merge( $srv, array( 'HTTP_X_FORWARDED_FOR' => 'not-an-ip' ) ) ) );
Settings::update( array( 'trusted_proxy_header' => 'cf_connecting_ip' ) );
check( 'configured header (CF-Connecting-IP) is used', '198.51.100.9' === RateLimit::client_ip( $srv ) );
add_filter( 'wpcap_trusted_proxies', $hd_tp = static fn( $list ) => array( '2001:db8::/32' ), 10, 1 );
check( 'filter wpcap_trusted_proxies (IPv6 CIDR)', '198.51.100.9' === RateLimit::client_ip( array_merge( $srv, array( 'REMOTE_ADDR' => '2001:db8::5' ) ) ) && '203.0.113.10' === RateLimit::client_ip( $srv ) );
remove_filter( 'wpcap_trusted_proxies', $hd_tp );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.20';
$k1 = RateLimit::client();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.21';
$k2 = RateLimit::client();
check( 'behind the trusted proxy every client gets its own rate-limit bucket', $k1 !== $k2 && Orders::client_ip() === '198.51.100.21' );
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
Settings::update( array( 'trusted_proxies' => '', 'trusted_proxy_header' => 'x_forwarded_for', 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => '', 'http_endpoint' => '' ) ), 'stripe_secret_key' => '', 'twilio_auth_token' => '' ) );
$GLOBALS['wpcap_test_dns'] = array();
wpcap_test_reset_transients();
http_reset();
