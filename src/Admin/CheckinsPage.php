<?php
/**
 * Check-in log per event with filters and pagination (read-only).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CheckinsPage {
	const SLUG = 'wpcap-checkins';
	const VIEW_CAPS = array( 'wpcap_checkin', 'wpcap_view_reports' );

	public static function menu_cap() {
		foreach ( self::VIEW_CAPS as $cap ) {
			if ( current_user_can( $cap ) ) { return $cap; }
		}
		return self::VIEW_CAPS[0];
	}

	/**
	 * Converts a local date range (Y-m-d, inclusive) in $timezone into UTC bounds [from, to).
	 *
	 * @return array{0: string, 1: string} Empty strings for missing/invalid dates.
	 */
	public static function date_bounds( $date_from, $date_to, $timezone ) {
		$valid = static function ( $date ) {
			return is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) );
		};
		$from = $valid( $date_from ) ? (string) Forms::local_to_utc( $date_from . ' 00:00', $timezone ) : '';
		$to = '';
		if ( $valid( $date_to ) ) {
			$next = ( new \DateTimeImmutable( $date_to ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$to = (string) Forms::local_to_utc( $next . ' 00:00', $timezone );
		}
		return array( $from, $to );
	}

	/** Current presence (checked in minus checked out) per occurrence and entrance area. */
	private static function render_presence( $event_id, array $occurrences ) {
		$areas = \PassCaptain\Admission\Areas::for_event( $event_id, true );
		?>
		<h2><?php esc_html_e( 'Aktuell anwesend', 'wp-pass-captain' ); ?></h2>
		<table class="widefat striped" style="margin-bottom:1em"><thead><tr><th><?php esc_html_e( 'Termin', 'wp-pass-captain' ); ?></th><th><?php esc_html_e( 'Anwesend', 'wp-pass-captain' ); ?></th><?php foreach ( $areas as $area ) : ?><th><?php echo esc_html( $area->name ); ?></th><?php endforeach; ?></tr></thead><tbody>
		<?php foreach ( array_slice( $occurrences, 0, 50 ) as $occ ) : $presence = \PassCaptain\Admission\Engine::presence( (int) $occ->occurrence_id ); ?>
			<tr><td><?php echo esc_html( Forms::datetime( $occ->starts_at, $occ->timezone ) ); ?></td><td><?php echo esc_html( (string) $presence['present'] ); ?></td><?php foreach ( $areas as $area ) : ?><td><?php echo esc_html( (string) ( $presence['by_area'][ (int) $area->area_id ] ?? 0 ) ); ?></td><?php endforeach; ?></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	public static function render() {
		if ( ! array_filter( self::VIEW_CAPS, 'current_user_can' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$scope = Queries::event_ids_for( self::VIEW_CAPS );
		$events = Queries::event_options( $scope );
		$event_id = absint( Forms::query_arg( 'event_id', 0 ) );
		if ( $event_id && ! isset( $events[ $event_id ] ) ) { $event_id = 0; }
		$results = Queries::checkin_results();
		$result = in_array( Forms::query_arg( 'result' ), $results, true ) ? Forms::query_arg( 'result' ) : '';
		$date_from = Forms::query_arg( 'date_from' );
		$date_to = Forms::query_arg( 'date_to' );
		$timezone = wp_timezone_string();
		if ( $event_id ) {
			$occurrences = Queries::occurrences_for_event( $event_id );
			if ( $occurrences ) { $timezone = $occurrences[0]->timezone; }
		}
		list( $from_utc, $to_utc ) = self::date_bounds( $date_from, $date_to, $timezone );
		$page = Forms::current_page();
		$per_page = 50;
		$data = Queries::checkin_log( array( 'event_id' => $event_id, 'result' => $result, 'date_from' => $from_utc, 'date_to' => $to_utc ), $scope, $page, $per_page );
		$result_options = array( '' => __( 'Alle Ergebnisse', 'wp-pass-captain' ) );
		foreach ( $results as $r ) { $result_options[ $r ] = \PassCaptain\Admission\ConflictsPage::result_label( $r ); }
		$base = Forms::page_url( self::SLUG, array_filter( array( 'event_id' => $event_id, 'result' => $result, 'date_from' => $date_from, 'date_to' => $date_to ) ) );
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Check-ins', 'wp-pass-captain' ); ?></h1>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'wp-pass-captain' ) ) + $events, $event_id ); ?>
			<?php Forms::select( 'result', $result_options, $result ); ?>
			<label><?php esc_html_e( 'von', 'wp-pass-captain' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
			<label><?php esc_html_e( 'bis', 'wp-pass-captain' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></label>
			<button class="button" type="submit"><?php esc_html_e( 'Filtern', 'wp-pass-captain' ); ?></button>
			<span class="description"><?php echo esc_html( sprintf( /* translators: %s: timezone */ __( 'Datum und Uhrzeit in %s', 'wp-pass-captain' ), $timezone ) ); ?></span>
		</form>
		<?php if ( $event_id && ! empty( $occurrences ) ) { self::render_presence( $event_id, $occurrences ); } ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Zeitpunkt', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Event', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Ticket', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Ergebnis', 'wp-pass-captain' ); ?></th>
				<th><?php esc_html_e( 'Personal', 'wp-pass-captain' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $data['rows'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'Keine Check-ins gefunden.', 'wp-pass-captain' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $data['rows'] as $row ) : ?>
				<tr>
					<td><?php echo esc_html( Forms::datetime( $row->created_at, $timezone, false ) ); ?></td>
					<td><?php echo esc_html( (string) $row->event_name ); ?></td>
					<td><?php if ( $row->ticket_public_id ) : ?><a href="<?php echo esc_url( Forms::page_url( TicketsPage::SLUG, array( 's' => $row->ticket_public_id ) ) ); ?>"><code><?php echo esc_html( $row->ticket_public_id ); ?></code></a><br><small><?php echo esc_html( (string) $row->ticket_name ); ?></small><?php else : ?>—<?php endif; ?></td>
					<td><?php echo esc_html( \PassCaptain\Admission\ConflictsPage::result_label( $row->result ) . ( 'web' !== $row->source && $row->source ? ' · ' . $row->source : '' ) ); ?></td>
					<td><?php echo esc_html( Forms::user_label( $row->user_id ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php Forms::pagination( $data['total'], $page, $per_page, $base ); ?>
		</div>
		<?php
	}
}
