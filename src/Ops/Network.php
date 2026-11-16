<?php
/**
 * Multisite network administration (W9): a network admin page (only when the plugin is network
 * activated) with a per-site overview – events, orders, paid orders and revenue of the last 30 days,
 * health from Ops\Status – and a network-wide CSV report.
 *
 * Site isolation: every figure is read inside switch_to_blog() with the site's own table prefix; no
 * query joins tables of different sites, and a site whose prefix cannot be switched to or whose
 * plugin tables are missing is reported as "not installed" instead of being read. Only users with
 * manage_network_options see the page or download the report; the download is audited.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Ops;

use PassCaptain\Audit;
use PassCaptain\Reports\Scope;
use PassCaptain\Security\StepUp;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Network {
	const SLUG = 'wpcap-network';
	const CAP = 'manage_network_options';
	const REPORT_ACTION = 'wpcap_network_report';
	const DAYS = 30;

	public static function register() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! self::network_active() ) { return; }
		add_action( 'network_admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'network_admin_edit_' . self::REPORT_ACTION, array( __CLASS__, 'handle_report' ) );
	}

	/** Whether the plugin is network activated. */
	public static function network_active() {
		if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'plugin_basename' ) ) { return false; }
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $plugins[ plugin_basename( WPCAP_FILE ) ] );
	}

	public static function add_menu() {
		add_menu_page( __( 'Pass Captain Netzwerk', 'pass-captain' ), \PassCaptain\Brand::menu_title(), self::CAP, self::SLUG, array( __CLASS__, 'render' ), \PassCaptain\Brand::menu_icon(), 58 );
	}

	/** Site ids of the network (filter wpcap_network_site_ids for very large networks). */
	public static function site_ids() {
		$ids = function_exists( 'get_sites' ) ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array();
		return array_values( array_map( 'absint', (array) apply_filters( 'wpcap_network_site_ids', $ids ) ) );
	}

	/**
	 * Figures of one site, read with that site's prefix.
	 *
	 * @return array{site_id:int,name:string,prefix:string,installed:bool,events:int,orders:int,paid_orders_30d:int,revenue_30d:array<string,int>,errors:int,warnings:int,issues:string[]}
	 */
	public static function site_overview( $site_id ) {
		global $wpdb;
		$site_id = absint( $site_id );
		$row = array( 'site_id' => $site_id, 'name' => '', 'prefix' => '', 'installed' => false, 'events' => 0, 'orders' => 0, 'paid_orders_30d' => 0, 'revenue_30d' => array(), 'errors' => 0, 'warnings' => 0, 'issues' => array() );
		$switched = function_exists( 'switch_to_blog' ) && (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) !== $site_id;
		if ( $switched ) { switch_to_blog( $site_id ); }
		try {
			$prefix = (string) $wpdb->prefix;
			$row['prefix'] = $prefix;
			$row['name'] = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			Status::flush_cache();
			if ( ! Status::table_exists( 'wpcap_orders' ) || ! Status::table_exists( 'wpcap_events' ) ) { return $row; }
			$row['installed'] = true;
			$since = gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
			$row['events'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- site prefix.
			$row['orders'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_orders" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- site prefix.
			$paid = $wpdb->get_results( $wpdb->prepare( "SELECT currency, COUNT(*) AS n, COALESCE(SUM(total_minor-refunded_minor),0) AS revenue FROM {$prefix}wpcap_orders WHERE payment_status IN ('paid','refunded') AND created_at >= %s GROUP BY currency", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- site prefix.
			foreach ( (array) $paid as $p ) {
				$row['paid_orders_30d'] += (int) $p->n;
				$row['revenue_30d'][ (string) $p->currency ] = (int) $p->revenue;
			}
			foreach ( Status::issues() as $issue ) {
				if ( 'error' === $issue['severity'] ) { ++$row['errors']; } else { ++$row['warnings']; }
				$row['issues'][] = (string) $issue['message'];
			}
			return $row;
		} finally {
			Status::flush_cache();
			if ( $switched && function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
		}
	}

	/** Overview of all (or the given) sites. */
	public static function overview( ?array $site_ids = null ) {
		$out = array();
		foreach ( null === $site_ids ? self::site_ids() : $site_ids as $site_id ) {
			$out[] = self::site_overview( $site_id );
		}
		return $out;
	}

	/** Formatted revenue per currency ("1.234,00 EUR; 10,00 CHF"). */
	private static function revenue_label( array $revenue ) {
		$parts = array();
		foreach ( $revenue as $currency => $minor ) { $parts[] = Scope::money( (int) $minor, (string) $currency ); }
		return $parts ? implode( '; ', $parts ) : '—';
	}

	/** CSV rows of the network report (header first; one row per site and currency). */
	public static function csv_rows( array $overview ) {
		$rows = array( array( 'site_id', 'site', 'installed', 'events', 'orders', 'paid_orders_30d', 'currency', 'revenue_30d', 'health_errors', 'health_warnings' ) );
		foreach ( $overview as $site ) {
			$revenue = $site['revenue_30d'] ? $site['revenue_30d'] : array( '' => 0 );
			foreach ( $revenue as $currency => $minor ) {
				$rows[] = array( $site['site_id'], $site['name'], $site['installed'] ? 'yes' : 'no', $site['events'], $site['orders'], $site['paid_orders_30d'], (string) $currency, \PassCaptain\Localization::money_decimal( (int) $minor, (string) $currency ), $site['errors'], $site['warnings'] );
			}
		}
		return $rows;
	}

	/**
	 * Network report after capability and 2FA checks (audited on the main site).
	 *
	 * @return array{filename:string,csv:string}|\WP_Error
	 */
	public static function report() {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'wpcap_network_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$check = StepUp::check( array( 'exports' ), self::REPORT_ACTION );
		if ( is_wp_error( $check ) ) { return $check; }
		$overview = self::overview();
		Audit::record( 'network_report_exported', 'network', 0, array( 'sites' => count( $overview ) ) );
		return array( 'filename' => 'wp-pass-captain-netzwerk-' . gmdate( 'Ymd' ) . '.csv', 'csv' => Scope::csv( self::csv_rows( $overview ) ) );
	}

	public static function handle_report() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		check_admin_referer( self::REPORT_ACTION );
		$result = self::report();
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Scope::send_download( $result['csv'], $result['filename'], 'text/csv; charset=utf-8' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$overview = self::overview();
		$url = wp_nonce_url( add_query_arg( 'action', self::REPORT_ACTION, network_admin_url( 'edit.php' ) ), self::REPORT_ACTION );
		echo '<div class="wrap"><h1>' . esc_html__( 'Pass Captain – Netzwerkübersicht', 'pass-captain' ) . '</h1>';
		/* translators: %d: number of days */
		echo '<p class="description">' . esc_html( sprintf( /* translators: %d: days */ _n( 'Zahlen je Website aus deren eigenen Tabellen; Umsatz = bezahlte Bestellungen des letzten %d Tages abzüglich Erstattungen. Daten verschiedener Websites werden nie gemeinsam abgefragt.', 'Zahlen je Website aus deren eigenen Tabellen; Umsatz = bezahlte Bestellungen der letzten %d Tage abzüglich Erstattungen. Daten verschiedener Websites werden nie gemeinsam abgefragt.', self::DAYS, 'pass-captain' ), self::DAYS ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Netzwerkbericht als CSV (wird protokolliert)', 'pass-captain' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Website', 'pass-captain' ) . '</th><th>' . esc_html__( 'Events', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bestellungen', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bezahlt (30 Tage)', 'pass-captain' ) . '</th><th>' . esc_html__( 'Umsatz (30 Tage)', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zustand', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $overview as $site ) {
			$admin = function_exists( 'get_admin_url' ) ? get_admin_url( $site['site_id'], 'admin.php?page=wpcap-status' ) : '';
			echo '<tr><td>' . esc_html( '#' . $site['site_id'] . ' ' . $site['name'] ) . '</td>';
			if ( ! $site['installed'] ) {
				echo '<td colspan="5">' . esc_html__( 'Plugin-Tabellen nicht installiert', 'pass-captain' ) . '</td></tr>';
				continue;
			}
			$health = $site['errors'] ? sprintf( /* translators: %d: number */ _n( '%d Fehler', '%d Fehler', $site['errors'], 'pass-captain' ), $site['errors'] ) : ( $site['warnings'] ? sprintf( /* translators: %d: number */ _n( '%d Warnung', '%d Warnungen', $site['warnings'], 'pass-captain' ), $site['warnings'] ) : __( 'OK', 'pass-captain' ) );
			echo '<td>' . (int) $site['events'] . '</td><td>' . (int) $site['orders'] . '</td><td>' . (int) $site['paid_orders_30d'] . '</td><td>' . esc_html( self::revenue_label( $site['revenue_30d'] ) ) . '</td><td>' . ( $admin ? '<a href="' . esc_url( $admin ) . '">' . esc_html( $health ) . '</a>' : esc_html( $health ) ) . '</td></tr>';
		}
		if ( ! $overview ) { echo '<tr><td colspan="6">' . esc_html__( 'Keine Websites.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
