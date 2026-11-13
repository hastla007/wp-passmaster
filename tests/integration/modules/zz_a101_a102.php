<?php
/**
 * A101: HTML mail layout as default for built-in customer mails (plain-text alternative, escaping,
 * admin overrides untouched). A102: nonexistent/ambiguous local times around DST changes in the
 * admin (Europe/Vienna 2027-03-28 02:30 does not exist, 2027-10-31 02:30 exists twice).
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\EventsPage;
use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Mail\Layout;
use PassCaptain\Mail\Mailer;
use PassCaptain\Mail\Templates;
use PassCaptain\Mail\Types;
use PassCaptain\Modules\Reminders;
use PassCaptain\Series\Generator;
use PassCaptain\Series\Rules;

global $wpdb;
$p = $wpdb->prefix;

/* ============================================================== A101 */

echo "A101: built-in customer mails default to HTML with plain-text alternative\n";
update_option( Templates::OPTION, array() );
Types::flush();
check( 'built-in defaults resolve to HTML', 'html' === Templates::builtin( 'order_access' )['format'] && 'html' === Templates::resolve( 'tickets', 'de_DE' )['format'] && 'builtin' === Templates::resolve( 'tickets', 'de_DE' )['source'] );
add_filter( 'wpcap_mail_default_format', $a101_text = static function ( $format, $type ) { return 'event_cancelled' === $type ? 'text' : $format; }, 10, 2 );
check( 'wpcap_mail_default_format can switch a type back to plain text', 'text' === Templates::builtin( 'event_cancelled' )['format'] && 'html' === Templates::builtin( 'order_access' )['format'] );
remove_filter( 'wpcap_mail_default_format', $a101_text, 10 );
add_filter( 'wpcap_mail_types', $a101_types = static function ( $types ) { $types['member_pass']['format'] = 'text'; return $types; } );
Types::flush();
check( 'a type definition may keep plain text (format => text)', 'text' === Templates::builtin( 'member_pass' )['format'] );
remove_filter( 'wpcap_mail_types', $a101_types );
Types::flush();

Templates::save( 'event_cancelled', 'de_DE', array( 'subject' => 'Absage {event_name}', 'body' => 'Leider abgesagt: {event_name}', 'format' => 'text' ) );
$a101_custom = Mailer::compose( 'event_cancelled', array( 'event_name' => 'A<b>' ), 'de_DE' );
check( 'admin overrides keep their format (text stays text, unescaped)', 'text' === $a101_custom['format'] && 'Leider abgesagt: A<b>' === $a101_custom['body'] && null === Mailer::text_body( $a101_custom ) );
Templates::reset( 'event_cancelled', 'de_DE' );

$GLOBALS['wpcap_test_mails'] = array();
Mailer::send( 'event_cancelled', 'a101@example.com', array( 'event_name' => '<script>alert(1)</script> & "Co"' ), array(), 'de_DE' );
$a101_mail = $GLOBALS['wpcap_test_mails'][0] ?? array( 'html' => '', 'body' => '', 'headers' => array() );
check( 'default mail is an HTML document with layout and HTML header', str_starts_with( $a101_mail['html'], '<!DOCTYPE html>' ) && in_array( 'Content-Type: text/html; charset=UTF-8', (array) $a101_mail['headers'], true ) );
check( 'placeholder values are escaped in the HTML part', false !== strpos( $a101_mail['html'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Co&quot;' ) && false === strpos( $a101_mail['html'], '<script>' ) );
check( 'plain-text part is exactly the historic text mail', 'Das Event „<script>alert(1)</script> & "Co"“ wurde abgesagt. Bitte kontaktiere den Veranstalter für Informationen zu den nächsten Schritten. Diese Nachricht ist keine Bestätigung einer Erstattung.' === $a101_mail['body'] );
check( 'the AltBody hook is removed after sending', empty( array_filter( $GLOBALS['wpcap_test_hooks']['phpmailer_init'] ?? array() ) ) );

$a101_site = Mailer::render_template( 'event_cancelled', array( 'subject' => 's', 'body' => "Mehr auf {site_url}\nZeile 2", 'format' => 'html' ), array() );
check( '{site_url} is a link in HTML and the bare URL in the text part', false !== strpos( $a101_site['body'], '<a href="https://example.test/">https://example.test/</a>' ) && "Mehr auf https://example.test/\nZeile 2" === Mailer::text_body( $a101_site ) );

$a101_msg = Mailer::compose( 'event_cancelled', array( 'event_name' => 'X' ) );
$a101_appended = Mailer::apply_filtered( $a101_msg, array( 'body' => $a101_msg['body'] . "\n\nNOTIZ <x> https://n.example/z" ) );
check( 'legacy filters appending text: escaped and linked in HTML, verbatim in the text part', false !== strpos( $a101_appended['body'], 'NOTIZ &lt;x&gt; <a href="https://n.example/z">' ) && str_ends_with( (string) Mailer::text_body( $a101_appended ), "Erstattung.\n\nNOTIZ <x> https://n.example/z" ) );
$a101_replaced = Mailer::apply_filtered( $a101_msg, array( 'body' => '<p>Ganz neu</p>' ) );
check( 'a replaced body drops the stale text part (converted from HTML instead)', null === Mailer::text_body( $a101_replaced ) && 'Ganz neu' === Mailer::prepare( 'x@example.com', $a101_replaced )['alt_body'] );
$a101_changed = $a101_msg;
$a101_changed['body'] = '<p>Vom Filter wpcap_mail</p>';
check( 'body changed after rendering (wpcap_mail): the text part follows the new body', 'Vom Filter wpcap_mail' === Mailer::prepare( 'x@example.com', $a101_changed )['alt_body'] );
check( 'HTML-to-text keeps table rows readable (label: value)', "Zahlung\n\nIBAN: AT61\nBIC: BKAU" === Layout::to_text( '<p>Zahlung</p><table><tr><td>IBAN</td><td><strong>AT61</strong></td></tr><tr><td>BIC</td><td>BKAU</td></tr></table>' ) );

echo "A101: reminders use the HTML layout as well\n";
$a101_type = make_type( 10 );
$a101_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a101_type" );
$a101_event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$a101_occ" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_events SET name=%s WHERE event_id=%d", 'Jazz <i>&</i> Blues', $a101_event ) );
$a101_rem_saved = PassCaptain\Settings::get( 'reminders' );
PassCaptain\Settings::update( array( 'reminders' => array_merge( Reminders::settings_defaults()['reminders'], array( 'subject' => 'Erinnerung: {event}', 'body' => "In {hours} Stunden: {event}\nKalender: {ics_url}" ) ) ) );
$a101_rem = Reminders::render_template( null, Reminders::TEMPLATE, array( 'occurrence_id' => $a101_occ, 'hours' => 2, 'tickets' => 1 ) );
check( 'reminder is rendered as HTML: values escaped, URLs linked', is_array( $a101_rem ) && 'html' === $a101_rem['format'] && false !== strpos( $a101_rem['body'], 'Jazz &lt;i&gt;&amp;&lt;/i&gt; Blues' ) && false === strpos( $a101_rem['body'], '<i>' ) && false !== strpos( $a101_rem['body'], '<a href="https://example.test/?' ) && false !== strpos( Layout::to_text( $a101_rem['body'] ), 'Jazz <i>&</i> Blues' ) );
add_filter( 'wpcap_mail_default_format', $a101_rem_text = static function ( $format, $type ) { return 'reminder' === $type ? 'text' : $format; }, 10, 2 );
$a101_rem2 = Reminders::render_template( null, Reminders::TEMPLATE, array( 'occurrence_id' => $a101_occ, 'hours' => 2, 'tickets' => 1 ) );
remove_filter( 'wpcap_mail_default_format', $a101_rem_text, 10 );
PassCaptain\Settings::update( array( 'reminders' => $a101_rem_saved ) );
check( 'reminder can be switched back to plain text', 'text' === $a101_rem2['format'] && false !== strpos( $a101_rem2['body'], 'Jazz <i>&</i> Blues' ) );

/* ============================================================== A102 */

echo "A102: nonexistent and ambiguous local times (Europe/Vienna)\n";
$gap = Forms::local_time_info( '2027-03-28T02:30', 'Europe/Vienna' );
check( '2027-03-28 02:30 does not exist; next valid time is 03:00', $gap && 'gap' === $gap['status'] && '2027-03-28 03:00' === $gap['next_valid'] );
$amb = Forms::local_time_info( '2027-10-31T02:30', 'Europe/Vienna' );
check( '2027-10-31 02:30 is ambiguous; first instance (summer time) is used', $amb && 'ambiguous' === $amb['status'] && '2027-10-31 00:30:00' === $amb['utc'] && array( '+02:00', '+01:00' ) === $amb['offsets'] );
$ok = Forms::local_time_info( '2027-10-31T03:00', 'Europe/Vienna' );
check( 'times outside the change are unaffected', $ok && 'ok' === $ok['status'] && '2027-10-31 02:00:00' === $ok['utc'] && 'ok' === Forms::local_time_info( '2027-03-28T03:00', 'Europe/Vienna' )['status'] && 'ok' === Forms::local_time_info( '2027-03-28T01:59', 'Europe/Vienna' )['status'] );
check( 'local_to_utc is deterministic: ambiguous → first instance, gap → moved forward', '2027-10-31 00:30:00' === Forms::local_to_utc( '2027-10-31T02:30', 'Europe/Vienna' ) && '2027-10-31 00:00:00' === Forms::local_to_utc( '2027-10-31 02:00', 'Europe/Vienna' ) && '2027-03-28 01:30:00' === Forms::local_to_utc( '2027-03-28T02:30', 'Europe/Vienna' ) );
check( 'other zones: New York spring gap and fall-back hour', 'gap' === Forms::local_time_info( '2027-03-14T02:30', 'America/New_York' )['status'] && '2027-03-14 03:00' === Forms::local_time_info( '2027-03-14T02:30', 'America/New_York' )['next_valid'] && '2027-11-07 05:30:00' === Forms::local_to_utc( '2027-11-07T01:30', 'America/New_York' ) );
$notes = array();
$err = Forms::local_to_utc_checked( '2027-03-28T02:30', 'Europe/Vienna', $notes );
check( 'checked conversion rejects the nonexistent time and suggests the next valid one', is_wp_error( $err ) && 'wpcap_time_nonexistent' === $err->get_error_code() && false !== strpos( $err->get_error_message(), '28.03.2027 02:30' ) && false !== strpos( $err->get_error_message(), '28.03.2027 03:00' ) && ! $notes );
$utc = Forms::local_to_utc_checked( '2027-10-31T02:30', 'Europe/Vienna', $notes );
check( 'checked conversion accepts the ambiguous time with a note', '2027-10-31 00:30:00' === $utc && 1 === count( $notes ) && false !== strpos( $notes[0], 'zweimal' ) && false !== strpos( $notes[0], 'UTC+02:00' ) );
check( 'invalid input stays null', null === Forms::local_to_utc_checked( '2027-02-30T10:00', 'Europe/Vienna' ) && null === Forms::local_to_utc_checked( '2027-10-31T02:30', 'Mars/Base' ) );

echo "A102: occurrence form (EventsPage)\n";
$occ_count = static function ( $event_id ) use ( $wpdb, $p ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_occurrences WHERE event_id=%d", $event_id ) ); };
$before = $occ_count( $a101_event );
$gap_save = EventsPage::save_occurrence( array( 'event_id' => $a101_event, 'starts_at' => '2027-03-28T02:30', 'timezone' => 'Europe/Vienna', 'capacity' => '10' ) );
check( 'new occurrence at a nonexistent time is rejected, nothing saved', is_wp_error( $gap_save ) && 'wpcap_time_nonexistent' === $gap_save->get_error_code() && $before === $occ_count( $a101_event ) );
$gap_end = EventsPage::save_occurrence( array( 'event_id' => $a101_event, 'starts_at' => '2027-03-28T01:00', 'ends_at' => '2027-03-28T02:15', 'timezone' => 'Europe/Vienna' ) );
check( 'an end time inside the gap is rejected as well', is_wp_error( $gap_end ) && 'wpcap_time_nonexistent' === $gap_end->get_error_code() );
$amb_save = EventsPage::save_occurrence( array( 'event_id' => $a101_event, 'starts_at' => '2027-10-31T02:30', 'timezone' => 'Europe/Vienna', 'capacity' => '10' ) );
check( 'ambiguous start is saved as the first instance with a warning notice', ! is_wp_error( $amb_save ) && '2027-10-31 00:30:00' === Queries::occurrence( $amb_save['occurrence_id'] )->starts_at && false !== strpos( $amb_save['warning'] ?? '', 'zweimal' ) );
$plain_save = EventsPage::save_occurrence( array( 'occurrence_id' => $amb_save['occurrence_id'] ?? 0, 'starts_at' => '2027-10-31T19:30', 'timezone' => 'Europe/Vienna', 'capacity' => '10' ) );
check( 'ordinary times carry no warning', ! is_wp_error( $plain_save ) && ! isset( $plain_save['warning'] ) );

$event_base = array( 'name' => 'A102 Nachtkonzert', 'timezone' => 'Europe/Vienna', 'ticket_name' => 'Eintritt', 'price' => '10', 'currency' => 'EUR', 'tax_rate' => '10', 'status' => 'draft' );
$ev_gap = EventsPage::create_event( $event_base + array( 'starts_at' => '2027-03-28T02:30' ) );
check( 'new event at a nonexistent start time is rejected', is_wp_error( $ev_gap ) && 'wpcap_time_nonexistent' === $ev_gap->get_error_code() );
$ev_amb = EventsPage::create_event( $event_base + array( 'starts_at' => '2027-10-17T02:30', 'repeat_frequency' => 'weekly', 'repeat_count' => '3' ) );
$ev_amb_starts = is_wp_error( $ev_amb ) ? array() : $wpdb->get_col( $wpdb->prepare( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at", $ev_amb['event_id'] ) );
check( 'weekly repetition onto the fall-back night uses the first instance and warns', array( '2027-10-17 00:30:00', '2027-10-24 00:30:00', '2027-10-31 00:30:00' ) === $ev_amb_starts && false !== strpos( $ev_amb['warning'] ?? '', '31.10.2027 02:30' ) );
$ev_spring = EventsPage::create_event( $event_base + array( 'starts_at' => '2027-03-14T02:30', 'repeat_frequency' => 'weekly', 'repeat_count' => '3' ) );
$ev_spring_starts = is_wp_error( $ev_spring ) ? array() : $wpdb->get_col( $wpdb->prepare( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE event_id=%d ORDER BY starts_at", $ev_spring['event_id'] ) );
check( 'weekly repetition into the spring gap is moved forward and reported', array( '2027-03-14 01:30:00', '2027-03-21 01:30:00', '2027-03-28 01:30:00' ) === $ev_spring_starts && false !== strpos( $ev_spring['warning'] ?? '', '28.03.2027 03:30' ) );

echo "A102: series generator\n";
$rule = Rules::normalize( array( 'timezone' => 'Europe/Vienna', 'time' => '02:30', 'duration_minutes' => 60, 'freq' => 'dates', 'dates' => "2027-03-28\n2027-10-31\n2027-11-07" ) );
$items = is_wp_error( $rule ) ? array() : Rules::expand( $rule );
check( 'series items are flagged gap / ambiguous / ordinary', 3 === count( $items ) && 'gap' === $items[0]['dst'] && '2027-03-28 03:30' === $items[0]['local'] && 'ambiguous' === $items[1]['dst'] && '2027-10-31 00:30:00' === $items[1]['starts_at'] && '' === $items[2]['dst'] );
$series_notes = Generator::dst_notes( $items, 'Europe/Vienna' );
check( 'series notes list the moved and the ambiguous dates', 2 === count( $series_notes ) && false !== strpos( $series_notes[0], '28.03.2027 → 03:30' ) && false !== strpos( $series_notes[1], '31.10.2027 02:30 (UTC+02:00)' ) );
$created = Generator::create( array( 'event_id' => $a101_event, 'template_occurrence_id' => $a101_occ, 'freq' => 'dates', 'dates' => "2027-03-28\n2027-10-31", 'time' => '02:30', 'duration_minutes' => 60, 'timezone' => 'Europe/Vienna', 'name' => 'A102' ) );
check( 'creating the series reports the DST notes as a warning', ! is_wp_error( $created ) && 2 === count( $created['created'] ) && false !== strpos( $created['warning'] ?? '', '28.03.2027 → 03:30' ) && false !== strpos( $created['warning'] ?? '', 'zweimal' ) );
