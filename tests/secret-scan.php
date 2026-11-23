<?php
/**
 * A195: secret scan for the repository (all git-tracked files) and, optionally, the release ZIP.
 *
 *   php tests/secret-scan.php                          # tracked files of the working tree
 *   php tests/secret-scan.php --zip=build/dist/x.zip   # additionally every file inside the ZIP
 *
 * Fails (exit 1) on live-looking credentials: provider API keys, private keys, tokens, passwords in
 * URLs. Test fixtures that need key-shaped strings build them from parts ('sk_' . 'live_…') or use
 * obvious dummies; anything else that is intentional goes into ALLOW with a reason.
 */

$root = dirname( __DIR__ );

$patterns = array(
	'Stripe live secret key'   => '/\b(?:sk|rk)_live_[0-9a-zA-Z]{16,}/',
	'Stripe webhook secret'    => '/\bwhsec_[0-9a-zA-Z]{24,}/',
	'PayPal/Braintree token'   => '/\baccess_token\$production\$[0-9a-z]{16}\$[0-9a-f]{32}/',
	'Mollie live key'          => '/\blive_[A-Za-z0-9]{30}\b/',
	'AWS access key'           => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
	'GitHub token'             => '/\b(?:ghp|gho|ghs|ghu|ghr)_[0-9A-Za-z]{36}\b|\bgithub_pat_[0-9A-Za-z_]{60,}/',
	'Slack token'              => '/\bxox[abposr]-[0-9A-Za-z-]{10,}/',
	'Google API key'           => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
	'Twilio API key'           => '/\bSK[0-9a-f]{32}\b/',
	'SendGrid key'             => '/\bSG\.[0-9A-Za-z_\-]{22}\.[0-9A-Za-z_\-]{43}\b/',
	// Header plus a real base64 body (dummy fixtures with a short placeholder body do not match).
	'Private key block'        => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----(?:\\\\n|\\s)*[A-Za-z0-9+\\/=]{64,}/',
	'Password in URL'          => '#\b[a-z][a-z0-9+.-]*://[^/\s:@\'"]+:(?!\$|%|\{|<|password\b|pass\b|secret\b|xxx|\*)[^/\s:@\'"]{6,}@[a-z0-9.-]+\.[a-z]{2,}#i',
	'JWT'                      => '/\beyJ[0-9A-Za-z_\-]{10,}\.eyJ[0-9A-Za-z_\-]{10,}\.[0-9A-Za-z_\-]{20,}/',
);

// Files that may contain key-shaped strings on purpose (path regex => reason).
$allow = array(
	'#^vendor/#'            => 'third-party code (checked by composer audit)',
	'#^node_modules/#'      => 'third-party code (checked by npm audit)',
	'#^languages/.*\.mo$#'  => 'compiled translations',
	'#\.(png|jpe?g|gif|ico|woff2?|ttf|pdf|zip|mo)$#i' => 'binary',
);

$args = getopt( '', array( 'zip::' ) );

$files = array();
exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files -z', $out, $rc );
if ( 0 !== $rc ) {
	fwrite( STDERR, "git ls-files failed\n" );
	exit( 2 );
}
foreach ( array_filter( explode( "\0", implode( "\n", $out ) ) ) as $rel ) {
	$files[ $rel ] = static function () use ( $root, $rel ) { return is_file( $root . '/' . $rel ) ? (string) file_get_contents( $root . '/' . $rel ) : ''; };
}
if ( ! empty( $args['zip'] ) ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $args['zip'] ) ) {
		fwrite( STDERR, "cannot open {$args['zip']}\n" );
		exit( 2 );
	}
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( '/' === substr( $name, -1 ) ) { continue; }
		$files[ 'zip:' . $name ] = static function () use ( $zip, $name ) { return (string) $zip->getFromName( $name ); };
	}
}

$hits    = 0;
$scanned = 0;
foreach ( $files as $rel => $read ) {
	$path = preg_replace( '#^zip:[^/]+/#', '', $rel );
	$skip = false;
	foreach ( $allow as $re => $reason ) {
		// Third-party code inside the ZIP is scanned too: a bundled dependency must not carry secrets either.
		if ( preg_match( $re, $path ) && ! ( 0 === strpos( $rel, 'zip:' ) && 0 === strpos( $path, 'vendor/' ) ) ) { $skip = true; break; }
	}
	if ( $skip ) { continue; }
	$text = $read();
	if ( '' === $text || false !== strpos( substr( $text, 0, 8000 ), "\0" ) ) { continue; }
	$scanned++;
	foreach ( $patterns as $label => $re ) {
		if ( preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $match ) {
				$line = substr_count( substr( $text, 0, $match[1] ), "\n" ) + 1;
				$shown = substr( $match[0], 0, 8 ) . '…';   // never print the full secret
				echo "FAIL {$label}: {$rel}:{$line} ({$shown})\n";
				$hits++;
			}
		}
	}
}
echo $hits ? "\n{$hits} possible secret(s) in {$scanned} files.\n" : "ok   no secrets found in {$scanned} files\n";
exit( $hits ? 1 : 0 );
