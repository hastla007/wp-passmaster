<?php
/** Admin screen "Berichte" with SVG bar charts and CSV export. */
namespace PassCaptain\Reports;

use PassCaptain\Admin\Forms;
use PassCaptain\Admin\Queries;
use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Page {
	const SLUG = 'wpcap-reports';

	public static function menu_cap() {
		return current_user_can( 'wpcap_view_finance' ) ? 'wpcap_view_finance' : 'wpcap_view_reports';
	}

	/** Filters from the query string. */
	public static function filters( array $in ) {
		$today = \PassCaptain\Clock::today( Scope::site_timezone() );
		return array(
			'from' => Data::valid_date( $in['from'] ?? '' ) ? $in['from'] : ( new \DateTimeImmutable( $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '-29 days' )->format( 'Y-m-d' ),
			'to' => Data::valid_date( $in['to'] ?? '' ) ? $in['to'] : $today,
			'event_id' => absint( $in['event_id'] ?? 0 ),
			'occurrence_id' => absint( $in['occurrence_id'] ?? 0 ),
		);
	}

	/**
	 * Whether an event/occurrence filter lies inside the user's report scope (A145): a filter on an
	 * object outside it is refused instead of silently returning an empty report.
	 *
	 * @param array      $filters Output of filters().
	 * @param int[]|null $scope   Output of Data::scope() (null = all events).
	 */
	public static function filters_allowed( array $filters, $scope ) {
		if ( $filters['event_id'] && is_array( $scope ) && ! in_array( (int) $filters['event_id'], array_map( 'intval', $scope ), true ) ) { return false; }
		if ( $filters['occurrence_id'] ) {
			$event_id = Scope::occurrence_event( $filters['occurrence_id'] );
			if ( ! $event_id || ( is_array( $scope ) && ! in_array( $event_id, array_map( 'intval', $scope ), true ) ) || ! \PassCaptain\Access::occurrence_in_scope( $filters['occurrence_id'] ) ) { return false; }
		}
		return true;
	}

	/**
	 * Simple horizontal bar chart as inline SVG (escaped, no scripts).
	 *
	 * @param array<string,int|float> $values label => value.
	 */
	public static function bar_chart( array $values, $title, ?callable $format = null ) {
		if ( ! $values ) { return ''; }
		$format = $format ?: static function ( $v ) { return (string) $v; };
		$max = max( 1, max( array_map( 'abs', $values ) ) );
		$row = 22;
		$label_w = 220;
		$bar_w = 420;
		$height = count( $values ) * $row + 30;
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" role="img" class="wpcap-chart" width="100%" viewBox="0 0 ' . ( $label_w + $bar_w + 110 ) . ' ' . $height . '" style="max-width:760px;font:12px sans-serif"><title>' . esc_html( $title ) . '</title>';
		$svg .= '<text x="0" y="14" font-weight="bold">' . esc_html( $title ) . '</text>';
		$i = 0;
		foreach ( $values as $label => $value ) {
			$y = 24 + $i * $row;
			$w = max( 0, round( $bar_w * max( 0, $value ) / $max, 1 ) );
			$label = (string) $label;
			$short = mb_strlen( $label ) > 34 ? mb_substr( $label, 0, 33 ) . '…' : $label;
			$svg .= '<g><title>' . esc_html( $label . ': ' . $format( $value ) ) . '</title><text x="' . ( $label_w - 6 ) . '" y="' . ( $y + 14 ) . '" text-anchor="end">' . esc_html( $short ) . '</text>';
			$svg .= '<rect x="' . $label_w . '" y="' . ( $y + 3 ) . '" width="' . $w . '" height="' . ( $row - 6 ) . '" fill="#2271b1" rx="2"></rect>';
			$svg .= '<text x="' . ( $label_w + $w + 6 ) . '" y="' . ( $y + 14 ) . '">' . esc_html( $format( $value ) ) . '</text></g>';
			$i++;
		}
		return $svg . '</svg>';
	}

	public static function render() {
		$scope = Data::scope();
		if ( false === $scope ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$filters = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		if ( ! self::filters_allowed( $filters, $scope ) ) { wp_die( esc_html__( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$report = Data::build( $filters, $scope );
		$cur = $report['currency'];
		$money = static function ( $minor ) use ( $cur ) { return Scope::money( $minor, $cur ); };
		$csv_url = add_query_arg( array_merge( $filters, array( 'action' => 'wpcap_reports_csv', '_wpnonce' => wp_create_nonce( 'wpcap_reports_csv' ) ) ), admin_url( 'admin-post.php' ) );
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Berichte', 'pass-captain' ) . '</h1>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><p>';
		echo '<label>' . esc_html__( 'Von', 'pass-captain' ) . ' <input type="date" name="from" value="' . esc_attr( $report['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'Bis', 'pass-captain' ) . ' <input type="date" name="to" value="' . esc_attr( $report['to'] ) . '"></label> ';
		Forms::select( 'event_id', array( 0 => __( 'Alle Events', 'pass-captain' ) ) + Queries::event_options( $scope, array( (int) $filters['event_id'] ) ), $filters['event_id'] );
		echo ' <button class="button">' . esc_html__( 'Anzeigen', 'pass-captain' ) . '</button> <a class="button" href="' . esc_url( $csv_url ) . '">' . esc_html__( 'CSV-Export', 'pass-captain' ) . '</a></p></form>' . Queries::event_options_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped note.
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Verkäufe nach Bestelldatum, Erstattungen nach Abschlussdatum (Zeitzone %s). Beträge brutto inkl. USt.', 'pass-captain' ), $report['timezone'] ) ) . '</p>';

		$t = $report['total'];
		echo '<table class="widefat" style="max-width:52rem"><tbody>';
		$summary = array(
			__( 'Verkaufte Tickets', 'pass-captain' ) => (string) $t['tickets'],
			__( 'Umsatz brutto', 'pass-captain' ) => $money( $t['gross'] ),
			__( 'Erstattungen', 'pass-captain' ) => '-' . $money( $t['refunded'] ) . ' (' . (int) $t['refunded_tickets'] . ' ' . __( 'Tickets', 'pass-captain' ) . ')',
			__( 'Umsatz nach Erstattungen', 'pass-captain' ) => $money( $t['revenue'] ),
			__( 'davon USt', 'pass-captain' ) => $money( $t['revenue_tax'] ),
			__( 'Rechnungskorrekturen (Belege)', 'pass-captain' ) => (int) $report['credit_notes']['count'] . ' / ' . $money( $report['credit_notes']['total'] ),
			// Guest list comps (GuestLists module): shown separately, not part of sales/revenue.
			__( 'Freikarten (nicht im Umsatz)', 'pass-captain' ) => (string) (int) ( $report['comps']['tickets'] ?? 0 ),
		);
		foreach ( $summary as $k => $v ) { echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>'; }
		echo '</tbody></table>';
		$unreconciled = Data::reconcile( $report );
		if ( $unreconciled ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( __( 'Die Summen des Berichts stimmen nicht überein:', 'pass-captain' ) . ' ' . implode( ', ', $unreconciled ) ) . '</p></div>';
		}
		if ( count( (array) $report['currencies'] ) > 1 ) {
			// Several currencies: the totals above mix them; the table per currency is authoritative.
			echo '<h2>' . esc_html__( 'Umsatz nach Währung', 'pass-captain' ) . '</h2><p class="description">' . esc_html__( 'Im Zeitraum wurde in mehreren Währungen verkauft. Die Gesamtsummen oben sind nicht addierbar; maßgeblich sind die Summen je Währung.', 'pass-captain' ) . '</p>';
			foreach ( $report['currencies'] as $cur_code => $cur_report ) {
				$cur_money = static function ( $minor ) use ( $cur_code ) { return Scope::money( $minor, $cur_code ); };
				self::table( __( 'Satz', 'pass-captain' ), array_merge( array_values( $cur_report['rates'] ), array( $cur_report['total'] ) ), $cur_money );
			}
		}

		$share = array();
		foreach ( $report['channels'] as $c ) { $share[ $c['label'] ] = $c['revenue']; }
		$total_rev = max( 1, array_sum( array_map( 'abs', $share ) ) );
		echo '<h2>' . esc_html__( 'Kasse vs. online', 'pass-captain' ) . '</h2>';
		echo self::bar_chart( $share, __( 'Umsatz nach Kanal', 'pass-captain' ), static function ( $v ) use ( $money, $total_rev ) { return $money( $v ) . ' (' . number_format_i18n( 100 * $v / $total_rev, 1 ) . ' %)'; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in bar_chart.

		$days = array();
		foreach ( $report['dimensions']['day'] as $b ) { $days[ $b['label'] ] = $b['revenue']; }
		echo '<h2>' . esc_html__( 'Umsatz pro Tag', 'pass-captain' ) . '</h2>' . self::bar_chart( $days, __( 'Umsatz nach Erstattungen pro Tag', 'pass-captain' ), $money ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$labels = array( 'event' => __( 'Event', 'pass-captain' ), 'occurrence' => __( 'Termin', 'pass-captain' ), 'ticket_type' => __( 'Ticketart', 'pass-captain' ), 'provider' => __( 'Zahlungsanbieter', 'pass-captain' ) );
		foreach ( $labels as $dim => $label ) {
			echo '<h2>' . esc_html( sprintf( /* translators: %s: grouping (e.g. ticket type, channel) */ __( 'Umsatz nach %s', 'pass-captain' ), $label ) ) . '</h2>';
			self::table( $label, $report['dimensions'][ $dim ], $money );
		}
		echo '<h2>' . esc_html__( 'Umsatzsteuer nach Satz', 'pass-captain' ) . '</h2>';
		self::table( __( 'Satz', 'pass-captain' ), $report['rates'], $money );

		echo '<h2>' . esc_html__( 'Auslastung und Check-in', 'pass-captain' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Termin', 'pass-captain' ) . '</th><th>' . esc_html__( 'Verkauft / Kapazität', 'pass-captain' ) . '</th><th>' . esc_html__( 'Eingecheckt / gültig', 'pass-captain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $report['occurrences'] as $o ) {
			$link = Forms::page_url( self::SLUG, array_merge( $filters, array( 'occurrence_id' => $o['occurrence_id'] ) ) );
			echo '<tr><td>' . esc_html( $o['label'] ) . '</td><td>' . esc_html( $o['sold'] . ( ! empty( $o['comps'] ) ? ' + ' . (int) $o['comps'] . ' ' . __( 'Freikarten', 'pass-captain' ) : '' ) . ' / ' . ( $o['capacity'] ?: '∞' ) . ( null !== $o['utilisation'] ? ' (' . number_format_i18n( $o['utilisation'], 1 ) . ' %)' : '' ) ) . '</td><td>' . esc_html( $o['checked_in'] . ' / ' . $o['issued'] . ( null !== $o['checkin_rate'] ? ' (' . number_format_i18n( $o['checkin_rate'], 1 ) . ' %)' : '' ) ) . '</td><td><a href="' . esc_url( $link ) . '">' . esc_html__( 'Einlass pro Stunde', 'pass-captain' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		if ( $report['histogram'] ) {
			$hist = array();
			foreach ( $report['histogram'] as $hour => $n ) { $hist[ substr( $hour, 5 ) ] = $n; }
			echo '<h2>' . esc_html__( 'Check-ins pro Stunde', 'pass-captain' ) . '</h2>' . self::bar_chart( $hist, $report['occurrences'][ $report['histogram_occurrence'] ]['label'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		self::settlement_form( $filters['occurrence_id'] );
		if ( current_user_can( 'manage_options' ) || current_user_can( 'wpcap_view_finance' ) ) {
			// Accounting export (Invoices::handle_export()) for the same local date range.
			$export_url = add_query_arg( array( 'action' => 'wpcap_export_accounting', 'from' => $report['from'], 'to' => $report['to'], '_wpnonce' => wp_create_nonce( 'wpcap_export_accounting' ) ), admin_url( 'admin-post.php' ) );
			echo '<h2>' . esc_html__( 'Buchhaltungsexport', 'pass-captain' ) . '</h2><p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html( sprintf( /* translators: 1: from, 2: to */ __( 'Buchhaltungsexport %1$s bis %2$s (CSV)', 'pass-captain' ), $report['from'], $report['to'] ) ) . '</a></p>';
			$datev_url = add_query_arg( 'format', 'datev', $export_url );
			echo '<p><a class="button" href="' . esc_url( $datev_url ) . '">' . esc_html( sprintf( /* translators: 1: from, 2: to */ __( 'Buchungsstapel %1$s bis %2$s (DATEV-Format, CSV)', 'pass-captain' ), $report['from'], $report['to'] ) ) . '</a></p>';
			echo '<p class="description">' . esc_html__( 'Rechnungen, Rechnungskorrekturen sowie Verkäufe und Erstattungen ohne Rechnung (z. B. Kasse, Partner-API, automatische Rechnungen aus). Tagesgrenzen in der Zeitzone der Website.', 'pass-captain' ) . '</p>';
			$gaps = \PassCaptain\Invoices::document_gaps( $report['from'], $report['to'] );
			if ( $gaps ) {
				$gap_text = array();
				foreach ( $gaps as $range => $missing ) { $gap_text[] = $range . ': ' . implode( ', ', array_slice( $missing, 0, 10 ) ) . ( count( $missing ) > 10 ? ' …' : '' ); }
				echo '<div class="notice notice-warning inline"><p>' . esc_html( __( 'Lücken in den Belegnummern:', 'pass-captain' ) . ' ' . implode( '; ', $gap_text ) ) . '</p></div>';
			}
		}
		echo '</div>';
	}

	/** Form for the settlement sheet per occurrence (Settlement; opens the print view or the CSV). */
	private static function settlement_form( $selected ) {
		$options = Settlement::occurrence_options();
		echo '<h2 id="wpcap-settlement">' . esc_html__( 'Abrechnungsblatt pro Termin (Door Deal)', 'pass-captain' ) . '</h2>';
		if ( ! $options ) { echo '<p>' . esc_html__( 'Keine Termine im Zugriff.', 'pass-captain' ) . '</p>'; return; }
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank"><input type="hidden" name="action" value="' . esc_attr( Settlement::ACTION ) . '">';
		wp_nonce_field( Settlement::ACTION );
		echo '<p><label for="wpcap-settlement-occ">' . esc_html__( 'Termin', 'pass-captain' ) . '</label> <select id="wpcap-settlement-occ" name="occurrence_id">';
		foreach ( $options as $id => $label ) { echo '<option value="' . (int) $id . '"' . selected( (int) $selected, (int) $id, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></p><p><label for="wpcap-settlement-fixed">' . esc_html__( 'Fixbetrag', 'pass-captain' ) . '</label> <input id="wpcap-settlement-fixed" type="text" inputmode="decimal" name="fixed" size="10" placeholder="0,00" aria-describedby="wpcap-settlement-help"> ';
		echo '<label for="wpcap-settlement-share">' . esc_html__( 'Anteil %', 'pass-captain' ) . '</label> <input id="wpcap-settlement-share" type="text" inputmode="decimal" name="share" size="6" placeholder="0" aria-describedby="wpcap-settlement-help"> ';
		echo '<button class="button" name="format" value="html">' . esc_html__( 'Druckansicht', 'pass-captain' ) . '</button> <button class="button" name="format" value="csv">' . esc_html__( 'CSV-Export', 'pass-captain' ) . '</button></p>';
		echo '<p class="description" id="wpcap-settlement-help">' . esc_html__( 'Alle Verkäufe und Erstattungen des Termins nach Ticketart und Kanal, Freikarten, USt je Satz, Kasse nach Zahlungsart, Check-ins und No-Shows. Deal: vom Netto-Umsatz nach Erstattungen wird zuerst der Fixbetrag abgezogen, vom Rest erhält der Künstler/Veranstalter den Anteil. Die Deal-Werte werden nicht gespeichert.', 'pass-captain' ) . '</p></form>';
	}

	private static function table( $label, array $buckets, callable $money ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( $label ) . '</th><th>' . esc_html__( 'Tickets', 'pass-captain' ) . '</th><th>' . esc_html__( 'Brutto', 'pass-captain' ) . '</th><th>' . esc_html__( 'Netto', 'pass-captain' ) . '</th><th>' . esc_html__( 'USt', 'pass-captain' ) . '</th><th>' . esc_html__( 'Erstattet', 'pass-captain' ) . '</th><th>' . esc_html__( 'Umsatz nach Erstattung', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $buckets as $b ) {
			echo '<tr><td>' . esc_html( $b['label'] ) . '</td><td>' . (int) $b['tickets'] . '</td><td>' . esc_html( $money( $b['gross'] ) ) . '</td><td>' . esc_html( $money( $b['net'] ) ) . '</td><td>' . esc_html( $money( $b['tax'] ) ) . '</td><td>' . esc_html( '-' . $money( $b['refunded'] ) ) . '</td><td>' . esc_html( $money( $b['revenue'] ) ) . '</td></tr>';
		}
		if ( ! $buckets ) { echo '<tr><td colspan="7">' . esc_html__( 'Keine Daten im Zeitraum.', 'pass-captain' ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	public static function handle_csv() {
		$scope = Data::scope();
		if ( false === $scope ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		check_admin_referer( 'wpcap_reports_csv' );
		$filters = self::filters( wp_unslash( $_GET ) );
		if ( ! self::filters_allowed( $filters, $scope ) ) { wp_die( esc_html__( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), 403 ); }
		$report = Data::build( $filters, $scope );
		Audit::record( 'report_exported', 'report', 0, array( 'from' => $report['from'], 'to' => $report['to'], 'event_id' => $filters['event_id'] ) );
		Scope::send_download( Scope::csv( Data::csv_rows( $report ) ), 'pass-captain-bericht-' . $report['from'] . '-' . $report['to'] . '.csv', 'text/csv; charset=utf-8' );
	}
}
