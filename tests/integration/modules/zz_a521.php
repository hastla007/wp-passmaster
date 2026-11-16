<?php
// phpcs:ignoreFile
/**
 * A521: the series overview shows a readable rule ("Wöchentlich am Montag, Mittwoch · 19:00
 * Europe/Vienna · bis 31.12.2026") instead of raw values ("weekly · 19:00 Europe/Vienna · ≤ 2026-12-31").
 */

use PassCaptain\Series\AdminPage as SeriesAdmin;

echo "A521: readable series rules\n";
$a521_saved = $GLOBALS['wpcap_test_options']['date_format'] ?? null;
$GLOBALS['wpcap_test_options']['date_format'] = 'd.m.Y';
check( 'A521: weekly rule with weekdays, time zone and end date', 'Wöchentlich am Montag, Mittwoch · 19:00 Europe/Vienna · bis 31.12.2026' === SeriesAdmin::rule_summary( array( 'freq' => 'weekly', 'interval' => 1, 'weekdays' => array( 1, 3 ), 'time' => '19:00', 'timezone' => 'Europe/Vienna', 'until' => '2026-12-31' ) ) );
check( 'A521: intervals, nth weekday and counts are spelled out', 'Jede 2. Woche am Freitag · 2 Termine' === SeriesAdmin::rule_summary( array( 'freq' => 'weekly', 'interval' => 2, 'weekdays' => array( 5 ), 'count' => 2 ) ) && 'Monatlich am letzten Sonntag' === SeriesAdmin::rule_summary( array( 'freq' => 'monthly_nth', 'nth' => -1, 'nth_weekday' => 7 ) ) && 'Täglich · 1 Termin' === SeriesAdmin::rule_summary( array( 'freq' => 'daily', 'count' => 1 ) ) && 'Datumsliste (2 Daten)' === SeriesAdmin::rule_summary( array( 'freq' => 'dates', 'dates' => array( '2026-01-01', '2026-01-02' ) ) ) );
$GLOBALS['wpcap_test_options']['date_format'] = 'Y/m/d';
check( 'A521: dates follow the site date format', 'Monatlich am 15. · bis 2026/12/31' === SeriesAdmin::rule_summary( array( 'freq' => 'monthly_day', 'month_day' => 15, 'until' => '2026-12-31' ) ) );
$a521_src = (string) file_get_contents( WPCAP_PATH . 'src/Series/AdminPage.php' );
check( 'A521: no raw rule values or fixed d.m.Y in the series admin', false === strpos( $a521_src, "' · ≤ ' . \$rule['until']" ) && false === strpos( $a521_src, "gmdate( 'd.m.Y'" ) );
if ( null === $a521_saved ) { unset( $GLOBALS['wpcap_test_options']['date_format'] ); } else { $GLOBALS['wpcap_test_options']['date_format'] = $a521_saved; }
