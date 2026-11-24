<?php
/**
 * Attendee list for an occurrence (print view and CSV), minimised per role:
 *  - wpcap_manage_events on the event: full list incl. e-mail addresses, order and field answers;
 *  - wpcap_checkin (or wpcap_manage_orders) only: name, ticket type and status; the name follows the
 *    PII matrix (Pii::show(), door staff only with scanner_show_attendee, otherwise initials).
 *
 * Large events (A477): tickets are read block by block (Templates::ticket_blocks(), keyset on the
 * list order) and written out as they are read – CSV and HTML stream, memory stays bounded.
 */
namespace PassCaptain\Printing;

use PassCaptain\Reports\Scope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class AttendeeList {
	/** 'full', 'basic' or '' (no access) for the current user. */
	public static function access_level( $occurrence_id ) {
		$event_id = Scope::occurrence_event( $occurrence_id );
		if ( ! $event_id || ! \PassCaptain\Access::occurrence_in_scope( $occurrence_id ) ) { return ''; }
		// Occurrence assignments (A144) are checked per capability: a person limited to other
		// occurrences for a right does not get this list through that right.
		$via = static function ( $cap ) use ( $event_id, $occurrence_id ) {
			return Scope::can_event( $event_id, array( $cap ) ) && \PassCaptain\Access::occurrence_in_scope( $occurrence_id, 0, $cap );
		};
		if ( $via( 'wpcap_manage_events' ) ) { return 'full'; }
		if ( $via( 'wpcap_checkin' ) || $via( 'wpcap_manage_orders' ) ) { return 'basic'; }
		// Staff assigned to this date only (occurrence scope) get the basic list of that date.
		if ( method_exists( \PassCaptain\Access::class, 'has_occurrence_assignment' ) && \PassCaptain\Access::has_occurrence_assignment( $occurrence_id, 'wpcap_checkin' ) ) { return 'basic'; }
		return '';
	}

	/** Tickets per block for lists and exports (filter wpcap_attendee_list_chunk, 1–5000, default 500). */
	public static function chunk_size() {
		return max( 1, min( 5000, (int) apply_filters( 'wpcap_attendee_list_chunk', 500 ) ) );
	}

	/** Header row for the level. */
	public static function header( $occurrence_id, $level ) {
		if ( 'full' !== $level ) { return array( __( 'Name', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'Status', 'pass-captain' ) ); }
		$header = array( __( 'Ticket', 'pass-captain' ), __( 'Name', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'E-Mail Teilnehmer', 'pass-captain' ), __( 'E-Mail Besteller', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ), __( 'Termin', 'pass-captain' ), __( 'Sitzplatz', 'pass-captain' ), __( 'Eingecheckt um', 'pass-captain' ), __( 'Bereich', 'pass-captain' ) );
		foreach ( self::fields( $occurrence_id ) as $f ) { $header[] = $f->label; }
		return $header;
	}

	/**
	 * Active participant fields of the occurrence's ticket types in list order (A611: no removed fields).
	 * PASS-FE-05: fields whose answers the user may not see (Pii::can_view_answer(), filter
	 * wpcap_pii_can_view_answer) are left out – column and answers.
	 */
	private static function fields( $occurrence_id ) {
		return array_values( array_filter( self::all_fields( $occurrence_id ), static function ( $f ) { return \PassCaptain\Pii::can_view_answer( (string) $f->field_key, true ); } ) );
	}

	/** Active participant fields of the occurrence without the per-field rights. */
	private static function all_fields( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT f.field_id,f.field_key,f.label FROM {$p}wpcap_participant_fields f INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=f.ticket_type_id WHERE tt.occurrence_id=%d AND f.status='active' ORDER BY f.position,f.field_id", absint( $occurrence_id ) ) );
	}

	/**
	 * A611: first admission time (site time) and entrance area of the latest admission at this occurrence, per
	 * ticket of one block: from the admission log (also passes and repeat entries), else the check-in log.
	 *
	 * @param object[] $tickets Tickets of one block.
	 * @return array<int,array{at:string,area:string}>
	 */
	private static function admissions( $occurrence_id, array $tickets, $timezone ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', $ids );
		$out = array();
		$local = static function ( $utc ) use ( $timezone ) { return $utc ? Scope::local( (string) $utc, (string) $timezone ) : ''; };
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null; // The admission log belongs to an optional module.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix, integer lists of one block.
		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT e.ticket_id,MIN(e.entered_at) AS first_at,MAX(e.entry_id) AS last_id FROM {$p}wpcap_admission_entries e WHERE e.ticket_id IN ($in) AND e.occurrence_id=%d AND e.direction='in' AND e.undone=0 GROUP BY e.ticket_id", absint( $occurrence_id ) ) );
		$last_ids = array();
		foreach ( (array) $entries as $e ) {
			$out[ (int) $e->ticket_id ] = array( 'at' => $local( $e->first_at ), 'area' => '' );
			$last_ids[ (int) $e->last_id ] = (int) $e->ticket_id;
		}
		if ( $last_ids ) {
			$areas = $wpdb->get_results( "SELECT e.entry_id,a.name FROM {$p}wpcap_admission_entries e INNER JOIN {$p}wpcap_admission_areas a ON a.area_id=e.area_id WHERE e.entry_id IN (" . implode( ',', array_keys( $last_ids ) ) . ')' );
			foreach ( (array) $areas as $a ) { $out[ $last_ids[ (int) $a->entry_id ] ]['area'] = (string) $a->name; }
		}
		$missing = array_values( array_diff( $ids, array_keys( $out ) ) );
		if ( $missing ) {
			$rows = $wpdb->get_results( "SELECT ticket_id,MIN(created_at) AS first_at FROM {$p}wpcap_checkins WHERE ticket_id IN (" . implode( ',', $missing ) . ") AND result='checked_in' GROUP BY ticket_id" );
			foreach ( (array) $rows as $r ) { $out[ (int) $r->ticket_id ] = array( 'at' => $local( $r->first_at ), 'area' => '' ); }
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		if ( null !== $suppress ) { $wpdb->suppress_errors( $suppress ); }
		return $out;
	}

	/**
	 * Data rows (without header), block by block (A477): at most chunk_size() tickets and their
	 * answers are in memory at a time, however large the event is.
	 *
	 * @param string $level  'full' or 'basic'.
	 * @param array  $filter PASS-FE-05, optional: type_name (ticket type name), status ('valid' or 'checked_in').
	 * @return \Generator<int, array>
	 */
	public static function row_stream( $occurrence_id, $level, array $filter = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$status_labels = array( 'valid' => __( 'Gültig', 'pass-captain' ), 'checked_in' => __( 'Eingecheckt', 'pass-captain' ) );
		$fields = 'full' === $level ? self::fields( $occurrence_id ) : array();
		$occ = 'full' === $level ? $wpdb->get_row( $wpdb->prepare( 'SELECT starts_at,timezone FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', absint( $occurrence_id ) ) ) : null;
		$when = $occ ? Scope::local( (string) $occ->starts_at, (string) $occ->timezone ) : '';
		foreach ( Templates::ticket_blocks( $occurrence_id, array( 'valid', 'checked_in' ), self::chunk_size() ) as $tickets ) {
			// A573: the status says whether the ticket was admitted at this occurrence (also passes and
			// repeatable policies, whose ticket status stays valid).
			$admitted = \PassCaptain\Reports\Attendance::admitted_ids( $occurrence_id, $tickets );
			foreach ( $tickets as $t ) { $t->status = isset( $admitted[ (int) $t->ticket_id ] ) ? 'checked_in' : 'valid'; }
			$tickets = self::filter_tickets( $tickets, $filter );
			if ( ! $tickets ) { continue; }
			if ( 'full' !== $level ) {
				foreach ( $tickets as $t ) { yield array( \PassCaptain\Pii::show( 'attendee_name', $t->attendee_name ), $t->ticket_name, $status_labels[ $t->status ] ?? $t->status ); }
				continue;
			}
			$answers = array();
			if ( $fields ) {
				$ids = implode( ',', array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
				foreach ( $wpdb->get_results( "SELECT ticket_id,field_id,answer FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($ids)" ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list of one block.
					$answers[ (int) $a->ticket_id ][ (int) $a->field_id ] = $a->answer;
				}
			}
			$entries = self::admissions( $occurrence_id, $tickets, $occ ? (string) $occ->timezone : 'UTC' );
			foreach ( $tickets as $t ) {
				$buyer = false !== strpos( (string) $t->customer_email, '@invalid.example' ) ? '' : $t->customer_email;
				$seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $t->ticket_id, (array) $t );
				$entry = 'checked_in' === $t->status ? ( $entries[ (int) $t->ticket_id ] ?? array( 'at' => '', 'area' => '' ) ) : array( 'at' => '', 'area' => '' );
				// PASS-FE-05: e-mail columns follow the PII matrix per field (unchanged for the default matrix).
				$row = array( substr( $t->public_id, 0, 12 ), \PassCaptain\Pii::show( 'attendee_name', $t->attendee_name ), $t->ticket_name, $status_labels[ $t->status ] ?? $t->status, \PassCaptain\Pii::show( 'attendee_email', $t->attendee_email ), \PassCaptain\Pii::show( 'buyer_email', $buyer ), $t->order_public_id, $when, $seat, $entry['at'], $entry['area'] );
				foreach ( $fields as $f ) { $row[] = $answers[ (int) $t->ticket_id ][ (int) $f->field_id ] ?? ''; }
				yield $row;
			}
		}
	}

	/**
	 * PASS-FE-05: tickets of one block matching the list filter (ticket type name, admission status).
	 *
	 * @param object[] $tickets Tickets with their list status.
	 * @param array    $filter  type_name, status.
	 * @return object[]
	 */
	private static function filter_tickets( array $tickets, array $filter ) {
		$name = (string) ( $filter['type_name'] ?? '' );
		$status = (string) ( $filter['status'] ?? '' );
		if ( '' === $name && '' === $status ) { return $tickets; }
		return array_values( array_filter( $tickets, static function ( $t ) use ( $name, $status ) {
			return ( '' === $name || (string) $t->ticket_name === $name ) && ( '' === $status || (string) $t->status === $status );
		} ) );
	}

	/**
	 * List filter from request values (PASS-FE-05): ticket type name and status of the attendee list
	 * ('valid' or 'checked_in'; other values are dropped).
	 *
	 * @param array $q Raw values (type_name, status).
	 * @return array{type_name:string,status:string}
	 */
	public static function list_filter( array $q ) {
		$status = (string) ( $q['status'] ?? '' );
		return array(
			'type_name' => mb_substr( trim( sanitize_text_field( (string) ( $q['type_name'] ?? '' ) ) ), 0, 190 ),
			'status' => in_array( $status, array( 'valid', 'checked_in' ), true ) ? $status : '',
		);
	}

	/**
	 * Rows (first row = header). Builds the whole list in memory – for small lists and tests; the
	 * downloads stream (row_stream(), Scope::stream_csv(), stream_html()).
	 *
	 * @param string $level 'full' or 'basic'.
	 */
	public static function rows( $occurrence_id, $level ) {
		$rows = array( self::header( $occurrence_id, $level ) );
		foreach ( self::row_stream( $occurrence_id, $level ) as $row ) { $rows[] = $row; }
		return $rows;
	}

	/* ------------------------------------------------------------------ PASS-FE-05: all dates of an event */

	/**
	 * Dates of an event whose attendee list the current user may export, and the level of the combined
	 * export: 'full' only when the user has the full list for every one of them, otherwise 'basic' (the
	 * lower level applies to the whole file – a date with only the basic list never contributes contact
	 * data). Dates the user may not access are left out; level '' when there is none.
	 *
	 * @return array{level:string,occurrences:int[]}
	 */
	public static function event_scope( $event_id ) {
		global $wpdb;
		$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE event_id=%d ORDER BY starts_at,occurrence_id', $wpdb->prefix . 'wpcap_occurrences', absint( $event_id ) ) ) );
		$level = '';
		$allowed = array();
		foreach ( $ids as $id ) {
			$own = self::access_level( $id );
			if ( '' === $own ) { continue; }
			$allowed[] = $id;
			$level = ( '' === $level || 'full' === $level ) ? $own : 'basic';
		}
		return array( 'level' => $level, 'occurrences' => $allowed );
	}

	/**
	 * Active participant fields of several dates, merged by field key (the same field on every date of an
	 * event, A333) in first-seen order: key => label.
	 *
	 * @param int[] $occurrence_ids Dates.
	 * @return array<string,string>
	 */
	private static function event_fields( array $occurrence_ids ) {
		$out = array();
		foreach ( $occurrence_ids as $id ) {
			foreach ( self::fields( $id ) as $f ) {
				if ( ! isset( $out[ (string) $f->field_key ] ) ) { $out[ (string) $f->field_key ] = (string) $f->label; }
			}
		}
		return $out;
	}

	/**
	 * Header of the export over several dates: the columns of the single-date list plus the date – the
	 * basic list gets a leading "Termin" column, the full list already has one; participant fields of all
	 * dates are merged by key.
	 *
	 * @param int[]  $occurrence_ids Dates (event_scope()).
	 * @param string $level          'full' or 'basic'.
	 */
	public static function event_header( array $occurrence_ids, $level ) {
		if ( 'full' !== $level ) { return array_merge( array( __( 'Termin', 'pass-captain' ) ), self::header( 0, 'basic' ) ); }
		return array_merge( array_slice( self::header( 0, 'full' ), 0, self::FULL_BASE_COLUMNS ), array_values( self::event_fields( $occurrence_ids ) ) );
	}

	/** Columns of a full row before the participant fields (row_stream()). */
	const FULL_BASE_COLUMNS = 11;

	/**
	 * Rows of the export over several dates (without header), date by date and block by block like
	 * row_stream() – same data, same role rules, the field answers moved into the merged columns.
	 *
	 * @param int[]  $occurrence_ids Dates (event_scope()).
	 * @param string $level          'full' or 'basic'.
	 * @param array  $filter         PASS-FE-05, optional: type_name, status (list_filter()).
	 * @return \Generator<int, array>
	 */
	public static function event_row_stream( array $occurrence_ids, $level, array $filter = array() ) {
		global $wpdb;
		$keys = 'full' === $level ? array_flip( array_keys( self::event_fields( $occurrence_ids ) ) ) : array();
		foreach ( $occurrence_ids as $occurrence_id ) {
			if ( 'full' !== $level ) {
				$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT starts_at,timezone FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
				$when = $occ ? Scope::local( (string) $occ->starts_at, (string) $occ->timezone ) : '';
				foreach ( self::row_stream( $occurrence_id, 'basic', $filter ) as $row ) { yield array_merge( array( $when ), $row ); }
				continue;
			}
			// Column of each field of this date in the merged header.
			$map = array();
			foreach ( array_values( self::fields( $occurrence_id ) ) as $i => $f ) { $map[ $i ] = $keys[ (string) $f->field_key ]; }
			foreach ( self::row_stream( $occurrence_id, 'full', $filter ) as $row ) {
				$out = array_slice( $row, 0, self::FULL_BASE_COLUMNS );
				$answers = array_fill( 0, count( $keys ), '' );
				foreach ( array_slice( $row, self::FULL_BASE_COLUMNS ) as $i => $answer ) {
					// The same key on two ticket types of a date: the answer of the ticket's own type wins over the empty one.
					if ( isset( $map[ $i ] ) && '' !== (string) $answer ) { $answers[ $map[ $i ] ] = $answer; }
				}
				yield array_merge( $out, $answers );
			}
		}
	}

	/** Printable HTML (A4, escaped) as a string; the download uses stream_html(). */
	public static function html( $occurrence_id, $level ) {
		ob_start();
		self::stream_html( $occurrence_id, $level );
		return (string) ob_get_clean();
	}

	/**
	 * Prints the printable HTML (A4, escaped) block by block (A477): rows are written as they are
	 * read and flushed per block, so memory stays bounded for events with 100,000 tickets.
	 */
	public static function stream_html( $occurrence_id, $level ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT o.*,e.name AS event_name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d", absint( $occurrence_id ) ) );
		$count = Templates::count_for_occurrence( $occurrence_id, array( 'valid', 'checked_in' ) );
		$title = ( $occ ? $occ->event_name . ' – ' . Scope::local( $occ->starts_at, $occ->timezone ) : '' );
		$html = '<!doctype html><html ' . \PassCaptain\Frontend\Format::html_lang_attributes() . '><head><meta charset="utf-8"><title>' . esc_html( __( 'Teilnehmerliste', 'pass-captain' ) ) . '</title>'
			. '<style>@page{size:A4;margin:12mm}body{font:11px/1.35 system-ui,sans-serif;color:#000}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}th{background:#eee}td.box{width:14px}@media print{button{display:none}}</style></head><body>';
		$html .= '<h1>' . esc_html( __( 'Teilnehmerliste', 'pass-captain' ) ) . '</h1><p>' . esc_html( $title ) . ' · ' . esc_html( sprintf( /* translators: %d: count */ _n( '%d Ticket', '%d Tickets', $count, 'pass-captain' ), $count ) ) . '</p>';
		$html .= '<table><thead><tr><th></th>';
		foreach ( self::header( $occurrence_id, $level ) as $cell ) { $html .= '<th>' . esc_html( $cell ) . '</th>'; }
		$html .= '</tr></thead><tbody>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		$n = 0;
		$block = '';
		foreach ( self::row_stream( $occurrence_id, $level ) as $row ) {
			$block .= '<tr><td class="box">☐</td>';
			foreach ( $row as $cell ) { $block .= '<td>' . esc_html( $cell ) . '</td>'; }
			$block .= '</tr>';
			if ( 0 === ++$n % self::chunk_size() ) {
				echo $block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$block = '';
				Scope::flush_output();
			}
		}
		echo $block . '</tbody></table><p>' . \PassCaptain\Frontend\PrintButton::html( __( 'Drucken', 'pass-captain' ) ) . '</p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above and in PrintButton::html() (no inline handler, strict CSP).
	}
}
