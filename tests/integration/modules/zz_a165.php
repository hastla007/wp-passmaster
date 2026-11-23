<?php
/**
 * A165: buyer language choice in the checkout. The selector (Frontend\Language, field "buyer_locale") sets
 * the language of e-mails, tickets and the portal; the hidden "locale" keeps naming the language of the
 * legal texts shown on the page (consent and terms version). String check: front-end i18n keys
 * (tests/consistency.php) and the busy-retry message of the checkout.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Settings;

echo "A165: buyer language in the checkout\n";
global $wpdb;
// buyer_language_selector is a Policy setting (registered by Plugin::boot()); registered only for this module,
// so later modules see the same settings as without it.
$a165_policy = new ReflectionProperty( PassCaptain\Security\Policy::class, 'registered' );
$a165_policy_was = (bool) $a165_policy->getValue();
PassCaptain\Security\Policy::register();
$a165_saved = Settings::all();
Settings::update( array(
	'require_terms' => 1,
	'terms_text' => '<p>AGB deutsch</p>',
	'legal_texts' => array( 'fr_FR' => array( 'terms_text' => '<p>CGV français</p>', 'consumer_info_text' => '' ) ),
	'buyer_language_selector' => 1,
) );
$a165_type = make_type( 50, 10, 1000 );
$a165_de_version = Settings::terms_version( 'de_DE' );
check( 'A165: fixture – German and French terms differ', '' !== $a165_de_version && $a165_de_version !== Settings::terms_version( 'fr_FR' ) );

$a165_field = PassCaptain\Frontend\Language::checkout_field( 'wpcap-a165' );
check( 'A165: the checkout selector is the field buyer_locale (not a second "locale" next to the hidden legal locale)', false !== strpos( $a165_field, 'name="buyer_locale"' ) && false === strpos( $a165_field, 'name="locale"' ) );
check( 'A165: the REST checkout accepts buyer_locale', in_array( 'buyer_locale', PassCaptain\Frontend\Language::payload_keys( array() ), true ) );

function a165_order( array $extra ) {
	global $a165_type;
	$GLOBALS['wpcap_test_transients'] = array();
	return Orders::create( array( 'email' => 'a165-' . uniqid() . '@example.com', 'items' => array( array( 'ticket_type_id' => $a165_type, 'quantity' => 1 ) ), 'idempotency_key' => 'a165-' . wp_generate_uuid4(), 'accept_terms' => true ) + $extra );
}

// Page shown in German (terms accepted in German), buyer chooses French for mails and tickets.
$a165_fr = a165_order( array( 'locale' => 'de_DE', 'terms_version' => $a165_de_version, 'buyer_locale' => 'fr_FR' ) );
$a165_fr_row = is_wp_error( $a165_fr ) ? null : Orders::get( $a165_fr['order_id'] );
check( 'A165: a chosen language other than the page language does not invalidate the accepted terms', ! is_wp_error( $a165_fr ) );
check( 'A165: the order gets the chosen language, the terms version of the texts shown', $a165_fr_row && 'fr_FR' === $a165_fr_row->locale && $a165_de_version === $a165_fr_row->terms_version );
check( 'A165: the contract texts in the mail are the accepted (German) wording', $a165_fr_row && false !== strpos( wp_json_encode( PassCaptain\LegalVersions::mail_block( $a165_fr_row ) ), 'AGB deutsch' ) );

$a165_bad = a165_order( array( 'locale' => 'de_DE', 'terms_version' => $a165_de_version, 'buyer_locale' => 'xx_XX' ) );
check( 'A165: an unsupported choice falls back to the page language', ! is_wp_error( $a165_bad ) && 'de_DE' === Orders::get( $a165_bad['order_id'] )->locale );

Settings::update( array( 'buyer_language_selector' => 0 ) );
$a165_off = a165_order( array( 'locale' => 'de_DE', 'terms_version' => $a165_de_version, 'buyer_locale' => 'fr_FR' ) );
check( 'A165: with the selector switched off buyer_locale is ignored', ! is_wp_error( $a165_off ) && 'de_DE' === Orders::get( $a165_off['order_id'] )->locale );
Settings::update( array( 'buyer_language_selector' => 1 ) );

$a165_old = a165_order( array( 'locale' => 'fr_FR', 'terms_version' => Settings::terms_version( 'fr_FR' ) ) );
check( 'A165: without a choice the page language still decides (W9 behaviour)', ! is_wp_error( $a165_old ) && 'fr_FR' === Orders::get( $a165_old['order_id'] )->locale );

$a165_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'A165: the checkout script sends the selected language', false !== strpos( $a165_js, "select[name=\"buyer_locale\"]" ) && false !== strpos( $a165_js, 'buyer_locale: buyerLocale' ) );
check( 'A165: the busy-retry message is a translatable string (t.retrying)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' ), "'retrying' => __(" ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( WPCAP_PATH . 'tests/consistency.php' ) . ' 2>&1', $a165_out );
$a165_i18n = array_filter( $a165_out, static fn( $l ) => false !== strpos( $l, 'every i18n key is defined' ) );
check( 'A165: consistency check covers the i18n keys of the front-end scripts and passes for them', count( $a165_i18n ) >= 5 && ! array_filter( $a165_i18n, static fn( $l ) => false !== strpos( $l, 'FAIL' ) ) );

update_option( Settings::OPTION, $a165_saved );
if ( ! $a165_policy_was ) {
	remove_filter( 'wp_pass_captain_settings_defaults_v1', array( PassCaptain\Security\Policy::class, 'defaults' ) );
	remove_filter( 'wp_pass_captain_settings_sections_v1', array( PassCaptain\Security\Policy::class, 'sections' ) );
	$a165_policy->setValue( null, false );
}
Settings::flush();
