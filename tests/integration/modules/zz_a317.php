<?php
/**
 * A317: the waitlist offer page answers with Post/Redirect/Get: accepting or declining redirects to
 * a result page (keyed link) that shows the answer and the payment status; a second submit (double
 * click) or opening the mail link again lands there instead of "Angebot nicht verfügbar"; the result
 * page offers "Jetzt bezahlen" for hosted payment pages; the form is locked after the first submit.
 * Included from run.php (uses check(), make_type(), http mocks of payments.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;

if ( ! class_exists( 'Wpcap_Test_Redirect' ) ) { final class Wpcap_Test_Redirect extends Exception {} }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $url ) { throw new Wpcap_Test_Redirect( $url ); } }
if ( ! function_exists( 'wp_redirect' ) ) { function wp_redirect( $url, $status = 302 ) { throw new Wpcap_Test_Redirect( $url ); } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1 ) { return 1; } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $code ) { $GLOBALS['wpcap_test_status'] = (int) $code; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $f = '<input type="hidden" name="_wpnonce" value="n">'; if ( $echo ) { echo $f; } return $f; } }
if ( ! function_exists( 'bloginfo' ) ) { function bloginfo( $k = '' ) { echo 'UTF-8'; } }
if ( ! function_exists( 'determine_locale' ) ) { function determine_locale() { return 'de_DE'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }

echo "A317: waitlist offer page with Post/Redirect/Get and result page\n";
// Hooks are restored at the end: a second registration would run the waitlist steps twice in later files.
$a317_hooks = $GLOBALS['wpcap_test_hooks'] ?? array();
Waitlists::register();
$a317_saved = array_intersect_key( Settings::all(), array_flip( array( 'enabled_providers', 'require_terms', 'waitlist' ) ) );
Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe' ), 'require_terms' => 0, 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0 ), 'stripe_secret_key' => 'sk_test_123', 'stripe_webhook_secret' => 'whsec_test' ) );
PassCaptain\Payments\Registry::reset();
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
$a317_user = $GLOBALS['wpcap_test_user_id'] ?? 0;
$GLOBALS['wpcap_test_user_id'] = 0;
update_option( Waitlists::QUEUE_OPTION, array() );

/** A fresh offer for $email on a sold-out paid type: [entry, token]. */
$a317_offer = static function ( $email ) use ( $wpdb, $p ) {
	$type = make_type( 1, 10, 2500 );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
	// Far enough ahead for bank transfer.
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), $occ ) );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	$GLOBALS['wpcap_test_transients'] = array();
	$sold = Orders::create( array( 'email' => 'sold-' . $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'a317-' . uniqid( '', true ) ) );
	Orders::confirm_external_payment( $sold['order_id'], 'a317-' . uniqid() );
	$_SERVER['REMOTE_ADDR'] = '10.31.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	$GLOBALS['wpcap_test_transients'] = array();
	Waitlists::join( $event, $occ, $type, $email, 1 );
	PassCaptain\Refunds::request( $sold['order_id'], array(), 'a317', 'a317-r-' . uniqid() );
	Orders::cancel_order( $sold['order_id'], 'a317' );
	Waitlists::run_due( true );
	$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $type, $email ) );
	$token = '';
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( $mail['to'] === $email && preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { $token = $m[1]; break; }
	}
	return array( $entry, $token );
};
/** POSTs the offer form; returns the redirect target or 'page:<html>'. */
$a317_post = static function ( array $post ) {
	$saved = $_POST;
	$_POST = $post + array( 'action' => 'wpcap_accept_waitlist' );
	ob_start();
	try {
		Waitlists::handle_accept_request();
		$out = 'none';
	} catch ( Wpcap_Test_Redirect $e ) {
		$out = $e->getMessage();
	}
	$html = ob_get_clean();
	$_POST = $saved;
	return 'none' === $out ? 'page:' . $html : $out;
};

list( $a317_e, $a317_tok ) = $a317_offer( 'a317-wait@example.com' );
check( 'A317 setup: a paid offer is open', $a317_e && 'offered' === $a317_e->status && 64 === strlen( $a317_tok ) );
$a317_id = (int) $a317_e->waitlist_id;
$a317_first = $a317_post( array( 'waitlist_id' => $a317_id, 'token' => $a317_tok, 'decision' => 'accept', 'provider' => 'manual' ) );
$a317_result = Waitlists::result_url( $a317_id );
check( 'A317 accepting redirects to the result page (PRG), no result page rendered by the POST', '' !== $a317_result && $a317_first === $a317_result && false !== strpos( $a317_result, 'wpcap_waitlist_result=' . $a317_id ) && 'accepted' === Waitlists::get( $a317_id )->status );
$a317_second = $a317_post( array( 'waitlist_id' => $a317_id, 'token' => $a317_tok, 'decision' => 'accept', 'provider' => 'manual' ) );
check( 'A317 a second submit (double click) lands on the same result page, not "Angebot nicht verfügbar"', $a317_second === $a317_result );
$a317_orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email=%s", 'a317-wait@example.com' ) );
check( 'A317 still one order for the entry', 1 === $a317_orders );
// The mail link opened again after the answer.
$_GET = array( 'wpcap_waitlist_accept' => (string) $a317_id, 'token' => $a317_tok );
try { ob_start(); Waitlists::maybe_render_offer_page(); $a317_get = 'none'; } catch ( Wpcap_Test_Redirect $e ) { $a317_get = $e->getMessage(); } finally { ob_end_clean(); }
$_GET = array();
check( 'A317 the offer link after accepting shows the result page', $a317_get === $a317_result );
parse_str( (string) parse_url( $a317_result, PHP_URL_QUERY ), $a317_q );
$a317_html = Waitlists::result_html( $a317_id, $a317_q['wl'] ?? '' );
$a317_order = Orders::get( (int) $a317_e->order_id );
check( 'A317 result page: accepted, order number, waiting for payment with bank details', false !== strpos( $a317_html, 'Angebot angenommen' ) && false !== strpos( $a317_html, PassCaptain\Frontend\Format::order_reference( $a317_order->public_id ) ) && false !== strpos( $a317_html, 'wartet auf die Zahlung' ) && false !== strpos( $a317_html, 'AT61 1904 3002 3457 3201' ) );
check( 'A317 result page with a wrong key shows nothing of the order', false === strpos( Waitlists::result_html( $a317_id, str_repeat( 'a', 32 ) ), 'Bestellnummer' ) );
Orders::confirm_external_payment( (int) $a317_e->order_id, 'a317-paid' );
check( 'A317 result page after payment: order complete', false !== strpos( Waitlists::result_html( $a317_id, $a317_q['wl'] ), 'Bestellung ist abgeschlossen' ) );

// Hosted payment page: straight to the provider, the result page offers "Jetzt bezahlen".
list( $a317_s, $a317_stok ) = $a317_offer( 'a317-stripe@example.com' );
$a317_sid = (int) $a317_s->waitlist_id;
http_reset();
http_push( array( 'id' => 'cs_a317', 'url' => 'https://checkout.stripe.com/c/pay/cs_a317', 'status' => 'open' ), 200, '/v1/checkout/sessions' );
$a317_spost = $a317_post( array( 'waitlist_id' => $a317_sid, 'token' => $a317_stok, 'decision' => 'accept', 'provider' => 'stripe' ) );
check( 'A317 accepting with Stripe redirects to the payment page', 'https://checkout.stripe.com/c/pay/cs_a317' === $a317_spost );
parse_str( (string) parse_url( Waitlists::result_url( $a317_sid ), PHP_URL_QUERY ), $a317_sq );
ob_start();
$a317_shtml = Waitlists::result_html( $a317_sid, $a317_sq['wl'] );
ob_end_clean();
check( 'A317 result page for a hosted payment: "Jetzt bezahlen", form locked after the first submit', false !== strpos( $a317_shtml, 'value="pay"' ) && false !== strpos( $a317_shtml, 'f.dataset.sent' ) );
http_reset();
http_push( array( 'id' => 'cs_a317', 'url' => 'https://checkout.stripe.com/c/pay/cs_a317', 'status' => 'open' ), 200, '/v1/checkout/sessions/cs_a317' );
$a317_pay = $a317_post( array( 'waitlist_id' => $a317_sid, 'wl' => $a317_sq['wl'], 'decision' => 'pay' ) );
check( 'A317 "Jetzt bezahlen" continues the open payment session of the same order', 'https://checkout.stripe.com/c/pay/cs_a317' === $a317_pay );
$a317_badpay = $a317_post( array( 'waitlist_id' => $a317_sid, 'wl' => str_repeat( 'b', 32 ), 'decision' => 'pay' ) );
check( 'A317 "Jetzt bezahlen" with a wrong key goes nowhere near the order', 'https://example.test/' === $a317_badpay );

// Decline.
list( $a317_d, $a317_dtok ) = $a317_offer( 'a317-decline@example.com' );
$a317_did = (int) $a317_d->waitlist_id;
$a317_dpost = $a317_post( array( 'waitlist_id' => $a317_did, 'token' => $a317_dtok, 'decision' => 'decline' ) );
parse_str( (string) parse_url( (string) $a317_dpost, PHP_URL_QUERY ), $a317_dq );
check( 'A317 declining redirects to the result page "Angebot abgelehnt"', $a317_dpost === Waitlists::result_url( $a317_did ) && false !== strpos( Waitlists::result_html( $a317_did, $a317_dq['wl'] ?? '' ), 'Angebot abgelehnt' ) );
// Input errors (offer still open) keep rendering the "Bitte Angaben prüfen" page: no redirect target.
check( 'A317 input errors and unknown tokens get no redirect', '' === Waitlists::answer_target( $a317_did, $a317_dtok, new WP_Error( 'wpcap_terms_required', 'x' ) ) && '' === Waitlists::answer_target( $a317_did, str_repeat( 'c', 64 ), new WP_Error( 'wpcap_waitlist_offer_invalid', 'x' ) ) );

Settings::update( $a317_saved );
PassCaptain\Payments\Registry::reset();
$GLOBALS['wpcap_test_user_id'] = $a317_user;
$GLOBALS['wpcap_test_hooks'] = $a317_hooks;
