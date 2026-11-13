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
 *  - on_cancel 'revoke': the whole pass is revoked and every buyer gets the cancellation mail; with
 *    `auto_refund_cancelled` paid native orders are refunded for the pass tickets (Refunds::request,
 *    releases capacity in all occurrences). Otherwise the orders are recorded as "refund open"
 *    (audit pass_refund_open, summary mail to the site admin) and stay refundable.
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
		$summary = array( 'passes' => 0, 'kept' => 0, 'day_refunds' => 0, 'refund_errors' => 0, 'revoked' => 0, 'notified' => 0, 'refunds_open' => 0 );
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

	/**
	 * on_cancel 'revoke': the pass becomes invalid. Paid native orders are refunded automatically
	 * with `auto_refund_cancelled`; otherwise (and for WooCommerce or failed refunds) the order is
	 * recorded as "refund open" (audit + one summary mail to the site admin) and the revoked pass
	 * tickets stay refundable (filter refundable_tickets()). Every buyer gets the cancellation mail.
	 */
	private static function revoke_pass( $type_id, $orders, $occurrence_id, array $summary ) {
		global $wpdb;
		$auto = (bool) Settings::get( 'auto_refund_cancelled' );
		$type = Definitions::type_row( $type_id );
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
		$open = array();
		foreach ( (array) $orders as $row ) {
			$refunded = false;
			if ( $auto && 'woocommerce' !== $row->payment_provider ) {
				$result = Refunds::request( (int) $row->order_id, array_map( 'absint', explode( ',', (string) $row->ticket_ids ) ), __( 'Pass: Termin abgesagt', 'wp-pass-captain' ), 'pass-cancelled-' . $occurrence_id . '-' . $type_id );
				if ( is_wp_error( $result ) || ! in_array( $result['status'] ?? '', array( 'completed', 'pending_approval' ), true ) ) { ++$summary['refund_errors']; } else { $refunded = true; }
			}
			if ( 'woocommerce' === $row->payment_provider ) {
				do_action( 'wpcap_occurrence_cancelled_woocommerce_order', (int) $row->order_id, absint( $occurrence_id ) );
			}
			if ( ! $refunded ) {
				$open[] = (int) $row->order_id;
				Audit::record( 'pass_refund_open', 'order', (int) $row->order_id, array( 'occurrence_id' => absint( $occurrence_id ), 'ticket_type_id' => absint( $type_id ), 'tickets' => (int) $row->n ) );
			}
		}
		$revoked = Orders::revoke_ids( $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_type_id=%d AND status='valid'", $type_id ) ) );
		Orders::fire_revoked( $revoked, 'occurrence_cancelled' );
		$summary['revoked'] += count( $revoked );
		if ( $type && $occ ) {
			foreach ( (array) $orders as $row ) {
				$summary['notified'] += self::notify_revoked( $row, $occ, $type, $auto && ! in_array( (int) $row->order_id, $open, true ) );
			}
		}
		$summary['refunds_open'] = ( $summary['refunds_open'] ?? 0 ) + count( $open );
		if ( $open && $type && $occ ) {
			wp_mail(
				get_option( 'admin_email' ),
				__( 'WP Pass Captain: Erstattungen für abgesagte Pässe offen', 'wp-pass-captain' ),
				/* translators: 1: pass name, 2: event name, 3: date, 4: number of orders, 5: order ids */
				sprintf( __( 'Der Pass „%1$s“ wurde wegen der Absage von %2$s am %3$s widerrufen. Für %4$d bezahlte Bestellungen ist noch keine Erstattung erfolgt (Bestellungen: %5$s). Die widerrufenen Pässe bleiben in der Bestellansicht erstattbar.', 'wp-pass-captain' ), $type->name, $occ->event_name, Display::date( $occ->starts_at, $occ->timezone ), count( $open ), implode( ', ', array_map( static function ( $id ) { $o = Orders::get( $id ); return $o ? $o->public_id : (string) $id; }, $open ) ) )
			);
		}
		return $summary;
	}

	/**
	 * Filter wpcap_refundable_tickets: revoked, unrefunded pass tickets of a pass with on_cancel
	 * 'revoke' and a cancelled linked occurrence stay refundable (the core only covers tickets of a
	 * cancelled anchor occurrence).
	 */
	public static function refundable_tickets( $rows, $order_id ) {
		global $wpdb;
		$pass_ids = Definitions::all_ids();
		if ( ! $pass_ids ) { return $rows; }
		$known = array();
		foreach ( (array) $rows as $row ) { $known[ (int) $row->ticket_id ] = true; }
		$revoked = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id,ticket_type_id FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND refunded=0 AND status='revoked' AND ticket_type_id IN (" . implode( ',', array_map( 'intval', $pass_ids ) ) . ') ORDER BY ticket_id', absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( (array) $revoked as $ticket ) {
			if ( isset( $known[ (int) $ticket->ticket_id ] ) ) { continue; }
			$config = Definitions::get( (int) $ticket->ticket_type_id );
			if ( ! $config || 'revoke' !== $config->on_cancel ) { continue; }
			foreach ( Definitions::linked_occurrences( (int) $ticket->ticket_type_id ) as $occ ) {
				if ( 'cancelled' === $occ->status || 'cancelled' === $occ->event_status ) { $rows[] = $ticket; break; }
			}
		}
		return $rows;
	}

	/** Cancellation mail for a revoked pass. @return int 1 when sent. */
	private static function notify_revoked( $row, $occ, $type, $refunded ) {
		if ( ! is_email( $row->customer_email ) ) { return 0; }
		$tz = \PassCaptain\Admission\Engine::timezone( $occ->timezone );
		$vars = array(
			'event_name' => (string) $occ->event_name,
			'event_date' => wp_date( 'd.m.Y H:i', strtotime( $occ->starts_at . ' UTC' ), $tz ),
			'event_location' => (string) $occ->location,
			'timezone' => (string) $occ->timezone,
			'refund_note' => static function () use ( $type, $refunded ) {
				/* translators: %s: pass name */
				$note = sprintf( __( 'Dein Pass „%s“ ist deshalb nicht mehr gültig.', 'wp-pass-captain' ), $type->name );
				return $note . ' ' . ( $refunded ? __( 'Der Ticketpreis wird automatisch erstattet.', 'wp-pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'wp-pass-captain' ) );
			},
		);
		return \PassCaptain\Mail\Mailer::send( 'occurrence_cancelled', $row->customer_email, $vars, array(), (string) $row->locale ) ? 1 : 0;
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
