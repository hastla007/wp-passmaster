<?php
/**
 * MINVER-1: minimum platform PHP 8.5 / WordPress 7.0 / WooCommerce 11.2; database servers below
 * MySQL 9.7 / MariaDB 12.3 only get a Site Health warning. Included from run.php.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Operations\SiteHealth;

echo "MINVER-1: minimum versions\n";
$mv_main = (string) file_get_contents( WPCAP_PATH . 'pass-captain.php' );
check( 'MINVER-1: header requires PHP 8.5, WordPress 7.0, WooCommerce 11.2.2', false !== strpos( $mv_main, 'Requires PHP: 8.5' ) && false !== strpos( $mv_main, 'Requires at least: 7.0' ) && false !== strpos( $mv_main, 'WC requires at least: 11.2.2' ) );
check( 'MINVER-1: the loader guard refuses PHP < 8.5 and WordPress < 7.0', false !== strpos( $mv_main, "version_compare( PHP_VERSION, '8.5', '<' )" ) && false !== strpos( $mv_main, "version_compare( (string) \$wp_version, '7.0', '<' )" ) );
check( 'MINVER-1: Site Health registers the database server test', isset( SiteHealth::test_callbacks()['db_server'] ) );
$mv = static fn( $info ) => SiteHealth::test_db_server( $info )['status'];
check( 'MINVER-1: MySQL 9.7 and MariaDB 12.3 are supported', 'good' === $mv( '9.7.0' ) && 'good' === $mv( '12.3.1-MariaDB-ubu2404' ) && 'good' === $mv( '5.5.5-12.3.1-MariaDB' ) );
check( 'MINVER-1: MySQL 8.4 and MariaDB 11.8 only get a warning (recommended, no critical)', 'recommended' === $mv( '8.4.3' ) && 'recommended' === $mv( '11.8.2-MariaDB' ) && 'recommended' === $mv( '5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1' ) );
check( 'MINVER-1: MariaDB 10.x is not mistaken for MySQL 10', 'MariaDB' === SiteHealth::db_server( '10.11.6-MariaDB' )['type'] && 'MySQL' === SiteHealth::db_server( '9.7.0' )['type'] );

// MariaDB 11.6.2+ defaults to innodb_snapshot_isolation=ON (error 1020 on rows changed after the snapshot).
global $wpdb;
$mv_info = (string) $wpdb->db_server_info();
$mv_has_var = null !== $wpdb->get_var( "SHOW VARIABLES LIKE 'innodb_snapshot_isolation'" );
if ( $mv_has_var ) {
	$wpdb->query( 'SET SESSION innodb_snapshot_isolation = ON' );
	$mv_ref = new ReflectionProperty( PassCaptain\DbSession::class, 'snapshot_connection' );
	$mv_ref->setAccessible( true );
	$mv_ref->setValue( null, null );
	$mv_off = PassCaptain\DbSession::classic_snapshots();
	$mv_val = strtoupper( (string) $wpdb->get_var( 'SELECT @@SESSION.innodb_snapshot_isolation' ) );
	$mv_newer = false !== stripos( $mv_info, 'mariadb' ) && preg_match( '/(\d+\.\d+\.\d+)/', (string) preg_replace( '/^5\.5\.5-/', '', $mv_info ), $mv_m ) && version_compare( $mv_m[1], '11.6.2', '>=' );
	if ( $mv_newer ) {
		check( 'MINVER-1: on MariaDB 11.6.2+ the plugin switches innodb_snapshot_isolation off for its session', $mv_off && in_array( $mv_val, array( '0', 'OFF' ), true ) );
		// Read, another connection changes the row and commits, then a conditional UPDATE in the first transaction.
		$p = $wpdb->prefix;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}wpcap_test_snapshot (id INT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB" );
		$wpdb->query( "REPLACE INTO {$p}wpcap_test_snapshot VALUES (1, 0)" );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( "SELECT n FROM {$p}wpcap_test_snapshot WHERE id=1" );
		$mv_other = new mysqli( getenv( 'WPCAP_TEST_DB_HOST' ) ?: '127.0.0.1', getenv( 'WPCAP_TEST_DB_USER' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_PASS' ) ?: 'wp', getenv( 'WPCAP_TEST_DB_NAME' ) ?: 'wp' );
		$mv_other->query( "UPDATE {$p}wpcap_test_snapshot SET n=n+1 WHERE id=1" );
		$mv_other->close();
		$mv_upd = $wpdb->query( "UPDATE {$p}wpcap_test_snapshot SET n=n+10 WHERE id=1" );
		$mv_err = (string) $wpdb->last_error;
		$wpdb->query( 'COMMIT' );
		check( 'MINVER-1: a conditional UPDATE after a concurrent change succeeds (no error 1020) (' . $mv_err . ')', 1 === (int) $mv_upd && 11 === (int) $wpdb->get_var( "SELECT n FROM {$p}wpcap_test_snapshot WHERE id=1" ) );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}wpcap_test_snapshot" );
	} else {
		check( 'MINVER-1: below MariaDB 11.6.2 / on MySQL the session variable is left alone', false === $mv_off );
		$wpdb->query( 'SET SESSION innodb_snapshot_isolation = OFF' );
	}
} else {
	check( 'MINVER-1: no innodb_snapshot_isolation on this server: nothing to switch', false === PassCaptain\DbSession::classic_snapshots() );
}
