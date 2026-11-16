<?php
// phpcs:ignoreFile
/**
 * A499: in feeds and REST responses (content.rendered) the events shortcode/block is a plain list
 * of dates linked to the post, the calendar its list view – no order form, no checkout script; the
 * editor's block preview (REST block renderer) keeps the full card. Runs in child processes (REST_REQUEST
 * and is_feed() differ per mode).
 */

echo "A499: feeds and REST output\n";
global $wpdb;
$p = $wpdb->prefix;
$a499_type = make_type( 10 );
$a499_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a499_type" );
$a499_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a499_occ" );
$wpdb->update( "{$p}wpcap_events", array( 'name' => 'A499 Matinee' ), array( 'event_id' => $a499_event ) );
$a499_run = static function ( $mode ) use ( $a499_event ) {
	$raw = (string) shell_exec( 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a499/child.php' ) . ' ' . escapeshellarg( $mode ) . ' ' . (int) $a499_event . ' 2>&1' );
	$out = json_decode( trim( $raw ), true );
	return is_array( $out ) ? $out : array( 'error' => substr( $raw, 0, 300 ) );
};
$a499_link = 'https://example.test/programm/?wpcap_occurrence=' . $a499_occ . '#wpcap-occ-' . $a499_occ;
foreach ( array( 'feed' => 'feed', 'rest' => 'REST content.rendered' ) as $a499_mode => $a499_label ) {
	$a499 = $a499_run( $a499_mode );
	check( "A499 $a499_label: child process ran" . ( isset( $a499['error'] ) ? ' – ' . $a499['error'] : '' ), ! isset( $a499['error'] ) );
	$a499_events = (string) ( $a499['events'] ?? '' );
	check( "A499 $a499_label: no order form, no ids, no checkout script", false === strpos( $a499_events, '<form' ) && false === strpos( $a499_events, ' id="' ) && ! in_array( 'wpcap-checkout', (array) ( $a499['scripts'] ?? array() ), true ) );
	check( "A499 $a499_label: the date links to its card on the post", false !== strpos( $a499_events, '<ul class="wpcap-events-list"><li><a href="' . htmlspecialchars( $a499_link, ENT_QUOTES ) . '">A499 Matinee</a> – <time datetime="' ) );
	$a499_cal = (string) ( $a499['calendar'] ?? '' );
	check( "A499 $a499_label: the month calendar becomes the list, linked to the post", false === strpos( $a499_cal, '<table' ) && false === strpos( $a499_cal, 'wpcap_month=' ) && false !== strpos( $a499_cal, 'class="wpcap-cal-link" href="' . htmlspecialchars( $a499_link, ENT_QUOTES ) . '"' ) );
}
$a499_preview = $a499_run( 'preview' );
check( 'A499 the editor block preview keeps the full card', false !== strpos( (string) ( $a499_preview['events'] ?? '' ), '<form class="wpcap-checkout-form"' ) && false !== strpos( (string) ( $a499_preview['calendar'] ?? '' ), '<table class="wpcap-cal-grid">' ) );
$GLOBALS['wpcap_test_filters']['wpcap_slim_output'] = true;
check( 'A499 the filter wpcap_slim_output switches the plain list on (and off)', PassCaptain\PublicEvents::slim_output() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_slim_output'] );
check( 'A499 a normal page request renders cards', ! PassCaptain\PublicEvents::slim_output() );
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'a499_done' ), array( 'occurrence_id' => $a499_occ ) );
