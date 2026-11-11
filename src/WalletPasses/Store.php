<?php
/**
 * Persistence for wallet passes: the core table wpcap_wallet_passes (one row per provider and pass),
 * the module table wpcap_wallet_state (one row per ticket: encrypted ticket code + Apple auth token,
 * fingerprint, update tag) and wpcap_wallet_registrations (Apple devices).
 *
 * The plaintext ticket code is only known at issuance (and when a code is rotated). It is stored
 * encrypted with sodium_crypto_secretbox so passes can be rebuilt later; see docs/WALLET.md.
 */
namespace PassCaptain\WalletPasses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Store {
	/** 32 byte secretbox key from WPCAP_WALLET_ENCRYPTION_KEY or the WordPress auth salt. */
	public static function key() {
		$base = defined( 'WPCAP_WALLET_ENCRYPTION_KEY' ) ? (string) constant( 'WPCAP_WALLET_ENCRYPTION_KEY' ) : '';
		if ( '' === $base && function_exists( 'wp_salt' ) ) { $base = (string) wp_salt( 'auth' ); }
		if ( '' === $base ) { throw new \RuntimeException( 'wallet_key_missing' ); }
		return hash_hmac( 'sha256', 'wpcap-wallet-secretbox-v1', $base, true );
	}

	public static function encrypt( array $data ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) wp_json_encode( $data ), $nonce, self::key() ) );
	}

	/** @return array|null Null when the box cannot be opened (e.g. key/salt changed). */
	public static function decrypt( $box ) {
		if ( 0 !== strpos( (string) $box, 'v1:' ) ) { return null; }
		$raw = base64_decode( substr( $box, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return null; }
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Throwable $e ) { return null; }
		$data = false === $plain ? null : json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	private static function t( $name ) { global $wpdb; return $wpdb->prefix . 'wpcap_' . $name; }

	/**
	 * Monotonic update tag (milliseconds) used for passesUpdatedSince, Last-Modified and ETag.
	 * Every new tag lies in a later whole second than the previous one, so a second-resolution
	 * If-Modified-Since from a fetch can never hide an update made in the same second.
	 */
	public static function next_tag( $previous = 0 ) {
		$previous = (int) $previous;
		$floor = $previous > 0 ? ( intdiv( $previous, 1000 ) + 1 ) * 1000 : 0;
		return max( $floor, (int) floor( microtime( true ) * 1000 ) );
	}

	public static function state( $ticket_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'wallet_state' ) . ' WHERE ticket_id=%d', absint( $ticket_id ) ) );
	}

	/**
	 * Stores (or replaces) the ticket code. A new code starts a new generation with a new Apple
	 * authentication token: devices holding the previous generation only ever receive a voided pass.
	 *
	 * @return array{auth_token:string,generation:int}
	 */
	public static function put_code( $ticket_id, $code ) {
		global $wpdb;
		$existing = self::state( $ticket_id );
		$auth = bin2hex( random_bytes( 20 ) );
		$generation = $existing ? (int) $existing->generation + 1 : 1;
		$now = current_time( 'mysql', true );
		$row = array(
			'secret' => self::encrypt( array( 'code' => (string) $code, 'auth' => $auth ) ),
			'code_hash' => hash( 'sha256', (string) $code ),
			'auth_token_hash' => hash( 'sha256', $auth ),
			'generation' => $generation,
			'fingerprint' => '',
			'update_tag' => self::next_tag( $existing ? $existing->update_tag : 0 ),
			'updated_at' => $now,
		);
		if ( $existing ) {
			$wpdb->update( self::t( 'wallet_state' ), $row, array( 'ticket_id' => absint( $ticket_id ) ) );
		} else {
			$wpdb->insert( self::t( 'wallet_state' ), array( 'ticket_id' => absint( $ticket_id ), 'created_at' => $now ) + $row );
		}
		return array( 'auth_token' => $auth, 'generation' => $generation );
	}

	/** Decrypted secrets of a ticket: ['code' => …, 'auth' => …] or null. */
	public static function secrets( $state ) { return $state ? self::decrypt( $state->secret ) : null; }

	public static function touch( $ticket_id, $fingerprint, $previous_tag ) {
		global $wpdb;
		$tag = self::next_tag( $previous_tag );
		$wpdb->update( self::t( 'wallet_state' ), array( 'fingerprint' => $fingerprint, 'update_tag' => $tag, 'updated_at' => current_time( 'mysql', true ) ), array( 'ticket_id' => absint( $ticket_id ) ) );
		return $tag;
	}

	/* ---- wpcap_wallet_passes (core table) ---- */

	public static function passes( $ticket_id, $provider = '' ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::t( 'wallet_passes' ) . ' WHERE ticket_id=%d' . ( $provider ? ' AND provider=%s' : '' ) . ' ORDER BY wallet_pass_id';
		return $provider ? $wpdb->get_results( $wpdb->prepare( $sql, absint( $ticket_id ), $provider ) ) : $wpdb->get_results( $wpdb->prepare( $sql, absint( $ticket_id ) ) );
	}

	public static function pass_by_serial( $provider, $serial ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'wallet_passes' ) . ' WHERE provider=%s AND serial_number=%s', $provider, (string) $serial ) );
	}

	/** Idempotent insert/update by serial number. */
	public static function save_pass( $ticket_id, $provider, $serial, $ref, $status = 'active' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$existing = self::pass_by_serial( $provider, $serial );
		if ( $existing ) {
			$wpdb->update( self::t( 'wallet_passes' ), array( 'provider_ref' => $ref, 'status' => $status, 'updated_at' => $now ), array( 'wallet_pass_id' => (int) $existing->wallet_pass_id ) );
			return (int) $existing->wallet_pass_id;
		}
		$wpdb->insert( self::t( 'wallet_passes' ), array( 'ticket_id' => absint( $ticket_id ), 'provider' => $provider, 'provider_ref' => $ref, 'serial_number' => $serial, 'status' => $status, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	public static function set_pass_status( $wallet_pass_id, $status ) {
		global $wpdb;
		$wpdb->update( self::t( 'wallet_passes' ), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'wallet_pass_id' => (int) $wallet_pass_id ) );
	}

	/* ---- Apple device registrations ---- */

	public static function register_device( $device, $pass_type, $serial, $push_token, $auth_hash ) {
		global $wpdb;
		$table = self::t( 'wallet_registrations' );
		$now = current_time( 'mysql', true );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT registration_id FROM $table WHERE device_library_id=%s AND serial_number=%s", $device, $serial ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET push_token=%s,updated_at=%s WHERE device_library_id=%s", $push_token, $now, $device ) );
		if ( $exists ) {
			$wpdb->update( $table, array( 'auth_token_hash' => $auth_hash, 'pass_type_id' => $pass_type, 'updated_at' => $now ), array( 'registration_id' => (int) $exists ) );
			return false;
		}
		$wpdb->insert( $table, array( 'device_library_id' => $device, 'pass_type_id' => $pass_type, 'serial_number' => $serial, 'push_token' => $push_token, 'auth_token_hash' => $auth_hash, 'created_at' => $now, 'updated_at' => $now ) );
		return true;
	}

	public static function unregister_device( $device, $serial ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'wallet_registrations' ) . ' WHERE device_library_id=%s AND serial_number=%s', $device, $serial ) );
	}

	public static function delete_push_token( $push_token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'wallet_registrations' ) . ' WHERE push_token=%s', $push_token ) );
	}

	public static function registrations_for_serial( $serial ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'wallet_registrations' ) . ' WHERE serial_number=%s', $serial ) );
	}

	public static function registration_by_auth( $serial, $auth_hash ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'wallet_registrations' ) . ' WHERE serial_number=%s AND auth_token_hash=%s LIMIT 1', $serial, $auth_hash ) );
	}

	/** Serial numbers registered on a device that changed after $since (update tag). */
	public static function updated_serials( $device, $pass_type, $since ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT r.serial_number,s.update_tag FROM ' . self::t( 'wallet_registrations' ) . ' r INNER JOIN ' . self::t( 'wallet_passes' ) . " p ON p.provider='apple' AND p.serial_number=r.serial_number INNER JOIN " . self::t( 'wallet_state' ) . ' s ON s.ticket_id=p.ticket_id WHERE r.device_library_id=%s AND r.pass_type_id=%s AND s.update_tag>%d ORDER BY s.update_tag',
			$device, $pass_type, (int) $since
		) );
	}

	public static function has_registrations_for_device( $device, $pass_type ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'wallet_registrations' ) . ' WHERE device_library_id=%s AND pass_type_id=%s LIMIT 1', $device, $pass_type ) );
	}
}
