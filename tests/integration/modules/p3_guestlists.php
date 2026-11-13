<?php
/**
 * Guest lists / complimentary tickets (src/GuestLists, src/Modules/GuestLists.php): request →
 * approve → issue workflow, quota enforcement, capacity consumption, cancel frees capacity, revenue
 * reports and partner settlements exclude comps, permissions (approve capability, designated
 * approver, event scope), will-call list and check-in by name, CSV import/export.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\GuestLists\Page as GlPage;
use PassCaptain\GuestLists\Service as Gl;
use PassCaptain\Modules\GuestLists;
use PassCaptain\Orders;
use PassCaptain\Partners\Repository as GlPartners;
use PassCaptain\Partners\Settlements as GlSettlements;
use PassCaptain\Reports\Data as GlReport;

if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'salt-' . $scheme; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = null ) { return esc_html( $t ); } }

GuestLists::register();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_transients'] = array();

function gl_event_occ( $type ) {
	global $wpdb;
	return $wpdb->get_row( "SELECT o.event_id,o.occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function gl_status( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : $r->status; }

echo "Guest lists: lists and requests\n";
$type = make_type( 10, 10, 2500 );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET tax_rate_bp=1900 WHERE ticket_type_id=$type" );
$ctx = gl_event_occ( $type );
$event = (int) $ctx->event_id;
$occ = (int) $ctx->occurrence_id;
check( 'list needs a name and an occurrence of the event', is_wp_error( Gl::save_list( array( 'event_id' => $event, 'name' => '' ) ) ) && is_wp_error( Gl::save_list( array( 'event_id' => $event, 'occurrence_id' => $occ + 999, 'name' => 'X' ) ) ) );
$press = Gl::save_list( array( 'event_id' => $event, 'occurrence_id' => $occ, 'name' => 'Presse', 'reason' => 'press', 'quota' => 3, 'note' => 'Akkreditierung' ) );
check( 'list created with reason, quota and owner', ! is_wp_error( $press ) && 'press' === $press->reason && 3 === (int) $press->quota && 1 === (int) $press->owner_id );
$other_type = make_type( 10, 10, 1000 );
check( 'entry requires a ticket type of the list occurrence and a valid quantity', is_wp_error( Gl::add_entry( $press->list_id, array( 'name' => 'X', 'ticket_type_id' => $other_type ) ) ) && is_wp_error( Gl::add_entry( $press->list_id, array( 'name' => 'X', 'ticket_type_id' => $type, 'quantity' => 0 ) ) ) && is_wp_error( Gl::add_entry( $press->list_id, array( 'name' => 'X', 'email' => 'kaputt', 'ticket_type_id' => $type ) ) ) );
$e1 = Gl::add_entry( $press->list_id, array( 'name' => 'Zoe Presse', 'email' => 'zoe@example.com', 'company' => 'Tagblatt', 'quantity' => 2, 'ticket_type_id' => $type ) );
check( 'request starts as requested and does not use the quota yet', 'requested' === gl_status( $e1 ) && array( 'quota' => 3, 'used' => 0, 'requested' => 2, 'issued' => 0 ) === Gl::usage( $press->list_id ) );
check( 'requested entries cannot be issued', 'wpcap_guestlist_state' === gl_status( Gl::issue( $e1->entry_id ) ) );

echo "Guest lists: approval and quota\n";
check( 'approval moves the entry to approved and counts against the quota', 'approved' === gl_status( Gl::approve( $e1->entry_id ) ) && 2 === Gl::usage( $press->list_id )['used'] );
$e2 = Gl::add_entry( $press->list_id, array( 'name' => 'Anton Muster', 'quantity' => 2, 'ticket_type_id' => $type ) );
$over = Gl::approve( $e2->entry_id );
check( 'quota enforced at approval (2 + 2 > 3)', is_wp_error( $over ) && 'wpcap_guestlist_quota' === $over->get_error_code() && 'requested' === Gl::get_entry( $e2->entry_id )->status );
$e3 = Gl::add_entry( $press->list_id, array( 'name' => 'Bernd Beispiel', 'quantity' => 1, 'ticket_type_id' => $type, 'approve' => true ) );
check( 'add with approve flag approves right away up to the quota', 'approved' === gl_status( $e3 ) && 3 === Gl::usage( $press->list_id )['used'] );
check( 'quota cannot be lowered below the approved tickets', is_wp_error( Gl::save_list( array( 'list_id' => $press->list_id, 'name' => 'Presse', 'reason' => 'press', 'quota' => 2, 'occurrence_id' => $occ ) ) ) );
check( 'reject: requested entry rejected with note', 'rejected' === gl_status( Gl::reject( $e2->entry_id, 'Kein Platz' ) ) && 'Kein Platz' === Gl::get_entry( $e2->entry_id )->note && 'wpcap_guestlist_state' === gl_status( Gl::approve( $e2->entry_id ) ) );

echo "Guest lists: issuing comps\n";
$GLOBALS['wpcap_test_mails'] = array();
$avail_before = Orders::availability( $type );
$issued = Gl::issue( $e1->entry_id );
$order = Orders::get( $issued->order_id ?? 0 );
$snap = json_decode( (string) ( $order->price_snapshot ?? '' ), true );
check( 'issued entry has a paid 0 € comp order', 'issued' === gl_status( $issued ) && $order && 'comp' === $order->payment_provider && 'paid' === $order->payment_status && 0 === (int) $order->total_minor && 0 === (int) $order->tax_minor );
check( 'order lines at 0 € with 0 VAT; comp reason in the price snapshot', 0 === (int) $wpdb->get_var( "SELECT SUM(unit_price_minor+line_total_minor+tax_minor) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $order->order_id ) && 'press' === ( $snap['comp']['reason'] ?? '' ) && (int) $press->list_id === ( $snap['comp']['list_id'] ?? 0 ) && 0 === ( $snap['total_minor'] ?? -1 ) );
check( 'tickets issued (guest + plus-one) with attendee names; the plus-one is neutral (A057)', 2 === count( tickets( $order->order_id, 'valid' ) ) && 'Begleitung' === $wpdb->get_var( "SELECT ta.attendee_name FROM {$p}wpcap_ticket_assignments ta INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=ta.ticket_id WHERE t.order_id=" . (int) $order->order_id . ' ORDER BY t.ticket_id DESC LIMIT 1' ) );
check( 'tickets mailed to the guest', 1 <= count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'zoe@example.com' === $m['to'] ) ) );
check( 'capacity consumed like normal tickets', $avail_before - 2 === Orders::availability( $type ) );
$again = Gl::issue( $e1->entry_id );
check( 'issuing again is idempotent', 'issued' === gl_status( $again ) && (int) $again->order_id === (int) $order->order_id && 2 === count( tickets( $order->order_id ) ) );
$i3 = Gl::issue( $e3->entry_id );
$o3 = Orders::get( $i3->order_id );
check( 'guest without e-mail: placeholder address (will-call), mail suppressed', 'issued' === gl_status( $i3 ) && Gl::is_placeholder_email( $o3->customer_email ) && true === Gl::suppress_placeholder_mail( null, array( 'to' => $o3->customer_email ) ) && null === Gl::suppress_placeholder_mail( null, array( 'to' => 'x@example.com' ) ) );
check( 'resend without e-mail points to will-call', 'wpcap_guestlist_no_email' === gl_status( Gl::resend( $e3->entry_id ) ) );
$GLOBALS['wpcap_test_mails'] = array();
$resent = Gl::resend( $e3->entry_id, 'bernd@example.com' );
check( 'resend with a new e-mail stores it and mails the tickets', 'issued' === gl_status( $resent ) && 'bernd@example.com' === $resent->email && 'bernd@example.com' === Orders::get( $i3->order_id )->customer_email && 1 === count( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'bernd@example.com' === $m['to'] ) ) );

echo "Guest lists: capacity and cancellation\n";
$small = make_type( 2, 10, 3000 );
$sctx = gl_event_occ( $small );
$staff = Gl::save_list( array( 'event_id' => (int) $sctx->event_id, 'occurrence_id' => (int) $sctx->occurrence_id, 'name' => 'Personal', 'reason' => 'staff' ) );
$s1 = Gl::add_entry( $staff->list_id, array( 'name' => 'Sam Staff', 'quantity' => 2, 'ticket_type_id' => $small, 'approve' => true ) );
$a57_name = static function ( $name, $entry ) { return 'Begleitung von ' . $entry->name; };
add_filter( 'wpcap_guestlist_companion_name', $a57_name, 10, 2 );
$s1 = Gl::issue( $s1->entry_id );
remove_filter( 'wpcap_guestlist_companion_name', $a57_name, 10 );
check( 'A057: the companion name can be customised (wpcap_guestlist_companion_name)', 'Begleitung von Sam Staff' === $wpdb->get_var( "SELECT ta.attendee_name FROM {$p}wpcap_ticket_assignments ta INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=ta.ticket_id WHERE t.order_id=" . (int) $s1->order_id . ' ORDER BY t.ticket_id DESC LIMIT 1' ) );
$s2 = Gl::add_entry( $staff->list_id, array( 'name' => 'Sina Staff', 'quantity' => 1, 'ticket_type_id' => $small, 'approve' => true ) );
$full = Gl::issue( $s2->entry_id );
check( 'comps cannot exceed capacity (sold out keeps the entry approved)', 0 === Orders::availability( $small ) && is_wp_error( $full ) && 'approved' === Gl::get_entry( $s2->entry_id )->status && ! Gl::get_entry( $s2->entry_id )->order_id );
$cancel = Gl::cancel( $s1->entry_id, 'Dienstplan geändert' );
check( 'cancel revokes the tickets, cancels the order and frees capacity', 'cancelled' === gl_status( $cancel ) && 'cancelled' === Orders::get( $s1->order_id )->status && ! tickets( $s1->order_id, 'valid' ) && 2 === Orders::availability( $small ) );
check( 'freed capacity can be used by the next approved entry', 'issued' === gl_status( Gl::issue( $s2->entry_id ) ) && 1 === Orders::availability( $small ) );
$s3 = Gl::add_entry( $staff->list_id, array( 'name' => 'Sven Staff', 'quantity' => 1, 'ticket_type_id' => $small, 'approve' => true ) );
$s3 = Gl::issue( $s3->entry_id );
Orders::cancel_order( $s3->order_id, 'admin' );
check( 'an order cancelled elsewhere cancels the entry', 'cancelled' === Gl::get_entry( $s3->entry_id )->status );
check( 'archiving a list with open entries is refused', is_wp_error( Gl::archive_list( $staff->list_id ) ) );

echo "Guest lists: reports and settlements exclude comps\n";
$sale = Orders::create( array( 'email' => 'buyer-gl@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'gl-sale-' . uniqid() ) );
Orders::confirm_manual_payment( $sale['order_id'] );
$today = gmdate( 'Y-m-d' );
$report = GlReport::build( array( 'from' => $today, 'to' => $today, 'event_id' => $event ), null );
$occ_stats = $report['occurrences'][ $occ ] ?? array();
check( 'revenue report counts only the sale (1 ticket, 25,00 incl. 19 % VAT)', 1 === $report['total']['tickets'] && 2500 === $report['total']['gross'] && 399 === $report['total']['tax'] && ! isset( $report['dimensions']['provider']['comp'] ) );
check( 'comps reported separately (3 tickets in 2 orders)', array( 'orders' => 2, 'tickets' => 3 ) === $report['comps'] );
check( 'occurrence stats: sold excludes comps, utilisation includes them', 1 === ( $occ_stats['sold'] ?? -1 ) && 3 === ( $occ_stats['comps'] ?? -1 ) && 40.0 === ( $occ_stats['utilisation'] ?? 0 ) );
check( 'report CSV lists comps separately', (bool) preg_match( '/"?Freikarten \(nicht im Umsatz\)"?;3;2/', PassCaptain\Reports\Scope::csv( GlReport::csv_rows( $report ) ) ) );
$partner_id = GlPartners::save( array( 'name' => 'GL Veranstalter', 'currency' => 'EUR', 'commission_bp' => 1000, 'fee_per_ticket_minor' => 50 ) );
GlPartners::set_events( $partner_id, array( $event ) );
$settle = GlSettlements::compute( GlPartners::get( $partner_id ), gmdate( 'Y-m' ) );
check( 'settlement: comps are no sale, no commission and no ticket fee', 1 === $settle['totals']['tickets_sold'] && 2500 === $settle['totals']['sales_gross'] && 50 === $settle['totals']['fee'] && 3 === $settle['totals']['comp_tickets'] && 1 === count( $settle['lines'] ) );
$wpdb->query( "DELETE FROM {$p}wpcap_partner_events WHERE partner_id=" . (int) $partner_id );
$wpdb->query( "DELETE FROM {$p}wpcap_partners WHERE partner_id=" . (int) $partner_id );

echo "Guest lists: permissions\n";
$artists = Gl::save_list( array( 'event_id' => $event, 'occurrence_id' => $occ, 'name' => 'Künstler', 'reason' => 'artist' ) );
$a1 = Gl::add_entry( $artists->list_id, array( 'name' => 'Ährens Anna', 'company' => 'Band', 'quantity' => 1, 'ticket_type_id' => $type ) );
$GLOBALS['wpcap_test_denied_caps'] = array( Gl::APPROVE_CAP );
$no_cap = Gl::approve( $a1->entry_id );
$flag = Gl::add_entry( $artists->list_id, array( 'name' => 'Ohne Recht', 'quantity' => 1, 'ticket_type_id' => $type, 'approve' => true ) );
check( 'approval requires wpcap_approve_comps (requests stay possible)', is_wp_error( $no_cap ) && 403 === $no_cap->get_error_data()['status'] && 'requested' === gl_status( $flag ) && is_wp_error( Gl::reject( $a1->entry_id ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'event scope: unassigned users cannot manage, approve or look up guests', is_wp_error( Gl::add_entry( $artists->list_id, array( 'name' => 'X', 'ticket_type_id' => $type ) ) ) && is_wp_error( Gl::save_list( array( 'event_id' => $event, 'name' => 'Y' ) ) ) && is_wp_error( Gl::approve( $a1->entry_id ) ) && is_wp_error( Gl::search( $occ, 'Zoe' ) ) && is_wp_error( Gl::arrive( $e1->entry_id ) ) && is_wp_error( Gl::will_call( $occ ) ) && is_wp_error( Gl::export_rows( $event ) ) );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $event, 'user_id' => 1, 'capability' => 'wpcap_manage_events' ) );
check( 'assigned event manager with the approve capability may approve', 'approved' === gl_status( Gl::approve( $a1->entry_id ) ) );
$vip = Gl::save_list( array( 'event_id' => $event, 'occurrence_id' => $occ, 'name' => 'Sponsor', 'reason' => 'sponsor', 'approver_id' => 99 ) );
$v1 = Gl::add_entry( $vip->list_id, array( 'name' => 'Viktor Sponsor', 'quantity' => 1, 'ticket_type_id' => $type ) );
check( 'designated approver: other users cannot decide', is_wp_error( Gl::approve( $v1->entry_id ) ) );
$GLOBALS['wpcap_test_user_id'] = 99;
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $event, 'user_id' => 99, 'capability' => 'wpcap_manage_events' ) );
check( 'designated approver may approve', 'approved' === gl_status( Gl::approve( $v1->entry_id ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE event_id=$event AND capability='wpcap_manage_events'" );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $event, 'user_id' => 1, 'capability' => 'wpcap_checkin' ) );
check( 'check-in staff: lookup and will-call yes, managing no', is_array( Gl::search( $occ, 'Zoe' ) ) && is_array( Gl::will_call( $occ ) ) && is_wp_error( Gl::issue( $a1->entry_id ) ) && is_wp_error( Gl::cancel( $e1->entry_id ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "Guest lists: will-call and check-in by name\n";
$wc = Gl::will_call( $occ );
$names = array_map( static fn( $e ) => $e->name, $wc );
check( 'will-call list is alphabetical (umlauts sorted with their base letter) and skips open/rejected requests', array( 'Ährens Anna', 'Bernd Beispiel', 'Viktor Sponsor', 'Zoe Presse' ) === $names );
$zoe = $wc[3];
check( 'will-call shows plus-ones and short ticket codes', 1 === $zoe->plus_ones && 2 === count( $zoe->codes ) && preg_match( '/^[A-F0-9]{8}$/', $zoe->codes[0] ) );
$html = GlPage::will_call_html( $occ );
check( 'printable will-call list contains guests and codes (escaped)', is_string( $html ) && false !== strpos( $html, 'Zoe Presse' ) && false !== strpos( $html, $zoe->codes[0] ) && false !== strpos( $html, '+1 Begleitung' ) && false === strpos( $html, '<script' ) );
$found = Gl::search( $occ, 'zoe' );
$by_code = Gl::search( $occ, strtolower( substr( $zoe->codes[1], 0, 6 ) ) );
$by_company = Gl::search( $occ, 'Tagbl' );
check( 'lookup by name, company or short code; only issued entries; no e-mail in results', 1 === count( $found ) && 'Zoe Presse' === $found[0]['name'] && 2 === $found[0]['tickets_valid'] && ! isset( $found[0]['email'] ) && 1 === count( $by_code ) && 1 === count( $by_company ) && array() === Gl::search( $occ, 'Ährens' ) && array() === Gl::search( $occ, 'z' ) );
$arr = Gl::arrive( $e1->entry_id, 1 );
check( 'arrival checks in the requested number of tickets via the check-in service', is_array( $arr ) && 1 === $arr['checked_in'] && 1 === $arr['remaining'] && 1 === count( tickets( $order->order_id, 'checked_in' ) ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins c INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id WHERE t.order_id=" . (int) $order->order_id . " AND c.result='checked_in' AND c.source='guestlist'" ) && null !== Gl::get_entry( $e1->entry_id )->arrived_at );
$arr2 = Gl::arrive( $e1->entry_id );
check( 'second arrival checks in the plus-one; then nothing is left', is_array( $arr2 ) && 1 === $arr2['checked_in'] && 0 === $arr2['remaining'] && 'wpcap_guestlist_all_checked_in' === gl_status( Gl::arrive( $e1->entry_id ) ) );
check( 'checked-in entries cannot be cancelled', 'wpcap_guestlist_checked_in' === gl_status( Gl::cancel( $e1->entry_id ) ) );
check( 'arrival requires an issued entry', 'wpcap_guestlist_state' === gl_status( Gl::arrive( $a1->entry_id ) ) );

echo "Guest lists: CSV import and export\n";
$imp = Gl::import_csv( $artists->list_id, "\xEF\xBB\xBFName;E-Mail;Anzahl;Ticketart\nCarla Gitarre;carla@example.com;2;Standard\nDieter Drums;;1;\nKaputt;nope;1;\n\"Eva, Bass\";eva@example.com;1;" . $type, $type );
check( 'import: header skipped, type by name/id/default, invalid lines reported', 3 === $imp['created'] && array( 4 ) === array_keys( $imp['errors'] ) && 'requested' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_guest_entries WHERE name='Carla Gitarre'" ) && 2 === (int) $wpdb->get_var( "SELECT quantity FROM {$p}wpcap_guest_entries WHERE name='Carla Gitarre'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_guest_entries WHERE name='Eva, Bass'" ) );
$rows = Gl::export_rows( $event );
$flat = PassCaptain\Reports\Scope::csv( $rows );
check( 'export lists entries with status and codes, never placeholder addresses', 'Liste' === $rows[0][0] && false !== strpos( $flat, 'Zoe Presse' ) && false !== strpos( $flat, $zoe->codes[0] ) && false === strpos( $flat, '@invalid.example' ) && false !== strpos( $flat, 'Abgelehnt' ) );
check( 'module registers its admin page and handlers', 'wpcap-guestlists' === GuestLists::admin_pages()[0][0] && isset( GuestLists::admin_handlers()['wpcap_gl_entry_action'] ) && 2 === count( GuestLists::schema( 'x_', '' ) ) );
