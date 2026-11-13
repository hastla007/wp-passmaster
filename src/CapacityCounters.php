<?php
/**
 * Maintained sold/held counters for ticket types and occurrences (schema 3.7.0).
 *
 * Why: the capacity check in Orders::create() runs while the ticket type and occurrence rows are
 * locked FOR UPDATE. Summing every paid order line and every hold there made each checkout cost
 * linear in the tickets already sold, and every buyer of the occurrence queued behind that sum.
 *
 * Model:
 * - `sold_qty`  = Σ (quantity − refunded_quantity) of order lines whose order is paid and not
 *                 refunded – exactly what the old SUM counted.
 * - `held_qty`  = Σ quantity of reservations with status 'active' (whether or not their
 *                 expires_at has passed). Holds expire by time, not by a write, so the capacity
 *                 check subtracts the small set of active-but-lapsed holds (status 'active',
 *                 expires_at <= now; cleaned up every few minutes) with an indexed range query.
 *                 Net result: the same "held" as before (active and not expired).
 * - Every order line and reservation stores `counted_qty`, the units it currently contributes to
 *   the counters. sync_order() compares that with the value the row should contribute and applies
 *   the difference to the counters of its ticket type and occurrence. It is idempotent, so a
 *   writer that syncs twice, late or after a crash never double-counts.
 *
 * Correctness guards:
 * - Every write that ADDS usage (hold created, order paid, exchange moves a unit in) syncs inside
 *   the same transaction while the ticket type and occurrence rows are locked, so a concurrent
 *   capacity check never sees usage without its counter. Writes that only RELEASE usage (expiry,
 *   cancel, refund, revoke) sync right after their commit; until then the counter is higher than
 *   the truth, which can only under-sell for a moment, never over-sell.
 * - Lock order: order row → ticket types (by id) → occurrences (by id) → order lines/holds by
 *   primary key (same as Orders::create(), confirm and exchange).
 * - Safety net: orders touched by wpcap_order_paid / wpcap_capacity_released / refunds / revokes
 *   are re-synced at the end of the request; a cron (every five minutes, also `wp pass-captain
 *   reconcile-capacity`) re-syncs any line whose counted value disagrees with its order, repairs
 *   counters that disagree with their lines, logs it (audit log, error log, `wpcap_capacity_drift`,
 *   admin mail at most hourly) – a writer that forgot to sync is found within minutes.
 * - Upgrades: counters are rebuilt from the source tables per occurrence under the row locks
 *   (state "building"), then capacity decisions keep using the old SUM queries until a reconcile
 *   run at least ten minutes later finds no drift (state "verifying" → "ready"). Fresh installs
 *   start "ready". Filter `wpcap_capacity_counters_enabled` (false) forces the SUM path.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CapacityCounters {
	const OPTION = 'wpcap_capacity_counters';
	const HOOK = 'wpcap_reconcile_capacity';
	/** Seconds after a rebuild before a clean reconcile run switches decisions to the counters. */
	const VERIFY_AFTER = 600;
	/** Orders re-synced per reconcile run (the next run continues). */
	const BATCH = 500;

	/** Orders touched by hooks in this request (safety net, synced on shutdown). */
	private static $dirty = array();
	/** Tickets revoked in this request (resolved to orders on shutdown). */
	private static $dirty_tickets = array();

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_cron' ) );
		foreach ( array( 'wpcap_order_paid', 'wpcap_order_cancelled', 'wpcap_order_refunded', 'wpcap_capacity_released' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'mark_dirty' ), 99, 1 );
		}
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'mark_dirty_refund' ), 99, 2 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'mark_dirty_ticket' ), 99, 1 );
		add_action( 'shutdown', array( __CLASS__, 'flush_dirty' ), 5, 0 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'pass-captain reconcile-capacity', static function () {
				$report = self::reconcile();
				\WP_CLI::success( wp_json_encode( $report ) );
			} );
		}
	}

	/** Schedules the reconcile cron (every five minutes, `wpcap_five_minutes` from Communications). */
	public static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'wpcap_five_minutes', self::HOOK );
		}
	}

	/* ---------------------------------------------------------------------------------------------
	 * State.
	 * ------------------------------------------------------------------------------------------- */

	/** '' (columns missing / not initialised), 'building', 'verifying' or 'ready'. */
	public static function state() {
		$option = get_option( self::OPTION );
		if ( is_array( $option ) ) {
			return (string) ( $option['state'] ?? '' );
		}
		// No state stored (e.g. an install interrupted after dbDelta) but the columns exist: keep
		// the counters maintained, decide with the SUM queries; reconcile repairs and promotes.
		return self::columns_exist() ? 'verifying' : '';
	}

	/** Whether the counter columns exist (checked once per process and table prefix). */
	private static function columns_exist() {
		global $wpdb;
		static $known = array();
		$key = (string) $wpdb->prefix;
		if ( ! isset( $known[ $key ] ) ) {
			$known[ $key ] = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_ticket_types LIKE 'sold_qty'" );
		}
		return $known[ $key ];
	}

	/** Whether writers keep the counters up to date (columns exist and were initialised). */
	public static function maintained() {
		return in_array( self::state(), array( 'building', 'verifying', 'ready' ), true );
	}

	/**
	 * Counted units (CapacityCounters, schema 3.7.0) of ticket types: sold_qty + held_qty minus holds
	 * still marked active but lapsed, minus the units of $exclude_order_id. Null when the counters are
	 * not enabled (the SUM queries are used then).
	 *
	 * @param int[] $type_ids Ticket types.
	 */
	public static function used_for_types( array $type_ids, $exclude_order_id, $now ) {
		global $wpdb;
		if ( ! self::enabled() ) { return null; }
		$type_ids = array_values( array_filter( array_map( 'absint', $type_ids ) ) );
		if ( ! $type_ids ) { return 0; }
		$p = $wpdb->prefix;
		$in = implode( ',', $type_ids );
		$used = (int) $wpdb->get_var( "SELECT COALESCE(SUM(sold_qty+held_qty),0) FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$lapsed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND status='active' AND expires_at <= %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$own = 0;
		if ( $exclude_order_id ) {
			$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_order_items WHERE order_id=%d AND ticket_type_id IN ($in)", absint( $exclude_order_id ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(counted_qty),0) FROM {$p}wpcap_reservations WHERE order_id=%d AND ticket_type_id IN ($in) AND status='active' AND expires_at > %s", absint( $exclude_order_id ), $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return max( 0, $used - $lapsed - $own );
	}

	/** Whether capacity decisions and availability use the counters (else the SUM queries). */
	public static function enabled() {
		return 'ready' === self::state() && (bool) apply_filters( 'wpcap_capacity_counters_enabled', true );
	}

	private static function set_state( $state ) {
		update_option( self::OPTION, array( 'state' => (string) $state, 'since' => time() ) );
	}

	/**
	 * Called by Installer::install() after dbDelta. A fresh install (no order lines, no holds) is
	 * ready at once; an upgrade rebuilds the counters and waits for a clean reconcile run.
	 */
	public static function after_install() {
		global $wpdb;
		if ( 'ready' === self::state() ) {
			return;
		}
		$p = $wpdb->prefix;
		$has_data = $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_order_items LIMIT 1" ) || $wpdb->get_var( "SELECT 1 FROM {$p}wpcap_reservations LIMIT 1" );
		if ( ! $has_data ) {
			self::set_state( 'ready' );
			return;
		}
		self::set_state( 'building' );
		self::rebuild_all();
		self::set_state( 'verifying' );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Sync (writers).
	 * ------------------------------------------------------------------------------------------- */

	/** Units an order line should contribute to sold_qty. */
	public static function line_units( $order, $item ) {
		$counts = $order && 'paid' === $order->payment_status && 'refunded' !== $order->status;
		return $counts ? max( 0, (int) $item->quantity - (int) $item->refunded_quantity ) : 0;
	}

	/** Units a reservation should contribute to held_qty. */
	public static function hold_units( $reservation ) {
		return 'active' === $reservation->status ? (int) $reservation->quantity : 0;
	}

	/**
	 * Brings the counted units of an order's lines and holds in line with the order's state and
	 * applies the difference to the counters. Call INSIDE the caller's transaction, while the caller
	 * holds the order row lock (or inserted the order). Locks the affected ticket types and
	 * occurrences (by id) itself; rows already locked by the caller are not locked again.
	 *
	 * @param int        $order_id Order.
	 * @param int[]|null $only     Limit to these ticket types (e.g. exchange: old and target type).
	 * @return int|false Number of changed rows, false on a database error (caller rolls back).
	 */
	public static function sync_order( $order_id, $only = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		if ( ! $order_id || ! self::maintained() ) {
			return 0;
		}
		$only = null === $only ? null : array_map( 'intval', (array) $only );
		$keep = static function ( $row ) use ( $only ) {
			return null === $only || in_array( (int) $row->ticket_type_id, $only, true );
		};
		// The set of lines/holds of an order only grows inside create/exchange transactions, which
		// hold the order and type locks, so a plain read finds every row.
		$items = array_filter( (array) $wpdb->get_results( $wpdb->prepare( "SELECT item_id,ticket_type_id FROM {$p}wpcap_order_items WHERE order_id=%d", $order_id ) ), $keep );
		$holds = array_filter( (array) $wpdb->get_results( $wpdb->prepare( "SELECT reservation_id,ticket_type_id FROM {$p}wpcap_reservations WHERE order_id=%d", $order_id ) ), $keep );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		$type_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->ticket_type_id; }, array_merge( $items, $holds ) ) ) );
		if ( ! $type_ids ) {
			return 0;
		}
		sort( $type_ids, SORT_NUMERIC );
		$occ_of = self::lock( $type_ids );
		if ( false === $occ_of ) {
			return false;
		}
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT payment_status,status FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$deltas = array();
		$changed = 0;
		if ( $items ) {
			$rows = $wpdb->get_results( "SELECT item_id,ticket_type_id,quantity,refunded_quantity,counted_qty FROM {$p}wpcap_order_items WHERE item_id IN (" . self::ids( $items, 'item_id' ) . ') ORDER BY item_id FOR UPDATE' );
			foreach ( (array) $rows as $row ) {
				$want = self::line_units( $order, $row );
				if ( $want === (int) $row->counted_qty ) { continue; }
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET counted_qty=%d WHERE item_id=%d", $want, $row->item_id ) ) ) { return false; }
				$deltas[ (int) $row->ticket_type_id ]['sold'] = ( $deltas[ (int) $row->ticket_type_id ]['sold'] ?? 0 ) + $want - (int) $row->counted_qty;
				++$changed;
			}
		}
		if ( $holds ) {
			$rows = $wpdb->get_results( "SELECT reservation_id,ticket_type_id,quantity,status,counted_qty FROM {$p}wpcap_reservations WHERE reservation_id IN (" . self::ids( $holds, 'reservation_id' ) . ') ORDER BY reservation_id FOR UPDATE' );
			foreach ( (array) $rows as $row ) {
				$want = self::hold_units( $row );
				if ( $want === (int) $row->counted_qty ) { continue; }
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_reservations SET counted_qty=%d WHERE reservation_id=%d", $want, $row->reservation_id ) ) ) { return false; }
				$deltas[ (int) $row->ticket_type_id ]['held'] = ( $deltas[ (int) $row->ticket_type_id ]['held'] ?? 0 ) + $want - (int) $row->counted_qty;
				++$changed;
			}
		}
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		return false === self::add( $deltas, $occ_of ) ? false : $changed;
	}

	/**
	 * sync_order() in a transaction of its own, for writers that only release usage and already
	 * committed (cancel, refund, revoke, expiry) and for the safety net. Locks the order row first.
	 * Inside an open transaction (MariaDB reports it) it joins that transaction instead.
	 *
	 * @return int|false Changed rows.
	 */
	public static function sync_order_now( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id || ! self::maintained() ) {
			return 0;
		}
		$nested = true === Orders::transaction_alive();
		if ( ! $nested ) { $wpdb->query( 'START TRANSACTION' ); }
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d FOR UPDATE", $order_id ) );
		$result = '' === $wpdb->last_error ? self::sync_order( $order_id ) : false;
		if ( $nested ) {
			return $result;
		}
		if ( false === $result || false === Orders::transaction_alive() ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return false === $wpdb->query( 'COMMIT' ) ? false : $result;
	}

	/**
	 * Applies counter deltas. Caller holds the locks of the ticket types and occurrences.
	 *
	 * @param array $deltas ticket_type_id => {sold, held} (signed units).
	 * @param array $occ_of ticket_type_id => occurrence_id.
	 * @return bool
	 */
	public static function add( array $deltas, array $occ_of ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = array();
		ksort( $deltas );
		foreach ( $deltas as $type_id => $delta ) {
			$sold = (int) ( $delta['sold'] ?? 0 );
			$held = (int) ( $delta['held'] ?? 0 );
			if ( ! $sold && ! $held ) { continue; }
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET sold_qty=sold_qty+%d,held_qty=held_qty+%d WHERE ticket_type_id=%d", $sold, $held, $type_id ) ) ) { return false; }
			$occ_id = (int) ( $occ_of[ $type_id ] ?? 0 );
			if ( $occ_id ) {
				$occ[ $occ_id ]['sold'] = ( $occ[ $occ_id ]['sold'] ?? 0 ) + $sold;
				$occ[ $occ_id ]['held'] = ( $occ[ $occ_id ]['held'] ?? 0 ) + $held;
			}
		}
		ksort( $occ );
		foreach ( $occ as $occ_id => $delta ) {
			if ( ! $delta['sold'] && ! $delta['held'] ) { continue; }
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET sold_qty=sold_qty+%d,held_qty=held_qty+%d WHERE occurrence_id=%d", $delta['sold'], $delta['held'], $occ_id ) ) ) { return false; }
		}
		if ( $deltas ) { Orders::flush_availability_cache(); }
		return true;
	}

	/**
	 * Moves a ticket type to another occurrence (e.g. a festival pass re-anchored when its anchor
	 * date is cancelled) and moves its sold/held units between the occurrence counters in the same
	 * transaction. Locks the type, then both occurrences by id. Use this instead of updating
	 * ticket_types.occurrence_id directly.
	 *
	 * @return bool True when the type was moved.
	 */
	public static function move_type( $type_id, $from_occurrence_id, $to_occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$type_id = absint( $type_id );
		$from = absint( $from_occurrence_id );
		$to = absint( $to_occurrence_id );
		$nested = true === Orders::transaction_alive();
		if ( ! $nested ) { $wpdb->query( 'START TRANSACTION' ); }
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_ticket_types WHERE ticket_type_id=%d FOR UPDATE", $type_id ) );
		$occ_ids = array( $from, $to );
		sort( $occ_ids, SORT_NUMERIC );
		foreach ( array_unique( $occ_ids ) as $occ_id ) {
			$wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occ_id ) );
		}
		$ok = $type && (int) $type->occurrence_id === $from && $from !== $to && '' === $wpdb->last_error;
		if ( $ok ) {
			$ok = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET occurrence_id=%d WHERE ticket_type_id=%d AND occurrence_id=%d", $to, $type_id, $from ) );
		}
		if ( $ok && self::maintained() ) {
			$sold = (int) ( $type->sold_qty ?? 0 );
			$held = (int) ( $type->held_qty ?? 0 );
			$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET sold_qty=sold_qty-%d,held_qty=held_qty-%d WHERE occurrence_id=%d", $sold, $held, $from ) )
				&& false !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET sold_qty=sold_qty+%d,held_qty=held_qty+%d WHERE occurrence_id=%d", $sold, $held, $to ) );
		}
		if ( $nested ) {
			return $ok;
		}
		if ( ! $ok || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		Orders::flush_availability_cache();
		return true;
	}

	/**
	 * Locks ticket types and then their occurrences FOR UPDATE, both by id.
	 *
	 * @return array|false ticket_type_id => occurrence_id.
	 */
	private static function lock( array $type_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( "SELECT ticket_type_id,occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN (" . implode( ',', array_map( 'intval', $type_ids ) ) . ') ORDER BY ticket_type_id FOR UPDATE' );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		$occ_of = array();
		foreach ( (array) $rows as $row ) { $occ_of[ (int) $row->ticket_type_id ] = (int) $row->occurrence_id; }
		$occ_ids = array_values( array_unique( array_filter( $occ_of ) ) );
		if ( $occ_ids ) {
			sort( $occ_ids, SORT_NUMERIC );
			$wpdb->get_col( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE occurrence_id IN (" . implode( ',', $occ_ids ) . ') ORDER BY occurrence_id FOR UPDATE' );
			if ( '' !== $wpdb->last_error ) {
				return false;
			}
		}
		return $occ_of;
	}

	private static function ids( array $rows, $column ) {
		return implode( ',', array_map( static function ( $row ) use ( $column ) { return (int) $row->$column; }, $rows ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Safety net: orders touched by capacity hooks are re-synced at the end of the request.
	 * ------------------------------------------------------------------------------------------- */

	public static function mark_dirty( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id ) { self::$dirty[ $order_id ] = true; }
	}

	public static function mark_dirty_refund( $refund_id, $order_id = 0 ) {
		self::mark_dirty( $order_id );
	}

	public static function mark_dirty_ticket( $ticket_id ) {
		$ticket_id = absint( $ticket_id );
		if ( $ticket_id ) { self::$dirty_tickets[ $ticket_id ] = true; }
	}

	/** Shutdown: syncs every order a capacity hook reported in this request (no-op when in sync). */
	public static function flush_dirty() {
		global $wpdb;
		if ( ( ! self::$dirty && ! self::$dirty_tickets ) || ! self::maintained() ) {
			return;
		}
		if ( self::$dirty_tickets ) {
			$ids = implode( ',', array_map( 'intval', array_keys( self::$dirty_tickets ) ) );
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT order_id FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id IN ($ids)" ) as $order_id ) { self::mark_dirty( $order_id ); }
		}
		$orders = array_keys( self::$dirty );
		self::$dirty = array();
		self::$dirty_tickets = array();
		sort( $orders, SORT_NUMERIC );
		foreach ( $orders as $order_id ) { self::sync_order_now( $order_id ); }
	}

	/* ---------------------------------------------------------------------------------------------
	 * Verification, reconciliation and rebuild.
	 * ------------------------------------------------------------------------------------------- */

	/** SQL: units an order line should count (alias oi for the line, o for its order). */
	private static function line_units_sql() {
		return "IF(o.payment_status='paid' AND o.status<>'refunded',GREATEST(CAST(oi.quantity AS SIGNED)-oi.refunded_quantity,0),0)";
	}

	/**
	 * Counters that differ from the source tables, computed like the old SUM queries (paid,
	 * not refunded lines; active holds). Read-only; for tests, monitoring and the load test.
	 *
	 * @param int[] $occurrence_ids Limit to these occurrences (all when empty).
	 * @return array[] {kind (type|occurrence), id, sold_qty, sold_sum, held_qty, held_sum}
	 */
	public static function drift( array $occurrence_ids = array(), $limit = 100 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$where = $occurrence_ids ? ' AND tt.occurrence_id IN (' . implode( ',', array_map( 'intval', $occurrence_ids ) ) . ')' : '';
		$sold = "SELECT oi.ticket_type_id,SUM(CAST(oi.quantity AS SIGNED)-oi.refunded_quantity) AS v FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE o.payment_status='paid' AND o.status<>'refunded' GROUP BY oi.ticket_type_id";
		$held = "SELECT ticket_type_id,SUM(quantity) AS v FROM {$p}wpcap_reservations WHERE status='active' GROUP BY ticket_type_id";
		$per_type = "SELECT tt.ticket_type_id,tt.occurrence_id,tt.sold_qty,tt.held_qty,COALESCE(s.v,0) AS sold_sum,COALESCE(h.v,0) AS held_sum FROM {$p}wpcap_ticket_types tt LEFT JOIN ($sold) s ON s.ticket_type_id=tt.ticket_type_id LEFT JOIN ($held) h ON h.ticket_type_id=tt.ticket_type_id WHERE 1=1$where";
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM ($per_type) x WHERE sold_qty<>sold_sum OR held_qty<>held_sum LIMIT " . (int) $limit ) as $row ) {
			$out[] = array( 'kind' => 'type', 'id' => (int) $row->ticket_type_id, 'sold_qty' => (int) $row->sold_qty, 'sold_sum' => (int) $row->sold_sum, 'held_qty' => (int) $row->held_qty, 'held_sum' => (int) $row->held_sum );
		}
		$occ_where = $occurrence_ids ? ' WHERE oc.occurrence_id IN (' . implode( ',', array_map( 'intval', $occurrence_ids ) ) . ')' : '';
		$per_occ = "SELECT oc.occurrence_id,oc.sold_qty,oc.held_qty,COALESCE(SUM(x.sold_sum),0) AS sold_sum,COALESCE(SUM(x.held_sum),0) AS held_sum FROM {$p}wpcap_occurrences oc LEFT JOIN ($per_type) x ON x.occurrence_id=oc.occurrence_id$occ_where GROUP BY oc.occurrence_id,oc.sold_qty,oc.held_qty";
		foreach ( (array) $wpdb->get_results( "SELECT * FROM ($per_occ) y WHERE sold_qty<>sold_sum OR held_qty<>held_sum LIMIT " . (int) $limit ) as $row ) {
			$out[] = array( 'kind' => 'occurrence', 'id' => (int) $row->occurrence_id, 'sold_qty' => (int) $row->sold_qty, 'sold_sum' => (int) $row->sold_sum, 'held_qty' => (int) $row->held_qty, 'held_sum' => (int) $row->held_sum );
		}
		return $out;
	}

	/** Cron: expire lapsed holds (keeps the lapsed-hold correction small), then reconcile. */
	public static function run_cron() {
		Orders::expire_holds();
		return self::reconcile();
	}

	/**
	 * Recomputes from the source tables: re-syncs lines/holds whose counted units disagree with
	 * their order (a writer did not sync) and repairs counters that disagree with their lines.
	 * Any finding is logged and alerted. Also moves an upgraded install from "verifying" to
	 * "ready" after a clean run.
	 *
	 * @return array {state, resynced_orders, resynced_rows, repaired_occurrences, orders, occurrences}
	 */
	public static function reconcile() {
		global $wpdb;
		$p = $wpdb->prefix;
		$report = array( 'state' => self::state(), 'resynced_orders' => 0, 'resynced_rows' => 0, 'repaired_occurrences' => 0, 'orders' => array(), 'occurrences' => array() );
		if ( ! self::maintained() ) {
			return $report;
		}
		$limit = (int) self::BATCH;
		$orders = array_merge(
			(array) $wpdb->get_col( "SELECT DISTINCT oi.order_id FROM {$p}wpcap_order_items oi LEFT JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.counted_qty<>" . self::line_units_sql() . " LIMIT $limit" ),
			(array) $wpdb->get_col( "SELECT DISTINCT order_id FROM {$p}wpcap_reservations WHERE counted_qty<>IF(status='active',quantity,0) LIMIT $limit" )
		);
		$orders = array_values( array_unique( array_map( 'intval', $orders ) ) );
		sort( $orders, SORT_NUMERIC );
		foreach ( $orders as $order_id ) {
			$changed = self::sync_order_now( $order_id );
			if ( $changed ) {
				++$report['resynced_orders'];
				$report['resynced_rows'] += (int) $changed;
				$report['orders'][] = $order_id;
			}
		}
		// Counters against the units their lines and holds count (only differs after a bug or a
		// manual edit of the counter columns).
		$sums = "SELECT tt.ticket_type_id,tt.occurrence_id,tt.sold_qty,tt.held_qty,COALESCE((SELECT SUM(counted_qty) FROM {$p}wpcap_order_items oi WHERE oi.ticket_type_id=tt.ticket_type_id),0) AS sold_sum,COALESCE((SELECT SUM(counted_qty) FROM {$p}wpcap_reservations r WHERE r.ticket_type_id=tt.ticket_type_id),0) AS held_sum FROM {$p}wpcap_ticket_types tt";
		$bad = (array) $wpdb->get_col( "SELECT DISTINCT occurrence_id FROM ($sums) x WHERE sold_qty<>sold_sum OR held_qty<>held_sum" );
		$bad = array_merge( $bad, (array) $wpdb->get_col( "SELECT oc.occurrence_id FROM {$p}wpcap_occurrences oc LEFT JOIN ($sums) x ON x.occurrence_id=oc.occurrence_id GROUP BY oc.occurrence_id,oc.sold_qty,oc.held_qty HAVING oc.sold_qty<>COALESCE(SUM(x.sold_sum),0) OR oc.held_qty<>COALESCE(SUM(x.held_sum),0)" ) );
		$bad = array_values( array_unique( array_map( 'intval', $bad ) ) );
		sort( $bad, SORT_NUMERIC );
		foreach ( $bad as $occurrence_id ) {
			if ( false !== self::rebuild_occurrence( $occurrence_id ) ) {
				++$report['repaired_occurrences'];
				$report['occurrences'][] = $occurrence_id;
			}
		}
		$drift = $report['resynced_orders'] || $report['repaired_occurrences'];
		if ( $drift ) {
			self::alert( $report );
		}
		$option = get_option( self::OPTION );
		if ( 'verifying' === self::state() ) {
			if ( $drift ) {
				self::set_state( 'verifying' );
			} elseif ( time() - (int) ( $option['since'] ?? 0 ) >= (int) apply_filters( 'wpcap_capacity_verify_after', self::VERIFY_AFTER ) ) {
				self::set_state( 'ready' );
				Audit::record( 'capacity_counters_ready', 'system', 0, array() );
			}
		}
		$report['state'] = self::state();
		return $report;
	}

	private static function alert( array $report ) {
		Audit::record( 'capacity_counter_drift', 'system', 0, $report );
		error_log( 'WP Pass Captain: capacity counters drifted and were repaired: ' . wp_json_encode( $report ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		/**
		 * Capacity counters disagreed with the order/hold tables and were repaired. A writer changed
		 * order lines, holds or order states without CapacityCounters::sync_order().
		 *
		 * @param array $report {resynced_orders, resynced_rows, repaired_occurrences, orders, occurrences}
		 */
		do_action( 'wpcap_capacity_drift', $report );
		if ( apply_filters( 'wpcap_capacity_drift_alert', true, $report ) && ! get_transient( 'wpcap_capacity_drift_mail' ) ) {
			set_transient( 'wpcap_capacity_drift_mail', 1, HOUR_IN_SECONDS );
			wp_mail(
				get_option( 'admin_email' ),
				__( 'WP Pass Captain: Kontingentzähler korrigiert', 'wp-pass-captain' ),
				sprintf(
					/* translators: 1: number of orders, 2: number of occurrences, 3: order ids */
					__( 'Die Zähler für verkaufte und reservierte Tickets wichen von den Bestellungen ab und wurden korrigiert (%1$d Bestellungen, %2$d Termine; Bestellungen: %3$s). Bitte prüfen, welches Plugin oder welcher Ablauf Bestellungen direkt in der Datenbank ändert.', 'wp-pass-captain' ),
					(int) $report['resynced_orders'],
					(int) $report['repaired_occurrences'],
					implode( ', ', array_slice( (array) $report['orders'], 0, 20 ) )
				)
			);
		}
	}

	/** Rebuilds every occurrence's counters from the source tables (upgrade, load-test seeding). */
	public static function rebuild_all() {
		global $wpdb;
		$ok = true;
		foreach ( array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types ORDER BY occurrence_id" ) ) as $occurrence_id ) {
			$ok = false !== self::rebuild_occurrence( $occurrence_id ) && $ok;
		}
		return $ok;
	}

	/**
	 * Recomputes counted units of every line/hold of an occurrence's ticket types and the counters
	 * of the types and the occurrence, under the type and occurrence locks (by id). Values come from
	 * a consistent read taken after the locks: usage can only be added under these locks, and a
	 * release committed meanwhile is re-synced by its writer afterwards.
	 *
	 * @return int|false Rows changed.
	 */
	public static function rebuild_occurrence( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$wpdb->query( 'START TRANSACTION' );
		$type_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id FOR UPDATE", $occurrence_id ) ) );
		$wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE occurrence_id=%d FOR UPDATE", $occurrence_id ) );
		if ( '' !== $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$changed = 0;
		$sold = $held = array();
		if ( $type_ids ) {
			$in = implode( ',', $type_ids );
			$lines = $wpdb->get_results( "SELECT oi.item_id AS id,oi.counted_qty AS counted," . self::line_units_sql() . " AS want FROM {$p}wpcap_order_items oi LEFT JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id IN ($in) AND oi.counted_qty<>" . self::line_units_sql() );
			$holds = $wpdb->get_results( "SELECT reservation_id AS id,counted_qty AS counted,IF(status='active',quantity,0) AS want FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) AND counted_qty<>IF(status='active',quantity,0)" );
			if ( false === self::bulk_set( "{$p}wpcap_order_items", 'item_id', (array) $lines ) || false === self::bulk_set( "{$p}wpcap_reservations", 'reservation_id', (array) $holds ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$changed = count( (array) $lines ) + count( (array) $holds );
			foreach ( (array) $wpdb->get_results( "SELECT ticket_type_id,SUM(counted_qty) AS v FROM {$p}wpcap_order_items WHERE ticket_type_id IN ($in) GROUP BY ticket_type_id" ) as $row ) { $sold[ (int) $row->ticket_type_id ] = (int) $row->v; }
			foreach ( (array) $wpdb->get_results( "SELECT ticket_type_id,SUM(counted_qty) AS v FROM {$p}wpcap_reservations WHERE ticket_type_id IN ($in) GROUP BY ticket_type_id" ) as $row ) { $held[ (int) $row->ticket_type_id ] = (int) $row->v; }
			foreach ( $type_ids as $type_id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_ticket_types SET sold_qty=%d,held_qty=%d WHERE ticket_type_id=%d", $sold[ $type_id ] ?? 0, $held[ $type_id ] ?? 0, $type_id ) );
			}
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_occurrences SET sold_qty=%d,held_qty=%d WHERE occurrence_id=%d", array_sum( $sold ), array_sum( $held ), $occurrence_id ) );
		if ( '' !== $wpdb->last_error || false === Orders::transaction_alive() || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		Orders::flush_availability_cache();
		return $changed;
	}

	/** Sets counted_qty=want for rows {id, want} in chunks (one UPDATE … CASE per 500 rows). */
	private static function bulk_set( $table, $key, array $rows ) {
		global $wpdb;
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$case = '';
			$ids = array();
			foreach ( $chunk as $row ) {
				$case .= ' WHEN ' . (int) $row->id . ' THEN ' . max( 0, (int) $row->want );
				$ids[] = (int) $row->id;
			}
			if ( false === $wpdb->query( "UPDATE $table SET counted_qty=CASE $key$case ELSE counted_qty END WHERE $key IN (" . implode( ',', $ids ) . ')' ) ) {
				return false;
			}
		}
		return true;
	}
}
