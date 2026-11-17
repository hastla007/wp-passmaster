<?php
/**
 * Z16-02 (rest of PASS-FE-21): a reminder that became invalid between queueing and sending never
 * goes out – after a refund, an unsubscribe, a moved start, a cancelled occurrence and an
 * unpublished event – and the check also holds with a backlog larger than one queue run and with
 * a raised `wpcap_message_batch_size`.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Communications;
use PassCaptain\Mail\Suppression;
use PassCaptain\Modules\Reminders;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

// Idempotent (same callbacks and priorities as in reminders.php); needed when this file runs alone.
Reminders::register();
global $wpdb;
$p = $wpdb->prefix;

$z16r_settings = Settings::get( 'reminders' );
Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 48 ), 'subject' => 'Z16 Erinnerung {event}', 'body' => 'Z16 {event} um {time}', 'sms' => 0, 'sms_body' => '' ) ) );
// Start from an empty reminder queue: earlier test files leave their own rows.
$wpdb->query( "DELETE FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder'" );

/** Creates a ticket type whose occurrence starts in $hours hours; returns array( type, occurrence ). */
function z16r_type( $hours ) {
	global $wpdb;
	$type = make_type( 2000, 10, 1000 );
	$occ  = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=$type" );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + $hours * 3600 ), $occ ) );
	return array( $type, $occ );
}
/** Status of the queued reminder(s) of one recipient, e.g. "cancelled" or "sent,queued". */
function z16r_status( $email ) {
	global $wpdb;
	return implode( ',', $wpdb->get_col( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND recipient=%s ORDER BY message_id", $email ) ) );
}
/** Runs the queue the way the cron hook does (purge first, then send) until nothing is due. */
function z16r_run_queue() {
	global $wpdb;
	for ( $i = 0; $i < 20; $i++ ) {
		Reminders::purge_stale();
		Communications::process_due();
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM {$wpdb->prefix}wpcap_message_queue WHERE status='queued' AND send_at<=%s LIMIT 1", gmdate( 'Y-m-d H:i:s' ) ) ) ) { break; }
	}
}
/** Recipients of the reminder mails sent since $from (index into the test mail log). */
function z16r_reminded( $from ) {
	$to = array();
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $from ) as $mail ) {
		// Only this file's holders: occurrences of earlier test files may be due for a reminder as well.
		if ( 0 === strpos( (string) $mail['subject'], 'Z16 Erinnerung' ) && 0 === strpos( strtolower( (string) $mail['to'] ), 'z16r-' ) ) { $to[] = strtolower( (string) $mail['to'] ); }
	}
	sort( $to );
	return $to;
}

echo "Z16: reminders that became invalid between queueing and sending\n";
list( $z16r_t1, $z16r_o1 ) = z16r_type( 10 );
$z16r_keep     = admin_paid_order( $z16r_t1, 1, 'z16r-keep@example.com' );
$z16r_refund   = admin_paid_order( $z16r_t1, 1, 'z16r-refund@example.com' );
$z16r_unsub    = admin_paid_order( $z16r_t1, 1, 'z16r-unsub@example.com' );
$z16r_cancel   = admin_paid_order( $z16r_t1, 1, 'z16r-cancel@example.com' );
list( $z16r_t2, $z16r_o2 ) = z16r_type( 12 );
$z16r_moved    = admin_paid_order( $z16r_t2, 1, 'z16r-moved@example.com' );
list( $z16r_t3, $z16r_o3 ) = z16r_type( 14 );
$z16r_occ_gone = admin_paid_order( $z16r_t3, 1, 'z16r-occ@example.com' );
list( $z16r_t4, $z16r_o4 ) = z16r_type( 16 );
$z16r_draft    = admin_paid_order( $z16r_t4, 1, 'z16r-draft@example.com' );
Reminders::schedule_due();
$z16r_all = array( 'z16r-keep@example.com', 'z16r-refund@example.com', 'z16r-unsub@example.com', 'z16r-cancel@example.com', 'z16r-moved@example.com', 'z16r-occ@example.com', 'z16r-draft@example.com' );
check( 'Z16: one reminder is queued for each of the seven holders', array_fill( 0, 7, 'queued' ) === array_map( 'z16r_status', $z16r_all ) );

// Everything below happens after queueing and before the queue runs.
$z16r_done = Refunds::request( $z16r_refund, array(), 'Z16 Erstattung', 'z16r-refund-' . uniqid() );
Suppression::suppress_hash( Suppression::hash( 'z16r-unsub@example.com' ), Suppression::SCOPE_REMINDERS, 'unsubscribe' );
Orders::cancel_order( $z16r_cancel, 'z16' );
$z16r_old_start = (string) $wpdb->get_var( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=$z16r_o2" );
$z16r_new_start = gmdate( 'Y-m-d H:i:s', time() + 30 * 3600 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", $z16r_new_start, $z16r_o2 ) );
$wpdb->query( "UPDATE {$p}wpcap_occurrences SET status='cancelled' WHERE occurrence_id=$z16r_o3" );
$wpdb->query( "UPDATE {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id SET e.status='draft' WHERE o.occurrence_id=$z16r_o4" );
check( 'Z16: the refund used for the scenario completed', is_array( $z16r_done ) && 'completed' === $z16r_done['status'] );

$z16r_from = count( $GLOBALS['wpcap_test_mails'] );
z16r_run_queue();
$z16r_sent = z16r_reminded( $z16r_from );
check( 'Z16: only the unchanged holder receives the queued reminder', array( 'z16r-keep@example.com' ) === $z16r_sent );
check( 'Z16: reminder after a refund is cancelled, not sent', 'cancelled' === z16r_status( 'z16r-refund@example.com' ) );
check( 'Z16: reminder after an unsubscribe is cancelled, not sent', 'cancelled' === z16r_status( 'z16r-unsub@example.com' ) );
check( 'Z16: reminder after an order cancellation is cancelled, not sent', 'cancelled' === z16r_status( 'z16r-cancel@example.com' ) );
check( 'Z16: reminder for the old start time is cancelled after a reschedule', 'cancelled' === z16r_status( 'z16r-moved@example.com' ) );
check( 'Z16: reminder for a cancelled occurrence is cancelled', 'cancelled' === z16r_status( 'z16r-occ@example.com' ) );
check( 'Z16: reminder for an event that is no longer published is cancelled', 'cancelled' === z16r_status( 'z16r-draft@example.com' ) );

Reminders::schedule_due();
z16r_run_queue();
$z16r_again = z16r_reminded( $z16r_from );
$z16r_moved_payload = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND recipient=%s AND status='sent'", 'z16r-moved@example.com' ) ), true );
check( 'Z16: after the reschedule exactly one reminder goes out, for the new start time', 1 === count( array_keys( $z16r_again, 'z16r-moved@example.com', true ) ) && $z16r_new_start === ( $z16r_moved_payload['starts_at'] ?? '' ) && $z16r_old_start !== $z16r_new_start );
check( 'Z16: a later scheduling run queues nothing for refunded, unsubscribed, cancelled or unpublished holders', array( 'z16r-keep@example.com', 'z16r-moved@example.com' ) === $z16r_again );

echo "Z16: backlog larger than one queue run\n";
list( $z16r_t5, $z16r_o5 ) = z16r_type( 10 );
$z16r_bulk = admin_paid_order( $z16r_t5, 1, 'z16r-bulk-0000@example.com' );
$z16r_rows = array();
$z16r_now  = gmdate( 'Y-m-d H:i:s' );
for ( $i = 1; $i <= 520; $i++ ) {
	$z16r_rows[] = $wpdb->prepare( '(%s,%d,%d,%s,%s,%s,%s,1)', bin2hex( random_bytes( 16 ) ), $z16r_bulk, $z16r_t5, hash( 'sha256', 'z16r-bulk-' . $i . '-' . uniqid( '', true ) ), sprintf( 'z16r-bulk-%04d@example.com', $i ), 'valid', $z16r_now );
}
// Merged line (A581): reminders go to the holder of the current code – the attendee once the code was delivered there.
$wpdb->query( "INSERT INTO {$p}wpcap_tickets (public_id,order_id,ticket_type_id,token_hash,attendee_email,status,issued_at,code_with_attendee) VALUES " . implode( ',', $z16r_rows ) );
Reminders::schedule_due();
$z16r_queue = $wpdb->get_col( $wpdb->prepare( "SELECT recipient FROM {$p}wpcap_message_queue WHERE status='queued' AND template_key='wpcap_reminder' AND payload LIKE %s ORDER BY send_at,message_id", '%"occurrence_id":' . $z16r_o5 . ',%' ) );
check( 'Z16: 521 reminders are queued for the large occurrence', 521 === count( $z16r_queue ) );

// With a raised batch size one run could send all 521; the last one in send order became invalid.
$z16r_last = (string) end( $z16r_queue );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='refunded',refunded=1 WHERE ticket_type_id=%d AND LOWER(COALESCE(NULLIF(attendee_email,''),%s))=%s", $z16r_t5, 'z16r-bulk-0000@example.com', $z16r_last ) );
$z16r_batch = static function () { return 600; };
add_filter( 'wpcap_message_batch_size', $z16r_batch );
$z16r_purged = Reminders::purge_stale();
remove_filter( 'wpcap_message_batch_size', $z16r_batch );
check( 'Z16: with a batch size of 600 the invalid reminder at position 521 is cancelled before the run', 1 === $z16r_purged && 'cancelled' === z16r_status( $z16r_last ) );

// Default batch size: the invalid reminder sits behind 500 valid ones; purge and send alternate.
$z16r_late = (string) $z16r_queue[509];
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='refunded',refunded=1 WHERE ticket_type_id=%d AND attendee_email=%s", $z16r_t5, $z16r_late ) );
$z16r_from = count( $GLOBALS['wpcap_test_mails'] );
z16r_run_queue();
$z16r_bulk_sent = z16r_reminded( $z16r_from );
check( 'Z16: with a backlog of more than 500 the invalid reminder is never sent', ! in_array( $z16r_late, $z16r_bulk_sent, true ) && ! in_array( $z16r_last, $z16r_bulk_sent, true ) && 'cancelled' === z16r_status( $z16r_late ) );
check( 'Z16: the 519 valid reminders of the backlog are all sent exactly once', 519 === count( $z16r_bulk_sent ) && 519 === count( array_unique( $z16r_bulk_sent ) ) );

$wpdb->query( "DELETE FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder'" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_tickets WHERE ticket_type_id=%d AND attendee_email LIKE %s", $z16r_t5, 'z16r-bulk-%' ) );
Settings::update( array( 'reminders' => $z16r_settings ) );
