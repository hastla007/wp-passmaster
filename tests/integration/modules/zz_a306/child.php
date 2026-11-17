<?php
/**
 * Worker for tests/integration/modules/zz_a306.php: the locale functions are stubbed here
 * (switch_to_locale() refuses locales without a core language pack, like WordPress does).
 */
// phpcs:ignoreFile
require dirname( __DIR__, 2 ) . '/bootstrap.php';

use PassCaptain\Mail\Mailer;

$GLOBALS['a306_core_packs'] = array( 'de_DE', 'en_US' );
$GLOBALS['a306_locale'] = 'de_DE';
$GLOBALS['a306_domain'] = 'de_DE'; // Locale the plugin's text domain is loaded in.
$GLOBALS['a306_calls'] = array();
function get_locale() { return 'de_DE'; }
function switch_to_locale( $l ) { if ( ! in_array( $l, $GLOBALS['a306_core_packs'], true ) ) { return false; } $GLOBALS['a306_stack'][] = $GLOBALS['a306_locale']; $GLOBALS['a306_locale'] = $l; $GLOBALS['a306_domain'] = $l; return true; }
function restore_previous_locale() { $GLOBALS['a306_locale'] = array_pop( $GLOBALS['a306_stack'] ); $GLOBALS['a306_domain'] = $GLOBALS['a306_locale']; return true; }
function unload_textdomain( $d, $reloadable = false ) { $GLOBALS['a306_calls'][] = "unload:$d"; $GLOBALS['a306_domain'] = ''; return true; }
function load_textdomain( $d, $file, $locale = null ) { $GLOBALS['a306_calls'][] = "load:$d:" . basename( $file ) . ":$locale"; $GLOBALS['a306_domain'] = $locale; return is_readable( $file ); }
function load_plugin_textdomain( $d, $deprecated = false, $rel = '' ) { $GLOBALS['a306_calls'][] = "load_plugin:$d:$rel"; $GLOBALS['a306_domain'] = $GLOBALS['a306_locale']; return true; }
function plugin_basename( $f ) { return 'pass-captain/' . basename( $f ); }

$out = array();
$seen = Mailer::with_locale( 'es_ES', static function () { return array( $GLOBALS['a306_locale'], $GLOBALS['a306_domain'] ); } );
$out['es_without_core_pack_uses_plugin_catalog'] = array( 'de_DE', 'es_ES' ) === $seen;
$out['bundled_mo_loaded'] = in_array( 'load:pass-captain:pass-captain-es_ES.mo:es_ES', $GLOBALS['a306_calls'], true );
$out['site_catalog_restored'] = 'de_DE' === $GLOBALS['a306_domain'] && 'load_plugin:pass-captain:pass-captain/languages' === end( $GLOBALS['a306_calls'] );

$GLOBALS['a306_calls'] = array();
$seen = Mailer::with_locale( 'en_US', static function () { return array( $GLOBALS['a306_locale'], $GLOBALS['a306_domain'] ); } );
$out['core_pack_switches_normally'] = array( 'en_US', 'en_US' ) === $seen && ! $GLOBALS['a306_calls'] && 'de_DE' === $GLOBALS['a306_locale'];

$GLOBALS['a306_calls'] = array();
$seen = Mailer::with_locale( 'pt_BR', static function () { return $GLOBALS['a306_domain']; } );
$out['no_catalog_keeps_site_language'] = 'de_DE' === $seen && ! $GLOBALS['a306_calls'];

$GLOBALS['a306_calls'] = array();
try { Mailer::with_locale( 'fr_FR', static function () { throw new RuntimeException( 'mail failed' ); } ); } catch ( RuntimeException $e ) {}
$out['restored_after_exception'] = 'de_DE' === $GLOBALS['a306_domain'];
$out['catalog_lookup'] = '' !== Mailer::plugin_catalog( 'it_IT' ) && '' === Mailer::plugin_catalog( 'xx_XX' ) && '' === Mailer::plugin_catalog( '../../etc' );
echo json_encode( $out );
