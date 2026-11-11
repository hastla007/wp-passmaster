<?php
/** Membership and season-pass issue/redeem operations. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Memberships {
	public static function revoke( $pass_id ) {
		global $wpdb;
		$pass_id = absint( $pass_id );
		$result = $wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'status' => 'revoked', 'token_hash' => hash( 'sha256', wp_generate_password( 64, true, true ) ) ), array( 'pass_id' => $pass_id, 'status' => 'active' ), array( '%s', '%s' ), array( '%d', '%s' ) );
		if ( false === $result ) { return new \WP_Error( 'wpcap_pass_revoke_failed', __( 'Pass konnte nicht widerrufen werden.', 'wp-pass-captain' ) ); }
		Audit::record( 'member_pass_revoked', 'member_pass', $pass_id );
		return true;
	}

	public static function issue( $email, $valid_from, $valid_until, array $rules ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! $rules || strtotime( $valid_until . ' UTC' ) <= strtotime( $valid_from . ' UTC' ) ) { return new \WP_Error( 'wpcap_pass_invalid', __( 'Passdaten ungültig.', 'wp-pass-captain' ) ); }
		$token = bin2hex( random_bytes( 32 ) );
		$passes = $wpdb->prefix . 'wpcap_member_passes';
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( $passes, array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'member_email' => $email, 'token_hash' => hash( 'sha256', $token ), 'valid_from' => sanitize_text_field( $valid_from ), 'valid_until' => sanitize_text_field( $valid_until ), 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$pass_id = (int) $wpdb->insert_id;
		if ( ! $pass_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_save_failed', __( 'Pass konnte nicht angelegt werden.', 'wp-pass-captain' ) ); }
		foreach ( $rules as $rule ) {
			$wpdb->insert( $wpdb->prefix . 'wpcap_pass_rules', array( 'pass_id' => $pass_id, 'event_id' => absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => absint( $rule['ticket_type_id'] ?? 0 ), 'uses_total' => absint( $rule['uses_total'] ?? 0 ), 'uses_per_day' => max( 1, absint( $rule['uses_per_day'] ?? 1 ) ) ), array( '%d', '%d', '%d', '%d', '%d' ) );
			if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_rule_failed', __( 'Passregeln konnten nicht gespeichert werden.', 'wp-pass-captain' ) ); }
		}
		$wpdb->query( 'COMMIT' );
		wp_mail( $email, sprintf( /* translators: %s: site name */ __( 'Dein Pass für %s', 'wp-pass-captain' ), Orders::site_name() ), __( 'Dein Passcode:', 'wp-pass-captain' ) . ' ' . $token . "\n" . __( 'Gültig bis:', 'wp-pass-captain' ) . ' ' . $valid_until );
		Audit::record( 'member_pass_issued', 'member_pass', $pass_id, array( 'rule_count' => count( $rules ) ) );
		return array( 'pass_id' => $pass_id, 'token' => $token );
	}

	public static function redeem( $token, $ticket_type_id, $idempotency_key ) {
		global $wpdb;
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		$ticket_type_id = absint( $ticket_type_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( 64 !== strlen( $token ) || ! $ticket_type_id || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_pass_redeem_invalid', __( 'Passcode oder Ticketkategorie ungültig.', 'wp-pass-captain' ) ); }
		$key = hash( 'sha256', $token . '|' . $idempotency_key );
		$uses = $wpdb->prefix . 'wpcap_pass_uses';
		$wpdb->query( 'START TRANSACTION' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT pass_use_id,pass_id FROM $uses WHERE idempotency_key=%s", $key ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id ); }
		$pass = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_member_passes WHERE token_hash=%s FOR UPDATE", hash( 'sha256', $token ) ) );
		$now = current_time( 'mysql', true );
		if ( ! $pass || 'active' !== $pass->status || $pass->valid_from > $now || $pass->valid_until < $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_inactive', __( 'Pass ist ungültig oder außerhalb der Laufzeit.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT r.*,o.timezone FROM {$wpdb->prefix}wpcap_pass_rules r INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=%d INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE r.pass_id=%d AND (r.ticket_type_id=%d OR r.ticket_type_id=0) AND (r.event_id=o.event_id OR r.event_id=0) ORDER BY r.ticket_type_id DESC,r.event_id DESC LIMIT 1 FOR UPDATE", $ticket_type_id, $pass->pass_id, $ticket_type_id ) );
		if ( ! $rule ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_scope', __( 'Dieser Pass gilt nicht für dieses Event oder diese Ticketkategorie.', 'wp-pass-captain' ), array( 'status' => 403 ) ); }
		$day = wp_date( 'Y-m-d', time(), new \DateTimeZone( $rule->timezone ) );
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d AND service_date=%s", $pass->pass_id, $rule->rule_id, $day ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $uses WHERE pass_id=%d AND rule_id=%d", $pass->pass_id, $rule->rule_id ) );
		if ( (int) $rule->uses_per_day > 0 && $today >= (int) $rule->uses_per_day || (int) $rule->uses_total > 0 && $total >= (int) $rule->uses_total ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_quota', __( 'Das Nutzungskontingent dieses Passes ist ausgeschöpft.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->insert( $uses, array( 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'service_date' => $day, 'user_id' => get_current_user_id(), 'idempotency_key' => $key, 'created_at' => $now ), array( '%d', '%d', '%s', '%d', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_redeem_failed', __( 'Pass konnte nicht eingelöst werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'member_pass_redeemed', 'member_pass', $pass->pass_id, array( 'ticket_type_id' => $ticket_type_id, 'pass_use_id' => (int) $wpdb->insert_id ) );
		return array( 'status' => 'redeemed', 'pass_id' => (int) $pass->pass_id );
	}
}
