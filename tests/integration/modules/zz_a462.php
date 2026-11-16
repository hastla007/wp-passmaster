<?php
/**
 * A462 (extends A283): the waitlist offer, unsubscribe and "Jetzt bezahlen" forms carried a
 * WordPress nonce (12–24 h, bound to the login state) although an offer stays open for offer_hours
 * (default 24, often more): an offer answered the next morning failed with "link expired". The forms
 * now carry a proof bound to the link's own secret (Waitlists::form_sig()); the 303 redirects of the
 * waitlist pages are marked no-store as well. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Waitlists;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! class_exists( 'Wpcap_Test_Redirect' ) ) { final class Wpcap_Test_Redirect extends Exception {} }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $url ) { throw new Wpcap_Test_Redirect( $url ); } }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1 ) { $GLOBALS['wpcap_test_nonce_checks'][] = $action; return 1; } }

echo "A462: waitlist forms without short-lived nonces; redirects no-store\n";
global $wpdb;
$p = $wpdb->prefix;
$a462_saved_user = $GLOBALS['wpcap_test_user_id'] ?? 0;

// A waiting entry with its personal unsubscribe link.
$a462_type = make_type( 1, 4, 2500 );
$a462_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a462_type" );
$a462_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a462_occ" );
$GLOBALS['wpcap_test_transients'] = array();
Orders::create( array( 'email' => 'a462-block@example.com', 'items' => array( array( 'ticket_type_id' => $a462_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a462-block-' . uniqid() ) );
$_SERVER['REMOTE_ADDR'] = '10.162.' . random_int( 0, 255 ) . '.' . random_int( 1, 255 );
Waitlists::join( $a462_event, $a462_occ, $a462_type, 'a462@example.com', 1 );
$a462_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $a462_type, 'a462@example.com' ) );
$a462_token = '';
foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $a462_mail ) {
	if ( 'a462@example.com' === $a462_mail['to'] && preg_match( '/wpcap_waitlist_unsubscribe=(\d+)(?:&amp;|&)token=([a-f0-9]{64})/', $a462_mail['body'], $a462_m ) ) { $a462_token = $a462_m[2]; break; }
}
check( 'A462 setup: waiting entry with unsubscribe link', $a462_entry && 64 === strlen( $a462_token ) );
$a462_id = (int) $a462_entry->waitlist_id;

// The page carries the proof bound to the link, the same for every login state.
$GLOBALS['wpcap_test_user_id'] = 46201;
list( , $a462_html ) = Waitlists::unsubscribe_page( $a462_id, $a462_token );
$GLOBALS['wpcap_test_user_id'] = 0;
list( , $a462_html_anon ) = Waitlists::unsubscribe_page( $a462_id, $a462_token );
$a462_sig = Waitlists::form_sig( 'unsubscribe', $a462_id, $a462_token );
check( 'A462: unsubscribe form carries the link-bound proof (_wpcap_sig)', false !== strpos( $a462_html, 'name="' . Waitlists::SIG_FIELD . '" value="' . $a462_sig . '"' ) );
check( 'A462: the proof does not depend on login state or time', false !== strpos( $a462_html_anon, 'value="' . $a462_sig . '"' ) && 64 === strlen( $a462_sig ) );
check( 'A462: the proof is bound to kind, entry and link secret', $a462_sig !== Waitlists::form_sig( 'offer', $a462_id, $a462_token ) && $a462_sig !== Waitlists::form_sig( 'unsubscribe', $a462_id + 1, $a462_token ) && $a462_sig !== Waitlists::form_sig( 'unsubscribe', $a462_id, str_repeat( 'a', 64 ) ) );

// POST check: a valid proof needs no nonce; without one the nonce of older pages is checked.
$a462_verify = new ReflectionMethod( Waitlists::class, 'verify_form' );
$a462_verify->setAccessible( true );
$a462_saved_post = $_POST;
$GLOBALS['wpcap_test_nonce_checks'] = array();
$_POST = array( Waitlists::SIG_FIELD => $a462_sig );
$a462_verify->invoke( null, 'unsubscribe', $a462_id, $a462_token, 'nonce-action-a462' );
$a462_checks_valid = $GLOBALS['wpcap_test_nonce_checks'] ?? array();
$_POST = array( Waitlists::SIG_FIELD => Waitlists::form_sig( 'unsubscribe', $a462_id, str_repeat( 'b', 64 ) ) );
$a462_verify->invoke( null, 'unsubscribe', $a462_id, $a462_token, 'nonce-action-a462' );
$a462_checks_wrong = $GLOBALS['wpcap_test_nonce_checks'] ?? array();
$_POST = $a462_saved_post;
check( 'A462: a valid proof is accepted without a WordPress nonce check', array() === $a462_checks_valid );
check( 'A462: a wrong proof falls back to the nonce check of older pages', array( 'nonce-action-a462' ) === $a462_checks_wrong );

// Offer and pay forms use the same proof instead of wp_nonce_field().
$a462_src = (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' );
check( 'A462: offer and "Jetzt bezahlen" forms carry form_sig(), no wp_nonce_field()', false !== strpos( $a462_src, "self::form_sig( 'offer', \$waitlist_id, \$token )" ) && false !== strpos( $a462_src, "self::form_sig( 'pay', \$waitlist_id, \$key )" ) && false === strpos( $a462_src, 'wp_nonce_field(' ) );

// The 303 of the waitlist pages is no-store (DONOTCACHEPAGE + nocache_headers via PageCache::no_store()).
$a462_no_store = array();
$a462_cb = static function ( $context ) use ( &$a462_no_store ) { $a462_no_store[] = $context; };
add_action( 'wpcap_page_no_store', $a462_cb );
$a462_redirect = new ReflectionMethod( Waitlists::class, 'redirect' );
$a462_redirect->setAccessible( true );
try { $a462_redirect->invoke( null, home_url( '/' ) . '?wpcap_waitlist_result=1&wl=x' ); } catch ( Wpcap_Test_Redirect $e ) { /* expected */ }
remove_action( 'wpcap_page_no_store', $a462_cb );
check( 'A462: waitlist redirects are marked no-store', in_array( 'waitlist_result', $a462_no_store, true ) );

$GLOBALS['wpcap_test_user_id'] = $a462_saved_user;
