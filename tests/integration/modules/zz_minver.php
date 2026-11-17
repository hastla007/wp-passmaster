<?php
/**
 * MINVER-1: minimum platform PHP 8.5 / WordPress 7.0 / WooCommerce 11.2.2; database servers below
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

// MariaDB 11.6.2+ (innodb_snapshot_isolation ON by default, MariaDB 12.3 in CI): a locking read of a row another
// connection changed after this transaction's first read must see the new row instead of failing with 1020.
// Snapshot isolation is switched on first, so older MariaDB with the setting (10.6.18+, 10.11.8+) reproduces it.
global $wpdb;
$mv_info  = (string) $wpdb->get_var( 'SELECT VERSION()' );
$mv_table = $wpdb->prefix . 'wpcap_minver_snapshot';
$mv_has   = null !== $wpdb->get_var( "SHOW VARIABLES LIKE 'innodb_snapshot_isolation'" );
if ( false !== stripos( $mv_info, 'mariadb' ) && $mv_has ) {
	$wpdb->query( "DROP TABLE IF EXISTS $mv_table" );
	$wpdb->query( "CREATE TABLE $mv_table ( id INT PRIMARY KEY, v INT NOT NULL ) ENGINE=InnoDB" );
	$wpdb->query( "INSERT INTO $mv_table VALUES ( 1, 1 )" );
	$mv_other = new Test_WPDB();
	$mv_run   = static function ( $fix ) use ( $wpdb, $mv_other, $mv_table ) {
		$wpdb->query( "UPDATE $mv_table SET v = 1 WHERE id = 1" );
		$wpdb->query( 'SET SESSION innodb_snapshot_isolation = ON' );
		if ( $fix ) { PassCaptain\DbSession::repeatable_read(); }
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( "SELECT v FROM $mv_table WHERE id = 1" ); // First read: the snapshot starts here.
		$mv_other->query( "UPDATE $mv_table SET v = 2 WHERE id = 1" ); // Another request changes the row and commits.
		$locked = $wpdb->get_var( "SELECT v FROM $mv_table WHERE id = 1 FOR UPDATE" );
		$error  = $wpdb->last_error;
		$wpdb->query( 'ROLLBACK' );
		return array( $locked, $error );
	};
	list( $mv_plain, $mv_plain_error ) = $mv_run( false );
	list( $mv_fixed, $mv_fixed_error ) = $mv_run( true );
	check( 'MINVER-1: with snapshot isolation on, the plain locking read fails (1020, MariaDB 11.6+ default)', null === $mv_plain && false !== stripos( $mv_plain_error, 'changed since last read' ) );
	check( 'MINVER-1: DbSession::classic_locking_reads() (via repeatable_read) – the locking read sees the latest row', '2' === (string) $mv_fixed && '' === $mv_fixed_error );
	$wpdb->query( "DROP TABLE IF EXISTS $mv_table" );
} else {
	check( 'MINVER-1: snapshot isolation check skipped (MySQL or MariaDB without innodb_snapshot_isolation): ' . $mv_info, true );
}
