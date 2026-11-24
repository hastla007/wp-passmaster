<?php
/**
 * Bulk release of open, unpaid bank transfer orders (manual payment) on the tab "Betrieb" of the screen
 * "Systemstatus": a list filtered by event and minimum age, then a confirmed admin-post action that
 * releases the shown orders early.
 *
 * Release = the normal expiry path: the order's active holds end now and Orders::expire_holds() expires
 * the order exactly like the hold cron does (order and payment status "expired", holds freed, limited
 * discount codes released, `wpcap_capacity_released` / `wpcap_order_cancelled` fired, waitlists offered;
 * no other buyer mail than on a normal expiry). A bank transfer that still arrives later goes through the
 * existing late payment check, as for every expired order. Only orders that are still manual, unpaid and
 * held when the action runs are touched; only ids that were shown in the list are released.
 *
 * Capability manage_options (like the rest of the tab), nonce, confirmation, audit log (one entry per
 * order and one for the run).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Clock;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ManualHoldsRelease {
	const ACTION = 'wpcap_release_manual_holds';
	const CAP = 'manage_options';
	/** Orders per list and per run. */
	const MAX = 200;
	/** Anchor of the section on the tab "Betrieb". */
	const ANCHOR = 'wpcap-manual-holds';

	/** URL of the section (tab "Betrieb"), optionally with filters. */
	public static function url( array $args = array() ) {
		return OperationsPage::url( $args ) . '#' . self::ANCHOR;
	}

	/** admin-post handlers of this file (StatusPage::handlers()). */
	public static function handlers() {
		return array( self::ACTION => array( self::class, 'handle' ) );
	}

	/**
	 * Open bank transfer orders with an active hold, oldest first.
	 *
	 * @param int $event_id  0 = all events.
	 * @param int $min_hours Only orders created at least this many hours ago (0 = all).
	 * @param int $limit     At most this many.
	 * @return array<int, array<string, mixed>> Rows: order_id, public_id, customer_email, created_at, total_minor, currency, tickets, hold_until.
	 */
	public static function candidates( $event_id = 0, $min_hours = 0, $limit = self::MAX ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		$before = gmdate( 'Y-m-d H:i:s', Clock::now() - max( 0, (int) $min_hours ) * HOUR_IN_SECONDS );
		$event_sql = $event_id ? $wpdb->prepare( ' AND EXISTS (SELECT 1 FROM %i re INNER JOIN %i te ON te.ticket_type_id=re.ticket_type_id INNER JOIN %i oe ON oe.occurrence_id=te.occurrence_id WHERE re.order_id=o.order_id AND oe.event_id=%d)', $p . 'wpcap_reservations', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', absint( $event_id ) ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.order_id,o.public_id,o.customer_email,o.created_at,o.total_minor,o.currency,SUM(r.quantity) AS tickets,MIN(r.expires_at) AS hold_until FROM {$p}wpcap_orders o INNER JOIN {$p}wpcap_reservations r ON r.order_id=o.order_id AND r.status='active' AND r.expires_at > %s WHERE o.payment_provider='manual' AND o.payment_status='pending' AND o.created_at <= %s" . $event_sql . ' GROUP BY o.order_id,o.public_id,o.customer_email,o.created_at,o.total_minor,o.currency ORDER BY o.created_at ASC, o.order_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $event_sql is prepared above.
			$now,
			$before,
			max( 1, min( self::MAX, (int) $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Events with open bank transfer holds: event_id => "name (n tickets)". */
	public static function events_with_holds() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,SUM(r.quantity) AS tickets FROM %i r INNER JOIN %i o ON o.order_id=r.order_id INNER JOIN %i t ON t.ticket_type_id=r.ticket_type_id INNER JOIN %i oc ON oc.occurrence_id=t.occurrence_id INNER JOIN %i e ON e.event_id=oc.event_id WHERE r.status='active' AND r.expires_at > %s AND o.payment_provider='manual' AND o.payment_status='pending' GROUP BY e.event_id,e.name ORDER BY e.name ASC LIMIT 200",
			$p . 'wpcap_reservations',
			$p . 'wpcap_orders',
			$p . 'wpcap_ticket_types',
			$p . 'wpcap_occurrences',
			$p . 'wpcap_events',
			Clock::mysql()
		) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			/* translators: 1: event name, 2: number of tickets */
			$out[ (int) $row->event_id ] = sprintf( __( '%1$s (%2$d Tickets)', 'pass-captain' ), (string) $row->name, (int) $row->tickets );
		}
		return $out;
	}

	/**
	 * Releases the given orders early through the expiry path (see class comment). Only ids that still
	 * match the filters (manual, unpaid, held, event, age) are touched.
	 *
	 * @param int[] $order_ids Ids shown in the list.
	 * @param int   $event_id  Filter of the list (0 = all events).
	 * @param int   $min_hours Filter of the list.
	 * @return array{released:int, skipped:int}
	 */
	public static function release( array $order_ids, $event_id = 0, $min_hours = 0 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wanted = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		$current = array_map( static function ( $row ) { return (int) $row['order_id']; }, self::candidates( $event_id, $min_hours, self::MAX ) );
		$ids = array_values( array_intersect( $wanted, $current ) );
		$ended = gmdate( 'Y-m-d H:i:s', Clock::now() - 1 );
		$shortened = array();
		foreach ( $ids as $order_id ) {
			// Per order by its index (no range UPDATE over the reservation indexes); guarded by the order state.
			$done = $wpdb->query( $wpdb->prepare(
				"UPDATE %i SET expires_at=%s WHERE order_id=%d AND status='active' AND expires_at > %s AND EXISTS (SELECT 1 FROM %i o WHERE o.order_id=%d AND o.payment_provider='manual' AND o.payment_status='pending')",
				$p . 'wpcap_reservations',
				$ended,
				$order_id,
				$ended,
				$p . 'wpcap_orders',
				$order_id
			) );
			if ( $done ) { $shortened[] = $order_id; }
		}
		$released = 0;
		$expired_ids = array();
		if ( $shortened ) {
			Orders::expire_holds( count( $shortened ), $shortened );
			$states = $wpdb->get_results( 'SELECT order_id,payment_status FROM ' . $p . 'wpcap_orders WHERE order_id IN (' . implode( ',', $shortened ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			foreach ( (array) $states as $state ) {
				if ( 'expired' !== (string) $state->payment_status ) { continue; }
				$expired_ids[] = (int) $state->order_id;
				Audit::record( 'manual_hold_released', 'order', (int) $state->order_id, array( 'event_id' => absint( $event_id ), 'min_hours' => absint( $min_hours ) ) );
			}
			$released = count( $expired_ids );
		}
		Audit::record( 'manual_holds_bulk_release', 'event', absint( $event_id ), array( 'requested' => count( $wanted ), 'released' => $released, 'min_hours' => absint( $min_hours ), 'order_ids' => array_slice( $expired_ids, 0, 200 ) ) );
		return array( 'released' => $released, 'skipped' => count( $wanted ) - $released );
	}

	/**
	 * Applies a release request. Input: event_id, min_hours, ids[] (orders shown in the list), confirm=1.
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		if ( empty( $in['confirm'] ) ) {
			return Forms::error( 'wpcap_manual_release_confirm', __( 'Bitte bestätige, dass die gezeigten Bestellungen freigegeben werden sollen.', 'pass-captain' ), 400, 'confirm' );
		}
		$ids = Forms::ids( $in, 'ids' );
		if ( ! $ids ) {
			return Forms::error( 'wpcap_manual_release_empty', __( 'Keine Bestellungen ausgewählt.', 'pass-captain' ) );
		}
		return self::release( $ids, absint( $in['event_id'] ?? 0 ), absint( $in['min_hours'] ?? 0 ) );
	}

	/** admin-post handler (capability before the nonce). */
	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), '', array( 'response' => 403, 'back_link' => true ) ); }
		Forms::verify( self::ACTION );
		$in = Forms::post();
		$result = self::admin_action( $in );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			/* translators: %d: number of orders */
			$message = sprintf( _n( '%d Überweisungsbestellung wurde freigegeben (wie bei Ablauf der Reservierung).', '%d Überweisungsbestellungen wurden freigegeben (wie bei Ablauf der Reservierung).', $result['released'], 'pass-captain' ), $result['released'] );
			if ( $result['skipped'] > 0 ) {
				/* translators: %d: number of orders */
				$result['warning'] = sprintf( _n( '%d Bestellung wurde übersprungen (inzwischen bezahlt, abgelaufen oder geändert).', '%d Bestellungen wurden übersprungen (inzwischen bezahlt, abgelaufen oder geändert).', $result['skipped'], 'pass-captain' ), $result['skipped'] );
			}
		}
		Forms::finish( $result, $message, self::url( array_filter( array( 'manual_event' => absint( $in['event_id'] ?? 0 ), 'manual_hours' => absint( $in['min_hours'] ?? 0 ) ) ) ) );
	}

	/** One line for the overview tab: how much is held by open bank transfers, with a link to the section. */
	public static function render_summary() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$events = self::events_with_holds();
		if ( ! $events ) { return; }
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html(
			/* translators: %d: number of events */
			sprintf( _n( 'Offene Überweisungsbestellungen halten Tickets für %d Veranstaltung – ansehen und freigeben', 'Offene Überweisungsbestellungen halten Tickets für %d Veranstaltungen – ansehen und freigeben', count( $events ), 'pass-captain' ), count( $events ) )
		) . '</a></p>';
	}

	/** Section on the tab "Betrieb": filter, list, confirmed release. */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$event_id = absint( Forms::query_arg( 'manual_event', 0 ) );
		$min_hours = absint( Forms::query_arg( 'manual_hours', 24 ) );
		echo '<h2 id="' . esc_attr( self::ANCHOR ) . '">' . esc_html__( 'Offene Überweisungen freigeben', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Unbezahlte Überweisungsbestellungen halten ihre Tickets bis zum Ende der Reservierung. Hier kannst du mehrere davon vorzeitig freigeben, z. B. wenn ein Event durch viele offene Überweisungen blockiert ist. Die Freigabe wirkt wie ein normaler Ablauf der Reservierung: Die Tickets werden wieder verkäuflich, die Bestellung gilt als abgelaufen, und eine später eingehende Zahlung wird wie bei jeder abgelaufenen Bestellung geprüft.', 'pass-captain' ) . '</p>';
		$events = self::events_with_holds();
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		Forms::hidden( 'page', StatusPage::SLUG );
		Forms::hidden( 'tab', StatusPage::TAB_OPERATIONS );
		echo '<label>' . esc_html__( 'Veranstaltung', 'pass-captain' ) . ' ';
		Forms::select( 'manual_event', array( 0 => __( 'Alle Veranstaltungen', 'pass-captain' ) ) + $events, (string) $event_id );
		echo '</label> <label>' . esc_html__( 'Bestellt vor mindestens … Stunden', 'pass-captain' ) . ' <input type="number" name="manual_hours" min="0" max="720" value="' . esc_attr( (string) $min_hours ) . '"></label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button></form>';
		$rows = self::candidates( $event_id, $min_hours, self::MAX );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine offenen Überweisungsbestellungen für diese Auswahl.', 'pass-captain' ) . '</p>';
			return;
		}
		$tickets = array_sum( array_map( static function ( $row ) { return (int) $row['tickets']; }, $rows ) );
		/* translators: 1: number of orders, 2: number of tickets */
		$question = sprintf( __( '%1$d offene Überweisungsbestellungen mit %2$d Tickets jetzt freigeben? Das lässt sich nicht rückgängig machen.', 'pass-captain' ), count( $rows ), $tickets );
		Forms::open_form( self::ACTION, null, 'data-wpcap-confirm="' . esc_attr( $question ) . '"' );
		Forms::hidden( 'event_id', $event_id );
		Forms::hidden( 'min_hours', $min_hours );
		echo '<table class="widefat striped" style="max-width:60em"><thead><tr><th scope="col">' . esc_html__( 'Bestellung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'E-Mail', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bestellt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Tickets', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Betrag', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Reserviert bis', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><input type="hidden" name="ids[]" value="' . esc_attr( (string) (int) $row['order_id'] ) . '"><a href="' . esc_url( OrdersPage::order_url( (int) $row['order_id'] ) ) . '">' . esc_html( \PassCaptain\Frontend\Format::order_reference( (string) $row['public_id'] ) ) . '</a></td>';
			echo '<td>' . esc_html( (string) $row['customer_email'] ) . '</td>';
			echo '<td>' . esc_html( Forms::datetime( (string) $row['created_at'], wp_timezone_string(), false ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['tickets'] ) . '</td>';
			echo '<td>' . esc_html( Forms::money( (int) $row['total_minor'], (string) $row['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( Forms::datetime( (string) $row['hold_until'], wp_timezone_string(), false ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $rows ) >= self::MAX ) {
			/* translators: %d: maximum number of orders */
			echo '<p class="description">' . esc_html( sprintf( __( 'Es werden höchstens %d Bestellungen auf einmal angezeigt und freigegeben.', 'pass-captain' ), self::MAX ) ) . '</p>';
		}
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Ich habe die Liste geprüft: Diese Bestellungen sollen freigegeben werden.', 'pass-captain' ) . '</label></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Gezeigte Bestellungen freigeben', 'pass-captain' ) . '</button></p></form>';
	}
}
