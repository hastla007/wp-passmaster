<?php
/**
 * Diagnose and reconciliation checks for inconsistent data.
 *
 * Every check reports a count, sample rows and a suggested action. run() only reads (the report is
 * the preview); repair() exists only where the repair is idempotent and safe to repeat:
 * releasing expired holds, re-queueing stale queue claims, booking provider refunds that were
 * paid out but not booked (Refunds::reconcile(), never calls the provider), queueing ticket
 * issuance retries and retrying a failed schema upgrade. Every repair is written to the audit log.
 * Everything else (tickets without valid order, duplicate refunds, refunds stuck in
 * "processing" without provider reference) needs a human decision.
 *
 * Runs from the system status page (Admin\StatusPage) and via `wp pass-captain diagnose`.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

use PassCaptain\Ops\Status;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Diagnostics {
	/** Option holding the last report shown on the status page. */
	const REPORT_OPTION = 'wpcap_last_diagnostics';

	/** Sample rows per check. */
	const SAMPLES = 10;

	/** Expired holds are normal until the hourly cleanup ran; older ones mean it did not. */
	const HOLD_GRACE = 65 * MINUTE_IN_SECONDS;

	/** Paid orders get this long to receive their tickets (inline issuance or retry job) before they count. */
	const ISSUE_GRACE = 10 * MINUTE_IN_SECONDS;

	/** Check key => [label, repairable]. */
	public static function checks() {
		return array(
			'expired_holds'        => array( __( 'Abgelaufene, aber offene Reservierungen', 'pass-captain' ), true ),
			'paid_without_tickets' => array( __( 'Bezahlte Bestellungen ohne (alle) Tickets', 'pass-captain' ), true ),
			'tickets_without_order' => array( __( 'Gültige Tickets ohne gültige Bestellung', 'pass-captain' ), false ),
			'duplicate_refunds'    => array( __( 'Doppelte Erstattungen zur selben Anbieter-Referenz', 'pass-captain' ), false ),
			'stale_claims'         => array( __( 'Hängende Queue-Claims (Jobs, E-Mails, Webhooks, SMS)', 'pass-captain' ), true ),
			'unbooked_refunds'     => array( __( 'Ausgezahlte, aber nicht verbuchte Erstattungen', 'pass-captain' ), true ),
			'stuck_refunds'        => array( __( 'Erstattungen in „processing“ ohne Anbieter-Referenz', 'pass-captain' ), false ),
			'schema'               => array( __( 'Datenbankschema und Migrationen', 'pass-captain' ), true ),
		);
	}

	/**
	 * Runs all (or the given) checks. Read-only.
	 *
	 * @param string[] $only Check keys; empty = all.
	 * @return array {time, checks: key => {key, label, count, severity: ok|warning|error, samples, action, repairable, error}}
	 */
	public static function run( array $only = array() ) {
		$report = array( 'time' => time(), 'checks' => array() );
		foreach ( self::checks() as $key => list( $label, $repairable ) ) {
			if ( $only && ! in_array( $key, $only, true ) ) { continue; }
			$report['checks'][ $key ] = self::run_check( $key, $label, $repairable );
		}
		return $report;
	}

	/** Runs one check and stores the full report on the status page option. */
	public static function run_and_store() {
		$report = self::run();
		update_option( self::REPORT_OPTION, $report, false );
		return $report;
	}

	private static function run_check( $key, $label, $repairable ) {
		global $wpdb;
		$wpdb->last_error = '';
		list( $count, $samples, $severity, $action ) = call_user_func( array( __CLASS__, 'check_' . $key ) );
		$error = (string) $wpdb->last_error;
		return array(
			'key'        => $key,
			'label'      => $label,
			'count'      => (int) $count,
			'severity'   => '' !== $error ? 'error' : ( $count ? $severity : 'ok' ),
			'samples'    => array_map( static function ( $row ) { return (array) $row; }, array_slice( (array) $samples, 0, self::SAMPLES ) ),
			'action'     => $count ? $action : '',
			'repairable' => $repairable && $count > 0,
			'error'      => $error,
		);
	}

	private static function gmt( $offset_seconds = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() - (int) $offset_seconds );
	}

	/* ------------------------------------------------------------------ Checks */

	/** Active reservations/seat holds past their expiry and pending orders whose hold ran out. */
	public static function check_expired_holds() {
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = self::gmt( self::HOLD_GRACE );
		$reservations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='active' AND expires_at<%s", $p . 'wpcap_reservations', $cutoff ) );
		$seat_holds = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='active' AND expires_at<%s", $p . 'wpcap_seat_holds', $cutoff ) );
		$orders_sql = "FROM {$p}wpcap_orders ord WHERE ord.payment_status='pending' AND ord.created_at<%s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>=%s)";
		$orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $orders_sql", $cutoff, $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$samples = $wpdb->get_results( $wpdb->prepare( "SELECT r.reservation_id, r.order_id, r.ticket_type_id, r.quantity, r.expires_at FROM %i r WHERE r.status='active' AND r.expires_at<%s ORDER BY r.expires_at LIMIT %d", $p . 'wpcap_reservations', $cutoff, self::SAMPLES ) );
		if ( ! $samples ) {
			$samples = $wpdb->get_results( $wpdb->prepare( "SELECT ord.order_id, ord.created_at $orders_sql ORDER BY ord.order_id LIMIT %d", $cutoff, $cutoff, self::SAMPLES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array(
			$reservations + $seat_holds + $orders,
			$samples,
			'warning',
			/* translators: 1: reservations, 2: seat holds, 3: orders */
			sprintf( __( '%1$d Reservierungen, %2$d Sitzplatz-Holds und %3$d offene Bestellungen sind abgelaufen. Die stündliche Bereinigung lief offenbar nicht (WP-Cron prüfen). Reparatur gibt die Kontingente frei und lässt die Bestellungen ablaufen.', 'pass-captain' ), $reservations, $seat_holds, $orders ),
		);
	}

	/** Rows {order_id, public_id, missing, updated_at} of paid orders that miss tickets. */
	private static function orders_missing_tickets( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT ord.order_id, ord.public_id, ord.updated_at, SUM(GREATEST(0, CAST(oi.qty AS SIGNED) - COALESCE(t.n,0))) AS missing
			FROM %i ord
			INNER JOIN (SELECT order_id, ticket_type_id, SUM(quantity) AS qty FROM %i GROUP BY order_id, ticket_type_id) oi ON oi.order_id=ord.order_id
			LEFT JOIN (SELECT order_id, ticket_type_id, COUNT(*) AS n FROM %i GROUP BY order_id, ticket_type_id) t ON t.order_id=oi.order_id AND t.ticket_type_id=oi.ticket_type_id
			WHERE ord.payment_status=\'paid\' AND ord.updated_at<%s
			GROUP BY ord.order_id, ord.public_id, ord.updated_at HAVING missing>0 ORDER BY ord.order_id LIMIT %d', $p . 'wpcap_orders', $p . 'wpcap_order_items', $p . 'wpcap_tickets',
			self::gmt( self::ISSUE_GRACE ),
			max( 1, (int) $limit )
		) );
	}

	public static function check_paid_without_tickets() {
		$rows = self::orders_missing_tickets( 1000 );
		return array( count( $rows ), $rows, 'error', __( 'Die Ticketausstellung ist fehlgeschlagen oder hängt. Reparatur plant pro Bestellung einen Ausstellungs-Job ein (idempotent: es werden nur fehlende Tickets erzeugt und per E-Mail versendet).', 'pass-captain' ) );
	}

	/** Valid tickets whose order is missing or not (or no longer) paid. */
	public static function check_tickets_without_order() {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = "FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id WHERE t.status='valid' AND (ord.order_id IS NULL OR ord.payment_status NOT IN ('paid','paid_unallocated'))";
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no input.
		$samples = $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id, t.order_id, COALESCE(ord.payment_status,'missing') AS order_status $where ORDER BY t.ticket_id LIMIT %d", self::SAMPLES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( $count, $samples, 'error', __( 'Diese Tickets würden beim Einlass akzeptiert, obwohl die Bestellung nicht bezahlt ist. Bestellung prüfen und die Tickets unter „Tickets“ widerrufen (keine automatische Reparatur).', 'pass-captain' ) );
	}

	/** Several completed/processing refunds share one real provider reference. */
	public static function check_duplicate_refunds() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT provider_reference, COUNT(*) AS refunds, GROUP_CONCAT(refund_id ORDER BY refund_id) AS refund_ids, GROUP_CONCAT(DISTINCT order_id) AS order_ids, SUM(amount_minor) AS amount_minor FROM %i WHERE status IN ('completed','processing') AND provider_reference NOT IN ('','manual','external') GROUP BY provider_reference HAVING COUNT(*)>1 ORDER BY MIN(refund_id) LIMIT %d", $wpdb->prefix . 'wpcap_refunds', 1000 ) );
		return array( count( (array) $rows ), $rows, 'error', __( 'Mehrere Erstattungen verweisen auf dieselbe Rückzahlung beim Zahlungsanbieter. Beim Anbieter prüfen, ob doppelt ausgezahlt wurde, und die Buchungen manuell korrigieren.', 'pass-captain' ) );
	}

	public static function check_stale_claims() {
		$count = 0;
		$samples = array();
		foreach ( array_keys( Status::queues() ) as $queue ) {
			$s = Status::queue_summary( $queue );
			if ( $s && $s['stale_claims'] > 0 ) {
				$count += $s['stale_claims'];
				$samples[] = array( 'queue' => $queue, 'claims' => $s['stale_claims'] );
			}
		}
		return array( $count, $samples, 'warning', __( 'Einträge stecken seit über 15 Minuten in Bearbeitung; der Worker ist abgebrochen und seither nicht mehr gelaufen. Reparatur stellt sie zurück in die Queue (wie es der nächste Worker-Lauf auch täte).', 'pass-captain' ) );
	}

	public static function check_unbooked_refunds() {
		global $wpdb;
		$samples = $wpdb->get_results( $wpdb->prepare( "SELECT refund_id, order_id, amount_minor, currency, provider_reference, updated_at FROM %i WHERE status='processing' AND provider_reference<>'' ORDER BY refund_id LIMIT %d", $wpdb->prefix . 'wpcap_refunds', self::SAMPLES ) );
		return array( Refunds::unbooked_count(), $samples, 'error', __( 'Der Zahlungsanbieter hat ausgezahlt, die lokale Verbuchung fehlt. Reparatur verbucht sie (ohne erneuten Anbieteraufruf).', 'pass-captain' ) );
	}

	/** Rows of refunds stuck in processing without provider reference (manual review). */
	public static function stuck_refunds( $limit = 50 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT refund_id, order_id, amount_minor, currency, reason, error_message, updated_at FROM %i WHERE status='processing' AND provider_reference='' AND updated_at<%s ORDER BY refund_id LIMIT %d", $wpdb->prefix . 'wpcap_refunds', self::gmt( Status::STALE_CLAIM_AFTER ), max( 1, absint( $limit ) ) ) );
	}

	public static function check_stuck_refunds() {
		$rows = self::stuck_refunds( 1000 );
		return array( count( $rows ), $rows, 'error', __( 'Unklar, ob der Zahlungsanbieter ausgezahlt hat (Abbruch während des Anbieteraufrufs). Beim Anbieter prüfen und die Erstattung im Systemstatus manuell als erledigt oder fehlgeschlagen markieren.', 'pass-captain' ) );
	}

	public static function check_schema() {
		$error = get_option( Installer::ERROR_OPTION );
		$stored = (string) get_option( 'wpcap_db_version' );
		$samples = array();
		if ( is_array( $error ) && $error ) {
			$samples[] = array( 'missing' => implode( ', ', (array) ( $error['missing'] ?? array() ) ), 'errors' => implode( ' | ', (array) ( $error['errors'] ?? array() ) ), 'attempts' => (int) ( $error['attempts'] ?? 1 ), 'next_retry' => ! empty( $error['next_retry'] ) ? gmdate( 'Y-m-d H:i:s', (int) $error['next_retry'] ) : '' );
		}
		if ( Installer::version() !== $stored ) {
			$samples[] = array( 'stored_version' => $stored, 'expected_version' => Installer::version() );
		}
		return array( count( $samples ), $samples, 'error', __( 'Die Datenbank-Migration ist nicht abgeschlossen. Fehler im PHP-Fehlerprotokoll prüfen (Rechte, Speicherplatz, Engine); Reparatur startet die Aktualisierung sofort erneut.', 'pass-captain' ) );
	}

	/* ------------------------------------------------------------------ Repairs */

	/**
	 * Runs the idempotent repair of a check and records it in the audit log.
	 *
	 * @return array|\WP_Error {repaired: int, report: check result after the repair}
	 */
	public static function repair( $key ) {
		$checks = self::checks();
		if ( ! isset( $checks[ $key ] ) || ! $checks[ $key ][1] ) {
			return new \WP_Error( 'wpcap_diag_not_repairable', __( 'Für diese Prüfung gibt es keine automatische Reparatur.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$repaired = call_user_func( array( __CLASS__, 'repair_' . $key ) );
		if ( is_wp_error( $repaired ) ) { return $repaired; }
		Audit::record( 'ops_diagnostics_repair', 'system', 0, array( 'check' => $key, 'repaired' => (int) $repaired ) );
		Status::flush_issues();
		$after = self::run( array( $key ) );
		return array( 'repaired' => (int) $repaired, 'report' => $after['checks'][ $key ] );
	}

	public static function repair_expired_holds() {
		global $wpdb;
		$released = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired' WHERE status='active' AND expires_at<=%s", $wpdb->prefix . 'wpcap_seat_holds', current_time( 'mysql', true ) ) );
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='active' AND expires_at<=%s", $wpdb->prefix . 'wpcap_reservations', current_time( 'mysql', true ) ) );
		$total = 0;
		do {
			$expired = (int) Orders::expire_holds( 500 );
			$total += $expired;
		} while ( $expired >= 500 );
		return $released + $before + $total;
	}

	/** Queues one ticket_issue job per affected order (skipped while one is still open). */
	public static function repair_paid_without_tickets() {
		global $wpdb;
		$queued = 0;
		foreach ( self::orders_missing_tickets( 500 ) as $row ) {
			$order_id = (int) $row->order_id;
			$open = $wpdb->get_var( $wpdb->prepare( "SELECT job_id FROM %i WHERE job_type=%s AND status IN ('queued','running') AND payload=%s LIMIT 1", $wpdb->prefix . 'wpcap_jobs', Orders::ISSUE_JOB, wp_json_encode( array( 'order_id' => $order_id ) ) ) );
			if ( $open ) { continue; }
			if ( true === Jobs::enqueue( 'diagnostics-ticket-issue-' . $order_id . '-' . gmdate( 'YmdHi' ), Orders::ISSUE_JOB, array( 'order_id' => $order_id ) ) ) { ++$queued; }
		}
		return $queued;
	}

	/** Same re-queue statements the workers run for claims older than 15 minutes. */
	public static function repair_stale_claims() {
		global $wpdb;
		$requeued = 0;
		$stale = gmdate( 'Y-m-d H:i:s', time() - Status::STALE_CLAIM_AFTER );
		foreach ( Status::queues() as $q ) {
			if ( ! Status::table_exists( $q['table'] ) ) { continue; }
			$t = $wpdb->prefix . $q['table'];
			$requeued += (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status=%s,locked_at=NULL WHERE status=%s AND locked_at<%s', $t, $q['ready'], $q['claimed'], $stale ) );
		}
		return $requeued;
	}

	public static function repair_unbooked_refunds() {
		return Refunds::reconcile( 100 );
	}

	public static function repair_schema() {
		if ( ! get_option( Installer::ERROR_OPTION ) && Installer::version() === (string) get_option( 'wpcap_db_version' ) ) { return 0; } // Nothing to do.
		$result = Installer::retry_now();
		if ( null === $result ) {
			return new \WP_Error( 'wpcap_diag_schema_locked', __( 'Eine andere Anfrage aktualisiert die Datenbank gerade. Bitte gleich erneut versuchen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( $result ) {
			if ( function_exists( 'wp_next_scheduled' ) ) { Plugin::schedule_events(); } // Like Plugin::maybe_upgrade().
			return 1;
		}
		return new \WP_Error( 'wpcap_diag_schema_failed', __( 'Die Datenbank-Aktualisierung ist erneut fehlgeschlagen. Details stehen im PHP-Fehlerprotokoll.', 'pass-captain' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------ Manual refund review */

	/**
	 * Resolves a refund stuck in "processing" without provider reference after checking the
	 * provider manually. "completed": the provider did pay out; the refund is booked (tickets
	 * revoked, totals updated) with the given reference. "failed": nothing was paid out; the refund
	 * becomes failed and can be executed again from the order. Audited.
	 *
	 * @param int    $refund_id  Refund.
	 * @param string $resolution completed|failed.
	 * @param string $reference  Provider reference of the payout (completed) or note (failed).
	 * @return array|\WP_Error Refund row after the resolution.
	 */
	public static function resolve_stuck_refund( $refund_id, $resolution, $reference = '' ) {
		global $wpdb;
		$refund_id = absint( $refund_id );
		$reference = substr( sanitize_text_field( (string) $reference ), 0, 150 );
		if ( ! in_array( $resolution, array( 'completed', 'failed' ), true ) ) {
			return new \WP_Error( 'wpcap_diag_resolution', __( 'Ungültige Auswahl.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$refund = Refunds::get( $refund_id );
		if ( ! $refund || 'processing' !== $refund['status'] || '' !== (string) $refund['provider_reference'] ) {
			return new \WP_Error( 'wpcap_diag_refund_state', __( 'Die Erstattung wartet nicht (mehr) auf eine manuelle Prüfung.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		if ( 'completed' === $resolution ) {
			// A provider reference turns it into "paid out, not booked": reconcile() books it exactly once.
			$note = 'manual-review:' . ( '' !== $reference ? $reference : (string) $refund_id );
			$changed = $wpdb->update( "{$wpdb->prefix}wpcap_refunds", array( 'provider_reference' => $note, 'updated_at' => $now ), array( 'refund_id' => $refund_id, 'status' => 'processing', 'provider_reference' => '' ) );
		} else {
			$message = __( 'Manuell als fehlgeschlagen markiert (keine Auszahlung beim Anbieter).', 'pass-captain' ) . ( '' !== $reference ? ' ' . $reference : '' );
			$changed = $wpdb->update( "{$wpdb->prefix}wpcap_refunds", array( 'status' => 'failed', 'error_message' => substr( $message, 0, 255 ), 'updated_at' => $now ), array( 'refund_id' => $refund_id, 'status' => 'processing', 'provider_reference' => '' ) );
		}
		if ( 1 !== (int) $changed ) {
			return new \WP_Error( 'wpcap_diag_refund_state', __( 'Die Erstattung wartet nicht (mehr) auf eine manuelle Prüfung.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		Audit::record( 'refund_manual_resolution', 'order', (int) $refund['order_id'], array( 'refund_id' => $refund_id, 'resolution' => $resolution, 'reference' => $reference ) );
		if ( 'completed' === $resolution ) { Refunds::reconcile( 100 ); }
		Status::flush_issues();
		return Refunds::get( $refund_id );
	}
}
