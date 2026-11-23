<?php
/**
 * Z3 (audit panel 2026-11-15, residuals): atomic rate limits without a persistent object cache in
 * the table wpcap_rate_limits (QA-V2, PERF-16), static module manifest (PERF-14), batched presence on
 * the check-in screen, memoised masking in the order list and index-friendly order search (PERF-15).
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Admission\Engine as Z3Engine;
use PassCaptain\Admin\Queries as Z3Queries;
use PassCaptain\Installer as Z3Installer;
use PassCaptain\Modules as Z3Modules;
use PassCaptain\Orders as Z3Orders;
use PassCaptain\Privacy\Retention as Z3Retention;
use PassCaptain\RateLimit as Z3RateLimit;
use PassCaptain\Security\DataPolicy as Z3DataPolicy;

global $wpdb;
$p = $wpdb->prefix;
// The merged test bootstrap pins the transient backend (WPCAP_TEST_RATE_BACKEND); this file tests the
// default without an object cache: the database table.
$z3_saved_backend = $GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';
$z3_table = Z3RateLimit::table();
$z3_rows = static function ( $key ) use ( $wpdb, $z3_table ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE bucket_key=%s', $z3_table, Z3RateLimit::hash( $key ) ) );
};

/* ------------------------------------------------------------------------------------------ */
echo "Z3 QA-V2/PERF-16: rate-limit counters in wpcap_rate_limits\n";
wpcap_test_reset_transients();
$z3_idx = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $z3_table ) );
$z3_idx_names = array_unique( array_map( static fn( $r ) => $r->Key_name . ':' . $r->Column_name, $z3_idx ) );
check( 'schema: wpcap_rate_limits with primary key bucket_key and index on expires_at (DB 3.16.0)', in_array( 'PRIMARY:bucket_key', $z3_idx_names, true ) && in_array( 'expires_at:expires_at', $z3_idx_names, true ) && version_compare( Z3Installer::DB_VERSION, '3.16.0', '>=' ) );
$z3_a = Z3RateLimit::hit( 'z3_a', 2, 600 );
$z3_b = Z3RateLimit::hit( 'z3_a', 2, 600 );
$z3_c = Z3RateLimit::hit( 'z3_a', 2, 600 );
$z3_row = $z3_rows( 'z3_a' );
check( 'without an object cache the counter lives in one table row per bucket (no transient / options row)', $z3_a && $z3_b && ! $z3_c && $z3_row && 3 === (int) $z3_row->hits && array() === $GLOBALS['wpcap_test_transients'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $z3_table ) ) );
check( 'bucket key is the keyed hash of the counter name (64 hex, no client data)', 64 === strlen( $z3_row->bucket_key ) && Z3RateLimit::hash( 'z3_a' ) === $z3_row->bucket_key && hash( 'sha256', 'z3_a' ) !== $z3_row->bucket_key );
$z3_exp_before = $z3_row->expires_at;
for ( $i = 0; $i < 5; $i++ ) { Z3RateLimit::hit( 'z3_a', 2, 600 ); }
$z3_row = $z3_rows( 'z3_a' );
check( 'refused hits stop at limit + 1 and do not extend the window', 3 === (int) $z3_row->hits && $z3_exp_before === $z3_row->expires_at && 3 === Z3RateLimit::count( 'z3_a' ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET window_start=%s, expires_at=%s WHERE bucket_key=%s', $z3_table, gmdate( 'Y-m-d H:i:s', time() - 700 ), gmdate( 'Y-m-d H:i:s', time() - 100 ), Z3RateLimit::hash( 'z3_a' ) ) );
check( 'an expired counter reads as 0', 0 === Z3RateLimit::count( 'z3_a' ) );
$z3_again = Z3RateLimit::hit( 'z3_a', 2, 600 );
$z3_row = $z3_rows( 'z3_a' );
check( 'an expired counter restarts at 1 with a new window', $z3_again && 1 === (int) $z3_row->hits && strtotime( $z3_row->expires_at . ' UTC' ) > time() + 500 && strtotime( $z3_row->window_start . ' UTC' ) >= time() - 5 );
check( 'allow() (fixed windows) also counts in the table', Z3RateLimit::allow( 'z3_allow|x', 1, 60 ) && ! Z3RateLimit::allow( 'z3_allow|x', 1, 60 ) && array() === $GLOBALS['wpcap_test_transients'] );

// Before the schema upgrade (table missing) the transient fallback keeps limiting.
$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $z3_table, $z3_table . '_z3off' ) );
$z3_fb = array( Z3RateLimit::hit( 'z3_fb', 1, 60 ), Z3RateLimit::hit( 'z3_fb', 1, 60 ) );
$z3_fb_count = Z3RateLimit::count( 'z3_fb' );
$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $z3_table . '_z3off', $z3_table ) );
check( 'without the table (before the upgrade) the transient fallback still limits', true === $z3_fb[0] && false === $z3_fb[1] && 1 === ( $GLOBALS['wpcap_test_transients']['z3_fb'] ?? 0 ) && 1 === $z3_fb_count );

// The SQL avoids VALUES() in ON DUPLICATE KEY UPDATE (deprecated in MySQL 8.0.20+) and the row alias (MariaDB).
$z3_src = file_get_contents( WPCAP_PATH . 'src/RateLimit.php' );
check( 'upsert is portable: no VALUES() and no row alias in ON DUPLICATE KEY UPDATE', false !== strpos( $z3_src, 'ON DUPLICATE KEY UPDATE' ) && ! preg_match( '/VALUES\s*\(\s*(hits|window_start|expires_at)\s*\)/i', $z3_src ) && ! preg_match( '/\)\s+AS\s+new\b/i', $z3_src ) );

/* ------------------------------------------------------------------------------------------ */
echo "Z3 QA-V2: parallel requests (own connections) never exceed the limit\n";
wpcap_test_reset_transients();
$z3_spawn = static function ( array $commands ) {
	$procs = array();
	foreach ( $commands as $cmd ) { $procs[] = popen( $cmd, 'r' ); }
	$out = array();
	foreach ( $procs as $proc ) { $out[] = trim( (string) stream_get_contents( $proc ) ); pclose( $proc ); }
	return $out;
};
$z3_worker = 'WPCAP_TEST_RATE_BACKEND=db ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/workers/z3_worker.php' );
$z3_start = microtime( true ) + 1.5;
$z3_cmds = array();
for ( $i = 0; $i < 12; $i++ ) { $z3_cmds[] = $z3_worker . ' hit ' . $z3_start . ' z3_parallel 5 3'; }
$z3_out = $z3_spawn( $z3_cmds );
$z3_allowed = array_sum( array_map( 'intval', $z3_out ) );
// The only row after the reset (workers hash with their own salt; earlier tests may change it in this process).
$z3_prow = $wpdb->get_row( $wpdb->prepare( 'SELECT hits FROM %i', $z3_table ) );
$z3_prow_n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $z3_table ) );
check( "12 processes x 3 hits on one bucket (limit 5): exactly 5 pass (got $z3_allowed), counter capped at 6 [" . implode( ",", $z3_out ) . " / hits " . ( $z3_prow ? $z3_prow->hits : "none" ) . "]", 5 === $z3_allowed && 12 === count( array_filter( $z3_out, 'is_numeric' ) ) && 1 === $z3_prow_n && $z3_prow && 6 === (int) $z3_prow->hits );

$z3_type = make_type( 50, 10, 1000 );
$z3_start = microtime( true ) + 1.5;
$z3_cmds = array();
for ( $i = 0; $i < 10; $i++ ) { $z3_cmds[] = $z3_worker . ' checkout ' . $z3_start . ' ' . $z3_type . ' 3'; }
$z3_out = $z3_spawn( $z3_cmds );
$z3_ok = count( array_filter( $z3_out, static fn( $r ) => 'OK' === $r ) );
$z3_limited = count( array_filter( $z3_out, static fn( $r ) => 'wpcap_rate_limited' === $r ) );
$z3_orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE customer_email LIKE 'z3-par-%%'", $p . 'wpcap_orders' ) );
check( "10 parallel checkouts from one IP (limit 3): exactly 3 orders (got $z3_ok OK, $z3_limited limited, $z3_orders orders)", 3 === $z3_ok && $z3_ok === $z3_orders && 10 === $z3_ok + $z3_limited );
wpcap_test_reset_transients();

/* ------------------------------------------------------------------------------------------ */
echo "Z3: every caller uses the shared RateLimit API (no read-then-write transient counters)\n";
$z3_callers = array( 'src/ServiceBookings.php', 'src/Waitlists.php', /* src/Seats.php removed in the merged line (A529) */ 'src/ServiceWaitlist.php', 'src/Services/Api.php', 'src/DeveloperKeys.php', 'src/Integrations/AffiliateCaptain/Adapter.php', 'src/Orders.php', 'src/Modules/Sms.php', 'src/Rest/Controller.php', 'src/Partners/Keys.php' );
$z3_bad = array();
foreach ( $z3_callers as $file ) {
	$src = (string) file_get_contents( WPCAP_PATH . $file );
	if ( preg_match( '/\(int\)\s*get_transient\(/', $src ) ) { $z3_bad[] = $file; }
	if ( false === strpos( $src, 'RateLimit::' ) ) { $z3_bad[] = $file . ' (no RateLimit)'; }
}
check( 'no (int) get_transient() counters left in the rate-limited entry points' . ( $z3_bad ? ': ' . implode( ', ', $z3_bad ) : '' ), array() === $z3_bad );
// Behaviour: the service pass endpoint counts in the table.
// WP_REST_Request: test double from tests/integration/payments.php.
$_SERVER['REMOTE_ADDR'] = '198.51.100.88';
$z3_req = new WP_REST_Request( 'POST', '/x' );
$z3_req->set_param( 'token', str_repeat( 'a', 64 ) );
for ( $i = 0; $i < 61; $i++ ) { $z3_last = PassCaptain\Services\Api::redeem_service_pass( $z3_req ); }
check( 'service pass redemption: 61st attempt per client is refused (429) and counted in the table', is_wp_error( $z3_last ) && 'wpcap_pass_rate' === $z3_last->get_error_code() && 61 === Z3RateLimit::count( 'wpcap_svcpass_ip_' . Z3RateLimit::client() ) && array() === array_filter( array_keys( $GLOBALS['wpcap_test_transients'] ), static fn( $k ) => 0 === strpos( $k, 'wpcap_svcpass' ) ) );
unset( $_SERVER['REMOTE_ADDR'] );
wpcap_test_reset_transients();

/* ------------------------------------------------------------------------------------------ */
echo "Z3 PERF-16: expired counters are purged by the retention run (batched)\n";
wpcap_test_reset_transients();
for ( $i = 0; $i < 7; $i++ ) {
	$wpdb->insert( $z3_table, array( 'bucket_key' => hash( 'sha256', 'z3-old-' . $i ), 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), 'hits' => 3, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
}
Z3RateLimit::hit( 'z3_live', 5, 3600 );
$z3_purged = Z3RateLimit::purge_expired( null, null, 3 );
check( 'purge_expired() deletes expired rows in LIMIT batches until done, live rows stay', 7 === $z3_purged && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $z3_table ) ) && $z3_rows( 'z3_live' ) );
$wpdb->insert( $z3_table, array( 'bucket_key' => hash( 'sha256', 'z3-old-r' ), 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), 'hits' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
$z3_counts = Z3Retention::run();
check( 'the daily retention run purges expired rate-limit rows (step rate_limits)', 1 === ( $z3_counts['rate_limits'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $z3_table ) ) );
$z3_rt_src = file_get_contents( WPCAP_PATH . 'src/RateLimit.php' );
check( 'purge uses the expires_at index with LIMIT', (bool) preg_match( "/DELETE FROM %i WHERE expires_at<=%s LIMIT %d/", $z3_rt_src ) );
wpcap_test_reset_transients();

/* ------------------------------------------------------------------------------------------ */
echo "Z3 PERF-14: static module manifest, lazy module loading\n";
$z3_files = array_map( static fn( $f ) => basename( $f, '.php' ), glob( WPCAP_PATH . 'src/Modules/*.php' ) );
sort( $z3_files );
$z3_manifest = array_keys( Z3Modules::MANIFEST );
sort( $z3_manifest );
check( 'contract: every file in src/Modules/ is listed in Modules::MANIFEST (and nothing else)', $z3_files === $z3_manifest );
$z3_methods = array( 'register', 'schema', 'settings_defaults', 'settings_schema', 'admin_pages', 'admin_handlers' );
$z3_mismatch = array();
foreach ( Z3Modules::MANIFEST as $name => $listed ) {
	$class = 'PassCaptain\\Modules\\' . $name;
	foreach ( $z3_methods as $method ) {
		if ( method_exists( $class, $method ) !== in_array( $method, $listed, true ) ) { $z3_mismatch[] = "$name::$method"; }
	}
}
check( 'contract: MANIFEST lists exactly the module methods each class implements' . ( $z3_mismatch ? ': ' . implode( ', ', $z3_mismatch ) : '' ), array() === $z3_mismatch );
$z3_mod_src = file_get_contents( WPCAP_PATH . 'src/Modules.php' );
// Merged line: 24 bundled modules (both development lines), all from the static manifest.
check( 'Modules::classes() needs no glob() directory scan', false === strpos( $z3_mod_src, 'glob( ' ) && count( Z3Modules::MANIFEST ) === count( Z3Modules::bundled() ) && 25 === count( Z3Modules::bundled() ) );
$z3_boot = json_decode( (string) shell_exec( $z3_worker . ' boot' ), true );
$z3_boot_files = (array) ( $z3_boot['files'] ?? array() );
$z3_boot_modules = array_filter( $z3_boot_files, static fn( $f ) => 0 === strpos( $f, 'src/Modules/' ) );
echo '  (frontend boot loads ' . count( $z3_boot_files ) . ' plugin files, ' . count( $z3_boot_modules ) . " module files)\n";
check( 'frontend request: admin-only module (Reports) and admin screens are not loaded', $z3_boot_files && ! in_array( 'src/Modules/Reports.php', $z3_boot_files, true ) && ! in_array( 'src/Admin/FeatureScreens.php', $z3_boot_files, true ) && ! in_array( 'src/Admin/SecurityScreens.php', $z3_boot_files, true ) && count( Z3Modules::bundled() ) - 1 === count( $z3_boot_modules ) );
check( 'frontend request: admin-post handlers are only registered in admin requests', isset( $z3_boot['admin_post'] ) && false === $z3_boot['admin_post'] );
check( 'admin pages and handlers still include admin-only modules (Reports)', in_array( PassCaptain\Reports\Page::SLUG, array_map( static fn( $pg ) => (string) $pg[0], Z3Modules::admin_pages() ), true ) && isset( Z3Modules::admin_handlers()['wpcap_reports_csv'] ) );

/* ------------------------------------------------------------------------------------------ */
echo "Z3 PERF-15: check-in presence in one query\n";
$z3_ev = $wpdb->get_var( "SELECT MAX(event_id) FROM {$p}wpcap_events" ) + 1000;
$z3_occs = array( 990001, 990002, 990003 );
$z3_tid = 9900000;
foreach ( array( array( 990001, 1, 2 ), array( 990001, 1, 2 ), array( 990001, 1, 5 ), array( 990002, 1, 2 ), array( 990002, 0, 2 ) ) as $row ) {
	$wpdb->insert( "{$p}wpcap_admission_state", array( 'ticket_id' => ++$z3_tid, 'occurrence_id' => $row[0], 'event_id' => $z3_ev, 'inside' => $row[1], 'area_id' => $row[2], 'entries' => 1, 'last_checkin_id' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
}
$z3_q = $wpdb->num_queries;
$z3_many = Z3Engine::presence_many( $z3_occs );
$z3_q = $wpdb->num_queries - $z3_q;
check( 'presence_many(): one query for all occurrences, same result as presence() each', 1 === $z3_q && $z3_many[990001] === Z3Engine::presence( 990001 ) && $z3_many[990002] === Z3Engine::presence( 990002 ) && array( 'present' => 3, 'by_area' => array( 2 => 2, 5 => 1 ) ) === $z3_many[990001] && 1 === $z3_many[990002]['present'] && array( 'present' => 0, 'by_area' => array() ) === $z3_many[990003] );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id=%d', "{$p}wpcap_admission_state", $z3_ev ) );
$z3_ck_src = file_get_contents( WPCAP_PATH . 'src/Admin/CheckinsPage.php' );
check( 'check-in screen uses presence_many() instead of presence() per occurrence', false !== strpos( $z3_ck_src, 'Engine::presence_many(' ) && false === strpos( $z3_ck_src, 'Engine::presence( ' ) );

echo "Z3 PERF-15: masking in the order list decided once per page\n";
$GLOBALS['wpcap_test_filters']['wpcap_data_profile'] = 'support';
$GLOBALS['wpcap_test_user_id'] = 7301;
wpcap_test_reset_transients();
Z3DataPolicy::flush_cache();
$z3_m1 = Z3Orders::create( array( 'email' => 'z3-mask1@example.com', 'items' => array( array( 'ticket_type_id' => $z3_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z3-mask-1' ), array( 'skip_rate_limit' => true ) );
$z3_m2 = Z3Orders::create( array( 'email' => 'z3-mask2@example.com', 'items' => array( array( 'ticket_type_id' => $z3_type, 'quantity' => 1 ) ), 'idempotency_key' => 'z3-mask-2' ), array( 'skip_rate_limit' => true ) );
$z3_o1 = (int) $z3_m1['order_id'];
$z3_o2 = (int) $z3_m2['order_id'];
$z3_before = Z3DataPolicy::masks_pii_many( array( $z3_o1, $z3_o2 ) );
$z3_unmask = Z3DataPolicy::unmask( $z3_o1, 'Supportfall #Z3' );
$z3_after = Z3DataPolicy::masks_pii_many( array( $z3_o1, $z3_o2 ) );
$z3_list = $GLOBALS['wpcap_test_transients']['wpcap_unmask_7301'] ?? null;
check( 'masks_pii_many(): all masked for support, unmask applies per order', array( $z3_o1 => true, $z3_o2 => true ) === $z3_before && is_array( $z3_unmask ) && array( $z3_o1 => false, $z3_o2 => true ) === $z3_after && ! Z3DataPolicy::masks_pii( $z3_o1 ) && Z3DataPolicy::masks_pii( $z3_o2 ) );
check( 'unmasks of a user are one transient (one lookup per request), with expiry per order', is_array( $z3_list ) && array( $z3_o1 ) === array_keys( $z3_list ) && $z3_list[ $z3_o1 ] > time() && 1 === count( array_filter( array_keys( $GLOBALS['wpcap_test_transients'] ), static fn( $k ) => 0 === strpos( $k, 'wpcap_unmask_' ) ) ) );
$GLOBALS['wpcap_test_transients']['wpcap_unmask_7301'] = array( $z3_o1 => time() - 1 );
Z3DataPolicy::flush_cache();
check( 'an expired unmask masks again', Z3DataPolicy::masks_pii( $z3_o1 ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_data_profile'], $GLOBALS['wpcap_test_user_id'] );
Z3DataPolicy::flush_cache();
check( 'other profiles see nothing masked', array( $z3_o1 => false, $z3_o2 => false ) === Z3DataPolicy::masks_pii_many( array( $z3_o1, $z3_o2 ) ) );
$z3_op_src = file_get_contents( WPCAP_PATH . 'src/Admin/OrdersPage.php' );
check( 'order list calls masks_pii_many() once instead of masks_pii() per row', false !== strpos( $z3_op_src, 'DataPolicy::masks_pii_many(' ) && 1 === substr_count( $z3_op_src, 'DataPolicy::masks_pii(' ) );

echo "Z3 PERF-15: order search by e-mail uses the index\n";
$wpdb->wpcap_log = array();
$z3_exact = Z3Queries::search_orders( array( 'search' => 'z3-mask1@example.com' ), null );
$z3_sql_exact = implode( "\n", $wpdb->wpcap_log );
$wpdb->wpcap_log = array();
$z3_prefix = Z3Queries::search_orders( array( 'search' => 'z3-mask' ), null );
$z3_sql_prefix = implode( "\n", $wpdb->wpcap_log );
$wpdb->wpcap_log = array();
$z3_domain = Z3Queries::search_orders( array( 'search' => '@example.com' ), null, 1, 500 );
$z3_sql_domain = implode( "\n", $wpdb->wpcap_log );
$wpdb->wpcap_log = null;
$z3_middle = Z3Queries::search_orders( array( 'search' => 'mask1' ), null );
check( 'complete e-mail: exact match (customer_email = …), no LIKE', 1 === $z3_exact['total'] && false !== strpos( $z3_sql_exact, "ord.customer_email='z3-mask1@example.com'" ) && false === stripos( $z3_sql_exact, 'customer_email LIKE' ) );
// Merged line: the buyer e-mail is searched by prefix; names, company and attendee data (A336) keep their own matching.
check( 'partial input: prefix search (x%) instead of %x%', 2 === $z3_prefix['total'] && false !== strpos( $z3_sql_prefix, "customer_email LIKE 'z3-mask%'" ) && false === strpos( $z3_sql_prefix, "customer_email LIKE '%z3-mask" ) );
check( 'leading @ searches by domain (documented full scan)', $z3_domain['total'] >= 2 && false !== strpos( $z3_sql_domain, "customer_email LIKE '%@example.com'" ) );
check( 'a fragment from the middle of the address is no longer matched (documented)', 0 === $z3_middle['total'] );
$z3_ex = $wpdb->get_row( $wpdb->prepare( "EXPLAIN SELECT order_id FROM %i ord WHERE ord.customer_email=%s", $p . 'wpcap_orders', 'z3-mask1@example.com' ) );
check( 'EXPLAIN: exact e-mail search uses the customer_email index', $z3_ex && false !== strpos( (string) ( $z3_ex->possible_keys ?? '' ), 'customer_email' ) );
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = $z3_saved_backend;
