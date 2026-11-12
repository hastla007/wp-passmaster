<?php
/**
 * Reminders module: stage selection, dedupe per holder/occurrence/offset, skips (revoked, refunded,
 * unpaid, cancelled occurrence), purge of invalid queued reminders and template rendering.
 */

// phpcs:ignoreFile

use PassCaptain\Communications;
use PassCaptain\Modules\Reminders;
use PassCaptain\Orders;
use PassCaptain\Settings;

Reminders::register();
global $wpdb;
$p = $wpdb->prefix;

echo "Reminders: stages\n";
$now = time();
check( 'stage: nothing before the largest offset', 0 === Reminders::stage( $now + 50 * 3600, array( 2, 48 ), $now ) );
check( 'stage: 48 h reminder when 47 h before start', 48 === Reminders::stage( $now + 47 * 3600, array( 2, 48 ), $now ) );
check( 'stage: only the 2 h reminder for a late buyer', 2 === Reminders::stage( $now + 3600, array( 2, 48 ), $now ) );
check( 'stage: nothing after start', 0 === Reminders::stage( $now - 60, array( 2, 48 ), $now ) );

Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 48, 2 ), 'subject' => 'Erinnerung: {event} am {date}', 'body' => "Noch {hours} Stunden: {event} um {time} ({timezone}), {location}. Tickets: {tickets}. {ics_url}", 'sms' => 0, 'sms_body' => '' ) ) );
check( 'offsets are normalized ascending', array( 2, 48 ) === Reminders::config()['offsets'] );

function rem_queue( $occ ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s ORDER BY message_id", '%"occurrence_id":' . (int) $occ . ',%' ) );
}

$type = make_type( 20, 10, 0 );
$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 10 * 3600 ), $occ ) );
$GLOBALS['wpcap_test_transients'] = array();
$a = Orders::create( array( 'email' => 'rem-a@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 2 ) ), 'idempotency_key' => 'rem-a' ) );
$b = Orders::create( array( 'email' => 'rem-b@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'rem-b' ) );
$c = Orders::create( array( 'email' => 'rem-c@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'rem-c' ) );
$d = Orders::create( array( 'email' => 'rem-d@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'rem-d' ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE order_id=" . (int) $b['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET refunded=1 WHERE order_id=" . (int) $c['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_orders SET payment_status='pending' WHERE order_id=" . (int) $d['order_id'] );
// Attendee with own e-mail on one of A's tickets: separate holder.
$a_tickets = tickets( $a['order_id'] );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET attendee_email='rem-guest@example.com' WHERE ticket_id=" . (int) $a_tickets[1]->ticket_id );

echo "Reminders: scheduling and dedupe\n";
$queued = Reminders::schedule_due();
$rows = rem_queue( $occ );
$recipients = array_map( static fn( $r ) => $r->recipient, $rows );
sort( $recipients );
check( 'one reminder per valid holder (buyer and attendee)', array( 'rem-a@example.com', 'rem-guest@example.com' ) === $recipients );
check( 'revoked, refunded and unpaid tickets are skipped', ! array_intersect( $recipients, array( 'rem-b@example.com', 'rem-c@example.com', 'rem-d@example.com' ) ) );
$payload = json_decode( $rows[0]->payload ?? '{}', true );
check( 'queued with the 48 h stage and no PII in the payload', 48 === ( $payload['hours'] ?? 0 ) && false === strpos( (string) ( $rows[0]->payload ?? '' ), '@' ) );
check( 'second run queues nothing (dedupe key)', 0 === Reminders::schedule_due() && 2 === count( rem_queue( $occ ) ) );
$wpdb->query( "UPDATE {$p}wpcap_message_queue SET status='sent' WHERE template_key='wpcap_reminder'" );
check( 'already sent reminders are not repeated', 0 === Reminders::schedule_due() );

$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 3600 ), $occ ) );
Reminders::schedule_due();
$rows = rem_queue( $occ );
$new = array_values( array_filter( $rows, static fn( $r ) => 'queued' === $r->status ) );
check( 'a moved start or the next offset produces new reminders (2 h stage)', 2 === count( $new ) && 2 === json_decode( $new[0]->payload, true )['hours'] );

echo "Reminders: template rendering and sending\n";
$tpl = Reminders::render_template( null, 'wpcap_reminder', json_decode( $new[0]->payload, true ) );
check( 'template renders placeholders from settings', is_array( $tpl ) && 0 === strpos( $tpl['subject'], 'Erinnerung: Konzert am ' ) && false !== strpos( $tpl['body'], 'Noch 2 Stunden: Konzert' ) && false !== strpos( $tpl['body'], 'Europe/Vienna' ) && false !== strpos( $tpl['body'], 'Tickets: 1' ) && false !== strpos( $tpl['body'], 'wpcap_ics=occurrence' ) );
check( 'other templates are untouched', 'x' === Reminders::render_template( 'x', 'other', array() ) );
$GLOBALS['wpcap_test_mails'] = array();
Communications::process_due();
$sent = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => in_array( $m['to'], array( 'rem-a@example.com', 'rem-guest@example.com' ), true ) ) );
check( 'queued reminders are sent through the core message queue', 2 === count( $sent ) && 0 === strpos( $sent[0]['subject'], 'Erinnerung: Konzert' ) );

echo "Reminders: invalid reminders are cancelled before sending\n";
$type2 = make_type( 20, 10, 0 );
$occ2 = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type2" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 20 * 3600 ), $occ2 ) );
$e = Orders::create( array( 'email' => 'rem-e@example.com', 'items' => array( array( 'ticket_type_id' => $type2, 'quantity' => 1 ) ), 'idempotency_key' => 'rem-e' ) );
$f = Orders::create( array( 'email' => 'rem-f@example.com', 'items' => array( array( 'ticket_type_id' => $type2, 'quantity' => 1 ) ), 'idempotency_key' => 'rem-f' ) );
Reminders::schedule_due();
check( 'two reminders queued for the second occurrence', 2 === count( rem_queue( $occ2 ) ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE order_id=" . (int) $e['order_id'] );
check( 'revoked holder: queued reminder cancelled', 1 === Reminders::purge_stale() && 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND recipient=%s", 'rem-e@example.com' ) ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$occ2" );
do_action( 'wpcap_occurrence_cancelled', $occ2, 1 );
check( 'cancelled occurrence: remaining reminders cancelled', 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND recipient=%s", 'rem-f@example.com' ) ) );
check( 'cancelled occurrence gets no new reminders', 0 === Reminders::schedule_due() );

echo "Reminders: settings\n";
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'saving requires manage_options', is_wp_error( Reminders::save( array( 'enabled' => '1' ) ) ) );
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'enabled reminders need offsets and texts', is_wp_error( Reminders::save( array( 'enabled' => '1', 'offsets' => 'abc', 'subject' => 'x', 'body' => 'y' ) ) ) );
$saved = Reminders::save( array( 'enabled' => '1', 'offsets' => '72, 2;24 9999', 'subject' => 'S {event}', 'body' => "Zeile 1\nZeile 2" ) );
check( 'offsets parsed, bounded and sorted; multi-line body kept', ! is_wp_error( $saved ) && array( 2, 24, 72 ) === Reminders::config()['offsets'] && "Zeile 1\nZeile 2" === Reminders::config()['body'] );
// Leave reminders off for later test files.
Settings::update( array( 'reminders' => array( 'enabled' => 0, 'offsets' => array( 24 ), 'subject' => 'x', 'body' => 'y', 'sms' => 0, 'sms_body' => '' ) ) );
