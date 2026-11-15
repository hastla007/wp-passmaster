<?php
/**
 * Tickets admin: search, revoke or reissue a valid ticket, undo a check-in.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketsPage {
	const SLUG = 'wpcap-tickets';
	const VIEW_CAPS = array( 'wpcap_manage_orders', 'wpcap_checkin' );
	const STATUSES = array( 'valid', 'checked_in', 'revoked' );

	/** Menu capability for the current user (first matching view capability). */
	public static function menu_cap() {
		foreach ( self::VIEW_CAPS as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return self::VIEW_CAPS[0];
	}

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/**
	 * Revokes a valid ticket. Requires wpcap_manage_orders for the ticket's event and a reason.
	 * With release_capacity the seat becomes sellable again (counted as refunded quantity).
	 */
	public static function revoke_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_revoke_reason', __( 'Bitte einen Grund für den Widerruf angeben.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$updated = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='revoked' WHERE ticket_id=%d AND status='valid'", $p . 'wpcap_tickets', (int) $ticket->ticket_id ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige (nicht eingecheckte) Tickets können widerrufen werden.', 'pass-captain' ), 409 );
		}
		$release = Forms::flag( $in, 'release_capacity' );
		if ( $release ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_quantity=LEAST(quantity,refunded_quantity+1) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1', $p . 'wpcap_order_items', (int) $ticket->order_id, (int) $ticket->ticket_type_id ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'ticket_revoked', 'ticket', (int) $ticket->ticket_id, array( 'order_id' => (int) $ticket->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => $reason, 'capacity_released' => $release ) );
		do_action( 'wpcap_ticket_revoked', (int) $ticket->ticket_id, $reason );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id );
	}

	/**
	 * Reissues one valid ticket (new code, old QR code invalid) and mails it to the holder.
	 * Requires wpcap_manage_orders for the ticket's event.
	 */
	public static function reissue_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$result = \PassCaptain\Orders::reissue_ticket( (int) $ticket->ticket_id, array( 'reason' => Forms::text( $in, 'reason', 255 ) ) );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return Forms::error( $result->get_error_code(), $result->get_error_message(), (int) ( $data['status'] ?? 409 ) );
		}
		return $result + array( 'public_id' => $ticket->public_id );
	}

	/**
	 * Undoes a check-in: checked_in → valid, logged as a check-in row with result "undone".
	 * Requires wpcap_checkin for the ticket's event.
	 */
	public static function undo_checkin( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( (int) $ticket->occurrence_id, 'wpcap_checkin', (int) $ticket->event_id ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) ); }
		$denied = \PassCaptain\Checkin::undo_denied( (int) $ticket->ticket_id, (int) $ticket->event_id, array( 'device_id' => Forms::int( $in, 'device_id' ) ) );
		if ( $denied ) { return Forms::error( $denied->get_error_code(), $denied->get_error_message(), 403 ); }
		$key = Forms::text( $in, 'idempotency_key', 190 );
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_db_busy', __( 'Die Änderung konnte gerade nicht gespeichert werden. Bitte erneut versuchen.', 'pass-captain' ), 503 );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT t.ticket_id,t.status,t.refunded,ord.status AS order_status,ord.payment_status FROM %i t INNER JOIN %i ord ON ord.order_id=t.order_id WHERE t.ticket_id=%d FOR UPDATE', $p . 'wpcap_tickets', $p . 'wpcap_orders', (int) $ticket->ticket_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy(); }
		// A retried undo (same key, e.g. a double submit or an app retry) never removes a second entry.
		$claim = '' !== $key ? \PassCaptain\Checkin::claim_key( $key, (int) $ticket->ticket_id ) : 1;
		if ( $claim < 0 ) { return $busy(); }
		if ( 0 === $claim ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'status' => $row ? (string) $row->status : '', 'replayed' => true );
		}
		if ( ! $row || 'checked_in' !== $row->status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ), 409 );
		}
		// A checked-in ticket keeps its status when it is refunded or its order is cancelled/refunded;
		// undoing the check-in must not turn it back into a valid (admissible) ticket.
		if ( (int) $row->refunded || 'paid' !== $row->payment_status || 'complete' !== $row->order_status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_undoable', __( 'Der Check-in eines erstatteten oder stornierten Tickets kann nicht zurückgenommen werden.', 'pass-captain' ), 409 );
		}
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_day_id FROM %i WHERE ticket_id=%d AND result='checked_in' ORDER BY checkin_id DESC LIMIT 1", $p . 'wpcap_checkins', (int) $ticket->ticket_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $busy(); }
		// Pending lazy undos are applied first, so the state below is current.
		if ( false === \PassCaptain\Admission\Engine::state_for_update( (int) $ticket->ticket_id ) ) { return $busy(); }
		$logged = $wpdb->insert( "{$p}wpcap_checkins", array(
			'ticket_id' => (int) $ticket->ticket_id,
			'event_id' => (int) $ticket->event_id,
			'occurrence_day_id' => $last ? (int) $last->occurrence_day_id : 0,
			'user_id' => get_current_user_id(),
			'result' => 'undone',
			'device_id' => Forms::int( $in, 'device_id' ),
			'created_at' => $now,
		), array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' ) );
		$undo_id = false === $logged ? 0 : (int) $wpdb->insert_id;
		if ( ! $undo_id ) { return $busy(); }
		// Only the last entry is taken back; the ticket is valid again only when no entry remains
		// (after a re-entry the first admission still counts and the ticket stays checked in).
		$remaining = \PassCaptain\Admission\Engine::revert_last_entry( (int) $ticket->ticket_id, $undo_id );
		if ( false === $remaining ) { return $busy(); }
		if ( 0 === $remaining && 1 !== (int) $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'checked_in' ), array( '%s' ), array( '%d', '%s' ) ) ) { return $busy(); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		Audit::record( 'checkin_undone', 'ticket', (int) $ticket->ticket_id, array( 'event_id' => (int) $ticket->event_id, 'reason' => Forms::text( $in, 'reason', 255 ), 'remaining_entries' => $remaining ) );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'status' => $remaining ? 'checked_in' : 'valid', 'remaining_entries' => $remaining );
	}

	/* =============================================================================================
	 * admin-post wrappers
	 * =========================================================================================== */

	private static function back_url( array $in ) {
		$search = Forms::text( $in, 'return_search', 190 );
		return Forms::page_url( self::SLUG, '' !== $search ? array( 's' => $search ) : array() );
	}

	public static function handle_revoke_ticket() {
		$in = Forms::post();
		Forms::verify( 'wpcap_revoke_ticket_' . Forms::int( $in, 'ticket_id' ) );
		Forms::finish( self::revoke_ticket( $in ), __( 'Ticket widerrufen.', 'pass-captain' ), self::back_url( $in ) );
	}

	public static function handle_reissue_ticket() {
		$in = Forms::post();
		Forms::verify( 'wpcap_reissue_ticket_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::reissue_ticket( $in );
		$message = ! is_wp_error( $result ) && empty( $result['mailed'] )
			? __( 'Ticket neu ausgestellt. Die E-Mail an den Inhaber konnte nicht zugestellt werden; bitte erneut versuchen.', 'pass-captain' )
			: __( 'Ticket neu ausgestellt und an den Inhaber gesendet. Der bisherige Code ist ungültig.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	public static function handle_undo_checkin() {
		$in = Forms::post();
		Forms::verify( 'wpcap_undo_checkin_' . Forms::int( $in, 'ticket_id' ) );
		// Every form carries its own key: a double submit or a resent POST undoes only once.
		$result = '' === Forms::text( $in, 'idempotency_key', 190 )
			? Forms::error( 'wpcap_idempotency_required', __( 'idempotency_key ist erforderlich.', 'pass-captain' ), 400 )
			: self::undo_checkin( $in );
		$message = ! is_wp_error( $result ) && ! empty( $result['remaining_entries'] )
			? __( 'Letzter Einlass zurückgenommen; der frühere Einlass bleibt gezählt, das Ticket bleibt eingecheckt.', 'pass-captain' )
			: __( 'Check-in zurückgenommen; das Ticket ist wieder gültig.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	/**
	 * Ticket search in the current user's scope: events with an event-level assignment plus – for
	 * date-level staff (wpcap_occurrence_staff) – the tickets of their assigned dates only.
	 *
	 * @return array{rows: object[], total: int}
	 */
	public static function search( array $filters, $page = 1, $per_page = 30 ) {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		$dates = null === $scope ? array() : Queries::occurrence_ids_for( self::VIEW_CAPS );
		return Queries::search_tickets( $filters, $scope, $page, $per_page, $dates );
	}

	public static function render() {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		if ( array() === $scope && ! array_filter( self::VIEW_CAPS, 'current_user_can' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 );
		}
		// Events offered in the filter: event-level scope plus events of assigned dates.
		$option_scope = null === $scope ? null : array_values( array_unique( array_merge( $scope, Access::occurrence_event_ids( 'wpcap_checkin' ), Access::occurrence_event_ids( 'wpcap_manage_orders' ) ) ) );
		$filters = array(
			'search' => Forms::query_arg( 's' ),
			'event_id' => absint( Forms::query_arg( 'event_id', 0 ) ),
			'status' => in_array( Forms::query_arg( 'status' ), self::STATUSES, true ) ? Forms::query_arg( 'status' ) : '',
		);
		$has_filter = '' !== $filters['search'] || $filters['event_id'] || '' !== $filters['status'];
		$page = Forms::current_page();
		$per_page = 30;
		$data = $has_filter ? self::search( $filters, $page, $per_page ) : array( 'rows' => array(), 'total' => 0 );
		if ( $has_filter ) {
			// Data minimisation per role and read audit (no search terms in the log).
			$data['rows'] = array_map( array( \PassCaptain\Security\DataPolicy::class, 'ticket_view' ), $data['rows'] );
			\PassCaptain\Security\AuditTrail::read( 'ticket_search', 'event', (int) $filters['event_id'], array( 'results' => count( $data['rows'] ), 'page' => $page, 'scope' => md5( (string) $filters['search'] ) . $page ) );
		}
		$status_options = array( '' => __( 'Alle Status', 'pass-captain' ) );
		foreach ( self::STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="search" name="s" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, E-Mail oder Name', 'pass-captain' ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + Queries::event_options( $option_scope ), $filters['event_id'], '', Forms::aria_label( __( 'Event', 'pass-captain' ) ) ); ?>
			<?php Forms::select( 'status', $status_options, $filters['status'], '', Forms::aria_label( __( 'Status', 'pass-captain' ) ) ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Suchen', 'pass-captain' ); ?></button>
		</form>
		<?php if ( ! $has_filter ) : ?>
			<p><?php esc_html_e( 'Bitte nach Ticket, Käufer oder Teilnehmer suchen oder ein Event wählen.', 'pass-captain' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Ticket', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Event / Termin', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Käufer', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Teilnehmer', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Status', 'pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Aktion', 'pass-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $data['rows'] ) : ?><tr><td colspan="6"><?php esc_html_e( 'Keine Tickets gefunden.', 'pass-captain' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $data['rows'] as $ticket ) :
					$email = '' !== (string) ( $ticket->assigned_email ?? '' ) ? $ticket->assigned_email : ( $ticket->attendee_email ?? '' );
					$can_orders = Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' );
					$can_checkin = Access::can_access_occurrence( (int) $ticket->occurrence_id, 'wpcap_checkin', (int) $ticket->event_id );
					?>
					<tr>
						<td><code><?php echo esc_html( $ticket->public_id ); ?></code><br><small><?php echo esc_html( $ticket->ticket_name ); ?></small></td>
						<td><?php echo esc_html( $ticket->event_name ); ?><br><small><?php echo esc_html( Forms::datetime( $ticket->starts_at, $ticket->timezone ) ); ?></small></td>
						<td>
							<?php if ( $can_orders ) : ?><a href="<?php echo esc_url( OrdersPage::order_url( $ticket->order_id ) ); ?>"><?php echo esc_html( $ticket->customer_email ); ?></a>
							<?php else : ?><?php echo esc_html( $ticket->customer_email ); ?><?php endif; ?>
						</td>
						<td><?php echo esc_html( trim( (string) $ticket->attendee_name . ' ' . ( $email ? '<' . $email . '>' : '' ) ) ?: '—' ); ?></td>
						<td>
							<?php echo esc_html( Forms::status_label( $ticket->status ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'pass-captain' ) . ')' : '' ) ); ?>
							<?php if ( $ticket->last_checkin_at ) : ?><br><small><?php echo esc_html( Forms::datetime( $ticket->last_checkin_at, $ticket->timezone, false ) ); ?></small><?php endif; ?>
						</td>
						<td>
							<?php if ( 'valid' === $ticket->status && $can_orders ) : ?>
								<?php Forms::open_form( 'wpcap_revoke_ticket', 'wpcap_revoke_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); Forms::hidden( 'idempotency_key', wp_generate_uuid4() ); ?>
								<input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund', 'pass-captain' ); ?>">
								<label><input type="checkbox" name="release_capacity" value="1"> <?php esc_html_e( 'Platz freigeben', 'pass-captain' ); ?></label>
								<button class="button" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Ticket widerrufen? Es kann danach nicht mehr eingelöst werden. Eine Erstattung erfolgt nicht automatisch.', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Widerrufen', 'pass-captain' ); ?></button>
								</form>
								<?php Forms::open_form( 'wpcap_reissue_ticket', 'wpcap_reissue_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<button class="button" type="submit" data-wpcap-confirm="<?php echo esc_attr( __( 'Ticket neu ausstellen? Der bisherige Code wird ungültig und der Inhaber erhält einen neuen Ticketlink.', 'pass-captain' ) ); ?>"><?php esc_html_e( 'Neu ausstellen', 'pass-captain' ); ?></button>
								</form>
							<?php elseif ( 'checked_in' === $ticket->status && $can_checkin ) : ?>
								<?php Forms::open_form( 'wpcap_undo_checkin', 'wpcap_undo_checkin_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); Forms::hidden( 'idempotency_key', wp_generate_uuid4() ); ?>
								<input type="text" name="reason" maxlength="255" placeholder="<?php esc_attr_e( 'Grund (optional)', 'pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Check-in zurücknehmen', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php Forms::pagination( $data['total'], $page, $per_page, Forms::page_url( self::SLUG, array_filter( array( 's' => $filters['search'], 'event_id' => $filters['event_id'], 'status' => $filters['status'] ) ) ) ); ?>
		<?php endif; ?>
		</div>
		<?php
	}
}
