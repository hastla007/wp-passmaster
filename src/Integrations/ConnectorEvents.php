<?php
/**
 * Delivers the plugin's domain events to the enabled connectors (ConnectorInterface) through the job
 * queue, so a slow or failing connector never blocks checkout, check-in or the admin and is retried
 * with backoff (dead letter after the last attempt).
 *
 * Events (connector event name ← core action):
 * - order_paid            ← wpcap_order_paid
 * - order_refunded        ← wpcap_order_refunded
 * - order_cancelled       ← wpcap_order_cancelled
 * - refund_completed      ← wpcap_refund_completed
 * - tickets_issued        ← wpcap_tickets_issued
 * - voucher_redeemed      ← wpcap_tickets_issued of an order the ticket service created for a redeemed
 *                           voucher (payment provider `voucher`; contract 1.1.0)
 * - ticket_revoked        ← wpcap_ticket_revoked (contract 1.1.0)
 * - ticket_checked_in     ← wpcap_ticket_checked_in
 * - occurrence_rescheduled← wpcap_occurrence_rescheduled
 * - occurrence_cancelled  ← wpcap_occurrence_cancelled
 *
 * One `connector_delivery` job per event and connector: a connector that fails is retried on its
 * own without delivering the event to the others twice. The job key is derived from the event's
 * idempotency key, so the same domain event is queued only once per connector; connectors receive
 * a stable idempotency key per event and connector (ConnectorRegistry::dispatch()). Nothing is
 * queued while no connector is enabled.
 *
 * Payloads carry identifiers and state only (no names, e-mail addresses, ticket or voucher codes).
 * Reasons are passed as codes only: free text (admin, partner API, voucher system) becomes `other`.
 * A connector that needs more loads it through the read facade (wpcap_get_*_state()).
 *
 * Contract version (CONTRACT_VERSION, payload key `contract_version`, SemVer): a minor version only
 * adds events or payload fields. The integer payload key `version` stays 1 for the whole 1.x line.
 * The core checks no licence; a connector decides itself whether it may run.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations;

use PassCaptain\Clock;
use PassCaptain\Integrations\Vouchers\TicketService;
use PassCaptain\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConnectorEvents {
	const JOB = 'connector_delivery';

	/** Payload format version (payload key `version`); stays 1 for every 1.x contract version. */
	const VERSION = 1;

	/**
	 * Version of the connector contract (ConnectorInterface, events, payload fields; payload key
	 * `contract_version`). 1.0.0: eight events; 1.1.0: voucher_redeemed, ticket_revoked, reason codes,
	 * `contract_version`.
	 */
	const CONTRACT_VERSION = '1.1.0';

	/** Event names a connector can receive. */
	const EVENTS = array( 'order_paid', 'order_refunded', 'order_cancelled', 'refund_completed', 'tickets_issued', 'voucher_redeemed', 'ticket_revoked', 'ticket_checked_in', 'occurrence_rescheduled', 'occurrence_cancelled' );

	/** @var bool */
	private static $registered = false;

	/** Hooks the domain events and the job handler. */
	public static function register() {
		if ( self::$registered || ! function_exists( 'add_action' ) ) { return; }
		self::$registered = true;
		add_action( 'wpcap_order_paid', array( __CLASS__, 'order_paid' ), 50, 1 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'order_refunded' ), 50, 2 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'order_cancelled' ), 50, 2 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund_completed' ), 50, 2 );
		add_action( 'wpcap_tickets_issued', array( __CLASS__, 'tickets_issued' ), 50, 2 );
		add_action( 'wpcap_ticket_revoked', array( __CLASS__, 'ticket_revoked' ), 50, 2 );
		add_action( 'wpcap_ticket_checked_in', array( __CLASS__, 'ticket_checked_in' ), 50, 2 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), 50, 3 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_cancelled' ), 50, 2 );
		add_filter( 'wpcap_process_job', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/* ---- event listeners ---- */

	public static function order_paid( $order_id ) {
		self::queue( 'order_paid', 'order_paid|' . absint( $order_id ), self::order_data( $order_id ) );
	}

	public static function order_refunded( $order_id, $reason = '' ) {
		self::queue( 'order_refunded', 'order_refunded|' . absint( $order_id ), self::order_data( $order_id ) + array( 'reason' => self::reason_code( $reason ) ) );
	}

	/** An expired hold passes no reason: `expired`. */
	public static function order_cancelled( $order_id, $reason = null ) {
		self::queue( 'order_cancelled', 'order_cancelled|' . absint( $order_id ), self::order_data( $order_id ) + array( 'reason' => null === $reason ? 'expired' : self::reason_code( $reason ) ) );
	}

	/**
	 * A reason as a code for connectors: fixed codes and prefixes (`refund_12`, `woocommerce_order_trash`)
	 * pass, free text of an admin, a partner or a voucher system (spaces, capitals, punctuation, more than
	 * 64 characters) becomes `other`, so no personal data written into a reason leaves the site.
	 */
	public static function reason_code( $reason ) {
		$reason = (string) $reason;
		if ( '' === $reason ) { return ''; }
		return 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $reason ) ? $reason : 'other';
	}

	public static function refund_completed( $refund_id, $order_id = 0 ) {
		global $wpdb;
		$refund = $wpdb->get_row( $wpdb->prepare( 'SELECT refund_id,order_id,amount_minor,tax_minor,currency,ticket_ids,status FROM %i WHERE refund_id=%d', $wpdb->prefix . 'wpcap_refunds', absint( $refund_id ) ) );
		if ( ! $refund ) { return; }
		self::queue( 'refund_completed', 'refund_completed|' . absint( $refund_id ), array(
			'refund_id'    => (int) $refund->refund_id,
			'order_id'     => (int) $refund->order_id,
			'amount_minor' => (int) $refund->amount_minor,
			'tax_minor'    => (int) $refund->tax_minor,
			'currency'     => (string) $refund->currency,
			'ticket_ids'   => array_map( 'intval', (array) json_decode( (string) $refund->ticket_ids, true ) ),
		) );
	}

	public static function tickets_issued( $order_id, $count = 0 ) {
		global $wpdb;
		$tickets = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,public_id,ticket_type_id,status FROM %i WHERE order_id=%d ORDER BY ticket_id', $wpdb->prefix . 'wpcap_tickets', absint( $order_id ) ) );
		$list = array_map( static function ( $t ) {
			return array( 'ticket_id' => (int) $t->ticket_id, 'public_id' => (string) $t->public_id, 'ticket_type_id' => (int) $t->ticket_type_id, 'status' => (string) $t->status );
		}, (array) $tickets );
		$ids = implode( ',', array_column( $list, 'ticket_id' ) );
		$order = self::order_data( $order_id );
		self::queue( 'tickets_issued', 'tickets_issued|' . absint( $order_id ) . '|' . md5( $ids ), $order + array( 'issued_count' => (int) $count, 'tickets' => $list ) );
		if ( TicketService::PROVIDER === ( $order['payment_provider'] ?? '' ) ) {
			// Voucher redeemed → tickets (ticket service v1): once per redemption, also when a repeated
			// call of the voucher system fills gaps of an interrupted issuance.
			self::queue( 'voucher_redeemed', 'voucher_redeemed|' . absint( $order_id ), $order + self::voucher_data( $order_id ) + array( 'tickets' => $list ) );
		}
	}

	public static function ticket_revoked( $ticket_id, $reason = '' ) {
		$ticket = self::ticket_row( $ticket_id );
		if ( ! $ticket ) { return; }
		self::queue( 'ticket_revoked', 'ticket_revoked|' . absint( $ticket_id ), array(
			'ticket_id'      => (int) $ticket->ticket_id,
			'public_id'      => (string) $ticket->public_id,
			'order_id'       => (int) $ticket->order_id,
			'ticket_type_id' => (int) $ticket->ticket_type_id,
			'occurrence_id'  => (int) $ticket->occurrence_id,
			'status'         => (string) $ticket->status,
			'reason'         => self::reason_code( $reason ),
		) );
	}

	public static function ticket_checked_in( $ticket_id, $context = array() ) {
		$context = (array) $context;
		$ticket = self::ticket_row( $ticket_id );
		if ( ! $ticket ) { return; }
		// Repeatable admissions check in the same ticket several times: one event per admission (scan
		// idempotency key, else the check-in row; a fresh key only when neither is known).
		$scan = (string) ( $context['idempotency_key'] ?? '' );
		$checkin = absint( $context['checkin_id'] ?? 0 );
		$key = 'ticket_checked_in|' . absint( $ticket_id ) . '|' . ( '' !== $scan ? hash( 'sha256', $scan ) : ( $checkin ? 'checkin_' . $checkin : uniqid( '', true ) ) );
		self::queue( 'ticket_checked_in', $key, array(
			'ticket_id'      => (int) $ticket->ticket_id,
			'public_id'      => (string) $ticket->public_id,
			'order_id'       => (int) $ticket->order_id,
			'ticket_type_id' => (int) $ticket->ticket_type_id,
			'occurrence_id'  => (int) $ticket->occurrence_id,
			'source'         => sanitize_key( (string) ( $context['source'] ?? 'web' ) ),
			'device_id'      => absint( $context['device_id'] ?? 0 ),
			'area_id'        => absint( $context['area_id'] ?? 0 ),
		) );
	}

	public static function occurrence_rescheduled( $occurrence_id, $old_starts_at = '', $new_starts_at = '' ) {
		self::queue( 'occurrence_rescheduled', 'occurrence_rescheduled|' . absint( $occurrence_id ) . '|' . (string) $new_starts_at, self::occurrence_data( $occurrence_id ) + array( 'old_starts_at' => (string) $old_starts_at, 'new_starts_at' => (string) $new_starts_at ) );
	}

	public static function occurrence_cancelled( $occurrence_id, $issued = 0 ) {
		self::queue( 'occurrence_cancelled', 'occurrence_cancelled|' . absint( $occurrence_id ), self::occurrence_data( $occurrence_id ) + array( 'issued_tickets' => (int) $issued ) );
	}

	/* ---- queue and delivery ---- */

	/**
	 * Keys of the connectors that are registered and enabled (option wpcap_enabled_connectors).
	 *
	 * @return string[]
	 */
	public static function enabled_keys() {
		$enabled = get_option( 'wpcap_enabled_connectors', array() );
		$connectors = apply_filters( 'wp_pass_captain_connectors_v1', array() );
		if ( ! is_array( $enabled ) || ! $enabled || ! is_array( $connectors ) ) { return array(); }
		$keys = array();
		foreach ( $connectors as $connector ) {
			if ( $connector instanceof ConnectorInterface && in_array( $connector->key(), $enabled, true ) ) {
				$keys[] = (string) $connector->key();
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Registered connectors for the suite status (A705): key, own version, capabilities, opt-in state and
	 * the connector contract version of the core. Read-only; the core neither knows nor shows a licence state.
	 *
	 * @return array[] list of {key, version, capabilities, enabled, contract_version}
	 */
	public static function registered() {
		$enabled = get_option( 'wpcap_enabled_connectors', array() );
		$enabled = is_array( $enabled ) ? $enabled : array();
		$connectors = apply_filters( 'wp_pass_captain_connectors_v1', array() );
		$out = array();
		foreach ( is_array( $connectors ) ? $connectors : array() as $connector ) {
			if ( ! $connector instanceof ConnectorInterface ) { continue; }
			try {
				$key = (string) $connector->key();
				$version = $connector->version();
				$capabilities = $connector->capabilities();
			} catch ( \Throwable $error ) {
				continue;
			}
			$out[ $key ] = array(
				'key'              => $key,
				'version'          => is_scalar( $version ) ? (string) $version : '',
				'capabilities'     => array_values( array_filter( array_map( static function ( $c ) { return is_scalar( $c ) ? sanitize_key( (string) $c ) : ''; }, (array) $capabilities ) ) ),
				'enabled'          => in_array( $key, $enabled, true ),
				'contract_version' => self::CONTRACT_VERSION,
			);
		}
		return array_values( $out );
	}

	/** Open (queued or running) and dead-letter deliveries of all connectors. */
	public static function backlog() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS n FROM %i WHERE job_type=%s AND status IN ('queued','running','dead_letter') GROUP BY status", $wpdb->prefix . 'wpcap_jobs', self::JOB ) );
		$out = array( 'pending' => 0, 'dead_letter' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ 'dead_letter' === $row->status ? 'dead_letter' : 'pending' ] += (int) $row->n;
		}
		return $out;
	}

	/** Queues one delivery job per enabled connector. */
	private static function queue( $event, $event_key, array $data ) {
		$keys = self::enabled_keys();
		if ( ! $keys ) { return; }
		$payload = array(
			'version'          => self::VERSION,
			'contract_version' => self::CONTRACT_VERSION,
			'event'            => $event,
			'occurred_at'      => gmdate( 'c', Clock::now() ),
			'site'             => home_url( '/' ),
			'data'             => $data,
		);
		foreach ( $keys as $connector_key ) {
			Jobs::enqueue( 'connector|' . $connector_key . '|' . $event_key, self::JOB, array( 'connector' => $connector_key, 'event' => $event, 'event_key' => $event_key, 'payload' => $payload ) );
		}
	}

	/**
	 * Filter wp_pass_captain_process_job_v1: delivers one event to one connector. A connector that is
	 * no longer registered or enabled ends the job. Success: deliver() returned anything but false or
	 * a WP_Error and did not throw; otherwise the job is retried.
	 */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::JOB !== $type ) { return $handled; }
		$job = (array) $payload;
		$connector_key = (string) ( $job['connector'] ?? '' );
		if ( '' === $connector_key || ! in_array( $connector_key, self::enabled_keys(), true ) ) { return true; }
		$results = ConnectorRegistry::dispatch( (string) ( $job['event'] ?? '' ), (array) ( $job['payload'] ?? array() ), (string) ( $job['event_key'] ?? '' ), $connector_key );
		$key = sanitize_key( $connector_key );
		if ( ! array_key_exists( $key, $results ) ) { return false; } // Threw (reported via wp_pass_captain_connector_error_v1).
		$result = $results[ $key ];
		if ( false === $result || is_wp_error( $result ) ) {
			do_action( 'wp_pass_captain_connector_error_v1', $connector_key, (string) ( $job['event'] ?? '' ), is_wp_error( $result ) ? $result->get_error_message() : 'delivery failed' );
			return false;
		}
		return true;
	}

	/* ---- payload helpers ---- */

	private static function ticket_row( $ticket_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT t.ticket_id,t.public_id,t.order_id,t.ticket_type_id,t.status,tt.occurrence_id FROM %i t LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', $wpdb->prefix . 'wpcap_ticket_types', absint( $ticket_id ) ) );
	}

	/** Voucher fields of a ticket-service order: provider, voucher id, purpose, value – no code, code hint or redemption key. */
	private static function voucher_data( $order_id ) {
		global $wpdb;
		$snapshot = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT voucher_snapshot FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) ), true );
		$source = (array) ( $snapshot['source'] ?? array() );
		return array(
			'voucher_provider'     => sanitize_key( (string) ( $source['provider'] ?? '' ) ),
			'voucher_id'           => absint( $source['voucher_id'] ?? 0 ),
			'voucher_purpose'      => sanitize_key( (string) ( $snapshot['purpose'] ?? '' ) ),
			'voucher_amount_minor' => (int) ( $snapshot['amount_minor'] ?? 0 ),
		);
	}

	private static function order_data( $order_id ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,public_id,status,payment_status,payment_provider,currency,total_minor,refunded_minor,created_at FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) );
		if ( ! $order ) { return array( 'order_id' => absint( $order_id ) ); }
		return array(
			'order_id'         => (int) $order->order_id,
			'public_id'        => (string) $order->public_id,
			'status'           => (string) $order->status,
			'payment_status'   => (string) $order->payment_status,
			'payment_provider' => (string) $order->payment_provider,
			'currency'         => (string) $order->currency,
			'total_minor'      => (int) $order->total_minor,
			'refunded_minor'   => (int) $order->refunded_minor,
			'created_at'       => (string) $order->created_at,
		);
	}

	private static function occurrence_data( $occurrence_id ) {
		global $wpdb;
		$occ = $wpdb->get_row( $wpdb->prepare( 'SELECT occurrence_id,event_id,starts_at,timezone,status FROM %i WHERE occurrence_id=%d', $wpdb->prefix . 'wpcap_occurrences', absint( $occurrence_id ) ) );
		if ( ! $occ ) { return array( 'occurrence_id' => absint( $occurrence_id ) ); }
		return array(
			'occurrence_id' => (int) $occ->occurrence_id,
			'event_id'      => (int) $occ->event_id,
			'starts_at'     => (string) $occ->starts_at,
			'timezone'      => (string) $occ->timezone,
			'status'        => (string) $occ->status,
		);
	}
}
