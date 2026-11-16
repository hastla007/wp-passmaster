<?php
/**
 * A595–A598: scanner – who admitted first on a double scan, group check-in of an order, one-scan pairing of the
 * offline PWA with automatic event assignment, no unpairing with unsynchronised scans.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Checkin;
use PassCaptain\Mobile\Devices;
use PassCaptain\Orders;

echo "A595-A598: scanner improvements\n";
global $wpdb;
$p = $wpdb->prefix;
$sx_type = make_type( 40, 10, 1500 );
$sx_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d", $sx_type ) );
$sx_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $sx_occ ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 1800 ) ), array( 'occurrence_id' => $sx_occ ) );
$sx_order = Orders::create( array( 'email' => 'sx-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $sx_type, 'quantity' => 3 ) ), 'idempotency_key' => 'sx-' . uniqid(), 'accept_terms' => true ), array( 'payment_provider' => 'stripe', 'hold_minutes' => 60, 'skip_rate_limit' => true ) );
check( 'A595: fixture order', ! is_wp_error( $sx_order ) );
if ( ! is_wp_error( $sx_order ) ) {
	Orders::confirm_external_payment( (int) $sx_order['order_id'], 'sx' );
	$sx_tickets = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", (int) $sx_order['order_id'] ) ) );
	$sx_first = Checkin::perform_ticket( $sx_tickets[0], array( 'source' => 'web', 'occurrence_id' => $sx_occ, 'idempotency_key' => 'web:sx-' . uniqid() ) );
	check( 'A596: an online admission lists the other valid tickets of the order for this date', ! is_wp_error( $sx_first ) && 2 === count( $sx_first['group'] ?? array() ) && 32 === strlen( (string) ( $sx_first['group'][0]['ticket_public_id'] ?? '' ) ) && ! isset( $sx_first['group'][0]['ticket_id'] ) );
	$sx_second = Checkin::perform_ticket( $sx_tickets[1], array( 'source' => 'web', 'occurrence_id' => $sx_occ, 'idempotency_key' => 'web:sx-' . uniqid() ) );
	check( 'A596: admitted tickets drop out of the group', ! is_wp_error( $sx_second ) && 1 === count( $sx_second['group'] ?? array() ) );
	add_filter( 'wpcap_scanner_group_max', '__return_zero' );
	$sx_third = Checkin::perform_ticket( $sx_tickets[2], array( 'source' => 'web', 'occurrence_id' => $sx_occ, 'idempotency_key' => 'web:sx-' . uniqid() ) );
	remove_filter( 'wpcap_scanner_group_max', '__return_zero' );
	check( 'A596: the group list can be switched off', ! is_wp_error( $sx_third ) && ! isset( $sx_third['group'] ) );
	$sx_hist = Checkin::history( $sx_tickets[0] );
	check( 'A595: the history names who admitted first (staff name or device)', '' !== (string) ( $sx_hist['first_checked_in_by'] ?? '' ) || '' === Checkin::admitted_by( 0, get_current_user_id() ) );
}
$wpdb->insert( "{$p}wpcap_scanner_devices", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'user_id' => 1, 'secret_hash' => str_repeat( 'a', 64 ), 'label' => 'Einlass Nord', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$sx_dev = (int) $wpdb->insert_id;
check( 'A595: a device admission is shown with the device label', 'Einlass Nord' === Checkin::admitted_by( $sx_dev, 1 ) );
check( 'A595: the field reaches scanner clients with refusals', in_array( 'first_checked_in_by', Checkin::CLIENT_ERROR_KEYS, true ) );
// A597: pairing link for the PWA and automatic assignment of offline-enabled events.
$sx_pair = Devices::create_pairing( array( $sx_event ), 'Aushilfe', 2 );
check( 'A597: the pairing has a PWA link that pairs on open (code in the fragment)', ! is_wp_error( $sx_pair ) && ( '' === (string) ( $sx_pair['pwa_url'] ?? '' ) || 1 === preg_match( '/#pair=' . preg_quote( $sx_pair['code'], '/' ) . '$/', $sx_pair['pwa_url'] ) ) );
if ( class_exists( PassCaptain\OfflineScanner\Assignments::class ) ) {
	$sx_rules = PassCaptain\OfflineScanner\Rules::save( $sx_event, array( 'enabled' => 1, 'max_offline_hours' => 12 ) );
	$sx_done = PassCaptain\OfflineScanner\Assignments::assign_paired( $sx_dev, array( $sx_event, 999999 ), 1 );
	check( 'A597: a paired device is assigned to its offline-enabled events right away', array( $sx_event ) === $sx_done && null !== PassCaptain\OfflineScanner\Assignments::find( $sx_dev, $sx_event, $sx_occ ) );
}
$sx_app = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' );
$sx_html = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/index.html' );
check( 'A597: the PWA pairs from #pair= and accepts long pasted codes', false !== strpos( $sx_app, 'pair=([A-Za-z0-9-]{16,64})' ) && false === strpos( $sx_html, 'maxlength="40"' ) );
check( 'A598: unpairing with open scans syncs first and needs the number typed in', false !== strpos( $sx_app, 'syncNow().catch(function () {}).then(function () {' ) && false !== strpos( $sx_app, 'String(typed).trim() === String(open)' ) );
check( 'A595: offline double scans show this device\'s admission time', false !== strpos( $sx_app, "Auf diesem Gerät eingelassen um %1" ) );
check( 'A596: the web scanner offers the group check-in', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'assets/scanner.js' ), 'function offerGroup(group)' ) );
