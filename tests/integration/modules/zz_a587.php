<?php
/**
 * A587: reminders go out in the language of the holder's order (own texts per language, layout
 * locale) and carry the holder's personal ticket links. Included from run.php.
 */
// phpcs:ignoreFile
use PassCaptain\Communications;
use PassCaptain\Modules\Reminders;
use PassCaptain\Orders;
use PassCaptain\Settings;
use PassCaptain\TicketCodes;

if ( ! function_exists( 'switch_to_locale' ) ) {
	function switch_to_locale( $locale ) { $GLOBALS['wpmt_locale_calls'][] = 'switch:' . $locale; $GLOBALS['wpmt_locale_stack'][] = $locale; return true; }
	function restore_previous_locale() { $GLOBALS['wpmt_locale_calls'][] = 'restore'; array_pop( $GLOBALS['wpmt_locale_stack'] ); return true; }
}
echo "A587: reminders in the buyer's language with ticket links\n";
global $wpdb;
$p = $wpdb->prefix;
$a587_registered = false;
foreach ( $GLOBALS['wpcap_test_hooks']['wpcap_email_template'] ?? array() as $a587_cbs ) {
	foreach ( $a587_cbs as $a587_cb ) { $a587_registered = $a587_registered || array( Reminders::class, 'render_template' ) === $a587_cb[0]; }
}
if ( ! $a587_registered ) { Reminders::register(); }
TicketCodes::register();
$a587_saved = Reminders::save( array_merge( Reminders::settings_defaults()['reminders'], array( 'enabled' => '1', 'offsets' => '24', 'sms' => '', 'translations' => array( 'en_US' => array( 'subject' => 'Reminder: {event} on {date}', 'body' => "See you in {hours} hours!\n{ticket_links}" ) ) ) ) );
$a587_conf = (array) Settings::get( 'reminders' );
check( 'A587: unchanged built-in texts are stored as "" (translated per recipient), own English text kept', ! is_wp_error( $a587_saved ) && '' === $a587_conf['subject'] && '' === $a587_conf['body'] && 'Reminder: {event} on {date}' === ( $a587_conf['translations']['en_US']['subject'] ?? '' ) );
check( 'A587: config falls back to the built-in text', false !== strpos( Reminders::config()['body'], '{ticket_links}' ) && 0 === strpos( Reminders::config()['subject'], 'Erinnerung:' ) );

$a587_type = make_type( 20, 10, 0 );
$a587_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$a587_type" );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET starts_at=%s WHERE occurrence_id=%d", gmdate( 'Y-m-d H:i:s', time() + 10 * 3600 ), $a587_occ ) );
$GLOBALS['wpcap_test_transients'] = array();
$a587_en = Orders::create( array( 'email' => 'a587-en@example.com', 'locale' => 'en_US', 'items' => array( array( 'ticket_type_id' => $a587_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a587-en' ) );
$a587_de = Orders::create( array( 'email' => 'a587-de@example.com', 'items' => array( array( 'ticket_type_id' => $a587_type, 'quantity' => 2 ) ), 'idempotency_key' => 'a587-de' ) );
$a587_en_code = ticket_code_for( $a587_en['order_id'] )[0] ?? '';
$a587_de_codes = ticket_code_for( $a587_de['order_id'] );
Reminders::schedule_due();
$a587_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}wpcap_message_queue WHERE template_key='wpcap_reminder' AND payload LIKE %s", '%"occurrence_id":' . $a587_occ . ',%' ) );
$a587_payloads = array();
foreach ( $a587_rows as $a587_row ) { $a587_payloads[ $a587_row->recipient ] = json_decode( $a587_row->payload, true ); }
check( 'A587: the queued payload carries the order locale (no PII)', 'en_US' === ( $a587_payloads['a587-en@example.com']['locale'] ?? '' ) && in_array( $a587_payloads['a587-de@example.com']['locale'] ?? '', array( '', 'de_DE' ), true ) );

$GLOBALS['wpmt_locale_calls'] = array();
$GLOBALS['wpmt_locale_stack'] = array();
$a587_tpl_en = Reminders::render_template( null, 'wpcap_reminder', $a587_payloads['a587-en@example.com'] ?? array(), 'a587-en@example.com' );
check( 'A587: English recipient gets the English text, rendered inside en_US', is_array( $a587_tpl_en ) && 0 === strpos( $a587_tpl_en['subject'], 'Reminder: Konzert on ' ) && false !== strpos( $a587_tpl_en['body'], 'See you in' ) && 'en_US' === $a587_tpl_en['locale'] && in_array( 'switch:en_US', $GLOBALS['wpmt_locale_calls'], true ) && ! $GLOBALS['wpmt_locale_stack'] );
check( 'A587: {ticket_links} carries the recipient\'s own ticket link', '' !== $a587_en_code && false !== strpos( $a587_tpl_en['body'], 'wpcap_ticket=' . $a587_en_code ) );
$a587_tpl_de = Reminders::render_template( null, 'wpcap_reminder', $a587_payloads['a587-de@example.com'] ?? array(), 'a587-de@example.com' );
check( 'A587: German recipient gets the built-in German text with both ticket links, not the English one', is_array( $a587_tpl_de ) && 0 === strpos( $a587_tpl_de['subject'], 'Erinnerung: Konzert' ) && 2 === count( $a587_de_codes ) && false !== strpos( $a587_tpl_de['body'], 'wpcap_ticket=' . $a587_de_codes[0] ) && false !== strpos( $a587_tpl_de['body'], 'wpcap_ticket=' . $a587_de_codes[1] ) && false === strpos( $a587_tpl_de['body'], $a587_en_code ) );
$wpdb->query( "UPDATE {$p}wpcap_tickets SET code_secret=NULL WHERE order_id=" . (int) $a587_de['order_id'] );
$a587_tpl_nocode = Reminders::render_template( null, 'wpcap_reminder', $a587_payloads['a587-de@example.com'] ?? array(), 'a587-de@example.com' );
check( 'A587: without a stored code the reminder points to the ticket mail (no new code issued)', false === strpos( $a587_tpl_nocode['body'], 'wpcap_ticket=' ) && false !== strpos( $a587_tpl_nocode['body'], 'E-Mail mit deinen Tickets' ) && in_array( hash( 'sha256', $a587_de_codes[0] ), $wpdb->get_col( "SELECT token_hash FROM {$p}wpcap_tickets WHERE order_id=" . (int) $a587_de['order_id'] ), true ) );

$GLOBALS['wpcap_test_mails'] = array();
Communications::process_due();
$a587_mail = array_values( array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => 'a587-en@example.com' === $m['to'] ) )[0] ?? array( 'subject' => '', 'body' => '' );
check( 'A587: the queue passes the recipient and sends the English reminder with the ticket link', 0 === strpos( $a587_mail['subject'], 'Reminder: Konzert' ) && false !== strpos( $a587_mail['body'], 'wpcap_ticket=' . $a587_en_code ) );

Settings::update( array( 'reminders' => array( 'enabled' => 0 ) ) );
remove_filter( 'wpcap_ticket_mail', array( TicketCodes::class, 'capture' ), 0 );
remove_action( 'wpcap_ticket_code_rotated', array( TicketCodes::class, 'remember' ), 5 );
remove_action( 'wpcap_ticket_code_issued', array( TicketCodes::class, 'remember' ), 5 );
remove_action( 'wpcap_ticket_revoked', array( TicketCodes::class, 'forget' ), 5 );
remove_filter( 'wpcap_ticket_known_code', array( TicketCodes::class, 'known_code' ), 5 );
