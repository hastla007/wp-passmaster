<?php
/**
 * PayPal adapter (Orders API v2): hosted approval, capture on return/webhook, verified webhooks, refunds.
 *
 * Flow: create_payment() creates a PayPal order and redirects the buyer to PayPal. After approval the
 * buyer returns to ?wpcap_paypal_return=1 where handle_return() captures the order. As a fallback for
 * buyers who never return, a CHECKOUT.ORDER.APPROVED webhook captures as well. Both paths use the same
 * PayPal-Request-Id and the same normalized event id ("capture-{id}"), so tickets are issued once.
 */
namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PayPalGateway implements GatewayInterface {
	const SANDBOX = 'https://api-m.sandbox.paypal.com';
	const LIVE = 'https://api-m.paypal.com';

	/** Currencies PayPal accepts for payments. */
	const CURRENCIES = array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD' );

	/** Currencies PayPal only accepts without decimals. */
	const NO_DECIMALS = array( 'HUF', 'JPY', 'TWD' );

	/** ISO 4217 currencies without minor unit (same list as the WooCommerce adapter). */
	const ISO_ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/** Prefix of the custom_id on refunds created by the plugin. */
	const REFUND_PREFIX = 'wpcap-refund-';

	public function id() { return 'paypal'; }

	public function title() { return __( 'PayPal', 'wp-pass-captain' ); }

	public function is_available( $currency ) {
		return '' !== Settings::secret( 'paypal_client_id' )
			&& '' !== \PassCaptain\Secrets::get( 'paypal_client_secret' )
			&& '' !== Settings::secret( 'paypal_webhook_id' )
			&& in_array( strtoupper( (string) $currency ), self::CURRENCIES, true );
	}

	public function supports_refunds() { return true; }

	/** Registers the return handler. Call once from Plugin bootstrap. */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_return' ) );
	}

	public function create_payment( array $order, array $items = array() ) {
		$public_id = sanitize_text_field( (string) ( $order['public_id'] ?? '' ) );
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		$total = (int) ( $order['total_minor'] ?? 0 );
		if ( '' === $public_id || $total <= 0 || ! in_array( $currency, self::CURRENCIES, true ) ) {
			return new \WP_Error( 'wpcap_paypal_order', __( 'Die Bestellung kann nicht mit PayPal bezahlt werden.', 'wp-pass-captain' ) );
		}
		$value = self::format_amount( $total, $currency );
		if ( is_wp_error( $value ) ) { return $value; }
		$experience = array(
			'return_url' => add_query_arg( array( 'wpcap_paypal_return' => '1', 'order' => $public_id ), home_url( '/' ) ),
			'cancel_url' => self::return_url( 'cancel', $public_id ),
			'user_action' => 'PAY_NOW',
			'shipping_preference' => 'NO_SHIPPING',
		);
		$locale = str_replace( '_', '-', (string) ( $order['locale'] ?? '' ) );
		if ( preg_match( '/^[a-z]{2}-[A-Z]{2}$/', $locale ) ) { $experience['locale'] = $locale; }
		$body = array(
			'intent' => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => $public_id,
					'custom_id' => $public_id,
					'description' => self::summary( $items ),
					'amount' => array( 'currency_code' => $currency, 'value' => $value ),
				),
			),
			'payment_source' => array( 'paypal' => array( 'experience_context' => $experience ) ),
		);
		$body = apply_filters( 'wpcap_paypal_order_body', $body, $order, $items );
		$result = self::api( 'POST', '/v2/checkout/orders', $body, 'wpcap-' . $public_id );
		if ( is_wp_error( $result ) ) { return $result; }
		$redirect = '';
		foreach ( (array) ( $result['links'] ?? array() ) as $link ) {
			if ( in_array( $link['rel'] ?? '', array( 'payer-action', 'approve' ), true ) && ! empty( $link['href'] ) ) {
				$redirect = (string) $link['href'];
				break;
			}
		}
		if ( empty( $result['id'] ) || '' === $redirect ) {
			return new \WP_Error( 'wpcap_paypal_response', __( 'Unerwartete Antwort von PayPal.', 'wp-pass-captain' ) );
		}
		return array(
			'provider' => 'paypal',
			'status' => 'pending',
			'redirect_url' => esc_url_raw( $redirect ),
			'provider_reference' => (string) $result['id'],
		);
	}

	/** template_redirect: buyer comes back from PayPal (?wpcap_paypal_return=1&order=…&token=…). */
	public static function maybe_handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Redirect from PayPal; the capture itself is authorized by PayPal.
		if ( empty( $_GET['wpcap_paypal_return'] ) ) { return; }
		$public_id = sanitize_text_field( wp_unslash( (string) ( $_GET['order'] ?? '' ) ) );
		$token = sanitize_text_field( wp_unslash( (string) ( $_GET['token'] ?? '' ) ) );
		// phpcs:enable
		$result = ( '' !== $public_id && '' !== $token ) ? self::handle_return( $public_id, $token ) : new \WP_Error( 'wpcap_paypal_return', '' );
		if ( is_wp_error( $result ) ) {
			$status = 'failed';
		} else {
			$status = 'pending' === $result ? 'pending' : 'success';
		}
		nocache_headers();
		wp_safe_redirect( self::return_url( $status, $public_id ) );
		exit;
	}

	/**
	 * Captures an approved PayPal order and confirms the plugin order.
	 *
	 * @return string|\WP_Error Webhooks::process() result, "pending" for a pending capture.
	 */
	public static function handle_return( $public_id, $paypal_order_id ) {
		global $wpdb;
		$public_id = sanitize_text_field( (string) $public_id );
		$paypal_order_id = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $paypal_order_id );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status,provider_reference FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s AND payment_provider='paypal'", $public_id ) );
		if ( ! $order || '' === $paypal_order_id ) {
			return new \WP_Error( 'wpcap_paypal_return', __( 'Bestellung nicht gefunden.', 'wp-pass-captain' ), array( 'status' => 404 ) );
		}
		if ( 'paid' === $order->payment_status ) { return 'paid'; }
		if ( (string) $order->provider_reference !== $paypal_order_id ) {
			return new \WP_Error( 'wpcap_paypal_return', __( 'Die PayPal-Zahlung gehört nicht zu dieser Bestellung.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$event = self::capture( $paypal_order_id );
		if ( is_wp_error( $event ) ) { return $event; }
		if ( 'pending' === $event['type'] ) { return 'pending'; }
		if ( 'paid' !== $event['type'] || $event['order_public_id'] !== $public_id ) {
			return new \WP_Error( 'wpcap_paypal_capture', __( 'Die PayPal-Zahlung wurde nicht abgeschlossen.', 'wp-pass-captain' ) );
		}
		$full = \PassCaptain\Orders::get( (int) $order->order_id );
		if ( (int) $event['amount_minor'] !== (int) $full->total_minor || $event['currency'] !== strtoupper( (string) $full->currency ) ) {
			Audit::record( 'payment_amount_mismatch', 'order', (int) $order->order_id, array( 'provider' => 'paypal', 'amount_minor' => (int) $event['amount_minor'] ) );
			return new \WP_Error( 'wpcap_paypal_amount', __( 'Der bezahlte Betrag stimmt nicht mit der Bestellung überein.', 'wp-pass-captain' ) );
		}
		return Webhooks::process( 'paypal', $event );
	}

	/** Verifies a webhook via PayPal's verify-webhook-signature API and maps it. */
	public function verify_webhook( \WP_REST_Request $request ) {
		$raw = (string) $request->get_body();
		$event = json_decode( $raw, true );
		$webhook_id = Settings::secret( 'paypal_webhook_id' );
		$headers = array();
		foreach ( array( 'transmission_id' => 'paypal-transmission-id', 'transmission_time' => 'paypal-transmission-time', 'transmission_sig' => 'paypal-transmission-sig', 'cert_url' => 'paypal-cert-url', 'auth_algo' => 'paypal-auth-algo' ) as $field => $header ) {
			$headers[ $field ] = (string) $request->get_header( $header );
		}
		if ( '' === $webhook_id || in_array( '', $headers, true ) || ! is_array( $event ) || empty( $event['id'] ) ) {
			return new \WP_Error( 'wpcap_paypal_signature', __( 'Ungültige PayPal-Signatur.', 'wp-pass-captain' ), array( 'status' => 401 ) );
		}
		// The event is embedded verbatim: re-encoding it could change the bytes PayPal signed.
		$payload = '{';
		foreach ( $headers as $field => $value ) { $payload .= wp_json_encode( $field ) . ':' . wp_json_encode( $value ) . ','; }
		$payload .= '"webhook_id":' . wp_json_encode( $webhook_id ) . ',"webhook_event":' . $raw . '}';
		$result = self::api( 'POST', '/v1/notifications/verify-webhook-signature', $payload );
		if ( is_wp_error( $result ) ) {
			// Our side could not verify (network/auth): 503 so PayPal retries.
			return new \WP_Error( 'wpcap_paypal_verify', $result->get_error_message(), array( 'status' => 503 ) );
		}
		if ( 'SUCCESS' !== ( $result['verification_status'] ?? '' ) ) {
			return new \WP_Error( 'wpcap_paypal_signature', __( 'Ungültige PayPal-Signatur.', 'wp-pass-captain' ), array( 'status' => 401 ) );
		}
		return self::normalize( $event );
	}

	/** Refunds a capture. */
	public function refund( array $order, $amount_minor, $idempotency_key ) {
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		$capture_id = self::capture_id_for( (string) ( $order['provider_reference'] ?? '' ) );
		if ( is_wp_error( $capture_id ) ) { return $capture_id; }
		$value = self::format_amount( (int) $amount_minor, $currency );
		if ( is_wp_error( $value ) ) { return $value; }
		$body = array(
			'amount' => array( 'value' => $value, 'currency_code' => $currency ),
			// Marks plugin refunds so their PAYMENT.CAPTURE.REFUNDED webhook is not booked a second time.
			'custom_id' => substr( self::REFUND_PREFIX . (string) ( $order['public_id'] ?? '' ), 0, 127 ),
		);
		$result = self::api( 'POST', '/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund', $body, (string) $idempotency_key );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['id'] ) || in_array( $result['status'] ?? '', array( 'CANCELLED', 'FAILED' ), true ) ) {
			return new \WP_Error( 'wpcap_paypal_refund_failed', __( 'PayPal hat die Erstattung abgelehnt.', 'wp-pass-captain' ) );
		}
		return (string) $result['id'];
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Captures a PayPal order (idempotent via PayPal-Request-Id) and returns the normalized event:
	 * type "paid", "pending" (capture pending), "failed" or WP_Error.
	 */
	private static function capture( $paypal_order_id ) {
		$result = self::api( 'POST', '/v2/checkout/orders/' . rawurlencode( $paypal_order_id ) . '/capture', '{}', 'wpcap-capture-' . $paypal_order_id );
		if ( is_wp_error( $result ) ) {
			// Already captured (e.g. by the webhook without the same request id): read the order instead.
			$data = (array) $result->get_error_data();
			if ( 422 !== (int) ( $data['status'] ?? 0 ) || 'ORDER_ALREADY_CAPTURED' !== ( $data['issue'] ?? '' ) ) { return $result; }
			$result = self::api( 'GET', '/v2/checkout/orders/' . rawurlencode( $paypal_order_id ) );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		$unit = $result['purchase_units'][0] ?? array();
		$capture = $unit['payments']['captures'][0] ?? null;
		if ( ! is_array( $capture ) || empty( $capture['id'] ) ) {
			return new \WP_Error( 'wpcap_paypal_capture', __( 'Die PayPal-Zahlung wurde nicht abgeschlossen.', 'wp-pass-captain' ) );
		}
		$event = self::capture_event( $capture, (string) ( $unit['custom_id'] ?? ( $unit['reference_id'] ?? '' ) ) );
		$status = (string) ( $capture['status'] ?? '' );
		if ( 'COMPLETED' === $status ) {
			$event['type'] = 'paid';
		} elseif ( 'PENDING' === $status ) {
			$event['type'] = 'pending';
		} else {
			$event['type'] = 'failed';
		}
		return $event;
	}

	/** Normalized event for a capture; event id is shared by return handler and webhooks. */
	private static function capture_event( array $capture, $fallback_public_id = '' ) {
		$currency = strtoupper( (string) ( $capture['amount']['currency_code'] ?? '' ) );
		return array(
			'event_id' => 'capture-' . (string) $capture['id'],
			'type' => 'ignored',
			'order_public_id' => (string) ( $capture['custom_id'] ?? $fallback_public_id ),
			'provider_reference' => (string) $capture['id'],
			// The PayPal order id is stored on the Pass Captain order until the capture happened.
			'related_references' => array_values( array_filter( array( (string) $capture['id'], (string) ( $capture['supplementary_data']['related_ids']['order_id'] ?? '' ) ) ) ),
			'amount_minor' => self::parse_amount( (string) ( $capture['amount']['value'] ?? '' ), $currency ),
			'currency' => $currency,
		);
	}

	/** Maps a verified webhook event. */
	private static function normalize( array $event ) {
		$resource = is_array( $event['resource'] ?? null ) ? $event['resource'] : array();
		$ignored = array( 'event_id' => (string) $event['id'], 'type' => 'ignored', 'order_public_id' => '', 'provider_reference' => '', 'amount_minor' => 0, 'currency' => '' );
		switch ( (string) ( $event['event_type'] ?? '' ) ) {
			case 'CHECKOUT.ORDER.APPROVED':
				// Buyer approved but may never return to the site: capture here as well.
				if ( empty( $resource['id'] ) ) { return $ignored; }
				$captured = self::capture( (string) $resource['id'] );
				if ( is_wp_error( $captured ) ) {
					return new \WP_Error( $captured->get_error_code(), $captured->get_error_message(), array( 'status' => 503 ) );
				}
				// A pending capture is confirmed later by PAYMENT.CAPTURE.COMPLETED.
				return 'paid' === $captured['type'] ? $captured : $ignored;

			case 'PAYMENT.CAPTURE.COMPLETED':
				if ( empty( $resource['id'] ) ) { return $ignored; }
				$normalized = self::capture_event( $resource );
				if ( '' === $normalized['order_public_id'] ) {
					// custom_id missing on the capture: read it from the parent PayPal order.
					foreach ( (array) ( $resource['links'] ?? array() ) as $link ) {
						if ( 'up' === ( $link['rel'] ?? '' ) && preg_match( '#/v2/checkout/orders/([A-Za-z0-9\-]+)#', (string) ( $link['href'] ?? '' ), $m ) ) {
							$parent = self::api( 'GET', '/v2/checkout/orders/' . rawurlencode( $m[1] ) );
							if ( is_wp_error( $parent ) ) {
								return new \WP_Error( $parent->get_error_code(), $parent->get_error_message(), array( 'status' => 503 ) );
							}
							$normalized['order_public_id'] = (string) ( $parent['purchase_units'][0]['custom_id'] ?? '' );
						}
					}
				}
				$normalized['type'] = 'paid';
				return $normalized;

			case 'PAYMENT.CAPTURE.DENIED':
			case 'PAYMENT.CAPTURE.DECLINED':
				if ( empty( $resource['id'] ) ) { return $ignored; }
				$normalized = self::capture_event( $resource );
				$normalized['event_id'] = (string) $event['id'];
				$normalized['type'] = 'failed';
				return $normalized;

			case 'PAYMENT.CAPTURE.REFUNDED':
				return self::normalize_refund( $ignored, $resource );
		}
		return $ignored;
	}

	/**
	 * The resource is a single refund. Plugin refunds (custom_id prefix) are already booked and
	 * ignored. Otherwise the capture is read: a fully refunded capture is reported with its full
	 * amount, a partial one with the amount of this refund.
	 */
	private static function normalize_refund( array $base, array $refund ) {
		$custom_id = (string) ( $refund['custom_id'] ?? '' );
		if ( 0 === strpos( $custom_id, self::REFUND_PREFIX ) ) { return $base; }
		$currency = strtoupper( (string) ( $refund['amount']['currency_code'] ?? '' ) );
		$base['type'] = 'refunded';
		$base['provider_reference'] = (string) ( $refund['id'] ?? '' );
		$base['currency'] = $currency;
		$base['amount_minor'] = self::parse_amount( (string) ( $refund['amount']['value'] ?? '' ), $currency );
		$base['order_public_id'] = $custom_id;
		$capture_id = '';
		foreach ( (array) ( $refund['links'] ?? array() ) as $link ) {
			if ( 'up' === ( $link['rel'] ?? '' ) && preg_match( '#/v2/payments/captures/([A-Za-z0-9\-]+)#', (string) ( $link['href'] ?? '' ), $m ) ) { $capture_id = $m[1]; }
		}
		if ( '' !== $capture_id ) {
			$capture = self::api( 'GET', '/v2/payments/captures/' . rawurlencode( $capture_id ) );
			if ( is_wp_error( $capture ) ) {
				return new \WP_Error( $capture->get_error_code(), $capture->get_error_message(), array( 'status' => 503 ) );
			}
			if ( '' === $base['order_public_id'] ) { $base['order_public_id'] = (string) ( $capture['custom_id'] ?? '' ); }
			if ( 'REFUNDED' === ( $capture['status'] ?? '' ) ) {
				$base['amount_minor'] = self::parse_amount( (string) ( $capture['amount']['value'] ?? '' ), $currency );
			}
		}
		if ( '' === $base['order_public_id'] ) { $base['type'] = 'ignored'; }
		return $base;
	}

	/** The order's provider_reference is the capture id after payment, else the PayPal order id. */
	private static function capture_id_for( $reference ) {
		if ( '' === $reference ) {
			return new \WP_Error( 'wpcap_paypal_refund_reference', __( 'Für diese Bestellung ist keine PayPal-Zahlung bekannt.', 'wp-pass-captain' ) );
		}
		$paypal_order = self::api( 'GET', '/v2/checkout/orders/' . rawurlencode( $reference ) );
		if ( ! is_wp_error( $paypal_order ) ) {
			$capture_id = (string) ( $paypal_order['purchase_units'][0]['payments']['captures'][0]['id'] ?? '' );
			return '' !== $capture_id ? $capture_id : new \WP_Error( 'wpcap_paypal_refund_reference', __( 'Die PayPal-Zahlung wurde noch nicht abgeschlossen.', 'wp-pass-captain' ) );
		}
		$data = (array) $paypal_order->get_error_data();
		return 404 === (int) ( $data['status'] ?? 0 ) ? $reference : $paypal_order;
	}

	/** OAuth access token, cached until shortly before expiry. */
	private static function token( $force = false ) {
		$client_id = Settings::secret( 'paypal_client_id' );
		$secret = \PassCaptain\Secrets::get( 'paypal_client_secret' );
		if ( '' === $client_id || '' === $secret ) {
			return new \WP_Error( 'wpcap_paypal_config', __( 'PayPal ist nicht konfiguriert.', 'wp-pass-captain' ) );
		}
		$cache_key = 'wpcap_paypal_token_' . substr( hash( 'sha256', self::base() . '|' . $client_id . '|' . $secret ), 0, 20 );
		$cached = $force ? false : get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
		$response = wp_remote_post( self::base() . '/v1/oauth2/token', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept' => 'application/json',
			),
			'body' => 'grant_type=client_credentials',
		) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'wpcap_paypal_http', sprintf( __( 'PayPal ist nicht erreichbar: %s', 'wp-pass-captain' ), $response->get_error_message() ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			/* translators: %s: HTTP status */
			return new \WP_Error( 'wpcap_paypal_auth', sprintf( __( 'PayPal-Anmeldung fehlgeschlagen (%s). Bitte Client-ID und Secret prüfen.', 'wp-pass-captain' ), (string) ( $body['error_description'] ?? 'HTTP ' . $code ) ) );
		}
		$ttl = (int) ( $body['expires_in'] ?? 0 ) - 60;
		if ( $ttl > 0 ) { set_transient( $cache_key, (string) $body['access_token'], $ttl ); }
		return (string) $body['access_token'];
	}

	/**
	 * PayPal REST call with JSON body (array or pre-encoded string).
	 *
	 * @return array|\WP_Error Decoded body; errors carry ['status'=>HTTP code,'issue'=>first issue].
	 */
	private static function api( $method, $path, $body = null, $request_id = '', $retry = true ) {
		$token = self::token( ! $retry );
		if ( is_wp_error( $token ) ) { return $token; }
		$args = array(
			'method' => $method,
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
		);
		if ( '' !== $request_id ) { $args['headers']['PayPal-Request-Id'] = substr( $request_id, 0, 108 ); }
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
			$response = wp_remote_post( self::base() . $path, $args );
		} else {
			$response = wp_remote_get( self::base() . $path, $args );
		}
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'wpcap_paypal_http', sprintf( __( 'PayPal ist nicht erreichbar: %s', 'wp-pass-captain' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && $retry ) {
			// Token revoked/expired early: fetch a new one once.
			return self::api( $method, $path, $body, $request_id, false );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			$decoded = is_array( $decoded ) ? $decoded : array();
			$issue = (string) ( $decoded['details'][0]['issue'] ?? '' );
			$message = (string) ( $decoded['details'][0]['description'] ?? ( $decoded['message'] ?? 'HTTP ' . $code ) );
			/* translators: %s: error message from PayPal */
			return new \WP_Error( 'wpcap_paypal_api', sprintf( __( 'PayPal-Fehler: %s', 'wp-pass-captain' ), trim( $issue . ' ' . $message ) ), array( 'status' => $code, 'issue' => $issue ) );
		}
		return $decoded;
	}

	private static function base() {
		return 'live' === Settings::get( 'paypal_mode' ) ? self::LIVE : self::SANDBOX;
	}

	/** Plugin minor units → PayPal decimal string. */
	public static function format_amount( $minor, $currency ) {
		$currency = strtoupper( $currency );
		$minor = (int) $minor;
		$iso = in_array( $currency, self::ISO_ZERO_DECIMAL, true ) ? 0 : 2;
		$target = in_array( $currency, self::NO_DECIMALS, true ) ? 0 : $iso;
		if ( $iso > $target ) {
			$factor = 10 ** ( $iso - $target );
			if ( 0 !== $minor % $factor ) {
				return new \WP_Error( 'wpcap_paypal_amount', __( 'PayPal unterstützt in dieser Währung keine Nachkommastellen.', 'wp-pass-captain' ) );
			}
			$minor = intdiv( $minor, $factor );
		}
		if ( 0 === $target ) { return (string) $minor; }
		$factor = 10 ** $target;
		return intdiv( $minor, $factor ) . '.' . str_pad( (string) ( $minor % $factor ), $target, '0', STR_PAD_LEFT );
	}

	/** PayPal decimal string → plugin minor units (string arithmetic, no floats). */
	public static function parse_amount( $value, $currency ) {
		$currency = strtoupper( $currency );
		$iso = in_array( $currency, self::ISO_ZERO_DECIMAL, true ) ? 0 : 2;
		if ( ! preg_match( '/^(\d+)(?:\.(\d+))?$/', trim( (string) $value ), $m ) ) { return -1; }
		$fraction = str_pad( $m[2] ?? '', $iso, '0' );
		if ( strlen( $fraction ) > $iso ) {
			if ( '' !== trim( substr( $fraction, $iso ), '0' ) ) { return -1; }
			$fraction = substr( $fraction, 0, $iso );
		}
		return (int) ( $m[1] . $fraction );
	}

	private static function summary( array $items ) {
		$parts = array();
		foreach ( $items as $item ) {
			$parts[] = trim( (string) ( $item['event_name'] ?? '' ) . ' – ' . (string) ( $item['name'] ?? '' ), ' –' ) . ' × ' . (int) ( $item['quantity'] ?? 1 );
		}
		$summary = $parts ? implode( ', ', $parts ) : __( 'Tickets', 'wp-pass-captain' );
		return function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 127 ) : substr( $summary, 0, 127 );
	}

	private static function return_url( $status, $public_id ) {
		$url = add_query_arg( array( 'wpcap_payment' => $status, 'order' => $public_id ), home_url( '/' ) );
		return (string) apply_filters( 'wpcap_payment_return_url', $url, $status, $public_id, 'paypal' );
	}
}
