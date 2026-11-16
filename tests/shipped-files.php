<?php
/**
 * Files that end up in the plugin ZIP (build/release.sh), for checks that must only look at shipped
 * code (tests/license-guard.php, tests/consistency.php). Included, not run.
 *
 * - Base set: files git knows (tracked plus untracked, not ignored – the release exports a committed
 *   tree); without git a directory walk (vendor/, node_modules/ and .git/ left out).
 * - vendor/: only the runtime packages of composer.lock ("packages", not "packages-dev"), plus
 *   vendor/autoload.php and vendor/composer/ – the release runs `composer install --no-dev`.
 * - .distignore is applied to everything, with the rsync --exclude-from semantics release.sh uses:
 *   leading "/" anchors to the root, trailing "/" matches directories only, patterns without "/"
 *   match a name at any depth, patterns with "/" match the end of the path; an excluded directory
 *   excludes everything below it.
 */

// phpcs:ignoreFile

/** Parsed .distignore rules: [pattern, anchored, dir_only, has_slash]. */
function wpcap_ship_rules( $root ) {
	$rules = array();
	foreach ( (array) @file( $root . '/.distignore', FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || '#' === $line[0] || ';' === $line[0] ) { continue; }
		if ( 0 === strpos( $line, '- ' ) ) { $line = substr( $line, 2 ); }
		$dir_only = '/' === substr( $line, -1 );
		$line     = rtrim( $line, '/' );
		$anchored = '/' === $line[0];
		$line     = ltrim( $line, '/' );
		$rules[]  = array( $line, $anchored, $dir_only, false !== strpos( $line, '/' ) );
	}
	return $rules;
}

/** Whether the relative path (a file, or a directory when $is_dir) is excluded by one rule itself. */
function wpcap_ship_rule_matches( array $rule, $path, $is_dir ) {
	list( $pattern, $anchored, $dir_only, $has_slash ) = $rule;
	if ( $dir_only && ! $is_dir ) { return false; }
	$parts = explode( '/', $path );
	if ( $anchored ) { return fnmatch( $pattern, $path, FNM_PATHNAME ); }
	if ( ! $has_slash ) { return fnmatch( $pattern, end( $parts ) ); }
	for ( $i = 0, $n = count( $parts ); $i < $n; $i++ ) {
		if ( fnmatch( $pattern, implode( '/', array_slice( $parts, $i ) ), FNM_PATHNAME ) ) { return true; }
	}
	return false;
}

/** Whether a relative path is left out of the ZIP (the path itself or one of its parent directories). */
function wpcap_ship_excluded( array $rules, $path, $is_dir = false ) {
	$parts = explode( '/', trim( str_replace( '\\', '/', $path ), '/' ) );
	for ( $k = 1, $n = count( $parts ); $k <= $n; $k++ ) {
		$sub = implode( '/', array_slice( $parts, 0, $k ) );
		$dir = $k < $n || $is_dir;
		foreach ( $rules as $rule ) {
			if ( wpcap_ship_rule_matches( $rule, $sub, $dir ) ) { return true; }
		}
	}
	return false;
}

/** Recursive walk below $dir (relative to $root), pruning excluded directories. */
function wpcap_ship_walk( $root, $dir, array $rules, array $skip = array() ) {
	$out = array();
	foreach ( (array) @scandir( $root . ( '' === $dir ? '' : '/' . $dir ) ) as $name ) {
		if ( '.' === $name || '..' === $name ) { continue; }
		$rel = '' === $dir ? $name : $dir . '/' . $name;
		if ( in_array( $rel, $skip, true ) ) { continue; }
		$abs = $root . '/' . $rel;
		if ( is_link( $abs ) ) { continue; }
		if ( is_dir( $abs ) ) {
			if ( ! wpcap_ship_excluded( $rules, $rel, true ) ) { $out = array_merge( $out, wpcap_ship_walk( $root, $rel, $rules ) ); }
		} elseif ( ! wpcap_ship_excluded( $rules, $rel ) ) {
			$out[] = $rel;
		}
	}
	return $out;
}

/**
 * Relative paths of all files the release ZIP would contain.
 *
 * @param string $root    Plugin root.
 * @param bool   $use_git False forces the directory walk (self-tests).
 */
function wpcap_shipped_files( $root, $use_git = true ) {
	$root  = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
	$rules = wpcap_ship_rules( $root );
	$files = null;
	if ( $use_git && file_exists( $root . '/.git' ) ) {
		$list = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files -z --cached --others --exclude-standard 2>/dev/null' );
		if ( is_string( $list ) && '' !== $list ) {
			$files = array_filter( explode( "\0", $list ), static fn( $f ) => '' !== $f && 0 !== strpos( $f, 'vendor/' ) && is_file( $root . '/' . $f ) && ! wpcap_ship_excluded( $rules, $f ) );
		}
	}
	if ( null === $files ) { $files = wpcap_ship_walk( $root, '', $rules, array( 'vendor', 'node_modules', '.git' ) ); }
	// Runtime Composer packages only (release.sh: composer install --no-dev).
	$lock = json_decode( (string) @file_get_contents( $root . '/composer.lock' ), true );
	$vendor_dirs = array( 'vendor/composer' );
	foreach ( (array) ( $lock['packages'] ?? array() ) as $package ) {
		if ( ! empty( $package['name'] ) ) { $vendor_dirs[] = 'vendor/' . $package['name']; }
	}
	if ( is_file( $root . '/vendor/autoload.php' ) ) { $files[] = 'vendor/autoload.php'; }
	foreach ( $vendor_dirs as $dir ) {
		if ( is_dir( $root . '/' . $dir ) && ! wpcap_ship_excluded( $rules, $dir, true ) ) { $files = array_merge( $files, wpcap_ship_walk( $root, $dir, $rules ) ); }
	}
	$files = array_values( array_unique( $files ) );
	sort( $files );
	return $files;
}
