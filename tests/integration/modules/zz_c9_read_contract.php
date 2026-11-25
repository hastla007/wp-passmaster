<?php
/**
 * C9: additive read contract 1.5.0 for connectors (wish of the LearnBoating ticket connector).
 *
 * - opt-in occurrence location (include_location) and holder-changed flag (include_holder_changes) in
 *   wpcap_get_ticket_details() / wpcap_get_order_ticket_details(); without them the 1.4.0 arrays, unchanged;
 * - wpcap_get_ticket_ids(): public IDs per occurrence, event or ticket type, default valid/checked-in and not
 *   refunded, keyset pagination, null for invalid arguments;
 * - wpcap_get_event_catalog(): events with ticket types, also without future occurrences, no personal data;
 * - contract version 1.5.0, PublicApi::FUNCTIONS and docs/EXTENSIONS.md.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Api\ReadFacade;
use PassCaptain\Api\TicketDetails;
use PassCaptain\Orders;

require_once WPCAP_PATH . 'src/functions.php';

echo "C9: read contract 1.5.0 for connectors\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
wpcap_test_reset_transients();
PassCaptain\Settings::update( array( 'require_terms' => 0 ) );

/* ---- Contract version, public functions, documentation ---- */

$c9_main = (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' );
$c9_contract = preg_match( "/^define\( 'WPCAP_CONTRACT_VERSION', '([^']+)' \);/m", $c9_main, $c9_m ) ? $c9_m[1] : '0';
$c9_docs = (string) file_get_contents( WPCAP_PATH . 'docs/EXTENSIONS.md' );
check( 'C9: WPCAP_CONTRACT_VERSION is at least 1.5.0 and the version table has a 1.5.0 row naming the new functions', version_compare( $c9_contract, '1.5.0', '>=' ) && 1 === preg_match( '/^\| 1\.5\.0 \| \d+\.\d+\.\d+ \|[^\n]*`wpcap_get_ticket_ids\(\)`[^\n]*`wpcap_get_event_catalog\(\)`/m', $c9_docs ) );
check( 'C9: PublicApi::FUNCTIONS lists wpcap_get_ticket_ids and wpcap_get_event_catalog with contract 1.5.0, the 1.4.0 functions keep 1.4.0', '1.5.0' === ( PassCaptain\PublicApi::FUNCTIONS['wpcap_get_ticket_ids'] ?? '' ) && '1.5.0' === ( PassCaptain\PublicApi::FUNCTIONS['wpcap_get_event_catalog'] ?? '' ) && '1.4.0' === PassCaptain\PublicApi::FUNCTIONS['wpcap_get_ticket_details'] && function_exists( 'wpcap_get_ticket_ids' ) && function_exists( 'wpcap_get_event_catalog' ) );
check( 'C9: the 1.4.0 contract constants are unchanged (VERSION 1, key lists)', 1 === TicketDetails::VERSION && array( 'version', 'ticket', 'occurrence', 'event', 'holder', 'checkins' ) === TicketDetails::KEYS && array( 'user_id', 'is_buyer', 'email_included', 'email' ) === TicketDetails::HOLDER_KEYS && array( 'cancelled', 'rescheduled' ) === TicketDetails::OCCURRENCE_EXTRA_KEYS );
$c9_start = strpos( $c9_docs, "\n### Erweiterungen in Vertrag 1.5.0\n" );
$c9_end = false === $c9_start ? false : strpos( $c9_docs, "\n## ", $c9_start + 5 );
$c9_doc = false === $c9_start ? '' : substr( $c9_docs, $c9_start, ( false === $c9_end ? strlen( $c9_docs ) : $c9_end ) - $c9_start );
$c9_names = array_unique( array_merge( TicketDetails::LOCATION_KEYS, TicketDetails::HOLDER_CHANGE_KEYS, TicketDetails::TICKET_IDS_KEYS, TicketDetails::TICKET_ID_SCOPES, TicketDetails::CATALOG_KEYS, TicketDetails::CATALOG_EVENT_KEYS, TicketDetails::CATALOG_TYPE_KEYS, array( 'include_location', 'include_holder_changes', 'statuses', 'limit', 'after', 'wpcap_read_holder_email' ) ) );
$c9_undoc = array_values( array_filter( $c9_names, static fn( $k ) => false === strpos( $c9_doc, '`' . $k . '`' ) ) );
check( 'C9: docs/EXTENSIONS.md "Erweiterungen in Vertrag 1.5.0" names every new key and argument' . ( $c9_undoc ? ': ' . implode( ', ', $c9_undoc ) : '' ), '' !== $c9_doc && array() === $c9_undoc && false !== strpos( $c9_docs, '`wpcap_get_ticket_ids( $args = [] )`' ) && false !== strpos( $c9_docs, '`wpcap_get_event_catalog( $args = [] )`' ) );
check( 'C9: the LearnBoating section names the new functions', 1 === preg_match( '/## Connectoren BoatingSchool und LearnBoating.*?`wpcap_get_ticket_ids\(\)`.*?`wpcap_get_event_catalog\(\)`.*?\n## /s', $c9_docs ) );
check( 'C9: docs/HOOKS.md names the new functions', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/HOOKS.md' ), '`wpcap_get_ticket_ids()`' ) );

/* ---- Fixture: event with two dates, two ticket types, tickets in several states ---- */

$c9_type = make_type( 50, 10, 1500 );
$c9_row = $wpdb->get_row( "SELECT tt.occurrence_id, o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$c9_type" );
$c9_occ = (int) $c9_row->occurrence_id;
$c9_event = (int) $c9_row->event_id;
$wpdb->query( "UPDATE {$p}wpcap_events SET name='C9 Segelkurs', description='C9MarkerDescription' WHERE event_id=$c9_event" );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET location='Hafen Kiel, Steg 3', venue_country='de' WHERE occurrence_id=$c9_occ" );
$wpdb->query( "UPDATE {$p}wpcap_ticket_types SET staff_note='C9MarkerStaffNote', access_codes='C9MARKERCODE' WHERE ticket_type_id=$c9_type" );
// Second date in the past with its own ticket type (no future occurrence for that type).
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $c9_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ), 'timezone' => 'Europe/Berlin', 'location' => '', 'capacity' => 0, 'status' => 'scheduled' ) );
$c9_occ_past = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $c9_occ_past, 'name' => 'C9 Vergangen', 'price_minor' => 900, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
$c9_type_past = (int) $wpdb->insert_id;

$c9_order = Orders::create( array( 'email' => 'c9-buyer@example.com', 'name' => 'C9MarkerBuyerName', 'items' => array( array( 'ticket_type_id' => $c9_type, 'quantity' => 5 ) ), 'idempotency_key' => 'c9-' . uniqid() ) );
check( 'C9 fixture: order created', ! is_wp_error( $c9_order ) && ! empty( $c9_order['order_id'] ) );
$c9_oid = (int) $c9_order['order_id'];
Orders::confirm_manual_payment( $c9_oid );
$c9_tickets = $wpdb->get_results( "SELECT ticket_id, public_id FROM {$p}wpcap_tickets WHERE order_id=$c9_oid ORDER BY ticket_id" );
check( 'C9 fixture: five tickets issued', 5 === count( $c9_tickets ) );
$c9_t = array_map( static fn( $r ) => (int) $r->ticket_id, $c9_tickets );
$c9_pub = array_map( static fn( $r ) => (string) $r->public_id, $c9_tickets );
// t0 valid, t1 checked in, t2 revoked, t3 valid but refunded, t4 valid with a holder change.
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id={$c9_t[1]}" );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id={$c9_t[2]}" );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE ticket_id={$c9_t[3]}" );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='c9-new-holder@example.com', holder_changes=1 WHERE ticket_id={$c9_t[4]}" );
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'ticket_holder_changed', 'object_type' => 'ticket', 'object_id' => $c9_t[4], 'details' => wp_json_encode( array( 'old_email' => 'c9-old-holder@example.com' ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );

/* ---- Part 1 and 4: opt-in location and holder change flag ---- */

$c9_occ_keys = array_merge( ReadFacade::OCCURRENCE_KEYS, TicketDetails::OCCURRENCE_EXTRA_KEYS );
$c9_plain = wpcap_get_ticket_details( $c9_t[0] );
check( 'C9-1: without the new arguments the ticket details are exactly the 1.4.0 arrays (no location, no change flag)', is_array( $c9_plain ) && TicketDetails::KEYS === array_keys( $c9_plain ) && $c9_occ_keys === array_keys( (array) $c9_plain['occurrence'] ) && TicketDetails::HOLDER_KEYS === array_keys( $c9_plain['holder'] ) && false === strpos( strtolower( (string) wp_json_encode( $c9_plain ) ), 'hafen kiel' ) );
$wpdb->wpcap_log = array();
$c9_loc = wpcap_get_ticket_details( $c9_pub[0], array( 'include_location' => true, 'include_holder_changes' => true ) );
$c9_log = $wpdb->wpcap_log;
$wpdb->wpcap_log = null;
check( 'C9-1: include_location appends location and venue_country to the occurrence (facade keys first, unchanged)', is_array( $c9_loc ) && array_merge( $c9_occ_keys, TicketDetails::LOCATION_KEYS ) === array_keys( $c9_loc['occurrence'] ) && 'Hafen Kiel, Steg 3' === $c9_loc['occurrence']['location'] && 'DE' === $c9_loc['occurrence']['venue_country'] && array_intersect_key( $c9_loc['occurrence'], array_flip( ReadFacade::OCCURRENCE_KEYS ) ) === wpcap_get_occurrence_state( $c9_occ ) );
check( 'C9-4: include_holder_changes appends holder_changed=false / holder_change_count=0 for an unchanged ticket', array_merge( TicketDetails::HOLDER_KEYS, TicketDetails::HOLDER_CHANGE_KEYS ) === array_keys( $c9_loc['holder'] ) && false === $c9_loc['holder']['holder_changed'] && 0 === $c9_loc['holder']['holder_change_count'] );
check( 'C9-1/4: the extended read only issues SELECT statements', count( (array) $c9_log ) > 0 && array() === array_filter( (array) $c9_log, static fn( $sql ) => 1 !== preg_match( '/^\s*SELECT\s/i', $sql ) ) );
$c9_changed = wpcap_get_ticket_details( $c9_t[4], array( 'include_holder_changes' => true ) );
$c9_changed_blob = strtolower( (string) wp_json_encode( $c9_changed ) );
check( 'C9-4: a ticket whose holder was changed reports holder_changed=true with the count, no previous or current holder address', true === $c9_changed['holder']['holder_changed'] && 1 === $c9_changed['holder']['holder_change_count'] && false === strpos( $c9_changed_blob, '@' ) && false === strpos( $c9_changed_blob, 'old-holder' ) && ! array_key_exists( 'location', (array) $c9_changed['occurrence'] ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET transfer_count=1 WHERE ticket_id={$c9_t[0]}" );
$c9_transferred = wpcap_get_ticket_details( $c9_t[0], array( 'include_holder_changes' => true ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET transfer_count=0 WHERE ticket_id={$c9_t[0]}" );
check( 'C9-4: a transfer counts as a holder change', true === $c9_transferred['holder']['holder_changed'] && 1 === $c9_transferred['holder']['holder_change_count'] );
$c9_order_details = wpcap_get_order_ticket_details( $c9_oid, array( 'include_location' => true ) );
check( 'C9-1: the order read passes include_location to every ticket', is_array( $c9_order_details ) && 5 === count( $c9_order_details['tickets'] ) && array() === array_filter( $c9_order_details['tickets'], static fn( $d ) => 'Hafen Kiel, Steg 3' !== ( $d['occurrence']['location'] ?? '' ) ) );
check( 'C9-1: the e-mail rule is unchanged with the new arguments (no capability = no address)', null === $c9_loc['holder']['email'] && false === $c9_loc['holder']['email_included'] );

/* ---- Part 2: ticket IDs per occurrence, event, ticket type ---- */

$c9_ids = wpcap_get_ticket_ids( array( 'occurrence_id' => $c9_occ ) );
check( 'C9-2: ticket IDs of an occurrence: contract keys, public IDs of valid/checked-in, not refunded tickets, ordered', is_array( $c9_ids ) && TicketDetails::TICKET_IDS_KEYS === array_keys( $c9_ids ) && 1 === $c9_ids['version'] && 'occurrence_id' === $c9_ids['scope'] && $c9_occ === $c9_ids['scope_id'] && array( 'valid', 'checked_in' ) === $c9_ids['statuses'] && array( $c9_pub[0], $c9_pub[1], $c9_pub[4] ) === $c9_ids['ticket_ids'] && null === $c9_ids['next_after'] );
$c9_by_event = wpcap_get_ticket_ids( array( 'event_id' => (string) $c9_event ) );
$c9_by_type = wpcap_get_ticket_ids( array( 'ticket_type_id' => $c9_type ) );
check( 'C9-2: the same tickets per event (digit string accepted) and per ticket type', is_array( $c9_by_event ) && is_array( $c9_by_type ) && $c9_ids['ticket_ids'] === $c9_by_event['ticket_ids'] && $c9_ids['ticket_ids'] === $c9_by_type['ticket_ids'] && 'event_id' === $c9_by_event['scope'] && 'ticket_type_id' === $c9_by_type['scope'] );
$c9_any = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'statuses' => 'any' ) );
$c9_revoked = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'statuses' => array( 'revoked' ) ) );
$c9_valid = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'statuses' => array( 'valid', 'valid' ) ) );
check( "C9-2: statuses 'any' lists all tickets, an explicit list filters by status only (refunded included)", $c9_pub === $c9_any['ticket_ids'] && array() === $c9_any['statuses'] && array( $c9_pub[2] ) === $c9_revoked['ticket_ids'] && array( $c9_pub[0], $c9_pub[3], $c9_pub[4] ) === $c9_valid['ticket_ids'] && array( 'valid' ) === $c9_valid['statuses'] );
$c9_page1 = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'statuses' => 'any', 'limit' => 2 ) );
$c9_page2 = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'statuses' => 'any', 'limit' => 2, 'after' => $c9_page1['next_after'] ) );
$c9_page3 = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'statuses' => 'any', 'limit' => 2, 'after' => $c9_page2['next_after'] ) );
check( 'C9-2: keyset pagination: limit 2 gives pages 2+2+1, next_after null on the last page', array_slice( $c9_pub, 0, 2 ) === $c9_page1['ticket_ids'] && $c9_t[1] === $c9_page1['next_after'] && array_slice( $c9_pub, 2, 2 ) === $c9_page2['ticket_ids'] && array( $c9_pub[4] ) === $c9_page3['ticket_ids'] && null === $c9_page3['next_after'] );
$c9_capped = wpcap_get_ticket_ids( array( 'event_id' => $c9_event, 'limit' => 999999 ) );
$c9_empty = wpcap_get_ticket_ids( array( 'ticket_type_id' => $c9_type_past ) );
check( 'C9-2: an oversized limit is capped, a ticket type without tickets gives an empty list', is_array( $c9_capped ) && 3 === count( $c9_capped['ticket_ids'] ) && TicketDetails::MAX_IDS >= 1000 && is_array( $c9_empty ) && array() === $c9_empty['ticket_ids'] && null === $c9_empty['next_after'] );
$c9_invalid = array(
	'no scope' => array(),
	'two scopes' => array( 'event_id' => $c9_event, 'occurrence_id' => $c9_occ ),
	'unknown event' => array( 'event_id' => 999999999 ),
	'negative id' => array( 'event_id' => -1 ),
	'bad status' => array( 'event_id' => $c9_event, 'statuses' => array( "valid' OR 1=1" ) ),
	'empty status list' => array( 'event_id' => $c9_event, 'statuses' => array() ),
	'limit 0' => array( 'event_id' => $c9_event, 'limit' => 0 ),
	'bad after' => array( 'event_id' => $c9_event, 'after' => 'x' ),
);
$c9_not_null = array_keys( array_filter( $c9_invalid, static fn( $args ) => null !== wpcap_get_ticket_ids( $args ) ) );
check( 'C9-2: invalid arguments or an unknown record give null' . ( $c9_not_null ? ': ' . implode( ', ', $c9_not_null ) : '' ), array() === $c9_not_null );
$c9_ids_blob = strtolower( (string) wp_json_encode( array( $c9_ids, $c9_any ) ) );
check( 'C9-2: the ID list holds only public IDs (32 hex), no e-mail, no names', array() === array_filter( $c9_any['ticket_ids'], static fn( $id ) => 1 !== preg_match( '/^[0-9a-f]{32}$/', $id ) ) && false === strpos( $c9_ids_blob, '@' ) && false === strpos( $c9_ids_blob, 'c9markerbuyername' ) );

/* ---- Part 3: event catalogue ---- */

// A second event with only a past date: listed although nothing lies ahead.
$c9_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'C9 Archiv', 'slug' => 'c9-' . uniqid(), 'description' => 'C9MarkerDescription', 'status' => 'draft', 'created_at' => $c9_now, 'updated_at' => $c9_now ) );
$c9_event_old = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $c9_event_old, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * 86400 ), 'timezone' => 'Europe/Berlin', 'location' => 'Alt', 'capacity' => 0, 'status' => 'scheduled' ) );
$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => (int) $wpdb->insert_id, 'name' => 'C9 Alt', 'price_minor' => 4200, 'currency' => 'chf', 'capacity' => 5, 'max_per_order' => 5, 'status' => 'inactive' ) );
$c9_type_old = (int) $wpdb->insert_id;

$c9_find = static function ( $catalog, $event_id ) {
	foreach ( (array) ( $catalog['events'] ?? array() ) as $event ) {
		if ( $event_id === $event['event_id'] ) { return $event; }
	}
	return null;
};
$c9_after = $c9_event - 1;
$wpdb->wpcap_log = array();
$c9_cat = wpcap_get_event_catalog( array( 'after' => $c9_after, 'limit' => 200 ) );
$c9_cat_log = $wpdb->wpcap_log;
$wpdb->wpcap_log = null;
$c9_e = $c9_find( $c9_cat, $c9_event );
$c9_o = $c9_find( $c9_cat, $c9_event_old );
check( 'C9-3: catalogue has the contract keys (result, event, ticket type)', is_array( $c9_cat ) && TicketDetails::CATALOG_KEYS === array_keys( $c9_cat ) && 1 === $c9_cat['version'] && is_array( $c9_e ) && TicketDetails::CATALOG_EVENT_KEYS === array_keys( $c9_e ) && isset( $c9_e['ticket_types'][0] ) && TicketDetails::CATALOG_TYPE_KEYS === array_keys( $c9_e['ticket_types'][0] ) );
check( 'C9-3: event with name, status, occurrence count, next start and both ticket types (also the one of the past date)', 'C9 Segelkurs' === $c9_e['name'] && 'published' === $c9_e['status'] && false === $c9_e['cancelled'] && 2 === $c9_e['occurrence_count'] && 1 === preg_match( '/Z$/', (string) $c9_e['next_starts_at'] ) && array( $c9_type, $c9_type_past ) === array_column( $c9_e['ticket_types'], 'ticket_type_id' ) && array( 'Standard', 'C9 Vergangen' ) === array_column( $c9_e['ticket_types'], 'name' ) && 1500 === $c9_e['ticket_types'][0]['price_minor'] && 'EUR' === $c9_e['ticket_types'][0]['currency'] && $c9_occ_past === $c9_e['ticket_types'][1]['occurrence_id'] && 'ticket' === $c9_e['ticket_types'][0]['kind'] );
check( 'C9-3: an event without future occurrences is listed with next_starts_at null and its inactive ticket type', is_array( $c9_o ) && 'C9 Archiv' === $c9_o['name'] && 'draft' === $c9_o['status'] && null === $c9_o['next_starts_at'] && 1 === $c9_o['occurrence_count'] && array( $c9_type_old ) === array_column( $c9_o['ticket_types'], 'ticket_type_id' ) && 'inactive' === $c9_o['ticket_types'][0]['status'] && 'CHF' === $c9_o['ticket_types'][0]['currency'] && 4200 === $c9_o['ticket_types'][0]['price_minor'] );
$c9_cat_blob = strtolower( (string) wp_json_encode( $c9_cat ) );
$c9_cat_leaks = array_values( array_filter( array( '@', 'c9markerdescription', 'c9markerstaffnote', 'c9markercode', 'hafen kiel', 'c9markerbuyername', 'wpcap_' ), static fn( $m ) => false !== strpos( $c9_cat_blob, $m ) ) );
check( 'C9-3: no e-mail, description, staff note, access code, location, buyer or table name in the catalogue' . ( $c9_cat_leaks ? ': ' . implode( ', ', $c9_cat_leaks ) : '' ), array() === $c9_cat_leaks );
check( 'C9-3: the catalogue read only issues SELECT statements', count( (array) $c9_cat_log ) > 0 && array() === array_filter( (array) $c9_cat_log, static fn( $sql ) => 1 !== preg_match( '/^\s*SELECT\s/i', $sql ) ) );
$c9_drafts = wpcap_get_event_catalog( array( 'after' => $c9_after, 'statuses' => array( 'draft' ) ) );
check( 'C9-3: the statuses filter keeps only matching events', is_array( $c9_drafts ) && null === $c9_find( $c9_drafts, $c9_event ) && is_array( $c9_find( $c9_drafts, $c9_event_old ) ) && array() === array_filter( $c9_drafts['events'], static fn( $e ) => 'draft' !== $e['status'] ) );
$c9_cp1 = wpcap_get_event_catalog( array( 'after' => $c9_after, 'limit' => 1 ) );
$c9_cp2 = wpcap_get_event_catalog( array( 'after' => $c9_cp1['next_after'], 'limit' => 1 ) );
check( 'C9-3: keyset pagination over events', 1 === count( $c9_cp1['events'] ) && $c9_event === $c9_cp1['events'][0]['event_id'] && $c9_event === $c9_cp1['next_after'] && isset( $c9_cp2['events'][0] ) && $c9_cp2['events'][0]['event_id'] > $c9_event );
$c9_cat_invalid = array_keys( array_filter( array(
	'limit 0' => array( 'limit' => 0 ),
	'negative after' => array( 'after' => -5 ),
	'bad status' => array( 'statuses' => array( 'Published!' ) ),
	'status string' => array( 'statuses' => 'draft' ),
), static fn( $args ) => null !== wpcap_get_event_catalog( $args ) ) );
check( 'C9-3: invalid catalogue arguments give null' . ( $c9_cat_invalid ? ': ' . implode( ', ', $c9_cat_invalid ) : '' ), array() === $c9_cat_invalid );
