<?php
/**
 * A133: duration/buffers enforced for resources (slot creation, series generation, reservation,
 * public listing) and service providers assigned to resources (no double booking across resources).
 * Included from run.php (helpers from p3_exchange.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\ServiceBookings;
use PassCaptain\ServiceProviders;
use PassCaptain\Admin\ServiceSlotsPage;
use PassCaptain\Admin\Menu;

global $wpdb;
$p = $wpdb->prefix;
$a133_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$a133_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.133';

/** UTC 'Y-m-d H:i:s' of day +$days at $hm (UTC). */
function a133_at( $days, $hm ) {
	return gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) . ' ' . $hm . ':00';
}
function a133_slot( $resource, $days, $from, $to, $capacity = 2 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_service_slots", array( 'resource_id' => $resource, 'starts_at' => a133_at( $days, $from ), 'ends_at' => a133_at( $days, $to ), 'capacity' => $capacity, 'status' => 'open' ) );
	return (int) $wpdb->insert_id;
}
function a133_book( $slot, $email, $qty = 1 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	return ServiceBookings::reserve( $slot, $email, $qty, 0, 10, 'a133-' . uniqid( '', true ) );
}
function a133_audit( $action, $type, $id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action=%s AND object_type=%s AND object_id=%d", $action, $type, $id ) );
}

echo "A133: schema and resource duration\n";
ServiceBookings::flush_schema_cache();
check( 'schema 3.10.2: resources.duration_minutes, service_providers, resource_providers', version_compare( PassCaptain\Installer::DB_VERSION, '3.10.2', '>=' ) && ServiceBookings::providers_ready() );
$a133_event = ex_event( 'A133 Bootsschule' );
ex_occ( $a133_event );
$a133_r1 = ServiceSlotsPage::save_resource( array( 'event_id' => $a133_event, 'name' => 'Boot 1', 'capacity' => '2', 'buffer_before' => '15', 'buffer_after' => '15', 'duration_minutes' => '60' ) );
check( 'resource stores duration and buffers', is_int( $a133_r1 ) && 60 === (int) ServiceBookings::resource( $a133_r1 )->duration_minutes && 15 === (int) ServiceBookings::resource( $a133_r1 )->buffer_after );
ServiceBookings::update_resource( $a133_r1, array( 'name' => 'Boot 1', 'capacity' => 2 ) );
check( 'partial update keeps buffers and duration', 15 === (int) ServiceBookings::resource( $a133_r1 )->buffer_before && 60 === (int) ServiceBookings::resource( $a133_r1 )->duration_minutes );

echo "A133: buffers in slot creation\n";
$a133_s1 = ServiceBookings::create_slot( $a133_r1, array( 'starts_at' => a133_at( 6, '10:00' ), 'ends_at' => a133_at( 6, '11:00' ) ) );
$a133_tight = ServiceBookings::create_slot( $a133_r1, array( 'starts_at' => a133_at( 6, '11:15' ), 'ends_at' => a133_at( 6, '12:15' ) ) );
check( 'slot 15 min after the previous one is refused (needs 15 after + 15 before)', is_int( $a133_s1 ) && is_wp_error( $a133_tight ) && 'wpcap_service_slot_overlap' === $a133_tight->get_error_code() );
$a133_ok = ServiceBookings::create_slot( $a133_r1, array( 'starts_at' => a133_at( 6, '11:30' ), 'ends_at' => a133_at( 6, '12:30' ) ) );
check( 'slot 30 min after the previous one is accepted', is_int( $a133_ok ) );
ServiceBookings::update_slot( $a133_ok, array( 'starts_at' => a133_at( 6, '11:30' ), 'ends_at' => a133_at( 6, '12:30' ), 'status' => 'closed' ) );
$a133_moved = ServiceBookings::create_slot( $a133_r1, array( 'starts_at' => a133_at( 6, '11:20' ), 'ends_at' => a133_at( 6, '11:25' ) ) );
check( 'a closed slot without bookings does not block; overlap with an open one still does', is_wp_error( $a133_moved ) );
$a133_reopen = ServiceBookings::create_slot( $a133_r1, array( 'starts_at' => a133_at( 6, '12:00' ), 'ends_at' => a133_at( 6, '12:40' ) ) );
$a133_reopen_old = ServiceBookings::update_slot( $a133_ok, array( 'starts_at' => a133_at( 6, '11:30' ), 'ends_at' => a133_at( 6, '12:30' ), 'status' => 'open' ) );
check( 'reopening a slot that now overlaps another open one is refused', is_int( $a133_reopen ) && is_wp_error( $a133_reopen_old ) && 'wpcap_service_slot_overlap' === $a133_reopen_old->get_error_code() && 'closed' === ServiceBookings::slot( $a133_ok )->status );
ServiceBookings::delete_slot( $a133_reopen );
ServiceBookings::delete_slot( $a133_ok );

echo "A133: series generation\n";
$a133_r2 = ServiceBookings::create_resource( $a133_event, array( 'name' => 'Boot 2', 'capacity' => 1, 'buffer_before' => 15, 'buffer_after' => 15, 'duration_minutes' => 60 ) );
$a133_day = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
$a133_gen = ServiceBookings::generate_slots( $a133_r2, array( 'date_from' => $a133_day, 'date_to' => $a133_day, 'time_from' => '09:00', 'time_to' => '13:00', 'weekdays' => range( 1, 7 ), 'timezone' => 'UTC' ) );
$a133_starts = $wpdb->get_col( $wpdb->prepare( "SELECT starts_at FROM {$p}wpcap_service_slots WHERE resource_id=%d ORDER BY starts_at", $a133_r2 ) );
check( 'series: 60-min slots 30 min apart (buffers 15+15) between 09:00 and 13:00', is_array( $a133_gen ) && 3 === $a133_gen['created'] && array( $a133_day . ' 09:00:00', $a133_day . ' 10:30:00', $a133_day . ' 12:00:00' ) === $a133_starts && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT capacity FROM {$p}wpcap_service_slots WHERE resource_id=%d LIMIT 1", $a133_r2 ) ) );
$a133_again = ServiceBookings::generate_slots( $a133_r2, array( 'date_from' => $a133_day, 'date_to' => $a133_day, 'time_from' => '09:00', 'time_to' => '13:00', 'weekdays' => range( 1, 7 ), 'timezone' => 'UTC' ) );
check( 'generating again skips every overlapping candidate', is_array( $a133_again ) && 0 === $a133_again['created'] && 3 === $a133_again['skipped'] && a133_audit( 'service_slots_generated', 'resource', $a133_r2 ) >= 2 );
$a133_wd = (int) gmdate( 'N', strtotime( $a133_day ) );
$a133_none = ServiceBookings::generate_slots( $a133_r2, array( 'date_from' => $a133_day, 'date_to' => $a133_day, 'time_from' => '14:00', 'time_to' => '18:00', 'weekdays' => array( 1 === $a133_wd ? 2 : 1 ), 'duration' => 30, 'timezone' => 'UTC' ) );
check( 'other weekdays only: nothing generated', is_array( $a133_none ) && 0 === $a133_none['created'] );
check( 'invalid series (no weekday, reversed times) is refused', is_wp_error( ServiceBookings::generate_slots( $a133_r2, array( 'date_from' => $a133_day, 'date_to' => $a133_day, 'time_from' => '09:00', 'time_to' => '13:00', 'weekdays' => array() ) ) ) && is_wp_error( ServiceBookings::generate_slots( $a133_r2, array( 'date_from' => $a133_day, 'date_to' => $a133_day, 'time_from' => '13:00', 'time_to' => '09:00', 'weekdays' => array( 1 ) ) ) ) );

echo "A133: buffers in reservations\n";
// Two slots of one resource that only overlap through the buffers (e.g. buffers raised later).
$a133_b1 = a133_slot( $a133_r1, 8, '10:00', '11:00' );
$a133_b2 = a133_slot( $a133_r1, 8, '11:10', '12:00' );
$a133_first = a133_book( $a133_b1, 'a133-one@example.com' );
$a133_second = a133_book( $a133_b2, 'a133-two@example.com' );
check( 'booking a slot inside the buffer of a booked slot of the same resource is refused', is_array( $a133_first ) && is_wp_error( $a133_second ) && 'wpcap_slot_resource_busy' === $a133_second->get_error_code() );
check( 'the same slot itself still takes a second place (capacity 2)', is_array( a133_book( $a133_b1, 'a133-three@example.com' ) ) && 2 === ServiceBookings::booked( $a133_b1 ) );
$a133_public = a133_ids( ServiceBookings::public_slots( $a133_event ) );
check( 'public listing hides the full slot and the buffer-blocked one', ! in_array( $a133_b1, $a133_public, true ) && ! in_array( $a133_b2, $a133_public, true ) && in_array( $a133_s1, $a133_public, true ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET expires_at=%s WHERE slot_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $a133_b1 ) );
check( 'expired holds no longer block the neighbour slot', is_array( a133_book( $a133_b2, 'a133-two@example.com' ) ) );
function a133_ids( array $rows ) { return array_map( static function ( $r ) { return (int) $r['slot_id']; }, $rows ); }

echo "A133: service providers\n";
$a133_p = ServiceSlotsPage::save_provider( array( 'name' => 'Skipperin Anna', 'email' => 'anna@example.com', 'user_id' => '0' ) );
check( 'provider created and audited', is_int( $a133_p ) && 'Skipperin Anna' === ServiceProviders::get( $a133_p )->name && 'active' === ServiceProviders::get( $a133_p )->status && a133_audit( 'service_provider_created', 'service_provider', $a133_p ) );
check( 'provider without name or with invalid e-mail refused', is_wp_error( ServiceProviders::save( array( 'name' => '' ) ) ) && is_wp_error( ServiceProviders::save( array( 'name' => 'X', 'email' => 'kein-mail' ) ) ) );
$a133_ra = ServiceBookings::create_resource( $a133_event, array( 'name' => 'Jolle A', 'capacity' => 1, 'buffer_before' => 0, 'buffer_after' => 30 ) );
$a133_rb = ServiceBookings::create_resource( $a133_event, array( 'name' => 'Jolle B', 'capacity' => 1, 'buffer_before' => 10, 'buffer_after' => 0 ) );
check( 'provider assigned to two resources (audited)', true === ServiceProviders::assign( $a133_ra, array( $a133_p ) ) && true === ServiceProviders::assign( $a133_rb, array( $a133_p ) ) && array( $a133_p ) === array_map( 'intval', array_column( ServiceProviders::for_resource( $a133_rb ), 'provider_id' ) ) && a133_audit( 'service_resource_providers_set', 'resource', $a133_rb ) );
$a133_sa = a133_slot( $a133_ra, 9, '10:00', '11:00', 1 );
$a133_sb_same = a133_slot( $a133_rb, 9, '10:00', '11:00', 1 );
$a133_sb_buffer = a133_slot( $a133_rb, 9, '11:30', '12:30', 1 ); // A ends 11:00 + 30 after; B starts 11:30 − 10 before → overlap.
$a133_sb_free = a133_slot( $a133_rb, 9, '11:40', '12:40', 1 );   // 11:40 − 10 = 11:30: just free.
$a133_pa = a133_book( $a133_sa, 'a133-anna1@example.com' );
$a133_pb = a133_book( $a133_sb_same, 'a133-anna2@example.com' );
$a133_pbuf = a133_book( $a133_sb_buffer, 'a133-anna3@example.com' );
check( 'same provider at the same time on another resource is refused', is_array( $a133_pa ) && is_wp_error( $a133_pb ) && 'wpcap_slot_provider_busy' === $a133_pb->get_error_code() );
check( 'provider conflict uses the buffers of both resources', is_wp_error( $a133_pbuf ) && 'wpcap_slot_provider_busy' === $a133_pbuf->get_error_code() );
$a133_listed = a133_ids( ServiceBookings::public_slots( $a133_event ) );
check( 'public listing hides provider-blocked slots, keeps the free one', ! in_array( $a133_sb_same, $a133_listed, true ) && ! in_array( $a133_sb_buffer, $a133_listed, true ) && in_array( $a133_sb_free, $a133_listed, true ) );
check( 'slot right after the buffers is bookable', is_array( a133_book( $a133_sb_free, 'a133-anna4@example.com' ) ) );
ServiceProviders::save( array( 'name' => 'Skipperin Anna', 'email' => 'anna@example.com', 'status' => 'inactive' ), $a133_p );
check( 'inactive provider no longer blocks', null === ServiceBookings::blocking_slot( ServiceBookings::bookable_slot( $a133_sb_same ) ) );
ServiceProviders::save( array( 'name' => 'Skipperin Anna', 'email' => 'anna@example.com', 'status' => 'active' ), $a133_p );

// Assigning a provider who would then be double-booked is refused.
$a133_rc = ServiceBookings::create_resource( $a133_event, array( 'name' => 'Jolle C', 'capacity' => 1 ) );
$a133_sc = a133_slot( $a133_rc, 9, '10:30', '11:30', 1 );
check( 'setup: booking on a resource without provider', is_array( a133_book( $a133_sc, 'a133-c@example.com' ) ) );
$a133_assign = ServiceProviders::assign( $a133_rc, array( $a133_p ) );
check( 'assigning a provider who is booked at the same time elsewhere is refused (409), nothing changes', is_wp_error( $a133_assign ) && 'wpcap_provider_double_booked' === $a133_assign->get_error_code() && array() === ServiceProviders::for_resource( $a133_rc ) );
check( 'unknown provider id refused', is_wp_error( ServiceProviders::assign( $a133_rc, array( 99999999 ) ) ) );
$GLOBALS['wpcap_test_user_id'] = 4133;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_manage_events' );
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=4133" );
check( 'without event rights: no assignment, no provider changes', is_wp_error( ServiceProviders::assign( $a133_ra, array() ) ) && is_wp_error( ServiceSlotsPage::save_provider( array( 'name' => 'Fremd' ) ) ) && is_wp_error( ServiceBookings::generate_slots( $a133_r2, array() ) ) );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

echo "A133: locks (deterministic)\n";
// Another connection holds the provider row: a reservation on a resource of that provider must wait
// for it (lock order resource → provider → slot) instead of reading stale data.
$a133_sd = a133_slot( $a133_rb, 11, '10:00', '11:00', 1 );
$a133_other = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$a133_other->query( 'START TRANSACTION' );
$a133_other->query( "SELECT provider_id FROM {$p}wpcap_service_providers WHERE provider_id=$a133_p FOR UPDATE" );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=1' );
$a133_waited = a133_book( $a133_sd, 'a133-lock@example.com' );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout=50' );
check( 'reservation waits for the provider lock held elsewhere (retryable 503, nothing written)', is_wp_error( $a133_waited ) && ! empty( $a133_waited->get_error_data()['retryable'] ) && 0 === ServiceBookings::booked( $a133_sd ) );
// The other transaction books an overlapping slot of resource A for the same provider and commits.
$a133_sa2 = a133_slot( $a133_ra, 11, '10:15', '10:45', 1 );
$a133_other->query( "INSERT INTO {$p}wpcap_service_bookings (slot_id,order_id,customer_email,idempotency_key,quantity,expires_at,status,created_at) VALUES ($a133_sa2,0,'a133-other@example.com','" . hash( 'sha256', 'a133-other' . uniqid() ) . "',1,NULL,'confirmed',UTC_TIMESTAMP())" );
$a133_other->query( 'COMMIT' );
$a133_other->close();
$a133_after = a133_book( $a133_sd, 'a133-lock@example.com' );
check( 'after the other commit the reservation sees its booking and is refused', is_wp_error( $a133_after ) && 'wpcap_slot_provider_busy' === $a133_after->get_error_code() );

echo "A133: admin page\n";
check( 'handlers registered (series, provider, assignment)', isset( Menu::handlers()['wpcap_service_slots_generate'], Menu::handlers()['wpcap_service_provider_save'], Menu::handlers()['wpcap_service_resource_providers'] ) );
$a133_saved_get = $_GET;
$_GET = array( 'event_id' => (string) $a133_event );
ob_start();
try { ServiceSlotsPage::render(); $a133_html = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $a133_html = 'EXCEPTION ' . $e->getMessage(); }
$_GET = $a133_saved_get;
check( 'page shows providers, assignment, duration and the series form', false !== strpos( $a133_html, 'Skipperin Anna' ) && false !== strpos( $a133_html, 'wpcap_service_resource_providers' ) && false !== strpos( $a133_html, 'duration_minutes' ) && false !== strpos( $a133_html, 'wpcap_service_slots_generate' ) && false === strpos( $a133_html, 'EXCEPTION' ) );
check( 'documented in OPERATIONS.md', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'docs/OPERATIONS.md' ), 'Leistungserbringer' ) );

$GLOBALS['wpcap_test_user_id'] = $a133_saved_user;
$GLOBALS['wpcap_test_denied_caps'] = $a133_saved_denied;
$GLOBALS['wpcap_test_transients'] = array();
