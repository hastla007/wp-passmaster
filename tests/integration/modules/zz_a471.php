<?php
/**
 * A471, A636 (with A479): staff assignment on a subdirectory network. An event manager may only
 * add members of the current site that already hold a Pass Captain role; unknown accounts, accounts
 * of other sites and accounts without such a role all get the same answer (no account oracle), and
 * none of them receives wpcap_checkin. Administrators (list_users) may add any member of the site.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admission\RulesPage;
use PassCaptain\StaffAssignments;

global $wpdb;
$p = $wpdb->prefix;

echo "A471/A636: staff assignment only for members of this site with a Pass Captain role\n";
$a471_now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A471', 'slug' => 'a471-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a471_now, 'updated_at' => $a471_now ) );
$a471_event = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $a471_event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
$a471_occ = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A471 fremd', 'slug' => 'a471f-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $a471_now, 'updated_at' => $a471_now ) );
$a471_foreign = (int) $wpdb->insert_id;

// 50: event manager of the event (no list_users); 51: site member without a plugin role; 52: door
// staff of this site; 53: door staff of another site of the network only.
$a471_users = array(
	50 => array( 'a471-manager', array( 'wpcap_manage_events' ) ),
	51 => array( 'a471-plain', array( 'read' ) ),
	52 => array( 'a471-door', array( 'wpcap_checkin' ) ),
	53 => array( 'a471-other', array( 'wpcap_checkin' ) ),
);
foreach ( $a471_users as $a471_id => list( $a471_login, $a471_caps ) ) {
	$GLOBALS['wpcap_test_users'][] = new Wpcap_Test_User( $a471_id, $a471_login, $a471_login . '@example.com' );
	$GLOBALS['wpcap_test_user_caps'][ $a471_id ] = $a471_caps;
}
$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $a471_event, 'user_id' => 50, 'capability' => 'wpcap_manage_events' ) );
$GLOBALS['wpcap_test_multisite'] = true;
$GLOBALS['wpcap_test_blog_id'] = 1;
$GLOBALS['wpcap_test_blog_members'] = array( 1 => array( 1, 50, 51, 52 ), 2 => array( 1, 53 ) );
$a471_caps_of = static function ( $id ) {
	foreach ( $GLOBALS['wpcap_test_users'] as $u ) { if ( (int) $u->ID === $id ) { return $u->caps; } }
	return null;
};
$a471_answer = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() . '|' . $r->get_error_message() : 'ok';
$a471_assign = static fn( $user, $cap = 'wpcap_checkin', $event = null ) => EventsPage::assign_staff( array( 'event_id' => $event ?? $a471_event, 'user' => $user, 'capability' => $cap ) );

// As event manager.
$GLOBALS['wpcap_test_user_id'] = 50;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options', 'list_users' );
StaffAssignments::flush();
$a471_unknown = $a471_answer( $a471_assign( 'a471-nobody' ) );
$a471_other_site = $a471_answer( $a471_assign( 'a471-other' ) );
$a471_other_by_id = $a471_answer( $a471_assign( '53' ) );
$a471_plain = $a471_answer( $a471_assign( 'a471-plain@example.com' ) );
check( 'A471: an account of another site is refused', 'ok' !== $a471_other_site && array() === $a471_caps_of( 53 ) );
check( 'A636: an account without a Pass Captain role is refused and gets no wpcap_checkin', 'ok' !== $a471_plain && array() === $a471_caps_of( 51 ) );
check( 'A636: unknown, foreign and role-less accounts get exactly the same answer (login, id, e-mail)', $a471_unknown === $a471_other_site && $a471_unknown === $a471_other_by_id && $a471_unknown === $a471_plain && 0 === strpos( $a471_unknown, 'wpcap_staff_invalid|' ) );
check( 'A471: door staff of this site is assigned', 'ok' === $a471_answer( $a471_assign( 'a471-door' ) ) && in_array( 'wpcap_checkin', (array) $a471_caps_of( 52 ), true ) );
check( 'A636: a missing role for another right gives the same answer to an event manager', $a471_unknown === $a471_answer( $a471_assign( 'a471-door', 'wpcap_view_reports' ) ) );
check( 'A471: without rights for the event the answer does not depend on the account', $a471_answer( $a471_assign( 'a471-door', 'wpcap_checkin', $a471_foreign ) ) === $a471_answer( $a471_assign( 'a471-nobody', 'wpcap_checkin', $a471_foreign ) ) );
$a471_occ_unknown = $a471_answer( StaffAssignments::add( 'occurrence', $a471_occ, 'a471-nobody', 'wpcap_checkin' ) );
check( 'A471: occurrence assignment refuses foreign and unknown accounts alike', 'ok' !== $a471_occ_unknown && $a471_occ_unknown === $a471_answer( StaffAssignments::add( 'occurrence', $a471_occ, 'a471-other', 'wpcap_checkin' ) ) );
check( 'A471: occurrence assignment of the team member still works', 'ok' === $a471_answer( StaffAssignments::add( 'occurrence', $a471_occ, 'a471-door', 'wpcap_checkin' ) ) );
if ( class_exists( RulesPage::class ) && PassCaptain\Privacy::has_table( 'wpcap_admission_areas' ) ) {
	$a471_area = RulesPage::save_area( array( 'event_id' => $a471_event, 'name' => 'A471 Nord' ) );
	$a471_area_id = is_array( $a471_area ) ? (int) $a471_area['area_id'] : 0;
	$a471_area_unknown = $a471_answer( RulesPage::add_staff( array( 'area_id' => $a471_area_id, 'user' => 'a471-nobody' ) ) );
	check( 'A471: entry area refuses foreign and role-less accounts like unknown ones', 'ok' !== $a471_area_unknown && $a471_area_unknown === $a471_answer( RulesPage::add_staff( array( 'area_id' => $a471_area_id, 'user' => 'a471-other' ) ) ) && $a471_area_unknown === $a471_answer( RulesPage::add_staff( array( 'area_id' => $a471_area_id, 'user' => 'a471-plain' ) ) ) );
	check( 'A471: entry area accepts door staff of this site', 'ok' === $a471_answer( RulesPage::add_staff( array( 'area_id' => $a471_area_id, 'user' => 'a471-door' ) ) ) );
}

// As site administrator (list_users): any member of this site, still no other site's accounts.
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
StaffAssignments::flush();
check( 'A471: the administrator may add a member without a plugin role (check-in)', 'ok' === $a471_answer( $a471_assign( 'a471-plain' ) ) );
check( 'A471: the administrator cannot pull in an account of another site', 'ok' !== $a471_answer( $a471_assign( 'a471-other' ) ) && array() === $a471_caps_of( 53 ) );
$a471_role = $a471_assign( 'a471-door', 'wpcap_view_reports' );
check( 'A471: the administrator still sees the role hint', is_wp_error( $a471_role ) && 'wpcap_staff_role' === $a471_role->get_error_code() );
check( 'A471: single site: every account counts as member', ( function () use ( $a471_answer, $a471_assign ) {
	$GLOBALS['wpcap_test_multisite'] = false;
	$r = $a471_answer( $a471_assign( 'a471-other' ) );
	$GLOBALS['wpcap_test_multisite'] = true;
	return 'ok' === $r;
} )() );

// Cleanup.
$GLOBALS['wpcap_test_multisite'] = false;
$GLOBALS['wpcap_test_blog_members'] = array();
foreach ( array_keys( $a471_users ) as $a471_id ) { unset( $GLOBALS['wpcap_test_user_caps'][ $a471_id ] ); }
$GLOBALS['wpcap_test_users'] = array_values( array_filter( $GLOBALS['wpcap_test_users'], static fn( $u ) => ! isset( $a471_users[ (int) $u->ID ] ) ) );
StaffAssignments::flush();
