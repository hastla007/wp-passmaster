<?php
/**
 * A557: waitlist entries of cancelled or past dates (or after the end of sales) are closed, with an
 * optional mail; the order portal only lists entries of dates that are still on sale.
 */

// phpcs:ignoreFile

use PassCaptain\Settings;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;
$a557_waitlist = Settings::get( 'waitlist' );

function a557_entry( $email, $hours = 300, array $type_extra = array() ) {
	global $wpdb;
	$event = ex_event( 'A557 Konzert' );
	$occ = ex_occ( $event, 0, $hours );
	$type = ex_type( $occ, 'Stehplatz', 1500 );
	if ( $type_extra ) { $wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", $type_extra, array( 'ticket_type_id' => $type ) ); }
	$GLOBALS['wpcap_test_transients'] = array();
	Waitlists::join( $event, $occ, $type, $email, 1 );
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT waitlist_id FROM {$wpdb->prefix}wpcap_waitlist WHERE ticket_type_id=%d AND email_hash=%s", $type, hash( 'sha256', strtolower( $email ) ) ) );
	return array( 'event' => $event, 'occ' => $occ, 'type' => $type, 'id' => $id );
}
function a557_status( $id ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcap_waitlist WHERE waitlist_id=%d", $id ) );
}
function a557_mails( $before, $to ) {
	return array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $before ), static fn( $m ) => $to === $m['to'] && false !== strpos( (string) $m['subject'], 'Warteliste beendet' ) ) );
}

echo "A557: waitlist entries of a cancelled date are closed\n";
Settings::update( array( 'waitlist' => array_merge( (array) $a557_waitlist, array( 'close_mail' => 1 ) ) ) );
$a = a557_entry( 'a557-cancel@example.com' );
$before = count( $GLOBALS['wpcap_test_mails'] );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a['occ'] ) );
$closed = Waitlists::on_occurrence_cancelled( $a['occ'] );
$mails = a557_mails( $before, 'a557-cancel@example.com' );
check( 'A557: cancelling the date closes its waiting entries', $a['id'] > 0 && 1 === $closed && 'closed' === a557_status( $a['id'] ) );
check( 'A557: the person gets the waitlist_closed mail with the reason', 1 === count( $mails ) && false !== strpos( (string) $mails[0]['body'], 'abgesagt' ) );
check( 'A557: closing is audited and idempotent', 0 === Waitlists::on_occurrence_cancelled( $a['occ'] ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='waitlist_closed' AND object_id=%d", $a['id'] ) ) );
check( 'A557: the hook is registered on wpcap_occurrence_cancelled', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' ), "add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'on_occurrence_cancelled' )" ) );

echo "A557: past dates and ended sales are closed by the sweep\n";
$b = a557_entry( 'a557-past@example.com' );
$c = a557_entry( 'a557-sales@example.com' );
$d = a557_entry( 'a557-open@example.com' );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'occurrence_id' => $b['occ'] ) );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_end' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'ticket_type_id' => $c['type'] ) );
$order = (object) array( 'customer_email' => 'a557-past@example.com' );
check( 'A557: the portal hides entries of past dates even before the sweep', array() === Waitlists::portal_entries( $order ) && 1 === count( Waitlists::portal_entries( (object) array( 'customer_email' => 'a557-open@example.com' ) ) ) );
check( 'A557: the portal hides entries whose sales ended', array() === Waitlists::portal_entries( (object) array( 'customer_email' => 'a557-sales@example.com' ) ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
Waitlists::close_ended();
check( 'A557: past date and ended sales are closed, a future date keeps waiting', 'closed' === a557_status( $b['id'] ) && 'closed' === a557_status( $c['id'] ) && 'waiting' === a557_status( $d['id'] ) );
$mails = a557_mails( $before, 'a557-past@example.com' );
check( 'A557: the closing mail names the end, not a cancellation', 1 === count( $mails ) && false !== strpos( (string) $mails[0]['body'], 'begonnen' ) );
check( 'A557: the sweep runs the closing', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Waitlists.php' ), "self::close_ended();" ) );

echo "A557: the closing mail can be switched off\n";
Settings::update( array( 'waitlist' => array_merge( (array) $a557_waitlist, array( 'close_mail' => 0 ) ) ) );
$e = a557_entry( 'a557-quiet@example.com' );
$wpdb->update( "{$p}wpcap_events", array( 'status' => 'cancelled' ), array( 'event_id' => $e['event'] ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
Waitlists::close_ended();
check( 'A557: cancelled event closes the entry without mail when close_mail is off', 'closed' === a557_status( $e['id'] ) && array() === a557_mails( $before, 'a557-quiet@example.com' ) );
check( 'A557: status label and mail type exist', 'Geschlossen (Termin abgesagt oder vorbei)' === Waitlists::status_label( 'closed' ) && is_array( PassCaptain\Mail\Mailer::type( 'waitlist_closed' ) ) );

Settings::update( array( 'waitlist' => $a557_waitlist ) );
