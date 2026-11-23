<?php
/**
 * Contact resolver of the review contract (A668): review events carry no e-mail and no name, only an
 * opaque recipient_ref. A review provider that wants to invite a participant asks this resolver for
 * the contact – explicitly, per participation, with its own capability.
 *
 * - Capability Contract::RESOLVER_CAPABILITY (`wpcap_resolve_review_contacts`). It is not part of the
 *   plugin roles and not granted to administrators by default: the site owner grants it deliberately
 *   (e.g. to the service account a review plugin runs its invitations under).
 * - Only for completed experiences (current completion cycle recorded, no undo since), paid and not
 *   refunded tickets, dates and events that are not cancelled, and only for the current holder:
 *   an outdated recipient_ref (holder changed, transfer) is refused.
 * - Every call is audited (`review_contact_resolved`, participation id, purpose and result – never
 *   the contact itself).
 * - Opt-out, invitation rights, frequency limits and sending remain the provider's job.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Review;

use PassCaptain\Audit;
use PassCaptain\Suite\Outbox;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ContactResolver {
	const VERSION = '1.0.0';

	/**
	 * Contact of a participation's current holder.
	 *
	 * @param string $participation_id Public ticket id (participation_id of the events).
	 * @param string $recipient_ref    recipient_ref of the event the invitation is based on.
	 * @param array  $context          purpose (default review_invitation), source (plugin slug).
	 * @return array|\WP_Error participation_id, recipient_ref, email, name, locale.
	 */
	public static function resolve( $participation_id, $recipient_ref, array $context = array() ) {
		global $wpdb;
		$participation_id = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $participation_id ) );
		$purpose = sanitize_key( (string) ( $context['purpose'] ?? 'review_invitation' ) );
		$source = sanitize_key( (string) ( $context['source'] ?? '' ) );
		$ticket_id = 32 === strlen( $participation_id ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_id FROM {$wpdb->prefix}wpcap_tickets WHERE public_id=%s", $participation_id ) ) : 0;
		$audit = static function ( $result ) use ( $ticket_id, $participation_id, $purpose, $source ) {
			Audit::record( 'review_contact_resolved', 'ticket', $ticket_id, array( 'participation_id' => $participation_id, 'purpose' => $purpose, 'source' => $source, 'result' => $result ) );
		};
		if ( ! current_user_can( Contract::RESOLVER_CAPABILITY ) ) {
			$audit( 'forbidden' );
			return new \WP_Error( 'wpcap_review_forbidden', __( 'Keine Berechtigung, Kontaktdaten für Bewertungseinladungen abzurufen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( ! $ticket_id ) {
			$audit( 'not_found' );
			return new \WP_Error( 'wpcap_review_not_found', __( 'Teilnahme nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$reason = self::ineligible( $ticket_id );
		if ( '' !== $reason ) {
			$audit( 'not_eligible:' . $reason );
			return new \WP_Error( 'wpcap_review_not_eligible', __( 'Für diese Teilnahme ist keine Bewertungseinladung zulässig.', 'pass-captain' ), array( 'status' => 409, 'reason' => $reason ) );
		}
		if ( ! hash_equals( Events::recipient_ref( $ticket_id ), (string) $recipient_ref ) ) {
			$audit( 'recipient_changed' );
			return new \WP_Error( 'wpcap_review_recipient_changed', __( 'Das Ticket hat inzwischen eine andere Inhaberin bzw. einen anderen Inhaber.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.attendee_email,ord.customer_email,ord.locale,COALESCE(a.attendee_email,'') AS assigned_email,COALESCE(a.attendee_name,'') AS attendee_name FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id WHERE t.ticket_id=%d", $ticket_id ) );
		$email = '';
		foreach ( array( (string) $row->assigned_email, (string) $row->attendee_email, (string) $row->customer_email ) as $candidate ) {
			if ( '' !== $candidate && is_email( $candidate ) && ! \PassCaptain\Mail\Mailer::is_placeholder( $candidate ) ) { $email = $candidate; break; }
		}
		if ( '' === $email ) {
			$audit( 'no_contact' );
			return new \WP_Error( 'wpcap_review_not_eligible', __( 'Für diese Teilnahme ist keine E-Mail-Adresse hinterlegt.', 'pass-captain' ), array( 'status' => 409, 'reason' => 'no_contact' ) );
		}
		$audit( 'ok' );
		return array( 'participation_id' => $participation_id, 'recipient_ref' => (string) $recipient_ref, 'email' => $email, 'name' => (string) $row->attendee_name, 'locale' => (string) $row->locale );
	}

	/**
	 * Why no invitation may be sent for this ticket ('' = allowed): see Events::ineligible(), plus
	 * not_completed (no completed experience in the current cycle, e.g. no admission or undone).
	 */
	public static function ineligible( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.status,t.refunded,o.status AS occurrence_status,e.status AS event_status,ord.payment_status FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
		$reason = Events::ineligible( $row );
		if ( '' !== $reason ) { return $reason; }
		return Events::completed( (int) $ticket_id ) ? '' : 'not_completed';
	}
}
