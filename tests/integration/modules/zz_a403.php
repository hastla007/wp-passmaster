<?php
/**
 * A403: money is formatted by Money::number()/format() (currency minor unit, site number format), never
 * with a fixed "/ 100, 2" plus a currency code; the box office scripts get the minor unit from the server.
 *
 * @package PassCaptain
 */
// phpcs:ignoreFile

echo "A403: one money formatter\n";
$a403_hits = array();
$a403_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $a403_it as $a403_file ) {
	if ( 'php' !== $a403_file->getExtension() ) { continue; }
	foreach ( file( $a403_file->getPathname() ) as $a403_n => $a403_line ) {
		// "number_format_i18n( $x / 100, 2 ) . ' ' . $currency": a hard-coded minor unit next to a currency code.
		if ( preg_match( "/number_format(?:_i18n)?\\([^;]*\\/ *100, *2 *\\) *\\. *' ' *\\. *\\$[a-z_>\\-]*currency/i", $a403_line ) ) {
			$a403_hits[] = str_replace( WPCAP_PATH, '', $a403_file->getPathname() ) . ':' . ( $a403_n + 1 );
		}
	}
}
check( 'A403: no hard-coded 2-decimal money formatting with a currency code' . ( $a403_hits ? ' – ' . implode( ', ', $a403_hits ) : '' ), ! $a403_hits );
$a403_pos = (string) file_get_contents( WPCAP_PATH . 'src/BoxOffice/Page.php' );
check( 'A403: the box office script gets the currency minor unit and number format', false !== strpos( $a403_pos, "'decimals' => \\PassCaptain\\Money::decimals( \$session->currency )" ) && false !== strpos( $a403_pos, "'decimalPoint'" ) );
check( 'A403: the denomination count gets the minor unit', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/BoxOffice/ShiftPage.php' ), 'data-decimals="' ) );
