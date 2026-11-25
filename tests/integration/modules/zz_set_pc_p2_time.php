<?php
/**
 * SET-PC-P2 (b): time zones and deadlines from start to end. Europe/Vienna (CET/CEST), Europe/Lisbon
 * (WET/WEST) and UTC, the daylight-saving switch (2026-03-29 and 2026-10-25, both at 01:00 UTC) and
 * deadlines around midnight and the turn of the year:
 *   - attendee_edit_hours (order portal assignment, ticket page form and save),
 *   - cancellation requests in the order portal (portal_refund_request_hours, end to end),
 *   - the event's refund rule (Sales\SelfService) across New Year's Eve,
 *   - the refund request after a postponement (reschedule_refund_hours) and its deadline text,
 *   - reminders (send time, stage, rendered local date/time),
 *   - holds (bank transfer cutoff and hold capped at the start, 72 h hold across the switch).
 * The current time is injected through PassCaptain\Clock (filter `wpcap_now`). Fixed past dates are used
 * (real "now" is later), so expiry runs at a simulated time never touch other tests' holds. Everything
 * runs twice, with PHP default time zones UTC and Asia/Kathmandu (+05:45): storage and arithmetic are UTC,
 * so the results must not change.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\Format;
use PassCaptain\Modules\Reminders;
use PassCaptain\Orders;
use PassCaptain\PortalSelfService;
use PassCaptain\RescheduleRefunds;
use PassCaptain\Sales\SelfService;
use PassCaptain\Settings;
use PassCaptain\TicketAssignments;
use PassCaptain\TicketPage;

if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'n'; } }
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return 'n' === $n ? 1 : false; } }

global $wpdb;
$p = $wpdb->prefix;
$p2t_saved = array_intersect_key( Settings::all(), array_flip( array( 'attendee_edit_hours', 'portal_refund_request_hours', 'reschedule_refund_request', 'reschedule_refund_hours', 'manual_hold_hours', 'manual_cutoff_hours', 'manual_hold_max_percent', 'reminders', 'refund_approval_threshold', 'auto_invoice' ) ) );
$p2t_saved_tz = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$p2t_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna'; // Site time zone.
$GLOBALS['wpcap_test_user_id'] = 1;
Settings::update( array( 'attendee_edit_hours' => 24, 'portal_refund_request_hours' => 48, 'reschedule_refund_request' => 1, 'reschedule_refund_hours' => 24, 'manual_hold_hours' => 72, 'manual_cutoff_hours' => 24, 'manual_hold_max_percent' => 0, 'refund_approval_threshold' => 0, 'auto_invoice' => 0 ) );
Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 24, 1 ), 'subject' => 'P2T Erinnerung {event}', 'body' => '{event} am {date} um {time} ({timezone}), noch {hours} h', 'sms' => 0, 'sms_body' => '' ) ) );
Reminders::register();

/** Injects the current time (UTC "Y-m-d H:i:s" or timestamp) or restores the real clock (null). */
function p2t_at( $utc ) {
	if ( null === $utc ) { unset( $GLOBALS['wpcap_test_filters']['wpcap_now'] ); return; }
	$GLOBALS['wpcap_test_filters']['wpcap_now'] = is_int( $utc ) ? $utc : strtotime( $utc . ' UTC' );
	wpcap_test_reset_transients(); // Rate limits are not under test here.
}
/** UTC "Y-m-d H:i:s" of a local wall time in $tz. */
function p2t_utc( $local, $tz ) { return gmdate( 'Y-m-d H:i:s', ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp() ); }
/** Local "Y-m-d H:i T" of a UTC datetime or timestamp. */
function p2t_local( $utc, $tz ) { return ( new DateTimeImmutable( '@' . ( is_int( $utc ) ? $utc : strtotime( $utc . ' UTC' ) ) ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'Y-m-d H:i T' ); }
function p2t_ts( $utc ) { return strtotime( $utc . ' UTC' ); }
/** Event + occurrence (UTC start, zone) + ticket type; returns [event, occurrence, type]. */
function p2t_offer( $starts_utc, $tz, $price = 0, array $event_extra = array() ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array_merge( array( 'name' => 'P2T Konzert', 'slug' => 'p2t-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ), $event_extra ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => $starts_utc, 'timezone' => $tz, 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => 50, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( $event, $occ, (int) $wpdb->insert_id );
}
/** Buys (and pays) $qty tickets at the injected time; returns [order_id, public_id, access token, tickets]. */
function p2t_buy( $type, $email, $qty = 1, array $internal = array() ) {
	global $wpdb;
	$r = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'p2t-' . uniqid( '', true ) ), $internal + array( 'skip_rate_limit' => true ) );
	if ( is_wp_error( $r ) ) { return array( 0, '', '', array(), $r ); }
	if ( empty( $internal['payment_provider'] ) && 'paid' !== $r['payment_status'] ) { Orders::confirm_external_payment( $r['order_id'], 'test' ); }
	$token = bin2hex( random_bytes( 32 ) );
	$wpdb->update( "{$wpdb->prefix}wpcap_orders", array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => '2099-01-01 00:00:00' ), array( 'order_id' => (int) $r['order_id'] ) );
	return array( (int) $r['order_id'], (string) Orders::get( $r['order_id'] )->public_id, $token, tickets( $r['order_id'] ), $r );
}
function p2t_err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }
function p2t_reminders( $occ ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT payload,created_at FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s ORDER BY message_id", '%"occurrence_id":' . (int) $occ . ',%' ) );
}

$p2t_zones = array( 'Europe/Vienna', 'Europe/Lisbon', 'UTC' );
foreach ( array( 'UTC', 'Asia/Kathmandu' ) as $p2t_php ) {
	date_default_timezone_set( $p2t_php );
	$p2t_n = 0;

	echo "SET-PC-P2 time [PHP $p2t_php]: attendee_edit_hours (24 h) before a start at 00:30 the day after spring forward\n";
	// Start 2026-03-30 00:30 local. 24 elapsed hours earlier is 23:30 on the 28th in Vienna and Lisbon
	// (the 29th has 23 hours), 00:30 on the 29th in UTC.
	$p2t_expect = array( 'Europe/Vienna' => array( '2026-03-29 22:30:00', '2026-03-28 23:30 CET' ), 'Europe/Lisbon' => array( '2026-03-29 23:30:00', '2026-03-28 23:30 WET' ), 'UTC' => array( '2026-03-30 00:30:00', '2026-03-29 00:30 UTC' ) );
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		$start = p2t_utc( '2026-03-30 00:30', $tz );
		$deadline = p2t_ts( $start ) - 24 * HOUR_IN_SECONDS;
		list( , , $type ) = p2t_offer( $start, $tz );
		p2t_at( '2026-03-20 10:00:00' );
		list( $oid, $pub, $tok, $tix ) = p2t_buy( $type, 'p2t-edit-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com', 2 );
		$codes = $oid ? ticket_code_for( $oid ) : array();
		check( "$tag fixture: start and deadline in UTC/local as expected", $p2t_expect[ $tz ][0] === $start && $p2t_expect[ $tz ][1] === p2t_local( $deadline, $tz ) && $oid && 2 === count( $tix ) && 2 === count( $codes ) );
		$row = static function ( $t ) use ( $type, $start ) { return (object) array( 'ticket_id' => (int) $t->ticket_id, 'ticket_type_id' => $type, 'status' => 'valid', 'starts_at' => $start ); };
		$by_code = array();
		foreach ( $codes as $c ) { $by_code[ (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $c ) ) ) ] = $c; }
		p2t_at( $deadline - 1 );
		$form = TicketPage::attendee_html( $row( $tix[0] ) );
		$assign = TicketAssignments::assign( $pub, $tok, $tix[0]->public_id, 'Anna Vorher', '' );
		$holder = TicketAssignments::update_by_holder( $by_code[ (int) $tix[1]->ticket_id ] ?? '', 'Berta Vorher' );
		check( "$tag one second before the deadline: ticket page form open, portal assignment and holder change accepted", false !== strpos( $form, 'name="attendee_name"' ) && 'ok' === p2t_err( $assign ) && 'ok' === p2t_err( $holder ) );
		p2t_at( $deadline );
		$assign = TicketAssignments::assign( $pub, $tok, $tix[0]->public_id, 'Anna Danach', '' );
		$holder = TicketAssignments::update_by_holder( $by_code[ (int) $tix[1]->ticket_id ] ?? '', 'Berta Danach' );
		check( "$tag at the deadline: no form, assignment and holder change refused", '' === TicketPage::attendee_html( $row( $tix[0] ) ) && 'wpcap_assignment_not_allowed' === p2t_err( $assign ) && 'wpcap_assignment_not_allowed' === p2t_err( $holder ) );
		if ( 'UTC' !== $tz ) {
			// A wall-clock computation ("00:30 local the day before") would still allow changes at 00:00 local on the 29th.
			p2t_at( p2t_utc( '2026-03-29 00:00', $tz ) );
			check( "$tag closed at 00:00 local on the 29th (24 real hours, not the wall clock)", 'wpcap_assignment_not_allowed' === p2t_err( TicketAssignments::assign( $pub, $tok, $tix[0]->public_id, 'Anna Mitternacht', '' ) ) );
		}
		check( "$tag the names saved before the deadline are kept", 'Anna Vorher' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", (int) $tix[0]->ticket_id ) ) && 'Berta Vorher' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", (int) $tix[1]->ticket_id ) ) );
	}
	// attendee_edit_hours = 0: changes until the start itself (midnight).
	Settings::update( array( 'attendee_edit_hours' => 0 ) );
	$start = p2t_utc( '2026-01-01 00:00', 'Europe/Lisbon' );
	list( , , $type ) = p2t_offer( $start, 'Europe/Lisbon' );
	p2t_at( '2025-12-20 10:00:00' );
	list( $oid, $pub, $tok, $tix ) = p2t_buy( $type, 'p2t-edit0-' . sanitize_key( $p2t_php ) . '@example.com' );
	p2t_at( p2t_ts( $start ) - 1 );
	$before = TicketAssignments::assign( $pub, $tok, $tix[0]->public_id, 'Silvester Gast', '' );
	p2t_at( $start );
	$after = TicketAssignments::assign( $pub, $tok, $tix[0]->public_id, 'Neujahr Gast', '' );
	check( "[$p2t_php] attendee_edit_hours 0: open until 23:59:59 on New Year's Eve, closed at midnight (start)", 'ok' === p2t_err( $before ) && 'wpcap_assignment_not_allowed' === p2t_err( $after ) );
	Settings::update( array( 'attendee_edit_hours' => 24 ) );

	echo "SET-PC-P2 time [PHP $p2t_php]: cancellation requests in the order portal (48 h) before 00:30 the day after fall back\n";
	// Start 2026-10-26 00:30 local; 48 real hours earlier is 01:30 summer time on the 24th in Vienna and Lisbon.
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		$start = p2t_utc( '2026-10-26 00:30', $tz );
		$deadline = p2t_ts( $start ) - 48 * HOUR_IN_SECONDS;
		list( , , $type ) = p2t_offer( $start, $tz, 2500 );
		p2t_at( '2026-10-01 10:00:00' );
		list( $oid, $pub, $tok, $tix ) = p2t_buy( $type, 'p2t-refund-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com', 2 );
		check( "$tag deadline is " . ( 'UTC' === $tz ? '00:30 UTC' : '01:30 summer time' ) . ' on the 24th', ( 'UTC' === $tz ? '2026-10-24 00:30 UTC' : ( 'Europe/Vienna' === $tz ? '2026-10-24 01:30 CEST' : '2026-10-24 01:30 WEST' ) ) === p2t_local( $deadline, $tz ) && $oid && 'paid' === Orders::get( $oid )->payment_status );
		$order = Orders::get( $oid );
		$portal_tickets = PortalSelfService::tickets( $order );
		p2t_at( $deadline - 1 );
		$open = PortalSelfService::refund_ticket_block( $portal_tickets[0], $order, array() );
		$req = PortalSelfService::request_refund( $pub, $tok, array( 'ticket_public_ids' => array( $tix[0]->public_id ), 'idempotency_key' => 'p2t-ref-a-' . bin2hex( random_bytes( 8 ) ) ) );
		check( "$tag one second before: the ticket can be requested, the request is created (pending approval)", '' === $open && 'ok' === p2t_err( $req ) && 'pending_approval' === ( $req['status'] ?? '' ) );
		p2t_at( $deadline );
		$late = PortalSelfService::request_refund( $pub, $tok, array( 'ticket_public_ids' => array( $tix[1]->public_id ), 'idempotency_key' => 'p2t-ref-b-' . bin2hex( random_bytes( 8 ) ) ) );
		check( "$tag at the deadline: too_late, the second ticket is refused", 'too_late' === PortalSelfService::refund_ticket_block( $portal_tickets[1], $order, array() ) && 'wpcap_refund_ticket_too_late' === p2t_err( $late ) );
	}

	echo "SET-PC-P2 time [PHP $p2t_php]: event refund rule (24 h) across New Year's Eve\n";
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		$start = p2t_utc( '2026-01-01 00:30', $tz );
		$deadline = p2t_ts( $start ) - 24 * HOUR_IN_SECONDS;
		list( , , $type ) = p2t_offer( $start, $tz, 1800, array( 'refund_policy' => wp_json_encode( array( 'hours_before' => 24, 'fee_bp' => 0 ) ) ) );
		p2t_at( '2025-12-01 10:00:00' );
		list( $oid ) = p2t_buy( $type, 'p2t-rule-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com' );
		$order = Orders::get( $oid );
		p2t_at( $deadline - 1 );
		$eligible = SelfService::eligible( $order );
		$section = SelfService::portal_section( '', $order );
		$label = '31.12.2025 00:30' . ( 'Europe/Vienna' === $tz ? '' : " ($tz)" );
		check( "$tag one second before: eligible, deadline stored in UTC and shown as $label", 1 === count( $eligible ) && gmdate( 'Y-m-d H:i:s', $deadline ) === reset( $eligible )->deadline && false !== strpos( $section, "(bis $label)" ) );
		p2t_at( $deadline );
		check( "$tag at the deadline (00:30 local on New Year's Eve): no longer eligible, no card", array() === SelfService::eligible( $order ) && '' === SelfService::portal_section( '', $order ) );
	}

	echo "SET-PC-P2 time [PHP $p2t_php]: refund request after a postponement (24 h before the new start, across spring forward)\n";
	$p2t_expect = array( 'Europe/Vienna' => '28.03.2026 11:00', 'Europe/Lisbon' => '28.03.2026 11:00', 'UTC' => '28.03.2026 12:00' );
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		list( , $occ, $type ) = p2t_offer( p2t_utc( '2026-03-20 20:00', $tz ), $tz, 2000 );
		p2t_at( '2026-03-01 10:00:00' );
		list( $oid ) = p2t_buy( $type, 'p2t-resched-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com' );
		// Moved on 2026-03-02 to 2026-03-29 12:00 local (after the switch).
		p2t_at( '2026-03-02 09:00:00' );
		$new_start = p2t_utc( '2026-03-29 12:00', $tz );
		$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => $new_start ), array( 'occurrence_id' => $occ ) );
		RescheduleRefunds::mark( $occ );
		$deadline = RescheduleRefunds::deadline( $new_start );
		$order = Orders::get( $oid );
		check( "$tag deadline is 24 real hours before the new start: {$p2t_expect[ $tz ]} local (the day before has no switch)", p2t_ts( $new_start ) - 24 * HOUR_IN_SECONDS === $deadline && 0 === strpos( RescheduleRefunds::deadline_label( $new_start, $tz ), $p2t_expect[ $tz ] ) );
		check( "$tag the deadline text names the event's time zone (A608)", false !== strpos( RescheduleRefunds::deadline_label( $new_start, $tz ), "($tz)" ) && false !== strpos( RescheduleRefunds::mail_note( $new_start, $tz ), $p2t_expect[ $tz ] . " ($tz)" ) );
		p2t_at( $deadline - 1 );
		$before = RescheduleRefunds::eligible_tickets( $order );
		p2t_at( $deadline );
		check( "$tag eligible until one second before the deadline, not at it", 1 === count( $before ) && array() === RescheduleRefunds::eligible_tickets( $order ) && 'wpcap_reschedule_refund_unavailable' === p2t_err( RescheduleRefunds::request( $order ) ) );
	}

	echo "SET-PC-P2 time [PHP $p2t_php]: reminders (24 h and 1 h) for a start at midnight after fall back\n";
	// Start 2026-10-26 00:00 local. The 24 h reminder goes out 24 real hours earlier: 01:00 summer time
	// on the 25th in Vienna and Lisbon (the 25th has 25 hours), 00:00 in UTC.
	$p2t_expect = array( 'Europe/Vienna' => '2026-10-25 01:00 CEST', 'Europe/Lisbon' => '2026-10-25 01:00 WEST', 'UTC' => '2026-10-25 00:00 UTC' );
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		$start = p2t_ts( p2t_utc( '2026-10-26 00:00', $tz ) );
		list( , $occ, $type ) = p2t_offer( gmdate( 'Y-m-d H:i:s', $start ), $tz );
		p2t_at( '2026-10-01 10:00:00' );
		$mail = 'p2t-rem-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com';
		p2t_buy( $type, $mail );
		$runs = array();
		for ( $t = $start - 26 * HOUR_IN_SECONDS; $t < $start; $t += 900 ) {
			p2t_at( $t );
			$before = count( p2t_reminders( $occ ) );
			Reminders::schedule_due();
			$rows = p2t_reminders( $occ );
			if ( count( $rows ) > $before ) { $runs[ p2t_local( $t, $tz ) ] = (int) json_decode( (string) end( $rows )->payload, true )['hours']; }
		}
		check( "$tag 24 h reminder at {$p2t_expect[ $tz ]}, 1 h reminder at 23:00 local, each exactly once", array( $p2t_expect[ $tz ] => 24, p2t_local( $start - HOUR_IN_SECONDS, $tz ) => 1 ) === $runs && 0 === strpos( p2t_local( $start - HOUR_IN_SECONDS, $tz ), '2026-10-25 23:00' ) );
		p2t_at( $start - HOUR_IN_SECONDS );
		$rendered = Reminders::render_template( null, 'wpcap_reminder', array( 'occurrence_id' => $occ, 'hours' => 1, 'tickets' => 1 ), $mail );
		check( "$tag the reminder shows the local date of the start (26.10.2026, 00:00, zone) and 1 hour left", false !== strpos( (string) ( $rendered['body'] ?? '' ), "am 26.10.2026 um 00:00 ($tz), noch 1 h" ) );
		p2t_at( $start - 1 );
		$valid = Reminders::still_valid( $mail, array( 'occurrence_id' => $occ, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ) ) );
		p2t_at( $start );
		check( "$tag a queued reminder stays valid until 23:59:59, not at midnight (start)", $valid && ! Reminders::still_valid( $mail, array( 'occurrence_id' => $occ, 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ) ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_message_queue SET status='cancelled' WHERE template_key='wpcap_reminder' AND status='queued' AND recipient=%s", $mail ) );
	}

	echo "SET-PC-P2 time [PHP $p2t_php]: holds – bank transfer cutoff (24 h) and hold capped at a start at 00:30 on New Year's Day\n";
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		$start = p2t_ts( p2t_utc( '2026-01-01 00:30', $tz ) );
		list( , , $type ) = p2t_offer( gmdate( 'Y-m-d H:i:s', $start ), $tz, 3000 );
		p2t_at( $start - 30 * HOUR_IN_SECONDS );
		$minutes = Orders::manual_hold_minutes( array( $type ) );
		list( $oid, , , , $r ) = p2t_buy( $type, 'p2t-hold-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com', 1, array( 'payment_provider' => 'manual', 'hold_minutes' => (int) $minutes ) );
		$hold = $oid ? $wpdb->get_var( $wpdb->prepare( "SELECT expires_at FROM {$p}wpcap_reservations WHERE order_id=%d", $oid ) ) : '';
		check( "$tag 30 h before: the 72 h hold is capped at the start (1800 min, ends 00:30 local on 01.01.2026)", 1800 === $minutes && $oid && gmdate( 'Y-m-d H:i:s', $start ) === $hold && 0 === strpos( p2t_local( $hold, $tz ), '2026-01-01 00:30' ) && 'pending' === Orders::get( $oid )->payment_status );
		check( "$tag the payment deadline text names the local time and zone", '01.01.2026 00:30 (' . $tz . ')' === Format::deadline( $hold, $tz ) );
		p2t_at( $start - 24 * HOUR_IN_SECONDS );
		$at_cutoff = Orders::manual_hold_minutes( array( $type ) );
		p2t_at( $start - 24 * HOUR_IN_SECONDS + 1 );
		check( "$tag bank transfer offered until exactly 24 h before (00:30 local on New Year's Eve), not one second later", 1440 === $at_cutoff && null === Orders::manual_hold_minutes( array( $type ) ) );
		p2t_at( $start - 1 );
		Orders::expire_holds( 200, array( $oid ) );
		$still = Orders::get( $oid )->payment_status;
		p2t_at( $start );
		Orders::expire_holds( 200, array( $oid ) );
		check( "$tag the unpaid order is still held at 00:29:59 and expires at 00:30 (start)", 'pending' === $still && 'expired' === Orders::get( $oid )->payment_status );
	}
	// 72 h bank transfer hold placed at 12:00 local on 2026-10-24: 72 real hours end at 11:00 local on the
	// 27th in Vienna and Lisbon (one hour gained), at 12:00 in UTC.
	foreach ( $p2t_zones as $tz ) {
		$tag = "[$p2t_php/$tz]";
		list( , , $type ) = p2t_offer( p2t_utc( '2026-11-20 20:00', $tz ), $tz, 3000 );
		p2t_at( p2t_utc( '2026-10-24 12:00', $tz ) );
		list( $oid ) = p2t_buy( $type, 'p2t-hold72-' . ( ++$p2t_n ) . '-' . sanitize_key( $p2t_php ) . '@example.com', 1, array( 'payment_provider' => 'manual', 'hold_minutes' => (int) Orders::manual_hold_minutes( array( $type ) ) ) );
		$hold = $oid ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT expires_at FROM {$p}wpcap_reservations WHERE order_id=%d", $oid ) ) : '';
		$local = 'UTC' === $tz ? '27.10.2026 12:00' : '27.10.2026 11:00';
		check( "$tag 72 h hold across fall back ends $local local (72 real hours)", $oid && p2t_ts( p2t_utc( '2026-10-24 12:00', $tz ) ) + 72 * HOUR_IN_SECONDS === p2t_ts( $hold ) && "$local ($tz)" === Format::deadline( $hold, $tz ) );
		p2t_at( p2t_ts( $hold ) );
		Orders::expire_holds( 200, array( $oid ) );
		check( "$tag and expires exactly then", 'expired' === Orders::get( $oid )->payment_status );
	}
	p2t_at( null );
}
date_default_timezone_set( 'UTC' );

// Restore.
p2t_at( null );
Settings::update( $p2t_saved );
if ( null === $p2t_saved_tz ) { unset( $GLOBALS['wpcap_test_options']['timezone_string'] ); } else { $GLOBALS['wpcap_test_options']['timezone_string'] = $p2t_saved_tz; }
$GLOBALS['wpcap_test_user_id'] = $p2t_saved_user;
wpcap_test_reset_transients();
