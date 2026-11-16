<?php
// phpcs:ignoreFile
/**
 * A607: business rules read the time from Clock (filter wpcap_now) instead of time()/current_time().
 * Ratchet: the files converted here must stay free of time() and current_time('mysql', true), and no
 * file outside zz_a607/time-allowlist.txt (the files not converted yet) may start using them. Remove
 * a file from the list once it is converted.
 */

use PassCaptain\Clock;
use PassCaptain\Passes\Admission;

echo "A607: business rules use Clock\n";
$a607_pattern = "/(^|[^A-Za-z0-9_>:\\\\\$])time\\(\\)|current_time\\( 'mysql', true \\)/";
$a607_converted = array( 'Exchange/Service.php', 'PortalSelfService.php', 'OrderPortal.php', 'TicketAssignments.php', 'Seats.php', 'Seating/Reservations.php', 'Passes/Admission.php', 'Mobile/Api.php', 'Frontend/EventCard.php', 'Admin/Queries.php' );
$a607_dirty = array_values( array_filter( $a607_converted, static fn( $f ) => (bool) preg_match( $a607_pattern, (string) file_get_contents( WPCAP_PATH . 'src/' . $f ) ) ) );
check( 'A607: the converted business-rule files use only Clock' . ( $a607_dirty ? ' – ' . implode( ', ', $a607_dirty ) : '' ), ! $a607_dirty );
$a607_allow = array_filter( array_map( 'trim', file( __DIR__ . '/zz_a607/time-allowlist.txt' ) ) );
$a607_new = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $a607_file ) {
	if ( '.php' !== substr( (string) $a607_file, -4 ) ) { continue; }
	$a607_rel = substr( str_replace( '\\', '/', (string) $a607_file ), strlen( WPCAP_PATH . 'src/' ) );
	if ( ! in_array( $a607_rel, $a607_allow, true ) && preg_match( $a607_pattern, (string) file_get_contents( (string) $a607_file ) ) ) { $a607_new[] = $a607_rel; }
}
check( 'A607: no new file reads the wall clock directly (use Clock::now()/Clock::mysql())' . ( $a607_new ? ' – ' . implode( ', ', $a607_new ) : '' ), ! $a607_new );
check( 'A607: the allowlist only names files that still need it', ! array_filter( $a607_allow, static fn( $f ) => ! is_file( WPCAP_PATH . 'src/' . $f ) || ! preg_match( $a607_pattern, (string) file_get_contents( WPCAP_PATH . 'src/' . $f ) ) ) );

// Behaviour: an injected time reaches the converted rules.
$GLOBALS['wpcap_test_filters']['wpcap_now'] = strtotime( '2030-01-01 12:00:00 UTC' );
check( 'A607: Clock::mysql() follows wpcap_now', '2030-01-01 12:00:00' === Clock::mysql() );
$a607_rows = array( (object) array( 'occurrence_id' => 1, 'starts_at' => '2029-12-01 19:00:00', 'timezone' => 'UTC', 'status' => 'scheduled', 'label' => 'A' ) );
check( 'A607: the scanner date choice judges "past" by the injected time', array() === PassCaptain\Admin\Queries::occurrence_choices( $a607_rows, false ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_now'] );
check( 'A607: with the real time the same date is upcoming', isset( PassCaptain\Admin\Queries::occurrence_choices( $a607_rows, false )[1] ) );
