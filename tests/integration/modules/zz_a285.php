<?php
/**
 * A285: with a replica-aware database drop-in (HyperDB, LudicrousDB) transactions and locking reads
 * run on the primary. DbReplication hooks the `query` filter and calls send_reads_to_masters()
 * (LudicrousDB: send_reads_to_primaries()) as soon as a transaction, SELECT … FOR UPDATE / FOR SHARE /
 * LOCK IN SHARE MODE or GET_LOCK() starts; plain reads before that stay on the replicas.
 * Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\DbReplication;

echo "A285: transactions and locking reads on the primary with HyperDB/LudicrousDB\n";
$a285_real = $GLOBALS['wpdb'];
$a285_hyper = new class() { public $calls = 0; public function send_reads_to_masters() { $this->calls++; } };
$a285_ludi = new class() { public $calls = 0; public function send_reads_to_masters() {} public function send_reads_to_primaries() { $this->calls++; } };

check( 'A285 plain wpdb: no filter, nothing to route', ! DbReplication::supported( $a285_real ) );
// The query filter also carries other listeners (Reports\Cache::note_query, A201): restored afterwards.
$a285_query_hooks = $GLOBALS['wpcap_test_hooks']['query'] ?? null;
try {
	$GLOBALS['wpdb'] = $a285_hyper;
	DbReplication::reset();
	unset( $GLOBALS['wpcap_test_hooks']['query'] );
	DbReplication::register();
	check( 'A285 HyperDB: the query filter is registered (before other filters)', ! empty( $GLOBALS['wpcap_test_hooks']['query'][0] ) );
	DbReplication::route( "SELECT * FROM wptest_wpcap_events WHERE status='published'" );
	check( 'A285 plain reads stay on the replicas', 0 === $a285_hyper->calls );
	check( 'A285 the statement passes through unchanged', 'START TRANSACTION' === DbReplication::route( 'START TRANSACTION' ) );
	check( 'A285 START TRANSACTION sends further reads to the primary', 1 === $a285_hyper->calls );
	DbReplication::route( 'SELECT * FROM t WHERE id=1 FOR UPDATE' );
	check( 'A285 switched once per request', 1 === $a285_hyper->calls );
	foreach ( array( "SELECT order_id FROM t WHERE id=3\n FOR UPDATE", 'SELECT * FROM t FOR SHARE', 'select * from t lock in share mode', "SELECT GET_LOCK('wpcap_x', 5)", '  BEGIN', 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' ) as $a285_sql ) {
		DbReplication::reset();
		$a285_hyper->calls = 0;
		DbReplication::route( $a285_sql );
		check( 'A285 routed to the primary: ' . preg_replace( '/\s+/', ' ', trim( $a285_sql ) ), 1 === $a285_hyper->calls );
	}
	$GLOBALS['wpdb'] = $a285_ludi;
	DbReplication::reset();
	DbReplication::route( 'START TRANSACTION' );
	check( 'A285 LudicrousDB: send_reads_to_primaries() is used', 1 === $a285_ludi->calls );
} finally {
	$GLOBALS['wpdb'] = $a285_real;
	DbReplication::reset();
	unset( $GLOBALS['wpcap_test_hooks']['query'] );
	if ( null !== $a285_query_hooks ) { $GLOBALS['wpcap_test_hooks']['query'] = $a285_query_hooks; }
}

// Every transaction start and locking read the plugin issues matches the pattern.
$a285_missed = array();
$a285_count = 0;
$a285_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $a285_it as $a285_file ) {
	if ( 'php' !== $a285_file->getExtension() ) { continue; }
	$a285_src = (string) file_get_contents( $a285_file->getPathname() );
	preg_match_all( '/([\'"])((?:START TRANSACTION|SELECT\b(?:(?!\1).)*?(?:FOR UPDATE|GET_LOCK)(?:(?!\1).)*))\1/s', $a285_src, $a285_m );
	foreach ( $a285_m[2] as $a285_sql ) {
		$a285_count++;
		if ( ! preg_match( DbReplication::PATTERN, $a285_sql ) ) { $a285_missed[] = basename( $a285_file->getPathname() ) . ': ' . substr( $a285_sql, 0, 60 ); }
	}
}
check( "A285 all $a285_count transaction starts / locking reads in src/ are recognised" . ( $a285_missed ? ' – missed ' . implode( ' | ', array_slice( $a285_missed, 0, 5 ) ) : '' ), $a285_count > 100 && ! $a285_missed );
check( 'A285 the plugin registers the routing on startup', false !== strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' ), 'DbReplication::register();' ) );
