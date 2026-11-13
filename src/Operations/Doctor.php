<?php
/**
 * Data diagnosis and reconciliation (A204): finds inconsistent data across orders, tickets, refunds,
 * holds, queues, counters, document numbers, service bookings and the audit log.
 *
 * Read-only by default: run() only reads. Repairs exist only where the correct state is unambiguous
 * and the plugin's own code would have produced it (release lapsed holds, rebuild capacity counters,
 * re-queue stale queue claims, revoke still-valid tickets of closed orders, finish service bookings of
 * ended or paid orders). Every repair has a preview (dry run, nothing written) and every executed repair
 * is audited (`doctor_repair`, plus the per-object entries of the reused code paths).
 *
 * Entry points: `wp pass-captain doctor` (WP-CLI) and the section "Datenkonsistenz" on the "Betrieb"
 * admin page. Documentation: docs/OPERATIONS.md.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Operations;

use PassCaptain\Audit;
use PassCaptain\CapacityCounters;
use PassCaptain\Invoices;
use PassCaptain\Orders;
use PassCaptain\Privacy;
use PassCaptain\ServiceBookings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Doctor {
	const CAP = 'manage_options';
	/** Examples listed per check. */
	const LIMIT = 50;
	/** Queue claims older than this are stale (the workers recover them after the same time). */
	const STALE_MINUTES = 15;
	/** Paid orders get this long to receive their tickets before they are reported. */
	const ISSUE_GRACE = 600;
	/** Most objects one repair run changes. */
	const MAX_REPAIR = 500;

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'pass-captain doctor', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Checks: id => label, severity (critical | warning), repair id ('' = report only), hint.
	 *
	 * @return array<string, array{label:string, severity:string, repair:string, hint:string}>
	 */
	public static function checks() {
		return array(
			'tickets_without_order'      => array(
				'label'    => __( 'Gültige Tickets ohne Bestellung', 'pass-captain' ),
				'severity' => 'critical',
				'repair'   => '',
				'hint'     => __( 'Die Bestellung wurde gelöscht oder nie gespeichert. Ticket prüfen und bei Bedarf in der Ticketliste sperren.', 'pass-captain' ),
			),
			'tickets_of_closed_orders'   => array(
				'label'    => __( 'Gültige Tickets stornierter, erstatteter oder abgelaufener Bestellungen (oder erstattete Tickets)', 'pass-captain' ),
				'severity' => 'critical',
				'repair'   => 'revoke_closed_order_tickets',
				'hint'     => __( 'Die Tickets hätten beim Storno bzw. bei der Erstattung gesperrt werden müssen.', 'pass-captain' ),
			),
			'paid_orders_without_tickets' => array(
				'label'    => __( 'Bezahlte Bestellungen ohne Tickets', 'pass-captain' ),
				'severity' => 'critical',
				'repair'   => '',
				'hint'     => __( 'Bestellung öffnen und die Tickets erneut senden; bei offenen Jobs zuerst die Hintergrundverarbeitung prüfen.', 'pass-captain' ),
			),
			'refunds_exceed_payment'     => array(
				'label'    => __( 'Erstattungen höher als die Zahlung', 'pass-captain' ),
				'severity' => 'critical',
				'repair'   => '',
				'hint'     => __( 'Mit dem Konto des Zahlungsanbieters abgleichen; zu viel erstattete Beträge lassen sich nicht automatisch korrigieren.', 'pass-captain' ),
			),
			'duplicate_provider_refunds' => array(
				'label'    => __( 'Doppelte Erstattungen mit derselben Anbieter-Referenz', 'pass-captain' ),
				'severity' => 'critical',
				'repair'   => '',
				'hint'     => __( 'Eine Erstattung des Anbieters wurde mehrfach verbucht. Im Dashboard des Anbieters prüfen, welche Buchung echt ist.', 'pass-captain' ),
			),
			'stale_queue_claims'         => array(
				'label'    => __( 'Hängende Einträge der Warteschlangen (Job, E-Mail, Webhook in Bearbeitung)', 'pass-captain' ),
				'severity' => 'warning',
				'repair'   => 'requeue_stale_claims',
				'hint'     => __( 'Ein Worker ist abgebrochen. Die Einträge werden erneut eingeplant (gleicher Idempotenzschlüssel).', 'pass-captain' ),
			),
			'expired_holds'              => array(
				'label'    => __( 'Reservierungen nach Ablauf noch aktiv', 'pass-captain' ),
				'severity' => 'warning',
				'repair'   => 'release_expired_holds',
				'hint'     => __( 'WP-Cron gibt abgelaufene Reservierungen normalerweise alle paar Minuten frei; läuft der Cron?', 'pass-captain' ),
			),
			'capacity_counter_drift'     => array(
				'label'    => __( 'Kontingentzähler weichen von Bestellungen und Reservierungen ab', 'pass-captain' ),
				'severity' => 'warning',
				'repair'   => 'rebuild_counters',
				'hint'     => __( 'Die Zähler werden aus Bestellungen und Reservierungen neu aufgebaut.', 'pass-captain' ),
			),
			'capacity_ticket_drift'      => array(
				'label'    => __( 'Bestellzeilen zählen weniger Plätze als gültige Tickets', 'pass-captain' ),
				'severity' => 'critical',
				'repair'   => '',
				'hint'     => __( 'Ein Platz wurde doppelt freigegeben (z. B. Erstattung nach Widerruf oder Umtausch); die Ticketart kann überverkauft werden. Bestellung und Tickets prüfen und das überzählige Ticket widerrufen oder erstatten.', 'pass-captain' ),
			),
			'document_number_gaps'       => array(
				'label'    => __( 'Lücken in Rechnungs- und Korrekturnummern', 'pass-captain' ),
				'severity' => 'warning',
				'repair'   => '',
				'hint'     => __( 'Nummern werden nie nachträglich vergeben. Lücke dokumentieren (z. B. abgebrochene Ausstellung) und mit der Steuerberatung klären.', 'pass-captain' ),
			),
			'service_bookings'           => array(
				'label'    => __( 'Servicebuchungen im widersprüchlichen Zustand', 'pass-captain' ),
				'severity' => 'warning',
				'repair'   => 'sync_service_bookings',
				'hint'     => __( 'Buchungen beendeter Bestellungen werden freigegeben, Buchungen bezahlter Bestellungen mit noch gültiger Reservierung bestätigt. Bezahlt nach Ablauf oder „ohne Platz“ ohne Erstattung: bitte von Hand prüfen.', 'pass-captain' ),
			),
			'audit_gaps'                 => array(
				'label'    => __( 'Fehlende Einträge im Audit-Log', 'pass-captain' ),
				'severity' => 'warning',
				'repair'   => '',
				'hint'     => __( 'Abgeschlossene Erstattungen, Rechnungen oder Korrekturen ohne Audit-Eintrag (nur innerhalb der Aufbewahrungsfrist des Audit-Logs prüfbar).', 'pass-captain' ),
			),
		);
	}

	/**
	 * Repairs: id => label and the check it resolves.
	 *
	 * @return array<string, array{label:string, check:string}>
	 */
	public static function repairs() {
		return array(
			'revoke_closed_order_tickets' => array( 'label' => __( 'Tickets beendeter Bestellungen sperren', 'pass-captain' ), 'check' => 'tickets_of_closed_orders' ),
			'requeue_stale_claims'        => array( 'label' => __( 'Hängende Einträge erneut einplanen', 'pass-captain' ), 'check' => 'stale_queue_claims' ),
			'release_expired_holds'       => array( 'label' => __( 'Abgelaufene Reservierungen freigeben', 'pass-captain' ), 'check' => 'expired_holds' ),
			'rebuild_counters'            => array( 'label' => __( 'Kontingentzähler neu aufbauen', 'pass-captain' ), 'check' => 'capacity_counter_drift' ),
			'sync_service_bookings'       => array( 'label' => __( 'Servicebuchungen an den Bestellstatus angleichen', 'pass-captain' ), 'check' => 'service_bookings' ),
		);
	}

	/**
	 * Runs the checks (read-only).
	 *
	 * @param string[] $only  Check ids (all when empty; unknown ids are ignored).
	 * @param int      $limit Examples per check.
	 * @return array<string, array{id:string, label:string, severity:string, count:int, items:array, repair:string, hint:string}>
	 */
	public static function run( array $only = array(), $limit = self::LIMIT ) {
		$limit = max( 1, min( 1000, (int) $limit ) );
		$out = array();
		foreach ( self::checks() as $id => $def ) {
			if ( $only && ! in_array( $id, $only, true ) ) { continue; }
			$result = call_user_func( array( __CLASS__, 'check_' . $id ), $limit );
			$out[ $id ] = array(
				'id'       => $id,
				'label'    => $def['label'],
				'severity' => $def['severity'],
				'count'    => (int) $result['count'],
				'items'    => array_slice( (array) $result['items'], 0, $limit ),
				'repair'   => $def['repair'],
				'hint'     => $def['hint'],
			);
		}
		return $out;
	}

	/** Number of findings of a run() result. */
	public static function total( array $report ) {
		return array_sum( array_column( $report, 'count' ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Checks. Each returns ['count' => int, 'items' => [ ['key' => 'type:id', 'detail' => string], … ]],
	 * newest objects first.
	 * ------------------------------------------------------------------------------------------- */

	private static function now() {
		return current_time( 'mysql', true );
	}

	private static function result( $count, array $items ) {
		return array( 'count' => (int) $count, 'items' => $items );
	}

	private static function check_tickets_without_order( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$from = "FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_orders o ON o.order_id=t.order_id WHERE o.order_id IS NULL AND t.status IN ('valid','checked_in')";
		$items = array();
		foreach ( (array) $wpdb->get_results( "SELECT t.ticket_id,t.order_id,t.status $from ORDER BY t.ticket_id DESC LIMIT " . (int) $limit ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragments.
			/* translators: 1: ticket id, 2: order id, 3: ticket status */
			$items[] = array( 'key' => 'ticket:' . (int) $row->ticket_id, 'detail' => sprintf( __( 'Ticket #%1$d (Status %3$s) verweist auf die fehlende Bestellung #%2$d', 'pass-captain' ), (int) $row->ticket_id, (int) $row->order_id, $row->status ) );
		}
		return self::result( $wpdb->get_var( "SELECT COUNT(*) $from" ), $items ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** SQL: still valid tickets of closed orders, or refunded tickets that were left valid. */
	private static function closed_order_tickets_from() {
		global $wpdb;
		$p = $wpdb->prefix;
		return "FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders o ON o.order_id=t.order_id WHERE t.status='valid' AND (o.status IN ('cancelled','refunded','expired') OR o.payment_status IN ('cancelled','refunded','expired') OR t.refunded=1)";
	}

	private static function check_tickets_of_closed_orders( $limit ) {
		global $wpdb;
		$from = self::closed_order_tickets_from();
		$items = array();
		foreach ( (array) $wpdb->get_results( "SELECT t.ticket_id,t.order_id,t.refunded,o.status,o.payment_status $from ORDER BY t.ticket_id DESC LIMIT " . (int) $limit ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items[] = array(
				'key'    => 'ticket:' . (int) $row->ticket_id,
				'detail' => (int) $row->refunded
					/* translators: 1: ticket id, 2: order id */
					? sprintf( __( 'Ticket #%1$d (Bestellung #%2$d) ist erstattet, aber noch gültig', 'pass-captain' ), (int) $row->ticket_id, (int) $row->order_id )
					/* translators: 1: ticket id, 2: order id, 3: order status, 4: payment status */
					: sprintf( __( 'Ticket #%1$d ist gültig, Bestellung #%2$d ist %3$s/%4$s', 'pass-captain' ), (int) $row->ticket_id, (int) $row->order_id, $row->status, $row->payment_status ),
			);
		}
		return self::result( $wpdb->get_var( "SELECT COUNT(*) $from" ), $items ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function check_paid_orders_without_tickets( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$from = $wpdb->prepare(
			"FROM {$p}wpcap_orders o WHERE o.payment_status='paid' AND o.status='complete' AND o.updated_at < %s AND EXISTS (SELECT 1 FROM {$p}wpcap_order_items oi WHERE oi.order_id=o.order_id AND oi.quantity > oi.refunded_quantity) AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_tickets t WHERE t.order_id=o.order_id)",
			gmdate( 'Y-m-d H:i:s', time() - self::ISSUE_GRACE )
		);
		$items = array();
		foreach ( (array) $wpdb->get_results( "SELECT o.order_id,o.public_id,o.updated_at $from ORDER BY o.order_id DESC LIMIT " . (int) $limit ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
			/* translators: 1: order id, 2: time of payment (UTC) */
			$items[] = array( 'key' => 'order:' . (int) $row->order_id, 'detail' => sprintf( __( 'Bestellung #%1$d ist seit %2$s (UTC) bezahlt, hat aber keine Tickets', 'pass-captain' ), (int) $row->order_id, $row->updated_at ) );
		}
		return self::result( $wpdb->get_var( "SELECT COUNT(*) $from" ), $items ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function check_refunds_exceed_payment( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Cash paid = amount due (orders.total_minor); refund rows hold the cash part only.
		$sub = "SELECT o.order_id,o.total_minor,o.currency,SUM(r.amount_minor) AS refunded,COUNT(*) AS refunds FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status IN ('completed','processing') GROUP BY o.order_id,o.total_minor,o.currency HAVING SUM(r.amount_minor) > o.total_minor";
		$items = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM ($sub) x ORDER BY order_id DESC LIMIT " . (int) $limit ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			/* translators: 1: order id, 2: refunded amount (minor units), 3: paid amount (minor units), 4: currency, 5: number of refunds */
			$items[] = array( 'key' => 'order:' . (int) $row->order_id, 'detail' => sprintf( __( 'Bestellung #%1$d: %5$d Erstattungen über %2$d, bezahlt %3$d (kleinste Einheit, %4$s)', 'pass-captain' ), (int) $row->order_id, (int) $row->refunded, (int) $row->total_minor, $row->currency, (int) $row->refunds ) );
		}
		return self::result( $wpdb->get_var( "SELECT COUNT(*) FROM ($sub) x" ), $items ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function check_duplicate_provider_refunds( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sub = "SELECT o.payment_provider,r.provider_reference,COUNT(*) AS n,GROUP_CONCAT(r.refund_id ORDER BY r.refund_id) AS refund_ids,MIN(r.order_id) AS order_id FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders o ON o.order_id=r.order_id WHERE r.status IN ('completed','processing') AND r.provider_reference<>'' GROUP BY o.payment_provider,r.provider_reference HAVING COUNT(*) > 1";
		$items = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM ($sub) x ORDER BY order_id DESC LIMIT " . (int) $limit ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			/* translators: 1: payment provider, 2: provider refund reference, 3: refund ids */
			$items[] = array( 'key' => 'refund:' . (int) strtok( (string) $row->refund_ids, ',' ), 'detail' => sprintf( __( '%1$s-Erstattung %2$s ist mehrfach verbucht (Erstattungen %3$s)', 'pass-captain' ), $row->payment_provider, $row->provider_reference, $row->refund_ids ) );
		}
		return self::result( $wpdb->get_var( "SELECT COUNT(*) FROM ($sub) x" ), $items ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Stale claims per queue: [queue => [ids]] (at most $limit per queue). */
	private static function stale_claims( $limit ) {
		global $wpdb;
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );
		$out = array();
		foreach ( Queues::available() as $queue => $def ) {
			$table = $wpdb->prefix . $def['table'];
			$where = $wpdb->prepare( 'status=%s AND locked_at IS NOT NULL AND locked_at < %s', $def['running'], $stale );
			$out[ $queue ] = array(
				'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table, prepared condition.
				'ids'   => array_map( 'intval', (array) $wpdb->get_col( "SELECT {$def['id']} FROM $table WHERE $where ORDER BY {$def['id']} DESC LIMIT " . (int) $limit ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		return $out;
	}

	private static function check_stale_queue_claims( $limit ) {
		$labels = Queues::labels();
		$count = 0;
		$items = array();
		foreach ( self::stale_claims( $limit ) as $queue => $found ) {
			$count += $found['count'];
			foreach ( $found['ids'] as $id ) {
				/* translators: 1: queue name, 2: item id, 3: minutes */
				$items[] = array( 'key' => $queue . ':' . $id, 'detail' => sprintf( __( '%1$s: Eintrag #%2$d ist seit über %3$d Minuten in Bearbeitung', 'pass-captain' ), $labels[ $queue ] ?? $queue, $id, self::STALE_MINUTES ) );
			}
		}
		return self::result( $count, $items );
	}

	private static function check_expired_holds( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = self::now();
		$count = 0;
		$items = array();
		$sources = array(
			// Ticket holds of orders.
			array( 'reservation', $wpdb->prepare( "FROM {$p}wpcap_reservations WHERE status='active' AND expires_at <= %s", $now ), 'reservation_id', 'order_id' ),
			// Seat holds.
			array( 'seat_hold', $wpdb->prepare( "FROM {$p}wpcap_seat_holds WHERE status='active' AND expires_at <= %s", $now ), 'hold_id', 'order_id' ),
			// Service booking holds without an order (bookings of an order end with the order).
			array( 'booking', $wpdb->prepare( "FROM {$p}wpcap_service_bookings WHERE status='held' AND order_id=0 AND expires_at IS NOT NULL AND expires_at <= %s", $now ), 'booking_id', 'order_id' ),
		);
		$names = array(
			'reservation' => __( 'Ticket-Reservierung', 'pass-captain' ),
			'seat_hold'   => __( 'Sitzplatz-Reservierung', 'pass-captain' ),
			'booking'     => __( 'Servicebuchung (reserviert)', 'pass-captain' ),
		);
		foreach ( $sources as $source ) {
			list( $type, $from, $id_col, $order_col ) = $source;
			$count += (int) $wpdb->get_var( "SELECT COUNT(*) $from" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
			foreach ( (array) $wpdb->get_results( "SELECT $id_col AS id,$order_col AS order_id,expires_at $from ORDER BY $id_col DESC LIMIT " . (int) $limit ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				/* translators: 1: kind of hold, 2: id, 3: order id, 4: expiry (UTC) */
				$items[] = array( 'key' => $type . ':' . (int) $row->id, 'detail' => sprintf( __( '%1$s #%2$d (Bestellung #%3$d) ist seit %4$s (UTC) abgelaufen', 'pass-captain' ), $names[ $type ], (int) $row->id, (int) $row->order_id, $row->expires_at ) );
			}
		}
		// Unpaid orders whose holds all lapsed (expire_holds() closes them).
		$orders = $wpdb->prepare( "FROM {$p}wpcap_orders o WHERE o.payment_status='pending' AND o.status='pending_payment' AND EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=o.order_id) AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_reservations r WHERE r.order_id=o.order_id AND r.status='active' AND r.expires_at > %s)", $now );
		$count += (int) $wpdb->get_var( "SELECT COUNT(*) $orders" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( "SELECT o.order_id $orders ORDER BY o.order_id DESC LIMIT " . (int) $limit ) as $order_id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			/* translators: %d: order id */
			$items[] = array( 'key' => 'order:' . (int) $order_id, 'detail' => sprintf( __( 'Unbezahlte Bestellung #%d hat keine gültige Reservierung mehr und ist noch nicht abgelaufen', 'pass-captain' ), (int) $order_id ) );
		}
		return self::result( $count, $items );
	}

	private static function check_capacity_counter_drift( $limit ) {
		if ( ! CapacityCounters::maintained() ) { return self::result( 0, array() ); }
		$items = array();
		$drift = CapacityCounters::drift( array(), max( $limit, 1000 ) );
		foreach ( array_slice( $drift, 0, $limit ) as $row ) {
			$items[] = array(
				'key'    => ( 'type' === $row['kind'] ? 'ticket_type:' : 'occurrence:' ) . (int) $row['id'],
				'detail' => 'type' === $row['kind']
					/* translators: 1: ticket type id, 2: counted sold, 3: actual sold, 4: counted held, 5: actual held */
					? sprintf( __( 'Ticketart #%1$d: verkauft %2$d statt %3$d, reserviert %4$d statt %5$d', 'pass-captain' ), $row['id'], $row['sold_qty'], $row['sold_sum'], $row['held_qty'], $row['held_sum'] )
					/* translators: 1: occurrence id, 2: counted sold, 3: actual sold, 4: counted held, 5: actual held */
					: sprintf( __( 'Termin #%1$d: verkauft %2$d statt %3$d, reserviert %4$d statt %5$d', 'pass-captain' ), $row['id'], $row['sold_qty'], $row['sold_sum'], $row['held_qty'], $row['held_sum'] ),
			);
		}
		return self::result( count( $drift ), $items );
	}

	private static function check_capacity_ticket_drift( $limit ) {
		$items = array();
		$drift = CapacityCounters::ticket_drift( array(), max( $limit, 1000 ) );
		foreach ( array_slice( $drift, 0, $limit ) as $row ) {
			$items[] = array(
				'key'    => 'order:' . (int) $row['order_id'] . ':type:' . (int) $row['ticket_type_id'],
				/* translators: 1: order id, 2: ticket type id, 3: tickets in use, 4: units counted by the order lines */
				'detail' => sprintf( __( 'Bestellung #%1$d, Ticketart #%2$d: %3$d gültige Tickets, aber nur %4$d gezählte Plätze', 'pass-captain' ), $row['order_id'], $row['ticket_type_id'], $row['tickets'], $row['units'] ),
			);
		}
		return self::result( count( $drift ), $items );
	}

	private static function check_document_number_gaps( $limit ) {
		$year = (int) gmdate( 'Y' );
		$gaps = Invoices::document_gaps( ( $year - 1 ) . '-01-01', $year . '-12-31' );
		$count = 0;
		$items = array();
		foreach ( $gaps as $range => $missing ) {
			$count += count( $missing );
			foreach ( $missing as $number ) {
				if ( count( $items ) >= $limit ) { break; }
				/* translators: 1: missing document number, 2: number range */
				$items[] = array( 'key' => 'number:' . $number, 'detail' => sprintf( __( 'Nummer %1$s fehlt im Nummernkreis %2$s', 'pass-captain' ), $number, $range ) );
			}
		}
		return self::result( $count, $items );
	}

	/**
	 * Service bookings that contradict their order: [kind => rows] with kinds
	 * closed (active booking of an ended or missing order), paid_active (order paid, booking still
	 * pending_payment with a valid hold), paid_lapsed (order paid, hold lapsed – manual check),
	 * unallocated (paid without a place and no refund).
	 */
	private static function booking_conflicts( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = self::now();
		$base = "FROM {$p}wpcap_service_bookings b LEFT JOIN {$p}wpcap_orders o ON o.order_id=b.order_id WHERE b.order_id>0";
		$kinds = array(
			'closed'      => "$base AND b.status IN ('held','pending_payment','confirmed') AND (o.order_id IS NULL OR o.status IN ('cancelled','refunded','expired'))",
			'paid_active' => $wpdb->prepare( "$base AND b.status='pending_payment' AND o.payment_status='paid' AND o.status='complete' AND (b.expires_at IS NULL OR b.expires_at > %s)", $now ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fragment.
			'paid_lapsed' => $wpdb->prepare( "$base AND b.status IN ('pending_payment','held','expired') AND o.payment_status='paid' AND o.status='complete' AND b.expires_at IS NOT NULL AND b.expires_at <= %s", $now ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'unallocated' => "$base AND b.status='unallocated' AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_refunds r WHERE r.order_id=b.order_id AND r.status<>'rejected')",
		);
		$out = array();
		foreach ( $kinds as $kind => $from ) {
			$out[ $kind ] = array(
				'count' => (int) $wpdb->get_var( "SELECT COUNT(*) $from" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'rows'  => (array) $wpdb->get_results( "SELECT b.booking_id,b.order_id,b.status,o.status AS order_status,o.payment_status $from ORDER BY b.booking_id DESC LIMIT " . (int) $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		return $out;
	}

	private static function check_service_bookings( $limit ) {
		$texts = array(
			/* translators: 1: booking id, 2: booking status, 3: order id, 4: order status */
			'closed'      => __( 'Buchung #%1$d ist %2$s, Bestellung #%3$d ist %4$s', 'pass-captain' ),
			/* translators: 1: booking id, 2: booking status, 3: order id, 4: order status */
			'paid_active' => __( 'Buchung #%1$d ist noch %2$s, Bestellung #%3$d ist bezahlt (%4$s)', 'pass-captain' ),
			/* translators: 1: booking id, 2: booking status, 3: order id, 4: order status */
			'paid_lapsed' => __( 'Buchung #%1$d (%2$s): Bestellung #%3$d (%4$s) wurde nach Ablauf der Reservierung bezahlt, Buchung nicht abgeschlossen – bitte prüfen', 'pass-captain' ),
			/* translators: 1: booking id, 2: booking status, 3: order id, 4: order status */
			'unallocated' => __( 'Buchung #%1$d ist %2$s, für Bestellung #%3$d (%4$s) gibt es keine Erstattung', 'pass-captain' ),
		);
		$count = 0;
		$items = array();
		foreach ( self::booking_conflicts( $limit ) as $kind => $found ) {
			$count += $found['count'];
			foreach ( $found['rows'] as $row ) {
				$items[] = array( 'key' => 'booking:' . (int) $row->booking_id, 'detail' => sprintf( $texts[ $kind ], (int) $row->booking_id, $row->status, (int) $row->order_id, (string) ( $row->order_status ?? '–' ) ), 'kind' => $kind ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			}
		}
		return self::result( $count, $items );
	}

	private static function check_audit_gaps( $limit ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// Only what the retention period of the audit log still covers.
		$since = (string) $wpdb->get_var( "SELECT MIN(created_at) FROM {$p}wpcap_audit_logs" );
		if ( '' === $since ) { return self::result( 0, array() ); }
		$sources = array(
			'refund'      => array(
				$wpdb->prepare( "FROM {$p}wpcap_refunds r WHERE r.status='completed' AND r.updated_at >= %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_audit_logs a WHERE a.action='refund_completed' AND a.object_type='order' AND a.object_id=r.order_id AND a.details LIKE CONCAT('%%\"refund_id\":',r.refund_id,',%%'))", $since ),
				'r.refund_id',
				/* translators: %d: refund id */
				__( 'Erstattung #%d ist abgeschlossen, im Audit-Log fehlt „refund_completed“', 'pass-captain' ),
			),
			'invoice'     => array(
				$wpdb->prepare( "FROM {$p}wpcap_invoices i WHERE i.issued_at >= %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_audit_logs a WHERE a.action='invoice_issued' AND a.object_type='invoice' AND a.object_id=i.invoice_id)", $since ),
				'i.invoice_id',
				/* translators: %d: invoice id */
				__( 'Rechnung #%d ist ausgestellt, im Audit-Log fehlt „invoice_issued“', 'pass-captain' ),
			),
			'credit_note' => array(
				$wpdb->prepare( "FROM {$p}wpcap_credit_notes c WHERE c.issued_at >= %s AND NOT EXISTS (SELECT 1 FROM {$p}wpcap_audit_logs a WHERE a.action='credit_note_issued' AND a.object_type='invoice' AND a.object_id=c.invoice_id AND a.details LIKE CONCAT('%%\"refund_id\":',c.refund_id,',%%'))", $since ),
				'c.credit_note_id',
				/* translators: %d: credit note id */
				__( 'Korrektur #%d ist ausgestellt, im Audit-Log fehlt „credit_note_issued“', 'pass-captain' ),
			),
		);
		$count = 0;
		$items = array();
		foreach ( $sources as $type => $source ) {
			list( $from, $id_col, $text ) = $source;
			$count += (int) $wpdb->get_var( "SELECT COUNT(*) $from" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
			foreach ( (array) $wpdb->get_col( "SELECT $id_col $from ORDER BY $id_col DESC LIMIT " . (int) $limit ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$items[] = array( 'key' => $type . ':' . (int) $id, 'detail' => sprintf( $text, (int) $id ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			}
		}
		return self::result( $count, $items );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Repairs.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Previews (dry run, default) or applies a repair.
	 *
	 * @param string   $repair  Repair id (see repairs()).
	 * @param bool     $dry_run True: only report what would change.
	 * @param string[] $keys    Limit to these finding keys ("ticket:12", "jobs:5", "booking:3"); empty = all.
	 *                          release_expired_holds and rebuild_counters always work on everything.
	 * @param string   $source  Who runs it (cli, admin) – stored in the audit entry.
	 * @return array|\WP_Error repair, dry_run, count, keys (affected finding keys), details.
	 */
	public static function repair( $repair, $dry_run = true, array $keys = array(), $source = 'cli' ) {
		$repairs = self::repairs();
		$repair = sanitize_key( (string) $repair );
		if ( ! isset( $repairs[ $repair ] ) ) {
			return new \WP_Error( 'wpcap_doctor_repair', __( 'Unbekannte Reparatur.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$keys = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $keys ) ) ) );
		$candidates = self::candidates( $repair, $keys );
		if ( $dry_run ) {
			return array( 'repair' => $repair, 'dry_run' => true, 'count' => count( $candidates ), 'keys' => $candidates, 'details' => array() );
		}
		$details = call_user_func( array( __CLASS__, 'apply_' . $repair ), $candidates );
		$changed = array_values( array_unique( array_map( 'strval', (array) ( $details['keys'] ?? array() ) ) ) );
		unset( $details['keys'] );
		Audit::record( 'doctor_repair', 'system', 0, array( 'repair' => $repair, 'source' => sanitize_key( $source ), 'count' => count( $changed ), 'keys' => array_slice( $changed, 0, 100 ), 'details' => $details ) );
		return array( 'repair' => $repair, 'dry_run' => false, 'count' => count( $changed ), 'keys' => $changed, 'details' => $details );
	}

	/** Finding keys a repair would change (from the same queries as the check, at most MAX_REPAIR). */
	private static function candidates( $repair, array $keys ) {
		$check = self::repairs()[ $repair ]['check'];
		$found = call_user_func( array( __CLASS__, 'check_' . $check ), self::MAX_REPAIR );
		$items = (array) $found['items'];
		if ( 'sync_service_bookings' === $repair ) {
			// Only bookings with an unambiguous outcome; lapsed paid and unallocated bookings stay for a person.
			$items = array_filter( $items, static function ( $item ) { return in_array( $item['kind'] ?? '', array( 'closed', 'paid_active' ), true ); } );
		}
		$out = array_values( array_unique( array_column( $items, 'key' ) ) );
		return $keys ? array_values( array_intersect( $out, $keys ) ) : $out;
	}

	/** Ids of the keys of one type ("ticket:12" → 12). */
	private static function ids_of( array $keys, $type ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, $type . ':' ) ) { $out[] = (int) substr( $key, strlen( $type ) + 1 ); }
		}
		return array_values( array_filter( $out ) );
	}

	private static function apply_revoke_closed_order_tickets( array $keys ) {
		global $wpdb;
		$ids = self::ids_of( $keys, 'ticket' );
		if ( ! $ids ) { return array( 'keys' => array() ); }
		// Re-check under the same condition right before the change (a ticket may have changed since the check).
		$ids = array_map( 'intval', (array) $wpdb->get_col( 'SELECT t.ticket_id ' . self::closed_order_tickets_from() . ' AND t.ticket_id IN (' . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		$revoked = Orders::revoke_ids( $ids );
		$by_order = array();
		foreach ( $revoked as $ticket_id ) {
			$by_order[ (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", $ticket_id ) ) ][] = $ticket_id;
		}
		foreach ( $by_order as $order_id => $ticket_ids ) {
			Audit::record( 'doctor_tickets_revoked', 'order', $order_id, array( 'ticket_ids' => $ticket_ids ) );
		}
		Orders::fire_revoked( $revoked, 'doctor' );
		return array( 'keys' => array_map( static function ( $id ) { return 'ticket:' . $id; }, $revoked ), 'orders' => array_keys( $by_order ) );
	}

	private static function apply_requeue_stale_claims( array $keys ) {
		global $wpdb;
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );
		$changed = array();
		foreach ( Queues::available() as $queue => $def ) {
			$ids = self::ids_of( $keys, $queue );
			if ( ! $ids ) { continue; }
			$table = $wpdb->prefix . $def['table'];
			foreach ( $ids as $id ) {
				// Same recovery as the workers: back to the waiting status, claim cleared; still stale only.
				$done = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status=%s,locked_at=NULL WHERE {$def['id']}=%d AND status=%s AND locked_at < %s", $def['queued'], $id, $def['running'], $stale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table/column names.
				if ( 1 === (int) $done ) { $changed[] = $queue . ':' . $id; }
			}
		}
		return array( 'keys' => $changed );
	}

	private static function apply_release_expired_holds( array $keys ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = self::now();
		// The cron's own routine: lapsed holds, counters, unpaid orders without a valid hold.
		$expired_orders = Orders::expire_holds( self::MAX_REPAIR );
		$seats = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_seat_holds SET status='expired' WHERE status='active' AND expires_at <= %s", $now ) );
		$bookings = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_service_bookings SET status='expired' WHERE status='held' AND order_id=0 AND expires_at IS NOT NULL AND expires_at <= %s", $now ) );
		$left = array_column( self::check_expired_holds( self::MAX_REPAIR )['items'], 'key' );
		return array( 'keys' => array_values( array_diff( $keys, $left ) ), 'expired_orders' => (int) $expired_orders, 'seat_holds' => $seats, 'service_bookings' => $bookings );
	}

	private static function apply_rebuild_counters( array $keys ) {
		$report = CapacityCounters::reconcile( true );
		$left = array_column( self::check_capacity_counter_drift( self::MAX_REPAIR )['items'], 'key' );
		return array( 'keys' => array_values( array_diff( $keys, $left ) ), 'state' => (string) ( $report['state'] ?? '' ), 'resynced_orders' => (int) ( $report['resynced_orders'] ?? 0 ), 'repaired_occurrences' => (int) ( $report['repaired_occurrences'] ?? 0 ) );
	}

	private static function apply_sync_service_bookings( array $keys ) {
		global $wpdb;
		$ids = self::ids_of( $keys, 'booking' );
		if ( ! $ids ) { return array( 'keys' => array() ); }
		$changed = array();
		$orders = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT order_id FROM {$wpdb->prefix}wpcap_service_bookings WHERE booking_id IN (" . implode( ',', $ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		foreach ( $orders as $order_id ) {
			$order = Orders::get( $order_id );
			if ( ! $order || in_array( $order->status, array( 'cancelled', 'refunded', 'expired' ), true ) ) {
				// Same as wpcap_order_cancelled/refunded: open bookings of an ended order are released.
				$released = $order ? ServiceBookings::release_order( $order_id ) : self::release_orphan_bookings( $order_id, $ids );
				foreach ( $released as $booking_id ) { $changed[] = 'booking:' . (int) $booking_id; }
			} elseif ( 'paid' === $order->payment_status ) {
				// Same as wpcap_order_paid, but only when every open booking of the order still has a valid hold:
				// a lapsed one could end "without place" with an automatic refund – that is left to a person.
				$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_bookings WHERE order_id=%d AND status IN ('held','pending_payment','expired') AND expires_at IS NOT NULL AND expires_at <= %s", $order_id, self::now() ) );
				if ( $lapsed ) { continue; }
				foreach ( ServiceBookings::confirm_paid_order( $order_id ) as $booking_id => $state ) { $changed[] = 'booking:' . (int) $booking_id; }
			}
		}
		return array( 'keys' => array_values( array_unique( $changed ) ) );
	}

	/** Bookings whose order row is missing: cancelled (audited like release_order()). */
	private static function release_orphan_bookings( $order_id, array $ids ) {
		global $wpdb;
		$out = array();
		foreach ( $ids as $booking_id ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_service_bookings SET status='cancelled',expires_at=NULL WHERE booking_id=%d AND order_id=%d AND status IN ('held','pending_payment','confirmed')", $booking_id, $order_id ) );
			if ( 1 !== (int) $done ) { continue; }
			$out[] = $booking_id;
			Audit::record( 'service_booking_released', 'service_booking', $booking_id, array( 'order_id' => (int) $order_id, 'order_status' => 'missing', 'status' => 'cancelled' ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------------------------------
	 * WP-CLI.
	 * ------------------------------------------------------------------------------------------- */

	/**
	 * Finds inconsistent data (read-only) and optionally repairs it.
	 *
	 * ## OPTIONS
	 *
	 * [--check=<ids>]
	 * : Comma-separated check ids (default: all).
	 *
	 * [--repair=<id>]
	 * : Repair to preview (dry run) or, with --apply, to execute.
	 *
	 * [--apply]
	 * : Execute the repair (audited). Without it the repair is only previewed.
	 *
	 * [--keys=<keys>]
	 * : Comma-separated finding keys the repair is limited to (e.g. ticket:12,jobs:5).
	 *
	 * [--limit=<n>]
	 * : Examples per check (default 50).
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * Exit code 1 when a check finds something (monitoring), 0 otherwise.
	 *
	 * @param array $args  Positional arguments (unused).
	 * @param array $assoc Options.
	 */
	public static function cli( $args = array(), $assoc = array() ) {
		$assoc = (array) $assoc;
		$json = 'json' === ( $assoc['format'] ?? 'table' );
		if ( ! empty( $assoc['repair'] ) ) {
			$keys = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['keys'] ?? '' ) ) ) );
			$result = self::repair( (string) $assoc['repair'], empty( $assoc['apply'] ), $keys, 'cli' );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return $result;
			}
			if ( $json ) {
				\WP_CLI::line( (string) wp_json_encode( $result ) );
			} else {
				foreach ( $result['keys'] as $key ) { \WP_CLI::line( '  ' . $key ); }
				$result['dry_run']
					/* translators: 1: repair id, 2: number of objects */
					? \WP_CLI::success( sprintf( __( 'Vorschau %1$s: %2$d Objekte würden geändert (nichts gespeichert; mit --apply ausführen).', 'pass-captain' ), $result['repair'], $result['count'] ) )
					/* translators: 1: repair id, 2: number of objects */
					: \WP_CLI::success( sprintf( __( 'Reparatur %1$s: %2$d Objekte geändert (im Audit-Log protokolliert).', 'pass-captain' ), $result['repair'], $result['count'] ) );
			}
			return $result;
		}
		$only = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['check'] ?? '' ) ) ) );
		$report = self::run( $only, (int) ( $assoc['limit'] ?? self::LIMIT ) );
		$total = self::total( $report );
		if ( $json ) {
			\WP_CLI::line( (string) wp_json_encode( array_values( $report ) ) );
		} else {
			foreach ( $report as $check ) {
				\WP_CLI::line( sprintf( '%-8s %-28s %6d  %s', $check['count'] ? strtoupper( $check['severity'] ) : 'OK', $check['id'], $check['count'], $check['label'] ) );
				foreach ( $check['items'] as $item ) { \WP_CLI::line( '         - [' . $item['key'] . '] ' . $item['detail'] ); }
				if ( $check['count'] && '' !== $check['repair'] ) {
					/* translators: %s: repair id */
					\WP_CLI::line( '         ' . sprintf( __( 'Reparatur: wp pass-captain doctor --repair=%s (Vorschau), dann mit --apply', 'pass-captain' ), $check['repair'] ) );
				}
			}
		}
		if ( $total ) {
			/* translators: %d: number of findings */
			\WP_CLI::warning( sprintf( __( '%d Auffälligkeiten gefunden.', 'pass-captain' ), $total ) );
			\WP_CLI::halt( 1 );
		} else {
			\WP_CLI::success( __( 'Keine Inkonsistenzen gefunden.', 'pass-captain' ) );
		}
		return $report;
	}
}
