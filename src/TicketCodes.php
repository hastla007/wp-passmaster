<?php
/**
 * Encrypted copy of the current ticket code (A586), so a ticket PDF can be built again without
 * issuing a new code (order portal, admin "Ticket-PDF") – before, every portal download without the
 * wallet module invalidated the code that had just been mailed or printed.
 *
 * - Column wpcap_tickets.code_secret (schema 3.10.15), sealed with Secrets::seal_value() (sodium
 *   secretbox, key WPCAP_SECRETS_KEY or the salts, rotation through WPCAP_SECRETS_KEY_PREVIOUS),
 *   bound to the ticket id. The check-in still only uses the SHA-256 hash (token_hash).
 * - Written at issuance (wpcap_ticket_mail, before any other filter), after every code change
 *   (wpcap_ticket_code_rotated) and for tickets issued elsewhere (wpcap_ticket_code_issued); only when
 *   the code matches the ticket's current hash. Removed when the ticket is revoked.
 * - Read through the filter wpcap_ticket_known_code (also used by the wallet module's own store).
 * - Off with the filter wpcap_store_ticket_codes (false): then only hashes are stored, as before.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketCodes {
	public static function register() {
		add_filter( 'wpcap_ticket_mail', array( __CLASS__, 'capture' ), 0, 3 );
		add_action( 'wpcap_ticket_code_rotated', array( __CLASS__, 'remember' ), 5, 2 );
		add_action( 'wpcap_ticket_code_issued', array( __CLASS__, 'remember' ), 5, 2 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'forget' ), 5, 1 );
		add_filter( 'wpcap_ticket_known_code', array( __CLASS__, 'known_code' ), 5, 2 );
	}

	/** Whether the column exists (deferred schema upgrade). */
	public static function ready() {
		return ServiceBookings::has_column( 'wpcap_tickets', 'code_secret' );
	}

	public static function enabled() {
		/**
		 * Whether the current ticket code is kept encrypted for PDF downloads without a new code (A586).
		 *
		 * @param bool $store Default true.
		 */
		return self::ready() && (bool) apply_filters( 'wpcap_store_ticket_codes', true );
	}

	private static function context( $ticket_id ) { return 'ticket_code|' . absint( $ticket_id ); }

	private static function valid_code( $code ) {
		return is_string( $code ) && 64 === strlen( $code ) && ctype_xdigit( $code );
	}

	/** Stores the code when it is the ticket's current one. */
	public static function remember( $ticket_id, $code ) {
		if ( ! self::enabled() || ! self::valid_code( $code ) ) { return false; }
		global $wpdb;
		$code = strtolower( $code );
		try {
			$sealed = Secrets::seal_value( self::context( $ticket_id ), $code );
		} catch ( \Throwable $e ) {
			return false;
		}
		// Only for the current code: an older rotation hook arriving late must not overwrite a newer code.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET code_secret=%s WHERE ticket_id=%d AND token_hash=%s", $sealed, absint( $ticket_id ), hash( 'sha256', $code ) ) );
	}

	/** wpcap_ticket_mail (priority 0): stores the codes of the freshly issued tickets. */
	public static function capture( $mail, $order = null, $issued = array() ) {
		foreach ( is_array( $issued ) ? $issued : array() as $entry ) {
			if ( ! empty( $entry['ticket_id'] ) && isset( $entry['token'] ) ) { self::remember( (int) $entry['ticket_id'], (string) $entry['token'] ); }
		}
		return $mail;
	}

	/** wpcap_ticket_revoked: a revoked ticket's code is never needed again. */
	public static function forget( $ticket_id ) {
		if ( ! self::ready() ) { return; }
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_tickets SET code_secret=NULL WHERE ticket_id=%d", absint( $ticket_id ) ) );
	}

	/** Stored current code of a ticket ('' when unknown, undecryptable or outdated). */
	public static function get( $ticket_id ) {
		if ( ! self::ready() ) { return ''; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT token_hash,code_secret FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $row || empty( $row->code_secret ) ) { return ''; }
		$code = Secrets::open_value( self::context( $ticket_id ), (string) $row->code_secret );
		return is_string( $code ) && self::valid_code( $code ) && hash_equals( (string) $row->token_hash, hash( 'sha256', $code ) ) ? $code : '';
	}

	/** Filter wpcap_ticket_known_code. */
	public static function known_code( $code, $ticket_id ) {
		if ( '' !== (string) $code ) { return $code; }
		$stored = self::get( $ticket_id );
		return '' !== $stored ? $stored : $code;
	}
}
