<?php
/**
 * Membership and season-pass issue/redeem operations.
 *
 * Pass rules have a scope: "event" rules admit to events (redeem(), scanner/check-in), "service"
 * rules are used for service resources (redeem_service()), "any" rules work for both. Service rules
 * are slot-free (used directly at the resource, e.g. open training) or slot-bound (the use books a
 * place in a slot, capacity-checked like a paid booking). Single use = uses_total 1, multi use =
 * uses_total > 1, unlimited = 0; uses_per_day caps daily uses; a rule may expire before the pass.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Memberships {
	const SCOPES = array( 'event', 'service', 'any' );
	const SLOT_MODES = array( 'slot_free', 'slot_bound' );

	/** Validated scope fields of a rule definition. */
	private static function rule_scope( array $rule ) {
		$scope = in_array( $rule['scope'] ?? 'event', self::SCOPES, true ) ? (string) ( $rule['scope'] ?? 'event' ) : 'event';
		$expires = sanitize_text_field( (string) ( $rule['expires_at'] ?? '' ) );
		return array(
			'scope' => $scope,
			'resource_id' => 'event' === $scope ? 0 : absint( $rule['resource_id'] ?? 0 ),
			'slot_mode' => in_array( $rule['slot_mode'] ?? 'slot_free', self::SLOT_MODES, true ) ? (string) ( $rule['slot_mode'] ?? 'slot_free' ) : 'slot_free',
			'expires_at' => '' !== $expires && strtotime( $expires . ' UTC' ) ? gmdate( 'Y-m-d H:i:s', strtotime( $expires . ' UTC' ) ) : null,
		);
	}

	public static function revoke( $pass_id ) {
		global $wpdb;
		$pass_id = absint( $pass_id );
		$result = $wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'status' => 'revoked', 'token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ) ), array( 'pass_id' => $pass_id, 'status' => 'active' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( false === $result ) { return new \WP_Error( 'wpcap_pass_revoke_failed', __( 'Pass konnte nicht widerrufen werden.', 'pass-captain' ) ); }
		Audit::record( 'member_pass_revoked', 'member_pass', $pass_id );
		return true;
	}

	public static function issue( $email, $valid_from, $valid_until, array $rules ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! $rules || strtotime( $valid_until . ' UTC' ) <= strtotime( $valid_from . ' UTC' ) ) { return new \WP_Error( 'wpcap_pass_invalid', __( 'Passdaten ungültig.', 'pass-captain' ) ); }
		$token = bin2hex( random_bytes( 32 ) );
		$passes = $wpdb->prefix . 'wpcap_member_passes';
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( $passes, array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'member_email' => $email, 'token_hash' => hash( 'sha256', $token ), 'valid_from' => sanitize_text_field( $valid_from ), 'valid_until' => sanitize_text_field( $valid_until ), 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$pass_id = (int) $wpdb->insert_id;
		if ( ! $pass_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_save_failed', __( 'Pass konnte nicht angelegt werden.', 'pass-captain' ) ); }
		foreach ( $rules as $rule ) {
			$scope = self::rule_scope( (array) $rule );
			$wpdb->insert( $wpdb->prefix . 'wpcap_pass_rules', array( 'pass_id' => $pass_id, 'event_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['ticket_type_id'] ?? 0 ), 'uses_total' => absint( $rule['uses_total'] ?? 0 ), 'uses_per_day' => max( 1, absint( $rule['uses_per_day'] ?? 1 ) ) ) + $scope, array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
			if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_rule_failed', __( 'Passregeln konnten nicht gespeichert werden.', 'pass-captain' ) ); }
		}
		$wpdb->query( 'COMMIT' );
		Mail\Mailer::send( 'member_pass', $email, array( 'pass_code' => $token, 'valid_from' => (string) $valid_from, 'valid_until' => (string) $valid_until ) );
		Audit::record( 'member_pass_issued', 'member_pass', $pass_id, array( 'rule_count' => count( $rules ) ) );
		return array( 'pass_id' => $pass_id, 'token' => $token );
	}

	public static function redeem( $token, $ticket_type_id, $idempotency_key ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		$ticket_type_id = absint( $ticket_type_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( 64 !== strlen( $token ) || ! $ticket_type_id || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_pass_redeem_invalid', __( 'Passcode oder Ticketkategorie ungültig.', 'pass-captain' ) ); }
		$key = hash( 'sha256', $token . '|' . $idempotency_key );
		$uses = $wpdb->prefix . 'wpcap_pass_uses';
		$wpdb->query( 'START TRANSACTION' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT pass_use_id,pass_id FROM $uses WHERE idempotency_key=%s", $key ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id ); }
		$pass = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_member_passes WHERE token_hash=%s FOR UPDATE", hash( 'sha256', $token ) ) );
		$now = current_time( 'mysql', true );
		if ( ! $pass || 'active' !== $pass->status || $pass->valid_from > $now || $pass->valid_until < $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_inactive', __( 'Pass ist ungültig oder außerhalb der Laufzeit.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT r.*,o.timezone FROM {$wpdb->prefix}wpcap_pass_rules r INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=%d INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE r.pass_id=%d AND r.scope IN ('event','any') AND (r.expires_at IS NULL OR r.expires_at>%s) AND (r.ticket_type_id=%d OR r.ticket_type_id=0) AND (r.event_id=o.event_id OR r.event_id=0) ORDER BY r.ticket_type_id DESC,r.event_id DESC LIMIT 1 FOR UPDATE", $ticket_type_id, $pass->pass_id, $now, $ticket_type_id ) );
		if ( ! $rule ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_scope', __( 'Dieser Pass gilt nicht für dieses Event oder diese Ticketkategorie.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$day = wp_date( 'Y-m-d', time(), new \DateTimeZone( $rule->timezone ) );
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d AND service_date=%s", $pass->pass_id, $rule->rule_id, $day ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d", $pass->pass_id, $rule->rule_id ) );
		if ( (int) $rule->uses_per_day > 0 && $today >= (int) $rule->uses_per_day || (int) $rule->uses_total > 0 && $total >= (int) $rule->uses_total ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_quota', __( 'Das Nutzungskontingent dieses Passes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->insert( $uses, array( 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'service_date' => $day, 'user_id' => get_current_user_id(), 'idempotency_key' => $key, 'created_at' => $now ), array( '%d', '%d', '%s', '%d', '%s', '%s' ) );
		$use_id = (int) $wpdb->insert_id;
		if ( ! $use_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_redeem_failed', __( 'Pass konnte nicht eingelöst werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'member_pass_redeemed', 'member_pass', $pass->pass_id, array( 'ticket_type_id' => $ticket_type_id, 'pass_use_id' => $use_id ) );
		return array( 'status' => 'redeemed', 'pass_id' => (int) $pass->pass_id );
	}

	/**
	 * Uses a pass for a service resource. Only rules with scope "service" or "any" apply (an event
	 * entry pass cannot be used here unless configured with scope "any"). Slot-free rules record the
	 * use directly ($slot_id must be 0); slot-bound rules need a slot of the resource and book it
	 * (confirmed, capacity and collision checked) in the same call. Idempotent per key.
	 *
	 * @return array|\WP_Error status (redeemed|already_redeemed), pass_id, rule_id, booking_id (slot-bound).
	 */
	public static function redeem_service( $token, $resource_id, $idempotency_key, $slot_id = 0, $quantity = 1 ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		$resource_id = absint( $resource_id );
		$slot_id = absint( $slot_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( 64 !== strlen( $token ) || ! $resource_id || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_pass_redeem_invalid', __( 'Passcode oder Ressource ungültig.', 'pass-captain' ) ); }
		$resource = ServiceResources::resource( $resource_id );
		if ( ! $resource || 'active' !== $resource->status ) { return new \WP_Error( 'wpcap_pass_scope', __( 'Diese Ressource ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $slot_id ) {
			$slot = ServiceResources::slot( $slot_id );
			if ( ! $slot || (int) $slot->resource_id !== $resource_id ) { return new \WP_Error( 'wpcap_pass_slot', __( 'Das Zeitfenster gehört nicht zu dieser Ressource.', 'pass-captain' ), array( 'status' => 400 ) ); }
		}
		$key = hash( 'sha256', $token . '|service|' . $idempotency_key );
		$uses = $wpdb->prefix . 'wpcap_pass_uses';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT pass_use_id,pass_id,rule_id,booking_id FROM $uses WHERE idempotency_key=%s", $key ) );
		if ( $existing ) { return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id, 'rule_id' => (int) $existing->rule_id, 'booking_id' => (int) $existing->booking_id ); }
		$wpdb->query( 'START TRANSACTION' );
		$pass = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_member_passes WHERE token_hash=%s FOR UPDATE", hash( 'sha256', $token ) ) );
		$now = current_time( 'mysql', true );
		if ( ! $pass || 'active' !== $pass->status || $pass->valid_from > $now || $pass->valid_until < $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_inactive', __( 'Pass ist ungültig oder außerhalb der Laufzeit.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT pass_use_id,pass_id,rule_id,booking_id FROM $uses WHERE idempotency_key=%s", $key ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id, 'rule_id' => (int) $existing->rule_id, 'booking_id' => (int) $existing->booking_id ); }
		$mode = $slot_id ? 'slot_bound' : 'slot_free';
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d AND scope IN ('service','any') AND slot_mode=%s AND (resource_id=%d OR resource_id=0) AND (expires_at IS NULL OR expires_at>%s) ORDER BY resource_id DESC,rule_id LIMIT 1 FOR UPDATE", (int) $pass->pass_id, $mode, $resource_id, $now ) );
		if ( ! $rule ) {
			$wpdb->query( 'ROLLBACK' );
			$other = $slot_id ? __( 'Dieser Pass gilt nur ohne Terminbuchung.', 'pass-captain' ) : __( 'Dieser Pass erfordert die Buchung eines Zeitfensters.', 'pass-captain' );
			$any = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d AND scope IN ('service','any') AND (resource_id=%d OR resource_id=0) AND (expires_at IS NULL OR expires_at>%s)", (int) $pass->pass_id, $resource_id, $now ) );
			return new \WP_Error( 'wpcap_pass_scope', $any ? $other : __( 'Dieser Pass gilt nicht für diese Ressource.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$tz = ServiceResources::timezone_of( $resource );
		$day = wp_date( 'Y-m-d', $slot_id ? strtotime( $slot->starts_at . ' UTC' ) : time(), new \DateTimeZone( $tz ) );
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d AND service_date=%s", $pass->pass_id, $rule->rule_id, $day ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d", $pass->pass_id, $rule->rule_id ) );
		if ( ( (int) $rule->uses_per_day > 0 && $today >= (int) $rule->uses_per_day ) || ( (int) $rule->uses_total > 0 && $total >= (int) $rule->uses_total ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_quota', __( 'Das Nutzungskontingent dieses Passes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->insert( $uses, array( 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'service_date' => $day, 'user_id' => get_current_user_id(), 'idempotency_key' => $key, 'resource_id' => $resource_id, 'slot_id' => $slot_id, 'created_at' => $now ), array( '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ) );
		$use_id = (int) $wpdb->insert_id;
		if ( ! $use_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_redeem_failed', __( 'Pass konnte nicht eingelöst werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		$booking_id = 0;
		if ( $slot_id ) {
			// Book the place; a full slot or a rule violation undoes the use.
			$booking = ServiceBookings::reserve( $slot_id, (string) $pass->member_email, $quantity, 0, 15, 'pass-' . $key, array( 'actor' => 'customer', 'skip_rate_limit' => true, 'status' => 'confirmed', 'pass_use_id' => $use_id ) );
			if ( is_wp_error( $booking ) ) {
				$wpdb->delete( $uses, array( 'pass_use_id' => $use_id ), array( '%d' ) );
				return $booking;
			}
			$booking_id = (int) $booking['booking_id'];
			$wpdb->update( $uses, array( 'booking_id' => $booking_id ), array( 'pass_use_id' => $use_id ), array( '%d' ), array( '%d' ) );
		}
		Audit::record( 'member_pass_service_redeemed', 'member_pass', (int) $pass->pass_id, array( 'resource_id' => $resource_id, 'slot_id' => $slot_id, 'rule_id' => (int) $rule->rule_id, 'pass_use_id' => $use_id, 'booking_id' => $booking_id ) );
		return array( 'status' => 'redeemed', 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'booking_id' => $booking_id );
	}

	/** Gives a use back (slot-bound booking cancelled in time). */
	public static function release_use( $pass_use_id ) {
		global $wpdb;
		$use = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_pass_uses WHERE pass_use_id=%d", absint( $pass_use_id ) ) );
		if ( ! $use ) { return false; }
		$wpdb->delete( $wpdb->prefix . 'wpcap_pass_uses', array( 'pass_use_id' => (int) $use->pass_use_id ), array( '%d' ) );
		Audit::record( 'member_pass_use_released', 'member_pass', (int) $use->pass_id, array( 'pass_use_id' => (int) $use->pass_use_id, 'booking_id' => (int) $use->booking_id ) );
		return true;
	}

	public static function get( $pass_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT pass_id,public_id,member_email,valid_from,valid_until,status,created_at FROM {$wpdb->prefix}wpcap_member_passes WHERE pass_id=%d", absint( $pass_id ) ) );
	}

	/** Rules of a pass with their use counts. */
	public static function rules( $pass_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT r.*,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_pass_uses u WHERE u.rule_id=r.rule_id) AS used FROM {$wpdb->prefix}wpcap_pass_rules r WHERE r.pass_id=%d ORDER BY r.rule_id", absint( $pass_id ) ) );
	}

	/**
	 * Changes the validity period of a pass (UTC datetimes).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_validity( $pass_id, $valid_from, $valid_until ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return new \WP_Error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$valid_from = sanitize_text_field( (string) $valid_from );
		$valid_until = sanitize_text_field( (string) $valid_until );
		if ( ! strtotime( $valid_from . ' UTC' ) || strtotime( $valid_until . ' UTC' ) <= strtotime( $valid_from . ' UTC' ) ) { return new \WP_Error( 'wpcap_pass_invalid', __( 'Passdaten ungültig.', 'pass-captain' ) ); }
		$wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'valid_from' => $valid_from, 'valid_until' => $valid_until ), array( 'pass_id' => (int) $pass->pass_id ), array( '%s', '%s' ), array( '%d' ) );
		Audit::record( 'member_pass_updated', 'member_pass', (int) $pass->pass_id, array( 'valid_from' => $valid_from, 'valid_until' => $valid_until, 'previous_from' => $pass->valid_from, 'previous_until' => $pass->valid_until ) );
		return true;
	}

	/**
	 * Adds a rule (rule_id 0) or changes the quotas of an existing rule. The scope (event, ticket
	 * type) of an existing rule stays fixed so recorded uses keep their meaning.
	 *
	 * @return int|\WP_Error Rule id.
	 */
	public static function save_rule( $pass_id, $rule_id, array $rule ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return new \WP_Error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$values = array( 'uses_total' => min( 65535, absint( $rule['uses_total'] ?? 0 ) ), 'uses_per_day' => min( 65535, max( 1, absint( $rule['uses_per_day'] ?? 1 ) ) ) );
		$table = $wpdb->prefix . 'wpcap_pass_rules';
		if ( $rule_id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT rule_id FROM $table WHERE rule_id=%d AND pass_id=%d", absint( $rule_id ), (int) $pass->pass_id ) );
			if ( ! $existing ) { return new \WP_Error( 'wpcap_pass_rule_missing', __( 'Passregel nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
			$wpdb->update( $table, $values, array( 'rule_id' => (int) $existing->rule_id ), array( '%d', '%d' ), array( '%d' ) );
			Audit::record( 'member_pass_rule_updated', 'member_pass', (int) $pass->pass_id, array( 'rule_id' => (int) $existing->rule_id ) + $values );
			return (int) $existing->rule_id;
		}
		$scope = self::rule_scope( $rule );
		$wpdb->insert( $table, array( 'pass_id' => (int) $pass->pass_id, 'event_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['ticket_type_id'] ?? 0 ) ) + $values + $scope, array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_pass_rule_failed', __( 'Passregeln konnten nicht gespeichert werden.', 'pass-captain' ) ); }
		$new_id = (int) $wpdb->insert_id;
		Audit::record( 'member_pass_rule_added', 'member_pass', (int) $pass->pass_id, array( 'rule_id' => $new_id, 'event_id' => absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => absint( $rule['ticket_type_id'] ?? 0 ) ) + $values + $scope );
		return $new_id;
	}
}
