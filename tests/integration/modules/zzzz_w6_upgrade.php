<?php
/**
 * Upgrade tests from older plugin versions (W6).
 *
 * The schemas of 1.0.0/1.2.0 (DB 3.3.0) and 1.3.0 (DB 3.4.0) are installed from fixtures
 * (tests/integration/fixtures/schema-*.sql, extracted from the installers of those releases) under a
 * separate table prefix and filled with data: generic rows in every table plus a realistic business
 * fixture (event, paid order with tickets, check-in, pending order with hold, refund, queued job).
 * The current Installer then upgrades it in child processes with a WordPress-like dbDelta():
 *
 *  - plain upgrade, then again (idempotent re-run),
 *  - hard process abort in the middle of the upgrade, then the retry,
 *  - two upgrade processes in parallel.
 *
 * After every run: no data lost or changed (hash of every pre-existing column of every table), the
 * schema equals a fresh install (tables and columns), the stored version is current, and the
 * business data still works with the current code (check-in of an old ticket, availability with
 * old holds, new orders, queued jobs).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Orders;
use PassCaptain\Checkin;

global $wpdb;
$p = $wpdb->prefix;
$w6u_real_prefix = $p;

/** Statements of a schema fixture for $prefix. */
function w6u_fixture( $file, $prefix ) {
	global $wpdb;
	$sql = str_replace( array( '{prefix}', '{charset}' ), array( $prefix, $wpdb->get_charset_collate() ), (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $file ) );
	return array_values( array_filter( array_map( 'trim', explode( '-- ;;', $sql ) ), static fn( $s ) => 0 === stripos( $s, 'CREATE TABLE' ) ) );
}
function w6u_drop( $prefix ) {
	global $wpdb;
	foreach ( $wpdb->get_col( "SHOW TABLES LIKE '" . str_replace( '_', '\\_', $prefix ) . "%'" ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }
}
/** Columns (name => type) of every table with $prefix, keyed by the unprefixed table name. */
function w6u_columns( $prefix ) {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->get_col( "SHOW TABLES LIKE '" . str_replace( '_', '\\_', $prefix ) . "wpcap\\_%'" ) as $t ) {
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `$t`" ) as $c ) { $out[ substr( $t, strlen( $prefix ) ) ][ strtolower( $c->Field ) ] = strtolower( $c->Type ); }
	}
	ksort( $out );
	return $out;
}
/** Generic rows for every table: type-appropriate, distinct values for every column without default. */
function w6u_fill( $prefix, array $statements, $rows = 3 ) {
	global $wpdb;
	foreach ( $statements as $sql ) {
		preg_match( '/CREATE TABLE (\S+)\s*\((.*)\)/is', $sql, $m );
		$table = $m[1];
		$cols = array();
		foreach ( preg_split( '/\r?\n/', $m[2] ) as $line ) {
			$line = trim( $line );
			if ( ! preg_match( '/^([a-z0-9_]+)\s+([a-z]+)(\(([^)]*)\))?(.*)$/i', $line, $c ) || in_array( strtoupper( $c[1] ), array( 'PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'FULLTEXT' ), true ) ) { continue; }
			if ( false !== stripos( $c[5], 'AUTO_INCREMENT' ) ) { continue; }
			$cols[ $c[1] ] = array( strtolower( $c[2] ), $c[4] ?? '' );
		}
		for ( $r = 1; $r <= $rows; $r++ ) {
			$row = array();
			foreach ( $cols as $name => list( $type, $len ) ) {
				$n = 900000 + $r;
				switch ( true ) {
					case in_array( $type, array( 'bigint', 'int', 'mediumint', 'integer' ), true ): $v = $n; break;
					case 'smallint' === $type: $v = 2000 + $r; break;
					case 'tinyint' === $type: $v = $r % 2; break;
					case in_array( $type, array( 'decimal', 'float', 'double' ), true ): $v = '1.5'; break;
					case 'datetime' === $type || 'timestamp' === $type: $v = sprintf( '2026-01-%02d 10:00:00', $r ); break;
					case 'date' === $type: $v = sprintf( '2026-01-%02d', $r ); break;
					case 'time' === $type: $v = '10:00:00'; break;
					case 'char' === $type: $v = substr( hash( 'sha256', "$table|$name|$r" ), 0, max( 1, (int) $len ) ); break;
					case 'varchar' === $type: $v = substr( "w6-$name-$r", 0, max( 1, (int) $len ) ); break;
					default: $v = '{"w6":' . $r . '}'; // text, longtext, json.
				}
				$row[ $name ] = $v;
			}
			if ( false === $wpdb->insert( $table, $row ) ) { return "$table: " . $wpdb->last_error; }
		}
	}
	return '';
}
/** Hash of every column that existed in the old schema, per table. */
/**
 * Columns the merged upgrade encrypts at rest in place (Security\Vault::migrate(): webhook signing
 * secrets, partner payout details). They are compared by "encrypted or empty" instead of their bytes.
 */
const W6U_SEALED = array( 'wpcap_webhook_subscriptions' => array( 'secret' ), 'wpcap_partners' => array( 'payout_details' ) );
function w6u_hashes( $prefix, array $old_columns ) {
	global $wpdb;
	$out = array();
	foreach ( $old_columns as $table => $cols ) {
		$sealed = W6U_SEALED[ $table ] ?? array();
		$names = array_map( static fn( $c ) => "`$c`", array_diff( array_keys( $cols ), $sealed ) );
		// The audit log is append-only: migrations of the merged line audit themselves (secrets moved,
		// legal texts archived); only the rows that existed before the upgrade must stay unchanged.
		$limit = 'wpcap_audit_logs' === $table ? ' LIMIT ' . ( $GLOBALS['w6u_audit_rows'][ $prefix ] ??= (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}{$table}`" ) ) : '';
		$order = 'wpcap_audit_logs' === $table && isset( $cols['audit_id'] ) ? '`audit_id`' : implode( ',', $names );
		$rows = $wpdb->get_results( "SELECT " . implode( ',', $names ) . " FROM `{$prefix}{$table}` ORDER BY " . $order . $limit, ARRAY_A );
		foreach ( $sealed as $col ) {
			if ( isset( $cols[ $col ] ) ) {
				$values = (array) $wpdb->get_col( "SELECT `$col` FROM `{$prefix}{$table}`" );
				$out[ "$table.$col" ] = count( $values ) . ':' . count( array_filter( $values, static fn( $v ) => '' !== (string) $v ) );
				$GLOBALS['w6u_plain'][ $prefix ][ "$table.$col" ] = array_values( array_filter( $values, static fn( $v ) => '' !== (string) $v && ! PassCaptain\Security\Vault::is_encrypted( (string) $v ) ) );
			}
		}
		$out[ $table ] = count( $rows ) . ':' . md5( json_encode( $rows ) );
	}
	return $out;
}
/** Realistic business data in the old schema. Returns ids and the plaintext ticket code. */
function w6u_business( $prefix ) {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$prefix}wpcap_events", array( 'name' => 'Altes Konzert', 'slug' => 'w6-old-' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = (int) $wpdb->insert_id;
	$wpdb->insert( "{$prefix}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 3 * 86400 ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = (int) $wpdb->insert_id;
	$wpdb->insert( "{$prefix}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 2000, 'currency' => 'EUR', 'capacity' => 10, 'max_per_order' => 10, 'status' => 'active' ) );
	$type = (int) $wpdb->insert_id;
	$order = static function ( $status, $pay, $email ) use ( $wpdb, $prefix, $now ) {
		$wpdb->insert( "{$prefix}wpcap_orders", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'idempotency_key' => hash( 'sha256', uniqid( '', true ) ), 'customer_email' => $email, 'access_token_hash' => hash( 'sha256', 'x' ), 'status' => $status, 'payment_status' => $pay, 'currency' => 'EUR', 'total_minor' => 4000, 'subtotal_minor' => 4000, 'price_snapshot' => '{}', 'payment_provider' => 'manual', 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	};
	$paid = $order( 'complete', 'paid', 'old-paid@example.com' );
	$wpdb->insert( "{$prefix}wpcap_order_items", array( 'order_id' => $paid, 'ticket_type_id' => $type, 'quantity' => 2, 'unit_price_minor' => 2000, 'line_total_minor' => 4000 ) );
	$code = bin2hex( random_bytes( 32 ) );
	$wpdb->insert( "{$prefix}wpcap_tickets", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'order_id' => $paid, 'ticket_type_id' => $type, 'token_hash' => hash( 'sha256', $code ), 'status' => 'valid', 'issued_at' => $now ) );
	$wpdb->insert( "{$prefix}wpcap_tickets", array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'order_id' => $paid, 'ticket_type_id' => $type, 'token_hash' => hash( 'sha256', 'used' . $code ), 'status' => 'checked_in', 'issued_at' => $now ) );
	$used = (int) $wpdb->insert_id;
	$wpdb->insert( "{$prefix}wpcap_checkins", array( 'ticket_id' => $used, 'event_id' => $event, 'user_id' => 1, 'result' => 'checked_in', 'created_at' => $now ) );
	$pending = $order( 'pending_payment', 'pending', 'old-pending@example.com' );
	$wpdb->insert( "{$prefix}wpcap_order_items", array( 'order_id' => $pending, 'ticket_type_id' => $type, 'quantity' => 3, 'unit_price_minor' => 2000, 'line_total_minor' => 6000 ) );
	$wpdb->insert( "{$prefix}wpcap_reservations", array( 'order_id' => $pending, 'ticket_type_id' => $type, 'quantity' => 3, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'status' => 'active' ) );
	$wpdb->insert( "{$prefix}wpcap_refunds", array( 'order_id' => $paid, 'idempotency_key' => hash( 'sha256', 'old-refund' ), 'amount_minor' => 0, 'tax_minor' => 0, 'currency' => 'EUR', 'ticket_ids' => '[]', 'reason' => 'alt', 'status' => 'rejected', 'requested_by' => 1, 'approved_by' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$wpdb->insert( "{$prefix}wpcap_jobs", array( 'idempotency_key' => hash( 'sha256', 'w6-old-job' ), 'job_type' => 'w6_old_job', 'payload' => '{}', 'run_after' => '2000-01-01 00:00:00', 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now ) );
	$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_orders WHERE order_id IN ($paid,$pending)" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_tickets WHERE order_id=$paid" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_reservations WHERE order_id=$pending" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_jobs WHERE job_type='w6_old_job'" );
	return array( 'event' => $event, 'type' => $type, 'paid' => $paid, 'pending' => $pending, 'code' => $code, 'error' => 6 === $rows ? '' : "business fixture incomplete ($rows of 6 rows)" );
}
/** Runs the upgrade child; returns [exit code, decoded JSON or raw output]. */
function w6u_child( $prefix, $old_version, $abort = null, $async = false ) {
	$env = 'WPCAP_TEST_PREFIX=' . escapeshellarg( $prefix ) . ' W6_OLD_VERSION=' . escapeshellarg( $old_version ) . ( null !== $abort ? ' W6_ABORT_AFTER=' . (int) $abort : '' );
	foreach ( array( 'WPCAP_TEST_DB_HOST', 'WPCAP_TEST_DB_USER', 'WPCAP_TEST_DB_PASS', 'WPCAP_TEST_DB_NAME' ) as $k ) { if ( false !== getenv( $k ) ) { $env .= " $k=" . escapeshellarg( getenv( $k ) ); } }
	$cmd = "env $env " . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/w6_upgrade_child.php' ) . ' 2>&1';
	if ( $async ) { return popen( $cmd, 'r' ); }
	exec( $cmd, $out, $code );
	return array( $code, w6u_decode( implode( "\n", $out ) ) );
}
function w6u_decode( $out ) {
	foreach ( array_reverse( preg_split( '/\R/', (string) $out ) ) as $line ) { $j = json_decode( $line, true ); if ( is_array( $j ) ) { return $j; } }
	return (string) $out;
}

// Schema of a fresh install by the current code (own prefix: the shared test tables may contain more).
w6u_drop( 'w6ufresh_' );
$wpdb->prefix = 'w6ufresh_';
PassCaptain\Installer::flush_version_cache();
wpcap_test_install();
$w6u_fresh = w6u_columns( 'w6ufresh_' );
w6u_drop( 'w6ufresh_' );
$wpdb->prefix = $w6u_real_prefix;
PassCaptain\Installer::flush_version_cache();
$w6u_cases = array(
	array( 'schema-1.0.0.sql', '3.3.0', '1.0.0/1.2.0', 'w6u10_', true ),
	array( 'schema-1.3.0.sql', '3.4.0', '1.3.0', 'w6u13_', false ),
);
foreach ( $w6u_cases as list( $file, $db_version, $label, $prefix, $full ) ) {
	echo "W6 upgrade from $label\n";
	w6u_drop( $prefix );
	$statements = w6u_fixture( $file, $prefix );
	foreach ( $statements as $sql ) { $wpdb->query( $sql ); }
	$old_columns = w6u_columns( $prefix );
	$fill_error = w6u_fill( $prefix, $statements );
	$wpdb->prefix = $prefix;
	$biz = w6u_business( $prefix );
	$wpdb->prefix = $w6u_real_prefix;
	$before = w6u_hashes( $prefix, $old_columns );
	check( "$label fixture: " . count( $statements ) . ' tables with data installed', '' === $fill_error && '' === $biz['error'] && count( $old_columns ) === count( $statements ) );
	$stored = $db_version . '+old';

	$verify = static function ( $what, $result ) use ( $prefix, $old_columns, $before, $w6u_fresh ) {
		$after = w6u_hashes( $prefix, $old_columns );
		$changed = array_keys( array_diff_assoc( $before, $after ) );
		$cols = w6u_columns( $prefix );
		$schema_diff = array();
		foreach ( $w6u_fresh as $t => $c ) {
			if ( ! isset( $cols[ $t ] ) ) { $schema_diff[] = "missing table $t"; continue; }
			foreach ( array_diff_key( $c, $cols[ $t ] ) as $name => $type ) { $schema_diff[] = "missing $t.$name"; }
		}
		$ok = is_array( $result ) && empty( $result['error'] ) && empty( $result['dbdelta_errors'] ) && $result['version'] === $result['expected'];
		$plain = array_filter( (array) ( $GLOBALS['w6u_plain'][ $prefix ] ?? array() ) );
		check( "$what: version current, schema equals a fresh install, no row of " . count( $old_columns ) . ' tables changed (secret columns encrypted at rest)', $ok && ! $changed && ! $schema_diff && ! $plain );
		if ( ! $ok || $changed || $schema_diff ) { echo '         ' . substr( json_encode( array( 'result' => $result, 'changed' => array_slice( $changed, 0, 5 ), 'plaintext' => array_keys( $plain ), 'schema' => array_slice( $schema_diff, 0, 5 ) ) ), 0, 600 ) . "\n"; }
	};

	if ( $full ) {
		list( $code, $res ) = w6u_child( $prefix, $stored, 30 );
		check( "$label: upgrade process aborted after 30 of " . count( $statements ) . ' schema statements', 9 === $code );
		$mid = w6u_hashes( $prefix, $old_columns );
		check( "$label: data intact after the abort", $mid === $before );
		list( $code, $res ) = w6u_child( $prefix, $stored );
		check( "$label: retry after the abort runs the upgrade", 0 === $code && is_array( $res ) && true === $res['ran'] );
		$verify( "$label after abort + retry", $res );
	} else {
		list( $code, $res ) = w6u_child( $prefix, $stored );
		check( "$label: upgrade runs", 0 === $code && is_array( $res ) && true === $res['ran'] );
		$verify( "$label upgrade", $res );
	}
	list( $code, $res ) = w6u_child( $prefix, $stored );
	$verify( "$label upgrade repeated on the upgraded schema", $res );
	if ( $full ) {
		$a = w6u_child( $prefix, $stored, null, true );
		$b = w6u_child( $prefix, $stored, null, true );
		$ra = w6u_decode( stream_get_contents( $a ) );
		$ca = pclose( $a );
		$rb = w6u_decode( stream_get_contents( $b ) );
		$cb = pclose( $b );
		check( "$label: two parallel upgrade processes both finish (at least one ran, the other skipped or re-ran)", 0 === $ca && 0 === $cb && is_array( $ra ) && is_array( $rb ) && ( $ra['ran'] || $rb['ran'] ) );
		$verify( "$label after parallel upgrades", $ra['ran'] ? $ra : $rb );
	}

	// The upgraded data works with the current code.
	$wpdb->prefix = $prefix;
	PassCaptain\Installer::flush_version_cache();
	wpcap_test_reset_transients();
	$ci = Checkin::perform( $biz['code'], array( 'idempotency_key' => 'w6u-' . $prefix ) );
	$avail = Orders::availability( $biz['type'] );
	$new = Orders::create( array( 'email' => 'w6u-new@example.com', 'items' => array( array( 'ticket_type_id' => $biz['type'], 'quantity' => 1 ) ), 'idempotency_key' => 'w6u-' . $prefix ), array( 'skip_rate_limit' => true ) );
	$confirmed = is_wp_error( $new ) ? $new : Orders::confirm_manual_payment( $new['order_id'] );
	$handled = array();
	$h = static function ( $done, $type, $payload, $job_id ) use ( &$handled ) { if ( 'w6_old_job' === $type ) { $handled[] = $job_id; return true; } return $done; };
	add_filter( 'wp_pass_captain_process_job_v1', $h, 10, 4 );
	PassCaptain\Jobs::process_due( 20 );
	remove_filter( 'wp_pass_captain_process_job_v1', $h, 10 );
	$paid_old = Orders::get( $biz['paid'] );
	$wpdb->prefix = $w6u_real_prefix;
	PassCaptain\Installer::flush_version_cache();
	if ( is_wp_error( $ci ) || 5 !== $avail || true !== $confirmed || 1 !== count( $handled ) ) {
		echo '         ' . substr( json_encode( array( 'ci' => is_wp_error( $ci ) ? $ci->get_error_code() : $ci['status'], 'avail' => $avail, 'confirmed' => is_wp_error( $confirmed ) ? $confirmed->get_error_message() : $confirmed, 'handled' => $handled, 'new' => is_wp_error( $new ) ? $new->get_error_message() : 'ok' ) ), 0, 500 ) . "\n";
	}
	check( "$label data after the upgrade: old ticket checks in, old holds count, new orders and old jobs work", ! is_wp_error( $ci ) && 'checked_in' === $ci['status'] && 10 - 2 - 3 === $avail && true === $confirmed && 1 === count( $handled ) && 'paid' === $paid_old->payment_status && null === $paid_old->billing );
	w6u_drop( $prefix );
}
$wpdb->prefix = $w6u_real_prefix;
