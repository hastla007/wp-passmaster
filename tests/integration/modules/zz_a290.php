<?php
/**
 * A290: links of the "Erste Schritte" checklist open the settings tab (and payments section) the
 * step belongs to; the anchor exists on that tab.
 * Included from run.php (uses check()).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\GettingStarted;
use PassCaptain\Admin\SettingsPage;
use PassCaptain\Settings;

require_once __DIR__ . '/zz_r6_stubs.inc';

echo "A290: checklist links open the right settings tab\n";
$a290_saved = get_option( Settings::OPTION, array() );
$a290_by = static function ( array $items ) { $o = array(); foreach ( $items as $i ) { $o[ $i['key'] ] = $i; } return $o; };
$a290_items = $a290_by( GettingStarted::items( array() ) );
$a290_query = static function ( $url ) { parse_str( (string) parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $q ); return $q; };
$a290_fragment = static fn( $url ) => (string) parse_url( $url, PHP_URL_FRAGMENT );

$a290_expect = array( 'payment' => array( 'payments', 'payments' ), 'legal' => array( 'legal', 'legal' ), 'seller' => array( 'invoices', 'seller' ) );
foreach ( $a290_expect as $a290_key => list( $a290_tab, $a290_anchor ) ) {
	$a290_url = $a290_items[ $a290_key ]['url'];
	$a290_q = $a290_query( $a290_url );
	ob_start(); SettingsPage::render( $a290_q['tab'] ?? '', $a290_q['section'] ?? '' ); $a290_html = ob_get_clean();
	check( "step $a290_key links to tab $a290_tab and the anchor exists there", 'wpcap-settings' === ( $a290_q['page'] ?? '' ) && $a290_tab === ( $a290_q['tab'] ?? '' ) && 'wpcap-settings-' . $a290_anchor === $a290_fragment( $a290_url ) && false !== strpos( $a290_html, 'id="wpcap-settings-' . $a290_anchor . '"' ) );
}
// Every settings link of the checklist names a known tab (no fallback to "Allgemein").
$a290_bad = array_filter( $a290_items, static fn( $i ) => false !== strpos( $i['url'], 'page=wpcap-settings' ) && '' === SettingsPage::tab( $a290_query( $i['url'] )['tab'] ?? '' ) );
check( 'no checklist link to the settings lacks a valid tab', ! $a290_bad );

// Bank transfer enabled but incomplete: the payment step opens the "Überweisung" section.
$a290_manual = $a290_by( GettingStarted::items( array( 'manual_incomplete' => true ) ) )['payment']['url'];
check( 'incomplete bank transfer: payment step opens tab=payments&section=manual#wpcap-settings-manual', 'payments' === ( $a290_query( $a290_manual )['tab'] ?? '' ) && 'manual' === ( $a290_query( $a290_manual )['section'] ?? '' ) && 'wpcap-settings-manual' === $a290_fragment( $a290_manual ) );
Settings::update( array( 'enabled_providers' => array( 'manual' ), 'bank_iban' => '', 'bank_recipient' => '', 'seller_name' => '', 'manual_instructions' => '' ) );
check( 'state() reports the incomplete bank transfer', true === GettingStarted::state()['manual_incomplete'] );
Settings::update( array( 'manual_instructions' => 'Bitte überweisen.' ) );
check( 'state(): configured bank transfer is not incomplete', false === GettingStarted::state()['manual_incomplete'] );

update_option( Settings::OPTION, $a290_saved );
Settings::flush();
