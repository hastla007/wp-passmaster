<?php
/**
 * W14: admin "Inhaber ändern" (PASS-FE-03, same effect as a buyer transfer), attendee CSV over all dates of an
 * event (PASS-FE-05), wallet buttons per ticket in the order portal, and the connector events of contract
 * 1.2.0 (ticket_checked_out, event_cancelled, attendance_confirmed, ticket_transferred).
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\TicketsPage;
use PassCaptain\Integrations\ConnectorEvents;
use PassCaptain\Integrations\ConnectorInterface;
use PassCaptain\Jobs;
use PassCaptain\Orders;
use PassCaptain\Printing\AttendeeList;
use PassCaptain\Reports\Scope;
use PassCaptain\Security\DataPolicy;
use PassCaptain\TicketAssignments;
use PassCaptain\WalletPasses\Service as W14Wallet;
use PassCaptain\WalletPasses\Store as W14Store;

require_once dirname( __DIR__ ) . '/lib/admin_render_stubs.php';
if ( ! class_exists( 'Wpcap_Test_Die' ) ) { final class Wpcap_Test_Die extends Exception {} }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message = '', $title = '', $args = array() ) { throw new Wpcap_Test_Die( (string) $message ); } }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1 ) { $GLOBALS['wpcap_test_nonce_checks'][] = $action; return 1; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }

final class W14_Stub_Connector implements ConnectorInterface {
	public array $received = array();
	public function key() { return 'w14_conn'; }
	public function version() { return '1.0.0'; }
	public function capabilities() { return array( 'tickets' ); }
	public function deliver( $event_name, array $payload, $idempotency_key ) {
		$this->received[] = array( 'event' => $event_name, 'payload' => $payload, 'key' => $idempotency_key );
		return true;
	}
	public function of( $event ) { return array_values( array_filter( $this->received, static fn( $r ) => $event === $r['event'] ) ); }
}

final class W14_Fake_Wallet implements \PassCaptain\Wallet\ProviderInterface {
	public function create_pass( array $ticket ) { return array(); }
	public function update_pass( array $ticket, array $provider_pass ) { return array(); }
	public function revoke_pass( array $provider_pass ) { return array(); }
}

global $wpdb;
$p = $wpdb->prefix;
$w14_prev_user = $GLOBALS['wpcap_test_user_id'] ?? 1;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();

/* ================================================================== 1. PASS-FE-03: admin holder change */
echo "W14 PASS-FE-03: admin holder change like a buyer transfer\n";
$w14_type = make_type( 20, 10, 0 );
$w14_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$w14_type" );
$w14_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$w14_occ" );
$w14_buyer = 'w14-buyer@example.com';
$w14_o = Orders::create( array( 'email' => $w14_buyer, 'items' => array( array( 'ticket_type_id' => $w14_type, 'quantity' => 4 ) ), 'idempotency_key' => 'w14-' . uniqid() ) );
$w14_oid = (int) $w14_o['order_id'];
$w14_order = Orders::get( $w14_oid );
$w14_token = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $w14_token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'order_id' => $w14_oid ) );
$w14_t = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,public_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $w14_oid ) );
check( 'fixture: paid order with four valid tickets', 'paid' === $w14_order->payment_status && 4 === count( $w14_t ) );
$w14_tid = (int) $w14_t[0]->ticket_id;
// A known previous code, so the test can show that it stops working.
$w14_old = bin2hex( random_bytes( 32 ) );
$wpdb->update( "{$p}wpcap_tickets", array( 'token_hash' => hash( 'sha256', $w14_old ) ), array( 'ticket_id' => $w14_tid ) );
$w14_in = static function ( $tid, array $extra = array() ) { return $extra + array( 'ticket_id' => (int) $tid, 'attendee_name' => 'Nora Neu', 'attendee_email' => 'w14-nora@example.com', 'reason' => 'Ticket verschenkt, telefonisch bestätigt', 'notify' => '1' ); };
$w14_code = static function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; };

check( 'FE-03: a reason is required', 'wpcap_holder_change_reason' === $w14_code( TicketsPage::change_holder( $w14_in( $w14_tid, array( 'reason' => '' ) ) ) ) );
check( 'FE-03: name and a valid e-mail of the new person are required', 'wpcap_holder_change_invalid' === $w14_code( TicketsPage::change_holder( $w14_in( $w14_tid, array( 'attendee_email' => 'kein-at' ) ) ) ) && 'wpcap_holder_change_invalid' === $w14_code( TicketsPage::change_holder( $w14_in( $w14_tid, array( 'attendee_name' => '' ) ) ) ) );
check( 'FE-03: the current holder address is refused (that is a correction)', 'wpcap_holder_change_same' === $w14_code( TicketsPage::change_holder( $w14_in( $w14_tid, array( 'attendee_email' => strtoupper( $w14_buyer ) ) ) ) ) );

// PII masking: support staff with masked data cannot change the holder.
$GLOBALS['wpcap_test_filters']['wpcap_data_profile'] = 'support';
DataPolicy::flush_cache();
$w14_masked = TicketsPage::change_holder( $w14_in( $w14_tid ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_data_profile'] );
DataPolicy::flush_cache();
check( 'FE-03: masked personal data → refused (403), nothing changed', 'wpcap_admin_forbidden' === $w14_code( $w14_masked ) && hash( 'sha256', $w14_old ) === $wpdb->get_var( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=$w14_tid" ) );

// The change itself.
$w14_seen = array();
$w14_listener = static function ( $tid, $ctx = array() ) use ( &$w14_seen ) { $w14_seen[] = array( (int) $tid, (array) $ctx ); };
add_action( 'wpcap_ticket_transferred', $w14_listener, 99, 2 );
$w14_mails = count( $GLOBALS['wpcap_test_mails'] );
$w14_actions = count( $GLOBALS['wpcap_test_actions'] );
$w14_r = TicketsPage::change_holder( $w14_in( $w14_tid ) );
$w14_row = $wpdb->get_row( "SELECT token_hash,attendee_email,transfer_count,status FROM {$p}wpcap_tickets WHERE ticket_id=$w14_tid" );
$w14_assign = $wpdb->get_row( "SELECT attendee_name,attendee_email FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$w14_tid" );
$w14_new_mails = array_slice( $GLOBALS['wpcap_test_mails'], $w14_mails );
$w14_to_new = array_values( array_filter( $w14_new_mails, static fn( $m ) => 'w14-nora@example.com' === $m['to'] ) );
$w14_to_buyer = array_values( array_filter( $w14_new_mails, static fn( $m ) => $w14_buyer === $m['to'] ) );
check( 'FE-03: holder changed – new code, new holder address, counted as a transfer, name of the new person', ! is_wp_error( $w14_r ) && 'transferred' === ( $w14_r['status'] ?? '' ) && hash( 'sha256', $w14_old ) !== $w14_row->token_hash && 'w14-nora@example.com' === $w14_row->attendee_email && 1 === (int) $w14_row->transfer_count && 'valid' === $w14_row->status && $w14_assign && 'Nora Neu' === $w14_assign->attendee_name );
check( 'FE-03: the new holder gets the ticket link, the buyer a notice without code (as in a portal transfer)', 1 === count( $w14_to_new ) && false !== strpos( (string) $w14_to_new[0]['body'], 'wpcap_ticket=' ) && 1 === count( $w14_to_buyer ) && false === strpos( (string) $w14_to_buyer[0]['body'], 'wpcap_ticket=' ) && ! empty( $w14_r['mailed'] ) );
$w14_fired = array_slice( $GLOBALS['wpcap_test_actions'], $w14_actions );
check( 'FE-03: wpcap_ticket_code_rotated and wpcap_ticket_transferred (source admin) fire', in_array( 'wpcap_ticket_code_rotated', $w14_fired, true ) && $w14_seen && $w14_tid === $w14_seen[0][0] && 'admin' === ( $w14_seen[0][1]['source'] ?? '' ) && 1 === (int) ( $w14_seen[0][1]['holder_changes'] ?? 0 ) );
$w14_audit = $wpdb->get_row( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='ticket_transferred' AND object_type='ticket' AND object_id=$w14_tid ORDER BY audit_id DESC LIMIT 1" );
$w14_ad = $w14_audit ? (array) json_decode( (string) $w14_audit->details, true ) : array();
check( 'FE-03: audited as ticket_transferred with source admin, reason and notification, no address', 'admin' === ( $w14_ad['source'] ?? '' ) && 'Ticket verschenkt, telefonisch bestätigt' === ( $w14_ad['reason'] ?? '' ) && true === ( $w14_ad['notified'] ?? null ) && false === strpos( (string) ( $w14_audit->details ?? '' ), 'w14-nora' ) );
$w14_scan = PassCaptain\Checkin::perform( $w14_old, array( 'idempotency_key' => 'w14-old-scan', 'source' => 'web' ) );
check( 'FE-03: the previous code no longer admits', is_wp_error( $w14_scan ) || 'checked_in' !== ( $w14_scan['status'] ?? '' ) );
check( 'FE-03: the buyer\'s order link can no longer transfer or re-assign the ticket', is_wp_error( Orders::transfer_ticket( $w14_order->public_id, $w14_token, $w14_t[0]->public_id, 'w14-back@example.com' ) ) && 'wpcap_assignment_transferred' === $w14_code( TicketAssignments::assign( $w14_order->public_id, $w14_token, $w14_t[0]->public_id, 'Wer Anders', 'w14-other@example.com' ) ) );
$w14_r2 = TicketsPage::change_holder( $w14_in( $w14_tid, array( 'attendee_name' => 'Otto Dritt', 'attendee_email' => 'w14-otto@example.com', 'notify' => '' ) ) );
check( 'FE-03: staff are not bound to the holder-change limit; without "senden" nobody is mailed', ! is_wp_error( $w14_r2 ) && empty( $w14_r2['mailed'] ) && 2 === (int) $wpdb->get_var( "SELECT transfer_count FROM {$p}wpcap_tickets WHERE ticket_id=$w14_tid" ) && ! array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $w14_mails ), static fn( $m ) => 'w14-otto@example.com' === $m['to'] ) );
remove_action( 'wpcap_ticket_transferred', $w14_listener, 99 );
$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'revoked' ), array( 'ticket_id' => (int) $w14_t[3]->ticket_id ) );
check( 'FE-03: a revoked ticket cannot change its holder', 'wpcap_holder_change_not_allowed' === $w14_code( TicketsPage::change_holder( $w14_in( (int) $w14_t[3]->ticket_id ) ) ) );
// The portal transfer still works on another ticket (shared code path).
$w14_pt = Orders::transfer_ticket( $w14_order->public_id, $w14_token, $w14_t[2]->public_id, 'w14-friend@example.com' );
check( 'FE-03: the portal transfer still works through the shared move_ticket()', ! is_wp_error( $w14_pt ) && 'transferred' === ( $w14_pt['status'] ?? '' ) && 1 === (int) $wpdb->get_var( "SELECT transfer_count FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $w14_t[2]->ticket_id ) && '' === (string) $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=" . (int) $w14_t[2]->ticket_id ) );

// Capability before the nonce; declared in the permission matrix and registered.
$GLOBALS['wpcap_test_nonce_checks'] = array();
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$_POST = $w14_in( (int) $w14_t[1]->ticket_id, array( '_wpnonce' => 'n' ) );
$w14_died = false;
try { TicketsPage::handle_change_holder(); } catch ( Throwable $e ) { $w14_died = true; }
$_POST = array();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'FE-03: without wpcap_manage_orders the handler stops before the nonce check and changes nothing', $w14_died && ! in_array( 'wpcap_change_holder_' . (int) $w14_t[1]->ticket_id, (array) $GLOBALS['wpcap_test_nonce_checks'], true ) && 0 === (int) $wpdb->get_var( "SELECT transfer_count FROM {$p}wpcap_tickets WHERE ticket_id=" . (int) $w14_t[1]->ticket_id ) );
$w14_handlers = PassCaptain\Admin\Menu::handlers();
check( 'FE-03: admin-post action registered and declared in the W5 permission map', array( TicketsPage::class, 'handle_change_holder' ) === ( $w14_handlers['wpcap_change_holder'] ?? null ) && false !== strpos( (string) file_get_contents( __DIR__ . '/zz_w5_permissions.php' ), "'wpcap_change_holder' => 'wpcap_manage_orders'" ) );

// Row actions (TicketsListTable cells come from TicketsPage::list_cell()).
$w14_filters = TicketsPage::filters( array( 'event_id' => $w14_event ) );
$w14_data = TicketsPage::list_data( $w14_filters, 1, 30 );
$w14_rowobj = null;
foreach ( $w14_data['rows'] as $w14_rr ) { if ( (int) $w14_rr->ticket_id === (int) $w14_t[1]->ticket_id ) { $w14_rowobj = $w14_rr; } }
$w14_cell = $w14_rowobj ? TicketsPage::list_cell( 'actions', $w14_rowobj, $w14_filters, 1, false ) : '';
check( 'FE-03: the row actions offer "Inhaber ändern" with name, e-mail, reason and notification', false !== strpos( $w14_cell, 'wpcap_change_holder' ) && false !== strpos( $w14_cell, 'Inhaber ändern' ) && false !== strpos( $w14_cell, 'name="notify"' ) && false !== strpos( $w14_cell, 'name="reason" required' ) );
$GLOBALS['wpcap_test_filters']['wpcap_data_profile'] = 'support';
DataPolicy::flush_cache();
$w14_data_m = TicketsPage::list_data( $w14_filters, 1, 30 );
$w14_cell_m = '';
foreach ( $w14_data_m['rows'] as $w14_rr ) { if ( (int) $w14_rr->ticket_id === (int) $w14_t[1]->ticket_id ) { $w14_cell_m = TicketsPage::list_cell( 'actions', $w14_rr, $w14_filters, 1, false ); } }
unset( $GLOBALS['wpcap_test_filters']['wpcap_data_profile'] );
DataPolicy::flush_cache();
check( 'FE-03: with masked data the row has no holder change form', '' !== $w14_cell_m && false === strpos( $w14_cell_m, 'wpcap_change_holder' ) );

/* ================================================================== 2. PASS-FE-05: attendee CSV over all dates */
echo "W14 PASS-FE-05: attendee CSV over all dates of an event\n";
$e5_type1 = make_type( 20, 10, 0 );
$e5_occ1 = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$e5_type1" );
$e5_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$e5_occ1" );
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $e5_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 14 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$e5_occ2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $e5_occ2, 'name' => 'Abend', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 20, 'max_per_order' => 10, 'status' => 'active' ) );
$e5_type2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $e5_type1, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $e5_type2, 'field_key' => 'diaet', 'label' => 'Diät', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
$wpdb->insert( "{$p}wpcap_participant_fields", array( 'ticket_type_id' => $e5_type2, 'field_key' => 'firma', 'label' => 'Firma', 'field_type' => 'text', 'required' => 0, 'status' => 'active' ) );
wpcap_test_reset_transients();
$e5_o1 = Orders::create( array( 'email' => 'e5-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $e5_type1, 'quantity' => 1 ) ), 'idempotency_key' => 'e5-1-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $e5_type1, 'name' => '=HYPERLINK("http://evil")', 'email' => 'e5-ada@example.com', 'answers' => array( 'firma' => 'ACME' ) ) ) ) );
wpcap_test_reset_transients();
$e5_o2 = Orders::create( array( 'email' => 'e5-buyer2@example.com', 'items' => array( array( 'ticket_type_id' => $e5_type2, 'quantity' => 2 ) ), 'idempotency_key' => 'e5-2-' . uniqid(), 'attendees' => array( array( 'ticket_type_id' => $e5_type2, 'name' => 'Bea Abend', 'answers' => array( 'firma' => 'Beta GmbH', 'diaet' => 'vegan' ) ) ) ) );
check( 'fixture: two dates of one event with tickets and answers', ! is_wp_error( $e5_o1 ) && ! is_wp_error( $e5_o2 ) );
$e5_scope = AttendeeList::event_scope( $e5_event );
check( 'FE-05: event managers get the full level for both dates (in date order)', 'full' === $e5_scope['level'] && array( $e5_occ1, $e5_occ2 ) === $e5_scope['occurrences'] );
$e5_header = AttendeeList::event_header( $e5_scope['occurrences'], 'full' );
$e5_single = AttendeeList::header( $e5_occ1, 'full' );
check( 'FE-05: full header = columns of the single-date list (incl. "Termin") plus the fields of all dates merged by key', array_slice( $e5_single, 0, 11 ) === array_slice( $e5_header, 0, 11 ) && 'Termin' === $e5_header[7] && array( 'Firma', 'Diät' ) === array_slice( $e5_header, 11 ) );
$e5_rows = iterator_to_array( AttendeeList::event_row_stream( $e5_scope['occurrences'], 'full' ), false );
$e5_dates = array_values( array_unique( array_column( $e5_rows, 7 ) ) );
check( 'FE-05: rows of both dates, each with its own date and answers in the merged columns', 3 === count( $e5_rows ) && 2 === count( $e5_dates ) && 'ACME' === $e5_rows[0][11] && '' === $e5_rows[0][12] && in_array( 'Beta GmbH', array_column( $e5_rows, 11 ), true ) && in_array( 'vegan', array_column( $e5_rows, 12 ), true ) && 13 === count( $e5_rows[2] ) );
$e5_mem = fopen( 'php://memory', 'w+' );
Scope::write_csv( $e5_mem, PassCaptain\Printing\Page::with_header( $e5_header, AttendeeList::event_row_stream( $e5_scope['occurrences'], 'full' ) ) );
rewind( $e5_mem );
$e5_csv = (string) stream_get_contents( $e5_mem );
fclose( $e5_mem );
check( 'FE-05: the CSV keeps the formula protection of the single-date export', false !== strpos( $e5_csv, "'=HYPERLINK" ) && false === strpos( $e5_csv, ';=HYPERLINK' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$e5_basic = AttendeeList::event_scope( $e5_event );
$e5_bh = AttendeeList::event_header( $e5_basic['occurrences'], $e5_basic['level'] );
$e5_brows = iterator_to_array( AttendeeList::event_row_stream( $e5_basic['occurrences'], $e5_basic['level'] ), false );
check( 'FE-05: without event management the basic list with a leading "Termin" column, no e-mail or answers', 'basic' === $e5_basic['level'] && array( 'Termin', 'Name', 'Ticketart', 'Status' ) === $e5_bh && 3 === count( $e5_brows ) && 4 === count( $e5_brows[0] ) && false === strpos( wp_json_encode( $e5_brows ), '@' ) && false === strpos( wp_json_encode( $e5_brows ), 'ACME' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events', 'wpcap_checkin', 'wpcap_manage_orders' );
$e5_none = AttendeeList::event_scope( $e5_event );
$_GET = array( 'event_id' => $e5_event, 'format' => 'csv' );
$e5_denied = false;
try { PassCaptain\Printing\Page::handle_attendee_list(); } catch ( Wpcap_Test_Die $e ) { $e5_denied = true; }
$_GET = array();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'FE-05: without any list right no date is exported and the handler refuses (403)', '' === $e5_none['level'] && array() === $e5_none['occurrences'] && $e5_denied );
$e5_src = (string) file_get_contents( WPCAP_PATH . 'src/Printing/Page.php' );
check( 'FE-05: the handler streams the event CSV with audit; the per-date export is unchanged', false !== strpos( $e5_src, 'AttendeeList::event_row_stream( $scope[\'occurrences\'], $scope[\'level\'], $filter )' ) && false !== strpos( $e5_src, "Audit::record( 'attendee_list_csv', 'event', \$event_id" ) && false !== strpos( $e5_src, 'AttendeeList::row_stream( $occurrence_id, $level, $filter )' ) && 12 === count( AttendeeList::header( $e5_occ1, 'full' ) ) );

/* ================================================================== 3. Wallet buttons in the order portal */
echo "W14: wallet buttons per ticket in the order portal\n";
$w3_fake = new W14_Fake_Wallet();
$w3_filter = static function ( $provider, $key ) use ( $w3_fake ) { return in_array( $key, array( 'apple', 'google' ), true ) ? $w3_fake : $provider; };
check( 'wallet: the portal section filter is registered by the wallet service', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/WalletPasses/Service.php' ), "add_filter( 'wpcap_order_portal_sections', array( __CLASS__, 'portal_section' )" ) );
check( 'wallet: without a configured provider the portal shows nothing', 'X' === W14Wallet::portal_section( 'X', $w14_order ) );
add_filter( 'wp_pass_captain_wallet_provider_v1', $w3_filter, 1, 2 );
$w3_type = make_type( 10, 10, 0 );
wpcap_test_reset_transients();
$w3_o = Orders::create( array( 'email' => 'w3-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $w3_type, 'quantity' => 3 ) ), 'idempotency_key' => 'w3-' . uniqid() ) );
$w3_order = Orders::get( (int) $w3_o['order_id'] );
$w3_t = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,public_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $w3_o['order_id'] ) );
// With the wallet module registered by earlier modules the ticket mail stores codes already: start without passes.
$w3_ids = implode( ',', array_map( static fn( $r ) => (int) $r->ticket_id, $w3_t ) );
$wpdb->query( "DELETE FROM {$p}wpcap_wallet_passes WHERE ticket_id IN ($w3_ids)" );
$wpdb->query( "DELETE FROM {$p}wpcap_wallet_state WHERE ticket_id IN ($w3_ids)" );
check( 'wallet: no passes yet → no section', '' === W14Wallet::portal_section( '', $w3_order ) );
foreach ( $w3_t as $w3_row ) {
	W14Store::put_code( (int) $w3_row->ticket_id, bin2hex( random_bytes( 32 ) ) );
	W14Store::save_pass( (int) $w3_row->ticket_id, 'apple', (string) $w3_row->public_id, 'pass.test.w14', 'active' );
}
// Ticket 3 goes to another person (admin holder change): its pass is no longer the buyer's.
$w3_moved = Orders::change_ticket_holder( (int) $w3_t[2]->ticket_id, 'w3-other@example.com', 'Olga Anders', array( 'reason' => 'Test', 'notify' => false ) );
$w3_html = W14Wallet::portal_section( '', $w3_order );
check( 'wallet: one Apple and one Google button per ticket the buyer still holds, as on the ticket page', ! is_wp_error( $w3_moved ) && 2 === substr_count( $w3_html, 'Zu Apple Wallet hinzufügen' ) && 2 === substr_count( $w3_html, 'In Google Wallet speichern' ) && 2 === substr_count( $w3_html, '/wallet/apple/download/' ) && 2 === substr_count( $w3_html, '/wallet/google/save' ) && false !== strpos( $w3_html, 'Wallet-Pässe deiner Tickets' ) );
check( 'wallet: the ticket that went to another person has no button in the buyer portal', false === strpos( $w3_html, (string) $w3_t[2]->public_id ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_status' => 'pending' ), array( 'order_id' => (int) $w3_o['order_id'] ) );
check( 'wallet: unpaid order → no section', '' === W14Wallet::portal_section( '', Orders::get( (int) $w3_o['order_id'] ) ) );
$wpdb->update( "{$p}wpcap_orders", array( 'payment_status' => 'paid' ), array( 'order_id' => (int) $w3_o['order_id'] ) );
remove_filter( 'wp_pass_captain_wallet_provider_v1', $w3_filter, 1 );

/* ================================================================== 4. Connector events of contract 1.2.0 */
echo "W14: connector events ticket_checked_out, event_cancelled, attendance_confirmed, ticket_transferred\n";
ConnectorEvents::register();
$c4_stub = new W14_Stub_Connector();
$c4_filter = static function ( $list ) use ( $c4_stub ) { $list[] = $c4_stub; return $list; };
add_filter( 'wp_pass_captain_connectors_v1', $c4_filter );
$c4_enabled_before = get_option( 'wpcap_enabled_connectors', array() );
update_option( 'wpcap_enabled_connectors', array( 'w14_conn' ) );
$c4_run = static function () use ( $wpdb, $p ) {
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE job_type='connector_delivery' AND status='queued'" );
	Jobs::process_due( 200 );
};
check( 'C4: contract 1.2.0 declares the four new events (additive minor)', '1.2.0' === ConnectorEvents::CONTRACT_VERSION && ! array_diff( array( 'ticket_checked_out', 'event_cancelled', 'attendance_confirmed', 'ticket_transferred' ), ConnectorEvents::EVENTS ) && 14 === count( ConnectorEvents::EVENTS ) );
$c4_tid = (int) $w14_t[1]->ticket_id;
do_action( 'wpcap_ticket_checked_out', $c4_tid, array( 'checkin_id' => 914001, 'source' => 'app', 'device_id' => 7, 'area_id' => 3 ) );
do_action( 'wpcap_ticket_checked_out', $c4_tid, array( 'checkin_id' => 914001, 'source' => 'app', 'device_id' => 7, 'area_id' => 3 ) ); // Same check-out reported twice.
$c4_run();
$c4_out = $c4_stub->of( 'ticket_checked_out' );
check( 'C4: ticket_checked_out once per check-out with ticket ids, source, device and area', 1 === count( $c4_out ) && $c4_tid === $c4_out[0]['payload']['data']['ticket_id'] && $w14_oid === $c4_out[0]['payload']['data']['order_id'] && $w14_occ === $c4_out[0]['payload']['data']['occurrence_id'] && 'app' === $c4_out[0]['payload']['data']['source'] && 7 === $c4_out[0]['payload']['data']['device_id'] && 3 === $c4_out[0]['payload']['data']['area_id'] && hash( 'sha256', 'ticket_checked_out|' . $c4_tid . '|checkin_914001|w14_conn' ) === $c4_out[0]['key'] );
do_action( 'wpcap_ticket_checked_in', $c4_tid, array( 'checkin_id' => 914002, 'source' => 'web' ) );
do_action( 'wpcap_ticket_checked_in', $c4_tid, array( 'checkin_id' => 914003, 'source' => 'web' ) );
do_action( 'wpcap_attendance_confirmed', $c4_tid, array( 'method' => 'manual', 'source' => 'api' ) );
do_action( 'wpcap_attendance_confirmed', (int) $w14_t[0]->ticket_id, array( 'method' => 'manual', 'source' => 'api' ) );
$c4_run();
$c4_att = $c4_stub->of( 'attendance_confirmed' );
$c4_att_own = array_values( array_filter( $c4_att, static fn( $r ) => $c4_tid === $r['payload']['data']['ticket_id'] ) );
$c4_att_api = array_values( array_filter( $c4_att, static fn( $r ) => (int) $w14_t[0]->ticket_id === $r['payload']['data']['ticket_id'] ) );
check( 'C4: attendance_confirmed once per ticket at the first admission (method check_in), two ticket_checked_in', 2 === count( $c4_stub->of( 'ticket_checked_in' ) ) && 1 === count( $c4_att_own ) && 'check_in' === $c4_att_own[0]['payload']['data']['method'] && 'web' === $c4_att_own[0]['payload']['data']['source'] );
check( 'C4: wpcap_attendance_confirmed (wpcap_confirm_attendance()) delivers attendance_confirmed with its method', 1 === count( $c4_att_api ) && 'manual' === $c4_att_api[0]['payload']['data']['method'] && 'api' === $c4_att_api[0]['payload']['data']['source'] );
do_action( 'wpcap_event_cancelled', $w14_event, array( 'occurrence_ids' => array( $w14_occ ), 'run_id' => 77 ) );
$c4_run();
$c4_ev = $c4_stub->of( 'event_cancelled' );
check( 'C4: event_cancelled with event id, status and the cancelled dates', 1 === count( $c4_ev ) && $w14_event === $c4_ev[0]['payload']['data']['event_id'] && array( $w14_occ ) === $c4_ev[0]['payload']['data']['occurrence_ids'] && is_string( $c4_ev[0]['payload']['data']['status'] ) );
$c4_change = Orders::change_ticket_holder( $c4_tid, 'w14-conn-new@example.com', 'Conny Neu', array( 'reason' => 'Test', 'notify' => false ) );
$c4_run();
$c4_tr = $c4_stub->of( 'ticket_transferred' );
check( 'C4: ticket_transferred for an admin holder change with source and holder_changes', ! is_wp_error( $c4_change ) && 1 === count( $c4_tr ) && $c4_tid === $c4_tr[0]['payload']['data']['ticket_id'] && 'admin' === $c4_tr[0]['payload']['data']['source'] && 1 === $c4_tr[0]['payload']['data']['holder_changes'] );
$c4_all = wp_json_encode( $c4_stub->received );
check( 'C4: payloads name contract 1.2.0 and carry no e-mail address or name', array( '1.2.0' ) === array_values( array_unique( array_map( static fn( $r ) => $r['payload']['contract_version'], $c4_stub->received ) ) ) && false === strpos( $c4_all, '@example.com' ) && false === strpos( $c4_all, 'Conny' ) );
remove_filter( 'wp_pass_captain_connectors_v1', $c4_filter );
update_option( 'wpcap_enabled_connectors', $c4_enabled_before );

$GLOBALS['wpcap_test_user_id'] = $w14_prev_user;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
