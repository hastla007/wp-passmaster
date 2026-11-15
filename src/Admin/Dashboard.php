<?php
/**
 * Start screen summary (top of the events list, the plugin's landing page): key figures for the
 * user's event scope and a setup checklist built from settings and the system status checks
 * (Ops\Status::critical_issues()). The checklist is hidden once everything is done.
 *
 * Also: menu groups (Menu::group_pages()) and the "Funktionen" setting that hides menu entries of
 * modules an organizer does not use (setting hidden_modules; the modules keep running).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dashboard {
	/**
	 * Key figures: orders and revenue today and in the last 7 days (paid, per currency), tickets of the
	 * next 7 days, refunds waiting for approval, unpaid orders with an active hold.
	 *
	 * @param int[]|null $scope Event ids (null = all).
	 * @return array{orders_today:int, revenue_today:array<string,int>, orders_7d:int, revenue_7d:array<string,int>, tickets_next_7d:int, upcoming_7d:int, pending_refunds:int, unpaid:int}
	 */
	public static function kpis( $scope ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$today = gmdate( 'Y-m-d 00:00:00' );
		$week_ago = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$week_ahead = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
		$in_scope = null === $scope ? '' : " AND EXISTS (SELECT 1 FROM {$p}wpcap_order_items si INNER JOIN {$p}wpcap_ticket_types st ON st.ticket_type_id=si.ticket_type_id INNER JOIN {$p}wpcap_occurrences so ON so.occurrence_id=st.occurrence_id WHERE si.order_id=ord.order_id" . Queries::scope_sql( $scope, 'so.event_id' ) . ')';
		$out = array( 'orders_today' => 0, 'revenue_today' => array(), 'orders_7d' => 0, 'revenue_7d' => array() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_scope: table names and absint ids.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ord.currency, SUM(ord.created_at>=%s) AS today, COUNT(*) AS week, SUM(IF(ord.created_at>=%s,ord.total_minor,0)) AS revenue_today, SUM(ord.total_minor) AS revenue_week FROM %i ord WHERE ord.payment_status='paid' AND ord.created_at>=%s{$in_scope} GROUP BY ord.currency", $today, $today, $p . 'wpcap_orders', $week_ago ) );
		foreach ( (array) $rows as $row ) {
			$out['orders_today'] += (int) $row->today;
			$out['orders_7d'] += (int) $row->week;
			if ( (int) $row->revenue_today ) { $out['revenue_today'][ (string) $row->currency ] = (int) $row->revenue_today; }
			if ( (int) $row->revenue_week ) { $out['revenue_7d'][ (string) $row->currency ] = (int) $row->revenue_week; }
		}
		$occ_scope = Queries::scope_sql( $scope, 'o.event_id' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $occ_scope: absint ids.
		$out['upcoming_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.status='scheduled' AND o.starts_at>=%s AND o.starts_at<%s{$occ_scope}", $p . 'wpcap_occurrences', $now, $week_ahead ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $occ_scope: absint ids.
		$out['tickets_next_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE t.status IN ('valid','checked_in') AND o.status='scheduled' AND o.starts_at>=%s AND o.starts_at<%s{$occ_scope}", $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $now, $week_ahead ) );
		$out['pending_refunds'] = Queries::pending_refunds_count( $scope );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_scope: table names and absint ids.
		$out['unpaid'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i ord WHERE ord.status='pending_payment' AND ord.payment_status='pending' AND EXISTS (SELECT 1 FROM %i r WHERE r.order_id=ord.order_id AND r.status='active' AND r.expires_at>%s){$in_scope}", $p . 'wpcap_orders', $p . 'wpcap_reservations', $now ) );
		return $out;
	}

	/**
	 * Setup checklist: key => [done (bool), label, link].
	 *
	 * @return array<string,array{0:bool,1:string,2:string}>
	 */
	public static function checklist() {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$sellable = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e INNER JOIN %i o ON o.event_id=e.event_id INNER JOIN %i tt ON tt.occurrence_id=o.occurrence_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at>%s AND tt.status='active'", $p . 'wpcap_events', $p . 'wpcap_occurrences', $p . 'wpcap_ticket_types', $now ) );
		$providers = (array) Settings::get( 'enabled_providers' );
		$settings = Forms::page_url( SettingsPage::SLUG );
		$critical = class_exists( '\\PassCaptain\\Ops\\Status' ) ? \PassCaptain\Ops\Status::critical_issues() : array();
		return array(
			'seller' => array( '' !== trim( (string) Settings::get( 'seller_name' ) ) && '' !== trim( (string) Settings::get( 'seller_address' ) ), __( 'Rechnungssteller (Name und Anschrift) eintragen', 'pass-captain' ), $settings ),
			'payments' => array( (bool) $providers, __( 'Mindestens eine Zahlungsart aktivieren', 'pass-captain' ), $settings ),
			'terms' => array( '' !== trim( (string) Settings::get( 'terms_text' ) ) && '' !== trim( (string) Settings::get( 'consumer_info_text' ) ), __( 'Bedingungen und Verbraucherinformationen hinterlegen', 'pass-captain' ), $settings ),
			'event' => array( $sellable > 0, __( 'Ein veröffentlichtes Event mit kommendem Termin und aktiver Ticketart anlegen', 'pass-captain' ), Forms::page_url( EventsPage::SLUG ) ),
			'frontend' => array( (bool) Settings::get( 'event_pages' ), __( 'Eventseiten einschalten oder den Shortcode [wp_pass_captain_events] auf einer Seite einfügen', 'pass-captain' ), $settings ),
			'status' => array( ! $critical, __( 'Systemstatus ohne Fehler (Hintergrundaufgaben, Datenbank)', 'pass-captain' ), Forms::page_url( StatusPage::SLUG ) ),
		);
	}

	private static function money_list( array $amounts ) {
		if ( ! $amounts ) { return Forms::money( 0, 'EUR' ); }
		$parts = array();
		foreach ( $amounts as $currency => $minor ) { $parts[] = Forms::money( (int) $minor, (string) $currency ); }
		return implode( ' · ', $parts );
	}

	/** Summary box on the events list (start page). */
	public static function render_summary() {
		$scope = Queries::event_ids_for( array( EventsPage::CAP, OrdersPage::CAP ) );
		$kpi = self::kpis( $scope );
		$tiles = array(
			__( 'Bestellungen heute', 'pass-captain' ) => (string) $kpi['orders_today'],
			__( 'Umsatz heute', 'pass-captain' ) => self::money_list( $kpi['revenue_today'] ),
			__( 'Umsatz 7 Tage', 'pass-captain' ) => self::money_list( $kpi['revenue_7d'] ),
			__( 'Tickets nächste 7 Tage', 'pass-captain' ) => (string) $kpi['tickets_next_7d'],
			__( 'Termine nächste 7 Tage', 'pass-captain' ) => (string) $kpi['upcoming_7d'],
			__( 'Erstattungen zur Freigabe', 'pass-captain' ) => (string) $kpi['pending_refunds'],
			__( 'Offene Zahlungen', 'pass-captain' ) => (string) $kpi['unpaid'],
		);
		echo '<section class="wpcap-dashboard" aria-labelledby="wpcap-dashboard-title"><h2 id="wpcap-dashboard-title" class="screen-reader-text">' . esc_html__( 'Übersicht', 'pass-captain' ) . '</h2><ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.75rem;margin:1rem 0;padding:0;list-style:none">';
		foreach ( $tiles as $label => $value ) {
			echo '<li class="card" style="margin:0;padding:.75rem 1rem;max-width:none"><span class="description">' . esc_html( $label ) . '</span><br><strong style="font-size:1.4em">' . esc_html( $value ) . '</strong></li>';
		}
		echo '</ul>';
		if ( current_user_can( 'manage_options' ) ) {
			$list = self::checklist();
			$open = array_filter( $list, static function ( $item ) { return ! $item[0]; } );
			if ( $open ) {
				/* translators: 1: done steps, 2: all steps */
				echo '<div class="card" style="max-width:none"><h3>' . esc_html( sprintf( __( 'Einrichtung: %1$d von %2$d erledigt', 'pass-captain' ), count( $list ) - count( $open ), count( $list ) ) ) . '</h3><ul>';
				foreach ( $list as $item ) {
					echo '<li>' . ( $item[0] ? '<span aria-hidden="true">✓</span> <span class="screen-reader-text">' . esc_html__( 'Erledigt:', 'pass-captain' ) . '</span>' . esc_html( $item[1] ) : '<span aria-hidden="true">○</span> <a href="' . esc_url( $item[2] ) . '">' . esc_html( $item[1] ) . '</a>' ) . '</li>';
				}
				echo '</ul></div>';
			}
		}
		echo '</section>';
	}

	/* ------------------------------------------------------------------ "Funktionen" (menu entries) */

	/**
	 * Modules with admin pages that can be hidden from the menu: name => label.
	 *
	 * @return array<string,string>
	 */
	public static function hideable_modules() {
		$labels = array(
			'Admission' => __( 'Einlassregeln und -bereiche', 'pass-captain' ),
			'BoxOffice' => __( 'Abendkasse', 'pass-captain' ),
			'Calendar' => __( 'Kalender', 'pass-captain' ),
			'Connectors' => __( 'Konnektoren', 'pass-captain' ),
			'Fiscalization' => __( 'Fiskalisierung', 'pass-captain' ),
			'MailTemplates' => __( 'E-Mail-Vorlagen', 'pass-captain' ),
			'Mollie' => __( 'Mollie', 'pass-captain' ),
			'OfflineScanner' => __( 'Offline-Scanner', 'pass-captain' ),
			'Partners' => __( 'Veranstalter und Abrechnungen', 'pass-captain' ),
			'PrintTemplates' => __( 'Druckvorlagen', 'pass-captain' ),
			'Reminders' => __( 'Erinnerungen', 'pass-captain' ),
			'Reports' => __( 'Berichte', 'pass-captain' ),
			'SalesRules' => __( 'Verkaufsregeln', 'pass-captain' ),
			'Seating' => __( 'Saalpläne', 'pass-captain' ),
			'Series' => __( 'Serien', 'pass-captain' ),
			'Sms' => __( 'SMS', 'pass-captain' ),
			'Wallet' => __( 'Wallet', 'pass-captain' ),
			'Webhooks' => __( 'Webhooks', 'pass-captain' ),
		);
		$out = array();
		foreach ( \PassCaptain\Modules::MANIFEST as $name => $methods ) {
			if ( in_array( 'admin_pages', $methods, true ) ) { $out[ $name ] = $labels[ $name ] ?? $name; }
		}
		return $out;
	}

	/** Settings sanitiser for hidden_modules (array of module names). */
	public static function sanitize_hidden( $value ) {
		return array_values( array_intersect( array_keys( self::hideable_modules() ), array_map( 'strval', (array) $value ) ) );
	}

	/** Whether the admin pages of a module class are hidden from the menu. */
	public static function module_hidden( $class ) {
		$name = substr( strrchr( '\\' . ltrim( (string) $class, '\\' ), '\\' ), 1 );
		return in_array( $name, (array) Settings::get( 'hidden_modules' ), true );
	}
}
