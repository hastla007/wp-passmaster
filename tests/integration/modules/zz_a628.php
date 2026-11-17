<?php
// phpcs:ignoreFile
/**
 * A628: pass design – no generated text logo on Apple passes (the organisation name was shown twice
 * and umlauts were stripped); logoText keeps UTF-8; an operator logo is still used. Google passes get
 * the operator's logo (HTTPS URL setting, mail layout logo or site icon).
 */
use PassCaptain\Settings;
use PassCaptain\WalletPasses\AdminPage;
use PassCaptain\WalletPasses\Archive;
use PassCaptain\WalletPasses\Service;

require_once __DIR__ . '/r7/wallet_fixture.php';
echo "A628: wallet pass design (logo, umlauts, Google logo)\n";
wpr7_wallet_enable();
Settings::update( array( 'wallet_organization_name' => 'Bühnenverein Österreich', 'wallet_apple_logo_path' => '', 'wallet_google_logo_url' => '' ) );
$a628_apple = Service::provider( 'apple' );
$a628_ticket = array( 'public_id' => str_repeat( 'a6', 16 ), 'event_name' => 'Kärntner Abend', 'ticket_name' => 'Stehplatz', 'starts_at' => '2027-05-01 18:00:00', 'ends_at' => null, 'timezone' => 'Europe/Vienna', 'location' => 'Graz', 'code' => 'CODE-A628', 'occurrence_id' => 628, 'seat' => '', 'attendee_name' => '' );
$a628_files = wpr7_unzip( $a628_apple->bundle( $a628_apple->pass_json( $a628_ticket ) ) );
$a628_json = json_decode( $a628_files['pass.json'] ?? '{}', true );
check( 'A628: Apple pass without operator logo has no generated text logo', ! isset( $a628_files['logo.png'] ) && ! isset( $a628_files['logo@2x.png'] ) && isset( $a628_files['icon.png'], $a628_files['icon@2x.png'] ) );
check( 'A628: logoText and organisation keep the umlauts', 'Bühnenverein Österreich' === ( $a628_json['logoText'] ?? '' ) && 'Bühnenverein Österreich' === ( $a628_json['organizationName'] ?? '' ) && false !== strpos( $a628_files['pass.json'], 'Bühnenverein' ) );
check( 'A628: the generated logo helper is gone', ! method_exists( Archive::class, 'logo_png' ) );

$a628_png = sys_get_temp_dir() . '/a628-logo-' . getmypid() . '.png';
file_put_contents( $a628_png, Archive::solid_png( 160, 50, array( 10, 20, 30 ) ) );
Settings::update( array( 'wallet_apple_logo_path' => $a628_png ) );
$a628_files = wpr7_unzip( $a628_apple->bundle( $a628_apple->pass_json( $a628_ticket ) ) );
check( 'A628: an operator logo is still added (@1x and @2x)', isset( $a628_files['logo.png'], $a628_files['logo@2x.png'] ) && file_get_contents( $a628_png ) === $a628_files['logo.png'] );
@unlink( $a628_png );

$a628_g = Service::provider( 'google' );
$a628_saved_layout = Settings::get( 'mail_layout' );
Settings::update( array( 'mail_layout' => array_merge( (array) $a628_saved_layout, array( 'logo_url' => '' ) ) ) );
$a628_class = $a628_g->class_payload( $a628_ticket );
check( 'A628: Google class without any logo source has no logo', ! isset( $a628_class['logo'] ) );
Settings::update( array( 'mail_layout' => array_merge( (array) $a628_saved_layout, array( 'logo_url' => 'https://example.test/mail-logo.png' ) ) ) );
check( 'A628: Google falls back to the mail layout logo', 'https://example.test/mail-logo.png' === ( $a628_g->class_payload( $a628_ticket )['logo']['sourceUri']['uri'] ?? '' ) );
Settings::update( array( 'wallet_google_logo_url' => 'https://cdn.example.test/wallet-logo.png' ) );
$a628_class = $a628_g->class_payload( $a628_ticket );
check( 'A628: the Google logo setting wins, with a description in the buyer\'s language', 'https://cdn.example.test/wallet-logo.png' === ( $a628_class['logo']['sourceUri']['uri'] ?? '' ) && 'Bühnenverein Österreich' === ( $a628_class['logo']['contentDescription']['defaultValue']['value'] ?? '' ) );
$a628_bad = AdminPage::save( array( 'wallet_google_logo_url' => 'http://insecure.example.test/logo.png' ) );
check( 'A628: a non-HTTPS Google logo URL is rejected', is_wp_error( $a628_bad ) && 'wpcap_wallet_logo_url' === $a628_bad->get_error_code() );

Settings::update( array( 'mail_layout' => $a628_saved_layout, 'wallet_google_logo_url' => '', 'wallet_organization_name' => 'Pass Captain Test' ) );
wpr7_wallet_disable();
