<?php
/**
 * A553: typo protection for the buyer e-mail. Domain list for the checkout script (filterable,
 * cleaned), texts for hint, soft stop and the confirmation panel. The behaviour is covered by
 * tests/js/checkout.test.js. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\PublicEvents;

echo "A553: buyer e-mail typo hint\n";

$a553_domains = PublicEvents::email_domains();
check( 'A553 default domain list has the common providers (gmail.com, gmx.de, web.de, t-online.de)', ! array_diff( array( 'gmail.com', 'gmx.de', 'web.de', 't-online.de', 'gmx.at', 'outlook.com' ), $a553_domains ) );
check( 'A553 domain list is lowercase and unique', $a553_domains === array_values( array_unique( array_map( 'strtolower', $a553_domains ) ) ) );

$a553_filter = static function ( $domains ) { return array_merge( $domains, array( ' Verein-Mail.AT ', 'kein domain', '', 'gmail.com' ) ); };
add_filter( 'wpcap_checkout_email_domains', $a553_filter );
$a553_filtered = PublicEvents::email_domains();
remove_filter( 'wpcap_checkout_email_domains', $a553_filter );
check( 'A553 wpcap_checkout_email_domains adds domains; invalid entries and duplicates are dropped', in_array( 'verein-mail.at', $a553_filtered, true ) && ! in_array( 'kein domain', $a553_filtered, true ) && ! in_array( '', $a553_filtered, true ) && 1 === count( array_keys( $a553_filtered, 'gmail.com', true ) ) );

$a553_off = static function () { return array(); };
add_filter( 'wpcap_checkout_email_domains', $a553_off );
check( 'A553 an empty list turns the hint off', array() === PublicEvents::email_domains() );
remove_filter( 'wpcap_checkout_email_domains', $a553_off );

$a553_src = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
check( 'A553 checkout config hands the domains to the script', false !== strpos( $a553_src, "'emailDomains' => self::email_domains()," ) );
check( 'A553 hint, soft stop and confirmation texts are translatable', false !== strpos( $a553_src, "'emailSuggest' => __( 'Meintest du %s?', 'pass-captain' )" ) && false !== strpos( $a553_src, "'emailTypo' => __(" ) && false !== strpos( $a553_src, "'wrongEmail' => __( 'Adresse falsch?" ) );
$a553_js = (string) file_get_contents( WPCAP_PATH . 'assets/checkout.js' );
check( 'A553 checkout script: suggestion, soft stop once per address, note on the confirmation', false !== strpos( $a553_js, 'function emailSuggestion(value)' ) && false !== strpos( $a553_js, 'emailChecked.get(form) !== value' ) && false !== strpos( $a553_js, "class: 'wpcap-wrong-email'" ) );
