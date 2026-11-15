<?php
/**
 * Registry of card-terminal device gateways (contract DeviceGatewayInterface, version 1).
 *
 * Filter `wp_pass_captain_pos_payment_devices_v1( array $devices, array $context )`: device id =>
 * DeviceGatewayInterface instance, or an array `label`, `start`, `poll`, `cancel` (callables with the
 * signatures of the interface) and optional `registers` (list of "location_id|register_id" keys the
 * device serves). `$context`: version, session_id, location_id, register_id. Invalid entries are
 * dropped; an exception in a filter callback means "no devices" (the POS then offers no terminal).
 * Devices are only offered for registers the terminal registry allows.
 */
namespace PassCaptain\BoxOffice;

use PassCaptain\Log;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Devices {
	/** Hook names (the calls below use the literals). */
	const FILTER = 'wp_pass_captain_pos_payment_devices_v1';
	const TIMEOUT_FILTER = 'wp_pass_captain_pos_device_timeout_v1';
	const DEFAULT_TIMEOUT = 180;

	/**
	 * Devices usable in a cash session (register context).
	 *
	 * @param object|null $session Session row (location_id, register_id, session_id) or null.
	 * @return array<string,DeviceGatewayInterface> Device id => gateway.
	 */
	public static function for_session( $session ) {
		$location_id = $session ? (int) $session->location_id : 0;
		$register_id = $session ? (string) $session->register_id : '';
		if ( ! TerminalRegistry::allows( $location_id, $register_id ) ) { return array(); }
		$context = array(
			'version' => DeviceGatewayInterface::VERSION,
			'session_id' => $session ? (int) $session->session_id : 0,
			'location_id' => $location_id,
			'register_id' => $register_id,
		);
		try {
			$raw = apply_filters( 'wp_pass_captain_pos_payment_devices_v1', array(), $context );
		} catch ( \Throwable $e ) {
			self::log( 'registry', $e->getMessage() );
			return array();
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $definition ) {
			$id = (string) $id;
			if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $id ) ) { continue; }
			$gateway = self::gateway( $definition, TerminalRegistry::key( $location_id, $register_id ) );
			if ( $gateway ) { $out[ $id ] = $gateway; }
		}
		return $out;
	}

	/** One device of a session, or null when it is not (or no longer) registered for it. */
	public static function find( $session, $device_id ) {
		$devices = self::for_session( $session );
		return $devices[ (string) $device_id ] ?? null;
	}

	/** Gateway from a filter entry (instance or callback array); null when invalid or not for this register. */
	private static function gateway( $definition, $register_key ) {
		if ( $definition instanceof DeviceGatewayInterface ) { return $definition; }
		if ( ! is_array( $definition ) ) { return null; }
		foreach ( array( 'start', 'poll', 'cancel' ) as $callback ) {
			if ( ! is_callable( $definition[ $callback ] ?? null ) ) { return null; }
		}
		if ( isset( $definition['registers'] ) && ! in_array( $register_key, array_map( 'strval', (array) $definition['registers'] ), true ) ) { return null; }
		return new CallbackDeviceGateway( (string) ( $definition['label'] ?? '' ), $definition['start'], $definition['poll'], $definition['cancel'] );
	}

	/** Seconds a terminal payment may stay pending before it is cancelled (30–840, below the 15-minute hold). */
	public static function timeout( $device_id ) {
		$seconds = (int) apply_filters( 'wp_pass_captain_pos_device_timeout_v1', self::DEFAULT_TIMEOUT, (string) $device_id );
		return max( 30, min( 840, $seconds ) );
	}

	/** Display name of a device. */
	public static function label( DeviceGatewayInterface $gateway, $device_id ) {
		try {
			$label = sanitize_text_field( (string) $gateway->label() );
		} catch ( \Throwable $e ) {
			$label = '';
		}
		return '' !== $label ? mb_substr( $label, 0, 80 ) : (string) $device_id;
	}

	/**
	 * Calls start() and normalizes the answer.
	 *
	 * @return array{reference:string,status:string,provider_reference:string,message:string}|\WP_Error
	 */
	public static function start( DeviceGatewayInterface $gateway, array $sale ) {
		$result = self::call( static function () use ( $gateway, $sale ) { return $gateway->start( $sale ); }, 'start' );
		if ( is_wp_error( $result ) ) { return $result; }
		$normalized = self::normalize( $result, array( 'pending', 'succeeded', 'failed', 'cancelled' ) );
		if ( '' === $normalized['reference'] ) {
			return new \WP_Error( 'wpcap_pos_device_invalid', __( 'Das Kartenterminal hat keine gültige Antwort geliefert.', 'pass-captain' ) );
		}
		return $normalized;
	}

	/**
	 * Calls poll() and normalizes the answer (status pending|succeeded|failed|cancelled).
	 *
	 * @return array{reference:string,status:string,provider_reference:string,message:string}|\WP_Error
	 */
	public static function poll( DeviceGatewayInterface $gateway, $reference ) {
		$result = self::call( static function () use ( $gateway, $reference ) { return $gateway->poll( (string) $reference ); }, 'poll' );
		return is_wp_error( $result ) ? $result : self::normalize( $result, array( 'pending', 'succeeded', 'failed', 'cancelled' ) );
	}

	/** Calls cancel(); true or WP_Error. */
	public static function cancel( DeviceGatewayInterface $gateway, $reference ) {
		$result = self::call( static function () use ( $gateway, $reference ) { return $gateway->cancel( (string) $reference ); }, 'cancel' );
		if ( is_wp_error( $result ) ) { return $result; }
		return false === $result ? new \WP_Error( 'wpcap_pos_device_cancel', __( 'Die Zahlung konnte am Terminal nicht abgebrochen werden.', 'pass-captain' ) ) : true;
	}

	/** Runs an add-on callback; exceptions become a WP_Error (logged without details of the payment). */
	private static function call( callable $callback, $what ) {
		try {
			return $callback();
		} catch ( \Throwable $e ) {
			self::log( $what, $e->getMessage() );
			return new \WP_Error( 'wpcap_pos_device_error', __( 'Das Kartenterminal ist nicht erreichbar.', 'pass-captain' ) );
		}
	}

	private static function normalize( $result, array $states ) {
		$result = is_array( $result ) ? $result : array();
		$status = is_string( $result['status'] ?? null ) ? strtolower( $result['status'] ) : '';
		$clean = static function ( $value, $max ) {
			return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
		};
		return array(
			'reference' => $clean( $result['reference'] ?? '', 191 ),
			// Unknown states count as pending: the timeout ends them.
			'status' => in_array( $status, $states, true ) ? $status : 'pending',
			'provider_reference' => $clean( $result['provider_reference'] ?? '', 191 ),
			'message' => $clean( $result['message'] ?? '', 255 ),
		);
	}

	private static function log( $what, $message ) {
		Log::warning( 'pos_device_' . $what . '_failed', 'Card terminal add-on: ' . mb_substr( sanitize_text_field( (string) $message ), 0, 200 ) );
	}
}
