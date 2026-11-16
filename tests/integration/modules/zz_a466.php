<?php
/**
 * A466: a schema upgrade that keeps failing (missing tables/columns, e.g. no ALTER right) waits with
 * a growing backoff instead of running dbDelta on every admin request, every cron run and every
 * overdue frontend request; a new target version, WP-CLI and the admin button retry at once; a
 * failed install schedules no events.
 */

use PassCaptain\Installer;
use PassCaptain\Plugin;

echo "A466: failing schema upgrade with backoff\n";

if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ?? false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] = (int) $ts; return true; } }

global $wpdb;
$a466_options = array();
foreach ( array( 'wpcap_schema_error', 'wpcap_db_version', 'wpcap_schema_fingerprint', Installer::ATTEMPT_OPTION ) as $a466_name ) { $a466_options[ $a466_name ] = get_option( $a466_name ); }
$a466_cron = $GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ] ?? null;
unset( $GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ] );

// Guard: an install() started by maybe_upgrade() would recreate every table here (test dbDelta).
// The first schema statement throws instead, so the test only sees *whether* it was attempted.
$a466_attempts = 0;
$a466_guard = static function ( $sql ) use ( &$a466_attempts ) {
	if ( preg_match( '/^\s*(DROP|CREATE) TABLE/i', (string) $sql ) ) {
		++$a466_attempts;
		throw new RuntimeException( 'a466: schema statement blocked' );
	}
	return $sql;
};
add_filter( 'query', $a466_guard );
$a466_try = static function ( $context ) use ( &$a466_attempts ) {
	$before = $a466_attempts;
	try {
		Installer::maybe_upgrade( $context );
	} catch ( RuntimeException $e ) {
		// Attempted (and aborted by the guard).
		unset( $e );
	}
	return $a466_attempts > $before;
};

$a466_record = new ReflectionMethod( Installer::class, 'record_failure' );
$a466_record->setAccessible( true );
delete_option( 'wpcap_schema_error' );
$a466_record->invoke( null, array( $wpdb->prefix . 'wpcap_orders.new_column' ), 'ALTER command denied' );
$a466_e1 = get_option( 'wpcap_schema_error' );
check( 'failure stored with target version, 1 attempt and a retry time 5 minutes ahead', is_array( $a466_e1 ) && Installer::version() === $a466_e1['target'] && 1 === $a466_e1['attempts'] && abs( $a466_e1['retry_at'] - ( time() + 300 ) ) <= 2 );
$a466_record->invoke( null, array( $wpdb->prefix . 'wpcap_orders.new_column' ), 'ALTER command denied' );
$a466_record->invoke( null, array( $wpdb->prefix . 'wpcap_orders.new_column' ), 'ALTER command denied' );
$a466_e3 = get_option( 'wpcap_schema_error' );
check( 'backoff doubles per failed attempt (5, 10, 20 minutes)', 3 === $a466_e3['attempts'] && abs( $a466_e3['retry_at'] - ( time() + 1200 ) ) <= 2 );
update_option( 'wpcap_schema_error', array_merge( $a466_e3, array( 'attempts' => 40 ) ) );
$a466_record->invoke( null, array( 'x' ), '' );
check( 'backoff is capped at 6 hours', abs( get_option( 'wpcap_schema_error' )['retry_at'] - ( time() + 6 * 3600 ) ) <= 2 );
check( 'audit entry per failed attempt with attempt number', 41 === (int) json_decode( (string) $wpdb->get_var( "SELECT details FROM {$wpdb->prefix}wpcap_audit_logs WHERE action='schema_upgrade_failed' ORDER BY audit_id DESC LIMIT 1" ), true )['attempts'] );

// The site is "behind": stored version differs, the failure is waiting for its next attempt.
update_option( 'wpcap_db_version', '3.0.0+old' );
update_option( 'wpcap_schema_fingerprint', 'outdated' );
update_option( 'wpcap_schema_error', array( 'missing' => array( 'x' ), 'at' => time(), 'db_error' => '', 'target' => Installer::version(), 'attempts' => 2, 'retry_at' => time() + 600 ) );
check( 'retry_at() reports the waiting attempt', Installer::retry_at() > time() );
check( 'admin request during the backoff does not run dbDelta again', false === $a466_try( 'admin' ) );
check( 'cron run during the backoff does not run dbDelta again', false === $a466_try( 'cron' ) );
unset( $GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ] );
check( 'frontend request during the backoff does not run it either', false === $a466_try( 'frontend' ) );
$a466_next = wp_next_scheduled( Installer::UPGRADE_HOOK );
check( 'frontend schedules the deferred upgrade at the retry time, not now', $a466_next && abs( (int) $a466_next - ( time() + 600 ) ) <= 2 );
$GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ][ md5( serialize( array() ) ) ] = time() - 3600; // "overdue"
check( 'an overdue event does not make a frontend request upgrade during the backoff', false === $a466_try( 'frontend' ) );
check( 'WP-CLI tries at once', true === $a466_try( 'cli' ) );

// A new plugin version (other target) is not held back by the failure of the previous one.
delete_option( Installer::ATTEMPT_OPTION );
update_option( 'wpcap_schema_error', array( 'missing' => array( 'x' ), 'at' => time(), 'db_error' => '', 'target' => '3.0.0+other', 'attempts' => 5, 'retry_at' => time() + 3600 ) );
check( 'failure of another target version does not block', 0 === Installer::retry_at() && true === $a466_try( 'admin' ) );
// After the backoff the next admin request retries.
delete_option( Installer::ATTEMPT_OPTION );
update_option( 'wpcap_schema_error', array( 'missing' => array( 'x' ), 'at' => time() - 900, 'db_error' => '', 'target' => Installer::version(), 'attempts' => 1, 'retry_at' => time() - 1 ) );
check( 'after the backoff an admin request retries', true === $a466_try( 'admin' ) );
// Retry button / WP-CLI command.
update_option( 'wpcap_schema_error', array( 'missing' => array( 'x' ), 'at' => time(), 'db_error' => '', 'target' => Installer::version(), 'attempts' => 3, 'retry_at' => time() + 3600 ) );
Installer::allow_retry();
check( 'allow_retry() (button, wp pass-captain upgrade) lifts the backoff, attempts are kept', 0 === Installer::retry_at() && 3 === get_option( 'wpcap_schema_error' )['attempts'] && true === $a466_try( 'admin' ) );

// Attempts that die mid-upgrade (time limit, fatal error) leave no failure record, only the marker
// of the unfinished attempt: the first one is repeated at once, then the same backoff applies.
delete_option( 'wpcap_schema_error' );
delete_option( Installer::ATTEMPT_OPTION );
check( 'an upgrade that died is repeated at once by the next request', true === $a466_try( 'admin' ) && 1 === (int) get_option( Installer::ATTEMPT_OPTION )['count'] && true === $a466_try( 'admin' ) );
check( 'from the second unfinished attempt on, requests wait (5 minutes, then doubling)', 2 === (int) get_option( Installer::ATTEMPT_OPTION )['count'] && abs( Installer::retry_at() - ( time() + 300 ) ) <= 2 && false === $a466_try( 'admin' ) && false === $a466_try( 'cron' ) );
update_option( Installer::ATTEMPT_OPTION, array( 'target' => Installer::version(), 'count' => 4, 'at' => time() ) );
check( 'backoff of unfinished attempts doubles (4th: 20 minutes)', abs( Installer::retry_at() - ( time() + 1200 ) ) <= 2 );
update_option( Installer::ATTEMPT_OPTION, array( 'target' => '3.0.0+other', 'count' => 9, 'at' => time() ) );
check( 'unfinished attempts of another target do not block', 0 === Installer::retry_at() );
delete_option( Installer::ATTEMPT_OPTION );

remove_filter( 'query', $a466_guard );
check( 'the upgrade lock is released after an aborted attempt', '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', Installer::lock_name() ) ) );

// No events after a failed install: activation and the deferred upgrade only schedule after success.
$a466_src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );
check( 'activation schedules no events when install() failed', false !== strpos( $a466_src, "if ( false === Installer::install() ) { return false; }\n\t\tself::schedule_events();" ) );
$a466_isrc = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Installer.php' );
check( 'maybe_upgrade() reports a failed install as not run (no events scheduled after it)', false !== strpos( $a466_isrc, '$ran = false !== self::install();' ) );
check( 'a completed install clears the marker of its attempt (bootstrap install left none)', false === $a466_options[ Installer::ATTEMPT_OPTION ] );

// Admin notice: attempts, next attempt and the retry button.
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce">'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
update_option( 'wpcap_schema_error', array( 'missing' => array( 'wpr_wpcap_orders.x' ), 'at' => time(), 'db_error' => 'denied', 'target' => Installer::version(), 'attempts' => 2, 'retry_at' => time() + 600 ) );
ob_start();
PassCaptain\Admin\Menu::schema_error_notice();
$a466_html = ob_get_clean();
check( 'notice names the failed attempts, the next attempt and offers a retry button', false !== strpos( $a466_html, '2 Versuche fehlgeschlagen' ) && false !== strpos( $a466_html, 'value="' . PassCaptain\Admin\Menu::SCHEMA_RETRY_ACTION . '"' ) && false !== strpos( $a466_html, 'erneut versuchen' ) );
check( 'retry handler is registered', isset( PassCaptain\Admin\Menu::handlers()[ PassCaptain\Admin\Menu::SCHEMA_RETRY_ACTION ] ) );

foreach ( $a466_options as $a466_name => $a466_value ) {
	if ( false === $a466_value ) { delete_option( $a466_name ); } else { update_option( $a466_name, $a466_value ); }
}
if ( null === $a466_cron ) { unset( $GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ] ); } else { $GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ] = $a466_cron; }
