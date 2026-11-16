<?php
/**
 * A470 (with A479): the access scope of a staff member belongs to one site. Two sites of a
 * subdirectory network side by side (prefix "<p>" and "<p>n2_", like wp_ and wp_2_), one user who
 * is staff on both: a scope set on site 1 must not limit (or widen) them on site 2, and the upgrade
 * copies the former network-wide scope to each site of the user.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Access;
use PassCaptain\Installer;
use PassCaptain\StaffAssignments;

if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['p1u_user_meta'][ $u ][ $k ] ?? ''; } }
if ( ! function_exists( 'update_user_meta' ) ) { function update_user_meta( $u, $k, $v ) { $GLOBALS['p1u_user_meta'][ $u ][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_user_meta' ) ) { function delete_user_meta( $u, $k ) { unset( $GLOBALS['p1u_user_meta'][ $u ][ $k ] ); return true; } }
if ( ! function_exists( 'maybe_unserialize' ) ) { function maybe_unserialize( $v ) { return is_string( $v ) && preg_match( '/^[aOs]:\d+:/', $v ) ? unserialize( $v, array( 'allowed_classes' => false ) ) : $v; } }

global $wpdb;
$a470_s1 = $wpdb->prefix;
$a470_s2 = $wpdb->prefix . 'n2_';

/** Creates site 2 (same schema, empty tables) once; returns the switcher. */
function a470_network() {
	global $wpdb;
	static $ready = false;
	$s1 = $GLOBALS['a470_s1'];
	$s2 = $GLOBALS['a470_s2'];
	if ( ! $ready ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $s2, '_%\\' ) . '%' ) ) as $t ) { $wpdb->query( "DROP TABLE `$t`" ); }
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $s1 . 'wpcap_', '_%\\' ) . '%' ) ) as $t ) {
			$wpdb->query( 'CREATE TABLE `' . $s2 . substr( $t, strlen( $s1 ) ) . "` LIKE `$t`" );
		}
		$ready = true;
	}
	$GLOBALS['wpcap_test_multisite'] = true;
	$GLOBALS['wpcap_test_blog_prefixes'] = array( 1 => $s1, 2 => $s2 );
}
/** Switches like switch_to_blog(): table prefix, current site, request caches. */
function a470_switch( $blog_id ) {
	global $wpdb;
	$wpdb->prefix = $GLOBALS['wpcap_test_blog_prefixes'][ $blog_id ];
	$GLOBALS['wpcap_test_blog_id'] = $blog_id;
	StaffAssignments::flush();
	do_action( 'switch_blog', $blog_id, 2 === $blog_id ? 1 : 2, 'switch' );
}
/** Event + occurrence at a location on the current site, with the user in its team. */
function a470_event( $location, $user_id, $cap = 'wpcap_manage_orders' ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'A470 ' . $location, 'slug' => 'a470-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'timezone' => 'Europe/Vienna', 'location' => $location, 'capacity' => 0, 'status' => 'scheduled' ) );
	$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $event, 'user_id' => $user_id, 'capability' => $cap ) );
	return $event;
}

echo "A470: access scope per site (subdirectory network)\n";
a470_network();
a470_switch( 1 );
$a470_user = 41;
// Staff member without manage_options (never global), member of both sites.
$GLOBALS['wpcap_test_user_caps'][ $a470_user ] = array( 'wpcap_manage_orders', 'wpcap_checkin' );
$a470_ev1 = a470_event( 'Wien', $a470_user );
a470_switch( 2 );
$a470_ev2 = a470_event( 'Graz', $a470_user );
a470_switch( 1 );

// The site administrator of site 1 limits the user to "Wien".
check( 'A470: scope key carries the site prefix (like update_user_option())', $a470_s1 . 'wpcap_access_scope' === Access::scope_meta_key() );
Access::set_scope( $a470_user, array( 'locations' => 'Wien' ) );
check( 'A470: scope is stored for site 1 only', array( 'Wien' ) === ( $GLOBALS['p1u_user_meta'][ $a470_user ][ $a470_s1 . 'wpcap_access_scope' ]['locations'] ?? null ) && ! isset( $GLOBALS['p1u_user_meta'][ $a470_user ]['wpcap_access_scope'] ) && ! isset( $GLOBALS['p1u_user_meta'][ $a470_user ][ $a470_s2 . 'wpcap_access_scope' ] ) );
check( 'A470: on site 1 the scope applies', array( 'Wien' ) === ( Access::scope( $a470_user )['locations'] ?? null ) );

$GLOBALS['wpcap_test_user_id'] = $a470_user;
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
check( 'A470: site 1 event at Wien is reachable', Access::can_access_event( $a470_ev1, 'wpcap_manage_orders' ) );
a470_switch( 2 );
check( 'A470: on site 2 the user has no scope (site 1 locations do not apply)', null === Access::scope( $a470_user ) );
check( 'A470: site 2 event at Graz stays reachable through the site 2 team assignment', Access::can_access_event( $a470_ev2, 'wpcap_manage_orders' ) );

// Site 2 limits the user to a partner of site 2: site 1 keeps its own scope.
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
Access::set_scope( $a470_user, array( 'partners' => array( 7 ) ) );
check( 'A470: site 2 stores its own scope', array( 7 ) === ( Access::scope( $a470_user )['partners'] ?? null ) && array() === Access::scope( $a470_user )['locations'] );
a470_switch( 1 );
check( 'A470: site 1 scope is untouched by site 2', array( 'Wien' ) === ( Access::scope( $a470_user )['locations'] ?? null ) && null === Access::scope( $a470_user )['partners'] );
a470_switch( 2 );
Access::set_scope( $a470_user, array() );
a470_switch( 1 );
check( 'A470: clearing the scope on site 2 leaves site 1 limited', array( 'Wien' ) === ( Access::scope( $a470_user )['locations'] ?? null ) );
check( 'A470: user profile form shows the scope of the current site', array( 'Wien' ) === PassCaptain\Admin\UserScope::stored( $a470_user )['locations'] );
Access::set_scope( $a470_user, array() );

echo "A470: upgrade copies the former network-wide scope\n";
$wpdb->query( "DROP TABLE IF EXISTS `{$a470_s1}a470_usermeta`" );
$wpdb->query( "CREATE TABLE `{$a470_s1}a470_usermeta` (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext)" );
$a470_usermeta = $wpdb->usermeta;
$wpdb->usermeta = $a470_s1 . 'a470_usermeta';
$a470_legacy = array( 'locations' => array( 'Linz' ), 'partners' => null, 'linked_partners' => false );
foreach ( array( 42, 43, 44 ) as $a470_u ) {
	$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $a470_u, 'meta_key' => 'wpcap_access_scope', 'meta_value' => serialize( $a470_legacy ) ) );
}
// User 43 only belongs to site 1; user 44 already has a site 2 scope of its own.
$GLOBALS['wpcap_test_blog_members'] = array( 1 => array( 1, 41, 42, 43, 44 ), 2 => array( 1, 41, 42, 44 ) );
$GLOBALS['p1u_user_meta'][44][ $a470_s2 . 'wpcap_access_scope' ] = array( 'locations' => array( 'Graz' ), 'partners' => null, 'linked_partners' => false );
check( 'A470: no copy for fresh installs or schema 3.10.18+', 0 === Installer::migrate_site_scopes( '' ) && 0 === Installer::migrate_site_scopes( '3.10.18' ) );
check( 'A470: site 1 upgrade copies the scope of its three members', 3 === Installer::migrate_site_scopes( '3.10.17' ) && array( 'Linz' ) === ( $GLOBALS['p1u_user_meta'][42][ $a470_s1 . 'wpcap_access_scope' ]['locations'] ?? null ) );
a470_switch( 2 );
check( 'A470: site 2 upgrade copies only to its members and keeps an existing site scope', 1 === Installer::migrate_site_scopes( '3.10.17' ) && array( 'Linz' ) === ( $GLOBALS['p1u_user_meta'][42][ $a470_s2 . 'wpcap_access_scope' ]['locations'] ?? null ) && ! isset( $GLOBALS['p1u_user_meta'][43][ $a470_s2 . 'wpcap_access_scope' ] ) && array( 'Graz' ) === $GLOBALS['p1u_user_meta'][44][ $a470_s2 . 'wpcap_access_scope' ]['locations'] );
check( 'A470: the network-wide key stays on a network (other sites may not be upgraded yet)', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='wpcap_access_scope'" ) );
check( 'A470: a repeated run copies nothing', 0 === Installer::migrate_site_scopes( '3.10.17' ) );
a470_switch( 1 );
// Single site: the old key is removed once copied.
$GLOBALS['wpcap_test_multisite'] = false;
$GLOBALS['p1u_user_meta'][45]['wpcap_access_scope'] = $a470_legacy;
$wpdb->insert( $wpdb->usermeta, array( 'user_id' => 45, 'meta_key' => 'wpcap_access_scope', 'meta_value' => serialize( $a470_legacy ) ) );
Installer::migrate_site_scopes( '3.10.17' );
check( 'A470: single site moves the scope to the prefixed key', array( 'Linz' ) === ( $GLOBALS['p1u_user_meta'][45][ $a470_s1 . 'wpcap_access_scope' ]['locations'] ?? null ) && ! isset( $GLOBALS['p1u_user_meta'][45]['wpcap_access_scope'] ) );

// Cleanup.
$wpdb->usermeta = $a470_usermeta;
$wpdb->query( "DROP TABLE IF EXISTS `{$a470_s1}a470_usermeta`" );
foreach ( array( 41, 42, 43, 44, 45 ) as $a470_u ) { unset( $GLOBALS['p1u_user_meta'][ $a470_u ], $GLOBALS['wpcap_test_user_caps'][ $a470_u ] ); }
$GLOBALS['wpcap_test_multisite'] = false;
$GLOBALS['wpcap_test_blog_members'] = array();
$GLOBALS['wpcap_test_blog_prefixes'] = array();
$GLOBALS['wpcap_test_blog_id'] = 1;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
$wpdb->prefix = $a470_s1;
StaffAssignments::flush();
