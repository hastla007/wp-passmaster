<?php
/**
 * Multisite network administration (W9, A162): a network admin page (only when the plugin is network
 * activated) with a paged per-site overview – events, upcoming dates, orders, paid orders and revenue of
 * the last 30 days, health from Ops\Status – and a network-wide report for a chosen period (orders,
 * tickets, gross, refunds, net per site and currency, network totals per currency; page and CSV).
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

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Network {
	const SLUG = 'wpcap-network';
	const CAP = 'manage_network_options';
	const REPORT_ACTION = 'wpcap_network_report';
	const DAYS = 30;
	/** Sites per overview page (the health check runs per listed site). */
	const PER_PAGE = 20;
	/** Longest report period in days. */
	const MAX_PERIOD_DAYS = 366;

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
	 * One overview page of site ids (A162: the health check runs per listed site, so large networks are paged).
	 *
	 * @return array{ids:int[], total:int, page:int, pages:int}
	 */
	public static function page_of_sites( $page ) {
		$all = self::site_ids();
		$pages = max( 1, (int) ceil( count( $all ) / self::PER_PAGE ) );
		$page = min( $pages, max( 1, absint( $page ) ) );
		return array( 'ids' => array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ), 'total' => count( $all ), 'page' => $page, 'pages' => $pages );
	}

	/**
	 * Report period from user input (local dates of each site, at most MAX_PERIOD_DAYS; default: the last 30 days).
	 *
	 * @return array{0:string,1:string} [from, to] as Y-m-d.
	 */
	public static function period( $from, $to ) {
		$today = gmdate( 'Y-m-d', \PassCaptain\Clock::now() );
		$to = \PassCaptain\Reports\Data::valid_date( $to ) ? (string) $to : $today;
		$from = \PassCaptain\Reports\Data::valid_date( $from ) ? (string) $from : gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( self::DAYS - 1 ) * DAY_IN_SECONDS );
		if ( $from > $to ) { list( $from, $to ) = array( $to, $from ); }
		$min = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( self::MAX_PERIOD_DAYS - 1 ) * DAY_IN_SECONDS );
		return array( max( $from, $min ), $to );
	}

	/**
	 * Sales figures of one site for a period (local dates in the site's timezone), read with that site's
	 * prefix: per currency paid orders and tickets (by order date, complimentary orders excluded), gross,
	 * completed refunds (by refund date) and net.
	 *
	 * @return array{site_id:int,name:string,installed:bool,currencies:array<string,array{orders:int,tickets:int,gross:int,refunded:int,net:int}>}
	 */
	public static function site_period( $site_id, $from, $to ) {
		global $wpdb;
		$site_id = absint( $site_id );
		$row = array( 'site_id' => $site_id, 'name' => '', 'installed' => false, 'currencies' => array() );
		$switched = function_exists( 'switch_to_blog' ) && (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) !== $site_id;
		if ( $switched ) { switch_to_blog( $site_id ); }
		try {
			$prefix = (string) $wpdb->prefix;
			$row['name'] = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			Status::flush_cache();
			if ( ! Status::table_exists( 'wpcap_orders' ) || ! Status::table_exists( 'wpcap_order_items' ) || ! Status::table_exists( 'wpcap_refunds' ) ) { return $row; }
			$row['installed'] = true;
			list( $start, $end ) = \PassCaptain\Reports\Data::bounds( $from, $to, Scope::site_timezone() );
			$comps = \PassCaptain\Reports\Data::comp_providers();
			$not_comp = $comps ? ' AND o.payment_provider NOT IN (' . implode( ',', array_fill( 0, count( $comps ), '%s' ) ) . ')' : '';
			$empty = array( 'orders' => 0, 'tickets' => 0, 'gross' => 0, 'refunded' => 0, 'net' => 0 );
			$sales = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.currency, COUNT(*) AS orders, COALESCE(SUM(o.total_minor),0) AS gross, COALESCE(SUM((SELECT COALESCE(SUM(i.quantity),0) FROM {$prefix}wpcap_order_items i WHERE i.order_id=o.order_id)),0) AS tickets FROM {$prefix}wpcap_orders o WHERE o.payment_status IN ('paid','refunded') AND o.created_at >= %s AND o.created_at < %s" . $not_comp . ' GROUP BY o.currency', array_merge( array( $start, $end ), $comps ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- site prefix, placeholders built for the comp list.
			foreach ( $sales as $sale ) {
				$row['currencies'][ (string) $sale->currency ] = array( 'orders' => (int) $sale->orders, 'tickets' => (int) $sale->tickets, 'gross' => (int) $sale->gross ) + $empty;
			}
			$refunds = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.currency, COALESCE(SUM(r.amount_minor),0) AS refunded FROM {$prefix}wpcap_refunds r INNER JOIN {$prefix}wpcap_orders o ON o.order_id=r.order_id WHERE r.status='completed' AND r.updated_at >= %s AND r.updated_at < %s" . $not_comp . ' GROUP BY o.currency', array_merge( array( $start, $end ), $comps ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- site prefix, placeholders built for the comp list.
			foreach ( $refunds as $refund ) {
				$cur = (string) $refund->currency;
				$row['currencies'][ $cur ] = $row['currencies'][ $cur ] ?? $empty;
				$row['currencies'][ $cur ]['refunded'] = (int) $refund->refunded;
			}
			foreach ( $row['currencies'] as $cur => $figures ) { $row['currencies'][ $cur ]['net'] = $figures['gross'] - $figures['refunded']; }
			ksort( $row['currencies'] );
			return $row;
		} finally {
			Status::flush_cache();
			if ( $switched && function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
		}
	}

	/**
	 * Network-wide report for a period: one entry per site plus totals per currency (amounts of different
	 * currencies are never added up).
	 *
	 * @param string     $from     Local start date (Y-m-d).
	 * @param string     $to       Local end date (Y-m-d).
	 * @param int[]|null $site_ids Sites (null = all).
	 * @return array{from:string,to:string,sites:array[],totals:array<string,array>}
	 */
	public static function period_report( $from, $to, ?array $site_ids = null ) {
		list( $from, $to ) = self::period( $from, $to );
		$sites = array();
		$totals = array();
		foreach ( null === $site_ids ? self::site_ids() : $site_ids as $site_id ) {
			$site = self::site_period( $site_id, $from, $to );
			$sites[] = $site;
			foreach ( $site['currencies'] as $cur => $figures ) {
				foreach ( $figures as $key => $value ) { $totals[ $cur ][ $key ] = ( $totals[ $cur ][ $key ] ?? 0 ) + (int) $value; }
			}
		}
		ksort( $totals );
		return array( 'from' => $from, 'to' => $to, 'sites' => $sites, 'totals' => $totals );
	}

	/** CSV rows of the period report (header first; one row per site and currency, then the totals). */
	public static function period_csv_rows( array $report ) {
		$rows = array( array( 'site_id', 'site', 'installed', 'from', 'to', 'currency', 'orders', 'tickets', 'gross', 'refunded', 'net' ) );
		$money = static function ( $minor, $currency ) { return \PassCaptain\Localization::money_decimal( (int) $minor, (string) $currency ); };
		foreach ( $report['sites'] as $site ) {
			$currencies = $site['currencies'] ? $site['currencies'] : array( '' => array( 'orders' => 0, 'tickets' => 0, 'gross' => 0, 'refunded' => 0, 'net' => 0 ) );
			foreach ( $currencies as $cur => $f ) {
				$rows[] = array( $site['site_id'], $site['name'], $site['installed'] ? 'yes' : 'no', $report['from'], $report['to'], (string) $cur, $f['orders'], $f['tickets'], $money( $f['gross'], $cur ), $money( $f['refunded'], $cur ), $money( $f['net'], $cur ) );
			}
		}
		foreach ( $report['totals'] as $cur => $f ) {
			$rows[] = array( 'total', '', '', $report['from'], $report['to'], (string) $cur, $f['orders'], $f['tickets'], $money( $f['gross'], $cur ), $money( $f['refunded'], $cur ), $money( $f['net'], $cur ) );
		}
		return $rows;
	}

	/**
	 * Figures of one site, read with that site's prefix.
	 *
	 * @return array{site_id:int,name:string,prefix:string,installed:bool,events:int,orders:int,paid_orders_30d:int,revenue_30d:array<string,int>,errors:int,warnings:int,issues:string[]}
	 */
	public static function site_overview( $site_id ) {
		global $wpdb;
		$site_id = absint( $site_id );
		$row = array( 'site_id' => $site_id, 'name' => '', 'prefix' => '', 'installed' => false, 'events' => 0, 'upcoming' => 0, 'orders' => 0, 'paid_orders_30d' => 0, 'revenue_30d' => array(), 'errors' => 0, 'warnings' => 0, 'issues' => array() );
		$switched = function_exists( 'switch_to_blog' ) && (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0 ) !== $site_id;
		if ( $switched ) { switch_to_blog( $site_id ); }
		try {
			$prefix = (string) $wpdb->prefix;
			$row['prefix'] = $prefix;
			$row['name'] = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			Status::flush_cache();
			if ( ! Status::table_exists( 'wpcap_orders' ) || ! Status::table_exists( 'wpcap_events' ) ) { return $row; }
			$row['installed'] = true;
			$since = gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - self::DAYS * DAY_IN_SECONDS );
			$row['events'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- site prefix.
			$row['orders'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wpcap_orders" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- site prefix.
			// A162: scheduled dates starting in the next 30 days.
			if ( Status::table_exists( 'wpcap_occurrences' ) ) {
				$row['upcoming'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}wpcap_occurrences WHERE status='scheduled' AND starts_at >= %s AND starts_at < %s", \PassCaptain\Clock::mysql(), gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() + self::DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- site prefix.
			}
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
	public static function report( $from = null, $to = null ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'wpcap_network_forbidden', __( 'Keine Berechtigung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$check = StepUp::check( array( 'exports' ), self::REPORT_ACTION );
		if ( is_wp_error( $check ) ) { return $check; }
		if ( null !== $from || null !== $to ) {
			// A162: sales report for a period (per site and currency, with network totals).
			$period = self::period_report( (string) $from, (string) $to );
			Audit::record( 'network_report_exported', 'network', 0, array( 'sites' => count( $period['sites'] ), 'from' => $period['from'], 'to' => $period['to'] ) );
			return array( 'filename' => 'pass-captain-netzwerk-' . str_replace( '-', '', $period['from'] ) . '-' . str_replace( '-', '', $period['to'] ) . '.csv', 'csv' => Scope::csv( self::period_csv_rows( $period ) ) );
		}
		$overview = self::overview();
		Audit::record( 'network_report_exported', 'network', 0, array( 'sites' => count( $overview ) ) );
		return array( 'filename' => 'pass-captain-netzwerk-' . gmdate( 'Ymd', \PassCaptain\Clock::now() ) . '.csv', 'csv' => Scope::csv( self::csv_rows( $overview ) ) );
	}

	public static function handle_report() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		check_admin_referer( self::REPORT_ACTION );
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$result = self::report( $from, $to );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 403 ); }
		Scope::send_download( $result['csv'], $result['filename'], 'text/csv; charset=utf-8' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo self::render_html( $_GET ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended -- escaped in render_html(); read-only filters.
	}

	/**
	 * Network page (escaped markup): paged site overview and the period report.
	 *
	 * @param array $query Request parameters (paged, from, to).
	 */
	public static function render_html( array $query ) {
		$paging = self::page_of_sites( absint( $query['paged'] ?? 1 ) );
		$overview = self::overview( $paging['ids'] );
		$period = self::period( sanitize_text_field( (string) ( $query['from'] ?? '' ) ), sanitize_text_field( (string) ( $query['to'] ?? '' ) ) );
		$base = function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php?page=' . self::SLUG ) : '';
		$csv_url = static function ( array $args ) {
			return wp_nonce_url( add_query_arg( array( 'action' => self::REPORT_ACTION ) + $args, network_admin_url( 'edit.php' ) ), self::REPORT_ACTION );
		};
		$html = '<div class="wrap"><h1>' . esc_html__( 'Pass Captain – Netzwerkübersicht', 'pass-captain' ) . '</h1>';
		/* translators: %d: number of days */
		$intro = _n( 'Zahlen je Website aus deren eigenen Tabellen; Umsatz = bezahlte Bestellungen des letzten %d Tages abzüglich Erstattungen. Daten verschiedener Websites werden nie gemeinsam abgefragt.', 'Zahlen je Website aus deren eigenen Tabellen; Umsatz = bezahlte Bestellungen der letzten %d Tage abzüglich Erstattungen. Daten verschiedener Websites werden nie gemeinsam abgefragt.', self::DAYS, 'pass-captain' );
		$html .= '<p class="description">' . esc_html( sprintf( $intro, self::DAYS ) ) . '</p>';
		$html .= '<p><a class="button" href="' . esc_url( $csv_url( array() ) ) . '">' . esc_html__( 'Netzwerkbericht als CSV (wird protokolliert)', 'pass-captain' ) . '</a></p>';
		$html .= '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Website', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Events', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Termine (nächste 30 Tage)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bestellungen', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bezahlt (30 Tage)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Umsatz (30 Tage)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Zustand', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $overview as $site ) {
			$admin = function_exists( 'get_admin_url' ) ? get_admin_url( $site['site_id'], 'admin.php?page=wpcap-status' ) : '';
			$html .= '<tr><td>' . esc_html( '#' . $site['site_id'] . ' ' . $site['name'] ) . '</td>';
			if ( ! $site['installed'] ) {
				$html .= '<td colspan="6">' . esc_html__( 'Plugin-Tabellen nicht installiert', 'pass-captain' ) . '</td></tr>';
				continue;
			}
			/* translators: %d: number of errors */
			$errors = sprintf( _n( '%d Fehler', '%d Fehler', $site['errors'], 'pass-captain' ), $site['errors'] );
			/* translators: %d: number of warnings */
			$warnings = sprintf( _n( '%d Warnung', '%d Warnungen', $site['warnings'], 'pass-captain' ), $site['warnings'] );
			$health = $site['errors'] ? $errors : ( $site['warnings'] ? $warnings : __( 'OK', 'pass-captain' ) );
			$html .= '<td>' . (int) $site['events'] . '</td><td>' . (int) $site['upcoming'] . '</td><td>' . (int) $site['orders'] . '</td><td>' . (int) $site['paid_orders_30d'] . '</td><td>' . esc_html( self::revenue_label( $site['revenue_30d'] ) ) . '</td><td>' . ( $admin ? '<a href="' . esc_url( $admin ) . '">' . esc_html( $health ) . '</a>' : esc_html( $health ) ) . '</td></tr>';
		}
		if ( ! $overview ) { $html .= '<tr><td colspan="7">' . esc_html__( 'Keine Websites.', 'pass-captain' ) . '</td></tr>'; }
		$html .= '</tbody></table>';
		if ( $paging['pages'] > 1 ) {
			// A162: the health check runs per listed site, so large networks are paged.
			$links = array();
			for ( $i = 1; $i <= $paging['pages']; $i++ ) {
				$links[] = $i === $paging['page'] ? '<span aria-current="page"><strong>' . (int) $i . '</strong></span>' : '<a href="' . esc_url( add_query_arg( 'paged', $i, $base ) ) . '">' . (int) $i . '</a>';
			}
			/* translators: %d: number of sites */
			$count = sprintf( _n( '%d Website', '%d Websites', $paging['total'], 'pass-captain' ), (int) $paging['total'] );
			$html .= '<nav aria-label="' . esc_attr__( 'Seiten der Netzwerkübersicht', 'pass-captain' ) . '"><p>' . esc_html( $count ) . ' · ' . implode( ' ', $links ) . '</p></nav>';
		}

		// A162: sales per site and currency for a period, with totals per currency.
		$report = self::period_report( $period[0], $period[1], $paging['ids'] );
		$html .= '<h2 id="wpcap-network-period">' . esc_html__( 'Netzwerkbericht für einen Zeitraum', 'pass-captain' ) . '</h2>';
		$html .= '<form method="get" action="' . esc_url( function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php' ) : '' ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$html .= '<p><label for="wpcap-net-from">' . esc_html__( 'Von', 'pass-captain' ) . '</label> <input type="date" id="wpcap-net-from" name="from" value="' . esc_attr( $report['from'] ) . '"> <label for="wpcap-net-to">' . esc_html__( 'Bis', 'pass-captain' ) . '</label> <input type="date" id="wpcap-net-to" name="to" value="' . esc_attr( $report['to'] ) . '"> <button type="submit" class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button> <a class="button" href="' . esc_url( $csv_url( array( 'from' => $report['from'], 'to' => $report['to'] ) ) ) . '">' . esc_html__( 'Als CSV für alle Websites (wird protokolliert)', 'pass-captain' ) . '</a></p></form>';
		/* translators: %d: maximum number of days */
		$note = _n( 'Verkäufe nach Bestelldatum, Erstattungen nach Abschlussdatum, jeweils in der Zeitzone der Website; ohne Freikarten. Höchstens %d Tag. Die Tabelle zeigt die Websites dieser Seite, die CSV alle.', 'Verkäufe nach Bestelldatum, Erstattungen nach Abschlussdatum, jeweils in der Zeitzone der Website; ohne Freikarten. Höchstens %d Tage. Die Tabelle zeigt die Websites dieser Seite, die CSV alle.', self::MAX_PERIOD_DAYS, 'pass-captain' );
		$html .= '<p class="description">' . esc_html( sprintf( $note, self::MAX_PERIOD_DAYS ) ) . '</p>';
		$html .= '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Website', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Währung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Bestellungen', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Tickets', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Brutto', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Erstattet', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Netto nach Erstattungen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		$cells = static function ( $cur, array $f ) {
			return '<td>' . esc_html( (string) $cur ) . '</td><td>' . (int) $f['orders'] . '</td><td>' . (int) $f['tickets'] . '</td><td>' . esc_html( Scope::money( (int) $f['gross'], (string) $cur ) ) . '</td><td>' . esc_html( Scope::money( (int) $f['refunded'], (string) $cur ) ) . '</td><td>' . esc_html( Scope::money( (int) $f['net'], (string) $cur ) ) . '</td>';
		};
		foreach ( $report['sites'] as $site ) {
			$label = '<td>' . esc_html( '#' . $site['site_id'] . ' ' . $site['name'] ) . '</td>';
			if ( ! $site['installed'] || ! $site['currencies'] ) {
				$html .= '<tr>' . $label . '<td colspan="6">' . esc_html( $site['installed'] ? __( 'Keine Verkäufe im Zeitraum', 'pass-captain' ) : __( 'Plugin-Tabellen nicht installiert', 'pass-captain' ) ) . '</td></tr>';
				continue;
			}
			foreach ( $site['currencies'] as $cur => $f ) { $html .= '<tr>' . $label . $cells( $cur, $f ) . '</tr>'; }
		}
		foreach ( $report['totals'] as $cur => $f ) {
			$html .= '<tr><th scope="row">' . esc_html__( 'Summe (diese Seite)', 'pass-captain' ) . '</th>' . $cells( $cur, $f ) . '</tr>';
		}
		return $html . '</tbody></table></div>';
	}
}
