<?php
/**
 * PASS-E17-01: public connector API of contract 1.4.0, so the LearnBoating ticket connector (and every other
 * connector) needs no direct table reads, no internal Audit::record() call and no write of the core option
 * wpcap_enabled_connectors.
 *
 * Part 1: read service wpcap_get_ticket_details() / wpcap_get_order_ticket_details() – positive key lists, held
 * against docs/EXTENSIONS.md; holder user ID; e-mail only with wpcap_read_holder_email and audited; check-ins;
 * occurrence cancelled/rescheduled; no table names, codes or free text.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;

require_once WPCAP_PATH . 'src/functions.php';

echo "PASS-E17-01: connector API (read service, audit entry, opt-in)\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
PassCaptain\Settings::update( array( 'require_terms' => 0 ) );

$e17_docs = (string) file_get_contents( WPCAP_PATH . 'docs/EXTENSIONS.md' );
$e17_section = static function ( $heading ) use ( $e17_docs ) {
	$start = strpos( $e17_docs, "\n## " . $heading . "\n" );
	if ( false === $start ) { return ''; }
	$end = strpos( $e17_docs, "\n## ", $start + 4 );
	return false === $end ? substr( $e17_docs, $start ) : substr( $e17_docs, $start, $end - $start );
};

/* ---- Part 1: read service ---- */

$e17_has_read = function_exists( 'wpcap_get_ticket_details' ) && function_exists( 'wpcap_get_order_ticket_details' ) && class_exists( PassCaptain\Api\TicketDetails::class );
check( 'E17-1: wpcap_get_ticket_details() and wpcap_get_order_ticket_details() exist (contract 1.4.0)', $e17_has_read );

if ( $e17_has_read ) {
	$e17_type = make_type( 20, 10, 1200 );
	$e17_row = $wpdb->get_row( "SELECT tt.occurrence_id, o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$e17_type" );
	$e17_occ = (int) $e17_row->occurrence_id;
	$e17_event = (int) $e17_row->event_id;
	$wpdb->query( "UPDATE {$p}wpcap_events SET name='E17MarkerEventName', description='E17MarkerDescription' WHERE event_id=$e17_event" );
	$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='E17MarkerLocation' WHERE occurrence_id=$e17_occ" );
	$e17_order = Orders::create( array( 'email' => 'e17-buyer@example.com', 'name' => 'E17MarkerBuyerName', 'items' => array( array( 'ticket_type_id' => $e17_type, 'quantity' => 2 ) ), 'idempotency_key' => 'e17-' . uniqid() ) );
	check( 'E17-1 fixture: order created', ! is_wp_error( $e17_order ) && ! empty( $e17_order['order_id'] ) );
	$e17_oid = (int) $e17_order['order_id'];
	Orders::confirm_manual_payment( $e17_oid );
	$wpdb->query( "UPDATE {$p}wpcap_orders SET customer_user_id=8802 WHERE order_id=$e17_oid" );
	$e17_tickets = $wpdb->get_results( "SELECT ticket_id, public_id FROM {$p}wpcap_tickets WHERE order_id=$e17_oid ORDER BY ticket_id" );
	check( 'E17-1 fixture: two tickets issued', 2 === count( $e17_tickets ) );
	$e17_t1 = (int) $e17_tickets[0]->ticket_id;
	$e17_t2 = (int) $e17_tickets[1]->ticket_id;
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET attendee_email='e17-attendee@example.com', token_hash=%s WHERE ticket_id=%d", str_repeat( 'e17c', 16 ), $e17_t1 ) );
	$wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='' WHERE ticket_id=$e17_t2" );
	// WordPress account with the attendee's e-mail address (get_user_by() stub of fixtures_admin).
	$e17_users_before = $GLOBALS['wpcap_test_users'] ?? array();
	$GLOBALS['wpcap_test_users'] = array_merge( $e17_users_before, array( (object) array( 'ID' => 8801, 'user_login' => 'e17attendee', 'user_email' => 'e17-attendee@example.com' ) ) );
	$e17_now = gmdate( 'Y-m-d H:i:s' );
	foreach ( array( 'checked_in', 'already_checked_in', 'undone', 'checked_in' ) as $e17_i => $e17_result ) {
		$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $e17_t1, 'event_id' => $e17_event, 'user_id' => 55, 'result' => $e17_result, 'device_id' => 0 === $e17_i ? 4 : 0, 'source' => 0 === $e17_i ? 'app' : 'web', 'idempotency_key' => hash( 'sha256', 'e17-' . $e17_i . uniqid() ), 'scanned_at' => 0 === $e17_i ? '2026-11-20 08:00:00' : null, 'created_at' => $e17_now ) );
	}

	// The contract: key lists spelled out here and held against the documentation.
	$e17_keys = array( 'version', 'ticket', 'occurrence', 'event', 'holder', 'checkins' );
	$e17_holder_keys = array( 'user_id', 'is_buyer', 'email_included', 'email' );
	$e17_checkin_keys = array( 'checkin_id', 'result', 'at', 'source', 'device_id' );
	$e17_order_keys = array( 'version', 'order', 'tickets' );
	$e17_occ_keys = array_merge( PassCaptain\Api\ReadFacade::OCCURRENCE_KEYS, array( 'cancelled', 'rescheduled' ) );
	$e17_event_keys = array_merge( PassCaptain\Api\ReadFacade::EVENT_KEYS, array( 'cancelled' ) );
	check( 'E17-1: class constants match the spelled-out key lists', array( $e17_keys, $e17_holder_keys, $e17_checkin_keys, $e17_order_keys ) === array( PassCaptain\Api\TicketDetails::KEYS, PassCaptain\Api\TicketDetails::HOLDER_KEYS, PassCaptain\Api\TicketDetails::CHECKIN_KEYS, PassCaptain\Api\TicketDetails::ORDER_KEYS ) && 1 === PassCaptain\Api\TicketDetails::VERSION && 'wpcap_read_holder_email' === PassCaptain\Api\TicketDetails::EMAIL_CAP );
	$e17_doc = $e17_section( 'Lesedienst für Connectoren' );
	$e17_undocumented = array_values( array_filter( array_unique( array_merge( $e17_keys, $e17_holder_keys, $e17_checkin_keys, $e17_order_keys, array( 'cancelled', 'rescheduled', 'include_email', 'user_id', 'source', 'wpcap_read_holder_email', 'holder_email_read' ) ) ), static fn( $k ) => false === strpos( $e17_doc, '`' . $k . '`' ) ) );
	check( 'E17-1: docs/EXTENSIONS.md "Lesedienst für Connectoren" names every key, argument, the capability and the audit action' . ( $e17_undocumented ? ': ' . implode( ', ', $e17_undocumented ) : '' ), '' !== $e17_doc && array() === $e17_undocumented );
	check( 'E17-1: the documentation names both functions with contract 1.4.0', false !== strpos( $e17_doc, '`wpcap_get_ticket_details( $id, $args = [] )`' ) && false !== strpos( $e17_doc, '`wpcap_get_order_ticket_details( $id, $args = [] )`' ) && 2 <= substr_count( $e17_doc, 'Vertrag 1.4.0' ) );

	$wpdb->wpcap_log = array();
	$e17_d1 = wpcap_get_ticket_details( $e17_t1, array( 'source' => 'e17_connector' ) );
	$e17_log = $wpdb->wpcap_log;
	$wpdb->wpcap_log = null;
	$e17_d2 = wpcap_get_ticket_details( (string) $e17_tickets[1]->public_id );
	check( 'E17-1: ticket details have exactly the contract keys (also nested)', is_array( $e17_d1 ) && $e17_keys === array_keys( $e17_d1 ) && PassCaptain\Api\ReadFacade::TICKET_KEYS === array_keys( $e17_d1['ticket'] ) && $e17_occ_keys === array_keys( (array) $e17_d1['occurrence'] ) && $e17_event_keys === array_keys( (array) $e17_d1['event'] ) && $e17_holder_keys === array_keys( $e17_d1['holder'] ) );
	check( 'E17-1: nested ticket, occurrence and event equal the read facade (one source of truth)', wpcap_get_ticket_state( $e17_t1 ) === $e17_d1['ticket'] && array_intersect_key( $e17_d1['occurrence'], array_flip( PassCaptain\Api\ReadFacade::OCCURRENCE_KEYS ) ) === wpcap_get_occurrence_state( $e17_occ ) && array_intersect_key( $e17_d1['event'], array_flip( PassCaptain\Api\ReadFacade::EVENT_KEYS ) ) === wpcap_get_event_state( $e17_event ) );
	check( 'E17-1: status, occurrence start/end and event of the ticket', 'valid' === $e17_d1['ticket']['status'] && $e17_occ === $e17_d1['occurrence']['occurrence_id'] && 1 === preg_match( '/Z$/', (string) $e17_d1['occurrence']['starts_at'] ) && array_key_exists( 'ends_at', $e17_d1['occurrence'] ) && false === $e17_d1['occurrence']['cancelled'] && false === $e17_d1['occurrence']['rescheduled'] && $e17_event === $e17_d1['event']['event_id'] && false === $e17_d1['event']['cancelled'] );
	check( 'E17-1: holder of a ticket with its own attendee e-mail = account with that address, not the buyer', array( 'user_id' => 8801, 'is_buyer' => false, 'email_included' => false, 'email' => null ) === $e17_d1['holder'] );
	check( 'E17-1: holder of a ticket without attendee e-mail = the buyer account of the order (lookup by public id)', is_array( $e17_d2 ) && $e17_t2 === $e17_d2['ticket']['ticket_id'] && array( 'user_id' => 8802, 'is_buyer' => true, 'email_included' => false, 'email' => null ) === $e17_d2['holder'] );
	$e17_ci = $e17_d1['checkins'];
	check( 'E17-1: check-ins list admission entries only (checked_in, undone, checked_in), oldest first, with the contract keys', 3 === count( $e17_ci ) && array( 'checked_in', 'undone', 'checked_in' ) === array_column( $e17_ci, 'result' ) && $e17_checkin_keys === array_keys( $e17_ci[0] ) && '2026-11-20T08:00:00Z' === $e17_ci[0]['at'] && 'app' === $e17_ci[0]['source'] && 4 === $e17_ci[0]['device_id'] && null === $e17_ci[1]['device_id'] && 1 === preg_match( '/Z$/', (string) $e17_ci[1]['at'] ) && array() === $e17_d2['checkins'] );
	$e17_blob = strtolower( (string) wp_json_encode( array( $e17_d1, $e17_d2 ) ) );
	$e17_leaks = array_values( array_filter( array( '@', 'wpcap_', 'e17markereventname', 'e17markerdescription', 'e17markerlocation', 'e17markerbuyername', str_repeat( 'e17c', 16 ), 'attendee_email', 'customer_email', '"user_id":55' ), static fn( $m ) => false !== strpos( $e17_blob, $m ) ) );
	check( 'E17-1: no e-mail, table/column name, code hash, name, free text or scanning staff ID without the capability' . ( $e17_leaks ? ': ' . implode( ', ', $e17_leaks ) : '' ), array() === $e17_leaks );
	check( 'E17-1: a read without include_email only issues SELECT statements', count( $e17_log ) > 0 && array() === array_filter( $e17_log, static fn( $sql ) => 1 !== preg_match( '/^\s*SELECT\s/i', $sql ) ) );

	// E-mail: only with the own capability, audited, never with the address in the log.
	$e17_audit = static function () use ( $wpdb, $p, $e17_t1 ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='holder_email_read' AND object_type='ticket' AND object_id=$e17_t1" ); };
	$e17_audit_before = $e17_audit();
	$GLOBALS['wpcap_test_user_caps'][8810] = array( 'manage_options', 'wpcap_manage_orders' );
	$GLOBALS['wpcap_test_user_caps'][8811] = array( 'wpcap_read_holder_email' );
	$e17_denied = wpcap_get_ticket_details( $e17_t1, array( 'include_email' => true, 'user_id' => 8810, 'source' => 'e17_connector' ) );
	$e17_nobody = wpcap_get_ticket_details( $e17_t1, array( 'include_email' => true, 'user_id' => 0 ) );
	check( 'E17-1: include_email without wpcap_read_holder_email (even an administrator) or without a user gives no e-mail and no audit entry', null === $e17_denied['holder']['email'] && false === $e17_denied['holder']['email_included'] && null === $e17_nobody['holder']['email'] && $e17_audit_before === $e17_audit() );
	$e17_granted = wpcap_get_ticket_details( $e17_t1, array( 'include_email' => true, 'user_id' => 8811, 'source' => 'E17 Connector!' ) );
	$e17_entry = $wpdb->get_row( "SELECT user_id, details FROM {$p}wpcap_audit_logs WHERE action='holder_email_read' AND object_id=$e17_t1 ORDER BY audit_id DESC LIMIT 1" );
	$e17_entry_details = json_decode( (string) ( $e17_entry->details ?? '' ), true );
	check( 'E17-1: with wpcap_read_holder_email the e-mail is returned and one audit entry (source, reader, no address) is written', 'e17-attendee@example.com' === $e17_granted['holder']['email'] && true === $e17_granted['holder']['email_included'] && $e17_audit_before + 1 === $e17_audit() && is_array( $e17_entry_details ) && 'e17connector' === ( $e17_entry_details['source'] ?? '' ) && 8811 === (int) ( $e17_entry_details['reader_user_id'] ?? 0 ) && false === strpos( (string) $e17_entry->details, '@' ) );
	$GLOBALS['wpcap_test_user_id'] = 8811;
	$e17_current = wpcap_get_ticket_details( $e17_t2, array( 'include_email' => true ) );
	$GLOBALS['wpcap_test_user_id'] = 1;
	check( 'E17-1: without user_id the current user is the reader; buyer ticket gives the buyer address', 'e17-buyer@example.com' === $e17_current['holder']['email'] );
	unset( $GLOBALS['wpcap_test_user_caps'][8810], $GLOBALS['wpcap_test_user_caps'][8811] );

	// Occurrence cancelled and rescheduled are visible on the next read.
	$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET status='cancelled', rescheduled_at=%s WHERE occurrence_id=%d", $e17_now, $e17_occ ) );
	$e17_d3 = wpcap_get_ticket_details( $e17_t1 );
	check( 'E17-1: cancelled and rescheduled occurrence flags', true === $e17_d3['occurrence']['cancelled'] && true === $e17_d3['occurrence']['rescheduled'] && 'cancelled' === $e17_d3['occurrence']['status'] );
	$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='scheduled', rescheduled_at=NULL WHERE occurrence_id=$e17_occ" );

	// Order.
	$e17_opub = (string) $wpdb->get_var( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=$e17_oid" );
	$e17_o = wpcap_get_order_ticket_details( $e17_opub );
	check( 'E17-1: order details: contract keys, order state of the facade, both tickets in ID order with full ticket details', is_array( $e17_o ) && $e17_order_keys === array_keys( $e17_o ) && wpcap_get_order_state( $e17_oid ) === $e17_o['order'] && array( $e17_t1, $e17_t2 ) === array_map( static fn( $t ) => $t['ticket']['ticket_id'], $e17_o['tickets'] ) && $e17_keys === array_keys( $e17_o['tickets'][0] ) && $e17_o === wpcap_get_order_ticket_details( $e17_oid ) );
	$e17_bad = array( 0, -1, 999999999, '', 'abc', null, array( $e17_t1 ), str_repeat( 'g', 32 ) );
	$e17_all_null = true;
	foreach ( $e17_bad as $e17_id ) {
		if ( null !== wpcap_get_ticket_details( $e17_id ) || null !== wpcap_get_order_ticket_details( $e17_id ) ) { $e17_all_null = false; }
	}
	check( 'E17-1: unknown and invalid ids give null', $e17_all_null && null === wpcap_get_order_ticket_details( (string) $e17_tickets[0]->public_id ) );
	check( 'E17-1: wpcap_read_holder_email is a plugin capability (removed on uninstall), not granted to a role package', in_array( 'wpcap_read_holder_email', PassCaptain\Capabilities::all(), true ) && ! in_array( 'wpcap_read_holder_email', PassCaptain\Capabilities::CORE, true ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Installer.php' ), 'wpcap_read_holder_email' ) );
	$GLOBALS['wpcap_test_users'] = $e17_users_before;
}

check( 'E17: WPCAP_CONTRACT_VERSION is at least 1.4.0 and the version table names 1.4.0', version_compare( (string) ( preg_match( "/^define\( 'WPCAP_CONTRACT_VERSION', '([^']+)' \);/m", (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' ), $e17_m ) ? $e17_m[1] : '0' ), '1.4.0', '>=' ) && 1 === preg_match( '/^\| 1\.4\.0 \| 1\.6\.\d+ \|/m', $e17_docs ) );
