<?php
// phpcs:ignoreFile
/**
 * A607: business rules read the time from Clock (filter wpcap_now) instead of time()/current_time().
 * Ratchet: raw time() and current_time( 'mysql', true ) are allowed in src/Clock.php only. Infrastructure
 * that must keep the real wall clock (cron scheduling, locks/leases, rate-limit and time-budget windows,
 * provider/device signatures, certificate expiry, logs, caches) calls Clock::real()/Clock::real_mysql(),
 * and only the files in zz_a607/time-allowlist.txt may do so, each with a one-line reason
 * ("path  # reason"). Remove a file from the list once it no longer needs the real clock.
 */

use PassCaptain\Clock;
use PassCaptain\Passes\Admission;

echo "A607: business rules use Clock\n";
$a607_pattern = "/(^|[^A-Za-z0-9_>:\\\\\$])time\\(\\)|current_time\\( 'mysql', true \\)/";
$a607_real = '/Clock::real(_mysql)?\(/';
$a607_converted = array( 'Exchange/Service.php', 'PortalSelfService.php', 'OrderPortal.php', 'TicketAssignments.php', 'Seating/Reservations.php', 'Passes/Admission.php', 'Mobile/Api.php', 'Frontend/EventCard.php', 'Admin/Queries.php', 'ServiceBookings.php', 'ServiceWaitlist.php', 'Partners/Sales.php', 'Communications.php', 'Jobs.php', 'Refunds.php', 'Memberships.php' );
$a607_dirty = array_values( array_filter( $a607_converted, static fn( $f ) => (bool) preg_match( $a607_pattern, (string) file_get_contents( WPCAP_PATH . 'src/' . $f ) ) || (bool) preg_match( $a607_real, (string) file_get_contents( WPCAP_PATH . 'src/' . $f ) ) ) );
check( 'A607: the converted business-rule files use only Clock::now()/Clock::mysql()' . ( $a607_dirty ? ' – ' . implode( ', ', $a607_dirty ) : '' ), ! $a607_dirty );
$a607_allow = array();
$a607_no_reason = array();
foreach ( file( __DIR__ . '/zz_a607/time-allowlist.txt' ) as $a607_line ) {
	$a607_line = trim( $a607_line );
	if ( '' === $a607_line || '#' === $a607_line[0] ) { continue; }
	$a607_parts = array_map( 'trim', explode( '#', $a607_line, 2 ) );
	$a607_allow[] = $a607_parts[0];
	if ( '' === ( $a607_parts[1] ?? '' ) ) { $a607_no_reason[] = $a607_parts[0]; }
}
check( 'A607: every allowlisted file names its reason for the real clock' . ( $a607_no_reason ? ' – ' . implode( ', ', $a607_no_reason ) : '' ), $a607_allow && ! $a607_no_reason );
$a607_raw = array();
$a607_new = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $a607_file ) {
	if ( '.php' !== substr( (string) $a607_file, -4 ) ) { continue; }
	$a607_rel = substr( str_replace( '\\', '/', (string) $a607_file ), strlen( WPCAP_PATH . 'src/' ) );
	if ( 'Clock.php' === $a607_rel ) { continue; }
	// Docblocks may mention time(); only code counts.
	$a607_code = (string) preg_replace( '~^\s*(\*|//|/\*).*$~m', '', (string) file_get_contents( (string) $a607_file ) );
	if ( preg_match( $a607_pattern, $a607_code ) ) { $a607_raw[] = $a607_rel; }
	if ( ! in_array( $a607_rel, $a607_allow, true ) && preg_match( $a607_real, $a607_code ) ) { $a607_new[] = $a607_rel; }
}
check( 'A607: no file outside Clock.php calls time() or current_time( \'mysql\', true ) (use Clock::now()/Clock::mysql(), or Clock::real() for infrastructure)' . ( $a607_raw ? ' – ' . implode( ', ', $a607_raw ) : '' ), ! $a607_raw );
check( 'A607: only allowlisted files read the real wall clock (Clock::real*)' . ( $a607_new ? ' – ' . implode( ', ', $a607_new ) : '' ), ! $a607_new );
$a607_stale = array_values( array_filter( $a607_allow, static fn( $f ) => ! is_file( WPCAP_PATH . 'src/' . $f ) || ! preg_match( $a607_real, (string) file_get_contents( WPCAP_PATH . 'src/' . $f ) ) ) );
check( 'A607: the allowlist only names files that still need the real clock' . ( $a607_stale ? ' – ' . implode( ', ', $a607_stale ) : '' ), ! $a607_stale );
check( 'A607: the allowlist is short (shrink it, do not grow it)', count( $a607_allow ) <= 43 );

// Behaviour: an injected time reaches the converted rules.
$GLOBALS['wpcap_test_filters']['wpcap_now'] = strtotime( '2030-01-01 12:00:00 UTC' );
check( 'A607: Clock::mysql() follows wpcap_now', '2030-01-01 12:00:00' === Clock::mysql() );
$a607_rows = array( (object) array( 'occurrence_id' => 1, 'starts_at' => '2029-12-01 19:00:00', 'timezone' => 'UTC', 'status' => 'scheduled', 'label' => 'A' ) );
check( 'A607: the scanner date choice judges "past" by the injected time', array() === PassCaptain\Admin\Queries::occurrence_choices( $a607_rows, false ) );
// Z1: converted in the second round (current_time( 'mysql', true ) only): the key expiry follows wpcap_now too.
$a607_key = (object) array( 'revoked_at' => null, 'expires_at' => '2029-06-01 00:00:00' );
check( 'A607/Z1: an API key valid until 2029 counts as expired at the injected 2030 time', 'expired' === PassCaptain\Admin\ApiKeysPage::status_of( $a607_key ) );
// Zeitfunktionen: the remaining business rules follow wpcap_now; the real clock does not.
check( 'A607/Zeit: developer API key expiry follows the injected time', 'expired' === PassCaptain\DeveloperKeys::status( $a607_key ) );
check( 'A607/Zeit: Clock::real() ignores wpcap_now', abs( Clock::real() - time() ) <= 2 && abs( strtotime( Clock::real_mysql() . ' UTC' ) - time() ) <= 2 );
check( 'A607/Zeit: Clock::real_mysql() applies its offset', abs( strtotime( Clock::real_mysql( 3600 ) . ' UTC' ) - time() - 3600 ) <= 2 );
unset( $GLOBALS['wpcap_test_filters']['wpcap_now'] );
check( 'A607/Zeit: with the real time the developer key is active', 'active' === PassCaptain\DeveloperKeys::status( $a607_key ) );
check( 'A607/Z1: with the real time the same key is active', 'active' === PassCaptain\Admin\ApiKeysPage::status_of( $a607_key ) );
check( 'A607: with the real time the same date is upcoming', isset( PassCaptain\Admin\Queries::occurrence_choices( $a607_rows, false )[1] ) );
