<?php
/**
 * Workflow provider contract v1 (A673, PASS-WF-01): what Pass Captain hands to a workflow provider
 * (Workflow Captain or any other plugin) and how. Only this class, the outbox service (Outbox::since())
 * and the filter below are the contract – no private classes or tables.
 *
 * - Versioning: SemVer "MAJOR.MINOR.PATCH" (docs/CONTRACTS.md). A minor version only adds event types or
 *   optional fields; a field is never removed, renamed or changes its type within a major version.
 * - Provider: filter `wp_pass_captain_workflow_provider_v1` returns an object with
 *   `contract_version(): string` (optional, default "1.0.0") and `receive( array $envelope )`. A provider
 *   of another major version (or whose version check throws) is never called.
 * - Envelope: id, type, contract, contract_version, schema_version (per event type), occurred_at,
 *   sequence, attempt, replay, source, subject, data. Stable ids: public order/ticket ids, numeric
 *   event/occurrence/line ids.
 * - Field allowlist: data only ever contains the fields listed in EVENTS/OBJECTS. No ticket codes, no
 *   help codes, no access tokens and no attendee or buyer personal data – independent of the webhook
 *   opt-in include_pii (a PII opt-in for workflows is an open decision, A697).
 * - Capability: replaying events (Outbox::replay()) needs CAPABILITY. The core never checks licences:
 *   whether a provider feature is paid is the provider's own business.
 * - Error codes: ERRORS. Deprecation: DEPRECATED (field => since, removal, replacement); a deprecated
 *   field stays for at least DEPRECATION_MINOR_RELEASES minor releases and DEPRECATION_MONTHS months
 *   and is removed only with the next major version.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Workflow;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Contract {
	const NAME = 'workflow';
	const VERSION = '1.0.0';
	const FILTER = 'wp_pass_captain_workflow_provider_v1';
	const CAPABILITY = 'manage_options';
	const DEPRECATION_MINOR_RELEASES = 2;
	const DEPRECATION_MONTHS = 6;

	/** Deprecated envelope/data fields: "type.field" or "*.field" => [since, removal, replacement]. None in 1.0. */
	const DEPRECATED = array();

	/** Error codes of the contract (WP_Error codes; the provider uses the last two). */
	const ERRORS = array(
		'wpcap_workflow_contract_version' => 'provider speaks another major version – never called',
		'wpcap_workflow_provider_missing' => 'no provider registered – events stay in the outbox (replayable)',
		'wpcap_workflow_unknown_event'    => 'event type not part of the contract – not recorded',
		'wpcap_workflow_field_not_allowed' => 'a field outside the allowlist was dropped before recording',
		'wpcap_workflow_provider_error'   => 'the provider threw or answered outside the contract – retried with backoff',
		'wpcap_workflow_forbidden'        => 'the current user lacks the capability for the action (replay)',
		'wpcap_workflow_temporary'        => 'provider: temporary failure – retried with backoff (also any WP_Error without retryable=false)',
		'wpcap_workflow_rejected'         => 'provider: permanent rejection – not retried (also any WP_Error with retryable=false)',
	);

	/**
	 * Object shapes: field => type. Types: int, string, bool, ?int, ?string (nullable), int[], string[],
	 * "@name" (nested object of OBJECTS), "@name[]" (list of them).
	 */
	const OBJECTS = array(
		'order' => array( 'order_id' => 'string', 'status' => 'string', 'payment_status' => 'string', 'currency' => 'string', 'total_minor' => 'int', 'refunded_minor' => 'int', 'payment_provider' => 'string', 'ticket_count' => 'int', 'event_ids' => 'int[]', 'lines' => '@line[]' ),
		'line' => array( 'line_id' => 'int', 'ticket_type_id' => 'int', 'occurrence_id' => 'int', 'event_id' => 'int', 'quantity' => 'int', 'refunded_quantity' => 'int' ),
		'ticket' => array( 'ticket_id' => 'string', 'order_id' => 'string', 'line_id' => 'int', 'ticket_type_id' => 'int', 'occurrence_id' => 'int', 'event_id' => 'int', 'status' => 'string', 'refunded' => 'bool' ),
		'occurrence' => array( 'occurrence_id' => 'int', 'event_id' => 'int', 'starts_at' => 'string', 'ends_at' => '?string', 'timezone' => 'string', 'status' => 'string', 'revision' => 'int' ),
		'event' => array( 'event_id' => 'int', 'status' => 'string', 'occurrence_ids' => 'int[]' ),
		'refund' => array( 'refund_id' => '?int', 'order_id' => 'string', 'kind' => 'string', 'amount_minor' => '?int', 'currency' => 'string', 'source' => 'string', 'status' => 'string', 'ticket_ids' => 'string[]', 'lines' => '@refund_line[]' ),
		'refund_line' => array( 'line_id' => 'int', 'ticket_type_id' => 'int', 'quantity' => 'int' ),
		'exchange' => array( 'exchange_id' => 'int', 'from_ticket_type_id' => 'int', 'to_ticket_type_id' => 'int', 'channel' => 'string', 'currency' => 'string', 'difference_minor' => 'int', 'fee_minor' => 'int', 'refund_minor' => 'int', 'due_minor' => 'int' ),
	);

	/**
	 * Event types: type => [schema version, subject object, extra data fields => type]. The data of an
	 * event is the subject object plus the extra fields.
	 */
	const EVENTS = array(
		'order.paid'             => array( '1.0', 'order', array() ),
		'order.cancelled'        => array( '1.0', 'order', array() ),
		'order.refunded'         => array( '1.0', 'order', array( 'refund' => '@refund' ) ),
		'refund.reversed'        => array( '1.0', 'order', array( 'refund' => '@refund' ) ),
		'ticket.issued'          => array( '1.0', 'ticket', array() ),
		'ticket.revoked'         => array( '1.0', 'ticket', array( 'reason' => 'string' ) ),
		'ticket.transferred'     => array( '1.0', 'ticket', array( 'source' => 'string', 'holder_changes' => 'int' ) ),
		'ticket.exchanged'       => array( '1.0', 'ticket', array( 'previous_ticket_id' => 'string', 'exchange' => '@exchange' ) ),
		'ticket.checked_in'      => array( '1.0', 'ticket', array( 'checked_in_at' => 'string', 'source' => 'string', 'area_id' => 'int', 'checkin_id' => 'int' ) ),
		'ticket.checked_out'     => array( '1.0', 'ticket', array( 'checked_out_at' => 'string', 'source' => 'string', 'area_id' => 'int', 'checkin_id' => 'int' ) ),
		'attendance.confirmed'   => array( '1.0', 'ticket', array( 'confirmed_at' => 'string', 'method' => 'string', 'source' => 'string' ) ),
		'occurrence.rescheduled' => array( '1.0', 'occurrence', array( 'previous_starts_at' => '?string' ) ),
		'occurrence.changed'     => array( '1.0', 'occurrence', array( 'changed_fields' => 'string[]' ) ),
		'occurrence.cancelled'   => array( '1.0', 'occurrence', array( 'issued_tickets' => 'int' ) ),
		'event.cancelled'        => array( '1.0', 'event', array() ),
	);

	/** Keys that never appear anywhere in an envelope, whatever a filter or a future field list says. */
	const FORBIDDEN = array( 'token', 'code', 'ticket_code', 'help_code', 'access_token', 'secret', 'email', 'customer_email', 'attendee_email', 'attendee_name', 'name', 'first_name', 'last_name', 'phone', 'address', 'billing', 'answers', 'ip', 'ip_hash' );

	public static function known( $type ) {
		return isset( self::EVENTS[ (string) $type ] );
	}

	/** Schema version of an event type ('' for unknown types). */
	public static function schema_version( $type ) {
		return self::EVENTS[ (string) $type ][0] ?? '';
	}

	/** Subject object of an event type (order, ticket, occurrence, event). */
	public static function subject( $type ) {
		return self::EVENTS[ (string) $type ][1] ?? '';
	}

	/** Allowed data fields of an event type: field => type. */
	public static function fields( $type ) {
		if ( ! self::known( $type ) ) { return array(); }
		return self::OBJECTS[ self::subject( $type ) ] + self::EVENTS[ $type ][2];
	}

	/**
	 * Data reduced to the allowlist of the event type, with the documented types. Fields outside the
	 * allowlist (and every FORBIDDEN key) are dropped and named in $dropped.
	 *
	 * @param string[] $dropped Out: dropped field paths.
	 */
	public static function sanitize( $type, array $data, &$dropped = array() ) {
		$dropped = array();
		return self::clean( self::fields( $type ), $data, '', $dropped );
	}

	private static function clean( array $spec, array $data, $path, array &$dropped ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $spec[ $key ] ) || in_array( $key, self::FORBIDDEN, true ) ) { $dropped[] = $path . $key; continue; }
			$out[ $key ] = self::cast( $spec[ $key ], $value, $path . $key . '.', $dropped );
		}
		return $out;
	}

	private static function cast( $type, $value, $path, array &$dropped ) {
		if ( '?' === $type[0] ) { return null === $value ? null : self::cast( substr( $type, 1 ), $value, $path, $dropped ); }
		if ( '@' === $type[0] ) {
			$list = '[]' === substr( $type, -2 );
			$spec = self::OBJECTS[ trim( $type, '@[]' ) ] ?? array();
			if ( ! $list ) { return self::clean( $spec, is_array( $value ) ? $value : array(), $path, $dropped ); }
			$items = array();
			foreach ( is_array( $value ) ? array_values( $value ) : array() as $item ) { $items[] = self::clean( $spec, is_array( $item ) ? $item : array(), $path, $dropped ); }
			return $items;
		}
		switch ( $type ) {
			case 'int': return (int) $value;
			case 'bool': return (bool) $value;
			case 'int[]': return array_values( array_map( 'intval', is_array( $value ) ? $value : array() ) );
			case 'string[]': return array_values( array_map( 'strval', array_filter( is_array( $value ) ? $value : array(), 'is_scalar' ) ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The envelope of an event (without sequence/attempt/replay, which the outbox adds per delivery).
	 *
	 * @param string $subject_id Stable id of the subject (public id or numeric id as string).
	 */
	public static function envelope( $event_id, $type, $subject_id, array $data, $occurred_at ) {
		return array(
			'id' => (string) $event_id,
			'type' => (string) $type,
			'contract' => self::NAME,
			'contract_version' => self::VERSION,
			'schema_version' => self::schema_version( $type ),
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $occurred_at ),
			'source' => array(
				'plugin' => 'pass-captain',
				'version' => defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : '',
				'site_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			),
			'subject' => array( 'type' => self::subject( $type ), 'id' => (string) $subject_id ),
			'data' => $data,
		);
	}

	/**
	 * The registered provider if it speaks major version 1, else null; $status says why.
	 *
	 * @param array $status Out: ['state' => missing|incompatible|ready, 'version' => string, 'code' => error code].
	 */
	public static function provider( &$status = array() ) {
		$provider = apply_filters( self::FILTER, null, (int) \PassCaptain\Contracts::major( self::VERSION ) );
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'receive' ) ) {
			$status = array( 'state' => 'missing', 'version' => '', 'code' => 'wpcap_workflow_provider_missing' );
			return null;
		}
		try {
			$version = method_exists( $provider, 'contract_version' ) ? (string) $provider->contract_version() : '1.0.0';
		} catch ( \Throwable $e ) {
			$version = '';
		}
		if ( ! \PassCaptain\Contracts::compatible( $version, self::VERSION ) ) {
			$status = array( 'state' => 'incompatible', 'version' => sanitize_text_field( substr( $version, 0, 20 ) ), 'code' => 'wpcap_workflow_contract_version' );
			return null;
		}
		$status = array( 'state' => 'ready', 'version' => $version, 'code' => '' );
		return $provider;
	}

	/** Machine-readable description of the contract (for providers, docs and fixtures). */
	public static function describe() {
		$events = array();
		foreach ( self::EVENTS as $type => $def ) { $events[ $type ] = array( 'schema_version' => $def[0], 'subject' => $def[1], 'fields' => self::fields( $type ) ); }
		return array(
			'contract' => self::NAME,
			'version' => self::VERSION,
			'filter' => self::FILTER,
			'capability' => self::CAPABILITY,
			'events' => $events,
			'objects' => self::OBJECTS,
			'errors' => array_keys( self::ERRORS ),
			'deprecated' => self::DEPRECATED,
			'deprecation' => array( 'minor_releases' => self::DEPRECATION_MINOR_RELEASES, 'months' => self::DEPRECATION_MONTHS ),
		);
	}
}
