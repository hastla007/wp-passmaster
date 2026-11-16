<?php
// phpcs:ignoreFile
/**
 * A362: plugin roles are stored with their untranslated source names and translated on display (the
 * "User role" context of translate_user_role()); names an earlier version stored translated are
 * normalised, names the operator chose are kept.
 */

use PassCaptain\Installer;

echo "A362: neutral role names, translated on display\n";
$GLOBALS['wpcap_test_x'] = array( "User role\x04Pass Captain Einlasspersonal" => 'Pass Captain Check-in Staff', "User role\x04Pass Captain Veranstalter" => 'Pass Captain Organizer' );
$a362_defs = Installer::role_definitions();
check( 'A362: role_definitions() store the source names even while another language is active', 'Pass Captain Einlasspersonal' === $a362_defs['wpcap_checkin_staff'][0] && array_keys( Installer::ROLE_NAMES ) === array_keys( $a362_defs ) );
check( 'A362: role_label() translates with the "User role" context', 'Pass Captain Check-in Staff' === Installer::role_label( 'wpcap_checkin_staff' ) && '' === Installer::role_label( 'editor' ) );
check( 'A362: translate_user_role() (domain default, context User role) shows the translated plugin role name', 'Pass Captain Check-in Staff' === Installer::translate_role_name( 'Pass Captain Einlasspersonal', 'Pass Captain Einlasspersonal', 'User role', 'default' ) );
check( 'A362: other texts, contexts and domains are untouched', 'Redakteur' === Installer::translate_role_name( 'Redakteur', 'Editor', 'User role', 'default' ) && 'x' === Installer::translate_role_name( 'x', 'Pass Captain Einlasspersonal', 'other', 'default' ) && 'y' === Installer::translate_role_name( 'y', 'Pass Captain Einlasspersonal', 'User role', 'pass-captain' ) );
check( 'A362: Plugin registers the gettext_with_context filter', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), "'gettext_with_context', array( Installer::class, 'translate_role_name' ), 10, 4" ) );

$a362_roles = (object) array(
	'roles'      => array(
		'wpcap_checkin_staff' => array( 'name' => 'Pass Captain Check-in Staff', 'capabilities' => array() ),
		'wpcap_partner'       => array( 'name' => 'Pass Captain Partner', 'capabilities' => array() ),
		'wpcap_finance'       => array( 'name' => 'Buchhaltung (eigener Name)', 'capabilities' => array() ),
		'wpcap_support'       => array( 'name' => 'Pass Captain Support', 'capabilities' => array() ),
	),
	'role_names' => array(),
	'use_db'     => true,
	'role_key'   => 'x4_a362_roles',
);
Installer::rename_legacy_roles( $a362_roles );
check( 'A362: a name stored translated at activation becomes the source name', 'Pass Captain Einlasspersonal' === $a362_roles->roles['wpcap_checkin_staff']['name'] && 'Pass Captain Einlasspersonal' === $a362_roles->role_names['wpcap_checkin_staff'] );
check( 'A362: the former default name is still renamed', 'Pass Captain Veranstalter' === $a362_roles->roles['wpcap_partner']['name'] );
check( 'A362: an operator-chosen name is kept', 'Buchhaltung (eigener Name)' === $a362_roles->roles['wpcap_finance']['name'] && ! isset( $a362_roles->role_names['wpcap_finance'] ) );
check( 'A362: the normalised names are saved', is_array( get_option( 'x4_a362_roles' ) ) && 'Pass Captain Einlasspersonal' === get_option( 'x4_a362_roles' )['wpcap_checkin_staff']['name'] );
delete_option( 'x4_a362_roles' );
$GLOBALS['wpcap_test_x'] = array();
