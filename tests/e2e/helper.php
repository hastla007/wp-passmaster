<?php
/**
 * Server-side helper for the browser E2E tests (disposable test site only). Prints JSON.
 *
 *   php tests/e2e/helper.php /path/to/wordpress pairing <event_id>          → offline scanning on, pairing code
 *   php tests/e2e/helper.php /path/to/wordpress assign <event_id> <occ_id>  → assigns the newest paired device
 *   php tests/e2e/helper.php /path/to/wordpress ticket <code>               → ticket status by code
 *   php tests/e2e/helper.php /path/to/wordpress order <public_id>           → payment status and tickets
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$root = rtrim( $argv[1] ?? '', '/' );
$cmd  = $argv[2] ?? '';
if ( ! $root || ! is_file( "$root/wp-load.php" ) || ! $cmd ) {
	fwrite( STDERR, "Usage: php tests/e2e/helper.php /path/to/wordpress <pairing|assign|ticket|order> [args]\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define( 'WP_USE_THEMES', false );
require "$root/wp-load.php";

global $wpdb;
$p = $wpdb->prefix;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );

switch ( $cmd ) {
	case 'pairing':
		$event = (int) ( $argv[3] ?? 0 );
		$rules = \PassCaptain\OfflineScanner\Rules::save( $event, array( 'enabled' => 1, 'max_offline_hours' => 12, 'duplicate_policy' => 'first_sync_wins', 'include_attendee' => 0 ) );
		if ( is_wp_error( $rules ) ) { fwrite( STDERR, $rules->get_error_message() . "\n" ); exit( 1 ); }
		$pairing = \PassCaptain\Mobile\Devices::create_pairing( array( $event ), 'E2E', 2 );
		if ( is_wp_error( $pairing ) ) { fwrite( STDERR, $pairing->get_error_message() . "\n" ); exit( 1 ); }
		echo wp_json_encode( $pairing );
		break;
	case 'assign':
		$device = $wpdb->get_row( "SELECT device_id FROM {$p}wpcap_scanner_devices ORDER BY device_id DESC LIMIT 1" );
		$r = \PassCaptain\OfflineScanner\Assignments::assign( (int) $device->device_id, (int) $argv[3], (int) $argv[4] );
		if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() . "\n" ); exit( 1 ); }
		echo wp_json_encode( $r );
		break;
	case 'today':
		// Event with an occurrence starting in 30 minutes (inside the admission window) and N free tickets.
		$count = max( 1, (int) ( $argv[3] ?? 1 ) );
		// Earlier E2E events are no longer needed: keep the public event list short.
		$wpdb->query( "UPDATE {$p}wpcap_events SET status='draft' WHERE slug LIKE 'e2e-%'" );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'E2E Einlass ' . gmdate( 'H:i:s' ), 'slug' => 'e2e-' . bin2hex( random_bytes( 4 ) ), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$event = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Halle', 'capacity' => 0, 'status' => 'scheduled' ) );
		$occ = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Eintritt', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
		$type = (int) $wpdb->insert_id;
		$mails = array();
		add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 1, 2 );
		add_filter( 'wpcap_checkout_ip_limit', static fn() => PHP_INT_MAX );
		$order = \PassCaptain\Orders::create( array( 'email' => 'e2e-door@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $count ) ), 'idempotency_key' => 'e2e-' . bin2hex( random_bytes( 6 ) ), 'accept_terms' => true ), array( 'skip_rate_limit' => true ) );
		if ( is_wp_error( $order ) ) { fwrite( STDERR, $order->get_error_message() . "\n" ); exit( 1 ); }
		$codes = array();
		foreach ( $mails as $mail ) {
			$body = is_array( $mail['message'] ?? null ) ? implode( "\n", $mail['message'] ) : (string) ( $mail['message'] ?? '' );
			if ( preg_match_all( '#wpcap_ticket=([a-f0-9]{64})#', $body, $m ) ) { $codes = array_merge( $codes, $m[1] ); }
		}
		echo wp_json_encode( array( 'event_id' => $event, 'occurrence_id' => $occ, 'ticket_type_id' => $type, 'codes' => array_values( array_unique( $codes ) ) ) );
		break;
	case 'ticket':
		$code = strtolower( (string) ( $argv[3] ?? '' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,status FROM {$p}wpcap_tickets WHERE token_hash=%s", hash( 'sha256', $code ) ) );
		$checkins = $row ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_checkins WHERE ticket_id=%d", $row->ticket_id ) ) : 0;
		echo wp_json_encode( array( 'found' => (bool) $row, 'status' => $row ? $row->status : null, 'checkins' => $checkins ) );
		break;
	case 'order':
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,status,payment_status,payment_provider,total_minor FROM {$p}wpcap_orders WHERE public_id=%s", (string) ( $argv[3] ?? '' ) ) );
		$tickets = $order ? $wpdb->get_results( $wpdb->prepare( "SELECT status,attendee_email FROM {$p}wpcap_tickets WHERE order_id=%d", $order->order_id ) ) : array();
		echo wp_json_encode( array( 'order' => $order, 'tickets' => $tickets ) );
		break;
	case 'woo-order':
		$wc = wc_get_order( (int) ( $argv[3] ?? 0 ) );
		echo wp_json_encode( array( 'status' => $wc ? $wc->get_status() : null, 'attendees' => $wc ? $wc->get_meta( \PassCaptain\Integrations\WooCommerce\OrderAdapter::ATTENDEE_META ) : null ) );
		break;
	default:
		fwrite( STDERR, "unknown command $cmd\n" );
		exit( 2 );
}
echo "\n";
