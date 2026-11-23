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

	/** Check-in results that change admission (rejected scans are not listed). */
	const CHECKIN_RESULTS = array( 'checked_in', 'checked_out', 'undone' );

	/** Most check-ins listed per ticket and tickets per order. */
	const MAX_CHECKINS = 200;
	const MAX_TICKETS = 500;

	/**
	 * Details of one ticket.
	 *
	 * @param int|string $id   Ticket ID or the ticket's `public_id` (32 hex characters).
	 * @param array      $args include_email (bool), user_id (int, reading user; default current user),
	 *                         source (string, e.g. the connector key; written to the audit log).
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

	/** Holder of a ticket; the e-mail address only with EMAIL_CAP (audited). */
	private static function holder( $ticket_id, array $args ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT t.attendee_email,o.customer_email,o.customer_user_id FROM %i t LEFT JOIN %i o ON o.order_id=t.order_id WHERE t.ticket_id=%d LIMIT 1',
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
		return array(
			'user_id' => $user_id > 0 ? $user_id : null,
			'is_buyer' => $is_buyer,
			'email_included' => $include,
			'email' => $include ? $email : null,
		);
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
