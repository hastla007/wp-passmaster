<?php
/**
 * A143: role "Pass Captain Leistungserbringer" (service provider) with minimal rights – own
 * assigned services only (slots, bookings, confirm, no-show) – and the documented role matrix.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Access;
use PassCaptain\Installer;
use PassCaptain\Pii;
use PassCaptain\ServiceBookings;
use PassCaptain\ServiceProviders;
use PassCaptain\Admin\Menu;
use PassCaptain\Admin\MyServicesPage;
use PassCaptain\Admin\ServiceSlotsPage;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $t, $d = null ) { return esc_attr( $t ); } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'Europe/Vienna'; } }

global $wpdb;
$p = $wpdb->prefix;
$wpa143_saved_user = $GLOBALS['wpcap_test_user_id'] ?? null;
$wpa143_saved_denied = $GLOBALS['wpcap_test_denied_caps'] ?? array();
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpa143_root = dirname( __DIR__, 3 );

echo "A143: roles and the documented role/capability matrix\n";
$wpa143_roles = Installer::role_definitions();
check( 'service provider role exists with only wpcap_service_bookings', isset( $wpa143_roles['wpcap_service_provider'] ) && array( 'wpcap_service_bookings' ) === $wpa143_roles['wpcap_service_provider'][1] && 'Pass Captain Leistungserbringer' === $wpa143_roles['wpcap_service_provider'][0] );
check( 'organiser role (wpcap_partner) unchanged: check-in and reports', array( 'wpcap_checkin', 'wpcap_view_reports' ) === $wpa143_roles['wpcap_partner'][1] );
check( 'every capability of a role is a known plugin capability; administrators get all of them', ! array_diff( array_merge( ...array_map( static fn( $d ) => $d[1], array_values( $wpa143_roles ) ) ), Installer::CAPABILITIES ) && in_array( 'wpcap_service_bookings', Installer::CAPABILITIES, true ) );
check( 'audit log knows every plugin role', array_keys( $wpa143_roles ) == PassCaptain\Privacy\AccessLog::PLUGIN_ROLES );
$wpa143_uninstall = (string) file_get_contents( $wpa143_root . '/uninstall.php' );
check( 'uninstall removes the new role and capability', false !== strpos( $wpa143_uninstall, "'wpcap_service_provider'" ) && false !== strpos( $wpa143_uninstall, "'wpcap_service_bookings'" ) );
// docs/SECURITY.md: the matrix must match role_definitions() exactly.
$wpa143_doc = (string) file_get_contents( $wpa143_root . '/docs/SECURITY.md' );
$wpa143_labels = array( 'Ticket-Administration' => 'wpcap_ticket_admin', 'Veranstaltungsleitung' => 'wpcap_event_manager', 'Vor-Ort-Kasse' => 'wpcap_box_office', 'Einlasspersonal' => 'wpcap_checkin_staff', 'Veranstalter' => 'wpcap_partner', 'Leistungserbringer' => 'wpcap_service_provider', 'Finanzbuchhaltung' => 'wpcap_finance', 'Support' => 'wpcap_support' );
$wpa143_doc_caps = array();
$wpa143_header = null;
foreach ( preg_split( '/\R/', $wpa143_doc ) as $wpa143_line ) {
	if ( str_starts_with( $wpa143_line, '| Capability | Wofür |' ) ) { $wpa143_header = array_map( 'trim', explode( '|', trim( $wpa143_line, '|' ) ) ); continue; }
	if ( null === $wpa143_header ) { continue; }
	if ( ! str_starts_with( $wpa143_line, '|' ) ) { if ( $wpa143_doc_caps ) { break; } continue; }
	$wpa143_cells = array_map( 'trim', explode( '|', trim( $wpa143_line, '|' ) ) );
	if ( ! preg_match( '/^`(wpcap_[a-z_]+)`$/', $wpa143_cells[0], $wpa143_m ) ) { continue; }
	foreach ( array_slice( $wpa143_header, 2, null, true ) as $wpa143_i => $wpa143_label ) {
		if ( '✓' === ( $wpa143_cells[ $wpa143_i ] ?? '' ) ) { $wpa143_doc_caps[ $wpa143_labels[ $wpa143_label ] ?? $wpa143_label ][] = $wpa143_m[1]; }
	}
}
$wpa143_code_caps = array_map( static function ( $d ) { $c = $d[1]; sort( $c ); return $c; }, $wpa143_roles );
$wpa143_doc_caps = array_map( static function ( $c ) { sort( $c ); return $c; }, $wpa143_doc_caps );
ksort( $wpa143_code_caps );
ksort( $wpa143_doc_caps );
check( 'schema 3.10.6: provider records (A133) are the access source', version_compare( Installer::DB_VERSION, '3.10.6', '>=' ) && ServiceBookings::providers_ready() );
check( 'admin menu: "Meine Leistungen" page and its admin-post action', isset( Menu::handlers()['wpcap_service_booking_status'], Menu::handlers()['wpcap_service_provider_save'], Menu::handlers()['wpcap_service_resource_providers'] ) && in_array( MyServicesPage::SLUG, array_column( Menu::entries(), 0 ), true ) );

echo "A143: a provider record linked to a user gives access to exactly its resources\n";
$wpa143_now = gmdate( 'Y-m-d H:i:s' );
$wpa143_event = static function ( $name ) use ( $wpdb, $p, $wpa143_now ) {
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => $name, 'slug' => 'wpa143-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $wpa143_now, 'updated_at' => $wpa143_now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	return $event;
};
$wpa143_resource = static function ( $event, $name ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $event, 'name' => $name, 'capacity' => 4, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
};
$wpa143_slot = static function ( $resource, $offset ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $resource, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $offset ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + $offset + 3600 ), 'capacity' => 4, 'status' => 'open' ) );
	return (int) $wpdb->insert_id;
};
$wpa143_booking = static function ( $slot, $email, $status = 'held', $expires = 600 ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $slot, 'order_id' => 0, 'customer_email' => $email, 'idempotency_key' => hash( 'sha256', uniqid( '', true ) ), 'quantity' => 1, 'expires_at' => 'confirmed' === $status ? null : gmdate( 'Y-m-d H:i:s', time() + $expires ), 'status' => $status, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	return (int) $wpdb->insert_id;
};
$wpa143_ev = $wpa143_event( 'WPA143 Messe' );
$wpa143_other_ev = $wpa143_event( 'WPA143 Fremd' );
$wpa143_r1 = $wpa143_resource( $wpa143_ev, 'Fotobox' );
$wpa143_r2 = $wpa143_resource( $wpa143_ev, 'Massage' );
$wpa143_r3 = $wpa143_resource( $wpa143_other_ev, 'Fremdraum' );
$wpa143_s1_future = $wpa143_slot( $wpa143_r1, 7200 );
$wpa143_s1_started = $wpa143_slot( $wpa143_r1, -600 );
$wpa143_s2 = $wpa143_slot( $wpa143_r2, -600 );
$wpa143_s3 = $wpa143_slot( $wpa143_r3, -600 );
$wpa143_b_future = $wpa143_booking( $wpa143_s1_future, 'gast1@example.com' );
$wpa143_b_future_conf = $wpa143_booking( $wpa143_s1_future, 'gast4@example.com', 'confirmed' );
$wpa143_b_started = $wpa143_booking( $wpa143_s1_started, 'gast2@example.com', 'confirmed' );
$wpa143_b_started_held = $wpa143_booking( $wpa143_s1_started, 'gast5@example.com' );
$wpa143_b_expired = $wpa143_booking( $wpa143_s1_future, 'gast3@example.com', 'held', -60 );
$wpa143_b_r2 = $wpa143_booking( $wpa143_s2, 'massage@example.com', 'confirmed' );
$wpa143_b_r3 = $wpa143_booking( $wpa143_s3, 'fremd@example.com', 'confirmed' );

$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( 9143, 'wpa143provider', 'provider143@example.com' );
// Administrator: provider record linked to user 9143, assigned to "Fotobox" only (A133 UI functions).
$wpa143_pid = ServiceSlotsPage::save_provider( array( 'name' => 'Fotografin Fee', 'email' => 'fee@example.com', 'user_id' => '9143' ) );
$wpa143_assign = ServiceProviders::assign( $wpa143_r1, array( $wpa143_pid ) );
check( 'administrator links a provider record to the user and assigns it to one resource (audited)', is_int( $wpa143_pid ) && true === $wpa143_assign && 9143 === (int) ServiceProviders::get( $wpa143_pid )->user_id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_resource_providers_set' AND object_id=%d", $wpa143_r1 ) ) > 0 && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_provider_created' AND object_id=%d", $wpa143_pid ) ) > 0 );
check( 'resource ids derive from the provider records of the user', array( $wpa143_r1 ) === ServiceProviders::resource_ids_for_user( 9143 ) && array() === ServiceProviders::resource_ids_for_user( 9144 ) );

// Act as the provider: only wpcap_service_bookings (and read).
$wpa143_provider_denied = array_values( array_diff( array_merge( array( 'manage_options' ), Installer::CAPABILITIES ), array( 'wpcap_service_bookings' ) ) );
$GLOBALS['wpcap_test_user_id'] = 9143;
$GLOBALS['wpcap_test_denied_caps'] = $wpa143_provider_denied;
ServiceProviders::flush();
check( 'provider reaches the assigned resource only (not the other resource of the event, not other events)', Access::can_access_resource( $wpa143_r1 ) && ! Access::can_access_resource( $wpa143_r2 ) && ! Access::can_access_resource( $wpa143_r3 ) );
check( 'provider has no event-level access (manage events/orders, check-in, reports)', ! Access::can_access_event( $wpa143_ev, 'wpcap_manage_events' ) && ! Access::can_access_event( $wpa143_ev, 'wpcap_manage_orders' ) && ! Access::can_access_event( $wpa143_ev, 'wpcap_checkin' ) && ! Access::can_access_event( $wpa143_ev, 'wpcap_view_reports' ) && array() === PassCaptain\Admin\Queries::event_ids_for( array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports' ) ) );
$wpa143_mine = array_map( static fn( $r ) => (int) $r->resource_id, ServiceBookings::provider_resources() );
$wpa143_slots = array_map( static fn( $s ) => (int) $s->slot_id, ServiceBookings::slots_of_resources( $wpa143_mine ) );
$wpa143_bookings = array_map( static fn( $b ) => (int) $b->booking_id, ServiceBookings::bookings_of_resources( $wpa143_mine ) );
check( 'provider lists: own resource, its slots and bookings only', array( $wpa143_r1 ) === $wpa143_mine && in_array( $wpa143_s1_future, $wpa143_slots, true ) && ! in_array( $wpa143_s2, $wpa143_slots, true ) && in_array( $wpa143_b_started, $wpa143_bookings, true ) && ! in_array( $wpa143_b_r2, $wpa143_bookings, true ) && ! in_array( $wpa143_b_r3, $wpa143_bookings, true ) );
check( 'PII profile "service": booking e-mail visible, attendee data and phone hidden', array( 'service' ) === Pii::profiles() && Pii::can_view( 'buyer_email' ) && ! Pii::can_view( 'attendee_name' ) && ! Pii::can_view( 'buyer_phone' ) && ! Pii::can_view( 'billing' ) );

$wpa143_confirm = ServiceBookings::set_booking_status( $wpa143_b_future, 'confirmed' );
$wpa143_row = ServiceBookings::booking( $wpa143_b_future );
check( 'provider confirms a held booking of the own resource (no expiry anymore, audited as provider)', true === $wpa143_confirm && 'confirmed' === $wpa143_row->status && null === $wpa143_row->expires_at && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_booking_confirmed' AND object_id=%d AND user_id=9143 AND details LIKE %s", $wpa143_b_future, '%provider%' ) ) );
check( 'confirming again is a no-op', true === ServiceBookings::set_booking_status( $wpa143_b_future, 'confirmed' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_booking_confirmed' AND object_id=%d", $wpa143_b_future ) ) );
$wpa143_early = ServiceBookings::set_booking_status( $wpa143_b_future_conf, 'no_show' );
check( 'no-show only once the slot has started (409)', is_wp_error( $wpa143_early ) && 409 === $wpa143_early->get_error_data()['status'] && 'confirmed' === ServiceBookings::booking( $wpa143_b_future_conf )->status );
$wpa143_held_noshow = ServiceBookings::set_booking_status( $wpa143_b_started_held, 'no_show' );
check( 'no-show only from confirmed (a held booking is refused, 409)', is_wp_error( $wpa143_held_noshow ) && 409 === $wpa143_held_noshow->get_error_data()['status'] && 'held' === ServiceBookings::booking( $wpa143_b_started_held )->status );
$GLOBALS['wpcap_test_actions'] = array();
$wpa143_noshow = ServiceBookings::set_booking_status( $wpa143_b_started, 'no_show' );
check( 'provider marks a confirmed booking as no-show after the slot started (audited, action fired)', true === $wpa143_noshow && 'no_show' === ServiceBookings::booking( $wpa143_b_started )->status && in_array( 'wpcap_service_booking_status_changed', $GLOBALS['wpcap_test_actions'], true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_booking_no_show' AND object_id=%d", $wpa143_b_started ) ) );
$wpa143_back = ServiceBookings::set_booking_status( $wpa143_b_started, 'confirmed' );
check( 'confirm acts on held only: a no-show is not confirmed again (409); no-show frees the place', is_wp_error( $wpa143_back ) && 409 === $wpa143_back->get_error_data()['status'] && 'no_show' === ServiceBookings::booking( $wpa143_b_started )->status && 1 === ServiceBookings::booked( $wpa143_s1_started ) );
$wpa143_exp = ServiceBookings::set_booking_status( $wpa143_b_expired, 'confirmed' );
check( 'expired holds cannot be confirmed (409)', is_wp_error( $wpa143_exp ) && 409 === $wpa143_exp->get_error_data()['status'] );
check( 'unknown status refused (400)', 400 === ServiceBookings::set_booking_status( $wpa143_b_future, 'cancelled' )->get_error_data()['status'] );
$wpa143_f1 = ServiceBookings::set_booking_status( $wpa143_b_r2, 'no_show' );
$wpa143_f2 = ServiceBookings::set_booking_status( $wpa143_b_r3, 'no_show' );
check( 'bookings of other resources (same or other event) are refused (403) and unchanged', is_wp_error( $wpa143_f1 ) && 403 === $wpa143_f1->get_error_data()['status'] && is_wp_error( $wpa143_f2 ) && 403 === $wpa143_f2->get_error_data()['status'] && 'confirmed' === ServiceBookings::booking( $wpa143_b_r2 )->status && 'confirmed' === ServiceBookings::booking( $wpa143_b_r3 )->status );
// admin-post handler: foreign booking dies with 403.
$_POST = array( 'booking_id' => (string) $wpa143_b_r2, 'status' => 'no_show' );
$wpa143_died = false;
try { MyServicesPage::handle_status(); } catch ( Wpcap_Test_Die $e ) { $wpa143_died = true; } catch ( Wpcap_Test_Redirect $e ) { $wpa143_died = false; }
$_POST = array();
check( 'admin-post: foreign booking → 403', $wpa143_died );
$wpa143_save = ServiceSlotsPage::save_provider( array( 'provider_id' => (string) $wpa143_pid, 'name' => 'Fee', 'user_id' => '9143' ) );
$wpa143_reassign = ServiceProviders::assign( $wpa143_r2, array( $wpa143_pid ) );
check( 'provider cannot manage resources, slots, provider records or their assignment', is_wp_error( ServiceBookings::update_resource( $wpa143_r1, array( 'name' => 'X' ) ) ) && is_wp_error( ServiceBookings::delete_slot( $wpa143_s1_future ) ) && is_wp_error( $wpa143_save ) && 403 === $wpa143_save->get_error_data()['status'] && is_wp_error( $wpa143_reassign ) && 403 === $wpa143_reassign->get_error_data()['status'] );

// "Meine Leistungen" page.
$wpa143_render = static function ( callable $render, array $get = array() ) {
	$saved = $_GET;
	$_GET = $get;
	ob_start();
	try { $render(); $html = ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); $html = 'EXCEPTION ' . get_class( $e ) . ' ' . $e->getMessage(); }
	$_GET = $saved;
	return $html;
};
$wpa143_page = $wpa143_render( array( MyServicesPage::class, 'render' ) );
check( 'page "Meine Leistungen" shows the own resource and its bookings, nothing of other resources', false !== strpos( $wpa143_page, 'Fotobox' ) && false !== strpos( $wpa143_page, 'gast1@example.com' ) && false === strpos( $wpa143_page, 'Massage' ) && false === strpos( $wpa143_page, 'massage@example.com' ) && false === strpos( $wpa143_page, 'fremd@example.com' ) && false !== strpos( $wpa143_page, 'wpcap_service_booking_status' ) && false === strpos( $wpa143_page, 'EXCEPTION' ) );
$wpa143_slots_page = $wpa143_render( array( ServiceSlotsPage::class, 'render' ), array( 'event_id' => (string) $wpa143_ev ) );
check( 'provider cannot open the "Service-Zeitfenster" management page', false === strpos( $wpa143_slots_page, 'massage@example.com' ) && false === strpos( $wpa143_slots_page, 'gast1@example.com' ) );
// Another user without a linked record: nothing.
$GLOBALS['wpcap_test_user_id'] = 9144;
ServiceProviders::flush();
$wpa143_empty = $wpa143_render( array( MyServicesPage::class, 'render' ) );
check( 'a provider user without a linked record sees nothing and cannot change bookings', ! Access::can_access_resource( $wpa143_r1 ) && array() === ServiceBookings::provider_resources() && false === strpos( $wpa143_empty, 'gast1@example.com' ) && 403 === ServiceBookings::set_booking_status( $wpa143_b_future_conf, 'no_show' )->get_error_data()['status'] );

echo "A143: event management and record lifecycle\n";
$GLOBALS['wpcap_test_user_id'] = 9145;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'wpcap_service_bookings' );
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $wpa143_ev, 'user_id' => 9145, 'capability' => 'wpcap_manage_events' ) );
$wpa143_mgr = ServiceSlotsPage::booking_action( array( 'booking_id' => $wpa143_b_r2, 'op' => 'no_show' ) );
check( 'event manager marks a no-show of any resource of the event, but not of other events', true === $wpa143_mgr && 'no_show' === ServiceBookings::booking( $wpa143_b_r2 )->status && 403 === ServiceBookings::set_booking_status( $wpa143_b_r3, 'no_show' )->get_error_data()['status'] );
$wpa143_slots_html = $wpa143_render( array( ServiceSlotsPage::class, 'render' ), array( 'event_id' => (string) $wpa143_ev ) );
check( 'Service-Zeitfenster page: provider records with user link, no-show action', false !== strpos( $wpa143_slots_html, 'wpcap_service_provider_save' ) && false !== strpos( $wpa143_slots_html, 'Meine Leistungen' ) && false === strpos( $wpa143_slots_html, 'EXCEPTION' ) );
check( 'event manager cannot assign provider records to a resource of another event (403)', 403 === ServiceProviders::assign( $wpa143_r3, array( $wpa143_pid ) )->get_error_data()['status'] );
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
ServiceSlotsPage::save_provider( array( 'provider_id' => (string) $wpa143_pid, 'name' => 'Fotografin Fee', 'user_id' => '9143', 'status' => 'inactive' ) );
check( 'deactivating the provider record removes the access (audited with user_id)', array() === ServiceProviders::resource_ids_for_user( 9143 ) && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='service_provider_updated' AND object_id=%d", $wpa143_pid ) ) > 0 );
ServiceSlotsPage::save_provider( array( 'provider_id' => (string) $wpa143_pid, 'name' => 'Fotografin Fee', 'user_id' => '9143', 'status' => 'active' ) );
$wpa143_r4 = $wpa143_resource( $wpa143_ev, 'Leerraum' );
ServiceProviders::assign( $wpa143_r4, array( $wpa143_pid ) );
ServiceBookings::delete_resource( $wpa143_r4 );
check( 'deleting a resource removes its provider assignments', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_resource_providers WHERE resource_id=%d", $wpa143_r4 ) ) && array( $wpa143_r1 ) === ServiceProviders::resource_ids_for_user( 9143 ) );
ServiceProviders::assign( $wpa143_r1, array() );

$GLOBALS['wpcap_test_user_id'] = $wpa143_saved_user ?? 1;
$GLOBALS['wpcap_test_denied_caps'] = $wpa143_saved_denied;
ServiceProviders::flush();
