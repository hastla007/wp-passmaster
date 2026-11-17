<?php
/**
 * Static check (A478): no byte-wise substr() on human-readable text in src/.
 *
 * Usage: php tests/utf8-substr-check.php [--self-test] [paths...]   (default: src/)
 *
 * A byte-wise substr() can cut a multi-byte UTF-8 character in half: wp_json_encode() then fails
 * (empty audit details), esc_html() prints nothing and a strict-mode database rejects the write.
 * Text is shortened with PassCaptain\Text::cut() (characters, never a broken character) instead.
 * Flagged is every substr( $x, 0, N ) whose first argument
 *  - contains a text source: sanitize_text_field(), sanitize_textarea_field(), __(), _x(),
 *    Redact::text(), get_error_message(), or
 *  - is one of the usual text variables ($reason, $note, $message, $summary, $error, $text, $label,
 *    $name, $title, $value, …), or
 *  - is cut to 100 or more bytes (a length for prose, not for a hash or code).
 * Exceptions only via WPCAP_SUBSTR_ALLOW below (ASCII-only values whose limit is in bytes).
 *
 * Exit code 1 on findings; --self-test checks the rule against built-in samples first.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

/** 'path relative to the plugin root' => array( 'substring of the first argument' => 'reason' ). */
const WPCAP_SUBSTR_ALLOW = array(
	'src/Mollie/MollieGateway.php' => array( '$idempotency_key' => 'HTTP header Idempotency-Key, ASCII key, limit in bytes' ),
	'src/Payments/StripeGateway.php' => array( '$idempotency_key' => 'HTTP header Idempotency-Key, ASCII key, limit in bytes' ),
	'src/Payments/PayPalGateway.php' => array( '$request_id' => 'HTTP header PayPal-Request-Id, ASCII key, limit in bytes' ),
	'src/Waitlists.php' => array( '$wpdb->prefix' => 'MySQL lock name (ASCII table prefix + id), limit in bytes' ),
);

/**
 * Calls substr( a, b, c ) in PHP source: [ [ line, args[] ], … ] (strings and comments skipped).
 *
 * @return array<int, array{0:int, 1:string[]}>
 */
function wpcap_substr_calls( $code ) {
	$tokens = token_get_all( $code );
	$calls = array();
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || T_STRING !== $token[0] || 'substr' !== strtolower( $token[1] ) ) { continue; }
		// Not a method/static call or a function declaration.
		for ( $p = $i - 1; $p >= 0 && is_array( $tokens[ $p ] ) && T_WHITESPACE === $tokens[ $p ][0]; $p-- ) {}
		if ( $p >= 0 && is_array( $tokens[ $p ] ) && in_array( $tokens[ $p ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) { continue; }
		for ( $n = $i + 1; $n < $count && is_array( $tokens[ $n ] ) && T_WHITESPACE === $tokens[ $n ][0]; $n++ ) {}
		if ( $n >= $count || '(' !== $tokens[ $n ] ) { continue; }
		$depth = 0;
		$args = array( '' );
		for ( $k = $n; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			if ( '(' === $text || '[' === $text ) { $depth++; if ( 1 === $depth && '(' === $text ) { continue; } }
			if ( ')' === $text || ']' === $text ) { $depth--; if ( 0 === $depth ) { break; } }
			if ( ',' === $text && 1 === $depth ) { $args[] = ''; continue; }
			$args[ count( $args ) - 1 ] .= $text;
		}
		$calls[] = array( (int) $token[2], array_map( 'trim', $args ) );
	}
	return $calls;
}

/** Whether a substr() call (its arguments) shortens text byte-wise. */
function wpcap_substr_is_text_cut( array $args ) {
	if ( 3 !== count( $args ) || '0' !== $args[1] || ! ctype_digit( $args[2] ) ) { return false; }
	$subject = $args[0];
	if ( preg_match( '/sanitize_text_field|sanitize_textarea_field|(?<![\w])__\(|(?<![\w])_x\(|Redact::text|get_error_message/', $subject ) ) { return true; }
	if ( preg_match( '/^\(?\s*(\(string\)\s*)?\$(reason|note|message|summary|error|errors|text|invalid|label|name|title|value|route|context|description|comment)\s*\)?$/', $subject ) ) { return true; }
	return (int) $args[2] >= 100;
}

/** Findings "file:line substr(…)" for the given PHP files. */
function wpcap_substr_findings( $root, array $files ) {
	$findings = array();
	foreach ( $files as $file ) {
		$rel = ltrim( str_replace( $root, '', $file ), '/' );
		foreach ( wpcap_substr_calls( (string) file_get_contents( $file ) ) as list( $line, $args ) ) {
			if ( ! wpcap_substr_is_text_cut( $args ) ) { continue; }
			$allowed = false;
			foreach ( WPCAP_SUBSTR_ALLOW[ $rel ] ?? array() as $needle => $reason ) { if ( false !== strpos( $args[0], $needle ) ) { $allowed = true; } }
			if ( ! $allowed ) { $findings[] = "$rel:$line substr( " . implode( ', ', $args ) . ' )'; }
		}
	}
	return $findings;
}

$root = dirname( __DIR__ );
$argv_rest = array_values( array_filter( array_slice( $argv, 1 ), static fn( $a ) => '--self-test' !== $a ) );
if ( in_array( '--self-test', $argv, true ) ) {
	$samples = array(
		'<?php substr( sanitize_text_field( $reason ), 0, 255 );' => true,
		'<?php substr( $note, 0, 255 );' => true,
		'<?php substr( (string) $message, 0, 40 );' => true,
		'<?php substr( __( "Text", "pass-captain" ), 0, 50 );' => true,
		'<?php substr( $row->comment_text, 0, 500 );' => true,
		'<?php substr( hash( "sha256", $x ), 0, 16 );' => false,
		'<?php substr( $public_id, 0, 12 );' => false,
		'<?php mb_substr( $reason, 0, 255 );' => false,
		'<?php $o->substr( $reason, 0, 255 );' => false,
		'<?php substr( $abs, -2 );' => false,
		'<?php \PassCaptain\Text::cut( $reason, 255 );' => false,
	);
	$bad = array();
	foreach ( $samples as $code => $expected ) {
		$flagged = false;
		foreach ( wpcap_substr_calls( $code ) as list( , $args ) ) { $flagged = $flagged || wpcap_substr_is_text_cut( $args ); }
		if ( $flagged !== $expected ) { $bad[] = $code; }
	}
	if ( $bad ) { echo "Self-test FAILED:\n  " . implode( "\n  ", $bad ) . "\n"; exit( 1 ); }
	echo "Self-test ok (" . count( $samples ) . " samples)\n";
}
$paths = $argv_rest ? $argv_rest : array( $root . '/src' );
$files = array();
foreach ( $paths as $path ) {
	if ( is_file( $path ) ) { $files[] = realpath( $path ); continue; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) { if ( '.php' === substr( $f->getFilename(), -4 ) ) { $files[] = $f->getPathname(); } }
}
sort( $files );
$findings = wpcap_substr_findings( $root, $files );
if ( $findings ) {
	echo "Byte-wise substr() on text (use PassCaptain\\Text::cut()):\n  " . implode( "\n  ", $findings ) . "\n";
	exit( 1 );
}
echo 'utf8-substr-check: ' . count( $files ) . " files, no byte-wise text truncation\n";
exit( 0 );
