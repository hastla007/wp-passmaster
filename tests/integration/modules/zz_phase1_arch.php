<?php
/** Phase 1 architecture: upgrade lock, per-request caches, budgeted queues, indexes, multisite drop. */
// phpcs:ignoreFile
use PassCaptain\Installer;
use PassCaptain\Jobs\TimeBudget;

// Minimal WP-Cron/multisite stand-ins (only if no earlier test defined them).
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ?? false; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] = (int) $ts; return true; }
}
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }
$arch_unschedule = static function ( $hook, $args = array() ) { unset( $GLOBALS['wpcap_test_cron'][ $hook ][ md5( serialize( $args ) ) ] ); };

/** Second database connection, to hold locks like a concurrent request would. */
$arch_db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$arch_lock = static fn() => '1' === (string) $arch_db->query( "SELECT GET_LOCK('" . $arch_db->real_escape_string( Installer::lock_name() ) . "',0)" )->fetch_row()[0];
$arch_unlock = static fn() => $arch_db->query( "SELECT RELEASE_LOCK('" . $arch_db->real_escape_string( Installer::lock_name() ) . "')" );

echo "Phase 1 architecture: schema upgrade lock\n";
$ran = 0;
check( 'setup: a concurrent request holds the upgrade lock', $arch_lock() );
check( 'upgrade callback is skipped while another request holds the lock', false === Installer::run_locked( static function () use ( &$ran ) { $ran++; } ) && 0 === $ran );
$saved_version = get_option( 'wpcap_db_version' );
update_option( 'wpcap_db_version', 'outdated' );
check( 'admin/cron upgrade does not run dbDelta without the lock', false === Installer::maybe_upgrade( 'cron' ) && 'outdated' === get_option( 'wpcap_db_version' ) );
$arch_unlock();
check( 'callback runs once the lock is free', true === Installer::run_locked( static function () use ( &$ran ) { $ran++; } ) && 1 === $ran );
check( 'lock is released afterwards (another request can take it)', $arch_lock() );
$arch_unlock();
update_option( 'wpcap_db_version', $saved_version );

echo "Phase 1 architecture: frontend requests defer upgrades\n";
$saved_fp = get_option( 'wpcap_schema_fingerprint' );
check( 'install stores the schema fingerprint', Installer::fingerprint() === $saved_fp );
check( 'frontend with current fingerprint does nothing', false === Installer::maybe_upgrade( 'frontend' ) && false === wp_next_scheduled( Installer::UPGRADE_HOOK ) );
update_option( 'wpcap_schema_fingerprint', 'stale' );
update_option( 'wpcap_db_version', 'outdated' );
check( 'frontend with a pending upgrade only schedules the cron event', false === Installer::maybe_upgrade( 'frontend' ) && false !== wp_next_scheduled( Installer::UPGRADE_HOOK ) && 'outdated' === get_option( 'wpcap_db_version' ) );
$arch_lock();
$GLOBALS['wpcap_test_cron'][ Installer::UPGRADE_HOOK ][ md5( serialize( array() ) ) ] = time() - 3600;
check( 'overdue event: frontend tries the upgrade, but only behind the lock', false === Installer::maybe_upgrade( 'frontend' ) && 'outdated' === get_option( 'wpcap_db_version' ) );
$arch_unlock();
$arch_unschedule( Installer::UPGRADE_HOOK );
update_option( 'wpcap_db_version', $saved_version );
check( 'schema current but fingerprint missing: fingerprint is refreshed without dbDelta', false === Installer::maybe_upgrade( 'admin' ) && Installer::fingerprint() === get_option( 'wpcap_schema_fingerprint' ) );

echo "Phase 1 architecture: per-request caches\n";
$arch_calls = 0;
$arch_count = static function ( $classes ) use ( &$arch_calls ) { $arch_calls++; return $classes; };
add_filter( 'wpcap_modules', $arch_count );
Installer::flush_version_cache();
$v1 = Installer::version();
$v2 = Installer::version();
check( 'schema version is computed once per request', $v1 === $v2 && 1 === $arch_calls && $v1 === $saved_version );
$arch_calls = 0;
PassCaptain\Settings::flush();
PassCaptain\Settings::get( 'hold_minutes' );
PassCaptain\Settings::get( 'invoice_prefix' );
PassCaptain\Settings::all();
check( 'settings defaults are built once per request', 1 === $arch_calls );
remove_filter( 'wpcap_modules', $arch_count );
$arch_hold = PassCaptain\Settings::get( 'hold_minutes' );
PassCaptain\Settings::update( array( 'hold_minutes' => 17 ) );
check( 'Settings::update invalidates the cache', 17 === (int) PassCaptain\Settings::get( 'hold_minutes' ) );
$raw = get_option( 'wpcap_settings' );
$raw['hold_minutes'] = 19;
update_option( 'wpcap_settings', $raw );
check( 'a direct option change (e.g. another site) is picked up', 19 === (int) PassCaptain\Settings::get( 'hold_minutes' ) );
PassCaptain\Settings::update( array( 'hold_minutes' => $arch_hold ) );

echo "Phase 1 architecture: budgeted message queue\n";
$mq = "{$p}wpcap_message_queue";
$wpdb->query( "DELETE FROM $mq WHERE recipient LIKE 'arch-%'" );
for ( $i = 0; $i < 30; $i++ ) {
	PassCaptain\Communications::enqueue( 'arch-' . $i, "arch-$i@example.com", 'arch', array( 'subject' => 'S', 'body' => 'B' ), '2000-01-01 00:00:00' );
}
$arch_queued = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mq WHERE recipient LIKE 'arch-%' AND status='queued'" );
$GLOBALS['wpcap_test_filters']['wpcap_message_batch_size'] = 10;
$arch_unschedule( 'wpcap_process_messages', array( 'continue' ) );
$n = PassCaptain\Communications::process_due();
check( 'one run sends up to the batch size', 10 === $n && 20 === $arch_queued() );
check( 'remaining backlog schedules an immediate follow-up run', false !== wp_next_scheduled( 'wpcap_process_messages', array( 'continue' ) ) && wp_next_scheduled( 'wpcap_process_messages', array( 'continue' ) ) <= time() );
$arch_unschedule( 'wpcap_process_messages', array( 'continue' ) );
unset( $GLOBALS['wpcap_test_filters']['wpcap_message_batch_size'] );
check( 'default batch is far larger than the old 25 per tick', PassCaptain\Communications::BATCH_SIZE >= 500 );
$GLOBALS['wpcap_test_filters']['wpcap_cron_time_budget'] = 1;
TimeBudget::arm();
usleep( 1100000 );
$n = PassCaptain\Communications::process_due();
check( 'exhausted time budget: nothing is claimed, nothing left in sending', 0 === $n && 20 === $arch_queued() && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mq WHERE recipient LIKE 'arch-%' AND status='sending'" ) );
check( 'exhausted budget with backlog schedules a follow-up run', false !== wp_next_scheduled( 'wpcap_process_messages', array( 'continue' ) ) );
$jt = "{$p}wpcap_jobs";
PassCaptain\Jobs::enqueue( 'arch-job-1', 'arch_job', array() );
check( 'jobs: exhausted shared budget stops before claiming', 0 === PassCaptain\Jobs::process_due() && 'queued' === $wpdb->get_var( "SELECT status FROM $jt WHERE job_type='arch_job'" ) );
TimeBudget::disarm();
unset( $GLOBALS['wpcap_test_filters']['wpcap_cron_time_budget'] );
$arch_unschedule( 'wpcap_process_messages', array( 'continue' ) );
$arch_unschedule( 'wpcap_process_jobs', array( 'continue' ) );
$n = PassCaptain\Communications::process_due();
check( 'fresh budget drains the rest in one run', $n >= 20 && 0 === $arch_queued() && false === wp_next_scheduled( 'wpcap_process_messages', array( 'continue' ) ) );
$wpdb->query( "DELETE FROM $jt WHERE job_type='arch_job'" );

$ini = ini_get( 'max_execution_time' );
ini_set( 'max_execution_time', '30' );
$capped = TimeBudget::seconds();
ini_set( 'max_execution_time', (string) $ini );
check( 'budget leaves room for one more 15 s HTTP call under max_execution_time', 15.0 === $capped );

echo "Phase 1 architecture: claims of a crashed worker are released\n";
PassCaptain\Communications::enqueue( 'arch-claim', 'arch-claim@example.com', 'arch', array( 'subject' => 'S', 'body' => 'B' ) );
$cid = (int) $wpdb->get_var( "SELECT message_id FROM $mq WHERE recipient='arch-claim@example.com'" );
$wpdb->query( "UPDATE $mq SET status='sending',locked_at=UTC_TIMESTAMP() WHERE message_id=$cid" );
TimeBudget::claimed( $mq, 'message_id', $cid, 'sending', 'queued' );
TimeBudget::release_claims();
check( 'in-flight claim goes back to queued on shutdown', 'queued' === $wpdb->get_var( "SELECT status FROM $mq WHERE message_id=$cid" ) && 0 === TimeBudget::open_claims() );
$wpdb->query( "DELETE FROM $mq WHERE recipient LIKE 'arch-%'" );

echo "Phase 1 architecture: indexes\n";
$arch_index = static fn( $table, $key ) => array_map( static fn( $r ) => $r->Column_name, $wpdb->get_results( "SHOW INDEX FROM {$p}wpcap_$table WHERE Key_name='$key'" ) );
check( 'tickets.attendee_email index', array( 'attendee_email' ) === $arch_index( 'tickets', 'attendee_email' ) );
check( 'orders (created_at, order_id) index', array( 'created_at', 'order_id' ) === $arch_index( 'orders', 'created' ) );
check( 'covering capacity index on order items', array( 'ticket_type_id', 'order_id', 'quantity', 'refunded_quantity' ) === $arch_index( 'order_items', 'capacity_sum' ) );
check( 'reservations (ticket_type_id, status, expires_at) index', array( 'ticket_type_id', 'status', 'expires_at' ) === $arch_index( 'reservations', 'inventory' ) );

echo "Phase 1 architecture: multisite site deletion drops the site's tables\n";
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}2_wpcap_probe (id int) ENGINE=InnoDB" );
$dropped = PassCaptain\Plugin::drop_site_tables( array( "{$p}options" ), 0 );
check( 'wpmu_drop_tables gets all wpcap tables of the site', in_array( "{$p}options", $dropped, true ) && in_array( "{$p}wpcap_orders", $dropped, true ) && in_array( "{$p}wpcap_message_queue", $dropped, true ) );
check( "another site's tables are never added", ! in_array( "{$p}2_wpcap_probe", $dropped, true ) && ! array_filter( $dropped, static fn( $t ) => ! str_starts_with( $t, "{$p}wpcap_" ) && "{$p}options" !== $t ) );
$wpdb->query( "DROP TABLE IF EXISTS {$p}2_wpcap_probe" );
$arch_db->close();
