<?php
/**
 * A562: central lifecycle of an occurrence – Occurrence::phase() (upcoming, running, over,
 * cancelled) and the action wpcap_occurrence_phase_changed (sweep, admin edits, cancellations),
 * with the built-in reactions (listing purge, waitlist). A606: wallet passes and offline-scanner
 * date lists use the same effective end.
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Occurrence;
use PassCaptain\Waitlists;

global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();

$a562_seen = array();
$a562_listener = static function ( $occurrence_id, $new, $old, $context = array() ) use ( &$a562_seen ) {
	$a562_seen[] = array( (int) $occurrence_id, $new, $old, $context['source'] ?? '' );
};
add_action( Occurrence::PHASE_ACTION, $a562_listener, 10, 4 );
function a562_events_for( array $seen, $occ ) {
	return array_values( array_map( static fn( $e ) => array( $e[1], $e[2], $e[3] ), array_filter( $seen, static fn( $e ) => $e[0] === (int) $occ ) ) );
}
function a562_occ_of( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type );
}

echo "A562: Occurrence::phase()\n";
$a562_t = 2000000000;
$a562_row = array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a562_t ), 'ends_at' => null, 'status' => 'scheduled' );
check( 'A562: upcoming before the start', Occurrence::UPCOMING === Occurrence::phase( $a562_row, $a562_t - 1 ) );
check( 'A562: running after the start (default duration without end)', Occurrence::RUNNING === Occurrence::phase( $a562_row, $a562_t + HOUR_IN_SECONDS ) );
check( 'A562: over after start + default duration', Occurrence::OVER === Occurrence::phase( $a562_row, $a562_t + Occurrence::default_duration() + 1 ) );
check( 'A562: a multi-day occurrence runs until its end', Occurrence::RUNNING === Occurrence::phase( array( 'ends_at' => gmdate( 'Y-m-d H:i:s', $a562_t + 3 * DAY_IN_SECONDS ) ) + $a562_row, $a562_t + 2 * DAY_IN_SECONDS ) );
check( 'A562: cancelled wins over the time', Occurrence::CANCELLED === Occurrence::phase( array( 'status' => 'cancelled' ) + $a562_row, $a562_t - 1 ) );

echo "A562: the sweep reports time-based transitions\n";
$a562_type = make_type( 10 );
$a562_occ = a562_occ_of( $a562_type );
$a562_start = time() + 2 * DAY_IN_SECONDS;
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a562_start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $a562_start + 2 * HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $a562_occ ) );
delete_option( Occurrence::SWEEP_OPTION );
check( 'A562: the first sweep only remembers the time', 0 === Occurrence::sweep_phases( $a562_start - 600 ) && $a562_start - 600 === (int) get_option( Occurrence::SWEEP_OPTION ) );
Occurrence::sweep_phases( $a562_start - 60 );
check( 'A562: nothing before the start', array() === a562_events_for( $a562_seen, $a562_occ ) );
Occurrence::sweep_phases( $a562_start + 60 );
check( 'A562: start passed → running (source sweep)', array( array( 'running', 'upcoming', 'sweep' ) ) === a562_events_for( $a562_seen, $a562_occ ) );
Occurrence::sweep_phases( $a562_start + 120 );
check( 'A562: the same transition is not reported again', 1 === count( a562_events_for( $a562_seen, $a562_occ ) ) );
Occurrence::sweep_phases( $a562_start + 3 * HOUR_IN_SECONDS );
check( 'A562: end passed → over', array( 'over', 'running', 'sweep' ) === ( a562_events_for( $a562_seen, $a562_occ )[1] ?? null ) );
$a562_type2 = make_type( 10 );
$a562_occ2 = a562_occ_of( $a562_type2 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $a562_start + 4 * HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $a562_occ2 ) );
Occurrence::sweep_phases( $a562_start + 20 * HOUR_IN_SECONDS );
check( 'A562: after a long gap start and end in one window → upcoming → over at once', array( array( 'over', 'upcoming', 'sweep' ) ) === a562_events_for( $a562_seen, $a562_occ2 ) );

echo "A562: admin edits and cancellations\n";
$a562_type3 = make_type( 10 );
$a562_occ3 = a562_occ_of( $a562_type3 );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $a562_occ3 ) );
$a562_future = ( new DateTimeImmutable( '@' . ( time() + 5 * DAY_IN_SECONDS ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i' );
EventsPage::save_occurrence( array( 'occurrence_id' => $a562_occ3, 'timezone' => 'Europe/Vienna', 'starts_at' => $a562_future, 'ends_at' => '', 'location' => 'Wien', 'capacity' => 0 ) );
check( 'A562: moving a running date into the future reports upcoming (source admin)', array( array( 'upcoming', 'running', 'admin' ) ) === a562_events_for( $a562_seen, $a562_occ3 ) );
EventsPage::save_occurrence( array( 'occurrence_id' => $a562_occ3, 'timezone' => 'Europe/Vienna', 'starts_at' => $a562_future, 'ends_at' => '', 'location' => 'Saal 2', 'capacity' => 0 ) );
check( 'A562: an edit within the same phase reports nothing', 1 === count( a562_events_for( $a562_seen, $a562_occ3 ) ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $a562_occ3 ) );
Occurrence::on_cancelled( $a562_occ3 );
check( 'A562: a cancellation reports cancelled', array( 'cancelled', 'upcoming', 'cancel' ) === ( a562_events_for( $a562_seen, $a562_occ3 )[1] ?? null ) );

echo "A562: reactions – waitlist closes started dates, keeps multi-day dates still on sale\n";
$a562_wt = make_type( 10 );
$a562_wocc = a562_occ_of( $a562_wt );
$a562_wevent = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a562_wocc" );
$GLOBALS['wpcap_test_transients'] = array();
Waitlists::join( $a562_wevent, $a562_wocc, $a562_wt, 'a562-wl@example.com', 1 );
$a562_ft = make_type( 10 );
$a562_focc = a562_occ_of( $a562_ft );
$a562_fevent = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a562_focc" );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'sales_end' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ) ), array( 'ticket_type_id' => $a562_ft ) );
$GLOBALS['wpcap_test_transients'] = array();
Waitlists::join( $a562_fevent, $a562_focc, $a562_ft, 'a562-fest@example.com', 1 );
$a562_wstatus = static function ( $type ) use ( $wpdb, $p ) { return (string) $wpdb->get_var( "SELECT status FROM {$p}wpcap_waitlist WHERE ticket_type_id=" . (int) $type . ' ORDER BY waitlist_id DESC LIMIT 1' ); };
check( 'A562: both entries wait', 'waiting' === $a562_wstatus( $a562_wt ) && 'waiting' === $a562_wstatus( $a562_ft ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'occurrence_id' => $a562_wocc ) );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) ), array( 'occurrence_id' => $a562_focc ) );
Waitlists::on_phase_changed( $a562_wocc, Occurrence::RUNNING );
check( 'A562: the started date closes its waitlist right away', 'closed' === $a562_wstatus( $a562_wt ) );
check( 'A562/A535: a running festival with a ticket type still on sale keeps its waitlist', 'waiting' === $a562_wstatus( $a562_ft ) );
check( 'A562: upcoming is no reason to close anything', 0 === Waitlists::on_phase_changed( $a562_focc, Occurrence::UPCOMING ) );

echo "A606: same effective end in wallet passes and offline-scanner date lists\n";
$a606_duration = static function () { return 3 * HOUR_IN_SECONDS; };
add_filter( 'wpcap_occurrence_default_duration', $a606_duration );
$a606_pass = ( new PassCaptain\WalletPasses\AppleProvider() )->pass_json( array( 'starts_at' => '2027-06-01 18:00:00', 'ends_at' => '', 'timezone' => 'Europe/Vienna', 'doors_open_at' => '', 'location' => '', 'ticket_name' => 'Standard', 'event_name' => 'A606', 'code' => 'X', 'voided' => false, 'public_id' => str_repeat( 'a', 32 ), 'seat' => '', 'attendee_name' => '', 'auth_token' => '', 'occurrence_status' => 'scheduled', 'event_status' => 'published' ) );
remove_filter( 'wpcap_occurrence_default_duration', $a606_duration );
$a606_expected = ( new DateTimeImmutable( '@' . ( strtotime( '2027-06-01 18:00:00 UTC' ) + 15 * HOUR_IN_SECONDS ) ) )->setTimezone( new DateTimeZone( 'Europe/Vienna' ) )->format( 'Y-m-d\TH:i:sP' );
check( 'A606: Apple pass expiry = effective end (default duration, here 3 h) + 12 h', is_array( $a606_pass ) && $a606_expected === ( $a606_pass['expirationDate'] ?? '' ) );

remove_action( Occurrence::PHASE_ACTION, $a562_listener, 10 );
delete_option( Occurrence::SWEEP_OPTION );
