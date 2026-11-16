<?php
/**
 * "Systemstatus": queue backlogs and failures (jobs, e-mails, webhooks, SMS), WP-Cron health with
 * worker heartbeats, refunds that need a manual decision, schema upgrade state and the
 * diagnose/reconciliation checks. Every action needs manage_options and a nonce and is audited
 * (bulk "retry all" per queue is capped per click). Also prints an admin notice on plugin pages and
 * the dashboard while the backlog is critical, and adds a dashboard widget with the critical issues.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Diagnostics;
use PassCaptain\Installer;
use PassCaptain\Ops\Heartbeat;
use PassCaptain\Ops\Status;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class StatusPage {
	const SLUG = 'wpcap-status';
	const CAP = 'manage_options';

	/** admin-post action => handler (merged into Menu::handlers()). */
	public static function handlers() {
		return array(
			'wpcap_ops_queue_action'   => array( self::class, 'handle_queue_action' ),
			'wpcap_ops_queue_bulk_retry' => array( self::class, 'handle_queue_bulk_retry' ),
			'wpcap_ops_diagnose'       => array( self::class, 'handle_diagnose' ),
			'wpcap_ops_repair'         => array( self::class, 'handle_repair' ),
			'wpcap_ops_refund_resolve' => array( self::class, 'handle_refund_resolve' ),
			'wpcap_ops_schema_retry'   => array( self::class, 'handle_schema_retry' ),
			'wpcap_ops_payment_case'   => array( self::class, 'handle_payment_case' ),
		);
	}

	/** Hooks of the page that are not admin-post handlers (called from Menu::register()). */
	public static function register() {
		add_action( 'admin_notices', array( self::class, 'render_backlog_notice' ) );
		add_action( 'wp_dashboard_setup', array( self::class, 'register_dashboard_widget' ) );
	}

	/** Dashboard widget with the critical issues (users with the status capability only). */
	public static function register_dashboard_widget() {
		if ( ! current_user_can( self::CAP ) || ! function_exists( 'wp_add_dashboard_widget' ) ) { return; }
		wp_add_dashboard_widget( 'wpcap_status_widget', __( 'Pass Captain: Systemstatus', 'pass-captain' ), array( self::class, 'render_dashboard_widget' ) );
	}

	/** Widget body: critical issues (cached like the menu bubble) and a link to the status page. */
	public static function render_dashboard_widget() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$issues = Status::critical_issues();
		if ( ! $issues ) {
			echo '<p>' . esc_html__( 'Keine kritischen Probleme im Hintergrundbetrieb.', 'pass-captain' ) . '</p>';
		} else {
			/* translators: %d: number of critical issues */
			echo '<p><strong>' . esc_html( sprintf( _n( '%d kritisches Problem', '%d kritische Probleme', count( $issues ), 'pass-captain' ), count( $issues ) ) ) . '</strong></p><ul>';
			foreach ( array_slice( $issues, 0, 5 ) as $issue ) { echo '<li>' . esc_html( $issue['message'] ) . '</li>'; }
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Systemstatus öffnen', 'pass-captain' ) . '</a></p>';
	}

	/** Menu title with a bubble counting critical issues. */
	public static function menu_title() {
		$count = current_user_can( self::CAP ) ? count( Status::critical_issues() ) : 0;
		return __( 'Systemstatus', 'pass-captain' ) . ( $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '' );
	}

	private static function url( array $args = array() ) { return Forms::page_url( self::SLUG, $args ); }

	/* =============================================================================================
	 * Actions (logic returns a result or WP_Error; handle_* adds nonce check and redirect)
	 * =========================================================================================== */

	/** Retry or discard a failed queue item. */
	public static function queue_action( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$queue = Forms::choice( $in, 'queue', array_keys( Status::queues() ), '' );
		$op = Forms::choice( $in, 'op', array( 'retry', 'discard' ), '' );
		if ( '' === $queue || '' === $op ) { return Forms::error( 'wpcap_ops_invalid', __( 'Ungültige Aktion.', 'pass-captain' ) ); }
		return 'retry' === $op ? Status::retry( $queue, Forms::int( $in, 'id' ) ) : Status::discard( $queue, Forms::int( $in, 'id' ) );
	}

	/** Retry all failed items of one queue (at most Status::bulk_retry_cap() per click). */
	public static function queue_bulk_retry( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$queue = Forms::choice( $in, 'queue', array_keys( Status::queues() ), '' );
		if ( '' === $queue ) { return Forms::error( 'wpcap_ops_invalid', __( 'Ungültige Aktion.', 'pass-captain' ) ); }
		return Status::retry_all( $queue );
	}

	public static function handle_queue_bulk_retry() {
		$in = Forms::post();
		Forms::verify( 'wpcap_ops_queue_bulk_' . sanitize_key( (string) ( $in['queue'] ?? '' ) ) );
		$result = self::queue_bulk_retry( $in );
		$message = is_wp_error( $result ) ? '' : ( $result['remaining']
			/* translators: 1: requeued items, 2: items still failed */
			? sprintf( __( '%1$d Einträge werden erneut versucht; %2$d weitere fehlgeschlagene Einträge bitte mit einem weiteren Klick erneut versuchen.', 'pass-captain' ), $result['retried'], $result['remaining'] )
			/* translators: %d: requeued items */
			: sprintf( __( '%d Einträge werden erneut versucht.', 'pass-captain' ), $result['retried'] ) );
		Forms::finish( $result, $message, self::url() . '#wpcap-failed' );
	}

	public static function handle_queue_action() {
		$in = Forms::post();
		Forms::verify( 'wpcap_ops_queue_' . sanitize_key( (string) ( $in['queue'] ?? '' ) ) . '_' . Forms::int( $in, 'id' ) );
		$retry = 'retry' === ( $in['op'] ?? '' );
		Forms::finish( self::queue_action( $in ), $retry ? __( 'Eintrag wird erneut versucht.', 'pass-captain' ) : __( 'Eintrag verworfen.', 'pass-captain' ), self::url() . '#wpcap-failed' );
	}

	/** Runs all diagnostics and stores the report for the page. */
	public static function diagnose( array $in = array() ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		return Diagnostics::run_and_store();
	}

	public static function handle_diagnose() {
		Forms::verify( 'wpcap_ops_diagnose' );
		Forms::finish( self::diagnose(), __( 'Diagnose ausgeführt.', 'pass-captain' ), self::url() . '#wpcap-diagnostics' );
	}

	/** Runs the idempotent repair of one check and refreshes the stored report. */
	public static function repair( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$key = Forms::choice( $in, 'check', array_keys( Diagnostics::checks() ), '' );
		$result = Diagnostics::repair( $key );
		if ( ! is_wp_error( $result ) ) { Diagnostics::run_and_store(); }
		return $result;
	}

	public static function handle_repair() {
		$in = Forms::post();
		Forms::verify( 'wpcap_ops_repair_' . sanitize_key( (string) ( $in['check'] ?? '' ) ) );
		$result = self::repair( $in );
		/* translators: %d: number of repaired records */
		$message = is_wp_error( $result ) ? '' : sprintf( __( 'Reparatur ausgeführt: %d Einträge bearbeitet.', 'pass-captain' ), (int) $result['repaired'] );
		Forms::finish( $result, $message, self::url() . '#wpcap-diagnostics' );
	}

	/** Manual decision for a refund stuck in processing without provider reference. */
	public static function resolve_refund( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		return Diagnostics::resolve_stuck_refund( Forms::int( $in, 'refund_id' ), Forms::choice( $in, 'resolution', array( 'completed', 'failed' ), '' ), Forms::text( $in, 'reference', 150 ) );
	}

	/** Payment case (Payments\Cases): refund at the provider or mark as resolved. */
	public static function payment_case( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$case_id = Forms::int( $in, 'case_id' );
		$action = Forms::choice( $in, 'case_action', array( 'refund', 'resolve' ), '' );
		if ( 'refund' === $action ) { return \PassCaptain\Payments\Cases::refund( $case_id ); }
		if ( 'resolve' === $action ) { return \PassCaptain\Payments\Cases::resolve( $case_id, Forms::text( $in, 'note', 200 ) ); }
		return Forms::error( 'wpcap_payment_case_action', __( 'Unbekannte Aktion.', 'pass-captain' ) );
	}

	public static function handle_payment_case() {
		$in = Forms::post();
		Forms::verify( 'wpcap_ops_case_' . Forms::int( $in, 'case_id' ) );
		Forms::finish( self::payment_case( $in ), __( 'Fall aktualisiert.', 'pass-captain' ), self::url() . '#wpcap-payment-cases' );
	}

	public static function handle_refund_resolve() {
		$in = Forms::post();
		Forms::verify( 'wpcap_ops_refund_' . Forms::int( $in, 'refund_id' ) );
		Forms::finish( self::resolve_refund( $in ), __( 'Erstattung manuell abgeschlossen.', 'pass-captain' ), self::url() . '#wpcap-refunds' );
	}

	/** "Jetzt erneut versuchen" for a failed schema upgrade (ignores the backoff). */
	public static function schema_retry( array $in = array() ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		if ( 0 === Diagnostics::run( array( 'schema' ) )['checks']['schema']['count'] ) {
			return Forms::error( 'wpcap_ops_schema_ok', __( 'Das Datenbankschema ist aktuell.', 'pass-captain' ) );
		}
		return Diagnostics::repair( 'schema' );
	}

	public static function handle_schema_retry() {
		Forms::verify( 'wpcap_ops_schema_retry' );
		Forms::finish( self::schema_retry(), __( 'Datenbank-Aktualisierung erfolgreich.', 'pass-captain' ), self::url() . '#wpcap-schema' );
	}

	/* =============================================================================================
	 * Admin notice
	 * =========================================================================================== */

	/** Whether the current admin screen is a plugin page or the dashboard. */
	private static function notice_screen() {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		if ( self::SLUG === $page ) { return false; } // The page itself lists everything.
		return str_starts_with( $page, 'wpcap' ) || Menu::SLUG === $page || ( '' === $page && 'index.php' === $pagenow );
	}

	public static function render_backlog_notice() {
		if ( ! current_user_can( self::CAP ) || ! self::notice_screen() ) { return; }
		$issues = Status::critical_issues();
		if ( ! $issues ) { return; }
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pass Captain: Der Hintergrundbetrieb braucht Aufmerksamkeit.', 'pass-captain' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
		foreach ( array_slice( $issues, 0, 5 ) as $issue ) { echo '<li>' . esc_html( $issue['message'] ) . '</li>'; }
		echo '</ul><p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Systemstatus öffnen', 'pass-captain' ) . '</a></p></div>';
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	private static function time( $utc ) {
		if ( ! $utc ) { return '–'; }
		return esc_html( get_date_from_gmt( is_numeric( $utc ) ? gmdate( 'Y-m-d H:i:s', (int) $utc ) : (string) $utc, 'Y-m-d H:i' ) );
	}

	private static function badge( $state ) {
		$labels = array( 'ok' => __( 'OK', 'pass-captain' ), 'warning' => __( 'Warnung', 'pass-captain' ), 'error' => __( 'Fehler', 'pass-captain' ) );
		$colors = array( 'ok' => '#007017', 'warning' => '#996800', 'error' => '#b32d2e' );
		$state = isset( $labels[ $state ] ) ? $state : 'warning';
		return '<strong style="color:' . esc_attr( $colors[ $state ] ) . '">' . esc_html( $labels[ $state ] ) . '</strong>';
	}

	/** Masks the local part of an e-mail address (status page shows no full recipient list). */
	private static function mask_email( $email ) {
		$parts = explode( '@', (string) $email, 2 );
		return 2 === count( $parts ) ? substr( $parts[0], 0, 2 ) . '…@' . $parts[1] : '';
	}

	private static function action_button( $action, $nonce, array $fields, $label, $class = 'button button-small', $confirm = '' ) {
		Forms::open_form( $action, $nonce, 'style="display:inline"' . ( $confirm ? ' data-wpcap-confirm="' . esc_attr( $confirm ) . '"' : '' ) );
		foreach ( $fields as $name => $value ) { Forms::hidden( $name, $value ); }
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		echo '<div class="wrap wpcap-admin"><h1>' . esc_html__( 'Systemstatus', 'pass-captain' ) . '</h1>';
		Forms::render_notices();
		self::render_issues();
		self::render_cron();
		self::render_queues();
		self::render_failed();
		self::render_refunds();
		self::render_payment_cases();
		self::render_schema();
		self::render_diagnostics();
		\PassCaptain\Integrations\WorkflowCaptain\Status::render();
		echo '</div>';
	}

	private static function render_issues() {
		$issues = Status::issues();
		Status::flush_issues(); // The notice uses the fresh state after viewing this page.
		if ( ! $issues ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Keine Auffälligkeiten im Hintergrundbetrieb.', 'pass-captain' ) . '</p></div>';
			return;
		}
		foreach ( $issues as $issue ) {
			echo '<div class="notice notice-' . ( 'error' === $issue['severity'] ? 'error' : 'warning' ) . ' inline"><p>' . esc_html( $issue['message'] ) . '</p></div>';
		}
	}

	private static function render_cron() {
		echo '<h2 id="wpcap-cron">' . esc_html__( 'WP-Cron und Worker', 'pass-captain' ) . '</h2>';
		if ( Status::cron_disabled() ) {
			echo '<p>' . esc_html__( 'WP-Cron ist deaktiviert (DISABLE_WP_CRON). Ein externer Cron-Job muss wp-cron.php regelmäßig (mindestens alle 5 Minuten) aufrufen.', 'pass-captain' ) . '</p>';
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Aufgabe', 'pass-captain' ) . '</th><th>' . esc_html__( 'Cron-Hook', 'pass-captain' ) . '</th><th>' . esc_html__( 'Nächster Lauf', 'pass-captain' ) . '</th><th>' . esc_html__( 'Letzter erfolgreicher Lauf', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( Status::cron_health() as $row ) {
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td><code>' . esc_html( $row['hook'] ) . '</code></td><td>' . self::time( $row['next_run'] ) . '</td><td>' . self::time( $row['last_beat'] ) . ( $row['last_beat'] ? ' (' . (int) $row['processed'] . ')' : '' ) . '</td><td>' . self::badge( $row['state'] ) . ( $row['message'] ? '<br>' . esc_html( $row['message'] ) : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped helpers.
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Weitere Worker:', 'pass-captain' ) . ' ';
		$parts = array();
		foreach ( array( 'webhooks', 'sms' ) as $worker ) {
			$last = Heartbeat::last( $worker );
			$parts[] = esc_html( Heartbeat::workers()[ $worker ][0] ) . ': ' . ( $last ? self::time( $last['time'] ) : esc_html__( 'noch kein Lauf', 'pass-captain' ) );
		}
		echo implode( ' · ', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	private static function render_queues() {
		echo '<h2 id="wpcap-queues">' . esc_html__( 'Warteschlangen', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Queue', 'pass-captain' ) . '</th><th>' . esc_html__( 'Offen nach Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Überfällig (> 30 Min.)', 'pass-captain' ) . '</th><th>' . esc_html__( 'Lange in Bearbeitung (> 5 Min.)', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( array_keys( Status::queues() ) as $queue ) {
			$s = Status::queue_summary( $queue );
			if ( ! $s ) { continue; }
			$counts = array();
			foreach ( $s['counts'] as $status => $n ) { $counts[] = esc_html( $status ) . ': ' . (int) $n; }
			echo '<tr><td>' . esc_html( $s['label'] ) . '</td><td>' . ( $counts ? implode( ', ', $counts ) : '–' ) . '</td><td>' . (int) $s['overdue'] . '</td><td>' . (int) $s['long_running'] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</tbody></table>';
		$backlog = Status::job_backlog();
		if ( $backlog ) {
			echo '<h3>' . esc_html__( 'Jobs nach Status und Typ', 'pass-captain' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Typ', 'pass-captain' ) . '</th><th>' . esc_html__( 'Anzahl', 'pass-captain' ) . '</th><th>' . esc_html__( 'Ältester fälliger', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $backlog as $row ) {
				echo '<tr><td>' . esc_html( $row->status ) . '</td><td><code>' . esc_html( $row->job_type ) . '</code></td><td>' . (int) $row->n . '</td><td>' . self::time( $row->oldest_due ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped helper.
			}
			echo '</tbody></table>';
		}
		$mail = Status::email_failures();
		if ( $mail ) {
			echo '<h3>' . esc_html__( 'E-Mail-Versandfehler', 'pass-captain' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Fehler', 'pass-captain' ) . '</th><th>' . esc_html__( 'Nachrichten', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zuletzt', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $mail as $row ) {
				echo '<tr><td>' . esc_html( $row->last_error ) . '</td><td>' . (int) $row->n . '</td><td>' . self::time( $row->latest ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped helper.
			}
			echo '</tbody></table><p class="description">' . esc_html__( 'Häufige Ursachen: SMTP-Plugin falsch konfiguriert, Absenderadresse nicht erlaubt, Versandlimit des Hosters.', 'pass-captain' ) . '</p>';
		}
	}

	private static function render_failed() {
		echo '<h2 id="wpcap-failed">' . esc_html__( 'Fehlgeschlagene Einträge', 'pass-captain' ) . '</h2>';
		$any = false;
		foreach ( Status::queues() as $queue => $q ) {
			$rows = Status::failed_items( $queue, 50 );
			if ( ! $rows ) { continue; }
			$any = true;
			echo '<h3>' . esc_html( $q['label'] ) . '</h3>';
			$total = Status::failed_count( $queue );
			/* translators: 1: number of failed items, 2: maximum per click */
			self::action_button( 'wpcap_ops_queue_bulk_retry', 'wpcap_ops_queue_bulk_' . $queue, array( 'queue' => $queue ), sprintf( __( 'Alle %1$d erneut versuchen (höchstens %2$d pro Klick)', 'pass-captain' ), $total, Status::bulk_retry_cap() ), 'button', __( 'Alle fehlgeschlagenen Einträge dieser Warteschlange erneut versuchen?', 'pass-captain' ) );
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Typ', 'pass-captain' ) . '</th><th>' . esc_html__( 'Versuche', 'pass-captain' ) . '</th><th>' . esc_html__( 'Letzter Fehler', 'pass-captain' ) . '</th><th>' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktionen', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$detail = isset( $row->recipient ) ? ' (' . self::mask_email( $row->recipient ) . ')' : ( isset( $row->response_code ) && $row->response_code ? ' (HTTP ' . (int) $row->response_code . ')' : '' );
				echo '<tr><td>' . (int) $row->id . '</td><td><code>' . esc_html( $row->type ) . '</code>' . esc_html( $detail ) . '</td><td>' . (int) $row->attempts . '</td><td>' . esc_html( (string) $row->last_error ) . '</td><td>' . self::time( $row->updated ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped helper.
				$nonce = 'wpcap_ops_queue_' . $queue . '_' . (int) $row->id;
				self::action_button( 'wpcap_ops_queue_action', $nonce, array( 'queue' => $queue, 'id' => (int) $row->id, 'op' => 'retry' ), __( 'Erneut versuchen', 'pass-captain' ) );
				self::action_button( 'wpcap_ops_queue_action', $nonce, array( 'queue' => $queue, 'id' => (int) $row->id, 'op' => 'discard' ), __( 'Verwerfen', 'pass-captain' ), 'button button-small button-link-delete', __( 'Eintrag endgültig verwerfen?', 'pass-captain' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! $any ) { echo '<p>' . esc_html__( 'Keine fehlgeschlagenen Einträge.', 'pass-captain' ) . '</p>'; }
	}

	private static function render_refunds() {
		$rows = Diagnostics::stuck_refunds( 50 );
		echo '<h2 id="wpcap-refunds">' . esc_html__( 'Erstattungen zur manuellen Prüfung', 'pass-captain' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine Erstattung wartet auf eine manuelle Prüfung.', 'pass-captain' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Bei diesen Erstattungen brach der Ablauf während des Aufrufs beim Zahlungsanbieter ab. Bitte im Konto des Anbieters prüfen, ob ausgezahlt wurde: „Erledigt“ verbucht die Erstattung (Tickets werden widerrufen), „Fehlgeschlagen“ gibt sie für einen neuen Versuch frei.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Erstattung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bestellung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Betrag', 'pass-captain' ) . '</th><th>' . esc_html__( 'Seit', 'pass-captain' ) . '</th><th>' . esc_html__( 'Entscheidung', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . (int) $row->refund_id . ( '' !== (string) $row->reason ? '<br><small>' . esc_html( $row->reason ) . '</small>' : '' ) . '</td><td><a href="' . esc_url( Forms::page_url( OrdersPage::SLUG, array( 'order_id' => (int) $row->order_id ) ) ) . '">#' . (int) $row->order_id . '</a></td><td>' . esc_html( Forms::money( (int) $row->amount_minor, $row->currency ) ) . '</td><td>' . self::time( $row->updated_at ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped helper.
			Forms::open_form( 'wpcap_ops_refund_resolve', 'wpcap_ops_refund_' . (int) $row->refund_id, 'style="display:inline"' );
			Forms::hidden( 'refund_id', (int) $row->refund_id );
			echo '<label class="screen-reader-text" for="wpcap-ref-' . (int) $row->refund_id . '">' . esc_html__( 'Anbieter-Referenz oder Notiz', 'pass-captain' ) . '</label><input type="text" id="wpcap-ref-' . (int) $row->refund_id . '" name="reference" maxlength="150" placeholder="' . esc_attr__( 'Anbieter-Referenz / Notiz', 'pass-captain' ) . '"> ';
			echo '<button type="submit" name="resolution" value="completed" class="button button-small" data-wpcap-confirm="' . esc_attr( __( 'Wurde die Erstattung beim Zahlungsanbieter wirklich ausgezahlt? Sie wird jetzt verbucht.', 'pass-captain' ) ) . '">' . esc_html__( 'Manuell als erledigt markieren', 'pass-captain' ) . '</button> ';
			echo '<button type="submit" name="resolution" value="failed" class="button button-small" data-wpcap-confirm="' . esc_attr( __( 'Wurde beim Zahlungsanbieter sicher nichts ausgezahlt?', 'pass-captain' ) ) . '">' . esc_html__( 'Als fehlgeschlagen markieren', 'pass-captain' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_payment_cases() {
		$rows = \PassCaptain\Payments\Cases::open_cases( 50 );
		echo '<h2 id="wpcap-payment-cases">' . esc_html__( 'Zahlungen zur Klärung', 'pass-captain' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine offenen Fälle.', 'pass-captain' ) . '</p>';
			return;
		}
		$kinds = array(
			'paid_for_closed_order' => __( 'Zahlung auf stornierte/abgelaufene Bestellung', 'pass-captain' ),
			'double_payment' => __( 'Doppelzahlung', 'pass-captain' ),
			'refund_failed' => __( 'Erstattung beim Anbieter fehlgeschlagen', 'pass-captain' ),
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Fall', 'pass-captain' ) . '</th><th>' . esc_html__( 'Bestellung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Anbieter / Referenz', 'pass-captain' ) . '</th><th>' . esc_html__( 'Betrag', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . (int) $row->case_id . '<br><small>' . esc_html( $kinds[ $row->kind ] ?? $row->kind ) . ( 'refund_failed' === $row->status ? ' · ' . esc_html__( 'automatische Erstattung fehlgeschlagen', 'pass-captain' ) : '' ) . '</small>' . ( '' !== (string) $row->note ? '<br><small>' . esc_html( $row->note ) . '</small>' : '' ) . '</td><td><a href="' . esc_url( Forms::page_url( OrdersPage::SLUG, array( 'order_id' => (int) $row->order_id ) ) ) . '">#' . (int) $row->order_id . '</a></td><td>' . esc_html( $row->provider . ' · ' . $row->reference ) . '</td><td>' . esc_html( Forms::money( (int) $row->amount_minor, $row->currency ) ) . '</td><td>';
			Forms::open_form( 'wpcap_ops_payment_case', 'wpcap_ops_case_' . (int) $row->case_id, 'style="display:inline"' );
			Forms::hidden( 'case_id', (int) $row->case_id );
			if ( 'refund_failed' !== $row->kind ) {
				echo '<button type="submit" name="case_action" value="refund" class="button button-small" data-wpcap-confirm="' . esc_attr( __( 'Betrag jetzt beim Zahlungsanbieter erstatten?', 'pass-captain' ) ) . '">' . esc_html__( 'Beim Anbieter erstatten', 'pass-captain' ) . '</button> ';
			}
			echo '<label class="screen-reader-text" for="wpcap-case-' . (int) $row->case_id . '">' . esc_html__( 'Notiz', 'pass-captain' ) . '</label><input type="text" id="wpcap-case-' . (int) $row->case_id . '" name="note" maxlength="200" placeholder="' . esc_attr__( 'Notiz', 'pass-captain' ) . '"> ';
			echo '<button type="submit" name="case_action" value="resolve" class="button button-small">' . esc_html__( 'Als erledigt markieren', 'pass-captain' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_schema() {
		echo '<h2 id="wpcap-schema">' . esc_html__( 'Datenbankschema', 'pass-captain' ) . '</h2>';
		$isolation = \PassCaptain\DbSession::isolation();
		/* translators: %s: transaction isolation level of the database server, e.g. REPEATABLE-READ */
		echo '<p>' . esc_html( sprintf( __( 'Transaktions-Isolation des Servers: %s (Bestell- und Zahlungstransaktionen laufen immer mit REPEATABLE READ).', 'pass-captain' ), '' !== $isolation['global'] ? $isolation['global'] : __( 'unbekannt', 'pass-captain' ) ) ) . '</p>';
		$error = get_option( Installer::ERROR_OPTION );
		$stored = (string) get_option( 'wpcap_db_version' );
		if ( ! is_array( $error ) && Installer::version() === $stored ) {
			echo '<p>' . esc_html__( 'Aktuell.', 'pass-captain' ) . ' <code>' . esc_html( $stored ) . '</code></p>';
			return;
		}
		if ( is_array( $error ) ) {
			$blocked = Installer::retry_blocked_until();
			/* translators: 1: number of failed attempts, 2: time of the next automatic attempt */
			echo '<p>' . esc_html( sprintf( __( 'Die Aktualisierung ist %1$d-mal in Folge fehlgeschlagen. Nächster automatischer Versuch: %2$s.', 'pass-captain' ), (int) ( $error['attempts'] ?? 1 ), $blocked ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $blocked ), 'Y-m-d H:i' ) : __( 'bei der nächsten Admin- oder Cron-Anfrage', 'pass-captain' ) ) ) . '</p>';
			echo '<p><code>' . esc_html( substr( implode( ', ', array_merge( (array) ( $error['missing'] ?? array() ), (array) ( $error['errors'] ?? array() ) ) ), 0, 800 ) ) . '</code></p>';
		} else {
			/* translators: 1: stored schema version, 2: expected schema version */
			echo '<p>' . esc_html( sprintf( __( 'Gespeicherte Version %1$s, erwartet %2$s.', 'pass-captain' ), $stored, Installer::version() ) ) . '</p>';
		}
		self::action_button( 'wpcap_ops_schema_retry', 'wpcap_ops_schema_retry', array(), __( 'Jetzt erneut versuchen', 'pass-captain' ), 'button button-primary' );
	}

	private static function render_diagnostics() {
		echo '<h2 id="wpcap-diagnostics">' . esc_html__( 'Diagnose und Abgleich', 'pass-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sucht nach inkonsistenten Daten. Die Prüfung ändert nichts; Reparaturen gibt es nur, wo sie gefahrlos wiederholbar sind, und sie werden im Audit-Log protokolliert. Auch per WP-CLI: wp pass-captain diagnose', 'pass-captain' ) . '</p>';
		self::action_button( 'wpcap_ops_diagnose', 'wpcap_ops_diagnose', array(), __( 'Diagnose ausführen', 'pass-captain' ), 'button button-primary' );
		$report = get_option( Diagnostics::REPORT_OPTION );
		if ( ! is_array( $report ) || empty( $report['checks'] ) ) { return; }
		/* translators: %s: date and time */
		echo '<p class="description">' . esc_html( sprintf( __( 'Letzte Diagnose: %s', 'pass-captain' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $report['time'] ), 'Y-m-d H:i' ) ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Prüfung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Anzahl', 'pass-captain' ) . '</th><th>' . esc_html__( 'Status', 'pass-captain' ) . '</th><th>' . esc_html__( 'Beispiele und empfohlene Maßnahme', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $report['checks'] as $check ) {
			echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td>' . (int) $check['count'] . '</td><td>' . self::badge( $check['severity'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped helper.
			if ( '' !== $check['error'] ) { echo '<p>' . esc_html( $check['error'] ) . '</p>'; }
			if ( $check['samples'] ) {
				echo '<ul style="margin:0">';
				foreach ( $check['samples'] as $sample ) {
					$pairs = array();
					foreach ( $sample as $k => $v ) {
						$text = esc_html( $k . '=' . $v );
						$pairs[] = 'order_id' === $k && (int) $v ? '<a href="' . esc_url( Forms::page_url( OrdersPage::SLUG, array( 'order_id' => (int) $v ) ) ) . '">' . $text . '</a>' : $text;
					}
					echo '<li><code>' . implode( ' ', $pairs ) . '</code></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				}
				echo '</ul>';
			}
			if ( '' !== $check['action'] ) { echo '<p>' . esc_html( $check['action'] ) . '</p>'; }
			if ( $check['repairable'] ) {
				self::action_button( 'wpcap_ops_repair', 'wpcap_ops_repair_' . $check['key'], array( 'check' => $check['key'] ), __( 'Reparieren', 'pass-captain' ), 'button button-small', __( 'Reparatur jetzt ausführen?', 'pass-captain' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
