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
check( 'MINVER-1: header requires PHP 8.5, WordPress 7.0, WooCommerce 11.2', false !== strpos( $mv_main, 'Requires PHP: 8.5' ) && false !== strpos( $mv_main, 'Requires at least: 7.0' ) && false !== strpos( $mv_main, 'WC requires at least: 11.2' ) );
check( 'MINVER-1: the loader guard refuses PHP < 8.5 and WordPress < 7.0', false !== strpos( $mv_main, "version_compare( PHP_VERSION, '8.5', '<' )" ) && false !== strpos( $mv_main, "version_compare( (string) \$wp_version, '7.0', '<' )" ) );
check( 'MINVER-1: Site Health registers the database server test', isset( SiteHealth::test_callbacks()['db_server'] ) );
$mv = static fn( $info ) => SiteHealth::test_db_server( $info )['status'];
check( 'MINVER-1: MySQL 9.7 and MariaDB 12.3 are supported', 'good' === $mv( '9.7.0' ) && 'good' === $mv( '12.3.1-MariaDB-ubu2404' ) && 'good' === $mv( '5.5.5-12.3.1-MariaDB' ) );
check( 'MINVER-1: MySQL 8.4 and MariaDB 11.8 only get a warning (recommended, no critical)', 'recommended' === $mv( '8.4.3' ) && 'recommended' === $mv( '11.8.2-MariaDB' ) && 'recommended' === $mv( '5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1' ) );
check( 'MINVER-1: MariaDB 10.x is not mistaken for MySQL 10', 'MariaDB' === SiteHealth::db_server( '10.11.6-MariaDB' )['type'] && 'MySQL' === SiteHealth::db_server( '9.7.0' )['type'] );
