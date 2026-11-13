<?php
/**
 * Mollie payments: registers the gateway (wpcap_payment_gateways), the optional payment method
 * selector in the checkout (only enabled methods Mollie offers for amount/currency), the buyer's
 * return handler (?wpcap_mollie_return=1) and the settings page. The webhook uses the generic
 * payment route /wp-pass-captain/v1/payments/mollie/webhook.
 */
namespace PassCaptain\Modules;

use PassCaptain\Admin\Forms;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mollie {
	const CAP = 'manage_options';
	const PAGE = 'wpcap-mollie';

	/** Methods offered in the settings (Mollie ids). */
	const KNOWN_METHODS = array( 'applepay', 'bancontact', 'banktransfer', 'belfius', 'blik', 'creditcard', 'eps', 'giftcard', 'ideal', 'kbc', 'klarna', 'mybank', 'paypal', 'paysafecard', 'przelewy24', 'riverty', 'satispay', 'trustly', 'twint' );

	public static function register() {
		add_filter( 'wpcap_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_filter( 'wpcap_checkout_payload_keys', array( __CLASS__, 'payload_keys' ) );
		add_action( 'wpcap_checkout_form_fields', array( __CLASS__, 'render_checkout_fields' ), 30, 2 );
		add_action( 'wpcap_order_creating', array( __CLASS__, 'store_method' ), 10, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_return' ) );
		add_filter( 'wpcap_payment_settings_url', array( __CLASS__, 'settings_url' ), 10, 2 );
	}

	/** wpcap_payment_settings_url: the payments tab of the settings links to this page. */
	public static function settings_url( $url, $provider_id ) {
		return 'mollie' === $provider_id ? Forms::page_url( self::PAGE ) : $url;
	}

	public static function schema( $prefix, $charset ) {
		return array(
			"CREATE TABLE {$prefix}wpcap_mollie_choices (
				order_id bigint(20) unsigned NOT NULL,
				method varchar(32) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (order_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}wpcap_mollie_payments (
				payment_id varchar(64) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (payment_id),
				KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
		);
	}

	public static function settings_defaults() {
		return array(
			'mollie' => array( 'methods' => array(), 'billing_country' => '' ),
			'mollie_api_key' => '',
		);
	}

	public static function admin_pages() {
		return array( array( self::PAGE, __( 'Mollie', 'wp-pass-captain' ), self::CAP, array( __CLASS__, 'render_admin' ) ) );
	}

	public static function admin_handlers() {
		return array( 'wpcap_mollie_save' => array( __CLASS__, 'handle_save' ) );
	}

	public static function add_gateway( $gateways ) {
		$gateways[] = new MollieGateway();
		return $gateways;
	}

	public static function payload_keys( $keys ) {
		return array_merge( (array) $keys, array( 'mollie_method' ) );
	}

	/** wpcap_order_creating: remember the buyer's method for Mollie orders (validated against the enabled list). */
	public static function store_method( $order_id, $selected = array(), $payload = array(), $internal = array() ) {
		global $wpdb;
		if ( 'mollie' !== ( $internal['payment_provider'] ?? '' ) ) { return; }
		$method = sanitize_key( is_scalar( $payload['mollie_method'] ?? null ) ? $payload['mollie_method'] : '' );
		if ( '' === $method || ! in_array( $method, MollieGateway::config()['methods'], true ) ) { return; }
		$wpdb->query( $wpdb->prepare( "REPLACE INTO {$wpdb->prefix}wpcap_mollie_choices (order_id,method,created_at) VALUES (%d,%s,%s)", absint( $order_id ), $method, current_time( 'mysql', true ) ) );
	}

	public static function chosen_method( $order_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT method FROM {$wpdb->prefix}wpcap_mollie_choices WHERE order_id=%d", absint( $order_id ) ) );
	}

	public static function render_checkout_fields( $event = null, $types = null ) {
		if ( ! is_array( $types ) || ! $types ) { return; }
		$enabled = (array) Settings::get( 'enabled_providers' );
		$currency = (string) $types[0]->currency;
		$gateway = new MollieGateway();
		if ( ! in_array( 'mollie', $enabled, true ) || ! $gateway->is_available( $currency ) ) { return; }
		// The cheapest paid ticket as reference amount; the final amount is checked again on payment.
		$min = 0;
		foreach ( $types as $type ) {
			$price = \PassCaptain\Orders::unit_price( $type );
			if ( $price > 0 && ( ! $min || $price < $min ) ) { $min = $price; }
		}
		$methods = $min ? MollieGateway::selectable_methods( $min, $currency ) : array();
		if ( count( $methods ) < 2 ) { return; }
		if ( function_exists( 'wp_enqueue_script' ) ) { wp_enqueue_script( 'wpcap-mollie-checkout', WPCAP_URL . 'assets/mollie/checkout-mollie.js', array(), WPCAP_VERSION, true ); }
		$id = 'wpcap-mollie-' . ( is_object( $event ) ? (int) $event->occurrence_id : 0 );
		echo '<fieldset class="wpcap-providers wpcap-mollie-methods" hidden><legend>' . esc_html__( 'Zahlungsmethode bei Mollie', 'wp-pass-captain' ) . '</legend>';
		echo '<label><input type="radio" name="mollie_method" value="" checked> ' . esc_html__( 'Auf der Zahlungsseite wählen', 'wp-pass-captain' ) . '</label>';
		foreach ( $methods as $method => $label ) {
			echo '<label for="' . esc_attr( $id . '-' . $method ) . '"><input type="radio" id="' . esc_attr( $id . '-' . $method ) . '" name="mollie_method" value="' . esc_attr( $method ) . '"> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset>';
	}

	/** Buyer returns from Mollie: confirm a paid payment right away (same event id as the webhook). */
	public static function maybe_handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- redirect from Mollie; state is fetched from the API.
		if ( empty( $_GET['wpcap_mollie_return'] ) ) { return; }
		$public_id = sanitize_text_field( wp_unslash( (string) ( $_GET['order'] ?? '' ) ) );
		// phpcs:enable
		$state = self::handle_return( $public_id );
		nocache_headers();
		wp_safe_redirect( MollieGateway::return_url( $state, $public_id ) );
		exit;
	}

	/** @return string success|pending|failed|cancel */
	public static function handle_return( $public_id ) {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,payment_status,provider_reference FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s AND payment_provider='mollie'", sanitize_text_field( (string) $public_id ) ) );
		if ( ! $order ) { return 'failed'; }
		if ( 'paid' === $order->payment_status ) { return 'success'; }
		if ( 0 !== strpos( (string) $order->provider_reference, 'tr_' ) ) { return 'pending'; }
		$payment = MollieGateway::get_payment( $order->provider_reference );
		if ( is_wp_error( $payment ) ) { return 'pending'; }
		$status = (string) ( $payment['status'] ?? '' );
		if ( 'paid' === $status ) {
			$event = MollieGateway::normalize( $payment );
			if ( $event['order_public_id'] === $public_id ) {
				$result = Webhooks::process( 'mollie', $event );
				return in_array( $result, array( 'paid', 'duplicate' ), true ) ? 'success' : 'pending';
			}
			return 'pending';
		}
		if ( 'canceled' === $status ) { return 'cancel'; }
		return in_array( $status, array( 'failed', 'expired' ), true ) ? 'failed' : 'pending';
	}

	/* ------------------------------------------------------------------ Admin */

	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$methods = array_values( array_intersect( self::KNOWN_METHODS, array_map( 'sanitize_key', (array) ( $in['methods'] ?? array() ) ) ) );
		$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['billing_country'] ?? '' ) ) );
		$update = array( 'mollie' => array( 'methods' => $methods, 'billing_country' => 2 === strlen( $country ) ? $country : '' ) );
		if ( Forms::flag( $in, 'mollie_api_key__clear' ) ) {
			$update['mollie_api_key'] = '';
		} elseif ( '' !== trim( (string) ( $in['mollie_api_key'] ?? '' ) ) ) {
			$key = trim( sanitize_text_field( (string) $in['mollie_api_key'] ) );
			if ( ! preg_match( '/^(test|live)_[A-Za-z0-9]{20,}$/', $key ) ) {
				return Forms::error( 'wpcap_mollie_key', __( 'Der Mollie-API-Schlüssel muss mit test_ oder live_ beginnen.', 'wp-pass-captain' ) );
			}
			$update['mollie_api_key'] = $key;
		}
		Settings::update( $update );
		return array( 'saved' => array_keys( $update ) );
	}

	public static function handle_save() {
		Forms::verify( 'wpcap_mollie_save' );
		Forms::finish( self::save( Forms::post() ), __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::PAGE ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		$c = MollieGateway::config();
		echo '<div class="wrap wpcap-admin"><h1>Mollie</h1>';
		Forms::render_notices();
		echo '<p>' . esc_html__( 'Webhook-URL (wird pro Zahlung automatisch übergeben):', 'wp-pass-captain' ) . ' <code>' . esc_html( MollieGateway::webhook_url() ) . '</code></p>';
		echo '<p>' . esc_html__( 'Aktiviere „Mollie“ zusätzlich unter Einstellungen → Zahlungsarten.', 'wp-pass-captain' ) . ' <a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\SettingsPage::SLUG, array( 'tab' => 'payments' ) ) ) . '">' . esc_html__( 'Zu den Zahlungsarten', 'wp-pass-captain' ) . '</a></p>';
		Forms::open_form( 'wpcap_mollie_save' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpcap-mollie-key">' . esc_html__( 'API-Schlüssel', 'wp-pass-captain' ) . '</label></th><td>';
		\PassCaptain\Secrets::render_field( 'mollie_api_key', 'wpcap-mollie-key' );
		echo '<p class="description">' . esc_html__( 'Format: test_… oder live_…', 'wp-pass-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Zahlungsmethoden', 'wp-pass-captain' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Zahlungsmethoden', 'wp-pass-captain' ) . '</legend>';
		foreach ( self::KNOWN_METHODS as $method ) {
			echo '<label style="display:inline-block;min-width:11rem"><input type="checkbox" name="methods[]" value="' . esc_attr( $method ) . '"' . checked( in_array( $method, $c['methods'], true ), true, false ) . '> ' . esc_html( $method ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Leer = alle in deinem Mollie-Profil aktiven Methoden. Angezeigt werden nur Methoden, die Mollie für Betrag, Währung und Land anbietet (Abfrage wird eine Stunde zwischengespeichert).', 'wp-pass-captain' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="wpcap-mollie-country">' . esc_html__( 'Rechnungsland für die Methodenauswahl', 'wp-pass-captain' ) . '</label></th><td><input type="text" id="wpcap-mollie-country" name="billing_country" maxlength="2" size="3" value="' . esc_attr( $c['billing_country'] ) . '"> <span class="description">' . esc_html__( 'ISO-Code, z. B. DE, NL, BE (optional).', 'wp-pass-captain' ) . '</span></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Speichern', 'wp-pass-captain' ) );
		echo '</form></div>';
	}
}
