<?php
/**
 * Static check (A182): table prefixes and WooCommerce tables in the SQL of src/.
 *
 * Usage: php tests/sql-prefix-check.php [--self-test] [paths...]   (default: src/, templates/, uninstall.php, pass-captain.php)
 *
 * Every PHP string of src/ is reassembled with the tokenizer (interpolations and concatenated
 * expressions become a "dynamic" marker). In SQL strings
 * - a table position (after FROM, JOIN, INTO, UPDATE, TABLE, DESCRIBE) must hold a dynamic name
 *   ({$wpdb->prefix}…, $wpdb->posts, a $table variable, a placeholder) or a subquery – a literal
 *   table name has no site prefix and would read another site's (or no) table;
 * - `wp_` never appears (hard-coded WordPress table prefix);
 * - WooCommerce order tables are never queried directly (wc_orders, wc_order_*,
 *   woocommerce_order_items/itemmeta, posts of type shop_order*): orders go through the WooCommerce
 *   CRUD API, which works with HPOS and posts storage alike. Exceptions only via ALLOW below.
 * Additionally, any string that is just such a WooCommerce table name (e.g. for
 * `$wpdb->prefix . 'wc_orders'`) and any 'shop_order…' literal inside a `$wpdb->…()` call is flagged.
 *
 * Exit code 1 on findings; --self-test checks the rules against built-in samples first.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

/**
 * Allowed findings: 'path relative to the plugin root' => array( 'substring of the SQL' => 'reason' ).
 * Keep this empty unless a direct query is unavoidable; give the reason.
 */
const WPCAP_SQL_ALLOW = array();

const WPCAP_SQL_DYNAMIC = "\x01";

/** WooCommerce order tables (without prefix) and post types the plugin must not query directly. */
const WPCAP_WC_TABLE_RE = '/\b(?:wc_orders(?:_meta)?|wc_order_[a-z_]+|woocommerce_order_items|woocommerce_order_itemmeta)\b/';
const WPCAP_WC_POSTTYPE_RE = '/\bshop_order(?:_refund|_placehold)?\b/';

/**
 * String units of a PHP source: [ 'text' => string with WPCAP_SQL_DYNAMIC markers, 'line' => int, 'in_wpdb' => bool ].
 */
function wpcap_sql_units( $source ) {
	$tokens = token_get_all( $source );
	$n = count( $tokens );
	$units = array();
	$stack = array(); // Paren stack: true = arguments of a $wpdb->method( … ) call.
	$sig = static function ( $i, $dir ) use ( $tokens, $n ) {
		for ( $j = $i + $dir; $j >= 0 && $j < $n; $j += $dir ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
			return $j;
		}
		return -1;
	};
	$text_of = static fn( $t ) => is_array( $t ) ? $t[1] : $t;
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		$in_wpdb = in_array( true, $stack, true );
		if ( '(' === $t ) {
			// $wpdb -> method (
			$m = $sig( $i, -1 );
			$arrow = $m >= 0 ? $sig( $m, -1 ) : -1;
			$var = $arrow >= 0 ? $sig( $arrow, -1 ) : -1;
			$stack[] = $m >= 0 && is_array( $tokens[ $m ] ) && T_STRING === $tokens[ $m ][0]
				&& $arrow >= 0 && is_array( $tokens[ $arrow ] ) && T_OBJECT_OPERATOR === $tokens[ $arrow ][0]
				&& $var >= 0 && is_array( $tokens[ $var ] ) && T_VARIABLE === $tokens[ $var ][0] && '$wpdb' === $tokens[ $var ][1];
			continue;
		}
		if ( ')' === $t ) { array_pop( $stack ); continue; }
		$start = $i;
		$text = null;
		if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
			$raw = $t[1];
			$text = substr( $raw, 1, -1 );
			if ( '"' === $raw[0] ) {
				// A double-quoted string without interpolation: stays text.
				$text = stripcslashes( $text );
			}
		} elseif ( '"' === $t || ( is_array( $t ) && T_START_HEREDOC === $t[0] ) ) {
			$end = '"' === $t ? '"' : T_END_HEREDOC;
			$text = '';
			$depth = 0;
			for ( $i++; $i < $n; $i++ ) {
				$u = $tokens[ $i ];
				if ( 0 === $depth && ( ( '"' === $end && '"' === $u ) || ( is_array( $u ) && T_END_HEREDOC === $u[0] && T_END_HEREDOC === $end ) ) ) { break; }
				if ( 0 === $depth && is_array( $u ) && T_ENCAPSED_AND_WHITESPACE === $u[0] ) { $text .= $u[1]; continue; }
				// Interpolation: {$…}, ${…}, $var, $var->prop, $var[...].
				if ( is_array( $u ) && in_array( $u[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) { $depth++; }
				elseif ( '{' === $u ) { $depth++; }
				elseif ( '}' === $u && $depth > 0 ) { $depth--; }
				if ( '' === $text || WPCAP_SQL_DYNAMIC !== substr( $text, -1 ) ) { $text .= WPCAP_SQL_DYNAMIC; }
			}
		}
		if ( null === $text ) { continue; }
		// Concatenation: an expression before/after the string is dynamic too.
		$prev = $sig( $start, -1 );
		$next = $sig( $i, 1 );
		if ( $prev >= 0 && '.' === $text_of( $tokens[ $prev ] ) ) { $text = WPCAP_SQL_DYNAMIC . $text; }
		if ( $next >= 0 && '.' === $text_of( $tokens[ $next ] ) ) { $text .= WPCAP_SQL_DYNAMIC; }
		$units[] = array( 'text' => $text, 'line' => is_array( $t ) ? $t[2] : wpcap_line_of( $tokens, $start ), 'in_wpdb' => $in_wpdb );
	}
	return $units;
}

function wpcap_line_of( array $tokens, $i ) {
	for ( $j = $i; $j < count( $tokens ); $j++ ) {
		if ( is_array( $tokens[ $j ] ) ) { return $tokens[ $j ][2]; }
	}
	return 0;
}

/** Whether a string unit is (part of) an SQL statement. */
function wpcap_is_sql( $text ) {
	return 1 === preg_match( '/\b(?:SELECT\s|INSERT\s|UPDATE\s|DELETE\s|REPLACE\s+INTO|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s|SHOW\s+(?:FULL\s+)?(?:COLUMNS|INDEX|TABLES|CREATE)|DESCRIBE\s|FROM\s|JOIN\s|WHERE\s)/', $text );
}

/** Findings of one string unit: list of messages. */
function wpcap_sql_findings( array $unit ) {
	$text = $unit['text'];
	$out = array();
	$sql = wpcap_is_sql( $text );
	// Strings that are only a WooCommerce table name ($wpdb->prefix . 'wc_orders').
	$bare = trim( str_replace( WPCAP_SQL_DYNAMIC, '', $text ), " `\t" );
	if ( preg_match( '/^' . trim( WPCAP_WC_TABLE_RE, '/' ) . '$/', $bare ) ) {
		$out[] = "WooCommerce table name '$bare' (use the WooCommerce CRUD API)";
	}
	if ( $unit['in_wpdb'] && ! $sql && preg_match( '/^' . trim( WPCAP_WC_POSTTYPE_RE, '/' ) . '$/', $bare ) ) {
		$out[] = "post type '$bare' in a \$wpdb query (use wc_get_orders())";
	}
	if ( ! $sql ) { return $out; }
	if ( preg_match( '/\bwp_[a-z0-9_]*/i', $text, $m ) ) {
		$out[] = "hard-coded table prefix '{$m[0]}' (use \$wpdb->prefix or \$wpdb->tablename)";
	}
	if ( preg_match( WPCAP_WC_TABLE_RE, $text, $m ) ) {
		$out[] = "direct query on WooCommerce table '{$m[0]}' (use the WooCommerce CRUD API)";
	}
	if ( preg_match( WPCAP_WC_POSTTYPE_RE, $text, $m ) ) {
		$out[] = "direct query on WooCommerce orders ('{$m[0]}' posts; use wc_get_orders())";
	}
	// Table positions must be dynamic.
	if ( preg_match_all( '/(?<![A-Za-z0-9_])(FROM|JOIN|INTO|UPDATE|TABLE|DESCRIBE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?=(.{0,40}))/s', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $all as $match ) {
			$keyword = $match[1][0];
			$before = substr( $text, max( 0, $match[0][1] - 12 ), min( 12, $match[0][1] ) );
			$after = $match[2][0];
			if ( 'UPDATE' === $keyword && preg_match( '/(KEY|FOR)\s+$/', $before ) ) { continue; } // ON DUPLICATE KEY UPDATE, FOR UPDATE.
			if ( 'FROM' === $keyword && preg_match( '/\b(YEAR|MONTH|DAY|HOUR|MINUTE|SECOND|LEADING|TRAILING|BOTH)\s+$/', $before ) ) { continue; } // EXTRACT/TRIM.
			if ( '' === $after || WPCAP_SQL_DYNAMIC === $after[0] || '(' === $after[0] ) { continue; }
			if ( preg_match( '/^`' . WPCAP_SQL_DYNAMIC . '/', $after ) ) { continue; }
			if ( preg_match( '/^(?:%[0-9$]*[si]\b|DUAL\b|information_schema\.|INFORMATION_SCHEMA\.)/', $after ) ) { continue; }
			if ( preg_match( '/^`?([A-Za-z_][A-Za-z0-9_]*)/', $after, $id ) ) {
				$out[] = "literal table name '{$id[1]}' after $keyword (no \$wpdb prefix)";
			}
		}
	}
	return $out;
}

function wpcap_sql_check_source( $source, $path ) {
	$found = array();
	foreach ( wpcap_sql_units( $source ) as $unit ) {
		foreach ( wpcap_sql_findings( $unit ) as $msg ) {
			$plain = str_replace( WPCAP_SQL_DYNAMIC, '…', $unit['text'] );
			$allowed = false;
			foreach ( WPCAP_SQL_ALLOW[ $path ] ?? array() as $needle => $reason ) {
				if ( false !== strpos( $plain, $needle ) ) { $allowed = true; }
			}
			if ( ! $allowed ) { $found[] = sprintf( '%s:%d: %s: %s', $path, $unit['line'], $msg, substr( preg_replace( '/\s+/', ' ', $plain ), 0, 160 ) ); }
		}
	}
	return $found;
}

function wpcap_sql_self_test() {
	$bad = array(
		'hard-coded wp_ table'          => '<?php $wpdb->get_var( "SELECT COUNT(*) FROM wp_wpcap_orders" );',
		'wp_ in a concatenation'        => '<?php $wpdb->query( "DELETE FROM " . $t . " WHERE x IN (SELECT ID FROM wp_posts)" );',
		'literal table without prefix'  => '<?php $wpdb->get_results( "SELECT * FROM wpcap_events WHERE 1" );',
		'literal table in JOIN'         => '<?php $wpdb->get_results( "SELECT * FROM {$p}wpcap_events e JOIN wpcap_occurrences o ON 1" );',
		'HPOS table'                    => '<?php $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE status=%s" );',
		'HPOS table name literal'       => '<?php $t = $wpdb->prefix . \'wc_order_stats\';',
		'order items table'             => '<?php $wpdb->get_col( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items" );',
		'shop_order posts in SQL'       => '<?php $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type=\'shop_order\'" );',
		'shop_order as prepare arg'     => '<?php $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s", \'shop_order\' ) );',
		'heredoc literal table'         => "<?php \$sql = <<<SQL\nSELECT * FROM wpcap_tickets\nSQL;\n",
		'INSERT INTO literal'           => '<?php $wpdb->query( \'INSERT INTO wpcap_jobs (a) VALUES (1)\' );',
	);
	$good = array(
		'prefix interpolation'          => '<?php $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_orders o JOIN {$p}wpcap_tickets t ON t.order_id=o.order_id" );',
		'core table property'           => '<?php $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", \'x\' ) );',
		'concatenated table'            => '<?php $wpdb->query( \'DELETE FROM \' . $table . \' WHERE id=1\' );',
		'backticked variable'           => '<?php $wpdb->query( "ALTER TABLE `$table` DROP INDEX `$name`" );',
		'subquery and on duplicate'     => '<?php $wpdb->query( "INSERT INTO {$p}x (a) SELECT a FROM (SELECT 1 AS a) s ON DUPLICATE KEY UPDATE a=VALUES(a)" );',
		'for update, extract'           => '<?php $wpdb->get_row( "SELECT EXTRACT(YEAR FROM created_at) FROM {$p}x WHERE 1 FOR UPDATE" );',
		'create table if not exists'    => '<?php $q = "CREATE TABLE IF NOT EXISTS $table (id int)";',
		'information_schema'            => '<?php $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()" );',
		'UI text and hook names'        => '<?php add_action( \'wp_head\', \'f\' ); $s = __( \'Bestellung aus shop_order übernehmen\' ); $page = \'wc-orders\';',
		'screen id outside wpdb'        => '<?php if ( \'shop_order\' === $screen->id ) {}',
	);
	$ok = true;
	foreach ( $bad as $name => $code ) {
		$hit = (bool) wpcap_sql_check_source( $code, 'self-test.php' );
		echo ( $hit ? '  ok   flags ' : '  FAIL misses ' ) . "$name\n";
		$ok = $ok && $hit;
	}
	foreach ( $good as $name => $code ) {
		$found = wpcap_sql_check_source( $code, 'self-test.php' );
		echo ( $found ? '  FAIL flags ' : '  ok   accepts ' ) . "$name\n";
		if ( $found ) { echo '       ' . implode( "\n       ", $found ) . "\n"; }
		$ok = $ok && ! $found;
	}
	return $ok;
}

$root = dirname( __DIR__ );
$args = array_slice( $argv, 1 );
$failed = false;
if ( in_array( '--self-test', $args, true ) ) {
	echo "Self-test of the SQL prefix rules\n";
	$failed = ! wpcap_sql_self_test();
	$args = array_values( array_diff( $args, array( '--self-test' ) ) );
}
$paths = $args ? $args : array( $root . '/src', $root . '/templates', $root . '/uninstall.php', $root . '/pass-captain.php' );
$files = array();
foreach ( $paths as $path ) {
	if ( is_file( $path ) ) { $files[] = $path; continue; }
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( '.php' === substr( (string) $file, -4 ) ) { $files[] = (string) $file; }
	}
}
sort( $files );
$findings = array();
$sql_units = 0;
foreach ( $files as $file ) {
	$rel = ltrim( str_replace( $root, '', realpath( $file ) ?: $file ), '/' );
	$source = (string) file_get_contents( $file );
	$sql_units += count( array_filter( wpcap_sql_units( $source ), static fn( $u ) => wpcap_is_sql( $u['text'] ) ) );
	$findings = array_merge( $findings, wpcap_sql_check_source( $source, $rel ) );
}
echo 'SQL prefix check: ' . count( $files ) . " files, $sql_units SQL strings\n";
foreach ( $findings as $finding ) { echo "  FAIL $finding\n"; }
if ( ! $findings ) { echo "  ok   every table is prefixed via \$wpdb, no hard-coded wp_, no direct WooCommerce order table queries\n"; }
exit( $findings || $failed ? 1 : 0 );
