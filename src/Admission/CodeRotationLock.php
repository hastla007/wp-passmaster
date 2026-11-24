<?php
/**
 * Lock for buyer self-service code rotations during admission (A593).
 *
 * The buyer's order link can give a ticket a new code (portal resend, portal PDF without a known
 * code, transfer, attendee change). Once admission has begun – or once the ticket's code was shipped
 * to an offline scanner – such a rotation splits the ticket: offline devices still admit the old code
 * while the server only knows the new one ("offline valid, online invalid"), and the guest at the door
 * may hold either. Self-service rotations are therefore refused from the start of admission and while
 * an offline package with the current code may still be in use. Staff actions (reissue, admin resend,
 * printing) are not affected.
 *
 * Start of admission: the earliest of the doors-open time, the start minus `entry_opens_before_minutes`
 * and, for ticket types with an admission policy, the start minus its `opens_before_minutes`.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admission;

use PassCaptain\Clock;
use PassCaptain\DoorsOpen;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class CodeRotationLock {
	/** Hours an offline package may be used at most (upper bound of the event setting max_offline_hours). */
	const PACKAGE_HOURS = 72;

	/**
	 * Why a self-service rotation of the ticket's code is refused now: 'admission_open',
	 * 'offline_package' or '' (allowed).
	 */
	public static function reason( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ticket = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.ticket_type_id,t.token_hash,o.occurrence_id,o.starts_at FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
		if ( ! $ticket ) { return ''; }
		$reason = '';
		if ( Clock::now() >= self::admission_opens( $ticket ) ) {
			$reason = 'admission_open';
		} elseif ( self::in_offline_package( (string) $ticket->token_hash ) ) {
			$reason = 'offline_package';
		}
		/**
		 * Filters the lock for buyer self-service code rotations (A593).
		 *
		 * @param string $reason    'admission_open', 'offline_package' or '' (rotation allowed).
		 * @param int    $ticket_id Ticket id.
		 */
		return (string) apply_filters( 'wpcap_self_service_rotation_lock', $reason, (int) $ticket->ticket_id );
	}

	/** UTC timestamp from which the occurrence's admission may run. */
	private static function admission_opens( $ticket ) {
		$start = strtotime( $ticket->starts_at . ' UTC' );
		$before = max( 0, (int) Settings::get( 'entry_opens_before_minutes' ) );
		$policy = Policies::for_type( (int) $ticket->ticket_type_id );
		if ( $policy && null !== ( $policy['opens_before_minutes'] ?? null ) ) { $before = max( $before, (int) $policy['opens_before_minutes'] ); }
		$opens = $start - $before * MINUTE_IN_SECONDS;
		$doors = DoorsOpen::for_occurrence( (int) $ticket->occurrence_id );
		return null === $doors ? $opens : min( $opens, strtotime( $doors . ' UTC' ) );
	}

	/** Whether the code is admissible in an offline package fetched by an assigned device recently. */
	private static function in_offline_package( $token_hash ) {
		if ( '' === $token_hash || ! \PassCaptain\Privacy::has_table( 'wpcap_offline_entries' ) || ! \PassCaptain\Privacy::has_table( 'wpcap_offline_assignments' ) ) { return false; }
		global $wpdb;
		$p = $wpdb->prefix;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_offline_entries oe INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=oe.occurrence_id INNER JOIN {$p}wpcap_offline_assignments a ON a.event_id=o.event_id AND a.occurrence_id IN (0,oe.occurrence_id) WHERE oe.token_hash=%s AND oe.status<>'revoked' AND a.last_package_at>=%s LIMIT 1", $token_hash, Clock::mysql( -self::PACKAGE_HOURS * HOUR_IN_SECONDS ) ) );
	}

	/** Buyer-facing message for a lock reason. */
	public static function message( $reason ) {
		return 'offline_package' === $reason
			? __( 'Dieses Ticket ist bereits auf den Einlassgeräten gespeichert. Ein neuer Ticketcode ist jetzt nicht mehr möglich – bitte das vorhandene Ticket verwenden oder sich am Einlass mit der Bestellnummer melden.', 'pass-captain' )
			: __( 'Der Einlass hat begonnen. Ein neuer Ticketcode ist jetzt nicht mehr möglich – bitte das vorhandene Ticket verwenden oder sich am Einlass mit der Bestellnummer melden.', 'pass-captain' );
	}

	/** Error for a refused self-service rotation. */
	public static function error( $reason ) {
		return new \WP_Error( 'wpcap_ticket_rotation_locked', self::message( $reason ), array( 'status' => 409, 'reason' => (string) $reason ) );
	}
}
