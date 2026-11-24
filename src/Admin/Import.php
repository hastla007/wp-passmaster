<?php
/**
 * CSV import of events, occurrences and ticket types (core). One row per ticket type; rows with the
 * same event name form one event, rows of an event with the same start one occurrence.
 *
 * Columns (header row, "," or ";" separated, names case-insensitive; * = required):
 *   event*, description, starts_at* (local "Y-m-d H:i"), ends_at, timezone (default: site), location,
 *   occurrence_capacity, ticket*, price* (e.g. 12,50; gross, or net with the setting price_entry = net),
 *   currency (default EUR), tax_rate (e.g. 19),
 *   ticket_capacity, max_per_order (default 10)
 *
 * The whole file is validated first (preview with all line errors); the import runs in one
 * transaction and creates events as drafts, so nothing goes on sale before review. Ticket migration
 * from other systems (existing codes) is not part of the core.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Localization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Import {
	const MAX_ROWS = 2000;
	const COLUMNS = array( 'event', 'description', 'starts_at', 'ends_at', 'timezone', 'location', 'occurrence_capacity', 'ticket', 'price', 'currency', 'tax_rate', 'ticket_capacity', 'max_per_order' );
	const REQUIRED = array( 'event', 'starts_at', 'ticket', 'price' );

	/**
	 * Parses and validates the CSV into a plan.
	 *
	 * @return array{events: array, errors: string[], rows: int}
	 */
	public static function plan( $csv, $default_timezone = 'UTC' ) {
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		$errors = array();
		$events = array();
		if ( ! $lines || '' === trim( (string) $lines[0] ) ) {
			return array( 'events' => array(), 'errors' => array( __( 'Die Datei ist leer.', 'pass-captain' ) ), 'rows' => 0 );
		}
		$delimiter = substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';
		$header = array_map( static function ( $h ) { return strtolower( trim( (string) $h ) ); }, str_getcsv( $lines[0], $delimiter, '"', '\\' ) );
		$missing = array_diff( self::REQUIRED, $header );
		if ( $missing ) {
			/* translators: %s: column names */
			return array( 'events' => array(), 'errors' => array( sprintf( __( 'Pflichtspalten fehlen: %s', 'pass-captain' ), implode( ', ', $missing ) ) ), 'rows' => 0 );
		}
		$count = 0;
		foreach ( array_slice( $lines, 1 ) as $index => $line ) {
			$number = $index + 2;
			if ( '' === trim( $line ) ) { continue; }
			if ( ++$count > self::MAX_ROWS ) {
				/* translators: %d: maximum rows */
				$errors[] = sprintf( __( 'Höchstens %d Zeilen pro Import.', 'pass-captain' ), self::MAX_ROWS );
				break;
			}
			$cells = str_getcsv( $line, $delimiter, '"', '\\' );
			$row = array();
			foreach ( $header as $i => $name ) {
				if ( in_array( $name, self::COLUMNS, true ) ) { $row[ $name ] = trim( (string) ( $cells[ $i ] ?? '' ) ); }
			}
			$result = self::row( $row, $default_timezone );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: line number, 2: error */
				$errors[] = sprintf( __( 'Zeile %1$d: %2$s', 'pass-captain' ), $number, $result->get_error_message() );
				continue;
			}
			$event_key = mb_strtolower( $result['event']['name'] );
			if ( ! isset( $events[ $event_key ] ) ) { $events[ $event_key ] = $result['event'] + array( 'occurrences' => array() ); }
			$occ_key = $result['occurrence']['starts_at'];
			if ( ! isset( $events[ $event_key ]['occurrences'][ $occ_key ] ) ) { $events[ $event_key ]['occurrences'][ $occ_key ] = $result['occurrence'] + array( 'types' => array() ); }
			$events[ $event_key ]['occurrences'][ $occ_key ]['types'][] = $result['type'];
		}
		return array( 'events' => $events, 'errors' => $errors, 'rows' => $count );
	}

	/** One validated row: [event, occurrence, type] or an error. */
	private static function row( array $row, $default_timezone ) {
		$fail = static function ( $message ) { return new \WP_Error( 'wpcap_import_row', $message ); };
		$name = mb_substr( sanitize_text_field( $row['event'] ?? '' ), 0, 190 );
		$ticket = mb_substr( sanitize_text_field( $row['ticket'] ?? '' ), 0, 190 );
		if ( '' === $name || '' === $ticket ) { return $fail( __( 'Event und Ticketart sind Pflicht.', 'pass-captain' ) ); }
		$timezone = '' !== ( $row['timezone'] ?? '' ) ? $row['timezone'] : $default_timezone;
		if ( ! Forms::is_valid_timezone( $timezone ) ) { return $fail( __( 'Unbekannte Zeitzone.', 'pass-captain' ) ); }
		$start = Forms::local_to_utc( str_replace( ' ', 'T', (string) ( $row['starts_at'] ?? '' ) ), $timezone );
		if ( null === $start ) { return $fail( __( 'Beginn ungültig (Format JJJJ-MM-TT HH:MM).', 'pass-captain' ) ); }
		$end = null;
		if ( '' !== ( $row['ends_at'] ?? '' ) ) {
			$end = Forms::local_to_utc( str_replace( ' ', 'T', (string) $row['ends_at'] ), $timezone );
			if ( null === $end || $end <= $start ) { return $fail( __( 'Ende ungültig oder vor dem Beginn.', 'pass-captain' ) ); }
		}
		$currency = strtoupper( '' !== ( $row['currency'] ?? '' ) ? $row['currency'] : 'EUR' );
		if ( ! in_array( $currency, Localization::currencies(), true ) ) { return $fail( __( 'Währung nicht aktiviert.', 'pass-captain' ) ); }
		$price = Forms::parse_money( (string) ( $row['price'] ?? '' ), Forms::currency_decimals( $currency ) );
		if ( null === $price ) { return $fail( __( 'Preis ungültig.', 'pass-captain' ) ); }
		$tax = Forms::parse_percent_bp( '' !== ( $row['tax_rate'] ?? '' ) ? $row['tax_rate'] : '0' );
		if ( null === $tax ) { return $fail( __( 'Steuersatz ungültig.', 'pass-captain' ) ); }
		$ints = array();
		foreach ( array( 'occurrence_capacity' => 0, 'ticket_capacity' => 0, 'max_per_order' => 10 ) as $key => $default ) {
			$raw = (string) ( $row[ $key ] ?? '' );
			if ( '' !== $raw && ! preg_match( '/^\d+$/', $raw ) ) {
				/* translators: %s: column */
				return $fail( sprintf( __( '„%s“ muss eine ganze Zahl sein.', 'pass-captain' ), $key ) );
			}
			$ints[ $key ] = '' === $raw ? $default : (int) $raw;
		}
		if ( $ints['max_per_order'] < 1 || $ints['max_per_order'] > 100 ) { return $fail( __( 'max_per_order muss zwischen 1 und 100 liegen.', 'pass-captain' ) ); }
		return array(
			'event' => array( 'name' => $name, 'description' => wp_kses_post( (string) ( $row['description'] ?? '' ) ) ),
			'occurrence' => array( 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $timezone, 'location' => mb_substr( sanitize_text_field( $row['location'] ?? '' ), 0, 190 ), 'capacity' => $ints['occurrence_capacity'] ),
			'type' => array( 'name' => $ticket, 'price_minor' => $price, 'currency' => $currency, 'tax_rate_bp' => $tax, 'capacity' => $ints['ticket_capacity'], 'max_per_order' => $ints['max_per_order'], 'status' => 'active' ),
		);
	}

	/**
	 * Imports a validated plan (no errors) in one transaction; events become drafts.
	 *
	 * @return array|\WP_Error {events, occurrences, ticket_types, event_ids}
	 */
	public static function import( array $plan ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! current_user_can( EventsPage::CAP ) ) { return Forms::forbidden(); }
		if ( ! empty( $plan['errors'] ) || empty( $plan['events'] ) ) { return Forms::error( 'wpcap_import_invalid', __( 'Die Datei enthält Fehler oder keine Zeilen; es wurde nichts importiert.', 'pass-captain' ) ); }
		$now = \PassCaptain\Clock::mysql();
		$counts = array( 'events' => 0, 'occurrences' => 0, 'ticket_types' => 0, 'event_ids' => array() );
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $plan['events'] as $event ) {
				$slug = \PassCaptain\Text::cut( sanitize_title( $event['name'] ), 180 ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
				if ( ! $wpdb->insert( $p . 'wpcap_events', array( 'name' => $event['name'], 'slug' => $slug, 'description' => $event['description'], 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now ) ) ) { throw new \RuntimeException( 'event' ); }
				$event_id = (int) $wpdb->insert_id;
				$counts['event_ids'][] = $event_id;
				++$counts['events'];
				if ( ! Queries::is_global_admin() ) {
					$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id,user_id,capability) VALUES (%d,%d,%s)', $p . 'wpcap_event_staff', $event_id, get_current_user_id(), EventsPage::CAP ) );
				}
				foreach ( $event['occurrences'] as $occ ) {
					$types = $occ['types'];
					unset( $occ['types'] );
					if ( ! $wpdb->insert( $p . 'wpcap_occurrences', $occ + array( 'event_id' => $event_id, 'status' => 'scheduled' ) ) ) { throw new \RuntimeException( 'occurrence' ); }
					$occurrence_id = (int) $wpdb->insert_id;
					++$counts['occurrences'];
					$occ_row = EventsPage::net_entry() ? Queries::occurrence( $occurrence_id ) : null;
					foreach ( $types as $type ) {
						// SET-PC-45: with net price entry the CSV price is net, converted like the form does
						// (rate of an active tax profile for country, class and date, else the row's tax_rate).
						if ( $occ_row ) { $type['price_minor'] = EventsPage::entry_to_gross( (int) $type['price_minor'], EventsPage::net_rate_bp( (int) $type['tax_rate_bp'], $occ_row ) ); }
						if ( ! $wpdb->insert( $p . 'wpcap_ticket_types', $type + array( 'occurrence_id' => $occurrence_id ) ) ) { throw new \RuntimeException( 'type' ); }
						++$counts['ticket_types'];
					}
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return Forms::error( 'wpcap_import_failed', __( 'Der Import ist fehlgeschlagen; es wurde nichts gespeichert.', 'pass-captain' ), 500 );
		}
		Audit::record( 'events_imported', 'event', 0, array( 'events' => $counts['events'], 'occurrences' => $counts['occurrences'], 'ticket_types' => $counts['ticket_types'] ) );
		return $counts;
	}
}
