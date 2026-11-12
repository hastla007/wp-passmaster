<?php
/**
 * Signed offline verification packages per occurrence.
 *
 * Change tracking without core hooks: wpcap_offline_entries keeps a snapshot of every ticket hash
 * of an occurrence with a fingerprint (status, ticket type, attendee name if shipped). refresh()
 * compares the live tickets with the snapshot and stamps changed rows with a new occurrence version;
 * hashes that disappeared (ticket moved/reissued) and tickets that are no longer admissible become
 * "revoked". A delta (since=cursor) therefore contains every change incl. revocations.
 *
 * Cursor: "<epoch>.<version>" – epoch is random per occurrence snapshot, so a cursor of a reset or
 * foreign snapshot falls back to a full package.
 *
 * The signed document is the exact JSON string in `payload`; the Ed25519 signature (base64) covers
 * its UTF-8 bytes. Devices must verify before parsing and use only the signed fields.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Policies;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Package {
	const FORMAT = 'wpcap-offline-package';
	const VERSION = 1;

	/** Ticket statuses shipped as admissible/used; everything else is "revoked". */
	const SHIPPED = array( 'valid', 'checked_in' );

	/** Minimum seconds between two snapshot refreshes of one occurrence (filterable). */
	private static function refresh_interval() {
		return max( 0, (int) apply_filters( 'wpcap_offline_refresh_interval', 10 ) );
	}

	private static function state( $occurrence_id, $lock = false ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_offline_state WHERE occurrence_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), absint( $occurrence_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Effective status of a ticket in the package. */
	private static function effective_status( $row ) {
		if ( 'cancelled' === $row->occurrence_status || 'cancelled' === $row->event_status ) { return 'revoked'; }
		return in_array( $row->status, self::SHIPPED, true ) ? $row->status : 'revoked';
	}

	/**
	 * Brings the snapshot of an occurrence up to date.
	 *
	 * @param bool $with_names Whether attendee names are part of the fingerprint (shipped).
	 * @return object State row (epoch, version).
	 */
	public static function refresh( $occurrence_id, $with_names, $force = false ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occurrence_id = absint( $occurrence_id );
		$state = self::state( $occurrence_id );
		$now = time();
		if ( $state && ! $force && $state->refreshed_at && strtotime( $state->refreshed_at . ' UTC' ) > $now - self::refresh_interval() ) { return $state; }
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$p}wpcap_offline_state (occurrence_id,epoch,version) VALUES (%d,%s,0)", $occurrence_id, bin2hex( random_bytes( 8 ) ) ) );
		$wpdb->query( 'START TRANSACTION' );
		$state = self::state( $occurrence_id, true );
		$version = (int) $state->version + 1;
		$live = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.token_hash,t.status,t.ticket_type_id,ta.attendee_name,o.status AS occurrence_status,e.status AS event_status FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE tt.occurrence_id=%d AND t.token_hash<>''",
			$occurrence_id
		) );
		/**
		 * [Passes] Live ticket rows of the occurrence's snapshot (ticket_id, token_hash, status,
		 * ticket_type_id, attendee_name, occurrence_status, event_status). Modules may add tickets
		 * valid here whose ticket type belongs to another occurrence (multi-date passes) or drop rows.
		 */
		$live = (array) apply_filters( 'wpcap_offline_live_tickets', $live, $occurrence_id );
		$known = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT token_hash,fingerprint,status FROM {$p}wpcap_offline_entries WHERE occurrence_id=%d", $occurrence_id ) ) as $row ) {
			$known[ $row->token_hash ] = $row;
		}
		$changed = array();
		$seen = array();
		foreach ( $live as $row ) {
			$hash = strtolower( (string) $row->token_hash );
			if ( isset( $seen[ $hash ] ) ) { continue; }
			$seen[ $hash ] = true;
			$status = self::effective_status( $row );
			$name = $with_names ? mb_substr( (string) $row->attendee_name, 0, 190 ) : '';
			$fingerprint = md5( $status . '|' . (int) $row->ticket_type_id . '|' . $name );
			if ( isset( $known[ $hash ] ) && $known[ $hash ]->fingerprint === $fingerprint ) { continue; }
			// A hash first seen as not admissible is not interesting for devices that never had it.
			if ( ! isset( $known[ $hash ] ) && 'revoked' === $status ) { continue; }
			$changed[] = $wpdb->prepare( '(%d,%s,%d,%d,%s,%s,%s,%d)', $occurrence_id, $hash, (int) $row->ticket_id, (int) $row->ticket_type_id, $status, $name, $fingerprint, $version );
		}
		foreach ( $known as $hash => $row ) {
			if ( ! isset( $seen[ $hash ] ) && 'revoked' !== $row->status ) {
				$changed[] = $wpdb->prepare( '(%d,%s,0,0,%s,%s,%s,%d)', $occurrence_id, $hash, 'revoked', '', md5( 'gone' ), $version );
			}
		}
		foreach ( array_chunk( $changed, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$p}wpcap_offline_entries (occurrence_id,token_hash,ticket_id,ticket_type_id,status,attendee_name,fingerprint,version) VALUES " . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE ticket_id=IF(VALUES(ticket_id)>0,VALUES(ticket_id),ticket_id),ticket_type_id=IF(VALUES(ticket_type_id)>0,VALUES(ticket_type_id),ticket_type_id),status=VALUES(status),attendee_name=VALUES(attendee_name),fingerprint=VALUES(fingerprint),version=VALUES(version)' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- rows prepared above.
		}
		$wpdb->update( "{$p}wpcap_offline_state", array( 'version' => $changed ? $version : (int) $state->version, 'refreshed_at' => gmdate( 'Y-m-d H:i:s', $now ) ), array( 'occurrence_id' => $occurrence_id ) );
		$wpdb->query( 'COMMIT' );
		return self::state( $occurrence_id );
	}

	/** Parses "<epoch>.<version>"; null when not usable for this snapshot. */
	private static function parse_cursor( $since, $state ) {
		if ( ! is_string( $since ) || ! preg_match( '/^([a-f0-9]{16})\.(\d{1,10})$/', $since, $m ) ) { return null; }
		if ( $m[1] !== $state->epoch || (int) $m[2] > (int) $state->version ) { return null; }
		return (int) $m[2];
	}

	/**
	 * Builds and signs the package of an occurrence for a device (caller enforces opt-in/assignment).
	 *
	 * @param object      $device     Device row.
	 * @param object      $occurrence Occurrence row with event_name, event_status.
	 * @param string|null $since      Cursor of the device's current package.
	 * @return array Response body: alg, key_id, payload (signed JSON string), signature, kind, cursor, expires_at.
	 */
	public static function build( $device, $occurrence, $since = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = (int) $occurrence->event_id;
		$rules = Rules::get( $event_id );
		$names = Rules::attendee_names( $rules );
		$state = self::refresh( (int) $occurrence->occurrence_id, $names );
		$from = null === $since || '' === $since ? null : self::parse_cursor( (string) $since, $state );
		$kind = null === $from ? 'full' : 'delta';
		if ( 'full' === $kind ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT token_hash,status,ticket_type_id,attendee_name FROM {$p}wpcap_offline_entries WHERE occurrence_id=%d AND status IN ('valid','checked_in') ORDER BY token_hash", (int) $occurrence->occurrence_id ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT token_hash,status,ticket_type_id,attendee_name FROM {$p}wpcap_offline_entries WHERE occurrence_id=%d AND version>%d ORDER BY token_hash", (int) $occurrence->occurrence_id, $from ) );
		}
		$tickets = array();
		foreach ( $rows as $row ) {
			$entry = array( 'h' => $row->token_hash, 's' => $row->status );
			if ( 'revoked' !== $row->status ) { $entry['t'] = (int) $row->ticket_type_id; }
			if ( $names && '' !== (string) $row->attendee_name && 'revoked' !== $row->status ) { $entry['n'] = (string) $row->attendee_name; }
			$tickets[] = $entry;
		}
		$types = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,name FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d ORDER BY ticket_type_id", (int) $occurrence->occurrence_id ) ) as $type ) {
			$policy = Policies::for_type( (int) $type->ticket_type_id );
			$types[] = array( 'id' => (int) $type->ticket_type_id, 'name' => (string) $type->name, 'areas' => Areas::type_area_ids( (int) $type->ticket_type_id ), 'policy' => $policy ? $policy['policy'] : 'single', 'multi' => $policy && 'single' !== $policy['policy'] );
		}
		// [Passes] Ticket type descriptors of the package (id, name, areas, policy, multi); modules add
		// types of tickets they ship via `wpcap_offline_live_tickets`.
		$types = array_values( (array) apply_filters( 'wpcap_offline_package_types', $types, $occurrence ) );
		$areas = array_map( static function ( $a ) { return array( 'id' => (int) $a->area_id, 'name' => (string) $a->name ); }, Areas::for_event( $event_id ) );
		$area_id = \PassCaptain\Mobile\Devices::area_id( $device );
		$issued = time();
		$expires = $issued + $rules['max_offline_hours'] * HOUR_IN_SECONDS;
		$cursor = $state->epoch . '.' . (int) $state->version;
		$kid = Keys::active_id();
		$payload = array(
			'format' => self::FORMAT,
			'v' => self::VERSION,
			'kind' => $kind,
			'site' => home_url( '/' ),
			'device_id' => (string) $device->public_id,
			'key_id' => $kid,
			'event' => array( 'id' => $event_id, 'name' => (string) $occurrence->event_name ),
			'occurrence' => array( 'id' => (int) $occurrence->occurrence_id, 'starts_at' => gmdate( 'c', strtotime( $occurrence->starts_at . ' UTC' ) ), 'ends_at' => $occurrence->ends_at ? gmdate( 'c', strtotime( $occurrence->ends_at . ' UTC' ) ) : null, 'timezone' => (string) $occurrence->timezone, 'status' => (string) $occurrence->status ),
			'issued_at' => gmdate( 'c', $issued ),
			'expires_at' => gmdate( 'c', $expires ),
			'since' => 'delta' === $kind ? (string) $since : null,
			'cursor' => $cursor,
			'rules' => array( 'max_offline_hours' => $rules['max_offline_hours'], 'duplicate_policy' => $rules['duplicate_policy'], 'attendee_names' => $names ),
			'device_area_id' => $area_id ?: null,
			'hash' => 'sha256',
			'ticket_types' => $types,
			'areas' => $areas,
			'tickets' => $tickets,
		);
		$json = (string) wp_json_encode( $payload );
		$signed = Keys::sign( $json, $expires );
		if ( $signed['key_id'] !== $kid ) {
			// Key changed between reading the id and signing (concurrent rotation): sign again consistently.
			$payload['key_id'] = $signed['key_id'];
			$json = (string) wp_json_encode( $payload );
			$signed = Keys::sign( $json, $expires );
		}
		return array(
			'alg' => 'Ed25519',
			'key_id' => $signed['key_id'],
			'payload' => $json,
			'signature' => $signed['signature'],
			'kind' => $kind,
			'cursor' => $cursor,
			'count' => count( $tickets ),
			'expires_at' => gmdate( 'c', $expires ),
		);
	}

	/**
	 * Verifies a package response (server-side helper, used by tests and diagnostics).
	 *
	 * @return array|null Decoded payload when the signature is valid for a published key.
	 */
	public static function verify( array $response ) {
		$public = Keys::public_key( (string) ( $response['key_id'] ?? '' ) );
		$signature = base64_decode( (string) ( $response['signature'] ?? '' ), true );
		if ( ! $public || false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) { return null; }
		if ( ! sodium_crypto_sign_verify_detached( $signature, (string) ( $response['payload'] ?? '' ), $public ) ) { return null; }
		$payload = json_decode( (string) $response['payload'], true );
		return is_array( $payload ) && self::FORMAT === ( $payload['format'] ?? '' ) && ( $payload['key_id'] ?? '' ) === $response['key_id'] ? $payload : null;
	}
}
