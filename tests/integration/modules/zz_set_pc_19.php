<?php
/**
 * SET-PC-19: imprint and privacy link as settings, shown in checkout, mails and as placeholders.
 */
use PassCaptain\Frontend\LegalNotice;
use PassCaptain\Settings;
use PassCaptain\SettingsSchema;

echo "SET-PC-19: imprint and privacy links\n";
$sp19_saved = get_option( Settings::OPTION, array() );
Settings::update( array( 'imprint_url' => '', 'privacy_url' => '' ) );
check( 'SET-PC-19: without settings there is no imprint link and the privacy link is the WordPress page', '' === LegalNotice::imprint_url() && ( function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '' ) === LegalNotice::privacy_url() );
Settings::update( array( 'imprint_url' => 'https://example.com/impressum', 'privacy_url' => 'https://example.com/datenschutz' ) );
$sp19_occ = (object) array( 'starts_at' => '2027-05-01 18:00:00' );
$sp19_html = LegalNotice::checkout_html( $sp19_occ, array( (object) array( 'starts_at' => '2027-05-01 18:00:00' ) ) );
check( 'SET-PC-19: the checkout shows imprint and the configured privacy link', false !== strpos( $sp19_html, 'https://example.com/impressum' ) && false !== strpos( $sp19_html, 'https://example.com/datenschutz' ) );
$sp19_vars = PassCaptain\Mail\Mailer::global_vars();
check( 'SET-PC-19: mails offer {imprint_url} and {privacy_url}', '' !== $sp19_vars['imprint_url'] && '' !== $sp19_vars['privacy_url'] && isset( PassCaptain\Mail\Types::global_placeholders()['imprint_url'] ) );
check( 'SET-PC-19: the mail footer links imprint and privacy policy (HTML and text)', false !== strpos( PassCaptain\Mail\Layout::wrap( '<p>x</p>', 'Betreff' ), 'example.com/impressum' ) && false !== strpos( PassCaptain\Mail\Layout::alt_text( '<p>x</p>', 'x' ), 'Impressum: https://example.com/impressum' ) );
check( 'SET-PC-19: addresses are checked (full http(s) address)', is_wp_error( SettingsSchema::validate( 'imprint_url', 'example.com/impressum' ) ) && is_wp_error( SettingsSchema::validate( 'privacy_url', 'javascript:alert(1)' ) ) && '' === SettingsSchema::validate( 'imprint_url', '' ) );
update_option( Settings::OPTION, $sp19_saved );
Settings::flush();
