<?php
/**
 * R1 (audit round 2, experts #2/#4/#7/#11): concurrency and order core.
 *
 * - Overselling under READ COMMITTED: a payment committing between the sold and the held read of a
 *   capacity check; every order transaction runs under REPEATABLE READ (DbSession).
 * - Lock order of the late payment (no shared locks, reservations by primary key after the type lock).
 * - Deadlock/lock wait retry in Orders::create(): internal retry, then 503 retryable + Retry-After;
 *   short innodb_lock_wait_timeout restored afterwards.
 * - Late payment checks the sales state (cancelled/past occurrence, inactive ticket type).
 * - Occurrence cancellation also cancels expired orders with a started provider payment.
 * - Delayed payment methods hold at most until the occurrence starts.
 * - Seats: late payment re-claims seats inside the recovery transaction (or needs_review); ticket
 *   availability is limited to free seats; unchecked seat statements abort the order.
 * - PayPal: no capture after the hold lapsed.
 * - expire_holds(): per order (order row, then reservations), errors logged; 5-minute worker frees
 *   limited discount codes.
 * - System status shows a non-REPEATABLE-READ server.
 *
 * Included from run.php (uses check(), make_type(), expire_holds_of(), tickets(), the http fakes of
 * payments.php and the fault proxy of lib/fault_wpdb.php).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\DbSession;
use PassCaptain\Orders;
use PassCaptain\Payments\PayPalGateway;
use PassCaptain\Payments\Registry;
use PassCaptain\Payments\Webhooks;
use PassCaptain\Settings;

require_once dirname( __DIR__ ) . '/lib/fault_wpdb.php';

global $wpdb;
$p = $wpdb->prefix;

if ( ! class_exists( 'R1_Hook_WPDB' ) ) {
	/** $wpdb proxy that runs a callback right before matching statements (interleaving another connection). */
	final class R1_Hook_WPDB {
		public array $hooks = array();
		public array $log = array();
		public function __construct( public object $inner ) {}
		public function on( $pattern, callable $callback, $times = 1 ) { $this->hooks[] = array( $pattern, $callback, $times ); return $this; }
		private function before( $sql ) {
			$this->log[] = (string) $sql;
			foreach ( $this->hooks as $i => $hook ) {
				if ( $hook[2] > 0 && preg_match( $hook[0], str_replace( '`', '', (string) $sql ) ) ) { --$this->hooks[ $i ][2]; ( $hook[1] )( $this->inner ); }
			}
		}
		public function __get( $name ) { return $this->inner->$name ?? null; }
		public function __set( $name, $value ) { $this->inner->$name = $value; }
		public function __isset( $name ) { return isset( $this->inner->$name ); }
		public function __call( $name, $args ) { return $this->inner->$name( ...$args ); }
		public function prepare( $query, ...$args ) { return $this->inner->prepare( $query, ...$args ); }
		public function query( $sql ) { $this->before( $sql ); return $this->inner->query( $sql ); }
		public function get_results( $sql, $output = OBJECT ) { $this->before( $sql ); return $this->inner->get_results( $sql, $output ); }
		public function get_row( $sql, $output = OBJECT ) { $this->before( $sql ); return $this->inner->get_row( $sql, $output ); }
		public function get_col( $sql ) { $this->before( $sql ); return $this->inner->get_col( $sql ); }
		public function get_var( $sql ) { $this->before( $sql ); return $this->inner->get_var( $sql ); }
		public function insert( $table, $data, $format = null ) { $this->before( "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ')' ); return $this->inner->insert( $table, $data, $format ); }
		public function replace( $table, $data, $format = null ) { $this->before( "REPLACE INTO $table" ); return $this->inner->replace( $table, $data, $format ); }
		public function update( $table, $data, $where, $format = null, $where_format = null ) { $this->before( "UPDATE $table SET " . implode( ',', array_keys( $data ) ) ); return $this->inner->update( $table, $data, $where, $format, $where_format ); }
		public function delete( $table, $where, $format = null ) { $this->before( "DELETE FROM $table" ); return $this->inner->delete( $table, $where, $format ); }
	}
}

$r1_real = $wpdb;
$r1_hook = static function () use ( $r1_real ) { $GLOBALS['wpdb'] = new R1_Hook_WPDB( $r1_real ); return $GLOBALS['wpdb']; };
$r1_unhook = static function () use ( $r1_real ) { $GLOBALS['wpdb'] = $r1_real; };
/** Second connection (another PHP request). */
$r1_c2 = new Test_WPDB();
$r1_c2->prefix = $p;
$r1_order = static function ( $type, $qty, $key, $provider = 'stripe', array $extra = array() ) {
	wpcap_test_reset_transients();
	return Orders::create( array_merge( array( 'email' => "$key@example.com", 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => $key . '-' . uniqid() ), $extra ), array( 'payment_provider' => $provider, 'hold_minutes' => 30 ) );
};

/* ------------------------------------------------------------------------------------------ */
echo "R1: no overselling under READ COMMITTED (payment commits between two capacity reads)\n";
$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
$r1_type = make_type( 2, 10, 2500 );
$r1_a = $r1_order( $r1_type, 1, 'r1-rc-a' );
// While buyer B's checkout checks the held units, A's payment is confirmed on another connection.
$r1_proxy = $r1_hook();
$r1_proxy->on( '/COALESCE\(SUM\(quantity\),0\) FROM \S*wpcap_reservations WHERE ticket_type_id=' . $r1_type . '\b/', static function () use ( $r1_c2, $r1_a, $p ) {
	$r1_c2->query( "UPDATE {$p}wpcap_orders SET status='complete',payment_status='paid' WHERE order_id=" . (int) $r1_a['order_id'] );
	$r1_c2->query( "UPDATE {$p}wpcap_reservations SET status='converted' WHERE order_id=" . (int) $r1_a['order_id'] );
} );
$r1_b = $r1_order( $r1_type, 2, 'r1-rc-b' );
$r1_unhook();
$r1_used = (int) $wpdb->get_var( "SELECT (SELECT COALESCE(SUM(oi.quantity),0) FROM {$p}wpcap_order_items oi JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.ticket_type_id=$r1_type AND o.payment_status='paid') + (SELECT COALESCE(SUM(quantity),0) FROM {$p}wpcap_reservations WHERE ticket_type_id=$r1_type AND status='active' AND expires_at>UTC_TIMESTAMP())" );
check( 'READ COMMITTED session: the buyer is sold out, paid + held stay within capacity' . ( is_wp_error( $r1_b ) ? '' : " (oversold: $r1_used of 2)" ), is_wp_error( $r1_b ) && 2 >= $r1_used );

// The order transaction itself runs under REPEATABLE READ although the session default is READ COMMITTED.
$r1_type2 = make_type( 5, 10, 2500 );
$r1_counts = array();
$r1_proxy = $r1_hook();
$r1_proxy->on( '/FROM \S*wpcap_ticket_types WHERE ticket_type_id = ' . $r1_type2 . ' FOR UPDATE/', static function ( $inner ) use ( $r1_c2, $p, &$r1_counts ) {
	$r1_counts[] = (int) $inner->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" );
	$now = gmdate( 'Y-m-d H:i:s' );
	$r1_c2->query( "INSERT INTO {$p}wpcap_events (name,slug,description,status,created_at,updated_at) VALUES ('R1','r1-" . uniqid() . "','','draft','$now','$now')" );
	$r1_counts[] = (int) $inner->get_var( "SELECT COUNT(*) FROM {$p}wpcap_events" );
} );
$r1_rr = $r1_order( $r1_type2, 1, 'r1-rr' );
$r1_unhook();
check( 'the checkout transaction sees one snapshot (REPEATABLE READ forced per transaction)', ! is_wp_error( $r1_rr ) && 2 === count( $r1_counts ) && $r1_counts[0] === $r1_counts[1] );
check( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ directly before START TRANSACTION', (static function ( array $log ) { foreach ( $log as $i => $sql ) { if ( 'START TRANSACTION' === $sql ) { return 0 === strpos( $log[ $i - 1 ] ?? '', 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ); } } return false; } )( $r1_proxy->log ) );
$r1_issues = class_exists( DbSession::class ) ? DbSession::status_issues() : array();
check( 'system status warns about a server/session that is not REPEATABLE READ', 1 === count( $r1_issues ) && false !== strpos( $r1_issues[0]['message'], 'READ-COMMITTED' ) );
$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
check( 'no isolation warning under REPEATABLE READ', class_exists( DbSession::class ) && ( array() === DbSession::status_issues() || 'REPEATABLE-READ' !== DbSession::isolation()['global'] ) );

/* ------------------------------------------------------------------------------------------ */
echo "R1: deadlock / lock wait timeout in Orders::create()\n";
$r1_type3 = make_type( 10, 10, 2500 );
$r1_f = wpcap_faults();
$r1_f->fail( '/^INSERT INTO \S*wpcap_orders \(/', 0, 1, 'deadlock' );
$r1_retry = $r1_order( $r1_type3, 1, 'r1-dl-once' );
wpcap_faults_restore();
check( 'one deadlock is retried internally: the order is created', ! is_wp_error( $r1_retry ) && 'pending' === $r1_retry['payment_status'] && 1 === count( Orders::items_for_order( $r1_retry['order_id'] ) ) );
$r1_f = wpcap_faults();
$r1_f->fail( '/^INSERT INTO \S*wpcap_reservations \(/', 0, 5, 'lock_wait' );
$r1_busy = $r1_order( $r1_type3, 1, 'r1-dl-many' );
$r1_attempts = count( array_filter( $r1_f->log, static fn( $sql ) => 'START TRANSACTION' === $sql ) );
wpcap_faults_restore();
$r1_data = is_wp_error( $r1_busy ) ? (array) $r1_busy->get_error_data() : array();
check( 'lasting lock wait timeouts: 503 retryable with retry_after after 3 attempts', is_wp_error( $r1_busy ) && 503 === ( $r1_data['status'] ?? 0 ) && ! empty( $r1_data['retryable'] ) && 2 === ( $r1_data['retry_after'] ?? 0 ) && 3 === $r1_attempts );
check( 'nothing of the failed attempts is left (no order, no hold)', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE customer_email LIKE 'r1-dl-many%'" ) );
if ( class_exists( 'WP_REST_Response' ) ) {
	$r1_resp = new class( array( 'code' => 'wpcap_checkout_failed', 'message' => 'x', 'data' => $r1_data ), 503 ) extends WP_REST_Response { public $headers = array(); public function header( $k, $v ) { $this->headers[ $k ] = $v; } };
	PassCaptain\Sales\CheckoutGate::retry_after_header( $r1_resp );
	check( 'REST answer of a busy database carries Retry-After', array( 'Retry-After' => '2' ) === $r1_resp->headers );
}
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 50' ); // Server default (a simulated lost connection elsewhere may have left another value).
$r1_lwt_before = (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
$r1_lwt = array();
$r1_proxy = $r1_hook();
$r1_proxy->on( '/FROM \S*wpcap_ticket_types WHERE ticket_type_id = ' . $r1_type3 . ' FOR UPDATE/', static function ( $inner ) use ( &$r1_lwt ) { $r1_lwt[] = (int) $inner->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' ); } );
$r1_order( $r1_type3, 1, 'r1-lwt' );
$r1_unhook();
check( 'checkout waits at most 5 s for a row lock; the session value is restored afterwards', array( 5 ) === $r1_lwt && $r1_lwt_before === (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' ) && 5 !== $r1_lwt_before );

/* ------------------------------------------------------------------------------------------ */
echo "R1: late payment lock order and sales state\n";
$r1_type4 = make_type( 10, 10, 2500 );
$r1_late = $r1_order( $r1_type4, 2, 'r1-late-order' );
expire_holds_of( $r1_late['order_id'] );
$r1_proxy = $r1_hook();
$r1_ok = Orders::confirm_external_payment( $r1_late['order_id'], 'r1' );
$r1_unhook();
$r1_pos = static function ( array $log, $pattern ) { foreach ( $log as $i => $sql ) { if ( preg_match( $pattern, str_replace( '`', '', $sql ) ) ) { return $i; } } return -1; };
$r1_type_lock = $r1_pos( $r1_proxy->log, '/FROM \S*wpcap_ticket_types WHERE ticket_type_id=' . $r1_type4 . ' FOR UPDATE/' );
$r1_hold_lock = $r1_pos( $r1_proxy->log, '/FROM \S*wpcap_reservations WHERE reservation_id IN \([0-9,]+\) ORDER BY reservation_id FOR UPDATE/' );
check( 'late payment is confirmed (capacity free)', true === $r1_ok && 2 === count( tickets( $r1_late['order_id'], 'valid' ) ) );
check( 'late payment: no shared locks on order lines/reservations; reservations locked by id after the ticket type', -1 === $r1_pos( $r1_proxy->log, '/wpcap_(reservations|order_items|orders)\b.*LOCK IN SHARE MODE/' ) && $r1_type_lock >= 0 && $r1_hold_lock > $r1_type_lock );

$r1_mk = static function ( $start_offset ) use ( $p ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'R1', 'slug' => 'r1-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + $start_offset ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'S', 'price_minor' => 2500, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
	return array( (int) $wpdb->insert_id, (int) $occ );
};
list( $r1_t_c, $r1_occ_c ) = $r1_mk( 7 * DAY_IN_SECONDS );
$r1_oc = $r1_order( $r1_t_c, 2, 'r1-sale-cancelled', 'mollie' );
expire_holds_of( $r1_oc['order_id'] );
Orders::expire_holds();
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $r1_occ_c ) );
$r1_res_c = Webhooks::process( 'mollie', array( 'event_id' => 'r1-mollie-c-' . uniqid(), 'type' => 'paid', 'order_public_id' => Orders::get( $r1_oc['order_id'] )->public_id, 'provider_reference' => 'tr_r1c', 'amount_minor' => 5000, 'currency' => 'EUR' ) );
check( 'late payment for a cancelled occurrence: needs_review, no tickets', 'needs_review' === $r1_res_c && 'needs_review' === Orders::get( $r1_oc['order_id'] )->status && ! tickets( $r1_oc['order_id'], 'valid' ) );
list( $r1_t_p, $r1_occ_p ) = $r1_mk( HOUR_IN_SECONDS );
$r1_op = $r1_order( $r1_t_p, 1, 'r1-sale-past' );
expire_holds_of( $r1_op['order_id'] );
$wpdb->update( "{$p}wpcap_occurrences", array( 'starts_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'occurrence_id' => $r1_occ_p ) );
$r1_res_p = Orders::confirm_external_payment( $r1_op['order_id'], 'r1' );
check( 'late payment for a past occurrence: needs_review (sale_closed), no tickets', is_wp_error( $r1_res_p ) && 'wpcap_needs_review' === $r1_res_p->get_error_code() && 'sale_closed' === ( $r1_res_p->get_error_data()['reason'] ?? '' ) && ! tickets( $r1_op['order_id'], 'valid' ) );
list( $r1_t_i ) = $r1_mk( 7 * DAY_IN_SECONDS );
$r1_oi = $r1_order( $r1_t_i, 1, 'r1-sale-inactive' );
expire_holds_of( $r1_oi['order_id'] );
$wpdb->update( "{$p}wpcap_ticket_types", array( 'status' => 'inactive' ), array( 'ticket_type_id' => $r1_t_i ) );
$GLOBALS['wpcap_test_mails'] = array();
$r1_res_i = Orders::confirm_external_payment( $r1_oi['order_id'], 'r1' );
check( 'late payment for an inactive ticket type: needs_review + admin alert, no tickets', is_wp_error( $r1_res_i ) && 'wpcap_needs_review' === $r1_res_i->get_error_code() && ! tickets( $r1_oi['order_id'], 'valid' ) && 'paid_unallocated' === Orders::get( $r1_oi['order_id'] )->payment_status && (bool) array_filter( $GLOBALS['wpcap_test_mails'], static fn( $m ) => false !== strpos( (string) $m['subject'], 'ohne Tickets' ) ) ); // Merged late-payment alert (A273).

// Occurrence cancellation: an expired order whose provider payment was started is cancelled too.
list( $r1_t_x, $r1_occ_x ) = $r1_mk( 7 * DAY_IN_SECONDS );
$r1_ox = $r1_order( $r1_t_x, 1, 'r1-cancel-expired' );
Orders::set_provider_reference( $r1_ox['order_id'], 'cs_test_r1x' );
expire_holds_of( $r1_ox['order_id'] );
Orders::expire_holds();
$r1_before = Orders::get( $r1_ox['order_id'] )->payment_status;
$wpdb->update( "{$p}wpcap_occurrences", array( 'status' => 'cancelled' ), array( 'occurrence_id' => $r1_occ_x ) );
PassCaptain\OccurrenceCancellation::handle( $r1_occ_x );
check( 'occurrence cancellation also cancels an expired order with an open provider payment', 'expired' === $r1_before && 'cancelled' === Orders::get( $r1_ox['order_id'] )->status );

// Delayed payment methods: the hold is extended at most until the occurrence starts.
list( $r1_t_d, $r1_occ_d ) = $r1_mk( 3 * HOUR_IN_SECONDS );
$r1_od = $r1_order( $r1_t_d, 1, 'r1-delayed' );
$r1_ext = Webhooks::extend_for_delayed_payment( Orders::get( $r1_od['order_id'] ), 'stripe' );
$r1_hold_until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$p}wpcap_reservations WHERE order_id=%d", $r1_od['order_id'] ) );
$r1_starts = (string) $wpdb->get_var( $wpdb->prepare( "SELECT starts_at FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", $r1_occ_d ) );
check( 'delayed payment hold ends at the latest when the occurrence starts', true === $r1_ext && $r1_hold_until <= $r1_starts && $r1_hold_until > gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

/* ------------------------------------------------------------------------------------------ */
echo "R1: seats and late payments\n";
PassCaptain\Modules\Seating::register();
list( $r1_st, $r1_socc ) = $r1_mk( 7 * DAY_IN_SECONDS );
$r1_sevent = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$r1_socc" );
$r1_layout = PassCaptain\Seating\AdminPage::create( array( 'event_id' => $r1_sevent, 'name' => 'Saal' ) );
$r1_draft = array( 'width' => 800, 'height' => 500, 'zones' => array( array( 'key' => 'balkon', 'name' => 'Balkon', 'color' => '#d63638' ) ), 'categories' => array(), 'groups' => array( array( 'key' => 'gb', 'type' => 'row', 'label' => '' ) ),
	'seats' => array( array( 'key' => 'b1', 'group' => 'gb', 'row' => 'B', 'label' => '1', 'x' => 10, 'y' => 10, 'zone' => 'balkon' ), array( 'key' => 'b2', 'group' => 'gb', 'row' => 'B', 'label' => '2', 'x' => 40, 'y' => 10, 'zone' => 'balkon' ) ) );
$r1_pub = PassCaptain\Seating\AdminPage::save( array( 'layout_id' => $r1_layout['layout_id'], 'draft' => json_encode( $r1_draft ), 'do' => 'publish', 'upgrade_links' => '1' ) );
PassCaptain\Seating\AdminPage::link( array( 'occurrence_id' => $r1_socc, 'version_id' => $r1_pub['version_id'], 'zones' => array( $r1_st => array( 'balkon' ) ) ) );
$r1_seat = static fn( $key ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seat_id FROM ' . PassCaptain\Seating\Layouts::table( 'seats' ) . ' WHERE version_id=%d AND seat_key=%s', $r1_pub['version_id'], $key ) );
check( 'ticket availability is limited to the free seats of the zone (type capacity 10, 2 seats)', 2 === Orders::availability( $r1_st ) );
// Unchecked seat statements: a failing release of stale claims aborts the order (retried, then 503).
$r1_f = wpcap_faults();
$r1_f->fail( '/seating_claims c LEFT JOIN \S*wpcap_orders o ON o.order_id=c.order_id (SET|WHERE)/', 0, 5, 'error' );
$r1_sf = $r1_order( $r1_st, 1, 'r1-seat-fault', 'stripe', array( 'seats' => array( $r1_st => array( $r1_seat( 'b2' ) ) ) ) );
wpcap_faults_restore();
check( 'a failed statement in the seat claim aborts the checkout (no order without checked claims)', is_wp_error( $r1_sf ) && 503 === ( $r1_sf->get_error_data()['status'] ?? 0 ) );
$r1_sa = $r1_order( $r1_st, 1, 'r1-seat-late', 'stripe', array( 'seats' => array( $r1_st => array( $r1_seat( 'b1' ) ) ) ) );
$r1_past = gmdate( 'Y-m-d H:i:s', time() - 60 );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='$r1_past' WHERE order_id=" . (int) $r1_sa['order_id'] );
$wpdb->query( 'UPDATE ' . PassCaptain\Seating\Layouts::table( 'claims' ) . " SET expires_at='$r1_past' WHERE order_id=" . (int) $r1_sa['order_id'] );
Orders::expire_holds();
$r1_sb = $r1_order( $r1_st, 1, 'r1-seat-b', 'stripe', array( 'seats' => array( $r1_st => array( $r1_seat( 'b1' ) ) ) ) );
$r1_sc = $r1_order( $r1_st, 1, 'r1-seat-c', 'stripe', array( 'seats' => array( $r1_st => array( $r1_seat( 'b2' ) ) ) ) );
check( 'fixture: both seats bought by others while the first hold lapsed', ! is_wp_error( $r1_sb ) && ! is_wp_error( $r1_sc ) && 0 === Orders::availability( $r1_st ) );
if ( is_array( $r1_sb ) ) { Orders::confirm_external_payment( $r1_sb['order_id'], 'b' ); }
if ( is_array( $r1_sc ) ) { Orders::confirm_external_payment( $r1_sc['order_id'], 'c' ); }
$r1_sl = Orders::confirm_external_payment( $r1_sa['order_id'], 'late' );
$r1_valid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_tickets WHERE ticket_type_id=$r1_st AND status='valid'" );
$r1_seated = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PassCaptain\Seating\Layouts::table( 'ticket_seats' ) . " WHERE occurrence_id=$r1_socc AND status='assigned'" );
check( 'late payment without a free seat: needs_review (seat_sold_out), no ticket without seat', is_wp_error( $r1_sl ) && 'seat_sold_out' === ( $r1_sl->get_error_data()['reason'] ?? '' ) && 2 === $r1_valid && 2 === $r1_seated && 'needs_review' === Orders::get( $r1_sa['order_id'] )->status );
// A lapsed seat hold whose seat is still free is claimed again inside the recovery transaction.
list( $r1_st2, $r1_socc2 ) = $r1_mk( 7 * DAY_IN_SECONDS );
$r1_layout2 = PassCaptain\Seating\AdminPage::create( array( 'event_id' => (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$r1_socc2" ), 'name' => 'Saal 2' ) );
$r1_pub2 = PassCaptain\Seating\AdminPage::save( array( 'layout_id' => $r1_layout2['layout_id'], 'draft' => json_encode( $r1_draft ), 'do' => 'publish', 'upgrade_links' => '1' ) );
PassCaptain\Seating\AdminPage::link( array( 'occurrence_id' => $r1_socc2, 'version_id' => $r1_pub2['version_id'], 'zones' => array( $r1_st2 => array( 'balkon' ) ) ) );
$r1_seat2 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT seat_id FROM ' . PassCaptain\Seating\Layouts::table( 'seats' ) . " WHERE version_id=%d AND seat_key='b2'", $r1_pub2['version_id'] ) );
$r1_s2 = $r1_order( $r1_st2, 1, 'r1-seat-recover', 'stripe', array( 'seats' => array( $r1_st2 => array( $r1_seat2 ) ) ) );
$wpdb->query( "UPDATE {$p}wpcap_reservations SET expires_at='$r1_past' WHERE order_id=" . (int) $r1_s2['order_id'] );
$wpdb->query( 'UPDATE ' . PassCaptain\Seating\Layouts::table( 'claims' ) . " SET expires_at='$r1_past' WHERE order_id=" . (int) $r1_s2['order_id'] );
Orders::expire_holds();
$r1_s2ok = Orders::confirm_external_payment( $r1_s2['order_id'], 'late' );
$r1_s2seat = $wpdb->get_var( 'SELECT seat_key FROM ' . PassCaptain\Seating\Layouts::table( 'ticket_seats' ) . " WHERE occurrence_id=$r1_socc2 AND status='assigned' AND order_id=" . (int) $r1_s2['order_id'] );
check( 'late payment with the seat still free: same seat claimed again, ticket with seat', true === $r1_s2ok && 'b2' === $r1_s2seat && 1 === count( tickets( $r1_s2['order_id'], 'valid' ) ) );

/* ------------------------------------------------------------------------------------------ */
echo "R1: PayPal does not capture after the hold lapsed\n";
Settings::update( array( 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_webhook_id' => 'WH-ID-1', 'enabled_providers' => array( 'manual', 'paypal' ) ) );
Registry::reset();
$r1_type5 = make_type( 10, 10, 2500 );
http_reset();
$r1_pp = $r1_order( $r1_type5, 1, 'r1-paypal-expired', 'paypal' );
Orders::set_provider_reference( $r1_pp['order_id'], 'PAYR1EXP' );
expire_holds_of( $r1_pp['order_id'] );
$r1_pp_public = Orders::get( $r1_pp['order_id'] )->public_id;
http_push( array( 'id' => 'PAYR1EXP', 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAPR1', 'status' => 'COMPLETED', 'custom_id' => $r1_pp_public, 'amount' => array( 'currency_code' => 'EUR', 'value' => '25.00' ) ) ) ) ) ) ), 201, '/v2/checkout/orders/PAYR1EXP/capture' );
$r1_ret = PayPalGateway::handle_return( $r1_pp_public, 'PAYR1EXP' );
check( 'return after the hold lapsed: no capture, message "Reservierung abgelaufen, keine Zahlung eingezogen"', is_wp_error( $r1_ret ) && 'wpcap_paypal_hold_expired' === $r1_ret->get_error_code() && false !== strpos( $r1_ret->get_error_message(), 'keine Zahlung eingezogen' ) && array() === http_requests( '/capture' ) && 'paid' !== Orders::get( $r1_pp['order_id'] )->payment_status );
http_reset();
http_push( array( 'access_token' => 'R1-token', 'expires_in' => 32400, 'token_type' => 'Bearer' ), 200, '/v1/oauth2/token' );
http_push( array( 'verification_status' => 'SUCCESS' ), 200, 'verify-webhook-signature' );
$r1_appr = Registry::get( 'paypal' )->verify_webhook( paypal_request( array( 'id' => 'WH-R1-APPR', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => array( 'id' => 'PAYR1EXP', 'purchase_units' => array( array( 'custom_id' => $r1_pp_public ) ) ) ) ) );
check( 'CHECKOUT.ORDER.APPROVED after the hold lapsed: ignored, no capture call', is_array( $r1_appr ) && 'ignored' === $r1_appr['type'] && array() === http_requests( '/capture' ) );
http_reset();
Settings::update( array( 'paypal_client_id' => '', 'paypal_client_secret' => '', 'paypal_webhook_id' => '', 'enabled_providers' => array( 'manual' ) ) );
Registry::reset();

/* ------------------------------------------------------------------------------------------ */
echo "R1: hold cleanup per order and in the 5-minute worker\n";
while ( Orders::expire_holds() > 0 ) {}
$r1_type6 = make_type( 10, 10, 2500 );
$r1_e1 = $r1_order( $r1_type6, 1, 'r1-exp-1' );
$r1_e2 = $r1_order( $r1_type6, 1, 'r1-exp-2' );
expire_holds_of( $r1_e1['order_id'] );
expire_holds_of( $r1_e2['order_id'] );
$r1_logs = array();
add_action( 'wpcap_log', static function ( $level, $event ) use ( &$r1_logs ) { $r1_logs[] = $event; }, 10, 4 );
$r1_f = wpcap_faults();
$r1_f->fail( "/^UPDATE \\S*wpcap_orders SET status='expired',payment_status='expired'/", 0, 1, 'deadlock' );
$r1_expired = Orders::expire_holds();
wpcap_faults_restore();
$r1_e1_holds = $wpdb->get_col( $wpdb->prepare( "SELECT status FROM {$p}wpcap_reservations WHERE order_id=%d", $r1_e1['order_id'] ) );
check( 'a deadlocked order stays consistent (pending with its holds), the next order is expired', 1 === $r1_expired && 'pending' === Orders::get( $r1_e1['order_id'] )->payment_status && array( 'active' ) === array_values( array_unique( $r1_e1_holds ) ) && 'expired' === Orders::get( $r1_e2['order_id'] )->payment_status );
check( 'the failed expiry is logged', in_array( 'expire_holds_failed', $r1_logs, true ) );
check( 'the next run expires the left-over order', 1 === Orders::expire_holds() && 'expired' === Orders::get( $r1_e1['order_id'] )->payment_status );

// Limited discount code: back within minutes (5-minute worker), not only with the hourly cleanup.
$r1_type7 = make_type( 10, 10, 2500 );
$r1_event7 = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types t JOIN {$p}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE t.ticket_type_id=$r1_type7" );
$r1_code = 'R1ONCE' . strtoupper( substr( uniqid(), -5 ) );
$wpdb->insert( "{$p}wpcap_campaigns", array( 'event_id' => $r1_event7, 'code' => $r1_code, 'name' => $r1_code, 'kind' => 'fixed', 'discount_value' => 100, 'currency' => 'EUR', 'usage_limit' => 1, 'status' => 'active', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$r1_c1 = $r1_order( $r1_type7, 1, 'r1-camp-1', 'stripe', array( 'campaign_codes' => array( $r1_code ) ) );
$r1_c2o = $r1_order( $r1_type7, 1, 'r1-camp-2', 'stripe', array( 'campaign_codes' => array( $r1_code ) ) );
expire_holds_of( $r1_c1['order_id'] );
$r1_worker = method_exists( Orders::class, 'expire_holds_worker' ) ? Orders::expire_holds_worker() : -1;
$r1_c3 = $r1_order( $r1_type7, 1, 'r1-camp-3', 'stripe', array( 'campaign_codes' => array( $r1_code ) ) );
check( 'fixture: the limited code is used up by the first order', ! is_wp_error( $r1_c1 ) && is_wp_error( $r1_c2o ) );
check( 'campaign is available again after the hold lapsed (5-minute worker expire_holds_worker)', $r1_worker >= 1 && ! is_wp_error( $r1_c3 ) && 100 === (int) $r1_c3['discount_minor'] );
check( 'the worker runs on wpcap_process_jobs (Plugin)', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Plugin.php' ), "add_action( 'wpcap_process_jobs', array( Orders::class, 'process_expired_holds' )" ) );
$GLOBALS['wpcap_test_filters']['wpcap_expire_holds_batch'] = 1;
$r1_w1 = $r1_order( $r1_type7, 1, 'r1-batch-1' );
$r1_w2 = $r1_order( $r1_type7, 1, 'r1-batch-2' );
expire_holds_of( $r1_w1['order_id'] );
expire_holds_of( $r1_w2['order_id'] );
$r1_wn = method_exists( Orders::class, 'expire_holds_worker' ) ? Orders::expire_holds_worker() : -1;
unset( $GLOBALS['wpcap_test_filters']['wpcap_expire_holds_batch'] );
check( 'the worker continues with full batches within its time budget', $r1_wn >= 2 && 'expired' === Orders::get( $r1_w2['order_id'] )->payment_status );

$r1_c2->query( 'SELECT 1' );
unset( $r1_c2 );
