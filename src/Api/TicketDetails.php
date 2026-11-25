<?php
/**
 * Versioned read service for connectors (extension contract 1.4.0, PASS-E17-01; docs/EXTENSIONS.md
 * "Lesedienst für Connectoren"): one ticket, or all tickets of an order, with status, occurrence (start, end,
 * cancelled/rescheduled), event, holder and check-ins as nested arrays without table or column names.
 *
 * Builds on the read facade (ReadFacade, contract 1.3.0): `ticket`, `occurrence`, `event` and `order` are
 * exactly the facade's state arrays, occurrence and event extended by `cancelled`/`rescheduled`. Added here:
 *
 * - `holder`: `user_id` (WordPress account of the holder: the buyer's account for a ticket without its own
 *   attendee e-mail, else the account with the attendee e-mail; null when there is none), `is_buyer`,
 *   `email_included` and `email`. The e-mail address is only returned when the caller asks for it
 *   (`include_email`) and the reading user (`user_id`, default: the current user) has the capability
 *   EMAIL_CAP; every such read is written to the audit log (action `holder_email_read`, with the
 *   `source` given by the caller, never with the address).
 * - `checkins`: admission entries of the ticket (`checked_in`, `checked_out`, `undone`), oldest first, with
 *   result, time, source and device ID; never the scanning staff member or a code.
 *
 * Contract 1.5.0 (C9, additive): opt-in `include_location` adds `location`/`venue_country` to the occurrence,
 * opt-in `include_holder_changes` adds `holder_changed`/`holder_change_count` to the holder (a count only, never
 * a previous holder); ticket_ids() lists the public IDs of the tickets of an occurrence, event or ticket type
 * (keyset pagination); catalog() lists events with their ticket types, also without future occurrences (names,
 * status, currency and price; no personal data). Without the new arguments ticket() and order() return exactly
 * the 1.4.0 arrays.
 *
 * Within version 1 keys are only added; existing keys keep name, type and meaning.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Api;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketDetails {
	const VERSION = 1;

	/** Capability that releases the holder's e-mail address (granted to nobody by default). */
	const EMAIL_CAP = 'wpcap_read_holder_email';

	/** Keys of each result, in this order (the contract; tests compare against these lists). */
	const KEYS = array( 'version', 'ticket', 'occurrence', 'event', 'holder', 'checkins' );
	const ORDER_KEYS = array( 'version', 'order', 'tickets' );
	const HOLDER_KEYS = array( 'user_id', 'is_buyer', 'email_included', 'email' );
	const CHECKIN_KEYS = array( 'checkin_id', 'result', 'at', 'source', 'device_id' );
	/** Keys added to the facade's occurrence and event arrays. */
	const OCCURRENCE_EXTRA_KEYS = array( 'cancelled', 'rescheduled' );
	const EVENT_EXTRA_KEYS = array( 'cancelled' );
	/** Contract 1.5.0: keys added on request (include_location, include_holder_changes). */
	const LOCATION_KEYS = array( 'location', 'venue_country' );
	const HOLDER_CHANGE_KEYS = array( 'holder_changed', 'holder_change_count' );
	/** Contract 1.5.0: keys of ticket_ids() and catalog(). */
	const TICKET_IDS_KEYS = array( 'version', 'scope', 'scope_id', 'statuses', 'ticket_ids', 'next_after' );
	const TICKET_ID_SCOPES = array( 'occurrence_id', 'event_id', 'ticket_type_id' );
	const DEFAULT_TICKET_STATUSES = array( 'valid', 'checked_in' );
	const CATALOG_KEYS = array( 'version', 'events', 'next_after' );
	const CATALOG_EVENT_KEYS = array( 'event_id', 'name', 'status', 'cancelled', 'occurrence_count', 'next_starts_at', 'ticket_types' );
	const CATALOG_TYPE_KEYS = array( 'ticket_type_id', 'occurrence_id', 'name', 'status', 'kind', 'visibility', 'currency', 'price_minor' );

	/** Check-in results that change admission (rejected scans are not listed). */
	const CHECKIN_RESULTS = array( 'checked_in', 'checked_out', 'undone' );

	/** Most check-ins listed per ticket and tickets per order. */
	const MAX_CHECKINS = 200;
	const MAX_TICKETS = 500;
	/** Contract 1.5.0: page sizes (default / maximum) of ticket_ids() and catalog(); ticket types per catalog page. */
	const IDS_LIMIT = 100;
	const MAX_IDS = 1000;
	const CATALOG_LIMIT = 50;
	const MAX_CATALOG = 200;
	const MAX_CATALOG_TYPES = 2000;

	/**
	 * Details of one ticket.
	 *
	 * @param int|string $id   Ticket ID or the ticket's `public_id` (32 hex characters).
	 * @param array      $args include_email (bool), user_id (int, reading user; default current user),
	 *                         source (string, e.g. the connector key; written to the audit log);
	 *                         since contract 1.5.0 include_location (bool), include_holder_changes (bool).
	 * @return array<string,mixed>|null Null when the ticket does not exist.
	 */
	public static function ticket( $id, array $args = array() ) {
		$state = ReadFacade::ticket( $id );
		if ( null === $state ) { return null; }
		$memo = array();
		return self::build( $state, $args, $memo );
	}

	/**
	 * Details of an order and all its tickets (at most MAX_TICKETS, ordered by ticket ID).
	 *
	 * @param int|string $id   Order ID or the order's `public_id` (32 hex characters).
	 * @param array      $args As for ticket().
	 * @return array<string,mixed>|null Null when the order does not exist.
	 */
	public static function order( $id, array $args = array() ) {
		global $wpdb;
		$order = ReadFacade::order( $id );
		if ( null === $order ) { return null; }
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ticket_id FROM %i WHERE order_id=%d ORDER BY ticket_id LIMIT %d', $wpdb->prefix . 'wpcap_tickets', (int) $order['order_id'], self::MAX_TICKETS ) );
		$tickets = array();
		$memo = array();
		foreach ( (array) $ids as $ticket_id ) {
			$state = ReadFacade::ticket( (int) $ticket_id );
			if ( null !== $state ) { $tickets[] = self::build( $state, $args, $memo ); }
		}
		return array(
			'version' => self::VERSION,
			'order' => $order,
			'tickets' => $tickets,
		);
	}

	/**
	 * Result for one ticket state.
	 *
	 * @param array $state Ticket state of the read facade.
	 * @param array $args  Caller arguments.
	 * @param array $memo  Occurrence/event arrays already read in this call.
	 */
	private static function build( array $state, array $args, array &$memo ) {
		$occurrence = null;
		if ( null !== $state['occurrence_id'] ) {
			$key = 'o' . $state['occurrence_id'];
			if ( ! array_key_exists( $key, $memo ) ) {
				$memo[ $key ] = ReadFacade::occurrence( (int) $state['occurrence_id'] );
				if ( null !== $memo[ $key ] ) {
					$memo[ $key ]['cancelled'] = 'cancelled' === $memo[ $key ]['status'];
					$memo[ $key ]['rescheduled'] = null !== $memo[ $key ]['rescheduled_at'];
					if ( ! empty( $args['include_location'] ) ) { $memo[ $key ] += self::location( (int) $state['occurrence_id'] ); }
				}
			}
			$occurrence = $memo[ $key ];
		}
		$event = null;
		if ( null !== $state['event_id'] ) {
			$key = 'e' . $state['event_id'];
			if ( ! array_key_exists( $key, $memo ) ) {
				$memo[ $key ] = ReadFacade::event( (int) $state['event_id'] );
				if ( null !== $memo[ $key ] ) { $memo[ $key ]['cancelled'] = 'cancelled' === $memo[ $key ]['status']; }
			}
			$event = $memo[ $key ];
		}
		return array(
			'version' => self::VERSION,
			'ticket' => $state,
			'occurrence' => $occurrence,
			'event' => $event,
			'holder' => self::holder( (int) $state['ticket_id'], $args ),
			'checkins' => self::checkins( (int) $state['ticket_id'] ),
		);
	}

	/** Contract 1.5.0: location (venue text) and venue country of an occurrence; null when empty. */
	private static function location( $occurrence_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT location,venue_country FROM %i WHERE occurrence_id=%d LIMIT 1', $wpdb->prefix . 'wpcap_occurrences', $occurrence_id ) );
		$location = $row ? trim( (string) $row->location ) : '';
		$country = $row ? strtoupper( trim( (string) $row->venue_country ) ) : '';
		return array(
			'location' => '' === $location ? null : $location,
			'venue_country' => 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : null,
		);
	}

	/** Holder of a ticket; the e-mail address only with EMAIL_CAP (audited). */
	private static function holder( $ticket_id, array $args ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT t.attendee_email,t.transfer_count,o.customer_email,o.customer_user_id FROM %i t LEFT JOIN %i o ON o.order_id=t.order_id WHERE t.ticket_id=%d LIMIT 1',
			$wpdb->prefix . 'wpcap_tickets',
			$wpdb->prefix . 'wpcap_orders',
			$ticket_id
		) );
		$attendee = $row ? trim( (string) $row->attendee_email ) : '';
		$buyer = $row ? trim( (string) $row->customer_email ) : '';
		$is_buyer = '' === $attendee || 0 === strcasecmp( $attendee, $buyer );
		$email = $is_buyer ? $buyer : $attendee;
		$user_id = $is_buyer && $row ? (int) $row->customer_user_id : 0;
		if ( $user_id <= 0 && '' !== $email && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'email', $email );
			$user_id = $user ? (int) $user->ID : 0;
		}
		$include = false;
		if ( ! empty( $args['include_email'] ) && '' !== $email ) {
			$reader = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : (int) get_current_user_id();
			$include = $reader > 0 && user_can( $reader, self::EMAIL_CAP );
			if ( $include ) {
				$source = isset( $args['source'] ) && is_scalar( $args['source'] ) ? substr( sanitize_key( (string) $args['source'] ), 0, 40 ) : '';
				Audit::record( 'holder_email_read', 'ticket', $ticket_id, array( 'source' => '' === $source ? 'unknown' : $source, 'reader_user_id' => $reader ) );
			}
		}
		$holder = array(
			'user_id' => $user_id > 0 ? $user_id : null,
			'is_buyer' => $is_buyer,
			'email_included' => $include,
			'email' => $include ? $email : null,
		);
		if ( ! empty( $args['include_holder_changes'] ) ) {
			// Same count as the holder generation of the review events: holder changes plus transfers.
			$changes = \PassCaptain\TicketAssignments::holder_changes( $ticket_id ) + ( $row ? (int) $row->transfer_count : 0 );
			$holder['holder_changed'] = $changes > 0;
			$holder['holder_change_count'] = $changes;
		}
		return $holder;
	}

	/**
	 * Contract 1.5.0: public IDs of the tickets of one occurrence, event or ticket type, ordered by ticket ID,
	 * one page at a time (keyset pagination: pass `next_after` of the previous page as `after`).
	 *
	 * @param array $args Exactly one of occurrence_id, event_id, ticket_type_id (positive int);
	 *                    statuses (list of ticket status keys, or 'any'; default: valid and checked_in tickets
	 *                    that are not refunded); limit (1 to MAX_IDS, default IDS_LIMIT); after (ticket ID, default 0).
	 * @return array<string,mixed>|null Null when the arguments are invalid or the record does not exist.
	 */
	public static function ticket_ids( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = '';
		$scope_id = 0;
		foreach ( self::TICKET_ID_SCOPES as $key ) {
			if ( ! isset( $args[ $key ] ) ) { continue; }
			if ( '' !== $scope ) { return null; }
			$scope = $key;
			$scope_id = self::positive( $args[ $key ] );
		}
		if ( '' === $scope || $scope_id <= 0 ) { return null; }
		$tables = array(
			'occurrence_id' => 'wpcap_occurrences',
			'event_id' => 'wpcap_events',
			'ticket_type_id' => 'wpcap_ticket_types',
		);
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i=%d LIMIT 1', $scope, $p . $tables[ $scope ], $scope, $scope_id ) ) ) { return null; }

		$default = ! isset( $args['statuses'] );
		$statuses = self::DEFAULT_TICKET_STATUSES;
		if ( ! $default ) {
			$statuses = 'any' === $args['statuses'] ? array() : self::status_keys( $args['statuses'] );
			if ( null === $statuses ) { return null; }
		}
		$page = self::page( $args, self::IDS_LIMIT, self::MAX_IDS );
		if ( null === $page ) { return null; }

		$columns = array(
			'occurrence_id' => 'tt.occurrence_id',
			'event_id' => 'oc.event_id',
			'ticket_type_id' => 't.ticket_type_id',
		);
		$where = 't.ticket_id>%d AND ' . $columns[ $scope ] . '=%d';
		$values = array( $page[1], $scope_id );
		if ( $statuses ) {
			$where .= ' AND t.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$values = array_merge( $values, $statuses );
		}
		if ( $default ) { $where .= ' AND t.refunded=0'; }
		$values[] = $page[0] + 1;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one array of replacements, the conditions add their own placeholders.
			'SELECT t.ticket_id,t.public_id FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i oc ON oc.occurrence_id=tt.occurrence_id WHERE ' . $where . ' ORDER BY t.ticket_id LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal conditions with placeholders.
			array_merge( array( $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences' ), $values )
		) );
		$more = count( $rows ) > $page[0];
		$rows = array_slice( $rows, 0, $page[0] );
		$last = $rows ? (int) $rows[ count( $rows ) - 1 ]->ticket_id : 0;
		return array(
			'version' => self::VERSION,
			'scope' => $scope,
			'scope_id' => $scope_id,
			'statuses' => $statuses,
			'ticket_ids' => array_map( static fn( $row ) => (string) $row->public_id, $rows ),
			'next_after' => $more ? $last : null,
		);
	}

	/**
	 * Contract 1.5.0: events with their ticket types, also events and ticket types without future occurrences,
	 * ordered by event ID, one page at a time. Names, status, currency and price only: no descriptions, slugs,
	 * locations, access codes, staff notes or personal data.
	 *
	 * @param array $args statuses (list of event status keys; default all); limit (1 to MAX_CATALOG, default
	 *                    CATALOG_LIMIT); after (event ID, default 0).
	 * @return array<string,mixed>|null Null when the arguments are invalid.
	 */
	public static function catalog( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$statuses = isset( $args['statuses'] ) ? self::status_keys( $args['statuses'] ) : array();
		$page = self::page( $args, self::CATALOG_LIMIT, self::MAX_CATALOG );
		if ( null === $statuses || null === $page ) { return null; }

		$where = 'e.event_id>%d';
		$values = array( \PassCaptain\Clock::mysql(), $page[1] );
		if ( $statuses ) {
			$where .= ' AND e.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$values = array_merge( $values, $statuses );
		}
		$values[] = $page[0] + 1;
		$events = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one array of replacements, the conditions add their own placeholders.
			'SELECT e.event_id,e.name,e.status,(SELECT COUNT(*) FROM %i oc WHERE oc.event_id=e.event_id) AS occurrence_count,'
			. "(SELECT MIN(oc.starts_at) FROM %i oc WHERE oc.event_id=e.event_id AND oc.starts_at>=%s AND oc.status<>'cancelled') AS next_starts_at"
			. ' FROM %i e WHERE ' . $where . ' ORDER BY e.event_id LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal condition with placeholders.
			array_merge( array( $p . 'wpcap_occurrences', $p . 'wpcap_occurrences' ), array_slice( $values, 0, 1 ), array( $p . 'wpcap_events' ), array_slice( $values, 1 ) )
		) );
		$more = count( $events ) > $page[0];
		$events = array_slice( $events, 0, $page[0] );
		$types = array();
		if ( $events ) {
			$ids = array_map( static fn( $row ) => (int) $row->event_id, $events );
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				'SELECT tt.ticket_type_id,tt.occurrence_id,tt.name,tt.status,tt.kind,tt.visibility,tt.currency,tt.price_minor,oc.event_id FROM %i tt INNER JOIN %i oc ON oc.occurrence_id=tt.occurrence_id WHERE oc.event_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY oc.event_id,tt.ticket_type_id LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer placeholders only.
				array_merge( array( $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences' ), $ids, array( self::MAX_CATALOG_TYPES ) )
			) );
			foreach ( $rows as $row ) {
				$types[ (int) $row->event_id ][] = array(
					'ticket_type_id' => (int) $row->ticket_type_id,
					'occurrence_id' => (int) $row->occurrence_id,
					'name' => (string) $row->name,
					'status' => (string) $row->status,
					'kind' => (string) $row->kind,
					'visibility' => (string) $row->visibility,
					'currency' => strtoupper( (string) $row->currency ),
					'price_minor' => (int) $row->price_minor,
				);
			}
		}
		$out = array();
		foreach ( $events as $row ) {
			$out[] = array(
				'event_id' => (int) $row->event_id,
				'name' => (string) $row->name,
				'status' => (string) $row->status,
				'cancelled' => 'cancelled' === (string) $row->status,
				'occurrence_count' => (int) $row->occurrence_count,
				'next_starts_at' => null === $row->next_starts_at ? null : self::utc( (string) $row->next_starts_at ),
				'ticket_types' => $types[ (int) $row->event_id ] ?? array(),
			);
		}
		return array(
			'version' => self::VERSION,
			'events' => $out,
			'next_after' => $more && $events ? (int) $events[ count( $events ) - 1 ]->event_id : null,
		);
	}

	/** Status keys of a caller list (deduplicated), or null when the list is empty or holds anything else. */
	private static function status_keys( $value ) {
		if ( ! is_array( $value ) || array() === $value ) { return null; }
		$out = array();
		foreach ( $value as $status ) {
			if ( ! is_string( $status ) || 1 !== preg_match( '/^[a-z_]{1,20}$/', $status ) ) { return null; }
			$out[ $status ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Page arguments: [limit, after]; limit defaults to $fallback and is capped at $max, after defaults to 0.
	 * Null when one of them is not a non-negative integer (limit: positive).
	 */
	private static function page( array $args, $fallback, $max ) {
		$limit = isset( $args['limit'] ) ? self::number( $args['limit'] ) : $fallback;
		$after = isset( $args['after'] ) ? self::number( $args['after'] ) : 0;
		if ( null === $limit || null === $after || $limit < 1 ) { return null; }
		return array( min( $limit, $max ), $after );
	}

	/** Non-negative integer from an int or a digit string, else null. */
	private static function number( $value ) {
		if ( is_int( $value ) ) { return $value >= 0 ? $value : null; }
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/', trim( $value ) ) ) { return (int) trim( $value ); }
		return null;
	}

	/** Positive record ID from an int or a digit string, else 0. */
	private static function positive( $value ) {
		$number = self::number( $value );
		return null === $number ? 0 : $number;
	}

	/** Admission entries of a ticket, oldest first. */
	private static function checkins( $ticket_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT checkin_id,result,source,device_id,scanned_at,created_at FROM %i WHERE ticket_id=%d AND result IN ('checked_in','checked_out','undone') ORDER BY checkin_id LIMIT %d",
			$wpdb->prefix . 'wpcap_checkins',
			$ticket_id,
			self::MAX_CHECKINS
		) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$at = self::utc( (string) $row->scanned_at );
			$out[] = array(
				'checkin_id' => (int) $row->checkin_id,
				'result' => (string) $row->result,
				'at' => null === $at ? self::utc( (string) $row->created_at ) : $at,
				'source' => sanitize_key( (string) $row->source ),
				'device_id' => (int) $row->device_id > 0 ? (int) $row->device_id : null,
			);
		}
		return $out;
	}

	/** Stored UTC datetime as RFC 3339 with `Z`, or null when empty. */
	private static function utc( $value ) {
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) { return null; }
		$time = strtotime( $value . ' UTC' );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
