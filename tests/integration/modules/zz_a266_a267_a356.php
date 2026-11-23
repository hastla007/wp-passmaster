<?php
/**
 * A266: an occurrence is only cancelled through the confirmation page (view=cancel_occurrence),
 * never through the status field of the occurrence form.
 * A267: cancelling a whole event has its own confirmation page (consequences, reason, mandatory
 * confirmation) instead of window.confirm.
 * A356: a cancelled occurrence cannot be set back to "scheduled" (server-side refusal).
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Queries;
use PassCaptain\OccurrenceCancellation;
use PassCaptain\Orders;

global $wpdb;
$p = $wpdb->prefix;

function a266_render( array $get ) {
	$_GET = $get + array( 'page' => 'pass-captain' );
	ob_start();
	try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
	$html = (string) ob_get_clean();
	$_GET = array();
	return $html;
}
function a266_valid( $occ ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND t.status='valid'", $occ ) );
}

echo "A266: the occurrence form cannot cancel\n";
$a266_event = ex_event( 'A266 Lesung' );
$a266_occ = ex_occ( $a266_event );
$a266_type = ex_type( $a266_occ, 'Eintritt', 1200 );
list( $a266_order ) = ex_buy( $a266_type, 2, 'a266@example.com' );
$a266_row = Queries::occurrence( $a266_occ );
$a266_base = array( 'occurrence_id' => $a266_occ, 'starts_at' => PassCaptain\Admin\Forms::utc_to_local( $a266_row->starts_at, 'Europe/Vienna' ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => '0' );
$a266_mark = count( $GLOBALS['wpcap_test_actions'] );
$a266_save = EventsPage::save_occurrence( $a266_base + array( 'status' => 'cancelled' ) );
check( 'A266: status "cancelled" through the form is refused with a pointer to the confirmation page', is_wp_error( $a266_save ) && 'wpcap_occurrence_cancel_flow' === $a266_save->get_error_code() && false !== strpos( $a266_save->get_error_message(), 'Absagen' ) );
check( 'A266: the occurrence stays scheduled, tickets stay valid, nothing is fired', 'scheduled' === Queries::occurrence( $a266_occ )->status && 2 === a266_valid( $a266_occ ) && ! in_array( 'wpcap_occurrence_cancelled', array_slice( $GLOBALS['wpcap_test_actions'], $a266_mark ), true ) );
$a266_plain = EventsPage::save_occurrence( array_merge( $a266_base, array( 'location' => 'Graz' ) ) );
check( 'A266: saving without a status keeps the status', ! is_wp_error( $a266_plain ) && 'scheduled' === Queries::occurrence( $a266_occ )->status && 'Graz' === Queries::occurrence( $a266_occ )->location );
$a266_html = a266_render( array( 'view' => 'occurrence', 'occurrence_id' => $a266_occ ) );
check( 'A266: the occurrence form has no "Abgesagt" option, only a link to the cancellation page', false === strpos( $a266_html, 'EXCEPTION' ) && false === strpos( $a266_html, 'value="cancelled"' ) && false === strpos( $a266_html, 'id="wpcap-occ-status"' ) && false !== strpos( $a266_html, 'view=cancel_occurrence' ) );

echo "A356: a cancelled occurrence cannot be scheduled again\n";
add_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10, 1 );
$a356_cancel = EventsPage::cancel_occurrence( array( 'occurrence_id' => $a266_occ, 'reason' => 'Autorin erkrankt', 'confirm' => '1' ) );
remove_action( 'wpcap_occurrence_cancelled', array( OccurrenceCancellation::class, 'handle' ), 10 );
$a356_back = EventsPage::save_occurrence( $a266_base + array( 'status' => 'scheduled' ) );
check( 'A356: cancelled → scheduled is refused with a clear message', ! is_wp_error( $a356_cancel ) && is_wp_error( $a356_back ) && 'wpcap_occurrence_reactivate' === $a356_back->get_error_code() && 409 === ( $a356_back->get_error_data()['status'] ?? 0 ) && false !== strpos( $a356_back->get_error_message(), 'neuen Termin' ) );
check( 'A356: the occurrence stays cancelled and its tickets revoked', 'cancelled' === Queries::occurrence( $a266_occ )->status && 0 === a266_valid( $a266_occ ) );
$a356_edit = EventsPage::save_occurrence( array_merge( $a266_base, array( 'location' => 'Linz' ) ) );
check( 'A356: details of a cancelled occurrence can still be corrected; the status stays cancelled', ! is_wp_error( $a356_edit ) && 'cancelled' === Queries::occurrence( $a266_occ )->status );
$a356_html = a266_render( array( 'view' => 'occurrence', 'occurrence_id' => $a266_occ ) );
check( 'A356: the form of a cancelled occurrence explains that it cannot be scheduled again', false === strpos( $a356_html, 'id="wpcap-occ-status"' ) && false !== strpos( $a356_html, 'nicht wieder' ) );

echo "A267: event cancellation has a confirmation page\n";
$a267_event = ex_event( 'A267 Festival' );
$a267_o1 = ex_occ( $a267_event, 0, 300 );
$a267_o2 = ex_occ( $a267_event, 0, 330 );
$a267_t1 = ex_type( $a267_o1, 'Tag 1', 1000 );
$a267_t2 = ex_type( $a267_o2, 'Tag 2', 1000 );
list( $a267_order ) = ex_buy( $a267_t1, 2, 'a267@example.com' );
ex_buy( $a267_t2, 1, 'a267b@example.com' );
$a267_list = a266_render( array() );
check( 'A267: the event list links to the confirmation page instead of a window.confirm form', false !== strpos( $a267_list, 'view=cancel_event&amp;event_id=' . $a267_event ) || false !== strpos( $a267_list, 'view=cancel_event&event_id=' . $a267_event ) );
check( 'A267: no window.confirm for the event cancellation is left', false === strpos( $a267_list, 'window.confirm' ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/EventsPage.php' ), 'window.confirm' ) );
$a267_pv = EventsPage::event_cancellation_preview( $a267_event );
check( 'A267: preview counts occurrences, tickets, paid orders and recipients', 2 === $a267_pv['occurrences'] && 3 === $a267_pv['tickets'] && 2 === $a267_pv['paid_orders'] && 2 === $a267_pv['recipients'] );
$a267_page = a266_render( array( 'view' => 'cancel_event', 'event_id' => $a267_event ) );
check( 'A267: the confirmation page lists the consequences and asks for reason and confirmation', false === strpos( $a267_page, 'EXCEPTION' ) && false !== strpos( $a267_page, '3 gültige Tickets werden widerrufen' ) && false !== strpos( $a267_page, 'name="reason"' ) && false !== strpos( $a267_page, 'name="confirm"' ) && false !== strpos( $a267_page, 'wpcap_cancel_event' ) );
$a267_no_reason = EventsPage::cancel_event( array( 'event_id' => $a267_event, 'confirm' => '1' ) );
$a267_no_confirm = EventsPage::cancel_event( array( 'event_id' => $a267_event, 'reason' => 'Unwetter' ) );
check( 'A267: cancelling needs a reason and the confirmation; nothing happens without', is_wp_error( $a267_no_reason ) && 'wpcap_cancel_reason' === $a267_no_reason->get_error_code() && is_wp_error( $a267_no_confirm ) && 'wpcap_cancel_confirm' === $a267_no_confirm->get_error_code() && 'published' === Queries::event( $a267_event )->status && 3 === a266_valid( $a267_o1 ) + a266_valid( $a267_o2 ) );
$a267_done = EventsPage::cancel_event( array( 'event_id' => $a267_event, 'reason' => 'Unwetterwarnung', 'confirm' => '1' ) );
check( 'A267: with reason and confirmation the event is cancelled', ! is_wp_error( $a267_done ) && 'cancelled' === Queries::event( $a267_event )->status && 0 === a266_valid( $a267_o1 ) + a266_valid( $a267_o2 ) );
check( 'A267: the reason is in the audit log', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_audit_logs WHERE action='event_cancelled' AND object_id=%d AND details LIKE %s", $a267_event, '%Unwetterwarnung%' ) ) );
