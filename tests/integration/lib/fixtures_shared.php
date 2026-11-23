<?php
/**
 * Helpers shared by several module tests (TODO "Gemeinsame Test-Stubs"): they used to be defined in one
 * module and called from others, so those others stopped with "Call to undefined function" when run
 * alone (WPCAP_TEST_ONLY). The defining module and every module that calls them load this file with
 * require_once; it only defines functions and classes (no hooks, no state), so the order of a full run
 * stays the same. tests/integration/modules/zz_helpers_guard.php fails when a module calls a function
 * that only another module defines.
 * - wpb_*: box office ticket types (boxoffice.php)
 * - sp_*: series with occurrences, orders, price rules (series_pricing.php)
 * - Wpcap_Test_Woo_Order/_Refund, woo_t_*: WooCommerce orders and refunds (woocommerce.php)
 * - Wpcap_Ms_Cart, Wpcap_Ms_WC, Wpcap_Ms_Errors: WooCommerce cart, WC() object, notices (woocommerce_multiseller.php)
 * - Q1_User, q1_*: test users with roles, devices, developer keys (zz_a277.php, zz_a278.php)
 * - mollie_*: Mollie payment payloads and webhooks (mollie.php)
 * - wpp_pdf_ok(): structural PDF check (print.php)
 * - wpw_unzip(): unpacks a .pkpass (wallet.php)
 * - bb_billing(): billing data (zz_billing.php)
 * - p1u_*: REST requests and access tokens (zz_phase1_ux.php)
 * - pa_event_of_type(), pa_order(): partner report fixtures (partners.php)
 * - svc_paid() … svc_ok(): service bookings, waitlist, worker processes (zzzz_services_b/c/e_*.php)
 * - w6_matrix() … w6_recover(): fault matrix over every SQL statement of a flow (zzzz_w6_faults.php)
 * - seating_draft(): seat map draft with zones, rows and a standing area (seating.php)
 * - x2_uninstall_run(): runs uninstall.php in a child process (zz_a372.php)
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

require_once __DIR__ . '/fault_wpdb.php';

use PassCaptain\DeveloperKeys;
use PassCaptain\Integrations\WooCommerce\OrderAdapter as Woo;
use PassCaptain\Jobs;
use PassCaptain\Mollie\MollieGateway;
use PassCaptain\Orders;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Series\Generator;
use PassCaptain\ServiceBookings;

/* ---- boxoffice.php ---- */
if ( ! function_exists( 'wpb_occurrence_of' ) ) {
	function wpb_occurrence_of( $type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT occurrence_id FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=" . (int) $type_id );
	}
	function wpb_event_of_type( $type_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type_id );
	}
	function wpb_add_type( $occurrence_id, $name, $price, $tax_bp, $capacity ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $occurrence_id, 'name' => $name, 'price_minor' => $price, 'currency' => 'EUR', 'capacity' => $capacity, 'max_per_order' => 10, 'tax_rate_bp' => $tax_bp, 'status' => 'active' ) );
		return (int) $wpdb->insert_id;
	}
	function wpb_key() { return 'test-' . bin2hex( random_bytes( 10 ) ); }
	function wpb_seq( $number ) { return (int) substr( (string) $number, -6 ); }
}

/* ---- series_pricing.php ---- */
/** Series with occurrences $day_offsets days from today at 19:00 Vienna; types copied from a template. */
function sp_series( array $day_offsets, $type_capacity = 50, $price = 2500, array $extra_types = array(), $occ_capacity = 0 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$tpl_type = make_type( $type_capacity, 10, $price, $occ_capacity );
	$tpl_occ = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$tpl_type" );
	$event = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$tpl_occ" );
	foreach ( $extra_types as $name => $cfg ) {
		$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $tpl_occ, 'name' => $name, 'price_minor' => $cfg['price'], 'currency' => 'EUR', 'capacity' => $cfg['capacity'] ?? 0, 'max_per_order' => 10, 'status' => 'active' ) );
	}
	$dates = array_map( static fn( $d ) => gmdate( 'Y-m-d', time() + $d * 86400 ), $day_offsets );
	$r = Generator::create( array( 'event_id' => $event, 'template_occurrence_id' => $tpl_occ, 'freq' => 'dates', 'dates' => implode( "\n", $dates ), 'time' => '19:00', 'duration_minutes' => 60, 'timezone' => 'Europe/Vienna', 'name' => 'P3 ' . uniqid() ) );
	$types = array();
	foreach ( $r['created'] as $occ ) {
		foreach ( $wpdb->get_results( "SELECT ticket_type_id,name FROM {$p}wpcap_ticket_types WHERE occurrence_id=" . (int) $occ . ' ORDER BY ticket_type_id' ) as $t ) { $types[ $occ ][ $t->name ] = (int) $t->ticket_type_id; }
	}
	return array( 'series_id' => (int) $r['series_id'], 'event_id' => $event, 'occurrences' => $r['created'], 'types' => $types );
}
function sp_buy( $type_id, $qty, $key, array $internal = array( 'skip_rate_limit' => true ), array $extra = array() ) {
	return Orders::create( array( 'email' => 'sp-' . preg_replace( '/[^a-z0-9]/', '', $key ) . '@example.com', 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $qty ) + ( $extra['item'] ?? array() ) ), 'idempotency_key' => 'sp-' . $key ) + ( $extra['payload'] ?? array() ), $internal );
}
function sp_rule( array $fields ) {
	return (object) ( $fields + array( 'rule_id' => 1, 'type_name' => '', 'priority' => 0, 'until_at' => null, 'hours_before' => 0, 'sold_pct' => 0, 'weekdays' => '', 'time_from' => '', 'time_to' => '', 'price_mode' => 'fixed', 'amount' => 1000 ) );
}

/* ---- woocommerce.php ---- */
class Wpcap_Test_Woo_Order extends WC_Order {
	public string $payment_method = '';
	public string $status = 'pending';
	public string $total = '0';
	public array $refund_list = array();
	public function get_payment_method() { return $this->payment_method; }
	public function get_status() { return $this->status; }
	public function get_total() { return $this->total; }
	public function get_total_refunded() { return array_sum( array_map( static fn( $r ) => (float) $r->amount, $this->refund_list ) ); }
	public function get_refunds() { return array_reverse( $this->refund_list ); } // newest first, like WooCommerce
}
class Wpcap_Test_Woo_Refund extends WC_Order {
	public string $amount = '0';
	public string $reason = '';
	public function get_amount() { return $this->amount; }
	public function get_reason() { return $this->reason; }
}
function woo_t_order( $id, array $lines, $method = '' ) {
	static $product_id = 8800, $item_id = 8900;
	$order = new Wpcap_Test_Woo_Order( $id );
	$order->payment_method = $method;
	$total = 0;
	foreach ( $lines as list( $type_id, $qty, $line_total ) ) {
		$product = new WC_Product( ++$product_id );
		$product->update_meta_data( Woo::PRODUCT_META, $type_id );
		$GLOBALS['wpcap_test_products'][ $product_id ] = $product;
		$order->items[] = new WC_Order_Item_Product( ++$item_id, $product, $qty, $line_total );
		$total += (float) $line_total;
	}
	$order->total = (string) $total;
	return $order;
}
function woo_t_refund( $id, Wpcap_Test_Woo_Order $order, $amount, array $lines ) {
	static $item_id = 9500;
	$refund = new Wpcap_Test_Woo_Refund( $id );
	$refund->amount = (string) $amount;
	foreach ( $lines as list( $line_index, $qty, $line_total ) ) {
		$original = $order->items[ $line_index ];
		$item = new WC_Order_Item_Product( ++$item_id, $original->get_product(), -$qty, (string) -$line_total );
		$item->update_meta_data( '_refunded_item_id', $original->get_id() );
		$refund->items[] = $item;
	}
	$order->refund_list[] = $refund;
	return $refund;
}
function woo_t_field( $type_id, $key, $label, $type = 'text', $required = 1 ) {
	global $wpdb;
	$wpdb->insert( "{$wpdb->prefix}wpcap_participant_fields", array( 'ticket_type_id' => $type_id, 'field_key' => $key, 'label' => $label, 'field_type' => $type, 'required' => $required, 'purpose' => 'Namensschild', 'status' => 'active' ) );
}

/* ---- zz_a277.php ---- */
/** Test user with roles and WP_User::has_cap() semantics (role capabilities + user capabilities). */
final class Q1_User {
	const ROLE_CAPS = array(
		'subscriber' => array(),
		'customer' => array(),
		'wpcap_checkin_staff' => array( 'wpcap_checkin' ),
		'wpcap_event_manager' => array( 'wpcap_manage_events', 'wpcap_manage_orders', 'wpcap_checkin', 'wpcap_view_reports' ),
		'wpcap_partner' => array( 'wpcap_checkin', 'wpcap_view_reports' ),
		'administrator' => array( 'manage_options', 'wpcap_manage_events', 'wpcap_checkin', 'wpcap_view_reports' ),
	);
	public array $caps = array();
	public string $display_name = 'Q1';
	public function __construct( public int $ID, public array $roles, public string $user_login = '', public string $user_email = '' ) {
		$this->user_login = $this->user_login ?: 'q1-' . $ID;
		$this->user_email = $this->user_email ?: 'q1-' . $ID . '@example.com';
	}
	public function add_cap( $cap ) { $this->caps[ $cap ] = true; }
	public function remove_cap( $cap ) { unset( $this->caps[ $cap ] ); }
	public function has_cap( $cap ) {
		if ( ! empty( $this->caps[ $cap ] ) ) { return true; }
		foreach ( $this->roles as $role ) { if ( in_array( $cap, self::ROLE_CAPS[ $role ] ?? array(), true ) ) { return true; } }
		return false;
	}
	/** Like WP_User::set_role(): replaces the roles and fires set_user_role. */
	public function set_role( $role ) {
		$old = $this->roles;
		$this->roles = array( $role );
		do_action( 'set_user_role', $this->ID, $role, $old );
	}
	public function remove_role( $role ) {
		$this->roles = array_values( array_diff( $this->roles, array( $role ) ) );
		do_action( 'remove_user_role', $this->ID, $role );
	}
}
function q1_user( $id, array $roles ) {
	$user = new Q1_User( $id, $roles );
	$GLOBALS['wpcap_test_users'][] = $user;
	return $user;
}
function q1_event() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . make_type( 5 ) );
}
function q1_staff( $user_id ) {
	global $wpdb;
	return $wpdb->get_col( "SELECT CONCAT(event_id,':',capability) FROM {$wpdb->prefix}wpcap_event_staff WHERE user_id=" . (int) $user_id . ' ORDER BY event_id,capability' );
}
function q1_device( $user_id ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}wpcap_scanner_devices", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'user_id' => $user_id, 'secret_hash' => hash( 'sha256', random_bytes( 16 ) ), 'event_ids' => '[]', 'created_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
	$device = (int) $wpdb->insert_id;
	if ( ! $device ) { echo '  device insert failed: ' . $wpdb->last_error . "\n"; }
	$wpdb->insert( "{$wpdb->prefix}wpcap_device_pairings", array( 'code_hash' => hash( 'sha256', uniqid( '', true ) ), 'user_id' => $user_id, 'event_ids' => '[]', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'created_at' => $now ) );
	return $device;
}
function q1_device_revoked( $device_id ) {
	global $wpdb;
	return null !== $wpdb->get_var( "SELECT revoked_at FROM {$wpdb->prefix}wpcap_scanner_devices WHERE device_id=" . (int) $device_id );
}
function q1_open_pairings( $user_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_device_pairings WHERE used_at IS NULL AND user_id=" . (int) $user_id );
}

/* ---- zz_a278.php ---- */
function q1_key_request( $key, $event_id ) {
	$r = new WP_REST_Request( 'GET' );
	$r->set_header( 'X-WPCAP-Key', $key );
	$r->set_param( 'event_id', $event_id );
	return $r;
}
function q1_issue_key( $user_id, $event_id ) {
	$saved = $GLOBALS['wpcap_test_user_id'] ?? 1;
	$GLOBALS['wpcap_test_user_id'] = $user_id;
	$key = DeveloperKeys::issue( $event_id, array( 'events.read' ), 'A278' );
	$GLOBALS['wpcap_test_user_id'] = $saved;
	return $key;
}
function q1_key_revoked( $key_id ) {
	global $wpdb;
	return null !== $wpdb->get_var( "SELECT revoked_at FROM {$wpdb->prefix}wpcap_api_keys WHERE api_key_id=" . (int) $key_id );
}

/* ---- mollie.php ---- */
function mollie_payment( $id, $status, $order, array $extra = array() ) {
	return array_merge( array( 'resource' => 'payment', 'id' => $id, 'status' => $status, 'amount' => array( 'value' => MollieGateway::format_amount( (int) $order['total_minor'], $order['currency'] ), 'currency' => $order['currency'] ), 'metadata' => array( 'order' => $order['public_id'] ), '_links' => array( 'checkout' => array( 'href' => 'https://www.mollie.com/checkout/select-method/' . $id ) ) ), $extra );
}
function mollie_webhook( $id ) {
	$req = new WP_REST_Request( 'POST', '/pass-captain/v1/payments/mollie/webhook' );
	$req->set_body( 'id=' . $id );
	$req['provider'] = 'mollie';
	return $req;
}
function mollie_deliver( $gateway, $id ) {
	$event = $gateway->verify_webhook( mollie_webhook( $id ) );
	return is_wp_error( $event ) ? $event : Webhooks::process( 'mollie', $event );
}

/* ---- print.php ---- */
if ( ! function_exists( 'wpp_pdf_ok' ) ) {
	/** Structural PDF check: header, EOF, xref offset and every object offset, page count. */
	function wpp_pdf_ok( $pdf, $pages = null ) {
		if ( ! is_string( $pdf ) || ! str_starts_with( $pdf, '%PDF-1.4' ) || ! preg_match( '/startxref\n(\d+)\n%%EOF$/', $pdf, $m ) ) { return false; }
		$xref = substr( $pdf, (int) $m[1] );
		if ( 0 !== strpos( $xref, 'xref' ) || ! preg_match_all( '/^(\d{10}) 00000 n $/m', $xref, $offsets ) ) { return false; }
		foreach ( $offsets[1] as $i => $offset ) {
			if ( 0 !== strpos( substr( $pdf, (int) $offset ), ( $i + 1 ) . ' 0 obj' ) ) { return false; }
		}
		if ( ! preg_match_all( '/<< \/Length (\d+)(?: \/Filter \/FlateDecode)? >>\nstream\n/', $pdf, $streams, PREG_OFFSET_CAPTURE ) ) { return false; }
		foreach ( $streams[1] as $i => $len ) {
			$start = $streams[0][ $i ][1] + strlen( $streams[0][ $i ][0] );
			if ( "\nendstream" !== substr( $pdf, $start + (int) $len[0], 10 ) ) { return false; }
		}
		return null === $pages || $pages === preg_match_all( '#/Type /Page /Parent#', $pdf );
	}
}

/* ---- wallet.php ---- */
function wpw_unzip( $bytes ) {
	$file = tempnam( sys_get_temp_dir(), 'wpw' );
	file_put_contents( $file, $bytes );
	$zip = new ZipArchive();
	$out = array();
	if ( true === $zip->open( $file ) ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { $name = $zip->getNameIndex( $i ); $out[ $name ] = $zip->getFromName( $name ); }
		$zip->close();
	}
	unlink( $file );
	return $out;
}

/* ---- zz_billing.php ---- */
function bb_billing( array $override = array() ) {
	return array_merge( array( 'enabled' => true, 'company' => 'Muster GmbH', 'first_name' => 'Erika', 'last_name' => 'Muster', 'street' => 'Ringstraße 1/3', 'postcode' => '1010', 'city' => 'Wien', 'country' => 'AT', 'vat_id' => 'ATU 1234 5678', 'po_number' => 'PO-4711' ), $override );
}

/* ---- zz_phase1_ux.php ---- */
function p1u_request( array $body, array $params = array() ) {
	$r = new WP_REST_Request( 'POST' );
	$r->set_body( wp_json_encode( $body ) );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}
function p1u_data( $res ) { return $res instanceof WP_REST_Response ? $res->get_data() : $res; }
function p1u_access_token( $public_id ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $mail ) {
		if ( preg_match( '/wpcap_order=' . preg_quote( $public_id, '/' ) . '&wpcap_access=([a-f0-9]{64})/', $mail['body'], $m ) ) { return $m[1]; }
	}
	return '';
}

/* ---- partners.php ---- */
function pa_event_of_type( $type ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=" . (int) $type );
}
function pa_order( $items, $key, $created_at, $pay = true ) {
	global $wpdb;
	$r = Orders::create( array( 'email' => "$key@example.com", 'items' => $items, 'idempotency_key' => $key . '-' . uniqid() ) );
	if ( $pay ) { Orders::confirm_manual_payment( $r['order_id'] ); }
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET created_at=%s WHERE order_id=%d", $created_at, $r['order_id'] ) );
	return (int) $r['order_id'];
}

/* ---- zzzz_services_b_lifecycle.php ---- */
/** Paid booking: hold → order (fake provider) → attach → payment. Returns [booking_id, order_id, access_token]. */
function svc_paid( $slot_id, $type_id, $email, $qty = 1 ) {
	global $wpdb;
	$b = svc_book( $slot_id, $email, $qty );
	if ( is_wp_error( $b ) ) { return array( $b, 0, '' ); }
	$before = count( $GLOBALS['wpcap_test_mails'] );
	$o = Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type_id, 'quantity' => $qty ) ), 'idempotency_key' => 'svcpaid-' . uniqid( '', true ) ) );
	if ( is_wp_error( $o ) ) { return array( $o, 0, '' ); }
	$token = '';
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], $before ) as $m ) { if ( preg_match( '/wpcap_access=([a-f0-9]{64})/', $m['body'], $mm ) ) { $token = $mm[1]; } }
	$wpdb->query( "UPDATE {$wpdb->prefix}wpcap_orders SET payment_provider='svc_fake' WHERE order_id=" . (int) $o['order_id'] );
	ServiceBookings::attach_order( $b['booking_id'], $o['order_id'] );
	Orders::confirm_external_payment( $o['order_id'], 'svc-pay-' . $o['order_id'] );
	return array( (int) $b['booking_id'], (int) $o['order_id'], $token );
}
function svc_tickets_of( $booking_id ) { return array_map( 'intval', (array) json_decode( (string) ServiceBookings::get( $booking_id )->ticket_ids, true ) ); }
function svc_ticket_status( $ticket_id ) { global $wpdb; return (string) $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=" . (int) $ticket_id ); }

/* ---- zzzz_services_c_waitlist.php ---- */
/** Token of the last mail to $email whose body matches $param=<64 hex>. */
function svc_token_from_mail( $email, $param ) {
	foreach ( array_reverse( $GLOBALS['wpcap_test_mails'] ) as $m ) {
		if ( $m['to'] === $email && preg_match( '/' . preg_quote( $param, '/' ) . '=(\d+)(?:&amp;|&)(?:do=[a-z]+(?:&amp;|&))?token=([a-f0-9]{64})/', $m['body'], $mm ) ) { return array( (int) $mm[1], $mm[2] ); }
	}
	return array( 0, '' );
}
function svc_run_jobs() { svc_reset_limits(); return Jobs::process_due( 100 ); }

/* ---- zzzz_services_e_concurrency.php ---- */
/** Runs worker processes that start at the same moment; returns their outputs in order. */
function svc_parallel( array $jobs ) {
	$start = sprintf( '%.3f', microtime( true ) + 1.2 );
	$procs = array();
	foreach ( $jobs as $args ) {
		$procs[] = popen( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/service_worker.php' ) . ' ' . $start . ' ' . implode( ' ', array_map( 'escapeshellarg', array_map( 'strval', $args ) ) ) . ' 2>&1', 'r' );
	}
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	if ( getenv( 'WPCAP_SVC_DEBUG' ) ) { fwrite( STDERR, 'PAR ' . json_encode( $out ) . "\n" ); }
	return $out;
}
function svc_ok( array $out ) { return count( array_filter( $out, static fn( $o ) => str_starts_with( $o, 'OK:' ) ) ); }

/* ---- zzzz_w6_faults.php ---- */
/**
 * Runs a fault matrix over one flow.
 *
 * @param string        $label
 * @param callable      $setup   fn(): array            fresh fixture (real $wpdb)
 * @param callable      $run     fn(array $ctx): mixed   the flow (runs under the fault proxy)
 * @param callable      $check   fn(array $ctx, $result, string $phase): string  '' or violation.
 *                               phase 'fault' (after the fault and after an immediate retry) or 'final'.
 * @param callable|null $recover fn(array $ctx): void    recovery between the retries (cron, time travel)
 * @param int|null      $max     maximum statement positions tested (evenly sampled above); all with WPCAP_TEST_SLOW=1
 * @return array{positions:int,runs:int,violations:string[]}
 */
function w6_matrix( $label, callable $setup, callable $run, callable $check, $recover = null, $max = null ) {
	$max = getenv( 'WPCAP_TEST_SLOW' ) ? PHP_INT_MAX : ( $max ?? 24 );
	$ctx = $setup();
	$f = wpcap_faults();
	$result = $run( $ctx );
	$statements = $f->log;
	wpcap_faults_restore();
	$violations = array();
	$dry = $check( $ctx, $result, 'final' );
	if ( '' !== $dry ) { $violations[] = "dry run: $dry"; }
	$n = count( $statements );
	$positions = range( 0, max( 0, $n - 1 ) );
	if ( $n > $max ) {
		$positions = array();
		for ( $i = 0; $i < $max; $i++ ) { $positions[] = (int) floor( $i * ( $n - 1 ) / max( 1, $max - 1 ) ); }
		$positions = array_values( array_unique( $positions ) );
	}
	$runs = 0;
	foreach ( $positions as $pos ) {
		foreach ( wpcap_fault_kinds() as $kind ) {
			$ctx = $setup();
			$f = wpcap_faults();
			$f->fail_nth( $pos, $kind );
			try { $result = $run( $ctx ); } catch ( Throwable $e ) { $result = new WP_Error( 'exception', $e->getMessage() ); }
			$stmt = substr( preg_replace( '/\s+/', ' ', (string) ( $f->log[ $pos ] ?? '?' ) ), 0, 90 );
			wpcap_faults_restore();
			++$runs;
			$where = "#$pos $kind [$stmt]";
			$v = $check( $ctx, $result, 'fault' );
			if ( '' !== $v ) { $violations[] = "$where after fault: $v"; continue; }
			$again = $run( $ctx ); // Immediate retry (client/provider/cashier), no recovery yet.
			$v = $check( $ctx, $again, 'fault' );
			if ( '' !== $v ) { $violations[] = "$where immediate retry: $v"; continue; }
			if ( $recover ) { $recover( $ctx ); }
			$final = $run( $ctx );
			$v = $check( $ctx, $final, 'final' );
			if ( '' !== $v ) { $violations[] = "$where final: $v"; }
		}
	}
	$ok = ! $violations;
	check( "$label: " . count( $positions ) . " of $n statements x 4 failure kinds ($runs runs) keep the invariants", $ok );
	if ( ! $ok ) { foreach ( array_slice( $violations, 0, 6 ) as $v ) { echo "         $v\n"; } }
	return array( 'positions' => count( $positions ), 'runs' => $runs, 'violations' => $violations );
}
function w6_count( $sql ) { global $wpdb; return (int) $wpdb->get_var( $sql ); }
function w6_err( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
/** Recovery a real site performs on its own: provider redelivery after the claim takeover window, cron. */
function w6_recover() {
	global $wpdb;
	$p = $wpdb->prefix;
	$past = gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS );
	$wpdb->query( "UPDATE {$p}wpcap_payment_events SET updated_at='$past' WHERE status='processing'" );
	$wpdb->query( "UPDATE {$p}wpcap_jobs SET run_after='2000-01-01 00:00:00' WHERE status='queued' AND job_type IN ('ticket_issue')" );
	Jobs::process_due( 50 );
}

/* ---- seating.php ---- */
function seating_draft() {
	$seats = array();
	foreach ( array( 1, 2, 3, 4 ) as $n ) { $seats[] = array( 'key' => 'a' . $n, 'group' => 'ga', 'row' => 'Reihe A', 'label' => (string) $n, 'x' => 40 + 30 * $n, 'y' => 60, 'zone' => 'parkett', 'category' => 'kat1', 'accessible' => 4 === $n ); }
	foreach ( array( 1, 2 ) as $n ) { $seats[] = array( 'key' => 'b' . $n, 'group' => 'gb', 'row' => 'Reihe B', 'label' => (string) $n, 'x' => 40 + 30 * $n, 'y' => 200, 'zone' => 'balkon', 'category' => 'kat1' ); }
	return array(
		'width' => 800, 'height' => 500,
		'zones' => array( array( 'key' => 'parkett', 'name' => 'Parkett', 'color' => '#2271b1' ), array( 'key' => 'balkon', 'name' => 'Balkon', 'color' => '#d63638' ), array( 'key' => 'steh', 'name' => 'Stehplätze', 'color' => '#00a32a' ) ),
		'categories' => array( array( 'key' => 'kat1', 'name' => 'Kategorie 1', 'color' => '#00a32a' ) ),
		'groups' => array( array( 'key' => 'ga', 'type' => 'row', 'label' => '' ), array( 'key' => 'gb', 'type' => 'row', 'label' => '' ), array( 'key' => 'st', 'type' => 'standing', 'label' => 'Stehbereich', 'x' => 400, 'y' => 300, 'w' => 200, 'h' => 100, 'capacity' => 3, 'zone' => 'steh' ) ),
		'seats' => $seats,
	);
}

/* ---- zz_a372.php ---- */
/** Runs uninstall.php in a child process; returns the decoded end state. */
function x2_uninstall_run( array $config ) {
	$cmd = 'X2U_PREFIX=' . escapeshellarg( $GLOBALS['wpdb']->prefix . 'u2_' ) . ' X2U_CONFIG=' . escapeshellarg( json_encode( $config ) ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/modules/zz_x2_uninstall/child.php' ) . ' 2>&1';
	$raw = (string) shell_exec( $cmd );
	$out = json_decode( $raw, true );
	if ( ! is_array( $out ) ) { echo '  (uninstall child: ' . substr( $raw, 0, 400 ) . ")\n"; }
	return is_array( $out ) ? $out : array();
}

/* ---- woocommerce_multiseller.php ---- */
class Wpcap_Ms_Cart { public array $cart_contents = array(); public function get_cart() { return $this->cart_contents; } }
class Wpcap_Ms_WC { public $cart; public $session; public bool $store_api = false; public function is_store_api_request() { return $this->store_api; } }
class Wpcap_Ms_Errors { public array $errors = array(); public function add( $code, $message ) { $this->errors[ $code ] = $message; } }
