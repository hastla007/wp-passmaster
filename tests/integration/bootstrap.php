<?php
/**
 * Minimal WordPress runtime for integration tests against a real MySQL/MariaDB database.
 *
 * Provides a mysqli based $wpdb and the handful of WordPress/WooCommerce functions the order
 * services and the WooCommerce adapter use. Connection via env: WPCAP_TEST_DB_HOST, _USER, _PASS, _NAME.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/wp/' );
define( 'WPCAP_FILE', dirname( __DIR__, 2 ) . '/pass-captain.php' );
define( 'WPCAP_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'WPCAP_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add( $code, $message ) { $this->code = $code; $this->message = $message; $GLOBALS['wpcap_test_store_errors'][] = $message; }
}

final class Test_WPDB {
	public $prefix = 'wptest_';
	public function __construct_prefix() { $this->prefix = getenv( 'WPCAP_TEST_PREFIX' ) ?: 'wptest_'; }
	/** Options table; empty here (options live in memory) unless a runner sets a DB-backed store (tests/upgrade). */
	public $options = '';
	public $last_error = '';
	public $insert_id = 0;
	public $rows_affected = 0;
	private mysqli $db;

	public function __construct() {
		mysqli_report( MYSQLI_REPORT_OFF );
		$this->connect();
	}
	/** Opens a (new) session; a replaced session is closed, so the server rolls its transaction back and frees its locks. */
	private function connect() {
		if ( isset( $this->db ) ) { @$this->db->close(); }
		$this->db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
		if ( $this->db->connect_error ) { fwrite( STDERR, 'DB connect failed: ' . $this->db->connect_error . "\n" ); exit( 2 ); }
		$this->db->set_charset( 'utf8mb4' );
		// WPCAP_TEST_SQL_MODE=<modes> runs the suite under a given sql_mode (e.g. MySQL 8's strict default).
		if ( false !== getenv( 'WPCAP_TEST_SQL_MODE' ) ) { $this->db->query( "SET SESSION sql_mode='" . $this->db->real_escape_string( (string) getenv( 'WPCAP_TEST_SQL_MODE' ) ) . "'" ); }
	}
	/* Used by WordPress' real dbDelta() (tests/upgrade loads it from a WordPress download). */
	private $suppress = false;
	public function suppress_errors( $suppress = true ) { $old = $this->suppress; $this->suppress = (bool) $suppress; return $old; }
	public function db_server_info() { return (string) $this->db->server_info; }
	public function db_version() { return preg_replace( '/[^0-9.].*/', '', $this->db_server_info() ); }
	public function tables( $scope = 'all', $prefix = true, $blog_id = 0 ) { return array(); }
	/**
	 * Fault injection (A183): the first rule of $GLOBALS['wpcap_test_faults'] that matches the
	 * statement (regex `match`, after `skip` matches, while `times` > 0) makes it fail like the server
	 * would. Returns false when the statement fails, null when it runs normally.
	 *   deadlock      1213: the server rolls the whole transaction back.
	 *   lock_timeout  1205: only the statement fails (innodb_rollback_on_timeout=OFF); with
	 *                 `rollback` => true the transaction is rolled back too (=ON).
	 *   lost          2013: the connection drops during the statement (transaction rolled back, locks
	 *                 freed); the statement fails, the next one runs on a new session (autocommit). With
	 *                 `executed` => true the statement ran before the reply was lost (e.g. a COMMIT
	 *                 that went through).
	 *   gone_away     2006 as handled by WordPress' wpdb: reconnect and run the statement again on the
	 *                 new session, so it succeeds outside the lost transaction.
	 */
	private function inject_fault( $sql ) {
		if ( empty( $GLOBALS['wpcap_test_faults'] ) ) { return null; }
		foreach ( $GLOBALS['wpcap_test_faults'] as $i => $rule ) {
			if ( $rule['times'] <= 0 || ! preg_match( $rule['match'], $sql ) ) { continue; }
			if ( $rule['skip'] > 0 ) { --$GLOBALS['wpcap_test_faults'][ $i ]['skip']; continue; }
			--$GLOBALS['wpcap_test_faults'][ $i ]['times'];
			$GLOBALS['wpcap_test_faults'][ $i ]['fired'][] = $sql;
			switch ( $rule['kind'] ) {
				case 'deadlock':
					$this->db->query( 'ROLLBACK' );
					$this->last_error = 'Deadlock found when trying to get lock; try restarting transaction';
					return false;
				case 'lock_timeout':
					if ( ! empty( $rule['rollback'] ) ) { $this->db->query( 'ROLLBACK' ); }
					$this->last_error = 'Lock wait timeout exceeded; try restarting transaction';
					return false;
				case 'lost':
					if ( ! empty( $rule['executed'] ) ) { $this->db->query( $sql ); }
					$this->connect();
					$this->last_error = 'Lost connection to MySQL server during query';
					return false;
				case 'gone_away':
					$this->connect();
					return null; // Runs normally on the new session.
			}
		}
		return null;
	}
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; }
	private function esc( $v ) { return null === $v ? 'NULL' : "'" . $this->db->real_escape_string( (string) $v ) . "'"; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%%|%[dsf]/', function ( $m ) use ( &$i, $args ) {
			if ( '%%' === $m[0] ) { return '%'; }
			$v = $args[ $i++ ] ?? '';
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return $this->esc( $v );
		}, $query );
	}
	public $num_queries = 0;
	public function query( $sql ) {
		// Like WordPress' wpdb: the `query` filter sees every statement, num_queries counts them.
		$sql = apply_filters( 'query', $sql );
		++$this->num_queries;
		$this->last_error = '';
		if ( isset( $GLOBALS['wpcap_test_query_log'] ) && is_array( $GLOBALS['wpcap_test_query_log'] ) ) { $GLOBALS['wpcap_test_query_log'][] = $sql; }
		if ( false === $this->inject_fault( $sql ) ) { $this->insert_id = 0; $this->rows_affected = 0; return false; }
		$res = $this->db->query( $sql );
		if ( false === $res ) { $this->last_error = $this->db->error; return false; }
		$this->insert_id = (int) $this->db->insert_id;
		$this->rows_affected = $this->db->affected_rows;
		if ( $res instanceof mysqli_result ) { return $res; }
		return preg_match( '/^\s*(insert|update|delete|replace)/i', $sql ) ? $this->db->affected_rows : true;
	}
	public function get_results( $sql, $output = OBJECT ) {
		$res = $this->query( $sql );
		if ( ! $res instanceof mysqli_result ) { return array(); }
		$out = array();
		while ( $row = $res->fetch_assoc() ) { $out[] = ARRAY_A === $output ? $row : (object) $row; }
		return $out;
	}
	public function get_row( $sql, $output = OBJECT ) { $r = $this->get_results( $sql, $output ); return $r[0] ?? null; }
	public function get_col( $sql ) { return array_map( static fn( $r ) => array_values( (array) $r )[0], $this->get_results( $sql ) ); }
	public function get_var( $sql ) { $r = $this->get_row( $sql ); return $r ? array_values( (array) $r )[0] : null; }
	public function insert( $table, $data, $format = null ) {
		$sql = "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_map( array( $this, 'esc' ), $data ) ) . ')';
		$r = $this->query( $sql );
		return false === $r ? false : 1;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set = array(); foreach ( $data as $k => $v ) { $set[] = "$k=" . $this->esc( $v ); }
		$w = array(); foreach ( $where as $k => $v ) { $w[] = "$k=" . $this->esc( $v ); }
		return $this->query( "UPDATE $table SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $w ) );
	}
	public function delete( $table, $where, $format = null ) {
		$w = array(); foreach ( $where as $k => $v ) { $w[] = "$k=" . $this->esc( $v ); }
		return $this->query( "DELETE FROM $table WHERE " . implode( ' AND ', $w ) );
	}
}

/**
 * Fault injection for the next statement(s) matching $match (see Test_WPDB::inject_fault()).
 * Options: skip (matches to let pass first), times (default 1), rollback, executed.
 */
function wpcap_test_fault( $match, $kind, array $options = array() ) {
	$GLOBALS['wpcap_test_faults'][] = array_merge( array( 'match' => $match, 'kind' => $kind, 'skip' => 0, 'times' => 1, 'fired' => array() ), $options );
}
/** Removes all fault rules; returns the statements they made fail. */
function wpcap_test_faults_clear() {
	$fired = array();
	foreach ( $GLOBALS['wpcap_test_faults'] ?? array() as $rule ) { $fired = array_merge( $fired, $rule['fired'] ); }
	$GLOBALS['wpcap_test_faults'] = array();
	return $fired;
}

$GLOBALS['wpdb'] = new Test_WPDB();
$GLOBALS['wpdb']->__construct_prefix();
$GLOBALS['wpcap_test_mails'] = array();
$GLOBALS['wpcap_test_notices'] = array();
$GLOBALS['wpcap_test_options'] = array( 'woocommerce_hold_stock_minutes' => 60, 'admin_email' => 'admin@example.com' );
$GLOBALS['wpcap_test_transients'] = array();
// Module tests reset RateLimit counters by clearing the transients: they run on the transient backend.
// The atomic database/object-cache backends are tested in modules/zz_a186.php (WPCAP_TEST_RATE_BACKEND=db for workers).
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = getenv( 'WPCAP_TEST_RATE_BACKEND' ) ?: 'transient';
$GLOBALS['wpcap_test_actions'] = array();

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t, $d = null ) { return esc_html( $t ); }
function esc_html_e( $t, $d = null ) { echo esc_html( $t ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url_raw( $u ) { return (string) $u; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_email( $e ) { return filter_var( trim( (string) $e ), FILTER_SANITIZE_EMAIL ); }
function is_email( $e ) { return false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? $e : false; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_kses_post( $t ) { return (string) $t; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ",", "." ); }
function user_can( $u, $c ) { return true; }
function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%012x', random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xfff ), random_int( 0x8000, 0xbfff ), random_int( 0, 0xffffffffffff ) ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_salt( $scheme = 'auth' ) { return 'salt-' . $scheme; } // Same in parent and worker processes (keyed hashes).
function current_user_can( $cap ) { return ! in_array( $cap, $GLOBALS['wpcap_test_denied_caps'] ?? array(), true ); }
function get_current_user_id() { return $GLOBALS['wpcap_test_user_id'] ?? 1; }
// tests/upgrade sets $GLOBALS['wpcap_test_option_store'] (options in the database, shared between processes).
function get_option( $k, $d = false ) { if ( isset( $GLOBALS['wpcap_test_option_store'] ) ) { return $GLOBALS['wpcap_test_option_store']->get( $k, $d ); } return $GLOBALS['wpcap_test_options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { if ( isset( $GLOBALS['wpcap_test_option_store'] ) ) { return $GLOBALS['wpcap_test_option_store']->update( $k, $v ); } $GLOBALS['wpcap_test_options'][ $k ] = $v; return true; }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { if ( isset( $GLOBALS['wpcap_test_option_store'] ) && method_exists( $GLOBALS['wpcap_test_option_store'], 'delete' ) ) { return $GLOBALS['wpcap_test_option_store']->delete( $k ); } unset( $GLOBALS['wpcap_test_options'][ $k ] ); return true; } }
function get_transient( $k ) { return $GLOBALS['wpcap_test_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['wpcap_test_transients'][ $k ] = $v; return true; }
function add_query_arg( $args, $url, $u = null ) { if ( ! is_array( $args ) ) { $args = array( $args => $url ); $url = $u; } return $url . '?' . http_build_query( $args ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function mysql2date( $f, $d ) { return gmdate( $f, strtotime( $d ) ); }
function get_date_from_gmt( $d ) { return $d; }
// HTML mails (A101: default for customer mails) are recorded with their plain-text part as 'body'
// (what the PHPMailer AltBody would carry, set through phpmailer_init) and the document as 'html'.
function wp_mail( $to, $subject, $body, $headers = '', $attachments = array() ) {
	$files = array();
	foreach ( (array) $attachments as $f ) { $files[ basename( $f ) ] = (string) file_get_contents( $f ); }
	$html = '';
	if ( in_array( 'Content-Type: text/html; charset=UTF-8', (array) $headers, true ) ) {
		$fake = new stdClass();
		$fake->AltBody = '';
		do_action( 'phpmailer_init', $fake );
		$html = $body;
		$body = '' !== $fake->AltBody ? $fake->AltBody : $body;
	}
	$GLOBALS['wpcap_test_mails'][] = compact( 'to', 'subject', 'body', 'files', 'html', 'headers' );
	return true;
}
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function get_bloginfo( $k = '' ) { return 'Testsite'; }
function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
function do_action( $hook, ...$args ) { $GLOBALS['wpcap_test_actions'][] = $hook; wpcap_test_run_hooks( $hook, $args, false ); }
function did_action( $hook ) { return in_array( $hook, $GLOBALS['wpcap_test_actions'] ?? array(), true ) || 'init' === $hook ? 1 : 0; }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, $q ); }
function apply_filters( $hook, $value, ...$args ) { if ( array_key_exists( $hook, $GLOBALS['wpcap_test_filters'] ?? array() ) ) { return $GLOBALS['wpcap_test_filters'][ $hook ]; } return wpcap_test_run_hooks( $hook, array_merge( array( $value ), $args ), true ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['wpcap_test_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args ); return true; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_action( $hook, $callback, $priority, $accepted_args ); }
function has_filter( $hook, $callback = false ) { foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() as $cbs ) { if ( $cbs ) { return true; } } return false; }
function has_action( $hook, $callback = false ) { return has_filter( $hook, $callback ); }
function apply_filters_deprecated( $hook, $args, $version, $replacement = '', $message = '' ) { $GLOBALS['wpcap_test_deprecated_hooks'][] = array( $hook, $version, $replacement ); return apply_filters( $hook, ...$args ); }
function remove_filter( $hook, $callback, $priority = 10 ) { foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ][ $priority ] ?? array() as $i => $entry ) { if ( $entry[0] === $callback ) { unset( $GLOBALS['wpcap_test_hooks'][ $hook ][ $priority ][ $i ] ); return true; } } return false; }
function remove_action( $hook, $callback, $priority = 10 ) { return remove_filter( $hook, $callback, $priority ); }
function remove_all_actions( $hook ) { unset( $GLOBALS['wpcap_test_hooks'][ $hook ] ); }
/** Minimal WordPress hook runner: only callbacks registered by tests/modules are executed. */
function wpcap_test_run_hooks( $hook, array $args, $is_filter ) {
	$value = $args[0] ?? null;
	$registered = $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array();
	ksort( $registered );
	foreach ( $registered as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted ) ) {
			if ( ! is_callable( $callback ) ) { continue; }
			$call_args = array_slice( $args, 0, max( 1, (int) $accepted ) );
			if ( $is_filter ) { $call_args[0] = $value; $value = call_user_func_array( $callback, $call_args ); $args[0] = $value; }
			else { call_user_func_array( $callback, $call_args ); }
		}
	}
	return $value;
}
function current_filter() { return 'test_filter'; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_role( $r ) { return null; }
function add_role() {}
function wc_get_price_decimals() { return 2; }
function determine_locale() { return 'de_DE'; }
function wp_date( $f, $ts = null, $tz = null ) { $d = new DateTime( "@" . ( $ts ?? time() ) ); $d->setTimezone( $tz instanceof DateTimeZone ? $tz : new DateTimeZone( "UTC" ) ); return $d->format( $f ); }
function wc_add_notice( $m, $t = 'success' ) { $GLOBALS['wpcap_test_notices'][] = $m; }

/* ---- WooCommerce fakes ---- */
trait Test_Meta {
	public array $meta = array();
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { return true; }
}
class WC_Product { use Test_Meta; public function __construct( public int $id, public int $parent = 0 ) {} public function get_parent_id() { return $this->parent; } public function set_virtual( $v ) {} }
class WC_Order_Item_Product {
	use Test_Meta;
	public function __construct( public int $id, public ?WC_Product $product, public int $qty, public string $total, public string $tax = '0' ) { $GLOBALS['wpcap_test_items'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_product() { return $this->product; }
	public function get_quantity() { return $this->qty; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
}
class WC_Order {
	use Test_Meta;
	public array $notes = array();
	public array $items = array();
	public string $email = 'buyer@example.com';
	public bool $paid = false;
	public function __construct( public int $id ) { $GLOBALS['wpcap_test_orders'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_billing_email() { return $this->email; }
	public function get_currency() { return 'EUR'; }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function is_paid() { return $this->paid; }
}
class WC_Order_Factory { public static function get_order_item( $id ) { return $GLOBALS['wpcap_test_items'][ $id ] ?? null; } }
class WooCommerce {}
function wc_get_order( $id ) { return $GLOBALS['wpcap_test_orders'][ (int) $id ] ?? false; }
function wc_get_product( $id ) { return $GLOBALS['wpcap_test_products'][ (int) $id ] ?? null; }

/* ---- Load plugin classes ---- */
spl_autoload_register( static function ( $class ) {
	if ( 0 === strpos( $class, 'PassCaptain\\' ) ) {
		$file = WPCAP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
		if ( is_readable( $file ) ) { require_once $file; }
	}
} );

/** Creates the plugin schema using the real Installer statements. */
function wpcap_test_install() {
	if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) { mkdir( ABSPATH . 'wp-admin/includes', 0777, true ); }
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', '<?php if ( ! function_exists( "dbDelta" ) ) { function dbDelta( $sql ) { global $wpdb; if ( preg_match( "/CREATE TABLE (\\S+)/", $sql, $m ) ) { $wpdb->query( "DROP TABLE IF EXISTS " . $m[1] ); } if ( false === $wpdb->query( $sql ) ) { fwrite( STDERR, $wpdb->last_error . "\n" ); } } }' );
	PassCaptain\Installer::install();
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$out = array();
		foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default; }
		return $out;
	}
}
