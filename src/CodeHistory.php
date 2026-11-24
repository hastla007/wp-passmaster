<?php
/**
 * Why a ticket code no longer admits (A543): codes replaced by a new one and the reason of a
 * revocation, so the scanner can say "Code ersetzt (übertragen am …)" or "Widerrufen: umgetauscht"
 * instead of "Ticket nicht gefunden" / a bare "Widerrufen".
 *
 * - Table wpcap_code_history (schema 3.10.20): ticket_id, token_hash (SHA-256 of the old code; empty
 *   for revocations), kind `replaced` or `revoked`, reason (short code, never free text), created_at.
 *   No personal data: the hash of a code that no longer works, the ticket id and a reason category.
 * - `replaced` rows are written by every code rotation in the same transaction as the new hash
 *   (transfer, assign, resend, reissue, portal_pdf, print), so a rolled back rotation leaves nothing.
 * - `revoked` rows come from the action wpcap_ticket_revoked; free-text reasons of staff
 *   revocations are stored as `admin` (the text stays in the audit log only).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class CodeHistory {
	/** Reasons of code rotations as passed to wpcap_ticket_code_rotated. */
	const ROTATION_REASONS = array( 'transfer', 'assign', 'resend', 'reissue', 'portal_pdf', 'print' );

	public static function register() {
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'on_revoked' ), 5, 2 );
	}

	/** Whether the table exists (deferred schema upgrade). */
	public static function ready() {
		return Privacy::has_table( 'wpcap_code_history' );
	}

	/**
	 * Records that a ticket's current code is about to be replaced (call inside the rotation's
	 * transaction, before the new hash is written, ideally under the ticket row lock).
	 */
	public static function replacing( $ticket_id, $reason ) {
		if ( ! self::ready() ) { return; }
		global $wpdb;
		$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT token_hash FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", absint( $ticket_id ) ) );
		self::replaced( $ticket_id, $old, $reason );
	}

	/** Records an old code hash of a ticket as replaced. */
	public static function replaced( $ticket_id, $old_hash, $reason ) {
		$old_hash = strtolower( (string) $old_hash );
		if ( ! self::ready() || ! absint( $ticket_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $old_hash ) ) { return; }
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}wpcap_code_history", array(
			'ticket_id' => absint( $ticket_id ),
			'token_hash' => $old_hash,
			'kind' => 'replaced',
			'reason' => in_array( $reason, self::ROTATION_REASONS, true ) ? $reason : 'other',
			'created_at' => Clock::mysql(),
		), array( '%d', '%s', '%s', '%s', '%s' ) );
	}

	/** wpcap_ticket_revoked: remembers the reason category of the revocation. */
	public static function on_revoked( $ticket_id, $reason = '' ) {
		if ( ! self::ready() || ! absint( $ticket_id ) ) { return; }
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}wpcap_code_history", array(
			'ticket_id' => absint( $ticket_id ),
			'token_hash' => '',
			'kind' => 'revoked',
			'reason' => self::revoke_category( (string) $reason ),
			'created_at' => Clock::mysql(),
		), array( '%d', '%s', '%s', '%s', '%s' ) );
	}

	/** Reason category of a revocation (the free text of staff revocations is not kept here). */
	public static function revoke_category( $reason ) {
		$reason = (string) $reason;
		if ( 'refunded' === $reason || 0 === strpos( $reason, 'refund_' ) ) { return 'refunded'; }
		if ( in_array( $reason, array( 'occurrence_cancelled', 'event_cancelled' ), true ) ) { return 'cancelled'; }
		if ( 'exchange' === $reason ) { return 'exchanged'; }
		if ( in_array( $reason, array( 'order_cancelled', 'doctor' ), true ) ) { return $reason; }
		return 'admin';
	}

	/**
	 * The latest replacement of a code hash: {ticket_id, reason, created_at} or null. Only asked
	 * when no ticket has the hash as its current code.
	 */
	public static function find_replaced( $hash ) {
		if ( ! self::ready() ) { return null; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,reason,created_at FROM {$wpdb->prefix}wpcap_code_history WHERE token_hash=%s AND kind='replaced' ORDER BY history_id DESC LIMIT 1", strtolower( (string) $hash ) ) );
		return $row ?: null;
	}

	/** Latest revocation of a ticket: {reason, created_at} or null. */
	public static function last_revocation( $ticket_id ) {
		if ( ! self::ready() ) { return null; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT reason,created_at FROM {$wpdb->prefix}wpcap_code_history WHERE ticket_id=%d AND kind='revoked' ORDER BY history_id DESC LIMIT 1", absint( $ticket_id ) ) );
		return $row ?: null;
	}

	/** Staff-facing text of a replacement reason. */
	public static function replaced_label( $reason ) {
		$labels = array(
			'transfer' => __( 'an eine andere Person übertragen', 'pass-captain' ),
			'assign' => __( 'einer anderen Person zugewiesen', 'pass-captain' ),
			'resend' => __( 'erneut zugesendet', 'pass-captain' ),
			'reissue' => __( 'neu ausgestellt', 'pass-captain' ),
			'portal_pdf' => __( 'als neues PDF geladen', 'pass-captain' ),
			'print' => __( 'neu gedruckt', 'pass-captain' ),
		);
		return $labels[ $reason ] ?? __( 'neu ausgestellt', 'pass-captain' );
	}

	/** Staff-facing text of a revocation reason. */
	public static function revoked_label( $reason ) {
		$labels = array(
			'refunded' => __( 'erstattet', 'pass-captain' ),
			'cancelled' => __( 'Termin abgesagt', 'pass-captain' ),
			'order_cancelled' => __( 'Bestellung storniert', 'pass-captain' ),
			'exchanged' => __( 'umgetauscht – es gibt ein neues Ticket', 'pass-captain' ),
			'doctor' => __( 'bei der Datenprüfung gesperrt', 'pass-captain' ),
			'admin' => __( 'vom Veranstalter widerrufen', 'pass-captain' ),
		);
		return $labels[ $reason ] ?? __( 'vom Veranstalter widerrufen', 'pass-captain' );
	}
}
