<?php
// phpcs:ignoreFile
/**
 * A306: mails in the buyer's language also without an installed WordPress core language pack. When
 * switch_to_locale() refuses the locale, Mailer::with_locale() loads only the plugin's catalogue for it
 * and restores the site's afterwards. Runs in a child process (locale stubs).
 */

echo "A306: buyer language without a core language pack\n";
$a306_cmd = 'WPCAP_TEST_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/zz_a306/child.php' ) . ' 2>&1';
$a306_raw = (string) shell_exec( $a306_cmd );
$a306 = json_decode( trim( $a306_raw ), true );
check( 'A306 child process ran' . ( is_array( $a306 ) ? '' : ' – ' . substr( $a306_raw, 0, 300 ) ), is_array( $a306 ) );
$a306 = is_array( $a306 ) ? $a306 : array();
check( 'A306 es_ES without core pack: the mail is rendered with the plugin catalogue es_ES', ! empty( $a306['es_without_core_pack_uses_plugin_catalog'] ) && ! empty( $a306['bundled_mo_loaded'] ) );
check( 'A306 the site catalogue is restored afterwards (also after an exception)', ! empty( $a306['site_catalog_restored'] ) && ! empty( $a306['restored_after_exception'] ) );
check( 'A306 with a core pack the normal locale switch is used', ! empty( $a306['core_pack_switches_normally'] ) );
check( 'A306 without any catalogue the site language stays (no reload)', ! empty( $a306['no_catalog_keeps_site_language'] ) );
check( 'A306 catalogue lookup only for valid locales with a file', ! empty( $a306['catalog_lookup'] ) );
