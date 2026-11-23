<?php
/**
 * A400: operational alerts go through Alerts::raise() (recipient setting/filter, configured sender, stored for the
 * status page) instead of direct wp_mail() calls to admin_email.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Alerts;
use PassCaptain\Settings;

echo "A400: operational alerts\n";
$a400_admin = get_option( 'admin_email' );
update_option( 'admin_email', 'site-admin@example.com' );
$a400_settings = get_option( 'wpcap_settings', array() );
Alerts::clear();
$GLOBALS['wpcap_test_mails'] = array();
check( 'A400: without a setting the site admin gets the alert', array( 'site-admin@example.com' ) === Alerts::recipients() && Alerts::raise( 'test_topic', 'Pass Captain: Test', 'Body' ) && 'site-admin@example.com' === ( $GLOBALS['wpcap_test_mails'][0]['to'] ?? '' ) );
check( 'A400: the alert is stored for the status page', 'Pass Captain: Test' === ( Alerts::recent( 1 )[0]['subject'] ?? '' ) && 'test_topic' === Alerts::recent( 1 )[0]['topic'] );
update_option( 'wpcap_settings', array_merge( (array) $a400_settings, array( 'alert_email' => 'ops@example.com' ) ) );
if ( method_exists( Settings::class, 'flush' ) ) { Settings::flush(); }
$GLOBALS['wpcap_test_mails'] = array();
Alerts::raise( 'test_topic', 'Pass Captain: Test 2', 'Body' );
check( 'A400: the alert_email setting replaces the admin e-mail', 'ops@example.com' === ( $GLOBALS['wpcap_test_mails'][0]['to'] ?? '' ) );
$a400_route = static function ( $list, $topic ) { return 'refund_approval' === $topic ? array( 'finance@example.com', 'FINANCE@example.com', 'not-an-address' ) : $list; };
add_filter( 'wpcap_alert_recipients', $a400_route, 10, 2 );
check( 'A400: the filter routes a topic to other people (deduplicated, valid only)', array( 'finance@example.com' ) === Alerts::recipients( 'refund_approval' ) && array( 'ops@example.com' ) === Alerts::recipients( 'other' ) );
remove_filter( 'wpcap_alert_recipients', $a400_route, 10 );
$a400_seen = array();
$a400_hook = static function ( $topic ) use ( &$a400_seen ) { $a400_seen[] = $topic; };
add_action( 'wpcap_alert', $a400_hook, 10, 1 );
$a400_hold = static function () { return false; };
add_filter( 'wpcap_alert_send', $a400_hold );
$GLOBALS['wpcap_test_mails'] = array();
$a400_sent = Alerts::raise( 'held', 'Pass Captain: gehalten', 'Body' );
check( 'A400: wpcap_alert_send=false keeps the mail back, the action still fires and the alert is stored', false === $a400_sent && array() === $GLOBALS['wpcap_test_mails'] && in_array( 'held', $a400_seen, true ) && 'held' === Alerts::recent( 1 )[0]['topic'] );
remove_filter( 'wpcap_alert_send', $a400_hold );
remove_action( 'wpcap_alert', $a400_hook, 10 );
for ( $i = 0; $i < 40; $i++ ) { Alerts::raise( 'bulk', 'x' . $i, '' ); }
check( 'A400: only the latest 30 alerts are kept', 30 === count( get_option( Alerts::OPTION, array() ) ) && 'x39' === Alerts::recent( 1 )[0]['subject'] );
// No direct admin mail left in the plugin code.
$a400_direct = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $a400_file ) {
	if ( 'php' !== $a400_file->getExtension() ) { continue; }
	$a400_code = (string) file_get_contents( $a400_file->getPathname() );
	if ( preg_match( "/wp_mail\\(\\s*get_option\\( 'admin_email'/", $a400_code ) ) { $a400_direct[] = basename( $a400_file->getPathname() ); }
}
check( 'A400: no wp_mail( admin_email ) left in src (' . implode( ', ', $a400_direct ) . ')', array() === $a400_direct );
check( 'A400: the settings offer the alert recipient', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/SettingsPage.php' ), "'alert_email' => array( 'type' => 'email'" ) );
Alerts::clear();
update_option( 'wpcap_settings', $a400_settings );
if ( method_exists( Settings::class, 'flush' ) ) { Settings::flush(); }
update_option( 'admin_email', $a400_admin );
