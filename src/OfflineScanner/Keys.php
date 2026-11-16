<?php
/**
 * Ed25519 signing keys for offline packages (libsodium).
 *
 * Stored in the option wpcap_offline_keys (not autoloaded):
 *   active => key id, keys => [ kid => [public, secret (secretbox, '' once retired), created_at,
 *   retired_at, valid_until, max_expires] ]
 * The secret key is encrypted with a key derived from WPCAP_OFFLINE_SIGNING_KEY (constant) or the
 * WordPress auth salt and never leaves this class. Key id = first 16 hex chars of sha256(public).
 *
 * Rotation creates a new active key; the previous secret is wiped immediately, its public key stays
 * published until the last package it signed has expired (grace), then it is pruned.
 *
 * Concurrency (A629): every change of the key option (first key, rotation, extended grace) runs under
 * the DbLock lock `offline_keys` (unique per installation) and re-reads the option inside it, so a
 * rotation is never undone by a stale copy. Signing only reads the key option; the latest expiry
 * signed per key is kept in its own option (EXPIRY_OPTION) and raised under the same lock. A package
 * signed with a key that was retired meanwhile extends that key's grace instead of being lost.
 */
namespace PassCaptain\OfflineScanner;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Keys {
	const OPTION = 'wpcap_offline_keys';

	/** Latest package expiry signed per key id (kid => unix time), separate from the keys (A629). */
	const EXPIRY_OPTION = 'wpcap_offline_keys_expiry';

	/** Seconds to wait for the key lock before giving up (exception `offline_key_busy`). */
	const LOCK_WAIT = 10;

	private static function box_key() {
		$base = defined( 'WPCAP_OFFLINE_SIGNING_KEY' ) ? (string) constant( 'WPCAP_OFFLINE_SIGNING_KEY' ) : '';
		if ( '' === $base && function_exists( 'wp_salt' ) ) { $base = (string) wp_salt( 'auth' ); }
		if ( '' === $base ) { throw new \RuntimeException( 'offline_key_missing' ); }
		return hash_hmac( 'sha256', 'wpcap-offline-signing-secretbox-v1', $base, true );
	}

	private static function seal( $secret ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::box_key() ) );
	}

	private static function open( $box ) {
		if ( 0 !== strpos( (string) $box, 'v1:' ) ) { return null; }
		$raw = base64_decode( substr( $box, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return null; }
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::box_key() );
		} catch ( \Throwable $e ) { return null; }
		return false === $plain || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $plain ) ? null : $plain;
	}

	/**
	 * Key data; $fresh drops the cached copy first (inside the lock: what other processes stored).
	 */
	private static function load( $fresh = false ) {
		if ( $fresh && function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); }
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		return array( 'active' => (string) ( $data['active'] ?? '' ), 'keys' => is_array( $data['keys'] ?? null ) ? $data['keys'] : array() );
	}

	private static function store( array $data ) {
		update_option( self::OPTION, $data, false );
	}

	/** Latest signed expiry per key id (A629). */
	private static function expiries( $fresh = false ) {
		if ( $fresh && function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::EXPIRY_OPTION, 'options' ); }
		$data = get_option( self::EXPIRY_OPTION, array() );
		return is_array( $data ) ? array_map( 'intval', $data ) : array();
	}

	/**
	 * Runs $fn under the database lock of the key option (A629). Throws `offline_key_busy` when the
	 * lock cannot be had within LOCK_WAIT seconds – nothing is changed then.
	 */
	private static function locked( callable $fn ) {
		if ( ! \PassCaptain\DbLock::acquire( 'offline_keys', self::LOCK_WAIT ) ) {
			throw new \RuntimeException( 'offline_key_busy' );
		}
		try {
			return $fn();
		} finally {
			\PassCaptain\DbLock::release( 'offline_keys' );
		}
	}

	public static function key_id( $public ) {
		return substr( hash( 'sha256', $public ), 0, 16 );
	}

	/** Creates a new key pair entry (not yet active). */
	private static function generate() {
		$pair = sodium_crypto_sign_keypair();
		$public = sodium_crypto_sign_publickey( $pair );
		$secret = sodium_crypto_sign_secretkey( $pair );
		$entry = array( 'public' => base64_encode( $public ), 'secret' => self::seal( $secret ), 'created_at' => time(), 'retired_at' => 0, 'valid_until' => 0, 'max_expires' => 0 );
		sodium_memzero( $secret );
		return array( self::key_id( $public ), $entry );
	}

	/**
	 * Active key id; generates the first key (or a new one if the secret cannot be opened anymore,
	 * e.g. after a salt change – the old public key then stays valid until its packages expire).
	 */
	public static function active_id() {
		$data = self::load();
		if ( self::usable( $data ) ) { return $data['active']; }
		// A629: under the lock, and only if no other process created a usable key meanwhile.
		return self::locked( static function () {
			$data = self::load( true );
			if ( self::usable( $data ) ) { return $data['active']; }
			return self::rotate_unlocked( '' === $data['active'] ? 'initial' : 'unreadable', $data )['key_id'];
		} );
	}

	/** Whether the active key of $data exists and its secret can be opened. */
	private static function usable( array $data ) {
		$kid = $data['active'];
		return '' !== $kid && isset( $data['keys'][ $kid ] ) && null !== self::open( $data['keys'][ $kid ]['secret'] );
	}

	/**
	 * Signs a message with the active key.
	 *
	 * @param string $message    Exact bytes to sign.
	 * @param int    $expires_at Expiry of the signed package (extends the key's grace on rotation).
	 * @return array{key_id: string, signature: string} Signature base64.
	 */
	public static function sign( $message, $expires_at = 0 ) {
		// One consistent read: key id and secret come from the same copy (A629).
		$data = self::load();
		if ( ! self::usable( $data ) ) {
			self::active_id();
			$data = self::load( true );
		}
		$kid = $data['active'];
		$secret = self::open( $data['keys'][ $kid ]['secret'] ?? '' );
		if ( null === $secret ) { throw new \RuntimeException( 'offline_key_missing' ); }
		$signature = sodium_crypto_sign_detached( (string) $message, $secret );
		sodium_memzero( $secret );
		$known = max( (int) ( $data['keys'][ $kid ]['max_expires'] ?? 0 ), (int) ( self::expiries()[ $kid ] ?? 0 ) );
		if ( $expires_at > $known + 60 ) { self::record_expiry( $kid, (int) $expires_at ); }
		return array( 'key_id' => $kid, 'signature' => base64_encode( $signature ) );
	}

	/**
	 * Raises the latest signed expiry of a key (A629, under the lock, fresh read). The key option is
	 * only written when the key was retired meanwhile: its grace then covers this package, too.
	 */
	private static function record_expiry( $kid, $expires_at ) {
		self::locked( static function () use ( $kid, $expires_at ) {
			$expiries = self::expiries( true );
			if ( $expires_at > (int) ( $expiries[ $kid ] ?? 0 ) ) {
				$expiries[ $kid ] = $expires_at;
				update_option( self::EXPIRY_OPTION, $expiries, false );
			}
			$data = self::load( true );
			if ( isset( $data['keys'][ $kid ] ) && $kid !== $data['active'] && (int) $data['keys'][ $kid ]['valid_until'] < $expires_at ) {
				$data['keys'][ $kid ]['valid_until'] = $expires_at;
				self::store( $data );
			}
		} );
	}

	/**
	 * Rotates the signing key. The previous key's secret is wiped; its public key stays valid until
	 * the latest package it signed expires (at least the longest configured offline duration).
	 *
	 * @return array{key_id: string, previous: string, previous_valid_until: int}
	 */
	public static function rotate( $reason = 'manual' ) {
		return self::locked( static function () use ( $reason ) {
			return self::rotate_unlocked( $reason, self::load( true ) );
		} );
	}

	/** rotate() inside the lock, on data read inside the lock. */
	private static function rotate_unlocked( $reason, array $data ) {
		$now = time();
		$previous = $data['active'];
		$until = 0;
		$expiries = self::expiries( true );
		if ( '' !== $previous && isset( $data['keys'][ $previous ] ) ) {
			$old = &$data['keys'][ $previous ];
			$until = max( $now, (int) $old['max_expires'], (int) ( $expiries[ $previous ] ?? 0 ) );
			// Packages issued before max_expires was tracked (or in the 60 s tolerance) are covered by the longest configured duration.
			$until = max( $until, $now + Rules::max_hours_enabled() * HOUR_IN_SECONDS );
			$old['secret'] = '';
			$old['retired_at'] = $now;
			$old['valid_until'] = $until;
			unset( $old );
		}
		list( $kid, $entry ) = self::generate();
		$data['keys'][ $kid ] = $entry;
		$data['active'] = $kid;
		$data['keys'] = self::prune( $data['keys'], $kid, $now );
		self::store( $data );
		$kept = array_intersect_key( $expiries, $data['keys'] );
		if ( $kept !== $expiries ) { update_option( self::EXPIRY_OPTION, $kept, false ); }
		Audit::record( 'offline_key_rotated', 'settings', 0, array( 'key_id' => $kid, 'previous' => $previous, 'previous_valid_until' => $until ? gmdate( 'c', $until ) : null, 'reason' => sanitize_key( $reason ) ) );
		return array( 'key_id' => $kid, 'previous' => $previous, 'previous_valid_until' => $until );
	}

	private static function prune( array $keys, $active, $now ) {
		foreach ( $keys as $kid => $key ) {
			if ( $kid !== $active && (int) $key['valid_until'] <= $now ) { unset( $keys[ $kid ] ); }
		}
		return $keys;
	}

	/**
	 * Public keys devices should trust: the active one and retired ones within their grace period.
	 *
	 * @return array[] key_id, alg, public_key (base64), status (active|retired), created_at, valid_until (ISO or null)
	 */
	public static function public_keys() {
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
				'created_at' => gmdate( 'c', (int) $key['created_at'] ),
				'valid_until' => $kid === $active ? null : gmdate( 'c', (int) $key['valid_until'] ),
			);
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['status'], $b['status'] ); } );
		return $out;
	}

	/** Raw public key (32 bytes) of a published key id, or null. */
	public static function public_key( $kid ) {
		foreach ( self::public_keys() as $key ) {
			if ( $key['key_id'] === $kid ) { return base64_decode( $key['public_key'] ); }
		}
		return null;
	}
}
