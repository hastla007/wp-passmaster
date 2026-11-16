<?php
/**
 * A296: buyer mails carry a sender name and a Reply-To (settings "Absendername" and "Antwort an",
 * defaults: seller name / site name and admin e-mail); the From address stays WordPress'.
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\SettingsPage;
use PassCaptain\Mail\Mailer;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';

echo "A296: sender name and reply-to of buyer mails\n";
$a296_saved = get_option( Settings::OPTION, array() );
$a296_admin = get_option( 'admin_email' );
update_option( 'admin_email', 'admin@example.com' );
$a296_send = static function () {
	$seen = array();
	add_action( 'wpcap_mail_before_send', $cb = static function ( $prepared ) use ( &$seen ) { $seen['from_name'] = apply_filters( 'wp_mail_from_name', 'WordPress' ); $seen['headers'] = $prepared['headers']; }, 10, 1 );
	$GLOBALS['wpcap_test_mails'] = array_values( $GLOBALS['wpcap_test_mails'] );
	$before = count( $GLOBALS['wpcap_test_mails'] );
	Mailer::deliver( 'buyer-a296@example.com', array( 'type' => 'order_confirmation', 'subject' => 'Test', 'body' => 'Hallo', 'format' => 'text', 'locale' => '', 'attachments' => array() ) );
	remove_action( 'wpcap_mail_before_send', $cb );
	$seen['mail'] = $GLOBALS['wpcap_test_mails'][ $before ] ?? array();
	return $seen;
};

Settings::update( array( 'mail_from_name' => '', 'mail_reply_to' => '', 'seller_name' => 'Kulturverein Nord' ) );
$a296 = $a296_send();
check( 'defaults: sender name = seller name, Reply-To = admin e-mail', 'Kulturverein Nord' === $a296['from_name'] && in_array( 'Reply-To: admin@example.com', (array) $a296['mail']['headers'], true ) );
check( 'the From name applies only while the plugin sends', 'WordPress' === apply_filters( 'wp_mail_from_name', 'WordPress' ) );
Settings::update( array( 'seller_name' => '' ) );
check( 'without seller name the site name is used', 'Testsite' === Mailer::sender()['name'] );

Settings::update( array( 'mail_from_name' => "Theater\r\nBcc: evil@example.com", 'mail_reply_to' => 'kasse@example.com' ) );
$a296 = $a296_send();
check( 'settings win; header injection is neutralised', 'Theater Bcc: evil@example.com' === $a296['from_name'] && in_array( 'Reply-To: kasse@example.com', (array) $a296['mail']['headers'], true ) && 1 === count( preg_grep( '/^Reply-To:/i', (array) $a296['mail']['headers'] ) ) );
$a296_own = Mailer::prepare( 'x@example.com', array( 'subject' => 'S', 'body' => 'B', 'format' => 'text', 'headers' => array( 'reply-to: other@example.com' ) ) );
check( 'a Reply-To already set by a template/filter is kept', array( 'reply-to: other@example.com' ) === $a296_own['headers'] );
add_filter( 'wpcap_mail_sender', $a296_f = static fn( $s ) => array( 'name' => 'Filter', 'reply_to' => 'not-an-address' ) );
check( 'wpcap_mail_sender adjusts the sender; an invalid reply address is dropped', array( 'name' => 'Filter', 'reply_to' => '' ) === Mailer::sender() && array() === Mailer::sender_headers() );
remove_filter( 'wpcap_mail_sender', $a296_f );

// Service booking mails (sent without deliver()) get the same sender.
$GLOBALS['wpcap_test_mails'] = array_values( $GLOBALS['wpcap_test_mails'] );
$a296_before = count( $GLOBALS['wpcap_test_mails'] );
Mailer::send_plain( 'svc-a296@example.com', 'Buchung', 'Text' );
check( 'send_plain adds the Reply-To', in_array( 'Reply-To: kasse@example.com', (array) ( $GLOBALS['wpcap_test_mails'][ $a296_before ]['headers'] ?? array() ), true ) );

// Settings: general tab section, e-mail validated.
ob_start(); SettingsPage::render( 'general' ); $a296_html = ob_get_clean();
check( 'general tab shows "E-Mails an Käufer" with both fields', false !== strpos( $a296_html, 'id="wpcap-settings-mail"' ) && false !== strpos( $a296_html, 'name="mail_from_name"' ) && false !== strpos( $a296_html, 'type="email" id="wpcap-set-mail_reply_to"' ) );
$a296_bad = SettingsPage::save( array( 'wpcap_tab' => 'general', 'mail_from_name' => 'Bühne', 'mail_reply_to' => 'kein@' ) );
check( 'an invalid reply-to address is rejected with the field name, the rest is saved', 1 === count( $a296_bad['errors'] ) && false !== strpos( $a296_bad['errors'][0], 'Antwort an' ) && 'kasse@example.com' === Settings::get( 'mail_reply_to' ) && 'Bühne' === Settings::get( 'mail_from_name' ) );
SettingsPage::save( array( 'wpcap_tab' => 'general', 'mail_from_name' => '', 'mail_reply_to' => '' ) );
check( 'empty fields reset to the defaults', '' === Settings::get( 'mail_reply_to' ) && '' === Settings::get( 'mail_from_name' ) );

update_option( 'admin_email', $a296_admin );
update_option( Settings::OPTION, $a296_saved );
Settings::flush();
