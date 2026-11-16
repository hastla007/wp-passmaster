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
 *
 * Formats (request parameter `format`, default 1 for existing clients):
 *  - v1: `tickets` = [{h: sha256 hex, s: status, t: ticket type, n?: attendee name}, ...].
 *  - v2 (compact, ~40 % of v1): `hash_prefix` = 32, `rows` = [[ticket type, status digit, the
 *    concatenated first 32 hex digits of every ticket hash of that type and status], ...] with status
 *    digits 0 revoked, 1 valid, 2 checked_in (revoked rows carry type 0); attendee names (opt-in per
 *    event) in `names` = {hash prefix: name}. 128 bit of the hash identify a ticket as safely as the
 *    full hash for this purpose (a forged code would need a 128-bit preimage).
 * Both formats carry per ticket type max_entries and valid_days (local entry counters of the PWA) and
 * the day cutoff hour of the occurrence.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Admission\Areas;
use PassCaptain\Admission\Engine;
use PassCaptain\Admission\Policies;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Package {
	const FORMAT = 'wpcap-offline-package';
	const VERSION = 1;
	/** Newest supported format version (compact rows); clients ask for it with format=2. */
	const VERSION_COMPACT = 2;
	/** Hex digits of the ticket hash shipped in the compact format. */
	const HASH_PREFIX = 32;
	/** Status digits of the compact format. */
	const STATUS_DIGITS = array( 'revoked' => 0, 'valid' => 1, 'checked_in' => 2 );

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
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (occurrence_id,epoch,version) VALUES (%d,%s,0)', $p . 'wpcap_offline_state', $occurrence_id, bin2hex( random_bytes( 8 ) ) ) );
		$wpdb->query( 'START TRANSACTION' );
		$state = self::state( $occurrence_id, true );
		$version = (int) $state->version + 1;
		$live = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.token_hash,t.status,t.ticket_type_id,ta.attendee_name,o.status AS occurrence_status,e.status AS event_status FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id LEFT JOIN %i ta ON ta.ticket_id=t.ticket_id WHERE tt.occurrence_id=%d AND t.token_hash<>''", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_events', $p . 'wpcap_ticket_assignments',
			$occurrence_id
		) );
		$known = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT token_hash,fingerprint,status FROM %i WHERE occurrence_id=%d', $p . 'wpcap_offline_entries', $occurrence_id ) ) as $row ) {
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
	 * @param int         $format     Payload format version (1 or 2, see class doc).
	 * @return array Response body: alg, key_id, payload (signed JSON string), signature, kind, cursor, expires_at.
	 */
	public static function build( $device, $occurrence, $since = null, $format = self::VERSION ) {
		$format = self::VERSION_COMPACT === (int) $format ? self::VERSION_COMPACT : self::VERSION;
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = (int) $occurrence->event_id;
		$rules = Rules::get( $event_id );
		$names = Rules::attendee_names( $rules );
		$state = self::refresh( (int) $occurrence->occurrence_id, $names );
		$from = null === $since || '' === $since ? null : self::parse_cursor( (string) $since, $state );
		$kind = null === $from ? 'full' : 'delta';
		if ( 'full' === $kind ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT token_hash,status,ticket_type_id,attendee_name FROM %i WHERE occurrence_id=%d AND status IN ('valid','checked_in') ORDER BY token_hash", $p . 'wpcap_offline_entries', (int) $occurrence->occurrence_id ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT token_hash,status,ticket_type_id,attendee_name FROM %i WHERE occurrence_id=%d AND version>%d ORDER BY token_hash', $p . 'wpcap_offline_entries', (int) $occurrence->occurrence_id, $from ) );
		}
		$tickets = array();
		$compact = array();
		$shipped_names = array();
		foreach ( $rows as $row ) {
			$revoked = 'revoked' === $row->status;
			$name = $names && ! $revoked ? (string) $row->attendee_name : '';
			if ( self::VERSION_COMPACT === $format ) {
				$prefix = substr( strtolower( (string) $row->token_hash ), 0, self::HASH_PREFIX );
				$group = ( $revoked ? 0 : (int) $row->ticket_type_id ) . ':' . ( self::STATUS_DIGITS[ $row->status ] ?? 0 );
				$compact[ $group ] = ( $compact[ $group ] ?? '' ) . $prefix;
				if ( '' !== $name ) { $shipped_names[ $prefix ] = $name; }
				continue;
			}
			$entry = array( 'h' => $row->token_hash, 's' => $row->status );
			if ( ! $revoked ) { $entry['t'] = (int) $row->ticket_type_id; }
			if ( '' !== $name ) { $entry['n'] = $name; }
			$tickets[] = $entry;
		}
		$count = self::VERSION_COMPACT === $format ? count( $rows ) : count( $tickets );
		$types = array();
		$found = array( 'occurrence_id' => (int) $occurrence->occurrence_id, 'event_id' => $event_id, 'starts_at' => (string) $occurrence->starts_at, 'ends_at' => $occurrence->ends_at ? (string) $occurrence->ends_at : '', 'timezone' => (string) $occurrence->timezone );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,name FROM %i WHERE occurrence_id=%d ORDER BY ticket_type_id', $p . 'wpcap_ticket_types', (int) $occurrence->occurrence_id ) ) as $type ) {
			$policy = Policies::for_type( (int) $type->ticket_type_id );
			$types[] = array(
				'id' => (int) $type->ticket_type_id,
				'name' => (string) $type->name,
				'areas' => Areas::type_area_ids( (int) $type->ticket_type_id ),
				'policy' => $policy ? $policy['policy'] : 'single',
				'multi' => $policy && 'single' !== $policy['policy'],
				// Local entry counters of the PWA (daily: once per valid day, multi_entry/reentry: max).
				'max_entries' => $policy ? (int) $policy['max_entries'] : 1,
				'valid_days' => $policy ? Policies::valid_days( $policy, $found ) : null,
			);
		}
		$areas = array_map( static function ( $a ) { return array( 'id' => (int) $a->area_id, 'name' => (string) $a->name ); }, Areas::for_event( $event_id ) );
		$area_id = \PassCaptain\Mobile\Devices::area_id( $device );
		$issued = time();
		$expires = $issued + $rules['max_offline_hours'] * HOUR_IN_SECONDS;
		$cursor = $state->epoch . '.' . (int) $state->version;
		$kid = Keys::active_id();
		$payload = array(
			'format' => self::FORMAT,
			'v' => $format,
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
			'day_cutoff_hour' => Engine::day_cutoff_hour( $found ),
			'hash' => 'sha256',
			'ticket_types' => $types,
			'areas' => $areas,
		);
		if ( self::VERSION_COMPACT === $format ) {
			$payload['hash_prefix'] = self::HASH_PREFIX;
			$payload['rows'] = array();
			foreach ( $compact as $group => $hashes ) {
				list( $type_id, $digit ) = array_map( 'intval', explode( ':', $group ) );
				$payload['rows'][] = array( $type_id, $digit, $hashes );
			}
			if ( $shipped_names ) { $payload['names'] = $shipped_names; }
		} else {
			$payload['tickets'] = $tickets;
		}
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
			'count' => $count,
			'format' => $format,
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
