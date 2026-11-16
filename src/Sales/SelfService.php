<?php
/**
 * Buyer self-service: cancellation / refund request from the order portal under the event's rule.
 *
 * Rule per event (wpcap_events.refund_policy, JSON {hours_before, fee_bp}): requests are possible for
 * valid, not checked-in tickets of a paid order until hours_before hours before the occurrence; the
 * fee (share of the ticket value) is kept. A request creates a normal refund through
 * Refunds::request() with the option self_service – it always waits for the second approval in
 * "Erstattungen", so staff decide; approval pays out through the payment provider, revokes the tickets
 * and issues the credit note for the refunded amount.
 *
 * Shared service for special cases: request() with $context['waive_policy'] (e.g. a postponed
 * occurrence: no deadline, no fee, also without an event rule) is the entry point other features
 * (the "verschobener Termin" button) call instead of their own refund logic.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Sales;

use PassCaptain\Audit;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SelfService {
	public static function register() {
		add_filter( 'wpcap_order_portal_sections', array( __CLASS__, 'portal_section' ), 20, 2 );
	}

	/**
	 * Rule from the admin event form; null = no self-service requests.
	 *
	 * @return array|null|\WP_Error {hours_before, fee_bp}
	 */
	public static function parse_policy( array $in ) {
		if ( empty( $in['refund_requests'] ) ) { return null; }
		$hours = is_scalar( $in['refund_hours_before'] ?? null ) ? trim( (string) $in['refund_hours_before'] ) : '';
		if ( ! preg_match( '/^\d{1,4}$/', $hours ) || (int) $hours > 8760 ) {
			return new \WP_Error( 'wpcap_refund_policy', __( 'Die Frist für Stornoanfragen ist ungültig (0 bis 8760 Stunden).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$fee = \PassCaptain\Admin\Forms::parse_percent_bp( '' === trim( (string) ( $in['refund_fee_percent'] ?? '' ) ) ? '0' : (string) $in['refund_fee_percent'] );
		if ( null === $fee ) {
			return new \WP_Error( 'wpcap_refund_policy', __( 'Die Bearbeitungsgebühr ist ungültig (0 bis 100 %).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		return array( 'hours_before' => (int) $hours, 'fee_bp' => (int) $fee );
	}

	/** Rule of an event row (null when off or not set). */
	public static function policy( $event ) {
		$raw = is_object( $event ) ? (string) ( $event->refund_policy ?? '' ) : '';
		$policy = '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $policy ) ? array( 'hours_before' => absint( $policy['hours_before'] ?? 0 ), 'fee_bp' => min( 10000, absint( $policy['fee_bp'] ?? 0 ) ) ) : null;
	}

	/**
	 * Tickets of the order that may be requested now: [ticket_id => {ticket row, event_id, policy,
	 * deadline (UTC)}]. Valid, not refunded, not checked in, no refund pending, before the deadline.
	 *
	 * @param bool $waive_policy Ignore rule and deadline (special cases, see class doc).
	 */
	public static function eligible( $order, $waive_policy = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $order || 'paid' !== $order->payment_status || in_array( (string) $order->payment_provider, array( 'woocommerce', 'comp' ), true ) ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.ticket_type_id,tt.name AS ticket_name,o.starts_at,o.timezone,o.status AS occurrence_status,e.event_id,e.name AS event_name,e.refund_policy FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.status='valid' AND t.refunded=0 ORDER BY t.ticket_id",
			$p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', (int) $order->order_id
		) );
		if ( '' !== (string) $wpdb->last_error || ! $rows ) { return array(); }
		$pending = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_ids FROM %i WHERE order_id=%d AND status IN ('pending_approval','approved','processing')", $p . 'wpcap_refunds', (int) $order->order_id ) ) as $list ) {
			foreach ( (array) json_decode( (string) $list, true ) as $id ) { $pending[ (int) $id ] = true; }
		}
		$checked_in = array_flip( Refunds::checked_in_ids( array_map( static function ( $r ) { return (int) $r->ticket_id; }, $rows ) ) );
		$now = time();
		$out = array();
		foreach ( $rows as $row ) {
			if ( isset( $pending[ (int) $row->ticket_id ] ) || isset( $checked_in[ (int) $row->ticket_id ] ) ) { continue; }
			$policy = self::policy( $row );
			if ( ! $waive_policy ) {
				if ( ! $policy ) { continue; }
				$deadline = strtotime( $row->starts_at . ' UTC' ) - $policy['hours_before'] * HOUR_IN_SECONDS;
				if ( $deadline <= $now ) { continue; }
			}
			$row->policy = $waive_policy ? array( 'hours_before' => 0, 'fee_bp' => 0 ) : $policy;
			$row->deadline = $waive_policy ? null : gmdate( 'Y-m-d H:i:s', strtotime( $row->starts_at . ' UTC' ) - $policy['hours_before'] * HOUR_IN_SECONDS );
			$out[ (int) $row->ticket_id ] = $row;
		}
		return $out;
	}

	/**
	 * Requests a refund for tickets of the order (all of one event rule; mixed fees are requested per
	 * event). The caller verified the order access token.
	 *
	 * @param int[] $ticket_ids Tickets (empty = all eligible).
	 * @param array $context    reason (text), waive_policy (bool, trusted callers only), key (idempotency).
	 * @return array|\WP_Error {refunds: [refund rows], tickets}
	 */
	public static function request( $order, array $ticket_ids, array $context = array() ) {
		$eligible = self::eligible( $order, ! empty( $context['waive_policy'] ) );
		$ids = $ticket_ids ? array_values( array_unique( array_map( 'absint', $ticket_ids ) ) ) : array_keys( $eligible );
		if ( ! $ids || array_diff( $ids, array_keys( $eligible ) ) ) {
			return new \WP_Error( 'wpcap_self_refund_not_allowed', __( 'Für diese Tickets ist keine Stornoanfrage (mehr) möglich.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$by_fee = array();
		foreach ( $ids as $id ) { $by_fee[ (int) $eligible[ $id ]->policy['fee_bp'] ][] = $id; }
		$reason = mb_substr( sanitize_text_field( (string) ( $context['reason'] ?? '' ) ), 0, 200 );
		$label = ! empty( $context['waive_policy'] ) ? __( 'Anfrage Käufer (Sonderfall)', 'pass-captain' ) : __( 'Stornoanfrage Käufer', 'pass-captain' );
		$refunds = array();
		foreach ( $by_fee as $fee_bp => $group ) {
			sort( $group );
			$key = 'self:' . ( $context['key'] ?? implode( ',', $group ) );
			$refund = Refunds::request( (int) $order->order_id, $group, $label . ( '' !== $reason ? ': ' . $reason : '' ), $key . ':' . $fee_bp, array( 'self_service' => true, 'deduct_bp' => (int) $fee_bp ) );
			if ( is_wp_error( $refund ) ) { return $refund; }
			$refunds[] = $refund;
		}
		Audit::record( 'self_service_refund_requested', 'order', (int) $order->order_id, array( 'tickets' => count( $ids ), 'refunds' => count( $refunds ), 'waived' => ! empty( $context['waive_policy'] ) ) );
		return array( 'refunds' => $refunds, 'tickets' => $ids );
	}

	/** Portal POST (form below): request for the checked tickets, then back to the portal. */
	public static function maybe_handle_portal_post( $order, $token ) {
		if ( 'POST' !== strtoupper( sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) || empty( $_POST['wpcap_self_refund'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the emailed access token is the capability.
		$ids = array_map( 'absint', (array) wp_unslash( $_POST['wpcap_refund_tickets'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint.
		$result = $ids ? self::request( $order, $ids, array( 'reason' => sanitize_text_field( wp_unslash( (string) ( $_POST['wpcap_refund_reason'] ?? '' ) ) ) ) ) : new \WP_Error( 'wpcap_self_refund_empty', '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_safe_redirect( add_query_arg( array( 'wpcap_order' => $order->public_id, 'wpcap_refund' => is_wp_error( $result ) ? 'error' : 'requested' ) + ( \PassCaptain\Security\AccessCookie::via_cookie() ? array() : array( 'wpcap_access' => $token ) ), home_url( '/' ) ), 303 );
		exit;
	}

	/** Portal card (filter wpcap_order_portal_sections). */
	public static function portal_section( $html, $order ) {
		$eligible = self::eligible( $order );
		$state = isset( $_GET['wpcap_refund'] ) ? sanitize_key( wp_unslash( (string) $_GET['wpcap_refund'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! $eligible && '' === $state ) { return $html; }
		$out = '<section class="card" aria-labelledby="wpcap-refund-title"><h2 id="wpcap-refund-title">' . esc_html__( 'Stornierung anfragen', 'pass-captain' ) . '</h2>';
		if ( 'requested' === $state ) { $out .= '<p role="status">' . esc_html__( 'Deine Anfrage wurde gesendet. Du erhältst eine E-Mail, sobald sie bearbeitet ist.', 'pass-captain' ) . '</p>'; }
		if ( 'error' === $state ) { $out .= '<p role="alert">' . esc_html__( 'Die Anfrage konnte nicht gesendet werden. Bitte wähle mindestens ein Ticket, für das eine Stornierung möglich ist.', 'pass-captain' ) . '</p>'; }
		if ( $eligible ) {
			$out .= '<form method="post"><input type="hidden" name="wpcap_self_refund" value="1"><fieldset><legend>' . esc_html__( 'Tickets', 'pass-captain' ) . '</legend>';
			foreach ( $eligible as $id => $row ) {
				/* translators: 1: event, 2: ticket type, 3: deadline */
				$label = sprintf( __( '%1$s – %2$s (bis %3$s)', 'pass-captain' ), $row->event_name, $row->ticket_name, \PassCaptain\Frontend\Format::event_start( (string) $row->deadline, (string) $row->timezone ) );
				$out .= '<label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" name="wpcap_refund_tickets[]" value="' . (int) $id . '"> ' . esc_html( $label ) . '</label>';
				if ( (int) $row->policy['fee_bp'] ) {
					/* translators: %s: percentage */
					$out .= '<p class="muted"><small>' . esc_html( sprintf( __( 'Bearbeitungsgebühr: %s %% des Ticketpreises', 'pass-captain' ), \PassCaptain\Admin\Forms::percent_input( (int) $row->policy['fee_bp'] ) ) ) . '</small></p>';
				}
			}
			$out .= '</fieldset><label>' . esc_html__( 'Grund (optional)', 'pass-captain' ) . '<input type="text" name="wpcap_refund_reason" maxlength="200"></label><p><button type="submit">' . esc_html__( 'Stornierung anfragen', 'pass-captain' ) . '</button></p><p class="muted"><small>' . esc_html__( 'Der Veranstalter prüft die Anfrage. Erst nach der Freigabe werden die Tickets ungültig und der Betrag erstattet.', 'pass-captain' ) . '</small></p></form>';
		}
		return $html . $out . '</section>';
	}
}
