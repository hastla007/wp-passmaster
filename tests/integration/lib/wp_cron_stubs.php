<?php
/**
 * WP-Cron stubs in memory ($GLOBALS['wpcap_test_cron'][hook][md5(args)] = timestamp), the definitions
 * modules/zz_a282.php introduced. Not loaded for every module like lib/wp_stubs.php: the plugin checks
 * function_exists( 'wp_next_scheduled' ) and behaves differently without cron, so only modules that
 * need cron load this file (require_once), at their position in the full run.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ?? false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] = (int) $ts; return true; } }
