<?php
/**
 * TODO "Übersetzungsverwaltung im Admin" (first part): the system status shows per bundled language how many
 * template strings are translated, fuzzy, missing or no longer used, and which file is loaded (language pack
 * before bundled file). Read-only. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admin\TranslationStatus;

echo "Translation status: coverage per bundled language\n";
$p3t_dir = sys_get_temp_dir() . '/wpcap-p3-l10n-' . uniqid();
mkdir( $p3t_dir );
$p3t_header = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\n";
file_put_contents( $p3t_dir . '/pass-captain.pot', $p3t_header . implode( "\n", array(
	"#: src/A.php:1\nmsgid \"Eins\"\nmsgstr \"\"\n",
	"msgctxt \"button\"\nmsgid \"Eins\"\nmsgstr \"\"\n",
	"msgid \"\"\n\"Zwei \"\n\"Zeilen\"\nmsgstr \"\"\n",
	"msgid \"%d Ticket\"\nmsgid_plural \"%d Tickets\"\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n",
	"msgid \"Fehlt\"\nmsgstr \"\"\n",
) ) );
file_put_contents( $p3t_dir . '/pass-captain-xx_XX.po', $p3t_header . implode( "\n", array(
	"msgid \"Eins\"\nmsgstr \"One\"\n",
	"#, fuzzy\nmsgctxt \"button\"\nmsgid \"Eins\"\nmsgstr \"One!\"\n",
	"msgid \"Zwei Zeilen\"\nmsgstr \"\"\n\"Two lines\"\n",
	"msgid \"%d Ticket\"\nmsgid_plural \"%d Tickets\"\nmsgstr[0] \"%d ticket\"\nmsgstr[1] \"\"\n",
	"msgid \"Alt\"\nmsgstr \"Old\"\n",
	"#~ msgid \"Ganz alt\"\n#~ msgstr \"Very old\"\n",
) ) );
file_put_contents( $p3t_dir . '/pass-captain-yy_YY.po', $p3t_header );
file_put_contents( $p3t_dir . '/pass-captain-xx_XX.mo', 'x' );

$p3t_pot = TranslationStatus::parse( $p3t_dir . '/pass-captain.pot' );
check( 'translation status: the parser reads entries without the header, with context as its own key and joined multi-line msgids', 5 === count( $p3t_pot ) && isset( $p3t_pot['Eins'], $p3t_pot["button\x04Eins"], $p3t_pot['Zwei Zeilen'], $p3t_pot['%d Ticket'] ) && ! isset( $p3t_pot[''] ) );
$p3t = TranslationStatus::coverage( $p3t_dir );
$p3t_x = $p3t['languages']['xx_XX'] ?? array();
check( 'translation status: translated, fuzzy, missing (incomplete plural counts as missing) and obsolete per language', 5 === $p3t['template'] && 2 === ( $p3t_x['translated'] ?? -1 ) && 1 === $p3t_x['fuzzy'] && 2 === $p3t_x['missing'] && 1 === $p3t_x['obsolete'] && 40.0 === (float) $p3t_x['percent'] );
check( 'translation status: an empty translation counts every template string as missing', 0 === $p3t['languages']['yy_YY']['translated'] && 5 === $p3t['languages']['yy_YY']['missing'] && array( 'xx_XX', 'yy_YY' ) === array_keys( $p3t['languages'] ) );
check( 'translation status: the loaded file is the bundled .mo, or none without one', 'bundled' === TranslationStatus::active_file( 'xx_XX', $p3t_dir )['source'] && 'none' === TranslationStatus::active_file( 'yy_YY', $p3t_dir )['source'] && 'none' === TranslationStatus::active_file( '../xx_XX', $p3t_dir . '/sub' )['source'] );
file_put_contents( $p3t_dir . '/pass-captain-yy_YY.po', $p3t_header . "msgid \"Fehlt\"\nmsgstr \"Missing\"\n" );
clearstatcache();
$p3t2 = TranslationStatus::coverage( $p3t_dir );
check( 'translation status: a changed file is counted again (cache keyed by size and modification time)', 1 === $p3t2['languages']['yy_YY']['translated'] );

// The real bundled languages.
$p3t_real = TranslationStatus::coverage();
$p3t_po = array_map( static fn( $f ) => substr( basename( $f, '.po' ), strlen( 'pass-captain-' ) ), glob( WPCAP_PATH . 'languages/pass-captain-*.po' ) );
sort( $p3t_po );
check( 'translation status: every bundled .po appears, the template has many strings', $p3t_po === array_keys( $p3t_real['languages'] ) && $p3t_real['template'] > 1000 );
$p3t_ok = true;
foreach ( $p3t_real['languages'] as $p3t_row ) {
	$p3t_ok = $p3t_ok && $p3t_row['translated'] + $p3t_row['fuzzy'] + $p3t_row['missing'] === $p3t_row['total'] && $p3t_row['percent'] >= 0 && $p3t_row['percent'] <= 100;
}
check( 'translation status: per language translated + fuzzy + missing = template strings', $p3t_ok );
ob_start();
TranslationStatus::render();
$p3t_html = (string) ob_get_clean();
check( 'translation status: the section lists one row per bundled language with the loaded file', 'wpcap-translations' === ( preg_match( '/<h2 id="([^"]+)"/', $p3t_html, $m ) ? $m[1] : '' ) && count( $p3t_po ) === preg_match_all( '/<tr data-locale="/', $p3t_html ) && false !== strpos( $p3t_html, 'data-locale="fr_FR"' ) );
check( 'translation status: the system status overview renders the section', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/StatusPage.php' ), 'TranslationStatus::render();' ) );
array_map( 'unlink', glob( $p3t_dir . '/*' ) );
rmdir( $p3t_dir );
