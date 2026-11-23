<?php
/**
 * Z1 (merge follow-up): the documentation names the REST namespace pass-captain/v1. The old
 * namespace wp-pass-captain/v1 (forwarded by LegacySlug) may only appear next to the new one, as
 * the legacy alias; docs/RENAME.md describes the rename and the audit reports are historical.
 * Self-contained (WPCAP_TEST_ONLY=z1_docs).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

echo "Z1: documentation uses the REST namespace pass-captain/v1\n";
$z1d_files = array_merge( glob( WPCAP_PATH . 'docs/*.md' ), array( WPCAP_PATH . 'readme.txt' ) );
$z1d_bad = array();
foreach ( $z1d_files as $z1d_file ) {
	if ( 'RENAME.md' === basename( $z1d_file ) ) { continue; }
	foreach ( file( $z1d_file ) as $z1d_no => $z1d_line ) {
		if ( preg_match( '#wp-pass-captain/v\d#', $z1d_line ) && ! preg_match( '#(?<![\w-])pass-captain/v1#', $z1d_line ) ) {
			$z1d_bad[] = basename( $z1d_file ) . ':' . ( $z1d_no + 1 );
		}
	}
}
check( 'z1: no doc names the old namespace wp-pass-captain/v1 without the new one' . ( $z1d_bad ? ' – ' . implode( ', ', $z1d_bad ) : '' ), ! $z1d_bad );
check( 'z1: readme FAQ names the new namespace and the forwarding', 1 === preg_match( '#REST namespace is now `pass-captain/v1`; requests to the old namespace `wp-pass-captain/v1` are forwarded#', (string) file_get_contents( WPCAP_PATH . 'readme.txt' ) ) );
