<?php
/**
 * Z1: remaining audit-panel findings.
 * - SEC-5: double opt-in for event and slot waitlists (unconfirmed entries get no position/offer,
 *   expire and are deleted by the retention run; setting waitlist_double_opt_in).
 * - SEC-7 / PRIV-14: buyer REST tokens via the X-WPCAP-Access-Token header; mail-link tokens of the
 *   portal, invoice and ticket pages are exchanged for a short-lived sealed cookie (303 without token).
 * - SEC-V2: WooCommerce product ↔ ticket type link limited to events the user manages.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\Integrations\WooCommerce\OrderAdapter as Z1Woo;
use PassCaptain\Mail\Types as Z1MailTypes;
use PassCaptain\Orders;
use PassCaptain\Privacy\Retention;
use PassCaptain\Rest\Controller as Z1Controller;
use PassCaptain\Rest\Credentials;
use PassCaptain\Security\AccessCookie;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceWaitlist;
use PassCaptain\Services\Api as Z1Api;
use PassCaptain\Services\Portal as Z1Portal;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
wpcap_test_reset_transients();

function z1_event_of( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $type ) );
}
function z1_occ_of( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type ) );
}
function z1_wl( $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_waitlist WHERE email=%s", $email ) );
}
/** [id, token] of the last mail to $email with $param=<id>&…token=<64 hex>. */
function z1_link( $email, $param ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $m ) {
		if ( $m['to'] === $email && preg_match( '/' . preg_quote( $param, '/' ) . '=(\d+)(?:&amp;|&)(?:do=[a-z]+(?:&amp;|&))?token=([a-f0-9]{64})/', $m['body'], $mm ) ) { return array( (int) $mm[1], $mm[2] ); }
	}
	return array( 0, '' );
}
function z1_mails( $email, $from ) { return array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $from ), static fn( $m ) => $m['to'] === $email ) ); }
function z1_request( $method, array $params = array(), array $headers = array() ) {
	$r = new WP_REST_Request( $method, '/z1' );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	foreach ( $headers as $k => $v ) { $r->set_header( $k, $v ); }
	return $r;
}
function z1_status( $r ) { return is_wp_error( $r ) ? (int) ( $r->get_error_data()['status'] ?? 0 ) : ( $r instanceof WP_REST_Response ? $r->get_status() : 200 ); }
function z1_src( $class, $method ) {
	$rm = new ReflectionMethod( $class, $method );
	return implode( '', array_slice( file( $rm->getFileName() ), $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1 ) );
}

/* ================================================================== SEC-5 event waitlist */

echo "Z1 SEC-5: double opt-in for the event waitlist\n";
/*
 * Merged design: the event waitlist of the Arbeitsliste line (A564) implements the double opt-in – status `pending`
 * (formerly `unconfirmed`, renamed by the 3.18.0 upgrade), confirmation page with a POST form (admin-post
 * wpcap_waitlist_confirm), confirmation period `confirm_hours` (48), purge by Waitlists::purge_unconfirmed() – and
 * the audit-line switch Security\Policy waitlist_double_opt_in stays the master switch. The checks below assert the
 * audit findings (SEC-5) on that implementation.
 */
// The test bootstrap switches the confirmation off for other modules (filter wpcap_waitlist_double_opt_in).
$z1_doi_filter = $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] ?? null;
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] );
check( 'SEC-5: double opt-in is on by default', true === Waitlists::double_opt_in() && 1 === (int) PassCaptain\Security\Policy::DEFAULTS['waitlist_double_opt_in'] && 1 === (int) Waitlists::config()['double_opt_in'] );
$z1_type = make_type( 1 );
$z1_event = z1_event_of( $z1_type );
$z1_occ = z1_occ_of( $z1_type );
$z1_m = count( $GLOBALS['wpcap_test_mails'] );
$z1_join = Waitlists::join( $z1_event, $z1_occ, $z1_type, 'z1-first@example.com' );
$z1_row = z1_wl( 'z1-first@example.com' );
list( $z1_id, $z1_tok ) = z1_link( 'z1-first@example.com', Waitlists::CONFIRM_QUERY );
$z1_first_mails = z1_mails( 'z1-first@example.com', $z1_m );
check( 'SEC-5: a sign-up is stored unconfirmed (pending) without position; only the token hash is stored', is_array( $z1_join ) && 'received' === $z1_join['status'] && ! empty( $z1_join['confirmation_required'] ) && $z1_row && 'pending' === $z1_row->status && 0 === (int) $z1_row->position && 64 === strlen( $z1_tok ) && hash( 'sha256', $z1_tok ) === $z1_row->confirm_token_hash && $z1_tok !== $z1_row->confirm_token_hash );
check( 'SEC-5: the confirmation link is valid for 48 hours', 48 === (int) Waitlists::config()['confirm_hours'] );
check( 'SEC-5: only the confirmation mail is sent (no position, no unsubscribe link yet)', 1 === count( $z1_first_mails ) && (int) $z1_row->waitlist_id === $z1_id && false === strpos( $z1_first_mails[0]['body'], Waitlists::UNSUBSCRIBE_QUERY . '=' ) );
$z1_offer = Waitlists::offer_next( $z1_event, $z1_type );
check( 'SEC-5: unconfirmed entries get no offer and are not queued', is_wp_error( $z1_offer ) && 'pending' === z1_wl( 'z1-first@example.com' )->status && 0 === (int) z1_wl( 'z1-first@example.com' )->position );
// A second address signs up and confirms first: it gets position 1 (positions are handed out on confirmation).
Waitlists::join( $z1_event, $z1_occ, $z1_type, 'z1-second@example.com' );
list( $z1_id2, $z1_tok2 ) = z1_link( 'z1-second@example.com', Waitlists::CONFIRM_QUERY );
check( 'SEC-5: a wrong token does not confirm', is_wp_error( Waitlists::confirm( $z1_id2, $z1_tok ) ) && 'pending' === z1_wl( 'z1-second@example.com' )->status );
$z1_m = count( $GLOBALS['wpcap_test_mails'] );
$z1_c2 = Waitlists::confirm( $z1_id2, $z1_tok2 );
$z1_row2 = z1_wl( 'z1-second@example.com' );
check( 'SEC-5: confirmation → waiting with position 1, sign-up mail with unsubscribe link', is_array( $z1_c2 ) && 'waiting' === $z1_c2['status'] && 'waiting' === $z1_row2->status && 1 === (int) $z1_row2->position && null !== $z1_row2->confirmed_at && (bool) array_filter( z1_mails( 'z1-second@example.com', $z1_m ), static fn( $m ) => false !== strpos( $m['body'], Waitlists::UNSUBSCRIBE_QUERY . '=' ) ) );
$z1_again = Waitlists::confirm( $z1_id2, $z1_tok2 );
check( 'SEC-5: a confirmation link works once (a repeat changes nothing)', is_wp_error( $z1_again ) || ( ! empty( $z1_again['already'] ) && 1 === (int) z1_wl( 'z1-second@example.com' )->position ) );
// Signing up again while unconfirmed sends a new link; the old one stops working.
Waitlists::join( $z1_event, $z1_occ, $z1_type, 'z1-first@example.com' );
list( , $z1_tok_new ) = z1_link( 'z1-first@example.com', Waitlists::CONFIRM_QUERY );
check( 'SEC-5: a repeated sign-up replaces the confirmation link (old link invalid)', $z1_tok_new !== $z1_tok && is_wp_error( Waitlists::confirm( $z1_id, $z1_tok ) ) );
// Confirmation through the admin-post handler (POST only, nonce of the confirmation page).
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'waitlist_id' => $z1_id, 'token' => $z1_tok_new, '_wpnonce' => 'n' );
// Http::end() exits after the page; the wpcap_response_end action lets the test stop it with an exception
// (without it the whole run ended here).
$z1_end = static function () { throw new RuntimeException( 'z1 response end' ); };
add_action( 'wpcap_response_end', $z1_end );
try { ob_start(); Waitlists::handle_confirm_request(); } catch ( Throwable $e ) { /* Http::end() / wp_die fakes throw. */ } finally { ob_end_clean(); remove_action( 'wpcap_response_end', $z1_end ); }
$_POST = array();
unset( $_SERVER['REQUEST_METHOD'] );
check( 'SEC-5: confirmation through the POST handler → position 2 behind the earlier confirmation', 2 === (int) z1_wl( 'z1-first@example.com' )->position && 'waiting' === z1_wl( 'z1-first@example.com' )->status );
check( 'SEC-5: the confirmation page is a GET form only and uses the private-page helper', false !== strpos( z1_src( Waitlists::class, 'maybe_render_confirm_page' ), 'PrivatePage::send()' ) && false === strpos( z1_src( Waitlists::class, 'maybe_render_confirm_page' ), 'self::confirm(' ) && false !== strpos( z1_src( Waitlists::class, 'handle_confirm_request' ), 'check_admin_referer(' ) );
// Expired confirmation: refused and deleted by the retention run; confirmed entries stay.
Waitlists::join( $z1_event, $z1_occ, $z1_type, 'z1-late@example.com' );
list( $z1_id3, $z1_tok3 ) = z1_link( 'z1-late@example.com', Waitlists::CONFIRM_QUERY );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_waitlist SET created_at=%s WHERE waitlist_id=%d", gmdate( 'Y-m-d H:i:s', time() - 49 * 3600 ), $z1_id3 ) );
check( 'SEC-5: an expired confirmation link is refused', is_wp_error( Waitlists::confirm( $z1_id3, $z1_tok3 ) ) );
$z1_counts = Retention::run();
check( 'SEC-5: the retention run deletes expired unconfirmed entries, confirmed entries stay', null === z1_wl( 'z1-late@example.com' ) && $z1_counts['unconfirmed_waitlists'] >= 1 && z1_wl( 'z1-first@example.com' ) && z1_wl( 'z1-second@example.com' ) );
$z1_listed = array_values( array_filter( Waitlists::entries( $z1_event ), static fn( $e ) => 'waiting' === $e->status ) );
check( 'SEC-5: admin list shows confirmed entries in queue order', array( 'z1-second@example.com', 'z1-first@example.com' ) === array_map( static fn( $e ) => $e->email, $z1_listed ) );
// Setting off: sign-ups count at once (behaviour before the double opt-in).
$GLOBALS['wpcap_test_options'][ PassCaptain\Settings::OPTION ]['waitlist_double_opt_in'] = 0;
PassCaptain\Settings::flush();
Waitlists::join( $z1_event, $z1_occ, $z1_type, 'z1-direct@example.com' );
check( 'SEC-5: with the setting off a sign-up waits at once with a position', false === Waitlists::double_opt_in() && 'waiting' === z1_wl( 'z1-direct@example.com' )->status && 3 === (int) z1_wl( 'z1-direct@example.com' )->position );
unset( $GLOBALS['wpcap_test_options'][ PassCaptain\Settings::OPTION ]['waitlist_double_opt_in'] );
PassCaptain\Settings::flush();
check( 'SEC-5: without the confirmation columns (deferred schema upgrade) sign-ups keep the old behaviour', Waitlists::schema_ready() === Waitlists::doi_ready() && Waitlists::double_opt_in() );
$z1_types = Z1MailTypes::all();
check( 'SEC-5: confirmation mail templates are registered with their placeholders', isset( $z1_types['waitlist_confirm']['placeholders']['confirm_link'], $z1_types['service_waitlist_confirm']['placeholders']['confirm_link'] ) && false !== strpos( PassCaptain\Mail\Mailer::compose( 'waitlist_confirm', call_user_func( $z1_types['waitlist_confirm']['sample'] ) )['body'] ?? '', 'wpcap_waitlist_confirm=' ) );
check( 'SEC-5: settings page offers the double opt-in switch', isset( PassCaptain\Security\Policy::sections( array() )['general']['fields']['waitlist_double_opt_in'] ) );
wpcap_test_reset_transients();

/* ================================================================== SEC-5 slot waitlist */

echo "Z1 SEC-5: double opt-in for the slot waitlist\n";
list( $z1s_event ) = svc_event();
$z1s_res = svc_resource( $z1s_event, array( 'capacity' => 1 ) );
$z1s_slot = svc_slot( $z1s_res, 100, 60, 1 );
$z1s_hold = svc_book( $z1s_slot, 'z1s-holder@svc.test' );
ServiceBookings::confirm_free( $z1s_hold['booking_id'], $z1s_hold['manage_token'] );
svc_reset_limits();
$z1s_m = count( $GLOBALS['wpcap_test_mails'] );
$z1s_join = ServiceWaitlist::join( $z1s_slot, 'z1s-wait@svc.test' );
list( $z1s_id, $z1s_tok ) = z1_link( 'z1s-wait@svc.test', 'wpcap_service_waitlist' );
$z1s_entry = ServiceWaitlist::get( $z1s_id );
check( 'SEC-5 slot: sign-up stored unconfirmed (hash only), confirmation mail with do=confirm, no position link', is_array( $z1s_join ) && $z1s_entry && 'unconfirmed' === $z1s_entry->status && 0 === (int) $z1s_entry->position && hash( 'sha256', $z1s_tok ) === $z1s_entry->confirm_token_hash && 1 === count( z1_mails( 'z1s-wait@svc.test', $z1s_m ) ) && false !== strpos( z1_mails( 'z1s-wait@svc.test', $z1s_m )[0]['body'], 'do=confirm' ) && false === strpos( z1_mails( 'z1s-wait@svc.test', $z1s_m )[0]['body'], 'do=status' ) );
ServiceBookings::cancel( $z1s_hold['booking_id'], array( 'actor' => 'customer' ) );
svc_run_jobs();
check( 'SEC-5 slot: a freed place is not offered to an unconfirmed entry', 'unconfirmed' === ServiceWaitlist::get( $z1s_id )->status && 0 === ServiceWaitlist::held_quantity( $z1s_slot ) );
check( 'SEC-5 slot: the confirmation token is no status/position credential', is_wp_error( ServiceWaitlist::position( $z1s_id, $z1s_tok ) ) );
$z1s_conf = Z1Portal::perform( array( 'do' => 'waitlist_confirm', 'id' => $z1s_id, 'token' => $z1s_tok ) );
check( 'SEC-5 slot: confirmation joins the queue (and gets the free place offered right away)', is_array( $z1s_conf ) && in_array( ServiceWaitlist::get( $z1s_id )->status, array( 'waiting', 'offered' ), true ) && 1 === (int) ServiceWaitlist::get( $z1s_id )->position && '' === ServiceWaitlist::get( $z1s_id )->confirm_token_hash );
$wpdb->insert( "{$p}wpcap_service_waitlist", array( 'slot_id' => $z1s_slot, 'email_hash' => hash( 'sha256', 'z1s-old@svc.test' ), 'email' => 'z1s-old@svc.test', 'quantity' => 1, 'position' => 0, 'status' => 'unconfirmed', 'confirm_token_hash' => str_repeat( 'a', 64 ), 'confirm_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$z1s_old = (int) $wpdb->insert_id;
Retention::run();
check( 'SEC-5 slot: expired unconfirmed slot entries are deleted by the retention run', null === ServiceWaitlist::get( $z1s_old ) && null !== ServiceWaitlist::get( $z1s_id ) );
svc_reset_limits();

/* ================================================================== SEC-7 / PRIV-14 REST header */

$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = $z1_doi_filter;
echo "Z1 SEC-7/PRIV-14: REST access token in the X-WPCAP-Access-Token header\n";
$z1o_type = make_type( 10, 10, 0 );
$z1o = Orders::create( array( 'email' => 'z1-order@example.com', 'items' => array( array( 'ticket_type_id' => $z1o_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z1-order-' . uniqid(), 'accept_terms' => 1 ), array( 'skip_rate_limit' => true ) );
$z1_access = '';
foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) { if ( 'z1-order@example.com' === $mail['to'] && preg_match( '/access=([a-f0-9]{64})/', $mail['body'], $m ) ) { $z1_access = $m[1]; break; } }
$z1_public = (string) Orders::get( $z1o['order_id'] )->public_id;
$z1_hdr = Z1Controller::order_status( z1_request( 'GET', array( 'public_id' => $z1_public ), array( 'X-WPCAP-Access-Token' => $z1_access ) ) );
$z1_qry = Z1Controller::order_status( z1_request( 'GET', array( 'public_id' => $z1_public, 'access_token' => $z1_access ) ) );
$z1_bad = Z1Controller::order_status( z1_request( 'GET', array( 'public_id' => $z1_public, 'access_token' => $z1_access ), array( 'X-WPCAP-Access-Token' => str_repeat( '0', 64 ) ) ) );
check( 'SEC-7: GET /orders/{id} accepts the header; the query parameter stays accepted (old mail links)', 64 === strlen( $z1_access ) && 200 === z1_status( $z1_hdr ) && $z1_public === ( $z1_hdr->get_data()['public_id'] ?? '' ) && 200 === z1_status( $z1_qry ) );
check( 'SEC-7: the header wins over the query parameter (a wrong header is refused)', 403 === z1_status( $z1_bad ) );
check( 'SEC-7: GET /orders/{id}/service-bookings accepts the header', 200 === z1_status( Z1Api::order_bookings( z1_request( 'GET', array( 'public_id' => $z1_public ), array( 'x-wpcap-access-token' => $z1_access ) ) ) ) && 403 === z1_status( Z1Api::order_bookings( z1_request( 'GET', array( 'public_id' => $z1_public ) ) ) ) );
// Slot waitlist status (GET /service-waitlist/{id}) with the manage token from the sign-up mail.
list( , $z1s_manage ) = z1_link( 'z1s-wait@svc.test', 'wpcap_service_waitlist' );
$z1s_mail_tokens = array();
foreach ( z1_mails( 'z1s-wait@svc.test', 0 ) as $mail ) { if ( preg_match( '/do=status(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { $z1s_mail_tokens[] = $m[1]; } }
$z1s_status = Z1Api::waitlist_position( z1_request( 'GET', array( 'entry_id' => $z1s_id ), array( 'X-WPCAP-Access-Token' => (string) end( $z1s_mail_tokens ) ) ) );
check( 'SEC-7: GET /service-waitlist/{id} accepts the header', $z1s_mail_tokens && 200 === z1_status( $z1s_status ) );
check( 'SEC-7: the header is allowed for CORS requests', in_array( 'X-WPCAP-Access-Token', Credentials::cors_headers( array( 'Authorization' ) ), true ) );
check( 'SEC-7: array values are no token (no warnings, empty)', '' === Credentials::token( z1_request( 'GET', array( 'access_token' => array( 'x' ) ) ) ) );

/* ================================================================== SEC-7 / PRIV-14 cookie exchange */

echo "Z1 SEC-7/PRIV-14: mail-link tokens exchanged for a short-lived cookie\n";
$z1_sealed = AccessCookie::seal( 'order', $z1_public, $z1_access, time() + 600 );
check( 'PRIV-14: the cookie value is sealed (no plain token) and opens only for the same order/scope', '' !== $z1_sealed && false === strpos( $z1_sealed, $z1_access ) && $z1_access === AccessCookie::open( 'order', $z1_public, $z1_sealed ) && '' === AccessCookie::open( 'order', str_repeat( 'b', 32 ), $z1_sealed ) && '' === AccessCookie::open( 'ticket', $z1_public, $z1_sealed ) );
check( 'PRIV-14: expired or tampered cookie values are rejected', '' === AccessCookie::open( 'order', $z1_public, $z1_sealed, time() + 601 ) && '' === AccessCookie::open( 'order', $z1_public, substr( $z1_sealed, 0, -4 ) . 'AAAA' ) && '' === AccessCookie::open( 'order', $z1_public, 'pcv1:' . base64_encode( str_repeat( 'x', 80 ) ) ) );
$z1_clean = add_query_arg( 'wpcap_order', $z1_public, home_url( '/' ) );
$z1_plan = AccessCookie::plan( 'order', $z1_public, $z1_access, $z1_clean, array( 'REQUEST_METHOD' => 'GET' ) );
$z1_cookie = $z1_plan['cookie'] ?? array();
check( 'SEC-7: GET with token → 303 target without token (marker only)', 'redirect' === $z1_plan['action'] && false === strpos( $z1_plan['url'], $z1_access ) && false !== strpos( $z1_plan['url'], 'wpcap_order=' . $z1_public ) && false !== strpos( $z1_plan['url'], AccessCookie::MARKER . '=1' ) );
check( 'SEC-7: cookie is HttpOnly, SameSite=Lax, path-limited, short-lived, per order', true === $z1_cookie['httponly'] && 'Lax' === $z1_cookie['samesite'] && '/' === $z1_cookie['path'] && $z1_cookie['expires'] <= time() + AccessCookie::TTL && $z1_cookie['expires'] > time() && AccessCookie::name( 'order', $z1_public ) === $z1_cookie['name'] && 0 === strpos( $z1_cookie['name'], 'wpcap_ax_' ) && AccessCookie::name( 'order', str_repeat( 'c', 32 ) ) !== $z1_cookie['name'] );
check( 'SEC-7: POST requests are not redirected (form bodies are kept)', 'render' === AccessCookie::plan( 'order', $z1_public, $z1_access, $z1_clean, array( 'REQUEST_METHOD' => 'POST' ) )['action'] );
check( 'SEC-7: the request after the redirect is authorised by the cookie', $z1_access === AccessCookie::resolve( 'order', $z1_public, array( 'wpcap_order' => $z1_public, AccessCookie::MARKER => '1' ), array( $z1_cookie['name'] => $z1_cookie['value'] ) ) && AccessCookie::via_cookie() );
check( 'SEC-7: without cookie and without marker nothing is resolved (no flag)', '' === AccessCookie::resolve( 'order', $z1_public, array(), array() ) && ! AccessCookie::cookies_blocked( 'order', $z1_public ) );
check( 'SEC-7: marker without cookie (cookies blocked) is remembered …', '' === AccessCookie::resolve( 'order', $z1_public, array( AccessCookie::MARKER => '1' ), array() ) && AccessCookie::cookies_blocked( 'order', $z1_public ) && 'blocked' === AccessCookie::last()['action'] );
check( '… so the next visit of the token URL renders directly (no redirect loop)', 'render' === AccessCookie::plan( 'order', $z1_public, $z1_access, $z1_clean, array( 'REQUEST_METHOD' => 'GET' ) )['action'] );
check( 'SEC-7: the blocked page explains what to do', false !== strpos( AccessCookie::blocked_html(), 'noch einmal' ) );
$z1_tref = AccessCookie::ticket_ref( str_repeat( 'd', 64 ) );
check( 'SEC-7: ticket reference is not the token and not its plain hash', 32 === strlen( $z1_tref ) && false === strpos( hash( 'sha256', str_repeat( 'd', 64 ) ), $z1_tref ) && 'redirect' === AccessCookie::plan( 'ticket', $z1_tref, str_repeat( 'd', 64 ), add_query_arg( 'wpcap_tref', $z1_tref, home_url( '/' ) ), array( 'REQUEST_METHOD' => 'GET' ) )['action'] );
$z1_pages = array(
	array( PassCaptain\OrderPortal::class, 'maybe_render' ),
	array( PassCaptain\TicketPage::class, 'maybe_render' ),
	array( PassCaptain\Invoices::class, 'maybe_render_customer_invoice' ),
	array( PassCaptain\Printing\InvoicePdf::class, 'maybe_customer_download' ),
);
$z1_missing = array();
foreach ( $z1_pages as list( $class, $method ) ) {
	$src = z1_src( $class, $method );
	if ( false === strpos( $src, 'AccessCookie::exchange(' ) || false === strpos( $src, 'AccessCookie::resolve(' ) ) { $z1_missing[] = $class . '::' . $method; }
}
check( 'SEC-7: portal, ticket, invoice and invoice PDF exchange the URL token and accept the cookie' . ( $z1_missing ? ' – missing: ' . implode( ', ', $z1_missing ) : '' ), ! $z1_missing );
check( 'SEC-7: the portal links the invoice without token when the cookie is in use', false !== strpos( z1_src( PassCaptain\OrderPortal::class, 'render_page' ), 'AccessCookie::via_cookie()' ) );
// N11: the portal script is inline in OrderPortal::render() (assets/order-portal.js was unused and is removed).
check( 'SEC-7: the portal script sends the token in the header, not in the body', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' ), "'X-WPCAP-Access-Token':TOKEN" ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' ), 'access_token:TOKEN' ) && ! file_exists( WPCAP_PATH . 'assets/order-portal.js' ) );
AccessCookie::reset();

/* ================================================================== SEC-V2 WooCommerce */

echo "Z1 SEC-V2: WooCommerce ticket type link limited to managed events\n";
if ( ! function_exists( 'woocommerce_wp_select' ) ) { function woocommerce_wp_select( $args ) { $GLOBALS['z1_wc_selects'][ $args['id'] ] = $args; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['z1_post_meta'][ (int) $id ][ $key ] ?? ''; } }
class Z1_WC_Product extends WC_Product { public function get_id() { return $this->id; } }
$z1w_own = make_type( 5 );
$z1w_foreign = make_type( 5 );
$z1w_user = 4711;
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => z1_event_of( $z1w_own ), 'user_id' => $z1w_user, 'capability' => 'wpcap_manage_events' ) );
$z1w_as_shop_manager = static function () use ( $z1w_user ) {
	$GLOBALS['wpcap_test_user_id'] = $z1w_user;
	$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
	$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => 'manage_options' !== $c;
};
$z1w_as_admin = static function () {
	$GLOBALS['wpcap_test_user_id'] = 1;
	$GLOBALS['wpcap_test_denied_caps'] = array();
	unset( $GLOBALS['wpcap_test_user_can'] );
};
$z1w_post = $_POST;
$z1w_as_shop_manager();
wpcap_test_reset_transients();
$z1w_p1 = new Z1_WC_Product( 9101 );
$_POST = array( Z1Woo::PRODUCT_META => (string) $z1w_foreign );
Z1Woo::save_product_field( $z1w_p1 );
$z1w_notice = get_transient( 'wpcap_admin_notices_' . $z1w_user );
check( 'SEC-V2: a shop manager cannot link a ticket type of an event they do not manage (unchanged + notice)', '' === $z1w_p1->get_meta( Z1Woo::PRODUCT_META ) && is_array( $z1w_notice ) && 'error' === $z1w_notice[0]['type'] );
$_POST = array( Z1Woo::PRODUCT_META => (string) $z1w_own );
Z1Woo::save_product_field( $z1w_p1 );
check( 'SEC-V2: a ticket type of a managed event can be linked', $z1w_own === (int) $z1w_p1->get_meta( Z1Woo::PRODUCT_META ) );
$z1w_p2 = new Z1_WC_Product( 9102 );
$z1w_p2->update_meta_data( Z1Woo::PRODUCT_META, $z1w_foreign );
$_POST = array( Z1Woo::PRODUCT_META => '' );
Z1Woo::save_product_field( $z1w_p2 );
check( 'SEC-V2: a link to a foreign event can neither be removed …', $z1w_foreign === (int) $z1w_p2->get_meta( Z1Woo::PRODUCT_META ) );
$_POST = array( Z1Woo::PRODUCT_META => (string) $z1w_own );
Z1Woo::save_product_field( $z1w_p2 );
check( '… nor replaced', $z1w_foreign === (int) $z1w_p2->get_meta( Z1Woo::PRODUCT_META ) );
wpcap_test_reset_transients();
$_POST = array(); // Locked (disabled) select: nothing posted, nothing changes, no notice.
Z1Woo::save_product_field( $z1w_p2 );
check( 'SEC-V2: a locked field that posts nothing keeps the link without a notice', $z1w_foreign === (int) $z1w_p2->get_meta( Z1Woo::PRODUCT_META ) && false === get_transient( 'wpcap_admin_notices_' . $z1w_user ) );
$GLOBALS['z1_wc_selects'] = array();
$GLOBALS['product_object'] = new Z1_WC_Product( 9103 );
ob_start();
Z1Woo::product_field();
ob_end_clean();
$z1w_opts = $GLOBALS['z1_wc_selects'][ Z1Woo::PRODUCT_META ]['options'] ?? array();
check( 'SEC-V2: the selection lists only ticket types of managed events', isset( $z1w_opts[ (string) $z1w_own ] ) && ! isset( $z1w_opts[ (string) $z1w_foreign ] ) );
$GLOBALS['z1_wc_selects'] = array();
$GLOBALS['product_object'] = $z1w_p2;
ob_start();
Z1Woo::product_field();
ob_end_clean();
$z1w_locked = $GLOBALS['z1_wc_selects'][ Z1Woo::PRODUCT_META ] ?? array();
check( 'SEC-V2: a product linked to a foreign event shows the link read-only', array( $z1w_foreign ) === array_map( 'intval', array_keys( $z1w_locked['options'] ?? array() ) ) && 'disabled' === ( $z1w_locked['custom_attributes']['disabled'] ?? '' ) );
// Other save paths (REST API, quick edit, import): the stored link is restored.
$GLOBALS['z1_post_meta'][9104][ Z1Woo::PRODUCT_META ] = $z1w_foreign;
$z1w_p4 = new Z1_WC_Product( 9104 );
$z1w_p4->update_meta_data( Z1Woo::PRODUCT_META, $z1w_own );
Z1Woo::guard_product_link( $z1w_p4 );
$z1w_p5 = new Z1_WC_Product( 9105 );
$z1w_p5->update_meta_data( Z1Woo::PRODUCT_META, $z1w_foreign );
Z1Woo::guard_product_link( $z1w_p5 );
check( 'SEC-V2: REST/import saves cannot change a foreign link or set one', $z1w_foreign === (int) $z1w_p4->get_meta( Z1Woo::PRODUCT_META ) && '' === $z1w_p5->get_meta( Z1Woo::PRODUCT_META ) );
$z1w_as_admin();
$_POST = array( Z1Woo::PRODUCT_META => (string) $z1w_own );
Z1Woo::save_product_field( $z1w_p2 );
$z1w_p6 = new Z1_WC_Product( 9106 );
$z1w_p6->update_meta_data( Z1Woo::PRODUCT_META, $z1w_foreign );
Z1Woo::guard_product_link( $z1w_p6 );
check( 'SEC-V2: administrators (manage_options) may link any ticket type', $z1w_own === (int) $z1w_p2->get_meta( Z1Woo::PRODUCT_META ) && $z1w_foreign === (int) $z1w_p6->get_meta( Z1Woo::PRODUCT_META ) );
$_POST = $z1w_post;
unset( $GLOBALS['product_object'] );
wpcap_test_reset_transients();
