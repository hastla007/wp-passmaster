<?php
/**
 * Registry for explicitly enabled site-local integration adapters.
 *
 * Connectors register through the filter `wp_pass_captain_connectors_v1` and only run when their key
 * is listed in the site option `wpcap_enabled_connectors`. The core delivers its domain events to them
 * through the job queue (see ConnectorEvents); dispatch() performs one delivery.
 */
namespace PassCaptain\Integrations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConnectorRegistry {
	/** Site option with the keys of the enabled connectors (written only by this class). */
	const OPTION = 'wpcap_enabled_connectors';

	/** Lock purpose for the read-modify-write of OPTION. */
	const LOCK = 'connector_optin';

	/** Seconds store() waits for the lock held by another request. */
	const LOCK_WAIT = 3;

	/**
	 * Whether a connector key is registered through `wp_pass_captain_connectors_v1` (enabled or not).
	 *
	 * @param string $key Connector key.
	 */
	public static function is_registered( $key ) {
		$connectors = apply_filters( 'wp_pass_captain_connectors_v1', array() );
		foreach ( is_array( $connectors ) ? $connectors : array() as $connector ) {
			if ( ! $connector instanceof ConnectorInterface ) { continue; }
			try {
				if ( (string) $connector->key() === (string) $key ) { return true; }
			} catch ( \Throwable $error ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * Whether a connector is enabled (its key is in the opt-in list); says nothing about registration.
	 * Contract 1.4.0, wpcap_is_connector_enabled().
	 *
	 * @param string $key Connector key.
	 */
	public static function is_enabled( $key ) {
		$list = get_option( self::OPTION, array() );
		return is_scalar( $key ) && is_array( $list ) && in_array( (string) $key, array_map( 'strval', $list ), true );
	}

	/**
	 * Opts a connector in or out (contract 1.4.0, wpcap_set_connector_enabled()) instead of writing the
	 * option from outside. Enabling needs a registered connector; disabling also works for a key whose
	 * plugin is gone. Requires `manage_options` (or WP-CLI). Idempotent; a change is written to the audit
	 * log (`connector_enabled` / `connector_disabled`).
	 *
	 * @param string $key     Connector key.
	 * @param bool   $enabled True = opt in, false = opt out.
	 * @return true|\WP_Error
	 */
	public static function set_enabled( $key, $enabled ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key ) ) {
			return new \WP_Error( 'wpcap_connector_invalid', __( 'Ungültiger Connector-Schlüssel.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'manage_options' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return new \WP_Error( 'wpcap_connector_forbidden', __( 'Connectoren dürfen nur Administratoren ein- oder ausschalten.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$enabled = (bool) $enabled;
		if ( $enabled && ! self::is_registered( $key ) ) {
			return new \WP_Error( 'wpcap_connector_unknown', __( 'Dieser Connector ist nicht registriert und kann nicht eingeschaltet werden.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		if ( ! self::store( $key, $enabled ) ) {
			return new \WP_Error( 'wpcap_connector_busy', __( 'Die Connector-Freigabe wird gerade geändert. Bitte erneut versuchen.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Writes the opt-in of a key under a named lock (no lost update when two plugins switch at once);
	 * audits a change. Internal: callers check permissions themselves (set_enabled(), HTTP/JSON connector).
	 *
	 * @param string $key     Connector key.
	 * @param bool   $enabled Target state.
	 * @return bool False when the lock could not be taken.
	 */
	public static function store( $key, $enabled ) {
		$locked = \PassCaptain\DbLock::acquire( self::LOCK, self::LOCK_WAIT );
		if ( ! $locked ) { return false; }
		try {
			$list = get_option( self::OPTION, array() );
			$list = is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
			$was = in_array( (string) $key, $list, true );
			if ( $was === (bool) $enabled ) { return true; }
			$list = array_values( array_diff( $list, array( (string) $key ) ) );
			if ( $enabled ) { $list[] = (string) $key; }
			update_option( self::OPTION, $list );
			\PassCaptain\Audit::record( $enabled ? 'connector_enabled' : 'connector_disabled', 'connector', 0, array( 'connector' => (string) $key ) );
			return true;
		} finally {
			\PassCaptain\DbLock::release( self::LOCK );
		}
	}

	/**
	 * Delivers an event to the enabled connectors.
	 *
	 * @param string      $event_name      Event name (sanitize_key()).
	 * @param array       $payload         Event payload.
	 * @param string      $idempotency_key Event key; each connector receives sha256( key . '|' . connector key ).
	 * @param string|null $only            Deliver to this connector key only (job queue delivery).
	 * @return array Connector key => deliver() result; a connector that threw has no entry.
	 */
	public static function dispatch( $event_name, array $payload, $idempotency_key, $only = null ) {
		$enabled = get_option( 'wpcap_enabled_connectors', array() );
		$connectors = apply_filters( 'wp_pass_captain_connectors_v1', array() );
		if ( ! is_array( $enabled ) || ! is_array( $connectors ) ) { return array(); }
		$results = array();
		foreach ( $connectors as $connector ) {
			if ( ! $connector instanceof ConnectorInterface || ! in_array( $connector->key(), $enabled, true ) ) { continue; }
			if ( null !== $only && (string) $only !== (string) $connector->key() ) { continue; }
			try {
				$results[ sanitize_key( $connector->key() ) ] = $connector->deliver( sanitize_key( $event_name ), $payload, hash( 'sha256', $idempotency_key . '|' . $connector->key() ) );
			} catch ( \Throwable $error ) {
				do_action( 'wp_pass_captain_connector_error_v1', $connector->key(), $event_name, $error->getMessage() );
			}
		}
		return $results;
	}
}
