<?php
/**
 * Integration tests for the admin screens' non-rendering logic (src/Admin/*).
 * Included from run.php; uses check(), make_type() and the schema installed there.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\CheckinsPage;
use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\OrdersPage;
use PassCaptain\Admin\Queries;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Admin\TicketsPage;
use PassCaptain\Orders;
use PassCaptain\Settings;

/* ---- WordPress stubs needed by the admin handlers (only if no other test file defined them) ---- */
final class Wpcap_Test_Redirect extends Exception {}
final class Wpcap_Test_Die extends Exception {}
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $len = 12, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $len ) ), 0, $len ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'check_admin_referer' ) ) { function check_admin_referer( $action = -1 ) { $GLOBALS['wpcap_test_nonce_checks'][] = $action; return 1; } }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $url ) { throw new Wpcap_Test_Redirect( $url ); } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $message = '', $title = '', $args = array() ) { throw new Wpcap_Test_Die( (string) $message ); } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		foreach ( $GLOBALS['wpcap_test_users'] ?? array() as $user ) {
			if ( ( 'id' === $field && (int) $user->ID === (int) $value ) || ( 'login' === $field && $user->user_login === $value ) || ( 'email' === $field && $user->user_email === $value ) ) { return $user; }
		}
		return false;
	}
}
final class Wpcap_Test_User {
	public array $caps = array();
	public function __construct( public int $ID, public string $user_login, public string $user_email ) {}
	public function add_cap( $cap ) { $this->caps[] = $cap; }
}
$GLOBALS['wpcap_test_users'] = array( new Wpcap_Test_User( 7, 'scanner', 'scanner@example.com' ) );

function admin_actions_since( $offset ) { return array_slice( $GLOBALS['wpcap_test_actions'], $offset ); }
function admin_last_audit( $action ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s ORDER BY audit_id DESC LIMIT 1", $action ) );
}
/** Creates a paid order with $qty tickets of $type and returns its order id. */
function admin_paid_order( $type, $qty, $email ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'admin-' . uniqid( '', true ) ) );
	if ( is_wp_error( $r ) ) { echo '    (order failed: ' . $r->get_error_message() . ")\n"; return 0; }
	Orders::confirm_manual_payment( $r['order_id'] );
	return (int) $r['order_id'];
}

global $wpdb;
$p = $wpdb->prefix;

echo "Admin: money and percent parsing\n";
$money_cases = array(
	array( '12,50', 2, 1250 ), array( '12.50', 2, 1250 ), array( '12', 2, 1200 ), array( '12,5', 2, 1250 ), array( '0,99', 2, 99 ),
	array( '1.234,56', 2, 123456 ), array( '1,234.56', 2, 123456 ), array( '1 234,56', 2, 123456 ), array( "1'234.50", 2, 123450 ),
	array( '1.234', 2, 123400 ), array( '1.234.567', 2, 123456700 ), array( '€ 12,50', 2, 1250 ), array( '12,50 EUR', 2, 1250 ),
	array( ',5', 2, 50 ), array( '12,500', 2, 1250000 ), array( '12,5000', 2, 1250 ), array( '0', 2, 0 ), array( '1500', 0, 1500 ), array( '100,00', 0, 100 ),
	array( '-5', 2, null ), array( 'abc', 2, null ), array( '', 2, null ), array( '1,2.3', 2, null ), array( '12,345,67', 2, null ), array( '12,555', 2, 1255500 ),
	array( '12,505', 2, 1250500 ), array( '1.2.3', 2, null ), array( '12,999', 2, 1299900 ), array( '0.001', 2, null ), array( '12.3456', 2, null ), array( '99999999999', 2, null ), array( array(), 2, null ),
);
$bad = array();
foreach ( $money_cases as $case ) {
	$got = Forms::parse_money( $case[0], $case[1] );
	if ( $got !== $case[2] ) { $bad[] = json_encode( $case[0] ) . ' => ' . var_export( $got, true ); }
}
check( 'parse_money handles German/English formats and rejects invalid input' . ( $bad ? ' (' . implode( '; ', $bad ) . ')' : '' ), ! $bad );
check( 'money_input round-trips', '1234,50' === Forms::money_input( 123450 ) && 123450 === Forms::parse_money( Forms::money_input( 123450 ) ) && '0,05' === Forms::money_input( 5 ) && '1500' === Forms::money_input( 1500, 0 ) );
check( 'zero-decimal currencies are recognised', 0 === Forms::currency_decimals( 'JPY' ) && 2 === Forms::currency_decimals( 'EUR' ) );
$pct_cases = array( array( '19', 1900 ), array( '7,7', 770 ), array( '7.75', 775 ), array( '19 %', 1900 ), array( '0', 0 ), array( '', 0 ), array( '100', 10000 ), array( '100,01', null ), array( '7,775', null ), array( '-7', null ), array( 'x', null ), array( '2,5', 250 ) );
$bad = array();
foreach ( $pct_cases as $case ) {
	$got = Forms::parse_percent_bp( $case[0] );
	if ( $got !== $case[1] ) { $bad[] = $case[0] . ' => ' . var_export( $got, true ); }
}
check( 'parse_percent_bp converts percent to basis points' . ( $bad ? ' (' . implode( '; ', $bad ) . ')' : '' ), ! $bad );
check( 'percent_input formats basis points', '19' === Forms::percent_input( 1900 ) && '7,7' === Forms::percent_input( 770 ) && '7,75' === Forms::percent_input( 775 ) && '0' === Forms::percent_input( 0 ) );

echo "Admin: timezone conversion\n";
check( 'local summer time converts to UTC', '2026-07-01 18:00:00' === Forms::local_to_utc( '2026-07-01T20:00', 'Europe/Berlin' ) );
check( 'local winter time converts to UTC', '2026-12-01 19:00:00' === Forms::local_to_utc( '2026-12-01 20:00', 'Europe/Berlin' ) );
check( 'other zones convert (New York)', '2026-07-02 00:30:00' === Forms::local_to_utc( '2026-07-01T20:30', 'America/New_York' ) );
check( 'invalid dates and zones are rejected', null === Forms::local_to_utc( '2026-02-30T10:00', 'Europe/Berlin' ) && null === Forms::local_to_utc( '2026-07-01T20:00', 'Mars/Base' ) && null === Forms::local_to_utc( 'morgen', 'UTC' ) && null === Forms::local_to_utc( '2026-07-01T25:00', 'UTC' ) );
check( 'UTC converts back to the datetime-local value', '2026-07-01T20:00' === Forms::utc_to_local( '2026-07-01 18:00:00', 'Europe/Berlin' ) && '' === Forms::utc_to_local( null, 'Europe/Berlin' ) );
list( $from, $to ) = CheckinsPage::date_bounds( '2026-07-01', '2026-07-01', 'Europe/Berlin' );
check( 'check-in date filter becomes a UTC range in the event timezone', '2026-06-30 22:00:00' === $from && '2026-07-01 22:00:00' === $to );

echo "Admin: events\n";
$future = gmdate( 'Y-m-d', time() + 30 * 86400 );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_events' );
$denied = EventsPage::create_event( array( 'name' => 'Nope' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'creating events requires wpcap_manage_events', is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'] );
$created = EventsPage::create_event( array( 'name' => 'Admin Gala', 'description' => 'Text', 'starts_at' => $future . 'T19:30', 'ends_at' => $future . 'T22:00', 'timezone' => 'Europe/Berlin', 'repeat_frequency' => 'weekly', 'repeat_count' => '3', 'location' => 'Saal', 'event_capacity' => '100', 'ticket_name' => 'Parkett', 'price' => '12,50', 'currency' => 'EUR', 'tax_rate' => '19', 'ticket_capacity' => '50', 'status' => 'published' ) );
check( 'create_event stores event, weekly occurrences and ticket types', ! is_wp_error( $created ) && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=" . (int) $created['event_id'] ) );
$event_id = (int) $created['event_id'];
$occs = Queries::occurrences_for_event( $event_id );
$type_row = $wpdb->get_row( "SELECT * FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occs[0]->occurrence_id );
check( 'price and tax are stored in minor units / basis points', 1250 === (int) $type_row->price_minor && 1900 === (int) $type_row->tax_rate_bp );
check( 'occurrence start is stored in UTC', Forms::local_to_utc( $future . 'T19:30', 'Europe/Berlin' ) === $occs[0]->starts_at && 'Europe/Berlin' === $occs[0]->timezone );
$invalid = EventsPage::create_event( array( 'name' => 'X', 'starts_at' => $future . 'T19:30', 'timezone' => 'Europe/Berlin', 'ticket_name' => 'A', 'price' => '12,345,6', 'currency' => 'EUR' ) );
check( 'create_event rejects an invalid price', is_wp_error( $invalid ) && 'wpcap_event_price' === $invalid->get_error_code() );
$overview = Queries::events_overview( null, 1, 500 );
$rows = array_values( array_filter( $overview['rows'], static fn( $r ) => (int) $r->event_id === $event_id ) );
$ids = array_map( static fn( $r ) => (int) $r->event_id, $overview['rows'] );
check( 'event list has one row per event with occurrence count and next occurrence', 1 === count( $rows ) && 3 === (int) $rows[0]->occurrence_count && (int) $rows[0]->next_occurrence_id === (int) $occs[0]->occurrence_id && count( $ids ) === count( array_unique( $ids ) ) && $overview['total'] >= count( $ids ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$scoped = Queries::events_overview( Queries::event_ids_for( 'wpcap_manage_events' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'event list is scoped to assigned events for non-admins', 0 === $scoped['total'] );

$updated = EventsPage::update_event( array( 'event_id' => $event_id, 'name' => 'Admin Gala 2', 'description' => '<b>neu</b>', 'status' => 'draft' ) );
check( 'update_event changes name and status', ! is_wp_error( $updated ) && 'draft' === Queries::event( $event_id )->status && 'Admin Gala 2' === Queries::event( $event_id )->name );
$bad_status = EventsPage::update_event( array( 'event_id' => $event_id, 'name' => 'Admin Gala 2', 'status' => 'cancelled' ) );
check( 'update_event rejects other statuses', is_wp_error( $bad_status ) );
EventsPage::update_event( array( 'event_id' => $event_id, 'name' => 'Admin Gala', 'status' => 'published' ) );

$occ = $occs[0];
$type_id = (int) $type_row->ticket_type_id;
$order_id = admin_paid_order( $type_id, 2, 'admin-buyer@example.com' );
check( 'test order is paid with tickets', $order_id && 2 === count( Orders::tickets_for_order( $order_id ) ) );
$mark = count( $GLOBALS['wpcap_test_actions'] );
$same_start = EventsPage::save_occurrence( array( 'occurrence_id' => $occ->occurrence_id, 'starts_at' => $future . 'T19:30', 'ends_at' => $future . 'T22:00', 'timezone' => 'Europe/Berlin', 'location' => 'Großer Saal', 'capacity' => '100', 'status' => 'scheduled' ) );
check( 'editing without start change does not fire a reschedule', ! is_wp_error( $same_start ) && ! $same_start['rescheduled'] && ! in_array( 'wpcap_occurrence_rescheduled', admin_actions_since( $mark ), true ) && 'Großer Saal' === Queries::occurrence( $occ->occurrence_id )->location );
$moved = EventsPage::save_occurrence( array( 'occurrence_id' => $occ->occurrence_id, 'starts_at' => $future . 'T20:00', 'ends_at' => $future . 'T22:30', 'timezone' => 'Europe/Berlin', 'location' => 'Großer Saal', 'capacity' => '100', 'status' => 'scheduled' ) );
check( 'moving an occurrence with sold tickets fires wpcap_occurrence_rescheduled', ! is_wp_error( $moved ) && $moved['rescheduled'] && in_array( 'wpcap_occurrence_rescheduled', admin_actions_since( $mark ), true ) && Forms::local_to_utc( $future . 'T20:00', 'Europe/Berlin' ) === Queries::occurrence( $occ->occurrence_id )->starts_at );
$audit = admin_last_audit( 'occurrence_updated' );
check( 'reschedule is audited with old and new start', $audit && false !== strpos( $audit->details, '"rescheduled":true' ) );
$mark = count( $GLOBALS['wpcap_test_actions'] );
$moved_empty = EventsPage::save_occurrence( array( 'occurrence_id' => $occs[1]->occurrence_id, 'starts_at' => $future . 'T18:00', 'timezone' => 'Europe/Berlin', 'capacity' => '0' ) );
check( 'moving an occurrence without tickets does not fire', ! is_wp_error( $moved_empty ) && ! $moved_empty['rescheduled'] && ! in_array( 'wpcap_occurrence_rescheduled', admin_actions_since( $mark ), true ) );
$too_small = EventsPage::save_occurrence( array( 'occurrence_id' => $occ->occurrence_id, 'starts_at' => $future . 'T20:00', 'timezone' => 'Europe/Berlin', 'capacity' => '1' ) );
check( 'occurrence capacity cannot drop below sold tickets', is_wp_error( $too_small ) && 'wpcap_capacity_below_sold' === $too_small->get_error_code() );
$bad_end = EventsPage::save_occurrence( array( 'event_id' => $event_id, 'starts_at' => $future . 'T20:00', 'ends_at' => $future . 'T19:00', 'timezone' => 'Europe/Berlin' ) );
check( 'occurrence end must be after start', is_wp_error( $bad_end ) );
$new_occ = EventsPage::save_occurrence( array( 'event_id' => $event_id, 'starts_at' => $future . 'T10:00', 'timezone' => 'America/New_York', 'location' => 'NYC', 'capacity' => '5' ) );
check( 'a new occurrence is added in its own timezone', ! is_wp_error( $new_occ ) && Forms::local_to_utc( $future . 'T10:00', 'America/New_York' ) === Queries::occurrence( $new_occ['occurrence_id'] )->starts_at );
$mark = count( $GLOBALS['wpcap_test_actions'] );
$cancelled = EventsPage::cancel_occurrence( array( 'occurrence_id' => $occ->occurrence_id ) );
check( 'cancelling an occurrence with tickets warns and fires wpcap_occurrence_cancelled', ! is_wp_error( $cancelled ) && 2 === $cancelled['issued'] && ! empty( $cancelled['warning'] ) && 'cancelled' === Queries::occurrence( $occ->occurrence_id )->status && in_array( 'wpcap_occurrence_cancelled', admin_actions_since( $mark ), true ) );
EventsPage::save_occurrence( array( 'occurrence_id' => $occ->occurrence_id, 'starts_at' => $future . 'T20:00', 'timezone' => 'Europe/Berlin', 'capacity' => '100', 'status' => 'scheduled' ) );

echo "Admin: ticket types and participant fields\n";
$tt = EventsPage::save_ticket_type( array( 'occurrence_id' => $new_occ['occurrence_id'], 'name' => 'VIP', 'price' => '1.234,56', 'currency' => 'EUR', 'tax_rate' => '7,7', 'capacity' => '20', 'max_per_order' => '4', 'sales_start' => $future . 'T08:00', 'sales_end' => '', 'status' => 'paused' ) );
$vip = ! is_wp_error( $tt ) ? Queries::ticket_type( $tt['ticket_type_id'] ) : null;
check( 'new ticket type stores price, tax rate and sales window in UTC', $vip && 123456 === (int) $vip->price_minor && 770 === (int) $vip->tax_rate_bp && 4 === (int) $vip->max_per_order && 'paused' === $vip->status && Forms::local_to_utc( $future . 'T08:00', 'America/New_York' ) === $vip->sales_start && null === $vip->sales_end );
$bad_window = EventsPage::save_ticket_type( array( 'occurrence_id' => $new_occ['occurrence_id'], 'name' => 'X', 'price' => '1', 'currency' => 'EUR', 'sales_start' => $future . 'T10:00', 'sales_end' => $future . 'T09:00' ) );
$bad_tax = EventsPage::save_ticket_type( array( 'occurrence_id' => $new_occ['occurrence_id'], 'name' => 'X', 'price' => '1', 'currency' => 'EUR', 'tax_rate' => '120' ) );
check( 'invalid sales window and tax rate are rejected', is_wp_error( $bad_window ) && is_wp_error( $bad_tax ) && 'wpcap_type_tax' === $bad_tax->get_error_code() );
$currency_change = EventsPage::save_ticket_type( array( 'ticket_type_id' => $type_id, 'name' => 'Parkett', 'price' => '15', 'currency' => 'CHF', 'tax_rate' => '19', 'capacity' => '50', 'max_per_order' => '10' ) );
check( 'currency of a ticket type with orders is locked', is_wp_error( $currency_change ) && 'wpcap_type_currency_locked' === $currency_change->get_error_code() );
$type_cap = EventsPage::save_ticket_type( array( 'ticket_type_id' => $type_id, 'name' => 'Parkett', 'price' => '15', 'currency' => 'EUR', 'tax_rate' => '19', 'capacity' => '1', 'max_per_order' => '10' ) );
check( 'ticket type capacity cannot drop below sold', is_wp_error( $type_cap ) && 'wpcap_capacity_below_sold' === $type_cap->get_error_code() );
$repriced = EventsPage::save_ticket_type( array( 'ticket_type_id' => $type_id, 'name' => 'Parkett', 'price' => '15', 'currency' => 'EUR', 'tax_rate' => '19', 'capacity' => '50', 'max_per_order' => '10', 'status' => 'active' ) );
check( 'price change on an existing type is saved; order snapshot unchanged', ! is_wp_error( $repriced ) && 1500 === (int) Queries::ticket_type( $type_id )->price_minor && 1250 === (int) $wpdb->get_var( "SELECT unit_price_minor FROM {$p}wpcap_order_items WHERE order_id=$order_id" ) );

$field = EventsPage::save_participant_field( array( 'ticket_type_id' => $type_id, 'field_key' => 'Firma-Name', 'label' => 'Firma', 'field_type' => 'text', 'required' => '1', 'purpose' => 'Namensschild', 'retention_days' => '30' ) );
$field_row = ! is_wp_error( $field ) ? Queries::participant_field( $field['field_id'] ) : null;
check( 'participant field is created with a normalised key', $field_row && 'firma_name' === $field_row->field_key && 1 === (int) $field_row->required && 30 === (int) $field_row->retention_days && 'active' === $field_row->status );
$dup = EventsPage::save_participant_field( array( 'ticket_type_id' => $type_id, 'field_key' => 'firma_name', 'label' => 'Doppelt', 'field_type' => 'text' ) );
$bad_type = EventsPage::save_participant_field( array( 'ticket_type_id' => $type_id, 'field_key' => 'x', 'label' => 'X', 'field_type' => 'select' ) );
check( 'duplicate keys and unknown field types are rejected', is_wp_error( $dup ) && 'wpcap_field_key_taken' === $dup->get_error_code() && is_wp_error( $bad_type ) );
EventsPage::save_participant_field( array( 'field_id' => $field['field_id'], 'field_key' => 'changed', 'label' => 'Firma (optional)', 'field_type' => 'textarea', 'retention_days' => '60' ) );
$field_row = Queries::participant_field( $field['field_id'] );
check( 'editing a field keeps its key', 'firma_name' === $field_row->field_key && 'Firma (optional)' === $field_row->label && 0 === (int) $field_row->required && 'textarea' === $field_row->field_type );
EventsPage::set_participant_field_status( array( 'field_id' => $field['field_id'], 'status' => 'inactive' ) );
check( 'participant field can be deactivated', 'inactive' === Queries::participant_field( $field['field_id'] )->status );

echo "Admin: staff\n";
$assign = EventsPage::assign_staff( array( 'event_id' => $event_id, 'user' => 'scanner@example.com', 'capability' => 'wpcap_checkin' ) );
$assign_again = EventsPage::assign_staff( array( 'event_id' => $event_id, 'user' => 'scanner', 'capability' => 'wpcap_checkin' ) );
check( 'staff can be assigned by e-mail or login (idempotent)', ! is_wp_error( $assign ) && ! is_wp_error( $assign_again ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_event_staff WHERE event_id=$event_id AND user_id=7" ) && in_array( 'wpcap_checkin', $GLOBALS['wpcap_test_users'][0]->caps, true ) );
check( 'unknown users and capabilities are rejected', is_wp_error( EventsPage::assign_staff( array( 'event_id' => $event_id, 'user' => 'nobody', 'capability' => 'wpcap_checkin' ) ) ) && is_wp_error( EventsPage::assign_staff( array( 'event_id' => $event_id, 'user' => '7', 'capability' => 'manage_options' ) ) ) );
EventsPage::remove_staff( array( 'event_id' => $event_id, 'user_id' => 7, 'capability' => 'wpcap_checkin' ) );
check( 'staff assignment can be removed', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_event_staff WHERE event_id=$event_id AND user_id=7" ) );

echo "Admin: orders\n";
$GLOBALS['wpcap_test_transients'] = array();
$pending = Orders::create( array( 'email' => 'admin-pending@example.com', 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => 1 ) ), 'idempotency_key' => 'admin-pending-' . uniqid() ) );
$found = Queries::search_orders( array( 'search' => 'admin-pending@', 'status' => 'pending_payment' ), null );
$by_id = Queries::search_orders( array( 'search' => substr( $pending['public_id'], 0, 10 ) ), null );
$by_event = Queries::search_orders( array( 'event_id' => $event_id ), null, 1, 1 );
$paid_filter = Queries::search_orders( array( 'event_id' => $event_id, 'payment_status' => 'paid' ), null );
check( 'order search filters by e-mail, public id, event and status with pagination', 1 === $found['total'] && (int) $found['rows'][0]->order_id === (int) $pending['order_id'] && 1 === $by_id['total'] && 2 === $by_event['total'] && 1 === count( $by_event['rows'] ) && 1 === $paid_filter['total'] && false !== strpos( (string) $by_event['rows'][0]->event_names, 'Admin Gala' ) );
check( 'LIKE wildcards in the search are escaped', 0 === Queries::search_orders( array( 'search' => '%' ), null )['total'] );

$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='stripe' WHERE order_id=" . (int) $pending['order_id'] );
$not_manual = OrdersPage::confirm_payment( array( 'order_id' => $pending['order_id'] ) );
check( 'mark as paid is limited to the manual provider', is_wp_error( $not_manual ) && 'wpcap_not_manual' === $not_manual->get_error_code() && 'pending' === Orders::get( $pending['order_id'] )->payment_status );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='manual' WHERE order_id=" . (int) $pending['order_id'] );
$cancel_paid = OrdersPage::cancel_order( array( 'order_id' => $order_id ) );
check( 'paid orders cannot be cancelled via the unpaid cancel action', is_wp_error( $cancel_paid ) && 'complete' === Orders::get( $order_id )->status );
$cancel = OrdersPage::cancel_order( array( 'order_id' => $pending['order_id'], 'reason' => 'Doppelt bestellt' ) );
check( 'unpaid order is cancelled and its hold released', ! is_wp_error( $cancel ) && 'cancelled' === Orders::get( $pending['order_id'] )->status && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_reservations WHERE order_id=" . (int) $pending['order_id'] . " AND status='active'" ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_manage_orders' );
$forbidden = OrdersPage::resend_tickets( array( 'order_id' => $order_id ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'order actions require wpcap_manage_orders', is_wp_error( $forbidden ) && 403 === $forbidden->get_error_data()['status'] );
check( 'order detail queries return items with tax and tickets', 1 === count( Queries::order_items( $order_id ) ) && 2 === count( Queries::order_tickets( $order_id ) ) && 1900 === (int) Queries::order_items( $order_id )[0]->tax_rate_bp );

echo "Admin: refunds\n";
Settings::update( array( 'refund_approval_threshold' => 0, 'require_2fa_for_refunds' => 0 ) );
$tickets = Orders::tickets_for_order( $order_id );
$no_reason = OrdersPage::refund( array( 'order_id' => $order_id, 'ticket_ids' => array( $tickets[0]->ticket_id ), 'reason' => '', 'idempotency_key' => wp_generate_uuid4() ) );
$no_selection = OrdersPage::refund( array( 'order_id' => $order_id, 'reason' => 'Test', 'idempotency_key' => wp_generate_uuid4() ) );
$no_key = OrdersPage::refund( array( 'order_id' => $order_id, 'ticket_ids' => array( $tickets[0]->ticket_id ), 'reason' => 'Test', 'idempotency_key' => '' ) );
check( 'refund requires a reason, a selection and a form key', is_wp_error( $no_reason ) && is_wp_error( $no_selection ) && is_wp_error( $no_key ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=$order_id" ) );
$form_key = wp_generate_uuid4();
$first = OrdersPage::refund( array( 'order_id' => $order_id, 'ticket_ids' => array( $tickets[0]->ticket_id ), 'reason' => 'Krank', 'idempotency_key' => $form_key ) );
$double = OrdersPage::refund( array( 'order_id' => $order_id, 'ticket_ids' => array( $tickets[0]->ticket_id ), 'reason' => 'Krank', 'idempotency_key' => $form_key ) );
check( 'double submit of the refund form creates one refund', ! is_wp_error( $first ) && ! is_wp_error( $double ) && $first['refund']['refund_id'] === $double['refund']['refund_id'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_refunds WHERE order_id=$order_id" ) && 'completed' === $first['refund']['status'] && 1250 === (int) $first['refund']['amount_minor'] );
check( 'refunded ticket is revoked', 'revoked' === Queries::ticket_context( $tickets[0]->ticket_id )->status );

Settings::update( array( 'refund_approval_threshold' => 500 ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$pending_refund = OrdersPage::refund( array( 'order_id' => $order_id, 'refund_all' => '1', 'reason' => 'Absage', 'idempotency_key' => wp_generate_uuid4() ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'refund above threshold waits for approval', ! is_wp_error( $pending_refund ) && 'pending_approval' === $pending_refund['refund']['status'] );
$refund_id = (int) $pending_refund['refund']['refund_id'];
$queue = Queries::pending_refunds( null );
check( 'pending refund appears in the approval queue', in_array( $refund_id, array_map( static fn( $r ) => (int) $r->refund_id, $queue ), true ) );
$self = OrdersPage::approve_refund( array( 'refund_id' => $refund_id ) );
check( 'the requester cannot approve their own refund', is_wp_error( $self ) && 'wpcap_refund_same_person' === $self->get_error_code() );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$no_cap = OrdersPage::approve_refund( array( 'refund_id' => $refund_id ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'approval requires wpcap_approve_refunds', is_wp_error( $no_cap ) && 403 === $no_cap->get_error_data()['status'] );
$GLOBALS['wpcap_test_user_id'] = 2;
$reject_no_reason = OrdersPage::reject_refund( array( 'refund_id' => $refund_id, 'reason' => '' ) );
$rejected = OrdersPage::reject_refund( array( 'refund_id' => $refund_id, 'reason' => 'Nicht berechtigt' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'another approver can reject with a reason', is_wp_error( $reject_no_reason ) && ! is_wp_error( $rejected ) && 'rejected' === $rejected['refund']['status'] && 'valid' === Queries::ticket_context( $tickets[1]->ticket_id )->status );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_approve_refunds' );
$second = OrdersPage::refund( array( 'order_id' => $order_id, 'refund_all' => '1', 'reason' => 'Absage', 'idempotency_key' => wp_generate_uuid4() ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 2;
$approved = OrdersPage::approve_refund( array( 'refund_id' => $second['refund']['refund_id'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
check( 'another approver can approve; order becomes refunded', ! is_wp_error( $approved ) && 'completed' === $approved['refund']['status'] && 'refunded' === Orders::get( $order_id )->status && 2500 === (int) Orders::get( $order_id )->refunded_minor );
Settings::update( array( 'refund_approval_threshold' => 0 ) );
$woo_order = admin_paid_order( $type_id, 1, 'admin-woo@example.com' );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_provider='woocommerce' WHERE order_id=$woo_order" );
$woo_refund = OrdersPage::refund( array( 'order_id' => $woo_order, 'refund_all' => '1', 'reason' => 'x', 'idempotency_key' => wp_generate_uuid4() ) );
$woo_cancel = OrdersPage::cancel_order( array( 'order_id' => $woo_order ) );
check( 'WooCommerce orders cannot be refunded or cancelled here', is_wp_error( $woo_refund ) && 'wpcap_refund_woocommerce' === $woo_refund->get_error_code() && is_wp_error( $woo_cancel ) );

echo "Admin: tickets and check-ins\n";
$t_order = admin_paid_order( $type_id, 3, 'admin-tickets@example.com' );
$t = Orders::tickets_for_order( $t_order );
$wpdb->query( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (" . (int) $t[0]->ticket_id . ",'admin-tickets@example.com','gast@example.com','Grace Gast','assigned',UTC_TIMESTAMP())" );
$by_attendee = Queries::search_tickets( array( 'search' => 'gast@example' ), null );
$by_ticket = Queries::search_tickets( array( 'search' => $t[1]->public_id ), null );
$by_buyer = Queries::search_tickets( array( 'search' => 'admin-tickets@', 'event_id' => $event_id ), null );
check( 'ticket search by attendee, ticket id, buyer and event', 1 === $by_attendee['total'] && 'Grace Gast' === $by_attendee['rows'][0]->attendee_name && 1 === $by_ticket['total'] && 3 === $by_buyer['total'] );
$no_reason = TicketsPage::revoke_ticket( array( 'ticket_id' => $t[0]->ticket_id ) );
$sold_before = Queries::sold_for_type( $type_id );
$revoked = TicketsPage::revoke_ticket( array( 'ticket_id' => $t[0]->ticket_id, 'reason' => 'Betrugsverdacht', 'release_capacity' => '1' ) );
check( 'revoking needs a reason; valid ticket is revoked and audited', is_wp_error( $no_reason ) && ! is_wp_error( $revoked ) && 'revoked' === Queries::ticket_context( $t[0]->ticket_id )->status && admin_last_audit( 'ticket_revoked' ) && (int) admin_last_audit( 'ticket_revoked' )->object_id === (int) $t[0]->ticket_id );
check( 'released capacity is sellable again', $sold_before - 1 === Queries::sold_for_type( $type_id ) );
check( 'already revoked tickets cannot be revoked again', is_wp_error( TicketsPage::revoke_ticket( array( 'ticket_id' => $t[0]->ticket_id, 'reason' => 'x' ) ) ) );

$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=" . (int) $t[1]->ticket_id );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $t[1]->ticket_id, 'event_id' => $event_id, 'occurrence_day_id' => 0, 'user_id' => 1, 'result' => 'checked_in', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
check( 'checked-in tickets cannot be revoked', is_wp_error( TicketsPage::revoke_ticket( array( 'ticket_id' => $t[1]->ticket_id, 'reason' => 'x' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'wpcap_checkin' );
$undo_denied = TicketsPage::undo_checkin( array( 'ticket_id' => $t[1]->ticket_id ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'undo check-in requires wpcap_checkin', is_wp_error( $undo_denied ) && 403 === $undo_denied->get_error_data()['status'] );
$undo = TicketsPage::undo_checkin( array( 'ticket_id' => $t[1]->ticket_id, 'reason' => 'Falsch gescannt' ) );
check( 'undo check-in makes the ticket valid, logs "undone" and audits', ! is_wp_error( $undo ) && 'valid' === Queries::ticket_context( $t[1]->ticket_id )->status && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=" . (int) $t[1]->ticket_id . " AND result='undone'" ) && admin_last_audit( 'checkin_undone' ) );
check( 'undo on a ticket that is not checked in is rejected', is_wp_error( TicketsPage::undo_checkin( array( 'ticket_id' => $t[1]->ticket_id ) ) ) );
$log = Queries::checkin_log( array( 'event_id' => $event_id ), null );
$undone_log = Queries::checkin_log( array( 'event_id' => $event_id, 'result' => 'undone' ), null );
$future_log = Queries::checkin_log( array( 'event_id' => $event_id, 'date_from' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), null );
check( 'check-in log filters by event, result and date', 2 === $log['total'] && 1 === $undone_log['total'] && 'undone' === $undone_log['rows'][0]->result && 0 === $future_log['total'] && in_array( 'undone', Queries::checkin_results(), true ) );

echo "Admin: settings\n";
Settings::update( array( 'stripe_secret_key' => 'sk_test_old', 'paypal_client_secret' => 'pp_old' ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$denied_settings = SettingsPage::save( array( 'hold_minutes' => '15' ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'settings require manage_options', is_wp_error( $denied_settings ) );
$saved = SettingsPage::save( array(
	'hold_minutes' => '9999', 'manual_hold_hours' => '48', 'enabled_providers' => array( 'manual', 'bogus' ), 'manual_instructions' => "IBAN DE00\nBank",
	'stripe_mode' => 'live', 'stripe_secret_key' => '', 'stripe_webhook_secret' => 'whsec_new', 'paypal_mode' => 'nonsense', 'paypal_client_secret__clear' => '1',
	'terms_text' => '<p>AGB</p>', 'require_terms' => '1', 'refund_approval_threshold' => '150,00', 'attendee_retention_days' => 'abc',
	'invoice_prefix' => 're-2026/x', 'credit_note_prefix' => '', 'seller_name' => '<b>Verein</b>',
) );
$all = Settings::all();
check( 'settings are sanitised and clamped', ! is_wp_error( $saved ) && 120 === $all['hold_minutes'] && 48 === $all['manual_hold_hours'] && array( 'manual' ) === $all['enabled_providers'] && 'live' === $all['stripe_mode'] && 'sandbox' === $all['paypal_mode'] && 15000 === $all['refund_approval_threshold'] && 1 === $all['require_terms'] && 0 === $all['delete_data_on_uninstall'] && 'Verein' === $all['seller_name'] );
check( 'empty secret keeps the stored value, new secret is stored, clear removes', 'sk_test_old' === $all['stripe_secret_key'] && 'whsec_new' === $all['stripe_webhook_secret'] && '' === $all['paypal_client_secret'] );
check( 'invalid numbers are reported, prefixes normalised', 1 === count( $saved['errors'] ) && 'RE-2026X' === $all['invoice_prefix'] && ( ! isset( $all['credit_note_prefix'] ) || 'GS' === $all['credit_note_prefix'] ) );
$defined = array();
foreach ( SettingsPage::field_definitions() as $section ) { $defined += $section['fields']; }
check( 'every settings key has a form field', ! array_diff( array_keys( array_diff_key( array_filter( Settings::defaults(), static fn( $v ) => ! is_array( $v ) ), PassCaptain\Modules::settings_defaults() ) ), array_keys( $defined ) ) && isset( $defined['enabled_providers'] ) );
putenv( 'WPCAP_STRIPE_SECRET_KEY=sk_env' );
check( 'constant/environment overrides are detected', 'WPCAP_STRIPE_SECRET_KEY' === SettingsPage::override_source( 'stripe_secret_key' ) && '' === SettingsPage::override_source( 'stripe_webhook_secret' ) );
putenv( 'WPCAP_STRIPE_SECRET_KEY' );
Settings::update( array( 'stripe_mode' => 'test', 'stripe_secret_key' => '', 'stripe_webhook_secret' => '', 'require_terms' => 0, 'terms_text' => '', 'refund_approval_threshold' => 0, 'hold_minutes' => 10, 'manual_hold_hours' => 72, 'manual_instructions' => '', 'seller_name' => '', 'invoice_prefix' => 'WPC' ) );

echo "Admin: admin-post wrappers\n";
$GLOBALS['wpcap_test_transients'] = array();
$GLOBALS['wpcap_test_nonce_checks'] = array();
$_POST = array( 'action' => 'wpcap_update_event', 'event_id' => (string) $event_id, 'name' => 'Admin Gala Final', 'description' => '', 'status' => 'published' );
$redirect = '';
try { EventsPage::handle_update_event(); } catch ( Wpcap_Test_Redirect $e ) { $redirect = $e->getMessage(); }
$notices = $GLOBALS['wpcap_test_transients'][ 'wpcap_admin_notices_1' ] ?? array();
check( 'wrapper checks the nonce, saves, flashes and redirects to the event', in_array( 'wpcap_update_event', $GLOBALS['wpcap_test_nonce_checks'] ?? array( 'wpcap_update_event' ), true ) && 'Admin Gala Final' === Queries::event( $event_id )->name && false !== strpos( $redirect, 'event_id=' . $event_id ) && 'success' === ( $notices[0]['type'] ?? '' ) );
$GLOBALS['wpcap_test_transients'] = array();
$_POST = array( 'action' => 'wpcap_update_event', 'event_id' => (string) $event_id, 'name' => '', 'status' => 'published' );
try { EventsPage::handle_update_event(); } catch ( Wpcap_Test_Redirect $e ) { $redirect = $e->getMessage(); }
$notices = $GLOBALS['wpcap_test_transients'][ 'wpcap_admin_notices_1' ] ?? array();
check( 'wrapper flashes validation errors', 'error' === ( $notices[0]['type'] ?? '' ) && 'Admin Gala Final' === Queries::event( $event_id )->name );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$_POST = array( 'action' => 'wpcap_save_settings', 'hold_minutes' => '30' );
$died = false;
try { SettingsPage::handle_save(); } catch ( Wpcap_Test_Die $e ) { $died = true; } catch ( Wpcap_Test_Redirect $e ) { $died = false; }
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'forbidden actions end with wp_die instead of a redirect', $died && 10 === (int) Settings::get( 'hold_minutes' ) );
$_POST = array();
$GLOBALS['wpcap_test_transients'] = array();
