<?php
/**
 * Helpers shared by several module tests (Z1): they used to be defined in one module and called from
 * others, so those others failed when run alone (WPCAP_TEST_ONLY). run.php loads this file before the
 * module loop; the defining modules keep their checks and side effects (module registration, hooks).
 * - ex_*: events, occurrences, ticket types and paid purchases (from p3_exchange.php)
 * - adm_*: admission setups and check-ins (from admission.php)
 * - svc_*: service resources, slots and bookings (from zzzz_services_a_rules.php)
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

/* ---- ex_*: p3_exchange.php ---- */
function ex_event( $name = 'Festival' ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}wpcap_events", array( 'name' => $name, 'slug' => 'ex' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	return (int) $wpdb->insert_id;
}
function ex_occ( $event, $capacity = 0, $hours = 168 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $hours * 3600 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => $capacity, 'status' => 'scheduled' ) );
	return (int) $wpdb->insert_id;
}
function ex_type( $occ, $name, $price, $capacity = 0, $rate = 1000 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'tax_rate_bp' => $rate, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
/** Buys and pays $qty tickets; returns [order_id, ticket ids]. */
function ex_buy( $type, $qty = 1, $email = 'ex@example.com' ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$r = \PassCaptain\Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'ex-' . uniqid( '', true ) ) );
	if ( is_wp_error( $r ) ) { return array( 0, array() ); }
	if ( 'paid' !== $r['payment_status'] ) { \PassCaptain\Orders::confirm_external_payment( $r['order_id'], 'test' ); }
	return array( (int) $r['order_id'], array_map( static fn( $t ) => (int) $t->ticket_id, tickets( $r['order_id'], 'valid' ) ) );
}
function ex_ticket( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $id ) );
}
function ex_item( $order_id, $type_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT SUM(quantity) AS quantity,SUM(refunded_quantity) AS refunded,SUM(line_total_minor) AS line_total FROM {$wpdb->prefix}wpcap_order_items WHERE order_id=%d AND ticket_type_id=%d", $order_id, $type_id ) );
}
function ex_mails( $type_word ) {
	return array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( (string) $m['subject'], $type_word ) ) );
}
function ex_policy( array $values ) {
	\PassCaptain\Exchange\Policy::save_global( array_merge( \PassCaptain\Exchange\Policy::defaults(), $values ) );
}

/* ---- adm_*: admission.php ---- */
function adm_ts( $local, $tz ) { return ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp(); }
function adm_now( $ts ) { if ( null === $ts ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] ); } else { $GLOBALS['wpcap_test_filters']['wpcap_checkin_now'] = (int) $ts; } }
/** Event with one occurrence (local times in $tz) and $qty free tickets. */
function adm_setup( $tz = 'Europe/Vienna', $start = null, $end = null, $qty = 1 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$type = make_type( 100, 50, 0 );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$occ" );
	wpcap_test_reset_transients();
	$order = \PassCaptain\Orders::create( array( 'email' => 'adm' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'adm-' . uniqid( '', true ) ) );
	$codes = is_wp_error( $order ) ? array() : ticket_code_for( $order['order_id'] );
	$fields = array( 'timezone' => $tz );
	if ( $start ) { $fields['starts_at'] = gmdate( 'Y-m-d H:i:s', adm_ts( $start, $tz ) ); }
	if ( $end ) { $fields['ends_at'] = gmdate( 'Y-m-d H:i:s', adm_ts( $end, $tz ) ); }
	$wpdb->update( "{$p}wpcap_occurrences", $fields, array( 'occurrence_id' => $occ ) );
	return array( 'type' => $type, 'occ' => $occ, 'event' => $event, 'codes' => $codes );
}
function adm_r( $r ) { return is_wp_error( $r ) ? (string) ( $r->get_error_data()['result'] ?? $r->get_error_code() ) : (string) $r['status']; }
function adm_in( $code, $at = null, array $ctx = array() ) { adm_now( $at ); $r = \PassCaptain\Checkin::perform( $code, $ctx + array( 'idempotency_key' => 'adm-' . uniqid( '', true ) ) ); adm_now( null ); return adm_r( $r ); }
function adm_out( $code, $at = null, array $ctx = array() ) { adm_now( $at ); $r = \PassCaptain\Checkin::check_out( $code, $ctx + array( 'idempotency_key' => 'adm-' . uniqid( '', true ) ) ); adm_now( null ); return adm_r( $r ); }
function adm_ticket_status( $code ) { global $wpdb; return $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE token_hash='" . hash( 'sha256', $code ) . "'" ); }

/* ---- svc_*: zzzz_services_a_rules.php ---- */
/** Event with one ticket type (price in minor units); returns [event_id, ticket_type_id]. */
function svc_event( $price = 0, $capacity = 500 ) {
	global $wpdb;
	$type = make_type( $capacity, 20, $price );
	$event = (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
	// Service slots may lie after the occurrence used as the price carrier.
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_occurrences SET starts_at='" . gmdate( 'Y-m-d H:i:s', time() + 400 * 86400 ) . "' WHERE event_id=$event" );
	return array( $event, $type );
}
/** Resource with rules; returns its id. */
function svc_resource( $event_id, array $data = array() ) {
	$id = \PassCaptain\ServiceResources::save_resource( array_merge( array( 'event_id' => $event_id, 'name' => 'Boot ' . uniqid(), 'capacity' => 2, 'timezone' => 'Europe/Vienna' ), $data ) );
	return is_wp_error( $id ) ? 0 : (int) $id;
}
/** Slot starting $hours from now (UTC, whole minutes); returns slot id or WP_Error. */
/** Slot at a fixed local time (resource timezone Europe/Vienna) $days from today: independent of the time of day the suite runs. */
function svc_slot_at( $resource_id, $days, $local_time, $minutes = 60, $capacity = 0 ) {
	$tz = new DateTimeZone( 'Europe/Vienna' );
	$local = new DateTimeImmutable( 'today ' . $local_time, $tz );
	$start = $local->modify( '+' . (int) $days . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) );
	return \PassCaptain\ServiceResources::create_slot( $resource_id, $start->format( 'Y-m-d H:i:s' ), $start->modify( '+' . (int) $minutes . ' minutes' )->format( 'Y-m-d H:i:s' ), $capacity );
}
function svc_slot( $resource_id, $hours, $minutes = 60, $capacity = 0 ) {
	$start = (int) ( floor( ( time() + (int) round( $hours * 3600 ) ) / 60 ) * 60 );
	return \PassCaptain\ServiceResources::create_slot( $resource_id, gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $start + $minutes * 60 ), $capacity );
}
function svc_reset_limits() { wpcap_test_reset_transients(); }
function svc_book( $slot_id, $email, $qty = 1, array $args = array(), $order_id = 0 ) {
	svc_reset_limits();
	return \PassCaptain\ServiceBookings::reserve( $slot_id, $email, $qty, $order_id, 10, 'svc-' . uniqid( '', true ), $args );
}
function svc_status( $booking_id ) { return (string) \PassCaptain\ServiceBookings::get( $booking_id )->status; }
function svc_audit( $action, $object_id = 0 ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s" . ( $object_id ? ' AND object_id=' . (int) $object_id : '' ) . ' ORDER BY audit_id DESC LIMIT 1', $action ) );
}
function svc_mails_to( $email, $from = 0 ) { return array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $from ), static fn( $m ) => $m['to'] === $email ) ); }
function svc_free( $slot_id ) { $slot = \PassCaptain\ServiceResources::slot( $slot_id ); return (int) $slot->capacity - \PassCaptain\ServiceResources::booked_quantity( $slot_id ) - \PassCaptain\ServiceWaitlist::held_quantity( $slot_id ); }
