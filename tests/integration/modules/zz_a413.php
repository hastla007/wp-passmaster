<?php
// phpcs:ignoreFile
/**
 * A413: the audit log filters by days of the site time zone, shows the time in that zone, names the
 * actions in plain language (code kept) and links orders, tickets, events, occurrences and users.
 */

use PassCaptain\Admin\AuditLogPage;

if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }

echo "A413: audit log in site time, readable actions, object links\n";
global $wpdb;
$a413_saved_tz = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
check( 'A413: a filter day starts at 00:00 site time (22:00 UTC the day before in summer)', '2026-06-30 22:00:00' === AuditLogPage::utc_bound( '2026-07-01' ) && '2026-07-01 22:00:00' === AuditLogPage::utc_bound( '2026-07-01', true ) );
check( 'A413: a DST day has its real length', '2026-03-28 23:00:00' === AuditLogPage::utc_bound( '2026-03-29' ) && '2026-03-29 22:00:00' === AuditLogPage::utc_bound( '2026-03-29', true ) );
$a413_action = 'x4_a413_' . substr( md5( uniqid() ), 0, 6 );
foreach ( array( '2026-06-30 21:30:00', '2026-06-30 22:30:00', '2026-07-01 21:59:59', '2026-07-01 22:00:00' ) as $a413_at ) {
	$wpdb->insert( "{$wpdb->prefix}wpcap_audit_logs", array( 'user_id' => 0, 'action' => $a413_action, 'object_type' => 'order', 'object_id' => 7, 'details' => '{}', 'created_at' => $a413_at ) );
}
$a413_res = AuditLogPage::query( array( 'action' => $a413_action, 'date_from' => '2026-07-01', 'date_to' => '2026-07-01' ) );
check( 'A413: filtering 01.07. returns exactly the entries of that site-time day', 2 === $a413_res['total'] );
check( 'A413: known actions have a readable name, unknown ones keep the code', 'Ticket eingecheckt' === AuditLogPage::action_label( 'ticket_checked_in' ) && 'Termin abgesagt' === AuditLogPage::action_label( 'occurrence_cancelled' ) && 'x_unknown' === AuditLogPage::action_label( 'x_unknown' ) );
$a413_html = AuditLogPage::row_cells( (object) array( 'created_at' => '2026-07-01 10:00:00', 'user_id' => 0, 'action' => 'order_refunded', 'object_type' => 'order', 'object_id' => 7 ) );
check( 'A413: the row shows site time, the action name with its code and links the order', false !== strpos( $a413_html, '<td>01.07.2026 12:00</td>' ) && false !== strpos( $a413_html, 'Bestellung erstattet<br><code>order_refunded</code>' ) && false !== strpos( $a413_html, 'order_id=7">Bestellung #7</a>' ) );
$a413_type = make_type( 1 );
$a413_occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $a413_type ) );
$a413_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $a413_occ ) );
check( 'A413: occurrences link to their event, tickets to the ticket search, users to their profile', false !== strpos( AuditLogPage::object_url( 'occurrence', $a413_occ ), 'event_id=' . $a413_event ) && false !== strpos( AuditLogPage::object_url( 'ticket', 5 ), 'page=wpcap-tickets&s=5' ) && false !== strpos( AuditLogPage::object_url( 'user', 3 ), 'user-edit.php?user_id=3' ) && '' === AuditLogPage::object_url( 'settings', 1 ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s", $a413_action ) );
if ( null === $a413_saved_tz ) { unset( $GLOBALS['wpcap_test_options']['timezone_string'] ); } else { $GLOBALS['wpcap_test_options']['timezone_string'] = $a413_saved_tz; }
