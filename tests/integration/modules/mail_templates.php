<?php
/**
 * Mail templates: central mailer defaults (unchanged texts), overrides, placeholders, escaping in
 * HTML mode, locale fallback and switching, AltBody, legacy filters, admin preview/test/save
 * handlers with nonce and capability checks.
 */

// phpcs:ignoreFile

use PassCaptain\Mail\Admin as MailAdmin;
use PassCaptain\Mail\Layout;
use PassCaptain\Mail\Mailer;
use PassCaptain\Mail\Templates;
use PassCaptain\Mail\Types;
use PassCaptain\Modules\MailTemplates;
use PassCaptain\Orders;
use PassCaptain\Settings;

final class Wpmt_Json_Response extends Exception { public $payload; public $success; public function __construct( $success, $payload ) { parent::__construct( 'json' ); $this->success = $success; $this->payload = $payload; } }
final class Wpmt_Fake_Mailer { public $AltBody = ''; }
if ( ! function_exists( 'switch_to_locale' ) ) {
	function switch_to_locale( $locale ) { $GLOBALS['wpmt_locale_calls'][] = 'switch:' . $locale; $GLOBALS['wpmt_locale_stack'][] = $locale; return true; }
	function restore_previous_locale() { $GLOBALS['wpmt_locale_calls'][] = 'restore'; array_pop( $GLOBALS['wpmt_locale_stack'] ); return true; }
}
if ( ! function_exists( 'check_ajax_referer' ) ) { function check_ajax_referer( $action = -1, $arg = false, $die = true ) { $GLOBALS['wpcap_test_nonce_checks'][] = $action; return 1; } }
if ( ! function_exists( 'wp_send_json_success' ) ) { function wp_send_json_success( $data = null, $status = null ) { throw new Wpmt_Json_Response( true, $data ); } }
if ( ! function_exists( 'wp_send_json_error' ) ) { function wp_send_json_error( $data = null, $status = null ) { throw new Wpmt_Json_Response( false, array( 'data' => $data, 'status' => $status ) ); } }
if ( ! function_exists( 'wp_get_current_user' ) ) { function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'user_email' => 'mail-admin@example.com' ); } }

MailTemplates::register();
Types::flush();
global $wpdb;
$p = $wpdb->prefix;
$GLOBALS['wpmt_locale_calls'] = array();
$GLOBALS['wpmt_locale_stack'] = array();
function wpmt_mails_since( $offset, $to = null ) { return array_values( array_filter( array_slice( $GLOBALS['wpcap_test_mails'], $offset ), static fn( $m ) => null === $to || $to === $m['to'] ) ); }
function wpmt_free_order( $email, $qty = 1 ) {
	$GLOBALS['wpcap_test_transients'] = array();
	$type = make_type( 10, 10, 0 );
	return Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'wpmt-' . uniqid( '', true ) ) );
}
$wpmt_prepared = array();
add_action( 'wpcap_mail_before_send', static function ( $prepared ) {
	global $wpmt_prepared;
	$fake = new Wpmt_Fake_Mailer();
	do_action( 'phpmailer_init', $fake );
	$prepared['phpmailer_alt'] = $fake->AltBody;
	$wpmt_prepared[] = $prepared;
}, 10, 1 );
update_option( Templates::OPTION, array() );

echo "Mail templates: built-in defaults keep the previous texts\n";
check( 'registry lists all customer mail types with labels and placeholders', ! array_diff( array( 'tickets', 'tickets_resend', 'order_access', 'ticket_transfer_recipient', 'ticket_transfer_sender', 'ticket_assigned_attendee', 'ticket_assigned_buyer', 'refund_confirmation', 'event_cancelled', 'occurrence_cancelled', 'occurrence_rescheduled', 'waitlist_joined', 'waitlist_offer', 'member_pass' ), array_keys( Mailer::types() ) ) && isset( Mailer::type( 'refund_confirmation' )['placeholders']['credit_note'] ) && '' !== Mailer::type( 'tickets' )['label'] );
$before = count( $GLOBALS['wpcap_test_mails'] );
$o = wpmt_free_order( 'mt-default@example.com', 2 );
$mails = wpmt_mails_since( $before, 'mt-default@example.com' );
$ticket_mail = array_values( array_filter( $mails, static fn( $m ) => str_starts_with( $m['subject'], 'Deine Tickets' ) ) )[0] ?? array( 'subject' => '', 'body' => '' );
preg_match_all( '/Ticket-Code: ([a-f0-9]{64})/', $ticket_mail['body'], $codes );
$expected = "Deine Tickets für Testsite:\n\n";
foreach ( Orders::tickets_for_order( $o['order_id'] ) as $i => $t ) {
	$code = $codes[1][ $i ] ?? '';
	$expected .= sprintf( "%s — %s\n%s\n%s\n\n", $t->event_name, $t->ticket_name, Orders::format_start( $t->starts_at, $t->timezone ), $t->location ) . 'Ticket öffnen oder drucken: https://example.test/?wpcap_ticket=' . $code . "\nTicket-Code: " . $code . "\n"
		// A089: help code line below the ticket code.
		. 'Hilfscode für den Einlass, falls der QR-Code nicht lesbar ist: ' . PassCaptain\HelpCode::for_ticket( (int) $t->ticket_id, $code ) . "\n\n";
}
check( 'ticket mail: subject and body identical to the previous plain text', 'Deine Tickets für Testsite' === $ticket_mail['subject'] && 2 === count( $codes[1] ) && $expected === $ticket_mail['body'] );
$access = array_values( array_filter( $mails, static fn( $m ) => str_starts_with( $m['subject'], 'Deine Bestellung' ) ) )[0] ?? array( 'subject' => '', 'body' => '' );
preg_match( '/wpcap_access=([a-f0-9]{64})/', $access['body'], $am );
$items_text = PassCaptain\Mail\Blocks::order_items( Orders::summary_lines( $o['order_id'] ) )['text'];
check( 'access link mail: previous plain text plus the ordered lines (audit 3)', 'Deine Bestellung bei Testsite' === $access['subject'] && '' !== $items_text && "Über diesen persönlichen Link kannst du den Status deiner Bestellung bei Testsite ansehen und Tickets verwalten:\n\nhttps://example.test/?wpcap_order=" . $o['public_id'] . '&wpcap_access=' . ( $am[1] ?? '' ) . "\n\n" . $items_text . "Bewahre diesen Link sicher auf und teile ihn nicht." === $access['body'] );
$text_prepared = end( $wpmt_prepared );
// A101: built-in defaults are HTML now; the plain-text part (AltBody) is the historic text (checked above).
check( 'built-in defaults are sent as HTML with the historic text as AltBody', str_starts_with( $text_prepared['body'], '<!DOCTYPE html>' ) && $text_prepared['phpmailer_alt'] === $text_prepared['alt_body'] && $access['body'] === $text_prepared['alt_body'] && in_array( 'Content-Type: text/html; charset=UTF-8', $text_prepared['headers'], true ) );
add_filter( 'wpcap_mail_default_format', $wpmt_text_default = static function () { return 'text'; } );
$wpmt_prepared = array();
Orders::rotate_access_link( $o['order_id'] );
$text_prepared = end( $wpmt_prepared );
remove_filter( 'wpcap_mail_default_format', $wpmt_text_default );
check( 'text mails (wpcap_mail_default_format = text) are sent without HTML header or AltBody', $text_prepared && null === $text_prepared['alt_body'] && '' === $text_prepared['phpmailer_alt'] && ! in_array( 'Content-Type: text/html; charset=UTF-8', $text_prepared['headers'], true ) );
$refund = Mailer::compose( 'refund_confirmation', array( 'order_id' => 'ABC', 'refund_amount' => static fn() => number_format_i18n( 1500 / 100, 2 ) . ' EUR', 'revoked_count' => 2, 'revoked_note' => static fn() => Types::revoked_note( 2 ), 'credit_note_number' => 'GS-7', 'credit_note' => static fn() => Types::credit_note( 'GS-7' ) ) );
$refund_plain = Mailer::compose( 'refund_confirmation', array( 'order_id' => 'ABC', 'refund_amount' => '15,00 EUR', 'revoked_note' => Types::revoked_note( 0 ), 'credit_note' => Types::credit_note( '' ) ) );
check( 'refund confirmation identical to the previous text (with and without optional lines)', 'Erstattung bestätigt – Testsite' === $refund['subject'] && "Für deine Bestellung ABC haben wir 15,00 EUR erstattet.\n2 Tickets sind damit ungültig.\nRechnungskorrektur: GS-7\n\nJe nach Zahlungsart kann es einige Tage dauern, bis der Betrag gutgeschrieben ist." === Mailer::text_body( $refund ) && "Für deine Bestellung ABC haben wir 15,00 EUR erstattet.\n\nJe nach Zahlungsart kann es einige Tage dauern, bis der Betrag gutgeschrieben ist." === Mailer::text_body( $refund_plain ) );
$wl = Mailer::compose( 'waitlist_joined', array( 'position' => 4 ) );
$mp = Mailer::compose( 'member_pass', array( 'pass_code' => 'abc', 'valid_until' => '2027-12-31' ) );
$asg = Mailer::compose( 'ticket_assigned_attendee', array( 'event_name' => 'E', 'ticket_name' => 'T', 'event_date' => 'D', 'event_location' => 'L', 'attendee_name' => 'N', 'ticket_link' => Mailer::link( 'https://example.test/?wpcap_ticket=x' ), 'ticket_extras' => '' ) );
check( 'other defaults keep their wording', 'Du bist auf der Warteliste eingetragen (Position 4). Wir schreiben dir, sobald ein Platz frei wird.' === Mailer::text_body( $wl ) && "Dein Passcode: abc\nGültig bis: 2027-12-31" === Mailer::text_body( $mp ) && 'Dein Pass für Testsite' === $mp['subject'] && "E — T\nD\nL\nTeilnehmer: N\n\nhttps://example.test/?wpcap_ticket=x" === Mailer::text_body( $asg ) );

echo "Mail templates: overrides and placeholders\n";
Templates::save( 'order_access', 'de_DE', array( 'subject' => 'Zugang {order_id} – {site_name}', 'body' => "Hallo!\n{access_link}\n{unknown_thing}Ende", 'format' => 'text' ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$o2 = wpmt_free_order( 'mt-override@example.com' );
$acc2 = array_values( array_filter( wpmt_mails_since( $before, 'mt-override@example.com' ), static fn( $m ) => str_starts_with( $m['subject'], 'Zugang' ) ) )[0] ?? array( 'subject' => '', 'body' => '' );
check( 'override is applied with placeholders replaced', 'Zugang ' . $o2['public_id'] . ' – Testsite' === $acc2['subject'] && str_starts_with( $acc2['body'], "Hallo!\nhttps://example.test/?wpcap_order=" . $o2['public_id'] . '&wpcap_access=' ) );
check( 'unknown placeholders are replaced by an empty string', str_ends_with( $acc2['body'], "\nEnde" ) && false === strpos( $acc2['body'], 'unknown_thing' ) );
$single = Mailer::render_template( 'event_cancelled', array( 'subject' => "A {event_name}\nB", 'body' => '{event_name}/{site_name}', 'format' => 'text' ), array( 'event_name' => 'X {site_name} <b>' ) );
check( 'placeholders are replaced in one pass, subject stays one line, text mode does not escape', 'X {site_name} <b>/Testsite' === $single['body'] && 'A X {site_name} <b> B' === $single['subject'] );
Templates::reset( 'order_access', 'de_DE' );
check( 'reset removes the override', null === Templates::get( 'order_access', 'de_DE' ) && 'builtin' === Templates::resolve( 'order_access', 'de_DE' )['source'] );

echo "Mail templates: HTML mode\n";
$html = Mailer::render_template( 'ticket_assigned_attendee', array( 'subject' => 'Ticket für {attendee_name}', 'body' => '<p>Hallo <strong>{attendee_name}</strong></p><p>{ticket_link}</p>', 'format' => 'html' ), array( 'attendee_name' => '<script>alert(1)</script> & Co', 'ticket_link' => Mailer::link( 'https://example.test/?wpcap_ticket=abc&x=1' ) ) );
check( 'HTML mode escapes placeholder values but keeps trusted blocks', false !== strpos( $html['body'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co' ) && false === strpos( $html['body'], '<script>' ) && false !== strpos( $html['body'], '<a href="https://example.test/?wpcap_ticket=abc&amp;x=1">' ) && 'Ticket für <script>alert(1)</script> & Co' === $html['subject'] );
$auto = Mailer::render_template( 'event_cancelled', array( 'subject' => 's', 'body' => "Zeile 1\nZeile 2 & {event_name}", 'format' => 'html' ), array( 'event_name' => 'A<B' ) );
check( 'plain text in HTML mode is escaped and line breaks kept', "Zeile 1<br />\nZeile 2 &amp; A&lt;B" === $auto['body'] );
Settings::update( array( 'mail_layout' => array( 'logo_url' => 'https://example.test/logo.png', 'color' => '#ff0000', 'footer' => "Impressum\nWien" ) ) );
$prep = Mailer::prepare( 'x@example.com', $html );
check( 'layout wraps content with logo, colour, footer and HTML header', str_starts_with( $prep['body'], '<!DOCTYPE html>' ) && false !== strpos( $prep['body'], 'src="https://example.test/logo.png"' ) && false !== strpos( $prep['body'], '#ff0000' ) && false !== strpos( $prep['body'], 'Impressum<br />' ) && in_array( 'Content-Type: text/html; charset=UTF-8', $prep['headers'], true ) );
check( 'plain-text alternative: tags removed, links readable, entities decoded, footer appended', "Hallo <script>alert(1)</script> & Co\n\nhttps://example.test/?wpcap_ticket=abc&x=1\n\n-- \nImpressum\nWien" === $prep['alt_body'] );

Templates::save( 'tickets', 'de_DE', array( 'subject' => 'HTML-Tickets {order_id}', 'body' => '<p>Deine Tickets:</p>{ticket_list}<p>Links: {ticket_links}</p>', 'format' => 'html' ) );
$wpmt_prepared = array();
add_filter( 'wpcap_ticket_mail', $wpmt_legacy = static function ( $mail ) { $mail['subject'] .= ' (gefiltert)'; $mail['body'] = rtrim( $mail['body'] ) . "\n\nZUSATZ <x> https://extra.example/y\n"; return $mail; }, 50, 1 );
$before = count( $GLOBALS['wpcap_test_mails'] );
$o3 = wpmt_free_order( 'mt-html@example.com' );
remove_filter( 'wpcap_ticket_mail', $wpmt_legacy, 50 );
$hmail = array_values( array_filter( wpmt_mails_since( $before, 'mt-html@example.com' ), static fn( $m ) => str_starts_with( $m['subject'], 'HTML-Tickets' ) ) )[0] ?? array( 'subject' => '', 'body' => '' );
$hprep = array_values( array_filter( $wpmt_prepared, static fn( $x ) => str_starts_with( $x['subject'], 'HTML-Tickets' ) ) )[0] ?? array( 'alt_body' => null, 'phpmailer_alt' => '', 'headers' => array() );
preg_match( '/<code>([a-f0-9]{64})<\/code>/', $hmail['html'] ?? '', $hc );
check( 'HTML ticket mail: layout, ticket list with link and code', str_starts_with( $hmail['html'] ?? '', '<!DOCTYPE html>' ) && ! empty( $hc[1] ) && false !== strpos( $hmail['html'], '<a href="https://example.test/?wpcap_ticket=' . $hc[1] . '">' ) );
check( 'wpcap_ticket_mail still receives and changes subject/body; appended text is escaped and linked', 'HTML-Tickets ' . $o3['public_id'] . ' (gefiltert)' === $hmail['subject'] && false !== strpos( $hmail['html'], 'ZUSATZ &lt;x&gt; <a href="https://extra.example/y">https://extra.example/y</a>' ) );
check( 'AltBody is set via phpmailer_init and contains the plain ticket data', '' !== $hprep['phpmailer_alt'] && $hprep['phpmailer_alt'] === $hprep['alt_body'] && false !== strpos( $hprep['alt_body'], 'Ticket-Code: ' . ( $hc[1] ?? 'x' ) ) && false !== strpos( $hprep['alt_body'], 'ZUSATZ <x>' ) && false === strpos( $hprep['alt_body'], '<p>' ) );
$after = new Wpmt_Fake_Mailer();
do_action( 'phpmailer_init', $after );
check( 'phpmailer_init hook is removed after sending', '' === $after->AltBody && empty( array_filter( $GLOBALS['wpcap_test_hooks']['phpmailer_init'] ?? array() ) ) );
Templates::reset( 'tickets', 'de_DE' );

echo "Mail templates: wpcap_ticket_code_mail_section in resend mails\n";
add_filter( 'wpcap_ticket_code_mail_section', $wpmt_section = static function ( $text, $ticket_id ) { return $text . "\nWALLET https://wallet.example/" . (int) $ticket_id . "\n"; }, 5, 2 );
$before = count( $GLOBALS['wpcap_test_mails'] );
Orders::resend_tickets( $o['order_id'] );
$resent = wpmt_mails_since( $before, 'mt-default@example.com' )[0] ?? array( 'subject' => '', 'body' => '' );
$tid = (int) Orders::tickets_for_order( $o['order_id'] )[0]->ticket_id;
check( 'resend (text) keeps the old format and includes the section text', 'Deine Tickets für Testsite' === $resent['subject'] && str_starts_with( $resent['body'], "Deine Tickets für Testsite:\n\nKonzert — Standard\n" ) && false !== strpos( $resent['body'], "\nWALLET https://wallet.example/$tid\n" ) );
Templates::save( 'tickets_resend', 'de_DE', array( 'subject' => 'Neu: {ticket_count}', 'body' => '<p>Neu</p>{ticket_list}', 'format' => 'html' ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
Orders::resend_tickets( $o['order_id'] );
$resent_html = wpmt_mails_since( $before, 'mt-default@example.com' )[0] ?? array( 'subject' => '', 'body' => '' );
check( 'resend (HTML) links the section text', 'Neu: 2' === $resent_html['subject'] && false !== strpos( $resent_html['html'] ?? '', 'WALLET <a href="https://wallet.example/' . $tid . '">' ) );
remove_filter( 'wpcap_ticket_code_mail_section', $wpmt_section, 5 );
Templates::reset( 'tickets_resend', 'de_DE' );

echo "Mail templates: recipient locale\n";
Templates::save( 'event_cancelled', 'de_DE', array( 'subject' => 'DE {event_name}', 'body' => 'DE', 'format' => 'text' ) );
$GLOBALS['wpmt_locale_calls'] = array();
$fallback = Mailer::compose( 'event_cancelled', array( 'event_name' => 'X' ), 'en_US' );
check( 'missing locale falls back to the default-locale override', 'DE X' === $fallback['subject'] );
check( 'compose switches to the recipient locale and restores it', array( 'switch:en_US', 'restore' ) === $GLOBALS['wpmt_locale_calls'] && ! $GLOBALS['wpmt_locale_stack'] );
Templates::save( 'event_cancelled', 'en_US', array( 'subject' => 'EN {event_name}', 'body' => 'EN', 'format' => 'text' ) );
check( 'exact locale override wins; other locales use the default', 'EN X' === Mailer::compose( 'event_cancelled', array( 'event_name' => 'X' ), 'en_US' )['subject'] && 'DE X' === Mailer::compose( 'event_cancelled', array( 'event_name' => 'X' ), 'fr_FR' )['subject'] && 'DE X' === Mailer::compose( 'event_cancelled', array( 'event_name' => 'X' ) )['subject'] );
Templates::reset( 'event_cancelled', 'de_DE' );
check( 'without default-locale override the built-in text is used', 'Eventabsage: X' === Mailer::compose( 'event_cancelled', array( 'event_name' => 'X' ), 'fr_FR' )['subject'] );
$GLOBALS['wpmt_locale_calls'] = array();
$seen_locale = '';
$cb = static function () use ( &$seen_locale ) { $seen_locale = end( $GLOBALS['wpmt_locale_stack'] ) ?: 'none'; return 'x'; };
Mailer::compose( 'occurrence_cancelled', array( 'refund_note' => $cb ), 'en_US' );
check( 'callable values are evaluated inside the recipient locale', 'en_US' === $seen_locale && ! $GLOBALS['wpmt_locale_stack'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_orders SET locale='en_US' WHERE order_id=%d", $o2['order_id'] ) );
$before = count( $GLOBALS['wpcap_test_mails'] );
$GLOBALS['wpmt_locale_calls'] = array();
Orders::rotate_access_link( $o2['order_id'] );
$en_mail = wpmt_mails_since( $before, 'mt-override@example.com' )[0] ?? array( 'subject' => '' );
check( 'order mails are rendered in the order locale', 'Deine Bestellung bei Testsite' === $en_mail['subject'] && in_array( 'switch:en_US', $GLOBALS['wpmt_locale_calls'], true ) && ! $GLOBALS['wpmt_locale_stack'] );
Templates::reset( 'event_cancelled', 'en_US' );

echo "Mail templates: admin preview, test mail, save (nonce + capability)\n";
$in = array( 'type' => 'order_access', 'locale' => 'de_DE', 'subject' => "Betreff <b>{order_id}</b>\n", 'body' => '<p>Hallo {order_id}</p><p>{access_link}</p>', 'format' => 'html' );
$pv = MailAdmin::preview( $in );
check( 'preview renders the unsaved draft with sample data in the layout', is_array( $pv ) && 'Betreff 3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' === $pv['subject'] && str_starts_with( $pv['html'], '<!DOCTYPE html>' ) && false !== strpos( $pv['html'], 'Hallo 3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ) && false !== strpos( $pv['text'], 'wpcap_access=' ) && null === Templates::get( 'order_access', 'de_DE' ) );
$pv_text = MailAdmin::preview( array( 'type' => 'tickets', 'locale' => 'de_DE', 'subject' => 'S', 'body' => "A <b>\n{ticket_list}", 'format' => 'text' ) );
check( 'text preview is shown escaped in a pre block', is_array( $pv_text ) && false !== strpos( $pv_text['html'], '<pre' ) && false !== strpos( $pv_text['html'], 'Sommerkonzert — Stehplatz' ) && false === strpos( $pv_text['html'], '<b>' ) );
check( 'unknown type is rejected', is_wp_error( MailAdmin::preview( array( 'type' => 'nope' ) ) ) );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$_POST = $in;
$json = null;
try { MailAdmin::handle_preview_ajax(); } catch ( Wpmt_Json_Response $e ) { $json = $e; }
check( 'ajax preview checks the nonce and returns JSON', $json && $json->success && in_array( MailAdmin::NONCE, $GLOBALS['wpcap_test_nonce_checks'], true ) && isset( $json->payload['html'] ) && ! isset( $json->payload['message'] ) );
$GLOBALS['wpcap_test_denied_caps'] = array( 'manage_options' );
$json = null;
try { MailAdmin::handle_preview_ajax(); } catch ( Wpmt_Json_Response $e ) { $json = $e; }
$denied_preview = MailAdmin::preview( $in );
$denied_save = MailAdmin::save( $in );
$died = false;
try { MailAdmin::handle_test(); } catch ( Wpcap_Test_Die $e ) { $died = true; } catch ( Wpcap_Test_Redirect $e ) { $died = false; }
$GLOBALS['wpcap_test_denied_caps'] = array();
check( 'without manage_options: preview, save and test mail are refused', $json && ! $json->success && 403 === $json->payload['status'] && is_wp_error( $denied_preview ) && 403 === $denied_preview->get_error_data()['status'] && is_wp_error( $denied_save ) && $died && null === Templates::get( 'order_access', 'de_DE' ) );
$no_mail_user = function_exists( 'wp_get_current_user' ) && empty( wp_get_current_user()->user_email );
$no_recipient = $no_mail_user ? MailAdmin::send_test( $in ) : new WP_Error( 'skip' );
check( 'test mail needs a valid address of the current user', is_wp_error( $no_recipient ) );
add_filter( 'wpcap_mail_test_recipient', $wpmt_recipient = static function ( $to ) { return '' !== $to ? $to : 'mail-admin@example.com'; }, 10, 1 );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$before = count( $GLOBALS['wpcap_test_mails'] );
$redirect = '';
try { MailAdmin::handle_test(); } catch ( Wpcap_Test_Redirect $e ) { $redirect = $e->getMessage(); }
$test_mail = wpmt_mails_since( $before, 'mail-admin@example.com' )[0] ?? array( 'subject' => '', 'body' => '' );
check( 'test mail goes to the current admin with sample data, checks the nonce and redirects back', in_array( MailAdmin::NONCE, $GLOBALS['wpcap_test_nonce_checks'], true ) && '[Test] Betreff 3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' === $test_mail['subject'] && str_starts_with( $test_mail['html'] ?? '', '<!DOCTYPE html>' ) && false !== strpos( $redirect, 'type=order_access' ) && null === Templates::get( 'order_access', 'de_DE' ) );
remove_filter( 'wpcap_mail_test_recipient', $wpmt_recipient, 10 );
$GLOBALS['wpcap_test_nonce_checks'] = array();
$_POST = array( 'type' => 'order_access', 'locale' => 'en_US', 'subject' => "Hi <i>{order_id}</i>\n", 'body' => '<b>Text</b> {access_link}', 'format' => 'text' );
try { MailAdmin::handle_save(); } catch ( Wpcap_Test_Redirect $e ) {}
$saved = Templates::get( 'order_access', 'en_US' );
check( 'save checks the nonce and sanitises: plain subject, text body without tags', in_array( MailAdmin::NONCE, $GLOBALS['wpcap_test_nonce_checks'], true ) && $saved && 'Hi {order_id}' === $saved['subject'] && 'Text {access_link}' === $saved['body'] && 'text' === $saved['format'] );
check( 'empty subject or body is rejected', is_wp_error( MailAdmin::save( array( 'type' => 'order_access', 'locale' => 'de_DE', 'subject' => '', 'body' => 'x' ) ) ) );
$_POST = array( 'type' => 'order_access', 'locale' => 'en_US' );
try { MailAdmin::handle_reset(); } catch ( Wpcap_Test_Redirect $e ) {}
check( 'reset handler removes the locale override', null === Templates::get( 'order_access', 'en_US' ) && array() === Templates::all() );
$layout = MailAdmin::save_layout( array( 'logo_url' => 'javascript:alert(1)', 'color' => 'red;', 'footer' => '<b>Fuß</b>' ) );
check( 'layout settings are sanitised', array( 'logo_url' => '', 'color' => Layout::DEFAULT_COLOR, 'footer' => 'Fuß' ) === $layout['saved'] && Layout::DEFAULT_COLOR === Layout::settings()['color'] );
check( 'module registers page, handlers and ajax preview', MailAdmin::PAGE === MailTemplates::admin_pages()[0][0] && isset( MailTemplates::admin_handlers()[ MailAdmin::ACTION_TEST ] ) && ! empty( $GLOBALS['wpcap_test_hooks'][ 'wp_ajax_' . MailAdmin::ACTION_PREVIEW ] ) );

// Leave defaults for the following test files.
$_POST = array();
update_option( Templates::OPTION, array() );
Settings::update( array( 'mail_layout' => MailTemplates::settings_defaults()['mail_layout'] ) );
