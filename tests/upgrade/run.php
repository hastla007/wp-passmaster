<?php
/**
 * Upgrade integration tests (A181): every previous schema version is upgraded to the current one.
 *
 * Usage: php tests/upgrade/run.php   (disposable MySQL/MariaDB database, git history with tags, wp-cli
 *        or WPCAP_TEST_WP_DIR pointing to a WordPress download for the real dbDelta())
 *
 * Previous states: every git tag v* plus the commit before the latest DB_VERSION bump (and the refs
 * in WPCAP_UPGRADE_REFS, comma-separated). Each state is created by that version's own code
 * (tests/upgrade/legacy.php), filled with rows in every table, then upgraded by the current code
 * with WordPress' real dbDelta (tests/upgrade/worker.php). Asserted per state:
 * - tables, columns (type, NULL, default, extra, collation) and indexes equal a fresh install;
 * - one-off data migrations ran exactly once (option write log), existing rows are preserved;
 * - a second upgrade is a no-op (SHOW CREATE TABLE and options unchanged).
 * Plus: an upgrade aborted mid-schema and one aborted mid-data-migration (exception, lock released)
 * are completed by the next run, and two parallel upgrade processes upgrade exactly once.
 *
 * Tables use the prefix WPCAP_UPGRADE_PREFIX (default wpupg_) plus a short suffix per scenario; they
 * are dropped before and after the run (WPCAP_UPGRADE_KEEP=1 keeps them for debugging).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$root = dirname( __DIR__, 2 );
$base = getenv( 'WPCAP_UPGRADE_PREFIX' ) ?: 'wpupg_';
if ( ! preg_match( '/^[a-z0-9_]{1,12}_$/', $base ) ) {
	fwrite( STDERR, "WPCAP_UPGRADE_PREFIX must be lowercase, end with _ and have at most 13 characters\n" );
	exit( 2 );
}

$failures = 0;
$passes = 0;
$failed_names = array();
function check( $name, $condition, $detail = '' ) {
	global $failures, $passes, $failed_names;
	if ( $condition ) {
		$passes++;
		echo "  ok   $name\n";
	} else {
		$failures++;
		$failed_names[] = $name;
		echo "  FAIL $name\n";
		if ( '' !== $detail ) { echo '       ' . str_replace( "\n", "\n       ", rtrim( $detail ) ) . "\n"; }
	}
}

mysqli_report( MYSQLI_REPORT_OFF );
$db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
if ( $db->connect_error ) {
	fwrite( STDERR, 'DB connect failed: ' . $db->connect_error . "\n" );
	exit( 2 );
}
$db->set_charset( 'utf8mb4' );

/* ---- WordPress download for the real dbDelta() ---- */
$wp_dir = getenv( 'WPCAP_TEST_WP_DIR' ) ?: $root . '/tests/upgrade/.wordpress';
if ( ! is_readable( $wp_dir . '/wp-admin/includes/upgrade.php' ) ) {
	$version = getenv( 'WPCAP_TEST_WP_VERSION' ) ? ' --version=' . escapeshellarg( getenv( 'WPCAP_TEST_WP_VERSION' ) ) : '';
	echo "Downloading WordPress (wp core download) to $wp_dir\n";
	passthru( 'wp core download --allow-root --skip-content --force --path=' . escapeshellarg( $wp_dir ) . $version . ' 2>&1', $code );
	if ( 0 !== $code || ! is_readable( $wp_dir . '/wp-admin/includes/upgrade.php' ) ) {
		fwrite( STDERR, "No WordPress for dbDelta(): install wp-cli or set WPCAP_TEST_WP_DIR\n" );
		exit( 2 );
	}
}
putenv( 'WPCAP_UPGRADE_WP_DIR=' . $wp_dir );
include $wp_dir . '/wp-includes/version.php';
echo 'dbDelta from WordPress ' . ( $wp_version ?? '?' ) . ', DB ' . $db->server_info . ', PHP ' . PHP_VERSION . "\n";

$tmp = sys_get_temp_dir() . '/wpcap-upgrade-' . getmypid();
@mkdir( $tmp, 0777, true );

/* ---- Helpers ---- */

function q( $sql ) {
	global $db;
	$res = $db->query( $sql );
	if ( false === $res ) { throw new RuntimeException( $db->error . ' in ' . substr( $sql, 0, 300 ) ); }
	return $res;
}
function rows( $sql ) {
	$res = q( $sql );
	return $res instanceof mysqli_result ? $res->fetch_all( MYSQLI_ASSOC ) : array();
}
function esc( $v ) {
	global $db;
	return null === $v ? 'NULL' : "'" . $db->real_escape_string( (string) $v ) . "'";
}
function like_prefix( $prefix ) { return esc( addcslashes( $prefix, '_%\\' ) . '%' ); }

function drop_prefix( $prefix ) {
	foreach ( rows( 'SELECT table_name AS t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE ' . like_prefix( $prefix ) ) as $r ) {
		q( 'DROP TABLE IF EXISTS `' . $r['t'] . '`' );
	}
}

/** Empty prefix with an options table and the option write log. */
function init_prefix( $prefix ) {
	drop_prefix( $prefix );
	q( "CREATE TABLE `{$prefix}options` (option_name varchar(191) NOT NULL, option_value longtext NOT NULL, PRIMARY KEY (option_name)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
	q( "CREATE TABLE `{$prefix}upgrade_log` (log_id bigint unsigned NOT NULL AUTO_INCREMENT, pid int NOT NULL, option_name varchar(191) NOT NULL, old_value longtext NULL, new_value longtext NULL, PRIMARY KEY (log_id)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
}

function option( $prefix, $name ) {
	$r = rows( "SELECT option_value FROM `{$prefix}options` WHERE option_name=" . esc( $name ) );
	if ( ! $r ) { return false; }
	$raw = $r[0]['option_value'];
	$v = @unserialize( $raw );
	return false !== $v || 'b:0;' === $raw ? $v : $raw;
}
function set_option( $prefix, $name, $value ) {
	$raw = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	q( "REPLACE INTO `{$prefix}options` (option_name, option_value) VALUES (" . esc( $name ) . ',' . esc( $raw ) . ')' );
}

/** Option writes logged by the workers: callable( old, new ) filters them (unserialized values). */
function writes( $prefix, $name, $filter = null ) {
	$n = 0;
	foreach ( rows( "SELECT old_value, new_value FROM `{$prefix}upgrade_log` WHERE option_name=" . esc( $name ) . ' ORDER BY log_id' ) as $r ) {
		$old = null === $r['old_value'] ? null : ( @unserialize( $r['old_value'] ) ?: $r['old_value'] );
		$new = null === $r['new_value'] ? null : ( @unserialize( $r['new_value'] ) ?: $r['new_value'] );
		if ( ! $filter || $filter( $old, $new ) ) { $n++; }
	}
	return $n;
}

function php_cmd( $script, array $args, array $env = array() ) {
	$cmd = '';
	foreach ( $env as $k => $v ) { $cmd .= $k . '=' . escapeshellarg( $v ) . ' '; }
	return $cmd . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
}

/** Runs tests/upgrade/worker.php and returns its JSON result (null + output on errors). */
function worker( $prefix, $action, array $args = array() ) {
	$out = shell_exec( php_cmd( __DIR__ . '/worker.php', array_merge( array( $prefix, $action ), $args ) ) );
	return decode_result( (string) $out );
}
function decode_result( $out ) {
	$json = substr( $out, (int) strrpos( $out, "\n{" ) );
	$data = json_decode( trim( $json ), true );
	if ( ! is_array( $data ) ) {
		$data = json_decode( trim( $out ), true );
	}
	if ( ! is_array( $data ) ) {
		echo "       worker output: " . substr( $out, 0, 2000 ) . "\n";
		return null;
	}
	return $data;
}

/** Extracts a git ref (once) and returns the directory. */
function checkout( $ref ) {
	global $root, $tmp;
	$dir = $tmp . '/' . preg_replace( '/[^A-Za-z0-9._-]/', '_', $ref );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
		// Main file: pass-captain.php, or wp-pass-captain.php in releases up to 1.4 (renamed for WordPress.org, legacy-slug).
		$main = 'pass-captain.php';
		exec( 'git -C ' . escapeshellarg( $root ) . ' cat-file -e ' . escapeshellarg( $ref . ':' . $main ) . ' 2>/dev/null', $unused, $has_main );
		if ( 0 !== $has_main ) { $main = 'wp-' . $main; }
		$cmd = 'git -C ' . escapeshellarg( $root ) . ' archive --format=tar ' . escapeshellarg( $ref ) . ' src ' . escapeshellarg( $main ) . ' tests/integration/bootstrap.php | tar -x -C ' . escapeshellarg( $dir );
		exec( $cmd . ' 2>&1', $out, $code );
		if ( 0 !== $code ) { throw new RuntimeException( "git archive $ref failed: " . implode( "\n", $out ) ); }
	}
	return $dir;
}

/** Creates the schema of $ref under $prefix with that version's code; stores its options. */
function legacy_state( $ref, $prefix ) {
	init_prefix( $prefix );
	$out = (string) shell_exec( php_cmd( __DIR__ . '/legacy.php', array( checkout( $ref ) ), array( 'WPCAP_TEST_PREFIX' => $prefix ) ) );
	$data = decode_result( $out );
	if ( ! $data || empty( $data['version'] ) ) { throw new RuntimeException( "could not install $ref" ); }
	foreach ( $data['options'] as $name => $value ) { set_option( $prefix, $name, $value ); }
	return (string) $data['version'];
}

function plugin_tables( $prefix ) {
	return array_column( rows( 'SELECT table_name AS t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE ' . like_prefix( $prefix . 'wpcap_' ) . ' ORDER BY table_name' ), 't' );
}

/**
 * Normalized schema of the plugin tables: table => engine/collation, columns (without position:
 * dbDelta appends new columns at the end), indexes. Table names without the prefix.
 */
function schema( $prefix ) {
	$out = array();
	$like = like_prefix( $prefix . 'wpcap_' );
	$cut = strlen( $prefix );
	foreach ( rows( "SELECT table_name AS t, engine AS e, table_collation AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE $like" ) as $r ) {
		$out[ substr( $r['t'], $cut ) ] = array( 'table' => $r['e'] . ' ' . $r['c'], 'columns' => array(), 'indexes' => array() );
	}
	foreach ( rows( "SELECT table_name AS t, column_name AS n, column_type AS ty, is_nullable AS nu, column_default AS d, extra AS x, collation_name AS co FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name LIKE $like" ) as $r ) {
		$out[ substr( $r['t'], $cut ) ]['columns'][ $r['n'] ] = sprintf( '%s %s default=%s %s %s', $r['ty'], 'YES' === $r['nu'] ? 'NULL' : 'NOT NULL', var_export( $r['d'], true ), $r['x'], $r['co'] );
	}
	foreach ( rows( "SELECT table_name AS t, index_name AS i, non_unique AS nu, column_name AS c, sub_part AS s, index_type AS it FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name LIKE $like ORDER BY table_name, index_name, seq_in_index" ) as $r ) {
		$t = substr( $r['t'], $cut );
		$key = $r['i'];
		$out[ $t ]['indexes'][ $key ] = ( $out[ $t ]['indexes'][ $key ] ?? ( ( $r['nu'] ? 'KEY ' : 'UNIQUE ' ) . $r['it'] . ' ' ) ) . $r['c'] . ( $r['s'] ? '(' . $r['s'] . ')' : '' ) . ',';
	}
	ksort( $out );
	foreach ( $out as &$t ) { ksort( $t['columns'] ); ksort( $t['indexes'] ); }
	return $out;
}

/** Human-readable differences between two schema() results ($a = expected). */
function schema_diff( array $a, array $b ) {
	$d = array();
	foreach ( array_diff_key( $a, $b ) as $t => $_ ) { $d[] = "missing table $t"; }
	foreach ( array_diff_key( $b, $a ) as $t => $_ ) { $d[] = "extra table $t"; }
	foreach ( array_intersect_key( $a, $b ) as $t => $ta ) {
		$tb = $b[ $t ];
		if ( $ta['table'] !== $tb['table'] ) { $d[] = "$t: table options {$tb['table']}, expected {$ta['table']}"; }
		foreach ( array( 'columns', 'indexes' ) as $kind ) {
			foreach ( $ta[ $kind ] as $n => $def ) {
				if ( ! isset( $tb[ $kind ][ $n ] ) ) { $d[] = "$t: missing $kind $n ($def)"; } elseif ( $tb[ $kind ][ $n ] !== $def ) { $d[] = "$t.$n: {$tb[$kind][$n]}, expected $def"; }
			}
			foreach ( array_diff_key( $tb[ $kind ], $ta[ $kind ] ) as $n => $def ) { $d[] = "$t: extra $kind $n ($def)"; }
		}
	}
	return $d;
}

/** SHOW CREATE TABLE of every plugin table (without AUTO_INCREMENT counters): exact, incl. column order. */
function create_statements( $prefix ) {
	$out = array();
	foreach ( plugin_tables( $prefix ) as $t ) {
		$out[ $t ] = preg_replace( '/ AUTO_INCREMENT=\d+/', '', rows( "SHOW CREATE TABLE `$t`" )[0]['Create Table'] );
	}
	return $out;
}

/** Three rows in every table, every column filled (values derived from table, column and row). */
function seed( $prefix ) {
	$cols = rows( 'SELECT table_name AS t, column_name AS n, data_type AS dt, column_type AS ct, extra AS x, character_maximum_length AS len, numeric_scale AS sc FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name LIKE ' . like_prefix( $prefix . 'wpcap_' ) . ' ORDER BY table_name, ordinal_position' );
	$by_table = array();
	foreach ( $cols as $c ) { $by_table[ $c['t'] ][] = $c; }
	foreach ( $by_table as $table => $columns ) {
		for ( $r = 1; $r <= 3; $r++ ) {
			$names = array();
			$values = array();
			foreach ( $columns as $c ) {
				if ( false !== stripos( $c['x'], 'auto_increment' ) ) { continue; }
				$h = md5( "$table.{$c['n']}.$r" );
				switch ( strtolower( $c['dt'] ) ) {
					case 'tinyint': case 'smallint': case 'mediumint': case 'int': case 'bigint': case 'year':
						$v = 'year' === strtolower( $c['dt'] ) ? 2020 + $r : $r; break;
					case 'decimal': case 'float': case 'double':
						$v = $c['sc'] ? $r + 0.5 : $r; break;
					case 'datetime': case 'timestamp':
						$v = "2025-0$r-15 10:0$r:00"; break;
					case 'date':
						$v = "2025-0$r-15"; break;
					case 'time':
						$v = "10:0$r:00"; break;
					case 'enum': case 'set':
						preg_match( "/'([^']*)'/", $c['ct'], $m ); $v = $m[1] ?? ''; break;
					case 'char': case 'varchar': case 'binary': case 'varbinary':
						$v = substr( $h, 0, max( 1, min( (int) $c['len'], 24 ) ) ); break;
					default: // text, blob, json
						$v = '{"seed":"' . $h . '"}';
				}
				$names[] = '`' . $c['n'] . '`';
				$values[] = esc( $v );
			}
			q( "INSERT INTO `$table` (" . implode( ',', $names ) . ') VALUES (' . implode( ',', $values ) . ')' );
		}
	}
	// Realistic sales data for the capacity counters: order 1 is paid (2 units of type 1, item 1),
	// reservation 2 holds 3 units of type 1. The other generated rows count nothing (unknown states).
	// Counter columns that already exist are consistent with that, as the old version kept them.
	$has = static fn( $t, $c ) => (bool) rows( "SHOW COLUMNS FROM `$prefix$t` LIKE '$c'" );
	q( "UPDATE `{$prefix}wpcap_orders` SET status='paid', payment_status='paid' WHERE order_id=1" );
	q( "UPDATE `{$prefix}wpcap_order_items` SET order_id=1, ticket_type_id=1, quantity=2, refunded_quantity=0 WHERE item_id=1" );
	q( "UPDATE `{$prefix}wpcap_reservations` SET ticket_type_id=1, quantity=3, status='active', expires_at='2099-01-01 00:00:00' WHERE reservation_id=2" );
	q( "UPDATE `{$prefix}wpcap_ticket_types` SET occurrence_id=1 WHERE ticket_type_id=1" );
	foreach ( array( 'wpcap_ticket_types', 'wpcap_occurrences' ) as $t ) {
		if ( $has( $t, 'sold_qty' ) ) { q( "UPDATE `$prefix$t` SET sold_qty=0, held_qty=0" ); }
	}
	if ( $has( 'wpcap_ticket_types', 'sold_qty' ) ) {
		q( "UPDATE `{$prefix}wpcap_ticket_types` SET sold_qty=2, held_qty=3 WHERE ticket_type_id=1" );
		q( "UPDATE `{$prefix}wpcap_occurrences` SET sold_qty=2, held_qty=3 WHERE occurrence_id=1" );
	}
	foreach ( array( 'wpcap_order_items' => 'item_id=1 THEN 2', 'wpcap_reservations' => 'reservation_id=2 THEN 3' ) as $t => $case ) {
		if ( $has( $t, 'counted_qty' ) ) { q( "UPDATE `$prefix$t` SET counted_qty=CASE WHEN $case ELSE 0 END" ); }
	}
}

/** Every row of every plugin table (all columns as they exist now), as sorted JSON lines per table. */
function snapshot_rows( $prefix ) {
	$out = array();
	foreach ( schema( $prefix ) as $t => $def ) {
		$cols = array_keys( $def['columns'] );
		$lines = array_map( 'json_encode', rows( 'SELECT ' . implode( ',', array_map( static fn( $c ) => "`$c`", $cols ) ) . " FROM `$prefix$t`" ) );
		sort( $lines );
		$out[ $t ] = array( 'cols' => $cols, 'lines' => $lines );
	}
	return $out;
}

/**
 * Tables that are caches derived from other tables: a data migration may rebuild them, so their
 * rows are not compared. Everything else must survive the upgrade unchanged.
 */
const DERIVED_TABLES = array(
	'wpcap_alloc_buyers', // BuyerLimits::maybe_migrate_keys() recomputes the buyer keys from the orders.
);

/** Rows of $before (its columns only) that are missing or changed after the upgrade. */
function lost_rows( $prefix, array $before ) {
	$lost = array();
	foreach ( $before as $t => $snap ) {
		if ( in_array( $t, DERIVED_TABLES, true ) ) { continue; }
		$now = rows( 'SELECT ' . implode( ',', array_map( static fn( $c ) => "`$c`", $snap['cols'] ) ) . " FROM `$prefix$t`" );
		$missing = array_diff( $snap['lines'], array_map( 'json_encode', $now ) );
		if ( $missing ) {
			$lost[] = "$t: " . count( $missing ) . ' of ' . count( $snap['lines'] ) . ' rows changed/missing, e.g. ' . substr( reset( $missing ), 0, 300 );
		}
	}
	return $lost;
}

function audit_count( $prefix, $action ) {
	return (int) rows( "SELECT COUNT(*) AS n FROM `{$prefix}wpcap_audit_logs` WHERE action=" . esc( $action ) )[0]['n'];
}

/** Old site settings: a plaintext provider secret (moved by Secrets::migrate) and no credit-note prefix. */
function seed_options( $prefix ) {
	$settings = option( $prefix, 'wpcap_settings' );
	$settings = is_array( $settings ) ? $settings : array();
	unset( $settings['credit_note_prefix'] );
	$settings['stripe_secret_key'] = 'sk_test_upgrade_a181';
	set_option( $prefix, 'wpcap_settings', $settings );
}

/**
 * Assertions after a completed upgrade of a previous state.
 *
 * @param int $counter_rebuilds Expected capacity counter rebuilds (an abort after the rebuild repeats it; it is idempotent).
 */
function assert_upgraded( $label, $prefix, $old_version, array $before_rows, $counter_rebuilds = 1 ) {
	global $fresh_schema, $current_version;
	$diff = schema_diff( $fresh_schema, schema( $prefix ) );
	check( "$label: tables, columns and indexes equal a fresh install", ! $diff, implode( "\n", array_slice( $diff, 0, 25 ) ) );
	// The version carries a hash of the module schema, which contains the table prefix.
	$info = worker( $prefix, 'info' );
	check( "$label: schema version is current", is_array( $info ) && $info['version'] === option( $prefix, 'wpcap_db_version' ) && strtok( $current_version, '+' ) === strtok( (string) option( $prefix, 'wpcap_db_version' ), '+' ), (string) option( $prefix, 'wpcap_db_version' ) );
	check( "$label: highest schema version remembered", strtok( $current_version, '+' ) === option( $prefix, 'wpcap_db_version_max' ) );
	check( "$label: version written once", 1 === writes( $prefix, 'wpcap_db_version' ) );
	$lost = lost_rows( $prefix, $before_rows );
	check( "$label: existing rows preserved (" . array_sum( array_map( static fn( $x ) => count( $x['lines'] ), $before_rows ) ) . ' rows in ' . count( $before_rows ) . ' tables)', ! $lost, implode( "\n", array_slice( $lost, 0, 10 ) ) );
	// One-off data migrations.
	$settings = option( $prefix, 'wpcap_settings' );
	$pinned = writes( $prefix, 'wpcap_settings', static fn( $o, $n ) => 'GS' === ( $n['credit_note_prefix'] ?? '' ) && 'GS' !== ( is_array( $o ) ? ( $o['credit_note_prefix'] ?? '' ) : '' ) );
	if ( version_compare( strtok( $old_version, '+' ), '3.8.3', '<' ) ) {
		check( "$label: legacy credit-note prefix pinned exactly once", 'GS' === ( $settings['credit_note_prefix'] ?? '' ) && 1 === $pinned, "pinned $pinned times" );
	} else {
		check( "$label: credit-note prefix not pinned (schema already 3.8.3+)", 0 === $pinned );
	}
	$vault = option( $prefix, 'wpcap_secrets' );
	check( "$label: provider secret moved to the vault exactly once", ! array_key_exists( 'stripe_secret_key', (array) $settings ) && isset( $vault['stripe_secret_key'] ) && 1 === audit_count( $prefix, 'secrets_migrated' ) && 1 === (int) option( $prefix, 'wpcap_secrets_version' ) && 1 === writes( $prefix, 'wpcap_secrets' ), 'audit ' . audit_count( $prefix, 'secrets_migrated' ) . ', vault writes ' . writes( $prefix, 'wpcap_secrets' ) );
	$rebuilds = writes( $prefix, 'wpcap_capacity_counters', static fn( $o, $n ) => 'building' === ( $n['state'] ?? '' ) );
	check( "$label: capacity counters rebuilt $counter_rebuilds time(s) and equal the source sums", $counter_rebuilds === $rebuilds && in_array( option( $prefix, 'wpcap_capacity_counters' )['state'] ?? '', array( 'verifying', 'ready' ), true ) && is_array( $info ) && array() === $info['drift'], "rebuilds $rebuilds, drift " . json_encode( $info['drift'] ?? null ) );
	check( "$label: box office migration ran once", 2 === (int) option( $prefix, 'wpcap_boxoffice_migration' ) && 1 === writes( $prefix, 'wpcap_boxoffice_migration' ) );
	check( "$label: buyer-limit key migration ran at most once", 1 >= writes( $prefix, 'wpcap_alloc_key_version' ) && 0 < (int) option( $prefix, 'wpcap_alloc_key_version' ) );
	check( "$label: no schema error recorded", false === option( $prefix, 'wpcap_schema_error' ) );
}

/** A second upgrade run changes nothing. */
function assert_noop( $label, $prefix ) {
	$create = create_statements( $prefix );
	$log = (int) rows( "SELECT COUNT(*) AS n FROM `{$prefix}upgrade_log`" )[0]['n'];
	$again = worker( $prefix, 'upgrade' );
	check( "$label: second upgrade is a no-op", is_array( $again ) && false === $again['ran'] && '' === $again['exception'] && $create === create_statements( $prefix ) && $log === (int) rows( "SELECT COUNT(*) AS n FROM `{$prefix}upgrade_log`" )[0]['n'], json_encode( $again ) );
}

/**
 * Previous state + rows + old settings; returns [old version, rows before, expected counter
 * rebuilds (none when the old version already maintained the counters)].
 */
function prepare_state( $ref, $prefix ) {
	$old = legacy_state( $ref, $prefix );
	seed( $prefix );
	seed_options( $prefix );
	$rebuilds = 'ready' === ( option( $prefix, 'wpcap_capacity_counters' )['state'] ?? '' ) ? 0 : 1;
	return array( $old, snapshot_rows( $prefix ), $rebuilds );
}

/* ---- Run ---- */

$exit = 0;
try {
	drop_prefix( $base );

	echo "Upgrade tests: migration hooks of the modules\n";
	$hooks = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
		if ( '.php' === substr( (string) $file, -4 ) ) { $hooks += preg_match_all( "/add_action\(\s*'update_option_wpcap_db_version'/", (string) file_get_contents( (string) $file ) ); }
	}
	check( "worker runs every update_option_wpcap_db_version migration of src/ ($hooks)", 3 === $hooks, 'update the list in tests/upgrade/worker.php' );

	echo "Upgrade tests: fresh install (reference schema)\n";
	$fresh = $base . 'f_';
	init_prefix( $fresh );
	$r = worker( $fresh, 'upgrade' );
	check( 'fresh install runs', is_array( $r ) && true === $r['ran'] && '' === $r['exception'] && '' === $r['errors'], json_encode( $r ) );
	check( 'fresh install rebuilds no index (CREATE TABLE and index check agree)', is_array( $r ) && 0 === $r['index_changes'], json_encode( $r ) );
	$current_version = (string) ( $r['version'] ?? '' );
	$fresh_schema = schema( $fresh );
	check( 'fresh install created the plugin tables (' . count( $fresh_schema ) . ')', count( $fresh_schema ) >= 50 );
	assert_noop( 'fresh install', $fresh );

	// Previous versions: tags v*, the state before the latest schema bump, extra refs.
	$refs = array_filter( explode( "\n", trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . " tag -l 'v*' --sort=version:refname 2>/dev/null" ) ) ) );
	$bump = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . " log -n 1 --format=%H -G\"const DB_VERSION = '\" -- src/Installer.php 2>/dev/null" ) );
	if ( '' !== $bump && '' !== trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse --verify --quiet ' . escapeshellarg( $bump . '^' ) . ' 2>/dev/null' ) ) ) {
		$refs[] = $bump . '^';
	}
	foreach ( array_filter( explode( ',', (string) getenv( 'WPCAP_UPGRADE_REFS' ) ) ) as $extra ) { $refs[] = trim( $extra ); }
	$refs = array_values( array_unique( $refs ) );
	$tags = array_values( array_filter( $refs, static fn( $r ) => 0 === strpos( $r, 'v' ) ) );
	check( 'previous versions found (' . implode( ', ', $refs ) . ')', count( $tags ) > 0, 'no v* tags: fetch them (git fetch --tags, CI: fetch-depth 0)' );

	foreach ( $refs as $i => $ref ) {
		$prefix = $base . 's' . $i . '_';
		$label = ( 0 === strpos( $ref, 'v' ) ? $ref : 'before last schema bump' );
		list( $old, $before, $rebuilds ) = prepare_state( $ref, $prefix );
		echo "Upgrade from $label (schema $old)\n";
		check( "$label: previous state has an older schema version", version_compare( strtok( $old, '+' ), strtok( $current_version, '+' ), '<' ) );
		echo '       (' . count( schema_diff( $fresh_schema, schema( $prefix ) ) ) . " schema differences to a fresh install before the upgrade)\n";
		$r = worker( $prefix, 'upgrade' );
		check( "$label: upgrade runs", is_array( $r ) && true === $r['ran'] && '' === $r['exception'] && true === $r['lock_free'], json_encode( $r ) );
		echo '       (' . (int) ( $r['index_changes'] ?? 0 ) . " changed or obsolete indexes rebuilt/dropped by Installer::upgrade_indexes)\n";
		assert_upgraded( $label, $prefix, $old, $before, $rebuilds );
		assert_noop( $label, $prefix );
		if ( ! getenv( 'WPCAP_UPGRADE_KEEP' ) ) { drop_prefix( $prefix ); }
	}

	$oldest = $tags[0] ?? $refs[0] ?? null;
	if ( $oldest ) {
		echo "Upgrade aborted mid-schema ($oldest), completed by the next run\n";
		$prefix = $base . 'a1_';
		list( $old, $before, $rebuilds ) = prepare_state( $oldest, $prefix );
		$r = worker( $prefix, 'upgrade', array( '--fail-ddl=3' ) );
		check( 'aborted run fails with the injected exception', is_array( $r ) && false !== strpos( (string) $r['exception'], 'injected failure at DDL statement 3' ), json_encode( $r ) );
		check( 'upgrade lock is released after the exception', is_array( $r ) && true === $r['lock_free'] );
		check( 'schema version is not advanced by the aborted run', $old === option( $prefix, 'wpcap_db_version' ) && 0 === writes( $prefix, 'wpcap_db_version' ) );
		check( 'schema is partially upgraded', array() !== schema_diff( $fresh_schema, schema( $prefix ) ) );
		$r = worker( $prefix, 'upgrade' );
		check( 'next run completes the upgrade', is_array( $r ) && true === $r['ran'] && '' === $r['exception'], json_encode( $r ) );
		assert_upgraded( 'after mid-schema abort', $prefix, $old, $before, $rebuilds );
		assert_noop( 'after mid-schema abort', $prefix );

		echo "Upgrade aborted mid-data-migration ($oldest), completed by the next run\n";
		$prefix = $base . 'a2_';
		list( $old, $before, $rebuilds ) = prepare_state( $oldest, $prefix );
		// Fails before the secrets vault is written: schema done, counters rebuilt, prefix pinned.
		$r = worker( $prefix, 'upgrade', array( '--fail-option=wpcap_secrets' ) );
		check( 'aborted run fails with the injected exception', is_array( $r ) && false !== strpos( (string) $r['exception'], 'injected failure before writing option wpcap_secrets' ), json_encode( $r ) );
		check( 'upgrade lock is released after the exception', is_array( $r ) && true === $r['lock_free'] );
		check( 'schema version is not advanced, plaintext secret still there', $old === option( $prefix, 'wpcap_db_version' ) && isset( option( $prefix, 'wpcap_settings' )['stripe_secret_key'] ) );
		$r = worker( $prefix, 'upgrade' );
		check( 'next run completes the upgrade', is_array( $r ) && true === $r['ran'] && '' === $r['exception'], json_encode( $r ) );
		assert_upgraded( 'after mid-migration abort', $prefix, $old, $before, 2 * $rebuilds );
		assert_noop( 'after mid-migration abort', $prefix );

		echo "Two parallel upgrade processes ($oldest)\n";
		$prefix = $base . 'p_';
		list( $old, $before, $rebuilds ) = prepare_state( $oldest, $prefix );
		$start = sprintf( '%.3f', microtime( true ) + 2.0 );
		$procs = array();
		for ( $n = 0; $n < 2; $n++ ) {
			$procs[] = popen( php_cmd( __DIR__ . '/worker.php', array( $prefix, 'upgrade', '--slow-ms=20', '--start-at=' . $start ) ), 'r' );
		}
		$results = array();
		foreach ( $procs as $proc ) {
			$results[] = decode_result( (string) stream_get_contents( $proc ) );
			pclose( $proc );
		}
		$ran = array_sum( array_map( static fn( $x ) => is_array( $x ) && true === $x['ran'] ? 1 : 0, $results ) );
		$errors = array_filter( array_map( static fn( $x ) => is_array( $x ) ? $x['exception'] : 'no result', $results ) );
		check( 'exactly one of two parallel processes upgrades, the other skips without error', 1 === $ran && ! $errors, json_encode( $results ) );
		// The skipping process must have run while the upgrade was in progress (not after it).
		$winner = array_values( array_filter( $results, static fn( $x ) => is_array( $x ) && true === $x['ran'] ) )[0] ?? null;
		$loser = array_values( array_filter( $results, static fn( $x ) => is_array( $x ) && false === $x['ran'] ) )[0] ?? null;
		check( 'the processes overlapped (the skipping one finished while the other was upgrading)', $winner && $loser && $loser['finished'] < $winner['finished'] && $loser['started'] < $winner['finished'], json_encode( $results ) );
		assert_upgraded( 'parallel', $prefix, $old, $before, $rebuilds );
		assert_noop( 'parallel', $prefix );
	}
} catch ( Throwable $e ) {
	check( 'upgrade test run: ' . $e->getMessage(), false );
} finally {
	if ( ! getenv( 'WPCAP_UPGRADE_KEEP' ) ) { drop_prefix( $base ); }
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
}

if ( $failed_names ) {
	echo "\nFailed checks:\n";
	foreach ( $failed_names as $name ) { echo "  FAIL $name\n"; }
}
echo "\n$passes passed, $failures failed\n";
exit( $failures ? 1 : 0 );
