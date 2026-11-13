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
 * API key: Secrets::get('mollie_api_key') (constant WPCAP_MOLLIE_API_KEY), "test_…" or "live_…".
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
		return 1 === preg_match( '/^(test|live)_[A-Za-z0-9]{20,}$/', \PassCaptain\Secrets::get( 'mollie_api_key' ) )
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
		return \PassCaptain\Money::to_decimal( (int) $minor, $currency );
	}

	/** Mollie value string → plugin minor units (no floating point). */
	public static function parse_amount( $value, $currency ) {
		$value = (string) $value;
		if ( ! preg_match( '/^\d+(?:\.\d{1,3})?$/', $value ) ) { return -1; }
		$minor = \PassCaptain\Money::to_minor( $value, $currency );
		return null === $minor ? -1 : $minor;
	}

	public static function webhook_url() {
		return rest_url( 'pass-captain/v1/payments/mollie/webhook' );
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
			if ( ! is_wp_error( $payment ) && 'open' === ( $payment['status'] ?? '' ) && ! empty( $payment['_links']['checkout']['href'] ) ) {
				self::remember( $payment['id'] ?? '', $order );
				return self::payment_result( $payment );
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
		$payment = self::api( 'POST', 'payments', $body, 'wpcap-mollie-' . $public_id . $key_suffix );
		if ( is_wp_error( $payment ) ) { return $payment; }
		if ( empty( $payment['id'] ) || empty( $payment['_links']['checkout']['href'] ) ) {
			return new \WP_Error( 'wpcap_mollie_response', __( 'Unerwartete Antwort von Mollie.', 'pass-captain' ) );
		}
		self::remember( $payment['id'], $order );
		return self::payment_result( $payment );
	}

	/** Records a payment id created for an order (the webhook only looks up known ids). */
	private static function remember( $payment_id, array $order ) {
		global $wpdb;
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', (string) $payment_id ) ) { return; }
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}wpcap_mollie_payments (payment_id,order_id,created_at) VALUES (%s,%d,%s)", $payment_id, (int) ( $order['order_id'] ?? 0 ), current_time( 'mysql', true ) ) );
	}

	/** Whether a payment id belongs to a Mollie order of this site. */
	public static function known_payment( $payment_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wpcap_mollie_payments WHERE payment_id=%s UNION SELECT 1 FROM {$p}wpcap_orders WHERE payment_provider='mollie' AND provider_reference=%s LIMIT 1", $payment_id, $payment_id ) );
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

	/**
	 * Methods Mollie offers for amount/currency (and billing country), cached for one hour.
	 *
	 * @return array|\WP_Error id => description
	 */
	public static function available_methods( $value, $currency ) {
		$query = array( 'amount' => array( 'value' => (string) $value, 'currency' => strtoupper( $currency ) ), 'sequenceType' => 'oneoff' );
		$country = self::config()['billing_country'];
		if ( '' !== $country ) { $query['billingCountry'] = $country; }
		$cache_key = 'wpcap_mollie_methods_' . substr( hash( 'sha256', wp_json_encode( $query ) . '|' . substr( \PassCaptain\Secrets::get( 'mollie_api_key' ), 0, 5 ) ), 0, 20 );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { return $cached; }
		$result = self::api( 'GET', 'methods?' . http_build_query( $query, '', '&' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$methods = array();
		foreach ( (array) ( $result['_embedded']['methods'] ?? array() ) as $method ) {
			if ( ! empty( $method['id'] ) ) { $methods[ sanitize_key( $method['id'] ) ] = sanitize_text_field( (string) ( $method['description'] ?? $method['id'] ) ); }
		}
		set_transient( $cache_key, $methods, HOUR_IN_SECONDS );
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
			$order = '' !== $public_id ? $wpdb->get_row( $wpdb->prepare( "SELECT payment_status FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s AND payment_provider='mollie'", $public_id ) ) : null;
			$external = self::external_refunded_minor( $payment, $currency );
			$chargeback = self::charged_back_minor( $payment, $currency );
			if ( $order && 'pending' !== $order->payment_status && $chargeback > 0 ) {
				// Money pulled back by the bank/card issuer: same consequences as an external refund.
				$event['event_id'] = 'mollie-' . $id . '-chargeback-' . $chargeback;
				$event['type'] = 'refunded';
				$event['chargeback'] = true;
				$event['cumulative'] = true;
				$event['amount_minor'] = $chargeback + $external;
				return $event;
			}
			if ( $order && 'pending' !== $order->payment_status && $external > 0 ) {
				// Refund made outside the plugin (Mollie dashboard); cumulative external amount.
				$event['event_id'] = 'mollie-' . $id . '-refunded-' . $external;
				$event['type'] = 'refunded';
				$event['cumulative'] = true;
				$event['amount_minor'] = $external;
				return $event;
			}
			$event['type'] = 'paid';
			if ( $order && 'pending' === $order->payment_status && ( $chargeback > 0 || $external > 0 ) ) {
				// Refund/chargeback seen before the payment was confirmed here (first webhook lost): the payment
				// is applied first, Mollie is asked to redeliver and the redelivery books the refund.
				$event['redeliver'] = true;
			}
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
	private static function api( $method, $path, $body = null, $idempotency_key = '' ) {
		$key = \PassCaptain\Secrets::get( 'mollie_api_key' );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_mollie_config', __( 'Mollie ist nicht konfiguriert.', 'pass-captain' ) );
		}
		$args = array( 'method' => $method, 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ) );
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
			if ( '' !== $idempotency_key ) { $args['headers']['Idempotency-Key'] = substr( $idempotency_key, 0, 255 ); }
		}
		$response = \PassCaptain\OutboundHttp::request( self::API . $path, $args, array( 'context' => 'mollie', 'hosts' => array( 'api.mollie.com' ) ) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'wpcap_mollie_http', sprintf( __( 'Mollie ist nicht erreichbar: %s', 'pass-captain' ), \PassCaptain\Redact::text( $response->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && ! empty( $data['detail'] ) ? (string) $data['detail'] : 'HTTP ' . $code;
			/* translators: %s: error message from Mollie */
			return new \WP_Error( 'wpcap_mollie_api', sprintf( __( 'Mollie-Fehler: %s', 'pass-captain' ), \PassCaptain\Redact::text( $message ) ), array( 'status' => $code ) );
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
