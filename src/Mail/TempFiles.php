<?php
/**
 * Temporary mail attachments (A626): ticket PDFs, invoice PDFs, .pkpass and .ics files.
 *
 * Mail queue plugins (e.g. for SMTP providers) take the attachment paths from wp_mail() and send
 * later. Deleting the files right after wp_mail() made them send the mail without attachments. The
 * files are therefore kept for `wpcap_mail_attachment_ttl` seconds (default 6 hours) and removed by
 * the hourly cleanup (cron `wpcap_cleanup_reservations`), which sweeps the plugin's own temp names
 * only: private directories `wpcap-<hex>` (0700, files 0600, e.g. ticket PDFs with plaintext codes)
 * and `termin-<id>-<hex>.ics`. A TTL of 0 deletes them right after sending, as before.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Mail;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TempFiles {
	/** Default lifetime of a temporary attachment (seconds). */
	const TTL = 6 * HOUR_IN_SECONDS;

	/** Seconds attachments are kept after sending (filter wpcap_mail_attachment_ttl; 0 = delete at once). */
	public static function ttl() {
		/**
		 * How long temporary mail attachments are kept after wp_mail() (A626), so mail queue plugins
		 * that send later still find them. 0 deletes them right after sending.
		 *
		 * @param int $seconds Default 6 hours.
		 */
		return max( 0, min( 7 * DAY_IN_SECONDS, (int) apply_filters( 'wpcap_mail_attachment_ttl', self::TTL ) ) );
	}

	/**
	 * The mail with these attachments was handed to wp_mail(): delete now (TTL 0) or leave them for
	 * the cleanup sweep.
	 *
	 * @param string[] $files Attachment paths.
	 * @param string[] $dirs  Private directories of the files.
	 */
	public static function release( array $files, array $dirs = array() ) {
		if ( self::ttl() > 0 ) { return false; }
		foreach ( $files as $file ) {
			if ( is_string( $file ) && '' !== $file && is_file( $file ) ) { wp_delete_file( $file ); }
		}
		foreach ( $dirs as $dir ) {
			if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) ) { @rmdir( $dir ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	/**
	 * Hourly cleanup: removes the plugin's temp attachments older than ttl(). Returns the number of
	 * removed files.
	 *
	 * @param int|null    $now  Reference time (tests).
	 * @param string|null $base Temp directory (tests; default get_temp_dir()).
	 */
	public static function purge( $now = null, $base = null ) {
		$now = null === $now ? \PassCaptain\Clock::now() : (int) $now;
		$base = rtrim( null === $base ? (string) get_temp_dir() : (string) $base, '/\\' );
		if ( '' === $base || ! is_dir( $base ) ) { return 0; }
		$limit = $now - self::ttl();
		$removed = 0;
		foreach ( (array) glob( $base . '/wpcap-*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! preg_match( '/\/wpcap-[0-9a-f]{24,32}$/', (string) $dir ) || (int) @filemtime( $dir ) > $limit ) { continue; } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				if ( is_file( $file ) ) { wp_delete_file( $file ); ++$removed; }
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
		}
		foreach ( (array) glob( $base . '/termin-*.ics' ) as $file ) {
			if ( preg_match( '/\/termin-\d+-[0-9a-f]{16}\.ics$/', (string) $file ) && (int) @filemtime( $file ) <= $limit ) { wp_delete_file( $file ); ++$removed; } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $removed;
	}
}
