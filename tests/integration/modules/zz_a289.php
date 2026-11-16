<?php
/**
 * A289: the checkout shows the terms only with "Zustimmung im Checkout verlangen", so the legal
 * step of the checklist is done only with require_terms; required consent with an empty terms
 * text is warned about (checklist, settings tab, saving).
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\GettingStarted;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';

echo "A289: legal step needs required consent, warning for consent without terms\n";
$a289_saved = get_option( Settings::OPTION, array() );
$a289_legal = static function () { foreach ( GettingStarted::items( GettingStarted::state() ) as $i ) { if ( 'legal' === $i['key'] ) { return $i; } } return null; };

Settings::update( array( 'require_terms' => 0, 'terms_text' => '<p>AGB</p>', 'consumer_info_text' => '<p>Widerruf</p>' ) );
$a289_item = $a289_legal();
check( 'texts without required consent: step open (terms are not shown in the checkout)', ! $a289_item['done'] && false !== strpos( $a289_item['description'], 'erst angezeigt' ) );
ob_start(); SettingsPage::render( 'legal' ); $a289_html = ob_get_clean();
check( 'settings explain that the terms appear only with the consent option', false !== strpos( $a289_html, 'Ohne diese Option erscheinen die AGB im Checkout nicht' ) && false === strpos( $a289_html, 'notice-warning' ) );

Settings::update( array( 'require_terms' => 1 ) );
check( 'texts and required consent: step done', $a289_legal()['done'] );

Settings::update( array( 'terms_text' => '<p> </p>' ) );
$a289_item = $a289_legal();
check( 'required consent with empty terms: step open with a warning', ! $a289_item['done'] && false !== strpos( $a289_item['description'], 'kein AGB-Text' ) );
ob_start(); SettingsPage::render( 'legal' ); $a289_html = ob_get_clean();
check( 'legal tab shows a warning for required consent without terms text', false !== strpos( $a289_html, 'notice-warning' ) && false !== strpos( $a289_html, 'kein AGB-Text hinterlegt' ) );
$a289_result = SettingsPage::save( array( 'wpcap_tab' => 'legal', 'require_terms' => '1', 'terms_text' => '', 'consumer_info_text' => 'VI' ) );
check( 'saving the legal tab with consent but without terms returns the warning (saved anyway)', array() === $a289_result['errors'] && 1 === count( $a289_result['warnings'] ) && 1 === (int) Settings::get( 'require_terms' ) );
$a289_ok = SettingsPage::save( array( 'wpcap_tab' => 'legal', 'require_terms' => '1', 'terms_text' => 'AGB', 'consumer_info_text' => 'VI' ) );
$a289_general = SettingsPage::save( array( 'wpcap_tab' => 'general', 'hold_minutes' => '10' ) );
check( 'no warning once the terms text exists, none for other tabs', array() === $a289_ok['warnings'] && array() === $a289_general['warnings'] && '' === SettingsPage::terms_warning() );

update_option( Settings::OPTION, $a289_saved );
Settings::flush();
