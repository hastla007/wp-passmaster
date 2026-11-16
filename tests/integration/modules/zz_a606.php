<?php
// phpcs:ignoreFile
/**
 * A606: "occurrence over" has one definition – Occurrence::effective_end()/end_sql() (ends_at, else
 * start + configured default duration) – also for late payments, retention runs (privacy, waitlist,
 * guest lists, buyer limits, partner codes) and the capacity counter reconcile scope.
 */

use PassCaptain\Occurrence;
use PassCaptain\Orders;

echo "A606: one definition of the effective end\n";
$GLOBALS['wpcap_test_filters']['wpcap_occurrence_default_duration'] = 3 * HOUR_IN_SECONDS;
$a606_occ = (object) array( 'starts_at' => '2026-12-24 19:00:00', 'ends_at' => null );
check( 'A606: Orders::occurrence_end() follows the configured default duration', '2026-12-24 22:00:00' === Orders::occurrence_end( $a606_occ ) && Occurrence::effective_end( $a606_occ ) === Orders::occurrence_end( $a606_occ ) );
check( 'A606: an end before the start counts as the start (as in SQL GREATEST)', '2026-12-24 19:00:00' === Orders::occurrence_end( (object) array( 'starts_at' => '2026-12-24 19:00:00', 'ends_at' => '2026-12-24 18:00:00' ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_occurrence_default_duration'] );
$a606_m = new ReflectionMethod( PassCaptain\Privacy\Retention::class, 'end_sql' );
$a606_m->setAccessible( true );
check( 'A606: the privacy retention uses the central SQL end', Occurrence::end_sql( 'o' ) === $a606_m->invoke( null ) );
$a606_left = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $a606_f ) {
	if ( '.php' !== substr( (string) $a606_f, -4 ) ) { continue; }
	if ( preg_match( '/COALESCE\(\s*[a-z]+\.ends_at\s*,\s*DATE_ADD\(/i', (string) file_get_contents( (string) $a606_f ) ) ) { $a606_left[] = basename( (string) $a606_f ); }
}
check( 'A606: no own COALESCE(ends_at, DATE_ADD(starts_at …)) end definition is left in src/' . ( $a606_left ? ' – ' . implode( ', ', $a606_left ) : '' ), ! $a606_left );
