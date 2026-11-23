<?php
/**
 * A479: cross-site checks for a subdirectory network (sites share origin and users). Access scope
 * per site: zz_a470.php; staff assignment: zz_a471.php; browser storage of the web scanner and the
 * offline PWA: tests/js/scanner.test.js and tests/js/offline-scanner-sites.test.js. Here: the keys
 * the server hands to the scanner for its browser storage.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Privacy;

global $wpdb;

echo "A479: scanner storage keys on a subdirectory network\n";
$a479_prefix = $wpdb->prefix;
$GLOBALS['wpcap_test_multisite'] = true;
$GLOBALS['wpcap_test_blog_id'] = 1;
$a479_site1 = Privacy::scanner_site();
$a479_owner1 = Privacy::scanner_owner( 7 );
$GLOBALS['wpcap_test_blog_id'] = 2;
$wpdb->prefix = $a479_prefix . 'n2_';
$a479_site2 = Privacy::scanner_site();
$a479_owner2 = Privacy::scanner_owner( 7 );
$wpdb->prefix = $a479_prefix;
$GLOBALS['wpcap_test_blog_id'] = 1;
check( 'A479: each site gets its own storage key for the scanner queue', 12 === strlen( $a479_site1 ) && $a479_site1 !== $a479_site2 && $a479_site1 === Privacy::scanner_site() );
check( 'A479: the user key is the same on every site (users are network-wide; device privacy rule)', $a479_owner1 === $a479_owner2 && $a479_owner1 !== Privacy::scanner_owner( 8 ) );
check( 'A479: site and user keys contain neither ids nor URL', false === strpos( $a479_site1, 'example' ) && 1 === preg_match( '/^[a-f0-9]+$/', $a479_site1 . $a479_owner1 ) );
check( 'A479: the scanner script receives the site key', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Privacy.php' ), "'site' => self::scanner_site()" ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/scanner.js' ), 'privacy.site' ) );
// After the merge the Arbeitsliste scanner tests live in tests/js/scanner-arbeitsliste.test.js (tests/js/scanner.test.js
// is the audit line's suite); either file may carry the A472 test once the JS suites are consolidated.
$a479_js = array( 'tests/js/scanner-arbeitsliste.test.js|tests/js/scanner.test.js' => 'A472: offline queues of two sites on one origin stay apart', 'tests/js/offline-scanner-sites.test.js' => 'A473: each site of the network gets its own store and pairing' );
$a479_missing = array();
foreach ( $a479_js as $a479_files => $a479_name ) {
	$a479_found = false;
	foreach ( explode( '|', $a479_files ) as $a479_file ) {
		if ( false !== strpos( (string) @file_get_contents( dirname( __DIR__, 3 ) . '/' . $a479_file ), $a479_name ) ) { $a479_found = true; }
	}
	if ( ! $a479_found ) { $a479_missing[] = $a479_files; }
}
check( 'A479: the cross-site browser storage tests exist (npm run test:js)' . ( $a479_missing ? ' – missing ' . implode( ', ', $a479_missing ) : '' ), ! $a479_missing );
$GLOBALS['wpcap_test_multisite'] = false;
