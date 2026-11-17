<?php
/**
 * A564: waitlist double opt-in – a sign-up is `pending` until the confirmation link in the mail is
 * used (POST confirmation page), never gets an offer before, is deleted after `confirm_hours` and
 * records `confirmed_at`. A565: offer page and waitlist mails carry date (with time zone) and
 * location, the offer page shows the legal texts and consent like the checkout (also for free
 * offers, with the version check), and people waiting are told about a new date or location.
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1 ) { return 1; } }
if ( ! function_exists( 'status_header' ) ) { function status_header( $code ) { $GLOBALS['wpcap_test_status'] = (int) $code; } }
if ( ! function_exists( 'determine_locale' ) ) { function determine_locale() { return 'de_DE'; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $t ) { return '<p>' . $t . '</p>'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }

echo "A564/A565: waitlist double opt-in, offer details and legal texts, change mail\n";
$a564_doi_filter = $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] ?? null;
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] );
$a564_delay = $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = 0;
$a564_saved = array_intersect_key( Settings::all(), array_flip( array( 'enabled_providers', 'require_terms', 'waitlist', 'terms_text', 'consumer_info_text' ) ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'require_terms' => 0, 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0, 'close_mail' => 1 ) ) );
update_option( Waitlists::QUEUE_OPTION, array() );

$a564_ctx = static function ( $type ) use ( $wpdb, $p ) {
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
	return array( (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" ), $occ );
};
$a564_join = static function ( $type, $email, $qty = 1 ) use ( $a564_ctx ) {
	list( $event, $occ ) = $a564_ctx( $type );
	$_SERVER['REMOTE_ADDR'] = '10.64.' . random_int( 0, 255 ) . '.' . random_int( 0, 255 );
	$GLOBALS['wpcap_test_transients'] = array();
	return Waitlists::join( $event, $occ, $type, $email, $qty );
};
$a564_entry = static function ( $type, $email ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_waitlist WHERE ticket_type_id=%d AND email=%s", $type, $email ) );
};
$a564_mails = static function ( $email ) { return array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => $m['to'] === $email ) ); };
$a564_confirm_token = static function ( $email ) use ( $a564_mails ) {
	foreach ( array_reverse( $a564_mails( $email ) ) as $mail ) {
		if ( preg_match( '/wpcap_waitlist_confirm=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
};

/* ---- A564: sign-up stays pending until confirmed ---- */
check( 'A564: schema has the double opt-in columns and the setting is on by default', Waitlists::doi_ready() && Waitlists::double_opt_in_enabled() );
$a564_type = make_type( 5, 10, 0 );
$a564_res = $a564_join( $a564_type, 'a564@example.com', 2 );
$a564_e = $a564_entry( $a564_type, 'a564@example.com' );
$a564_token = $a564_confirm_token( 'a564@example.com' );
$a564_first = $a564_mails( 'a564@example.com' )[0] ?? array( 'body' => '', 'subject' => '' );
check( 'A564: join creates a pending entry without queue position', $a564_e && 'pending' === $a564_e->status && 0 === (int) $a564_e->position && null === $a564_e->confirmed_at && '' !== (string) $a564_e->confirm_token_hash );
check( 'A564: join answers that a confirmation is needed (uniform response)', ! is_wp_error( $a564_res ) && ! empty( $a564_res['confirmation_required'] ) && ! isset( $a564_res['position'] ) );
check( 'A564: the only mail is the confirmation mail with the personal link (no "Du stehst auf der Warteliste")', 1 === count( $a564_mails( 'a564@example.com' ) ) && 64 === strlen( $a564_token ) && false !== strpos( $a564_first['subject'], 'bestätige' ) && false === strpos( $a564_first['body'], 'Position' ) );
check( 'A564: only the hash of the confirmation token is stored', hash( 'sha256', $a564_token ) === $a564_e->confirm_token_hash );
Waitlists::run_due( true );
check( 'A564: a pending entry gets no offer (holds no inventory)', 'pending' === $a564_entry( $a564_type, 'a564@example.com' )->status && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations r INNER JOIN {$p}wpcap_order_items i ON i.order_id=r.order_id WHERE i.ticket_type_id=$a564_type AND r.status='active'" ) );
$a564_again = $a564_join( $a564_type, 'a564@example.com', 2 );
check( 'A564: joining again answers the same', $a564_again === $a564_res );
$a564_token2 = $a564_confirm_token( 'a564@example.com' );
check( 'A564: joining again while pending sends a new link and revokes the old one', $a564_token2 !== $a564_token && is_wp_error( Waitlists::confirm( $a564_e->waitlist_id, $a564_token ) ) );

list( $a564_code, $a564_page ) = Waitlists::confirm_page( $a564_e->waitlist_id, $a564_token2 );
check( 'A564: GET confirmation page shows details and a POST button, changes nothing', 200 === $a564_code && false !== strpos( $a564_page, 'method="post"' ) && false !== strpos( $a564_page, Waitlists::CONFIRM_ACTION ) && 'pending' === $a564_entry( $a564_type, 'a564@example.com' )->status );
list( $a564_bad_code ) = Waitlists::confirm_page( $a564_e->waitlist_id, str_repeat( 'f', 64 ) );
check( 'A564: wrong token → 410 page, wrong token confirm → error', 410 === $a564_bad_code && is_wp_error( Waitlists::confirm( $a564_e->waitlist_id, str_repeat( 'f', 64 ) ) ) );
$a564_ok = Waitlists::confirm( $a564_e->waitlist_id, $a564_token2 );
$a564_e = $a564_entry( $a564_type, 'a564@example.com' );
$a564_joined = $a564_mails( 'a564@example.com' );
$a564_joined = end( $a564_joined );
check( 'A564: confirmation makes the entry waiting with a position and records confirmed_at', ! is_wp_error( $a564_ok ) && false === $a564_ok['already'] && 'waiting' === $a564_e->status && (int) $a564_e->position > 0 && null !== $a564_e->confirmed_at );
check( 'A564: the waitlist_joined mail follows the confirmation (position, unsubscribe link)', false !== strpos( (string) $a564_joined['body'], 'Position ' . (int) $a564_e->position ) && false !== strpos( (string) $a564_joined['body'], 'wpcap_waitlist_unsubscribe=' ) );
check( 'A564: confirmation is audited', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='waitlist_confirmed' AND object_id=%d", (int) $a564_e->waitlist_id ) ) );
$a564_twice = Waitlists::confirm( $a564_e->waitlist_id, $a564_token2 );
check( 'A564: a second confirmation is idempotent (already)', ! is_wp_error( $a564_twice ) && true === $a564_twice['already'] );
Waitlists::run_due( true );
check( 'A564: after the confirmation the entry gets its offer', 'offered' === $a564_entry( $a564_type, 'a564@example.com' )->status );

// Unconfirmed sign-ups lapse and are deleted.
$a564_late = $a564_join( $a564_type, 'a564-late@example.com' );
$a564_le = $a564_entry( $a564_type, 'a564-late@example.com' );
$a564_lt = $a564_confirm_token( 'a564-late@example.com' );
$wpdb->update( "{$p}wpcap_waitlist", array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 49 * HOUR_IN_SECONDS ) ), array( 'waitlist_id' => (int) $a564_le->waitlist_id ) );
check( 'A564: confirmation after confirm_hours (48 h) is refused', is_wp_error( Waitlists::confirm( $a564_le->waitlist_id, $a564_lt ) ) && 'pending' === $a564_entry( $a564_type, 'a564-late@example.com' )->status );
$a564_purged = Waitlists::purge_unconfirmed();
check( 'A564: the sweep deletes unconfirmed entries after the window (no address kept)', $a564_purged >= 1 && null === $a564_entry( $a564_type, 'a564-late@example.com' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_waitlist WHERE email_hash=%s", hash( 'sha256', 'a564-late@example.com' ) ) ) );
// A date cancelled before the confirmation: the pending entry is not confirmed.
$a564_type2 = make_type( 5, 10, 0 );
$a564_join( $a564_type2, 'a564-cx@example.com' );
$a564_cx = $a564_entry( $a564_type2, 'a564-cx@example.com' );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=" . (int) $a564_cx->occurrence_id );
check( 'A564: confirming a sign-up for a cancelled date is refused and the entry deleted', is_wp_error( Waitlists::confirm( $a564_cx->waitlist_id, $a564_confirm_token( 'a564-cx@example.com' ) ) ) && null === $a564_entry( $a564_type2, 'a564-cx@example.com' ) );
// Setting off: direct sign-up as before.
Settings::update( array( 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0, 'double_opt_in' => 0 ) ) );
$a564_direct = $a564_join( $a564_type, 'a564-direct@example.com' );
check( 'A564: with double opt-in switched off the entry waits right away', 'waiting' === $a564_entry( $a564_type, 'a564-direct@example.com' )->status && empty( $a564_direct['confirmation_required'] ) );
Settings::update( array( 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0 ) ) );
// Filter: an address verified otherwise may skip the confirmation.
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = false;
$a564_join( $a564_type, 'a564-verified@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] );
check( 'A564: filter wpcap_waitlist_double_opt_in can skip the confirmation', 'waiting' === $a564_entry( $a564_type, 'a564-verified@example.com' )->status );
check( 'A564: status label for pending entries', 'Unbestätigt (E-Mail-Bestätigung offen)' === Waitlists::status_label( 'pending' ) );

/* ---- A565: date, location and legal texts ---- */
$a565_type = make_type( 2, 10, 2500 );
list( , $a565_occ ) = $a564_ctx( $a565_type );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Stadthalle Graz', starts_at='2027-06-05 17:30:00', timezone='Europe/Vienna' WHERE occurrence_id=$a565_occ" );
$a565_when = Orders::format_start( '2027-06-05 17:30:00', 'Europe/Vienna' );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = false;
$a564_join( $a565_type, 'a565@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] );
$a565_joined = $a564_mails( 'a565@example.com' );
Waitlists::run_due( true );
$a565_e = $a564_entry( $a565_type, 'a565@example.com' );
$a565_offer = $a564_mails( 'a565@example.com' );
$a565_offer = end( $a565_offer );
check( 'A565: offer mail names the date with time zone and the location', 'offered' === $a565_e->status && false !== strpos( (string) $a565_offer['body'], $a565_when ) && false !== strpos( (string) $a565_offer['body'], 'Europe/Vienna' ) && false !== strpos( (string) $a565_offer['body'], 'Stadthalle Graz' ) );
$a565_types = PassCaptain\Mail\Types::all();
check( 'A565: waitlist mails offer {event_date} and {event_location}', isset( $a565_types['waitlist_offer']['placeholders']['event_date'], $a565_types['waitlist_joined']['placeholders']['event_location'], $a565_types['waitlist_closed']['placeholders']['event_date'], $a565_types['waitlist_confirm'], $a565_types['waitlist_changed'] ) );
$a565_html = Waitlists::offer_details_html( $a565_e, Orders::get( (int) $a565_e->order_id ) );
check( 'A565: offer page shows date with time zone and location', false !== strpos( $a565_html, $a565_when ) && false !== strpos( $a565_html, 'Stadthalle Graz' ) && false !== strpos( $a565_html, 'Termin' ) );
Settings::update( array( 'require_terms' => 1, 'terms_text' => 'A565 AGB Fassung 1', 'consumer_info_text' => 'A565 Widerrufsbelehrung' ) );
$a565_legal = Waitlists::legal_html();
check( 'A565: offer page shows terms, consumer information, version and consent like the checkout', false !== strpos( $a565_legal, 'A565 AGB Fassung 1' ) && false !== strpos( $a565_legal, 'A565 Widerrufsbelehrung' ) && false !== strpos( $a565_legal, 'name="terms_version" value="' . Settings::terms_version( 'de_DE' ) . '"' ) && false !== strpos( $a565_legal, 'name="accept_terms"' ) );
// Free offers need the consent too (like the free checkout); a changed version is refused.
$a565_free = make_type( 2, 10, 0 );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = false;
$a564_join( $a565_free, 'a565-free@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] );
Waitlists::run_due( true );
$a565_fe = $a564_entry( $a565_free, 'a565-free@example.com' );
$a565_ft = '';
foreach ( array_reverse( $a564_mails( 'a565-free@example.com' ) ) as $a565_m ) { if ( preg_match( '/wpcap_waitlist_accept=\d+(?:&amp;|&)token=([a-f0-9]{64})/', $a565_m['body'], $a565_mm ) ) { $a565_ft = $a565_mm[1]; break; } }
$a565_noterms = Waitlists::accept_offer( $a565_fe->waitlist_id, $a565_ft, array() );
check( 'A565: free offer without consent is refused when terms are required', is_wp_error( $a565_noterms ) && 'wpcap_terms_required' === $a565_noterms->get_error_code() );
$a565_changed = Waitlists::accept_offer( $a565_fe->waitlist_id, $a565_ft, array( 'accept_terms' => 1, 'terms_version' => 'deadbeefdeadbeef', 'locale' => 'de_DE' ) );
check( 'A565: consent to a version that changed meanwhile is refused', is_wp_error( $a565_changed ) && 'wpcap_terms_changed' === $a565_changed->get_error_code() );
$a565_accepted = Waitlists::accept_offer( $a565_fe->waitlist_id, $a565_ft, array( 'accept_terms' => 1, 'terms_version' => Settings::terms_version( 'de_DE' ), 'locale' => 'de_DE' ) );
$a565_order = Orders::get( (int) $a565_fe->order_id );
check( 'A565: free acceptance stores the accepted version and the buyer language', ! is_wp_error( $a565_accepted ) && Settings::terms_version( 'de_DE' ) === $a565_order->terms_version && 'de_DE' === $a565_order->locale );
Settings::update( array( 'require_terms' => 0 ) );

// New date / location: waiting people and open offers are told.
$a565_wait = make_type( 1, 10, 0 );
list( , $a565_wocc ) = $a564_ctx( $a565_wait );
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = false;
$a564_join( $a565_wait, 'a565-wait@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] );
$wpdb->update( "{$p}wpcap_waitlist", array( 'status' => 'waiting' ), array( 'ticket_type_id' => $a565_wait, 'email' => 'a565-wait@example.com' ) );
$a565_old_start = (string) $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$a565_wocc" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at=DATE_ADD(starts_at, INTERVAL 1 DAY), location='Neue Halle' WHERE occurrence_id=$a565_wocc" );
$a565_before = count( $a564_mails( 'a565-wait@example.com' ) );
$a565_sent = Waitlists::on_occurrence_changed( $a565_wocc, array( 'starts_at' => array( $a565_old_start, '' ), 'location' => array( 'Wien', 'Neue Halle' ) ) );
$a565_cm = $a564_mails( 'a565-wait@example.com' );
$a565_cm = end( $a565_cm );
check( 'A565: a new date/location mails the people waiting (old and new details, unsubscribe link)', 1 === $a565_sent && $a565_before + 1 === count( $a564_mails( 'a565-wait@example.com' ) ) && false !== strpos( (string) $a565_cm['body'], 'Neue Halle' ) && false !== strpos( (string) $a565_cm['body'], 'Wien' ) && false !== strpos( (string) $a565_cm['body'], Orders::format_start( $a565_old_start, 'Europe/Vienna' ) ) && false !== strpos( (string) $a565_cm['body'], 'wpcap_waitlist_unsubscribe=' ) );
check( 'A565: other changes (status, end) send no waitlist mail', 0 === Waitlists::on_occurrence_changed( $a565_wocc, array( 'ends_at' => array( '', '2030-01-01 00:00:00' ) ) ) );
Settings::update( array( 'waitlist' => array( 'auto_offers' => 1, 'offer_hours' => 24, 'max_offers_per_run' => 10, 'strict_fifo' => 0, 'change_mail' => 0 ) ) );
check( 'A565: setting change_mail off sends nothing', 0 === Waitlists::on_occurrence_changed( $a565_wocc, array( 'location' => array( 'Neue Halle', 'Wien' ) ) ) );

// Cleanup: no open offers or queue entries leak into later files.
foreach ( (array) $wpdb->get_col( "SELECT waitlist_id FROM {$p}wpcap_waitlist WHERE status IN ('waiting','offered','accepted','pending') AND ticket_type_id IN ($a564_type,$a564_type2,$a565_type,$a565_free,$a565_wait)" ) as $a564_id ) { Waitlists::close( (int) $a564_id, 'removed' ); }
update_option( Waitlists::QUEUE_OPTION, array() );
Settings::update( $a564_saved + array( 'terms_text' => '', 'consumer_info_text' => '' ) );
if ( null === $a564_delay ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_waitlist_process_delay'] = $a564_delay; }
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = null === $a564_doi_filter ? false : $a564_doi_filter;
