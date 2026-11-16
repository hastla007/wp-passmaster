<?php
/**
 * A506: the offline PWA's service worker gets a cache name derived from the shell files and the plugin version.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

use PassCaptain\OfflineScanner\Pwa;

echo "A506: PWA asset versioning\n";
$a506_sw = Pwa::body( WPCAP_PATH . 'assets/offline-scanner/sw.js' );
$a506_v = Pwa::shell_version();
check( 'A506: the served service worker carries the shell hash as cache name', 1 === preg_match( '/^[a-f0-9]{12}$/', $a506_v ) && false !== strpos( $a506_sw, "var CACHE = 'wpcap-offline-scanner-" . $a506_v . "';" ) && 1 === substr_count( $a506_sw, 'var CACHE =' ) );
check( 'A506: other shell files are served unchanged', (string) file_get_contents( WPCAP_PATH . 'assets/offline-scanner/app.js' ) === Pwa::body( WPCAP_PATH . 'assets/offline-scanner/app.js' ) );
check( 'A506: the hash is stable within a request', $a506_v === Pwa::shell_version() );
check( 'A506: shell files are revalidated by browsers (no-cache)', 'no-cache' === Pwa::headers( WPCAP_PATH . 'assets/offline-scanner/app.js' )['Cache-Control'] );
