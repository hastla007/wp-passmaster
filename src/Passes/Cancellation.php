<?php
/**
 * Cancellation of a single occurrence linked to passes (`wpcap_occurrence_cancelled`).
 *
 * before_core() (priority 5, before OccurrenceCancellation at 10): a pass whose ANCHOR occurrence
 * is cancelled is moved to the earliest remaining scheduled linked occurrence, so the core neither
 * revokes nor refunds the pass tickets (the core acts on tickets of types anchored at the
 * cancelled occurrence). Without a remaining date – or with on_cancel = 'revoke' – the core
 * handles the pass like any ticket of the cancelled occurrence (revocation, optional auto refund).
 *
 * after_core() (priority 20) for passes linked to the cancelled occurrence:
 *  - on_cancel 'keep' (default): the pass stays valid for the other dates; with a configured
 *    partial refund per day, each paid order receives refund_per_day_minor × pass tickets as an
 *    amount-only refund (Refunds::request_amount, idempotent per occurrence and pass type); holders
 *    get the cancellation mail with a pass note.
 *  - on_cancel 'revoke': the whole pass is revoked; with `auto_refund_cancelled` paid native orders
 *    are refunded for the pass tickets (Refunds::request, releases capacity in all occurrences).
 *
 * Capacity: a cancelled linked occurrence no longer counts pass units (Capacity skips it).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Passes;

use PassCaptain\Audit;
use PassCaptain\Orders;
use PassCaptain\Refunds;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cancellation {
	/** Summaries of the last after_core() run per occurrence (request scope). */
	private static $summaries = array();

	public static function last_summary( $occurrence_id ) {
		return self::$summaries[ absint( $occurrence_id ) ] ?? array();
	}

	/** Priority 5: re-anchor passes sold on the cancelled occurrence. */
	public static function before_core( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$pass_ids = Definitions::all_ids();
		if ( ! $pass_ids ) { return; }
		$anchored = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d AND ticket_type_id IN (" . implode( ',', $pass_ids ) . ')', $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( $anchored as $type_id ) {
			$config = Definitions::get( $type_id );
			if ( ! $config || 'keep' !== $config->on_cancel ) { continue; }
			$next = 0;
			foreach ( Definitions::linked_occurrences( $type_id ) as $occ ) {
				if ( (int) $occ->occurrence_id !== $occurrence_id && 'scheduled' === $occ->status && 'cancelled' !== $occ->event_status ) {
					$next = (int) $occ->occurrence_id;
					break;
				}
			}
			if ( ! $next ) { continue; }
			// Core API: moves the type's sold/held units between the occurrence counters as well.
			\PassCaptain\CapacityCounters::move_type( $type_id, $occurrence_id, $next );
			Audit::record( 'pass_reanchored', 'occurrence', $occurrence_id, array( 'ticket_type_id' => $type_id, 'new_occurrence_id' => $next ) );
		}
		Definitions::flush();
	}

	/** Priority 20: keep/refund per day or revoke passes linked to the cancelled occurrence. */
	public static function after_core( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$summary = array( 'passes' => 0, 'kept' => 0, 'day_refunds' => 0, 'refund_errors' => 0, 'revoked' => 0, 'notified' => 0 );
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", $occurrence_id ) );
		if ( ! $occ ) { return $summary; }
		foreach ( Definitions::pass_ids_for_occurrence( $occurrence_id ) as $type_id ) {
			$config = Definitions::get( $type_id );
			$type = Definitions::type_row( $type_id );
			// Still anchored here: the core already handled (revoked/refunded) the pass tickets.
			if ( ! $config || ! $type || (int) $type->occurrence_id === $occurrence_id ) { continue; }
			++$summary['passes'];
			$orders = $wpdb->get_results( $wpdb->prepare( "SELECT t.order_id,ord.payment_provider,ord.customer_email,ord.locale,GROUP_CONCAT(t.ticket_id ORDER BY t.ticket_id) AS ticket_ids,COUNT(*) AS n FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.ticket_type_id=%d AND t.refunded=0 AND t.status IN ('valid','checked_in') AND ord.payment_status='paid' GROUP BY t.order_id,ord.payment_provider,ord.customer_email,ord.locale ORDER BY t.order_id", $type_id ) );
			if ( 'revoke' === $config->on_cancel ) {
				$summary = self::revoke_pass( $type_id, $orders, $occurrence_id, $summary );
				continue;
			}
			++$summary['kept'];
			$per_day = (int) $config->refund_per_day_minor;
			foreach ( (array) $orders as $row ) {
				if ( $per_day > 0 ) {
					$result = 'woocommerce' === $row->payment_provider
						? new \WP_Error( 'wpcap_refund_woocommerce', 'woocommerce' )
						/* translators: 1: pass name, 2: event name, 3: date */
						: Refunds::request_amount( (int) $row->order_id, $per_day * (int) $row->n, sprintf( __( 'Pass „%1$s“: Termin %2$s am %3$s abgesagt', 'wp-pass-captain' ), $type->name, $occ->event_name, Display::date( $occ->starts_at, $occ->timezone ) ), 'pass-day-' . $occurrence_id . '-' . $type_id, array( 'system' => true ) );
					if ( is_wp_error( $result ) || ! in_array( $result['status'] ?? '', array( 'completed', 'pending_approval' ), true ) ) {
						++$summary['refund_errors'];
						Audit::record( 'pass_day_refund_pending', 'order', (int) $row->order_id, array( 'occurrence_id' => $occurrence_id, 'ticket_type_id' => $type_id, 'error' => is_wp_error( $result ) ? $result->get_error_code() : ( $result['status'] ?? '' ) ) );
						/** A partial pass refund could not be executed here (e.g. WooCommerce order: refund there). */
						do_action( 'wpcap_pass_day_refund_failed', (int) $row->order_id, $type_id, $occurrence_id, $per_day * (int) $row->n );
					} else {
						++$summary['day_refunds'];
					}
				}
				$summary['notified'] += self::notify( $row, $occ, $type, $per_day * (int) $row->n );
			}
		}
		if ( $summary['passes'] ) { Audit::record( 'pass_occurrence_cancelled', 'occurrence', $occurrence_id, $summary ); }
		Definitions::flush();
		self::$summaries[ $occurrence_id ] = $summary;
		return $summary;
	}

	private static function revoke_pass( $type_id, $orders, $occurrence_id, array $summary ) {
		global $wpdb;
		$auto = (bool) Settings::get( 'auto_refund_cancelled' );
		foreach ( (array) $orders as $row ) {
			if ( ! $auto || 'woocommerce' === $row->payment_provider ) { continue; }
			$result = Refunds::request( (int) $row->order_id, array_map( 'absint', explode( ',', (string) $row->ticket_ids ) ), __( 'Pass: Termin abgesagt', 'wp-pass-captain' ), 'pass-cancelled-' . $occurrence_id . '-' . $type_id );
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) ) { ++$summary['refund_errors']; }
		}
		$revoked = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_type_id=%d AND status='valid'", $type_id ) ) );
		Orders::fire_revoked( $revoked, 'occurrence_cancelled' );
		$summary['revoked'] += count( $revoked );
		return $summary;
	}

	/** Cancellation mail to the buyer of a pass that stays valid. @return int 1 when sent. */
	private static function notify( $row, $occ, $type, $refund_minor ) {
		if ( ! is_email( $row->customer_email ) ) { return 0; }
		$tz = \PassCaptain\Admission\Engine::timezone( $occ->timezone );
		$currency = (string) $type->currency;
		$vars = array(
			'event_name' => (string) $occ->event_name,
			'event_date' => wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz ),
			'event_location' => (string) $occ->location,
			'timezone' => (string) $occ->timezone,
			'refund_note' => static function () use ( $type, $refund_minor, $currency ) {
				/* translators: %s: pass name */
				$note = sprintf( __( 'Dein Pass „%s“ bleibt für die übrigen Termine gültig.', 'wp-pass-captain' ), $type->name );
				if ( $refund_minor > 0 ) {
					/* translators: %s: amount */
					$note .= ' ' . sprintf( __( 'Für den abgesagten Termin erstatten wir dir %s.', 'wp-pass-captain' ), \PassCaptain\Frontend\Format::money( $refund_minor, $currency ) );
				}
				return $note;
			},
		);
		return \PassCaptain\Mail\Mailer::send( 'occurrence_cancelled', $row->customer_email, $vars, array(), (string) $row->locale ) ? 1 : 0;
	}
}
