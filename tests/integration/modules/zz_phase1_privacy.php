<?php
/**
 * Privacy fixes: retention cron, complete eraser/exporter, privacy policy text, affiliate consent,
 * scanner offline queue context, partner API placeholders, keyed rate-limit hashes.
 * Included from run.php after all module tests (uses check(), make_type()).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\Privacy\PolicyText;
use PassCaptain\Privacy\Retention;
use PassCaptain\Settings;
use PassCaptain\Integrations\AffiliateCaptain\Adapter as P1pAffiliate;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_transients'] = array();
Settings::update( array( 'require_terms' => 0, 'terms_text' => '', 'attendee_retention_days' => 0 ) );

// Own stubs only where no earlier test defined the function; checks depending on them are skipped otherwise.
$p1p_own_cron = ! function_exists( 'wp_schedule_single_event' ) && ! function_exists( 'wp_next_scheduled' ) && ! function_exists( 'wp_schedule_event' );
$p1p_own_assets = ! function_exists( 'wp_add_inline_script' ) && ! function_exists( 'wp_script_is' );
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['p1p_scheduled'][] = array( $ts, $hook, $args ); return true; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook ) { foreach ( $GLOBALS['p1p_scheduled'] ?? array() as $e ) { if ( $e[1] === $hook ) { return $e[0]; } } return false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['p1p_scheduled'][] = array( $ts, $hook, $args, $rec ); return true; } }
if ( ! function_exists( 'wp_script_is' ) ) { function wp_script_is( $h, $l = 'enqueued' ) { return 'wpcap-scanner' === $h; } }
if ( ! function_exists( 'wp_add_inline_script' ) ) { function wp_add_inline_script( $h, $js, $pos = 'after' ) { $GLOBALS['p1p_inline'][] = array( $h, $js, $pos ); return true; } }
if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { function wp_add_privacy_policy_content( $name, $html ) { $GLOBALS['p1p_policy'][ $name ] = $html; } }

/** Moves the occurrence of $type to $days_ago days ago (ends_at NULL → starts_at + 1 day counts). Orders are created before. */
function p1p_to_past( $type, $days_ago, $with_end = true ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$type" );
	$start = time() - ( $days_ago * 86400 ) - 3 * 3600;
	$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $with_end ? $start : $start - 86400 ), 'ends_at' => $with_end ? gmdate( 'Y-m-d H:i:s', $start + 2 * 3600 ) : null ), array( 'occurrence_id' => $occ ) );
}
function p1p_field( $type, $key, $days ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_participant_fields", array( 'ticket_type_id' => $type, 'field_key' => $key, 'label' => ucfirst( $key ), 'field_type' => 'text', 'required' => 0, 'purpose' => 'Namensschild', 'retention_days' => $days, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}
/** Free order with one attendee per entry: array( name, email, answers ). */
function p1p_order( $type, $email, array $attendees ) {
	$list = array();
	foreach ( $attendees as $a ) { $list[] = array( 'ticket_type_id' => $type, 'name' => $a[0], 'email' => $a[1], 'answers' => $a[2] ?? array() ); }
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => count( $attendees ) ) ), 'idempotency_key' => 'p1p-' . uniqid( '', true ), 'attendees' => $list ) );
}
function p1p_tickets( $order_id ) { return array_map( static fn( $t ) => (int) $t->ticket_id, Orders::tickets_for_order( $order_id ) ); }
function p1p_export_values( array $export ) {
	$out = array();
	foreach ( $export['data'] as $item ) { foreach ( $item['data'] as $row ) { $out[] = $row['name'] . '=' . $row['value']; } }
	return implode( "\n", $out );
}

echo "Privacy: retention cron\n";
// Answers: per-field retention (setting 0). Field A 10 days, field B 400 days; event ended 30 days ago.
$rt = make_type( 20, 10, 0 );
$fa = p1p_field( $rt, 'firma', 10 );
$fb = p1p_field( $rt, 'diaet', 400 );
$ro = p1p_order( $rt, 'p1p-ret@example.com', array( array( 'Rita', 'rita@example.com', array( 'firma' => 'ACME', 'diaet' => 'vegan' ) ) ) );
p1p_to_past( $rt, 30 );
$rtid = p1p_tickets( $ro['order_id'] )[0];
// Upcoming event: nothing may be touched.
$ft = make_type( 20, 10, 0 );
p1p_field( $ft, 'firma', 0 );
$fo = p1p_order( $ft, 'p1p-future@example.com', array( array( 'Fred', 'fred@example.com', array( 'firma' => 'Zukunft' ) ) ) );
$ftid = p1p_tickets( $fo['order_id'] )[0];
// Old event (400 days, without ends_at) for attendee names.
$old = make_type( 20, 10, 0 );
$oo = p1p_order( $old, 'p1p-old@example.com', array( array( 'Olga', 'olga@example.com' ) ) );
p1p_to_past( $old, 400, false );
$otid = p1p_tickets( $oo['order_id'] )[0];
$wpdb->insert( "{$p}wpcap_offline_entries", array( 'occurrence_id' => (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$old" ), 'token_hash' => hash( 'sha256', 'p1p-old' ), 'ticket_id' => $otid, 'ticket_type_id' => $old, 'status' => 'valid', 'attendee_name' => 'Olga', 'fingerprint' => str_repeat( 'a', 32 ), 'version' => 1 ) );
$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $otid, 'provider' => 'apple', 'serial_number' => 'p1p-old-serial', 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_wallet_registrations", array( 'device_library_id' => 'dev-old', 'pass_type_id' => 'pass.test', 'serial_number' => 'p1p-old-serial', 'push_token' => 'push-old', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
// Unpaid orders: expired 10 days ago with attendee data, never paid 100 days ago.
$GLOBALS['wpcap_test_transients'] = array();
$paid_type = make_type( 20, 10, 1500 );
$u1 = Orders::create( array( 'email' => 'p1p-unpaid@example.com', 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p1p-u1', 'attendees' => array( array( 'ticket_type_id' => $paid_type, 'name' => 'Uwe' ) ) ) );
$u2 = Orders::create( array( 'email' => 'p1p-never@example.com', 'items' => array( array( 'ticket_type_id' => $paid_type, 'quantity' => 1 ) ), 'idempotency_key' => 'p1p-u2' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET status='expired',payment_status='expired',updated_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 10 * 86400 ), $u1['order_id'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET status='expired',payment_status='expired',updated_at=%s,created_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 100 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 100 * 86400 ), $u2['order_id'] ) );
// Messages, check-ins, audit logs.
$old_ts = gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 );
$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', 'p1p-m1' ), 'recipient' => 'p1p-msg@example.com', 'template_key' => 'x', 'payload' => '{"name":"Max"}', 'send_at' => $old_ts, 'status' => 'sent', 'created_at' => $old_ts ) );
$m1 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', 'p1p-m2' ), 'recipient' => 'p1p-msg@example.com', 'template_key' => 'x', 'payload' => '{"name":"Max"}', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'status' => 'queued', 'created_at' => $old_ts ) );
$m2 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', 'p1p-m3' ), 'recipient' => 'p1p-msg@example.com', 'template_key' => 'x', 'payload' => '{}', 'send_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 ), 'status' => 'sent', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 ) ) );
$m3 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $otid, 'event_id' => 1, 'user_id' => 7, 'result' => 'checked_in', 'device_id' => 3, 'source' => 'app', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 380 * 86400 ) ) );
$c1 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'p1p_old', 'object_type' => 'test', 'object_id' => 0, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 800 * 86400 ) ) );
$a1 = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'p1p_new', 'object_type' => 'test', 'object_id' => 0, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * 86400 ) ) );
$a2 = (int) $wpdb->insert_id;

$counts = Retention::run();
$answers = static fn( $tid ) => $wpdb->get_col( "SELECT answer FROM {$p}wpcap_participant_answers WHERE ticket_id=$tid ORDER BY answer" );
check( 'retention: answers past their field retention are deleted, longer ones kept', array( 'vegan' ) === $answers( $rtid ) && $counts['answers'] >= 1 );
check( 'retention: upcoming events are untouched', array( 'Zukunft' ) === $answers( $ftid ) && 'Fred' === $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$ftid" ) );
check( 'retention: names within the default 365 days stay', 'Rita' === $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$rtid" ) && 'rita@example.com' === $wpdb->get_var( "SELECT attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=$rtid" ) );
$oa = $wpdb->get_row( "SELECT attendee_name,attendee_email FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$otid" );
check( 'retention: attendee name/e-mail cleared after 365 days (start + 1 day without ends_at)', '' === $oa->attendee_name && '' === $oa->attendee_email && '' === $wpdb->get_var( "SELECT attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=$otid" ) );
check( 'retention: offline package names and wallet registrations of old tickets removed', '' === $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_offline_entries WHERE ticket_id=$otid" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='p1p-old-serial'" ) );
check( 'retention: ticket and order of the old event are kept', 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$otid" ) && 'p1p-old@example.com' === Orders::get( $oo['order_id'] )->customer_email );
check( 'retention: attendee data of expired unpaid orders cleared', null === Orders::get( $u1['order_id'] )->attendee_data && 'p1p-unpaid@example.com' === Orders::get( $u1['order_id'] )->customer_email );
check( 'retention: never-paid order older than 90 days pseudonymised (deterministic placeholder)', Privacy::replacement_email( 'p1p-never@example.com', $u2['order_id'] ) === Orders::get( $u2['order_id'] )->customer_email );
$mr = static fn( $id ) => $wpdb->get_row( "SELECT recipient,payload,status FROM {$p}wpcap_message_queue WHERE message_id=$id" );
check( 'retention: sent messages lose recipient/content after 30 days, queued ones stay', '' === $mr( $m1 )->recipient && '{}' === $mr( $m1 )->payload && 'p1p-msg@example.com' === $mr( $m2 )->recipient && null === $mr( $m3 ) );
check( 'retention: check-ins older than 365 days lose staff user and device', '0' === (string) $wpdb->get_var( "SELECT user_id FROM {$p}wpcap_checkins WHERE checkin_id=$c1" ) && '0' === (string) $wpdb->get_var( "SELECT device_id FROM {$p}wpcap_checkins WHERE checkin_id=$c1" ) && 'checked_in' === $wpdb->get_var( "SELECT result FROM {$p}wpcap_checkins WHERE checkin_id=$c1" ) );
check( 'retention: audit logs older than two years deleted, newer kept', null === $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE audit_id=$a1" ) && null !== $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE audit_id=$a2" ) );
$log = $wpdb->get_row( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='privacy_retention' ORDER BY audit_id DESC LIMIT 1" );
check( 'retention: audit entry holds counts only', $log && ! preg_match( '/@|Rita|Olga|ACME/', $log->details ) && isset( json_decode( $log->details, true )['answers'] ) );
// Global setting overrides the field setting.
Settings::update( array( 'attendee_retention_days' => 20 ) );
$counts2 = Retention::run();
check( 'retention: global setting (20 days) overrides field retention and clears names', array() === $answers( $rtid ) && '' === $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$rtid" ) && '' === $wpdb->get_var( "SELECT attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=$rtid" ) );
Settings::update( array( 'attendee_retention_days' => 0 ) );
$again = Retention::run();
check( 'retention: a second run finds nothing left', 0 === $again['answers'] && 0 === $again['attendees'] && 0 === $again['unpaid_customer_email'] );
// Batching and time budget.
$bt = make_type( 20, 10, 0 );
$bf = p1p_field( $bt, 'x', 1 );
$bo = p1p_order( $bt, 'p1p-batch@example.com', array( array( 'B', '' ) ) );
p1p_to_past( $bt, 30 );
$btid = p1p_tickets( $bo['order_id'] )[0];
for ( $i = 0; $i < 3; $i++ ) { p1p_field( $bt, 'y' . $i, 1 ); }
$fids = $wpdb->get_col( "SELECT field_id FROM {$p}wpcap_participant_fields WHERE ticket_type_id=$bt" );
foreach ( $fids as $fid ) { $wpdb->query( "INSERT IGNORE INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) VALUES ($btid,$fid,'v',NOW(),NOW())" ); }
$out = Retention::run( null, 0.000001 );
check( 'retention: an exhausted time budget stops early and reports incomplete', false === $out['complete'] );
Retention::run();
check( 'retention: the next run continues', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_participant_answers WHERE ticket_id=$btid" ) );
$GLOBALS['wpcap_test_hooks']['wpcap_privacy_retention_days'][10][] = array( static fn( $d, $step ) => 'audit_logs' === $step ? 0 : $d, 2 );
$wpdb->insert( "{$p}wpcap_audit_logs", array( 'user_id' => 1, 'action' => 'p1p_old2', 'object_type' => 'test', 'object_id' => 0, 'details' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 900 * 86400 ) ) );
$a3 = (int) $wpdb->insert_id;
Retention::run();
check( 'retention: a step can be disabled through the filter (0)', null !== $wpdb->get_var( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE audit_id=$a3" ) );
unset( $GLOBALS['wpcap_test_hooks']['wpcap_privacy_retention_days'] );
$GLOBALS['p1p_scheduled'] = array();
Retention::schedule();
Retention::schedule();
$daily = array_filter( $GLOBALS['p1p_scheduled'], static fn( $e ) => Retention::HOOK === $e[1] );
check( 'retention: daily event is scheduled once', ! $p1p_own_cron || 1 === count( $daily ) && 'daily' === ( array_values( $daily )[0][3] ?? '' ) );

echo "Privacy: exporter\n";
$GLOBALS['wpcap_test_transients'] = array();
$et = make_type( 20, 10, 1000 );
$ef = p1p_field( $et, 'firma', 365 );
$eo = Orders::create( array( 'email' => 'p1p-buyer@example.com', 'items' => array( array( 'ticket_type_id' => $et, 'quantity' => 2 ) ), 'idempotency_key' => 'p1p-exp', 'attendees' => array(
	array( 'ticket_type_id' => $et, 'name' => 'Ada Lovelace', 'email' => 'p1p-ada@example.com', 'answers' => array( 'firma' => 'Analytical' ) ),
	array( 'ticket_type_id' => $et, 'name' => 'Bob', 'answers' => array( 'firma' => 'Initech' ) ),
) ) );
Orders::confirm_manual_payment( $eo['order_id'] );
PassCaptain\Invoices::issue_for_order( $eo['order_id'] );
$etix = p1p_tickets( $eo['order_id'] );
$ada = (int) $wpdb->get_var( "SELECT ticket_id FROM {$p}wpcap_ticket_assignments WHERE attendee_name='Ada Lovelace' AND ticket_id IN (" . implode( ',', $etix ) . ')' );
$bob = (int) array_values( array_diff( $etix, array( $ada ) ) )[0];
$eocc = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$et" );
$eevent = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$eocc" );
$wpdb->insert( "{$p}wpcap_checkins", array( 'ticket_id' => $ada, 'event_id' => $eevent, 'user_id' => 1, 'result' => 'checked_in', 'source' => 'web', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_admission_areas", array( 'event_id' => $eevent, 'name' => 'Haupteingang P1P', 'status' => 'active', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$area = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_admission_entries", array( 'ticket_id' => $ada, 'occurrence_id' => $eocc, 'area_id' => $area, 'service_date' => gmdate( 'Y-m-d' ), 'direction' => 'in', 'entered_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $ada, 'provider' => 'apple', 'serial_number' => 'p1p-ada-serial', 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_wallet_registrations", array( 'device_library_id' => 'dev-ada', 'pass_type_id' => 'pass.test', 'serial_number' => 'p1p-ada-serial', 'push_token' => 'push-ada', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_wallet_passes", array( 'ticket_id' => $bob, 'provider' => 'apple', 'serial_number' => 'p1p-bob-serial', 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_wallet_registrations", array( 'device_library_id' => 'dev-bob', 'pass_type_id' => 'pass.test', 'serial_number' => 'p1p-bob-serial', 'push_token' => 'push-bob', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $eevent, 'name' => 'Workshopraum P1P', 'capacity' => 5, 'status' => 'active' ) );
$res = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $res, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 90000 ), 'capacity' => 5, 'status' => 'open' ) );
$slot = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $slot, 'order_id' => 0, 'customer_email' => 'p1p-buyer@example.com', 'idempotency_key' => hash( 'sha256', 'p1p-book' ), 'quantity' => 1, 'status' => 'booked', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$booking = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_message_queue", array( 'dedupe_key' => hash( 'sha256', 'p1p-msg-buyer' ), 'recipient' => 'p1p-buyer@example.com', 'template_key' => 'x', 'payload' => '{"name":"Ada Lovelace"}', 'send_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'status' => 'queued', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );

$exp = Privacy::export( 'p1p-buyer@example.com' );
$txt = p1p_export_values( $exp );
check( 'export: attendee names and participant answers', false !== strpos( $txt, 'Name des Teilnehmers=Ada Lovelace' ) && false !== strpos( $txt, 'Firma (Namensschild)=Analytical' ) && false !== strpos( $txt, 'Firma (Namensschild)=Initech' ) );
check( 'export: check-in history with time and area', false !== strpos( $txt, 'Haupteingang P1P' ) && false !== strpos( $txt, '"result":"checked_in"' ) );
check( 'export: wallet passes and registered devices', false !== strpos( $txt, 'p1p-ada-serial' ) && false !== strpos( $txt, '"registered_devices":1' ) );
check( 'export: invoice with buyer snapshot', false !== strpos( $txt, 'Käuferangaben auf der Rechnung=' ) && false !== strpos( $txt, 'p1p-buyer@example.com' ) );
check( 'export: service bookings', false !== strpos( $txt, 'Ressource=Workshopraum P1P' ) && $exp['done'] );
$aexp = Privacy::export( 'p1p-ada@example.com' );
$atxt = p1p_export_values( $aexp );
$groups = array_unique( array_map( static fn( $i ) => $i['group_id'], $aexp['data'] ) );
check( 'export: an attendee gets only their own ticket, not the buyer order', array( 'wp-pass-captain-tickets' ) === array_values( $groups ) && 1 === count( $aexp['data'] ) && false !== strpos( $atxt, 'Analytical' ) && false === strpos( $atxt, 'Initech' ) && false === strpos( $atxt, 'Bestellreferenz' ) );

echo "Privacy: eraser\n";
// Attendee-only erasure: buyer's tickets stay valid.
$revoked_seen = array();
add_action( 'wpcap_ticket_revoked', static function ( $id, $reason = '' ) use ( &$revoked_seen ) { $revoked_seen[] = array( (int) $id, $reason ); }, 99, 2 );
// These checks cover immediate anonymisation; deferral for upcoming dates is tested in zz_integration.
$GLOBALS['wpcap_test_filters']['wpcap_privacy_defer_upcoming'] = false;
$res_a = Privacy::erase( 'p1p-ada@example.com' );
check( 'erase attendee: name, e-mail, answers removed; ticket stays valid', '' === $wpdb->get_var( "SELECT attendee_name FROM {$p}wpcap_ticket_assignments WHERE ticket_id=$ada" ) && '' === $wpdb->get_var( "SELECT attendee_email FROM {$p}wpcap_tickets WHERE ticket_id=$ada" ) && array() === $answers( $ada ) && 'valid' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$ada" ) && array( 'Initech' ) === $answers( $bob ) );
check( 'erase attendee: wallet device registration removed, no revocation', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='p1p-ada-serial'" ) && ! $revoked_seen && $res_a['items_removed'] && ! $res_a['items_retained'] );
check( 'erase attendee: warns that the buyer\'s upcoming tickets stay valid', (bool) preg_grep( '/bleiben gültig/', $res_a['messages'] ) );
// Buyer erasure.
$GLOBALS['p1p_scheduled'] = array();
$bmsg = (int) $wpdb->get_var( "SELECT message_id FROM {$p}wpcap_message_queue WHERE recipient='p1p-buyer@example.com' ORDER BY message_id DESC LIMIT 1" );
$wpdb->query( "UPDATE {$p}wpcap_orders SET attendee_data='[{\"name\":\"Ada\"}]' WHERE order_id=" . (int) $eo['order_id'] );
$res_b = Privacy::erase( 'p1p-buyer@example.com' );
$eord = Orders::get( $eo['order_id'] );
$statuses = $wpdb->get_col( "SELECT status FROM {$p}wpcap_tickets WHERE order_id=" . (int) $eo['order_id'] );
$tokens = $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $eo['order_id'] );
check( 'erase buyer: order e-mail replaced by the deterministic placeholder, attendee_data cleared', Privacy::replacement_email( 'p1p-buyer@example.com', $eo['order_id'] ) === $eord->customer_email && null === $eord->attendee_data );
check( 'erase buyer: tickets revoked with distinct new token hashes (UNIQUE key)', array( 'revoked', 'revoked' ) === $statuses && 2 === count( array_unique( $tokens ) ) );
check( 'erase buyer: wpcap_ticket_revoked fired per ticket with reason', 2 === count( $revoked_seen ) && 'privacy_erasure' === $revoked_seen[0][1] && in_array( $bob, array_column( $revoked_seen, 0 ), true ) );
check( 'erase buyer: participant answers deleted', array() === $answers( $bob ) );
check( 'erase buyer: financial data kept', 2000 === (int) $eord->total_minor && 2 === (int) $wpdb->get_var( "SELECT SUM(quantity) FROM {$p}wpcap_order_items WHERE order_id=" . (int) $eo['order_id'] ) );
$inv = $wpdb->get_var( "SELECT buyer_snapshot FROM {$p}wpcap_invoices WHERE order_id=" . (int) $eo['order_id'] );
check( 'erase buyer: invoice buyer snapshot is retained and the message says so', false !== strpos( (string) $inv, 'p1p-buyer@example.com' ) && (bool) preg_grep( '/Käuferangaben auf Rechnungen wurden nicht entfernt/', $res_b['messages'] ) && (bool) preg_grep( '/Art\. 17 Abs\. 3 lit\. b/', $res_b['messages'] ) && $res_b['items_retained'] );
check( 'erase buyer: warns about tickets for upcoming events', (bool) preg_grep( '/bevorstehende Termine wurden durch die Löschung ungültig/', $res_b['messages'] ) );
check( 'erase buyer: service booking and queued message anonymised', 'p1p-buyer@example.com' !== $wpdb->get_var( "SELECT customer_email FROM {$p}wpcap_service_bookings WHERE booking_id=$booking" ) && Privacy::is_placeholder_email( $wpdb->get_var( "SELECT recipient FROM {$p}wpcap_message_queue WHERE message_id=$bmsg" ) ) && 'cancelled' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_message_queue WHERE message_id=$bmsg" ) && '{}' === $wpdb->get_var( "SELECT payload FROM {$p}wpcap_message_queue WHERE message_id=$bmsg" ) );
$purge = array_values( array_filter( $GLOBALS['p1p_scheduled'] ?? array(), static fn( $e ) => Privacy::WALLET_PURGE_HOOK === $e[1] ) );
check( 'erase buyer: wallet registrations kept for the revocation push, purge scheduled', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='p1p-bob-serial'" ) && ( ! $p1p_own_cron || ( $purge && in_array( $bob, $purge[0][2][0], true ) ) ) );
Privacy::purge_wallet_registrations( $etix );
check( 'erase buyer: the scheduled purge deletes the device registrations', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_wallet_registrations WHERE serial_number='p1p-bob-serial'" ) );
$again = Privacy::erase( 'p1p-buyer@example.com' );
check( 'erase buyer: second run finds nothing', ! $again['items_removed'] && $again['done'] );
$audit = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$p}wpcap_audit_logs WHERE action='privacy_erasure' AND object_id=%d ORDER BY audit_id DESC LIMIT 1", $eo['order_id'] ) );
check( 'erase buyer: audit entry without the address', $audit && false === strpos( $audit, '@' ) );
check( 'export after erasure finds nothing for the buyer', ! Privacy::export( 'p1p-buyer@example.com' )['data'] );
// Past checked-in ticket keeps its check-in status.
$pt = make_type( 20, 10, 0 );
$po = p1p_order( $pt, 'p1p-past@example.com', array( array( 'P', '' ) ) );
p1p_to_past( $pt, 5 );
$ptid = p1p_tickets( $po['order_id'] )[0];
$wpdb->query( "UPDATE {$p}wpcap_tickets SET status='checked_in' WHERE ticket_id=$ptid" );
$res_p = Privacy::erase( 'p1p-past@example.com' );
unset( $GLOBALS['wpcap_test_filters']['wpcap_privacy_defer_upcoming'] );
check( 'erase: checked-in history kept, no upcoming warning for past events', 'checked_in' === $wpdb->get_var( "SELECT status FROM {$p}wpcap_tickets WHERE ticket_id=$ptid" ) && ! preg_grep( '/bevorstehende/', $res_p['messages'] ) );

echo "Privacy: policy text\n";
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'wallet_apple_enabled' => 0, 'wallet_google_enabled' => 0, 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => '' ) ) ) );
$wpdb->query( "UPDATE {$p}wpcap_partners SET status='p1p_inactive' WHERE status='active'" );
$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='p1p_inactive' WHERE status='active'" );
$GLOBALS['wpac_test_version'] = '9.0.0';
$plain = PolicyText::content();
check( 'policy: legal bases, retention periods and rights', false !== strpos( $plain, 'Art. 6 Abs. 1 lit. b' ) && false !== strpos( $plain, 'lit. f' ) && false !== strpos( $plain, 'lit. c' ) && false !== strpos( $plain, '§ 132 BAO' ) && false !== strpos( $plain, '§ 147 AO' ) && false !== strpos( $plain, '§ 257 HGB' ) );
check( 'policy: disabled services are not listed', false === strpos( $plain, 'Stripe' ) && false === strpos( $plain, 'Twilio' ) && false === strpos( $plain, 'Apple' ) && false === strpos( $plain, 'Affiliate' ) && false === strpos( $plain, 'Drittländer' ) && false === strpos( $plain, 'Webhooks' ) );
Settings::update( array( 'enabled_providers' => array( 'manual', 'stripe', 'paypal', 'mollie' ), 'wallet_apple_enabled' => 1, 'wallet_google_enabled' => 1, 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => 'twilio' ) ), 'attendee_retention_days' => 45 ) );
$GLOBALS['wpac_test_version'] = '1.1.0';
$full = PolicyText::content();
check( 'policy: enabled services, third-country transfers and consent (lit. a) appear', false !== strpos( $full, 'Stripe' ) && false !== strpos( $full, 'PayPal' ) && false !== strpos( $full, 'Mollie' ) && false !== strpos( $full, 'Twilio' ) && false !== strpos( $full, 'Apple' ) && false !== strpos( $full, 'Google' ) && false !== strpos( $full, 'Affiliate' ) && false !== strpos( $full, 'Drittländer' ) && false !== strpos( $full, 'lit. a' ) && false !== strpos( $full, '45 Tage' ) );
$wpdb->query( "UPDATE {$p}wpcap_partners SET status='active' WHERE status='p1p_inactive'" );
$wpdb->query( "UPDATE {$p}wpcap_webhook_subscriptions SET status='active' WHERE status='p1p_inactive'" );
PolicyText::add();
check( 'policy: registered through wp_add_privacy_policy_content', ! isset( $GLOBALS['p1p_policy'] ) || isset( $GLOBALS['p1p_policy']['Pass Captain'] ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'wallet_apple_enabled' => 0, 'wallet_google_enabled' => 0, 'sms' => array_merge( (array) Settings::get( 'sms' ), array( 'provider' => '' ) ), 'attendee_retention_days' => 0 ) );

echo "Privacy: affiliate referral cookie needs consent\n";
$GLOBALS['wpcap_test_transients'] = array();
$aft = make_type( 50, 10, 1000 );
$aff_snap = static function ( $order_id ) use ( $wpdb, $p ) { return json_decode( (string) $wpdb->get_var( "SELECT price_snapshot FROM {$p}wpcap_orders WHERE order_id=" . (int) $order_id ), true ); };
$aff_buy = static function ( $payload = array() ) use ( $aft ) { $GLOBALS['wpcap_test_transients'] = array(); return Orders::create( array_merge( array( 'email' => 'p1p-aff' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $aft, 'quantity' => 1 ) ), 'idempotency_key' => 'p1p-aff-' . uniqid( '', true ) ), $payload ) ); };
$GLOBALS['wpac_test_touch'] = array( 'code' => 'NORD-1', 'method' => 'cookie', 'campaign' => 'herbst', 'content_id' => 0 );
$had_consent_api = function_exists( 'wp_has_consent' );
if ( ! $had_consent_api ) {
	$no_api = $aff_buy();
	check( 'affiliate: without a consent API the touch is read (operator gates the cookie)', 'cookie' === ( $aff_snap( $no_api['order_id'] )['affiliate_referral']['touch']['method'] ?? '' ) );
	function wp_has_consent( $category ) { return ! empty( $GLOBALS['p1p_consent'][ $category ] ); }
}
$GLOBALS['p1p_consent'] = array();
$denied = $aff_buy( array( 'partner_code' => 'nord-1' ) );
$ds = $aff_snap( $denied['order_id'] )['affiliate_referral'] ?? array();
check( 'affiliate: WP Consent API without marketing consent → cookie not read, typed code kept', ! isset( $ds['touch'] ) && 'NORD-1' === ( $ds['code'] ?? '' ) );
$GLOBALS['wpac_test_touch'] = array( 'code' => 'NORD-1', 'method' => 'link', 'campaign' => 'link-ohne-cookie', 'content_id' => 0 );
$link = $aff_buy();
check( 'affiliate: partner link of the shop session (no cookie) is kept without marketing consent', 'link-ohne-cookie' === ( $aff_snap( $link['order_id'] )['affiliate_referral']['touch']['campaign'] ?? '' ) );
$GLOBALS['wpac_test_touch'] = array( 'code' => 'NORD-1', 'method' => 'cookie', 'campaign' => 'herbst', 'content_id' => 0 );
$GLOBALS['p1p_consent'] = array( 'marketing' => true );
$granted = $aff_buy();
check( 'affiliate: with marketing consent the touch is stored', 'herbst' === ( $aff_snap( $granted['order_id'] )['affiliate_referral']['touch']['campaign'] ?? '' ) );
$GLOBALS['wpcap_test_filters']['wpcap_affiliate_tracking_allowed'] = false;
$filtered = $aff_buy();
check( 'affiliate: filter wpcap_affiliate_tracking_allowed can veto', ! isset( $aff_snap( $filtered['order_id'] )['affiliate_referral']['touch'] ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_affiliate_tracking_allowed'] );
$GLOBALS['p1p_consent'] = array( 'marketing' => true );
$GLOBALS['wpac_test_touch'] = null;

echo "Privacy: scanner queue, partner API, rate limit\n";
$GLOBALS['p1p_inline'] = array();
$GLOBALS['wpcap_test_user_id'] = 5;
Privacy::scanner_context();
$inline = $p1p_own_assets ? end( $GLOBALS['p1p_inline'] ) : false;
$ctx = $inline ? json_decode( preg_replace( '/^window\.WPCAP_SCANNER_PRIVACY=|;$/', '', $inline[1] ), true ) : null;
check( 'scanner: per-user owner key (not the raw user ID) and 24 h queue lifetime before the script', ( ! $p1p_own_assets && Privacy::scanner_owner( 5 ) !== Privacy::scanner_owner( 6 ) ) || is_array( $ctx ) && 'before' === $inline[2] && 24 === $ctx['maxAgeHours'] && Privacy::scanner_owner( 5 ) === $ctx['owner'] && '5' !== $ctx['owner'] && Privacy::scanner_owner( 6 ) !== $ctx['owner'] && false !== strpos( $ctx['i18n']['expired'], '24 Stunden' ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$js = file_get_contents( WPCAP_PATH . 'assets/scanner.js' );
check( 'scanner.js: queue key per owner, legacy/foreign queues removed, no 7-day lifetime', false !== strpos( $js, "queuePrefix + owner" ) && false !== strpos( $js, 'removeItem' ) && false === strpos( $js, '7 * 24 * 3600' ) );
check( 'partner API: placeholder and empty addresses become null', null === Privacy::public_email( 'deleted+abc@invalid.example' ) && null === Privacy::public_email( 'boxoffice+x@INVALID.example' ) && null === Privacy::public_email( '' ) && 'a@example.com' === Privacy::public_email( 'a@example.com' ) );
$api_src = file_get_contents( WPCAP_PATH . 'src/Partners/Api.php' );
check( 'partner API: e-mail fields go through Privacy::public_email()', 2 === substr_count( $api_src, 'Privacy::public_email(' ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$rl_client = PassCaptain\RateLimit::client();
check( 'rate limit: client key is an HMAC with the site salt, not a static key', hash_hmac( 'sha256', '198.51.100.7', 'wpcap-rate' ) !== $rl_client && hash_hmac( 'sha256', '198.51.100.7', 'wpcap-rate|' . wp_salt( 'nonce' ) ) === $rl_client );
$GLOBALS['wpcap_test_transients'] = array();
$allowed = 0;
for ( $i = 0; $i < 4; $i++ ) { $allowed += PassCaptain\RateLimit::allow( 'p1p_bucket|198.51.100.7', 3, 60 ) ? 1 : 0; }
$keys = array_keys( $GLOBALS['wpcap_test_transients'] );
check( 'rate limit: still limits, transient key is not a plain md5 of the bucket', 3 === $allowed && 1 === count( $keys ) && false === strpos( $keys[0], md5( 'p1p_bucket|198.51.100.7|' . (int) floor( time() / 60 ) ) ) );
unset( $_SERVER['REMOTE_ADDR'] );
$GLOBALS['wpcap_test_transients'] = array();
