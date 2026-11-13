<?php
/**
 * Central transition tables of the stateful entities (see docs/STATE-MACHINES.md).
 *
 * Every status change of an order, refund, job, exchange or payment event is written as a
 * conditional UPDATE ("... WHERE id=? AND status IN (<allowed sources>)"), so a stale read, a
 * duplicate or an out-of-order event can never move a row backwards or out of a terminal state:
 * the UPDATE simply changes no row and the caller treats that as "not applicable".
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StateMachine {
	/** Allowed transitions: entity => [ from => [ to, ... ] ]. A state without targets is terminal. */
	const TRANSITIONS = array(
		'order'         => array(
			'pending_payment' => array( 'complete', 'needs_review', 'cancelled', 'expired' ),
			'expired'         => array( 'complete', 'needs_review', 'cancelled' ),
			'needs_review'    => array( 'complete', 'cancelled', 'refunded' ),
			'complete'        => array( 'cancelled', 'refunded' ),
			'cancelled'       => array( 'refunded' ),
			'refunded'        => array(),
		),
		'refund'        => array(
			'pending_approval' => array( 'approved', 'rejected' ),
			'approved'         => array( 'processing', 'failed' ),
			'failed'           => array( 'processing', 'failed' ),
			'processing'       => array( 'completed', 'failed' ),
			'completed'        => array(),
			'rejected'         => array(),
		),
		'job'           => array(
			'queued'      => array( 'running' ),
			'running'     => array( 'complete', 'queued', 'dead_letter' ),
			'dead_letter' => array( 'queued' ),
			'complete'    => array(),
		),
		'exchange'      => array(
			'pending_payment' => array( 'completed', 'expired', 'cancelled', 'needs_review' ),
			// A supplement paid late (after its hold lapsed) still completes or flags the exchange.
			'expired'         => array( 'completed', 'needs_review' ),
			'cancelled'       => array( 'completed', 'needs_review' ),
			'completed'       => array(),
			'needs_review'    => array(),
		),
		'payment_event' => array(
			'processing' => array( 'processed' ),
			'processed'  => array(),
		),
	);

	/** Whether $from → $to is an allowed transition of $entity. */
	public static function allowed( $entity, $from, $to ) {
		return in_array( (string) $to, self::TRANSITIONS[ $entity ][ (string) $from ] ?? array(), true );
	}

	/** States from which $entity may move to $to. */
	public static function sources( $entity, $to ) {
		$sources = array();
		foreach ( self::TRANSITIONS[ $entity ] ?? array() as $from => $targets ) {
			if ( in_array( (string) $to, $targets, true ) ) { $sources[] = $from; }
		}
		return $sources;
	}

	/** Whether $state is terminal (no outgoing transition). */
	public static function is_terminal( $entity, $state ) {
		return isset( self::TRANSITIONS[ $entity ][ (string) $state ] ) && ! self::TRANSITIONS[ $entity ][ (string) $state ];
	}

	/**
	 * SQL condition "status IN (...)" with the allowed sources of $to (column name optional).
	 * Returns "1=0" when no state may move to $to.
	 */
	public static function source_sql( $entity, $to, $column = 'status' ) {
		$sources = self::sources( $entity, $to );
		if ( ! $sources ) { return '1=0'; }
		return $column . " IN ('" . implode( "','", array_map( 'sanitize_key', $sources ) ) . "')";
	}

	/**
	 * Conditional status change: UPDATE $table SET status=$to[, $set] WHERE $id_column=$id AND status IN (sources)[ AND $where].
	 *
	 * @param string $entity    Key of TRANSITIONS.
	 * @param string $table     Full table name.
	 * @param string $id_column Primary key column.
	 * @param int    $id        Row id.
	 * @param string $to        Target status.
	 * @param array  $set       Further columns to set (column => scalar|null).
	 * @param array  $where     Further equality conditions (column => scalar).
	 * @return int|false Changed rows (0 = transition not applicable), false on a database error.
	 */
	public static function transition( $entity, $table, $id_column, $id, $to, array $set = array(), array $where = array() ) {
		global $wpdb;
		$assign = array( $wpdb->prepare( 'status=%s', $to ) );
		foreach ( $set as $column => $value ) {
			$assign[] = null === $value ? sanitize_key( $column ) . '=NULL' : $wpdb->prepare( sanitize_key( $column ) . '=' . ( is_int( $value ) ? '%d' : '%s' ), $value );
		}
		$cond = array( $wpdb->prepare( sanitize_key( $id_column ) . '=%d', (int) $id ), self::source_sql( $entity, $to ) );
		foreach ( $where as $column => $value ) {
			$cond[] = $wpdb->prepare( sanitize_key( $column ) . '=' . ( is_int( $value ) ? '%d' : '%s' ), $value );
		}
		$result = $wpdb->query( 'UPDATE ' . $table . ' SET ' . implode( ',', $assign ) . ' WHERE ' . implode( ' AND ', $cond ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every part is prepared above.
		return false === $result ? false : (int) $result;
	}
}
