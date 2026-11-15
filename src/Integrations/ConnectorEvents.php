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
 * Payloads carry identifiers and state only (no names, e-mail addresses or ticket codes). A connector
 * that needs more loads it through the core classes.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations;

use PassCaptain\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ConnectorEvents {
	const JOB = 'connector_delivery';

	/** Payload contract version (payload key `version`). */
	const VERSION = 1;

	/** @var bool */
	private static $registered = false;

	/** Hooks the domain events and the job handler. */
	public static function register() {
		if ( self::$registered || ! function_exists( 'add_action' ) ) { return; }
		self::$registered = true;
		add_action( 'wpcap_order_paid', array( __CLASS__, 'order_paid' ), 50, 1 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'order_refunded' ), 50, 2 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'order_cancelled' ), 50, 1 );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'refund_completed' ), 50, 2 );
		add_action( 'wpcap_tickets_issued', array( __CLASS__, 'tickets_issued' ), 50, 2 );
		add_action( 'wpcap_ticket_checked_in', array( __CLASS__, 'ticket_checked_in' ), 50, 2 );
		add_action( 'wpcap_occurrence_rescheduled', array( __CLASS__, 'occurrence_rescheduled' ), 50, 3 );
		add_action( 'wpcap_occurrence_cancelled', array( __CLASS__, 'occurrence_cancelled' ), 50, 2 );
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	/* ---- event listeners ---- */

	public static function order_paid( $order_id ) {
		self::queue( 'order_paid', 'order_paid|' . absint( $order_id ), self::order_data( $order_id ) );
	}

	public static function order_refunded( $order_id, $reason = '' ) {
		self::queue( 'order_refunded', 'order_refunded|' . absint( $order_id ), self::order_data( $order_id ) + array( 'reason' => sanitize_key( (string) $reason ) ) );
	}

	public static function order_cancelled( $order_id ) {
		self::queue( 'order_cancelled', 'order_cancelled|' . absint( $order_id ), self::order_data( $order_id ) );
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
		self::queue( 'tickets_issued', 'tickets_issued|' . absint( $order_id ) . '|' . md5( $ids ), self::order_data( $order_id ) + array( 'issued_count' => (int) $count, 'tickets' => $list ) );
	}

	public static function ticket_checked_in( $ticket_id, $context = array() ) {
		global $wpdb;
		$context = (array) $context;
		$ticket = $wpdb->get_row( $wpdb->prepare( 'SELECT t.ticket_id,t.public_id,t.order_id,t.ticket_type_id,tt.occurrence_id FROM %i t LEFT JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE t.ticket_id=%d', $wpdb->prefix . 'wpcap_tickets', $wpdb->prefix . 'wpcap_ticket_types', absint( $ticket_id ) ) );
		if ( ! $ticket ) { return; }
		// Repeatable admissions check in the same ticket several times: one event per admission.
		$scan = (string) ( $context['idempotency_key'] ?? '' );
		$key = 'ticket_checked_in|' . absint( $ticket_id ) . '|' . ( '' !== $scan ? hash( 'sha256', $scan ) : uniqid( '', true ) );
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

	/** Queues one delivery job per enabled connector. */
	private static function queue( $event, $event_key, array $data ) {
		$keys = self::enabled_keys();
		if ( ! $keys ) { return; }
		$payload = array(
			'version'     => self::VERSION,
			'event'       => $event,
			'occurred_at' => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'data'        => $data,
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
