<?php
/**
 * Two-plugin contract test: Pass Captain's Form Captain adapter against the real Form Captain
 * (WordPress test suite + MariaDB/MySQL). Uses the WordPress test library, PHPUnit and the test
 * configuration of a Form Captain checkout, so Pass Captain needs no dev dependencies of its own.
 *
 * Usage (see docs/TESTING.md):
 *   FORM_CAPTAIN_DIR=/path/to/wp-formmaster WP_TESTS_DB_NAME=wp_passfc_tests \
 *     php "$FORM_CAPTAIN_DIR/vendor/bin/phpunit" -c tests/form-captain/phpunit.xml
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$wpcap_fc_dir = rtrim( getenv( 'FORM_CAPTAIN_DIR' ) ?: dirname( __DIR__, 3 ) . '/wp-formmaster', '/' );
if ( ! is_readable( $wpcap_fc_dir . '/form-captain.php' ) || ! is_readable( $wpcap_fc_dir . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Form Captain checkout with vendor/ not found; set FORM_CAPTAIN_DIR.\n" );
	exit( 2 );
}
define( 'WPCAP_FC_TEST_DIR', $wpcap_fc_dir );

require_once $wpcap_fc_dir . '/vendor/autoload.php';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $wpcap_fc_dir . '/tests/wp-tests-config.php' );
$wpcap_fc_tests = getenv( 'WP_PHPUNIT__DIR' ) ?: $wpcap_fc_dir . '/vendor/wp-phpunit/wp-phpunit';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $wpcap_fc_dir . '/vendor/yoast/phpunit-polyfills' );

require_once $wpcap_fc_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WPCAP_FC_TEST_DIR . '/form-captain.php';
		require dirname( __DIR__, 2 ) . '/pass-captain.php';
	}
);
tests_add_filter(
	'init',
	static function () {
		\FormCaptain\Install\Activator::activate();
		\PassCaptain\Installer::install();
	},
	2
);
// WP_UnitTestCase wraps each test in a transaction; Form Captain uses savepoints then.
tests_add_filter( 'form_captain_db_in_outer_transaction', '__return_true' );

require $wpcap_fc_tests . '/includes/bootstrap.php';
