<?php
/**
 * Outgoing webhooks: subscriptions, event fan-out into a delivery queue, signed HTTP delivery with
 * exponential backoff, automatic disabling after repeated failures and manual redelivery.
 *
 * Signature (like Stripe): header `X-WPCAP-Signature: t={unix},v1={hex}` where
 * v1 = HMAC-SHA256(secret, "{t}.{raw body}"). Payloads are versioned (api_version) and contain IDs
 * and status values only; e-mail addresses are added only for subscriptions with include_pii=1.
 */
namespace PassCaptain\Webhooks;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dispatcher {
	const API_VERSION = '2026-11-01';
	const EVENTS = array( 'order.paid', 'order.refunded', 'order.cancelled', 'ticket.issued', 'ticket.checked_in', 'ticket.revoked', 'occurrence.rescheduled', 'occurrence.cancelled' );
	const MAX_ATTEMPTS = 8;
	const DISABLE_AFTER = 20;
	const BASE_DELAY = 60;
	const MAX_DELAY = 21600;

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_webhook_' . $name;
	}

	public static function max_attempts() { return max( 1, (int) apply_filters( 'wpcap_webhooks_max_attempts', self::MAX_ATTEMPTS ) ); }

	public static function disable_after() { return max( 1, (int) apply_filters( 'wpcap_webhooks_disable_after', self::DISABLE_AFTER ) ); }

	/** Delay in seconds before attempt n+1 after n failed attempts: 60 s · 2^(n-1), at most 6 h. */
	public static function backoff( $attempts ) {
		return (int) min( self::MAX_DELAY, self::BASE_DELAY * ( 2 ** max( 0, (int) $attempts - 1 ) ) );
	}

	public static function generate_secret() {
		return 'whsec_' . bin2hex( random_bytes( 24 ) );
	}

	/** Signature header value for a body. */
	public static function sign( $body, $secret, $timestamp ) {
		return 't=' . (int) $timestamp . ',v1=' . hash_hmac( 'sha256', (int) $timestamp . '.' . $body, (string) $secret );
	}

	/** Verification helper for receivers (and tests): same scheme as Stripe, 300 s tolerance. */
	public static function verify( $body, $header, $secret, $now = null ) {
		return \PassCaptain\Payments\StripeGateway::signature_valid( $body, $header, $secret, $now );
	}

	/** Only absolute https URLs to public hosts. */
	public static function valid_url( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '~^https://[^\s/?#]+~i', $url ) || false !== strpos( $url, '@' ) ) { return false; }
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(local|localhost|internal)$/', $host ) ) { return false; }
		if ( filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) && ! filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { return false; }
		return function_exists( 'wp_http_validate_url' ) ? false !== wp_http_validate_url( $url ) : true;
	}

	/* ------------------------------------------------------------------ Subscriptions */

	/** @return array|\WP_Error ['subscription_id' => int, 'secret' => string] */
	public static function create_subscription( $url, array $events, $include_pii = false, $description = '' ) {
		global $wpdb;
		$url = esc_url_raw( trim( (string) $url ) );
		$events = array_values( array_intersect( self::EVENTS, $events ) );
		if ( ! self::valid_url( $url ) ) {
			return new \WP_Error( 'wpcap_webhook_url', __( 'Bitte eine öffentlich erreichbare HTTPS-URL angeben.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! $events ) {
			return new \WP_Error( 'wpcap_webhook_events', __( 'Bitte mindestens ein Ereignis auswählen.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$secret = self::generate_secret();
		$now = current_time( 'mysql', true );
		$wpdb->insert( self::t( 'subscriptions' ), array( 'url' => $url, 'description' => mb_substr( sanitize_text_field( $description ), 0, 190 ), 'events' => implode( ',', $events ), 'secret' => $secret, 'include_pii' => $include_pii ? 1 : 0, 'status' => 'active', 'consecutive_failures' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new \WP_Error( 'wpcap_webhook_save', __( 'Der Webhook konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'webhook_subscription_created', 'webhook', $id, array( 'events' => $events, 'include_pii' => (bool) $include_pii ) );
		return array( 'subscription_id' => $id, 'secret' => $secret );
	}

	public static function subscription( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'subscriptions' ) . ' WHERE subscription_id=%d', absint( $id ) ) );
	}

	public static function subscriptions() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::t( 'subscriptions' ) . ' ORDER BY subscription_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function set_status( $id, $active ) {
		global $wpdb;
		$wpdb->update( self::t( 'subscriptions' ), array( 'status' => $active ? 'active' : 'disabled', 'disabled_reason' => $active ? '' : 'manual', 'consecutive_failures' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'subscription_id' => absint( $id ) ) );
		Audit::record( $active ? 'webhook_subscription_enabled' : 'webhook_subscription_disabled', 'webhook', absint( $id ), array() );
		return true;
	}

	public static function rotate_secret( $id ) {
		global $wpdb;
		$secret = self::generate_secret();
		$wpdb->update( self::t( 'subscriptions' ), array( 'secret' => $secret, 'updated_at' => current_time( 'mysql', true ) ), array( 'subscription_id' => absint( $id ) ) );
		Audit::record( 'webhook_secret_rotated', 'webhook', absint( $id ), array() );
		return $secret;
	}

	public static function delete_subscription( $id ) {
		global $wpdb;
		$wpdb->delete( self::t( 'deliveries' ), array( 'subscription_id' => absint( $id ) ) );
		$wpdb->delete( self::t( 'subscriptions' ), array( 'subscription_id' => absint( $id ) ) );
		Audit::record( 'webhook_subscription_deleted', 'webhook', absint( $id ), array() );
		return true;
	}

	/* ------------------------------------------------------------------ Emitting */

	/**
	 * Queues an event for every active subscription that selected it.
	 *
	 * @param string   $type    Event type (one of EVENTS).
	 * @param string   $dedupe  Idempotency discriminator; the same type+dedupe is delivered once per subscription.
	 * @param array    $object  data.object (IDs/status only).
	 * @param array    $pii     Fields added only for subscriptions with include_pii=1 (documented).
	 * @return int Number of queued deliveries.
	 */
	public static function emit( $type, $dedupe, array $object, array $pii = array() ) {
		global $wpdb;
		if ( ! in_array( $type, self::EVENTS, true ) ) { return 0; }
		$subs = $wpdb->get_results( $wpdb->prepare( 'SELECT subscription_id,events,include_pii FROM ' . self::t( 'subscriptions' ) . " WHERE status='active' AND FIND_IN_SET(%s,events)", $type ) );
		if ( ! $subs ) { return 0; }
		$event_id = 'evt_' . substr( hash( 'sha256', $type . '|' . $dedupe ), 0, 32 );
		$object = apply_filters( 'wpcap_webhook_payload_object', $object, $type );
		$created = time();
		$queued = 0;
		$now = current_time( 'mysql', true );
		foreach ( $subs as $sub ) {
			$data = $object;
			if ( (int) $sub->include_pii && $pii ) { $data = array_merge( $data, $pii ); }
			$payload = wp_json_encode( array( 'id' => $event_id, 'type' => $type, 'api_version' => self::API_VERSION, 'created' => $created, 'data' => array( 'object' => $data ) ) );
			$queued += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'deliveries' ) . " (subscription_id,event_id,event_type,payload,status,attempts,next_attempt_at,created_at,updated_at) VALUES (%d,%s,%s,%s,'pending',0,%s,%s,%s)", (int) $sub->subscription_id, $event_id, $type, $payload, $now, $now, $now ) );
		}
		if ( $queued ) { self::schedule_soon(); }
		return $queued;
	}

	/** Best-effort immediate processing via a single cron event. */
	public static function schedule_soon() {
		if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpcap_webhooks_dispatch' ) ) {
			wp_schedule_single_event( time() + 5, 'wpcap_webhooks_dispatch' );
		}
	}

	/* ------------------------------------------------------------------ Delivery */

	/** Processes due deliveries (cron: wpcap_process_jobs and wpcap_webhooks_dispatch). Returns attempts made. */
	public static function process( $limit = 20 ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		$s = self::t( 'subscriptions' );
		$limit = is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : 20;
		$wpdb->query( $wpdb->prepare( "UPDATE $d SET status='pending',locked_at=NULL WHERE status='delivering' AND locked_at<%s", gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
		$done = 0;
		for ( $i = 0; $i < $limit; $i++ ) {
			$now = current_time( 'mysql', true );
			$wpdb->query( 'START TRANSACTION' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.* FROM $d d INNER JOIN $s s ON s.subscription_id=d.subscription_id WHERE d.status='pending' AND s.status='active' AND d.next_attempt_at<=%s ORDER BY d.next_attempt_at,d.delivery_id LIMIT 1 FOR UPDATE", $now ) );
			if ( ! $row ) { $wpdb->query( 'COMMIT' ); break; }
			$wpdb->update( $d, array( 'status' => 'delivering', 'locked_at' => $now, 'updated_at' => $now ), array( 'delivery_id' => (int) $row->delivery_id ) );
			$wpdb->query( 'COMMIT' );
			self::attempt( $row );
			++$done;
		}
		return $done;
	}

	/** One HTTP attempt for a claimed delivery row. */
	private static function attempt( $row ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		$sub = self::subscription( $row->subscription_id );
		if ( ! $sub ) {
			$wpdb->update( $d, array( 'status' => 'cancelled', 'locked_at' => null ), array( 'delivery_id' => (int) $row->delivery_id ) );
			return;
		}
		$timestamp = time();
		$args = array(
			'timeout' => 10,
			'redirection' => 0,
			'headers' => array(
				'Content-Type' => 'application/json',
				'User-Agent' => 'WP-Pass-Captain-Webhooks/' . self::API_VERSION,
				'X-WPCAP-Event' => $row->event_type,
				'X-WPCAP-Event-Id' => $row->event_id,
				'X-WPCAP-Delivery' => (string) $row->delivery_id,
				'X-WPCAP-Signature' => self::sign( $row->payload, $sub->secret, $timestamp ),
			),
			'body' => $row->payload,
		);
		$response = function_exists( 'wp_safe_remote_post' ) ? wp_safe_remote_post( $sub->url, $args ) : wp_remote_post( $sub->url, $args );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$attempts = (int) $row->attempts + 1;
		$now = current_time( 'mysql', true );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update( $d, array( 'status' => 'succeeded', 'attempts' => $attempts, 'response_code' => $code, 'last_error' => '', 'locked_at' => null, 'delivered_at' => $now, 'updated_at' => $now ), array( 'delivery_id' => (int) $row->delivery_id ) );
			$wpdb->update( self::t( 'subscriptions' ), array( 'consecutive_failures' => 0, 'last_success_at' => $now ), array( 'subscription_id' => (int) $sub->subscription_id ) );
			return;
		}
		$error = is_wp_error( $response ) ? mb_substr( sanitize_text_field( $response->get_error_message() ), 0, 190 ) : 'HTTP ' . $code;
		$final = $attempts >= self::max_attempts();
		$wpdb->update( $d, array( 'status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'response_code' => $code, 'last_error' => $error, 'locked_at' => null, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts ) ), 'updated_at' => $now ), array( 'delivery_id' => (int) $row->delivery_id ) );
		$failures = (int) $sub->consecutive_failures + 1;
		$update = array( 'consecutive_failures' => $failures, 'updated_at' => $now );
		if ( $failures >= self::disable_after() && 'active' === $sub->status ) {
			$update['status'] = 'disabled';
			$update['disabled_reason'] = 'failures';
			Audit::record( 'webhook_subscription_auto_disabled', 'webhook', (int) $sub->subscription_id, array( 'consecutive_failures' => $failures ) );
			if ( function_exists( 'get_option' ) && is_email( get_option( 'admin_email' ) ) ) {
				/* translators: 1: webhook id, 2: failure count */
				wp_mail( get_option( 'admin_email' ), __( 'WP Pass Captain: Webhook deaktiviert', 'wp-pass-captain' ), sprintf( __( 'Der Webhook #%1$d wurde nach %2$d fehlgeschlagenen Zustellversuchen in Folge deaktiviert. Ausstehende Zustellungen werden nach der Reaktivierung fortgesetzt.', 'wp-pass-captain' ), (int) $sub->subscription_id, $failures ) );
			}
		}
		$wpdb->update( self::t( 'subscriptions' ), $update, array( 'subscription_id' => (int) $sub->subscription_id ) );
	}

	/** Manual redelivery: the delivery is queued again with a fresh attempt budget. */
	public static function redeliver( $delivery_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'deliveries' ) . " SET status='pending',attempts=0,next_attempt_at=%s,locked_at=NULL,updated_at=%s WHERE delivery_id=%d AND status<>'delivering'", $now, $now, absint( $delivery_id ) ) );
		if ( ! $updated ) { return new \WP_Error( 'wpcap_webhook_redeliver', __( 'Zustellung nicht gefunden oder gerade in Bearbeitung.', 'wp-pass-captain' ), array( 'status' => 404 ) ); }
		Audit::record( 'webhook_redelivery', 'webhook', absint( $delivery_id ), array() );
		self::schedule_soon();
		return true;
	}

	public static function deliveries( $subscription_id = 0, $limit = 50 ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		if ( $subscription_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT delivery_id,subscription_id,event_id,event_type,status,attempts,response_code,last_error,next_attempt_at,delivered_at,created_at FROM $d WHERE subscription_id=%d ORDER BY delivery_id DESC LIMIT %d", absint( $subscription_id ), absint( $limit ) ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT delivery_id,subscription_id,event_id,event_type,status,attempts,response_code,last_error,next_attempt_at,delivered_at,created_at FROM $d ORDER BY delivery_id DESC LIMIT %d", absint( $limit ) ) );
	}
}
