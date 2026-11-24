<?php
/**
 * Worklist item A277: a role removal or the deletion of a user takes back the check-in capability
 * granted by the staff assignment, the event-team entries the user no longer holds a capability for,
 * scanner devices, open pairing codes and entry-area assignments (UserAccess). Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';

use PassCaptain\UserAccess;

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();


UserAccess::register();

echo "A277: check-in helper without plugin role loses access with the role change\n";
$q1_ev1 = q1_event();
$q1_ev2 = q1_event();
$q1_helper = q1_user( 27701, array( 'subscriber' ) );
PassCaptain\Admin\EventsPage::assign_staff( array( 'event_id' => $q1_ev1, 'user' => 'q1-27701', 'capability' => 'wpcap_checkin' ) );
PassCaptain\Admin\EventsPage::assign_staff( array( 'event_id' => $q1_ev2, 'user' => 'q1-27701', 'capability' => 'wpcap_checkin' ) );
$q1_dev = q1_device( 27701 );
check( 'A277 setup: user capability, two team entries, device and pairing', ! empty( $q1_helper->caps['wpcap_checkin'] ) && 2 === count( q1_staff( 27701 ) ) && ! q1_device_revoked( $q1_dev ) && 1 === q1_open_pairings( 27701 ) );
$q1_helper->set_role( 'customer' );
check( 'A277 role change: assignment capability removed', empty( $q1_helper->caps['wpcap_checkin'] ) && ! $q1_helper->has_cap( 'wpcap_checkin' ) );
check( 'A277 role change: team entries deleted, device revoked, open pairing deleted', array() === q1_staff( 27701 ) && q1_device_revoked( $q1_dev ) && 0 === q1_open_pairings( 27701 ) );
$q1_audit = $wpdb->get_row( "SELECT * FROM {$p}wpcap_audit_logs WHERE action='user_access_revoked' AND object_id=27701 ORDER BY audit_id DESC LIMIT 1" );
check( 'A277 revocation is audited with reason and removed role', $q1_audit && false !== strpos( (string) $q1_audit->details, 'role_removed' ) && false !== strpos( (string) $q1_audit->details, 'subscriber' ) );

echo "A277: removing the Einlasspersonal role\n";
$q1_role = q1_user( 27702, array( 'wpcap_checkin_staff', 'subscriber' ) );
PassCaptain\Admin\EventsPage::assign_staff( array( 'event_id' => $q1_ev1, 'user' => 'q1-27702', 'capability' => 'wpcap_checkin' ) );
$q1_dev2 = q1_device( 27702 );
$q1_role->remove_role( 'wpcap_checkin_staff' );
check( 'A277 remove_user_role: capability, team entry and device gone', ! $q1_role->has_cap( 'wpcap_checkin' ) && array() === q1_staff( 27702 ) && q1_device_revoked( $q1_dev2 ) );

echo "A277: a remaining role keeps what it grants\n";
$q1_mgr = q1_user( 27703, array( 'wpcap_event_manager', 'wpcap_partner' ) );
$wpdb->query( "INSERT INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES ($q1_ev1,27703,'wpcap_checkin'),($q1_ev1,27703,'wpcap_manage_events'),($q1_ev1,27703,'wpcap_view_reports')" );
$q1_dev3 = q1_device( 27703 );
$q1_mgr->remove_role( 'wpcap_event_manager' );
check( 'A277 partner role still grants check-in and reports: those entries and the device stay, event management goes', array( $q1_ev1 . ':wpcap_checkin', $q1_ev1 . ':wpcap_view_reports' ) === q1_staff( 27703 ) && ! q1_device_revoked( $q1_dev3 ) );
$q1_before = count( $wpdb->get_col( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='user_access_revoked' AND object_id=27703" ) );
$q1_mgr->roles[] = 'wpcap_event_manager';
do_action( 'add_user_role', 27703, 'wpcap_event_manager' );
$q1_mgr->set_role( 'wpcap_partner' ); // Same remaining role set minus the manager role.
check( 'A277 nothing to revoke for an unchanged set (no extra entry removed)', array( $q1_ev1 . ':wpcap_checkin', $q1_ev1 . ':wpcap_view_reports' ) === q1_staff( 27703 ) && $q1_before === count( $wpdb->get_col( "SELECT audit_id FROM {$p}wpcap_audit_logs WHERE action='user_access_revoked' AND object_id=27703" ) ) );
$q1_new = q1_user( 27704, array() );
$q1_new->set_role( 'wpcap_checkin_staff' ); // New user / role added: no old role removed.
$wpdb->query( "INSERT INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES ($q1_ev2,27704,'wpcap_checkin')" );
$q1_new->roles[] = 'subscriber';
$q1_new->set_role( 'wpcap_checkin_staff' );
check( 'A277 a role change that keeps the check-in role keeps the entry', array( $q1_ev2 . ':wpcap_checkin' ) === q1_staff( 27704 ) );
$GLOBALS['wpcap_test_filters']['wpcap_revoke_access_on_role_change'] = false;
$q1_new->set_role( 'subscriber' );
check( 'A277 filter wpcap_revoke_access_on_role_change=false keeps the access', array( $q1_ev2 . ':wpcap_checkin' ) === q1_staff( 27704 ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_revoke_access_on_role_change'] );

echo "A277: deleted user\n";
$q1_dev4 = q1_device( 27703 );
if ( PassCaptain\Privacy::has_table( 'wpcap_admission_area_staff' ) ) {
	$wpdb->query( "INSERT IGNORE INTO {$p}wpcap_admission_area_staff (area_id,user_id) VALUES (999001,27703)" );
}
do_action( 'delete_user', 27703, null, $q1_mgr );
check( 'A277 delete_user: every team entry, device, pairing and area assignment removed', array() === q1_staff( 27703 ) && q1_device_revoked( $q1_dev3 ) && q1_device_revoked( $q1_dev4 ) && 0 === q1_open_pairings( 27703 )
	&& ( ! PassCaptain\Privacy::has_table( 'wpcap_admission_area_staff' ) || 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_admission_area_staff WHERE user_id=27703" ) ) );
$wpdb->query( "INSERT INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES ($q1_ev2,27705,'wpcap_checkin')" );
do_action( 'remove_user_from_blog', 27705, 1, 0 );
check( 'A277 remove_user_from_blog (multisite) removes the entries of that site', array() === q1_staff( 27705 ) );
