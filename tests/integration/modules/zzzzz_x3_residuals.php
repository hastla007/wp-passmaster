<?php
/**
 * X3 residuals: box office consent fields and price-rule explanation, ticket list for date-level
 * staff, precise undo of re-entries, event waitlist offer window setting, consent on ticket
 * reassignment, campaign report refunds and split, REST tax profiles, bulk retry and dashboard
 * widget on the system status page.
 *
 * Included from run.php after zzzzz_w9_access.php (w9_* helpers, ticket_code_for from mobile.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\StatusPage;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\BoxOffice\Service as X3Pos;
use PassCaptain\Checkin;
use PassCaptain\Orders;
use PassCaptain\Ops\Status;
use PassCaptain\Reports\Data;
use PassCaptain\Sales\Consents;
use PassCaptain\Sales\PriceRules;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
PassCaptain\Modules\SalesRules::register(); // Price rules and consents (idempotent; also registered by zz_sales_rules.php).
w9_admin();
$GLOBALS['wpcap_test_transients'] = array();

function x3_access_token( $order_id, $from = 0 ) {
	$public = (string) Orders::get( $order_id )->public_id;
	$token = '';
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $from ) as $m ) {
		if ( str_contains( $m['body'], $public ) && preg_match( '/wpcap_access=([a-f0-9]{64})/', $m['body'], $mm ) ) { $token = $mm[1]; }
	}
	return $token;
}
function x3_state( $ticket_id ) { global $wpdb; return $wpdb->get_row( "SELECT inside,entries FROM {$wpdb->prefix}wpcap_admission_state WHERE ticket_id=" . (int) $ticket_id ); }
function x3_ticket( $code ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) ); }

/* ------------------------------------------------------------------ 2. Box office: consent fields, price rule */
echo "X3: box office consent fields and price-rule explanation\n";
$x3_type = make_type( 20, 10, 2500 );
$x3_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x3_type" );
$x3_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$x3_occ" );
$x3_field = EventsPage::save_participant_field( array( 'ticket_type_id' => $x3_type, 'field_key' => 'photo', 'label' => 'Fotoeinwilligung', 'field_type' => 'consent', 'required' => '1', 'consent_text' => 'Ich bin mit Fotos an der Kasse einverstanden.', 'consent_version' => '3' ) );
$x3_until = ( new DateTimeImmutable( '@' . ( time() + 2 * 86400 ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
PriceRules::create( $x3_event, array( 'kind' => 'early_bird', 'label' => 'Frühbucher X3', 'ticket_type_id' => $x3_type, 'price_mode' => 'fixed', 'amount' => '19,00', 'until' => $x3_until ) );
PriceRules::flush_cache();
$x3_types = X3Pos::ticket_types( $x3_occ );
$x3_consent = array_values( array_filter( $x3_types[0]['fields'], static fn( $f ) => 'consent' === $f['type'] ) );
check( 'POS ticket types carry the consent text/version of consent fields and the price-rule explanation', is_array( $x3_field ) && 1 === count( $x3_consent ) && 'Ich bin mit Fotos an der Kasse einverstanden.' === $x3_consent[0]['consent_text'] && '3' === $x3_consent[0]['consent_version'] && str_contains( $x3_types[0]['price_rule'], 'Frühbucher X3' ) && 1900 === $x3_types[0]['price_minor'] );
X3Pos::open_session( 0 );
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return ''; } }
// The occurrence picker lists the first 300 occurrences by start: move this one to the front for the render.
$x3_start = $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$x3_occ" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() - 29 * 86400 ) . "' WHERE occurrence_id=$x3_occ" );
ob_start();
$_GET['occurrence_id'] = $x3_occ;
try { PassCaptain\BoxOffice\Page::render(); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
$x3_html = (string) ob_get_clean();
unset( $_GET['occurrence_id'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", $x3_start, $x3_occ ) );
check( 'POS screen shows the price-rule explanation next to the ticket type', str_contains( $x3_html, 'Frühbucher X3' ) );
$x3_missing = X3Pos::sell( array( 'occurrence_id' => $x3_occ, 'quantities' => array( $x3_type => 1 ), 'attendees' => array( $x3_type => array( array( 'name' => 'Kim' ) ) ), 'method' => 'cash', 'form_key' => 'x3-' . bin2hex( random_bytes( 10 ) ) ) );
$x3_sale = X3Pos::sell( array( 'occurrence_id' => $x3_occ, 'quantities' => array( $x3_type => 1 ), 'attendees' => array( $x3_type => array( array( 'name' => 'Kim', 'email' => 'kim@example.com', 'answers' => array( 'photo' => '1' ) ) ) ), 'method' => 'cash', 'form_key' => 'x3-' . bin2hex( random_bytes( 10 ) ) ) );
$x3_cons = ! is_wp_error( $x3_sale ) ? Consents::for_order( (int) $x3_sale['sale']->order_id ) : array();
check( 'a required consent must be ticked at the box office', is_wp_error( $x3_missing ) );
check( 'box office consent is logged like checkout (text, version, hash, attendee) with source box_office', 1 === count( $x3_cons ) && 'box_office' === $x3_cons[0]->source && 'Ich bin mit Fotos an der Kasse einverstanden.' === $x3_cons[0]->consent_text && '3' === $x3_cons[0]->text_version && Consents::text_hash( 'Ich bin mit Fotos an der Kasse einverstanden.', '3' ) === $x3_cons[0]->text_hash && 'kim@example.com' === $x3_cons[0]->subject_email );

/* ------------------------------------------------------------------ 3. Tickets list for date-level staff */
echo "X3: tickets admin list for date-level staff\n";
$x3d = w9_type( 0 );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $x3d['event'], 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$x3d_occ2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $x3d_occ2, 'name' => 'Tag B', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
$x3d_type2 = (int) $wpdb->insert_id;
$x3d_o1 = w9_order( $x3d['type'], 2, 'x3-day-a@example.com' );
$x3d_o2 = w9_order( $x3d_type2, 1, 'x3-day-b@example.com' );
$wpdb->insert( "{$p}wpcap_occurrence_staff", array( 'occurrence_id' => $x3d['occ'], 'event_id' => $x3d['event'], 'user_id' => 9961, 'capability' => 'wpcap_checkin' ) );
w9_as( 9961, array( 'wpcap_checkin' ) );
$x3_by_event = TicketsPage::search( array( 'event_id' => $x3d['event'] ) );
$x3_by_mail = TicketsPage::search( array( 'search' => 'x3-day-' ) );
$x3_occs = array_unique( array_map( static fn( $t ) => (int) $t->occurrence_id, $x3_by_event['rows'] ) );
check( 'date-level staff see the tickets of their assigned date only (event filter and search)', 2 === $x3_by_event['total'] && array( $x3d['occ'] ) === array_values( $x3_occs ) && 2 === $x3_by_mail['total'] && ! array_filter( $x3_by_mail['rows'], static fn( $t ) => (int) $t->order_id === $x3d_o2 ) );
w9_as( 9962, array( 'wpcap_checkin' ) );
check( 'staff without any assignment see no tickets', 0 === TicketsPage::search( array( 'event_id' => $x3d['event'] ) )['total'] );
w9_admin();
check( 'administrators still see every date', 3 === TicketsPage::search( array( 'event_id' => $x3d['event'] ) )['total'] );

/* ------------------------------------------------------------------ 4. Undo after re-entry */
echo "X3: undo check-in restores the previous state precisely\n";
Settings::update( array( 'reentry_after_checkout' => 1, 'max_reentries' => 0 ) );
$x3u = w9_type( 0 );
$x3u_order = w9_order( $x3u['type'], 2, 'x3-undo@example.com' );
$x3u_codes = ticket_code_for( $x3u_order );
$x3u_t = x3_ticket( $x3u_codes[0] );
Checkin::perform( $x3u_codes[0], array( 'source' => 'web' ) );
Checkin::check_out( $x3u_codes[0], array( 'source' => 'web' ) );
$x3u_re = Checkin::perform( $x3u_codes[0], array( 'source' => 'web' ) );
$x3u_undo = TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x3u_t->ticket_id ) );
$x3u_s = x3_state( $x3u_t->ticket_id );
check( 'undo after a re-entry takes back only the re-entry: ticket stays checked in, one entry left, outside', is_array( $x3u_re ) && ! empty( $x3u_re['reentry'] ) && ! is_wp_error( $x3u_undo ) && 'checked_in' === x3_ticket( $x3u_codes[0] )->status && 1 === (int) $x3u_s->entries && 0 === (int) $x3u_s->inside && 1 === (int) $x3u_undo['remaining_entries'] );
$x3u_again = Checkin::perform( $x3u_codes[0], array( 'source' => 'web' ) );
check( 'after the undo the person can re-enter again (still checked out)', is_array( $x3u_again ) && ! empty( $x3u_again['reentry'] ) && 2 === (int) x3_state( $x3u_t->ticket_id )->entries );
TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x3u_t->ticket_id ) );
$x3u_last = TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x3u_t->ticket_id ) );
check( 'undoing the remaining first entry makes the ticket valid again', ! is_wp_error( $x3u_last ) && 0 === (int) $x3u_last['remaining_entries'] && 'valid' === x3_ticket( $x3u_codes[0] )->status && 0 === (int) x3_state( $x3u_t->ticket_id )->entries );
$x3u_none = TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x3u_t->ticket_id ) );
check( 'nothing left to undo is refused', is_wp_error( $x3u_none ) && 'wpcap_ticket_not_checked_in' === $x3u_none->get_error_code() );
// Simple single entry without re-entry (regression).
$x3u_t2 = x3_ticket( $x3u_codes[1] );
Checkin::perform( $x3u_codes[1], array( 'source' => 'web' ) );
$x3u_single = TicketsPage::undo_checkin( array( 'ticket_id' => (int) $x3u_t2->ticket_id ) );
check( 'single check-in undo: valid again, no entry, outside', ! is_wp_error( $x3u_single ) && 'valid' === x3_ticket( $x3u_codes[1] )->status && 0 === (int) x3_state( $x3u_t2->ticket_id )->entries && 0 === (int) x3_state( $x3u_t2->ticket_id )->inside );
Settings::update( array( 'reentry_after_checkout' => 0 ) );
// Multi-entry ticket (policy): in, out, in → undo keeps the first entry, state derived from the rest.
$x3m = w9_type( 0 );
PassCaptain\Admission\Policies::save( $x3m['type'], array( 'policy' => 'multi_entry', 'max_entries' => '3' ) );
$x3m_order = w9_order( $x3m['type'], 1, 'x3-multi@example.com' );
$x3m_code = ticket_code_for( $x3m_order )[0];
$x3m_t = x3_ticket( $x3m_code );
Checkin::perform( $x3m_code, array( 'source' => 'web' ) );
Checkin::check_out( $x3m_code, array( 'source' => 'web' ) );
Checkin::perform( $x3m_code, array( 'source' => 'web' ) );
$x3m_found = Checkin::lookup( $x3m_code );
$x3m_undo = PassCaptain\Admission\Engine::undo_last( $x3m_found, 'x3' );
$x3m_s = x3_state( $x3m_t->ticket_id );
check( 'multi-entry undo: last entry taken back, one entry left, outside (last movement was the check-out), ticket valid', ! is_wp_error( $x3m_undo ) && 1 === (int) $x3m_s->entries && 0 === (int) $x3m_s->inside && 'valid' === x3_ticket( $x3m_code )->status );
Checkin::perform( $x3m_code, array( 'source' => 'web' ) );
Checkin::perform( $x3m_code, array( 'source' => 'web' ) );
$x3m_before = (int) x3_state( $x3m_t->ticket_id )->entries;
PassCaptain\Admission\Engine::undo_last( $x3m_found, 'x3' );
$x3m_s2 = x3_state( $x3m_t->ticket_id );
check( 'multi-entry undo after two entries in a row: the earlier entry keeps the person inside', 3 === $x3m_before && 2 === (int) $x3m_s2->entries && 1 === (int) $x3m_s2->inside );

/* ------------------------------------------------------------------ 5. Waitlist offer window setting */
echo "X3: event waitlist offer window is a setting\n";
$x3_sections = apply_filters( 'wp_pass_captain_settings_sections_v1', array() );
check( 'setting waitlist_offer_hours is registered (default 24) on the settings page', 24 === PassCaptain\Waitlists::offer_hours() && isset( $x3_sections['general']['fields']['waitlist_offer_hours'] ) && 24 === (int) Settings::defaults()['waitlist_offer_hours'] );
Settings::update( array( 'waitlist_offer_hours' => 6 ) );
$x3_six = PassCaptain\Waitlists::offer_hours();
Settings::update( array( 'waitlist_offer_hours' => 5000 ) );
$x3_capped = PassCaptain\Waitlists::offer_hours();
$x3_filter = static fn( $h ) => 2;
add_filter( 'wpcap_waitlist_offer_hours', $x3_filter );
$x3_filtered = PassCaptain\Waitlists::offer_hours();
remove_filter( 'wpcap_waitlist_offer_hours', $x3_filter );
Settings::update( array( 'waitlist_offer_hours' => 24 ) );
check( 'offer window follows the setting (capped at 720 h); the filter still overrides it', 6 === $x3_six && 720 === $x3_capped && 2 === $x3_filtered );

/* ------------------------------------------------------------------ 6. Consent on reassignment */
echo "X3: consent does not carry over to a new attendee\n";
$x3c_type = make_type( 20, 10, 0 );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ) . "' WHERE occurrence_id=(SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$x3c_type)" );
$x3c_field = EventsPage::save_participant_field( array( 'ticket_type_id' => $x3c_type, 'field_key' => 'photo', 'label' => 'Fotoeinwilligung', 'field_type' => 'consent', 'required' => '0', 'consent_text' => 'Fotos sind ok.', 'consent_version' => '1' ) );
$x3c_fid = (int) $x3c_field['field_id'];
$x3c_mails = count( $GLOBALS['wpcap_test_mails'] );
$GLOBALS['wpcap_test_transients'] = array();
$x3c = Orders::create( array( 'email' => 'x3-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $x3c_type, 'quantity' => 3 ) ), 'attendees' => array( array( 'ticket_type_id' => $x3c_type, 'name' => 'Ana', 'email' => 'x3-ana@example.com', 'answers' => array( 'photo' => '1' ) ), array( 'ticket_type_id' => $x3c_type, 'name' => 'Ben', 'email' => 'x3-ben@example.com', 'answers' => array( 'photo' => '1' ) ) ), 'idempotency_key' => 'x3c-' . uniqid() ), array( 'skip_rate_limit' => true ) );
$x3c_token = x3_access_token( $x3c['order_id'], $x3c_mails );
$x3c_order = Orders::get( $x3c['order_id'] );
$x3c_tickets = $wpdb->get_results( "SELECT ticket_id,public_id,attendee_email FROM {$p}wpcap_tickets WHERE order_id=" . (int) $x3c['order_id'] . ' ORDER BY ticket_id' );
$x3c_ana = $x3c_tickets[0];
$x3c_mails = count( $GLOBALS['wpcap_test_mails'] );
$x3c_assign = PassCaptain\TicketAssignments::assign( $x3c_order->public_id, $x3c_token, $x3c_ana->public_id, 'Cleo', 'x3-cleo@example.com' );
$x3c_old = $wpdb->get_row( "SELECT * FROM {$p}wpcap_consents WHERE ticket_id=" . (int) $x3c_ana->ticket_id . ' ORDER BY consent_id LIMIT 1' );
$x3c_link = '';
foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $x3c_mails ) as $m ) {
	if ( 'x3-cleo@example.com' === $m['to'] && preg_match( '/wpcap_consent=([a-f0-9]{64})/', $m['body'], $mm ) ) { $x3c_link = $mm[1]; }
}
check( 'reassignment to another person ends the previous attendee\'s consent (kept as proof) and deletes the answer', ! is_wp_error( $x3c_assign ) && $x3c_old && 'x3-ana@example.com' === $x3c_old->subject_email && null !== $x3c_old->withdrawn_at && 'holder_changed' === $x3c_old->withdrawn_source && null === $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . (int) $x3c_ana->ticket_id . " AND field_id=$x3c_fid" ) && isset( Consents::missing_for_ticket( (int) $x3c_ana->ticket_id )[ $x3c_fid ] ) );
check( 'the new attendee gets a consent link in the ticket mail (ticket code as capability)', '' !== $x3c_link && hash( 'sha256', $x3c_link ) === (string) $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $x3c_ana->ticket_id ) );
$x3c_page = Consents::attendee_page( $x3c_link );
$x3c_granted = Consents::attendee_page( $x3c_link, array( $x3c_fid ) );
$x3c_new = $wpdb->get_row( "SELECT * FROM {$p}wpcap_consents WHERE ticket_id=" . (int) $x3c_ana->ticket_id . ' AND withdrawn_at IS NULL' );
check( 'consent page shows the open consent; ticking records it for the new attendee (source attendee_link, own text version)', is_string( $x3c_page ) && str_contains( $x3c_page, 'Fotos sind ok.' ) && str_contains( $x3c_page, 'name="wpcap_consent_fields[]"' ) && is_string( $x3c_granted ) && str_contains( $x3c_granted, 'keine Einwilligungen offen' ) && $x3c_new && 'x3-cleo@example.com' === $x3c_new->subject_email && 'attendee_link' === $x3c_new->source && '1' === $x3c_new->text_version && '1' === $wpdb->get_var( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=" . (int) $x3c_ana->ticket_id . " AND field_id=$x3c_fid" ) );
check( 'an unknown or rotated code does not open the consent page', null === Consents::attendee_page( str_repeat( 'b', 64 ) ) );
$x3c_ben = $x3c_tickets[1];
$x3c_transfer = Orders::transfer_ticket( $x3c_order->public_id, $x3c_token, $x3c_ben->public_id, 'x3-dan@example.com' );
$x3c_ben_old = $wpdb->get_row( "SELECT * FROM {$p}wpcap_consents WHERE ticket_id=" . (int) $x3c_ben->ticket_id . ' ORDER BY consent_id LIMIT 1' );
check( 'a transfer ends the previous holder\'s consent as well', ! is_wp_error( $x3c_transfer ) && 'holder_changed' === $x3c_ben_old->withdrawn_source && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consents WHERE ticket_id=" . (int) $x3c_ben->ticket_id . ' AND withdrawn_at IS NULL' ) );
$x3c_buyer_ticket = $x3c_tickets[2];
$x3c_section = Consents::portal_section( $x3c_order );
$x3c_denied = Consents::grant_for_order( $x3c_order, (int) $x3c_ana->ticket_id, $x3c_fid );
$x3c_portal = Consents::grant_for_order( $x3c_order, (int) $x3c_buyer_ticket->ticket_id, $x3c_fid );
$x3c_portal_row = is_int( $x3c_portal ) ? Consents::get( $x3c_portal ) : null;
check( 'portal: the buyer consents for a ticket they hold (source portal); tickets of other people are refused', str_contains( $x3c_section, 'name="wpcap_consent_grant" value="' . (int) $x3c_buyer_ticket->ticket_id . '"' ) && is_wp_error( $x3c_denied ) && 'wpcap_consent_not_holder' === $x3c_denied->get_error_code() && $x3c_portal_row && 'portal' === $x3c_portal_row->source && 'x3-buyer@example.com' === $x3c_portal_row->subject_email );
$x3c_same = PassCaptain\TicketAssignments::assign( $x3c_order->public_id, $x3c_token, $x3c_ana->public_id, 'Cleo M.', 'x3-cleo@example.com' );
check( 'correcting the name of the same attendee keeps the consent', ! is_wp_error( $x3c_same ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_consents WHERE ticket_id=" . (int) $x3c_ana->ticket_id . ' AND withdrawn_at IS NULL' ) );

/* ------------------------------------------------------------------ 7. Campaign report: refunds and split */
echo "X3: campaign report attributes refunds and splits orders with two codes\n";
$x3r_type = make_type( 50, 10, 1000 );
$x3r_event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$x3r_type" );
$x3r_order = static function ( $qty ) use ( $x3r_type ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => 'x3-report@example.com', 'items' => array( array( 'ticket_type_id' => $x3r_type, 'quantity' => $qty ) ), 'idempotency_key' => 'x3r-' . uniqid( '', true ) ), array( 'skip_rate_limit' => true ) );
	Orders::confirm_manual_payment( $r['order_id'] );
	return (int) $r['order_id'];
};
$x3r_now = gmdate( 'Y-m-d H:i:s' );
$x3r_campaign = static function ( $code ) use ( $wpdb, $p, $x3r_event, $x3r_now ) {
	$wpdb->insert( "{$p}wpcap_campaigns", array( 'event_id' => $x3r_event, 'code' => $code, 'name' => $code, 'kind' => 'fixed', 'discount_value' => 100, 'currency' => 'EUR', 'status' => 'active', 'created_at' => $x3r_now ) );
	return (int) $wpdb->insert_id;
};
$x3r_use = static function ( $campaign, $order, $code, $discount ) use ( $wpdb, $p, $x3r_now ) {
	$wpdb->insert( "{$p}wpcap_campaign_uses", array( 'campaign_id' => $campaign, 'order_id' => $order, 'code' => $code, 'discount_minor' => $discount, 'status' => 'committed', 'created_at' => $x3r_now ) );
};
$x3r_refund = static function ( $order, $amount ) use ( $wpdb, $p, $x3r_now ) {
	$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $order, 'idempotency_key' => hash( 'sha256', uniqid( 'x3r', true ) ), 'amount_minor' => $amount, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'reason' => 'x3', 'status' => 'completed', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => $x3r_now, 'updated_at' => $x3r_now ) );
};
$x3r_a = $x3r_campaign( 'X3A' . mt_rand( 100, 999 ) );
$x3r_b = $x3r_campaign( 'X3B' . mt_rand( 100, 999 ) );
$x3r_o1 = $x3r_order( 4 ); // 4000, codes A (300) + B (100).
$x3r_use( $x3r_a, $x3r_o1, 'X3A', 300 );
$x3r_use( $x3r_b, $x3r_o1, 'X3B', 100 );
$x3r_o2 = $x3r_order( 1 ); // 1000, code A only.
$x3r_use( $x3r_a, $x3r_o2, 'X3A', 100 );
$x3r_o3 = $x3r_order( 1 ); // 1000, without code.
$x3r_refund( $x3r_o1, 2000 );
$x3r_refund( $x3r_o3, 500 );
$x3r_report = Data::build( array( 'from' => gmdate( 'Y-m-d', time() - 86400 ), 'to' => gmdate( 'Y-m-d', time() + 86400 ), 'event_id' => $x3r_event ), null );
$x3r_rows = $x3r_report['campaigns'];
$x3r_sum = static fn( $field ) => array_sum( array_map( static fn( $r ) => (int) $r[ $field ], $x3r_rows ) );
check( 'order with two codes is split 3:1 by discount (revenue, tickets, order) and not double-counted', 3000 + 1000 === $x3r_rows[ $x3r_a ]['revenue'] && 1000 === $x3r_rows[ $x3r_b ]['revenue'] && 3 + 1 === $x3r_rows[ $x3r_a ]['tickets'] && 1 === $x3r_rows[ $x3r_b ]['tickets'] && 2 === $x3r_rows[ $x3r_a ]['orders'] && 0 === $x3r_rows[ $x3r_b ]['orders'] && 3 === $x3r_sum( 'orders' ) && $x3r_report['total']['gross'] === $x3r_sum( 'revenue' ) );
check( 'refunds are attributed per campaign in the same split; net revenue per row; rows add up to the report refunds', 1500 === $x3r_rows[ $x3r_a ]['refunded'] && 500 === $x3r_rows[ $x3r_b ]['refunded'] && 500 === $x3r_rows[0]['refunded'] && 2500 === $x3r_rows[ $x3r_a ]['net_revenue'] && $x3r_report['total']['refunded'] === $x3r_sum( 'refunded' ) );
check( 'split helper: exact, deterministic, equal parts without weights', array( 0 => 75, 1 => 25 ) === Data::split_by_weight( 100, array( 300, 100 ) ) && array( 0 => 34, 1 => 33, 2 => 33 ) === Data::split_by_weight( 100, array( 0, 0, 0 ) ) && array( 0 => 1, 1 => 0 ) === Data::split_by_weight( 1, array( 1, 1 ) ) && array( 0 => 1, 1 => 0 ) === Data::split_by_weight( 1, array( 300, 100 ) ) );
$x3r_csv = Data::csv_rows( $x3r_report );
check( 'CSV export has the refund columns for campaigns', (bool) array_filter( $x3r_csv, static fn( $r ) => is_array( $r ) && in_array( 'Umsatz nach Erstattung', $r, true ) ) );

/* ------------------------------------------------------------------ 8. REST tax profiles */
echo "X3: REST tax profiles (label, note, activation)\n";
$x3t_req = static function ( array $body ) { $r = new WP_REST_Request( 'POST', '/wp-pass-captain/v1/tax-profiles' ); $r->set_body( wp_json_encode( $body ) ); $r->set_header( 'content-type', 'application/json' ); foreach ( $body as $k => $v ) { $r->set_param( $k, $v ); } return $r; };
$x3t_ver = 'x3-' . mt_rand( 1000, 9999 );
$x3t = PassCaptain\Rest\Controller::save_tax_profile( $x3t_req( array( 'country_code' => 'LU', 'tax_class' => 'culture', 'rate_basis_points' => 300, 'version' => $x3t_ver, 'effective_from' => '2031-01-01', 'label' => 'Kultur LU', 'place_of_supply_note' => 'Ort der Veranstaltung', 'activate' => true ) ) );
$x3t_data = $x3t instanceof WP_REST_Response ? $x3t->get_data() : array();
$x3t_row = $x3t_data ? PassCaptain\Sales\TaxProfiles::get( $x3t_data['tax_profile_id'] ) : null;
check( 'REST creates the profile with label and place-of-supply note and activates it', $x3t_row && 'Kultur LU' === $x3t_row->label && 'Ort der Veranstaltung' === $x3t_row->place_of_supply_note && 'active' === $x3t_row->status && 201 === $x3t->get_status() && 'active' === $x3t_data['status'] );
$x3t_overlap = PassCaptain\Rest\Controller::save_tax_profile( $x3t_req( array( 'country_code' => 'LU', 'tax_class' => 'culture', 'rate' => '3', 'version' => $x3t_ver . 'b', 'effective_from' => '2031-06-01', 'activate' => 1 ) ) );
$x3t_draft = is_wp_error( $x3t_overlap ) ? (int) ( $x3t_overlap->get_error_data()['tax_profile_id'] ?? 0 ) : 0;
check( 'activation follows the admin rules: overlapping active version refused, the draft is kept', is_wp_error( $x3t_overlap ) && 'wpcap_tax_profile_overlap' === $x3t_overlap->get_error_code() && $x3t_draft && 'draft' === PassCaptain\Sales\TaxProfiles::get( $x3t_draft )->status );
$x3t_upd = PassCaptain\Rest\Controller::save_tax_profile( $x3t_req( array( 'tax_profile_id' => $x3t_draft, 'country_code' => 'LU', 'tax_class' => 'culture', 'rate' => '3', 'version' => $x3t_ver . 'b', 'effective_from' => '2031-06-01', 'label' => 'Neu' ) ) );
$x3t_frozen = PassCaptain\Rest\Controller::save_tax_profile( $x3t_req( array( 'tax_profile_id' => $x3t_data['tax_profile_id'], 'country_code' => 'LU', 'tax_class' => 'culture', 'rate' => '5', 'version' => $x3t_ver, 'effective_from' => '2031-01-01' ) ) );
$x3t_invalid = PassCaptain\Rest\Controller::save_tax_profile( $x3t_req( array( 'country_code' => 'LUX', 'version' => 'v', 'effective_from' => '2031-01-01', 'rate' => '3' ) ) );
check( 'drafts are updated (200), active versions frozen, invalid input refused with the admin validation', $x3t_upd instanceof WP_REST_Response && 200 === $x3t_upd->get_status() && 'Neu' === PassCaptain\Sales\TaxProfiles::get( $x3t_draft )->label && is_wp_error( $x3t_frozen ) && 'wpcap_tax_profile_frozen' === $x3t_frozen->get_error_code() && is_wp_error( $x3t_invalid ) && 'wpcap_tax_profile_country' === $x3t_invalid->get_error_code() );

/* ------------------------------------------------------------------ 9. System status: bulk retry, dashboard widget */
echo "X3: system status bulk retry and dashboard widget\n";
$x3s_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='cancelled' WHERE status='dead_letter'" );
$x3s_ids = array();
for ( $i = 0; $i < 5; $i++ ) {
	$wpdb->insert( "{$p}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', uniqid( 'x3s', true ) ), 'job_type' => 'x3_test', 'payload' => '{}', 'run_after' => $x3s_now, 'status' => 'dead_letter', 'attempts' => 8, 'last_error' => 'x3', 'created_at' => $x3s_now, 'updated_at' => $x3s_now ) );
	$x3s_ids[] = (int) $wpdb->insert_id;
}
w9_as( 9963, array( 'wpcap_manage_orders' ) );
$x3s_denied = StatusPage::queue_bulk_retry( array( 'queue' => 'jobs' ) );
w9_admin();
$x3s_cap = static fn() => 3;
add_filter( 'wpcap_ops_bulk_retry_cap', $x3s_cap );
$x3s_first = StatusPage::queue_bulk_retry( array( 'queue' => 'jobs' ) );
$x3s_queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_jobs WHERE job_type='x3_test' AND status='queued' AND attempts=0" );
check( 'bulk retry needs manage_options; one click requeues at most the cap (oldest first) and reports the rest', is_wp_error( $x3s_denied ) && 5 === Status::failed_count( 'jobs' ) + 3 && 3 === $x3s_first['retried'] && 2 === $x3s_first['remaining'] && 3 === $x3s_queued && 'queued' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=" . $x3s_ids[0] ) && 'dead_letter' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_jobs WHERE job_id=" . $x3s_ids[4] ) );
$x3s_audit = w9_last_audit( 'ops_queue_retry_bulk' );
check( 'bulk retry is audited (summary plus each item)', $x3s_audit && str_contains( (string) $x3s_audit->details, '"retried":3' ) && 3 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='ops_queue_retry' AND object_id IN (" . implode( ',', $x3s_ids ) . ')' ) );
$x3s_second = StatusPage::queue_bulk_retry( array( 'queue' => 'jobs' ) );
remove_filter( 'wpcap_ops_bulk_retry_cap', $x3s_cap );
$x3s_bad = StatusPage::queue_bulk_retry( array( 'queue' => 'nope' ) );
check( 'second click retries the rest; unknown queues are refused', 2 === $x3s_second['retried'] && 0 === $x3s_second['remaining'] && is_wp_error( $x3s_bad ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='cancelled' WHERE job_type='x3_test'" );
if ( ! function_exists( 'wp_add_dashboard_widget' ) ) { function wp_add_dashboard_widget( $id, $title, $callback ) { $GLOBALS['x3_widgets'][ $id ] = array( $title, $callback ); } }
$GLOBALS['x3_widgets'] = array();
w9_as( 9963, array( 'wpcap_manage_orders' ) );
StatusPage::register_dashboard_widget();
$x3s_staff = $GLOBALS['x3_widgets'];
w9_admin();
StatusPage::register_dashboard_widget();
Status::flush_issues();
$wpdb->insert( "{$p}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', uniqid( 'x3w', true ) ), 'job_type' => 'x3_widget', 'payload' => '{}', 'run_after' => $x3s_now, 'status' => 'dead_letter', 'attempts' => 8, 'last_error' => 'x3', 'created_at' => $x3s_now, 'updated_at' => $x3s_now ) );
$x3s_widget_job = (int) $wpdb->insert_id;
ob_start();
StatusPage::render_dashboard_widget();
$x3s_widget = (string) ob_get_clean();
check( 'dashboard widget only for the status capability; it lists the critical issues and links the status page', array() === $x3s_staff && isset( $GLOBALS['x3_widgets']['wpcap_status_widget'] ) && str_contains( $x3s_widget, 'endgültig fehlgeschlagene' ) && str_contains( $x3s_widget, 'page=wpcap-status' ) );
$wpdb->query( "UPDATE {$p}wpcap_jobs SET status='cancelled' WHERE job_id=$x3s_widget_job" );
Status::flush_issues();
w9_admin();
