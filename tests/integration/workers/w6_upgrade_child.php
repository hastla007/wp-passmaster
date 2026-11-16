<?php
/**
 * Child process for the upgrade tests (tests/integration/modules/zzzz_w6_upgrade.php).
 *
 * Runs Installer::maybe_upgrade() against the tables with prefix WPCAP_TEST_PREFIX, with a dbDelta()
 * that behaves like WordPress' (creates missing tables, adds missing columns and indexes, changes
 * column types, never drops anything) instead of the test bootstrap's drop-and-recreate version.
 *
 * Env: WPCAP_TEST_PREFIX, W6_OLD_VERSION (stored wpcap_db_version before the run),
 *      W6_ABORT_AFTER (exit hard after N dbDelta statements: simulated process abort).
 * Prints one JSON line: {"ran":bool,"version":string,"error":mixed,"missing":[...]}.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

$GLOBALS['w6_statements'] = 0;
$GLOBALS['w6_errors'] = array();

/** WordPress-like dbDelta(): additive schema changes only. */
function dbDelta( $queries = '' ) {
	global $wpdb;
	$abort = getenv( 'W6_ABORT_AFTER' );
	foreach ( (array) $queries as $sql ) {
		if ( false !== $abort && '' !== $abort && ++$GLOBALS['w6_statements'] > (int) $abort ) {
			echo "ABORT\n";
			exit( 9 ); // Hard stop in the middle of the upgrade (GET_LOCK is released with the connection).
		}
		if ( ! preg_match( '/CREATE\s+TABLE\s+(\S+)\s*\((.*)\)([^)]*)$/is', (string) $sql, $m ) ) { $wpdb->query( $sql ); continue; }
		$table = trim( $m[1], '`' );
		$exists = in_array( $table, $wpdb->get_col( "SHOW TABLES LIKE '" . str_replace( '_', '\\_', $table ) . "'" ), true );
		if ( ! $exists ) {
			if ( false === $wpdb->query( $sql ) ) { $GLOBALS['w6_errors'][] = $wpdb->last_error; }
			continue;
		}
		$columns = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `$table`" ) as $c ) { $columns[ strtolower( $c->Field ) ] = strtolower( $c->Type ); }
		$indexes = array();
		foreach ( $wpdb->get_results( "SHOW INDEX FROM `$table`" ) as $i ) { $indexes[ strtolower( $i->Key_name ) ] = true; }
		foreach ( preg_split( '/\r?\n/', $m[2] ) as $line ) {
			$line = trim( rtrim( trim( $line ), ',' ) );
			if ( '' === $line ) { continue; }
			if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|FULLTEXT\s+KEY|KEY|INDEX)\s*([A-Za-z0-9_]*)\s*\(/i', $line, $k ) ) {
				$name = 0 === stripos( $k[1], 'PRIMARY' ) ? 'primary' : strtolower( $k[2] );
				if ( ! isset( $indexes[ $name ] ) && false === $wpdb->query( "ALTER TABLE `$table` ADD " . preg_replace( '/\s+/', ' ', $line ) ) ) { $GLOBALS['w6_errors'][] = $wpdb->last_error; }
				continue;
			}
			if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?\s+(\S+)/', $line, $c ) ) { continue; }
			$name = strtolower( $c[1] );
			if ( ! isset( $columns[ $name ] ) ) {
				if ( false === $wpdb->query( "ALTER TABLE `$table` ADD COLUMN $line" ) ) { $GLOBALS['w6_errors'][] = $wpdb->last_error; }
				continue;
			}
			// Type change (e.g. varchar(16) -> varchar(40)); integer display widths are ignored.
			$norm = static fn( $t ) => preg_replace( '/^((?:tiny|small|medium|big)?int)\(\d+\)/', '$1', strtolower( $t ) );
			if ( $norm( $c[2] ) !== $norm( strtok( $columns[ $name ], ' ' ) ) && false === $wpdb->query( "ALTER TABLE `$table` MODIFY COLUMN $line" ) ) { $GLOBALS['w6_errors'][] = $wpdb->last_error; }
		}
	}
	if ( $GLOBALS['w6_errors'] ) { $wpdb->last_error = $GLOBALS['w6_errors'][0]; }
	return array();
}

require dirname( __DIR__ ) . '/bootstrap.php';
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['wpcap_test_options'][ $k ] ); return true; } }

$GLOBALS['wpcap_test_options']['wpcap_db_version'] = (string) getenv( 'W6_OLD_VERSION' );
$ran = PassCaptain\Installer::maybe_upgrade( 'cron' );
echo json_encode( array(
	'ran' => $ran,
	'version' => get_option( 'wpcap_db_version' ),
	'expected' => PassCaptain\Installer::version(),
	'error' => get_option( PassCaptain\Installer::ERROR_OPTION ),
	'dbdelta_errors' => $GLOBALS['w6_errors'],
) ), "\n";
