<?php
/**
 * Stripe adapter: hosted Stripe Checkout Sessions, signed webhooks and refunds.
 *
 * No card data touches the site. Payments are confirmed exclusively through verified webhooks that
 * are processed by Webhooks::process(); this class only talks to the Stripe API, verifies signatures
 * and normalizes events.
 */
namespace PassCaptain\Payments;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StripeGateway implements GatewayInterface {
	const API = 'https://api.stripe.com/v1/';

	/** Maximum age (and clock skew) of a webhook signature in seconds. */
	const TOLERANCE = 300;

	/**
	 * Currencies Stripe expects as "two-decimal" amounts although they have no minor unit in the
	 * plugin (ISO 4217): the plugin amount has to be multiplied by 100 (and be divisible by 100).
	 */
	const SCALED_ZERO_DECIMAL = array( 'ISK', 'UGX' );

	public function id() { return 'stripe'; }

	public function title() { return __( 'Kreditkarte & weitere Zahlarten (Stripe)', 'wp-pass-captain' ); }

	public function is_available( $currency ) {
		// Without the webhook secret payments could never be confirmed, so both are required.
		return '' !== Settings::secret( 'stripe_secret_key' )
			&& '' !== Settings::secret( 'stripe_webhook_secret' )
			&& 1 === preg_match( '/^[A-Za-z]{3}$/', (string) $currency );
	}

	public function supports_refunds() { return true; }

	/** Creates (or reuses) a Checkout Session for exactly the order total. */
	public function create_payment( array $order, array $items = array() ) {
		$public_id = sanitize_text_field( (string) ( $order['public_id'] ?? '' ) );
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		$total = (int) ( $order['total_minor'] ?? 0 );
		if ( '' === $public_id || $total <= 0 || 3 !== strlen( $currency ) ) {
			return new \WP_Error( 'wpcap_stripe_order', __( 'Die Bestellung kann nicht mit Stripe bezahlt werden.', 'wp-pass-captain' ) );
		}

		// A retried checkout (same idempotency key) reuses a still open session instead of creating a second one.
		$existing = (string) ( $order['provider_reference'] ?? '' );
		$key_suffix = '';
		if ( 0 === strpos( $existing, 'cs_' ) ) {
			$session = $this->api( 'GET', 'checkout/sessions/' . rawurlencode( $existing ) );
			if ( ! is_wp_error( $session ) && 'open' === ( $session['status'] ?? '' ) && ! empty( $session['url'] ) ) {
				return $this->payment_result( $session );
			}
			$key_suffix = '-' . substr( hash( 'sha256', $existing ), 0, 12 );
		}

		$amount = self::to_stripe_amount( $total, $currency );

		$params = array(
			'mode' => 'payment',
			'currency' => strtolower( $currency ),
			'success_url' => self::return_url( 'success', $public_id ),
			'cancel_url' => self::return_url( 'cancel', $public_id ),
			'client_reference_id' => $public_id,
			'expires_at' => self::session_expiry( $order ),
			// One line with the exact order total: discounts and rounding can never make Stripe charge a different amount.
			'line_items' => array(
				array(
					'quantity' => 1,
					'price_data' => array(
						'currency' => strtolower( $currency ),
						'unit_amount' => $amount,
						'product_data' => array(
							'name' => self::summary( $items ),
							/* translators: %s: order number */
							'description' => sprintf( __( 'Bestellung %s', 'wp-pass-captain' ), $public_id ),
						),
					),
				),
			),
			'metadata' => array( 'wpcap_order' => $public_id ),
			'payment_intent_data' => array( 'metadata' => array( 'wpcap_order' => $public_id ) ),
		);
		$email = sanitize_email( (string) ( $order['customer_email'] ?? '' ) );
		if ( $email && is_email( $email ) ) { $params['customer_email'] = $email; }
		$params = apply_filters( 'wpcap_stripe_session_params', $params, $order, $items );

		$session = $this->api( 'POST', 'checkout/sessions', $params, 'wpcap-session-' . $public_id . $key_suffix );
		if ( is_wp_error( $session ) ) { return $session; }
		if ( empty( $session['id'] ) || empty( $session['url'] ) ) {
			return new \WP_Error( 'wpcap_stripe_response', __( 'Unerwartete Antwort von Stripe.', 'wp-pass-captain' ) );
		}
		return $this->payment_result( $session );
	}

	/** Verifies the Stripe-Signature header and maps the event. */
	public function verify_webhook( \WP_REST_Request $request ) {
		$secret = Settings::secret( 'stripe_webhook_secret' );
		$payload = (string) $request->get_body();
		if ( '' === $secret || ! self::signature_valid( $payload, (string) $request->get_header( 'stripe-signature' ), $secret ) ) {
			return new \WP_Error( 'wpcap_stripe_signature', __( 'Ungültige Stripe-Signatur.', 'wp-pass-captain' ), array( 'status' => 401 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) || ! isset( $event['data']['object'] ) || ! is_array( $event['data']['object'] ) ) {
			return new \WP_Error( 'wpcap_stripe_payload', __( 'Ungültige Stripe-Nachricht.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		return $this->normalize( $event );
	}

	/** Refunds through the order's PaymentIntent. */
	public function refund( array $order, $amount_minor, $idempotency_key ) {
		$reference = (string) ( $order['provider_reference'] ?? '' );
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		if ( 0 === strpos( $reference, 'cs_' ) ) {
			$session = $this->api( 'GET', 'checkout/sessions/' . rawurlencode( $reference ) );
			if ( is_wp_error( $session ) ) { return $session; }
			$reference = self::id_of( $session['payment_intent'] ?? '' );
		}
		$params = array();
		if ( 0 === strpos( $reference, 'pi_' ) ) {
			$params['payment_intent'] = $reference;
		} elseif ( 0 === strpos( $reference, 'ch_' ) || 0 === strpos( $reference, 'py_' ) ) {
			$params['charge'] = $reference;
		} else {
			return new \WP_Error( 'wpcap_stripe_refund_reference', __( 'Für diese Bestellung ist keine Stripe-Zahlung bekannt.', 'wp-pass-captain' ) );
		}
		$amount = self::to_stripe_amount( (int) $amount_minor, $currency );
		$params['amount'] = $amount;
		// Marks refunds made by the plugin so their charge.refunded webhook is not treated as a dashboard refund.
		$params['metadata'] = array( 'wpcap_order' => (string) ( $order['public_id'] ?? '' ), 'wpcap_refund' => (string) $idempotency_key );
		$refund = $this->api( 'POST', 'refunds', $params, (string) $idempotency_key );
		if ( is_wp_error( $refund ) ) { return $refund; }
		if ( empty( $refund['id'] ) || in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ) {
			return new \WP_Error( 'wpcap_stripe_refund_failed', __( 'Stripe hat die Erstattung abgelehnt.', 'wp-pass-captain' ) );
		}
		return (string) $refund['id'];
	}

	/* ------------------------------------------------------------------ */

	/** Checks a `t=…,v1=…[,v1=…]` header against HMAC-SHA256("{t}.{payload}"). */
	public static function signature_valid( $payload, $header, $secret, $now = null ) {
		$timestamp = 0;
		$signatures = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) { continue; }
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) { $timestamp = (int) $pair[1]; }
			if ( 'v1' === $pair[0] ) { $signatures[] = $pair[1]; }
		}
		if ( ! $timestamp || ! $signatures || abs( ( $now ?? time() ) - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		$valid = false;
		foreach ( $signatures as $signature ) {
			// No early exit: compare all candidates in constant time.
			$valid = hash_equals( $expected, (string) $signature ) || $valid;
		}
		return $valid;
	}

	/** Maps a verified Stripe event to the normalized structure. */
	private function normalize( array $event ) {
		$object = $event['data']['object'];
		$base = array(
			'event_id' => (string) $event['id'],
			'type' => 'ignored',
			'order_public_id' => '',
			'provider_reference' => '',
			'amount_minor' => 0,
			'currency' => strtoupper( (string) ( $object['currency'] ?? '' ) ),
		);
		$type = (string) $event['type'];
		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
			case 'checkout.session.async_payment_failed':
			case 'checkout.session.expired':
				$base['order_public_id'] = (string) ( $object['metadata']['wpcap_order'] ?? ( $object['client_reference_id'] ?? '' ) );
				$base['amount_minor'] = self::from_stripe_amount( (int) ( $object['amount_total'] ?? 0 ), $base['currency'] );
				$intent = self::id_of( $object['payment_intent'] ?? '' );
				$base['provider_reference'] = '' !== $intent ? $intent : (string) ( $object['id'] ?? '' );
				if ( 'checkout.session.async_payment_failed' === $type || 'checkout.session.expired' === $type ) {
					$base['type'] = 'failed';
				} elseif ( 'paid' === ( $object['payment_status'] ?? '' ) ) {
					// "completed" with payment_status "unpaid" = delayed method (SEPA etc.); wait for async_payment_succeeded.
					$base['type'] = 'paid';
				}
				return $base;

			case 'charge.refunded':
				return $this->normalize_refund( $base, $object );
		}
		return $base;
	}

	/**
	 * charge.refunded carries the cumulative refunded amount. A fully refunded charge is reported as
	 * a refund of its total. For partial refunds the latest refund is inspected: refunds created by
	 * this plugin (metadata wpcap_refund) are already booked by Refunds and are ignored; others
	 * (e.g. Stripe dashboard) are reported with the amount of that single refund.
	 */
	private function normalize_refund( array $base, array $charge ) {
		$base['provider_reference'] = (string) ( $charge['id'] ?? '' );
		$public_id = (string) ( $charge['metadata']['wpcap_order'] ?? '' );
		$intent = self::id_of( $charge['payment_intent'] ?? '' );
		if ( '' === $public_id && '' !== $intent ) {
			$pi = $this->api( 'GET', 'payment_intents/' . rawurlencode( $intent ) );
			if ( is_wp_error( $pi ) ) { return self::retry_error( $pi ); }
			$public_id = (string) ( $pi['metadata']['wpcap_order'] ?? '' );
		}
		if ( '' === $public_id ) { return $base; }
		$base['order_public_id'] = $public_id;
		if ( ! empty( $charge['refunded'] ) ) {
			$base['type'] = 'refunded';
			$base['amount_minor'] = self::from_stripe_amount( (int) ( $charge['amount_refunded'] ?? 0 ), $base['currency'] );
			return $base;
		}
		$latest = $charge['refunds']['data'][0] ?? null;
		if ( ! is_array( $latest ) ) {
			$list = $this->api( 'GET', 'refunds?' . http_build_query( array( 'charge' => (string) $charge['id'], 'limit' => 1 ) ) );
			if ( is_wp_error( $list ) ) { return self::retry_error( $list ); }
			$latest = $list['data'][0] ?? null;
		}
		if ( ! is_array( $latest ) || ! empty( $latest['metadata']['wpcap_refund'] ) ) {
			return $base;
		}
		$base['type'] = 'refunded';
		$base['amount_minor'] = self::from_stripe_amount( (int) ( $latest['amount'] ?? 0 ), $base['currency'] );
		return $base;
	}

	/** Stripe API call; returns the decoded body or WP_Error with Stripe's message. */
	private function api( $method, $path, array $params = array(), $idempotency_key = '' ) {
		$secret = Settings::secret( 'stripe_secret_key' );
		if ( '' === $secret ) {
			return new \WP_Error( 'wpcap_stripe_config', __( 'Stripe ist nicht konfiguriert.', 'wp-pass-captain' ) );
		}
		$args = array(
			'method' => $method,
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
		);
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body'] = http_build_query( $params, '', '&' );
			if ( '' !== $idempotency_key ) { $args['headers']['Idempotency-Key'] = substr( $idempotency_key, 0, 255 ); }
			$response = wp_remote_post( self::API . $path, $args );
		} else {
			$response = wp_remote_get( self::API . $path, $args );
		}
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'wpcap_stripe_http', sprintf( __( 'Stripe ist nicht erreichbar: %s', 'wp-pass-captain' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) && ! empty( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $code;
			/* translators: %s: error message from Stripe */
			return new \WP_Error( 'wpcap_stripe_api', sprintf( __( 'Stripe-Fehler: %s', 'wp-pass-captain' ), $message ), array( 'status' => $code ) );
		}
		return $body;
	}

	private function payment_result( array $session ) {
		return array(
			'provider' => 'stripe',
			'status' => 'pending',
			'redirect_url' => esc_url_raw( (string) $session['url'] ),
			'provider_reference' => (string) $session['id'],
		);
	}

	/** API failure while normalizing a verified event: let Stripe retry. */
	private static function retry_error( \WP_Error $error ) {
		return new \WP_Error( 'wpcap_stripe_lookup', $error->get_error_message(), array( 'status' => 503 ) );
	}

	/** Expanded objects or plain ids. */
	private static function id_of( $value ) {
		return is_array( $value ) ? (string) ( $value['id'] ?? '' ) : (string) $value;
	}

	/**
	 * Stripe requires 30 min – 24 h. Derived from the order creation time so that a concurrent
	 * retry sends identical parameters for the same Idempotency-Key.
	 */
	private static function session_expiry( array $order ) {
		$created = ! empty( $order['created_at'] ) ? (int) strtotime( $order['created_at'] . ' UTC' ) : 0;
		$expires = $created ? $created + 40 * MINUTE_IN_SECONDS : 0;
		if ( $expires < time() + 31 * MINUTE_IN_SECONDS ) {
			$expires = (int) ( ceil( ( time() + 31 * MINUTE_IN_SECONDS ) / 60 ) * 60 );
		}
		return $expires;
	}

	/** Line item name such as "Konzert – Standard × 2". */
	private static function summary( array $items ) {
		$parts = array();
		foreach ( $items as $item ) {
			$name = trim( (string) ( $item['event_name'] ?? '' ) . ' – ' . (string) ( $item['name'] ?? '' ), ' –' );
			$parts[] = $name . ' × ' . (int) ( $item['quantity'] ?? 1 );
		}
		$summary = $parts ? implode( ', ', $parts ) : __( 'Tickets', 'wp-pass-captain' );
		return function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 250 ) : substr( $summary, 0, 250 );
	}

	/** Return URL for the buyer after the hosted payment page. */
	public static function return_url( $status, $public_id ) {
		$url = add_query_arg( array( 'wpcap_payment' => $status, 'order' => $public_id ), home_url( '/' ) );
		return (string) apply_filters( 'wpcap_payment_return_url', $url, $status, $public_id, 'stripe' );
	}

	/** Plugin minor units → Stripe amount. */
	public static function to_stripe_amount( $minor, $currency ) {
		if ( in_array( strtoupper( $currency ), self::SCALED_ZERO_DECIMAL, true ) ) {
			return (int) $minor * 100;
		}
		return (int) $minor;
	}

	/** Stripe amount → plugin minor units. */
	public static function from_stripe_amount( $amount, $currency ) {
		if ( in_array( strtoupper( $currency ), self::SCALED_ZERO_DECIMAL, true ) ) {
			return intdiv( (int) $amount, 100 );
		}
		return (int) $amount;
	}
}
