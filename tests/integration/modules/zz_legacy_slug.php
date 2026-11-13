<?php
/**
 * Rename "WP Pass Captain" (wp-pass-captain) → "Pass Captain" (pass-captain): old REST namespace,
 * signed callback URLs, old admin page slugs, old plugin copy (takeover, paused data deletion).
 * Old names are assembled from parts so the repository-wide rename leaves them alone.
 */

use PassCaptain\LegacySlug;

$ls_old = 'wp-' . 'pass-captain';
$ls_req = static function ( $route ) {
	return new class( $route ) {
		public function __construct( public $route ) {}
		public function get_route() { return $this->route; }
		public function set_route( $r ) { $this->route = $r; }
	};
};
$ls_resp = static function () {
	return new class() {
		public $headers = array();
		public function header( $k, $v ) { $this->headers[ $k ] = $v; }
		public function get_status() { return 200; }
	};
};

// REST: routing of the old namespace.
LegacySlug::reset();
check( 'legacy REST: old namespace maps to the new one', '/pass-captain/v1/payments/stripe/webhook' === LegacySlug::map_route( '/' . $ls_old . '/v1/payments/stripe/webhook' ) && '/pass-captain/v1' === LegacySlug::map_route( '/' . $ls_old . '/v1' ) );
check( 'legacy REST: other routes are not touched (new namespace, other plugins, look-alikes)', null === LegacySlug::map_route( '/pass-captain/v1/events' ) && null === LegacySlug::map_route( '/wp/v2/posts' ) && null === LegacySlug::map_route( '/' . $ls_old . '/v10/x' ) && null === LegacySlug::map_route( '/' . $ls_old . '-x/v1/y' ) );
$ls_r = $ls_req( '/' . $ls_old . '/v1/sms/twilio/status' );
$ls_out = LegacySlug::route_legacy_namespace( null, null, $ls_r );
check( 'legacy REST: rest_pre_dispatch rewrites the request route and does not answer itself', null === $ls_out && '/pass-captain/v1/sms/twilio/status' === $ls_r->get_route() && LegacySlug::is_legacy_request() );
$ls_resp1 = $ls_resp();
LegacySlug::deprecation_headers( $ls_resp1, null, $ls_r );
check( 'legacy REST: answer carries Deprecation and a Link to the new URL', 'true' === ( $ls_resp1->headers['Deprecation'] ?? '' ) && false !== strpos( (string) ( $ls_resp1->headers['Link'] ?? '' ), 'pass-captain/v1/sms/twilio/status' ) && false === strpos( (string) ( $ls_resp1->headers['Link'] ?? '' ), $ls_old ) && false !== strpos( (string) $ls_resp1->headers['Link'], 'successor-version' ) );
check( 'legacy REST: signature URL is the URL actually called (Twilio signs the old one)', 'https://example.test/wp-json/' . $ls_old . '/v1/sms/twilio/status' === LegacySlug::called_url( 'https://example.test/wp-json/pass-captain/v1/sms/twilio/status' ) && 'https://example.test/?rest_route=/' . $ls_old . '/v1/sms/twilio/inbound' === LegacySlug::called_url( 'https://example.test/?rest_route=/pass-captain/v1/sms/twilio/inbound' ) );
// Twilio status callback over the old URL: valid signature for the old URL is accepted.
$ls_token = 'twilio-test-token';
$ls_params = array( 'MessageSid' => 'SM1', 'MessageStatus' => 'delivered' );
$ls_old_url = LegacySlug::called_url( PassCaptain\Sms\TwilioProvider::status_callback_url() );
ksort( $ls_params );
$ls_data = $ls_old_url;
foreach ( $ls_params as $k => $v ) { $ls_data .= $k . $v; }
$ls_sig = base64_encode( hash_hmac( 'sha1', $ls_data, $ls_token, true ) );
check( 'legacy REST: Twilio signature over the old URL validates against called_url()', PassCaptain\Sms\TwilioProvider::signature_valid( LegacySlug::called_url( PassCaptain\Sms\TwilioProvider::status_callback_url() ), $ls_params, $ls_sig, $ls_token ) && ! PassCaptain\Sms\TwilioProvider::signature_valid( PassCaptain\Sms\TwilioProvider::status_callback_url(), $ls_params, $ls_sig, $ls_token ) );
LegacySlug::reset();
$ls_resp2 = $ls_resp();
LegacySlug::deprecation_headers( $ls_resp2, null, $ls_req( '/pass-captain/v1/events' ) );
check( 'new namespace: no Deprecation header, URLs unchanged', array() === $ls_resp2->headers && ! LegacySlug::is_legacy_request() && 'https://x.test/wp-json/pass-captain/v1/a' === LegacySlug::called_url( 'https://x.test/wp-json/pass-captain/v1/a' ) );
check( 'already answered requests are passed through', 'done' === LegacySlug::route_legacy_namespace( 'done', null, $ls_req( '/' . $ls_old . '/v1/events' ) ) && ! LegacySlug::is_legacy_request() );

// Admin page slugs.
check( 'legacy admin: old page slugs map to the new ones', 'pass-captain' === LegacySlug::map_admin_page( $ls_old ) && 'pass-captain-orders' === LegacySlug::map_admin_page( $ls_old . '-orders' ) && null === LegacySlug::map_admin_page( 'pass-captain' ) && null === LegacySlug::map_admin_page( 'wpcap-orders' ) && null === LegacySlug::map_admin_page( $ls_old . 'x' ) );
check( 'new admin slug is pass-captain', 'pass-captain' === PassCaptain\Admin\Menu::SLUG );

// Every registered REST route uses the new namespace; the old one is not registered twice.
$ls_src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $ls_file ) {
	if ( 'php' === $ls_file->getExtension() && 'LegacySlug.php' !== $ls_file->getFilename() ) { $ls_src .= file_get_contents( $ls_file->getPathname() ); }
}
check( 'source: no old slug outside the compatibility layer (block names excepted)', 0 === preg_match( '#' . preg_quote( $ls_old, '#' ) . '(?!/(?:events|calendar)\b)#', $ls_src ) );
check( 'source: text domain pass-captain, plugin main file pass-captain.php', false !== strpos( $ls_src, "'pass-captain' )" ) && is_file( WPCAP_PATH . 'pass-captain.php' ) && ! is_file( WPCAP_PATH . $ls_old . '.php' ) );
$ls_header = (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' );
check( 'plugin header: name "Pass Captain", text domain pass-captain', 1 === preg_match( '/^ \* Plugin Name: Pass Captain$/m', $ls_header ) && 1 === preg_match( '/^ \* Text Domain: pass-captain$/m', $ls_header ) );

// Old plugin copy: data deletion paused while its folder exists, restored afterwards.
// Same cron stand-in layout as zz_phase1_arch.php (whichever test defines them first).
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] = (int) $ts; return true; } }
$GLOBALS['wpcap_test_cron'] = array();
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/wpcap-plugins-' . getmypid() ); }
$ls_dir = WP_PLUGIN_DIR . '/' . $ls_old;
@mkdir( $ls_dir, 0777, true );
file_put_contents( $ls_dir . '/' . $ls_old . '.php', "<?php\n" );
$ls_settings_before = get_option( 'wpcap_settings', array() );
update_option( 'wpcap_settings', array_merge( (array) $ls_settings_before, array( 'delete_data_on_uninstall' => 1 ) ) );
delete_option( LegacySlug::PARKED_OPTION );
delete_option( LegacySlug::SEEN_OPTION );
LegacySlug::watch_legacy_copy();
$ls_s1 = (array) get_option( 'wpcap_settings', array() );
check( 'old copy installed: delete-on-uninstall is paused (its uninstall.php would drop the shared tables)', LegacySlug::legacy_installed() && empty( $ls_s1['delete_data_on_uninstall'] ) && 1 === (int) get_option( LegacySlug::PARKED_OPTION ) && 1 === (int) get_option( LegacySlug::SEEN_OPTION ) );
ob_start();
LegacySlug::notice();
$ls_notice = ob_get_clean();
check( 'old copy installed: admin notice names its folder and says data is kept', false !== strpos( $ls_notice, $ls_old ) && false !== strpos( $ls_notice, 'pausiert' ) );
unlink( $ls_dir . '/' . $ls_old . '.php' );
@rmdir( $ls_dir );
LegacySlug::watch_legacy_copy();
$ls_s2 = (array) get_option( 'wpcap_settings', array() );
$GLOBALS['wpcap_test_cron'] = array();
$ls_ver = get_option( 'wpcap_db_version', '' );
update_option( 'wpcap_db_version', '' === (string) $ls_ver ? '3.10.6' : $ls_ver );
PassCaptain\Plugin::ensure_events();
check( 'cron self-healing: missing events (e.g. removed by the old copy\'s uninstall.php) are re-scheduled on init', false !== wp_next_scheduled( 'wpcap_process_jobs' ) );
update_option( 'wpcap_db_version', $ls_ver );
check( 'old copy removed: delete-on-uninstall restored, markers cleared', ! LegacySlug::legacy_installed() && 1 === (int) ( $ls_s2['delete_data_on_uninstall'] ?? 0 ) && ! get_option( LegacySlug::PARKED_OPTION ) && ! get_option( LegacySlug::SEEN_OPTION ) );
update_option( LegacySlug::TAKEOVER_OPTION, 1 );
LegacySlug::watch_legacy_copy();
check( 'takeover from the old copy is finished on the next admin request', ! get_option( LegacySlug::TAKEOVER_OPTION ) );
update_option( 'wpcap_settings', $ls_settings_before );

// Uninstall of this copy keeps the data while the old copy exists (static check, uninstall.php is not run here).
$ls_uninstall = (string) file_get_contents( WPCAP_PATH . 'uninstall.php' );
check( 'uninstall.php keeps the data while the old copy is installed', 1 === preg_match( "#is_file\\( WP_PLUGIN_DIR \\. '/" . preg_quote( $ls_old, '#' ) . "/" . preg_quote( $ls_old, '#' ) . "\\.php' \\) \\).*?return false;#s", $ls_uninstall ) );
// Main file: takeover guard runs before any constant or the autoloader.
check( 'main file: takeover guard comes before define(WPCAP_VERSION) and the autoloader', strpos( $ls_header, 'wpcap_take_over_legacy_copy() ) {' ) < strpos( $ls_header, "define( 'WPCAP_VERSION'" ) && strpos( $ls_header, "define( 'WPCAP_VERSION'" ) < strpos( $ls_header, 'vendor/autoload.php' ) );
$GLOBALS['wpcap_test_cron'] = array();
