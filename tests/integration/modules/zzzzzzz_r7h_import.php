<?php
/**
 * R7 (audit round 2, expert #12): CSV import of events, occurrences and ticket types (Admin\Import).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\Import as R7hImport;

global $wpdb;
$p = $wpdb->prefix;
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $len = 12, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $len ) ), 0, $len ); } }

echo "R7 import: CSV events/occurrences/ticket types\n";
$r7h_sfx = substr( md5( microtime() ), 0, 6 );
$r7h_date = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
$r7h_csv = "\xEF\xBB\xBFevent;starts_at;timezone;location;ticket;price;tax_rate;ticket_capacity\n"
	. "Lesung $r7h_sfx;$r7h_date 19:30;Europe/Vienna;Wien;Standard;12,50;10;100\n"
	. "Lesung $r7h_sfx;$r7h_date 19:30;Europe/Vienna;Wien;Ermäßigt;8;10;50\n"
	. "Lesung $r7h_sfx;$r7h_date 21:30;Europe/Vienna;Wien;Standard;12,50;10;100\n"
	. "\"Konzert; $r7h_sfx\";$r7h_date 20:00;;Graz;Stehplatz;30;13;\n";
$r7h_plan = R7hImport::plan( $r7h_csv, 'Europe/Vienna' );
check( 'plan: 2 events, 3 occurrences, 4 rows, no errors (BOM, ";" separator, quoted cells)', ! $r7h_plan['errors'] && 2 === count( $r7h_plan['events'] ) && 4 === $r7h_plan['rows'] && 2 === count( $r7h_plan['events'][ mb_strtolower( "Lesung $r7h_sfx" ) ]['occurrences'] ) );
check( 'local start converted to UTC', isset( $r7h_plan['events'][ mb_strtolower( "Lesung $r7h_sfx" ) ]['occurrences'][ gmdate( 'Y-m-d H:i:s', strtotime( "$r7h_date 19:30 Europe/Vienna" ) ) ] ) );
$r7h_bad = R7hImport::plan( "event,starts_at,ticket,price\nA,morgen,X,1\nB,$r7h_date 10:00,Y,abc\n", 'UTC' );
check( 'invalid lines are listed with line numbers, nothing is importable', 2 === count( $r7h_bad['errors'] ) && false !== strpos( $r7h_bad['errors'][0], 'Zeile 2' ) && false !== strpos( $r7h_bad['errors'][1], 'Zeile 3' ) && is_wp_error( R7hImport::import( $r7h_bad ) ) );
check( 'missing required column is reported', array() !== R7hImport::plan( "event,ticket\nA,B\n" )['errors'] );
$r7h_res = R7hImport::import( $r7h_plan );
check( 'import creates 2 draft events, 3 occurrences, 4 ticket types', is_array( $r7h_res ) && 2 === $r7h_res['events'] && 3 === $r7h_res['occurrences'] && 4 === $r7h_res['ticket_types'] && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event_id IN (%d,%d) AND status='draft'", $p . 'wpcap_events', $r7h_res['event_ids'][0], $r7h_res['event_ids'][1] ) ) );
check( 'price and VAT parsed (12,50 → 1250, 10 % → 1000 bp)', 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE o.event_id=%d AND tt.price_minor=1250 AND tt.tax_rate_bp=1000", $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', is_array( $r7h_res ) ? $r7h_res['event_ids'][0] : 0 ) ) );
