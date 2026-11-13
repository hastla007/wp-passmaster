<?php
/**
 * "Betrieb" admin page (A163): state of the background queues (jobs, e-mails, webhook deliveries) with
 * counts by status, backlog and age of the oldest due item, dead-letter/failed items without payload
 * values, and the actions "erneut versuchen" / "verwerfen" for single items and in bulk (nonce,
 * capability manage_options, audited). Also shows the Site Health results of the plugin (A164) and the data
 * diagnosis (A204, Operations\Doctor): checks on request, repair preview and audited repairs.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Operations\Doctor;
use PassCaptain\Operations\Queues;
use PassCaptain\Operations\SiteHealth;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OperationsPage {
	const SLUG = 'wpcap-operations';
	const CAP = 'manage_options';
	const ACTION = 'wpcap_operations_action';
	/** admin-post action of a diagnosis repair. */
	const DOCTOR_ACTION = 'wpcap_doctor_repair';
	const PER_QUEUE = 50;

	/**
	 * Applies a retry/discard request. Input: queue, do (retry|discard), ids[] (checkboxes), all=1
	 * (every failed item of the queue, at most Queues::MAX_BULK) or row_action "retry:ID"/"discard:ID"
	 * (button of a single row, takes precedence).
	 *
	 * @return array|\WP_Error ['queue', 'do', 'count'].
	 */
	public static function admin_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$queue = sanitize_key( (string) ( $in['queue'] ?? '' ) );
		if ( ! isset( Queues::available()[ $queue ] ) ) {
			return Forms::error( 'wpcap_operations_queue', __( 'Unbekannte Warteschlange.', 'pass-captain' ), 404 );
		}
		$do = sanitize_key( (string) ( $in['do'] ?? '' ) );
		$ids = array_map( 'absint', (array) ( $in['ids'] ?? array() ) );
		if ( ! empty( $in['row_action'] ) && preg_match( '/^(retry|discard):(\d+)$/', (string) $in['row_action'], $m ) ) {
			$do = $m[1];
			$ids = array( (int) $m[2] );
		} elseif ( ! empty( $in['all'] ) ) {
			$ids = Queues::failed_ids( $queue );
		}
		if ( 'retry' === $do ) {
			$count = Queues::retry( $queue, $ids );
		} elseif ( 'discard' === $do ) {
			$count = Queues::discard( $queue, $ids );
		} else {
			return Forms::error( 'wpcap_operations_action', __( 'Bitte eine Aktion wählen.', 'pass-captain' ) );
		}
		if ( is_wp_error( $count ) ) { return $count; }
		delete_transient( SiteHealth::NOTICE_TRANSIENT );
		return array( 'queue' => $queue, 'do' => $do, 'count' => (int) $count );
	}

	/** admin-post handler. */
	public static function handle_action() {
		Forms::verify( self::ACTION );
		$result = self::admin_action( Forms::post() );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			$message = 'retry' === $result['do']
				/* translators: %d: number of items */
				? sprintf( _n( '%d Eintrag wurde erneut eingeplant; die Verarbeitung ist angestoßen.', '%d Einträge wurden erneut eingeplant; die Verarbeitung ist angestoßen.', $result['count'], 'pass-captain' ), $result['count'] )
				/* translators: %d: number of items */
				: sprintf( _n( '%d Eintrag wurde verworfen.', '%d Einträge wurden verworfen.', $result['count'], 'pass-captain' ), $result['count'] );
		}
		Forms::finish( $result, $message, Forms::page_url( self::SLUG ) );
	}

	/**
	 * Executes a diagnosis repair. Input: repair (id), keys (finding keys, optional; comma list or array),
	 * confirm=1 (without it nothing is changed and the preview is returned).
	 *
	 * @return array|\WP_Error See Doctor::repair().
	 */
	public static function doctor_action( array $in ) {
		if ( ! current_user_can( Doctor::CAP ) ) { return Forms::forbidden(); }
		$keys = $in['keys'] ?? array();
		$keys = is_array( $keys ) ? $keys : explode( ',', (string) $keys );
		return Doctor::repair( (string) ( $in['repair'] ?? '' ), empty( $in['confirm'] ), array_map( 'trim', array_map( 'strval', $keys ) ), 'admin' );
	}

	/** admin-post handler of a diagnosis repair. */
	public static function handle_doctor() {
		Forms::verify( self::DOCTOR_ACTION );
		$result = self::doctor_action( Forms::post() );
		$message = '';
		if ( ! is_wp_error( $result ) ) {
			$message = $result['dry_run']
				/* translators: %d: number of objects */
				? sprintf( _n( 'Vorschau: %d Objekt würde geändert.', 'Vorschau: %d Objekte würden geändert.', $result['count'], 'pass-captain' ), $result['count'] )
				/* translators: %d: number of objects */
				: sprintf( _n( '%d Objekt wurde repariert (im Audit-Log protokolliert).', '%d Objekte wurden repariert (im Audit-Log protokolliert).', $result['count'], 'pass-captain' ), $result['count'] );
		}
		Forms::finish( $result, $message, Forms::page_url( self::SLUG, array( 'wpcap_doctor' => 1 ) ) . '#wpcap-doctor' );
	}

	/** "3 h 12 min" style age. */
	public static function age( $seconds ) {
		$minutes = (int) floor( max( 0, (int) $seconds ) / 60 );
		if ( $minutes < 60 ) {
			/* translators: %d: minutes */
			return sprintf( __( '%d min', 'pass-captain' ), $minutes );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$d h %2$d min', 'pass-captain' ), intdiv( $minutes, 60 ), $minutes % 60 );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Betrieb', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		echo '<p class="description">' . esc_html__( 'Zustand der Hintergrundverarbeitung: Hier siehst du Rückstände und endgültig fehlgeschlagene Einträge und kannst sie erneut versuchen oder verwerfen. Inhalte werden nicht angezeigt, nur die Namen der enthaltenen Felder.', 'pass-captain' ) . '</p>';
		self::render_health();
		self::render_doctor();
		$labels = Queues::labels();
		foreach ( Queues::available() as $queue => $def ) {
			self::render_queue( $queue, $labels[ $queue ] ?? $queue, $def );
		}
		echo '</div>';
	}

	private static function render_health() {
		$results = SiteHealth::run_all();
		delete_transient( SiteHealth::NOTICE_TRANSIENT );
		echo '<h2>' . esc_html__( 'Überwachung', 'pass-captain' ) . '</h2><table class="widefat striped" style="max-width:60em"><tbody>';
		$names = array(
			'good'        => __( 'in Ordnung', 'pass-captain' ),
			'recommended' => __( 'empfohlen', 'pass-captain' ),
			'critical'    => __( 'kritisch', 'pass-captain' ),
		);
		foreach ( $results as $result ) {
			$color = 'critical' === $result['status'] ? '#b32d2e' : ( 'recommended' === $result['status'] ? '#996800' : '#007017' );
			echo '<tr><td style="width:9em"><strong style="color:' . esc_attr( $color ) . '">' . esc_html( $names[ $result['status'] ] ?? $result['status'] ) . '</strong></td><td><strong>' . esc_html( $result['label'] ) . '</strong>' . wp_kses_post( $result['description'] ) . '</td></tr>';
		}
		echo '</tbody></table><p><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Alle Prüfungen im Website-Zustand ansehen', 'pass-captain' ) . '</a></p>';
	}

	/**
	 * Section "Datenkonsistenz": the checks run only on request (?wpcap_doctor=1), a repair preview with
	 * ?wpcap_doctor_preview=<repair>; repairs are POST requests with nonce and confirmation.
	 */
	public static function render_doctor() {
		echo '<h2 id="wpcap-doctor">' . esc_html__( 'Datenkonsistenz', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Sucht nach widersprüchlichen Daten (Tickets, Bestellungen, Erstattungen, Reservierungen, Warteschlangen, Zähler, Nummernkreise, Servicebuchungen, Audit-Log). Die Prüfung ändert nichts; Reparaturen gibt es nur, wo das Ergebnis eindeutig ist, mit Vorschau und Eintrag im Audit-Log. Dasselbe per WP-CLI: wp pass-captain doctor', 'pass-captain' ) . '</p>';
		$run = ! empty( $_GET['wpcap_doctor'] ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		if ( ! $run ) {
			echo '<p><a class="button" href="' . esc_url( Forms::page_url( self::SLUG, array( 'wpcap_doctor' => 1 ) ) . '#wpcap-doctor' ) . '">' . esc_html__( 'Prüfung ausführen', 'pass-captain' ) . '</a></p>';
			return;
		}
		$preview = isset( $_GET['wpcap_doctor_preview'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_doctor_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only preview.
		$repairs = Doctor::repairs();
		$report = Doctor::run();
		$names = array(
			'critical' => __( 'kritisch', 'pass-captain' ),
			'warning'  => __( 'Warnung', 'pass-captain' ),
		);
		echo '<table class="widefat striped wpcap-doctor"><thead><tr><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Prüfung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Anzahl', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Beispiele und Hinweis', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Reparatur', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $report as $check ) {
			$color = ! $check['count'] ? '#007017' : ( 'critical' === $check['severity'] ? '#b32d2e' : '#996800' );
			$status = $check['count'] ? ( $names[ $check['severity'] ] ?? $check['severity'] ) : __( 'in Ordnung', 'pass-captain' );
			echo '<tr data-check="' . esc_attr( $check['id'] ) . '"><td><strong style="color:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</strong></td><td>' . esc_html( $check['label'] ) . '</td><td>' . esc_html( number_format_i18n( $check['count'] ) ) . '</td><td>';
			if ( $check['count'] ) {
				echo '<ul style="margin:0">';
				foreach ( array_slice( $check['items'], 0, 10 ) as $item ) { echo '<li><code>' . esc_html( $item['key'] ) . '</code> ' . esc_html( $item['detail'] ) . '</li>'; }
				echo '</ul><p class="description">' . esc_html( $check['hint'] ) . '</p>';
			} else {
				echo '–';
			}
			echo '</td><td>';
			if ( $check['count'] && '' !== $check['repair'] && isset( $repairs[ $check['repair'] ] ) ) {
				self::render_repair( $check['repair'], $repairs[ $check['repair'] ]['label'], $preview === $check['repair'] );
			} elseif ( $check['count'] ) {
				echo esc_html__( 'manuell prüfen', 'pass-captain' );
			} else {
				echo '–';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_repair( $repair, $label, $show_preview ) {
		if ( ! $show_preview ) {
			echo '<a class="button button-small" href="' . esc_url( Forms::page_url( self::SLUG, array( 'wpcap_doctor' => 1, 'wpcap_doctor_preview' => $repair ) ) . '#wpcap-doctor' ) . '">' . esc_html__( 'Vorschau', 'pass-captain' ) . '</a>';
			return;
		}
		$preview = Doctor::repair( $repair, true, array(), 'admin' );
		$keys = is_wp_error( $preview ) ? array() : $preview['keys'];
		/* translators: 1: repair name, 2: number of objects */
		echo '<p>' . esc_html( sprintf( __( '%1$s – Vorschau: %2$d Objekte würden geändert.', 'pass-captain' ), $label, count( $keys ) ) ) . '</p>';
		if ( $keys ) {
			echo '<p class="description"><code>' . esc_html( implode( ', ', array_slice( $keys, 0, 30 ) ) ) . ( count( $keys ) > 30 ? ' …' : '' ) . '</code></p>';
		}
		Forms::open_form( self::DOCTOR_ACTION );
		Forms::hidden( 'repair', $repair );
		Forms::hidden( 'confirm', '1' );
		echo '<button type="submit" class="button button-primary button-small" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Reparatur jetzt ausführen? Die Änderung wird im Audit-Log protokolliert.', 'pass-captain' ) ) ) . ')">' . esc_html( $label ) . '</button></form>';
	}

	private static function render_queue( $queue, $label, array $def ) {
		$stats = Queues::stats( $queue );
		$items = Queues::failed_items( $queue, self::PER_QUEUE );
		echo '<h2 id="' . esc_attr( 'wpcap-queue-' . $queue ) . '">' . esc_html( $label ) . '</h2>';
		$counts = array();
		foreach ( $stats['counts'] as $status => $n ) { $counts[] = $status . ': ' . number_format_i18n( $n ); }
		echo '<p>' . esc_html__( 'Einträge nach Status:', 'pass-captain' ) . ' <code>' . esc_html( $counts ? implode( ', ', $counts ) : '–' ) . '</code><br>';
		/* translators: %s: number of due items */
		echo esc_html( sprintf( __( 'Fällig und wartend: %s', 'pass-captain' ), number_format_i18n( $stats['due'] ) ) );
		if ( $stats['due'] ) {
			/* translators: %s: age, e.g. "1 h 5 min" */
			echo ' · ' . esc_html( sprintf( __( 'ältester fälliger Eintrag wartet seit %s', 'pass-captain' ), self::age( $stats['oldest_due_seconds'] ) ) );
		}
		echo '</p>';
		if ( ! $items['rows'] ) {
			echo '<p>' . esc_html__( 'Keine fehlgeschlagenen Einträge.', 'pass-captain' ) . '</p>';
			return;
		}
		Forms::open_form( self::ACTION );
		Forms::hidden( 'queue', $queue );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Auswahl', 'pass-captain' ) . '</span></td><th scope="col">' . esc_html__( 'ID', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Typ', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Versuche', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Letzter Fehler', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Inhalt (nur Feldnamen)', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Erstellt', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $items['rows'] as $item ) {
			/* translators: %d: item id */
			$select = sprintf( __( 'Eintrag %d auswählen', 'pass-captain' ), $item['id'] );
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( (string) $item['id'] ) . '" aria-label="' . esc_attr( $select ) . '"></th>';
			echo '<td>' . esc_html( (string) $item['id'] ) . '</td><td><code>' . esc_html( $item['type'] ) . '</code>' . ( '' !== $item['detail'] ? '<br><span class="description">' . esc_html( $item['detail'] ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) $item['attempts'] ) . '</td><td>' . esc_html( '' !== $item['last_error'] ? $item['last_error'] : '–' ) . '</td>';
			echo '<td><code>' . esc_html( $item['payload_keys'] ? implode( ', ', $item['payload_keys'] ) : '–' ) . '</code></td>';
			echo '<td>' . esc_html( Forms::datetime( $item['created_at'], wp_timezone_string(), false ) ) . '</td>';
			echo '<td><button type="submit" class="button button-small" name="row_action" value="' . esc_attr( 'retry:' . $item['id'] ) . '">' . esc_html__( 'Erneut versuchen', 'pass-captain' ) . '</button> ';
			echo '<button type="submit" class="button button-small button-link-delete" name="row_action" value="' . esc_attr( 'discard:' . $item['id'] ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Diesen Eintrag endgültig verwerfen?', 'pass-captain' ) ) ) . ')">' . esc_html__( 'Verwerfen', 'pass-captain' ) . '</button></td></tr>';
		}
		echo '</tbody></table>';
		if ( $items['total'] > count( $items['rows'] ) ) {
			/* translators: 1: shown items, 2: total failed items */
			echo '<p class="description">' . esc_html( sprintf( __( 'Angezeigt werden die neuesten %1$d von %2$d fehlgeschlagenen Einträgen.', 'pass-captain' ), count( $items['rows'] ), $items['total'] ) ) . '</p>';
		}
		$select_id = 'wpcap-bulk-' . $queue;
		echo '<p><label for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Mehrfachaktion', 'pass-captain' ) . '</label> ';
		Forms::select( 'do', array( 'retry' => __( 'Erneut versuchen', 'pass-captain' ), 'discard' => __( 'Verwerfen', 'pass-captain' ) ), 'retry', $select_id );
		echo ' <label><input type="checkbox" name="all" value="1"> ';
		/* translators: %d: maximum number of items */
		echo esc_html( sprintf( __( 'alle fehlgeschlagenen Einträge dieser Warteschlange (höchstens %d)', 'pass-captain' ), Queues::MAX_BULK ) ) . '</label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Ausführen', 'pass-captain' ) . '</button></p></form>';
	}
}
