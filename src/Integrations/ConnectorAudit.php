<?php
/**
 * Public audit entry for connectors (extension contract 1.4.0, PASS-E17-01; docs/EXTENSIONS.md
 * "Protokolleintrag eines Connectors"): a connector writes what it did into the core audit log through
 * wpcap_connector_audit() instead of calling the internal \PassCaptain\Audit::record().
 *
 * - Source: the key of a connector registered through `wp_pass_captain_connectors_v1` (enabled or not).
 *   It is stored as detail `source`; the action gets the prefix `connector_log_` (core actions such as `connector_created` never use it), so a connector can never write
 *   an entry that looks like one of the core.
 * - No personal data: the action is a code, the object one of OBJECT_TYPES with a numeric ID, and the details
 *   at most MAX_DETAILS flat values: keys are codes and must not name personal data (FORBIDDEN_KEY_PARTS),
 *   values are integers, floats, booleans, null or codes (letters, digits, `_ . : -`, at most 64 characters:
 *   no spaces, no `@`, so neither free text nor an e-mail address fits). Anything else is refused as a whole
 *   with a WP_Error; nothing is shortened or dropped silently.
 * - Rate-safe: at most RATE_LIMIT entries per connector and minute (RateLimit); more are refused with
 *   `wpcap_connector_audit_rate_limited`, so a looping connector cannot flood the log.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations;

use PassCaptain\Audit;
use PassCaptain\RateLimit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConnectorAudit {
	/** Prefix of every action written by a connector. */
	const ACTION_PREFIX = 'connector_log_';

	/** Object types a connector entry may refer to (`connector` = the connector itself, ID 0). */
	const OBJECT_TYPES = array( 'connector', 'order', 'ticket', 'occurrence', 'event', 'refund' );

	const MAX_DETAILS = 10;

	/** Entries per connector and minute. */
	const RATE_LIMIT = 60;

	/** Parts of a detail key (split at `_`) that stand for personal data or secrets. */
	const FORBIDDEN_KEY_PARTS = array( 'email', 'mail', 'name', 'firstname', 'lastname', 'phone', 'mobile', 'address', 'street', 'city', 'zip', 'postcode', 'birthday', 'birthdate', 'iban', 'ip', 'token', 'secret', 'password', 'code', 'source' );

	/**
	 * Writes an audit entry for a connector.
	 *
	 * @param string $source      Key of a registered connector.
	 * @param string $action      Action code (a-z, 0-9, `_`; 2–48 characters), stored as `connector_log_<action>`.
	 * @param string $object_type One of OBJECT_TYPES; '' = `connector`.
	 * @param int    $object_id   ID of the object (0 for `connector`).
	 * @param array  $details     Flat details (see class comment).
	 * @return true|\WP_Error
	 */
	public static function record( $source, $action, $object_type = '', $object_id = 0, $details = array() ) {
		$source = is_string( $source ) ? $source : '';
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/', $source ) || ! self::registered( $source ) ) {
			return self::error( 'wpcap_connector_audit_source', __( 'Unbekannter Connector: Nur ein registrierter Connector darf einen Protokolleintrag schreiben.', 'pass-captain' ), 'source' );
		}
		if ( ! is_string( $action ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{1,47}$/', $action ) ) {
			return self::error( 'wpcap_connector_audit_invalid', __( 'Die Aktion muss ein Code aus Kleinbuchstaben, Ziffern und Unterstrichen sein.', 'pass-captain' ), 'action' );
		}
		$object_type = '' === $object_type ? 'connector' : $object_type;
		if ( ! is_string( $object_type ) || ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			return self::error( 'wpcap_connector_audit_invalid', __( 'Unbekannter Objekttyp für den Protokolleintrag.', 'pass-captain' ), 'object_type' );
		}
		if ( ! ( is_int( $object_id ) || ( is_string( $object_id ) && 1 === preg_match( '/^[0-9]{1,19}$/', $object_id ) ) ) || (int) $object_id < 0 ) {
			return self::error( 'wpcap_connector_audit_invalid', __( 'Die Objekt-ID muss eine nicht negative ganze Zahl sein.', 'pass-captain' ), 'object_id' );
		}
		if ( ! is_array( $details ) || count( $details ) > self::MAX_DETAILS ) {
			return self::error( 'wpcap_connector_audit_invalid', __( 'Zu viele oder ungültige Angaben im Protokolleintrag.', 'pass-captain' ), 'details' );
		}
		$clean = array();
		foreach ( $details as $key => $value ) {
			if ( ! self::valid_key( $key ) ) {
				return self::error( 'wpcap_connector_audit_invalid', __( 'Ein Schlüssel der Angaben ist kein Code oder bezeichnet personenbezogene Daten.', 'pass-captain' ), 'details.' . substr( sanitize_key( (string) $key ), 0, 32 ) );
			}
			if ( ! self::valid_value( $value ) ) {
				return self::error( 'wpcap_connector_audit_invalid', __( 'Ein Wert der Angaben ist kein Code und keine Zahl (kein Freitext, keine E-Mail-Adresse).', 'pass-captain' ), 'details.' . $key );
			}
			$clean[ $key ] = $value;
		}
		if ( ! RateLimit::allow( 'connector_audit|' . $source, self::RATE_LIMIT, 60 ) ) {
			return new \WP_Error( 'wpcap_connector_audit_rate_limited', __( 'Zu viele Protokolleinträge dieses Connectors. Bitte später erneut versuchen.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$written = Audit::record( self::ACTION_PREFIX . $action, $object_type, (int) $object_id, array( 'source' => $source ) + $clean );
		if ( true !== $written ) {
			return new \WP_Error( 'wpcap_connector_audit_failed', __( 'Der Protokolleintrag konnte nicht geschrieben werden.', 'pass-captain' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/** Whether $key is the key of a connector registered through wp_pass_captain_connectors_v1. */
	public static function registered( $key ) {
		return ConnectorRegistry::is_registered( $key );
	}

	private static function valid_key( $key ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $key ) ) { return false; }
		return array() === array_intersect( explode( '_', $key ), self::FORBIDDEN_KEY_PARTS );
	}

	private static function valid_value( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) { return true; }
		if ( is_float( $value ) ) { return is_finite( $value ); }
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_.:-]{0,64}$/', $value );
	}

	private static function error( $code, $message, $field ) {
		return new \WP_Error( $code, $message, array( 'status' => 400, 'field' => $field ) );
	}
}
