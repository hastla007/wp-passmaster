<?php
/**
 * Smoke check for an installed and activated release ZIP, run by build/ci-install-test.sh via
 * `wp eval-file build/ci-smoke.php`. Prints one line per check and exits with 1 on any failure.
 *
 * Optional argument (WP-CLI positional arg): expected HPOS state "on" or "off" when WooCommerce is active.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$wpcap_failures = 0;
$wpcap_check    = static function ( $label, $ok, $detail = '' ) use ( &$wpcap_failures ) {
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $label . ( '' !== $detail ? " ($detail)" : '' ) . "\n";
	if ( ! $ok ) { ++$wpcap_failures; }
};

global $wpdb;

// Plugin loaded from the ZIP with its Composer autoloader.
$wpcap_check( 'plugin active', is_plugin_active( 'pass-captain/pass-captain.php' ) );
$wpcap_check( 'WPCAP_VERSION defined', defined( 'WPCAP_VERSION' ), defined( 'WPCAP_VERSION' ) ? WPCAP_VERSION : '' );
$wpcap_check( 'plugin path is the installed copy', defined( 'WPCAP_PATH' ) && str_ends_with( wp_normalize_path( WPCAP_PATH ), '/plugins/pass-captain/' ), defined( 'WPCAP_PATH' ) ? WPCAP_PATH : '' );
$wpcap_check( 'Composer autoloader present', is_readable( WPCAP_PATH . 'vendor/autoload.php' ) );
$wpcap_check( 'plugin classes autoload', class_exists( PassCaptain\Plugin::class ) && class_exists( PassCaptain\Orders::class ) );
$wpcap_check( 'runtime dependency endroid/qr-code autoloads', class_exists( Endroid\QrCode\QrCode::class ) );
$qr = PassCaptain\QrCode::data_uri( 'pass-captain-ci' );
$wpcap_check( 'QR code renders', is_string( $qr ) && str_starts_with( $qr, 'data:image/' ), is_string( $qr ) ? substr( $qr, 0, 22 ) : gettype( $qr ) );

// Schema: tables, version and fingerprint written by the installer.
$tables   = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpcap_' ) . '%' ) );
$expected = array( 'events', 'occurrences', 'ticket_types', 'orders', 'order_items', 'reservations', 'tickets', 'checkins', 'audit_logs', 'message_queue', 'api_keys', 'payment_events', 'refunds', 'jobs' );
$missing  = array_values( array_diff( array_map( static fn( $t ) => $wpdb->prefix . 'wpcap_' . $t, $expected ), $tables ) );
$wpcap_check( 'plugin tables created', count( $tables ) >= 40 && ! $missing, count( $tables ) . ' tables' . ( $missing ? ', missing: ' . implode( ', ', $missing ) : '' ) );
$wpcap_check( 'schema version stored', (string) get_option( 'wpcap_db_version' ) === (string) PassCaptain\Installer::version(), (string) get_option( 'wpcap_db_version' ) );
$wpcap_check( 'schema fingerprint stored', get_option( 'wpcap_schema_fingerprint' ) === PassCaptain\Installer::fingerprint() );
$wpcap_check( 'plugin version option', get_option( 'wpcap_version' ) === WPCAP_VERSION, (string) get_option( 'wpcap_version' ) );
$wpcap_check( 'administrator has plugin capabilities', ( $admin = get_role( 'administrator' ) ) && $admin->has_cap( 'wpcap_manage_events' ) && $admin->has_cap( 'wpcap_checkin' ) );
$wpcap_check( 'cron events scheduled', (bool) wp_next_scheduled( 'wpcap_cleanup_reservations' ) );
$wpcap_check( 'settings readable', is_array( PassCaptain\Settings::all() ) );

// REST API: routes registered and a public endpoint answers.
$server = rest_get_server();
$routes = array_filter( array_keys( $server->get_routes() ), static fn( $r ) => str_starts_with( $r, '/wp-pass-captain/v1' ) );
$wpcap_check( 'REST routes registered', count( $routes ) >= 10, count( $routes ) . ' routes' );
$response = rest_do_request( new WP_REST_Request( 'GET', '/wp-pass-captain/v1/events' ) );
$wpcap_check( 'GET /wp-pass-captain/v1/events', 200 === $response->get_status(), 'HTTP ' . $response->get_status() );

// WooCommerce: adapter declared HPOS/Blocks compatibility, HPOS in the expected state.
$hpos_expected = $args[0] ?? '';
if ( class_exists( 'WooCommerce' ) ) {
	$wpcap_check( 'WooCommerce loaded', true, WC()->version );
	$util = Automattic\WooCommerce\Utilities\FeaturesUtil::class;
	if ( method_exists( $util, 'get_compatible_plugins_for_feature' ) ) {
		$compat = $util::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$wpcap_check( 'HPOS compatibility declared', in_array( 'pass-captain/pass-captain.php', $compat['compatible'] ?? array(), true ) );
	}
	$hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( '' !== $hpos_expected ) {
		$wpcap_check( 'HPOS state', ( 'on' === $hpos_expected ) === $hpos, $hpos ? 'on' : 'off' );
	}
	$wpcap_check( 'WooCommerce order adapter available', class_exists( PassCaptain\Integrations\WooCommerce\OrderAdapter::class ) );
	// A WooCommerce order can be created through the active order storage (HPOS or posts).
	$order = wc_create_order();
	$wpcap_check( 'WooCommerce order storage writable', $order instanceof WC_Order && $order->get_id() > 0, $order instanceof WC_Order ? (string) $order->get_data_store()->get_current_class_name() : '' );
	if ( $order instanceof WC_Order ) { $order->delete( true ); }
} elseif ( '' !== $hpos_expected ) {
	$wpcap_check( 'WooCommerce loaded', false );
}

if ( $wpcap_failures ) {
	echo "$wpcap_failures smoke check(s) failed.\n";
	exit( 1 );
}
echo "All smoke checks passed.\n";
