<?php
/**
 * Admin page "Einlass-Konflikte": offline double admissions and other rejected offline scans,
 * with review and resolution (audited).
 */
namespace PassCaptain\Admission;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Admin\TicketsPage;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConflictsPage {
	const SLUG = 'wpcap-admission-conflicts';

	public static function menu_cap() {
		foreach ( Conflicts::VIEW_CAPS as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return Conflicts::VIEW_CAPS[0];
	}

	public static function resolve( array $in ) {
		return Conflicts::resolve( Forms::int( $in, 'conflict_id' ), Forms::text( $in, 'resolution', 32 ), Forms::textarea( $in, 'note', 2000 ) );
	}

	public static function handle_resolve() {
		$in = Forms::post();
		Forms::verify( 'wpcap_admission_resolve_conflict_' . Forms::int( $in, 'conflict_id' ) );
		Forms::finish( self::resolve( $in ), __( 'Konflikt als geklärt markiert.', 'wp-pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	/** Label for check-in result codes incl. admission codes. */
	public static function result_label( $result ) {
		$labels = array(
			'checked_out' => __( 'Ausgecheckt', 'wp-pass-captain' ),
			'not_checked_in' => __( 'Nicht eingecheckt (Auslass abgelehnt)', 'wp-pass-captain' ),
			'entry_limit_reached' => __( 'Einlasslimit erreicht', 'wp-pass-captain' ),
			'outside_entry_window' => __( 'Außerhalb des Einlassfensters', 'wp-pass-captain' ),
			'already_inside' => __( 'Bereits drinnen', 'wp-pass-captain' ),
			'wrong_area' => __( 'Falscher Einlassbereich', 'wp-pass-captain' ),
			'wrong_event' => __( 'Falsches Event', 'wp-pass-captain' ),
			'wrong_occurrence' => __( 'Falscher Termin', 'wp-pass-captain' ),
			'cancelled_event' => __( 'Termin abgesagt', 'wp-pass-captain' ),
			'out_of_window' => __( 'Außerhalb des Einlasszeitraums', 'wp-pass-captain' ),
			'forbidden' => __( 'Kein Einlasszugriff', 'wp-pass-captain' ),
		);
		return $labels[ (string) $result ] ?? Forms::status_label( $result );
	}

	public static function render() {
		if ( ! array_filter( Conflicts::VIEW_CAPS, 'current_user_can' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$scope = Queries::event_ids_for( Conflicts::VIEW_CAPS );
		$events = Queries::event_options( $scope );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( $event_id && ! isset( $events[ $event_id ] ) ) { $event_id = 0; }
		$status = Forms::query_arg( 'status', 'open' );
		if ( ! in_array( $status, array( 'open', 'resolved', 'all' ), true ) ) { $status = 'open'; }
		$page = Forms::current_page();
		$data = Conflicts::search( array( 'status' => 'all' === $status ? '' : $status, 'event_id' => $event_id ), $scope, $page, 50 );
		$resolutions = Conflicts::resolutions();
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Einlass-Konflikte', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<p class="description"><?php esc_html_e( 'Offline-Scans, die ein Gerät bereits eingelassen hat, die der Server aber abgelehnt hat (z. B. Ticket woanders schon verwendet). Bitte prüfen und mit Notiz klären.', 'wp-pass-captain' ); ?></p>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'wp-pass-captain' ) ) + $events, $event_id ); ?>
			<?php Forms::select( 'status', array( 'open' => __( 'Offen', 'wp-pass-captain' ), 'resolved' => __( 'Geklärt', 'wp-pass-captain' ), 'all' => __( 'Alle', 'wp-pass-captain' ) ), $status ); ?>
			<button class="button" type="submit"><?php esc_html_e( 'Filtern', 'wp-pass-captain' ); ?></button>
		</form>
		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Ticket', 'wp-pass-captain' ); ?></th>
			<th><?php esc_html_e( 'Ergebnis', 'wp-pass-captain' ); ?></th>
			<th><?php esc_html_e( 'Offline-Scan', 'wp-pass-captain' ); ?></th>
			<th><?php esc_html_e( 'Vorheriger Einlass', 'wp-pass-captain' ); ?></th>
			<th><?php esc_html_e( 'Status', 'wp-pass-captain' ); ?></th>
		</tr></thead><tbody>
		<?php if ( ! $data['rows'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'Keine Konflikte.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $data['rows'] as $row ) : $tz = $row->timezone ?: 'UTC'; ?>
			<tr>
				<td><?php echo esc_html( (string) $row->event_name ); ?><br><?php if ( $row->ticket_public_id ) : ?><a href="<?php echo esc_url( Forms::page_url( TicketsPage::SLUG, array( 's' => $row->ticket_public_id ) ) ); ?>"><code><?php echo esc_html( $row->ticket_public_id ); ?></code></a><?php endif; ?><br><small><?php echo esc_html( (string) $row->ticket_name ); ?></small></td>
				<td><?php echo esc_html( self::result_label( $row->result ) ); ?><?php if ( $row->area_name ) : ?><br><small><?php echo esc_html( $row->area_name ); ?></small><?php endif; ?></td>
				<td><?php echo esc_html( Forms::datetime( $row->scanned_at ?: $row->created_at, $tz ) ); ?><br><small><?php echo esc_html( ( $row->device_label ?: ( $row->device_id ? '#' . $row->device_id : __( 'Web-Scanner', 'wp-pass-captain' ) ) ) . ' · ' . Forms::user_label( $row->user_id ) ); ?></small></td>
				<td><?php if ( $row->first_checkin_id ) : ?><?php echo esc_html( Forms::datetime( $row->first_at, $tz ) ); ?><br><small><?php echo esc_html( ( $row->first_device_label ?: ( $row->first_device_id ? '#' . $row->first_device_id : __( 'Web-Scanner', 'wp-pass-captain' ) ) ) . ' · ' . Forms::user_label( $row->first_user_id ) ); ?></small><?php else : ?>—<?php endif; ?></td>
				<td>
				<?php if ( 'open' === $row->status ) : ?>
					<?php Forms::open_form( 'wpcap_admission_resolve_conflict', 'wpcap_admission_resolve_conflict_' . $row->conflict_id ); Forms::hidden( 'conflict_id', $row->conflict_id ); ?>
						<?php Forms::select( 'resolution', array( '' => __( 'Klärung …', 'wp-pass-captain' ) ) + $resolutions, '' ); ?>
						<input type="text" name="note" maxlength="2000" placeholder="<?php esc_attr_e( 'Notiz', 'wp-pass-captain' ); ?>">
						<button class="button"><?php esc_html_e( 'Klären', 'wp-pass-captain' ); ?></button>
					</form>
				<?php else : ?>
					<?php echo esc_html( ( $resolutions[ $row->resolution ] ?? $row->resolution ) . ' · ' . Forms::user_label( $row->resolved_by ) . ' · ' . Forms::datetime( $row->resolved_at, $tz ) ); ?>
					<?php if ( '' !== (string) $row->resolution_note ) : ?><br><small><?php echo esc_html( $row->resolution_note ); ?></small><?php endif; ?>
				<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php Forms::pagination( $data['total'], $page, 50, Forms::page_url( self::SLUG, array_filter( array( 'event_id' => $event_id, 'status' => $status ) ) ) ); ?>
		</div>
		<?php
	}
}
