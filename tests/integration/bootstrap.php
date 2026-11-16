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
	/** Posts/postmeta tables: empty (no such tables) unless a test creates them (modules/zz_a291.php, zz_a292.php). */
	public $posts = '';
	public $postmeta = '';
	/** Usermeta table: empty (user meta lives in memory, $GLOBALS['p1u_user_meta']) unless a test creates one (modules/zz_a470.php). */
	public $usermeta = '';
	public $last_error = '';
	public $insert_id = 0;
	public $rows_affected = 0;
	/** Executed statements (as in WordPress' wpdb), used by query-budget tests. */
	public $num_queries = 0;
	/** When an array, every executed statement is appended (query-budget diagnostics). */
	public $wpcap_log = null;
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
	/** Like wpdb::get_blog_prefix(): the current prefix, or $GLOBALS['wpcap_test_blog_prefixes'][ $blog_id ]. */
	public function get_blog_prefix( $blog_id = null ) { return null === $blog_id ? $this->prefix : (string) ( $GLOBALS['wpcap_test_blog_prefixes'][ (int) $blog_id ] ?? $this->prefix ); }
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
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	private function esc( $v ) { return null === $v ? 'NULL' : "'" . $this->db->real_escape_string( (string) $v ) . "'"; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%%|%[dsfi]/', function ( $m ) use ( &$i, $args ) {
			if ( '%%' === $m[0] ) { return '%'; }
			$v = $args[ $i++ ] ?? '';
			// %i (WordPress 6.2+): identifier, backtick-quoted with embedded backticks doubled.
			if ( '%i' === $m[0] ) { return '`' . str_replace( '`', '``', (string) $v ) . '`'; }
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return $this->esc( $v );
		}, $query );
	}
	public function query( $sql ) {
		// Like WordPress' wpdb: the `query` filter sees every statement, num_queries counts them.
		$sql = apply_filters( 'query', $sql );
		++$this->num_queries;
		$this->last_error = '';
		if ( is_array( $this->wpcap_log ) ) { $this->wpcap_log[] = $sql; }
		if ( isset( $GLOBALS['wpcap_test_query_log'] ) && is_array( $GLOBALS['wpcap_test_query_log'] ) ) { $GLOBALS['wpcap_test_query_log'][] = $sql; }
		if ( false === $this->inject_fault( $sql ) ) { $this->insert_id = 0; $this->rows_affected = 0; return false; }
		// Like wpdb::query() (A498): a statement with invalid UTF-8 is not sent at all (all columns are utf8mb4).
		if ( ! mb_check_encoding( (string) $sql, 'UTF-8' ) ) { $this->insert_id = 0; $this->rows_affected = 0; $this->last_error = 'WordPress database error: Could not perform query because it contains invalid data.'; return false; }
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
	/** Column limits per table: name => [type 'char'|'byte', length] (like wpdb::get_col_length()). */
	private $col_lengths = array();
	private function col_lengths( $table ) {
		if ( ! isset( $this->col_lengths[ $table ] ) ) {
			$limits = array();
			$res = $this->db->query( "SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->db->real_escape_string( (string) $table ) . "'" );
			while ( $res instanceof mysqli_result && ( $row = $res->fetch_assoc() ) ) {
				$type = strtolower( (string) $row['DATA_TYPE'] );
				$bytes = array( 'tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295 );
				if ( in_array( $type, array( 'char', 'varchar' ), true ) ) { $limits[ strtolower( $row['COLUMN_NAME'] ) ] = array( 'char', (int) $row['CHARACTER_MAXIMUM_LENGTH'] ); }
				elseif ( isset( $bytes[ $type ] ) ) { $limits[ strtolower( $row['COLUMN_NAME'] ) ] = array( 'byte', $bytes[ $type ] ); }
			}
			if ( $limits ) { $this->col_lengths[ $table ] = $limits; } // Tables created later are looked up again.
			return $limits;
		}
		return $this->col_lengths[ $table ];
	}
	/**
	 * Like wpdb::process_fields() (A498): a %s value that is not valid UTF-8 or longer than its column
	 * (varchar: characters, text: bytes) makes insert()/update() fail with WordPress' error text – on
	 * every server, independent of the SQL mode (MySQL/MariaDB in non-strict mode would silently cut).
	 */
	private function field_problem( $table, array $data, $format ) {
		$limits = null;
		$problems = array();
		$i = 0;
		foreach ( $data as $field => $value ) {
			$f = is_array( $format ) ? ( $format[ $i ] ?? '%s' ) : ( is_string( $format ) ? $format : '%s' );
			++$i;
			if ( ! is_string( $value ) || '%s' !== $f ) { continue; }
			$limits = $limits ?? $this->col_lengths( $table );
			$limit = $limits[ strtolower( (string) $field ) ] ?? null;
			if ( ! mb_check_encoding( $value, 'UTF-8' ) || ( $limit && ( 'char' === $limit[0] ? mb_strlen( $value, 'UTF-8' ) : strlen( $value ) ) > $limit[1] ) ) { $problems[] = $field; }
		}
		if ( ! $problems ) { return false; }
		$this->last_error = 1 === count( $problems )
			? 'WordPress database error: Processing the value for the following field failed: ' . $problems[0] . '. The supplied value may be too long or contains invalid data.'
			: 'WordPress database error: Processing the values for the following fields failed: ' . implode( ', ', $problems ) . '. The supplied values may be too long or contain invalid data.';
		$this->insert_id = 0;
		$this->rows_affected = 0;
		if ( isset( $GLOBALS['wpcap_test_field_errors'] ) && is_array( $GLOBALS['wpcap_test_field_errors'] ) ) { $GLOBALS['wpcap_test_field_errors'][] = $table . ': ' . implode( ', ', $problems ); }
		return true;
	}
	public function insert( $table, $data, $format = null ) {
		if ( $this->field_problem( $table, $data, $format ) ) { return false; }
		$sql = "INSERT INTO $table (" . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_map( array( $this, 'esc' ), $data ) ) . ')';
		$r = $this->query( $sql );
		return false === $r ? false : 1;
	}
	public function replace( $table, $data, $format = null ) {
		$r = $this->query( "REPLACE INTO $table (" . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_map( array( $this, 'esc' ), $data ) ) . ')' );
		return false === $r ? false : max( 1, (int) $this->rows_affected );
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $this->field_problem( $table, $data, $format ) ) { return false; }
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
// A564: waitlist sign-ups need the double opt-in confirmation by default; the suites that are not about it
// join directly (modules/zz_a564_a565.php unsets this and tests the confirmation flow).
$GLOBALS['wpcap_test_filters']['wpcap_waitlist_double_opt_in'] = false;
// A618: PDF content streams stay uncompressed in the suites that look for PDF operators; modules/zz_a618.php tests the compression.
$GLOBALS['wpcap_test_filters']['wpcap_pdf_compress'] = false;
// A626: attachments are deleted right after sending in the suites; modules/zz_a626.php tests the deferral.
$GLOBALS['wpcap_test_filters']['wpcap_mail_attachment_ttl'] = 0;
$GLOBALS['wpcap_test_actions'] = array();

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
// Context gettext; $GLOBALS['wpcap_test_x'][ "$context\x04$text" ] fakes a catalogue entry (A362, A515).
if ( ! function_exists( '_x' ) ) { function _x( $t, $c, $d = null ) { return (string) ( $GLOBALS['wpcap_test_x'][ $c . "\x04" . $t ] ?? $t ); } }
if ( ! function_exists( '_nx' ) ) { function _nx( $s, $p, $n, $c, $d = null ) { return _x( 1 === (int) $n ? $s : $p, $c, $d ); } }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t, $d = null ) { return esc_html( $t ); }
function esc_html_e( $t, $d = null ) { echo esc_html( $t ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url_raw( $u ) { return (string) $u; }
function absint( $v ) { return abs( (int) $v ); }
// WordPress' own sanitisers (A498): sanitize_text_field(), sanitize_textarea_field(), sanitize_key(),
// sanitize_email(), is_email(), wp_strip_all_tags(), wp_check_invalid_utf8() – ported, checked against a
// real WordPress by tests/integration/sanitize-parity.php. Arrays in sanitize_email()/is_email() are a
// TypeError (strlen()) exactly as on PHP 8.
require __DIR__ . '/wp-sanitize.php';
function wp_unslash( $v ) { return $v; }
function wp_kses_post( $t ) { return (string) $t; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ",", "." ); }
// $GLOBALS['wpcap_test_user_can'] = callable( $user, $cap ) decides for every user (takes precedence);
// $GLOBALS['wpcap_test_user_caps'][ user id ] = capabilities of that user (A471); users without an entry have every capability.
function user_can( $u, $c ) {
	if ( isset( $GLOBALS['wpcap_test_user_can'] ) ) { return (bool) call_user_func( $GLOBALS['wpcap_test_user_can'], $u, $c ); }
	$id = is_object( $u ) ? (int) ( $u->ID ?? 0 ) : (int) $u;
	return isset( $GLOBALS['wpcap_test_user_caps'][ $id ] ) ? in_array( $c, (array) $GLOBALS['wpcap_test_user_caps'][ $id ], true ) : true;
}
/*
 * Multisite (A479): $GLOBALS['wpcap_test_multisite'] switches is_multisite() on, $GLOBALS['wpcap_test_blog_id']
 * is the current site, $GLOBALS['wpcap_test_blog_members'][ blog id ] lists the user ids of a site (sites
 * without an entry have every user). Per-site user options use the table prefix like WordPress.
 */
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return ! empty( $GLOBALS['wpcap_test_multisite'] ); } }
if ( ! function_exists( 'get_current_blog_id' ) ) { function get_current_blog_id() { return (int) ( $GLOBALS['wpcap_test_blog_id'] ?? 1 ); } }
function is_user_member_of_blog( $user_id = 0, $blog_id = 0 ) {
	$user_id = (int) ( $user_id ?: get_current_user_id() );
	$blog_id = (int) ( $blog_id ?: get_current_blog_id() );
	if ( ! isset( $GLOBALS['wpcap_test_blog_members'][ $blog_id ] ) ) { return $user_id > 0; }
	return in_array( $user_id, (array) $GLOBALS['wpcap_test_blog_members'][ $blog_id ], true );
}
function get_user_option( $option, $user = 0 ) {
	$user = (int) ( $user ?: get_current_user_id() );
	$meta = $GLOBALS['p1u_user_meta'][ $user ] ?? array();
	$site = $GLOBALS['wpdb']->get_blog_prefix() . $option;
	return array_key_exists( $site, $meta ) ? $meta[ $site ] : ( $meta[ $option ] ?? false );
}
function update_user_option( $user_id, $option, $value, $global = false ) {
	$GLOBALS['p1u_user_meta'][ (int) $user_id ][ ( $global ? '' : $GLOBALS['wpdb']->get_blog_prefix() ) . $option ] = $value;
	return true;
}
function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
if ( ! function_exists( 'wp_print_inline_script_tag' ) ) { function wp_print_inline_script_tag( $js, $attributes = array() ) { echo '<script>' . $js . "</script>\n"; } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/pass-captain/' . ltrim( (string) $path, '/' ); } }
if ( ! function_exists( 'wp_print_script_tag' ) ) { function wp_print_script_tag( $attributes ) { echo '<script src="' . htmlspecialchars( (string) ( $attributes['src'] ?? '' ), ENT_QUOTES ) . "\"></script>\n"; } }
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
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['wpcap_test_transients'][ $k ] = $v; $GLOBALS['wpcap_test_transient_ttl'][ $k ] = (int) $e; return true; }
/** Forgets all transients and rate-limit counters (wpcap_rate_limits holds the counters without an object cache). */
function wpcap_test_reset_transients() {
	global $wpdb;
	$GLOBALS['wpcap_test_transients'] = array();
	$wpdb->query( 'DELETE FROM `' . $wpdb->prefix . 'wpcap_rate_limits`' );
}
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
// Theme lookup (A659): child theme first, then parent; $GLOBALS['wpcap_test_theme_dirs'] lists the theme folders (default: none).
function locate_template( $names, $load = false, $once = true, $args = array() ) { foreach ( (array) $names as $n ) { foreach ( (array) ( $GLOBALS['wpcap_test_theme_dirs'] ?? array() ) as $d ) { if ( '' !== (string) $n && is_file( $d . '/' . $n ) ) { return $d . '/' . $n; } } } return ''; }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function get_bloginfo( $k = '' ) { return 'Testsite'; }
function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
function do_action( $hook, ...$args ) { $GLOBALS['wpcap_test_actions'][] = $hook; wpcap_test_run_hooks( $hook, $args, false ); }
function did_action( $hook ) { return in_array( $hook, $GLOBALS['wpcap_test_actions'] ?? array(), true ) || 'init' === $hook ? 1 : 0; }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, $q ); }
function apply_filters( $hook, $value, ...$args ) { if ( array_key_exists( $hook, $GLOBALS['wpcap_test_filters'] ?? array() ) ) { return $GLOBALS['wpcap_test_filters'][ $hook ]; } return wpcap_test_run_hooks( $hook, array_merge( array( $value ), $args ), true ); }
/**
 * Like WordPress: fires only when a callback is registered and records the deprecated use – both as the
 * bare hook name and as array( hook, version, replacement ), so checks of either form work.
 */
function apply_filters_deprecated( $hook, $args, $version, $replacement = '', $message = '' ) { if ( ! has_filter( $hook ) ) { return $args[0]; } $GLOBALS['wpcap_test_deprecated_hooks'][] = $hook; $GLOBALS['wpcap_test_deprecated_hooks'][] = array( $hook, $version, $replacement ); return apply_filters( $hook, ...$args ); }
function do_action_deprecated( $hook, $args, $version, $replacement = '', $message = '' ) { if ( ! has_filter( $hook ) ) { return; } $GLOBALS['wpcap_test_deprecated_hooks'][] = $hook; $GLOBALS['wpcap_test_deprecated_hooks'][] = array( $hook, $version, $replacement ); do_action( $hook, ...$args ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['wpcap_test_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args ); return true; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_action( $hook, $callback, $priority, $accepted_args ); }
function has_filter( $hook, $callback = false ) { foreach ( $GLOBALS['wpcap_test_hooks'][ $hook ] ?? array() as $cbs ) { if ( $cbs ) { return true; } } return false; }
function has_action( $hook, $callback = false ) { return has_filter( $hook, $callback ); }
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
/* Outbound DNS is faked (Security\OutboundHttp): every host resolves to a public address unless tests map it in $GLOBALS['wpcap_test_dns']. */
$GLOBALS['wpcap_test_dns'] = array();
add_filter( 'wpcap_outbound_resolve', static function ( $ips, $host ) { return $GLOBALS['wpcap_test_dns'][ $host ] ?? array( '93.184.215.14' ); }, 10, 2 );
/* The suite creates hundreds of unpaid orders from one (empty) client address; the per-network limit of unpaid orders (Sales\CheckoutThrottle) is tested on its own with this filter removed. */
function wpcap_test_unpaid_limit() { return 100000; }
add_filter( 'wpcap_checkout_unpaid_per_network', 'wpcap_test_unpaid_limit' );
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_role( $r ) { return null; }
function add_role() {}
function wc_get_price_decimals() { return 2; }
function determine_locale() { return (string) ( $GLOBALS['wpcap_test_locale'] ?? 'de_DE' ); }
if ( ! function_exists( 'is_rtl' ) ) { function is_rtl() { return ! empty( $GLOBALS['wpcap_test_rtl'] ); } }
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

/**
 * Confirms every waitlist sign-up whose confirmation mail (double opt-in) was captured since mail
 * index $since (event and slot waitlists). Returns the number of confirmed entries.
 */
function wpcap_test_confirm_waitlists( $since = 0 ) {
	$done = 0;
	foreach ( array_slice( $GLOBALS['wpcap_test_mails'], (int) $since ) as $mail ) {
		$body = html_entity_decode( (string) $mail['body'] );
		if ( preg_match_all( '/wpcap_waitlist_confirm=(\d+)&token=([a-f0-9]{64})/', $body, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) { $done += is_array( PassCaptain\Waitlists::confirm( (int) $hit[1], $hit[2] ) ) ? 1 : 0; }
		}
		if ( preg_match_all( '/wpcap_service_waitlist=(\d+)&do=confirm&token=([a-f0-9]{64})/', $body, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) { $done += is_array( PassCaptain\ServiceWaitlist::confirm( (int) $hit[1], $hit[2] ) ) ? 1 : 0; }
		}
	}
	return $done;
}

/**
 * PDF with every FlateDecode content stream inflated (PdfWriter compresses streams), so tests can
 * search drawing operators such as "(Text) Tj" or count " re" rectangles. Offsets are not valid afterwards.
 */
function wpcap_test_pdf_plain( $pdf ) {
	// Merged helper: wpcap_pdf_plain() inflates the same streams and additionally decodes glyph-id strings of
	// embedded Unicode fonts (A330), so checks work with either PDF writer of the two development lines.
	return wpcap_pdf_plain( $pdf );
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$out = array();
		foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default; }
		return $out;
	}
}

/**
 * A330: PDF text with embedded Unicode fonts is written as glyph ids (<0041…> Tj). This returns the
 * PDF with every such string decoded through the font's ToUnicode map back to a literal string
 * ("(Text) Tj", PDF-escaped, UTF-8), so tests can look for printed text.
 */
function wpcap_pdf_plain( $pdf ) {
	// A618: content streams may be FlateDecode-compressed; inflate them (font programs carry /Length1).
	$pdf = (string) $pdf;
	$out = '';
	$offset = 0;
	while ( preg_match( '/<< \/Length (\d+) \/Filter \/FlateDecode >>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$start = $m[0][1] + strlen( $m[0][0] );
		$raw = substr( $pdf, $start, (int) $m[1][0] );
		$inflated = @gzuncompress( $raw );
		$out .= substr( $pdf, $offset, $m[0][1] - $offset ) . ( false !== $inflated ? '<< /Length ' . strlen( $inflated ) . " >>\nstream\n" . $inflated : $m[0][0] . $raw );
		$offset = $start + (int) $m[1][0];
	}
	$pdf = $out . substr( $pdf, $offset );
	$maps = array();
	foreach ( array( 'F1' => 3, 'F2' => 4 ) as $font => $id ) {
		if ( ! preg_match( '/(?:^|\n)' . $id . ' 0 obj\n<<[^\n]*\/ToUnicode (\d+) 0 R/', $pdf, $m ) ) { continue; }
		if ( ! preg_match( '/(?:^|\n)' . $m[1] . ' 0 obj\n<< \/Length (\d+) >>\nstream\n/', $pdf, $s, PREG_OFFSET_CAPTURE ) ) { continue; }
		$cmap = substr( $pdf, $s[0][1] + strlen( $s[0][0] ), (int) $s[1][0] );
		preg_match_all( '/<([0-9A-F]{4})> <([0-9A-F]+)>/', $cmap, $pairs, PREG_SET_ORDER );
		foreach ( $pairs as $pair ) { $maps[ $font ][ hexdec( $pair[1] ) ] = mb_convert_encoding( hex2bin( $pair[2] ), 'UTF-8', 'UTF-16BE' ); }
	}
	if ( ! $maps ) { return $pdf; }
	return preg_replace_callback( '/\/(F[12]) ([0-9.]+) Tf((?: [0-9.]+ Tr| [0-9.]+ w)*) ([-0-9.]+) ([-0-9.]+) Td <([0-9A-F]*)> Tj/', static function ( $m ) use ( $maps ) {
		$text = '';
		foreach ( str_split( $m[6], 4 ) as $gid ) { $text .= '' === $gid ? '' : ( $maps[ $m[1] ][ hexdec( $gid ) ] ?? '?' ); }
		return '/' . $m[1] . ' ' . $m[2] . ' Tf' . $m[3] . ' ' . $m[4] . ' ' . $m[5] . ' Td (' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text ) . ') Tj';
	}, $pdf );
}
