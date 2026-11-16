<?php
/**
 * A341: the waitlist admin shows the date (Termin) of each entry and explains the queue columns
 * ("Platz in der Warteschlange" = who gets the next offer, "Anmeldung Nr." = number from the
 * confirmation mail); orders appear with the short order number.
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Frontend\Format;
use PassCaptain\Orders;
use PassCaptain\Waitlists;
use PassCaptain\Waitlists\AdminPage as WaitlistAdmin;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $r = $a == $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $t = '' ) { echo '<button type="submit">' . esc_html( $t ) . '</button>'; } }

echo "A341: waitlist admin with date column and clear queue labels\n";

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$a341_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A341 Lesereihe', 'slug' => 'a341-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a341_now, 'updated_at' => $a341_now ) );
$a341_event = (int) $wpdb->insert_id;
$a341_types = array();
foreach ( array( '2031-03-05 18:30:00', '2031-03-12 18:30:00' ) as $a341_start ) {
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a341_event, 'starts_at' => $a341_start, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$a341_occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $a341_occ, 'name' => 'Standard', 'price_minor' => 1000, 'currency' => 'EUR', 'capacity' => 1, 'max_per_order' => 4, 'status' => 'active' ) );
	$a341_types[] = array( (int) $wpdb->insert_id, $a341_occ );
}
// Two entries on the first date (positions 1, 2), one on the second; the second date's entry has a pending order.
$a341_rows = array( array( 0, 1, 'a341-a@example.com', 0 ), array( 0, 2, 'a341-b@example.com', 0 ), array( 1, 1, 'a341-c@example.com', 0 ) );
foreach ( $a341_rows as $a341_r ) {
	$wpdb->insert( "{$p}wpcap_waitlist", array( 'event_id' => $a341_event, 'occurrence_id' => $a341_types[ $a341_r[0] ][1], 'ticket_type_id' => $a341_types[ $a341_r[0] ][0], 'email_hash' => hash( 'sha256', $a341_r[2] ), 'email' => $a341_r[2], 'position' => $a341_r[1], 'quantity' => 1, 'status' => 'waiting', 'created_at' => $a341_now, 'updated_at' => $a341_now ) );
}

$a341_entries = Waitlists::entries( $a341_event );
check( 'entries carry the date and its timezone', 3 === count( $a341_entries ) && '2031-03-05 18:30:00' === $a341_entries[0]->starts_at && 'Europe/Vienna' === $a341_entries[0]->timezone );

$_GET = array( 'page' => WaitlistAdmin::PAGE, 'event_id' => $a341_event );
ob_start();
try { WaitlistAdmin::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a341_html = ob_get_clean();
$_GET = array();
check( 'table has a "Termin" column with each entry\'s date (both dates of the event)', false !== strpos( $a341_html, '>Termin</th>' ) && false !== strpos( $a341_html, Orders::format_start( '2031-03-05 18:30:00', 'Europe/Vienna' ) ) && false !== strpos( $a341_html, Orders::format_start( '2031-03-12 18:30:00', 'Europe/Vienna' ) ) && false === strpos( $a341_html, 'EXCEPTION' ) );
check( 'queue columns are named clearly and explained', false !== strpos( $a341_html, 'Platz in der Warteschlange' ) && false !== strpos( $a341_html, 'Anmeldung Nr.' ) && false !== strpos( $a341_html, 'als Nächstes' ) && false === strpos( $a341_html, '>Rang</th>' ) && false === strpos( $a341_html, '>Position</th>' ) );
check( 'old labels gone from the source', false === strpos( file_get_contents( WPCAP_PATH . 'src/Waitlists/AdminPage.php' ), "__( 'Rang', 'pass-captain' )" ) );

// An entry with an order shows the short order number linked to the order.
$GLOBALS['wpcap_test_transients'] = array();
$a341_o = Orders::create( array( 'email' => 'a341-c@example.com', 'items' => array( array( 'ticket_type_id' => $a341_types[1][0], 'quantity' => 1 ) ), 'idempotency_key' => 'a341-' . uniqid( '', true ) ) );
$wpdb->update( "{$p}wpcap_waitlist", array( 'order_id' => (int) $a341_o['order_id'], 'status' => 'offered', 'offer_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( 'ticket_type_id' => $a341_types[1][0] ) );
$_GET = array( 'page' => WaitlistAdmin::PAGE, 'event_id' => $a341_event );
ob_start();
try { WaitlistAdmin::render(); } catch ( Throwable $e ) { echo 'EXCEPTION ' . $e->getMessage(); }
$a341_html = ob_get_clean();
$_GET = array();
check( 'order column: short order number as in the mails, linked to the order', false !== strpos( $a341_html, Format::order_reference( Orders::get( $a341_o['order_id'] )->public_id ) ) && false !== strpos( $a341_html, 'order_id=' . (int) $a341_o['order_id'] ) );
// Clean up: release the hold of the test order.
Orders::cancel_order( (int) $a341_o['order_id'], 'a341' );
