<?php
/**
 * A649: the offline scanner only accepts the app API of the site as API base; the server hands out
 * such a base in the pairing QR code and the admin link. The PWA check itself is tested in
 * tests/js/offline-scanner.test.js (apiBaseOk).
 * Included from run.php after mobile.php (uses check()).
 */
// phpcs:ignoreFile
use PassCaptain\Mobile\Devices;
use PassCaptain\OfflineScanner\AdminPage;

require_once __DIR__ . '/zz_r6_stubs.inc'; // get_user_locale() for AdminPage::app_url().
echo "A649: API base of the offline scanner\n";
$GLOBALS['wpcap_test_user_id'] = 7;
$a649_pairing = Devices::create_pairing( array( 1 ), 'A649', 1 );
$GLOBALS['wpcap_test_user_id'] = 1;
$a649_qr = json_decode( (string) ( $a649_pairing['qr_payload'] ?? '' ), true );
$a649_base = Devices::api_base();
check( 'A649: the pairing QR carries the app API base ending in /wp-json/pass-captain/v1/app/', is_array( $a649_qr ) && $a649_base === $a649_qr['api'] && '/wp-json/pass-captain/v1/app/' === substr( $a649_base, -29 ) );
check( 'A649: the admin link passes the same base to the PWA', false !== strpos( AdminPage::app_url(), 'index.html?api=' . rawurlencode( $a649_base ) ) );
$a649_js = (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' );
check( 'A649: the PWA checks ?api=, the pairing QR and the stored base with apiBaseOk() (no plain same-origin check left)', 3 <= substr_count( $a649_js, 'apiBaseOk(param)' ) + substr_count( $a649_js, 'apiBaseOk(qr.api)' ) + substr_count( $a649_js, 'apiBaseOk(api)' ) + substr_count( $a649_js, 'apiBaseOk(current)' ) + substr_count( $a649_js, 'apiBaseOk(apiBase)' ) && false === strpos( $a649_js, 'sameOrigin(' ) );
