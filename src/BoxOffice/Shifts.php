<?php
/**
 * Box office shifts (Kassenschichten): open with a counted float, several cashiers, cash in/out,
 * (blind) closing count with card terminal total, Z report on close, handover to the next cashier.
 *
 * Shifts are rows of wpcap_boxoffice_sessions (historic name; session_id = shift id). Life cycle:
 * open → counting (closing count submitted; no more sales or movements, recount possible) →
 * closed (Z report written). Counts, movements and Z reports are insert-only; a closed shift is
 * never changed again, corrections are booked as new movements into an open shift of the register.
 *
 * Locking order: register row, then shift row. Z numbers are derived under the register lock, so
 * they are gapless per register; refund payouts and movements lock the shift row and require
 * status "open", so totals cannot change between the closing count and the Z report.
 *
 * Hooks for fiscalization and other integrations (all fired after COMMIT):
 * - filter `wpcap_boxoffice_can_close_shift` (true|WP_Error, $shift, $count): veto a close.
 * - action `wpcap_boxoffice_shift_opened` ($shift_id, $register_id).
 * - action `wpcap_boxoffice_shift_closed` ($z_id, $z (ZReport::get() incl. decoded totals), $shift).
 * - action `wpcap_boxoffice_cash_movement` ($movement_id, $movement): cash in/out, corrections.
 * - action `wpcap_boxoffice_refund_booked` ($movement_id, $refund_id, $movement): payouts of refunds/stornos.
 * - actions `wpcap_boxoffice_handover_created` / `wpcap_boxoffice_handover_accepted` ($handover_id).
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Audit;
use PassCaptain\Invoices;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Shifts {
	/** Capability to storno checked-in tickets (with a reason). Administrators may always. */
	const OVERRIDE_CAP = 'wpcap_boxoffice_override';
	/** Users with this capability see expected amounts of blind counts and all Z reports. */
	const FINANCE_CAP = 'wpcap_view_finance';
	const ACTIVE = array( 'open', 'counting' );

	/** Override reason of a POS storno in progress (set by Service::storno for the payout movement). */
	public static $storno_override = '';

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_boxoffice_' . $name;
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function now() {
		return current_time( 'mysql', true );
	}

	/* ------------------------------------------------------------------ lookups */

	public static function get( $shift_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sessions' ) . ' WHERE session_id=%d', absint( $shift_id ) ) );
	}

	/** The open (or counting) shift of a register, if any. */
	public static function open_for_register( $register_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sessions' ) . ' WHERE open_register=%d', absint( $register_id ) ) );
	}

	/** Active shift the user works in (as opener or added cashier). */
	public static function current_for_user( $user_id, array $statuses = self::ACTIVE ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return null; }
		$in = implode( ',', array_map( static function ( $s ) { return "'" . preg_replace( '/[^a-z_]/', '', $s ) . "'"; }, $statuses ) );
		$s = self::t( 'sessions' );
		$c = self::t( 'shift_cashiers' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT s.* FROM $s s WHERE s.status IN ($in) AND (s.open_key=%d OR s.session_id IN (SELECT c.shift_id FROM $c c WHERE c.user_id=%d)) ORDER BY s.session_id DESC LIMIT 1", $user_id, $user_id ) );
	}

	/** @return int[] User ids working in a shift (opener first). */
	public static function cashiers( $shift_id ) {
		global $wpdb;
		$shift = self::get( $shift_id );
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'shift_cashiers' ) . ' WHERE shift_id=%d ORDER BY added_at,user_id', absint( $shift_id ) ) ) );
		if ( $shift ) { array_unshift( $ids, (int) $shift->user_id ); }
		return array_values( array_unique( $ids ) );
	}

	public static function is_cashier( $shift, $user_id ) {
		return $shift && $user_id && in_array( absint( $user_id ), self::cashiers( $shift->session_id ), true );
	}

	/** Cashiers of the shift and administrators may operate it (sell, count, close, movements). */
	public static function can_operate( $shift ) {
		return $shift && ( self::is_cashier( $shift, get_current_user_id() ) || current_user_can( Registers::MANAGE_CAP ) );
	}

	/** Who may see a shift and its Z report: its cashiers, administrators, finance. */
	public static function can_view( $shift ) {
		return $shift && ( self::can_operate( $shift ) || current_user_can( self::FINANCE_CAP ) );
	}

	/** Whether the expected amounts may be shown (blind count: only after the count was submitted). */
	public static function can_see_expected( $shift ) {
		if ( ! $shift ) { return false; }
		if ( ! (int) $shift->blind_count || 'closed' === $shift->status || current_user_can( Registers::MANAGE_CAP ) || current_user_can( self::FINANCE_CAP ) ) { return true; }
		return (bool) self::latest_count( $shift->session_id );
	}

	public static function latest_count( $shift_id, $kind = 'closing' ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'counts' ) . ' WHERE shift_id=%d AND kind=%s ORDER BY count_id DESC LIMIT 1', absint( $shift_id ), $kind ) );
	}

	public static function counts( $shift_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'counts' ) . ' WHERE shift_id=%d ORDER BY count_id', absint( $shift_id ) ) );
	}

	public static function movements( $shift_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'movements' ) . ' WHERE shift_id=%d ORDER BY movement_id', absint( $shift_id ) ) );
	}

	public static function movement( $movement_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'movements' ) . ' WHERE movement_id=%d', absint( $movement_id ) ) );
	}

	public static function handover( $handover_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'handovers' ) . ' WHERE handover_id=%d', absint( $handover_id ) ) );
	}

	/** Pending handovers addressed to a user. */
	public static function handovers_for( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'handovers' ) . " WHERE to_user=%d AND status='pending' ORDER BY handover_id", absint( $user_id ) ) );
	}

	/**
	 * Shift data for display. With a blind count the expected amounts (and the money totals they
	 * can be derived from) are withheld from cashiers until the closing count was submitted.
	 */
	public static function view( $shift_id ) {
		$shift = self::get( $shift_id );
		if ( ! $shift ) { return null; }
		$totals = ZReport::compute( $shift->session_id );
		$masked = ! self::can_see_expected( $shift );
		if ( $masked ) {
			$totals = array( 'currency' => $totals['currency'], 'counts' => $totals['counts'], 'cash' => array( 'opening' => $totals['cash']['opening'], 'in' => $totals['cash']['in'], 'out' => $totals['cash']['out'] ) );
		}
		return array(
			'shift' => $shift,
			'register' => Registers::get( $shift->register_id ),
			'totals' => $totals,
			'masked' => $masked,
			'count' => self::latest_count( $shift->session_id ),
			'cashiers' => self::cashiers( $shift->session_id ),
			'movements' => self::movements( $shift->session_id ),
			'z' => ZReport::for_shift( $shift->session_id ),
		);
	}

	/* ------------------------------------------------------------------ open */

	/**
	 * Opens a shift on a register with a counted float.
	 *
	 * @param array $args float_minor (int) and/or denominations ({value_minor: qty}), note,
	 *                    handover_id + handover_reason (internal, see accept_handover()).
	 * @return object|\WP_Error Shift row.
	 */
	public static function open( $register_id, array $args = array() ) {
		global $wpdb;
		if ( ! current_user_can( Service::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$register = Registers::get( $register_id );
		if ( ! $register || 'active' !== $register->status ) { return self::error( 'wpcap_pos_register', __( 'Kasse nicht gefunden oder deaktiviert.', 'wp-pass-captain' ), 404 ); }
		if ( (int) $register->event_id && ! \PassCaptain\Reports\Scope::can_event( (int) $register->event_id, Service::CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung für diese Kasse.', 'wp-pass-captain' ), 403 ); }
		$user_id = get_current_user_id();
		if ( self::current_for_user( $user_id ) ) { return self::error( 'wpcap_pos_shift_active', __( 'Sie arbeiten bereits in einer offenen Schicht. Bitte diese zuerst abschließen.', 'wp-pass-captain' ), 409 ); }
		$resolved = Cash::resolve( $args['float_minor'] ?? null, $args['denominations'] ?? null, $register->currency );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		list( $float, $denoms ) = $resolved;
		$handover_id = absint( $args['handover_id'] ?? 0 );
		$p = self::t( '' );
		$now = self::now();

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_row( $wpdb->prepare( 'SELECT register_id FROM ' . Registers::table() . ' WHERE register_id=%d FOR UPDATE', $register->register_id ) );
		if ( $wpdb->get_row( $wpdb->prepare( "SELECT session_id FROM {$p}sessions WHERE open_register=%d FOR UPDATE", $register->register_id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_register_busy', __( 'Auf dieser Kasse ist bereits eine Schicht offen.', 'wp-pass-captain' ), 409 );
		}
		$pending = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}handovers WHERE register_id=%d AND status='pending' ORDER BY handover_id DESC LIMIT 1 FOR UPDATE", $register->register_id ) );
		if ( $pending && (int) $pending->handover_id !== $handover_id ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_handover_pending', __( 'Für diese Kasse wartet eine Übergabe. Die nächste Schicht wird durch Annahme der Übergabe geöffnet.', 'wp-pass-captain' ), 409 );
		}
		if ( $handover_id && ! $pending ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_handover', __( 'Diese Übergabe wartet nicht mehr auf Annahme.', 'wp-pass-captain' ), 409 );
		}
		$inserted = $wpdb->insert( "{$p}sessions", array(
			'register_id' => (int) $register->register_id,
			'user_id' => $user_id,
			'open_key' => $user_id,
			'open_register' => (int) $register->register_id,
			'currency' => $register->currency,
			'opening_float_minor' => $float,
			'blind_count' => (int) $register->blind_count,
			'handover_id' => $handover_id,
			'note' => mb_substr( sanitize_text_field( (string) ( $args['note'] ?? '' ) ), 0, 255 ),
			'status' => 'open',
			'opened_at' => $now,
		) );
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_shift_active', __( 'Die Schicht konnte nicht geöffnet werden (bereits eine offene Schicht?).', 'wp-pass-captain' ), 409 );
		}
		$shift_id = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}shift_cashiers", array( 'shift_id' => $shift_id, 'user_id' => $user_id, 'role' => 'opener', 'added_by' => $user_id, 'added_at' => $now ) );
		$wpdb->insert( "{$p}counts", array( 'shift_id' => $shift_id, 'register_id' => (int) $register->register_id, 'kind' => 'opening', 'cash_minor' => $float, 'denominations' => $denoms ? wp_json_encode( $denoms ) : null, 'counted_by' => $user_id, 'created_at' => $now ) );
		if ( $pending ) {
			$updated = $wpdb->update( "{$p}handovers", array(
				'status' => 'accepted',
				'to_shift_id' => $shift_id,
				'received_minor' => $float,
				'difference_minor' => $float - (int) $pending->amount_minor,
				'reason' => mb_substr( sanitize_text_field( (string) ( $args['handover_reason'] ?? '' ) ), 0, 255 ),
				'decided_at' => $now,
				'decided_by' => $user_id,
			), array( 'handover_id' => (int) $pending->handover_id, 'status' => 'pending' ) );
			if ( ! $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wpcap_pos_handover', __( 'Diese Übergabe wartet nicht mehr auf Annahme.', 'wp-pass-captain' ), 409 );
			}
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'pos_session_opened', 'pos_session', $shift_id, array( 'register_id' => (int) $register->register_id, 'float_minor' => $float, 'denominations' => $denoms, 'handover_id' => $handover_id ) );
		if ( $pending ) {
			Audit::record( 'pos_handover_accepted', 'pos_register', (int) $register->register_id, array( 'handover_id' => (int) $pending->handover_id, 'from_user' => (int) $pending->from_user, 'to_user' => $user_id, 'amount_minor' => (int) $pending->amount_minor, 'received_minor' => $float, 'difference_minor' => $float - (int) $pending->amount_minor, 'reason' => (string) ( $args['handover_reason'] ?? '' ) ) );
			do_action( 'wpcap_boxoffice_handover_accepted', (int) $pending->handover_id );
		}
		do_action( 'wpcap_boxoffice_shift_opened', $shift_id, (int) $register->register_id );
		return self::get( $shift_id );
	}

	/** Adds a cashier to an active shift (by a cashier of the shift or an administrator). */
	public static function add_cashier( $shift_id, $user_id ) {
		global $wpdb;
		$shift = self::get( $shift_id );
		$user_id = absint( $user_id );
		if ( ! $shift || ! in_array( $shift->status, self::ACTIVE, true ) ) { return self::error( 'wpcap_pos_session_closed', __( 'Die Schicht ist nicht offen.', 'wp-pass-captain' ), 409 ); }
		if ( ! self::can_operate( $shift ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		if ( ! $user_id || ( function_exists( 'user_can' ) && ! user_can( $user_id, Service::CAP ) ) ) { return self::error( 'wpcap_pos_cashier', __( 'Dieser Benutzer darf nicht kassieren.', 'wp-pass-captain' ) ); }
		if ( self::is_cashier( $shift, $user_id ) ) { return $shift; }
		if ( self::current_for_user( $user_id ) ) { return self::error( 'wpcap_pos_shift_active', __( 'Dieser Benutzer arbeitet bereits in einer anderen offenen Schicht.', 'wp-pass-captain' ), 409 ); }
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'shift_cashiers' ) . " (shift_id,user_id,role,added_by,added_at) VALUES (%d,%d,'cashier',%d,%s)", $shift->session_id, $user_id, get_current_user_id(), self::now() ) );
		Audit::record( 'pos_cashier_added', 'pos_session', (int) $shift->session_id, array( 'user_id' => $user_id ) );
		return self::get( $shift->session_id );
	}

	/* ------------------------------------------------------------------ count and close */

	/** Stornos of this shift's sales that still wait for approval (they would be paid out later). */
	public static function pending_stornos( $shift_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$full = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales WHERE session_id=%d AND status='storno_pending'", absint( $shift_id ) ) );
		$partial = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_boxoffice_sales s ON s.order_id=r.order_id WHERE s.session_id=%d AND r.status='pending_approval' AND r.reason LIKE %s", absint( $shift_id ), 'POS-Storno%' ) );
		return max( $full, $partial );
	}

	/** Checks that block counting/closing: pending stornos, paid but unbooked sales. */
	private static function close_blockers( $shift ) {
		global $wpdb;
		Service::reset_rejected_stornos( $shift->session_id );
		if ( self::pending_stornos( $shift->session_id ) ) {
			return self::error( 'wpcap_pos_storno_pending', __( 'Ein Storno wartet noch auf Freigabe. Bitte freigeben oder ablehnen lassen, dann die Kasse abschließen.', 'wp-pass-captain' ), 409 );
		}
		$p = $wpdb->prefix;
		$unbooked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_boxoffice_sales s INNER JOIN {$p}wpcap_orders ord ON ord.order_id=s.order_id WHERE s.session_id=%d AND s.status='pending' AND ord.payment_status='paid'", $shift->session_id ) );
		if ( $unbooked ) {
			return self::error( 'wpcap_pos_unbooked', __( 'Ein bezahlter Verkauf ist noch nicht gebucht. Bitte zuerst „Nachbuchen“ ausführen.', 'wp-pass-captain' ), 409 );
		}
		return true;
	}

	/**
	 * Submits the closing count (cash with optional denominations, card terminal total). The shift
	 * changes to "counting": no further sales or movements. Returns the count with the expected
	 * amounts and differences, which a blind count reveals only now. A recount inserts a new count;
	 * the latest one is used for the Z report.
	 *
	 * @param array $args cash_minor and/or denominations; card_minor (terminal total, null = not counted).
	 * @return array|\WP_Error
	 */
	public static function submit_count( $shift_id, array $args ) {
		global $wpdb;
		$shift = self::get( $shift_id );
		if ( ! $shift ) { return self::error( 'wpcap_pos_session', __( 'Kassensitzung nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_operate( $shift ) ) { return self::error( 'wpcap_forbidden', __( 'Nur die eigene Kassensitzung kann geschlossen werden.', 'wp-pass-captain' ), 403 ); }
		if ( ! in_array( $shift->status, self::ACTIVE, true ) ) { return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung ist bereits geschlossen.', 'wp-pass-captain' ), 409 ); }
		$blocked = self::close_blockers( $shift );
		if ( is_wp_error( $blocked ) ) { return $blocked; }
		$resolved = Cash::resolve( $args['cash_minor'] ?? null, $args['denominations'] ?? null, $shift->currency );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		list( $cash, $denoms ) = $resolved;
		$card = isset( $args['card_minor'] ) && null !== $args['card_minor'] && '' !== $args['card_minor'] ? (int) $args['card_minor'] : null;
		if ( null !== $card && $card < 0 ) { return self::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'wp-pass-captain' ) ); }
		$p = self::t( '' );
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}sessions WHERE session_id=%d FOR UPDATE", $shift->session_id ) );
		if ( ! $locked || ! in_array( $locked->status, self::ACTIVE, true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung ist bereits geschlossen.', 'wp-pass-captain' ), 409 ); }
		$totals = ZReport::compute( $shift->session_id );
		$wpdb->insert( "{$p}counts", array(
			'shift_id' => (int) $shift->session_id,
			'register_id' => (int) $shift->register_id,
			'kind' => 'closing',
			'cash_minor' => $cash,
			'denominations' => $denoms ? wp_json_encode( $denoms ) : null,
			'card_minor' => $card,
			'expected_cash_minor' => $totals['cash']['expected'],
			'expected_card_minor' => $totals['card']['expected'],
			'counted_by' => get_current_user_id(),
			'created_at' => self::now(),
		) );
		$count_id = (int) $wpdb->insert_id;
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}sessions SET status='counting' WHERE session_id=%d AND status IN ('open','counting')", $shift->session_id ) );
		$wpdb->query( 'COMMIT' );
		$result = array(
			'count_id' => $count_id,
			'blind' => (bool) (int) $shift->blind_count,
			'cash_minor' => $cash,
			'card_minor' => $card,
			'denominations' => $denoms,
			'expected_cash_minor' => $totals['cash']['expected'],
			'expected_card_minor' => $totals['card']['expected'],
			'cash_difference_minor' => $cash - $totals['cash']['expected'],
			'card_difference_minor' => null === $card ? null : $card - $totals['card']['expected'],
			'currency' => $shift->currency,
		);
		Audit::record( 'pos_count_submitted', 'pos_session', (int) $shift->session_id, array_diff_key( $result, array( 'currency' => 1 ) ) );
		return $result;
	}

	/**
	 * Closes a counted shift and writes its Z report.
	 *
	 * @param array $args reason (required when cash or card differ), note, handover_to (user id),
	 *                    handover_minor and/or handover_denominations (cash left in the drawer for
	 *                    the next cashier; the rest of the counted cash is removed to the safe).
	 * @return object|\WP_Error Closed shift row.
	 */
	public static function close( $shift_id, array $args = array() ) {
		global $wpdb;
		$shift = self::get( $shift_id );
		if ( ! $shift ) { return self::error( 'wpcap_pos_session', __( 'Kassensitzung nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_operate( $shift ) ) { return self::error( 'wpcap_forbidden', __( 'Nur die eigene Kassensitzung kann geschlossen werden.', 'wp-pass-captain' ), 403 ); }
		if ( 'closed' === $shift->status ) { return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung ist bereits geschlossen.', 'wp-pass-captain' ), 409 ); }
		$count = self::latest_count( $shift->session_id );
		if ( 'counting' !== $shift->status || ! $count ) { return self::error( 'wpcap_pos_count_missing', __( 'Bitte zuerst den Kassenbestand zählen.', 'wp-pass-captain' ), 409 ); }
		$reason = mb_substr( sanitize_text_field( (string) ( $args['reason'] ?? '' ) ), 0, 255 );
		$note = mb_substr( sanitize_text_field( (string) ( $args['note'] ?? $reason ) ), 0, 255 );
		$to_user = absint( $args['handover_to'] ?? 0 );
		$handover_amount = 0;
		$handover_denoms = array();
		if ( $to_user ) {
			if ( function_exists( 'user_can' ) && ! user_can( $to_user, Service::CAP ) ) { return self::error( 'wpcap_pos_cashier', __( 'Dieser Benutzer darf nicht kassieren.', 'wp-pass-captain' ) ); }
			$resolved = Cash::resolve( $args['handover_minor'] ?? null, $args['handover_denominations'] ?? null, $shift->currency );
			if ( is_wp_error( $resolved ) ) { return $resolved; }
			list( $handover_amount, $handover_denoms ) = $resolved;
			if ( $handover_amount > (int) $count->cash_minor ) { return self::error( 'wpcap_pos_handover_amount', __( 'Der übergebene Betrag ist höher als der gezählte Bargeldbestand.', 'wp-pass-captain' ) ); }
		}
		$veto = apply_filters( 'wpcap_boxoffice_can_close_shift', true, $shift, $count );
		if ( is_wp_error( $veto ) ) { return $veto; }
		$blocked = self::close_blockers( $shift );
		if ( is_wp_error( $blocked ) ) { return $blocked; }

		$p = self::t( '' );
		$wp = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$register = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Registers::table() . ' WHERE register_id=%d FOR UPDATE', $shift->register_id ) );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}sessions WHERE session_id=%d FOR UPDATE", $shift->session_id ) );
		if ( ! $register || ! $locked || 'counting' !== $locked->status ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_session_closed', __( 'Die Kassensitzung ist bereits geschlossen.', 'wp-pass-captain' ), 409 );
		}
		$count = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}counts WHERE shift_id=%d AND kind='closing' ORDER BY count_id DESC LIMIT 1", $shift->session_id ) );
		$unfinished = $wpdb->get_results( $wpdb->prepare( "SELECT s.sale_id,ord.payment_status FROM {$p}sales s LEFT JOIN {$wp}wpcap_orders ord ON ord.order_id=s.order_id WHERE s.session_id=%d AND s.status='pending' FOR UPDATE", $shift->session_id ) );
		foreach ( $unfinished as $row ) {
			if ( 'paid' === $row->payment_status ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wpcap_pos_unbooked', __( 'Ein bezahlter Verkauf ist noch nicht gebucht. Bitte zuerst „Nachbuchen“ ausführen.', 'wp-pass-captain' ), 409 );
			}
		}
		foreach ( $unfinished as $row ) {
			$wpdb->update( "{$p}sales", array( 'status' => 'failed' ), array( 'sale_id' => (int) $row->sale_id, 'status' => 'pending' ) );
		}
		$totals = ZReport::compute( $locked->session_id );
		$cash_diff = (int) $count->cash_minor - $totals['cash']['expected'];
		$card_counted = null === $count->card_minor ? null : (int) $count->card_minor;
		$card_diff = null === $card_counted ? null : $card_counted - $totals['card']['expected'];
		if ( ( 0 !== $cash_diff || ( null !== $card_diff && 0 !== $card_diff ) ) && '' === $reason ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_difference_reason', __( 'Es gibt eine Kassendifferenz. Bitte einen Grund angeben.', 'wp-pass-captain' ) );
		}
		$now = self::now();
		$totals['register'] = array( 'register_id' => (int) $register->register_id, 'name' => $register->name, 'location' => $register->location );
		$totals['cashiers'] = self::cashiers( $locked->session_id );
		$totals['cash']['counted'] = (int) $count->cash_minor;
		$totals['cash']['difference'] = $cash_diff;
		$totals['cash']['denominations'] = $count->denominations ? (array) json_decode( $count->denominations, true ) : array();
		$totals['cash']['handover'] = $handover_amount;
		$totals['cash']['to_safe'] = (int) $count->cash_minor - $handover_amount;
		$totals['card']['counted'] = $card_counted;
		$totals['card']['difference'] = $card_diff;
		$totals['handover'] = $to_user ? array( 'to_user' => $to_user, 'amount' => $handover_amount, 'denominations' => $handover_denoms ) : null;
		$totals['blind_count'] = (bool) (int) $locked->blind_count;
		$totals['count_id'] = (int) $count->count_id;
		$totals['recounts'] = max( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}counts WHERE shift_id=%d AND kind='closing'", $locked->session_id ) ) - 1 );
		$z_id = ZReport::insert( $locked, $totals, $reason, $now );
		if ( is_wp_error( $z_id ) ) { $wpdb->query( 'ROLLBACK' ); return $z_id; }
		$wpdb->update( "{$p}sessions", array(
			'status' => 'closed',
			'open_key' => null,
			'open_register' => null,
			'closing_count_minor' => (int) $count->cash_minor,
			'expected_cash_minor' => $totals['cash']['expected'],
			'difference_minor' => $cash_diff,
			'counted_card_minor' => $card_counted,
			'expected_card_minor' => $totals['card']['expected'],
			'card_difference_minor' => $card_diff,
			'totals' => wp_json_encode( $totals ),
			'closing_note' => $note,
			'closed_at' => $now,
			'closed_by' => get_current_user_id(),
		), array( 'session_id' => (int) $locked->session_id ) );
		$handover_id = 0;
		if ( $to_user ) {
			$wpdb->insert( "{$p}handovers", array( 'register_id' => (int) $register->register_id, 'from_shift_id' => (int) $locked->session_id, 'from_user' => get_current_user_id(), 'to_user' => $to_user, 'amount_minor' => $handover_amount, 'denominations' => $handover_denoms ? wp_json_encode( $handover_denoms ) : null, 'status' => 'pending', 'created_at' => $now ) );
			$handover_id = (int) $wpdb->insert_id;
			if ( ! $handover_id ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'wpcap_pos_handover', __( 'Die Übergabe konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 ); }
		}
		$wpdb->query( 'COMMIT' );
		$z = ZReport::get( $z_id );
		Audit::record( 'pos_session_closed', 'pos_session', (int) $locked->session_id, array( 'z_id' => $z_id, 'z_number' => (int) $z->z_number, 'register_id' => (int) $register->register_id, 'expected_cash_minor' => $totals['cash']['expected'], 'counted_minor' => (int) $count->cash_minor, 'difference_minor' => $cash_diff, 'card_difference_minor' => $card_diff, 'reason' => $reason, 'failed_unfinished' => count( $unfinished ) ) );
		if ( $handover_id ) {
			Audit::record( 'pos_handover_created', 'pos_register', (int) $register->register_id, array( 'handover_id' => $handover_id, 'from_user' => get_current_user_id(), 'to_user' => $to_user, 'amount_minor' => $handover_amount ) );
		}
		/**
		 * A shift was closed and its Z report written (insert-only, hash-chained). Fiscal integrations
		 * can sign/export the Z report here; $z->totals_data holds the decoded totals.
		 */
		do_action( 'wpcap_boxoffice_shift_closed', $z_id, $z, self::get( $locked->session_id ) );
		if ( $handover_id ) { do_action( 'wpcap_boxoffice_handover_created', $handover_id ); }
		return self::get( $locked->session_id );
	}

	/* ------------------------------------------------------------------ handover */

	/**
	 * The next cashier counts the handed over cash and opens the next shift with it. A difference
	 * to the handed over amount needs a reason; both users and the difference are recorded.
	 *
	 * @param array $args cash_minor and/or denominations, reason, note.
	 */
	public static function accept_handover( $handover_id, array $args ) {
		$handover = self::handover( $handover_id );
		if ( ! $handover || 'pending' !== $handover->status ) { return self::error( 'wpcap_pos_handover', __( 'Diese Übergabe wartet nicht mehr auf Annahme.', 'wp-pass-captain' ), 409 ); }
		if ( (int) $handover->to_user !== get_current_user_id() && ! current_user_can( Registers::MANAGE_CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Diese Übergabe ist an eine andere Person gerichtet.', 'wp-pass-captain' ), 403 ); }
		$register = Registers::get( $handover->register_id );
		$resolved = Cash::resolve( $args['cash_minor'] ?? null, $args['denominations'] ?? null, $register ? $register->currency : 'EUR' );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		$reason = trim( sanitize_text_field( (string) ( $args['reason'] ?? '' ) ) );
		if ( $resolved[0] !== (int) $handover->amount_minor && '' === $reason ) {
			return self::error( 'wpcap_pos_difference_reason', __( 'Der gezählte Betrag weicht vom übergebenen Betrag ab. Bitte einen Grund angeben.', 'wp-pass-captain' ) );
		}
		return self::open( (int) $handover->register_id, array( 'float_minor' => $resolved[0], 'denominations' => $resolved[1], 'note' => $args['note'] ?? '', 'handover_id' => (int) $handover->handover_id, 'handover_reason' => $reason ) );
	}

	/** Cancels a pending handover (sender or administrator); the register can then be opened freely. */
	public static function cancel_handover( $handover_id, $reason = '' ) {
		global $wpdb;
		$handover = self::handover( $handover_id );
		if ( ! $handover || 'pending' !== $handover->status ) { return self::error( 'wpcap_pos_handover', __( 'Diese Übergabe wartet nicht mehr auf Annahme.', 'wp-pass-captain' ), 409 ); }
		if ( (int) $handover->from_user !== get_current_user_id() && ! current_user_can( Registers::MANAGE_CAP ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$reason = mb_substr( sanitize_text_field( (string) $reason ), 0, 255 );
		if ( '' === $reason ) { return self::error( 'wpcap_pos_reason', __( 'Bitte einen Grund angeben.', 'wp-pass-captain' ) ); }
		$wpdb->update( self::t( 'handovers' ), array( 'status' => 'cancelled', 'reason' => $reason, 'decided_at' => self::now(), 'decided_by' => get_current_user_id() ), array( 'handover_id' => (int) $handover->handover_id, 'status' => 'pending' ) );
		Audit::record( 'pos_handover_cancelled', 'pos_register', (int) $handover->register_id, array( 'handover_id' => (int) $handover->handover_id, 'reason' => $reason ) );
		return self::handover( $handover->handover_id );
	}

	/* ------------------------------------------------------------------ movements */

	/**
	 * Cash in (Einlage) or cash out (Entnahme) of an open shift, with a reason.
	 *
	 * @param string $kind cash_in|cash_out
	 * @return object|\WP_Error Movement row.
	 */
	public static function cash_movement( $shift_id, $kind, $amount_minor, $reason ) {
		$shift = self::get( $shift_id );
		if ( ! in_array( $kind, array( 'cash_in', 'cash_out' ), true ) ) { return self::error( 'wpcap_pos_movement', __( 'Unbekannte Buchungsart.', 'wp-pass-captain' ) ); }
		if ( ! $shift ) { return self::error( 'wpcap_pos_session', __( 'Kassensitzung nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! self::can_operate( $shift ) ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$amount = (int) $amount_minor;
		if ( $amount <= 0 ) { return self::error( 'wpcap_pos_amount', __( 'Ungültiger Betrag.', 'wp-pass-captain' ) ); }
		$reason = mb_substr( trim( sanitize_text_field( (string) $reason ) ), 0, 255 );
		if ( '' === $reason ) { return self::error( 'wpcap_pos_reason', __( 'Bitte einen Grund angeben.', 'wp-pass-captain' ) ); }
		return self::insert_movement( $shift, array( 'kind' => $kind, 'method' => 'cash', 'amount_minor' => 'cash_in' === $kind ? $amount : -$amount, 'reason' => $reason ) );
	}

	/**
	 * Correction of a closed Z report: booked as a new movement into the open shift of the same
	 * register (the Z report itself is never changed). Administrators only.
	 *
	 * @param int $amount_minor Signed cash correction (+ more cash, - less cash).
	 */
	public static function correction( $z_id, $amount_minor, $reason ) {
		if ( ! Registers::can_manage() ) { return self::error( 'wpcap_forbidden', __( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$z = ZReport::get( $z_id );
		if ( ! $z ) { return self::error( 'wpcap_pos_z', __( 'Z-Bericht nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		$reason = mb_substr( trim( sanitize_text_field( (string) $reason ) ), 0, 255 );
		if ( '' === $reason || 0 === (int) $amount_minor ) { return self::error( 'wpcap_pos_reason', __( 'Bitte Betrag und Grund der Korrektur angeben.', 'wp-pass-captain' ) ); }
		$shift = self::open_for_register( $z->register_id );
		if ( ! $shift || 'open' !== $shift->status ) { return self::error( 'wpcap_pos_no_shift', __( 'Korrekturen werden in die offene Schicht der Kasse gebucht. Bitte zuerst eine Schicht öffnen.', 'wp-pass-captain' ), 409 ); }
		/* translators: %d: Z number */
		return self::insert_movement( $shift, array( 'kind' => 'correction', 'method' => 'cash', 'amount_minor' => (int) $amount_minor, 'z_id' => (int) $z->z_id, 'reason' => sprintf( __( 'Korrektur zu Z %d: ', 'wp-pass-captain' ), (int) $z->z_number ) . $reason ) );
	}

	/** Inserts a movement into an open shift under the shift lock. */
	private static function insert_movement( $shift, array $data, $with_receipt = false ) {
		global $wpdb;
		$p = self::t( '' );
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$p}sessions WHERE session_id=%d FOR UPDATE", $shift->session_id ) );
		if ( ! $locked || 'open' !== $locked->status ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_session_closed', __( 'Buchungen sind nur in einer offenen Schicht möglich (nicht während der Zählung).', 'wp-pass-captain' ), 409 );
		}
		if ( ! empty( $data['refund_id'] ) ) {
			$dup = $wpdb->get_row( $wpdb->prepare( "SELECT movement_id FROM {$p}movements WHERE refund_id=%d FOR UPDATE", (int) $data['refund_id'] ) );
			if ( $dup ) { $wpdb->query( 'COMMIT' ); return self::movement( $dup->movement_id ); }
		}
		if ( $with_receipt ) {
			$number = Invoices::next_document_number( 'receipt', Service::receipt_prefix(), true );
			if ( is_wp_error( $number ) ) { $wpdb->query( 'ROLLBACK' ); return $number; }
			$data['receipt_number'] = $number;
		}
		$row = array_merge( array(
			'shift_id' => (int) $shift->session_id,
			'register_id' => (int) $shift->register_id,
			'currency' => $shift->currency,
			'created_by' => get_current_user_id(),
			'created_at' => self::now(),
		), $data );
		if ( false === $wpdb->insert( "{$p}movements", $row ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pos_movement', __( 'Die Buchung konnte nicht gespeichert werden.', 'wp-pass-captain' ), 500 );
		}
		$movement_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		$movement = self::movement( $movement_id );
		Audit::record( 'pos_movement', 'pos_session', (int) $shift->session_id, array( 'movement_id' => $movement_id, 'kind' => $movement->kind, 'method' => $movement->method, 'amount_minor' => (int) $movement->amount_minor, 'refund_id' => (int) $movement->refund_id, 'reason' => $movement->reason, 'override' => $movement->override_reason ) );
		if ( $movement->refund_id ) {
			do_action( 'wpcap_boxoffice_refund_booked', $movement_id, (int) $movement->refund_id, $movement );
		} else {
			do_action( 'wpcap_boxoffice_cash_movement', $movement_id, $movement );
		}
		return $movement;
	}

	/* ------------------------------------------------------------------ refunds of POS orders */

	/**
	 * Shift a refund/storno of a sale is booked into: the sale's own shift while it is open, else
	 * the current user's open shift, else the open shift of the sale's register.
	 */
	public static function target_shift_for_sale( $sale ) {
		if ( ! $sale ) { return null; }
		$own = self::get( $sale->session_id );
		if ( $own && 'open' === $own->status ) { return $own; }
		$mine = self::current_for_user( get_current_user_id(), array( 'open' ) );
		if ( $mine && $mine->currency === $sale->currency ) { return $mine; }
		$register = $own ? self::open_for_register( $own->register_id ) : null;
		return $register && 'open' === $register->status ? $register : null;
	}

	/** Whether paying back a sale moves money at the till (cash drawer or card terminal). */
	public static function needs_shift( $method ) {
		return in_array( $method, array( 'cash', 'card' ), true );
	}

	/**
	 * Books the payout of a refund of a box office order as a negative movement (with its own
	 * receipt number of the receipt series and the VAT per rate) into the target shift. Idempotent
	 * per refund. Cash and card payouts need an open shift ($require_shift): without one a WP_Error
	 * is returned and the refund fails (it can be retried once a shift is open). Other refunds
	 * (free, invoice) are booked when a shift is open, else skipped (null).
	 *
	 * @return object|null|\WP_Error Movement row, null when not a POS sale or nothing to book.
	 */
	public static function book_refund( $refund_id, $require_shift = true ) {
		global $wpdb;
		$refund = Refunds::get( $refund_id );
		if ( ! $refund ) { return null; }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'movements' ) . ' WHERE refund_id=%d', (int) $refund['refund_id'] ) );
		if ( $existing ) { return $existing; }
		$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sales' ) . ' WHERE order_id=%d', (int) $refund['order_id'] ) );
		if ( ! $sale || ! Service::is_booked( $sale ) ) { return null; }
		$target = self::target_shift_for_sale( $sale );
		if ( ! $target ) {
			if ( $require_shift && self::needs_shift( $sale->payment_method ) ) {
				return self::error( 'wpcap_pos_no_shift', __( 'Für die Auszahlung einer Kassenbestellung (bar oder Karte) muss eine Kassenschicht geöffnet sein. Bitte eine Schicht öffnen und die Erstattung erneut ausführen.', 'wp-pass-captain' ), 409 );
			}
			return null;
		}
		$ticket_ids = array_map( 'absint', (array) json_decode( (string) $refund['ticket_ids'], true ) );
		$amount = (int) $refund['amount_minor'];
		$tax = (int) $refund['tax_minor'];
		$vat = ZReport::refund_vat( (int) $refund['order_id'], $ticket_ids, $amount, $tax );
		$kind = 0 === strpos( (string) $refund['reason'], 'POS-Storno' ) ? 'storno' : 'refund';
		$movement = self::insert_movement( $target, array(
			'kind' => $kind,
			'method' => $sale->payment_method,
			'amount_minor' => -$amount,
			'tax_minor' => -$tax,
			'vat' => wp_json_encode( $vat ),
			'order_id' => (int) $refund['order_id'],
			'sale_id' => (int) $sale->sale_id,
			'refund_id' => (int) $refund['refund_id'],
			'ticket_ids' => wp_json_encode( $ticket_ids ),
			'reason' => mb_substr( (string) $refund['reason'], 0, 255 ),
			'override_reason' => mb_substr( (string) self::$storno_override, 0, 255 ),
		), true );
		if ( is_wp_error( $movement ) && ! ( $require_shift && self::needs_shift( $sale->payment_method ) ) ) { return null; }
		return $movement;
	}
}
