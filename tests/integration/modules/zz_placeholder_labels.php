<?php
// phpcs:ignoreFile
/**
 * Placeholder names in help texts are translatable (TODO "Platzhalter in Hilfetexten übersetzbar machen"):
 * descriptive placeholder names such as {Termin-ID}, {field:Schlüssel}, <Bestell-ID>/<Token> and V{ID} are no
 * longer part of a longer msgid but come in through sprintf() as their own _x() label (with a translators
 * comment). The German output stays the same. Literal machine tokens ({year}, {event}, …) stay in the texts; every
 * msgid that contains one carries a translators comment naming each token ("Keep the placeholder(s) … unchanged.").
 */

use PassCaptain\Printing\Page as PlPrintPage;
use PassCaptain\Partners\AdminPage as PlPartnersPage;

echo "Placeholder labels: descriptive placeholder names are separate translatable labels\n";

// No gettext msgid embeds a descriptive placeholder name (capitalised or non-ASCII name in {…}/<…>).
$pl_bad = array();
$pl_it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $pl_it as $pl_file ) {
	if ( 'php' !== $pl_file->getExtension() ) { continue; }
	$pl_src = (string) file_get_contents( $pl_file->getPathname() );
	if ( preg_match_all( "/\\b(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $pl_src, $pl_m ) ) {
		foreach ( $pl_m[1] as $pl_msgid ) {
			if ( preg_match( '/\{(?:[a-z]+:)?(?:[A-ZÄÖÜ]|[^}]*[äöüßÄÖÜ])[^}]*\}|<(?:[A-ZÄÖÜ][\p{L}-]*)>/u', $pl_msgid ) ) {
				$pl_bad[] = str_replace( WPCAP_PATH, '', $pl_file->getPathname() ) . ': ' . mb_substr( $pl_msgid, 0, 60 );
			}
		}
	}
}
check( 'Placeholder labels: no msgid in src/ embeds a descriptive placeholder name' . ( $pl_bad ? ': ' . implode( ' | ', array_slice( $pl_bad, 0, 5 ) ) : '' ), ! $pl_bad );

// Every msgid with literal machine tokens ({year}, {event}, {field:key} …) has a translators comment right before
// the gettext call (same or previous line, no other gettext call in between) that names each token.
// The settings files are left to the settings rework (SET-PC); they are listed here until it adds the comments.
$pl_skip    = array( 'src/Settings.php', 'src/Admin/SettingsPage.php' );
$pl_fn      = '(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)';
$pl_tok_bad = array();
$pl_tok_all = 0;
$pl_files   = array( WPCAP_PATH . 'pass-captain.php' );
foreach ( array( 'src', 'templates' ) as $pl_dir ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . $pl_dir, FilesystemIterator::SKIP_DOTS ) ) as $pl_file ) {
		if ( 'php' === $pl_file->getExtension() ) { $pl_files[] = $pl_file->getPathname(); }
	}
}
foreach ( $pl_files as $pl_path ) {
	$pl_rel = str_replace( WPCAP_PATH, '', $pl_path );
	if ( in_array( $pl_rel, $pl_skip, true ) ) { continue; }
	$pl_src = (string) file_get_contents( $pl_path );
	if ( ! preg_match_all( "/\\b{$pl_fn}\\(\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")/u", $pl_src, $pl_m, PREG_OFFSET_CAPTURE ) ) { continue; }
	foreach ( $pl_m[0] as $pl_i => $pl_call ) {
		$pl_msgid = ( isset( $pl_m[1][ $pl_i ] ) && $pl_m[1][ $pl_i ][1] >= 0 && '' !== $pl_m[1][ $pl_i ][0] ) ? $pl_m[1][ $pl_i ][0] : (string) ( $pl_m[2][ $pl_i ][0] ?? '' );
		if ( ! preg_match_all( '/\{[a-z][a-z0-9_]*(?::[a-z0-9_]+)?\}/', $pl_msgid, $pl_t ) ) { continue; }
		++$pl_tok_all;
		$pl_before = substr( $pl_src, max( 0, $pl_call[1] - 600 ), min( 600, $pl_call[1] ) );
		$pl_has    = preg_match( "#/\\*\\s*translators:((?:[^*]|\\*(?!/))*)\\*/(?:(?!\\b{$pl_fn}\\()[^;{}])*$#s", $pl_before, $pl_c );
		$pl_lack   = array();
		foreach ( array_unique( $pl_t[0] ) as $pl_token ) {
			if ( ! $pl_has || false === strpos( $pl_c[1], $pl_token ) ) { $pl_lack[] = $pl_token; }
		}
		if ( $pl_lack ) {
			$pl_tok_bad[] = $pl_rel . ':' . ( substr_count( substr( $pl_src, 0, $pl_call[1] ), "\n" ) + 1 ) . ' ' . implode( ',', $pl_lack );
		}
	}
}
check( 'Placeholder labels: the scan finds msgids with literal tokens (' . $pl_tok_all . ')', $pl_tok_all >= 14 );
check( 'Placeholder labels: every msgid with literal tokens has a translators comment naming them' . ( $pl_tok_bad ? ': ' . implode( ' | ', array_slice( $pl_tok_bad, 0, 5 ) ) : '' ), ! $pl_tok_bad );

// German output unchanged: print page help texts.
$pl_get = $_GET;
$_GET   = array( 'page' => 'wpcap-print' );
ob_start();
try { PlPrintPage::render(); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
$pl_print = (string) ob_get_clean();
$_GET = $pl_get;
check( 'Placeholder labels: the print page still says "Teilnehmerfelder: {field:Schlüssel}, z. B. …"', false !== strpos( $pl_print, 'Teilnehmerfelder: {field:Schlüssel}, z. B. ' ) && false === strpos( $pl_print, 'ERR ' ) );
check( 'Placeholder labels: the invoice PDF help still names ?wpcap_invoice_pdf=<Bestell-ID>&wpcap_access=<Token> (escaped)', false !== strpos( $pl_print, '?wpcap_invoice_pdf=&lt;Bestell-ID&gt;&amp;wpcap_access=&lt;Token&gt;.' ) );

// Partner form: invoice prefix fallback V{ID}.
$pl_fields = new ReflectionMethod( PlPartnersPage::class, 'render_sales_model_fields' );
$pl_fields->setAccessible( true );
ob_start();
try { $pl_fields->invoke( null, null, static function () {} ); } catch ( \Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
$pl_partner = (string) ob_get_clean();
check( 'Placeholder labels: the partner form still says "Ohne eigenes Präfix wird V{ID} verwendet"', false !== strpos( $pl_partner, 'Ohne eigenes Präfix wird V{ID} verwendet (z. B. V7-2026-000001)' ) );
