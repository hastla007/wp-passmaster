<?php
/**
 * Mollie adapter (Payments API v2).
 *
 * - create_payment(): POST /v2/payments with the exact order total (amount value string with the
 *   currency's decimals), description, redirectUrl, webhookUrl and metadata.order = public id;
 *   optional method (buyer's choice or the list of enabled methods).
 * - Webhook: Mollie posts only "id=tr_…". Authenticity comes from fetching that payment from the API
 *   with the secret key (Mollie's model); the payment must carry our order id in its metadata.
 *   Normalized event ids: "mollie-{id}-{status}" (refunds: "mollie-{id}-refunded-{amount}").
 * - Refunds: POST /v2/payments/{id}/refunds with an Idempotency-Key header and metadata.wpcap_refund,
 *   so refunds booked by the plugin are not booked again when their webhook arrives.
 * - Chargebacks (payment stays "paid", amountChargedBack / _embedded.chargebacks) are reported as
 *   "refunded" events with the id "mollie-{id}-chargeback-{amount}", so tickets do not stay valid
 *   after the money was pulled back.
 * - Only ids this site created (wpcap_mollie_payments / order provider_reference) are looked up at
 *   the API; unknown ids are acknowledged without an API call, and lookups are throttled per IP.
 * - Methods: GET /v2/methods for amount/currency (and optional billing country), cached 1 h.
 *
 * API key: Settings::secret('mollie_api_key') (constant WPCAP_MOLLIE_API_KEY), "test_…" or "live_…".
 */
namespace PassCaptain\Mollie;

use PassCaptain\Payments\GatewayInterface;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MollieGateway implements GatewayInterface {
	const API = 'https://api.mollie.com/v2/';

	/** Currencies Mollie accepts. */
	const CURRENCIES = array( 'AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'ISK', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TWD', 'USD', 'ZAR' );

	/** ISO 4217 currencies without minor unit (plugin minor units = whole units). */
	const ZERO_DECIMAL = array( 'ISK', 'JPY' );

	/** Locales accepted by Mollie's hosted checkout. */
	const LOCALES = array( 'en_US', 'en_GB', 'nl_NL', 'nl_BE', 'fr_FR', 'fr_BE', 'de_DE', 'de_AT', 'de_CH', 'es_ES', 'ca_ES', 'pt_PT', 'it_IT', 'nb_NO', 'sv_SE', 'fi_FI', 'da_DK', 'is_IS', 'hu_HU', 'pl_PL', 'lv_LV', 'lt_LT' );

	public function id() { return 'mollie'; }

	public function title() { return __( 'Mollie (iDEAL, Kreditkarte, Bancontact u. a.)', 'pass-captain' ); }

	public function is_available( $currency ) {
		return 1 === preg_match( '/^(test|live)_[A-Za-z0-9]{20,}$/', Settings::secret( 'mollie_api_key' ) )
			&& in_array( strtoupper( (string) $currency ), self::CURRENCIES, true );
	}

	public function supports_refunds() { return true; }

	public static function config() {
		$c = (array) Settings::get( 'mollie' );
		return array(
			'methods' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $c['methods'] ?? array() ) ) ) ),
			'billing_country' => preg_match( '/^[A-Z]{2}$/', (string) ( $c['billing_country'] ?? '' ) ) ? (string) $c['billing_country'] : '',
		);
	}

	/** Plugin minor units → Mollie value string ("45.00", "1500" for JPY). */
	public static function format_amount( $minor, $currency ) {
		$minor = (int) $minor;
		if ( in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ) { return (string) $minor; }
		return intdiv( $minor, 100 ) . '.' . str_pad( (string) ( $minor % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** Mollie value string → plugin minor units (no floating point). */
	public static function parse_amount( $value, $currency ) {
		$value = (string) $value;
		if ( ! preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m ) ) { return -1; }
		if ( in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ) { return (int) $m[1]; }
		return (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '0', 2, '0' );
	}

	public static function webhook_url() {
		return rest_url( 'wp-pass-captain/v1/payments/mollie/webhook' );
	}

	public function create_payment( array $order, array $items = array() ) {
		$public_id = sanitize_text_field( (string) ( $order['public_id'] ?? '' ) );
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		$total = (int) ( $order['total_minor'] ?? 0 );
		if ( '' === $public_id || $total <= 0 || ! in_array( $currency, self::CURRENCIES, true ) ) {
			return new \WP_Error( 'wpcap_mollie_order', __( 'Die Bestellung kann nicht mit Mollie bezahlt werden.', 'pass-captain' ) );
		}
		// A retried checkout reuses a still open payment.
		$existing = (string) ( $order['provider_reference'] ?? '' );
		$key_suffix = '';
		if ( 0 === strpos( $existing, 'tr_' ) ) {
			$payment = self::api( 'GET', 'payments/' . rawurlencode( $existing ) );
			$payment_currency = strtoupper( (string) ( $payment['amount']['currency'] ?? '' ) );
			// Only for exactly the current order total (a payment for another amount would be a mismatch).
			$same_amount = ! is_wp_error( $payment ) && $payment_currency === $currency && self::parse_amount( $payment['amount']['value'] ?? '', $currency ) === $total;
			if ( $same_amount && 'open' === ( $payment['status'] ?? '' ) && ! empty( $payment['_links']['checkout']['href'] ) ) {
				self::remember( $payment['id'] ?? '', $order );
				return self::payment_result( $payment );
			}
			if ( ! $same_amount && ! is_wp_error( $payment ) && ! empty( $payment['isCancelable'] ) ) {
				self::api( 'DELETE', 'payments/' . rawurlencode( $existing ) ); // Best effort: the stale payment must not be paid.
			}
			$key_suffix = '-' . substr( hash( 'sha256', $existing ), 0, 12 );
		}
		$value = self::format_amount( $total, $currency );
		$body = array(
			'amount' => array( 'currency' => $currency, 'value' => $value ),
			/* translators: %s: order number */
			'description' => mb_substr( sprintf( __( 'Bestellung %s', 'pass-captain' ), $public_id ), 0, 255 ),
			'redirectUrl' => add_query_arg( array( 'wpcap_mollie_return' => '1', 'order' => $public_id ), home_url( '/' ) ),
			'webhookUrl' => self::webhook_url(),
			'metadata' => array( 'order' => $public_id ),
		);
		$locale = (string) ( $order['locale'] ?? '' );
		if ( in_array( $locale, self::LOCALES, true ) ) { $body['locale'] = $locale; }
		$method = self::method_for_order( $order, $value, $currency );
		if ( '' !== $method && array() !== $method ) { $body['method'] = $method; }
		$body = apply_filters( 'wpcap_mollie_payment_body', $body, $order, $items );
		$payment = self::api( 'POST', 'payments', $body, 'wpcap-mollie-' . $public_id . $key_suffix . '-' . $total . strtolower( $currency ) );
		if ( is_wp_error( $payment ) ) { return $payment; }
		if ( empty( $payment['id'] ) || empty( $payment['_links']['checkout']['href'] ) ) {
			return new \WP_Error( 'wpcap_mollie_response', __( 'Unerwartete Antwort von Mollie.', 'pass-captain' ) );
		}
		self::remember( $payment['id'], $order );
		if ( 'banktransfer' === $method && ! empty( $order['order_id'] ) ) {
			// The buyer chose bank transfer: the hold must last until the transfer can arrive (QA-6).
			\PassCaptain\Payments\Webhooks::extend_for_delayed_payment( (object) $order, 'mollie' );
		}
		return self::payment_result( $payment );
	}

	/** Records a payment id created for an order (the webhook only looks up known ids). */
	private static function remember( $payment_id, array $order ) {
		global $wpdb;
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', (string) $payment_id ) ) { return; }
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (payment_id,order_id,created_at) VALUES (%s,%d,%s)', $wpdb->prefix . 'wpcap_mollie_payments', $payment_id, (int) ( $order['order_id'] ?? 0 ), current_time( 'mysql', true ) ) );
	}

	/** Whether a payment id belongs to a Mollie order of this site. */
	public static function known_payment( $payment_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE payment_id=%s UNION SELECT 1 FROM %i WHERE payment_provider='mollie' AND provider_reference=%s LIMIT 1", $p . 'wpcap_mollie_payments', $payment_id, $p . 'wpcap_orders', $payment_id ) );
	}

	/**
	 * Method parameter: the buyer's choice when it is enabled and offered for this amount, otherwise
	 * the enabled methods Mollie offers (array), or '' when no restriction is configured.
	 *
	 * @return string|array
	 */
	private static function method_for_order( array $order, $value, $currency ) {
		$enabled = self::config()['methods'];
		if ( ! $enabled ) { return ''; }
		$offered = self::available_methods( $value, $currency );
		$allowed = is_wp_error( $offered ) ? $enabled : array_values( array_intersect( $enabled, array_keys( $offered ) ) );
		$chosen = \PassCaptain\Modules\Mollie::chosen_method( (int) ( $order['order_id'] ?? 0 ) );
		if ( '' !== $chosen && in_array( $chosen, $allowed, true ) ) { return $chosen; }
		return $allowed ? $allowed : '';
	}

	/** Timeout (seconds) of the methods lookup: it runs while the event page renders. */
	const METHODS_TIMEOUT = 3;

	/** Seconds a failed methods lookup is remembered (no API call per page view while Mollie is down). */
	const METHODS_ERROR_TTL = 300;

	/**
	 * Methods Mollie offers for amount/currency (and billing country).
	 *
	 * The method list is fetched once per currency and billing country (cached for one hour) and
	 * filtered locally by each method's minimumAmount/maximumAmount, so the event page does not call
	 * the API per ticket price. Mollie returns those limits in EUR when no amount is sent; for other
	 * currencies the limits cannot be compared locally, so the list is requested for the amount
	 * (Mollie filters it) and cached per amount. Failures are cached for 5 minutes and the lookup
	 * times out after 3 seconds.
	 *
	 * @return array|\WP_Error id => description
	 */
	public static function available_methods( $value, $currency ) {
		$currency = strtoupper( (string) $currency );
		$minor = self::parse_amount( (string) $value, $currency );
		if ( 'EUR' === $currency && $minor >= 0 ) {
			$catalog = self::method_catalog( array(), 'EUR' );
			if ( is_wp_error( $catalog ) ) { return $catalog; }
			$methods = array();
			foreach ( $catalog as $id => $method ) {
				if ( self::within_limits( $method, $minor, $currency ) ) { $methods[ $id ] = $method['description']; }
			}
			return $methods;
		}
		$catalog = self::method_catalog( array( 'amount' => array( 'value' => (string) $value, 'currency' => $currency ) ), $currency );
		return is_wp_error( $catalog ) ? $catalog : array_map( static function ( $method ) { return $method['description']; }, $catalog );
	}

	/** True when the amount is inside the method's limits (limits in another currency count as unknown = allowed). */
	private static function within_limits( array $method, $minor, $currency ) {
		foreach ( array( 'min' => 1, 'max' => -1 ) as $key => $sign ) {
			$limit = $method[ $key ] ?? null;
			if ( ! $limit || strtoupper( (string) $limit['currency'] ) !== $currency ) { continue; }
			$limit_minor = self::parse_amount( (string) $limit['value'], $currency );
			if ( $limit_minor < 0 ) { continue; }
			if ( ( $minor - $limit_minor ) * $sign < 0 ) { return false; }
		}
		return true;
	}

	/**
	 * Cached GET /v2/methods (one-off payments, billing country) with the method limits.
	 *
	 * @param array  $query    Extra query parameters (e.g. amount).
	 * @param string $currency Currency of the cache bucket.
	 * @return array|\WP_Error id => {description, min, max}
	 */
	private static function method_catalog( array $query, $currency ) {
		$query = array_merge( $query, array( 'sequenceType' => 'oneoff' ) );
		$country = self::config()['billing_country'];
		if ( '' !== $country ) { $query['billingCountry'] = $country; }
		$cache_key = 'wpcap_mollie_catalog_' . substr( hash( 'sha256', $currency . '|' . wp_json_encode( $query ) . '|' . substr( Settings::secret( 'mollie_api_key' ), 0, 5 ) ), 0, 20 );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['error'] ) ) {
			return new \WP_Error( 'wpcap_mollie_methods_unavailable', (string) $cached['error'] );
		}
		if ( is_array( $cached ) && isset( $cached['methods'] ) ) { return (array) $cached['methods']; }
		$result = self::api( 'GET', 'methods?' . http_build_query( $query, '', '&' ), null, '', self::METHODS_TIMEOUT );
		if ( is_wp_error( $result ) ) {
			// Negative cache: while Mollie is unreachable the event page does not wait for it on every view.
			set_transient( $cache_key, array( 'error' => $result->get_error_message() ), self::METHODS_ERROR_TTL );
			return $result;
		}
		$limit = static function ( $amount ) {
			return is_array( $amount ) && isset( $amount['value'], $amount['currency'] ) ? array( 'value' => (string) $amount['value'], 'currency' => (string) $amount['currency'] ) : null;
		};
		$methods = array();
		foreach ( (array) ( $result['_embedded']['methods'] ?? array() ) as $method ) {
			if ( empty( $method['id'] ) ) { continue; }
			$methods[ sanitize_key( $method['id'] ) ] = array(
				'description' => sanitize_text_field( (string) ( $method['description'] ?? $method['id'] ) ),
				'min' => $limit( $method['minimumAmount'] ?? null ),
				'max' => $limit( $method['maximumAmount'] ?? null ),
			);
		}
		set_transient( $cache_key, array( 'methods' => $methods ), HOUR_IN_SECONDS );
		return $methods;
	}

	/** Enabled methods that Mollie offers for this amount (for the checkout selector). */
	public static function selectable_methods( $minor, $currency ) {
		$enabled = self::config()['methods'];
		if ( ! $enabled ) { return array(); }
		$offered = self::available_methods( self::format_amount( $minor, $currency ), $currency );
		if ( is_wp_error( $offered ) ) { return array(); }
		return array_intersect_key( $offered, array_flip( $enabled ) );
	}

	public function verify_webhook( \WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( '' === $id ) {
			parse_str( (string) $request->get_body(), $form );
			$id = (string) ( $form['id'] ?? '' );
		}
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', $id ) ) {
			return new \WP_Error( 'wpcap_mollie_id', __( 'Ungültige Mollie-Zahlungs-ID.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( ! self::known_payment( $id ) ) {
			// Not created by this site: acknowledge (200) without calling the API.
			return array( 'event_id' => 'mollie-unknown', 'type' => 'ignored', 'order_public_id' => '', 'provider_reference' => '', 'amount_minor' => 0, 'currency' => '' );
		}
		$client = \PassCaptain\RateLimit::client();
		if ( ! \PassCaptain\RateLimit::allow( 'mollie_webhook|' . $client, (int) apply_filters( 'wpcap_mollie_webhook_ip_limit', 120 ), 60 ) || ! \PassCaptain\RateLimit::allow( 'mollie_webhook|all', (int) apply_filters( 'wpcap_mollie_webhook_global_limit', 1200 ), 60 ) ) {
			return new \WP_Error( 'wpcap_mollie_throttled', 'Too many webhook calls.', array( 'status' => 429 ) );
		}
		$payment = self::api( 'GET', 'payments/' . rawurlencode( $id ) . '?embed=refunds,chargebacks' );
		if ( is_wp_error( $payment ) ) {
			$status = (int) ( ( (array) $payment->get_error_data() )['status'] ?? 0 );
			// Unknown payment: not ours (404). Other failures: 503 so Mollie retries.
			return new \WP_Error( 'wpcap_mollie_lookup', $payment->get_error_message(), array( 'status' => 404 === $status ? 404 : 503 ) );
		}
		return self::normalize( $payment );
	}

	/** Maps a fetched Mollie payment to the normalized event. */
	public static function normalize( array $payment ) {
		global $wpdb;
		$id = (string) ( $payment['id'] ?? '' );
		$status = sanitize_key( (string) ( $payment['status'] ?? '' ) );
		$currency = strtoupper( (string) ( $payment['amount']['currency'] ?? '' ) );
		$public_id = sanitize_text_field( (string) ( $payment['metadata']['order'] ?? '' ) );
		$event = array(
			'event_id' => 'mollie-' . $id . '-' . $status,
			'type' => 'ignored',
			'order_public_id' => $public_id,
			'provider_reference' => $id,
			'amount_minor' => self::parse_amount( $payment['amount']['value'] ?? '', $currency ),
			'currency' => $currency,
		);
		if ( 'paid' === $status ) {
			$order = '' !== $public_id ? $wpdb->get_row( $wpdb->prepare( "SELECT payment_status FROM %i WHERE public_id=%s AND payment_provider='mollie'", $wpdb->prefix . 'wpcap_orders', $public_id ) ) : null;
			$external = self::external_refunded_minor( $payment, $currency );
			$chargeback = self::charged_back_minor( $payment, $currency );
			if ( $order && 'pending' !== $order->payment_status && $chargeback > 0 ) {
				// Money pulled back by the bank/card issuer: same consequences as an external refund.
				$event['event_id'] = 'mollie-' . $id . '-chargeback-' . $chargeback;
				$event['type'] = 'refunded';
				$event['refund_scope'] = 'cumulative'; // Running total of chargebacks + external refunds.
				$event['refund_reason'] = 'chargeback';
				$event['amount_minor'] = $chargeback + $external;
				return $event;
			}
			if ( $order && 'pending' !== $order->payment_status && $external > 0 ) {
				// Refund made outside the plugin (Mollie dashboard); cumulative external amount.
				$event['event_id'] = 'mollie-' . $id . '-refunded-' . $external;
				$event['type'] = 'refunded';
				$event['refund_scope'] = 'cumulative';
				$event['amount_minor'] = $external;
				return $event;
			}
			$event['type'] = 'paid';
		} elseif ( in_array( $status, array( 'failed', 'canceled', 'expired' ), true ) ) {
			$event['type'] = 'failed';
		}
		return $event;
	}

	/** Sum of refunds not created by the plugin (no metadata.wpcap_refund), excluding failed/canceled. */
	private static function external_refunded_minor( array $payment, $currency ) {
		$sum = 0;
		foreach ( (array) ( $payment['_embedded']['refunds'] ?? array() ) as $refund ) {
			if ( ! empty( $refund['metadata']['wpcap_refund'] ) || in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ) { continue; }
			$sum += max( 0, self::parse_amount( $refund['amount']['value'] ?? '', $currency ) );
		}
		return $sum;
	}

	/** Charged-back amount (reversed chargebacks excluded). */
	private static function charged_back_minor( array $payment, $currency ) {
		if ( isset( $payment['_embedded']['chargebacks'] ) && is_array( $payment['_embedded']['chargebacks'] ) ) {
			$sum = 0;
			foreach ( $payment['_embedded']['chargebacks'] as $chargeback ) {
				if ( ! empty( $chargeback['reversedAt'] ) ) { continue; }
				$sum += max( 0, self::parse_amount( $chargeback['amount']['value'] ?? '', $currency ) );
			}
			return $sum;
		}
		return isset( $payment['amountChargedBack']['value'] ) ? max( 0, self::parse_amount( $payment['amountChargedBack']['value'], $currency ) ) : 0;
	}

	public function refund( array $order, $amount_minor, $idempotency_key ) {
		$reference = (string) ( $order['provider_reference'] ?? '' );
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		if ( 0 !== strpos( $reference, 'tr_' ) ) {
			return new \WP_Error( 'wpcap_mollie_refund_reference', __( 'Für diese Bestellung ist keine Mollie-Zahlung bekannt.', 'pass-captain' ) );
		}
		$body = array(
			'amount' => array( 'currency' => $currency, 'value' => self::format_amount( (int) $amount_minor, $currency ) ),
			/* translators: %s: order number */
			'description' => mb_substr( sprintf( __( 'Erstattung %s', 'pass-captain' ), (string) ( $order['public_id'] ?? '' ) ), 0, 140 ),
			'metadata' => array( 'order' => (string) ( $order['public_id'] ?? '' ), 'wpcap_refund' => (string) $idempotency_key ),
		);
		$refund = self::api( 'POST', 'payments/' . rawurlencode( $reference ) . '/refunds', $body, (string) $idempotency_key );
		if ( is_wp_error( $refund ) ) { return $refund; }
		if ( empty( $refund['id'] ) || in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ) {
			return new \WP_Error( 'wpcap_mollie_refund_failed', __( 'Mollie hat die Erstattung abgelehnt.', 'pass-captain' ) );
		}
		return (string) $refund['id'];
	}

	/** Public for the return handler: fetches a payment. */
	public static function get_payment( $id ) {
		return self::api( 'GET', 'payments/' . rawurlencode( (string) $id ) );
	}

	/** API call; returns decoded body or WP_Error (data.status = HTTP code). */
	private static function api( $method, $path, $body = null, $idempotency_key = '', $timeout = 15 ) {
		$key = Settings::secret( 'mollie_api_key' );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_mollie_config', __( 'Mollie ist nicht konfiguriert.', 'pass-captain' ) );
		}
		$args = array( 'method' => $method, 'timeout' => (int) $timeout, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ) );
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
			if ( '' !== $idempotency_key ) { $args['headers']['Idempotency-Key'] = substr( $idempotency_key, 0, 255 ); }
			$response = wp_remote_post( self::API . $path, $args );
		} elseif ( 'DELETE' === $method ) {
			$response = wp_remote_request( self::API . $path, $args );
		} else {
			$response = wp_remote_get( self::API . $path, $args );
		}
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'wpcap_mollie_http', sprintf( __( 'Mollie ist nicht erreichbar: %s', 'pass-captain' ), \PassCaptain\Security\Redact::text( $response->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && ! empty( $data['detail'] ) ? (string) $data['detail'] : 'HTTP ' . $code;
			/* translators: %s: error message from Mollie */
			return new \WP_Error( 'wpcap_mollie_api', sprintf( __( 'Mollie-Fehler: %s', 'pass-captain' ), \PassCaptain\Security\Redact::text( $message ) ), array( 'status' => $code ) );
		}
		return $data;
	}

	private static function payment_result( array $payment ) {
		return array(
			'provider' => 'mollie',
			'status' => 'pending',
			'redirect_url' => esc_url_raw( (string) $payment['_links']['checkout']['href'] ),
			'provider_reference' => (string) $payment['id'],
		);
	}

	/** Return URL with the standard notice (?wpcap_payment=…). */
	public static function return_url( $status, $public_id ) {
		$url = add_query_arg( array( 'wpcap_payment' => $status, 'order' => $public_id ), home_url( '/' ) );
		return (string) apply_filters( 'wpcap_payment_return_url', $url, $status, $public_id, 'mollie' );
	}
}
