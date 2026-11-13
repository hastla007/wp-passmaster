<?php
/**
 * Tickets admin: search, revoke or reissue a valid ticket, undo a check-in.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Access;
use PassCaptain\Audit;
use PassCaptain\Privacy\AccessLog;

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
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_revoke_reason', __( 'Bitte einen Grund für den Widerruf angeben.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$updated = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id=%d AND status='valid'", (int) $ticket->ticket_id ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige (nicht eingecheckte) Tickets können widerrufen werden.', 'pass-captain' ), 409 );
		}
		$release = Forms::flag( $in, 'release_capacity' );
		if ( $release ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_order_items SET refunded_quantity=LEAST(quantity,refunded_quantity+1) WHERE order_id=%d AND ticket_type_id=%d ORDER BY item_id LIMIT 1", (int) $ticket->order_id, (int) $ticket->ticket_type_id ) );
		}
		$wpdb->query( 'COMMIT' );
		if ( $release ) { \PassCaptain\CapacityCounters::sync_order_now( (int) $ticket->order_id ); }
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
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
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
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_checkin' ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", (int) $ticket->ticket_id ) );
		if ( ! $row || 'checked_in' !== $row->status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ), 409 );
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

	/**
	 * Corrects the attendee name and e-mail of a ticket (right to rectification, Art. 16 GDPR) for the
	 * same person: the ticket code stays valid (a change of person uses "Neu ausstellen"). Requires
	 * wpcap_manage_orders for the ticket's event and a reason. Audited as record_corrected with a masked
	 * before/after diff (field names, masked values).
	 */
	public static function correct_attendee( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		if ( ! in_array( (string) $ticket->status, array( 'valid', 'checked_in' ), true ) ) {
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige oder eingecheckte Tickets können korrigiert werden.', 'pass-captain' ), 409 );
		}
		$name = Forms::text( $in, 'attendee_name', 190 );
		$email = sanitize_email( Forms::text( $in, 'attendee_email', 190 ) );
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_correction_reason', __( 'Bitte einen Grund für die Korrektur angeben.', 'pass-captain' ) ); }
		if ( '' !== $email && ! is_email( $email ) ) { return Forms::error( 'wpcap_correction_email', __( 'Die E-Mail-Adresse ist ungültig.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT attendee_name,attendee_email FROM {$p}wpcap_ticket_assignments WHERE ticket_id=%d", (int) $ticket->ticket_id ) );
		$before = array(
			'attendee_name' => $assignment ? (string) $assignment->attendee_name : '',
			'attendee_email' => $assignment && '' !== (string) $assignment->attendee_email ? (string) $assignment->attendee_email : (string) $ticket->attendee_email,
		);
		$after = array( 'attendee_name' => $name, 'attendee_email' => $email );
		if ( AccessLog::diff( $before, $after ) === array() ) {
			return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'changed' => false );
		}
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $email ), array( 'ticket_id' => (int) $ticket->ticket_id ), array( '%s' ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", (int) $ticket->ticket_id, (string) $ticket->customer_email, $email, $name, current_time( 'mysql', true ) ) );
		if ( $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_correction_failed', __( 'Die Teilnehmerdaten konnten nicht gespeichert werden.', 'pass-captain' ), 500 );
		}
		$wpdb->query( 'COMMIT' );
		$changes = AccessLog::correction( 'ticket', (int) $ticket->ticket_id, $before, $after, array( 'order_id' => (int) $ticket->order_id, 'event_id' => (int) $ticket->event_id, 'source' => 'admin', 'reason' => $reason ) );
		/** Staff corrected personal data of a record (A122); $changes holds field names with masked values. */
		do_action( 'wpcap_record_corrected', 'ticket', (int) $ticket->ticket_id, $changes );
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'changed' => true );
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

	public static function handle_correct_attendee() {
		$in = Forms::post();
		Forms::verify( 'wpcap_correct_attendee_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::correct_attendee( $in );
		$message = ! is_wp_error( $result ) && empty( $result['changed'] ) ? __( 'Keine Änderung – die Teilnehmerdaten waren bereits so gespeichert.', 'pass-captain' ) : __( 'Teilnehmerdaten korrigiert. Der Ticketcode bleibt gültig.', 'pass-captain' );
		Forms::finish( $result, $message, self::back_url( $in ) );
	}

	public static function handle_undo_checkin() {
		$in = Forms::post();
		Forms::verify( 'wpcap_undo_checkin_' . Forms::int( $in, 'ticket_id' ) );
		Forms::finish( self::undo_checkin( $in ), __( 'Check-in zurückgenommen; das Ticket ist wieder gültig.', 'pass-captain' ), self::back_url( $in ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		if ( array() === $scope && ! array_filter( self::VIEW_CAPS, 'current_user_can' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 );
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
		if ( $has_filter ) { \PassCaptain\Privacy\AccessLog::list_view( 'ticket_list', $filters + array( 'paged' => $page ), count( $data['rows'] ) ); }
		$status_options = array( '' => __( 'Alle Status', 'pass-captain' ) );
		foreach ( self::STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="search" name="s" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, E-Mail oder Name', 'pass-captain' ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + Queries::event_options( $scope, array( (int) $filters['event_id'] ) ), $filters['event_id'] ); ?>
			<?php Forms::select( 'status', $status_options, $filters['status'] ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Suchen', 'pass-captain' ); ?></button>
		</form>
		<?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?>
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
					$email = \PassCaptain\Pii::show( 'attendee_email', '' !== (string) $ticket->assigned_email ? $ticket->assigned_email : $ticket->attendee_email );
					$buyer_email = \PassCaptain\Pii::show( 'buyer_email', $ticket->customer_email );
					$attendee_name = \PassCaptain\Pii::show( 'attendee_name', (string) $ticket->attendee_name );
					$can_orders = Access::can_access_event( $ticket->event_id, 'wpcap_manage_orders' );
					$can_checkin = Access::can_access_event( $ticket->event_id, 'wpcap_checkin' );
					?>
					<tr>
						<td><code><?php echo esc_html( $ticket->public_id ); ?></code><br><small><?php echo esc_html( $ticket->ticket_name ); ?></small></td>
						<td><?php echo esc_html( $ticket->event_name ); ?><br><small><?php echo esc_html( Forms::datetime( $ticket->starts_at, $ticket->timezone ) ); ?></small></td>
						<td>
							<?php if ( $can_orders ) : ?><a href="<?php echo esc_url( OrdersPage::order_url( $ticket->order_id ) ); ?>"><?php echo esc_html( $buyer_email ); ?></a>
							<?php else : ?><?php echo esc_html( $buyer_email ); ?><?php endif; ?>
						</td>
						<td><?php echo esc_html( trim( $attendee_name . ' ' . ( $email ? '<' . $email . '>' : '' ) ) ?: '—' ); ?></td>
						<td>
							<?php echo esc_html( Forms::status_label( $ticket->status ) . ( (int) $ticket->refunded ? ' (' . __( 'erstattet', 'pass-captain' ) . ')' : '' ) ); ?>
							<?php if ( $ticket->last_checkin_at ) : ?><br><small><?php echo esc_html( Forms::datetime( $ticket->last_checkin_at, $ticket->timezone, false ) ); ?></small><?php endif; ?>
						</td>
						<td>
							<?php if ( 'valid' === $ticket->status && $can_orders ) : ?>
								<?php Forms::open_form( 'wpcap_revoke_ticket', 'wpcap_revoke_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund', 'pass-captain' ); ?>">
								<label><input type="checkbox" name="release_capacity" value="1"> <?php esc_html_e( 'Platz freigeben', 'pass-captain' ); ?></label>
								<button class="button" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Ticket widerrufen? Es kann danach nicht mehr eingelöst werden. Eine Erstattung erfolgt nicht automatisch.', 'pass-captain' ) ); ?>')"><?php esc_html_e( 'Widerrufen', 'pass-captain' ); ?></button>
								</form>
								<?php Forms::open_form( 'wpcap_reissue_ticket', 'wpcap_reissue_ticket_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<button class="button" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Ticket neu ausstellen? Der bisherige Code wird ungültig und der Inhaber erhält einen neuen Ticketlink.', 'pass-captain' ) ); ?>')"><?php esc_html_e( 'Neu ausstellen', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) && $can_orders ) : ?>
								<details><summary><?php esc_html_e( 'Teilnehmerdaten korrigieren', 'pass-captain' ); ?></summary>
								<?php Forms::open_form( 'wpcap_correct_attendee', 'wpcap_correct_attendee_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<p><label><?php esc_html_e( 'Name', 'pass-captain' ); ?> <input type="text" name="attendee_name" maxlength="190" value="<?php echo esc_attr( (string) $ticket->attendee_name ); ?>"></label></p>
								<p><label><?php esc_html_e( 'E-Mail', 'pass-captain' ); ?> <input type="email" name="attendee_email" maxlength="190" value="<?php echo esc_attr( (string) $email ); ?>"></label></p>
								<p><input type="text" name="reason" required maxlength="255" placeholder="<?php esc_attr_e( 'Grund der Korrektur', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Grund der Korrektur', 'pass-captain' ); ?>">
								<button class="button" type="submit"><?php esc_html_e( 'Korrektur speichern', 'pass-captain' ); ?></button></p>
								<p class="description"><?php esc_html_e( 'Nur für Tippfehler derselben Person; der Ticketcode bleibt gültig. Für eine andere Person „Neu ausstellen“ verwenden.', 'pass-captain' ); ?></p>
								</form></details>
							<?php endif; ?>
							<?php if ( 'checked_in' === $ticket->status && $can_checkin ) : ?>
								<?php Forms::open_form( 'wpcap_undo_checkin', 'wpcap_undo_checkin_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
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
