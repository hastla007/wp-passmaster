<?php
/**
 * Short, typeable help code per ticket ("Hilfscode", e.g. "K7QM-4XWA") for staff when the QR code
 * cannot be read (cracked display, low brightness, bad print). Shown next to the QR code on the
 * ticket page, in the ticket PDF and in ticket mails; accepted by the web scanner, the offline PWA
 * (online only) and the mobile app API wherever a ticket code is accepted.
 *
 * Security model (docs/SECURITY.md, "Hilfscode für den Einlass"):
 * - The help code is derived from the ticket's secret code (keyed HMAC) and never stored in plain
 *   text: the tickets table only keeps a keyed hash (help_hash) plus a small derivation counter
 *   (help_n) that makes codes unique. Rotating the ticket code (resend, transfer, reissue, print,
 *   assignment, guest list) assigns a new help code, so the old one stops working at once.
 * - 8 characters from a 32-character alphabet without 0/O/1/I = 40 bits. Lookups are only possible
 *   for authenticated staff (wpcap_checkin with event scope, or a paired scanner device) and are
 *   rate-limited per user, per client address and per site. The help code is never accepted by
 *   public endpoints (ticket page, portal, wallet), which keep requiring the 64-hex code.
 * - Codes are assigned when the ticket code is known in plain text (issuance, rotation, ticket page
 *   view). Tickets issued before schema 3.8.6 get theirs lazily on the next ticket page view.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class HelpCode {
	/** Unambiguous alphabet (no 0, O, 1, I): exactly 32 characters, 5 bits each. */
	const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
	const LENGTH = 8;
	/** Derivation attempts before giving up (collisions are rare: 40-bit space). */
	const MAX_ATTEMPTS = 8;

	/** Help codes assigned in this request (ticket_id => formatted code), for mail sections. */
	private static $assigned = array();
	/** Resolved lookups in this request (normalized code => ticket_id|WP_Error), so retries do not count twice. */
	private static $resolved = array();

	public static function register() {
		// Before wallet passes and mails are built (they run at priority 10 or later).
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'on_code_rotated' ), 5, 2 );
		add_filter( 'wpcap_ticket_code_mail_section', array( __CLASS__, 'mail_section' ), 5, 2 );
	}

	/** Schema 3.8.6 columns present (older schema during a deferred upgrade: feature off). */
	public static function ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		// Only a positive answer is cached: the upgrade may complete later in the same process.
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_tickets LIKE 'help_hash'" );
		}
		return $ready[ $key ];
	}

	/** 32 byte key from WPCAP_HELP_CODE_KEY or the WordPress auth salt (not stored in the database). */
	private static function key() {
		$base = defined( 'WPCAP_HELP_CODE_KEY' ) ? (string) constant( 'WPCAP_HELP_CODE_KEY' ) : '';
		if ( '' === $base && function_exists( 'wp_salt' ) ) { $base = (string) wp_salt( 'auth' ); }
		if ( '' === $base ) { $base = ABSPATH; }
		return hash_hmac( 'sha256', 'wpcap-help-code-v1', $base, true );
	}

	/** Raw help code (8 characters, no separator) for a ticket code and derivation counter. */
	public static function derive( $token, $n = 0 ) {
		$bytes = substr( hash_hmac( 'sha256', 'derive|' . (int) $n . '|' . strtolower( (string) $token ), self::key(), true ), 0, 5 );
		$bits = '';
		foreach ( str_split( $bytes ) as $byte ) { $bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT ); }
		$code = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) { $code .= self::ALPHABET[ bindec( substr( $bits, $i * 5, 5 ) ) ]; }
		return $code;
	}

	/** Keyed hash stored in wpcap_tickets.help_hash. */
	public static function lookup_hash( $code ) {
		return hash_hmac( 'sha256', 'lookup|' . $code, self::key() );
	}

	/** "K7QM4XWA" -> "K7QM-4XWA". */
	public static function format( $code ) {
		$code = (string) $code;
		return '' === $code ? '' : substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
	}

	/**
	 * Normalizes typed input (case, spaces, hyphens); '' when it is not a well-formed help code.
	 * Characters that never occur (0, O, 1, I) make the input invalid instead of being guessed.
	 */
	public static function normalize( $value ) {
		$value = strtoupper( preg_replace( '/[\s\-_.]+/', '', (string) $value ) );
		return 1 === preg_match( '/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $value ) ? $value : '';
	}

	/** Whether $value looks like a help code (not a 64-hex ticket code). */
	public static function looks_like( $value ) {
		return '' !== self::normalize( $value );
	}

	/**
	 * Help code of a ticket whose plain code is known; assigns (or re-assigns after a code rotation)
	 * when needed. Returns the formatted code or '' (schema not ready, unknown ticket, no free code).
	 */
	public static function for_ticket( $ticket_id, $token ) {
		global $wpdb;
		$ticket_id = absint( $ticket_id );
		$token = Checkin::normalize_code( $token );
		if ( ! $ticket_id || '' === $token || ! self::ready() ) { return ''; }
		$table = $wpdb->prefix . 'wpcap_tickets';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,token_hash,help_hash,help_n FROM $table WHERE ticket_id=%d", $ticket_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Only for the ticket's current code: an old link must not (re)assign a help code.
		if ( ! $row || ! hash_equals( (string) $row->token_hash, hash( 'sha256', $token ) ) ) { return ''; }
		if ( null !== $row->help_hash && '' !== (string) $row->help_hash ) {
			$code = self::derive( $token, (int) $row->help_n );
			if ( hash_equals( (string) $row->help_hash, self::lookup_hash( $code ) ) ) {
				return self::$assigned[ $ticket_id ] = self::format( $code );
			}
		}
		for ( $n = 0; $n < self::MAX_ATTEMPTS; $n++ ) {
			$code = self::derive( $token, $n );
			$hash = self::lookup_hash( $code );
			$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM $table WHERE help_hash=%s AND ticket_id<>%d LIMIT 1", $hash, $ticket_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $taken ) { continue; }
			$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE $table SET help_hash=%s,help_n=%d WHERE ticket_id=%d AND token_hash=%s", $hash, $n, $ticket_id, hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
			if ( false === $updated ) { continue; } // Unique key race with another ticket: next counter.
			return self::$assigned[ $ticket_id ] = self::format( $code );
		}
		return '';
	}

	/** Action wpcap_ticket_code_rotated: the new code gets a new help code; the old one stops working. */
	public static function on_code_rotated( $ticket_id, $token ) {
		if ( ! self::ready() ) { return; }
		global $wpdb;
		// Drop the old help code first, even if no new one can be assigned.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET help_hash=NULL,help_n=0 WHERE ticket_id=%d", absint( $ticket_id ) ) );
		unset( self::$assigned[ absint( $ticket_id ) ] );
		self::$resolved = array();
		self::for_ticket( $ticket_id, $token );
	}

	/** Filter wpcap_ticket_code_mail_section: help code of a ticket whose code was rotated in this request. */
	public static function mail_section( $text, $ticket_id ) {
		$code = self::$assigned[ absint( $ticket_id ) ] ?? '';
		return '' === $code ? $text : (string) $text . self::label( $code ) . "\n";
	}

	/** Caption in front of the help code (ticket page, PDF). */
	public static function caption() {
		return __( 'Hilfscode für den Einlass, falls der QR-Code nicht lesbar ist:', 'pass-captain' );
	}

	/** Caption and code in one line (mails). */
	public static function label( $code ) {
		return self::caption() . ' ' . $code;
	}

	/**
	 * Rate limits for help-code lookups (attempts per window). Every lookup counts, found or not.
	 *
	 * @return array{user:int,client:int,site:int,window:int}
	 */
	public static function limits() {
		$limits = (array) apply_filters( 'wpcap_help_code_rate_limits', array( 'user' => 30, 'client' => 60, 'site' => 600, 'window' => 600 ) );
		return array(
			'user' => max( 1, (int) ( $limits['user'] ?? 30 ) ),
			'client' => max( 1, (int) ( $limits['client'] ?? 60 ) ),
			'site' => max( 1, (int) ( $limits['site'] ?? 600 ) ),
			'window' => max( 60, (int) ( $limits['window'] ?? 600 ) ),
		);
	}

	/**
	 * Ticket id for a typed help code (0 when unknown) or a rate-limit error. Rate-limited per
	 * WordPress user (staff or the scanner device's user), per client address and per site.
	 *
	 * @return int|\WP_Error
	 */
	public static function resolve( $value ) {
		$code = self::normalize( $value );
		if ( '' === $code || ! self::ready() ) { return 0; }
		if ( isset( self::$resolved[ $code ] ) ) { return self::$resolved[ $code ]; }
		$limits = self::limits();
		$user = (int) get_current_user_id();
		$allowed = RateLimit::allow( 'help-code|site', $limits['site'], $limits['window'] )
			&& RateLimit::allow( 'help-code|user|' . $user, $limits['user'], $limits['window'] )
			&& RateLimit::allow( 'help-code|client|' . RateLimit::client(), $limits['client'], $limits['window'] );
		if ( ! $allowed ) {
			Audit::record( 'help_code_rate_limited', 'user', $user, array() );
			return new \WP_Error( 'wpcap_help_code_rate_limited', __( 'Zu viele Hilfscode-Eingaben. Bitte kurz warten oder den QR-Code bzw. den langen Ticket-Code verwenden.', 'pass-captain' ), array( 'status' => 429, 'result' => 'rate_limited' ) );
		}
		global $wpdb;
		$ticket_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE help_hash=%s LIMIT 1", self::lookup_hash( $code ) ) );
		return self::$resolved[ $code ] = $ticket_id;
	}

	/** Forgets per-request caches (tests). */
	public static function flush() {
		self::$assigned = array();
		self::$resolved = array();
	}
}
