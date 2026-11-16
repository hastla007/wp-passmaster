<?php
/**
 * PC-4: documented read facade (contract 1.2.0). Every read function returns exactly the keys of its positive
 * list, accepts the record ID and (orders, tickets) the 32-hex public ID, gives null for unknown or invalid IDs,
 * never returns marker values planted in personal, code and free-text columns, issues only SELECT statements,
 * fires no hook and needs no capability.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Api\ReadFacade;
use PassCaptain\Orders;

require_once WPCAP_PATH . 'src/functions.php';

echo "PC-4: read facade for order, ticket, refund, occurrence and event state\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
PassCaptain\Settings::update( array( 'require_terms' => 0 ) );

// Marker values: none of them may appear in any result.
$pc4_markers = array(
	'pc4-marker-buyer@example.com',
	'pc4-marker-attendee@example.com',
	'Pc4MarkerBuyerName',
	'Pc4MarkerEventName',
	'pc4-marker-slug',
	'Pc4MarkerDescription',
	'Pc4MarkerLocation',
	'Pc4MarkerTicketTypeName',
	'Pc4MarkerRefundReason',
	'Pc4MarkerRefundError',
	'Pc4MarkerProviderRef',
	'Pc4MarkerCreditNote',
	'Pc4MarkerBilling',
	'Pc4MarkerAttendeeData',
	str_repeat( 'c0de', 16 ), // ticket token hash (stands for the code)
	str_repeat( 'acce55', 10 ) . 'acce', // order access token hash
	'Pc4MarkerIdempotency',
);

// Fixture: event, occurrence, ticket type, a paid order with two tickets and a refund row.
$pc4_type = make_type( 20, 10, 1500 );
$pc4_type_row = $wpdb->get_row( "SELECT tt.occurrence_id, o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$pc4_type" );
$pc4_occ = (int) $pc4_type_row->occurrence_id;
$pc4_event = (int) $pc4_type_row->event_id;
$wpdb->query( "UPDATE {$p}wpcap_events SET name='Pc4MarkerEventName', slug='pc4-marker-slug', description='Pc4MarkerDescription' WHERE event_id=$pc4_event" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Pc4MarkerLocation' WHERE occurrence_id=$pc4_occ" );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET name='Pc4MarkerTicketTypeName' WHERE ticket_type_id=$pc4_type" );
$pc4_order = Orders::create( array( 'email' => 'pc4-marker-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $pc4_type, 'quantity' => 2 ) ), 'idempotency_key' => 'pc4-' . uniqid() ) );
check( 'PC-4 fixture: order created', ! is_wp_error( $pc4_order ) && ! empty( $pc4_order['order_id'] ) );
$pc4_oid = (int) $pc4_order['order_id'];
Orders::confirm_manual_payment( $pc4_oid );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET customer_name='Pc4MarkerBuyerName', access_token_hash=%s, provider_reference='Pc4MarkerProviderRef', billing='{\"name\":\"Pc4MarkerBilling\"}', attendee_data='[\"Pc4MarkerAttendeeData\"]', customer_user_id=7 WHERE order_id=%d", str_repeat( 'acce55', 10 ) . 'acce', $pc4_oid ) );
$pc4_tickets = $wpdb->get_results( "SELECT ticket_id, public_id FROM {$p}wpcap_tickets WHERE order_id=$pc4_oid ORDER BY ticket_id" );
check( 'PC-4 fixture: two tickets issued', 2 === count( $pc4_tickets ) );
$pc4_tid = (int) $pc4_tickets[0]->ticket_id;
$pc4_tpub = (string) $pc4_tickets[0]->public_id;
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET attendee_email='pc4-marker-attendee@example.com', token_hash=%s WHERE ticket_id=%d", str_repeat( 'c0de', 16 ), $pc4_tid ) );
$pc4_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_refunds", array( 'order_id' => $pc4_oid, 'idempotency_key' => 'Pc4MarkerIdempotency', 'amount_minor' => 1500, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => wp_json_encode( array( (int) $pc4_tickets[1]->ticket_id ) ), 'reason' => 'Pc4MarkerRefundReason', 'status' => 'failed', 'provider_reference' => 'Pc4MarkerProviderRef', 'requested_by' => 3, 'approved_by' => 0, 'error_message' => 'Pc4MarkerRefundError', 'credit_note_number' => 'Pc4MarkerCreditNote', 'deducted_minor' => 100, 'created_at' => $pc4_now, 'updated_at' => $pc4_now ) );
$pc4_rid = (int) $wpdb->insert_id;
$pc4_opub = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=$pc4_oid" );

// The contract: the key lists are spelled out here, so a change of the class constants alone fails the test.
$pc4_expected = array(
	'order' => array( 'version', 'order_id', 'public_id', 'status', 'payment_status', 'payment_provider', 'currency', 'total_minor', 'subtotal_minor', 'discount_minor', 'tax_minor', 'refunded_minor', 'ticket_count', 'checked_in_count', 'customer_user_id', 'created_at', 'updated_at' ),
	'ticket' => array( 'version', 'ticket_id', 'public_id', 'order_id', 'order_public_id', 'ticket_type_id', 'occurrence_id', 'event_id', 'status', 'checked_in', 'refunded', 'issued_at' ),
	'refund' => array( 'version', 'refund_id', 'order_id', 'order_public_id', 'status', 'currency', 'amount_minor', 'tax_minor', 'deducted_minor', 'ticket_count', 'requested_by_user_id', 'approved_by_user_id', 'created_at', 'updated_at' ),
	'occurrence' => array( 'version', 'occurrence_id', 'event_id', 'status', 'starts_at', 'ends_at', 'timezone', 'capacity', 'issued_ticket_count', 'rescheduled_at' ),
	'event' => array( 'version', 'event_id', 'status', 'occurrence_count', 'created_at', 'updated_at' ),
);
check( 'PC-4: class constants match the documented key lists', $pc4_expected === array( 'order' => ReadFacade::ORDER_KEYS, 'ticket' => ReadFacade::TICKET_KEYS, 'refund' => ReadFacade::REFUND_KEYS, 'occurrence' => ReadFacade::OCCURRENCE_KEYS, 'event' => ReadFacade::EVENT_KEYS ) && 1 === ReadFacade::VERSION );

$pc4_results = array(
	'order' => wpcap_get_order_state( $pc4_oid ),
	'ticket' => wpcap_get_ticket_state( $pc4_tid ),
	'refund' => wpcap_get_refund_state( $pc4_rid ),
	'occurrence' => wpcap_get_occurrence_state( $pc4_occ ),
	'event' => wpcap_get_event_state( $pc4_event ),
);
foreach ( $pc4_results as $pc4_kind => $pc4_result ) {
	check( "PC-4: $pc4_kind state returns exactly the whitelisted keys", is_array( $pc4_result ) && $pc4_expected[ $pc4_kind ] === array_keys( $pc4_result ) );
}

// No marker value leaks (also not in a different letter case).
$pc4_blob = strtolower( (string) wp_json_encode( $pc4_results ) );
$pc4_leaks = array_values( array_filter( $pc4_markers, static fn( $m ) => false !== strpos( $pc4_blob, strtolower( $m ) ) ) );
check( 'PC-4: no name, e-mail, code hash, token, provider reference or free text in any result' . ( $pc4_leaks ? ': ' . implode( ', ', $pc4_leaks ) : '' ), array() === $pc4_leaks && false === strpos( $pc4_blob, '@' ) );

// Values.
$pc4_o = $pc4_results['order'];
check( 'PC-4: order state values (ids, status, amounts in minor units, counts, user id, UTC timestamps)', $pc4_oid === $pc4_o['order_id'] && $pc4_opub === $pc4_o['public_id'] && 'paid' === $pc4_o['payment_status'] && 'EUR' === $pc4_o['currency'] && 3000 === $pc4_o['total_minor'] && 0 === $pc4_o['refunded_minor'] && 2 === $pc4_o['ticket_count'] && 0 === $pc4_o['checked_in_count'] && 7 === $pc4_o['customer_user_id'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $pc4_o['created_at'] ) );
$pc4_t = $pc4_results['ticket'];
check( 'PC-4: ticket state values (public id, order, type, occurrence, event, not checked in)', $pc4_tid === $pc4_t['ticket_id'] && $pc4_tpub === $pc4_t['public_id'] && $pc4_oid === $pc4_t['order_id'] && $pc4_opub === $pc4_t['order_public_id'] && $pc4_type === $pc4_t['ticket_type_id'] && $pc4_occ === $pc4_t['occurrence_id'] && $pc4_event === $pc4_t['event_id'] && 'valid' === $pc4_t['status'] && false === $pc4_t['checked_in'] && false === $pc4_t['refunded'] && 1 === preg_match( '/Z$/', (string) $pc4_t['issued_at'] ) );
$pc4_r = $pc4_results['refund'];
check( 'PC-4: refund state values (amounts, counts, user ids, no approver = null)', $pc4_rid === $pc4_r['refund_id'] && $pc4_oid === $pc4_r['order_id'] && $pc4_opub === $pc4_r['order_public_id'] && 'failed' === $pc4_r['status'] && 1500 === $pc4_r['amount_minor'] && 100 === $pc4_r['deducted_minor'] && 1 === $pc4_r['ticket_count'] && 3 === $pc4_r['requested_by_user_id'] && null === $pc4_r['approved_by_user_id'] );
$pc4_oc = $pc4_results['occurrence'];
check( 'PC-4: occurrence state values (event, status, UTC start, time zone, issued tickets)', $pc4_occ === $pc4_oc['occurrence_id'] && $pc4_event === $pc4_oc['event_id'] && 'scheduled' === $pc4_oc['status'] && 'Europe/Vienna' === $pc4_oc['timezone'] && 2 === $pc4_oc['issued_ticket_count'] && null === $pc4_oc['ends_at'] && null === $pc4_oc['rescheduled_at'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $pc4_oc['starts_at'] ) );
$pc4_starts = (string) $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$pc4_occ" );
check( 'PC-4: occurrence start is the stored UTC value (re-read after commit, R-7.4)', str_replace( ' ', 'T', $pc4_starts ) . 'Z' === $pc4_oc['starts_at'] );
$pc4_e = $pc4_results['event'];
check( 'PC-4: event state values (status, occurrence count)', $pc4_event === $pc4_e['event_id'] && 'published' === $pc4_e['status'] && 1 === $pc4_e['occurrence_count'] );

// Both kinds of ID.
check( 'PC-4: order by record id (int and digit string) and by public id (also upper case) give the same state', $pc4_o === ReadFacade::order( (string) $pc4_oid ) && $pc4_o === wpcap_get_order_state( $pc4_opub ) && $pc4_o === ReadFacade::order( strtoupper( $pc4_opub ) ) );
check( 'PC-4: ticket by record id (int and digit string) and by public id give the same state', $pc4_t === ReadFacade::ticket( (string) $pc4_tid ) && $pc4_t === wpcap_get_ticket_state( $pc4_tpub ) );
check( 'PC-4: a ticket public id is no order public id and vice versa', null === wpcap_get_order_state( $pc4_tpub ) && null === wpcap_get_ticket_state( $pc4_opub ) );
check( 'PC-4: refund, occurrence and event take the record id as digit string too', $pc4_r === wpcap_get_refund_state( (string) $pc4_rid ) && $pc4_oc === wpcap_get_occurrence_state( (string) $pc4_occ ) && $pc4_e === wpcap_get_event_state( (string) $pc4_event ) );

// Unknown and invalid IDs.
$pc4_bad = array( 0, -1, 999999999, '0', '', ' ', 'abc', '12abc', '1.5', 1.5, null, true, array( $pc4_oid ), str_repeat( 'f', 32 ), str_repeat( 'g', 32 ), substr( $pc4_opub, 0, 31 ) );
$pc4_all_null = true;
foreach ( array( 'wpcap_get_order_state', 'wpcap_get_ticket_state', 'wpcap_get_refund_state', 'wpcap_get_occurrence_state', 'wpcap_get_event_state' ) as $pc4_fn ) {
	foreach ( $pc4_bad as $pc4_id ) {
		if ( null !== $pc4_fn( $pc4_id ) ) { $pc4_all_null = false; echo '    not null: ' . $pc4_fn . ' ' . var_export( $pc4_id, true ) . "\n"; }
	}
}
check( 'PC-4: unknown and invalid ids give null for every read function (' . count( $pc4_bad ) . ' cases each)', $pc4_all_null );
check( 'PC-4: records without a public id do not accept one', null === wpcap_get_refund_state( $pc4_opub ) && null === wpcap_get_occurrence_state( $pc4_opub ) && null === wpcap_get_event_state( $pc4_opub ) );

// State changes are visible on the next call (no cache).
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=$pc4_tid" );
check( 'PC-4: check-in is visible on the next read (no cache): ticket checked_in, order checked_in_count', true === wpcap_get_ticket_state( $pc4_tid )['checked_in'] && 1 === wpcap_get_order_state( $pc4_oid )['checked_in_count'] && 2 === wpcap_get_occurrence_state( $pc4_occ )['issued_ticket_count'] );

// Pure reads: one SELECT per call, no write, no hook; no capability needed.
$GLOBALS['wpcap_test_user_id'] = 0;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_view_reports', 'wpcap_scan' );
$pc4_tables = array( 'wpcap_orders', 'wpcap_tickets', 'wpcap_refunds', 'wpcap_occurrences', 'wpcap_events', 'wpcap_ticket_types' );
$pc4_checksum = static function () use ( $wpdb, $p, $pc4_tables ) {
	$sums = array();
	foreach ( $pc4_tables as $t ) { $sums[ $t ] = $wpdb->get_row( "CHECKSUM TABLE {$p}{$t}", ARRAY_A )['Checksum'] ?? null; }
	return $sums;
};
$pc4_before = $pc4_checksum();
$pc4_actions = count( $GLOBALS['wpcap_test_actions'] ?? array() );
$wpdb->wpcap_log = array();
$pc4_again = array(
	'order' => wpcap_get_order_state( $pc4_opub ),
	'ticket' => wpcap_get_ticket_state( $pc4_tpub ),
	'refund' => wpcap_get_refund_state( $pc4_rid ),
	'occurrence' => wpcap_get_occurrence_state( $pc4_occ ),
	'event' => wpcap_get_event_state( $pc4_event ),
);
$pc4_log = $wpdb->wpcap_log;
$wpdb->wpcap_log = null;
$pc4_non_select = array_values( array_filter( $pc4_log, static fn( $sql ) => 1 !== preg_match( '/^\s*SELECT\s/i', $sql ) ) );
check( 'PC-4: five reads issue exactly five SELECT statements and nothing else', 5 === count( $pc4_log ) && array() === $pc4_non_select );
check( 'PC-4: no SELECT * (personal columns are never loaded) and no locking read', array() === array_filter( $pc4_log, static fn( $sql ) => 1 === preg_match( '/SELECT\s+\*|\.\*|FOR UPDATE|LOCK IN SHARE MODE/i', $sql ) ) );
check( 'PC-4: tables unchanged (checksums) and no hook fired by the reads', $pc4_before === $pc4_checksum() && count( $GLOBALS['wpcap_test_actions'] ?? array() ) === $pc4_actions );
check( 'PC-4: reads work without a logged-in user and without any capability (callers decide on display rights)', is_array( $pc4_again['order'] ) && is_array( $pc4_again['ticket'] ) && is_array( $pc4_again['refund'] ) && is_array( $pc4_again['occurrence'] ) && is_array( $pc4_again['event'] ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

// Source guard: the facade names no personal, code or free-text column and asks no capability.
$pc4_src = (string) file_get_contents( WPCAP_PATH . 'src/Api/ReadFacade.php' );
$pc4_code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $pc4_src );
$pc4_forbidden = array_values( array_filter( array( 'customer_email', 'customer_name', 'attendee', 'token_hash', 'access_token', 'provider_reference', 'billing', 'reason', 'error_message', 'location', 'description', 'credit_note_number', 'idempotency_key', 'e.name', 'tt.name', 'slug', 'current_user_can', 'do_action', 'apply_filters', 'set_transient', 'wp_cache_set', 'update_option', 'INSERT', 'UPDATE ', 'DELETE' ), static fn( $w ) => false !== stripos( $pc4_code, $w ) ) );
check( 'PC-4: ReadFacade source reads no personal/code/free-text column, writes nothing, checks no capability' . ( $pc4_forbidden ? ': ' . implode( ', ', $pc4_forbidden ) : '' ), array() === $pc4_forbidden );
check( 'PC-4: contract version is at least 1.2.0 and documents the facade', version_compare( (string) ( preg_match( "/^define\( 'WPCAP_CONTRACT_VERSION', '([^']+)' \);/m", (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' ), $pc4_m ) ? $pc4_m[1] : '0' ), '1.2.0', '>=' ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/EXTENSIONS.md' ), '## Lesefassade für Zustände' ) );
