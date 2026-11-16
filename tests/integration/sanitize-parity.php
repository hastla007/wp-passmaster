<?php
/**
 * A498: the sanitisers of the test runtime (tests/integration/wp-sanitize.php) must behave exactly like
 * WordPress. Runs a fuzz corpus (type confusion, overlong values, invalid UTF-8, 4-byte characters, lone
 * "<", entities, percent octets, e-mail edge cases) through both – each in its own PHP process, the real
 * functions from a WordPress download – and compares the results.
 *
 * Usage: php tests/integration/sanitize-parity.php [--require]
 *   WordPress from WPCAP_TEST_WP_DIR or tests/upgrade/.wordpress (tests/upgrade/run.php downloads it).
 *   Without a download the check is skipped (exit 0), with --require it fails (exit 2).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

/** The corpus: every value is passed to every function. */
function wpcap_parity_corpus() {
	$long_local = str_repeat( 'a', 70 );
	return array(
		'', ' ', 'Erika Mustermann', '  Erika   Muster  ', "Zeile 1\nZeile 2\r\n\tTab", 'Kids <3 Party', 'Preis < 10 €', 'a<b', 'a <b>fett</b> c',
		'<script>alert(1)</script>Name', '<style>x{}</style>y', "<\nb>", 'A&B "Q" \'s', 'Kids &lt;3 &amp; Co', '&#61;1+1', '50%25 Rabatt %zz %41',
		'Café – Grüße 😀 مرحبا', "Name\u{200B}mit ZWSP", "Null\0Byte", "Caf\xC3", "Ok \xF0\x9F\x98", "\xFF\xFE", str_repeat( 'Ä', 300 ),
		'a@example.com', ' a@example.com ', 'A.B+tag@Example.COM', 'a@b', 'a@b.c', 'ab@c', '@example.com', 'a@@example.com', 'a@example..com',
		'a@.example.com', 'a@example.com.', 'a@-example.com', 'a@exa_mple.com', 'a b@example.com', 'ä@example.com', 'a@exämple.com',
		"a@example.com\n", 'x<y>@example.com', $long_local . '@example.com', str_repeat( 'a', 60 ) . '@' . str_repeat( 'b', 60 ) . '.' . str_repeat( 'c', 60 ) . '.com',
		'Mixed_Key-1', 'KEY with spaces', 'schlüssel',
		123, 0, -5, 1.5, true, false, null, array(), array( 'a@example.com' ), array( 'x' => 'y' ),
	);
}

/** Results of every function for every corpus value (Throwable → its class name). */
function wpcap_parity_run() {
	$functions = array(
		'sanitize_text_field' => static fn( $v ) => sanitize_text_field( $v ),
		'sanitize_textarea_field' => static fn( $v ) => sanitize_textarea_field( $v ),
		'sanitize_key' => static fn( $v ) => sanitize_key( $v ),
		'sanitize_email' => static fn( $v ) => sanitize_email( $v ),
		'is_email' => static fn( $v ) => is_email( $v ),
		'wp_strip_all_tags' => static fn( $v ) => wp_strip_all_tags( $v ),
		'wp_check_invalid_utf8' => static fn( $v ) => wp_check_invalid_utf8( $v ),
		'wp_check_invalid_utf8(strip)' => static fn( $v ) => wp_check_invalid_utf8( $v, true ),
	);
	$out = array();
	set_error_handler( static function () { return true; } ); // Real wp_strip_all_tags() warns for arrays; the result counts.
	foreach ( $functions as $name => $fn ) {
		foreach ( wpcap_parity_corpus() as $i => $value ) {
			try {
				$result = $fn( $value );
				$out[ $name ][ $i ] = array( 'type' => gettype( $result ), 'value' => is_string( $result ) ? base64_encode( $result ) : $result );
			} catch ( \Throwable $e ) {
				$out[ $name ][ $i ] = array( 'type' => 'throw', 'value' => get_class( $e ) );
			}
		}
	}
	restore_error_handler();
	return $out;
}

$child = null;
foreach ( $argv as $arg ) { if ( 0 === strpos( $arg, '--child=' ) ) { $child = substr( $arg, 8 ); } }

if ( 'stub' === $child ) {
	function apply_filters( $tag, $value ) { return $value; }
	require __DIR__ . '/wp-sanitize.php';
	echo json_encode( wpcap_parity_run() );
	exit( 0 );
}
if ( 'real' === $child ) {
	$wp = (string) getenv( 'WPCAP_PARITY_WP_DIR' );
	define( 'ABSPATH', rtrim( $wp, '/' ) . '/' );
	define( 'WPINC', 'wp-includes' );
	function apply_filters( $tag, $value ) { return $value; }
	function _deprecated_argument() {}
	function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) { trigger_error( $message, E_USER_WARNING ); }
	function __( $text ) { return $text; }
	function get_option( $name, $default = false ) { return 'blog_charset' === $name ? 'UTF-8' : $default; }
	function is_utf8_charset( $charset = null ) { return true; }
	function _canonical_charset( $charset ) { return 'UTF-8'; }
	function _wp_can_use_pcre_u( $set = null ) { return true; }
	foreach ( array( 'compat-utf8.php', 'utf8.php', 'kses.php', 'formatting.php' ) as $file ) {
		if ( is_readable( ABSPATH . WPINC . '/' . $file ) ) { require_once ABSPATH . WPINC . '/' . $file; }
	}
	echo json_encode( wpcap_parity_run() );
	exit( 0 );
}

$root = dirname( __DIR__, 2 );
$wp_dir = getenv( 'WPCAP_TEST_WP_DIR' ) ?: $root . '/tests/upgrade/.wordpress';
if ( ! is_readable( $wp_dir . '/wp-includes/formatting.php' ) ) {
	echo "Sanitizer parity: no WordPress download at $wp_dir – skipped (run tests/upgrade/run.php first or set WPCAP_TEST_WP_DIR)\n";
	exit( in_array( '--require', $argv, true ) ? 2 : 0 );
}
include $wp_dir . '/wp-includes/version.php';
$php = escapeshellarg( PHP_BINARY );
$self = escapeshellarg( __FILE__ );
$stub = json_decode( (string) shell_exec( "$php $self --child=stub" ), true );
$real = json_decode( (string) shell_exec( 'WPCAP_PARITY_WP_DIR=' . escapeshellarg( $wp_dir ) . " $php $self --child=real" ), true );
echo 'Sanitizer parity with WordPress ' . ( $wp_version ?? '?' ) . "\n";
if ( ! is_array( $stub ) || ! is_array( $real ) ) {
	echo "  FAIL a child process did not answer (stub: " . ( is_array( $stub ) ? 'ok' : 'no' ) . ', real: ' . ( is_array( $real ) ? 'ok' : 'no' ) . ")\n";
	exit( 1 );
}
$corpus = wpcap_parity_corpus();
$failed = 0;
foreach ( $real as $name => $results ) {
	$diff = array();
	foreach ( $results as $i => $expected ) {
		$got = $stub[ $name ][ $i ] ?? null;
		if ( $got !== $expected ) {
			$show = static fn( $r ) => null === $r ? 'missing' : ( 'string' === $r['type'] ? json_encode( base64_decode( $r['value'] ), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE ) : $r['type'] . ':' . json_encode( $r['value'] ) );
			$diff[] = json_encode( $corpus[ $i ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE ) . ' → WordPress ' . $show( $expected ) . ', test runtime ' . $show( $got );
		}
	}
	if ( $diff ) {
		++$failed;
		echo "  FAIL $name\n       " . implode( "\n       ", array_slice( $diff, 0, 8 ) ) . "\n";
	} else {
		echo "  ok   $name (" . count( $results ) . " values)\n";
	}
}
echo $failed ? "\n$failed functions differ\n" : "\nall equal\n";
exit( $failed ? 1 : 0 );
