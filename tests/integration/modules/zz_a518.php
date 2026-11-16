<?php
// phpcs:ignoreFile
/**
 * A518: Google Wallet texts declare a real language: the ticket object the buyer's (A474), the event
 * class – shared by all buyers of an occurrence – the site language, and the plugin texts of the class
 * (cancellation message) come with translatedValues for every bundled catalogue.
 */

use PassCaptain\Mail\Mailer;
use PassCaptain\WalletPasses\GoogleProvider;

echo "A518: Google Wallet languages\n";
check( 'A518: WordPress locales become BCP 47 languages', 'en-US' === GoogleProvider::language( 'en_US' ) && 'de-DE' === GoogleProvider::language( 'de_DE_formal' ) && 'fr' === GoogleProvider::language( 'fr' ) && 'de' === GoogleProvider::language( '' ) );
$a518_t = GoogleProvider::translated( static fn() => 'text-' . Mailer::active_locale() );
$a518_langs = array_column( $a518_t['translatedValues'] ?? array(), 'value', 'language' );
check( 'A518: the default value is built in the site language', 'de-DE' === $a518_t['defaultValue']['language'] && 'text-' . Mailer::site_locale() === $a518_t['defaultValue']['value'] );
check( 'A518: every bundled catalogue language is added as translatedValue', 'text-en_US' === ( $a518_langs['en-US'] ?? '' ) && 'text-fr_FR' === ( $a518_langs['fr-FR'] ?? '' ) && 5 === count( $a518_langs ) );
$a518_class = Mailer::with_locale( 'en_US', static fn() => ( new GoogleProvider() )->class_payload( array( 'occurrence_id' => 9, 'event_name' => 'Konzert', 'starts_at' => '2026-12-24 19:00:00', 'ends_at' => null, 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'occurrence_status' => 'cancelled', 'locale' => 'en_US' ) ) );
check( 'A518: the shared class keeps the site language although an English buyer triggered the build', 'de-DE' === $a518_class['eventName']['defaultValue']['language'] && 'de-DE' === $a518_class['venue']['name']['defaultValue']['language'] );
check( 'A518: the cancellation message is localised (header/body with translatedValues)', 'de-DE' === ( $a518_class['messages'][0]['localizedHeader']['defaultValue']['language'] ?? '' ) && isset( $a518_class['messages'][0]['localizedBody']['defaultValue']['value'] ) && 'Abgesagt' === $a518_class['messages'][0]['header'] );
check( 'A518: site_locale() inside with_locale() still reports the site', Mailer::site_locale() === Mailer::with_locale( 'en_US', static fn() => Mailer::site_locale() ) );
