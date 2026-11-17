<?php
// phpcs:ignoreFile
/**
 * A383: counted strings use real singular/plural forms (_n(), or a "…One" variant for strings whose
 * count is only known in the browser) instead of "Ticket(s)", "Bestellung(en)"; the ticket status is
 * one sentence ("Status: %s") instead of a label glued to the value.
 */

use PassCaptain\Admin\Forms;

echo "A383: plurals and composed sentences\n";
$a383_bad = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $a383_file ) {
	if ( '.php' !== substr( (string) $a383_file, -4 ) ) { continue; }
	$a383_src = (string) file_get_contents( (string) $a383_file );
	// Any literal of a gettext call that carries a count placeholder and a "(e)/(s)/(en)/(n)" plural marker.
	if ( preg_match_all( '/(?:__|_x|esc_html__|esc_attr__|_n|_nx)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $a383_src, $a383_m ) ) {
		foreach ( $a383_m[1] as $a383_text ) {
			if ( preg_match( '/%(?:\d\$)?d/', $a383_text ) && preg_match( '/[a-zäöüß]\((?:e|s|en|n)\)/u', $a383_text ) ) { $a383_bad[] = basename( (string) $a383_file ) . ': ' . substr( $a383_text, 0, 60 ); }
		}
	}
}
check( 'A383: no counted gettext string with a "(e)/(s)/(en)" plural marker is left' . ( $a383_bad ? ' – ' . implode( ' | ', $a383_bad ) : '' ), ! $a383_bad );

$a383_events = (string) file_get_contents( WPCAP_PATH . 'src/PublicEvents.php' );
$a383_scanner = (string) file_get_contents( WPCAP_PATH . 'assets/scanner.js' );
check( 'A383: the scanner gets singular variants and picks them for a count of one', false !== strpos( $a383_events, "'syncCountOne' =>" ) && false !== strpos( $a383_events, "'syncedOne' =>" ) && false !== strpos( $a383_scanner, '(1 === count && t.syncCountOne)' ) && false !== strpos( $a383_scanner, '(1 === sent && t.syncedOne)' ) && false !== strpos( $a383_scanner, 'src.expiredOne' ) );
check( 'A383: both expiry messages of the scanner have a singular variant', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admission/Rest.php' ), "'expiredOne' =>" ) && false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Privacy.php' ), "'expiredOne' =>" ) );
check( 'A383: the refund form uses its singular sum for one ticket', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' ), '1===n&&D.sumOne?D.sumOne:D.sum' ) );

check( 'A383: Forms::status_line() is one sentence with the translated status', 'Status: Gültig' === Forms::status_line( 'valid' ) && 'Status: Gültig (erstattet)' === Forms::status_line( 'valid', true ) );
check( 'A383: ticket page and order portal no longer glue "Status:" to the label', false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/TicketPage.php' ), "__( 'Status:', 'pass-captain' ) . ' '" ) && false === strpos( (string) file_get_contents( WPCAP_PATH . 'src/OrderPortal.php' ), "__( 'Status:', 'pass-captain' ) . ' '" ) );
