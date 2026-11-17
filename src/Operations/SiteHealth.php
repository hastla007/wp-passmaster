<?php
/**
 * Site Health tests of Pass Captain. On this line only the database server test of MINVER-1; the
 * class name, keys and methods are those of the operations monitoring (A164) on the other 1.5/1.6
 * line, so a later merge keeps one class.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Operations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SiteHealth {
	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
	}

	/** Adds the direct tests (filter site_status_tests). */
	public static function register_tests( $tests ) {
		$tests = is_array( $tests ) ? $tests : array();
		foreach ( self::test_callbacks() as $key => $callback ) {
			$tests['direct'][ 'wpcap_' . $key ] = array(
				'label' => __( 'Pass Captain', 'pass-captain' ),
				'test'  => $callback,
			);
		}
		return $tests;
	}

	/** Test key => callable. */
	public static function test_callbacks() {
		$tests = array(
			'db_server'        => array( __CLASS__, 'test_db_server' ),
		);
		return $tests;
	}

	/** Minimum database server versions (MINVER-1); older servers get a warning, the plugin still runs. */
	const MIN_MYSQL = '9.7';
	const MIN_MARIADB = '12.3';

	/**
	 * Database server of this site: array( 'type' => 'MariaDB'|'MySQL', 'version' => 'x.y.z' ).
	 *
	 * @param string|null $server_info Raw server info (tests); default $wpdb->db_server_info().
	 */
	public static function db_server( $server_info = null ) {
		global $wpdb;
		if ( null === $server_info ) {
			$server_info = ( isset( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) ? (string) $wpdb->db_server_info() : '';
		}
		$server_info = (string) $server_info;
		$mariadb = false !== stripos( $server_info, 'mariadb' );
		// MariaDB reports "5.5.5-10.11.6-MariaDB…" through the MySQL protocol.
		$clean = preg_replace( '/^5\.5\.5-/', '', $server_info );
		$version = preg_match( '/(\d+\.\d+(?:\.\d+)?)/', (string) $clean, $m ) ? $m[1] : '';
		return array( 'type' => $mariadb ? 'MariaDB' : 'MySQL', 'version' => $version );
	}

	/** Site Health: database server below MySQL 9.7 / MariaDB 12.3 (recommended, no block). */
	public static function test_db_server( $server_info = null ) {
		$server = self::db_server( $server_info );
		$minimum = 'MariaDB' === $server['type'] ? self::MIN_MARIADB : self::MIN_MYSQL;
		if ( '' === $server['version'] || version_compare( $server['version'], $minimum, '>=' ) ) {
			return self::result( 'db_server', 'good', __( 'Pass Captain: Datenbankserver wird unterstützt', 'pass-captain' ), self::p( $server['type'] . ' ' . $server['version'] ) );
		}
		return self::result(
			'db_server',
			'recommended',
			/* translators: 1: database server (MySQL/MariaDB), 2: installed version */
			sprintf( __( 'Pass Captain: %1$s %2$s ist älter als die unterstützte Version', 'pass-captain' ), $server['type'], $server['version'] ),
			/* translators: 1: minimum MySQL version, 2: minimum MariaDB version */
			self::p( sprintf( __( 'Pass Captain wird mit MySQL %1$s+ und MariaDB %2$s+ getestet. Ältere Datenbankserver werden nicht gesperrt, sind aber nicht mehr geprüft. Bitte den Datenbankserver beim Hosting aktualisieren.', 'pass-captain' ), self::MIN_MYSQL, self::MIN_MARIADB ) )
		);
	}

	/** Site Health result array. */
	private static function result( $key, $status, $label, $description, $actions = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Pass Captain', 'pass-captain' ),
				'color' => 'critical' === $status ? 'red' : ( 'recommended' === $status ? 'orange' : 'blue' ),
			),
			'description' => $description,
			'actions'     => $actions,
			'test'        => 'wpcap_' . $key,
		);
	}

	private static function p( $text ) { return '<p>' . esc_html( $text ) . '</p>'; }
}
