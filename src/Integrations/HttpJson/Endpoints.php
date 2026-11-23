<?php
/**
 * Generic HTTP/JSON connector (CRM, Zapier, Make, n8n, own services): configured endpoints, field
 * mapping, delivery and delivery log.
 *
 * Every active endpoint is one connector (key `http_json_{id}`, see Connector) registered through
 * `wp_pass_captain_connectors_v1` and listed in `wpcap_enabled_connectors`, so the core delivers the
 * selected domain events through the job queue with retries/backoff (ConnectorEvents); one job per
 * event and endpoint.
 *
 * Request: POST JSON to the target URL through Security\OutboundHttp (https only, public addresses,
 * no redirects, bounded size/time). Headers: Content-Type application/json, Idempotency-Key (stable per
 * event and endpoint, also in the body), X-PassCaptain-Event, and optionally one auth header whose value
 * (e.g. "Bearer …") is stored Vault-encrypted (context connector_http_secret).
 *
 * Body: { contract_version, event, occurred_at, idempotency_key, data }. Without a mapping `data` is the
 * core payload (identifiers and state only). With a mapping `data` holds the chosen fields under the
 * chosen JSON keys (dot notation nests: "customer.email"); ticket and attendee fields go into
 * `data.tickets[]` (one object per ticket). Fields marked as personal data (e-mail, attendee name) are
 * only sent when the endpoint has the PII opt-in; otherwise they are dropped at send time.
 *
 * Idempotency: deliveries are logged per endpoint and idempotency key (wpcap_connector_deliveries);
 * an event already delivered is acknowledged without a second request (job retry after a crash,
 * duplicate queueing). Log status: delivered, retrying (job queue retries), failed (attempts used up).
 * The log keeps the core payload (identifiers only), never the mapped body.
 */
namespace PassCaptain\Integrations\HttpJson;

use PassCaptain\Jobs;
use PassCaptain\Security\OutboundHttp;
use PassCaptain\Security\Redact;
use PassCaptain\Security\Vault;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Endpoints {
	const KEY_PREFIX = 'http_json_';
	const VAULT_CONTEXT = 'connector_http_secret';
	const CONTRACT_VERSION = 1;
	const MAX_MAPPING_ROWS = 50;

	/** Connector events an endpoint can subscribe to (see ConnectorEvents). */
	const EVENTS = array( 'order_paid', 'order_refunded', 'order_cancelled', 'refund_completed', 'tickets_issued', 'ticket_checked_in', 'occurrence_rescheduled', 'occurrence_cancelled' );

	/** Headers an auth header must not replace. */
	const RESERVED_HEADERS = array( 'host', 'content-type', 'content-length', 'idempotency-key', 'x-passcaptain-event', 'user-agent', 'connection', 'transfer-encoding', 'cookie' );

	/** @var array|null Per-request cache of all endpoints. */
	private static $cache = null;

	public static function flush() {
		self::$cache = null;
	}

	private static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_' . $name;
	}

	/* ------------------------------------------------------------------ Field catalogue */

	/**
	 * Mappable fields: source => [label, personal data].
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function sources() {
		return array(
			'order.id' => array( __( 'Bestellung: ID', 'pass-captain' ), false ),
			'order.status' => array( __( 'Bestellung: Status', 'pass-captain' ), false ),
			'order.payment_status' => array( __( 'Bestellung: Zahlungsstatus', 'pass-captain' ), false ),
			'order.payment_provider' => array( __( 'Bestellung: Zahlungsart', 'pass-captain' ), false ),
			'order.currency' => array( __( 'Bestellung: Währung', 'pass-captain' ), false ),
			'order.total_minor' => array( __( 'Bestellung: Gesamtbetrag (Cent)', 'pass-captain' ), false ),
			'order.refunded_minor' => array( __( 'Bestellung: Erstattet (Cent)', 'pass-captain' ), false ),
			'order.locale' => array( __( 'Bestellung: Sprache', 'pass-captain' ), false ),
			'order.created_at' => array( __( 'Bestellung: Datum', 'pass-captain' ), false ),
			'order.customer_email' => array( __( 'Bestellung: E-Mail-Adresse', 'pass-captain' ), true ),
			'event.id' => array( __( 'Event: ID', 'pass-captain' ), false ),
			'event.name' => array( __( 'Event: Name', 'pass-captain' ), false ),
			'occurrence.id' => array( __( 'Termin: ID', 'pass-captain' ), false ),
			'occurrence.starts_at' => array( __( 'Termin: Beginn (ISO 8601)', 'pass-captain' ), false ),
			'occurrence.timezone' => array( __( 'Termin: Zeitzone', 'pass-captain' ), false ),
			'occurrence.location' => array( __( 'Termin: Ort', 'pass-captain' ), false ),
			'occurrence.status' => array( __( 'Termin: Status', 'pass-captain' ), false ),
			'ticket.id' => array( __( 'Ticket: ID', 'pass-captain' ), false ),
			'ticket.status' => array( __( 'Ticket: Status', 'pass-captain' ), false ),
			'ticket.type' => array( __( 'Ticket: Ticketart', 'pass-captain' ), false ),
			'ticket.type_id' => array( __( 'Ticket: Ticketart-ID', 'pass-captain' ), false ),
			'attendee.name' => array( __( 'Teilnehmer: Name', 'pass-captain' ), true ),
			'attendee.email' => array( __( 'Teilnehmer: E-Mail-Adresse', 'pass-captain' ), true ),
			'refund.amount_minor' => array( __( 'Erstattung: Betrag (Cent)', 'pass-captain' ), false ),
			'refund.currency' => array( __( 'Erstattung: Währung', 'pass-captain' ), false ),
			'checkin.source' => array( __( 'Check-in: Quelle', 'pass-captain' ), false ),
			'checkin.area_id' => array( __( 'Check-in: Bereich', 'pass-captain' ), false ),
		);
	}

	public static function event_labels() {
		return array(
			'order_paid' => __( 'Bestellung bezahlt', 'pass-captain' ),
			'order_refunded' => __( 'Bestellung erstattet', 'pass-captain' ),
			'order_cancelled' => __( 'Bestellung storniert', 'pass-captain' ),
			'refund_completed' => __( 'Erstattung abgeschlossen', 'pass-captain' ),
			'tickets_issued' => __( 'Tickets ausgestellt', 'pass-captain' ),
			'ticket_checked_in' => __( 'Ticket eingecheckt', 'pass-captain' ),
			'occurrence_rescheduled' => __( 'Termin verschoben', 'pass-captain' ),
			'occurrence_cancelled' => __( 'Termin abgesagt', 'pass-captain' ),
		);
	}

	/** True for a usable JSON key path (letters, digits, underscore; up to four levels with dots). */
	public static function valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}(\.[A-Za-z_][A-Za-z0-9_]{0,63}){0,3}$/', $key );
	}

	/**
	 * Normalises mapping rows ([source, key] or ['source' => , 'key' => ]); unknown sources, invalid keys,
	 * duplicates and empty rows are dropped.
	 *
	 * @return array<int,array{source:string,key:string}>
	 */
	public static function sanitize_mapping( $rows ) {
		$sources = self::sources();
		$out = array();
		$keys = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$source = (string) ( $row['source'] ?? $row[0] ?? '' );
			$key = trim( (string) ( $row['key'] ?? $row[1] ?? '' ) );
			if ( ! isset( $sources[ $source ] ) || ! self::valid_key( $key ) || isset( $keys[ $key ] ) ) { continue; }
			$keys[ $key ] = true;
			$out[] = array( 'source' => $source, 'key' => $key );
			if ( count( $out ) >= self::MAX_MAPPING_ROWS ) { break; }
		}
		return $out;
	}

	public static function is_pii_source( $source ) {
		$sources = self::sources();
		return ! empty( $sources[ $source ][1] );
	}

	/* ------------------------------------------------------------------ Repository */

	/** All endpoints (objects with decoded events[] and mapping[]). */
	public static function all() {
		if ( null !== self::$cache ) { return self::$cache; }
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY endpoint_id', self::table( 'connector_endpoints' ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return array(); }
		$out = array();
		foreach ( $rows as $row ) { $out[ (int) $row->endpoint_id ] = self::hydrate( $row ); }
		self::$cache = $out;
		return $out;
	}

	private static function hydrate( $row ) {
		$row->endpoint_id = (int) $row->endpoint_id;
		$row->include_pii = (int) $row->include_pii;
		$row->events = array_values( array_intersect( self::EVENTS, explode( ',', (string) $row->events ) ) );
		$mapping = json_decode( (string) $row->mapping, true );
		$row->mapping = self::sanitize_mapping( is_array( $mapping ) ? $mapping : array() );
		return $row;
	}

	public static function get( $endpoint_id ) {
		$all = self::all();
		return $all[ absint( $endpoint_id ) ] ?? null;
	}

	public static function key( $endpoint_id ) {
		return self::KEY_PREFIX . absint( $endpoint_id );
	}

	/** Endpoint id of a connector key, or 0. */
	public static function id_from_key( $key ) {
		return preg_match( '/^' . self::KEY_PREFIX . '(\d+)$/', (string) $key, $m ) ? (int) $m[1] : 0;
	}

	/** Decrypted auth header value ('' when none or no longer decryptable). */
	public static function secret( $endpoint ) {
		$stored = (string) ( $endpoint->secret ?? '' );
		if ( '' === $stored ) { return ''; }
		$plain = Vault::decrypt( $stored, self::VAULT_CONTEXT );
		return null === $plain ? '' : $plain;
	}

	/**
	 * Creates or updates an endpoint.
	 *
	 * @param array $in name, url, auth_header, secret ('' keeps the stored one), clear_secret, events[], mapping[], include_pii, status (active|disabled).
	 * @return array|\WP_Error ['endpoint_id' => int]
	 */
	public static function save( array $in, $endpoint_id = 0 ) {
		global $wpdb;
		$endpoint_id = absint( $endpoint_id );
		$existing = $endpoint_id ? self::get( $endpoint_id ) : null;
		if ( $endpoint_id && ! $existing ) { return new \WP_Error( 'wpcap_connector_missing', __( 'Connector nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		if ( '' === $name ) { return new \WP_Error( 'wpcap_connector_name', __( 'Bitte einen Namen angeben.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$url = trim( (string) ( $in['url'] ?? '' ) );
		if ( strlen( $url ) > 500 ) { return new \WP_Error( 'wpcap_outbound_url_invalid', __( 'Die Ziel-URL ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$valid = OutboundHttp::validate_url( $url );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$header = trim( (string) ( $in['auth_header'] ?? '' ) );
		if ( '' === $header ) { $header = 'Authorization'; }
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $header ) || in_array( strtolower( $header ), self::RESERVED_HEADERS, true ) ) {
			return new \WP_Error( 'wpcap_connector_header', __( 'Der Name des Auth-Headers ist ungültig oder reserviert.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$events = array_values( array_intersect( self::EVENTS, array_map( 'strval', (array) ( $in['events'] ?? array() ) ) ) );
		if ( ! $events ) { return new \WP_Error( 'wpcap_connector_events', __( 'Bitte mindestens ein Ereignis wählen.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$secret = (string) ( $in['secret'] ?? '' );
		if ( preg_match( '/[\r\n\x00]/', $secret ) || strlen( $secret ) > 1000 ) {
			return new \WP_Error( 'wpcap_connector_secret', __( 'Der Header-Wert ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$stored_secret = $existing ? (string) $existing->secret : '';
		if ( '' !== $secret ) { $stored_secret = Vault::encrypt( $secret, self::VAULT_CONTEXT ); }
		if ( ! empty( $in['clear_secret'] ) ) { $stored_secret = ''; }
		$now = current_time( 'mysql', true );
		$data = array(
			'name' => $name,
			'url' => $url,
			'auth_header' => $header,
			'secret' => $stored_secret,
			'events' => implode( ',', $events ),
			'mapping' => (string) wp_json_encode( self::sanitize_mapping( $in['mapping'] ?? array() ) ),
			'include_pii' => ! empty( $in['include_pii'] ) ? 1 : 0,
			'status' => 'disabled' === ( $in['status'] ?? 'active' ) ? 'disabled' : 'active',
			'updated_at' => $now,
		);
		if ( $existing ) {
			$ok = false !== $wpdb->update( self::table( 'connector_endpoints' ), $data, array( 'endpoint_id' => $endpoint_id ) );
		} else {
			$data['created_at'] = $now;
			$ok = false !== $wpdb->insert( self::table( 'connector_endpoints' ), $data );
			$endpoint_id = (int) $wpdb->insert_id;
		}
		if ( ! $ok || ! $endpoint_id ) { return new \WP_Error( 'wpcap_connector_save', __( 'Connector konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		self::flush();
		self::sync_enabled( $endpoint_id, 'active' === $data['status'] );
		\PassCaptain\Audit::record( $existing ? 'connector_updated' : 'connector_created', 'settings', $endpoint_id, array( 'connector' => self::key( $endpoint_id ), 'events' => $events, 'include_pii' => (bool) $data['include_pii'], 'status' => $data['status'] ) );
		return array( 'endpoint_id' => $endpoint_id, 'pii_dropped' => ! $data['include_pii'] && (bool) array_filter( self::sanitize_mapping( $in['mapping'] ?? array() ), static function ( $row ) { return self::is_pii_source( $row['source'] ); } ) );
	}

	public static function set_status( $endpoint_id, $active ) {
		global $wpdb;
		if ( ! self::get( $endpoint_id ) ) { return false; }
		$wpdb->update( self::table( 'connector_endpoints' ), array( 'status' => $active ? 'active' : 'disabled', 'updated_at' => current_time( 'mysql', true ) ), array( 'endpoint_id' => absint( $endpoint_id ) ) );
		self::flush();
		self::sync_enabled( $endpoint_id, (bool) $active );
		return true;
	}

	public static function delete( $endpoint_id ) {
		global $wpdb;
		if ( ! self::get( $endpoint_id ) ) { return false; }
		$wpdb->delete( self::table( 'connector_endpoints' ), array( 'endpoint_id' => absint( $endpoint_id ) ) );
		$wpdb->delete( self::table( 'connector_deliveries' ), array( 'endpoint_id' => absint( $endpoint_id ) ) );
		self::flush();
		self::sync_enabled( $endpoint_id, false );
		\PassCaptain\Audit::record( 'connector_deleted', 'settings', absint( $endpoint_id ), array( 'connector' => self::key( $endpoint_id ) ) );
		return true;
	}

	/** Adds/removes the endpoint's connector key in wpcap_enabled_connectors (one writer: ConnectorRegistry). */
	private static function sync_enabled( $endpoint_id, $enabled ) {
		\PassCaptain\Integrations\ConnectorRegistry::store( self::key( $endpoint_id ), (bool) $enabled );
	}

	/* ------------------------------------------------------------------ Mapping */

	/** Data available to the mapping for one event payload. */
	private static function context( $event, array $data ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ctx = array( 'order' => null, 'occurrence' => null, 'tickets' => array(), 'refund' => null, 'checkin' => null );
		$order_id = absint( $data['order_id'] ?? 0 );
		if ( $order_id ) {
			$ctx['order'] = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,public_id,status,payment_status,payment_provider,currency,total_minor,refunded_minor,locale,created_at,customer_email FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $order_id ) );
		}
		$ticket_sql = "SELECT t.ticket_id,t.public_id,t.status,t.ticket_type_id,t.attendee_email,tt.name AS type_name,tt.occurrence_id,ta.attendee_name,ta.attendee_email AS assigned_email FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id";
		if ( 'ticket_checked_in' === $event && ! empty( $data['ticket_id'] ) ) {
			$ctx['tickets'] = (array) $wpdb->get_results( $wpdb->prepare( $ticket_sql . ' WHERE t.ticket_id=%d', absint( $data['ticket_id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL.
			$ctx['checkin'] = array( 'source' => (string) ( $data['source'] ?? '' ), 'area_id' => (int) ( $data['area_id'] ?? 0 ) );
		} elseif ( 'refund_completed' === $event && ! empty( $data['ticket_ids'] ) ) {
			$ids = array_slice( array_filter( array_map( 'absint', (array) $data['ticket_ids'] ) ), 0, 500 );
			if ( $ids ) { $ctx['tickets'] = (array) $wpdb->get_results( $ticket_sql . ' WHERE t.ticket_id IN (' . implode( ',', $ids ) . ') ORDER BY t.ticket_id' ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		} elseif ( $order_id ) {
			$ctx['tickets'] = (array) $wpdb->get_results( $wpdb->prepare( $ticket_sql . ' WHERE t.order_id=%d ORDER BY t.ticket_id LIMIT 500', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL.
		}
		if ( 'refund_completed' === $event ) {
			$ctx['refund'] = array( 'amount_minor' => (int) ( $data['amount_minor'] ?? 0 ), 'currency' => (string) ( $data['currency'] ?? '' ) );
		}
		$occurrence_id = absint( $data['occurrence_id'] ?? 0 );
		if ( ! $occurrence_id && $ctx['tickets'] ) { $occurrence_id = (int) $ctx['tickets'][0]->occurrence_id; }
		if ( $occurrence_id ) {
			$ctx['occurrence'] = $wpdb->get_row( $wpdb->prepare( 'SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.location,o.status,e.name AS event_name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id=%d', $p . 'wpcap_occurrences', $p . 'wpcap_events', $occurrence_id ) );
		}
		return $ctx;
	}

	/** Value of one non-ticket source. */
	private static function value( $source, array $ctx ) {
		$o = $ctx['order'];
		$occ = $ctx['occurrence'];
		switch ( $source ) {
			case 'order.id':
				return $o ? (string) $o->public_id : null;
			case 'order.status':
				return $o ? (string) $o->status : null;
			case 'order.payment_status':
				return $o ? (string) $o->payment_status : null;
			case 'order.payment_provider':
				return $o ? (string) $o->payment_provider : null;
			case 'order.currency':
				return $o ? (string) $o->currency : null;
			case 'order.total_minor':
				return $o ? (int) $o->total_minor : null;
			case 'order.refunded_minor':
				return $o ? (int) $o->refunded_minor : null;
			case 'order.locale':
				return $o ? (string) $o->locale : null;
			case 'order.created_at':
				return $o ? gmdate( 'c', (int) strtotime( $o->created_at . ' UTC' ) ) : null;
			case 'order.customer_email':
				return $o ? (string) $o->customer_email : null;
			case 'event.id':
				return $occ ? (int) $occ->event_id : null;
			case 'event.name':
				return $occ ? (string) $occ->event_name : null;
			case 'occurrence.id':
				return $occ ? (int) $occ->occurrence_id : null;
			case 'occurrence.starts_at':
				return $occ ? gmdate( 'c', (int) strtotime( $occ->starts_at . ' UTC' ) ) : null;
			case 'occurrence.timezone':
				return $occ ? (string) $occ->timezone : null;
			case 'occurrence.location':
				return $occ ? (string) $occ->location : null;
			case 'occurrence.status':
				return $occ ? (string) $occ->status : null;
			case 'refund.amount_minor':
				return $ctx['refund'] ? $ctx['refund']['amount_minor'] : null;
			case 'refund.currency':
				return $ctx['refund'] ? $ctx['refund']['currency'] : null;
			case 'checkin.source':
				return $ctx['checkin'] ? $ctx['checkin']['source'] : null;
			case 'checkin.area_id':
				return $ctx['checkin'] ? $ctx['checkin']['area_id'] : null;
		}
		return null;
	}

	private static function ticket_value( $source, $t ) {
		switch ( $source ) {
			case 'ticket.id':
				return (string) $t->public_id;
			case 'ticket.status':
				return (string) $t->status;
			case 'ticket.type':
				return (string) $t->type_name;
			case 'ticket.type_id':
				return (int) $t->ticket_type_id;
			case 'attendee.name':
				return '' !== (string) $t->attendee_name ? (string) $t->attendee_name : null;
			case 'attendee.email':
				$email = '' !== (string) $t->assigned_email ? (string) $t->assigned_email : (string) $t->attendee_email;
				return '' !== $email ? $email : null;
		}
		return null;
	}

	private static function set_path( array &$target, $path, $value ) {
		$parts = explode( '.', $path );
		$ref = &$target;
		foreach ( $parts as $i => $part ) {
			if ( $i === count( $parts ) - 1 ) { $ref[ $part ] = $value; break; }
			if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) { $ref[ $part ] = array(); }
			$ref = &$ref[ $part ];
		}
		unset( $ref );
	}

	/**
	 * `data` of the request body: the core payload data without mapping, otherwise the mapped fields
	 * (personal data only with the endpoint's opt-in).
	 */
	public static function map( $endpoint, $event, array $data ) {
		$mapping = (array) $endpoint->mapping;
		if ( ! $mapping ) { return $data; }
		$mapping = array_values( array_filter( $mapping, static function ( $row ) use ( $endpoint ) {
			return (int) $endpoint->include_pii || ! self::is_pii_source( $row['source'] );
		} ) );
		$ctx = self::context( (string) $event, $data );
		$out = array();
		$ticket_rows = array();
		foreach ( $mapping as $row ) {
			if ( 0 === strpos( $row['source'], 'ticket.' ) || 0 === strpos( $row['source'], 'attendee.' ) ) {
				$ticket_rows[] = $row;
				continue;
			}
			self::set_path( $out, $row['key'], self::value( $row['source'], $ctx ) );
		}
		if ( $ticket_rows ) {
			$out['tickets'] = array();
			foreach ( $ctx['tickets'] as $ticket ) {
				$item = array();
				foreach ( $ticket_rows as $row ) { self::set_path( $item, $row['key'], self::ticket_value( $row['source'], $ticket ) ); }
				$out['tickets'][] = $item;
			}
		}
		return (array) apply_filters( 'wp_pass_captain_http_connector_data_v1', $out, (int) $endpoint->endpoint_id, $event, $data );
	}

	/** Complete request body. */
	public static function body( $endpoint, $event, array $payload, $idempotency_key ) {
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'event' => (string) $event,
			'occurred_at' => (string) ( $payload['occurred_at'] ?? gmdate( 'c' ) ),
			'idempotency_key' => (string) $idempotency_key,
			'data' => self::map( $endpoint, $event, (array) ( $payload['data'] ?? array() ) ),
		);
	}

	/* ------------------------------------------------------------------ Delivery */

	private static function log_row( $endpoint_id, $idempotency_key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE endpoint_id=%d AND idempotency_key=%s', self::table( 'connector_deliveries' ), absint( $endpoint_id ), (string) $idempotency_key ) );
	}

	/** Sends one request (no log). @return array|\WP_Error OutboundHttp result. */
	private static function send( $endpoint, $event, array $body, $idempotency_key ) {
		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
			'Idempotency-Key' => (string) $idempotency_key,
			'X-PassCaptain-Event' => (string) $event,
			'User-Agent' => 'PassCaptain-Connector/' . self::CONTRACT_VERSION,
		);
		$secret = self::secret( $endpoint );
		if ( '' !== $secret ) { $headers[ (string) $endpoint->auth_header ] = $secret; }
		$result = OutboundHttp::request( (string) $endpoint->url, array( 'method' => 'POST', 'headers' => $headers, 'body' => (string) wp_json_encode( $body ), 'timeout' => 10 ), array( 'max_bytes' => 65536 ) );
		if ( ! is_wp_error( $result ) && ( $result['code'] < 200 || $result['code'] >= 300 ) ) {
			return new \WP_Error( 'wpcap_connector_http_status', sprintf( /* translators: %d: HTTP status code */ __( 'Der Empfänger antwortete mit HTTP %d.', 'pass-captain' ), (int) $result['code'] ), array( 'status' => 502, 'response_code' => (int) $result['code'] ) );
		}
		return $result;
	}

	/**
	 * Delivers one event to an endpoint and logs it. Already delivered keys are acknowledged without a
	 * request.
	 *
	 * @return true|\WP_Error
	 */
	public static function deliver( $endpoint, $event, array $payload, $idempotency_key ) {
		global $wpdb;
		$table = self::table( 'connector_deliveries' );
		$event = sanitize_key( (string) $event );
		$id = (int) $endpoint->endpoint_id;
		$row = self::log_row( $id, $idempotency_key );
		if ( $row && 'delivered' === $row->status ) { return true; }
		$now = current_time( 'mysql', true );
		if ( ! $row ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (endpoint_id,event,idempotency_key,payload,status,attempts,response_code,last_error,created_at,updated_at) VALUES (%d,%s,%s,%s,'pending',0,0,'',%s,%s)", $table, $id, $event, (string) $idempotency_key, (string) wp_json_encode( $payload ), $now, $now ) );
			$row = self::log_row( $id, $idempotency_key );
			if ( ! $row ) { return new \WP_Error( 'wpcap_connector_log', __( 'Zustellung konnte nicht protokolliert werden.', 'pass-captain' ) ); }
		}
		$result = self::send( $endpoint, $event, self::body( $endpoint, $event, $payload, $idempotency_key ), $idempotency_key );
		$attempts = (int) $row->attempts + 1;
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			$wpdb->update( $table, array( 'status' => $attempts >= Jobs::max_attempts() ? 'failed' : 'retrying', 'attempts' => $attempts, 'response_code' => (int) ( $data['response_code'] ?? 0 ), 'last_error' => mb_substr( Redact::text( $result->get_error_message() ), 0, 190 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'delivery_id' => (int) $row->delivery_id ) );
			return $result;
		}
		$wpdb->update( $table, array( 'status' => 'delivered', 'attempts' => $attempts, 'response_code' => (int) $result['code'], 'last_error' => '', 'delivered_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( 'delivery_id' => (int) $row->delivery_id ) );
		return true;
	}

	/** Manual retry of a logged delivery (same payload and idempotency key). @return true|\WP_Error */
	public static function retry( $delivery_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_id=%d', self::table( 'connector_deliveries' ), absint( $delivery_id ) ) );
		$endpoint = $row ? self::get( (int) $row->endpoint_id ) : null;
		if ( ! $row || ! $endpoint ) { return new \WP_Error( 'wpcap_connector_delivery_missing', __( 'Zustellung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( 'delivered' === $row->status ) { return true; }
		$payload = json_decode( (string) $row->payload, true );
		return self::deliver( $endpoint, (string) $row->event, is_array( $payload ) ? $payload : array(), (string) $row->idempotency_key );
	}

	/**
	 * Sends a synthetic test event (no stored data, no personal data) and logs it as event "test".
	 *
	 * @return array|\WP_Error ['response_code' => int]
	 */
	public static function test_send( $endpoint_id ) {
		$endpoint = self::get( $endpoint_id );
		if ( ! $endpoint ) { return new \WP_Error( 'wpcap_connector_missing', __( 'Connector nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$key = hash( 'sha256', 'test|' . $endpoint->endpoint_id . '|' . wp_generate_uuid4() );
		$sample = array();
		foreach ( $endpoint->mapping as $row ) {
			if ( ! (int) $endpoint->include_pii && self::is_pii_source( $row['source'] ) ) { continue; }
			$sample[ $row['source'] ] = $row['key'];
		}
		$body = array( 'contract_version' => self::CONTRACT_VERSION, 'event' => 'test', 'occurred_at' => gmdate( 'c' ), 'idempotency_key' => $key, 'data' => array( 'test' => true, 'mapping' => $sample ) );
		$result = self::send( $endpoint, 'test', $body, $key );
		global $wpdb;
		$now = current_time( 'mysql', true );
		$data = is_wp_error( $result ) ? (array) $result->get_error_data() : array();
		$wpdb->insert( self::table( 'connector_deliveries' ), array( 'endpoint_id' => (int) $endpoint->endpoint_id, 'event' => 'test', 'idempotency_key' => $key, 'payload' => '{}', 'status' => is_wp_error( $result ) ? 'failed' : 'delivered', 'attempts' => 1, 'response_code' => is_wp_error( $result ) ? (int) ( $data['response_code'] ?? 0 ) : (int) $result['code'], 'last_error' => is_wp_error( $result ) ? mb_substr( Redact::text( $result->get_error_message() ), 0, 190 ) : '', 'created_at' => $now, 'updated_at' => $now, 'delivered_at' => is_wp_error( $result ) ? null : $now ) );
		return is_wp_error( $result ) ? $result : array( 'response_code' => (int) $result['code'], 'endpoint_id' => (int) $endpoint->endpoint_id );
	}

	/** Latest log rows (optionally of one endpoint). */
	public static function deliveries( $endpoint_id = 0, $limit = 50 ) {
		global $wpdb;
		$table = self::table( 'connector_deliveries' );
		$limit = max( 1, min( 500, (int) $limit ) );
		if ( $endpoint_id ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_id,endpoint_id,event,status,attempts,response_code,last_error,created_at,updated_at,delivered_at FROM %i WHERE endpoint_id=%d ORDER BY delivery_id DESC LIMIT %d', $table, absint( $endpoint_id ), $limit ) );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_id,endpoint_id,event,status,attempts,response_code,last_error,created_at,updated_at,delivered_at FROM %i ORDER BY delivery_id DESC LIMIT %d', $table, $limit ) );
	}

	/** Removes log rows older than the retention (default 30 days, filter wpcap_connector_log_days). */
	public static function purge_old() {
		global $wpdb;
		$days = max( 1, (int) apply_filters( 'wpcap_connector_log_days', 30 ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('delivered','failed') AND updated_at<%s LIMIT 1000", self::table( 'connector_deliveries' ), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
