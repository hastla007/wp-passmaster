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
	const EVENTS = array( 'order.paid', 'order.refunded', 'order.cancelled', 'ticket.issued', 'ticket.checked_in', 'ticket.revoked', 'ticket.exchanged', 'occurrence.rescheduled', 'occurrence.cancelled', 'ticket.transferred', 'ticket.checked_out', 'attendance.confirmed', 'occurrence.changed', 'event.cancelled', 'refund.reversed', 'consent.granted', 'consent.withdrawn' );

	/**
	 * Payload schema version per event type (A674, field schema_version of every payload). A minor step
	 * only adds fields; order.refunded 1.1 added refund.kind, refund.lines and refund.source (A675).
	 */
	const SCHEMA_VERSIONS = array(
		'order.paid' => '1.0',
		'order.refunded' => '1.1',
		'order.cancelled' => '1.0',
		'ticket.issued' => '1.0',
		'ticket.checked_in' => '1.0',
		'ticket.revoked' => '1.0',
		'ticket.exchanged' => '1.0',
		'occurrence.rescheduled' => '1.0',
		'occurrence.cancelled' => '1.0',
		'ticket.transferred' => '1.0',
		'ticket.checked_out' => '1.0',
		'attendance.confirmed' => '1.0',
		'occurrence.changed' => '1.0',
		'event.cancelled' => '1.0',
		'refund.reversed' => '1.0',
		'consent.granted' => '1.0', // A612: consent records of participant fields.
		'consent.withdrawn' => '1.0',
	);
	const MAX_ATTEMPTS = 8;
	const DISABLE_AFTER = 20;
	const BASE_DELAY = 60;
	const MAX_DELAY = 21600;

	/** Opt-in personal data fields; removed from stored payloads once a delivery is final. */
	const PII_FIELDS = array( 'customer_email', 'attendee_email' );

	/** Days final deliveries are kept in the log (setting webhooks.retention_days, default 30). */
	public static function retention_days() {
		$settings = (array) \PassCaptain\Settings::get( 'webhooks' );
		return max( 1, min( 365, (int) ( $settings['retention_days'] ?? 30 ) ) );
	}

	/** Removes PII fields from a stored payload. Returns [payload, removed?]. */
	public static function strip_pii( $payload ) {
		$data = json_decode( (string) $payload, true );
		if ( ! is_array( $data ) || ! isset( $data['data']['object'] ) || ! is_array( $data['data']['object'] ) ) { return array( (string) $payload, false ); }
		$removed = false;
		foreach ( self::PII_FIELDS as $field ) {
			if ( array_key_exists( $field, $data['data']['object'] ) ) { unset( $data['data']['object'][ $field ] ); $removed = true; }
		}
		return array( $removed ? (string) wp_json_encode( $data ) : (string) $payload, $removed );
	}

	/** Current opt-in PII for a payload object (erased/anonymised addresses are left out). */
	public static function current_pii( array $object ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = (string) ( $object['id'] ?? '' );
		$pii = array();
		if ( 'order' === ( $object['object'] ?? '' ) ) {
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT customer_email FROM %i WHERE public_id=%s', $p . 'wpcap_orders', $id ) );
			if ( is_email( $email ) && '@invalid.example' !== substr( $email, -16 ) ) { $pii['customer_email'] = $email; }
		} elseif ( 'ticket' === ( $object['object'] ?? '' ) ) {
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT attendee_email FROM %i WHERE public_id=%s', $p . 'wpcap_tickets', $id ) );
			if ( is_email( $email ) ) { $pii['attendee_email'] = $email; }
		}
		return $pii;
	}

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

	/**
	 * Only absolute https URLs to public hosts (form check without DNS; every delivery is checked
	 * again with DNS and pinned by OutboundHttp).
	 */
	public static function valid_url( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '~^https://[^\s/?#]+~i', $url ) || false !== strpos( $url, '@' ) ) { return false; }
		if ( is_wp_error( \PassCaptain\OutboundHttp::check( $url, array( 'context' => 'webhook', 'resolve' => false ) ) ) ) { return false; }
		return function_exists( 'wp_http_validate_url' ) ? false !== wp_http_validate_url( $url ) : true;
	}

	/* ------------------------------------------------------------------ Subscriptions */

	/** @return array|\WP_Error ['subscription_id' => int, 'secret' => string] */
	public static function create_subscription( $url, array $events, $include_pii = false, $description = '' ) {
		global $wpdb;
		$url = esc_url_raw( trim( (string) $url ) );
		$events = array_values( array_intersect( self::EVENTS, $events ) );
		if ( ! self::valid_url( $url ) || is_wp_error( \PassCaptain\Security\OutboundHttp::validate_url( $url ) ) ) {
			return new \WP_Error( 'wpcap_webhook_url', __( 'Bitte eine öffentlich erreichbare HTTPS-URL angeben.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! $events ) {
			return new \WP_Error( 'wpcap_webhook_events', __( 'Bitte mindestens ein Ereignis auswählen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$secret = self::generate_secret();
		$now = current_time( 'mysql', true );
		$wpdb->insert( self::t( 'subscriptions' ), array( 'url' => $url, 'description' => mb_substr( sanitize_text_field( $description ), 0, 190 ), 'events' => implode( ',', $events ), 'secret' => \PassCaptain\Security\Vault::encrypt( $secret, 'webhook_secret' ), 'include_pii' => $include_pii ? 1 : 0, 'status' => 'active', 'consecutive_failures' => 0, 'created_at' => $now, 'updated_at' => $now ) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new \WP_Error( 'wpcap_webhook_save', __( 'Der Webhook konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'webhook_subscription_created', 'webhook', $id, array( 'events' => $events, 'include_pii' => (bool) $include_pii ) );
		return array( 'subscription_id' => $id, 'secret' => $secret );
	}

	/** Subscription row; ->secret is the decrypted signing secret ('' when it cannot be decrypted). */
	public static function subscription( $id ) {
		global $wpdb;
		return self::with_plain_secret( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id=%d', self::t( 'subscriptions' ), absint( $id ) ) ) );
	}

	public static function subscriptions() {
		global $wpdb;
		return array_map( array( __CLASS__, 'with_plain_secret' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY subscription_id', self::t( 'subscriptions' ) ) ) );
	}

	private static function with_plain_secret( $row ) {
		if ( $row && isset( $row->secret ) ) {
			$row->secret = (string) \PassCaptain\Security\Vault::decrypt( (string) $row->secret, 'webhook_secret' );
		}
		return $row;
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
		$wpdb->update( self::t( 'subscriptions' ), array( 'secret' => \PassCaptain\Security\Vault::encrypt( $secret, 'webhook_secret' ), 'updated_at' => current_time( 'mysql', true ) ), array( 'subscription_id' => absint( $id ) ) );
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
		$subs = $wpdb->get_results( $wpdb->prepare( "SELECT subscription_id,events,include_pii FROM %i WHERE status='active' AND FIND_IN_SET(%s,events)", self::t( 'subscriptions' ), $type ) );
		if ( ! $subs ) { return 0; }
		// Same id as the workflow outbox event of the same business event (Workflow\Outbox::event_id()).
		$event_id = \PassCaptain\Workflow\Outbox::event_id( $type, $dedupe );
		$object = apply_filters( 'wpcap_webhook_payload_object', $object, $type );
		$created = time();
		$queued = 0;
		$now = current_time( 'mysql', true );
		foreach ( $subs as $sub ) {
			$data = $object;
			if ( (int) $sub->include_pii && $pii ) { $data = array_merge( $data, $pii ); }
			$payload = wp_json_encode( array( 'id' => $event_id, 'type' => $type, 'api_version' => self::API_VERSION, 'schema_version' => self::SCHEMA_VERSIONS[ $type ], 'created' => $created, 'data' => array( 'object' => $data ) ) );
			$queued += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (subscription_id,event_id,event_type,payload,status,attempts,next_attempt_at,created_at,updated_at) VALUES (%d,%s,%s,%s,'pending',0,%s,%s,%s)", self::t( 'deliveries' ), (int) $sub->subscription_id, $event_id, $type, $payload, $now, $now, $now ) );
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
		// Claims of crashed workers go back to the queue; attempts are counted at claim time, so a
		// delivery that kills the worker every time ends as failed instead of being retried forever.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status=IF(attempts>=%d,'failed','pending'),locked_at=NULL WHERE status='delivering' AND locked_at<%s", $d, self::max_attempts(), gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
		$done = 0;
		// Shared cron time budget: checked before each claim, so a claimed delivery is always attempted.
		$deadline = \PassCaptain\Jobs\TimeBudget::deadline();
		$drained = false;
		for ( $i = 0; $i < $limit; $i++ ) {
			if ( \PassCaptain\Jobs\TimeBudget::exhausted( $deadline ) ) { break; }
			$now = current_time( 'mysql', true );
			// The HTTP request is only sent once the claim is durable: transaction started, exactly this row
			// moved from pending to delivering (attempt counted), commit succeeded. Otherwise a second worker
			// could deliver the same event again. On a database error the run ends; the next run retries.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { break; }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.* FROM %i d INNER JOIN %i s ON s.subscription_id=d.subscription_id WHERE d.status='pending' AND s.status='active' AND d.next_attempt_at<=%s ORDER BY d.next_attempt_at,d.delivery_id LIMIT 1 FOR UPDATE", $d, $s, $now ) );
			if ( ! $row ) {
				if ( '' !== (string) $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); break; }
				$wpdb->query( 'COMMIT' );
				$drained = true;
				break;
			}
			$claimed = $wpdb->update( $d, array( 'status' => 'delivering', 'attempts' => (int) $row->attempts + 1, 'locked_at' => $now, 'updated_at' => $now ), array( 'delivery_id' => (int) $row->delivery_id, 'status' => 'pending' ) );
			if ( false === $claimed ) { $wpdb->query( 'ROLLBACK' ); break; }
			if ( 1 !== (int) $claimed ) { $wpdb->query( 'ROLLBACK' ); continue; }
			if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); break; }
			\PassCaptain\Jobs\TimeBudget::claimed( $d, 'delivery_id', (int) $row->delivery_id, 'delivering', 'pending', 'failed', self::max_attempts(), array( 'due' => 'next_attempt_at', 'error' => 'last_error' ) );
			try {
				self::attempt( $row );
			} catch ( \Throwable $e ) {
				// A throwing filter/listener must not stall the delivery queue: retried with backoff.
				$attempts = (int) $row->attempts + 1;
				$error = \PassCaptain\Jobs::exception_error( $e, $row )->get_error_message();
				$wpdb->update( $d, array( 'status' => $attempts >= self::max_attempts() ? 'failed' : 'pending', 'attempts' => $attempts, 'last_error' => \PassCaptain\Text::cut( $error, 190 ), 'locked_at' => null, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts ) ), 'updated_at' => current_time( 'mysql', true ) ), array( 'delivery_id' => (int) $row->delivery_id ) );
			}
			\PassCaptain\Jobs\TimeBudget::finished( $d, (int) $row->delivery_id );
			++$done;
		}
		if ( ! $drained && $done > 0 ) { self::schedule_soon(); } // Backlog left (budget or limit): continue soon.
		\PassCaptain\Ops\Heartbeat::beat( 'webhooks', $done ); // System status: last completed worker run.
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
			'method' => 'POST',
			'timeout' => 10,
			'headers' => array(
				'Content-Type' => 'application/json',
				'User-Agent' => 'Pass-Captain-Webhooks/' . self::API_VERSION,
				'X-WPCAP-Event' => $row->event_type,
				'X-WPCAP-Event-Id' => $row->event_id,
				'X-WPCAP-Delivery' => (string) $row->delivery_id,
				'X-WPCAP-Signature' => self::sign( $row->payload, $sub->secret, $timestamp ),
			),
			'body' => $row->payload,
		);
		// HTTPS, public address checked after DNS and pinned, no redirects, 64 KB response (A202);
		// re-validated per attempt: the host's DNS may have changed to an internal address since creation.
		$response = '' === (string) $sub->secret
			? new \WP_Error( 'wpcap_webhook_secret_unavailable', __( 'Das Signing-Secret kann nicht entschlüsselt werden; bitte erneuern.', 'pass-captain' ) )
			: \PassCaptain\OutboundHttp::request( $sub->url, $args, array( 'context' => 'webhook', 'max_bytes' => 65536 ) );
		$code = is_wp_error( $response ) ? (int) ( ( (array) $response->get_error_data() )['status'] ?? 0 ) : (int) wp_remote_retrieve_response_code( $response );
		$attempts = (int) $row->attempts + 1;
		$now = current_time( 'mysql', true );
		// Final deliveries keep no personal data in the log.
		list( $stripped, $had_pii ) = self::strip_pii( $row->payload );
		if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
			$wpdb->update( $d, array( 'status' => 'succeeded', 'attempts' => $attempts, 'response_code' => $code, 'last_error' => '', 'locked_at' => null, 'delivered_at' => $now, 'updated_at' => $now, 'payload' => $stripped, 'pii_purged' => $had_pii ? 1 : (int) $row->pii_purged ), array( 'delivery_id' => (int) $row->delivery_id ) );
			$wpdb->update( self::t( 'subscriptions' ), array( 'consecutive_failures' => 0, 'last_success_at' => $now ), array( 'subscription_id' => (int) $sub->subscription_id ) );
			return;
		}
		$error = is_wp_error( $response ) ? \PassCaptain\Operations\Queues::safe_error( sanitize_text_field( \PassCaptain\OutboundHttp::redact( $response->get_error_message(), array( $sub->secret ) ) ) ) : 'HTTP ' . $code;
		$final = $attempts >= self::max_attempts();
		$update_row = array( 'status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'response_code' => $code, 'last_error' => $error, 'locked_at' => null, 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempts ) ), 'updated_at' => $now );
		if ( $final && $had_pii ) { $update_row['payload'] = $stripped; $update_row['pii_purged'] = 1; }
		$wpdb->update( $d, $update_row, array( 'delivery_id' => (int) $row->delivery_id ) );
		$failures = (int) $sub->consecutive_failures + 1;
		$update = array( 'consecutive_failures' => $failures, 'updated_at' => $now );
		if ( $failures >= self::disable_after() && 'active' === $sub->status ) {
			$update['status'] = 'disabled';
			$update['disabled_reason'] = 'failures';
			Audit::record( 'webhook_subscription_auto_disabled', 'webhook', (int) $sub->subscription_id, array( 'consecutive_failures' => $failures ) );
			if ( function_exists( 'get_option' ) ) {
				/* translators: 1: webhook id, 2: failure count */
				\PassCaptain\Alerts::raise( 'webhook_disabled', __( 'Pass Captain: Webhook deaktiviert', 'pass-captain' ), sprintf( __( 'Der Webhook #%1$d wurde nach %2$d fehlgeschlagenen Zustellversuchen in Folge deaktiviert. Ausstehende Zustellungen werden nach der Reaktivierung fortgesetzt.', 'pass-captain' ), (int) $sub->subscription_id, $failures ) );
			}
		}
		$wpdb->update( self::t( 'subscriptions' ), $update, array( 'subscription_id' => (int) $sub->subscription_id ) );
	}

	/**
	 * Manual redelivery: the delivery is queued again with a fresh attempt budget. Personal data
	 * removed from the log is rebuilt from current data (erased addresses stay out).
	 */
	public static function redeliver( $delivery_id ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE delivery_id=%d AND status<>'delivering'", $d, absint( $delivery_id ) ) );
		$sub = $row ? self::subscription( $row->subscription_id ) : null;
		if ( ! $row || ! $sub ) { return new \WP_Error( 'wpcap_webhook_redeliver', __( 'Zustellung nicht gefunden oder gerade in Bearbeitung.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$payload = $row->payload;
		$data = json_decode( (string) $payload, true );
		if ( ! is_array( $data ) || ! isset( $data['data']['object'] ) ) {
			return new \WP_Error( 'wpcap_webhook_redeliver_purged', __( 'Der Inhalt dieser Zustellung ist nicht mehr vorhanden.', 'pass-captain' ), array( 'status' => 410 ) );
		}
		list( $payload ) = self::strip_pii( $payload );
		if ( (int) $sub->include_pii ) {
			$data = json_decode( $payload, true );
			$data['data']['object'] = array_merge( $data['data']['object'], self::current_pii( $data['data']['object'] ) );
			$payload = (string) wp_json_encode( $data );
		}
		$now = current_time( 'mysql', true );
		$wpdb->update( $d, array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'locked_at' => null, 'updated_at' => $now, 'payload' => $payload, 'pii_purged' => 0 ), array( 'delivery_id' => (int) $row->delivery_id ) );
		Audit::record( 'webhook_redelivery', 'webhook', absint( $delivery_id ), array() );
		self::schedule_soon();
		return true;
	}

	/** Rows per DELETE of purge_old(). */
	const PURGE_BATCH = 1000;

	/** Seconds one purge_old() run may take (filter wpcap_webhook_purge_budget). */
	const PURGE_BUDGET = 5.0;

	/**
	 * Deletes final deliveries older than the retention period (hourly cron `wpcap_cleanup_reservations`,
	 * A437): batches of PURGE_BATCH rows on the index cleanup (status, updated_at) within a time budget;
	 * the next run continues. Returns deleted rows.
	 *
	 * @param float|null $budget Seconds (default: filter wpcap_webhook_purge_budget, 5).
	 * @param int|null   $now    Reference time (UNIX, tests).
	 */
	public static function purge_old( $budget = null, $now = null ) {
		global $wpdb;
		$budget = null === $budget || '' === $budget ? (float) apply_filters( 'wpcap_webhook_purge_budget', self::PURGE_BUDGET ) : (float) $budget;
		$deadline = microtime( true ) + max( 0.0, $budget );
		$cutoff = gmdate( 'Y-m-d H:i:s', ( null === $now || '' === $now ? time() : (int) $now ) - self::retention_days() * DAY_IN_SECONDS );
		// Pending deliveries of inactive subscriptions first (cancelled without personal data, then deleted below).
		self::cancel_stale( $cutoff );
		$deleted = 0;
		do {
			// The ids come from an index range on KEY cleanup (FORCE INDEX: on a small log MariaDB 11.8 would
			// rather scan the whole table, and DELETE takes no index hint); the DELETE then goes by primary key.
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT delivery_id FROM ' . self::t( 'deliveries' ) . " FORCE INDEX (cleanup) WHERE status IN ('succeeded','failed','cancelled') AND updated_at<%s LIMIT %d", $cutoff, self::PURGE_BATCH ) );
			$ids = array_map( 'intval', (array) $ids );
			$found = count( $ids );
			if ( ! $found ) { break; }
			$batch = $wpdb->query( 'DELETE FROM ' . self::t( 'deliveries' ) . ' WHERE delivery_id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- log cleanup, integer ids.
			$batch = false === $batch ? 0 : (int) $batch;
			$deleted += $batch;
		} while ( self::PURGE_BATCH === $found && microtime( true ) < $deadline );
		return $deleted;
	}

	/**
	 * Pending deliveries of a disabled or deleted subscription are never attempted and would keep
	 * their payload (possibly with e-mail addresses) forever: after the retention period they are
	 * cancelled without personal data and then deleted like other final deliveries. A subscription
	 * reactivated within the period still delivers them.
	 *
	 * @return int Cancelled deliveries.
	 */
	public static function cancel_stale( $cutoff ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT d.delivery_id,d.payload,d.pii_purged FROM %i d LEFT JOIN %i s ON s.subscription_id=d.subscription_id WHERE d.status='pending' AND d.created_at<%s AND (s.subscription_id IS NULL OR s.status<>'active') LIMIT 500", $d, self::t( 'subscriptions' ), (string) $cutoff ) );
		$now = current_time( 'mysql', true );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			list( $payload, $had_pii ) = self::strip_pii( $row->payload );
			$count += (int) $wpdb->update( $d, array( 'status' => 'cancelled', 'last_error' => 'subscription_inactive', 'payload' => $payload, 'pii_purged' => $had_pii ? 1 : (int) $row->pii_purged, 'locked_at' => null, 'updated_at' => $now ), array( 'delivery_id' => (int) $row->delivery_id, 'status' => 'pending' ) );
		}
		return $count;
	}

	/** Privacy eraser: removes the e-mail address from all logged and pending payloads. */
	public static function erase_email( $email ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		$email = sanitize_email( $email );
		if ( '' === $email ) { return 0; }
		$needle = substr( (string) wp_json_encode( $email ), 1, -1 );
		$like = '%' . ( method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $needle ) : addcslashes( $needle, '_%\\' ) ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT delivery_id,payload FROM %i WHERE payload LIKE %s AND status<>'delivering'", $d, $like ) );
		$count = 0;
		foreach ( $rows as $row ) {
			list( $payload, $removed ) = self::strip_pii( $row->payload );
			if ( $removed ) {
				$wpdb->update( $d, array( 'payload' => $payload, 'pii_purged' => 1 ), array( 'delivery_id' => (int) $row->delivery_id ) );
				++$count;
			}
		}
		return $count;
	}

	public static function deliveries( $subscription_id = 0, $limit = 50 ) {
		global $wpdb;
		$d = self::t( 'deliveries' );
		if ( $subscription_id ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_id,subscription_id,event_id,event_type,status,attempts,response_code,last_error,next_attempt_at,delivered_at,created_at FROM %i WHERE subscription_id=%d ORDER BY delivery_id DESC LIMIT %d', $d, absint( $subscription_id ), absint( $limit ) ) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_id,subscription_id,event_id,event_type,status,attempts,response_code,last_error,next_attempt_at,delivered_at,created_at FROM %i ORDER BY delivery_id DESC LIMIT %d', $d, absint( $limit ) ) );
	}
}
