<?php
/**
 * Tickets admin: search, revoke or reissue a valid ticket, check a ticket in manually, undo a check-in.
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
	 * With release_capacity the seat becomes sellable again (counted as refunded quantity, once per
	 * ticket via CapacityRelease, announced to waitlists and allocations). Refused while a refund of
	 * the ticket is pending or running (A537).
	 */
	public static function revoke_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_manage_orders' ) ) { return Forms::forbidden( __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ) ); }
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_revoke_reason', __( 'Bitte einen Grund für den Widerruf angeben.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$release = Forms::flag( $in, 'release_capacity' );
		$wpdb->query( 'START TRANSACTION' );
		// Order row first (same lock order as refunds and exchanges), then the ticket.
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE order_id=%d FOR UPDATE", (int) $ticket->order_id ) );
		// A537: while a refund of the ticket is pending or running, the refund decides about it.
		$open_refund = '' === $wpdb->last_error ? \PassCaptain\Refunds::ticket_has_open_refund( (int) $ticket->order_id, (int) $ticket->ticket_id ) : null;
		if ( false !== $open_refund ) {
			$wpdb->query( 'ROLLBACK' );
			return null === $open_refund
				? Forms::error( 'wpcap_db_busy', __( 'Das Ticket konnte gerade nicht widerrufen werden. Bitte erneut versuchen.', 'pass-captain' ), 503 )
				: Forms::error( 'wpcap_ticket_refund_open', __( 'Für dieses Ticket läuft eine Erstattung oder sie wartet auf Freigabe. Es kann bis zur Entscheidung nicht übertragen, umgetauscht oder widerrufen werden.', 'pass-captain' ), 409 );
		}
		$updated = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wpcap_tickets SET status='revoked' WHERE ticket_id=%d AND status='valid'", (int) $ticket->ticket_id ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_valid', __( 'Nur gültige (nicht eingecheckte) Tickets können widerrufen werden.', 'pass-captain' ), 409 );
		}
		// The unit is released once per ticket and recorded on it (CapacityRelease): a later refund or
		// exchange of the same ticket never releases it again (A538).
		$released = $release ? \PassCaptain\CapacityRelease::release_tickets( (int) $ticket->order_id, array( (int) $ticket->ticket_id ) ) : array();
		if ( false === $released || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_db_busy', __( 'Das Ticket konnte gerade nicht widerrufen werden. Bitte erneut versuchen.', 'pass-captain' ), 503 );
		}
		Audit::record( 'ticket_revoked', 'ticket', (int) $ticket->ticket_id, array( 'order_id' => (int) $ticket->order_id, 'event_id' => (int) $ticket->event_id, 'reason' => $reason, 'capacity_released' => $release ) );
		do_action( 'wpcap_ticket_revoked', (int) $ticket->ticket_id, $reason );
		// Waitlists and allocation holds get the released unit (A578).
		\PassCaptain\CapacityRelease::announce( (int) $ticket->order_id, $released, 'tickets_revoked', array( 'reason' => $reason, 'ticket_id' => (int) $ticket->ticket_id ) );
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
	 * Manual check-in of a valid ticket from the ticket list (A338), e.g. when the guest's code cannot
	 * be read. Requires wpcap_checkin for the ticket's occurrence; runs through Checkin::perform_ticket()
	 * with every rule of a scan (occurrence, area, admission policy, area capacity). The form's
	 * idempotency key makes a double submit harmless.
	 *
	 * @param array $in ticket_id, area_id (entrance area, when the event has areas), idempotency_key.
	 * @return array|\WP_Error
	 */
	public static function checkin_ticket( array $in ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_checkin' ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) ); }
		$key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $in['idempotency_key'] ?? '' ) );
		$context = array( 'area_id' => Forms::int( $in, 'area_id' ), 'source' => 'admin' );
		if ( strlen( $key ) >= 16 ) { $context['idempotency_key'] = 'admin-checkin|' . $key; }
		$result = \PassCaptain\Checkin::perform_ticket( (int) $ticket->ticket_id, $context );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( (array) $result->get_error_data() )['status'] ?? 409 );
			return Forms::error( $result->get_error_code(), $result->get_error_message(), 403 === $status ? 403 : 409 );
		}
		return array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => $ticket->public_id, 'replayed' => ! empty( $result['replayed'] ) );
	}

	/** Active entrance areas the current user may admit to for an event (empty without the admission module). */
	private static function checkin_areas( $event_id ) {
		if ( ! \PassCaptain\Modules::enabled( \PassCaptain\Modules\Admission::class ) || ! \PassCaptain\Privacy::has_table( 'wpcap_admission_areas' ) ) { return array(); }
		$areas = array();
		foreach ( \PassCaptain\Admission\Areas::selectable( array( (int) $event_id ) ) as $area ) { $areas[ (int) $area->area_id ] = (string) $area->name; }
		return $areas;
	}

	/**
	 * Undoes a check-in: checked_in → valid, logged as a check-in row with result "undone" (with device
	 * and source; the audit entry also names the area of the undone admission). Requires wpcap_checkin
	 * for the ticket's event; refused for refunded tickets and tickets of closed orders and outside the
	 * area binding of staff and device (Checkin::undo_guard(), A590). A reason is required (A547).
	 *
	 * @param array $in      ticket_id, reason.
	 * @param array $context Scanner context of the caller: device_id, area_id, source (default "admin").
	 */
	public static function undo_checkin( array $in, array $context = array() ) {
		$ticket = Queries::ticket_context( Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket ) { return Forms::error( 'wpcap_ticket_missing', __( 'Ticket nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! Access::can_access_occurrence( $ticket->occurrence_id, 'wpcap_checkin' ) ) { return Forms::forbidden( __( 'Für dieses Event ist kein Einlasszugriff zugewiesen.', 'pass-captain' ) ); }
		// A547: every undo names its reason (admin list, web scanner, app).
		if ( '' === Forms::text( $in, 'reason', 255 ) ) { return Forms::error( 'wpcap_undo_reason_required', __( 'Bitte einen Grund für die Rücknahme angeben.', 'pass-captain' ) ); }
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$busy = static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_db_busy', __( 'Der Check-in konnte gerade nicht zurückgenommen werden. Bitte erneut versuchen.', 'pass-captain' ), 503 );
		};
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $busy(); }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$p}wpcap_tickets WHERE ticket_id=%d FOR UPDATE", (int) $ticket->ticket_id ) );
		if ( '' !== $wpdb->last_error ) { return $busy(); }
		if ( ! $row || 'checked_in' !== $row->status ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_ticket_not_checked_in', __( 'Das Ticket ist nicht eingecheckt.', 'pass-captain' ), 409 );
		}
		// Earlier undo rows are applied to the admission log first, so the guard sees the admission
		// that is taken back now.
		\PassCaptain\Admission\Engine::state_for_update( (int) $ticket->ticket_id );
		$guard = \PassCaptain\Checkin::undo_guard( (int) $ticket->ticket_id, $context );
		if ( is_wp_error( $guard ) ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( $guard->get_error_code(), $guard->get_error_message(), (int) ( $guard->get_error_data()['status'] ?? 409 ) );
		}
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_day_id FROM {$p}wpcap_checkins WHERE ticket_id=%d AND result='checked_in' ORDER BY checkin_id DESC LIMIT 1", (int) $ticket->ticket_id ) );
		$device_id = absint( $context['device_id'] ?? 0 );
		$source = sanitize_key( (string) ( $context['source'] ?? '' ) );
		$source = '' !== $source ? $source : 'admin';
		$updated = $wpdb->update( "{$p}wpcap_tickets", array( 'status' => 'valid' ), array( 'ticket_id' => (int) $ticket->ticket_id, 'status' => 'checked_in' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== (int) $updated ) { return $busy(); }
		$wpdb->insert( "{$p}wpcap_checkins", array(
			'ticket_id' => (int) $ticket->ticket_id,
			'event_id' => (int) $ticket->event_id,
			'occurrence_day_id' => $last ? (int) $last->occurrence_day_id : 0,
			'user_id' => get_current_user_id(),
			'result' => 'undone',
			'device_id' => $device_id,
			'source' => $source,
			'created_at' => $now,
		), array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
		if ( '' !== $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) { return $busy(); }
		Audit::record( 'checkin_undone', 'ticket', (int) $ticket->ticket_id, array( 'event_id' => (int) $ticket->event_id, 'reason' => Forms::text( $in, 'reason', 255 ), 'device_id' => $device_id, 'area_id' => (int) $guard['area_id'], 'source' => $source ) );
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
		$email = \PassCaptain\Http::optional_email( $in['attendee_email'] ?? '' );
		$reason = Forms::text( $in, 'reason', 255 );
		if ( '' === $reason ) { return Forms::error( 'wpcap_correction_reason', __( 'Bitte einen Grund für die Korrektur angeben.', 'pass-captain' ) ); }
		if ( false === $email ) { return Forms::error( 'wpcap_correction_email', __( 'Die E-Mail-Adresse ist ungültig.', 'pass-captain' ) ); }
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
		\PassCaptain\TicketAssignments::touch( array( (int) $ticket->ticket_id ) ); // A574: partner API "since".
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

	public static function handle_checkin_ticket() {
		Forms::require_any_cap( array( 'wpcap_checkin' ) );
		$in = Forms::post();
		Forms::verify( 'wpcap_checkin_ticket_' . Forms::int( $in, 'ticket_id' ) );
		$result = self::checkin_ticket( $in );
		Forms::finish( $result, is_wp_error( $result ) || empty( $result['replayed'] ) ? __( 'Ticket eingecheckt.', 'pass-captain' ) : __( 'Das Ticket war bereits mit diesem Formular eingecheckt.', 'pass-captain' ), self::back_url( $in ) );
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
		// A339: rebooking to another date/ticket type straight from the list (exchange module).
		$exchange = class_exists( \PassCaptain\Exchange\AdminPage::class ) && \PassCaptain\Modules::enabled( \PassCaptain\Modules\Exchange::class );
		$status_options = array( '' => __( 'Alle Status', 'pass-captain' ) );
		foreach ( self::STATUSES as $s ) { $status_options[ $s ] = Forms::status_label( $s ); }
		?>
		<div class="wrap wpcap-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></h1>
		<?php if ( current_user_can( 'wpcap_checkin' ) ) : ?> <a class="page-title-action" href="<?php echo esc_url( Forms::page_url( ScannerPage::SLUG ) ); ?>"><?php esc_html_e( 'Web-Scanner öffnen', 'pass-captain' ); ?></a><?php endif; ?>
		<hr class="wp-header-end">
		<?php Forms::render_notices(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="search" name="s" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, Hilfscode, E-Mail oder Name', 'pass-captain' ); ?>" aria-label="<?php esc_attr_e( 'Ticket-ID, Bestellnummer, Hilfscode, E-Mail oder Name', 'pass-captain' ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + Queries::event_options( $scope, array( (int) $filters['event_id'] ) ), $filters['event_id'] ); ?>
			<?php Forms::select( 'status', $status_options, $filters['status'] ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Suchen', 'pass-captain' ); ?></button>
		</form>
		<?php echo Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note. ?>
		<?php if ( ! empty( $data['notice'] ) ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( $data['notice'] ); ?></p></div><?php endif; ?>
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
							<br><small><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'pass-captain' ), \PassCaptain\Frontend\Format::order_reference( $ticket->order_public_id ) ) ); ?></small>
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
								<?php if ( $exchange ) : ?><p><a class="button" href="<?php echo esc_url( \PassCaptain\Exchange\AdminPage::url( array( 'ticket_id' => (int) $ticket->ticket_id ) ) ); ?>"><?php esc_html_e( 'Umbuchen', 'pass-captain' ); ?></a></p><?php endif; ?>
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
							<?php if ( 'valid' === $ticket->status && $can_checkin ) : ?>
								<?php
								$areas = self::checkin_areas( (int) $ticket->event_id );
								Forms::open_form( 'wpcap_checkin_ticket', 'wpcap_checkin_ticket_' . $ticket->ticket_id );
								Forms::hidden( 'ticket_id', $ticket->ticket_id );
								Forms::hidden( 'return_search', $filters['search'] );
								Forms::hidden( 'idempotency_key', wp_generate_uuid4() );
								if ( $areas ) {
									Forms::select( 'area_id', array( 0 => __( '– Einlassbereich –', 'pass-captain' ) ) + $areas, 0, '', 'aria-label="' . esc_attr__( 'Einlassbereich', 'pass-captain' ) . '"' );
								}
								?>
								<button class="button button-primary" type="submit" onclick="return window.confirm('<?php echo esc_js( __( 'Ticket jetzt einchecken? Das gilt wie ein Scan am Einlass.', 'pass-captain' ) ); ?>')"><?php esc_html_e( 'Einchecken', 'pass-captain' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( 'checked_in' === $ticket->status && $can_checkin ) : ?>
								<?php Forms::open_form( 'wpcap_undo_checkin', 'wpcap_undo_checkin_' . $ticket->ticket_id ); Forms::hidden( 'ticket_id', $ticket->ticket_id ); Forms::hidden( 'return_search', $filters['search'] ); ?>
								<input type="text" name="reason" maxlength="255" required aria-label="<?php esc_attr_e( 'Grund der Rücknahme', 'pass-captain' ); ?>" placeholder="<?php esc_attr_e( 'Grund (Pflicht)', 'pass-captain' ); ?>">
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
