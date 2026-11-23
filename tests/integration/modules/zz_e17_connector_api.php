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
// Text of a section of docs/EXTENSIONS.md up to the next heading of level 2 or 3.
$e17_section = static function ( $heading, $level = '##' ) use ( $e17_docs ) {
	$start = strpos( $e17_docs, "\n" . $level . ' ' . $heading . "\n" );
	if ( false === $start ) { return ''; }
	$from = $start + strlen( $heading ) + 4;
	$ends = array_filter( array( strpos( $e17_docs, "\n## ", $from ), strpos( $e17_docs, "\n### ", $from ) ), static fn( $pos ) => false !== $pos );
	return $ends ? substr( $e17_docs, $start, min( $ends ) - $start ) : substr( $e17_docs, $start );
};

/** Minimal connector for the source and opt-in checks. */
final class E17_Stub_Connector implements PassCaptain\Integrations\ConnectorInterface {
	public function __construct( private string $k ) {}
	public function key() { return $this->k; }
	public function version() { return '1.0.0'; }
	public function capabilities() { return array( 'tickets' ); }
	public function deliver( $event_name, array $payload, $idempotency_key ) { return true; }
}

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

/* ---- Part 2: audit entry of a connector ---- */

$e17_has_audit = function_exists( 'wpcap_connector_audit' ) && class_exists( PassCaptain\Integrations\ConnectorAudit::class );
check( 'E17-2: wpcap_connector_audit() exists (contract 1.4.0)', $e17_has_audit );
if ( $e17_has_audit ) {
	$e17_key = 'e17-lb_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	$e17_filter = static function ( $list ) use ( $e17_key ) { $list[] = new E17_Stub_Connector( $e17_key ); return $list; };
	add_filter( 'wp_pass_captain_connectors_v1', $e17_filter );
	$e17_count = static function ( $action ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s", $action ) ); };
	$e17_ok = wpcap_connector_audit( $e17_key, 'course_access_granted', 'ticket', 42, array( 'course_id' => 12, 'result' => 'ok', 'retry' => false, 'ratio' => 0.5, 'lb_ref' => 'lb:course-12.v2', 'none' => null ) );
	$e17_row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, action, object_type, object_id, details FROM {$p}wpcap_audit_logs WHERE action='connector_log_course_access_granted' AND details LIKE %s ORDER BY audit_id DESC LIMIT 1", '%' . $wpdb->esc_like( $e17_key ) . '%' ) );
	check( 'E17-2: a valid entry is written with prefix connector_log_, own source, object and the details', true === $e17_ok && $e17_row && 'ticket' === $e17_row->object_type && 42 === (int) $e17_row->object_id && array( 'source' => $e17_key, 'course_id' => 12, 'result' => 'ok', 'retry' => false, 'ratio' => 0.5, 'lb_ref' => 'lb:course-12.v2', 'none' => null ) === json_decode( (string) $e17_row->details, true ) );
	check( 'E17-2: object type defaults to connector with ID 0', true === wpcap_connector_audit( $e17_key, 'sync_finished' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='connector_log_sync_finished' AND object_type='connector' AND object_id=0 AND details LIKE %s", '%' . $wpdb->esc_like( $e17_key ) . '%' ) ) );
	$e17_core_prefixed = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $e17_file ) {
		if ( 'php' === $e17_file->getExtension() && preg_match( "/Audit::record\\( '" . PassCaptain\Integrations\ConnectorAudit::ACTION_PREFIX . '/', (string) file_get_contents( $e17_file->getPathname() ) ) ) { $e17_core_prefixed[] = $e17_file->getFilename(); }
	}
	check( 'E17-2: the core itself writes no audit action with the connector prefix connector_log_ (no look-alike entries)' . ( $e17_core_prefixed ? ': ' . implode( ', ', $e17_core_prefixed ) : '' ), 'connector_log_' === PassCaptain\Integrations\ConnectorAudit::ACTION_PREFIX && array() === $e17_core_prefixed );
	$e17_refused = array(
		'unregistered source' => array( 'e17_not_registered', 'sync', '', 0, array(), 'wpcap_connector_audit_source' ),
		'source with spaces' => array( 'E17 LB', 'sync', '', 0, array(), 'wpcap_connector_audit_source' ),
		'action free text' => array( $e17_key, 'Kurs freigegeben', '', 0, array(), 'wpcap_connector_audit_invalid' ),
		'action too long' => array( $e17_key, str_repeat( 'a', 49 ), '', 0, array(), 'wpcap_connector_audit_invalid' ),
		'unknown object type' => array( $e17_key, 'sync', 'wpcap_orders', 1, array(), 'wpcap_connector_audit_invalid' ),
		'negative object id' => array( $e17_key, 'sync', 'ticket', -1, array(), 'wpcap_connector_audit_invalid' ),
		'e-mail as value' => array( $e17_key, 'sync', 'ticket', 1, array( 'user' => 'e17-person@example.com' ), 'wpcap_connector_audit_invalid' ),
		'free text value' => array( $e17_key, 'sync', 'ticket', 1, array( 'note' => 'Max Mustermann kam zu spät' ), 'wpcap_connector_audit_invalid' ),
		'personal key email' => array( $e17_key, 'sync', 'ticket', 1, array( 'holder_email' => 'x' ), 'wpcap_connector_audit_invalid' ),
		'personal key name' => array( $e17_key, 'sync', 'ticket', 1, array( 'first_name' => 'x' ), 'wpcap_connector_audit_invalid' ),
		'personal key ip' => array( $e17_key, 'sync', 'ticket', 1, array( 'client_ip' => '1.2.3.4' ), 'wpcap_connector_audit_invalid' ),
		'code key' => array( $e17_key, 'sync', 'ticket', 1, array( 'ticket_code' => 'ABC' ), 'wpcap_connector_audit_invalid' ),
		'reserved key source' => array( $e17_key, 'sync', 'ticket', 1, array( 'source' => 'core' ), 'wpcap_connector_audit_invalid' ),
		'nested value' => array( $e17_key, 'sync', 'ticket', 1, array( 'data' => array( 'a' => 1 ) ), 'wpcap_connector_audit_invalid' ),
		'numeric key' => array( $e17_key, 'sync', 'ticket', 1, array( 0 => 'x' ), 'wpcap_connector_audit_invalid' ),
		'too many details' => array( $e17_key, 'sync', 'ticket', 1, array_combine( array_map( static fn( $i ) => 'k' . $i, range( 1, 11 ) ), range( 1, 11 ) ), 'wpcap_connector_audit_invalid' ),
		'value too long' => array( $e17_key, 'sync', 'ticket', 1, array( 'ref' => str_repeat( 'a', 65 ) ), 'wpcap_connector_audit_invalid' ),
		'infinite float' => array( $e17_key, 'sync', 'ticket', 1, array( 'ratio' => INF ), 'wpcap_connector_audit_invalid' ),
	);
	$e17_wrong = array();
	$e17_sync_before = $e17_count( 'connector_log_sync' );
	foreach ( $e17_refused as $e17_label => $e17_case ) {
		$e17_r = wpcap_connector_audit( ...array_slice( $e17_case, 0, 5 ) );
		if ( ! is_wp_error( $e17_r ) || $e17_case[5] !== $e17_r->get_error_code() || 400 !== ( $e17_r->get_error_data()['status'] ?? 0 ) ) { $e17_wrong[] = $e17_label; }
	}
	check( 'E17-2: invalid source, action, object, personal or free-text details are refused as a whole (' . count( $e17_refused ) . ' cases)' . ( $e17_wrong ? ': ' . implode( ', ', $e17_wrong ) : '' ), array() === $e17_wrong && $e17_sync_before === $e17_count( 'connector_log_sync' ) && 0 === $e17_count( 'connector_log_kurs freigegeben' ) );
	// Rate-safe: at most RATE_LIMIT entries per connector and minute; another connector is not affected.
	$e17_flood = 'e17_flood_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	$e17_flood_filter = static function ( $list ) use ( $e17_flood ) { $list[] = new E17_Stub_Connector( $e17_flood ); return $list; };
	add_filter( 'wp_pass_captain_connectors_v1', $e17_flood_filter );
	$e17_results = array();
	for ( $e17_i = 0; $e17_i <= PassCaptain\Integrations\ConnectorAudit::RATE_LIMIT; $e17_i++ ) { $e17_results[] = wpcap_connector_audit( $e17_flood, 'flood', '', 0, array( 'n' => $e17_i ) ); }
	$e17_last = end( $e17_results );
	$e17_written = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='connector_log_flood' AND details LIKE %s", '%' . $wpdb->esc_like( $e17_flood ) . '%' ) );
	check( 'E17-2: rate-safe: ' . PassCaptain\Integrations\ConnectorAudit::RATE_LIMIT . ' entries per connector and minute, then wpcap_connector_audit_rate_limited (429); other connectors unaffected', 60 === PassCaptain\Integrations\ConnectorAudit::RATE_LIMIT && PassCaptain\Integrations\ConnectorAudit::RATE_LIMIT === $e17_written && is_wp_error( $e17_last ) && 'wpcap_connector_audit_rate_limited' === $e17_last->get_error_code() && 429 === ( $e17_last->get_error_data()['status'] ?? 0 ) && true === wpcap_connector_audit( $e17_key, 'still_allowed' ) );
	remove_filter( 'wp_pass_captain_connectors_v1', $e17_flood_filter );
	remove_filter( 'wp_pass_captain_connectors_v1', $e17_filter );
	$e17_audit_doc = $e17_section( 'Protokolleintrag eines Connectors', '###' );
	$e17_missing = array_values( array_filter( array( '`wpcap_connector_audit(', 'Vertragsversion 1.4.0', '`connector_log_<action>`', '`source`', '`wpcap_connector_audit_source`', '`wpcap_connector_audit_invalid`', '`wpcap_connector_audit_rate_limited`', 'höchstens 60', 'höchstens 10' ), static fn( $w ) => false === strpos( $e17_audit_doc, $w ) ) );
	$e17_object_doc = array_values( array_filter( PassCaptain\Integrations\ConnectorAudit::OBJECT_TYPES, static fn( $t ) => false === strpos( $e17_audit_doc, '`' . $t . '`' ) ) );
	check( 'E17-2: docs/EXTENSIONS.md "Protokolleintrag eines Connectors" documents function, version, rules, object types and error codes' . ( $e17_missing || $e17_object_doc ? ': ' . implode( ', ', array_merge( $e17_missing, $e17_object_doc ) ) : '' ), '' !== $e17_audit_doc && array() === $e17_missing && array() === $e17_object_doc && 10 === PassCaptain\Integrations\ConnectorAudit::MAX_DETAILS );
}

/* ---- Part 3: opt-in through a function instead of writing the option ---- */

$e17_has_optin = function_exists( 'wpcap_set_connector_enabled' ) && function_exists( 'wpcap_is_connector_enabled' );
check( 'E17-3: wpcap_set_connector_enabled() and wpcap_is_connector_enabled() exist (contract 1.4.0)', $e17_has_optin );
if ( $e17_has_optin ) {
	$e17_saved = get_option( 'wpcap_enabled_connectors', array() );
	update_option( 'wpcap_enabled_connectors', array( 'e17_other', 'e17_gone' ) );
	$e17_lb = 'e17-learnboating';
	$e17_lb_filter = static function ( $list ) use ( $e17_lb ) { $list[] = new E17_Stub_Connector( $e17_lb ); return $list; };
	add_filter( 'wp_pass_captain_connectors_v1', $e17_lb_filter );
	$e17_audits = static function ( $action ) use ( $wpdb, $p, $e17_lb ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action=%s AND object_type='connector' AND details LIKE %s", $action, '%' . $wpdb->esc_like( $e17_lb ) . '%' ) ); };
	$e17_on_before = $e17_audits( 'connector_enabled' );
	$e17_off_before = $e17_audits( 'connector_disabled' );

	$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
	$e17_forbidden = wpcap_set_connector_enabled( $e17_lb, true );
	$GLOBALS['wpcap_test_denied_caps'] = array();
	check( 'E17-3: without manage_options the opt-in is refused (403) and nothing changes', is_wp_error( $e17_forbidden ) && 'wpcap_connector_forbidden' === $e17_forbidden->get_error_code() && 403 === ( $e17_forbidden->get_error_data()['status'] ?? 0 ) && false === wpcap_is_connector_enabled( $e17_lb ) );
	$e17_unknown = wpcap_set_connector_enabled( 'e17_not_registered', true );
	$e17_invalid = wpcap_set_connector_enabled( 'E17 Connector', true );
	check( 'E17-3: an unregistered connector cannot be enabled (404); an invalid key is refused (400)', is_wp_error( $e17_unknown ) && 'wpcap_connector_unknown' === $e17_unknown->get_error_code() && is_wp_error( $e17_invalid ) && 'wpcap_connector_invalid' === $e17_invalid->get_error_code() && array( 'e17_other', 'e17_gone' ) === get_option( 'wpcap_enabled_connectors' ) );

	$e17_on = wpcap_set_connector_enabled( $e17_lb, true );
	$e17_on_again = wpcap_set_connector_enabled( $e17_lb, true );
	check( 'E17-3: enabling a registered connector adds its key once, keeps the other keys and is audited once (idempotent)', true === $e17_on && true === $e17_on_again && true === wpcap_is_connector_enabled( $e17_lb ) && array( 'e17_other', 'e17_gone', $e17_lb ) === get_option( 'wpcap_enabled_connectors' ) && $e17_on_before + 1 === $e17_audits( 'connector_enabled' ) );
	check( 'E17-3: an enabled connector is picked up by the event delivery', in_array( $e17_lb, PassCaptain\Integrations\ConnectorEvents::enabled_keys(), true ) );

	// Busy lock: another request is switching right now.
	$e17_other_db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
	$e17_lock = PassCaptain\DbLock::name( PassCaptain\Integrations\ConnectorRegistry::LOCK );
	$e17_held = '1' === (string) $e17_other_db->query( "SELECT GET_LOCK('" . $e17_lock . "',0)" )->fetch_row()[0];
	$e17_busy = wpcap_set_connector_enabled( $e17_lb, false );
	$e17_other_db->query( "SELECT RELEASE_LOCK('" . $e17_lock . "')" );
	$e17_other_db->close();
	check( 'E17-3: while another request holds the opt-in lock nothing is written (409 wpcap_connector_busy, no lost update)', $e17_held && is_wp_error( $e17_busy ) && 'wpcap_connector_busy' === $e17_busy->get_error_code() && true === wpcap_is_connector_enabled( $e17_lb ) );

	$e17_off = wpcap_set_connector_enabled( $e17_lb, false );
	check( 'E17-3: disabling removes the key, keeps the others and is audited', true === $e17_off && false === wpcap_is_connector_enabled( $e17_lb ) && array( 'e17_other', 'e17_gone' ) === get_option( 'wpcap_enabled_connectors' ) && $e17_off_before + 1 === $e17_audits( 'connector_disabled' ) && ! in_array( $e17_lb, PassCaptain\Integrations\ConnectorEvents::enabled_keys(), true ) );
	check( 'E17-3: a connector whose plugin is gone (not registered) can still be disabled', true === wpcap_set_connector_enabled( 'e17_gone', false ) && array( 'e17_other' ) === get_option( 'wpcap_enabled_connectors' ) && false === wpcap_is_connector_enabled( 'e17_gone' ) && true === wpcap_is_connector_enabled( 'e17_other' ) && false === wpcap_is_connector_enabled( array( 'e17_other' ) ) );
	remove_filter( 'wp_pass_captain_connectors_v1', $e17_lb_filter );
	update_option( 'wpcap_enabled_connectors', $e17_saved );

	// Only ConnectorRegistry writes the option (the HTTP/JSON connector goes through it as well).
	$e17_writers = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $e17_file ) {
		if ( 'php' === $e17_file->getExtension() && preg_match( "/update_option\(\s*'wpcap_enabled_connectors'/", (string) file_get_contents( $e17_file->getPathname() ) ) ) { $e17_writers[] = $e17_file->getFilename(); }
	}
	check( 'E17-3: no file in src/ writes wpcap_enabled_connectors directly (single writer ConnectorRegistry::store())' . ( $e17_writers ? ': ' . implode( ', ', $e17_writers ) : '' ), array() === $e17_writers && 'wpcap_enabled_connectors' === PassCaptain\Integrations\ConnectorRegistry::OPTION );
	$e17_optin_doc = $e17_section( 'Freigabe eines Connectors (Opt-in)', '###' );
	$e17_missing = array_values( array_filter( array( '`wpcap_set_connector_enabled( $key, $enabled )`', '`wpcap_is_connector_enabled( $key )`', 'Vertragsversion 1.4.0', '`manage_options`', '`wpcap_connector_invalid`', '`wpcap_connector_forbidden`', '`wpcap_connector_unknown`', '`wpcap_connector_busy`', '`connector_enabled`', '`connector_disabled`', 'gehören **nicht** zum Vertrag' ), static fn( $w ) => false === strpos( $e17_optin_doc, $w ) ) );
	check( 'E17-3: docs/EXTENSIONS.md "Freigabe eines Connectors (Opt-in)" documents both functions, rules, errors and that the option is not part of the contract' . ( $e17_missing ? ': ' . implode( ', ', $e17_missing ) : '' ), '' !== $e17_optin_doc && array() === $e17_missing );
}

check( 'E17: WPCAP_CONTRACT_VERSION is at least 1.4.0 and the version table names 1.4.0', version_compare( (string) ( preg_match( "/^define\( 'WPCAP_CONTRACT_VERSION', '([^']+)' \);/m", (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' ), $e17_m ) ? $e17_m[1] : '0' ), '1.4.0', '>=' ) && 1 === preg_match( '/^\| 1\.4\.0 \| 1\.6\.\d+ \|/m', $e17_docs ) );
