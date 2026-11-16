<?php
/**
 * WordPress' input sanitisers for the test runtime (A498), ported from wp-includes/formatting.php and
 * kses.php (WordPress 7.1, GPL-2.0-or-later) instead of simplified stubs, so the integration tests see
 * what real WordPress stores: a lone "<" becomes "&lt;", invalid UTF-8 becomes "", percent-encoded
 * octets and line breaks are removed, sanitize_email()/is_email() follow WordPress' own rules (no
 * length limit!) and throw a TypeError for arrays like on PHP 8.
 *
 * tests/integration/sanitize-parity.php compares every function here with the real WordPress
 * implementation on a fuzz corpus (CI, after the WordPress download of tests/upgrade).
 *
 * Needs: apply_filters(), esc_html()-free (the kses callback encodes like WordPress' esc_html()).
 */

// phpcs:ignoreFile

/** WordPress' esc_html(): ENT_QUOTES, existing entities are not encoded twice, invalid UTF-8 → ''. */
function wpcap_wp_esc_html( $text ) {
	$text = wp_check_invalid_utf8( (string) $text );
	if ( '' === $text || ! preg_match( '/[&<>"\']/', $text ) ) { return $text; }
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
}

function wp_check_invalid_utf8( $text, $strip = false ) {
	$text = (string) $text;
	if ( 0 === strlen( $text ) ) { return ''; }
	if ( mb_check_encoding( $text, 'UTF-8' ) ) { return $text; }
	if ( ! $strip ) { return ''; }
	$previous = mb_substitute_character();
	mb_substitute_character( 0xFFFD );
	$text = mb_scrub( $text, 'UTF-8' );
	mb_substitute_character( $previous );
	return $text;
}

function wp_pre_kses_less_than( $content ) {
	return preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', 'wp_pre_kses_less_than_callback', $content );
}

function wp_pre_kses_less_than_callback( $matches ) {
	if ( ! str_contains( $matches[0], '>' ) ) { return wpcap_wp_esc_html( $matches[0] ); }
	return $matches[0];
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	if ( is_null( $text ) || ! is_scalar( $text ) ) { return ''; }
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) { $text = preg_replace( '/[\r\n\t ]+/', ' ', $text ); }
	return trim( $text );
}

function _sanitize_text_fields( $str, $keep_newlines = false ) {
	if ( is_object( $str ) || is_array( $str ) ) { return ''; }
	$filtered = wp_check_invalid_utf8( (string) $str );
	if ( str_contains( $filtered, '<' ) ) {
		$filtered = wp_pre_kses_less_than( $filtered );
		$filtered = wp_strip_all_tags( $filtered, false );
		$filtered = str_replace( "<\n", "&lt;\n", $filtered );
	}
	if ( ! $keep_newlines ) { $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered ); }
	$filtered = trim( $filtered );
	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found = true;
	}
	if ( $found ) { $filtered = trim( preg_replace( '/ +/', ' ', $filtered ) ); }
	return $filtered;
}

function sanitize_text_field( $str ) {
	return apply_filters( 'sanitize_text_field', _sanitize_text_fields( $str, false ), $str );
}

function sanitize_textarea_field( $str ) {
	return apply_filters( 'sanitize_textarea_field', _sanitize_text_fields( $str, true ), $str );
}

function sanitize_key( $key ) {
	$sanitized_key = '';
	if ( is_scalar( $key ) ) {
		$sanitized_key = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	return apply_filters( 'sanitize_key', $sanitized_key, $key );
}

function sanitize_email( $email ) {
	if ( strlen( $email ) < 6 ) { return apply_filters( 'sanitize_email', '', $email, 'email_too_short' ); }
	if ( false === strpos( $email, '@', 1 ) ) { return apply_filters( 'sanitize_email', '', $email, 'email_no_at' ); }
	list( $local, $domain ) = explode( '@', $email, 2 );
	$local = preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]/', '', $local );
	if ( '' === $local ) { return apply_filters( 'sanitize_email', '', $email, 'local_invalid_chars' ); }
	$domain = preg_replace( '/\.{2,}/', '', $domain );
	if ( '' === $domain ) { return apply_filters( 'sanitize_email', '', $email, 'domain_period_sequence' ); }
	$domain = trim( $domain, " \t\n\r\0\x0B." );
	if ( '' === $domain ) { return apply_filters( 'sanitize_email', '', $email, 'domain_period_limits' ); }
	$subs = explode( '.', $domain );
	if ( 2 > count( $subs ) ) { return apply_filters( 'sanitize_email', '', $email, 'domain_no_periods' ); }
	$new_subs = array();
	foreach ( $subs as $sub ) {
		$sub = trim( $sub, " \t\n\r\0\x0B-" );
		$sub = preg_replace( '/[^a-z0-9-]+/i', '', $sub );
		if ( '' !== $sub ) { $new_subs[] = $sub; }
	}
	if ( 2 > count( $new_subs ) ) { return apply_filters( 'sanitize_email', '', $email, 'domain_no_valid_subs' ); }
	$sanitized_email = $local . '@' . implode( '.', $new_subs );
	return apply_filters( 'sanitize_email', $sanitized_email, $email, null );
}

function is_email( $email, $deprecated = false ) {
	if ( strlen( $email ) < 6 ) { return apply_filters( 'is_email', false, $email, 'email_too_short' ); }
	if ( false === strpos( $email, '@', 1 ) ) { return apply_filters( 'is_email', false, $email, 'email_no_at' ); }
	list( $local, $domain ) = explode( '@', $email, 2 );
	if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) ) { return apply_filters( 'is_email', false, $email, 'local_invalid_chars' ); }
	if ( preg_match( '/\.{2,}/', $domain ) ) { return apply_filters( 'is_email', false, $email, 'domain_period_sequence' ); }
	if ( trim( $domain, " \t\n\r\0\x0B." ) !== $domain ) { return apply_filters( 'is_email', false, $email, 'domain_period_limits' ); }
	$subs = explode( '.', $domain );
	if ( 2 > count( $subs ) ) { return apply_filters( 'is_email', false, $email, 'domain_no_periods' ); }
	foreach ( $subs as $sub ) {
		if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub ) { return apply_filters( 'is_email', false, $email, 'sub_hyphen_limits' ); }
		if ( ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) { return apply_filters( 'is_email', false, $email, 'sub_invalid_chars' ); }
	}
	return apply_filters( 'is_email', $email, $email, null );
}
