<?php
// phpcs:ignoreFile
/**
 * A371: the error style for [aria-invalid] only applies inside the plugin's own forms – no global
 * selector and no !important that would restyle invalid fields of other forms on the page.
 */

echo "A371: scoped aria-invalid style\n";
$a371_css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( WPCAP_PATH . 'assets/public.css' ) );
preg_match_all( '/([^{}]+)\{([^}]*)\}/', $a371_css, $a371_rules, PREG_SET_ORDER );
$a371_global = array();
$a371_scoped = 0;
foreach ( $a371_rules as $a371_rule ) {
	foreach ( explode( ',', $a371_rule[1] ) as $a371_selector ) {
		$a371_selector = trim( $a371_selector );
		if ( false === strpos( $a371_selector, 'aria-invalid' ) ) { continue; }
		if ( 0 === strpos( $a371_selector, '[aria-invalid' ) || false !== strpos( $a371_rule[2], '!important' ) ) { $a371_global[] = $a371_selector; }
		if ( preg_match( '/^\.wpcap-(events|service-booking|scanner) \[aria-invalid="true"\]$/', $a371_selector ) ) { ++$a371_scoped; }
	}
}
check( 'A371: no global or !important [aria-invalid] rule in public.css' . ( $a371_global ? ' – ' . implode( ', ', $a371_global ) : '' ), ! $a371_global );
check( 'A371: the error style covers the cards, the service booking and the scanner', 3 === $a371_scoped );
