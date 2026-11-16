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

final class Contract extends \PassCaptain\Suite\Contract {
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

	// known(), fields(), sanitize(), envelope(), provider(), describe(): PassCaptain\Suite\Contract.
}
