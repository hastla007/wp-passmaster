<?php
/**
 * A467: uninstall (with data removal) deletes the plugin options through the options API, so a
 * persistent object cache does not keep serving them (a cached `wpcap_db_version` made a later
 * reinstall skip creating its tables). Here the options API is the in-memory option store of the
 * test harness: raw SQL on the options table left it untouched. uninstall.php runs against its own
 * table prefix ({test prefix}u_), so the test tables are never affected.
 * Real WordPress: tests/wp-matrix/lifecycle.sh ("uninstall leaves no cached option").
 */

echo "A467: uninstall clears options through the options API (object cache)\n";

global $wpdb;
$a467_real = $wpdb;
$a467_prefix = $a467_real->prefix . 'u_';
$a467_real->query( "DROP TABLE IF EXISTS `{$a467_prefix}options`, `{$a467_prefix}usermeta`, `{$a467_prefix}wpcap_dummy`" );
$a467_real->query( "CREATE TABLE `{$a467_prefix}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL) ENGINE=InnoDB" );
$a467_real->query( "CREATE TABLE `{$a467_prefix}usermeta` (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NOT NULL, meta_key varchar(191) NOT NULL, meta_value longtext) ENGINE=InnoDB" );
$a467_real->query( "CREATE TABLE `{$a467_prefix}wpcap_dummy` (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB" );
$a467_rows = array( 'wpcap_db_version' => '3.10.14+x', 'wpcap_settings' => 'a:0:{}', 'wpcap_secrets' => 'a:0:{}', '_transient_wpcap_health_critical' => '1', '_transient_timeout_wpcap_health_critical' => '9999999999', '_transient_timeout_wpcap_orphan' => '1', 'blogname' => 'Site' );
foreach ( $a467_rows as $a467_name => $a467_value ) {
	$a467_real->query( $a467_real->prepare( "INSERT INTO `{$a467_prefix}options` (option_name, option_value) VALUES (%s, %s)", $a467_name, $a467_value ) );
}

// What the options API (and a persistent object cache) currently serves.
$a467_saved_options = $GLOBALS['wpcap_test_options'];
$a467_saved_transients = $GLOBALS['wpcap_test_transients'];
$GLOBALS['wpcap_test_options']['wpcap_db_version'] = '3.10.14+x';
$GLOBALS['wpcap_test_options']['wpcap_secrets'] = array( 'stripe_secret_key' => 'v1:x:y' );
$GLOBALS['wpcap_test_options']['wpcap_settings'] = array( 'delete_data_on_uninstall' => 1 );
$GLOBALS['wpcap_test_options']['blogname'] = 'Site';
$GLOBALS['wpcap_test_transients']['wpcap_health_critical'] = 1;
$GLOBALS['wpcap_test_transients']['wpcap_capacity_drift_mail'] = 1; // only in the object cache

if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $hook ) { $GLOBALS['a467_unscheduled'][] = $hook; return 0; } }
if ( ! function_exists( 'remove_role' ) ) { function remove_role( $role ) { $GLOBALS['a467_removed_roles'][] = $role; } }
if ( ! function_exists( 'wp_roles' ) ) { function wp_roles() { return (object) array( 'role_objects' => array() ); } }
if ( ! function_exists( 'get_users' ) ) { function get_users( $args = array() ) { return array(); } }
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['wpcap_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $key, $group = '' ) { $GLOBALS['a467_cache_deleted'][] = "$group:$key"; return true; } }
if ( ! function_exists( 'wp_cache_supports' ) ) { function wp_cache_supports( $feature ) { return 'flush_group' === $feature; } }
if ( ! function_exists( 'wp_cache_flush_group' ) ) { function wp_cache_flush_group( $group ) { $GLOBALS['a467_cache_flushed'][] = $group; return true; } }
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'pass-captain/pass-captain.php' ); }
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/a467-no-plugins' ); }
$GLOBALS['a467_cache_deleted'] = array();
$GLOBALS['a467_cache_flushed'] = array();

// $wpdb as WordPress has it for this site: own prefix, options/usermeta tables, esc_like().
$GLOBALS['wpdb'] = new class( $a467_real, $a467_prefix ) {
	public $prefix;
	public $options;
	public $usermeta;
	private $db;
	public function __construct( $db, $prefix ) { $this->db = $db; $this->prefix = $prefix; $this->options = $prefix . 'options'; $this->usermeta = $prefix . 'usermeta'; }
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function get_blog_prefix() { return $this->prefix; }
	public function __call( $method, $args ) { return $this->db->$method( ...$args ); }
};
if ( ! function_exists( 'esc_sql' ) ) { function esc_sql( $v ) { return addslashes( (string) $v ); } }
try {
	require dirname( __DIR__, 3 ) . '/uninstall.php';
} finally {
	$GLOBALS['wpdb'] = $a467_real;
	$wpdb = $a467_real;
}

check( 'uninstall dropped only the tables of its own prefix', ! $a467_real->get_var( "SHOW TABLES LIKE '{$a467_prefix}wpcap_dummy'" ) && (bool) $a467_real->get_var( "SHOW TABLES LIKE '" . $a467_real->prefix . "wpcap_orders'" ) );
check( 'options API no longer serves the plugin options (object cache cleared)', ! array_key_exists( 'wpcap_db_version', $GLOBALS['wpcap_test_options'] ) && ! array_key_exists( 'wpcap_secrets', $GLOBALS['wpcap_test_options'] ) && ! array_key_exists( 'wpcap_settings', $GLOBALS['wpcap_test_options'] ) );
check( 'transients are removed through the transient API, also those only held by the object cache', ! isset( $GLOBALS['wpcap_test_transients']['wpcap_health_critical'] ) && ! isset( $GLOBALS['wpcap_test_transients']['wpcap_capacity_drift_mail'] ) );
check( 'cached option lists and the plugin cache groups are cleared', in_array( 'options:alloptions', $GLOBALS['a467_cache_deleted'], true ) && in_array( 'options:notoptions', $GLOBALS['a467_cache_deleted'], true ) && array( 'wpcap', 'wpcap_rate' ) === $GLOBALS['a467_cache_flushed'] );
check( 'other options are kept', 'Site' === ( $GLOBALS['wpcap_test_options']['blogname'] ?? null ) );
check( 'no raw DELETE on the options table any more', false === strpos( (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' ), 'DELETE FROM {$wpdb->options}' ) );

$a467_real->query( "DROP TABLE IF EXISTS `{$a467_prefix}options`, `{$a467_prefix}usermeta`, `{$a467_prefix}wpcap_dummy`" );
$GLOBALS['wpcap_test_options'] = $a467_saved_options;
$GLOBALS['wpcap_test_transients'] = $a467_saved_transients;
