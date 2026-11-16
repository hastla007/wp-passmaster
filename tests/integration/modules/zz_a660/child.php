<?php
/**
 * Worker for tests/integration/modules/zz_a660.php, in its own process: Action Scheduler is stubbed
 * here (in the main test process the as_* functions would switch every later test from WP-Cron to
 * Action Scheduler). Prints one JSON object with the observations.
 * Usage: php child.php
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';

use PassCaptain\Jobs\TimeBudget;
use PassCaptain\LegacySlug;

// Minimal Action Scheduler: pending actions as [hook, args, group].
$GLOBALS['a660_as'] = array();
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) { $GLOBALS['a660_as'][] = array( $hook, array_values( $args ), $group ); return count( $GLOBALS['a660_as'] ); }
function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['a660_as'] as $a ) {
		if ( $a[0] === $hook && ( null === $args || $a[1] === array_values( $args ) ) && ( '' === $group || $a[2] === $group ) ) { return true; }
	}
	return false;
}
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	$GLOBALS['a660_as'] = array_values( array_filter( $GLOBALS['a660_as'], static fn( $a ) => ! ( ( '' === $hook || $a[0] === $hook ) && ( '' === $hook || ! $args || $a[1] === array_values( $args ) ) && ( '' === $group || $a[2] === $group ) ) ) );
}
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }
if ( ! function_exists( 'get_current_blog_id' ) ) { function get_current_blog_id() { return 1; } }
if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $hook ) { return 0; } }

$count = static function ( $hook, $group = null ) {
	return count( array_filter( $GLOBALS['a660_as'], static fn( $a ) => $a[0] === $hook && ( null === $group || $a[2] === $group ) ) );
};
$out = array();
$old = LegacySlug::LEGACY_AS_GROUP;
delete_option( LegacySlug::AS_MIGRATED_OPTION );

// A follow-up run queued by the copy before the rename is still pending.
as_enqueue_async_action( 'wpcap_process_messages', array( 'continue' ), $old );
$out['legacy_group_name'] = $old;
$out['detected'] = LegacySlug::has_legacy_action( 'wpcap_process_messages', array( 'continue' ) );
TimeBudget::continue_later( 'wpcap_process_messages' );
$out['no_duplicate'] = 1 === $count( 'wpcap_process_messages' );
TimeBudget::continue_later( 'wpcap_process_jobs' );
$out['new_group_used'] = 1 === $count( 'wpcap_process_jobs', TimeBudget::AS_GROUP );
TimeBudget::continue_later( 'wpcap_process_jobs' );
$out['new_group_no_duplicate'] = 1 === $count( 'wpcap_process_jobs' );

// One-time migration on action_scheduler_init (registered by LegacySlug::register()).
LegacySlug::register();
$out['hooked'] = has_filter( 'action_scheduler_init' );
$out['moved'] = LegacySlug::migrate_action_scheduler_group();
$out['old_group_empty'] = 0 === $count( 'wpcap_process_messages', $old );
$out['moved_to_new_group'] = 1 === $count( 'wpcap_process_messages', TimeBudget::AS_GROUP );
$out['jobs_untouched'] = 1 === $count( 'wpcap_process_jobs' );
$out['flag_set'] = (bool) get_option( LegacySlug::AS_MIGRATED_OPTION );
as_enqueue_async_action( 'wpcap_webhooks_dispatch', array( 'continue' ), $old );
$out['runs_once'] = 0 === LegacySlug::migrate_action_scheduler_group() && 1 === $count( 'wpcap_webhooks_dispatch', $old );
// Even after the one-time run, a pending old-group action still prevents a duplicate.
TimeBudget::continue_later( 'wpcap_webhooks_dispatch' );
$out['no_duplicate_after_flag'] = 1 === $count( 'wpcap_webhooks_dispatch' );

// Deactivation removes the actions of both groups.
PassCaptain\Plugin::deactivate();
$out['deactivate_clears_both'] = array() === $GLOBALS['a660_as'];
delete_option( LegacySlug::AS_MIGRATED_OPTION );
echo json_encode( $out );
