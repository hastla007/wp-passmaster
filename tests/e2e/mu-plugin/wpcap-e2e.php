<?php
/**
 * Plugin Name: Pass Captain E2E helpers (test sites only)
 * Description: Fake hosted payment page for the browser E2E tests. Copied into wp-content/mu-plugins of a
 * disposable test site by tests/e2e/setup.js. NEVER install this on a real site.
 *
 * - Payment gateway "e2e_fake" ("Testzahlung"), offered only to browsers with the cookie wpcap_e2e=1, so other
 *   browser tests (a11y) on the same site see the normal checkout.
 * - ?wpcap_e2e_pay=<order public id>: hosted payment page; "Jetzt bezahlen" confirms the payment like a
 *   provider webhook would (Orders::confirm_external_payment) and shows the order portal link from the order mail.
 * - Bot check of the checkout form: minimum fill time off for web requests unless the cookie wpcap_e2e_guard=1.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'plugins_loaded', static function () {
	if ( ! interface_exists( '\PassCaptain\Payments\GatewayInterface' ) ) { return; }

	final class WPCAP_E2E_Fake_Gateway implements \PassCaptain\Payments\GatewayInterface {
		public function id() { return 'e2e_fake'; }
		public function title() { return 'Testzahlung (E2E)'; }
		public function is_available( $currency ) { return true; }
		public function create_payment( array $order, array $items = array() ) {
			return array( 'provider' => 'e2e_fake', 'status' => 'pending', 'provider_reference' => 'e2e-' . $order['public_id'], 'redirect_url' => add_query_arg( 'wpcap_e2e_pay', rawurlencode( $order['public_id'] ), home_url( '/' ) ) );
		}
		public function verify_webhook( \WP_REST_Request $request ) { return new \WP_Error( 'e2e', 'no webhooks' ); }
		public function supports_refunds() { return false; }
		public function refund( array $order, $amount_minor, $idempotency_key ) { return new \WP_Error( 'e2e', 'no refunds' ); }
	}

	add_filter( 'wpcap_payment_gateways', static function ( $list ) { $list[] = new WPCAP_E2E_Fake_Gateway(); return $list; } );
	if ( ( $_COOKIE['wpcap_e2e'] ?? '' ) === '1' || isset( $_GET['wpcap_e2e_pay'] ) ) {
		$enable = static function ( $settings ) {
			$settings = is_array( $settings ) ? $settings : array();
			$current = isset( $settings['enabled_providers'] ) ? (array) $settings['enabled_providers'] : array( 'manual' );
			$settings['enabled_providers'] = array_values( array_unique( array_merge( $current, array( 'e2e_fake' ) ) ) );
			return $settings;
		};
		add_filter( 'option_wpcap_settings', $enable );
		add_filter( 'default_option_wpcap_settings', $enable ); // fresh site: the option does not exist yet
	}
}, 1 );

// Bot check of the checkout form (Security\FormGuard): the tests fill a form in far less than the minimum fill
// time (default 3 s), so the time check is off on the test site. The trap field stays on. Browsers with the
// cookie wpcap_e2e_guard=1 get the real threshold (conditions-widgets.test.js checks the "too fast" hint).
// Web requests only: the CLI helpers read and save the stored settings and must not persist the override.
if ( 'cli' !== PHP_SAPI && ( $_COOKIE['wpcap_e2e_guard'] ?? '' ) !== '1' ) {
	$wpcap_e2e_fast = static function ( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings['checkout_min_fill_seconds'] = 0;
		return $settings;
	};
	add_filter( 'option_wpcap_settings', $wpcap_e2e_fast, 20 );
	add_filter( 'default_option_wpcap_settings', $wpcap_e2e_fast, 20 );
}

// The order access link goes out by mail when the order is created (REST checkout): keep it for the payment page.
add_filter( 'pre_wp_mail', static function ( $short, $atts ) {
	$body = is_array( $atts['message'] ?? null ) ? implode( "\n", $atts['message'] ) : (string) ( $atts['message'] ?? '' );
	if ( preg_match( '#https?://[^\s"\'<>]*wpcap_order=([a-f0-9]{32})[^\s"\'<>]*#', $body, $m ) ) {
		set_transient( 'wpcap_e2e_portal_' . $m[1], html_entity_decode( $m[0] ), HOUR_IN_SECONDS );
	}
	return $short;
}, 5, 2 );

add_action( 'template_redirect', static function () {
	if ( ! isset( $_GET['wpcap_e2e_pay'] ) ) { return; }
	global $wpdb;
	$public = sanitize_text_field( wp_unslash( $_GET['wpcap_e2e_pay'] ) );
	$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public ) );
	if ( ! $order ) { status_header( 404 ); exit( 'unknown order' ); }
	$portal = (string) get_transient( 'wpcap_e2e_portal_' . $public );
	$state = '';
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pay'] ) ) {
		$result = \PassCaptain\Orders::confirm_external_payment( (int) $order->order_id, 'e2e-' . $public );
		$state = is_wp_error( $result ) ? 'Fehler: ' . $result->get_error_message() : 'Zahlung erfolgreich';
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Testzahlung</title></head><body><main>';
	echo '<h1>Testzahlung</h1><p id="e2e-amount">' . esc_html( number_format( $order->total_minor / 100, 2, ',', '.' ) . ' ' . $order->currency ) . '</p>';
	if ( $state ) {
		echo '<p id="e2e-state" role="status">' . esc_html( $state ) . '</p>';
		if ( $portal ) { echo '<p><a id="e2e-portal" href="' . esc_url( $portal ) . '">Zum Bestellportal</a></p>'; }
	} else {
		echo '<form method="post"><button type="submit" name="pay" value="1">Jetzt bezahlen</button></form>';
	}
	echo '</main></body></html>';
	exit;
} );
