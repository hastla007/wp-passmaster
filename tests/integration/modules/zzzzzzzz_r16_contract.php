<?php
/**
 * R16-02: an add-on can tell from the plugin version and the contract version which extension points exist.
 * The version table in docs/EXTENSIONS.md lists every versioned hook of the code (and no hook that does
 * not exist), the constants in the main file are consistent, and the extension points the premium add-on
 * relies on are present. The core itself never asks whether an add-on is active.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "R16: extension contract version and documented extension points\n";

$r16_root = dirname( __DIR__, 3 );
$r16_main = (string) file_get_contents( $r16_root . '/pass-captain.php' );
$r16_docs = (string) file_get_contents( $r16_root . '/docs/EXTENSIONS.md' );

preg_match( "/^define\( 'WPCAP_VERSION', '([^']+)' \);/m", $r16_main, $r16_v );
preg_match( "/^define\( 'WPCAP_CONTRACT_VERSION', '([^']+)' \);/m", $r16_main, $r16_c );
$r16_version  = $r16_v[1] ?? '';
$r16_contract = $r16_c[1] ?? '';
check( 'R16: main file defines WPCAP_VERSION >= 1.5.0', '' !== $r16_version && version_compare( $r16_version, '1.5.0', '>=' ) );
check( 'R16: main file defines WPCAP_CONTRACT_VERSION as x.y.z', 1 === preg_match( '/^\d+\.\d+\.\d+$/', $r16_contract ) );
check( 'R16: docs/EXTENSIONS.md lists the current contract version with the plugin version', 1 === preg_match( '/^\| ' . preg_quote( $r16_contract, '/' ) . ' \| ' . preg_quote( $r16_version, '/' ) . ' \|/m', $r16_docs ) );

// One version everywhere: header = constant = readme Stable tag = newest CHANGELOG entry (also build/check-versions.sh).
preg_match( '/^ \* Version:\s*(\S+)/m', $r16_main, $r16_h );
preg_match( '/^Stable tag:\s*(\S+)/m', (string) file_get_contents( $r16_root . '/readme.txt' ), $r16_s );
preg_match( '/^## (\d+\.\d+\.\d+)\b/m', (string) file_get_contents( $r16_root . '/CHANGELOG.md' ), $r16_l );
check( 'R16: plugin header, WPCAP_VERSION, readme Stable tag and newest CHANGELOG entry agree (' . $r16_version . ')', '' !== $r16_version && array( $r16_version, $r16_version, $r16_version ) === array( $r16_h[1] ?? '', $r16_s[1] ?? '', $r16_l[1] ?? '' ) );
check( 'R16: docs/EXTENSIONS.md writes down the SemVer rule of the contract version', false !== strpos( $r16_docs, 'WPCAP_CONTRACT_VERSION' ) && false !== strpos( $r16_docs, 'unverträglich' ) );
// No "since/seit x.y.z" in code or hook docs ahead of the plugin version.
$r16_ahead = array();
$r16_scan  = array( $r16_root . '/docs/HOOKS.md' => 0, $r16_root . '/docs/EXTENSIONS.md' => 0 );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $r16_root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $r16_file ) {
	if ( 'php' === $r16_file->getExtension() ) { $r16_scan[ $r16_file->getPathname() ] = 0; }
}
foreach ( array_keys( $r16_scan ) as $r16_path ) {
	if ( preg_match_all( '/\b(?:since|seit)\s+(\d+\.\d+\.\d+)\b/i', (string) file_get_contents( $r16_path ), $r16_m ) ) {
		foreach ( $r16_m[1] as $r16_since ) {
			if ( version_compare( $r16_since, $r16_version, '>' ) ) { $r16_ahead[] = basename( $r16_path ) . ' ' . $r16_since; }
		}
	}
}
check( 'R16: no "since x.y.z" in src/ or the hook docs is ahead of the plugin version' . ( $r16_ahead ? ': ' . implode( ', ', $r16_ahead ) : '' ), array() === $r16_ahead );

// Versioned hooks in the shipped code.
$r16_code = array();
$r16_it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $r16_root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $r16_it as $r16_file ) {
	if ( 'php' !== $r16_file->getExtension() ) { continue; }
	if ( preg_match_all( '/wp_pass_captain_[a-z_]+_v\d+/', (string) file_get_contents( $r16_file->getPathname() ), $r16_m ) ) {
		foreach ( $r16_m[0] as $r16_name ) { $r16_code[ $r16_name ] = true; }
	}
}
// Rows of the version table: | `hook` | x.y.z |
preg_match_all( '/^\| `(wp_pass_captain_[a-z_]+_v\d+)` \| (\d+\.\d+\.\d+) \|$/m', $r16_docs, $r16_rows, PREG_SET_ORDER );
$r16_table = array();
foreach ( $r16_rows as $r16_row ) { $r16_table[ $r16_row[1] ] = $r16_row[2]; }
$r16_undocumented = array_values( array_diff( array_keys( $r16_code ), array_keys( $r16_table ) ) );
$r16_stale        = array_values( array_diff( array_keys( $r16_table ), array_keys( $r16_code ) ) );
check( 'R16: every versioned hook of the code has a "since" row in docs/EXTENSIONS.md (' . count( $r16_code ) . ' hooks)' . ( $r16_undocumented ? ': ' . implode( ', ', $r16_undocumented ) : '' ), count( $r16_code ) >= 40 && array() === $r16_undocumented );
check( 'R16: the version table names no hook that does not exist' . ( $r16_stale ? ': ' . implode( ', ', $r16_stale ) : '' ), array() === $r16_stale );
$r16_future = array_filter( $r16_table, static function ( $since ) use ( $r16_version ) { return version_compare( $since, $r16_version, '>' ); } );
check( 'R16: no "since" version is newer than the plugin version', array() === $r16_future );

// Extension points the premium add-on (captain-pro, addons/pass-captain-pro) docks to.
$r16_needed = array( 'admin_menu_built', 'brand_name', 'brand_logo', 'admin_menu_title', 'admin_menu_icon', 'invoice_created', 'invoice_links', 'invoice_mail_attachments', 'api_scopes', 'checkout_allowed', 'pos_payment_devices' );
$r16_missing = array();
foreach ( $r16_needed as $r16_key ) {
	$r16_name = 'wp_pass_captain_' . $r16_key . '_v1';
	if ( empty( $r16_code[ $r16_name ] ) || '1.5.0' !== ( $r16_table[ $r16_name ] ?? '' ) ) { $r16_missing[] = $r16_name; }
}
check( 'R16: the eleven add-on hooks exist and are documented as "since 1.5.0"' . ( $r16_missing ? ': ' . implode( ', ', $r16_missing ) : '' ), array() === $r16_missing );
check( 'R16: public classes for add-ons exist', class_exists( \PassCaptain\Api\Auth::class ) && class_exists( \PassCaptain\Api\Invoices::class ) && interface_exists( \PassCaptain\BoxOffice\DeviceGatewayInterface::class ) );
check( 'R16: public methods for add-ons exist', is_callable( array( \PassCaptain\Orders::class, 'request_fingerprint' ) ) && is_callable( array( \PassCaptain\Access::class, 'can_access_occurrence' ) ) && is_callable( array( \PassCaptain\BoxOffice\Service::class, 'confirm_device_payment' ) ) );

// Rule: generic hooks only. The core never checks for a particular add-on.
$r16_knows_addon = array();
$r16_it          = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $r16_root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $r16_it as $r16_file ) {
	if ( 'php' !== $r16_file->getExtension() ) { continue; }
	$r16_src = (string) file_get_contents( $r16_file->getPathname() );
	if ( preg_match( '/pass[-_]captain[-_]pro\b|PassCaptainPro|PASS_CAPTAIN_PRO\b|captain-pro\b/i', $r16_src ) ) { $r16_knows_addon[] = substr( $r16_file->getPathname(), strlen( $r16_root ) + 1 ); }
}
if ( preg_match( '/pass[-_]captain[-_]pro\b|PassCaptainPro|PASS_CAPTAIN_PRO\b/i', $r16_main ) ) { $r16_knows_addon[] = 'pass-captain.php'; }
check( 'R16: no file of the core refers to the premium add-on by name' . ( $r16_knows_addon ? ': ' . implode( ', ', $r16_knows_addon ) : '' ), array() === $r16_knows_addon );
