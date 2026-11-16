<?php
// phpcs:ignoreFile
/**
 * A605: the deletion cutoff of financial records follows the calendar year of the site time zone:
 * 1 January 00:00 site time (in UTC), and the current year is the local one.
 */

use PassCaptain\Privacy\Retention;

echo "A605: financial retention by local business year\n";
$a605_saved_tz = $GLOBALS['wpcap_test_options']['timezone_string'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_financial_retention_years'] = 7;
$GLOBALS['wpcap_test_options']['timezone_string'] = 'Europe/Vienna';
check( 'A605: the cutoff is 1 January 00:00 Vienna time (23:00 UTC the day before)', '2018-12-31 23:00:00' === Retention::financial_cutoff( strtotime( '2026-06-01 12:00:00 UTC' ) ) );
check( 'A605: on 1 January 00:30 local (still 31 December UTC) the new local year already counts', '2019-12-31 23:00:00' === Retention::financial_cutoff( strtotime( '2026-12-31 23:30:00 UTC' ) ) );
$GLOBALS['wpcap_test_options']['timezone_string'] = 'America/New_York';
check( 'A605: west of UTC: on 31 December local (already 1 January UTC) the old year still counts, cutoff at 05:00 UTC', '2018-01-01 05:00:00' === Retention::financial_cutoff( strtotime( '2026-01-01 03:00:00 UTC' ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_financial_retention_years'] );
if ( null === $a605_saved_tz ) { unset( $GLOBALS['wpcap_test_options']['timezone_string'] ); } else { $GLOBALS['wpcap_test_options']['timezone_string'] = $a605_saved_tz; }
