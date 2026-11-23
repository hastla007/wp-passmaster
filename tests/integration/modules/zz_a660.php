<?php
// phpcs:ignoreFile
/**
 * A660: follow-up runs were queued in the Action Scheduler group of the old slug before the rename.
 * Pending ones are detected (no duplicate run), moved to the group pass-captain once and removed on
 * deactivation. Runs in a child process (Action Scheduler stubs must not leak into later tests).
 */

$a660_cmd = 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a660/child.php' ) . ' 2>&1';
$a660_raw = (string) shell_exec( $a660_cmd );
$a660 = json_decode( trim( $a660_raw ), true );
check( 'A660: child process ran' . ( is_array( $a660 ) ? '' : ' – ' . substr( $a660_raw, 0, 300 ) ), is_array( $a660 ) );
$a660 = is_array( $a660 ) ? $a660 : array();
check( 'A660: the old group is the pre-rename slug', 'wp-' . 'pass-captain' === ( $a660['legacy_group_name'] ?? '' ) );
check( 'A660: a pending action of the old group is detected', ! empty( $a660['detected'] ) );
check( 'A660: continue_later() queues no second run while the old-group action is pending', ! empty( $a660['no_duplicate'] ) );
check( 'A660: new follow-up runs go to the group pass-captain, once', ! empty( $a660['new_group_used'] ) && ! empty( $a660['new_group_no_duplicate'] ) );
check( 'A660: migration is hooked to action_scheduler_init', ! empty( $a660['hooked'] ) );
check( 'A660: migration moves the old-group action to the new group', 1 === ( $a660['moved'] ?? null ) && ! empty( $a660['old_group_empty'] ) && ! empty( $a660['moved_to_new_group'] ) );
check( 'A660: migration leaves runs already in the new group alone', ! empty( $a660['jobs_untouched'] ) );
check( 'A660: migration runs once per site (flag), duplicates stay prevented afterwards', ! empty( $a660['flag_set'] ) && ! empty( $a660['runs_once'] ) && ! empty( $a660['no_duplicate_after_flag'] ) );
check( 'A660: deactivation removes actions of the old and the new group', ! empty( $a660['deactivate_clears_both'] ) );
