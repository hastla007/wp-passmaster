<?php
/**
 * PHPStan bootstrap: constants the plugin defines at runtime in pass-captain.php.
 * Only read by PHPStan (phpstan.neon.dist), never shipped (build/ is in .distignore).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );
defined( 'WPCAP_VERSION' ) || define( 'WPCAP_VERSION', '0.0.0' );
defined( 'WPCAP_FILE' ) || define( 'WPCAP_FILE', dirname( __DIR__ ) . '/pass-captain.php' );
defined( 'WPCAP_PATH' ) || define( 'WPCAP_PATH', dirname( __DIR__ ) . '/' );
defined( 'WPCAP_URL' ) || define( 'WPCAP_URL', 'https://example.org/wp-content/plugins/pass-captain/' );
