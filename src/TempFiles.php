<?php
/**
 * Private temporary files (mail attachments with plaintext ticket codes, signing input).
 *
 * Uses WordPress' direct filesystem class (WP_Filesystem_Direct) for writing, chmod and directory
 * removal and wp_delete_file() for files. These files live only in the server's temp directory for the
 * duration of one request, so the credential-based WP_Filesystem() methods (FTP/SSH) are neither needed
 * nor suitable. When the class cannot be loaded (minimal runtimes, tests) plain PHP functions are used.
 *
 * Files are created inside a fresh 0700 directory with a random name and chmod 0600, and removed right
 * after use; track() additionally removes them at shutdown in case a request aborts.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TempFiles {
	/** @var \WP_Filesystem_Direct|false|null */
	private static $fs = null;

	/** @var array<int,array{0:string,1:string}> Files (and their directories) removed at shutdown. */
	private static $tracked = array();

	/** WP_Filesystem_Direct instance or null when unavailable. */
	private static function fs() {
		if ( null === self::$fs ) {
			self::$fs = false;
			if ( ! class_exists( 'WP_Filesystem_Direct', false ) && defined( 'ABSPATH' ) ) {
				$base = ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				$direct = ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				if ( is_readable( $base ) && is_readable( $direct ) ) {
					require_once $base;
					require_once $direct;
				}
			}
			if ( class_exists( 'WP_Filesystem_Direct', false ) ) {
				self::$fs = new \WP_Filesystem_Direct( null );
			}
		}
		return self::$fs ? self::$fs : null;
	}

	/** Creates a fresh directory (0700, random name) in the temp dir; null on failure. */
	public static function private_dir() {
		$base = rtrim( (string) get_temp_dir(), '/\\' );
		for ( $i = 0; $i < 3; $i++ ) {
			$dir = $base . '/wpcap-' . bin2hex( random_bytes( 16 ) );
			if ( file_exists( $dir ) ) { continue; }
			$old_umask = umask( 0077 );
			$fs = self::fs();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- fallback without WP_Filesystem_Direct.
			$made = $fs ? $fs->mkdir( $dir, 0700 ) : mkdir( $dir, 0700 );
			umask( $old_umask );
			if ( $made && is_dir( $dir ) ) {
				self::chmod( $dir, 0700 );
				return $dir;
			}
		}
		return null;
	}

	/** Writes $bytes to $file with mode 0600. */
	public static function put( $file, $bytes ) {
		$fs = self::fs();
		if ( $fs ) {
			$ok = $fs->put_contents( $file, (string) $bytes, 0600 );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fallback without WP_Filesystem_Direct.
			$ok = false !== file_put_contents( $file, (string) $bytes );
			if ( $ok ) { self::chmod( $file, 0600 ); }
		}
		return (bool) $ok;
	}

	/** Reads a file this request created; empty string on failure. */
	public static function get( $file ) {
		if ( ! is_readable( $file ) ) { return ''; }
		$fs = self::fs();
		$bytes = $fs ? $fs->get_contents( $file ) : file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		return is_string( $bytes ) ? $bytes : '';
	}

	/** Removes a file if it exists. */
	public static function remove( $file ) {
		if ( '' !== (string) $file && is_file( $file ) ) { wp_delete_file( $file ); }
	}

	/** Removes an (empty) directory created by private_dir(). */
	public static function remove_dir( $dir ) {
		if ( '' === (string) $dir || ! is_dir( $dir ) ) { return; }
		$fs = self::fs();
		if ( $fs ) {
			$fs->rmdir( $dir );
			return;
		}
		// Only empty directories: their files are removed first.
		if ( ! ( new \FilesystemIterator( $dir ) )->valid() ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- fallback without WP_Filesystem_Direct.
		}
	}

	/** Removes $file and then its directory $dir (when given). */
	public static function remove_with_dir( $file, $dir = '' ) {
		self::remove( $file );
		if ( '' !== (string) $dir ) { self::remove_dir( $dir ); }
	}

	/** Removes $file (and $dir) at shutdown at the latest; harmless when already removed. */
	public static function track( $file, $dir = '' ) {
		if ( ! self::$tracked ) { register_shutdown_function( array( __CLASS__, 'cleanup' ) ); }
		self::$tracked[] = array( (string) $file, (string) $dir );
	}

	/** Shutdown cleanup of tracked files (idempotent). */
	public static function cleanup() {
		foreach ( self::$tracked as $entry ) { self::remove_with_dir( $entry[0], $entry[1] ); }
		self::$tracked = array();
	}

	private static function chmod( $path, $mode ) {
		$fs = self::fs();
		if ( $fs ) {
			$fs->chmod( $path, $mode );
			return;
		}
		if ( file_exists( $path ) ) {
			chmod( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- fallback without WP_Filesystem_Direct.
		}
	}
}
