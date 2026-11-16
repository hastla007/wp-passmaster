<?php
/**
 * Review participation contract v1 (A667, CONCEPT.md "Zusammenspiel mit Form, Review und Affiliate
 * Captain"): what Pass Captain tells a review provider (Review Captain or any other plugin) about
 * actual participation. Pass Captain is the source of truth for event, ticket, cancellation, refund
 * and admission; invitations, frequency limits, opt-out, sending and review data belong to the
 * provider.
 *
 * - Versioning: SemVer like every contract (docs/CONTRACTS.md). Provider: filter
 *   `wp_pass_captain_review_provider_v1` returns an object with `contract_version(): string`
 *   (optional, default VERSION) and `receive( array $envelope )`; another major version is never
 *   called (status "incompatible").
 * - experience_completed: once per participation (ticket) at the first valid admission, or at an
 *   explicitly allowed attendance confirmation (command attendance.confirm, A676) – never for
 *   purchase, ticket dispatch, redemption alone or a generic form entry. Again only after the
 *   admission was undone and the ticket is admitted anew.
 * - experience_excluded: correction per participation – check_in_undone (no valid admission left),
 *   refunded, revoked (incl. order cancellation), transferred (the previous recipient reference is no
 *   longer valid), no_show (event ticket not admitted until the end of the date). Idempotent per
 *   participation and reason (per undo cycle for check_in_undone).
 * - experience_cancelled: a cancelled date or a cancelled event, once on event level (scope
 *   occurrence|event) instead of one event per ticket.
 * - No personal data: no e-mail, name or ticket code. The recipient is an opaque `recipient_ref` that
 *   changes with every holder change; the contact behind it is only available through the
 *   ContactResolver with its own capability (A668), audited.
 * - Idempotency: event ids are stable (type + participation + cycle), the provider guarantees "at most
 *   one request per experience and recipient" with experience_id + recipient_ref.
 * - The core contains no licence or entitlement check; without a provider nothing changes in the core.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Review;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Contract extends \PassCaptain\Suite\Contract {
	const NAME = 'review';
	const VERSION = '1.0.0';
	const FILTER = 'wp_pass_captain_review_provider_v1';
	/** Replay of review events. */
	const CAPABILITY = 'manage_options';
	/** Own capability of the contact resolver (A668); never granted implicitly by this contract. */
	const RESOLVER_CAPABILITY = 'wpcap_resolve_review_contacts';
	const DEPRECATION_MINOR_RELEASES = 2;
	const DEPRECATION_MONTHS = 6;
	const DEPRECATED = array();

	/** Reasons of experience_excluded. */
	const EXCLUSION_REASONS = array( 'check_in_undone', 'refunded', 'revoked', 'transferred', 'no_show' );

	const ERRORS = array(
		'wpcap_review_contract_version'   => 'provider speaks another major version – never called',
		'wpcap_review_provider_missing'   => 'no provider registered – events stay in the outbox (replayable)',
		'wpcap_review_field_not_allowed'  => 'a field outside the allowlist was dropped before recording',
		'wpcap_review_provider_error'     => 'the provider threw or answered outside the contract – retried with backoff',
		'wpcap_review_forbidden'          => 'the current user lacks the capability (replay, contact resolver)',
		'wpcap_review_not_eligible'       => 'resolver: the participation is excluded (refund, cancellation, no admission …)',
		'wpcap_review_recipient_changed'  => 'resolver: the ticket has another holder now – recipient_ref is outdated',
		'wpcap_review_not_found'          => 'resolver: unknown participation',
		'wpcap_review_temporary'          => 'provider: temporary failure – retried with backoff',
		'wpcap_review_rejected'           => 'provider: permanent rejection – not retried',
	);

	/** Object shapes (types as in the workflow contract). */
	const OBJECTS = array(
		'participation' => array( 'participation_id' => 'string', 'experience_id' => 'string', 'event_id' => 'int', 'occurrence_id' => 'int', 'ticket_type_id' => 'int', 'recipient_ref' => 'string', 'locale' => 'string' ),
		'experience' => array( 'experience_id' => 'string', 'scope' => 'string', 'event_id' => 'int', 'occurrence_ids' => 'int[]' ),
	);

	const EVENTS = array(
		'experience_completed' => array( '1.0', 'participation', array( 'outcome' => 'string', 'completed_at' => 'string', 'method' => 'string', 'source' => 'string' ) ),
		'experience_excluded'  => array( '1.0', 'participation', array( 'outcome' => 'string', 'reason' => 'string', 'excluded_at' => 'string', 'previous_recipient_ref' => '?string' ) ),
		'experience_cancelled' => array( '1.0', 'experience', array( 'outcome' => 'string', 'cancelled_at' => 'string' ) ),
	);
}
