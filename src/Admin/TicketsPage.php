<?php
/**
 * Tickets admin: search, revoke a valid ticket, undo a check-in.
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
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'wp-pass-captain' ) ); }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_revoke_reason', __( 'Bitte einen Grund für den Widerruf angeben.', 'wp-pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$updated = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id=%d AND status='valid'", (int) $ticket->ticket_id ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige (nicht eingecheckte) Tickets können widerrufen werden.', 'wp-pass-captain' ), 409 );
		}
		$release = Forms::flag( $in, 'release_capacity' );
		if ( $release ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+1) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", (int) $ticket->order_id, (int) $ticket->ticket_type_id ) );
		}
		$wpdb->query( 'COMMIT' );
		Audit::record( 'ticket_revoked', 'ticket', (int) $ticket->ticket_id, array( 'order_id' => (int) $ticket->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => $reason, 'capacity_released' => $release ) );
		do_action( 'wpcap_ticket_revoked', (int) $ticket->ticket_id, $reason );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id );
	}

	/**
	 * Undoes a check-in: checked_in → valid, logged as a check-in row with result "undone".
	 * Requires wpcap_checkin for the ticket's event.
	 */
	public static function undo_checkin( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'wp-pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_checkin' ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'wp-pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", (int) $ticket->ticket_id ) );
		if ( ! $row || 'checked_in' !== $row->status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'wp-pass-captain' ), 409 );
		}
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_day_id FROM {$p}wpcap_checkins WHERE ticket_id=%d AND result='checked_in' ORDER BY checkin_id DESC LIMIT 1", (int) $ticket->ticket_id ) );
		$wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'checked_in' ), array( '%s' ), array( '%d', '%s' ) );
		$wpdb->insert( "{$p}wpcap_checkins", array(
			'ticket_id' => (int) $ticket->ticket_id,
			'event_id' => (int) $ticket->event_id,
			'occurrence_day_id' => $last ? (int) $last->occurrence_day_id : 0,
			'user_id' => get_current_user_id(),
			'result' => 'undone',
			'created_at' => $now,
		), array( '%d', '%d', '%d', '%d', '%s', '%s' ) );
		$wpdb->query( 'COMMIT' );
		Audit::record( 'checkin_undone', 'ticket', (int) $ticket->ticket_id, array( 'event_id' => (int) $ticket->event_id, 'reason' => Forms::text( $in, 'reason', 255 ) ) );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id );
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
		Forms::finish( self::revoke_ticket( $in ), __( 'Ticket widerrufen.', 'wp-pass-captain' ), self::back_url( $in ) );
	}

	public static function handle_undo_checkin() {
		$in = Forms::post();
		Forms::verify( 'wpcap_undo_checkin_' . Forms::int( $in, 'ticket_id' ) );
		Forms::finish( self::undo_checkin( $in ), __( 'Check-in zurückgenommen; das Ticket ist wieder gültig.', 'wp-pass-captain' ), self::back_url( $in ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		if ( array() === $scope && ! array_filter( self::VIEW_CAPS, 'current_user_can' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 );
		}
		$filters = array(
			'search' => Forms::query_arg( 's' ),
			'event_id' => absint( Forms::query_arg( 'event_id', 0 ) ),
			'status' => in_array( Forms::query_arg( 'status' ), self::STATUSES, true ) ? Forms::query_arg( 'status' ) : '',
		);
		$has_filter = '' !== $filters['search'] || $filters['event_id'] || '' !== $filters['status'];
		$page = Forms::current_page();
		$per_page = 30;
		$data = $has_filter ? Queries::search_tickets( $filters, $scope, $page, $per_page ) : array( 'rows' => array(), 'total' => 0 );
		$status_options = array( '' => __( 'Alle Status', 'wp-pass-captain' ) );
		foreach ( self::STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Tickets', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="search" name="s" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, E-Mail oder Name', 'wp-pass-captain' ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'wp-pass-captain' ) ) + Queries::event_options( $scope ), $filters['event_id'] ); ?>
			<?php Forms::select( 'status', $status_options, $filters['status'] ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Suchen', 'wp-pass-captain' ); ?></button>
		</form>
		<?php if ( ! $has_filter ) : ?>
			<p><?php esc_html_e( 'Bitte nach Ticket, Käufer oder Teilnehmer suchen oder ein Event wählen.', 'wp-pass-captain' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Ticket', 'wp-pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Event / Termin', 'wp-pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Käufer', 'wp-pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Teilnehmer', 'wp-pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
					<th><?php esc_html_e( 'Aktion', 'wp-pass-captain' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $data['rows'] ) : ?><tr><td colspan="6"><?php esc_html_e( 'Keine Tickets gefunden.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $data['rows'] as $ticket ) :
					$email = '' !== (string) $ticket->assigned_email ? $ticket->assigned_email : $ticket->attendee_email;
					$can_orders = Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' );
					$can_checkin = Access::can_access_event( $ticket->event_id, 'wpcap_checkin' );
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
							<?php echo esc_html( Forms::status_label( $ticket->status ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'wp-pass-captain' ) . ')' : '' ) ); ?>
							<?php if ( $ticket->last_checkin_at ) : ?><br><small><?php echo esc_html( Forms::datetime( $ticket->last_checkin_at, $ticket->timezone, false ) ); ?></small><?php endif; ?>
						</td>
						<td>
							<?php if ( 'valid' === $ticket->status && $can_orders ) : ?>
								<?php Forms::open_form( 'wpcap_revoke_ticket', 'wpcap_revoke_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund', 'wp-pass-captain' ); ?>">
								<label><input type="checkbox" name="release_capacity" value="1"> <?php esc_html_e( 'Platz freigeben', 'wp-pass-captain' ); ?></label>
								<button class="button" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Ticket widerrufen? Es kann danach nicht mehr eingelöst werden. Eine Erstattung erfolgt nicht automatisch.', 'wp-pass-captain' ) ); ?>')"><?php esc_html_e( 'Widerrufen', 'wp-pass-captain' ); ?></button>
								</form>
							<?php elseif ( 'checked_in' === $ticket->status && $can_checkin ) : ?>
								<?php Forms::open_form( 'wpcap_undo_checkin', 'wpcap_undo_checkin_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<input type="text" name="reason" maxlength="255" placeholder="<?php esc_attr_e( 'Grund (optional)', 'wp-pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Check-in zurücknehmen', 'wp-pass-captain' ); ?></button>
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
