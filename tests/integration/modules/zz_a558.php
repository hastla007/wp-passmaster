<?php
/**
 * A558: {hours} in reminders is the real remaining time when the mail is rendered (a late buyer
 * queued at the 24 h stage five hours before the start reads "5"), and scheduling walks every due
 * occurrence in batches instead of only the first 200.
 */

// phpcs:ignoreFile

use PassCaptain\Modules\Reminders;
use PassCaptain\Orders;
use PassCaptain\Settings;

global $wpdb;
$p = $wpdb->prefix;
$a558_settings = Settings::get( 'reminders' );
Settings::update( array( 'reminders' => array( 'enabled' => 1, 'offsets' => array( 24 ), 'subject' => 'Erinnerung: {event}', 'body' => 'Noch {hours} Stunden bis {event}.', 'sms' => 0, 'sms_body' => '', 'translations' => array() ) ) );

function a558_queue( $occ ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s ORDER BY message_id", '%"occurrence_id":' . (int) $occ . ',%' ) );
}
function a558_holder( $hours, $email ) {
	global $wpdb;
	$type = make_type( 20, 10, 0 );
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=$type" );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + (int) round( $hours * HOUR_IN_SECONDS ) ), $occ ) );
	$GLOBALS['wpcap_test_transients'] = array();
	Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => 1 ) ), 'idempotency_key' => 'a558-' . uniqid( '', true ) ) );
	return $occ;
}

echo "A558: {hours} names the real remaining time\n";
$a558_occ = a558_holder( 5, 'a558-late@example.com' );
Reminders::schedule_due();
$a558_rows = a558_queue( $a558_occ );
$a558_payload = json_decode( (string) ( $a558_rows[0]->payload ?? '{}' ), true );
check( 'A558: a late buyer is queued once, at the 24 h stage (dedupe key unchanged)', 1 === count( $a558_rows ) && 24 === (int) ( $a558_payload['hours'] ?? 0 ) );
$a558_tpl = Reminders::render_template( null, Reminders::TEMPLATE, $a558_payload, 'a558-late@example.com' );
check( 'A558: the mail says 5 hours, not 24', is_array( $a558_tpl ) && false !== strpos( $a558_tpl['body'], 'Noch 5 Stunden' ) && false === strpos( $a558_tpl['body'], 'Noch 24 Stunden' ) );
$a558_later = static function () { return time() + 3 * HOUR_IN_SECONDS + 10 * MINUTE_IN_SECONDS; };
add_filter( 'wpcap_now', $a558_later );
$a558_tpl2 = Reminders::render_template( null, Reminders::TEMPLATE, $a558_payload, 'a558-late@example.com' );
remove_filter( 'wpcap_now', $a558_later );
check( 'A558: rendered (sent) later, the remaining time is counted at sending', false !== strpos( $a558_tpl2['body'], 'Noch 2 Stunden' ) );
check( 'A558: hours_left rounds and never says 0', 1 === Reminders::hours_left( gmdate( 'Y-m-d H:i:s', time() + 600 ) ) && 3 === Reminders::hours_left( gmdate( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS + 1200 ) ) );

echo "A558: scheduling is not limited to the first 200 occurrences\n";
$a558_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A558 Masse', 'slug' => 'a558-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a558_now, 'updated_at' => $a558_now ) );
$a558_event = (int) $wpdb->insert_id;
$a558_values = array();
for ( $i = 0; $i < Reminders::BATCH + 5; $i++ ) {
	$a558_values[] = $wpdb->prepare( '(%d,%s,%s,%s,%d,%s)', $a558_event, gmdate( 'Y-m-d H:i:s', time() + 1800 + $i ), 'Europe/Vienna', 'Halle', 0, 'scheduled' );
}
$wpdb->query( "INSERT INTO {$p}wpcap_occurrences (event_id,starts_at,timezone,location,capacity,status) VALUES " . implode( ',', $a558_values ) );
$a558_target = a558_holder( 3, 'a558-crowd@example.com' );
Reminders::schedule_due();
check( 'A558: a due occurrence behind 205 earlier dates gets its reminder in the same run', 1 === count( a558_queue( $a558_target ) ) );
check( 'A558: a finished walk leaves no cursor', false === get_option( Reminders::CURSOR, false ) );
$wpdb->query( "DELETE FROM {$p}wpcap_occurrences WHERE event_id=$a558_event" );
$wpdb->query( "DELETE FROM {$p}wpcap_events WHERE event_id=$a558_event" );
$wpdb->query( "UPDATE {$p}wpcap_message_queue SET status='cancelled' WHERE template_key='wpcap_reminder' AND status='queued'" );
Settings::update( array( 'reminders' => $a558_settings ) );
