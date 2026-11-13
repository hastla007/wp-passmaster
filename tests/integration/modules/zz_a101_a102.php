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

use PassCaptain\Mail\Layout;
use PassCaptain\Mail\Mailer;
use PassCaptain\Mail\Templates;
use PassCaptain\Mail\Types;
use PassCaptain\Modules\Reminders;

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
