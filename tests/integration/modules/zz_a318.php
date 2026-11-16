<?php
/**
 * A318: dark themes. Every front-end rule that sets a text colour also sets its background (and
 * vice versa), so text never relies on the theme's page colour; each literal pair has at least
 * 4.5:1. Exceptions: rules inside a container that sets both (listed below) and state variants
 * that only swap the background of a rule with white text. The dark set (.wpcap-dark /
 * .is-dark-theme) is checked the same way. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "A318: colour pairs for dark themes\n";

if ( ! function_exists( 'a318_luminance' ) ) {
	function a318_luminance( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
		$c = array_map( static function ( $i ) use ( $hex ) {
			$v = hexdec( substr( $hex, $i, 2 ) ) / 255;
			return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		}, array( 0, 2, 4 ) );
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}
	function a318_contrast( $a, $b ) {
		$x = a318_luminance( $a ); $y = a318_luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}
	/** Rules of a stylesheet as [selector, [property => value]] (media blocks flattened). */
	function a318_rules( $css ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '#@media[^{]*\{((?:[^{}]*\{[^{}]*\})*)\s*\}#', '$1', $css );
		preg_match_all( '#([^{}]+)\{([^{}]*)\}#', $css, $m, PREG_SET_ORDER );
		$rules = array();
		foreach ( $m as $rule ) {
			$decls = array();
			foreach ( explode( ';', $rule[2] ) as $decl ) {
				if ( false === strpos( $decl, ':' ) ) { continue; }
				list( $prop, $value ) = array_map( 'trim', explode( ':', $decl, 2 ) );
				$decls[ strtolower( $prop ) ] = trim( str_replace( '!important', '', $value ) );
			}
			$rules[] = array( trim( $rule[1] ), $decls );
		}
		return $rules;
	}
}

$a318_files = array( 'assets/public.css', 'assets/calendar/calendar.css' );
// Containers that set text and background themselves: descendants may set only one of them.
$a318_paired_containers = array( '.wpcap-bank', '.wpcap-error-summary', '.wpcap-confirmation', '.wpcap-cal-has-events', '.wpcap-scan-overlay', '.wpcap-scan-mode', '.wpcap-overlay-hint', '.wpcap-step' );
$a318_problems = array();
$a318_low = array();
foreach ( $a318_files as $a318_file ) {
	$a318_rules = a318_rules( (string) file_get_contents( WPCAP_PATH . $a318_file ) );
	// Custom properties: light set from the plain definitions, dark set from the .wpcap-dark rules.
	$a318_vars = array( 'light' => array(), 'dark' => array() );
	foreach ( $a318_rules as $a318_rule ) {
		foreach ( $a318_rule[1] as $a318_prop => $a318_value ) {
			if ( 0 === strpos( $a318_prop, '--' ) ) { $a318_vars[ false !== strpos( $a318_rule[0], 'wpcap-dark' ) ? 'dark' : 'light' ][ $a318_prop ] = $a318_value; }
		}
	}
	$a318_vars['dark'] += $a318_vars['light'];
	foreach ( $a318_rules as $a318_rule ) {
		list( $a318_selectors, $a318_decls ) = $a318_rule;
		$a318_color = $a318_decls['color'] ?? null;
		$a318_bg = $a318_decls['background'] ?? ( $a318_decls['background-color'] ?? null );
		if ( null !== $a318_color && in_array( strtolower( $a318_color ), array( 'inherit', 'currentcolor', 'transparent' ), true ) ) { $a318_color = null; }
		if ( null !== $a318_bg && ( in_array( strtolower( $a318_bg ), array( 'none', 'transparent', 'inherit' ), true ) || 0 === strpos( $a318_bg, 'rgba(' ) ) ) { $a318_bg = null; }
		if ( null === $a318_color && null === $a318_bg ) { continue; }
		foreach ( array_map( 'trim', explode( ',', $a318_selectors ) ) as $a318_selector ) {
			if ( null !== $a318_color && null !== $a318_bg ) { continue; }
			$a318_inside = false;
			foreach ( $a318_paired_containers as $a318_container ) {
				if ( 0 === strpos( $a318_selector, $a318_container . ' ' ) || 0 === strpos( $a318_selector, $a318_container . '>' ) || 0 === strpos( $a318_selector, $a318_container . '[' ) || 0 === strpos( $a318_selector, '.wpcap-calendar ' . $a318_container . ' ' ) ) { $a318_inside = true; }
			}
			if ( ! $a318_inside ) { $a318_problems[] = "$a318_file: $a318_selector sets only " . ( null !== $a318_color ? 'color' : 'background' ); }
		}
		if ( null === $a318_color || null === $a318_bg ) { continue; }
		foreach ( array( 'light', 'dark' ) as $a318_set ) {
			$a318_resolve = static function ( $value ) use ( $a318_vars, $a318_set ) {
				if ( preg_match( '#^var\((--[\w-]+)#', $value, $vm ) ) { $value = $a318_vars[ $a318_set ][ $vm[1] ] ?? ''; }
				return preg_match( '#^\#[0-9a-f]{3,6}$#i', $value ) ? $value : null;
			};
			$a318_fg = $a318_resolve( $a318_color );
			$a318_back = $a318_resolve( preg_replace( '#\s.*$#', '', $a318_bg ) );
			if ( $a318_fg && $a318_back && a318_contrast( $a318_fg, $a318_back ) < 4.5 ) { $a318_low[] = sprintf( '%s: %s (%s) %s on %s = %.2f', $a318_file, $a318_selectors, $a318_set, $a318_fg, $a318_back, a318_contrast( $a318_fg, $a318_back ) ); }
		}
	}
}
check( 'A318 every rule with a text colour sets its background and vice versa' . ( $a318_problems ? ': ' . implode( '; ', $a318_problems ) : '' ), ! $a318_problems );
check( 'A318 every text/background pair (light and dark set) has at least 4.5:1' . ( $a318_low ? ': ' . implode( '; ', $a318_low ) : '' ), ! $a318_low );

$a318_cal = (string) file_get_contents( WPCAP_PATH . 'assets/calendar/calendar.css' );
$a318_pub = (string) file_get_contents( WPCAP_PATH . 'assets/public.css' );
check( 'A318 dark set: color-scheme:dark via .wpcap-dark or .is-dark-theme (calendar and checkout)', false !== strpos( $a318_cal, '.wpcap-dark .wpcap-calendar' ) && false !== strpos( $a318_cal, '.is-dark-theme .wpcap-calendar' ) && false !== strpos( $a318_pub, '.wpcap-dark .wpcap-event' ) && 2 <= substr_count( $a318_cal . $a318_pub, 'color-scheme:dark' ) );
check( 'A318 calendar focus ring has a halo (visible on light and dark pages)', false !== strpos( $a318_cal, 'box-shadow:0 0 0 2px var(--wpcap-cal-focus-halo)' ) );
