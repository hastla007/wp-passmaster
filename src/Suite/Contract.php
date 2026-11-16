<?php
/**
 * Common machinery of the versioned Captain-Suite contracts (A667, A673): event allowlist with typed
 * objects, envelope, provider lookup by filter and major version, machine-readable description. A
 * concrete contract (Workflow\Contract, Review\Contract) only declares its constants:
 *
 * NAME, VERSION (SemVer), FILTER (provider filter, called with null and the major version),
 * CAPABILITY, DEPRECATION_MINOR_RELEASES, DEPRECATION_MONTHS, DEPRECATED, ERRORS, OBJECTS, EVENTS
 * (type => [schema version, subject object, extra fields]) and FORBIDDEN (keys never sent).
 *
 * The core never checks licences or paid-feature rights: whether a provider feature is paid is the provider's
 * own business. Without a (compatible) provider every core flow runs natively.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Suite;

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Contract {
	/** Keys that never appear anywhere in an envelope, whatever a filter or a future field list says. */
	const FORBIDDEN = array( 'token', 'code', 'ticket_code', 'help_code', 'access_token', 'secret', 'email', 'customer_email', 'attendee_email', 'attendee_name', 'name', 'first_name', 'last_name', 'phone', 'address', 'billing', 'answers', 'ip', 'ip_hash' );

	/** Error code of this contract: wpcap_{NAME}_{suffix}. */
	public static function code( $suffix ) {
		return 'wpcap_' . static::NAME . '_' . $suffix;
	}

	/** Option that keeps the last provider state of this contract (diagnostics, admin status). */
	public static function status_option() {
		return 'wpcap_' . static::NAME . '_status';
	}

	public static function known( $type ) {
		return isset( static::EVENTS[ (string) $type ] );
	}

	/** Schema version of an event type ('' for unknown types). */
	public static function schema_version( $type ) {
		return static::EVENTS[ (string) $type ][0] ?? '';
	}

	/** Subject object of an event type. */
	public static function subject( $type ) {
		return static::EVENTS[ (string) $type ][1] ?? '';
	}

	/** Allowed data fields of an event type: field => type. */
	public static function fields( $type ) {
		if ( ! static::known( $type ) ) { return array(); }
		return static::OBJECTS[ static::subject( $type ) ] + static::EVENTS[ $type ][2];
	}

	/**
	 * Data reduced to the allowlist of the event type, with the documented types. Fields outside the
	 * allowlist (and every FORBIDDEN key) are dropped and named in $dropped.
	 *
	 * @param string[] $dropped Out: dropped field paths.
	 */
	public static function sanitize( $type, array $data, &$dropped = array() ) {
		$dropped = array();
		return static::clean( static::fields( $type ), $data, '', $dropped );
	}

	protected static function clean( array $spec, array $data, $path, array &$dropped ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $spec[ $key ] ) || in_array( $key, static::FORBIDDEN, true ) ) { $dropped[] = $path . $key; continue; }
			$out[ $key ] = static::cast( $spec[ $key ], $value, $path . $key . '.', $dropped );
		}
		return $out;
	}

	protected static function cast( $type, $value, $path, array &$dropped ) {
		if ( '?' === $type[0] ) { return null === $value ? null : static::cast( substr( $type, 1 ), $value, $path, $dropped ); }
		if ( '@' === $type[0] ) {
			$list = '[]' === substr( $type, -2 );
			$spec = static::OBJECTS[ trim( $type, '@[]' ) ] ?? array();
			if ( ! $list ) { return static::clean( $spec, is_array( $value ) ? $value : array(), $path, $dropped ); }
			$items = array();
			foreach ( is_array( $value ) ? array_values( $value ) : array() as $item ) { $items[] = static::clean( $spec, is_array( $item ) ? $item : array(), $path, $dropped ); }
			return $items;
		}
		switch ( $type ) {
			case 'int': return (int) $value;
			case 'bool': return (bool) $value;
			case 'int[]': return array_values( array_map( 'intval', is_array( $value ) ? $value : array() ) );
			case 'string[]': return array_values( array_map( 'strval', array_filter( is_array( $value ) ? $value : array(), 'is_scalar' ) ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The envelope of an event (without sequence/attempt/replay, which the outbox adds per delivery).
	 *
	 * @param string $subject_id Stable id of the subject (public id or numeric id as string).
	 */
	public static function envelope( $event_id, $type, $subject_id, array $data, $occurred_at ) {
		return array(
			'id' => (string) $event_id,
			'type' => (string) $type,
			'contract' => static::NAME,
			'contract_version' => static::VERSION,
			'schema_version' => static::schema_version( $type ),
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $occurred_at ),
			'source' => array(
				'plugin' => 'pass-captain',
				'version' => defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : '',
				'site_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			),
			'subject' => array( 'type' => static::subject( $type ), 'id' => (string) $subject_id ),
			'data' => $data,
		);
	}

	/**
	 * The registered provider if it speaks this major version, else null; $status says why.
	 *
	 * @param array $status Out: ['state' => missing|incompatible|ready, 'version' => string, 'code' => error code].
	 */
	public static function provider( &$status = array() ) {
		$provider = apply_filters( static::FILTER, null, (int) \PassCaptain\Contracts::major( static::VERSION ) );
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'receive' ) ) {
			$status = array( 'state' => 'missing', 'version' => '', 'code' => static::code( 'provider_missing' ) );
			return null;
		}
		try {
			$version = method_exists( $provider, 'contract_version' ) ? (string) $provider->contract_version() : static::VERSION;
		} catch ( \Throwable $e ) {
			$version = '';
		}
		if ( ! \PassCaptain\Contracts::compatible( $version, static::VERSION ) ) {
			$status = array( 'state' => 'incompatible', 'version' => sanitize_text_field( substr( $version, 0, 20 ) ), 'code' => static::code( 'contract_version' ) );
			return null;
		}
		$status = array( 'state' => 'ready', 'version' => $version, 'code' => '' );
		return $provider;
	}

	/** Machine-readable description of the contract (for providers, docs and fixtures). */
	public static function describe() {
		$events = array();
		foreach ( static::EVENTS as $type => $def ) { $events[ $type ] = array( 'schema_version' => $def[0], 'subject' => $def[1], 'fields' => static::fields( $type ) ); }
		return array(
			'contract' => static::NAME,
			'version' => static::VERSION,
			'filter' => static::FILTER,
			'capability' => static::CAPABILITY,
			'events' => $events,
			'objects' => static::OBJECTS,
			'errors' => array_keys( static::ERRORS ),
			'deprecated' => static::DEPRECATED,
			'deprecation' => array( 'minor_releases' => static::DEPRECATION_MINOR_RELEASES, 'months' => static::DEPRECATION_MONTHS ),
		);
	}
}
