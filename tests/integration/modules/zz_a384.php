<?php
// phpcs:ignoreFile
/**
 * A384: mails, tickets and documents format dates centrally (Format::date_format()/time_format()): the
 * site's formats in the site language, the plugin catalogue's format of the recipient's language while
 * a mail is built in another language (Mailer::with_locale()); no hard-coded d.m.Y in those paths.
 */

use PassCaptain\Frontend\Format;
use PassCaptain\Mail\Mailer;
use PassCaptain\Orders;

echo "A384: date formats of mails and documents\n";
$a384_saved = array( $GLOBALS['wpcap_test_options']['date_format'] ?? null, $GLOBALS['wpcap_test_options']['time_format'] ?? null );
$GLOBALS['wpcap_test_options']['date_format'] = 'Y/m/d';
$GLOBALS['wpcap_test_options']['time_format'] = 'g:i A';
$GLOBALS['wpcap_test_x'] = array( "date format\x04d.m.Y" => 'm-d-Y', "time format\x04H:i" => 'H.i' );
check( 'A384: in the site language the site formats apply', 'Y/m/d g:i A' === Format::datetime_format() && '' === Mailer::active_locale() );
check( 'A384: the event start of tickets/mails uses them', '2026/12/24 8:00 PM Europe/Vienna' === Orders::format_start( '2026-12-24 19:00:00', 'Europe/Vienna' ) );
$a384_in = Mailer::with_locale( 'en_US', static fn() => array( Mailer::active_locale(), Format::datetime_format(), Format::local( '2026-12-24 19:00:00', 'Europe/Vienna' ), PassCaptain\Reminders\Placeholders::occurrence_vars( (object) array( 'event_name' => 'E', 'starts_at' => '2026-12-24 19:00:00', 'timezone' => 'Europe/Vienna', 'location' => '', 'occurrence_id' => 1 ) ) ) );
check( 'A384: a mail in another language uses the format of that language', 'en_US' === $a384_in[0] && 'm-d-Y H.i' === $a384_in[1] && '12-24-2026 20.00' === $a384_in[2] );
check( 'A384: reminder placeholders {date}/{time} follow it', '12-24-2026' === $a384_in[3]['date'] && '20.00' === $a384_in[3]['time'] );
check( 'A384: the active locale ends with the callback', '' === Mailer::active_locale() );
check( 'A384: a mail in the site language keeps the site format', 'Y/m/d g:i A' === Mailer::with_locale( Mailer::site_locale(), static fn() => Format::datetime_format() ) );
check( 'A384: Format::local() handles empty values, dates and times', '' === Format::local( '' ) && '2026/12/24' === Format::local( '2026-12-24 19:00:00', 'Europe/Vienna', 'date' ) && '8:00 PM' === Format::local( '2026-12-24 19:00:00', new DateTimeZone( 'Europe/Vienna' ), 'time' ) );
$GLOBALS['wpcap_test_filters']['wpcap_date_format'] = 'd/m/Y';
check( 'A384: wpcap_date_format overrides the date format', 'd/m/Y' === Format::date_format() );
unset( $GLOBALS['wpcap_test_filters']['wpcap_date_format'] );
$GLOBALS['wpcap_test_x'] = array();
foreach ( array( 'date_format', 'time_format' ) as $a384_i => $a384_k ) {
	if ( null === $a384_saved[ $a384_i ] ) { unset( $GLOBALS['wpcap_test_options'][ $a384_k ] ); } else { $GLOBALS['wpcap_test_options'][ $a384_k ] = $a384_saved[ $a384_i ]; }
}
check( 'A384: without site formats the fallback stays d.m.Y H:i', 'd.m.Y H:i' === Format::datetime_format() );

$a384_left = array();
foreach ( array( 'Notifications.php', 'Orders.php', 'Reminders/Placeholders.php', 'Printing/InvoicePdf.php', 'OccurrenceCancellation.php', 'Passes/Cancellation.php', 'Passes/Display.php', 'Waitlists.php', 'Invoices.php', 'Printing/Templates.php', 'DoorsOpen.php', 'BoxOffice/Service.php', 'Partners/Settlements.php', 'Partners/Statement.php' ) as $a384_f ) {
	if ( preg_match( "/'d\\.m\\.Y/", (string) file_get_contents( WPCAP_PATH . 'src/' . $a384_f ) ) ) { $a384_left[] = $a384_f; }
}
check( 'A384: no hard-coded d.m.Y left in mail and document code' . ( $a384_left ? ' – ' . implode( ', ', $a384_left ) : '' ), ! $a384_left );
