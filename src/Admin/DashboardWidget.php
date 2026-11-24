<?php
/**
 * Dashboard widget "Pass Captain: Überblick" (A615): sales of today and the last 7 days, orders waiting for a
 * manual payment confirmation or a review, and the next dates with sold/capacity. Complements the system status
 * widget (StatusPage). Only users who manage orders or events see it; amounts only with the order capability.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Money;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DashboardWidget {
	const ID = 'wpcap_overview_widget';

	/** Cache lifetime of the figures (seconds), filter `wpcap_dashboard_widget_ttl`. */
	const TTL = 300;

	public static function register() {
		add_action( 'wp_dashboard_setup', array( self::class, 'setup' ) );
	}

	/** Whether the current user may see the widget. */
	public static function visible() {
		return current_user_can( 'wpcap_manage_orders' ) || current_user_can( 'wpcap_manage_events' );
	}

	public static function setup() {
		if ( ! self::visible() || ! function_exists( 'wp_add_dashboard_widget' ) ) { return; }
		wp_add_dashboard_widget( self::ID, __( 'Pass Captain: Überblick', 'pass-captain' ), array( self::class, 'render' ) );
	}

	/**
	 * Figures of the widget (cached for a few minutes, per site).
	 *
	 * @return array{sales:array<string,array<string,array{orders:int,minor:int}>>,pending_manual:int,needs_review:int,upcoming:array<int,array<string,mixed>>}
	 */
	public static function data() {
		$cached = get_transient( 'wpcap_dashboard_widget' );
		if ( is_array( $cached ) && isset( $cached['sales'], $cached['upcoming'] ) ) { return $cached; }
		global $wpdb;
		$p = $wpdb->prefix;
		$now = \PassCaptain\Clock::mysql();
		$tz = new \DateTimeZone( \PassCaptain\Reports\Scope::site_timezone() );
		$today_local = ( new \DateTimeImmutable( '@' . \PassCaptain\Clock::now() ) )->setTimezone( $tz )->setTime( 0, 0 );
		$today_start = $today_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$week_start = $today_local->modify( '-6 days' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		// Payment date when the column exists (A613), otherwise the order date.
		$date = Orders::schema_at_least( '3.18.1' ) ? 'COALESCE(paid_at,created_at)' : 'created_at';
		$sales = array( 'today' => array(), 'week' => array() );
		foreach ( array( 'today' => $today_start, 'week' => $week_start ) as $key => $since ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT currency, COUNT(*) AS orders, COALESCE(SUM(total_minor),0) AS minor FROM {$p}wpcap_orders WHERE payment_status='paid' AND {$date}>=%s GROUP BY currency ORDER BY currency", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix, date expression from two literals.
			foreach ( (array) $rows as $row ) {
				$sales[ $key ][ (string) $row->currency ] = array( 'orders' => (int) $row->orders, 'minor' => (int) $row->minor );
			}
		}
		$pending_manual = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE status='pending_payment' AND payment_status='pending' AND payment_provider IN ('manual','bank_transfer')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix, no input.
		$needs_review = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpcap_orders WHERE status='needs_review'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix, no input.
		$limit = max( 1, min( 20, (int) apply_filters( 'wpcap_dashboard_widget_upcoming', 5 ) ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix, values as placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.occurrence_id, o.event_id, o.starts_at, o.capacity, e.name AS event_name, COALESCE(SUM(tt.sold_qty),0) AS sold, COALESCE(SUM(tt.capacity),0) AS type_capacity
			FROM {$p}wpcap_occurrences o
			INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id
			LEFT JOIN {$p}wpcap_ticket_types tt ON tt.occurrence_id=o.occurrence_id
			WHERE o.starts_at>=%s AND o.status='scheduled'
			GROUP BY o.occurrence_id, o.event_id, o.starts_at, o.capacity, e.name
			ORDER BY o.starts_at ASC, o.occurrence_id ASC LIMIT %d",
			$now,
			$limit
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$upcoming = array();
		foreach ( (array) $rows as $row ) {
			$capacity = (int) $row->capacity > 0 ? (int) $row->capacity : (int) $row->type_capacity;
			$upcoming[] = array(
				'occurrence_id' => (int) $row->occurrence_id,
				'event_id' => (int) $row->event_id,
				'event_name' => (string) $row->event_name,
				'starts_at' => (string) $row->starts_at,
				'sold' => max( 0, (int) $row->sold ),
				'capacity' => $capacity,
			);
		}
		$data = array( 'sales' => $sales, 'pending_manual' => $pending_manual, 'needs_review' => $needs_review, 'upcoming' => $upcoming );
		set_transient( 'wpcap_dashboard_widget', $data, max( 0, (int) apply_filters( 'wpcap_dashboard_widget_ttl', self::TTL ) ) );
		return $data;
	}

	public static function render() {
		if ( ! self::visible() ) { return; }
		$data = self::data();
		$orders_cap = current_user_can( 'wpcap_manage_orders' );
		if ( $orders_cap ) {
			echo '<h3>' . esc_html__( 'Verkäufe', 'pass-captain' ) . '</h3><table class="widefat striped"><tbody>';
			foreach ( array( 'today' => __( 'Heute', 'pass-captain' ), 'week' => __( 'Letzte 7 Tage', 'pass-captain' ) ) as $key => $label ) {
				$parts = array();
				foreach ( $data['sales'][ $key ] as $currency => $sum ) {
					/* translators: 1: number of paid orders, 2: amount */
					$parts[] = sprintf( _n( '%1$d Bestellung, %2$s', '%1$d Bestellungen, %2$s', $sum['orders'], 'pass-captain' ), $sum['orders'], Money::format( $sum['minor'], $currency ) );
				}
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $parts ? implode( '; ', $parts ) : __( 'Keine bezahlten Bestellungen', 'pass-captain' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<h3>' . esc_html( _x( 'Offen', 'dashboard widget: open tasks', 'pass-captain' ) ) . '</h3><ul>';
			$pending_url = Forms::page_url( OrdersPage::SLUG, array( 'status' => 'pending_payment', 'payment_status' => 'pending' ) );
			$review_url = Forms::page_url( OrdersPage::SLUG, array( 'status' => 'needs_review' ) );
			/* translators: %d: number of orders */
			echo '<li><a href="' . esc_url( $pending_url ) . '">' . esc_html( sprintf( _n( '%d Bestellung wartet auf Zahlungsbestätigung', '%d Bestellungen warten auf Zahlungsbestätigung', $data['pending_manual'], 'pass-captain' ), $data['pending_manual'] ) ) . '</a></li>';
			/* translators: %d: number of orders */
			echo '<li><a href="' . esc_url( $review_url ) . '">' . esc_html( sprintf( _n( '%d Bestellung zur Prüfung', '%d Bestellungen zur Prüfung', $data['needs_review'], 'pass-captain' ), $data['needs_review'] ) ) . '</a></li>';
			echo '</ul>';
		}
		echo '<h3>' . esc_html__( 'Nächste Termine', 'pass-captain' ) . '</h3>';
		if ( ! $data['upcoming'] ) {
			echo '<p>' . esc_html__( 'Keine anstehenden Termine.', 'pass-captain' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $data['upcoming'] as $row ) {
				$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $row['starts_at'] . ' UTC' ) );
				$sold = $row['capacity'] > 0
					/* translators: 1: sold tickets, 2: capacity */
					? sprintf( __( '%1$d/%2$d verkauft', 'pass-captain' ), $row['sold'], $row['capacity'] )
					/* translators: %d: sold tickets */
					: sprintf( __( '%d verkauft', 'pass-captain' ), $row['sold'] );
				echo '<li><strong>' . esc_html( $row['event_name'] ) . '</strong> – ' . esc_html( (string) $when ) . ' (' . esc_html( $sold ) . ')</li>';
			}
			echo '</ul>';
		}
	}
}
