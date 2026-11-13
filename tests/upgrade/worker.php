<?php
/**
 * Upgrade test worker (A181): one "request" against one table prefix, started by tests/upgrade/run.php.
 *
 * Loads the integration test stubs, but with
 * - WordPress' real dbDelta() (extracted from a WordPress download, WPCAP_UPGRADE_WP_DIR), so an
 *   upgrade really ALTERs the existing tables instead of recreating them;
 * - options in a database table ({prefix}options, cached per process like WordPress' alloptions),
 *   so parallel workers share them, and the update_option_/add_option_ actions fire;
 * - the data migrations modules hang on update_option_wpcap_db_version.
 * Every option write is logged to {prefix}upgrade_log (run.php counts one-off migrations with it).
 *
 * Usage: php tests/upgrade/worker.php <prefix> <action> [--fail-ddl=N] [--fail-option=NAME] [--slow-ms=N] [--start-at=UNIXTIME]
 *   action upgrade: Installer::maybe_upgrade( 'cron' ); prints JSON {ran, exception, lock_free, version}.
 *   action info:    prints JSON {version, lock_name, drift}.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$prefix = (string) ( $argv[1] ?? '' );
$action = (string) ( $argv[2] ?? '' );
if ( ! preg_match( '/^[a-z0-9_]+_$/', $prefix ) || ! in_array( $action, array( 'upgrade', 'info' ), true ) ) {
	fwrite( STDERR, "usage: worker.php <prefix> upgrade|info [options]\n" );
	exit( 2 );
}
$opts = array();
foreach ( array_slice( $argv, 3 ) as $arg ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $arg, $m ) ) { $opts[ $m[1] ] = $m[2]; }
}
putenv( 'WPCAP_TEST_PREFIX=' . $prefix );

// Real dbDelta before the bootstrap: the stub in tests/integration/wp/ only defines it if missing.
$wp_dir = (string) getenv( 'WPCAP_UPGRADE_WP_DIR' );
$upgrade_php = rtrim( $wp_dir, '/' ) . '/wp-admin/includes/upgrade.php';
if ( '' === $wp_dir || ! is_readable( $upgrade_php ) ) {
	fwrite( STDERR, "WPCAP_UPGRADE_WP_DIR must point to a WordPress download (wp-admin/includes/upgrade.php)\n" );
	exit( 2 );
}
eval( wpcap_upgrade_extract_function( (string) file_get_contents( $upgrade_php ), 'dbDelta' ) );

require dirname( __DIR__ ) . '/integration/bootstrap.php';

use PassCaptain\Installer;

/** Source of one top-level function of a PHP file (tokenizer, so braces in strings do not count). */
function wpcap_upgrade_extract_function( $source, $name ) {
	$tokens = token_get_all( $source );
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) { continue; }
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) { $j++; }
		if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || $name !== $tokens[ $j ][1] ) { continue; }
		$code = '';
		$depth = 0;
		$opened = false;
		for ( $k = $i; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			$code .= $text;
			if ( '{' === $text || ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { $depth++; $opened = true; }
			if ( '}' === $text ) { $depth--; }
			if ( $opened && 0 === $depth ) { return $code; }
		}
	}
	fwrite( STDERR, "function $name not found\n" );
	exit( 2 );
}

/** WordPress-like option API on {prefix}options: per-process cache, (de)serialization, hooks, write log. */
final class Upgrade_Option_Store {
	private $cache = null;
	public $fail_option = '';

	private function db() { return $GLOBALS['wpdb']; }
	private function table() { return $this->db()->prefix . 'options'; }

	private function load() {
		if ( null !== $this->cache ) { return; }
		$this->cache = array();
		foreach ( $this->db()->get_results( 'SELECT option_name, option_value FROM ' . $this->table() ) as $row ) {
			$this->cache[ $row->option_name ] = $row->option_value;
		}
	}

	public function get( $name, $default = false ) {
		$this->load();
		if ( ! array_key_exists( $name, $this->cache ) ) { return $default; }
		$raw = $this->cache[ $name ];
		$value = is_serialized_value( $raw ) ? unserialize( $raw ) : $raw;
		return $value;
	}

	public function update( $name, $value ) {
		$this->load();
		$exists = array_key_exists( $name, $this->cache );
		$old = $exists ? $this->get( $name ) : false;
		$raw = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) ( is_bool( $value ) ? (int) $value : $value );
		if ( $exists && $raw === $this->cache[ $name ] ) { return false; }
		if ( '' !== $this->fail_option && $name === $this->fail_option ) {
			$this->fail_option = '';
			throw new RuntimeException( "injected failure before writing option $name" );
		}
		$db = $this->db();
		$db->query( $db->prepare( 'INSERT INTO ' . $this->table() . ' (option_name, option_value) VALUES (%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $name, $raw ) );
		$db->query( $db->prepare( 'INSERT INTO ' . $db->prefix . 'upgrade_log (pid, option_name, old_value, new_value) VALUES (%d,%s,%s,%s)', getmypid(), $name, $exists ? $this->cache[ $name ] : null, $raw ) );
		$this->cache[ $name ] = $raw;
		if ( $exists ) {
			do_action( "update_option_{$name}", $old, $value, $name );
		} else {
			do_action( "add_option_{$name}", $name, $value );
		}
		return true;
	}

	public function delete( $name ) {
		$this->load();
		if ( ! array_key_exists( $name, $this->cache ) ) { return false; }
		$db = $this->db();
		$db->query( $db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE option_name=%s', $name ) );
		$db->query( $db->prepare( 'INSERT INTO ' . $db->prefix . 'upgrade_log (pid, option_name, old_value, new_value) VALUES (%d,%s,%s,NULL)', getmypid(), $name, $this->cache[ $name ] ) );
		unset( $this->cache[ $name ] );
		return true;
	}
}

function is_serialized_value( $raw ) {
	return is_string( $raw ) && ( 'b:0;' === $raw || 'N;' === $raw || false !== @unserialize( $raw ) ); // phpcs:ignore
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) { return $GLOBALS['wpcap_test_option_store']->delete( $name ); }
}

// Installer::install() requires wp-admin/includes/upgrade.php; dbDelta() is already defined above.
if ( ! is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n" );
}

global $wpdb;
$wpdb->options = $wpdb->prefix . 'options';
$GLOBALS['wpcap_test_option_store'] = new Upgrade_Option_Store();
$GLOBALS['wpcap_test_option_store']->fail_option = (string) ( $opts['fail-option'] ?? '' );

// Data migrations that modules run when the schema version changes (Module::register() hooks;
// run.php checks that this list matches every update_option_wpcap_db_version hook in src/).
$wpcap_upgrade_migrations = array(
	array( PassCaptain\Modules\BoxOffice::class, 'migrate' ),
	array( PassCaptain\Allocations\BuyerLimits::class, 'maybe_migrate_keys' ),
	array( PassCaptain\Modules\GuestLists::class, 'grant_caps' ),
);
foreach ( $wpcap_upgrade_migrations as $callback ) {
	add_action( 'add_option_wpcap_db_version', $callback, 10, 0 );
	add_action( 'update_option_wpcap_db_version', $callback, 10, 0 );
}

// Indexes rebuilt or dropped by Installer::upgrade_indexes() (dbDelta itself never drops one).
$GLOBALS['wpcap_upgrade_index_changes'] = 0;
add_filter(
	'query',
	static function ( $sql ) {
		if ( preg_match( '/^\s*ALTER\s+TABLE\s+\S+\s+DROP\s+INDEX\b/i', (string) $sql ) ) { $GLOBALS['wpcap_upgrade_index_changes']++; }
		return $sql;
	}
);

// Fault injection and slowdown (only DDL statements count: CREATE/ALTER TABLE issued by dbDelta).
$fail_ddl = (int) ( $opts['fail-ddl'] ?? 0 );
$slow_us = 1000 * (int) ( $opts['slow-ms'] ?? 0 );
if ( $fail_ddl || $slow_us ) {
	$ddl_seen = 0;
	add_filter(
		'query',
		static function ( $sql ) use ( &$ddl_seen, $fail_ddl, $slow_us ) {
			if ( preg_match( '/^\s*(CREATE|ALTER)\s+TABLE\b/i', (string) $sql ) ) {
				++$ddl_seen;
				if ( $fail_ddl && $ddl_seen === $fail_ddl ) { throw new RuntimeException( "injected failure at DDL statement $ddl_seen" ); }
				if ( $slow_us ) { usleep( $slow_us ); }
			}
			return $sql;
		}
	);
}

if ( 'info' === $action ) {
	echo json_encode(
		array(
			'version'   => Installer::version(),
			'lock_name' => Installer::lock_name(),
			'drift'     => PassCaptain\CapacityCounters::drift(),
		)
	);
	exit( 0 );
}

// Barrier: parallel workers start their upgrade at the same moment.
if ( isset( $opts['start-at'] ) ) {
	while ( microtime( true ) < (float) $opts['start-at'] ) { usleep( 1000 ); }
}
$result = array( 'ran' => false, 'exception' => '', 'lock_free' => null, 'started' => microtime( true ) );
try {
	$result['ran'] = Installer::maybe_upgrade( 'cron' );
} catch ( Throwable $e ) {
	$result['exception'] = $e->getMessage();
}
$result['finished'] = microtime( true );
// Still in this process (and connection): the lock must have been released by run_locked().
$result['lock_free'] = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', Installer::lock_name() ) );
$result['version'] = (string) get_option( 'wpcap_db_version', '' );
$result['index_changes'] = $GLOBALS['wpcap_upgrade_index_changes'];
$result['errors'] = (string) ( get_option( 'wpcap_schema_error' ) ? json_encode( get_option( 'wpcap_schema_error' ) ) : '' );
echo json_encode( $result );
