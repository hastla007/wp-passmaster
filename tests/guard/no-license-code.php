<?php
/**
 * Guard test (TODO A2): fails as soon as licence or self-update code appears in the core plugin.
 *
 * Decision of 13.11.2026: Pass Captain stays completely free and without licence checks so it can be hosted on
 * WordPress.org (guideline 5: no trialware or locked features; guideline 8: no updates from third-party servers).
 * Licence client, entitlement checks and the updater belong into the separate premium add-on.
 *
 * Scans every file that ends up in the installable ZIP: the plugin root minus .distignore (same rules as
 * build/release.sh, rsync --exclude-from semantics). Third-party code in vendor/ is not scanned line by line;
 * instead composer.json must not require licence or updater packages.
 *
 * Usage:
 *   php tests/guard/no-license-code.php              Scan the working tree (plugin root).
 *   php tests/guard/no-license-code.php <dir>        Scan another plugin root, e.g. an unpacked release ZIP.
 *   php tests/guard/no-license-code.php --self-test  Check that every rule detects a planted sample.
 *
 * Exit code 0 = clean, 1 = licence code found, 2 = usage/IO error.
 *
 * @package PassCaptain
 */

// CLI only; never part of the plugin package (/tests/ is in .distignore).
if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

/**
 * Forbidden patterns: rule id => [PCRE pattern, explanation]. Patterns are matched case-insensitively per line.
 */
function wpcap_guard_rules() {
	return array(
		'license-client'   => array( '/\bLicen[cs]e_?Client\b/i', 'Licence client class/call (belongs into the premium add-on)' ),
		'wplc-route'       => array( '#\bwplc/v\d+#i', 'REST route of the licence server WP License Captain (wplc/v1)' ),
		'wplc-prefix'      => array( '/\bwplc_[a-z0-9_]+/i', 'Function/option/hook with the licence server prefix wplc_' ),
		'wp-license-domain' => array( '/[\'"]wp-license[\'"]/i', 'Text domain of the licence client (wp-license)' ),
		'update-uri'       => array( '/^\s*(?:\*|#|\/\/)?\s*Update URI\s*:/im', 'Plugin header "Update URI" (updates from a non-WordPress.org server)' ),
		'update-transient' => array( '/\b(?:pre_set_)?site_transient_update_plugins\b/i', 'Own hook on the plugin update transient' ),
		'plugins-api'      => array( '/[\'"]plugins_api(?:_result|_args)?[\'"]/i', 'Own plugins_api filter (custom update/plugin info source)' ),
		'update-hostname'  => array( '/[\'"]update_plugins_[a-z0-9.\-]+[\'"]/i', 'update_plugins_{hostname} filter (custom update server)' ),
		'upgrader-download' => array( '/\bupgrader_pre_download\b/i', 'Own download hook of the upgrader (custom update package)' ),
		'entitlement'      => array( '/\b(?:allows_entitlement|has_entitlement|entitlements?)\b/i', 'Entitlement/rights check (feature gating by licence)' ),
		'license-option'   => array( '/\b(?:wpcap_|wp_pass_captain_|pass_captain_)?licen[cs]e_(?:key|status|data|activation|activations|server|expires?|token|cache|check|valid)\b/i', 'Licence key/status option or field' ),
		'license-action'   => array( '/\b(?:activate|deactivate|check|validate|verify)_licen[cs]e\b/i', 'Licence activation/check routine' ),
		'core-license-prefix' => array( '/\b(?:wpcap|wp_pass_captain|pass_captain)_licen[cs]e/i', 'Licence option/hook with the plugin prefix' ),
		'updater-sdk'      => array( '/\b(?:Puc_v\d|PucFactory|plugin-update-checker|EDD_SL_Plugin_Updater|edd_sl_|fs_dynamic_init|Freemius)\b/i', 'Third-party licence/updater SDK' ),
	);
}

/** Composer packages that must not be required by the core plugin. */
function wpcap_guard_forbidden_package( $name ) {
	return 1 === preg_match( '/licen[cs]e|update-?checker|updater|wplc|freemius|edd-sl|software-licensing/i', (string) $name );
}

/** File extensions whose contents are scanned. */
function wpcap_guard_extensions() {
	return array( 'php', 'js', 'mjs', 'json', 'txt', 'html', 'htm', 'css', 'xml', 'pot' );
}

/**
 * Reads .distignore into a list of [pattern, anchored, dir_only].
 */
function wpcap_guard_distignore( $root ) {
	$file = $root . '/.distignore';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$rules = array();
	foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		$dir_only = '/' === substr( $line, -1 );
		$line     = rtrim( $line, '/' );
		$anchored = '/' === $line[0];
		$line     = ltrim( $line, '/' );
		// rsync: a pattern containing a "/" (not trailing) is matched against the end of the full path.
		$has_slash = false !== strpos( $line, '/' );
		$rules[]   = array( $line, $anchored, $dir_only, $has_slash );
	}
	return $rules;
}

/** Whether a path relative to the plugin root is excluded by .distignore (rsync --exclude-from semantics). */
function wpcap_guard_excluded( $rel, $is_dir, array $rules ) {
	$base = basename( $rel );
	foreach ( $rules as $rule ) {
		list( $pattern, $anchored, $dir_only, $has_slash ) = $rule;
		if ( $dir_only && ! $is_dir ) {
			continue;
		}
		if ( $anchored ) {
			if ( fnmatch( $pattern, $rel, FNM_PATHNAME ) ) {
				return true;
			}
		} elseif ( $has_slash ) {
			if ( fnmatch( $pattern, $rel, FNM_PATHNAME ) || fnmatch( '*/' . $pattern, $rel, FNM_PATHNAME ) ) {
				return true;
			}
		} elseif ( fnmatch( $pattern, $base ) ) {
			return true;
		}
	}
	return false;
}

/** Shipped files below $root (relative paths), honouring .distignore; vendor/ is skipped (see header). */
function wpcap_guard_files( $root, $use_distignore = true ) {
	$rules = $use_distignore ? wpcap_guard_distignore( $root ) : array();
	$out   = array();
	$stack = array( '' );
	while ( $stack ) {
		$dir     = array_pop( $stack );
		$entries = scandir( '' === $dir ? $root : $root . '/' . $dir );
		if ( false === $entries ) {
			continue;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$rel    = '' === $dir ? $entry : $dir . '/' . $entry;
			$abs    = $root . '/' . $rel;
			$is_dir = is_dir( $abs ) && ! is_link( $abs );
			if ( wpcap_guard_excluded( $rel, $is_dir, $rules ) ) {
				continue;
			}
			if ( $is_dir ) {
				if ( 'vendor' !== $rel && 'node_modules' !== $entry && '.git' !== $entry ) {
					$stack[] = $rel;
				}
				continue;
			}
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, wpcap_guard_extensions(), true ) ) {
				$out[] = $rel;
			}
		}
	}
	sort( $out );
	return $out;
}

/**
 * Scans $root and returns a list of findings ["file:line [rule] explanation: excerpt"].
 */
function wpcap_guard_scan( $root, $use_distignore = true ) {
	$findings = array();
	$rules    = wpcap_guard_rules();
	foreach ( wpcap_guard_files( $root, $use_distignore ) as $rel ) {
		$contents = file_get_contents( $root . '/' . $rel );
		if ( false === $contents ) {
			$findings[] = $rel . ': unreadable';
			continue;
		}
		// Minified bundles (e.g. assets/vendor/jsqr) have huge lines; still scanned, excerpt is shortened.
		foreach ( preg_split( '/\R/', $contents ) as $index => $line ) {
			foreach ( $rules as $id => $rule ) {
				if ( preg_match( $rule[0], $line, $match ) ) {
					$findings[] = sprintf( '%s:%d [%s] %s: %s', $rel, $index + 1, $id, $rule[1], trim( substr( $match[0], 0, 120 ) ) );
				}
			}
		}
	}
	$composer = $root . '/composer.json';
	if ( is_readable( $composer ) ) {
		$data = json_decode( (string) file_get_contents( $composer ), true );
		foreach ( array( 'require', 'require-dev' ) as $section ) {
			foreach ( array_keys( (array) ( $data[ $section ] ?? array() ) ) as $package ) {
				if ( wpcap_guard_forbidden_package( $package ) ) {
					$findings[] = sprintf( 'composer.json [composer-package] Licence/updater package in %s: %s', $section, $package );
				}
			}
		}
	}
	return $findings;
}

/** Plants one sample per rule in a temporary plugin and checks every rule fires (and a clean file does not). */
function wpcap_guard_self_test() {
	$samples = array(
		'license-client'      => '$client = new LicenseClient( $url );',
		'wplc-route'          => "wp_remote_post( \$server . '/wp-json/wplc/v1/activate' );",
		'wplc-prefix'         => "get_option( 'wplc_activation' );",
		'wp-license-domain'   => "__( 'Activate', 'wp-license' );",
		'update-uri'          => ' * Update URI: https://shop.example.com/updates',
		'update-transient'    => "add_filter( 'pre_set_site_transient_update_plugins', 'x' );",
		'plugins-api'         => "add_filter( 'plugins_api', 'x', 10, 3 );",
		'update-hostname'     => "add_filter( 'update_plugins_shop.example.com', 'x' );",
		'upgrader-download'   => "add_filter( 'upgrader_pre_download', 'x' );",
		'entitlement'         => "if ( \$client->allows_entitlement( 'pass.wallet' ) ) {}",
		'license-option'      => "update_option( 'license_key', \$key );",
		'license-action'      => 'function activate_license() {}',
		'core-license-prefix' => "get_option( 'wpcap_licence' );",
		'updater-sdk'         => '$updater = PucFactory::buildUpdateChecker( $url, __FILE__ );',
	);
	$tmp = sys_get_temp_dir() . '/wpcap-guard-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp . '/src', 0700, true );
	mkdir( $tmp . '/tests', 0700, true );
	file_put_contents( $tmp . '/.distignore', "/tests/\n*.md\n" );
	file_put_contents( $tmp . '/clean.php', "<?php\n// License: GPL-2.0-or-later\nadd_filter( 'the_content', 'x' );\n" );
	file_put_contents( $tmp . '/tests/ignored.php', "<?php\nnew LicenseClient();\n" );
	file_put_contents( $tmp . '/NOTES.md', "LicenseClient\n" );
	file_put_contents( $tmp . '/composer.json', wp_json_encode_fallback( array( 'require' => array( 'yahnis-elsts/plugin-update-checker' => '^5' ) ) ) );
	foreach ( $samples as $id => $code ) {
		file_put_contents( $tmp . '/src/' . $id . '.php', "<?php\n" . $code . "\n" );
	}
	$findings = wpcap_guard_scan( $tmp );
	$failed   = array();
	foreach ( array_keys( wpcap_guard_rules() ) as $id ) {
		$hit = false;
		foreach ( $findings as $finding ) {
			if ( false !== strpos( $finding, 'src/' . $id . '.php:' ) && false !== strpos( $finding, '[' . $id . ']' ) ) {
				$hit = true;
			}
		}
		if ( ! $hit ) {
			$failed[] = 'rule ' . $id . ' did not detect its sample';
		}
	}
	foreach ( $findings as $finding ) {
		if ( 0 === strpos( $finding, 'clean.php' ) || 0 === strpos( $finding, 'tests/' ) || 0 === strpos( $finding, 'NOTES.md' ) ) {
			$failed[] = 'false positive or .distignore not honoured: ' . $finding;
		}
	}
	$composer_hit = (bool) array_filter( $findings, static fn( $f ) => str_contains( $f, '[composer-package]' ) );
	if ( ! $composer_hit ) {
		$failed[] = 'forbidden composer package not detected';
	}
	// Clean up.
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $tmp );
	return $failed;
}

/** json_encode without WordPress. */
function wp_json_encode_fallback( $data ) {
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

$args = array_slice( $argv, 1 );
if ( in_array( '--self-test', $args, true ) ) {
	$failed = wpcap_guard_self_test();
	if ( $failed ) {
		fwrite( STDERR, "Licence guard self-test FAILED:\n  " . implode( "\n  ", $failed ) . "\n" );
		exit( 1 );
	}
	echo 'Licence guard self-test passed (' . count( wpcap_guard_rules() ) . " rules + composer check).\n";
	exit( 0 );
}

$root = isset( $args[0] ) ? rtrim( $args[0], '/' ) : dirname( __DIR__, 2 );
if ( ! is_dir( $root ) || ! is_file( $root . '/pass-captain.php' ) ) {
	fwrite( STDERR, "Not a Pass Captain plugin root: {$root}\n" );
	exit( 2 );
}

$files    = wpcap_guard_files( $root );
$findings = wpcap_guard_scan( $root );
if ( $findings ) {
	fwrite( STDERR, "Licence code found in the core plugin (decision 13.11.2026: core stays free, licence code belongs into the premium add-on):\n  " . implode( "\n  ", $findings ) . "\n" );
	exit( 1 );
}
echo 'No licence or self-update code in ' . count( $files ) . " shipped files.\n";
exit( 0 );
