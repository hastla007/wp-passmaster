<?php
/**
 * Reconciliation of voucher redemptions: Pass Captain's voucher snapshots (orders.voucher_snapshot:
 * reserved, committed, released, commit_failed, external; refund plans and booked voucher refunds)
 * against each other, the order state and – when available – the state Voucher Captain reports.
 *
 * The v1 provider contract (ProviderInterface) has no lookup of a redemption, so Voucher Captain's side
 * is only compared when something answers the optional filter `wpcap_voucher_reconcile_states` (not part
 * of the provider contract; a future Voucher Captain version or a site plugin can answer it). Without it
 * the report shows Pass Captain's side and the mismatch candidates that follow from it alone.
 *
 * Read-only: nothing is changed, provider calls are never made.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\Vouchers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class ReconciliationReport {
	/** Orders checked per run (newest first). */
	const LIMIT = 500;
	/** An unpaid order may keep its reservation this long before it counts as stale. */
	const PENDING_HOURS = 24;

	/**
	 * Builds the report.
	 *
	 * @param array $args limit (int), now (unix time, tests).
	 * @return array{checked:int, limit:int, provider:bool, summary:array<string,array{count:int,amount_minor:int,refunded_minor:int}>, issues:array<int,array{code:string,order_id:int,public_id:string,status:string,amount_minor:int,currency:string,detail:string}>}
	 */
	public static function report( array $args = array() ) {
		global $wpdb;
		$limit = max( 1, absint( $args['limit'] ?? self::LIMIT ) );
		$now = (int) ( $args['now'] ?? \PassCaptain\Clock::now() );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id,public_id,status,payment_status,currency,created_at,voucher_snapshot FROM %i WHERE voucher_snapshot IS NOT NULL AND voucher_snapshot<>'' ORDER BY order_id DESC LIMIT %d", $wpdb->prefix . 'wpcap_orders', $limit ) );
		$orders = array();
		foreach ( $rows as $row ) {
			$snapshot = json_decode( (string) $row->voucher_snapshot, true );
			if ( is_array( $snapshot ) ) { $orders[ (int) $row->order_id ] = array( $row, $snapshot ); }
		}
		$refunds = self::completed_refunds( array_keys( $orders ) );
		$provider = self::provider_states( $orders );
		$summary = array();
		$issues = array();
		foreach ( $orders as $order_id => list( $row, $snapshot ) ) {
			$status = (string) ( $snapshot['status'] ?? '' );
			$amount = (int) ( $snapshot['amount_minor'] ?? 0 );
			$refunded = (int) ( $snapshot['refunded_minor'] ?? 0 );
			if ( ! isset( $summary[ $status ] ) ) { $summary[ $status ] = array( 'count' => 0, 'amount_minor' => 0, 'refunded_minor' => 0 ); }
			++$summary[ $status ]['count'];
			$summary[ $status ]['amount_minor'] += $amount;
			$summary[ $status ]['refunded_minor'] += $refunded;
			$add = static function ( $code, $detail ) use ( &$issues, $order_id, $row, $status, $amount ) {
				$issues[] = array( 'code' => $code, 'order_id' => (int) $order_id, 'public_id' => (string) $row->public_id, 'status' => $status, 'amount_minor' => $amount, 'currency' => (string) $row->currency, 'detail' => (string) $detail );
			};
			foreach ( self::local_findings( $row, $snapshot, $refunds[ $order_id ] ?? array(), $now ) as $finding ) {
				$add( $finding[0], $finding[1] );
			}
			if ( null !== $provider && 'external' !== $status ) {
				$finding = self::provider_finding( $snapshot, $provider[ $order_id ] ?? null, (string) $row->currency );
				if ( $finding ) { $add( $finding[0], $finding[1] ); }
			}
		}
		ksort( $summary );
		return array(
			'checked'  => count( $orders ),
			'limit'    => $limit,
			'provider' => null !== $provider,
			'summary'  => $summary,
			'issues'   => $issues,
		);
	}

	/** Label of a voucher redemption status (snapshot or Voucher Captain); unknown codes stay as they are. */
	public static function voucher_status_label( $status ) {
		$labels = array(
			'reserved'           => _x( 'reserviert', 'voucher status', 'pass-captain' ),
			'committed'          => _x( 'belastet', 'voucher status', 'pass-captain' ),
			'released'           => _x( 'freigegeben', 'voucher status', 'pass-captain' ),
			'commit_failed'      => _x( 'Belastung fehlgeschlagen', 'voucher status', 'pass-captain' ),
			'refunded'           => _x( 'zurückgebucht', 'voucher status', 'pass-captain' ),
			'partially_refunded' => _x( 'teilweise zurückgebucht', 'voucher status', 'pass-captain' ),
			'external'           => _x( 'extern eingelöst', 'voucher status', 'pass-captain' ),
		);
		return $labels[ (string) $status ] ?? (string) $status;
	}

	/** Labels of the finding codes. */
	public static function labels() {
		return array(
			'reserved_paid'      => __( 'Bezahlt, Gutschein nur reserviert (Belastung steht aus)', 'pass-captain' ),
			'reserved_closed'    => __( 'Bestellung beendet, Reservierung nicht freigegeben', 'pass-captain' ),
			'reserved_stale'     => __( 'Unbezahlt, Reservierung älter als 24 Stunden', 'pass-captain' ),
			'paid_released'      => __( 'Bezahlt, Gutschein freigegeben (Rabatt ohne Belastung)', 'pass-captain' ),
			'commit_failed'      => __( 'Gutschein konnte nicht belastet werden', 'pass-captain' ),
			'committed_unpaid'   => __( 'Gutschein belastet, Bestellung nicht bezahlt', 'pass-captain' ),
			'reversal_failed'    => __( 'Gutschein-Rückbuchung fehlgeschlagen', 'pass-captain' ),
			'reversal_open'      => __( 'Gutschein-Rückbuchung offen', 'pass-captain' ),
			'reversal_waiting'   => __( 'Rückbuchung wartet auf die Belastung', 'pass-captain' ),
			'refund_over'        => __( 'Mehr zurückgebucht als eingelöst', 'pass-captain' ),
			'claim_stale'        => __( 'Abgebrochene Gutschein-Bearbeitung', 'pass-captain' ),
			'provider_missing'   => __( 'Bei Voucher Captain nicht gefunden', 'pass-captain' ),
			'provider_status'    => __( 'Status weicht von Voucher Captain ab', 'pass-captain' ),
			'provider_refunded'  => __( 'Rückbuchungsbetrag weicht von Voucher Captain ab', 'pass-captain' ),
		);
	}

	/**
	 * Mismatch candidates from Pass Captain's own data.
	 *
	 * @param object $row      Order row (status, payment_status, created_at).
	 * @param array  $snapshot Voucher snapshot.
	 * @param array  $refunds  Completed refund ids of the order.
	 * @return array<int, array{0:string,1:string}> [code, detail]
	 */
	public static function local_findings( $row, array $snapshot, array $refunds, $now ) {
		$out = array();
		$status = (string) ( $snapshot['status'] ?? '' );
		$payment = (string) $row->payment_status;
		$paid = in_array( $payment, array( 'paid', 'refunded', 'partially_refunded' ), true );
		$closed = in_array( (string) $row->status, array( 'cancelled', 'expired', 'refunded' ), true ) || in_array( $payment, array( 'cancelled', 'expired', 'refunded' ), true );
		$amount = (int) ( $snapshot['amount_minor'] ?? 0 );
		$booked = array_map( 'absint', (array) ( $snapshot['refund_ids'] ?? array() ) );
		$failed = array_map( 'absint', (array) ( $snapshot['failed_refund_ids'] ?? array() ) );
		$pending = array_map( 'absint', (array) ( $snapshot['pending_refund_ids'] ?? array() ) );
		if ( 'reserved' === $status ) {
			if ( 'paid' === $payment ) {
				$out[] = array( 'reserved_paid', __( 'Die Belastung wird über die Job-Warteschlange wiederholt (voucher_commit); bleibt sie aus, die Warteschlange prüfen.', 'pass-captain' ) );
			} elseif ( $closed ) {
				$out[] = array( 'reserved_closed', __( 'Die Freigabe ist fehlgeschlagen; die Reservierung bei Voucher Captain prüfen und dort freigeben.', 'pass-captain' ) );
			} elseif ( strtotime( (string) $row->created_at . ' UTC' ) < $now - self::PENDING_HOURS * HOUR_IN_SECONDS ) {
				$out[] = array( 'reserved_stale', __( 'Die Bestellung ist noch offen; läuft sie ab, wird die Reservierung freigegeben.', 'pass-captain' ) );
			}
		}
		if ( 'released' === $status && 'paid' === $payment ) {
			$out[] = array( 'paid_released', __( 'Der offene Betrag enthält den Gutscheinabzug, der Gutschein wurde aber nicht belastet. Gutschein bei Voucher Captain prüfen und manuell belasten oder den Betrag nachfordern.', 'pass-captain' ) );
		}
		if ( 'commit_failed' === $status ) {
			/* translators: %s: error code */
			$out[] = array( 'commit_failed', sprintf( __( 'Fehler: %s. Betrag beim Kunden nachfordern oder den Gutschein manuell belasten.', 'pass-captain' ), (string) ( $snapshot['error'] ?? '–' ) ) );
		}
		if ( 'committed' === $status && ! $paid && ! in_array( $payment, array( 'paid_unallocated' ), true ) ) {
			/* translators: %s: payment status label (e.g. "Ausstehend") */
			$out[] = array( 'committed_unpaid', sprintf( __( 'Zahlungsstatus: %s. Gutschein bei Voucher Captain zurückbuchen, falls die Bestellung nicht bezahlt wird.', 'pass-captain' ), \PassCaptain\Admin\Forms::status_label( $payment, 'order' ) ) );
		}
		if ( $failed ) {
			/* translators: %s: refund ids */
			$out[] = array( 'reversal_failed', sprintf( __( 'Erstattung(en) %s: Der Gutscheinanteil wird wiederholt (voucher_refund) bzw. ist manuell zurückzubuchen.', 'pass-captain' ), '#' . implode( ', #', $failed ) ) );
		}
		if ( $pending && 'reserved' === $status ) {
			/* translators: %s: refund ids */
			$out[] = array( 'reversal_waiting', sprintf( __( 'Erstattung(en) %s: Der Gutscheinanteil wird nach der Belastung zurückgebucht.', 'pass-captain' ), '#' . implode( ', #', $pending ) ) );
		}
		if ( 'committed' === $status ) {
			$open = array();
			$plans = (array) ( $snapshot['refund_plans'] ?? array() );
			foreach ( $refunds as $refund_id ) {
				if ( in_array( (int) $refund_id, $booked, true ) || in_array( (int) $refund_id, $failed, true ) ) { continue; }
				$plan = $plans[ (string) (int) $refund_id ] ?? null;
				// Without a stored plan the share is not known yet (computed when the refund is processed).
				if ( null === $plan || (int) ( $plan['voucher_minor'] ?? 0 ) > 0 ) { $open[] = (int) $refund_id; }
			}
			if ( $pending ) { $open = array_values( array_unique( array_merge( $open, $pending ) ) ); }
			if ( $open ) {
				/* translators: %s: refund ids */
				$out[] = array( 'reversal_open', sprintf( __( 'Erstattung(en) %s: Gutscheinanteil noch nicht zurückgebucht.', 'pass-captain' ), '#' . implode( ', #', $open ) ) );
			}
		}
		if ( $amount > 0 && (int) ( $snapshot['refunded_minor'] ?? 0 ) > $amount ) {
			$out[] = array( 'refund_over', __( 'Die Summe der Rückbuchungen ist größer als der eingelöste Betrag.', 'pass-captain' ) );
		}
		if ( ! empty( $snapshot['claim']['token'] ) && (int) ( $snapshot['claim']['at'] ?? 0 ) <= $now - Checkout::CLAIM_TTL ) {
			/* translators: %s: operation (commit, release, reserve) */
			$out[] = array( 'claim_stale', sprintf( __( 'Vorgang „%s“ wurde nicht abgeschlossen; der nächste Versuch übernimmt ihn.', 'pass-captain' ), sanitize_key( (string) ( $snapshot['claim']['op'] ?? '' ) ) ) );
		}
		return $out;
	}

	/**
	 * Compares Pass Captain's snapshot with Voucher Captain's state of the same redemption.
	 *
	 * @param array      $snapshot Voucher snapshot.
	 * @param array|null $state    status ('reserved'|'committed'|'released'|'refunded'), refunded_minor; null = unknown there.
	 * @param string     $currency Order currency (amounts in the detail text; '' = snapshot currency or default currency).
	 * @return array{0:string,1:string}|null
	 */
	public static function provider_finding( array $snapshot, $state, $currency = '' ) {
		$status = (string) ( $snapshot['status'] ?? '' );
		if ( ! is_array( $state ) ) {
			return in_array( $status, array( 'reserved', 'committed' ), true ) ? array( 'provider_missing', __( 'Voucher Captain kennt diese Einlösung nicht.', 'pass-captain' ) ) : null;
		}
		$theirs = sanitize_key( (string) ( $state['status'] ?? '' ) );
		$amount = (int) ( $snapshot['amount_minor'] ?? 0 );
		$refunded = (int) ( $snapshot['refunded_minor'] ?? 0 );
		// States of Voucher Captain that agree with Pass Captain's state.
		$agree = array(
			'reserved'      => array( 'reserved' ),
			'released'      => array( 'released' ),
			// Not charged: anything but a charge agrees.
			'commit_failed' => array( 'reserved', 'released', '' ),
			'committed'     => 0 === $refunded ? array( 'committed' ) : ( $refunded < $amount ? array( 'committed', 'partially_refunded', 'refunded' ) : array( 'committed', 'refunded' ) ),
		);
		if ( ! in_array( $theirs, $agree[ $status ] ?? array( $status ), true ) ) {
			/* translators: 1: voucher status at Pass Captain, 2: voucher status at Voucher Captain (e.g. "belastet") */
			return array( 'provider_status', sprintf( __( 'Pass Captain: %1$s, Voucher Captain: %2$s.', 'pass-captain' ), self::voucher_status_label( $status ), '' !== $theirs ? self::voucher_status_label( $theirs ) : '–' ) );
		}
		if ( 'committed' === $status && isset( $state['refunded_minor'] ) && (int) $state['refunded_minor'] !== $refunded ) {
			$currency = '' !== (string) $currency ? (string) $currency : (string) ( $snapshot['currency'] ?? \PassCaptain\Localization::default_currency() );
			/* translators: 1: amount refunded at Pass Captain, 2: amount refunded at Voucher Captain (formatted with currency) */
			return array( 'provider_refunded', sprintf( __( 'Zurückgebucht laut Pass Captain: %1$s, laut Voucher Captain: %2$s.', 'pass-captain' ), \PassCaptain\Money::format( $refunded, $currency ), \PassCaptain\Money::format( (int) $state['refunded_minor'], $currency ) ) );
		}
		return null;
	}

	/**
	 * Voucher Captain's state of the listed redemptions through the optional filter
	 * `wpcap_voucher_reconcile_states( null, $redemptions )`; null when nobody answers.
	 *
	 * @param array $orders order_id => [row, snapshot].
	 * @return array<int, array>|null order_id => state
	 */
	private static function provider_states( array $orders ) {
		$redemptions = array();
		foreach ( $orders as $order_id => list( $row, $snapshot ) ) {
			if ( 'external' === ( $snapshot['status'] ?? '' ) ) { continue; }
			$redemptions[ (int) $order_id ] = array(
				'order_public_id' => (string) $row->public_id,
				'status'          => (string) ( $snapshot['status'] ?? '' ),
				'reservation'     => (array) ( $snapshot['reservation'] ?? array() ),
				'redemption'      => is_array( $snapshot['redemption'] ?? null ) ? $snapshot['redemption'] : array(),
				'amount_minor'    => (int) ( $snapshot['amount_minor'] ?? 0 ),
			);
		}
		if ( ! $redemptions ) { return null; }
		/**
		 * Optional (not part of the v1 provider contract): Voucher Captain's state of redemptions for the
		 * reconciliation report. Return an array order_id => ['status' => 'reserved'|'committed'|'released'|
		 * 'refunded', 'refunded_minor' => int] (orders it does not know are left out), or null when the
		 * state cannot be looked up.
		 *
		 * @param array|null $states      null.
		 * @param array      $redemptions order_id => order_public_id, status, reservation, redemption, amount_minor.
		 */
		$states = apply_filters( 'wpcap_voucher_reconcile_states', null, $redemptions );
		if ( ! is_array( $states ) ) { return null; }
		$out = array();
		foreach ( $states as $order_id => $state ) {
			if ( is_array( $state ) ) { $out[ absint( $order_id ) ] = $state; }
		}
		return $out;
	}

	/**
	 * Completed refunds per order.
	 *
	 * @param int[] $order_ids
	 * @return array<int, int[]>
	 */
	private static function completed_refunds( array $order_ids ) {
		global $wpdb;
		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( ! $order_ids ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT refund_id,order_id FROM %i WHERE status='completed' AND order_id IN (" . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ') ORDER BY refund_id', array_merge( array( $wpdb->prefix . 'wpcap_refunds' ), $order_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer placeholders.
		$out = array();
		foreach ( $rows as $row ) { $out[ (int) $row->order_id ][] = (int) $row->refund_id; }
		return $out;
	}
}
