<?php
/**
 * Worklist item A278: a developer API key only works while its owner exists and still holds the
 * capability of the event assignment; role removal and deletion revoke the keys (UserAccess).
 * Included from run.php after zz_a277.php (Q1_User, q1_user(), q1_event()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\DeveloperKeys;

$GLOBALS['wpcap_test_denied_caps'] = array();
$GLOBALS['wpcap_test_transients'] = array();

function q1_key_request( $key, $event_id ) {
	$r = new WP_REST_Request( 'GET' );
	$r->set_header( 'X-WPCAP-Key', $key );
	$r->set_param( 'event_id', $event_id );
	return $r;
}
function q1_issue_key( $user_id, $event_id ) {
	$saved = $GLOBALS['wpcap_test_user_id'] ?? 1;
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$key = DeveloperKeys::issue( $event_id, array( 'events.read' ), 'A278' );
	$GLOBALS['wpcap_test_user_id'] = $saved;
	return $key;
}
function q1_key_revoked( $key_id ) {
	global $wpdb;
	return null !== $wpdb->get_var( "SELECT revoked_at FROM {$wpdb->prefix}wpcap_api_keys WHERE api_key_id=" . (int) $key_id );
}

echo "A278: developer API key owner checks\n";
// Merged DeveloperKeys::owner_can() asks user_can() (as WordPress does: via the user's roles); the test user_can()
// stub is pointed at the registered test users (deleted ones have no capabilities) for this file.
$q1k_saved_user_can = $GLOBALS['wpcap_test_user_can'] ?? null;
$GLOBALS['wpcap_test_user_can'] = static function ( $u, $cap ) {
	$id = is_object( $u ) ? (int) ( $u->ID ?? 0 ) : (int) $u;
	if ( in_array( $id, $GLOBALS['wpcap_test_deleted_users'] ?? array(), true ) ) { return false; }
	foreach ( $GLOBALS['wpcap_test_users'] ?? array() as $user ) {
		if ( (int) $user->ID === $id && method_exists( $user, 'has_cap' ) ) { return $user->has_cap( $cap ); }
	}
	return isset( $GLOBALS['wpcap_test_user_caps'][ $id ] ) ? in_array( $cap, (array) $GLOBALS['wpcap_test_user_caps'][ $id ], true ) : true;
};
$q1k_ev = q1_event();
$q1k_mgr = q1_user( 27801, array( 'wpcap_event_manager' ) );
$wpdb->query( "INSERT INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES ($q1k_ev,27801,'wpcap_manage_events')" );
$q1k_key = q1_issue_key( 27801, $q1k_ev );
check( 'A278 setup: key issued and readable for the event manager', ! is_wp_error( $q1k_key ) && 27801 === DeveloperKeys::authorize( q1_key_request( $q1k_key['key'], $q1k_ev ), 'events.read', $q1k_ev ) );

// Downgraded without the role hook (e.g. roles edited directly in the database): the request check refuses.
$q1k_mgr->roles = array( 'subscriber' );
check( 'A278 owner without wpcap_manage_events/view_reports is refused despite the team entry', false === DeveloperKeys::authorize( q1_key_request( $q1k_key['key'], $q1k_ev ), 'events.read', $q1k_ev ) && ! q1_key_revoked( $q1k_key['api_key_id'] ) );
$q1k_mgr->roles = array( 'wpcap_event_manager' );
check( 'A278 restoring the role makes the key work again (not revoked by the request check)', 27801 === DeveloperKeys::authorize( q1_key_request( $q1k_key['key'], $q1k_ev ), 'events.read', $q1k_ev ) );

$GLOBALS['wpcap_test_deleted_users'] = array( 27801 );
check( 'A278 deleted owner: key refused', false === DeveloperKeys::authorize( q1_key_request( $q1k_key['key'], $q1k_ev ), 'events.read', $q1k_ev ) );
$GLOBALS['wpcap_test_deleted_users'] = array();

echo "A278: keys revoked on role removal and deletion\n";
$q1k_mgr->set_role( 'wpcap_partner' ); // Keeps check-in and reports, loses event management.
check( 'A278 role change: key revoked (manage_events entry gone, no reports entry), audited', q1_key_revoked( $q1k_key['api_key_id'] ) && 'wpcap_manage_events' !== implode( ',', q1_staff( 27801 ) )
	&& (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_audit_logs WHERE action='developer_key_revoked' AND object_id=" . (int) $q1k_key['api_key_id'] . " AND details LIKE '%owner_access_lost%'" ) > 0 );

$q1k_rep = q1_user( 27802, array( 'wpcap_event_manager', 'wpcap_partner' ) );
$wpdb->query( "INSERT INTO {$p}wpcap_event_staff (event_id,user_id,capability) VALUES ($q1k_ev,27802,'wpcap_manage_events'),($q1k_ev,27802,'wpcap_view_reports')" );
$q1k_key2 = q1_issue_key( 27802, $q1k_ev );
$q1k_rep->remove_role( 'wpcap_event_manager' );
check( 'A278 a remaining reports entry keeps the key', ! is_wp_error( $q1k_key2 ) && ! q1_key_revoked( $q1k_key2['api_key_id'] ) && 27802 === DeveloperKeys::authorize( q1_key_request( $q1k_key2['key'], $q1k_ev ), 'events.read', $q1k_ev ) );
do_action( 'delete_user', 27802, null, $q1k_rep );
check( 'A278 delete_user revokes every key of the owner', q1_key_revoked( $q1k_key2['api_key_id'] ) && false === DeveloperKeys::authorize( q1_key_request( $q1k_key2['key'], $q1k_ev ), 'events.read', $q1k_ev ) );
$GLOBALS['wpcap_test_transients'] = array();
if ( null === $q1k_saved_user_can ) { unset( $GLOBALS['wpcap_test_user_can'] ); } else { $GLOBALS['wpcap_test_user_can'] = $q1k_saved_user_can; }
