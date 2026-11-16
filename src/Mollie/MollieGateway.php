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
 *   the API; unknown ids are acknowledged without an API call. Every call counts against the per-IP
 *   limit before the database is asked (A431: a flood of random ids cannot keep the database busy);
 *   the global limit only counts known ids, so random ids cannot block the real webhooks.
 * - Methods: GET /v2/methods for amount/currency (and optional billing country), cached 1 h.
 *
 * API key: Secrets::get('mollie_api_key') (constant WPCAP_MOLLIE_API_KEY), "test_…" or "live_…".
 */
namespace PassCaptain\Mollie;

use PassCaptain\Payments\GatewayInterface;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MollieGateway implements GatewayInterface, \PassCaptain\Payments\RefundLookupInterface, \PassCaptain\Payments\PaymentLookupInterface {
	const API = 'https://api.mollie.com/v2/';

	/** Methods where the money arrives days later (open/pending = payment_pending, hold kept). */
	const DELAYED_METHODS = array( 'banktransfer', 'directdebit' );

	/** Methods that are only authorized first and must be captured (Klarna, Riverty, Billie). */
	const CAPTURE_METHODS = array( 'klarna', 'klarnapaylater', 'klarnapaynow', 'klarnasliceit', 'riverty', 'billie' );

	/** Currencies Mollie accepts. */
	const CURRENCIES = array( 'AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'ISK', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TWD', 'USD', 'ZAR' );

	/** ISO 4217 currencies without minor unit (plugin minor units = whole units). */
	const ZERO_DECIMAL = array( 'ISK', 'JPY' );

	/** Locales accepted by Mollie's hosted checkout. */
	const LOCALES = array( 'en_US', 'en_GB', 'nl_NL', 'nl_BE', 'fr_FR', 'fr_BE', 'de_DE', 'de_AT', 'de_CH', 'es_ES', 'ca_ES', 'pt_PT', 'it_IT', 'nb_NO', 'sv_SE', 'fi_FI', 'da_DK', 'is_IS', 'hu_HU', 'pl_PL', 'lv_LV', 'lt_LT' );

	public function id() { return 'mollie'; }

	public function title() {
		$title = __( 'Mollie (iDEAL, Kreditkarte, Bancontact u. a.)', 'pass-captain' );
		/* translators: %s: payment method title */
		return self::is_test_mode() ? sprintf( __( '%s – Testmodus', 'pass-captain' ), $title ) : $title;
	}

	/** Whether the API key is a test key (test_…): Mollie then processes no real payments (A274). */
	public static function is_test_mode() {
		return 0 === strpos( \PassCaptain\Secrets::get( 'mollie_api_key' ), 'test_' );
	}

	public function is_available( $currency ) {
		return 1 === preg_match( '/^(test|live)_[A-Za-z0-9]{20,}$/', \PassCaptain\Secrets::get( 'mollie_api_key' ) )
			&& in_array( strtoupper( (string) $currency ), self::CURRENCIES, true );
	}

	public function supports_refunds() { return true; }

	public static function config() {
		$c = (array) Settings::get( 'mollie' );
		return array(
			// Methods saved before A351 (e.g. klarna) are dropped: only methods the settings offer count.
			'methods' => array_values( array_intersect( array_filter( array_map( 'sanitize_key', (array) ( $c['methods'] ?? array() ) ) ), \PassCaptain\Modules\Mollie::KNOWN_METHODS ) ),
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
			// Back to the checkout page (A554); the return handler runs there on template_redirect.
			'redirectUrl' => \PassCaptain\Payments\ReturnNotice::with_args( \PassCaptain\Payments\ReturnNotice::base_of( $order ), array( 'wpcap_mollie_return' => '1', 'order' => $public_id ) ),
			'webhookUrl' => self::webhook_url(),
			'metadata' => array( 'order' => $public_id ),
		);
		$locale = (string) ( $order['locale'] ?? '' );
		if ( in_array( $locale, self::LOCALES, true ) ) { $body['locale'] = $locale; }
		$method = self::method_for_order( $order, $value, $currency );
		if ( is_wp_error( $method ) ) { return $method; }
		if ( '' !== $method && array() !== $method ) { $body['method'] = $method; }
		if ( 'banktransfer' === $method ) {
			// The transfer expires with the extended hold, not Mollie's default (~12 days): a transfer after
			// the tickets were released would only end in the late-payment re-check. Mollie's earliest due
			// date is tomorrow. The buyer chose bank transfer: the hold must last until the transfer can arrive
			// (QA-6), so it is extended first and the due date names the extended hold end.
			if ( ! empty( $order['order_id'] ) ) { \PassCaptain\Payments\Webhooks::extend_for_delayed_payment( (object) $order, 'mollie' ); }
			$body['dueDate'] = ! empty( $order['order_id'] ) ? self::due_date( (object) $order ) : gmdate( 'Y-m-d', \PassCaptain\Clock::now() + DAY_IN_SECONDS );
		}
		$body = apply_filters( 'wpcap_mollie_payment_body', $body, $order, $items );
		$payment = self::api( 'POST', 'payments', $body, 'wpcap-mollie-' . $public_id . $key_suffix . '-' . $total . strtolower( $currency ) );
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
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (payment_id,order_id,created_at) VALUES (%s,%d,%s)', $wpdb->prefix . 'wpcap_mollie_payments', $payment_id, (int) ( $order['order_id'] ?? 0 ), current_time( 'mysql', true ) ) );
	}

	/**
	 * Whether a payment id belongs to a Mollie order of this site: primary key of wpcap_mollie_payments,
	 * else (payments created before that table existed) the order index provider_ref (A431).
	 */
	public static function known_payment( $payment_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE payment_id=%s', $p . 'wpcap_mollie_payments', $payment_id ) ) ) { return true; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE payment_provider='mollie' AND provider_reference=%s LIMIT 1", $p . 'wpcap_orders', $payment_id ) );
	}

	/**
	 * Method parameter: the buyer's choice when it is enabled and offered for this amount, otherwise
	 * the enabled methods Mollie offers (array), or '' when no restriction is configured.
	 *
	 * Bank transfer (a delayed method: paid days later) only as the buyer's explicit choice, and then
	 * the hold is extended like a bank transfer hold (Orders::extend_hold_for_delayed_payment(), A577);
	 * it is never part of a method list (the checkout hold would end long before the money arrives).
	 * Without configured methods the list Mollie offers is passed without bank transfer. When bank
	 * transfer is the only method left, it is used with the extended hold (or refused close to the start).
	 *
	 * @return string|array|\WP_Error
	 */
	private static function method_for_order( array $order, $value, $currency ) {
		$enabled = self::config()['methods'];
		$order_id = (int) ( $order['order_id'] ?? 0 );
		if ( ! $enabled ) {
			$offered = self::available_methods( $value, $currency );
			$excluded = array_merge( array( self::DELAYED_METHOD ), self::CAPTURE_METHODS );
			if ( is_wp_error( $offered ) || ! array_intersect( array_keys( $offered ), $excluded ) ) { return ''; }
			$rest = array_values( array_diff( array_keys( $offered ), $excluded ) );
			if ( $rest ) { return $rest; }
			return isset( $offered[ self::DELAYED_METHOD ] ) ? self::delayed_only( $order_id ) : '';
		}
		$offered = self::available_methods( $value, $currency );
		$allowed = is_wp_error( $offered ) ? $enabled : array_values( array_intersect( $enabled, array_keys( $offered ) ) );
		$chosen = \PassCaptain\Modules\Mollie::chosen_method( $order_id );
		if ( '' !== $chosen && in_array( $chosen, $allowed, true ) ) {
			if ( self::DELAYED_METHOD !== $chosen || ( $order_id && null !== \PassCaptain\Orders::extend_hold_for_delayed_payment( $order_id ) ) ) { return $chosen; }
		}
		$rest = array_values( array_diff( $allowed, array( self::DELAYED_METHOD ) ) );
		if ( $rest ) { return $rest; }
		return $allowed ? self::delayed_only( $order_id ) : '';
	}

	/** Mollie method that confirms days later (see method_for_order()). */
	const DELAYED_METHOD = 'banktransfer';

	/** Bank transfer is the only method left: offered with an extended hold, refused when the start is too close. */
	private static function delayed_only( $order_id ) {
		if ( $order_id && null !== \PassCaptain\Orders::extend_hold_for_delayed_payment( $order_id ) ) { return self::DELAYED_METHOD; }
		return new \WP_Error( 'wpcap_manual_too_late', __( 'Eine Zahlung per Überweisung ist so kurz vor Beginn nicht mehr möglich. Bitte wähle eine andere Zahlungsart.', 'pass-captain' ), array( 'status' => 400 ) );
	}

	/** Background refresh of a method list the public event list asked for (A282). Args: amount value, currency. */
	const REFRESH_HOOK = 'wpcap_mollie_refresh_methods';

	/** A failed method lookup is remembered this long, so a Mollie outage does not slow every request (A282). */
	const METHODS_ERROR_TTL = 5 * MINUTE_IN_SECONDS;

	/** Timeout of the method lookup (seconds; payment creation keeps the longer default). */
	const METHODS_TIMEOUT = 3;

	/**
	 * Methods Mollie offers for amount/currency (and billing country).
	 *
	 * The method list is fetched once per currency and billing country (cached for one hour) and
	 * filtered locally by each method's minimumAmount/maximumAmount, so the event page does not call
	 * the API per ticket price. Mollie returns those limits in EUR when no amount is sent; for other
	 * currencies the limits cannot be compared locally, so the list is requested for the amount
	 * (Mollie filters it) and cached per amount. A failed lookup is cached for METHODS_ERROR_TTL (A282).
	 *
	 * @param string $value    Amount string (format_amount()).
	 * @param string $currency Currency.
	 * @param bool   $remote   false: never call Mollie in this request (page rendering) – on a cache miss
	 *                         the lookup is scheduled in the background (REFRESH_HOOK) and a
	 *                         wpcap_mollie_methods_pending error is returned.
	 * @return array|\WP_Error id => description
	 */
	public static function available_methods( $value, $currency, $remote = true ) {
		$currency = strtoupper( (string) $currency );
		$minor = self::parse_amount( (string) $value, $currency );
		if ( 'EUR' === $currency && $minor >= 0 ) {
			$catalog = self::method_catalog( array(), 'EUR', $remote, array( (string) $value, $currency ) );
			if ( is_wp_error( $catalog ) ) { return $catalog; }
			$methods = array();
			foreach ( $catalog as $id => $method ) {
				if ( self::within_limits( $method, $minor, $currency ) ) { $methods[ $id ] = $method['description']; }
			}
			return $methods;
		}
		$catalog = self::method_catalog( array( 'amount' => array( 'value' => (string) $value, 'currency' => $currency ) ), $currency, $remote, array( (string) $value, $currency ) );
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
	 * @param array  $query        Extra query parameters (e.g. amount).
	 * @param string $currency     Currency of the cache bucket.
	 * @param bool   $remote       false: never call Mollie in this request (A282, see available_methods()).
	 * @param array  $refresh_args Arguments of the background refresh (amount value, currency).
	 * @return array|\WP_Error id => {description, min, max}
	 */
	private static function method_catalog( array $query, $currency, $remote = true, array $refresh_args = array() ) {
		$query = array_merge( $query, array( 'sequenceType' => 'oneoff' ) );
		$country = self::config()['billing_country'];
		if ( '' !== $country ) { $query['billingCountry'] = $country; }
		$cache_key = 'wpcap_mollie_catalog_' . substr( hash( 'sha256', $currency . '|' . wp_json_encode( $query ) . '|' . substr( \PassCaptain\Secrets::get( 'mollie_api_key' ), 0, 5 ) ), 0, 20 );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['error'] ) ) {
			return new \WP_Error( 'wpcap_mollie_methods_unavailable', (string) $cached['error'], array( 'cached' => true ) );
		}
		if ( is_array( $cached ) && isset( $cached['methods'] ) ) { return (array) $cached['methods']; }
		if ( ! $remote ) {
			if ( $refresh_args && function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::REFRESH_HOOK, $refresh_args ) ) {
				wp_schedule_single_event( time(), self::REFRESH_HOOK, $refresh_args );
			}
			return new \WP_Error( 'wpcap_mollie_methods_pending', __( 'Die Zahlungsmethoden von Mollie werden gerade geladen.', 'pass-captain' ) );
		}
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

	/** REFRESH_HOOK handler: loads (and caches) the method list outside the page request. */
	public static function refresh_methods( $value = '', $currency = '' ) {
		if ( '' === (string) $value || ! preg_match( '/^[A-Za-z]{3}$/', (string) $currency ) ) { return; }
		self::available_methods( (string) $value, (string) $currency, true );
	}

	/**
	 * Enabled methods that Mollie offers for this amount (for the checkout selector).
	 *
	 * @param bool $remote Default false: the public event list renders from the cache only and loads a
	 *                     missing list in the background (A282); the selector then shows from the next view.
	 */
	public static function selectable_methods( $minor, $currency, $remote = false ) {
		$enabled = self::config()['methods'];
		if ( ! $enabled ) { return array(); }
		$offered = self::available_methods( self::format_amount( $minor, $currency ), $currency, $remote );
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
		// A431: the per-IP limit comes first, so unknown ids cost no database lookup beyond the counter.
		$throttled = new \WP_Error( 'wpcap_mollie_throttled', 'Too many webhook calls.', array( 'status' => 429 ) );
		if ( ! \PassCaptain\RateLimit::allow( 'mollie_webhook|' . \PassCaptain\RateLimit::client(), (int) apply_filters( 'wpcap_mollie_webhook_ip_limit', 120 ), 60 ) ) { return $throttled; }
		if ( ! self::known_payment( $id ) ) {
			// Not created by this site: acknowledge (200) without calling the API.
			return array( 'event_id' => 'mollie-unknown', 'type' => 'ignored', 'order_public_id' => '', 'provider_reference' => '', 'amount_minor' => 0, 'currency' => '' );
		}
		if ( ! \PassCaptain\RateLimit::allow( 'mollie_webhook|all', (int) apply_filters( 'wpcap_mollie_webhook_global_limit', 1200 ), 60 ) ) { return $throttled; }
		$payment = self::api( 'GET', 'payments/' . rawurlencode( $id ) . '?embed=refunds,chargebacks' );
		if ( is_wp_error( $payment ) ) {
			$status = (int) ( ( (array) $payment->get_error_data() )['status'] ?? 0 );
			// Unknown payment: not ours (404). Other failures: 503 so Mollie retries.
			return new \WP_Error( 'wpcap_mollie_lookup', $payment->get_error_message(), array( 'status' => 404 === $status ? 404 : 503 ) );
		}
		return self::normalize( $payment );
	}

	/**
	 * Reconciliation (A352): reads the order's Mollie payment and maps it like the webhook (same event
	 * ids, so a later webhook is a duplicate). An open payment is reported as "ignored".
	 */
	public function find_payment( array $order ) {
		$reference = (string) ( $order['provider_reference'] ?? '' );
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', $reference ) ) { return null; }
		$payment = self::api( 'GET', 'payments/' . rawurlencode( $reference ) . '?embed=refunds,chargebacks' );
		return is_wp_error( $payment ) ? $payment : self::normalize( $payment );
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
		// State of the plugin's own refunds (Mollie calls the webhook on refund changes as well): lets
		// Refunds settle a refund with an unclear outcome or a pending one (A272, A348).
		foreach ( (array) ( $payment['_embedded']['refunds'] ?? array() ) as $refund ) {
			if ( ! is_array( $refund ) || empty( $refund['metadata']['wpcap_refund'] ) ) { continue; }
			$event['refund_states'][] = array_merge( self::refund_state( $refund, $currency ), array( 'key' => (string) $refund['metadata']['wpcap_refund'] ) );
		}
		if ( 'paid' === $status ) {
			$order = '' !== $public_id ? $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status FROM %i WHERE public_id=%s AND payment_provider='mollie'", $wpdb->prefix . 'wpcap_orders', $public_id ) ) : null;
			$failed_refunds = $order ? self::failed_booked_refunds( $payment, (int) $order->order_id ) : array();
			if ( $failed_refunds ) {
				// A refund booked by the plugin failed later at Mollie: Webhooks reverses the booking.
				$event['event_id'] = 'mollie-' . $id . '-refund-failed-' . implode( '-', $failed_refunds );
				$event['type'] = 'refund_failed';
				$event['refund_ids'] = $failed_refunds;
				$event['refund_id'] = $failed_refunds[0];
				return $event;
			}
			$external = self::external_refunded_minor( $payment, $currency );
			$chargeback = self::charged_back_minor( $payment, $currency );
			if ( $order && 'pending' !== $order->payment_status && $chargeback > 0 ) {
				// Money pulled back by the bank/card issuer: same consequences as an external refund.
				$event['event_id'] = 'mollie-' . $id . '-chargeback-' . $chargeback;
				$event['type'] = 'refunded';
				$event['payment_references'] = array( $id );
				$event['chargeback'] = true;
				$event['refund_reason'] = 'chargeback';
				$event['cumulative'] = true; // Running total of chargebacks + external refunds.
				$event['amount_minor'] = $chargeback + $external;
				return $event;
			}
			if ( $order && 'pending' !== $order->payment_status && $external > 0 ) {
				// Refund made outside the plugin (Mollie dashboard); cumulative external amount.
				$event['event_id'] = 'mollie-' . $id . '-refunded-' . $external;
				$event['type'] = 'refunded';
				$event['payment_references'] = array( $id );
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
		} elseif ( 'authorized' === $status ) {
			// Klarna/Riverty: only authorized; Webhooks captures it (capture_authorized()), the capture's
			// "paid" webhook then confirms the order. An uncaptured authorization would lapse.
			$event['type'] = 'payment_authorized';
		} elseif ( in_array( $status, array( 'open', 'pending' ), true ) && in_array( (string) ( $payment['method'] ?? '' ), self::DELAYED_METHODS, true ) ) {
			// Bank transfer / SEPA direct debit: the money arrives days later; keep the tickets held (QA-6).
			$event['type'] = 'payment_pending';
			$event['method'] = (string) $payment['method'];
		}
		return $event;
	}

	/**
	 * Due date (Y-m-d, at least tomorrow, at most 100 days) of a bank transfer: the end of the order's
	 * (extended) hold, so Mollie does not accept a transfer after the tickets were released (A577). Without
	 * an active hold: the delayed-payment hold length.
	 */
	public static function due_date( $order ) {
		$end = \PassCaptain\Orders::hold_end( (int) ( $order->order_id ?? 0 ) );
		if ( $end > 0 ) {
			return gmdate( 'Y-m-d', min( max( $end, \PassCaptain\Clock::now() + DAY_IN_SECONDS ), \PassCaptain\Clock::now() + 100 * DAY_IN_SECONDS ) );
		}
		$minutes = \PassCaptain\Payments\Webhooks::delayed_hold_minutes( $order, 'mollie' );
		$days = max( 1, min( 100, (int) floor( $minutes / ( DAY_IN_SECONDS / MINUTE_IN_SECONDS ) ) ) );
		return gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Captures an authorized payment in full (POST /v2/payments/{id}/captures, idempotent per payment).
	 *
	 * @return true|\WP_Error
	 */
	public function capture_authorized( array $order, array $event ) {
		$id = (string) ( $event['provider_reference'] ?? '' );
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', $id ) ) {
			return new \WP_Error( 'wpcap_mollie_capture', __( 'Unbekannte Mollie-Zahlung.', 'pass-captain' ) );
		}
		$capture = self::api( 'POST', 'payments/' . rawurlencode( $id ) . '/captures', array( 'description' => mb_substr( sprintf( /* translators: %s: order number */ __( 'Bestellung %s', 'pass-captain' ), (string) ( $order['public_id'] ?? '' ) ), 0, 255 ) ), 'wpcap-capture-' . $id );
		if ( is_wp_error( $capture ) ) {
			$data = (array) $capture->get_error_data();
			return new \WP_Error( $capture->get_error_code(), $capture->get_error_message(), array( 'status' => (int) ( $data['status'] ?? 0 ) ) );
		}
		return true;
	}

	/**
	 * Hold extended for a delayed method: moves the bank transfer's due date to the new hold end
	 * (PATCH /v2/payments/{id}, dueDate), so Mollie does not accept money after the tickets were released.
	 */
	public function after_hold_extended( array $order, array $event ) {
		if ( 'banktransfer' !== (string) ( $event['method'] ?? '' ) ) { return true; }
		$id = (string) ( $event['provider_reference'] ?? '' );
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', $id ) ) { return true; }
		$result = self::api( 'PATCH', 'payments/' . rawurlencode( $id ), array( 'dueDate' => self::due_date( (object) $order ) ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Cancels an open payment of a cancelled/expired order (DELETE /v2/payments/{id} when Mollie reports
	 * isCancelable), so the buyer cannot pay it any more.
	 *
	 * @return true|\WP_Error
	 */
	public function void_payment( array $order ) {
		$id = (string) ( $order['provider_reference'] ?? '' );
		if ( 0 !== strpos( $id, 'tr_' ) ) { return true; }
		$payment = self::api( 'GET', 'payments/' . rawurlencode( $id ) );
		if ( is_wp_error( $payment ) ) { return $payment; }
		if ( empty( $payment['isCancelable'] ) ) { return true; }
		$deleted = self::api( 'DELETE', 'payments/' . rawurlencode( $id ) );
		return is_wp_error( $deleted ) ? $deleted : true;
	}

	/**
	 * Status poll for a pending order whose webhook did not arrive: the payment is read like in the
	 * webhook (same event ids, so a later webhook is a duplicate).
	 *
	 * @return array|\WP_Error|null Normalized event.
	 */
	public function poll_payment( array $order ) {
		$id = (string) ( $order['provider_reference'] ?? '' );
		if ( ! preg_match( '/^tr_[A-Za-z0-9]{4,64}$/', $id ) ) { return null; }
		$payment = self::api( 'GET', 'payments/' . rawurlencode( $id ) . '?embed=refunds,chargebacks' );
		return is_wp_error( $payment ) ? $payment : self::normalize( $payment );
	}

	/**
	 * Ids of embedded refunds with status failed that are still booked as completed for the order
	 * (plugin refunds store the Mollie refund id as provider reference).
	 *
	 * @return string[]
	 */
	private static function failed_booked_refunds( array $payment, $order_id ) {
		global $wpdb;
		$ids = array();
		foreach ( (array) ( $payment['_embedded']['refunds'] ?? array() ) as $refund ) {
			if ( 'failed' === ( $refund['status'] ?? '' ) && preg_match( '/^re_[A-Za-z0-9]{4,64}$/', (string) ( $refund['id'] ?? '' ) ) ) { $ids[] = (string) $refund['id']; }
		}
		if ( ! $ids ) { return array(); }
		$refs = array_merge( $ids, array_map( static function ( $id ) { return 'ext:mollie:' . $id; }, $ids ) );
		$booked = (array) $wpdb->get_col( $wpdb->prepare( "SELECT provider_reference FROM %i WHERE order_id=%d AND status='completed' AND provider_reference IN (" . implode( ',', array_fill( 0, count( $refs ), '%s' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_refunds', absint( $order_id ) ), $refs ) ) );
		$booked = array_map( static function ( $ref ) { return preg_replace( '/^ext:mollie:/', '', (string) $ref ); }, $booked );
		return array_values( array_intersect( $ids, $booked ) );
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
			return \PassCaptain\Refunds::definitive( new \WP_Error( 'wpcap_mollie_refund_reference', __( 'Für diese Bestellung ist keine Mollie-Zahlung bekannt.', 'pass-captain' ) ) );
		}
		$body = array(
			'amount' => array( 'currency' => $currency, 'value' => self::format_amount( (int) $amount_minor, $currency ) ),
			/* translators: %s: order number */
			'description' => mb_substr( sprintf( __( 'Erstattung %s', 'pass-captain' ), (string) ( $order['public_id'] ?? '' ) ), 0, 140 ),
			'metadata' => array( 'order' => (string) ( $order['public_id'] ?? '' ), 'wpcap_refund' => (string) $idempotency_key ),
		);
		// Transport errors, timeouts, 5xx and 409 come back marked as "outcome unknown" (api()).
		$refund = self::api( 'POST', 'payments/' . rawurlencode( $reference ) . '/refunds', $body, (string) $idempotency_key );
		if ( is_wp_error( $refund ) ) { return $refund; }
		if ( empty( $refund['id'] ) ) {
			return new \WP_Error( 'wpcap_mollie_response', __( 'Unerwartete Antwort von Mollie.', 'pass-captain' ), array( 'outcome' => 'unknown' ) );
		}
		if ( in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ) {
			return new \WP_Error( 'wpcap_mollie_refund_failed', __( 'Mollie hat die Erstattung abgelehnt.', 'pass-captain' ) );
		}
		$state = self::refund_state( $refund, $currency );
		return 'pending' === $state['status'] ? $state : (string) $refund['id'];
	}

	/** Looks up a plugin refund by its id or its metadata wpcap_refund (see RefundLookupInterface). */
	public function find_refund( array $order, $key, $reference = '' ) {
		$payment = (string) ( $order['provider_reference'] ?? '' );
		$currency = strtoupper( (string) ( $order['currency'] ?? '' ) );
		if ( 0 !== strpos( $payment, 'tr_' ) ) {
			return new \WP_Error( 'wpcap_mollie_refund_reference', __( 'Für diese Bestellung ist keine Mollie-Zahlung bekannt.', 'pass-captain' ) );
		}
		if ( 0 === strpos( (string) $reference, 're_' ) ) {
			$refund = self::api( 'GET', 'payments/' . rawurlencode( $payment ) . '/refunds/' . rawurlencode( (string) $reference ) );
			return is_wp_error( $refund ) ? $refund : self::refund_state( $refund, $currency );
		}
		$list = self::api( 'GET', 'payments/' . rawurlencode( $payment ) . '/refunds?limit=250' );
		if ( is_wp_error( $list ) ) { return $list; }
		if ( ! empty( $list['_links']['next']['href'] ) ) {
			return new \WP_Error( 'wpcap_mollie_refund_list', __( 'Die Erstattungen der Mollie-Zahlung konnten nicht vollständig gelesen werden.', 'pass-captain' ) );
		}
		$found = null;
		foreach ( (array) ( $list['_embedded']['refunds'] ?? array() ) as $refund ) {
			if ( ! is_array( $refund ) || (string) ( $refund['metadata']['wpcap_refund'] ?? '' ) !== (string) $key ) { continue; }
			$found = \PassCaptain\Refunds::better_state( $found, self::refund_state( $refund, $currency ) );
		}
		return $found;
	}

	/** Normalized state of a Mollie refund (queued/pending/processing = pending, refunded = succeeded). */
	private static function refund_state( array $refund, $currency ) {
		$status = (string) ( $refund['status'] ?? '' );
		if ( in_array( $status, array( 'failed', 'canceled' ), true ) ) {
			$status = 'failed';
		} elseif ( in_array( $status, array( 'queued', 'pending', 'processing' ), true ) ) {
			$status = 'pending';
		} else {
			$status = 'succeeded';
		}
		return array( 'status' => $status, 'reference' => (string) ( $refund['id'] ?? '' ), 'amount_minor' => self::parse_amount( $refund['amount']['value'] ?? '', $currency ) );
	}

	/** Public for the return handler: fetches a payment. */
	public static function get_payment( $id ) {
		return self::api( 'GET', 'payments/' . rawurlencode( (string) $id ) );
	}

	/** API call; returns decoded body or WP_Error (data.status = HTTP code). */
	private static function api( $method, $path, $body = null, $idempotency_key = '', $timeout = 15 ) {
		$key = \PassCaptain\Secrets::get( 'mollie_api_key' );
		if ( '' === $key ) {
			return new \WP_Error( 'wpcap_mollie_config', __( 'Mollie ist nicht konfiguriert.', 'pass-captain' ) );
		}
		$args = array( 'method' => $method, 'timeout' => (int) $timeout, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ) );
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
			if ( '' !== $idempotency_key ) { $args['headers']['Idempotency-Key'] = substr( $idempotency_key, 0, 255 ); }
		} elseif ( 'PATCH' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}
		$response = \PassCaptain\OutboundHttp::request( self::API . $path, $args, array( 'context' => 'mollie', 'hosts' => array( 'api.mollie.com' ) ) );
		if ( is_wp_error( $response ) ) {
			// A POST that timed out or lost its connection may have been executed (A272).
			/* translators: %s: error message */
			return new \WP_Error( 'wpcap_mollie_http', sprintf( __( 'Mollie ist nicht erreichbar: %s', 'pass-captain' ), \PassCaptain\Redact::text( $response->get_error_message() ) ), 'POST' === $method ? array( 'outcome' => 'unknown' ) : array() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && ! empty( $data['detail'] ) ? (string) $data['detail'] : 'HTTP ' . $code;
			$error_data = array( 'status' => $code );
			if ( 'POST' === $method ) { $error_data['outcome'] = \PassCaptain\Refunds::outcome_for_status( $code ); }
			/* translators: %s: error message from Mollie */
			return new \WP_Error( 'wpcap_mollie_api', sprintf( __( 'Mollie-Fehler: %s', 'pass-captain' ), \PassCaptain\Redact::text( $message ) ), $error_data );
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
	public static function return_url( $status, $public_id, $base = '' ) {
		return \PassCaptain\Payments\ReturnNotice::url( $status, $public_id, $base, 'mollie' );
	}

	/** Buyer cancelled on the return page (A554): cancel a still open Mollie payment; errors are ignored. */
	public function abandon_payment( array $order ) {
		$reference = (string) ( $order['provider_reference'] ?? '' );
		if ( 0 !== strpos( $reference, 'tr_' ) ) { return; }
		self::api( 'DELETE', 'payments/' . rawurlencode( $reference ) );
	}
}
