<?php
/**
 * Runs uninstall.php in its own PHP process against throw-away tables (prefix from X2U_PREFIX) with
 * a minimal single-site or multisite WordPress: roles/users with capabilities, per-site options,
 * shared usermeta, network sitemeta, Action Scheduler calls. Prints the resulting state as JSON.
 *
 * Config (env X2U_CONFIG, JSON): {"multisite":bool,"sites":{"<id>":{"data":bool,"delete":bool}},
 * "roles":{"<role>":[caps]},"user_caps":[caps],"sitemeta":[keys]}. Used by zz_a372.php, zz_a514.php.
 */
// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'pass-captain/pass-captain.php' );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/x2u-no-plugins' );

$cfg = json_decode( (string) getenv( 'X2U_CONFIG' ), true );
$base = (string) getenv( 'X2U_PREFIX' );
$db = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
$db->set_charset( 'utf8mb4' );

final class X2U_WPDB {
	public $prefix; public $base_prefix; public $options; public $usermeta; public $sitemeta; public $siteid = 1; public $blogid = 1;
	private $db;
	public function __construct( mysqli $db, $base ) { $this->db = $db; $this->base_prefix = $base; $this->usermeta = $base . 'usermeta'; $this->sitemeta = $base . 'sitemeta'; $this->set_blog( 1 ); }
	public function set_blog( $id ) { $this->blogid = (int) $id; $this->prefix = $this->get_blog_prefix( $id ); $this->options = $this->prefix . 'options'; }
	public function get_blog_prefix( $id = null ) { $id = null === $id ? $this->blogid : (int) $id; return 1 === $id ? $this->base_prefix : $this->base_prefix . $id . '_'; }
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ] ?? ''; return '%d' === $m[0] ? (string) (int) $v : "'" . $this->db->real_escape_string( (string) $v ) . "'"; }, $sql );
	}
	public function query( $sql ) { $r = $this->db->query( $sql ); return $r instanceof mysqli_result ? $r->num_rows : ( $r ? $this->db->affected_rows : false ); }
	public function get_col( $sql ) { $r = $this->db->query( $sql ); $o = array(); if ( $r instanceof mysqli_result ) { while ( $row = $r->fetch_row() ) { $o[] = $row[0]; } } return $o; }
	public function get_var( $sql ) { $c = $this->get_col( $sql ); return $c[0] ?? null; }
	public function get_results( $sql ) { $r = $this->db->query( $sql ); $o = array(); if ( $r instanceof mysqli_result ) { while ( $row = $r->fetch_object() ) { $o[] = $row; } } return $o; }
}
$wpdb = new X2U_WPDB( $db, $base );
$GLOBALS['wpdb'] = $wpdb;

// --- Fixture --------------------------------------------------------------------------------------
$sites = array_map( 'intval', array_keys( (array) ( $cfg['sites'] ?? array( 1 => array( 'data' => true, 'delete' => true ) ) ) ) );
$db->query( "DROP TABLE IF EXISTS `{$base}usermeta`, `{$base}sitemeta`" );
$db->query( "CREATE TABLE `{$base}usermeta` (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NOT NULL, meta_key varchar(191) NOT NULL, meta_value longtext) ENGINE=InnoDB" );
$db->query( "CREATE TABLE `{$base}sitemeta` (meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id bigint unsigned NOT NULL, meta_key varchar(191) NOT NULL, meta_value longtext) ENGINE=InnoDB" );
$db->query( "INSERT INTO `{$base}usermeta` (user_id, meta_key, meta_value) VALUES (7, 'wpcap_legacy_theme_notice_dismissed', '1'), (7, 'nickname', 'x')" );
foreach ( (array) ( $cfg['sitemeta'] ?? array() ) as $key ) {
	$db->query( "INSERT INTO `{$base}sitemeta` (site_id, meta_key, meta_value) VALUES (1, '" . $db->real_escape_string( $key ) . "', '1')" );
}
$db->query( "INSERT INTO `{$base}sitemeta` (site_id, meta_key, meta_value) VALUES (1, 'site_name', 'Net')" );
foreach ( $sites as $id ) {
	$p = $wpdb->get_blog_prefix( $id );
	$site = $cfg['sites'][ $id ] ?? array( 'data' => true, 'delete' => true );
	$db->query( "DROP TABLE IF EXISTS `{$p}options`, `{$p}wpcap_dummy`" );
	$db->query( "CREATE TABLE `{$p}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL) ENGINE=InnoDB" );
	$db->query( "INSERT INTO `{$p}options` (option_name, option_value) VALUES ('blogname', 'Site $id')" );
	if ( ! empty( $site['data'] ) ) {
		$db->query( "CREATE TABLE `{$p}wpcap_dummy` (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB" );
		$db->query( "INSERT INTO `{$p}options` (option_name, option_value) VALUES ('wpcap_db_version', '3.10.24'), ('wpcap_settings', '" . $db->real_escape_string( serialize( array( 'delete_data_on_uninstall' => ! empty( $site['delete'] ) ? 1 : 0 ) ) ) . "')" );
	}
	$db->query( "INSERT INTO `{$base}usermeta` (user_id, meta_key, meta_value) VALUES (7, '{$p}wpcap_scope', 'x')" );
}

final class X2U_Role {
	public $name; public $capabilities;
	public function __construct( $name, array $caps ) { $this->name = $name; $this->capabilities = array_fill_keys( $caps, true ); }
	public function remove_cap( $cap ) { unset( $this->capabilities[ $cap ] ); }
}
final class X2U_User {
	public $caps = array();
	public function __construct( array $caps ) { $this->caps = array_fill_keys( $caps, true ); }
	public function remove_cap( $cap ) { unset( $this->caps[ $cap ] ); }
}
$GLOBALS['x2u_roles'] = array();
$GLOBALS['x2u_users'] = array();
foreach ( $sites as $id ) {
	foreach ( (array) ( $cfg['roles'] ?? array() ) as $role => $caps ) { $GLOBALS['x2u_roles'][ $id ][ $role ] = new X2U_Role( $role, $caps ); }
	$GLOBALS['x2u_users'][ $id ] = array( new X2U_User( (array) ( $cfg['user_caps'] ?? array() ) ) );
}
$GLOBALS['x2u_as'] = array();
$GLOBALS['x2u_cron'] = array();

// --- WordPress API ----------------------------------------------------------------------------------
function is_multisite() { return ! empty( $GLOBALS['x2u_cfg']['multisite'] ); }
$GLOBALS['x2u_cfg'] = $cfg;
function get_sites( $args = array() ) { global $sites; return $sites; }
function get_current_blog_id() { return $GLOBALS['wpdb']->blogid; }
$GLOBALS['x2u_blog_stack'] = array();
function switch_to_blog( $id ) { $GLOBALS['x2u_blog_stack'][] = $GLOBALS['wpdb']->blogid; $GLOBALS['wpdb']->set_blog( $id ); return true; }
function restore_current_blog() { $GLOBALS['wpdb']->set_blog( array_pop( $GLOBALS['x2u_blog_stack'] ) ?: 1 ); return true; }
function get_option( $k, $d = false ) { global $wpdb; $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $k ) ); return null === $v ? $d : ( @unserialize( $v ) !== false || 'b:0;' === $v ? unserialize( $v ) : $v ); }
function delete_option( $k ) { global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s", $k ) ); return true; }
function delete_transient( $k ) { delete_option( '_transient_' . $k ); delete_option( '_transient_timeout_' . $k ); return true; }
function delete_site_option( $k ) { global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key=%s AND site_id=%d", $k, $wpdb->siteid ) ); return true; }
function get_current_network_id() { return 1; }
function wp_unschedule_hook( $hook ) { $GLOBALS['x2u_cron'][ get_current_blog_id() ][] = $hook; return 0; }
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) { $GLOBALS['x2u_as'][ get_current_blog_id() ][] = $group; }
function remove_role( $role ) { unset( $GLOBALS['x2u_roles'][ get_current_blog_id() ][ $role ] ); }
function wp_roles() { return (object) array( 'role_objects' => $GLOBALS['x2u_roles'][ get_current_blog_id() ] ?? array() ); }
function get_users( $args = array() ) {
	$want = (array) ( $args['capability__in'] ?? array() );
	return array_values( array_filter( $GLOBALS['x2u_users'][ get_current_blog_id() ] ?? array(), static fn( $u ) => (bool) array_intersect( array_keys( $u->caps ), $want ) ) );
}
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_cache_supports( $f ) { return false; }
function esc_sql( $v ) { return addslashes( (string) $v ); }

require dirname( __DIR__, 4 ) . '/uninstall.php';

// --- Result ------------------------------------------------------------------------------------------
$out = array( 'sites' => array(), 'usermeta' => $wpdb->get_col( "SELECT meta_key FROM `{$base}usermeta` ORDER BY meta_key" ), 'sitemeta' => $wpdb->get_col( "SELECT meta_key FROM `{$base}sitemeta` ORDER BY meta_key" ), 'as' => $GLOBALS['x2u_as'], 'cron' => array_map( 'count', $GLOBALS['x2u_cron'] ) );
foreach ( $sites as $id ) {
	$p = $wpdb->get_blog_prefix( $id );
	$caps = array();
	foreach ( $GLOBALS['x2u_roles'][ $id ] ?? array() as $role ) { $caps = array_merge( $caps, array_keys( $role->capabilities ) ); }
	foreach ( $GLOBALS['x2u_users'][ $id ] ?? array() as $user ) { $caps = array_merge( $caps, array_keys( $user->caps ) ); }
	$out['sites'][ $id ] = array( 'caps' => array_values( array_unique( $caps ) ), 'roles' => array_keys( $GLOBALS['x2u_roles'][ $id ] ?? array() ), 'tables' => $wpdb->get_col( "SHOW TABLES LIKE '" . $wpdb->esc_like( $p . 'wpcap_' ) . "%'" ), 'options' => $wpdb->get_col( "SELECT option_name FROM `{$p}options` ORDER BY option_name" ) );
	$db->query( "DROP TABLE IF EXISTS `{$p}options`, `{$p}wpcap_dummy`" );
}
$db->query( "DROP TABLE IF EXISTS `{$base}usermeta`, `{$base}sitemeta`" );
echo json_encode( $out );
