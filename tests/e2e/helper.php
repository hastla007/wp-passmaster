<?php
/**
 * Server-side helper for the browser E2E tests (disposable test site only). Prints JSON.
 *
 *   php tests/e2e/helper.php /path/to/wordpress pairing <event_id>          → offline scanning on, pairing code
 *   php tests/e2e/helper.php /path/to/wordpress assign <event_id> <occ_id>  → assigns the newest paired device
 *   php tests/e2e/helper.php /path/to/wordpress ticket <code>               → ticket status by code
 *   php tests/e2e/helper.php /path/to/wordpress order <public_id>           → payment status and tickets
 *   php tests/e2e/helper.php /path/to/wordpress orders-count <email>        → number of orders of a buyer
 *   php tests/e2e/helper.php /path/to/wordpress page <slug> <content>       → URL of a page with that content
 *   php tests/e2e/helper.php /path/to/wordpress checkin <code>              → server check-in (another device)
 *   php tests/e2e/helper.php /path/to/wordpress paid-order <qty>            → paid manual order, portal link
 *   php tests/e2e/helper.php /path/to/wordpress refunds <public_id>         → refunds and ticket refund flags
 *   php tests/e2e/helper.php /path/to/wordpress booking <email>             → newest service booking
 *   php tests/e2e/helper.php /path/to/wordpress seated                      → fresh event with a linked seat map
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
	case 'waitlist':
		// Waitlist entry by e-mail (frontend sign-up, double opt-in).
		echo wp_json_encode( array( 'entry' => $wpdb->get_row( $wpdb->prepare( "SELECT waitlist_id,status,ticket_type_id FROM {$p}wpcap_waitlist WHERE email=%s ORDER BY waitlist_id DESC LIMIT 1", (string) ( $argv[3] ?? '' ) ) ) ) );
		break;
	case 'buyer':
		// Newest order of an e-mail address with the account it was placed with (REST nonce → customer_user_id).
		echo wp_json_encode( array( 'order' => $wpdb->get_row( $wpdb->prepare( "SELECT order_id,customer_user_id,payment_status FROM {$p}wpcap_orders WHERE customer_email=%s ORDER BY order_id DESC LIMIT 1", (string) ( $argv[3] ?? '' ) ) ) ) );
		break;
	case 'orders-count':
		// A321: number of orders of an e-mail address (an unclear answer followed by a retry must not create a second one).
		echo wp_json_encode( array( 'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email=%s", (string) ( $argv[3] ?? '' ) ) ) ) );
		break;
	case 'page':
		// A321: published page with the given content (e.g. two shortcodes on one page); reused by slug.
		$slug = sanitize_title( (string) ( $argv[3] ?? '' ) );
		$existing = get_page_by_path( $slug );
		$id = $existing ? (int) $existing->ID : (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $slug, 'post_content' => (string) ( $argv[4] ?? '' ) ) );
		echo wp_json_encode( array( 'url' => get_permalink( $id ) ) );
		break;
	case 'checkin':
		// A142: another device checks the ticket in on the server (conflict for an offline admission of this code).
		$r = \PassCaptain\Checkin::perform( strtolower( (string) ( $argv[3] ?? '' ) ), array( 'source' => 'web', 'idempotency_key' => 'e2e-' . bin2hex( random_bytes( 8 ) ) ) );
		echo wp_json_encode( array( 'ok' => ! is_wp_error( $r ), 'error' => is_wp_error( $r ) ? $r->get_error_code() : '' ) );
		break;
	case 'paid-order':
		// A142/A321: paid order (manual payment, marked paid by staff) of <qty> tickets of the a11y event's "Standard"
		// type, with self-service refund requests in the portal switched on (portal_refund_request_hours).
		$qty = max( 1, (int) ( $argv[3] ?? 1 ) );
		$type = (int) $wpdb->get_var( "SELECT tt.ticket_type_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE e.slug='a11y-test-event' AND tt.name='Standard'" );
		if ( ! $type ) { fwrite( STDERR, "run tests/a11y/fixture.php first\n" ); exit( 1 ); }
		$settings = (array) get_option( 'wpcap_settings', array() );
		$settings['portal_refund_request_hours'] = 2;
		update_option( 'wpcap_settings', $settings );
		$mails = array();
		add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 1, 2 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \PassCaptain\RateLimit::table() ) );
		$email = 'e2e-paid-' . bin2hex( random_bytes( 4 ) ) . '@example.com';
		$order = \PassCaptain\Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'e2e-paid-' . bin2hex( random_bytes( 6 ) ), 'accept_terms' => true ), array( 'payment_provider' => 'manual', 'skip_rate_limit' => true ) );
		if ( is_wp_error( $order ) ) { fwrite( STDERR, 'order: ' . $order->get_error_message() . "\n" ); exit( 1 ); }
		$paid = \PassCaptain\Orders::confirm_manual_payment( (int) $order['order_id'] );
		if ( is_wp_error( $paid ) ) { fwrite( STDERR, 'paid: ' . $paid->get_error_message() . "\n" ); exit( 1 ); }
		$portal = '';
		foreach ( $mails as $mail ) {
			$body = is_array( $mail['message'] ?? null ) ? implode( "\n", $mail['message'] ) : (string) ( $mail['message'] ?? '' );
			if ( preg_match( '#https?://[^\s"\'<>]*wpcap_order=[a-f0-9]{32}[^\s"\'<>]*#', $body, $m ) ) { $portal = html_entity_decode( $m[0] ); }
		}
		$public = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$p}wpcap_orders WHERE order_id=%d", (int) $order['order_id'] ) );
		echo wp_json_encode( array( 'order_id' => (int) $order['order_id'], 'public_id' => $public, 'email' => $email, 'portal_url' => $portal ) );
		break;
	case 'refunds':
		// Refunds of an order (status, amount) and its tickets' refund flags.
		$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$p}wpcap_orders WHERE public_id=%s", (string) ( $argv[3] ?? '' ) ) );
		echo wp_json_encode( array(
			'refunds' => $wpdb->get_results( $wpdb->prepare( "SELECT refund_id,status,amount_minor FROM {$p}wpcap_refunds WHERE order_id=%d ORDER BY refund_id", $order_id ) ),
			'tickets' => $wpdb->get_results( $wpdb->prepare( "SELECT status,refunded FROM {$p}wpcap_tickets WHERE order_id=%d ORDER BY ticket_id", $order_id ) ),
		) );
		break;
	case 'booking':
		// Newest service booking of an e-mail address (A142 rebooking).
		echo wp_json_encode( array( 'booking' => $wpdb->get_row( $wpdb->prepare( "SELECT booking_id,slot_id,status,quantity FROM {$p}wpcap_service_bookings WHERE customer_email=%s ORDER BY booking_id DESC LIMIT 1", (string) ( $argv[3] ?? '' ) ) ) ) );
		break;
	case 'seated':
		// A321: event with a published seat map linked to its occurrence (8 seats in two rows, seat A4
		// wheelchair accessible), on its own page. A new event per call (every seat free); earlier ones go to draft.
		$wpdb->query( "UPDATE {$p}wpcap_events SET status='draft' WHERE slug LIKE 'e2e-seated-%'" );
		$slug = 'e2e-seated-' . bin2hex( random_bytes( 4 ) );
		$event = 0;
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( ! $event ) {
			$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'E2E Saalkonzert', 'slug' => $slug, 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
			$event = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 45 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Kammersaal', 'capacity' => 0, 'status' => 'scheduled' ) );
			$occ = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Saalplatz', 'price_minor' => 0, 'currency' => 'EUR', 'capacity' => 8, 'max_per_order' => 4, 'status' => 'active' ) );
			$type = (int) $wpdb->insert_id;
			$seats = array();
			foreach ( array( 'A' => 60, 'B' => 120 ) as $row => $y ) {
				foreach ( array( 1, 2, 3, 4 ) as $n ) {
					$seats[] = array( 'key' => strtolower( $row ) . $n, 'group' => 'g' . strtolower( $row ), 'row' => 'Reihe ' . $row, 'label' => (string) $n, 'x' => 40 + 40 * $n, 'y' => $y, 'zone' => 'parkett', 'category' => 'kat1', 'accessible' => 'A' === $row && 4 === $n );
				}
			}
			$draft = array(
				'width' => 400, 'height' => 200,
				'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett', 'color' => '#2271b1' ) ),
				'categories' => array( array( 'key' => 'kat1', 'name' => 'Kategorie 1', 'color' => '#00a32a' ) ),
				'groups' => array( array( 'key' => 'ga', 'type' => 'row', 'label' => '' ), array( 'key' => 'gb', 'type' => 'row', 'label' => '' ) ),
				'seats' => $seats,
			);
			$layout = \PassCaptain\Seating\Layouts::create( $event, 'Kammersaal' );
			if ( is_wp_error( $layout ) ) { fwrite( STDERR, $layout->get_error_message() . "\n" ); exit( 1 ); }
			$saved = \PassCaptain\Seating\Layouts::save_draft( (int) $layout['layout_id'], $draft );
			if ( is_wp_error( $saved ) ) { fwrite( STDERR, $saved->get_error_message() . "\n" ); exit( 1 ); }
			$pub = \PassCaptain\Seating\Layouts::publish( (int) $layout['layout_id'] );
			if ( is_wp_error( $pub ) ) { fwrite( STDERR, $pub->get_error_message() . "\n" ); exit( 1 ); }
			$link = \PassCaptain\Seating\Layouts::link_occurrence( $occ, (int) $pub['version_id'], array( $type => array( 'parkett' ) ) );
			if ( is_wp_error( $link ) ) { fwrite( STDERR, $link->get_error_message() . "\n" ); exit( 1 ); }
		}
		$occ = (int) $wpdb->get_var( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE event_id=%d", $event ) );
		$type = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ticket_type_id FROM {$p}wpcap_ticket_types WHERE occurrence_id=%d", $occ ) );
		$page_slug = 'pc-e2e-seated';
		$existing = get_page_by_path( $page_slug );
		$content = '[wpcap_events event="' . $event . '"]';
		$page = $existing ? (int) wp_update_post( array( 'ID' => (int) $existing->ID, 'post_content' => $content ) ) : (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $page_slug, 'post_title' => 'Saalplan', 'post_content' => $content ) );
		echo wp_json_encode( array( 'event_id' => $event, 'occurrence_id' => $occ, 'ticket_type_id' => $type, 'url' => get_permalink( $page ) ) );
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
