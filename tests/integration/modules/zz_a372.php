<?php
/**
 * A372: uninstall (with data removal) removes every plugin capability from roles and users, also
 * the module capabilities wpcap_approve_comps (guest lists) and wpcap_boxoffice_override; the list
 * comes from PassCaptain\Capabilities, and every *_CAP constant in src/ naming a wpcap_ capability
 * is part of it. uninstall.php runs in a child process (zz_x2_uninstall/child.php).
 * Included from run.php (uses check()).
 */
// phpcs:ignoreFile

require_once dirname( __DIR__ ) . '/lib/fixtures_shared.php';
use PassCaptain\Capabilities;

echo "A372: uninstall removes module capabilities\n";


$x2_a372_all = Capabilities::all();
$x2_a372 = x2_uninstall_run( array(
	'multisite' => false,
	'sites' => array( 1 => array( 'data' => true, 'delete' => true ) ),
	'roles' => array( 'administrator' => array_merge( array( 'manage_options' ), $x2_a372_all ), 'wpcap_ticket_admin' => $x2_a372_all, 'editor' => array( 'edit_posts', 'wpcap_approve_comps' ) ),
	'user_caps' => array( 'read', 'wpcap_approve_comps', 'wpcap_boxoffice_override', 'wpcap_checkin' ),
) );
$x2_a372_left = array_values( array_filter( (array) ( $x2_a372['sites'][1]['caps'] ?? array( 'missing' ) ), static fn( $c ) => 0 === strpos( $c, 'wpcap_' ) || 'missing' === $c ) );
check( 'A372: no wpcap_* capability is left on any role or user after uninstall' . ( $x2_a372_left ? ' – ' . implode( ', ', $x2_a372_left ) : '' ), array() === $x2_a372_left );
check( 'A372: other capabilities stay', array() === array_diff( array( 'manage_options', 'edit_posts', 'read' ), (array) ( $x2_a372['sites'][1]['caps'] ?? array() ) ) );
check( 'A372: the role package list is part of the full list', array() === array_diff( PassCaptain\Installer::CAPABILITIES, $x2_a372_all ) && in_array( 'wpcap_approve_comps', $x2_a372_all, true ) );

// Every capability constant in src/ that names a wpcap_ capability is in Capabilities::all().
$x2_a372_found = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $x2_a372_f ) {
	if ( '.php' !== substr( (string) $x2_a372_f, -4 ) ) { continue; }
	preg_match_all( "/const\s+[A-Z_]*CAP\s*=\s*'(wpcap_[a-z_]+)'/", (string) file_get_contents( (string) $x2_a372_f ), $x2_a372_m );
	$x2_a372_found = array_merge( $x2_a372_found, $x2_a372_m[1] );
}
$x2_a372_missing = array_values( array_diff( array_unique( $x2_a372_found ), $x2_a372_all ) );
check( 'A372: every wpcap_ *_CAP constant is listed in Capabilities' . ( $x2_a372_missing ? ' – missing ' . implode( ', ', $x2_a372_missing ) : '' ), $x2_a372_found && ! $x2_a372_missing );
