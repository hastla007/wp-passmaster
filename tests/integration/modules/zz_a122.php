<?php
/**
 * A122: audit of access to personal data (views, lists, REST reads), exports, WordPress privacy
 * exporter/eraser runs, plugin role and capability changes, and corrections with a masked diff.
 * Included from run.php (uses check(), make_type(), admin_paid_order()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\AuditLogPage;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Billing;
use PassCaptain\Orders;
use PassCaptain\Privacy\AccessLog;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }

echo "A122: audit of personal data access, exports, privacy tools, roles and corrections\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$a122_prev_user = $GLOBALS['wpcap_test_user_id'] ?? 1;
$a122_count = static function ( $action, $object_type = null, $object_id = null ) use ( $wpdb, $p ) {
	$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s", $action );
	if ( null !== $object_type ) { $sql .= $wpdb->prepare( ' AND object_type=%s', $object_type ); }
	if ( null !== $object_id ) { $sql .= $wpdb->prepare( ' AND object_id=%d', $object_id ); }
	return (int) $wpdb->get_var( $sql );
};
$a122_last = static function ( $action ) use ( $wpdb, $p ) {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_audit_logs WHERE action=%s ORDER BY audit_id DESC LIMIT 1", $action ) );
	if ( $row ) { $row->data = json_decode( (string) $row->details, true ); }
	return $row;
};

// ---------------------------------------------------------------------------------------- registration
if ( empty( array_filter( (array) ( $GLOBALS['wpcap_test_hooks']['set_user_role'] ?? array() ) ) ) ) { AccessLog::register(); }
$a122_hooked = static function ( $hook, $method ) {
	foreach ( (array) ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() ) as $cbs ) {
		foreach ( $cbs as $cb ) { if ( array( AccessLog::class, $method ) === $cb[0] ) { return true; } }
	}
	return false;
};
check( 'hooks: privacy export/erasure pages, export/erasure completion, role and capability changes, REST reads', $a122_hooked( 'wp_privacy_personal_data_export_page', 'privacy_export_page' ) && $a122_hooked( 'wp_privacy_personal_data_erasure_page', 'privacy_erasure_page' ) && $a122_hooked( 'wp_privacy_personal_data_exported', 'privacy_exported' ) && $a122_hooked( 'wp_privacy_personal_data_erased', 'privacy_erased' ) && $a122_hooked( 'set_user_role', 'role_set' ) && $a122_hooked( 'add_user_role', 'role_added' ) && $a122_hooked( 'remove_user_role', 'role_removed' ) && $a122_hooked( 'updated_user_meta', 'caps_after_update' ) && $a122_hooked( 'rest_post_dispatch', 'rest_read' ) );
check( 'Privacy::register() registers the access audit', false !== strpos( file_get_contents( WPCAP_PATH . 'src/Privacy.php' ), 'Privacy\AccessLog::register();' ) );

// ---------------------------------------------------------------------------------------- masking / diff
check( 'mask: e-mail keeps first letters and TLD only', 'e***@e***.com' === AccessLog::mask( 'erika.muster@example.com' ) );
check( 'mask: text keeps the first character, numbers/empty unchanged', 'M***' === AccessLog::mask( 'Max Mustermann' ) && '' === AccessLog::mask( '' ) && 5 === AccessLog::mask( 5 ) && null === AccessLog::mask( null ) );
$a122_diff = AccessLog::diff(
	array( 'attendee_name' => 'Max Muster', 'attendee_email' => 'max@example.com', 'status' => 'valid', 'token_hash' => 'aaa', 'city' => 'Wien' ),
	array( 'attendee_name' => 'Max Mustermann', 'attendee_email' => 'max@example.com', 'status' => 'checked_in', 'token_hash' => 'bbb', 'city' => 'Wien' ),
	array( 'status' )
);
check( 'diff: only changed fields, masked values', array( 'from' => 'M***', 'to' => 'M***' ) === ( $a122_diff['attendee_name'] ?? null ) && ! isset( $a122_diff['attendee_email'] ) && ! isset( $a122_diff['city'] ) );
check( 'diff: clear fields keep values, secrets only report a change', array( 'from' => 'valid', 'to' => 'checked_in' ) === ( $a122_diff['status'] ?? null ) && array( 'changed' => true ) === ( $a122_diff['token_hash'] ?? null ) );
check( 'diff: no plain personal value in the encoded diff', false === strpos( wp_json_encode( $a122_diff ), 'Muster' ) && false === strpos( wp_json_encode( $a122_diff ), 'aaa' ) );

// ---------------------------------------------------------------------------------------- views
$GLOBALS['wpcap_test_user_id'] = 4122;
$a122_obj = 912201;
check( 'view: first access of a user to an order is logged', true === AccessLog::view( 'order_detail', 'order', $a122_obj ) );
check( 'view: repeated access within the window is de-duplicated', false === AccessLog::view( 'order_detail', 'order', $a122_obj ) && 1 === $a122_count( 'pii_viewed', 'order', $a122_obj ) );
check( 'view: another view of the same object is logged separately', true === AccessLog::view( 'exchange_ticket', 'order', $a122_obj ) );
$GLOBALS['wpcap_test_user_id'] = 4123;
check( 'view: another user is logged separately', true === AccessLog::view( 'order_detail', 'order', $a122_obj ) );
$a122_v = $a122_last( 'pii_viewed' );
check( 'view: entry names who, what object and which view', $a122_v && 4123 === (int) $a122_v->user_id && 'order' === $a122_v->object_type && $a122_obj === (int) $a122_v->object_id && 'order_detail' === ( $a122_v->data['view'] ?? '' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_audit_logs SET created_at=%s WHERE action='pii_viewed' AND object_id=%d", gmdate( 'Y-m-d H:i:s', time() - 11 * 60 ), $a122_obj ) );
check( 'view: logged again once the 10-minute window has passed', true === AccessLog::view( 'order_detail', 'order', $a122_obj ) );
$GLOBALS['wpcap_test_filters']['wpcap_pii_audit_window'] = 0;
check( 'view: window 0 (filter wpcap_pii_audit_window) logs every access', true === AccessLog::view( 'order_detail', 'order', $a122_obj ) && true === AccessLog::view( 'order_detail', 'order', $a122_obj ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_pii_audit_window'] );

// Lists: once per page view and filter; search terms masked.
$a122_before = $a122_count( 'pii_list_viewed', 'list', 0 );
AccessLog::list_view( 'a122_list', array( 'status' => 'complete', 'search' => 'erika@example.com', 'paged' => 1 ), 20 );
AccessLog::list_view( 'a122_list', array( 'status' => 'complete', 'search' => 'erika@example.com', 'paged' => 1 ), 20 );
check( 'list: same filter on reload is logged once (not per row)', $a122_before + 1 === $a122_count( 'pii_list_viewed', 'list', 0 ) );
AccessLog::list_view( 'a122_list', array( 'status' => 'complete', 'search' => 'erika@example.com', 'paged' => 2 ), 20 );
check( 'list: another page or filter is a new page view', $a122_before + 2 === $a122_count( 'pii_list_viewed', 'list', 0 ) );
$a122_l = $a122_last( 'pii_list_viewed' );
check( 'list: filter stored with masked search, rows counted, no plain e-mail', 'complete' === ( $a122_l->data['filters']['status'] ?? '' ) && 'e***@e***.com' === ( $a122_l->data['filters']['search'] ?? '' ) && 20 === ( $a122_l->data['rows'] ?? 0 ) && false === strpos( (string) $a122_l->details, 'erika' ) );

// Exports: always logged.
$a122_exp = $a122_count( 'pii_exported', 'event', 912202 );
AccessLog::export( 'guestlist_csv', 'event', 912202, array( 'rows' => 3 ) );
AccessLog::export( 'guestlist_csv', 'event', 912202, array( 'rows' => 3 ) );
check( 'export: every export is logged (no de-duplication) with its kind', $a122_exp + 2 === $a122_count( 'pii_exported', 'event', 912202 ) && 'guestlist_csv' === ( $a122_last( 'pii_exported' )->data['kind'] ?? '' ) );

// Export handlers: every PII export path audits.
$a122_src = static function ( $file ) { return (string) file_get_contents( WPCAP_PATH . 'src/' . $file ); };
$a122_paths = array(
	'GuestLists/Page.php' => array( "'guestlist_csv'", "'guestlist_print'" ),
	'Partners/AdminPage.php' => array( "'partner_settlement_csv'", "'partner_statement_csv'" ),
	'BoxOffice/ShiftPage.php' => array( "'zreport_html'", "'zreport_csv'" ),
	'BoxOffice/Page.php' => array( "'pos_receipt'", "'pos_tickets_pdf'" ),
	'Printing/InvoicePdf.php' => array( "'invoice_pdf'" ),
	'Invoices.php' => array( "'invoice_html'", "'accounting_exported'" ),
	'Printing/Page.php' => array( "'attendee_list_'", "'no_show_list_'", "'print_batch'" ),
	'Reports/Page.php' => array( "'report_exported'" ),
	'Reports/Settlement.php' => array( "'settlement_sheet_'" ),
);
$a122_missing = array();
foreach ( $a122_paths as $file => $needles ) {
	foreach ( $needles as $needle ) { if ( false === strpos( $a122_src( $file ), $needle ) ) { $a122_missing[] = $file . ' ' . $needle; } }
}
check( 'export handlers (CSV, PDF, print lists) all write an audit entry' . ( $a122_missing ? ' – missing: ' . implode( ', ', $a122_missing ) : '' ), ! $a122_missing );
$a122_views = array(
	'Admin/OrdersPage.php' => array( "'order_list'", "'order_detail'" ),
	'Admin/TicketsPage.php' => array( "'ticket_list'" ),
	'Admin/CheckinsPage.php' => array( "'checkin_list'" ),
	'Admin/RefundsPage.php' => array( "'refund_queue'" ),
	'GuestLists/Page.php' => array( "'guestlist'", "'guestlist_checkin'" ),
	'Waitlists/AdminPage.php' => array( "'waitlist'" ),
	'Exchange/AdminPage.php' => array( "'exchange_ticket'", "'exchange_list'" ),
	'Admission/ConflictsPage.php' => array( "'admission_conflicts'" ),
	'Partners/AdminPage.php' => array( "'partner_detail'", "'partner_list'" ),
	'BoxOffice/Page.php' => array( "'pos_sale'" ),
	'Allocations/AdminPage.php' => array( "'allocation_occurrence'" ),
);
$a122_missing = array();
foreach ( $a122_views as $file => $needles ) {
	foreach ( $needles as $needle ) { if ( false === strpos( $a122_src( $file ), 'AccessLog::' ) || false === strpos( $a122_src( $file ), $needle ) ) { $a122_missing[] = $file . ' ' . $needle; } }
}
check( 'admin views with personal data log their access' . ( $a122_missing ? ' – missing: ' . implode( ', ', $a122_missing ) : '' ), ! $a122_missing );

// Rendering the ticket list logs once per filter and offers the correction form.
$GLOBALS['wpcap_test_user_id'] = 1;
$a122_type = make_type( 10, 10, 0 );
$a122_order = admin_paid_order( $a122_type, 2, 'a122-buyer@example.com' );
$a122_tickets = Orders::tickets_for_order( $a122_order );
$a122_ticket = (int) $a122_tickets[0]->ticket_id;
$_GET = array( 'page' => 'wpcap-tickets', 's' => 'a122-buyer@example.com' );
$a122_lv = $a122_count( 'pii_list_viewed' );
ob_start();
try { TicketsPage::render(); TicketsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a122_html = ob_get_clean();
$_GET = array();
check( 'ticket list: rendering twice with the same search logs one list view', $a122_lv + 1 === $a122_count( 'pii_list_viewed' ) && 'ticket_list' === ( $a122_last( 'pii_list_viewed' )->data['view'] ?? '' ) && false === strpos( (string) $a122_last( 'pii_list_viewed' )->details, 'a122-buyer' ) );
check( 'ticket list: correction form offered for valid tickets', false !== strpos( $a122_html, 'wpcap_correct_attendee' ) && false !== strpos( $a122_html, 'Teilnehmerdaten korrigieren' ) && false === strpos( $a122_html, 'EXCEPTION' ) );

// ---------------------------------------------------------------------------------------- corrections
$a122_c0 = $a122_count( 'record_corrected', 'ticket', $a122_ticket );
$a122_noreason = TicketsPage::correct_attendee( array( 'ticket_id' => $a122_ticket, 'attendee_name' => 'Erika Muster', 'attendee_email' => 'erika.muster@example.com' ) );
check( 'correction: a reason is required', is_wp_error( $a122_noreason ) && 'wpcap_correction_reason' === $a122_noreason->get_error_code() );
$a122_bad = TicketsPage::correct_attendee( array( 'ticket_id' => $a122_ticket, 'attendee_name' => 'Erika', 'attendee_email' => 'kein-mail', 'reason' => 'Tippfehler' ) );
check( 'correction: invalid e-mail refused', is_wp_error( $a122_bad ) );
$a122_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$p}wpcap_tickets WHERE ticket_id=%d", $a122_ticket ) );
$a122_fix = TicketsPage::correct_attendee( array( 'ticket_id' => $a122_ticket, 'attendee_name' => 'Erika Muster', 'attendee_email' => 'erika.muster@example.com', 'reason' => 'Tippfehler im Namen' ) );
$a122_row = $wpdb->get_row( $wpdb->prepare( "SELECT t.attendee_email,t.token_hash,ta.attendee_name,ta.attendee_email AS assigned FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_id=%d", $a122_ticket ) );
check( 'correction: attendee data stored, ticket code unchanged', ! is_wp_error( $a122_fix ) && ! empty( $a122_fix['changed'] ) && 'Erika Muster' === $a122_row->attendee_name && 'erika.muster@example.com' === $a122_row->assigned && 'erika.muster@example.com' === $a122_row->attendee_email && $a122_hash === $a122_row->token_hash );
$a122_c = $a122_last( 'record_corrected' );
check( 'correction: audited with masked before/after diff, source and reason', $a122_c0 + 1 === $a122_count( 'record_corrected', 'ticket', $a122_ticket ) && 'admin' === ( $a122_c->data['source'] ?? '' ) && array( 'from' => '', 'to' => 'E***' ) === ( $a122_c->data['changes']['attendee_name'] ?? null ) && 'e***@e***.com' === ( $a122_c->data['changes']['attendee_email']['to'] ?? '' ) && 1 === (int) $a122_c->user_id );
check( 'correction: no plain personal value in the audit entry', false === strpos( (string) $a122_c->details, 'erika.muster' ) && false === strpos( (string) $a122_c->details, 'Erika Muster' ) );
$a122_same = TicketsPage::correct_attendee( array( 'ticket_id' => $a122_ticket, 'attendee_name' => 'Erika Muster', 'attendee_email' => 'erika.muster@example.com', 'reason' => 'nochmal' ) );
check( 'correction: unchanged data writes nothing', ! is_wp_error( $a122_same ) && empty( $a122_same['changed'] ) && $a122_c0 + 1 === $a122_count( 'record_corrected', 'ticket', $a122_ticket ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders', 'manage_options' );
$GLOBALS['wpcap_test_user_id'] = 4124;
$a122_forbidden = TicketsPage::correct_attendee( array( 'ticket_id' => $a122_ticket, 'attendee_name' => 'X', 'reason' => 'x' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'correction: requires order rights for the event', is_wp_error( $a122_forbidden ) );
check( 'correction: admin-post handler registered', isset( Menu::handlers()['wpcap_correct_attendee'] ) );

// Billing correction through the order portal carries the masked diff.
$a122_order_row = Orders::get( $a122_order );
$a122_bill = Billing::update_for_order( $a122_order_row, array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => 'Ring 1', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT' ) );
$a122_b = $a122_last( 'billing_updated' );
check( 'billing correction: audit lists changed fields with masked values, country in clear', ! is_wp_error( $a122_bill ) && 'L***' === ( $a122_b->data['changes']['last_name']['to'] ?? '' ) && 'AT' === ( $a122_b->data['changes']['country']['to'] ?? '' ) && false === strpos( (string) $a122_b->details, 'Lovelace' ) );

// ---------------------------------------------------------------------------------------- privacy tools
$a122_req = 912210;
$a122_resp = array( 'data' => array( array( 'group_id' => 'x', 'item_id' => 'y', 'data' => array() ) ), 'done' => false );
$a122_out = AccessLog::privacy_export_page( $a122_resp, 1, 'privacy@example.com', 1, $a122_req, false, 'wp-pass-captain-orders' );
AccessLog::privacy_export_page( array( 'data' => array(), 'done' => true ), 1, 'privacy@example.com', 2, $a122_req, false, 'wp-pass-captain-orders' );
AccessLog::privacy_export_page( $a122_resp, 2, 'privacy@example.com', 1, $a122_req, false, 'woocommerce-customer' );
$a122_pe = $a122_last( 'privacy_export_run' );
check( 'privacy export: plugin exporter page logged with request, exporter, items and masked e-mail; response unchanged', $a122_out === $a122_resp && 1 === $a122_count( 'privacy_export_run', 'privacy_request', $a122_req ) && 'wp-pass-captain-orders' === ( $a122_pe->data['exporter'] ?? '' ) && 1 === ( $a122_pe->data['items'] ?? 0 ) && 'p***@e***.com' === ( $a122_pe->data['email'] ?? '' ) );
check( 'privacy export: empty follow-up pages and foreign exporters are not logged', 1 === $a122_count( 'privacy_export_run', 'privacy_request', $a122_req ) );
AccessLog::privacy_erasure_page( array( 'items_removed' => true, 'items_retained' => true, 'messages' => array(), 'done' => true ), 1, 'privacy@example.com', 1, $a122_req, 'wp-pass-captain-guestlists' );
AccessLog::privacy_erasure_page( array( 'items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => true ), 1, 'privacy@example.com', 1, $a122_req, 'other-plugin' );
$a122_pr = $a122_last( 'privacy_erasure_run' );
check( 'privacy erasure: plugin eraser page logged with removed/retained flags', 1 === $a122_count( 'privacy_erasure_run', 'privacy_request', $a122_req ) && true === ( $a122_pr->data['items_removed'] ?? null ) && true === ( $a122_pr->data['items_retained'] ?? null ) && 'wp-pass-captain-guestlists' === ( $a122_pr->data['eraser'] ?? '' ) );
AccessLog::privacy_exported( $a122_req );
AccessLog::privacy_erased( $a122_req );
check( 'privacy tools: export file and completed erasure are logged per request', 1 === $a122_count( 'privacy_export_completed', 'privacy_request', $a122_req ) && 1 === $a122_count( 'privacy_erasure_completed', 'privacy_request', $a122_req ) );
check( 'privacy tools: plugin exporter keys recognised (core and module exporters)', AccessLog::is_plugin_privacy_key( 'wp-pass-captain-orders' ) && AccessLog::is_plugin_privacy_key( 'wpcap-sms' ) && ! AccessLog::is_plugin_privacy_key( 'woocommerce-customer' ) );
// Through the filter as WordPress core calls it.
$a122_flt = apply_filters( 'wp_privacy_personal_data_export_page', $a122_resp, 1, 'privacy@example.com', 1, $a122_req + 1, false, 'wp-pass-captain-orders' );
check( 'privacy export: runs through the WordPress filter', $a122_flt === $a122_resp && 1 === $a122_count( 'privacy_export_run', 'privacy_request', $a122_req + 1 ) );

// ---------------------------------------------------------------------------------------- roles and caps
$a122_u = 912220;
do_action( 'set_user_role', $a122_u, 'wpcap_checkin_staff', array( 'subscriber' ) );
$a122_r = $a122_last( 'user_role_set' );
check( 'roles: set_user_role to a plugin role is logged (target user, new and old roles, actor)', 1 === $a122_count( 'user_role_set', 'user', $a122_u ) && 'wpcap_checkin_staff' === ( $a122_r->data['role'] ?? '' ) && array( 'subscriber' ) === ( $a122_r->data['old_roles'] ?? null ) && 1 === (int) $a122_r->user_id );
do_action( 'set_user_role', $a122_u, 'subscriber', array( 'wpcap_checkin_staff' ) );
do_action( 'set_user_role', $a122_u, 'author', array( 'subscriber' ) );
check( 'roles: losing a plugin role is logged, unrelated role changes are not', 2 === $a122_count( 'user_role_set', 'user', $a122_u ) );
do_action( 'add_user_role', $a122_u, 'wpcap_finance' );
do_action( 'remove_user_role', $a122_u, 'wpcap_finance' );
do_action( 'add_user_role', $a122_u, 'editor' );
check( 'roles: add_user_role/remove_user_role of plugin roles are logged', 1 === $a122_count( 'user_role_added', 'user', $a122_u ) && 1 === $a122_count( 'user_role_removed', 'user', $a122_u ) );
$a122_key = $p . 'capabilities';
$GLOBALS['p1u_user_meta'][ $a122_u ][ $a122_key ] = array( 'subscriber' => true );
do_action( 'update_user_meta', 1, $a122_u, $a122_key, array( 'subscriber' => true, 'wpcap_checkin' => true ) );
do_action( 'updated_user_meta', 1, $a122_u, $a122_key, array( 'subscriber' => true, 'wpcap_checkin' => true ) );
$a122_ca = $a122_last( 'user_cap_added' );
check( 'caps: a plugin capability granted to a single user (WP_User::add_cap) is logged', 1 === $a122_count( 'user_cap_added', 'user', $a122_u ) && array( 'wpcap_checkin' ) === ( $a122_ca->data['caps'] ?? null ) );
$GLOBALS['p1u_user_meta'][ $a122_u ][ $a122_key ] = array( 'subscriber' => true, 'wpcap_checkin' => true );
do_action( 'update_user_meta', 1, $a122_u, $a122_key, array( 'subscriber' => true, 'wpcap_ticket_admin' => true ) );
do_action( 'updated_user_meta', 1, $a122_u, $a122_key, array( 'subscriber' => true, 'wpcap_ticket_admin' => true ) );
check( 'caps: removal logged; plugin role slugs in the caps array are not counted as capabilities', 1 === $a122_count( 'user_cap_removed', 'user', $a122_u ) && 1 === $a122_count( 'user_cap_added', 'user', $a122_u ) );
do_action( 'updated_user_meta', 1, $a122_u, 'nickname', 'x' );
check( 'caps: other user meta is ignored', 1 === $a122_count( 'user_cap_added', 'user', $a122_u ) );
check( 'staff assignment grants/revokes wpcap_checkin through WP_User (audited as capability change) and stays audited as staff_assigned/staff_removed', false !== strpos( $a122_src( 'Admin/EventsPage.php' ), "\$user->add_cap( 'wpcap_checkin' )" ) && false !== strpos( $a122_src( 'Admin/EventsPage.php' ), "Audit::record( 'staff_assigned'" ) && in_array( 'staff_assigned', AccessLog::groups()['roles'], true ) );

// ---------------------------------------------------------------------------------------- REST reads
$a122_request = static function ( $method, $route, array $params = array() ) {
	return new class( $method, $route, $params ) {
		public function __construct( private string $m, private string $r, private array $p ) {}
		public function get_method() { return $this->m; }
		public function get_route() { return $this->r; }
		public function get_param( $k ) { return $this->p[ $k ] ?? null; }
	};
};
$GLOBALS['wpcap_test_user_id'] = 4125;
$a122_rows = array( array( 'email' => 'w@example.com' ), array( 'email' => 'v@example.com' ) );
$a122_res = AccessLog::rest_read( $a122_rows, null, $a122_request( 'GET', '/wp-pass-captain/v1/events/912230/waitlist/entries' ) );
AccessLog::rest_read( $a122_rows, null, $a122_request( 'GET', '/wp-pass-captain/v1/events/912230/waitlist/entries' ) );
$a122_rr = $a122_last( 'pii_api_read' );
check( 'REST: staff read of waitlist entries logged once within the window, response untouched', $a122_res === $a122_rows && 1 === $a122_count( 'pii_api_read', 'event', 912230 ) && 'waitlist_entries' === ( $a122_rr->data['view'] ?? '' ) && 2 === ( $a122_rr->data['rows'] ?? 0 ) && 4125 === (int) $a122_rr->user_id );
AccessLog::rest_read( $a122_rows, null, $a122_request( 'GET', '/wp-pass-captain/v1/guestlists/search', array( 'occurrence_id' => 912231, 'q' => 'Erika' ) ) );
$a122_rg = $a122_last( 'pii_api_read' );
check( 'REST: guest list search logged per occurrence with masked query', 1 === $a122_count( 'pii_api_read', 'occurrence', 912231 ) && 'E***' === ( $a122_rg->data['params']['q'] ?? '' ) );
AccessLog::rest_read( $a122_rows, null, $a122_request( 'POST', '/wp-pass-captain/v1/events/912232/waitlist/entries' ) );
AccessLog::rest_read( new WP_Error( 'x', 'y', array( 'status' => 403 ) ), null, $a122_request( 'GET', '/wp-pass-captain/v1/events/912232/waitlist/entries' ) );
AccessLog::rest_read( $a122_rows, null, $a122_request( 'GET', '/wp-pass-captain/v1/events' ) );
check( 'REST: writes, errors and routes without personal data are not logged', 0 === $a122_count( 'pii_api_read', 'event', 912232 ) );
check( 'REST: offline package and partner API reads are covered', isset( AccessLog::rest_routes()['#^/wp-pass-captain/v1/app/offline/package$#'] ) && isset( AccessLog::rest_routes()['#^/wp-pass-captain/v1/partner/orders$#'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;

// ---------------------------------------------------------------------------------------- viewer
$a122_q = AuditLogPage::query( array( 'group' => 'roles', 'object_type' => 'user', 'object_id' => $a122_u ), 1, 50 );
$a122_actions = array_unique( array_map( static function ( $r ) { return $r->action; }, $a122_q['rows'] ) );
sort( $a122_actions );
check( 'viewer: filter by area returns only those actions', array( 'user_cap_added', 'user_cap_removed', 'user_role_added', 'user_role_removed', 'user_role_set' ) === $a122_actions && 6 === $a122_q['total'] );
$a122_q2 = AuditLogPage::query( array( 'action' => 'pii_viewed', 'user_id' => 4122 ), 1, 50 );
check( 'viewer: filter by exact action and user', $a122_q2['total'] >= 2 && ! array_filter( $a122_q2['rows'], static function ( $r ) { return 'pii_viewed' !== $r->action || 4122 !== (int) $r->user_id; } ) );
$a122_q3 = AuditLogPage::query( array( 'group' => 'pii', 'date_to' => '2000-01-01' ), 1, 50 );
check( 'viewer: date filter', 0 === $a122_q3['total'] );
check( 'viewer: details shown without the internal fingerprint', false === strpos( AuditLogPage::details_text( '{"view":"order_detail","fp":"abc"}' ), 'fp' ) );
check( 'viewer: every area has a label', array_keys( AccessLog::groups() ) === array_keys( AccessLog::group_labels() ) );
$_GET = array( 'page' => AuditLogPage::SLUG, 'group' => 'corrections' );
ob_start();
try { AuditLogPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a122_vhtml = ob_get_clean();
$_GET = array();
check( 'viewer: renders the area filter, retention note and correction entries', false !== strpos( $a122_vhtml, 'Audit-Log' ) && false !== strpos( $a122_vhtml, 'record_corrected' ) && false !== strpos( $a122_vhtml, '730 Tagen automatisch gelöscht' ) && false === strpos( $a122_vhtml, 'EXCEPTION' ) && false === strpos( $a122_vhtml, 'erika.muster@' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$a122_denied = false;
ob_start();
try { AuditLogPage::render(); } catch ( Throwable $e ) { $a122_denied = true; }
ob_end_clean();
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'viewer: administrators only', $a122_denied );
$a122_menu = array_column( Menu::entries(), 3, 0 );
check( 'viewer: listed in the menu (settings area) with manage_options', 'manage_options' === ( $a122_menu[ AuditLogPage::SLUG ] ?? '' ) && 'settings' === Menu::group_of( AuditLogPage::SLUG ) );
check( 'retention: audit entries (incl. access log) follow the audit retention step', 730 === PassCaptain\Privacy\Retention::days( 'audit_logs' ) );

// ---------------------------------------------------------------------------------------- docs
$a122_hooks_doc = (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' );
$a122_sec_doc = (string) file_get_contents( WPCAP_PATH . 'docs/SECURITY.md' );
check( 'docs: new hooks documented', false !== strpos( $a122_hooks_doc, '`wpcap_pii_audit_window`' ) && false !== strpos( $a122_hooks_doc, '`wpcap_pii_audit_rest_routes`' ) && false !== strpos( $a122_hooks_doc, '`wpcap_record_corrected` (Action)' ) );
$a122_undoc = array();
foreach ( AccessLog::groups() as $a122_list ) {
	foreach ( $a122_list as $a122_action ) { if ( false === strpos( $a122_sec_doc, $a122_action ) && false === strpos( $a122_sec_doc, rtrim( preg_replace( '/_(csv|html)$/', '_', $a122_action ), '_' ) ) ) { $a122_undoc[] = $a122_action; } }
}
check( 'docs: every audited action is listed in SECURITY.md' . ( $a122_undoc ? ' – missing: ' . implode( ', ', $a122_undoc ) : '' ), ! $a122_undoc );

$GLOBALS['wpcap_test_user_id'] = $a122_prev_user;
