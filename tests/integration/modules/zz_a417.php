<?php
// phpcs:ignoreFile
/**
 * A417: the SMS outbox is a queue under Operations → Queues (failed, held by the budget stop, send
 * outcome unknown) with retry/discard; held messages show their expiry date and expire with an audit
 * entry instead of silently; the doctor's stale-claim repair never queues an SMS again blindly.
 */

use PassCaptain\Operations\Doctor;
use PassCaptain\Operations\Queues;
use PassCaptain\Sms\Service;

global $wpdb;
$p = $wpdb->prefix;
$wpdb->query( "UPDATE {$p}wpcap_sms_messages SET status='skipped' WHERE status IN ('queued','sending','unknown','held','failed')" );

function a417_insert( $status, $order_id, array $extra = array() ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}wpcap_sms_messages", array_merge( array( 'dedupe_key' => hash( 'sha256', uniqid( 'a417', true ) ), 'consent_id' => 0, 'order_id' => $order_id, 'occurrence_id' => 0, 'purpose' => 'reschedule', 'body' => 'A417 geheimer Text', 'status' => $status, 'attempts' => 2, 'send_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'last_error' => 'x' ), $extra ) );
	return (int) $wpdb->insert_id;
}
function a417_status( $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_sms_messages WHERE message_id=%d", $id ) );
}

echo "A417: SMS outbox under Operations → Queues\n";
$a417_failed = a417_insert( 'failed', 9417001 );
$a417_held = a417_insert( 'held', 9417002, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
$a417_unknown = a417_insert( 'unknown', 9417003 );
$a417_sent = a417_insert( 'sent', 9417004 );

check( 'A417: the SMS outbox is an available queue with a label', isset( Queues::available()['sms'] ) && '' !== ( Queues::labels()['sms'] ?? '' ) );
$a417_stats = Queues::stats( 'sms' );
check( 'A417: failed, held and unknown count as needing a decision', 3 === $a417_stats['failed'] );
$a417_items = Queues::failed_items( 'sms', 50 );
$a417_by_id = array_column( $a417_items['rows'], null, 'id' );
check( 'A417: the list shows failed, held and unknown, not sent', 3 === $a417_items['total'] && isset( $a417_by_id[ $a417_failed ], $a417_by_id[ $a417_held ], $a417_by_id[ $a417_unknown ] ) && ! isset( $a417_by_id[ $a417_sent ] ) );
check( 'A417: a held message shows its expiry date', false !== strpos( $a417_by_id[ $a417_held ]['detail'], wp_date( 'd.m.Y', time() + 5 * DAY_IN_SECONDS ) ) );
check( 'A417: rows carry the order, never the message text', 9417001 === ( $a417_by_id[ $a417_failed ]['refs']['order_id'] ?? 0 ) && false === strpos( json_encode( $a417_items ), 'geheimer' ) );
check( 'A417: retry queues a held message again with a fresh attempt budget', 1 === Queues::retry( 'sms', array( $a417_held, $a417_sent ) ) && 'queued' === a417_status( $a417_held ) && 0 === (int) $wpdb->get_var( "SELECT attempts FROM {$p}wpcap_sms_messages WHERE message_id=$a417_held" ) && 'sent' === a417_status( $a417_sent ) );
check( 'A417: discard cancels an unknown message', 1 === Queues::discard( 'sms', array( $a417_unknown ) ) && 'cancelled' === a417_status( $a417_unknown ) );
check( 'A417: retry/discard are audited', (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='operations_discard' AND object_type='queue_sms'" ) );

echo "A417: held messages do not expire silently\n";
$a417_old = a417_insert( 'held', 9417005, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( Service::HELD_DAYS + 1 ) * DAY_IN_SECONDS ) ) );
check( 'A417: the oldest held message is known (expiry hint)', '' !== Service::oldest_held_at() );
check( 'A417: an expired held message is skipped with an audit entry', 1 === Service::expire_held() && 'skipped' === a417_status( $a417_old ) && false !== strpos( (string) $wpdb->get_var( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='sms_held_expired' ORDER BY audit_id DESC LIMIT 1" ), (string) $a417_old ) );

echo "A417: stale SMS claims are not requeued blindly by the doctor\n";
$a417_stale = a417_insert( 'sending', 9417006, array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ) );
$a417_report = Doctor::run( array( 'stale_queue_claims' ) );
$a417_keys = array_column( (array) ( $a417_report['stale_queue_claims']['items'] ?? array() ), 'key' );
check( 'A417: the doctor reports the stale SMS claim', in_array( 'sms:' . $a417_stale, $a417_keys, true ) );
Doctor::repair( 'requeue_stale_claims', false, array( 'sms:' . $a417_stale ), 'test' );
check( 'A417: the repair marks it unknown (A416), not queued', 'unknown' === a417_status( $a417_stale ) );

$wpdb->query( "UPDATE {$p}wpcap_sms_messages SET status='skipped' WHERE status IN ('queued','sending','unknown','held','failed') AND order_id BETWEEN 9417000 AND 9417999" );
