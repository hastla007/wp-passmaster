<?php
/**
 * A104: hook prefixes unified, old names keep working as deprecated aliases.
 */

use PassCaptain\Hooks;
use PassCaptain\Jobs;
use PassCaptain\Localization;

echo "A104: unified hook prefixes with deprecated aliases\n";

$a104_root = dirname( __DIR__, 3 );
$a104_src  = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $a104_root . '/src' ) ) as $a104_f ) {
	if ( $a104_f->isFile() && 'php' === $a104_f->getExtension() ) { $a104_src .= file_get_contents( $a104_f->getPathname() ); }
}
preg_match_all( "/(?:do_action|apply_filters|add_action|add_filter)\(\s*'([a-z0-9_]+)'/", $a104_src, $a104_m );
$a104_bad = array();
$a104_contracts = array( 'wp_pass_captain_voucher_provider_v1', 'wp_pass_captain_ticket_service_v1', 'wp_pass_captain_checkin_service_v1', 'wp_pass_captain_voucher_tickets_revoked_v1', 'wp_pass_captain_wallet_provider_v1', 'wp_pass_captain_connectors_v1', 'wp_pass_captain_connector_error_v1' );
foreach ( array_unique( $a104_m[1] ) as $a104_h ) {
	if ( 0 === strpos( $a104_h, 'wp_pass_captain_' ) && ! in_array( $a104_h, $a104_contracts, true ) ) { $a104_bad[] = $a104_h; }
}
check( 'only the versioned cross-plugin contracts keep the wp_pass_captain_ prefix' . ( $a104_bad ? ' – found: ' . implode( ', ', $a104_bad ) : '' ), ! $a104_bad );
foreach ( Hooks::RENAMED as $a104_old => $a104_new ) {
	check( "renamed hook $a104_old is no longer used literally in src/", false === strpos( preg_replace( '/const RENAMED = array\(.*?\);/s', '', $a104_src ), "'$a104_old'" ) );
	check( "new hook $a104_new is documented and the old name listed as deprecated", false !== strpos( file_get_contents( $a104_root . '/docs/HOOKS.md' ), "| `$a104_old` | `$a104_new` |" ) );
}

// Deprecated alias still runs, after the new hook, and is reported as deprecated.
$GLOBALS['wpcap_test_deprecated_hooks'] = array();
$a104_new_cb = static function ( $list ) { $list[] = 'NEW'; return $list; };
$a104_old_cb = static function ( $list ) { $list[] = 'OLD'; return $list; };
add_filter( 'wpcap_currencies', $a104_new_cb );
add_filter( 'wp_pass_captain_currencies', $a104_old_cb );
$a104_cur = Localization::currencies();
check( 'callbacks on the new and the old currency hook both apply (old one after the new one)', false !== ( $a104_n = array_search( 'NEW', $a104_cur, true ) ) && false !== ( $a104_o = array_search( 'OLD', $a104_cur, true ) ) && $a104_n < $a104_o );
check( 'the old name is reported via apply_filters_deprecated with the replacement', in_array( array( 'wp_pass_captain_currencies', Hooks::DEPRECATED_SINCE, 'wpcap_currencies' ), $GLOBALS['wpcap_test_deprecated_hooks'], true ) );
remove_filter( 'wp_pass_captain_currencies', $a104_old_cb );
$GLOBALS['wpcap_test_deprecated_hooks'] = array();
Localization::currencies();
check( 'no deprecation notice when nothing is registered on the old name', array() === $GLOBALS['wpcap_test_deprecated_hooks'] );
remove_filter( 'wpcap_currencies', $a104_new_cb );

// A job handled only by a legacy handler still completes.
global $wpdb;
$a104_seen = array();
$a104_legacy_job = static function ( $handled, $type, $payload ) use ( &$a104_seen ) {
	if ( 'a104_legacy' !== $type ) { return $handled; }
	$a104_seen[] = $payload['x'] ?? null;
	return true;
};
add_filter( 'wp_pass_captain_process_job_v1', $a104_legacy_job, 10, 3 );
Jobs::enqueue( 'a104-legacy-1', 'a104_legacy', array( 'x' => 7 ) );
Jobs::process_due( 50 );
$a104_status = $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_jobs WHERE job_type='a104_legacy' ORDER BY job_id DESC LIMIT 1" );
check( 'a job handler registered on the old job hook still runs and completes the job', array( 7 ) === $a104_seen && 'complete' === $a104_status );
remove_filter( 'wp_pass_captain_process_job_v1', $a104_legacy_job, 10 );

// Shortcode aliases.
check( 'shortcodes with wpcap_ prefix are registered next to the old names', false !== strpos( $a104_src, "add_shortcode( 'wpcap_events'" ) && false !== strpos( $a104_src, "add_shortcode( 'wpcap_scanner'" ) && false !== strpos( $a104_src, "add_shortcode( 'wpcap_calendar'" ) && false !== strpos( $a104_src, "add_shortcode( 'wp_pass_captain_events'" ) );
