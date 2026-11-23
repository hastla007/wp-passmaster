<?php
/**
 * Release metadata consistency (CI): plugin header, WPCAP_VERSION, readme.txt, composer.json and the
 * changelogs must agree. Run: php tests/consistency.php (exit code 1 on a mismatch).
 */
$root = dirname( __DIR__ );
$main = (string) file_get_contents( $root . '/pass-captain.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );
$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$header = static function ( $text, $name ) { return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : ''; };
$failed = 0;
$check = static function ( $name, $ok ) use ( &$failed ) { echo ( $ok ? '  ok   ' : '  FAIL ' ) . $name . "\n"; if ( ! $ok ) { $failed++; } };

$version = $header( $main, 'Version' );
preg_match( "/define\(\s*'WPCAP_VERSION',\s*'([^']+)'\s*\)/", $main, $const );
$check( "plugin header Version ($version) = WPCAP_VERSION (" . ( $const[1] ?? '?' ) . ')', '' !== $version && $version === ( $const[1] ?? '' ) );
$check( 'readme Stable tag = plugin version', $header( $readme, 'Stable tag' ) === $version );
$check( 'Requires at least: header = readme', '' !== $header( $main, 'Requires at least' ) && $header( $main, 'Requires at least' ) === $header( $readme, 'Requires at least' ) );
$check( 'Requires PHP: header = readme', '' !== $header( $main, 'Requires PHP' ) && $header( $main, 'Requires PHP' ) === $header( $readme, 'Requires PHP' ) );
$check( 'composer require php matches Requires PHP', '>=' . $header( $main, 'Requires PHP' ) === ( $composer['require']['php'] ?? '' ) );
$check( 'composer platform php matches Requires PHP', 0 === strpos( (string) ( $composer['config']['platform']['php'] ?? '' ), $header( $main, 'Requires PHP' ) ) );
$check( "CHANGELOG.md has a section for $version", (bool) preg_match( '/^## ' . preg_quote( $version, '/' ) . '\b/m', $changelog ) );
$check( "readme.txt changelog has an entry for $version", false !== strpos( $readme, '= ' . $version . ' =' ) );
$check( 'Text Domain is pass-captain', 'pass-captain' === $header( $main, 'Text Domain' ) );
// Every released version in CHANGELOG.md also appears in the readme changelog.
preg_match_all( '/^## (\d+\.\d+\.\d+)/m', $changelog, $released );
$missing = array_filter( $released[1], static fn( $v ) => version_compare( $v, '1.1.0', '>=' ) && false === strpos( $readme, '= ' . $v . ' =' ) );
$check( 'readme changelog lists every release since 1.1.0' . ( $missing ? ' – missing ' . implode( ', ', $missing ) : '' ), ! $missing );
// A389: every readme changelog entry carries the main changes itself (at least three bullets), not just a
// pointer to CHANGELOG.md; the 1.5.0 entry names the rename.
$readme_log = preg_match( '/^== Changelog ==\n(.*?)(?=^== |\z)/ms', $readme, $lm ) ? $lm[1] : '';
$thin = array();
foreach ( preg_split( '/^(?== \d+\.\d+\.\d+ =)/m', $readme_log ) as $entry ) {
	if ( preg_match( '/^= (\d+\.\d+\.\d+) =/', $entry, $em ) && preg_match_all( '/^\* /m', $entry ) < 3 ) { $thin[] = $em[1]; }
}
$check( 'A389 readme changelog entries list their main changes (>= 3 bullets)' . ( $thin ? ' - too short: ' . implode( ', ', $thin ) : '' ), '' !== $readme_log && ! $thin );
$check( 'A389 readme changelog names the rename to "Pass Captain"', false !== strpos( $readme_log, 'Renamed to "Pass Captain"' ) );
// A653: GitHub Actions pinned to a full commit SHA (with the version as comment, Dependabot updates both),
// explicit top-level permissions, checkouts without persisted credentials (release: documented exception for
// its `git fetch`), Composer without install scripts and production installs without plugins.
$wf_problems = array();
foreach ( (array) glob( $root . '/.github/workflows/*.yml' ) as $wf_file ) {
	$wf = (string) file_get_contents( $wf_file );
	$wf_name = basename( $wf_file );
	if ( ! preg_match( '/^permissions:/m', $wf ) ) { $wf_problems[] = "$wf_name: no top-level permissions"; }
	preg_match_all( '/uses:\s*(\S+)([^\n]*)/', $wf, $wf_uses, PREG_SET_ORDER );
	foreach ( $wf_uses as $u ) {
		if ( str_starts_with( $u[1], './' ) ) { continue; }
		if ( ! preg_match( '/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/', $u[1] ) || ! preg_match( '/^\s*#\s*v?\d+(\.\d+)*/', $u[2] ) ) { $wf_problems[] = "$wf_name: not pinned by SHA with version comment: {$u[1]}"; }
	}
	foreach ( preg_split( '/\n(?=\s*- )/', $wf ) as $step ) {
		if ( ! preg_match( '/uses:\s*actions\/checkout@/', $step ) ) { continue; }
		$persist = preg_match( '/persist-credentials:\s*(\w+)/', $step, $pm ) ? $pm[1] : '';
		if ( 'false' !== $persist && ! ( 'release.yml' === $wf_name && 'true' === $persist ) ) { $wf_problems[] = "$wf_name: checkout without persist-credentials: false"; }
	}
	preg_match_all( '/composer install[^\n]*/', $wf, $wf_ci );
	foreach ( $wf_ci[0] as $c ) {
		if ( false === strpos( $c, '--no-scripts' ) || ( false !== strpos( $c, '--no-dev' ) && false === strpos( $c, '--no-plugins' ) ) ) { $wf_problems[] = "$wf_name: $c"; }
	}
}
foreach ( array( 'build/release.sh', 'build/ci-install-test.sh' ) as $wf_script ) {
	if ( ! preg_match( '/composer install [^\n]*--no-dev[^\n]*--no-scripts --no-plugins/', (string) file_get_contents( $root . '/' . $wf_script ) ) ) { $wf_problems[] = "$wf_script: production composer install without --no-scripts --no-plugins"; }
}
$check( 'A653 workflows: actions pinned by SHA, permissions, persist-credentials, composer flags' . ( $wf_problems ? ' - ' . implode( '; ', $wf_problems ) : '' ), ! $wf_problems );
// Documentation links in README.md point to existing files.
preg_match_all( '/\]\((docs\/[^)#]+)\)/', (string) file_get_contents( $root . '/README.md' ), $links );
$broken = array_filter( array_unique( $links[1] ), static fn( $l ) => ! file_exists( $root . '/' . $l ) );
$check( 'README.md doc links resolve' . ( $broken ? ' – ' . implode( ', ', $broken ) : '' ), ! $broken );
// A269: readme.txt documents every external service; every remote host in src/ is named there.
$services = (string) ( preg_match( '/^== External services ==\n(.*?)^== (?!External)/ms', $readme, $m ) ? $m[1] : '' );
$check( 'readme.txt has an "== External services ==" section', '' !== $services );
$local_hosts = array( 'developer.wordpress.org', 'example.com', 'github.com', 'www.googleapis.com', 'schema.org' ); // Doc links, samples, OAuth scope identifier, JSON-LD context (never contacted).
// A666: only code that ships in the ZIP (.distignore) counts – excluded code is not advertised either.
require __DIR__ . '/shipped-files.php';
$shipped = wpcap_shipped_files( $root );
$hosts = array();
foreach ( $shipped as $file ) {
	if ( 0 !== strpos( $file, 'src/' ) || '.php' !== substr( $file, -4 ) ) { continue; }
	preg_match_all( '#https://([a-z0-9.-]+\.[a-z]{2,})#', (string) file_get_contents( $root . '/' . $file ), $found );
	$hosts = array_merge( $hosts, $found[1] );
}
$undocumented = array_filter( array_unique( $hosts ), static fn( $h ) => ! in_array( $h, $local_hosts, true ) && false === strpos( $services, $h ) );
$check( 'every remote host in src/ is listed under External services' . ( $undocumented ? ' – missing ' . implode( ', ', $undocumented ) : '' ), ! $undocumented );
foreach ( array( 'Stripe', 'PayPal', 'Mollie', 'Twilio', 'SMS-HTTP', 'Apple', 'Google Wallet', 'Webhooks', 'Affiliate Captain', 'Voucher Captain' ) as $service ) {
	$check( "External services names $service", false !== stripos( $services, $service ) );
}
// A666: the fiscalization adapters are excluded from the ZIP until the fiscalization core (PR #14) is
// merged; readme.txt names fiskaltrust/efsta exactly when their adapters ship.
$fiscal_shipped = (bool) array_filter( $shipped, static fn( $f ) => 0 === strpos( $f, 'src/Fiscalization/' ) );
foreach ( array( 'fiskaltrust', 'efsta' ) as $service ) {
	$check( "readme.txt names $service only while its adapter ships (" . ( $fiscal_shipped ? 'shipped' : 'not shipped' ) . ')', $fiscal_shipped === ( false !== stripos( $readme, $service ) ) );
}
$check( 'fiscalization adapters are only shipped together with their interface', ! $fiscal_shipped || in_array( 'src/Fiscalization/AdapterInterface.php', $shipped, true ) );
$check( 'every External services entry links terms/privacy or names the operator-chosen recipient', ! array_filter( preg_split( '/^= /m', $services ), static fn( $part ) => '' !== trim( $part ) && 0 !== strpos( ltrim( $part ), 'Pass Captain' ) && 0 !== strpos( ltrim( $part ), 'Keine externen' ) && false === strpos( $part, 'https://' ) && false === strpos( $part, 'Betreiber' ) && false === stripos( $part, 'chosen by the administrator' ) && false === stripos( $part, 'the administrator configures' ) ) );
// A662: docs/FREE-PREMIUM.md lists every module of the module registry (src/Modules/*.php, see
// Modules::classes()) as "Kern, dauerhaft frei" and names every directory and class file under src/.
$free = (string) @file_get_contents( $root . '/docs/FREE-PREMIUM.md' );
$module_ids = array_map( static fn( $f ) => basename( $f, '.php' ), (array) glob( $root . '/src/Modules/*.php' ) );
$unlisted = array_filter( $module_ids, static fn( $id ) => ! preg_match( '/^\|\s*`' . preg_quote( $id, '/' ) . '`\s*\|.*\|\s*Kern, dauerhaft frei\s*\|\s*$/m', $free ) );
$check( 'docs/FREE-PREMIUM.md lists every module as "Kern, dauerhaft frei"' . ( $unlisted ? ' – missing ' . implode( ', ', $unlisted ) : '' ), $module_ids && ! $unlisted );
$listed_ids = preg_match_all( '/^\|\s*`([A-Za-z]+)`\s*\|/m', (string) strstr( (string) strstr( $free, '## Inventar: Module' ), '## Inventar: Kernbereiche', true ), $rows ) ? $rows[1] : array();
$stale = array_diff( $listed_ids, $module_ids );
$check( 'docs/FREE-PREMIUM.md module table has no removed modules' . ( $stale ? ' – ' . implode( ', ', $stale ) : '' ), ! $stale );
$src_entries = array_merge( array_map( static fn( $d ) => 'src/' . basename( $d ) . '/', (array) glob( $root . '/src/*', GLOB_ONLYDIR ) ), array_map( static fn( $f ) => 'src/' . basename( $f ), (array) glob( $root . '/src/*.php' ) ) );
$unnamed = array_filter( $src_entries, static fn( $e ) => false === strpos( $free, '`' . $e . '`' ) );
$check( 'docs/FREE-PREMIUM.md names every directory and class file under src/' . ( $unnamed ? ' – missing ' . implode( ', ', $unnamed ) : '' ), ! $unnamed );
// A663: the public add-on API (PassCaptain\PublicApi::HOOKS) matches the code and docs/HOOKS.md: every
// public hook is fired in src/ (do_action/apply_filters with its literal name, or the literal name used
// by the call site), is a row of the public table, and the table lists no hook outside the registry.
$api_src = (string) @file_get_contents( $root . '/src/PublicApi.php' );
$api_hooks = array();
if ( preg_match( "/const HOOKS = array\((.*?)\n\t\);/s", $api_src, $am ) && preg_match_all( "/^\s*'([a-z0-9_]+)'\s*=>\s*array\(\s*'(action|filter)'\s*,\s*'(\d+\.\d+)'/m", $am[1], $hm, PREG_SET_ORDER ) ) {
	foreach ( $hm as $row ) { $api_hooks[ $row[1] ] = $row[2]; }
}
$check( 'PublicApi::HOOKS lists the public add-on hooks', count( $api_hooks ) >= 20 );
$src_code = '';
foreach ( $shipped as $file ) {
	if ( 0 === strpos( $file, 'src/' ) && '.php' === substr( $file, -4 ) && 'src/PublicApi.php' !== $file ) { $src_code .= (string) file_get_contents( $root . '/' . $file ) . "\n"; }
}
$src_code .= preg_replace( '/const HOOKS = array\(.*?\n\t\);/s', '', $api_src ); // wpcap_loaded is fired in PublicApi::loaded().
$not_fired = array_filter( array_keys( $api_hooks ), static function ( $hook ) use ( $src_code, $api_hooks ) {
	$call = 'action' === $api_hooks[ $hook ] ? 'do_action' : 'apply_filters';
	return ! preg_match( '/' . $call . "\(\s*'" . preg_quote( $hook, '/' ) . "'/", $src_code ) && ! preg_match( "/'" . preg_quote( $hook, '/' ) . "'/", $src_code );
} );
$check( 'every public add-on hook is used in src/' . ( $not_fired ? ' – missing ' . implode( ', ', $not_fired ) : '' ), $api_hooks && ! $not_fired );
$hooks_doc = (string) @file_get_contents( $root . '/docs/HOOKS.md' );
$public_section = (string) strstr( (string) strstr( $hooks_doc, "\n## Öffentliche Add-on-API" ), "\n## Actions", true );
preg_match_all( '/^\|\s*`([a-z0-9_]+)`\s*\|\s*(Action|Filter)\s*\|/m', $public_section, $dm, PREG_SET_ORDER );
$doc_hooks = array();
foreach ( $dm as $row ) { $doc_hooks[ $row[1] ] = strtolower( $row[2] ); }
$undocumented_api = array_diff_key( $api_hooks, $doc_hooks );
$unregistered_api = array_diff_key( $doc_hooks, $api_hooks );
$wrong_type = array_keys( array_filter( $api_hooks, static fn( $type, $hook ) => isset( $doc_hooks[ $hook ] ) && $doc_hooks[ $hook ] !== $type, ARRAY_FILTER_USE_BOTH ) );
$check( 'docs/HOOKS.md public table lists every public add-on hook' . ( $undocumented_api ? ' – missing ' . implode( ', ', array_keys( $undocumented_api ) ) : '' ), '' !== $public_section && ! $undocumented_api );
$check( 'docs/HOOKS.md public table lists no hook outside PublicApi::HOOKS' . ( $unregistered_api ? ' – ' . implode( ', ', array_keys( $unregistered_api ) ) : '' ), ! $unregistered_api );
$check( 'docs/HOOKS.md public table names the right type (Action/Filter)' . ( $wrong_type ? ' – ' . implode( ', ', $wrong_type ) : '' ), ! $wrong_type );
preg_match( "/const VERSION = '([0-9.]+)'/", $api_src, $vm );
$check( 'docs/HOOKS.md and docs/CONTRACTS.md name the add-on API version ' . ( $vm[1] ?? '?' ), isset( $vm[1] ) && false !== strpos( $public_section, '**' . $vm[1] . '**' ) && false !== strpos( (string) @file_get_contents( $root . '/docs/CONTRACTS.md' ), '| `addon` | ' . $vm[1] ) );
// A270: tweetnacl ships with its readable source and a source reference that is not Markdown.
$nacl = $root . '/assets/vendor/tweetnacl/';
$nacl_source = (string) @file_get_contents( $nacl . 'SOURCE.txt' );
$check( 'tweetnacl: nacl-fast.js (source) ships next to nacl-fast.min.js', is_file( $nacl . 'nacl-fast.js' ) && is_file( $nacl . 'nacl-fast.min.js' ) );
$check( 'tweetnacl: SOURCE.txt names version, URL, license and the checksums of both files', false !== strpos( $nacl_source, '1.0.3' ) && false !== strpos( $nacl_source, 'https://github.com/dchest/tweetnacl-js' ) && false !== strpos( $nacl_source, 'Unlicense' ) && is_file( $nacl . 'nacl-fast.js' ) && false !== strpos( $nacl_source, hash_file( 'sha256', $nacl . 'nacl-fast.js' ) ) && false !== strpos( $nacl_source, hash_file( 'sha256', $nacl . 'nacl-fast.min.js' ) ) );
$check( 'readme.txt points to the tweetnacl source reference', false !== strpos( $readme, 'assets/vendor/tweetnacl/SOURCE.txt' ) );
// A658: the shipped jsQR build is pinned by checksum.
$jsqr = $root . '/assets/vendor/jsqr/';
$jsqr_source = (string) @file_get_contents( $jsqr . 'SOURCE.txt' );
$check( 'jsQR: SOURCE.txt names origin, license and the checksum of jsQR.js', is_file( $jsqr . 'jsQR.js' ) && false !== strpos( $jsqr_source, 'https://github.com/cozmo/jsQR' ) && false !== strpos( $jsqr_source, 'Apache' ) && false !== strpos( $jsqr_source, hash_file( 'sha256', $jsqr . 'jsQR.js' ) ) );
// A454: every block editor script has an asset file whose version is the content hash of the
// script (cache busting: a changed editor.js gets a new ?ver=) and whose dependencies cover every
// window.wp.* package the script uses. Expected hash: first 20 hex digits of md5 (LF line ends).
foreach ( (array) glob( $root . '/src/blocks/*/editor.js' ) as $editor_js ) {
	$block_dir = basename( dirname( $editor_js ) );
	$asset_src = (string) @file_get_contents( dirname( $editor_js ) . '/editor.asset.php' );
	$expected = substr( md5( str_replace( "\r\n", "\n", (string) file_get_contents( $editor_js ) ) ), 0, 20 );
	$asset_version = preg_match( "/'version'\s*=>\s*'([^']*)'/", $asset_src, $vm ) ? $vm[1] : '';
	$check( "src/blocks/$block_dir/editor.asset.php version = content hash of editor.js (expected '$expected')", $expected === $asset_version );
	$deps = preg_match( "/'dependencies'\s*=>\s*array\(([^)]*)\)/", $asset_src, $dm ) && preg_match_all( "/'([^']+)'/", $dm[1], $dl ) ? $dl[1] : array();
	preg_match_all( '/window\.wp\.([A-Za-z][A-Za-z0-9]*)/', (string) file_get_contents( $editor_js ), $used );
	$needed = array_unique( array_map( static fn( $pkg ) => 'wp-' . strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $pkg ) ), $used[1] ) );
	$missing_deps = array_diff( $needed, $deps );
	$check( "src/blocks/$block_dir/editor.asset.php lists every wp.* package of editor.js" . ( $missing_deps ? ' – missing ' . implode( ', ', $missing_deps ) : '' ), $needed && ! $missing_deps );
}
// A367: every shipped PHP file of the plugin (not vendor/) stops when loaded outside WordPress: an
// ABSPATH guard (uninstall.php: WP_UNINSTALL_PLUGIN) before the first declaration or statement.
$unguarded = array();
foreach ( $shipped as $file ) {
	if ( '.php' !== substr( $file, -4 ) || 0 === strpos( $file, 'vendor/' ) ) { continue; }
	$tokens = token_get_all( (string) file_get_contents( $root . '/' . $file ) );
	$guarded = false;
	$code = '';
	foreach ( $tokens as $i => $token ) {
		$id = is_array( $token ) ? $token[0] : null;
		if ( in_array( $id, array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { $code .= '' === $code ? '' : ' '; continue; }
		$code .= is_array( $token ) ? $token[1] : $token;
		// namespace/use/declare lines may come first; anything else must be the guard.
		if ( ';' === $token || '}' === $token ) {
			if ( preg_match( '/defined\(\s*([\'"])(ABSPATH|WP_UNINSTALL_PLUGIN)\1\s*\)/', $code ) && preg_match( '/\b(exit|die)\b/', $code ) ) { $guarded = true; break; }
			if ( ! preg_match( '/^\s*(namespace|use|declare)\b/', $code ) ) { break; }
			$code = '';
		}
	}
	if ( ! $guarded ) { $unguarded[] = $file; }
}
$check( 'every shipped PHP file has an ABSPATH guard before its first statement' . ( $unguarded ? ' – missing in ' . implode( ', ', $unguarded ) : '' ), ! $unguarded );
echo $failed ? "$failed failed\n" : "all passed\n";
exit( $failed ? 1 : 0 );
