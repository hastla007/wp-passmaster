<?php
/**
 * Optional signed QR format for tickets (setting `signed_qr`, default off).
 *
 * Classic QR codes carry the random 256-bit ticket code; the server stores only its SHA-256 hash.
 * The signed format lets scanners pre-validate a ticket offline without a package entry lookup by
 * code, and makes forged codes recognisable without a database:
 *
 *     PC1.<base64url(JSON claims)>.<base64url(Ed25519 signature)>
 *
 * Claims (no personal data): t = ticket public id, o = occurrence id, v = [not before, expires]
 * (Unix seconds), k = key id, c = code version (first 16 hex characters of the stored code hash).
 * The signature covers the exact ASCII bytes "PC1.<base64url(JSON)>" (domain separation by prefix).
 *
 * The code version binds the QR to the current ticket code: reissuing a ticket (new code, see
 * Orders/TicketsPage, print with codes) changes the hash and therefore invalidates every signed QR
 * of the previous code, exactly like the classic code. The server always checks the ticket state;
 * a valid signature alone never admits.
 *
 * Keys: option wpcap_qr_keys (not autoloaded) = [active => kid, keys => [kid => [public (base64),
 * secret (Vault ciphertext, context qr_signing_key; '' once retired), created_at, retired_at,
 * valid_until, max_expires]]]. Key id = first 16 hex of sha256(public key). Rotation keeps the
 * previous public key until the last QR it signed has expired (grace); rotation with `revoke`
 * removes it at once (compromise), so all QR codes signed with it are rejected.
 *
 * Scanners always accept classic codes as well, whether or not the setting is enabled, so turning the
 * format on or off never strands tickets that were already sent.
 */
namespace PassCaptain\Security;

use PassCaptain\Admission\Policies;
use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SignedQr {
	const PREFIX = 'PC1';
	const OPTION = 'wpcap_qr_keys';
	const VAULT_CONTEXT = 'qr_signing_key';
	/** Hex characters of the code hash used as code version. */
	const CODE_VERSION_LENGTH = 16;

	/** Whether newly rendered QR codes use the signed format. */
	public static function enabled() {
		return (bool) Settings::get( 'signed_qr' ) && function_exists( 'sodium_crypto_sign_detached' );
	}

	/** Cheap syntax check (no signature verification). */
	public static function is_signed( $value ) {
		return is_scalar( $value ) && 1 === preg_match( '/^PC1\.[A-Za-z0-9_-]{16,700}\.[A-Za-z0-9_-]{86}$/', trim( (string) $value ) );
	}

	public static function b64u_encode( $bytes ) {
		return rtrim( strtr( base64_encode( (string) $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary transport encoding.
	}

	/** @return string|false */
	public static function b64u_decode( $text ) {
		$text = strtr( (string) $text, '-_', '+/' );
		$pad = strlen( $text ) % 4;
		if ( 1 === $pad ) { return false; }
		return base64_decode( $text . ( $pad ? str_repeat( '=', 4 - $pad ) : '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary transport encoding.
	}

	/** Code version of a stored code hash (64 hex). */
	public static function code_version( $token_hash ) {
		return substr( strtolower( (string) $token_hash ), 0, self::CODE_VERSION_LENGTH );
	}

	/* ------------------------------------------------------------------ Key ring */

	private static function load() {
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		return array( 'active' => (string) ( $data['active'] ?? '' ), 'keys' => is_array( $data['keys'] ?? null ) ? $data['keys'] : array() );
	}

	private static function store( array $data ) {
		update_option( self::OPTION, $data, false );
	}

	private static function key_id( $public_key ) {
		return substr( hash( 'sha256', $public_key ), 0, 16 );
	}

	/** Secret key (64 bytes) of a key entry, or null when it cannot be opened (retired, Vault key changed). */
	private static function open_secret( array $entry ) {
		$stored = (string) ( $entry['secret'] ?? '' );
		if ( '' === $stored ) { return null; }
		$plain = Vault::decrypt( $stored, self::VAULT_CONTEXT );
		if ( null === $plain ) { return null; }
		$secret = base64_decode( $plain, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key encoding.
		return false !== $secret && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $secret ) ? $secret : null;
	}

	private static function generate() {
		$pair = sodium_crypto_sign_keypair();
		$public = sodium_crypto_sign_publickey( $pair );
		$secret = sodium_crypto_sign_secretkey( $pair );
		$entry = array(
			'public' => base64_encode( $public ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary key encoding.
			'secret' => Vault::encrypt( base64_encode( $secret ), self::VAULT_CONTEXT ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary key encoding.
			'created_at' => time(),
			'retired_at' => 0,
			'valid_until' => 0,
			'max_expires' => 0,
		);
		sodium_memzero( $secret );
		sodium_memzero( $pair );
		return array( self::key_id( $public ), $entry );
	}

	/** Active key id; creates the first key (or a new one when the secret cannot be opened any more). */
	public static function active_id() {
		$data = self::load();
		$kid = $data['active'];
		if ( '' !== $kid && isset( $data['keys'][ $kid ] ) ) {
			$secret = self::open_secret( $data['keys'][ $kid ] );
			if ( null !== $secret ) {
				sodium_memzero( $secret );
				return $kid;
			}
		}
		return self::rotate( false, '' === $kid ? 'initial' : 'unreadable' )['key_id'];
	}

	/**
	 * Rotates the signing key.
	 *
	 * @param bool   $revoke true: the previous key is dropped at once (QR codes signed with it are rejected);
	 *                       false: it stays valid until the last QR it signed expires.
	 * @return array{key_id: string, previous: string, previous_valid_until: int, revoked: bool}
	 */
	public static function rotate( $revoke = false, $reason = 'manual' ) {
		$data = self::load();
		$now = time();
		$previous = $data['active'];
		$until = 0;
		if ( '' !== $previous && isset( $data['keys'][ $previous ] ) ) {
			if ( $revoke ) {
				unset( $data['keys'][ $previous ] );
			} else {
				$until = max( $now, (int) $data['keys'][ $previous ]['max_expires'] );
				$data['keys'][ $previous ]['secret'] = '';
				$data['keys'][ $previous ]['retired_at'] = $now;
				$data['keys'][ $previous ]['valid_until'] = $until;
			}
		}
		list( $kid, $entry ) = self::generate();
		$data['keys'][ $kid ] = $entry;
		$data['active'] = $kid;
		foreach ( $data['keys'] as $id => $key ) {
			if ( $id !== $kid && (int) $key['valid_until'] <= $now ) { unset( $data['keys'][ $id ] ); }
		}
		self::store( $data );
		Audit::record( 'qr_key_rotated', 'settings', 0, array( 'key_id' => $kid, 'previous' => $previous, 'previous_valid_until' => $until ? gmdate( 'c', $until ) : null, 'revoked' => (bool) $revoke, 'reason' => sanitize_key( (string) $reason ) ) );
		return array( 'key_id' => $kid, 'previous' => $previous, 'previous_valid_until' => $until, 'revoked' => (bool) $revoke );
	}

	/**
	 * Public keys scanners trust: the active key and retired keys within their grace period.
	 *
	 * @return array[] key_id, alg, public_key (base64), status (active|retired), valid_until (ISO or null)
	 */
	public static function public_keys() {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) { return array(); }
		$active = self::active_id();
		$data = self::load();
		$now = time();
		$out = array();
		foreach ( $data['keys'] as $kid => $key ) {
			if ( $kid !== $active && (int) $key['valid_until'] <= $now ) { continue; }
			$out[] = array(
				'key_id' => (string) $kid,
				'alg' => 'Ed25519',
				'public_key' => (string) $key['public'],
				'status' => $kid === $active ? 'active' : 'retired',
				'valid_until' => $kid === $active ? null : gmdate( 'c', (int) $key['valid_until'] ),
			);
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['status'], $b['status'] ); } );
		return $out;
	}

	/** Raw public key (32 bytes) of a trusted key id, or null. */
	private static function public_key( $kid ) {
		$data = self::load();
		$key = $data['keys'][ (string) $kid ] ?? null;
		if ( ! is_array( $key ) ) { return null; }
		if ( (string) $kid !== $data['active'] && (int) $key['valid_until'] <= time() ) { return null; }
		$raw = base64_decode( (string) $key['public'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key encoding.
		return false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw ) ? $raw : null;
	}

	/* ------------------------------------------------------------------ Signing */

	/**
	 * Signs claims with the active key (k is set here).
	 *
	 * @param array $claims t, o, v, c.
	 */
	public static function sign( array $claims ) {
		$kid = self::active_id();
		$data = self::load();
		$secret = self::open_secret( $data['keys'][ $kid ] );
		if ( null === $secret ) { return ''; }
		$claims = array(
			't' => (string) $claims['t'],
			'o' => (int) $claims['o'],
			'v' => array( (int) $claims['v'][0], (int) $claims['v'][1] ),
			'k' => $kid,
			'c' => (string) $claims['c'],
		);
		$body = self::PREFIX . '.' . self::b64u_encode( (string) wp_json_encode( $claims ) );
		$signature = sodium_crypto_sign_detached( $body, $secret );
		sodium_memzero( $secret );
		if ( $claims['v'][1] > (int) $data['keys'][ $kid ]['max_expires'] + 60 ) {
			$data['keys'][ $kid ]['max_expires'] = $claims['v'][1];
			self::store( $data );
		}
		return $body . '.' . self::b64u_encode( $signature );
	}

	/**
	 * Validity window [not before, expires] of a ticket row (starts_at, ends_at, timezone, ticket_type_id,
	 * occurrence_id): from a year before the start until a day after the last valid time (filter
	 * wpcap_signed_qr_window).
	 */
	public static function window( $row ) {
		$found = array( 'occurrence_id' => (int) $row->occurrence_id, 'starts_at' => (string) $row->starts_at, 'ends_at' => $row->ends_at, 'timezone' => (string) $row->timezone );
		$start = (int) strtotime( $row->starts_at . ' UTC' );
		// Same plausibility range as Checkin::lookup() (up to a year ahead, a day after the last valid time);
		// entry windows and day rules are decided by the admission engine on the server.
		$window = array( $start - 365 * DAY_IN_SECONDS, Policies::last_valid_ts( (int) $row->ticket_type_id, $found ) + DAY_IN_SECONDS );
		$window = (array) apply_filters( 'wpcap_signed_qr_window', $window, $row );
		return array( (int) ( $window[0] ?? 0 ), max( (int) ( $window[0] ?? 0 ), (int) ( $window[1] ?? 0 ) ) );
	}

	private static function ticket_row( $where, $value ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$sql = "SELECT t.ticket_id,t.public_id,t.token_hash,t.ticket_type_id,tt.occurrence_id,o.starts_at,o.ends_at,o.timezone FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE {$where} LIMIT 1";
		return $wpdb->get_row( $wpdb->prepare( $sql, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prefix and $where are literals from this class.
	}

	/** Signed QR value of a ticket id ('' when the ticket has no code). Independent of the setting. */
	public static function for_ticket( $ticket_id ) {
		$row = self::ticket_row( 't.ticket_id=%d', absint( $ticket_id ) );
		return $row ? self::for_row( $row ) : '';
	}

	private static function for_row( $row ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', (string) $row->token_hash ) || '' === (string) $row->public_id ) { return ''; }
		return self::sign( array( 't' => (string) $row->public_id, 'o' => (int) $row->occurrence_id, 'v' => self::window( $row ), 'c' => self::code_version( $row->token_hash ) ) );
	}

	/**
	 * What a ticket's QR code carries: the signed payload when the setting is on, otherwise (or when
	 * signing is impossible) the classic ticket code itself.
	 */
	public static function qr_value( $token ) {
		$token = (string) $token;
		if ( '' === $token || ! self::enabled() || ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) { return $token; }
		$row = self::ticket_row( 't.token_hash=%s', hash( 'sha256', strtolower( $token ) ) );
		$signed = $row ? self::for_row( $row ) : '';
		return '' !== $signed ? $signed : $token;
	}

	/* ------------------------------------------------------------------ Verification */

	private static function invalid() {
		return new \WP_Error( 'wpcap_qr_invalid', __( 'Der QR-Code ist ungültig oder nicht von diesem System signiert.', 'pass-captain' ), array( 'status' => 404, 'result' => 'invalid_signature' ) );
	}

	/**
	 * Verifies signature, format and key of a signed QR (not the validity window, not the ticket).
	 *
	 * @return array|\WP_Error Claims t, o, v [nbf, exp], k, c.
	 */
	public static function verify( $code ) {
		$code = trim( (string) $code );
		if ( ! self::is_signed( $code ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) { return self::invalid(); }
		list( , $body, $sig ) = explode( '.', $code, 3 );
		$json = self::b64u_decode( $body );
		$signature = self::b64u_decode( $sig );
		$claims = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $claims ) || false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) { return self::invalid(); }
		$public = self::public_key( (string) ( $claims['k'] ?? '' ) );
		if ( null === $public || ! sodium_crypto_sign_verify_detached( $signature, self::PREFIX . '.' . $body, $public ) ) { return self::invalid(); }
		$valid = is_string( $claims['t'] ?? null ) && preg_match( '/^[A-Za-z0-9-]{8,64}$/', $claims['t'] )
			&& is_int( $claims['o'] ?? null ) && $claims['o'] > 0
			&& is_array( $claims['v'] ?? null ) && 2 === count( $claims['v'] ) && is_int( $claims['v'][0] ?? null ) && is_int( $claims['v'][1] ?? null )
			&& is_string( $claims['c'] ?? null ) && preg_match( '/^[a-f0-9]{' . self::CODE_VERSION_LENGTH . '}$/', $claims['c'] );
		return $valid ? $claims : self::invalid();
	}

	/** True when the evaluation time lies inside the signed validity window. */
	public static function in_window( array $claims, $at ) {
		return (int) $at >= (int) $claims['v'][0] && (int) $at <= (int) $claims['v'][1];
	}

	/** True when the claims belong to the ticket's current code (code version and occurrence). */
	public static function matches( array $claims, $token_hash, $occurrence_id ) {
		return hash_equals( self::code_version( $token_hash ), (string) $claims['c'] ) && (int) $claims['o'] === (int) $occurrence_id;
	}
}
