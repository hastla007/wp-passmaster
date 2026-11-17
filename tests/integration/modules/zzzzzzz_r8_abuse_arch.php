<?php
/**
 * R8 (audit round 2, experts #7, #1, #11): checkout rate limit behind a proxy and per IPv6 /64, sold-out
 * attempts not counted, unpaid holds per network, e-mail aliases, takeover in the same request, downgrade
 * detection, uninstall on large networks.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Installer as R8Installer;
use PassCaptain\LegacyCopy as R8Legacy;
use PassCaptain\Ops\Status as R8Status;
use PassCaptain\Orders as R8Orders;
use PassCaptain\Plugin as R8Plugin;
use PassCaptain\RateLimit as R8RateLimit;
use PassCaptain\Sales\BuyerLimits as R8Buyer;
use PassCaptain\Sales\CheckoutThrottle as R8Throttle;

global $wpdb;
$p = $wpdb->prefix;
$r8_server = $_SERVER;
$r8_buy = static function ( $type, $email, $qty = 1 ) {
	return R8Orders::create( array( 'email' => $email, 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'r8-' . uniqid( '', true ) ) );
};
$r8_code = static function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'OK'; };
$r8_set_client = static function ( $remote, array $headers = array() ) use ( $r8_server ) {
	$_SERVER = $r8_server;
	foreach ( array_keys( R8RateLimit::PROXY_HEADERS ) as $k ) { unset( $_SERVER[ R8RateLimit::PROXY_HEADERS[ $k ] ] ); }
	if ( null === $remote ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $remote; }
	foreach ( $headers as $k => $v ) { $_SERVER[ $k ] = $v; }
};

/* ------------------------------------------------------------------------------------------ */
// The counters below (limit + 1 cap, refund()) are those of the production backend without an object cache; the test
// bootstrap of the merged suite defaults to the transient fallback.
$r8_backend = $GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] ?? null;
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = 'db';
echo "R8 (#7 HOCH): proxy/CDN without trusted proxies is detected\n";
wpcap_test_reset_transients();
delete_option( R8Throttle::SEEN_OPTION );
check( 'private REMOTE_ADDR (load balancer) without trusted proxies: "private"', 'private' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '10.0.3.7' ) ) && 'private' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '127.0.0.1' ) ) && 'private' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '::1' ) ) && 'private' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => 'fd00::5' ) ) );
check( 'public REMOTE_ADDR with X-Forwarded-For / CF-Connecting-IP: "header"', 'header' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5' ) ) && 'header' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' ) ) );
check( 'public REMOTE_ADDR without forwarding header: no warning', '' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '198.51.100.7' ) ) );
add_filter( 'wpcap_trusted_proxies', $r8_tp = static fn( $list ) => array( '10.0.0.0/8' ), 10, 1 );
check( 'with configured trusted proxies nothing is suspected', '' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '10.0.3.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5' ) ) && null === R8Throttle::status_issue() );
remove_filter( 'wpcap_trusted_proxies', $r8_tp, 10 );
add_filter( 'wpcap_client_ip', $r8_cip = static fn( $ip, $server ) => '203.0.113.99', 10, 2 );
check( 'a client address resolved by wpcap_client_ip is not suspected', '' === R8Throttle::proxy_suspect( array( 'REMOTE_ADDR' => '10.0.3.7' ) ) );
remove_filter( 'wpcap_client_ip', $r8_cip, 10 );

echo "R8: behind a private proxy the per-address limit is suspended, network + e-mail still applies\n";
$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = 2;
$r8_type = make_type( 500, 10, 1000 );
$r8_set_client( '10.0.3.7' );
$r8_res = array();
for ( $i = 0; $i < 4; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_type, "r8-lb$i@example.com" ) ); }
check( '4 buyers behind the load balancer (limit 2 per address) all get their order', array( 'OK', 'OK', 'OK', 'OK' ) === $r8_res );
$r8_res = array();
for ( $i = 0; $i < 9; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_type, 'r8-same@example.com' ) ); }
check( 'same e-mail behind the load balancer: the 9th order is refused (8 per network + e-mail)', 8 === count( array_keys( $r8_res, 'OK', true ) ) && 'wpcap_rate_limited' === $r8_res[8] );
$r8_seen = get_option( R8Throttle::SEEN_OPTION );
$r8_issues = R8Status::issues();
$r8_issue = array_values( array_filter( $r8_issues, static fn( $i ) => false !== strpos( $i['message'], 'vertrauenswürdigen Proxys' ) ) );
check( 'system status warns about the unconfigured proxy (remembered from the checkout)', is_array( $r8_seen ) && 'private' === $r8_seen['kind'] && 1 === count( $r8_issue ) && 'warning' === $r8_issue[0]['severity'] );

echo "R8: forwarding header from a public address raises the per-address limit (x10)\n";
wpcap_test_reset_transients();
$r8_set_client( '198.51.100.140', array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.1' ) );
$r8_res = array();
for ( $i = 0; $i < 4; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_type, "r8-cdn$i@example.com" ) ); }
check( '4 buyers through an unconfigured CDN edge (limit 2) are not refused', array( 'OK', 'OK', 'OK', 'OK' ) === $r8_res );
$r8_set_client( '198.51.100.141' );
$r8_res = array();
for ( $i = 0; $i < 3; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_type, "r8-direct$i@example.com" ) ); }
check( 'a direct client without forwarding header keeps the normal limit (3rd refused)', array( 'OK', 'OK', 'wpcap_rate_limited' ) === $r8_res );

echo "R8: sold-out attempts do not use up the checkout limit\n";
wpcap_test_reset_transients();
$r8_small = make_type( 1, 10, 1000 );
$r8_set_client( '198.51.100.150' );
$r8_first = $r8_code( $r8_buy( $r8_small, 'r8-so0@example.com' ) );
$r8_sold = array();
for ( $i = 1; $i <= 5; $i++ ) { $r8_sold[] = $r8_code( $r8_buy( $r8_small, "r8-so$i@example.com" ) ); }
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET capacity=10 WHERE ticket_type_id=%d', $p . 'wpcap_ticket_types', $r8_small ) );
$r8_after = $r8_code( $r8_buy( $r8_small, 'r8-so9@example.com' ) );
$r8_third = $r8_code( $r8_buy( $r8_small, 'r8-so10@example.com' ) );
check( 'after 5 sold-out attempts the 2nd real order still passes (limit 2), the 3rd is refused', 'OK' === $r8_first && array( 'wpcap_checkout_failed' ) === array_values( array_unique( $r8_sold ) ) && 'OK' === $r8_after && 'wpcap_rate_limited' === $r8_third );
// 2 orders + the refused 3rd hit (a refused hit is not taken back; the table caps the counter at limit + 1).
check( 'the hold counter only holds the created orders (not the 5 sold-out attempts), the attempt counter all 8 attempts', 3 === R8RateLimit::count( 'wpcap_checkout_ip_' . R8Orders::rate_hash( '198.51.100.150' ) ) && 8 === R8RateLimit::count( 'wpcap_checkout_try_ip_' . R8Orders::rate_hash( '198.51.100.150' ) ) );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_attempt_factor'] = 1;
wpcap_test_reset_transients();
$r8_sold = array();
for ( $i = 0; $i < 4; $i++ ) { $r8_sold[] = $r8_code( $r8_buy( $r8_small, "r8-ham$i@example.com", 50 ) ); }
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_attempt_factor'] );
check( 'hammering a sold-out type is still bounded by the atomic attempt limit', array( 'wpcap_checkout_failed', 'wpcap_checkout_failed', 'wpcap_rate_limited', 'wpcap_rate_limited' ) === $r8_sold );
check( 'RateLimit::refund() takes back one allowed hit and never goes below 0', R8RateLimit::hit( 'r8_rf', 1, 60 ) && ! R8RateLimit::hit( 'r8_rf', 1, 60 ) && ( R8RateLimit::refund( 'r8_rf' ) || true ) && ( R8RateLimit::refund( 'r8_rf' ) || true ) && ( R8RateLimit::refund( 'r8_rf' ) || true ) && 0 === R8RateLimit::count( 'r8_rf' ) && R8RateLimit::hit( 'r8_rf', 1, 60 ) );

/* ------------------------------------------------------------------------------------------ */
echo "R8 (#7 MITTEL): checkout limit per IPv6 /64\n";
wpcap_test_reset_transients();
$r8_res = array();
foreach ( array( '2001:db8:77:1::a', '2001:db8:77:1:ffff::b', '2001:db8:77:1:1234:5678:9abc:def0' ) as $i => $ip ) {
	$r8_set_client( $ip );
	$r8_res[] = $r8_code( $r8_buy( $r8_type, "r8-v6-$i@example.com" ) );
}
$r8_set_client( '2001:db8:77:2::a' );
$r8_other = $r8_code( $r8_buy( $r8_type, 'r8-v6-other@example.com' ) );
check( 'rotating addresses inside one /64 share the limit (3rd refused), another /64 is independent', array( 'OK', 'OK', 'wpcap_rate_limited' ) === $r8_res && 'OK' === $r8_other );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = PHP_INT_MAX; // "Switched off" (tests/load does this): no integer overflow in the derived limits.
$r8_set_client( '198.51.100.145', array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.1' ) );
check( 'wpcap_checkout_ip_limit = PHP_INT_MAX (also x10 and x4 for attempts) does not overflow into a refusal', 'OK' === $r8_code( $r8_buy( $r8_type, 'r8-max@example.com' ) ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] );

echo "R8: unpaid holds per network\n";
wpcap_test_reset_transients();
remove_filter( 'wpcap_checkout_unpaid_per_network', 'wpcap_test_unpaid_limit' );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_unpaid_per_network'] = 3;
$r8_free = make_type( 50, 10, 0 );
$r8_set_client( '198.51.100.160' );
$r8_res = array();
for ( $i = 0; $i < 3; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_free, "r8-free$i@example.com" ) ); }
for ( $i = 0; $i < 4; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_type, "r8-unpaid$i@example.com" ) ); }
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_unpaid_per_network'] );
add_filter( 'wpcap_checkout_unpaid_per_network', 'wpcap_test_unpaid_limit' );
check( 'free orders do not use the slots; the 4th unpaid order of the network is refused (limit 3)', array( 'OK', 'OK', 'OK', 'OK', 'OK', 'OK', 'wpcap_rate_limited' ) === $r8_res );
check( 'the unpaid window is the hold period, at most one hour', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Sales/CheckoutThrottle.php' ), 'min( 60, (int) $hold_minutes )' ) );

/* ------------------------------------------------------------------------------------------ */
echo "R8 (#7 MITTEL): e-mail aliases\n";
check( 'normalize_email folds +tag and Gmail dots/domain', 'ab@gmail.com' === R8Buyer::normalize_email( ' A.B+promo@GoogleMail.com ' ) && 'a.b@example.com' === R8Buyer::normalize_email( 'A.B+x@Example.com' ) && 'ab@gmail.com' === R8Buyer::normalize_email( 'a.b@gmail.com' ) && '+x@example.com' === R8Buyer::normalize_email( '+x@example.com' ) && 'nomail' === R8Buyer::normalize_email( 'nomail' ) );
$GLOBALS['wpcap_test_filters']['wpcap_buyer_email_aliases'] = false;
check( 'filter wpcap_buyer_email_aliases=false keeps lowercase only', 'a.b+promo@googlemail.com' === R8Buyer::normalize_email( 'A.B+promo@GoogleMail.com' ) && 'LOWER(TRIM(x))' === R8Buyer::email_sql( 'x' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_buyer_email_aliases'] );
$r8_sql_ok = true;
foreach ( array( 'A.B+promo@GoogleMail.com', 'a.b@example.com', 'x+y+z@example.org', '+x@example.com', 'nomail', 'a.b+c@gmail.com' ) as $r8_mail ) {
	$wpdb->query( $wpdb->prepare( 'SET @r8_mail = %s', $r8_mail ) );
	$r8_sql = $wpdb->get_var( 'SELECT ' . R8Buyer::email_sql( '@r8_mail' ) );
	if ( $r8_sql !== R8Buyer::normalize_email( $r8_mail ) ) { $r8_sql_ok = false; echo "    SQL $r8_mail -> $r8_sql\n"; }
}
check( 'the SQL expression folds stored addresses exactly like normalize_email()', $r8_sql_ok );
// Buyer limit: 2 tickets per person for the event; aliases of one Gmail address count together.
wpcap_test_reset_transients();
$r8_set_client( '198.51.100.170' );
$r8_lim_type = make_type( 50, 10, 1000 );
$r8_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $r8_lim_type ) );
$wpdb->insert( $p . 'wpcap_buyer_limits', array( 'event_id' => $r8_event, 'occurrence_id' => 0, 'ticket_type_id' => 0, 'max_quantity' => 2, 'match_by' => 'email', 'status' => 'active', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$r8_a = $r8_buy( $r8_lim_type, 'Max.Muster+1@gmail.com', 2 );
$r8_b = $r8_buy( $r8_lim_type, 'maxmuster@googlemail.com', 1 );
$r8_c = $r8_buy( $r8_lim_type, 'max.muster@example.com', 1 );
check( 'buyer limit: an alias of the same Gmail address cannot buy beyond the limit; another address can', ! is_wp_error( $r8_a ) && is_wp_error( $r8_b ) && false !== strpos( $r8_b->get_error_message(), 'höchstens 2' ) && ! is_wp_error( $r8_c ) );
$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='deleted' WHERE event_id=%d", $p . 'wpcap_buyer_limits', $r8_event ) );
wpcap_test_reset_transients();
$r8_set_client( '198.51.100.171' );
$GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] = 100;
$r8_res = array();
for ( $i = 0; $i < 9; $i++ ) { $r8_res[] = $r8_code( $r8_buy( $r8_type, "r8.alias+$i@example.com" ) ); }
unset( $GLOBALS['wpcap_test_filters']['wpcap_checkout_ip_limit'] );
check( 'network + e-mail limit: 9 "+tag" aliases of one address count as one buyer (9th refused)', 8 === count( array_keys( $r8_res, 'OK', true ) ) && 'wpcap_rate_limited' === $r8_res[8] );
$_SERVER = $r8_server;
wpcap_test_reset_transients();

/* ------------------------------------------------------------------------------------------ */
$GLOBALS['wpcap_test_filters']['wpcap_rate_limit_backend'] = $r8_backend;
echo "R8 (#1 NIEDRIG): takeover – the old copy loading after this one in the same request\n";
$r8_rp = new ReflectionProperty( R8Plugin::class, 'started' );
$r8_rp->setAccessible( true );
$r8_started = $r8_rp->getValue();
$r8_rp->setValue( null, true );
$r8_hooks_before = serialize( array_keys( $GLOBALS['wpcap_test_hooks'] ) ) . count( $GLOBALS['wpcap_test_hooks'], COUNT_RECURSIVE );
R8Plugin::instance()->run();
$r8_hooks_after = serialize( array_keys( $GLOBALS['wpcap_test_hooks'] ) ) . count( $GLOBALS['wpcap_test_hooks'], COUNT_RECURSIVE );
$r8_rp->setValue( null, $r8_started );
check( 'Plugin::run() is idempotent: a second start registers no hooks', $r8_hooks_before === $r8_hooks_after );
$r8_tp_prop = new ReflectionProperty( PassCaptain\TicketPage::class, 'registered' );
$r8_tp_prop->setAccessible( true );
$r8_tp_old = $r8_tp_prop->getValue();
$r8_tp_prop->setValue( null, true );
$r8_hooks_before = count( $GLOBALS['wpcap_test_hooks'], COUNT_RECURSIVE );
PassCaptain\TicketPage::register();
$r8_tp_prop->setValue( null, $r8_tp_old );
check( 'TicketPage::register() is idempotent too (the old bootstrap calls it before run())', count( $GLOBALS['wpcap_test_hooks'], COUNT_RECURSIVE ) === $r8_hooks_before );

// The 1.4 bootstrap as it ran in wp-pass-captain/wp-pass-captain.php: the four constants and a plugins_loaded start.
$r8_dir = sys_get_temp_dir() . '/wpcap-r8-' . getmypid() . '/wp-pass-captain';
if ( ! is_dir( $r8_dir ) ) { mkdir( $r8_dir, 0700, true ); }
$r8_legacy = $r8_dir . '/wp-pass-captain.php';
file_put_contents( $r8_legacy, "<?php\ndefine( 'WPCAP_VERSION', '1.4.0' );\ndefine( 'WPCAP_FILE', __FILE__ );\ndefine( 'WPCAP_PATH', __DIR__ . '/' );\ndefine( 'WPCAP_URL', 'https://example.test/old/' );\n\$GLOBALS['r8_legacy_loaded'] = true;\n" );
$r8_warnings = array();
set_error_handler( static function ( $no, $str ) use ( &$r8_warnings ) { $r8_warnings[] = $str; return true; } );
R8Legacy::$swallowed = 0;
$r8_defined = count( array_filter( R8Legacy::CONSTANTS, 'defined' ) ); // The test bootstrap defines WPCAP_VERSION, WPCAP_PATH, WPCAP_FILE.
R8Legacy::neutralize( array( 'wp-pass-captain/wp-pass-captain.php' ) );
$r8_active = R8Legacy::active();
include $r8_legacy;
do_action( 'plugin_loaded', $r8_legacy );
$r8_after_restore = R8Legacy::active();
define( 'WPCAP_VERSION', 'again' ); // After the old file: reaches the previous handler again.
restore_error_handler();
check( 'the old bootstrap in the same request: its "Constant already defined" warnings are swallowed', $r8_active && ! empty( $GLOBALS['r8_legacy_loaded'] ) && $r8_defined >= 3 && $r8_defined === R8Legacy::$swallowed && WPCAP_PATH !== $r8_dir . '/' );
check( 'the warning filter ends with the old file (plugin_loaded); later warnings are not hidden', ! $r8_after_restore && 1 === count( $r8_warnings ) && false !== strpos( $r8_warnings[0], 'WPCAP_VERSION' ) );
R8Legacy::neutralize( array() );
check( 'nothing to neutralize without a taken-over copy', ! R8Legacy::active() );
unlink( $r8_legacy ); rmdir( $r8_dir ); rmdir( dirname( $r8_dir ) );
$r8_main = (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' );
check( 'pass-captain.php neutralizes the old copy only when it took over and loaded first', false !== strpos( $r8_main, 'PassCaptain\\LegacyCopy::neutralize( $wpcap_takeover )' ) && strpos( $r8_main, "if ( defined( 'WPCAP_VERSION' ) )" ) < strpos( $r8_main, 'LegacyCopy::neutralize' ) );

/* ------------------------------------------------------------------------------------------ */
echo "R8 (#1 NIEDRIG): downgrade – the database comes from a newer version\n";
$r8_saved = get_option( 'wpcap_db_version' );
R8Installer::flush_version_cache();
update_option( 'wpcap_db_version', '99.1.0+abc' );
delete_option( R8Installer::NEWER_OPTION );
unset( $GLOBALS['wpcap_test_hooks']['admin_notices'] );
$r8_ran = R8Installer::maybe_upgrade( 'admin' );
$r8_notice = false;
foreach ( $GLOBALS['wpcap_test_hooks']['admin_notices'] ?? array() as $r8_cbs ) { foreach ( $r8_cbs as $r8_cb ) { if ( array( R8Installer::class, 'render_newer_notice' ) === $r8_cb[0] ) { $r8_notice = true; } } }
check( 'maybe_upgrade() does not install over a newer schema and keeps its version', false === $r8_ran && '99.1.0+abc' === get_option( 'wpcap_db_version' ) );
check( 'option wpcap_schema_newer holds the newer version, admin notice "Restore erforderlich" registered', '99.1.0' === get_option( R8Installer::NEWER_OPTION ) && $r8_notice );
check( 'install() (e.g. reactivation of the old copy) refuses too', false === R8Installer::install() && '99.1.0+abc' === get_option( 'wpcap_db_version' ) );
$r8_issue = array_values( array_filter( R8Status::issues(), static fn( $i ) => false !== strpos( $i['message'], 'Restore erforderlich' ) ) );
check( 'system status reports the downgrade as error', 1 === count( $r8_issue ) && 'error' === $r8_issue[0]['severity'] );
update_option( 'wpcap_db_version', '3.1.0+abc' );
check( 'an older stored schema is not "newer" (normal upgrade path) and clears the option', false === R8Installer::schema_is_newer() && false === get_option( R8Installer::NEWER_OPTION ) );
check( 'non-numeric markers ("outdated") never count as newer', false === R8Installer::schema_is_newer( 'outdated' ) && false === R8Installer::schema_is_newer( false ) );
update_option( 'wpcap_db_version', $r8_saved );
R8Installer::flush_version_cache();

/* ------------------------------------------------------------------------------------------ */
echo "R8 (#11 NIEDRIG): uninstall on large networks\n";
$r8_un = (string) file_get_contents( WPCAP_PATH . 'uninstall.php' );
check( 'uninstall: sites without plugin data count as opted in (a repeated run after an abort finishes the job)', ( false !== strpos( $r8_un, 'return ! wpcap_uninstall_site_has_data();' ) || (bool) preg_match( '/if \( ! wpcap_uninstall_site_has_data\(\) \) \{\s*return true;/', $r8_un ) ) && false !== strpos( $r8_un, 'function wpcap_uninstall_site_has_data()' ) );
check( 'uninstall: the network loop keeps running without the browser and asks for more time', false !== strpos( $r8_un, 'ignore_user_abort( true )' ) && false !== strpos( $r8_un, 'set_time_limit( 0 )' ) );

/* ------------------------------------------------------------------------------------------ */
echo "R8 (#1 NIEDRIG): Orders split – portal access link in Orders\\PortalAccess, Orders stays the facade\n";
$r8_sig = static function ( $class, $method ) {
	return array_map( static fn( $p ) => $p->getName() . ( $p->isOptional() ? '=' . var_export( $p->getDefaultValue(), true ) : '' ), ( new ReflectionMethod( $class, $method ) )->getParameters() );
};
// Merged: rotate_access_link() gained the optional $keep_current of the pending link (A631); existing calls are unchanged.
check( 'facade signatures unchanged (rotate_access_link, send_order_access_link)', 'order_id' === ( $r8_sig( R8Orders::class, 'rotate_access_link' )[0] ?? '' ) && ! array_filter( array_slice( $r8_sig( R8Orders::class, 'rotate_access_link' ), 1 ), static fn( $a ) => false === strpos( $a, '=' ) ) && array( 'email', 'public_id', 'access_token', 'locale=NULL' ) === $r8_sig( R8Orders::class, 'send_order_access_link' ) && ( new ReflectionMethod( R8Orders::class, 'rotate_access_link' ) )->isStatic() );
wpcap_test_reset_transients();
$r8_po = $r8_buy( $r8_type, 'r8-portal@example.com' );
$r8_old_hash = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT access_token_hash FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $r8_po['order_id'] ) );
$r8_mails = count( $GLOBALS['wpcap_test_mails'] );
R8Orders::rotate_access_link( $r8_po['order_id'] );
$r8_row = $wpdb->get_row( $wpdb->prepare( 'SELECT access_token_hash,access_expires_at FROM %i WHERE order_id=%d', $p . 'wpcap_orders', $r8_po['order_id'] ) );
$r8_mail = array_slice( $GLOBALS['wpcap_test_mails'], $r8_mails );
$r8_token = '';
foreach ( $r8_mail as $m ) { if ( preg_match( '/wpcap_access=([a-f0-9]{64})/', (string) $m['body'], $mm ) ) { $r8_token = $mm[1]; } }
check( 'rotating the access link through the facade: new token hash, 30 days, the mailed link carries the new token', $r8_row && $r8_old_hash !== $r8_row->access_token_hash && '' !== $r8_token && hash( 'sha256', $r8_token ) === $r8_row->access_token_hash && abs( strtotime( $r8_row->access_expires_at . ' UTC' ) - strtotime( PassCaptain\Orders::access_until( $r8_po['order_id'] ) . ' UTC' ) ) < 120 && strtotime( $r8_row->access_expires_at . ' UTC' ) >= time() + 30 * DAY_IN_SECONDS - 120 ); // A424: at least 30 days, and 30 days past the last date.
// Merged: one implementation – Orders (with the A631 pending link); Orders\PortalAccess delegates to it.
$r8_pa = (string) file_get_contents( WPCAP_PATH . 'src/Orders/PortalAccess.php' );
check( 'Orders and Orders\\PortalAccess share one implementation (PortalAccess delegates)', false !== strpos( $r8_pa, 'return Orders::rotate_access_link( $order_id );' ) && false !== strpos( $r8_pa, 'return Orders::send_order_access_link( $email, $public_id, $access_token, $locale );' ) );
