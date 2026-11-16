<?php
// phpcs:ignoreFile
/**
 * A620: building a pass reuses work: the signed bundle per content (pass.json, images, certificate),
 * the images per settings hash (transient), the parsed credentials per request – and every change of
 * content, design or credentials still produces a fresh result.
 */
use PassCaptain\Secrets;
use PassCaptain\Settings;
use PassCaptain\WalletPasses\AppleProvider;
use PassCaptain\WalletPasses\Credentials;
use PassCaptain\WalletPasses\Service;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A620: wallet pass build cache\n";
wpr7_wallet_enable();
$a620_apple = Service::provider( 'apple' );
$a620_ticket = array( 'public_id' => str_repeat( 'c6', 16 ), 'event_name' => 'Cache-Abend', 'ticket_name' => 'Sitzplatz', 'starts_at' => '2027-06-01 18:00:00', 'ends_at' => null, 'timezone' => 'Europe/Vienna', 'location' => 'Linz', 'code' => 'CODE-A620', 'occurrence_id' => 620, 'seat' => '', 'attendee_name' => '' );

// PKCS#7 signatures carry the signing time: identical bytes prove the bundle was reused, not re-signed.
$a620_one = $a620_apple->bundle( $a620_apple->pass_json( $a620_ticket ) );
sleep( 1 );
$a620_two = $a620_apple->bundle( $a620_apple->pass_json( $a620_ticket ) );
check( 'A620: the same pass content is signed once and reused', '' !== $a620_one && $a620_one === $a620_two );
$a620_changed = $a620_ticket;
$a620_changed['location'] = 'Wels';
$a620_three = $a620_apple->bundle( $a620_apple->pass_json( $a620_changed ) );
check( 'A620: changed content builds a new bundle', $a620_three !== $a620_one && false !== strpos( wpr7_unzip( $a620_three )['pass.json'] ?? '', 'Wels' ) );

// Images: kept per settings hash in a transient, rebuilt when the design changes. A colour no earlier
// file used, so this request builds (and stores) them now.
Settings::update( array( 'wallet_background_color' => '#620620' ) );
$a620_images = $a620_apple->images();
$a620_cached = get_transient( AppleProvider::IMAGES_TRANSIENT );
check( 'A620: the built images are kept in a transient with their settings hash', is_array( $a620_cached ) && 64 === strlen( (string) ( $a620_cached['hash'] ?? '' ) ) && base64_decode( $a620_cached['images']['icon.png'] ?? '' ) === $a620_images['icon.png'] );
Settings::update( array( 'wallet_background_color' => '#620621' ) );
$a620_new_images = $a620_apple->images();
check( 'A620: a changed colour rebuilds the images (new hash)', $a620_new_images['icon.png'] !== $a620_images['icon.png'] && get_transient( AppleProvider::IMAGES_TRANSIENT )['hash'] !== $a620_cached['hash'] );
$a620_four = $a620_apple->bundle( $a620_apple->pass_json( $a620_ticket ) );
check( 'A620: a design change also changes the bundle', $a620_four !== $a620_one );

// Credentials: parsed once per request, a changed source is read again.
$a620_c1 = Credentials::apple();
$a620_c2 = Credentials::apple();
check( 'A620: Apple credentials are reused within the request', is_array( $a620_c1 ) && $a620_c1 === $a620_c2 );
$a620_wwdr = Secrets::get( 'apple_wwdr_cert' );
Secrets::set( 'apple_wwdr_cert', 'kein Zertifikat' );
check( 'A620: changed credentials are not served from the cache', is_wp_error( Credentials::apple() ) );
Secrets::set( 'apple_wwdr_cert', $a620_wwdr );
check( 'A620: the original credentials are valid again', is_array( Credentials::apple() ) );
$a620_g = Credentials::google();
check( 'A620: Google credentials are reused within the request', is_array( $a620_g ) && $a620_g === Credentials::google() );

Settings::update( array( 'wallet_background_color' => '#1d2327' ) );
wpr7_wallet_disable();
