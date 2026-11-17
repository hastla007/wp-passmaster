<?php
/**
 * A297: a failed admin form keeps its input (create event, event data, date, ticket type) and the
 * error names the field, which the form marks (aria-invalid). Only these forms store input; nonces
 * and secret-like fields never.
 * Included from run.php (uses check(), make_type()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;

require_once __DIR__ . '/zz_r6_stubs.inc';

global $wpdb;
$p = $wpdb->prefix;
echo "A297: failed admin forms keep the input and name the field\n";
$GLOBALS['wpcap_test_transients'] = array();
$a297_key = Forms::OLD_INPUT . get_current_user_id();
$a297_render = static function ( array $get ) {
	$_GET = array( 'page' => 'pass-captain' ) + $get;
	ob_start();
	try { EventsPage::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
	$html = ob_get_clean();
	$_GET = array();
	return $html;
};
$a297_post = static function ( $handler, array $post ) {
	$_POST = $post + array( '_wpnonce' => 'n', '_wp_http_referer' => '/x' );
	$url = '';
	try { call_user_func( array( EventsPage::class, $handler ) ); } catch ( Wpcap_Test_Redirect $e ) { $url = $e->getMessage(); }
	$_POST = array();
	return $url;
};

// --- Validation names the field ---------------------------------------------------------------------
$a297_base = array( 'name' => 'A297 Gala', 'description' => '<p>Abend</p>', 'starts_at' => gmdate( 'Y-m-d\TH:i', time() + 20 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Saal', 'ticket_name' => 'Parkett', 'price' => '12,50', 'currency' => 'EUR', 'tax_rate' => '10', 'status' => 'draft' );
$a297_err = EventsPage::create_event( array( 'name' => '' ) + $a297_base );
check( 'empty event name: message names the field, error data carries it', is_wp_error( $a297_err ) && false !== strpos( $a297_err->get_error_message(), 'Eventnamen' ) && 'name' === ( $a297_err->get_error_data()['field'] ?? '' ) );
$a297_err = EventsPage::create_event( array( 'price' => 'zwölf' ) + $a297_base );
check( 'invalid price: field price', is_wp_error( $a297_err ) && 'price' === ( $a297_err->get_error_data()['field'] ?? '' ) && false !== strpos( $a297_err->get_error_message(), 'Preis' ) );
$a297_err = EventsPage::create_event( array( 'ticket_name' => '' ) + $a297_base );
check( 'missing ticket category name is named (no generic "Bitte prüfe die Eventdaten")', is_wp_error( $a297_err ) && 'ticket_name' === ( $a297_err->get_error_data()['field'] ?? '' ) && false === strpos( $a297_err->get_error_message(), 'Bitte prüfe' ) );
$a297_err = EventsPage::create_event( array( 'ends_at' => gmdate( 'Y-m-d\TH:i', time() + 19 * DAY_IN_SECONDS ) ) + $a297_base );
check( 'end before start: field ends_at', is_wp_error( $a297_err ) && 'ends_at' === ( $a297_err->get_error_data()['field'] ?? '' ) );

// --- Create form: input comes back after the redirect -----------------------------------------------
$a297_url = $a297_post( 'handle_create_event', array( 'action' => 'wpcap_create_event', 'price' => 'zwölf', 'stripe_secret_key' => 'sk_test_never' ) + $a297_base );
$a297_stored = $GLOBALS['wpcap_test_transients'][ $a297_key ] ?? array();
check( 'failed create keeps the input (without nonce, referer and secret-like fields) for the user', 'wpcap_create_event' === ( $a297_stored['action'] ?? '' ) && 'A297 Gala' === ( $a297_stored['input']['name'] ?? '' ) && 'price' === ( $a297_stored['field'] ?? '' ) && ! isset( $a297_stored['input']['_wpnonce'], $a297_stored['input']['_wp_http_referer'], $a297_stored['input']['stripe_secret_key'], $a297_stored['input']['action'] ) && false !== strpos( $a297_url, 'page=pass-captain' ) );
$a297_list = $a297_render( array() );
check( 'create form shows the input again and marks the price field', false === strpos( $a297_list, 'EXCEPTION' ) && false !== strpos( $a297_list, 'id="wpcap-name" name="name" type="text" class="regular-text" required maxlength="190" value="A297 Gala"' ) && false !== strpos( $a297_list, 'value="zwölf" required size="10" aria-invalid="true" autofocus' ) && false !== strpos( $a297_list, '&lt;p&gt;Abend&lt;/p&gt;</textarea>' ) && false !== strpos( $a297_list, 'value="Parkett"' ) );
check( 'the kept input is used once', false === get_transient( $a297_key ) && false === strpos( $a297_render( array() ), 'value="A297 Gala"' ) );

// Other forms never store input.
$_POST = array( 'action' => 'wpcap_correct_attendee', 'attendee_name' => 'Privat' );
Forms::remember_input( new WP_Error( 'x', 'y' ) );
$_POST = array();
check( 'forms that do not show input again store nothing (e.g. attendee correction)', false === get_transient( $a297_key ) );

// --- Ticket type: only the submitted form instance gets the input ------------------------------------
$a297_type = make_type( 10, 10, 2500 );
$a297_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $a297_type ) );
$a297_post( 'handle_save_ticket_type', array( 'action' => 'wpcap_save_ticket_type', 'occurrence_id' => $a297_occ, 'ticket_type_id' => 0, 'name' => 'Loge A297', 'price' => '30', 'currency' => 'EUR', 'tax_rate' => '0', 'max_per_order' => '500', 'capacity' => '8', 'status' => 'active' ) );
$a297_occ_html = $a297_render( array( 'view' => 'occurrence', 'occurrence_id' => $a297_occ ) );
check( 'new ticket type form on the date page keeps the input and marks "Max. pro Bestellung"', false === strpos( $a297_occ_html, 'EXCEPTION' ) && false !== strpos( $a297_occ_html, 'value="Loge A297"' ) && false !== strpos( $a297_occ_html, 'name="max_per_order" type="number" min="1" max="100" value="500" aria-invalid="true" autofocus' ) );
$a297_post( 'handle_save_ticket_type', array( 'action' => 'wpcap_save_ticket_type', 'occurrence_id' => $a297_occ, 'ticket_type_id' => $a297_type, 'name' => '', 'price' => '25', 'currency' => 'EUR', 'status' => 'active' ) );
$a297_other = $a297_render( array( 'view' => 'occurrence', 'occurrence_id' => $a297_occ ) );
check( 'input of the edit form of another type is not shown in the "add" form', false === strpos( $a297_other, 'aria-invalid' ) );
$a297_type_html = $a297_render( array( 'view' => 'ticket_type', 'ticket_type_id' => $a297_type ) );
check( 'edit form of that type shows the empty name marked', false !== strpos( $a297_type_html, 'id="wpcap-tt-name" name="name" type="text" class="regular-text" required maxlength="190" value="" aria-invalid="true"' ) );

// --- Event data form ---------------------------------------------------------------------------------
$a297_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $a297_occ ) );
$a297_post( 'handle_update_event', array( 'action' => 'wpcap_update_event', 'event_id' => $a297_event, 'name' => '', 'description' => 'Neuer Text', 'status' => 'draft' ) );
$a297_ev = $a297_render( array( 'view' => 'event', 'event_id' => $a297_event ) );
check( 'event data form keeps the new description and marks the empty name', false !== strpos( $a297_ev, '>Neuer Text</textarea>' ) && false !== strpos( $a297_ev, 'id="wpcap-ev-name" name="name" type="text" class="regular-text" required maxlength="190" value="" aria-invalid="true"' ) );

$GLOBALS['wpcap_test_transients'] = array();
