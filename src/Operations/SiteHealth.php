<?php
/**
 * Operations monitoring through WordPress Site Health (A164): tests for WP-Cron, queue backlogs and
 * dead letters, plugin mail failures, SMS provider failures and payment webhook freshness, the
 * "Pass Captain – Betrieb" section of the Site Health info tab, and an admin notice on plugin pages
 * while a test is critical. Thresholds: filter wpcap_health_thresholds (docs/OPERATIONS.md).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Operations;

use PassCaptain\Admin\Forms;
use PassCaptain\Modules;
use PassCaptain\Privacy;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class SiteHealth {
	/** Transient with the critical test labels for the admin notice. */
	const NOTICE_TRANSIENT = 'wpcap_health_critical';

	/** Recurring plugin cron hooks that must always be scheduled. */
	const RECURRING_HOOKS = array( 'wpcap_process_messages', 'wpcap_process_jobs', 'wpcap_cleanup_reservations', 'wpcap_reconcile_capacity', 'wpcap_privacy_retention' );

	/** Providers without incoming payment webhooks. */
	const NO_WEBHOOK_PROVIDERS = array( 'manual', 'bank_transfer', 'woocommerce', 'boxoffice' );

	public static function register() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/** Thresholds (filterable). */
	public static function thresholds() {
		$defaults = array(
			'cron_late_minutes'        => 15,
			'cron_critical_minutes'    => 60,
			'backlog_items'            => 200,
			'backlog_age_minutes'      => 30,
			'backlog_critical_minutes' => 120,
			'dead_letters'             => 1,
			'dead_letters_critical'    => 25,
			'mail_window_hours'        => 24,
			'mail_failures'            => 1,
			'mail_failures_critical'   => 10,
			'sms_failures'             => 1,
			'sms_failures_critical'    => 10,
			'payment_webhook_hours'    => 48,
			// A323: rejected/failed payment webhooks within mail_window_hours; any crash is critical.
			'webhook_failures'         => 1,
			'webhook_failures_critical' => 10,
			'webhook_exceptions_critical' => 1,
			// A324: failed payment starts within payment_start_window_minutes ("Häufung").
			'payment_start_window_minutes' => 60,
			'payment_start_failures'   => 1,
			'payment_start_failures_critical' => 5,
		);
		/**
		 * Thresholds of the Pass Captain Site Health checks.
		 *
		 * @param array $thresholds Key => number (minutes, hours or item counts, see docs/OPERATIONS.md).
		 */
		$values = apply_filters( 'wpcap_health_thresholds', $defaults );
		$values = is_array( $values ) ? $values : array();
		$out = array();
		foreach ( $defaults as $key => $default ) {
			$out[ $key ] = max( 0, (int) ( $values[ $key ] ?? $default ) );
		}
		return $out;
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

	/** Test key => callable; the SMS test only while the module is active and a provider is set up. */
	public static function test_callbacks() {
		$tests = array(
			'cron'             => array( __CLASS__, 'test_cron' ),
			'queues'           => array( __CLASS__, 'test_queues' ),
			'mail'             => array( __CLASS__, 'test_mail' ),
			'payment_webhooks' => array( __CLASS__, 'test_payment_webhooks' ),
			'webhook_errors'   => array( __CLASS__, 'test_webhook_errors' ),
			'payment_start'    => array( __CLASS__, 'test_payment_start' ),
			'rest_anonymous'   => array( __CLASS__, 'test_rest_anonymous' ),
			'proxy'            => array( __CLASS__, 'test_proxy' ),
			'db_server'        => array( __CLASS__, 'test_db_server' ),
			'retention'        => array( __CLASS__, 'test_retention' ),
			'small_business_vat' => array( __CLASS__, 'test_small_business_vat' ),
		);
		if ( self::sms_active() ) { $tests['sms'] = array( __CLASS__, 'test_sms' ); }
		return $tests;
	}

	private static function sms_active() {
		return Modules::enabled( 'PassCaptain\\Modules\\Sms' ) && Privacy::has_table( 'wpcap_sms_messages' ) && \PassCaptain\Sms\Service::enabled();
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

	/**
	 * SET-PC-47: reminder while no retention period for attendee and contact data is set (setting
	 * attendee_retention_days = 0). The technical default stays "no general period" and existing
	 * installations are not changed; the country preset proposes a period. Status "recommended", never
	 * critical (no admin notice).
	 */
	public static function test_retention() {
		$days = \PassCaptain\Privacy\Retention::attendee_days();
		$actions = '<p><a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\SettingsPage::SLUG, array( 'tab' => 'privacy' ) ) ) . '">' . esc_html__( 'Datenschutz-Einstellungen öffnen', 'pass-captain' ) . '</a></p>';
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			return self::result( 'retention', 'good', __( 'Pass Captain: Löschfrist für Teilnehmerdaten ist festgelegt', 'pass-captain' ), self::p( sprintf( _n( 'Teilnehmerdaten werden %d Tag nach Ende des Termins gelöscht.', 'Teilnehmerdaten werden %d Tage nach Ende des Termins gelöscht.', $days, 'pass-captain' ), $days ) ) );
		}
		$text = __( 'In den Datenschutz-Einstellungen ist keine allgemeine Löschfrist für Teilnehmer- und Kontaktdaten festgelegt. Antworten auf Zusatzfelder bleiben dann so lange gespeichert, wie es die Einstellung des jeweiligen Feldes vorsieht – ohne Feldfrist unbegrenzt. Die DSGVO verlangt, personenbezogene Daten nur so lange zu speichern, wie sie gebraucht werden.', 'pass-captain' );
		$hint = __( 'Lege unter Einstellungen → Datenschutz fest, nach wie vielen Tagen nach dem Termin Teilnehmerdaten gelöscht werden. Das Länderprofil schlägt eine Frist vor (z. B. 365 Tage); lass die Frist von deiner Datenschutzberatung prüfen.', 'pass-captain' );
		return self::result( 'retention', 'recommended', __( 'Pass Captain: keine Löschfrist für Teilnehmerdaten festgelegt', 'pass-captain' ), self::p( $text ) . self::p( $hint ), $actions );
	}

	/**
	 * Site Health: active ticket types of upcoming dates with VAT although their seller (platform or
	 * agent organizer) is a small business. Checkout refuses them (Orders::checkout_apply_taxes()); prices
	 * are never changed automatically, the list names what to correct.
	 */
	public static function test_small_business_vat() {
		$types = \PassCaptain\Partners\SalesModel::small_business_vat_types( 20 );
		if ( ! $types ) {
			return self::result( 'small_business_vat', 'good', __( 'Pass Captain: keine Umsatzsteuer auf Tickets von Kleinunternehmern', 'pass-captain' ), self::p( __( 'Keine aktive Ticketart eines Kleinunternehmers (Plattform oder Veranstalter im Vermittlermodell) enthält Umsatzsteuer.', 'pass-captain' ) ) );
		}
		$lines = array();
		foreach ( $types as $type ) {
			/* translators: 1: event, 2: date, 3: ticket type, 4: tax rate in percent, 5: seller */
			$lines[] = sprintf( __( '%1$s (%2$s) – %3$s: %4$s %% USt, Rechnungssteller %5$s', 'pass-captain' ), $type['event'], \PassCaptain\Frontend\Format::site_datetime( $type['starts_at'] ), $type['ticket'], Forms::percent_input( $type['rate_bp'] ), $type['seller'] );
		}
		$text = __( 'Diese Ticketarten enthalten Umsatzsteuer, obwohl ihr Rechnungssteller als Kleinunternehmer eingestellt ist. Der Checkout lehnt Bestellungen dieser Ticketarten ab, weil dafür keine Rechnung ausgestellt werden dürfte (§ 14c UStG). Bitte setze den Steuersatz auf 0 % oder prüfe die Einstellung „Kleinunternehmer“ der Plattform bzw. des Veranstalters. Preise werden nicht automatisch geändert.', 'pass-captain' );
		$actions = '<p><a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\EventsPage::SLUG ) ) . '">' . esc_html__( 'Events öffnen', 'pass-captain' ) . '</a></p>';
		return self::result( 'small_business_vat', 'critical', __( 'Pass Captain: Ticketarten mit Umsatzsteuer bei Kleinunternehmern', 'pass-captain' ), self::p( $text ) . self::list_html( $lines ), $actions );
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

	/** "1 Minute", "20 Minuten", from 120 minutes on "3 Stunden". */
	public static function duration( $minutes ) {
		$minutes = max( 0, (int) $minutes );
		if ( $minutes < 120 ) {
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d Minute', '%d Minuten', $minutes, 'pass-captain' ), $minutes );
		}
		return self::hours( intdiv( $minutes, 60 ) );
	}

	/** "1 Stunde", "24 Stunden". */
	public static function hours( $hours ) {
		$hours = max( 0, (int) $hours );
		/* translators: %d: number of hours */
		return sprintf( _n( '%d Stunde', '%d Stunden', $hours, 'pass-captain' ), $hours );
	}

	private static function p( $text ) { return '<p>' . esc_html( $text ) . '</p>'; }

	private static function list_html( array $lines ) {
		return $lines ? '<ul>' . implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $lines ) ) . '</ul>' : '';
	}

	private static function operations_link() {
		return '<p><a href="' . esc_url( \PassCaptain\Admin\OperationsPage::url() ) . '">' . esc_html__( 'Zur Betriebsübersicht von Pass Captain', 'pass-captain' ) . '</a></p>';
	}

	/* ------------------------------------------------------------------ WP-Cron */

	/**
	 * Cron state: DISABLE_WP_CRON, overdue plugin events (hook => minutes late), recurring hooks that are
	 * not scheduled, last heartbeat of the queue hooks.
	 *
	 * @param array|null $crons    Cron array (_get_cron_array() format); null = read it.
	 * @param int|null   $now      Unix time.
	 * @param bool|null  $disabled DISABLE_WP_CRON (tests).
	 */
	public static function cron_state( $crons = null, $now = null, $disabled = null ) {
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		if ( null === $crons ) { $crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : null; }
		$hooks = \PassCaptain\Plugin::cron_hooks();
		$overdue = array();
		$scheduled = array();
		$known = is_array( $crons );
		foreach ( $known ? $crons : array() as $timestamp => $events ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $events ) ) { continue; }
			foreach ( array_keys( $events ) as $hook ) {
				if ( ! in_array( $hook, $hooks, true ) ) { continue; }
				$scheduled[ $hook ] = true;
				$late = (int) floor( ( $now - (int) $timestamp ) / 60 );
				if ( $late > 0 && $late > ( $overdue[ $hook ] ?? 0 ) ) { $overdue[ $hook ] = $late; }
			}
		}
		$missing = $known ? array_values( array_diff( self::RECURRING_HOOKS, array_keys( $scheduled ) ) ) : array();
		$heartbeat = Monitor::last_heartbeat();
		return array(
			'disabled'  => null === $disabled ? ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : (bool) $disabled,
			'known'     => $known,
			'overdue'   => $overdue,
			'missing'   => $missing,
			'heartbeat' => $heartbeat,
			'since'     => $heartbeat ? (int) floor( max( 0, $now - $heartbeat ) / 60 ) : null,
		);
	}

	public static function test_cron( $crons = null, $now = null, $disabled = null ) {
		$t = self::thresholds();
		$state = self::cron_state( $crons, $now, $disabled );
		$late = array_filter( $state['overdue'], static fn( $m ) => $m >= $t['cron_late_minutes'] );
		$critical = array_filter( $state['overdue'], static fn( $m ) => $m >= $t['cron_critical_minutes'] );
		$lines = array();
		foreach ( $late as $hook => $minutes ) {
			/* translators: 1: cron hook name, 2: duration, e.g. "20 Minuten" */
			$lines[] = sprintf( __( '%1$s ist seit %2$s überfällig.', 'pass-captain' ), $hook, self::duration( $minutes ) );
		}
		foreach ( $state['missing'] as $hook ) {
			/* translators: %s: cron hook name */
			$lines[] = sprintf( __( '%s ist nicht eingeplant.', 'pass-captain' ), $hook );
		}
		$silent = $state['disabled'] && null !== $state['since'] && $state['since'] >= $t['cron_critical_minutes'];
		if ( $silent ) {
			/* translators: %s: duration, e.g. "3 Stunden" */
			$lines[] = sprintf( __( 'DISABLE_WP_CRON ist gesetzt und die Warteschlangen liefen seit %s nicht mehr – offenbar löst kein externer Cronjob WP-Cron aus.', 'pass-captain' ), self::duration( $state['since'] ) );
		}
		$intro = $state['disabled'] ? __( 'WP-Cron ist per DISABLE_WP_CRON abgeschaltet; ein System-Cronjob muss wp-cron.php regelmäßig aufrufen.', 'pass-captain' ) : __( 'WP-Cron läuft bei Seitenaufrufen. Für verlässliche Ticketmails, Erinnerungen und Webhooks empfehlen wir einen System-Cronjob.', 'pass-captain' );
		if ( null !== $state['since'] ) {
			/* translators: %s: duration, e.g. "4 Minuten" */
			$intro .= ' ' . sprintf( __( 'Letzter Lauf der Warteschlangen vor %s.', 'pass-captain' ), self::duration( $state['since'] ) );
		}
		$actions = '<p><a href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/" target="_blank" rel="noopener">' . esc_html__( 'Externen Cronjob einrichten', 'pass-captain' ) . '</a></p>';
		if ( $critical || $silent ) {
			return self::result( 'cron', 'critical', __( 'Pass Captain: geplante Aufgaben laufen nicht', 'pass-captain' ), self::p( $intro ) . self::list_html( $lines ), $actions );
		}
		if ( $lines ) {
			return self::result( 'cron', 'recommended', __( 'Pass Captain: geplante Aufgaben verspätet', 'pass-captain' ), self::p( $intro ) . self::list_html( $lines ), $actions );
		}
		return self::result( 'cron', 'good', __( 'Pass Captain: geplante Aufgaben laufen pünktlich', 'pass-captain' ), self::p( $intro ) );
	}

	/* ------------------------------------------------------------------ Queues */

	public static function test_queues( $now = null ) {
		$t = self::thresholds();
		$labels = Queues::labels();
		$status = 'good';
		$lines = array();
		foreach ( array_keys( Queues::available() ) as $queue ) {
			$s = Queues::stats( $queue, $now );
			if ( ! $s ) { continue; }
			$age = (int) floor( $s['oldest_due_seconds'] / 60 );
			$crit = ( $t['dead_letters_critical'] > 0 && $s['failed'] >= $t['dead_letters_critical'] ) || ( $s['due'] > 0 && $age >= $t['backlog_critical_minutes'] );
			$warn = ( $t['dead_letters'] > 0 && $s['failed'] >= $t['dead_letters'] ) || ( $t['backlog_items'] > 0 && $s['due'] >= $t['backlog_items'] ) || ( $s['due'] > 0 && $age >= $t['backlog_age_minutes'] );
			if ( $crit ) { $status = 'critical'; } elseif ( $warn && 'good' === $status ) { $status = 'recommended'; }
			if ( $crit || $warn ) {
				/* translators: 1: queue name, 2: number of due items, 3: waiting time of the oldest due item, e.g. "40 Minuten", 4: number of failed items */
				$lines[] = sprintf( __( '%1$s: fällig %2$d (ältester wartet seit %3$s), fehlgeschlagen %4$d.', 'pass-captain' ), $labels[ $queue ] ?? $queue, $s['due'], self::duration( $age ), $s['failed'] );
			}
		}
		if ( 'good' === $status ) {
			return self::result( 'queues', 'good', __( 'Pass Captain: keine Rückstände in den Warteschlangen', 'pass-captain' ), self::p( __( 'Hintergrundjobs, E-Mails und Webhooks werden zeitnah verarbeitet; es gibt keine endgültig fehlgeschlagenen Einträge.', 'pass-captain' ) ) );
		}
		$label = 'critical' === $status ? __( 'Pass Captain: große Rückstände oder viele fehlgeschlagene Einträge', 'pass-captain' ) : __( 'Pass Captain: Rückstände oder fehlgeschlagene Einträge in den Warteschlangen', 'pass-captain' );
		return self::result( 'queues', $status, $label, self::p( __( 'Prüfe die Einträge und versuche sie erneut oder verwirf sie.', 'pass-captain' ) ) . self::list_html( $lines ), self::operations_link() );
	}

	/* ------------------------------------------------------------------ Mail */

	public static function test_mail( $now = null ) {
		$t = self::thresholds();
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		$failures = Monitor::mail_failures( $now - $t['mail_window_hours'] * HOUR_IN_SECONDS );
		$count = count( $failures );
		if ( 0 === $count || ( $t['mail_failures'] > 0 && $count < $t['mail_failures'] ) ) {
			/* translators: %s: period, e.g. "24 Stunden" */
			$text = sprintf( __( 'Innerhalb von %s ist kein Versand einer Plugin-E-Mail fehlgeschlagen.', 'pass-captain' ), self::hours( $t['mail_window_hours'] ) );
			return self::result( 'mail', 'good', __( 'Pass Captain: E-Mail-Versand ohne Fehler', 'pass-captain' ), self::p( $text ) );
		}
		$last = $failures[0];
		/* translators: 1: number of failed mails, 2: period, e.g. "24 Stunden" */
		$text = sprintf( _n( 'Innerhalb von %2$s ist %1$d Plugin-E-Mail nicht versendet worden (wp_mail() meldete einen Fehler).', 'Innerhalb von %2$s sind %1$d Plugin-E-Mails nicht versendet worden (wp_mail() meldete einen Fehler).', $count, 'pass-captain' ), $count, self::hours( $t['mail_window_hours'] ) );
		/* translators: 1: mail type, 2: error code, 3: error message */
		$detail = sprintf( __( 'Letzter Fehler: Typ %1$s, Code %2$s %3$s', 'pass-captain' ), (string) $last['type'], (string) $last['code'], (string) $last['error'] );
		$status = $t['mail_failures_critical'] > 0 && $count >= $t['mail_failures_critical'] ? 'critical' : 'recommended';
		return self::result( 'mail', $status, __( 'Pass Captain: E-Mails werden nicht zugestellt', 'pass-captain' ), self::p( $text ) . self::p( trim( $detail ) ) . self::p( __( 'Prüfe die SMTP-Einstellungen bzw. das Mail-Plugin der Website.', 'pass-captain' ) ), self::operations_link() );
	}

	/* ------------------------------------------------------------------ SMS */

	/** SMS failures (failed/undelivered) in the mail window. */
	public static function sms_failures( $now = null ) {
		global $wpdb;
		if ( ! Privacy::has_table( 'wpcap_sms_messages' ) ) { return 0; }
		$t = self::thresholds();
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_sms_messages WHERE status IN ('failed','undelivered') AND updated_at>=%s", gmdate( 'Y-m-d H:i:s', $now - $t['mail_window_hours'] * HOUR_IN_SECONDS ) ) );
	}

	public static function test_sms( $now = null ) {
		$t = self::thresholds();
		$count = self::sms_failures( $now );
		if ( 0 === $count || ( $t['sms_failures'] > 0 && $count < $t['sms_failures'] ) ) {
			/* translators: %s: period, e.g. "24 Stunden" */
			$text = sprintf( __( 'Innerhalb von %s hat der SMS-Anbieter keinen Fehler gemeldet.', 'pass-captain' ), self::hours( $t['mail_window_hours'] ) );
			return self::result( 'sms', 'good', __( 'Pass Captain: SMS-Versand ohne Fehler', 'pass-captain' ), self::p( $text ) );
		}
		$status = $t['sms_failures_critical'] > 0 && $count >= $t['sms_failures_critical'] ? 'critical' : 'recommended';
		/* translators: 1: number of SMS, 2: period, e.g. "24 Stunden" */
		$text = sprintf( _n( 'Innerhalb von %2$s ist %1$d SMS fehlgeschlagen oder nicht zugestellt worden.', 'Innerhalb von %2$s sind %1$d SMS fehlgeschlagen oder nicht zugestellt worden.', $count, 'pass-captain' ), $count, self::hours( $t['mail_window_hours'] ) );
		return self::result( 'sms', $status, __( 'Pass Captain: SMS-Versand fehlerhaft', 'pass-captain' ), self::p( $text ) . self::p( __( 'Prüfe Zugangsdaten und Guthaben beim SMS-Anbieter.', 'pass-captain' ) ), '<p><a href="' . esc_url( Forms::page_url( 'wpcap-sms' ) ) . '">' . esc_html__( 'SMS-Einstellungen', 'pass-captain' ) . '</a></p>' );
	}

	/* ------------------------------------------------------------------ Payment webhooks */

	/**
	 * Per enabled provider with incoming webhooks: time of the last received webhook event (UTC, '' =
	 * never) and the number of orders of that provider within the freshness window.
	 *
	 * @return array<string, array{last: string, orders: int}>
	 */
	public static function payment_webhooks( $now = null ) {
		global $wpdb;
		$t = self::thresholds();
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		$since = gmdate( 'Y-m-d H:i:s', $now - $t['payment_webhook_hours'] * HOUR_IN_SECONDS );
		$out = array();
		$has_events = Privacy::has_table( 'wpcap_payment_events' );
		foreach ( (array) Settings::get( 'enabled_providers' ) as $provider ) {
			$provider = sanitize_key( (string) $provider );
			if ( '' === $provider || in_array( $provider, self::NO_WEBHOOK_PROVIDERS, true ) ) { continue; }
			$last = $has_events ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM {$wpdb->prefix}wpcap_payment_events WHERE provider=%s", $provider ) ) : '';
			$orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_orders WHERE payment_provider=%s AND created_at>=%s", $provider, $since ) );
			$out[ $provider ] = array( 'last' => $last, 'orders' => $orders );
		}
		return $out;
	}

	public static function test_payment_webhooks( $now = null ) {
		$t = self::thresholds();
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		$lines = array();
		foreach ( self::payment_webhooks( $now ) as $provider => $info ) {
			$fresh = '' !== $info['last'] && strtotime( $info['last'] . ' UTC' ) >= $now - $t['payment_webhook_hours'] * HOUR_IN_SECONDS;
			if ( $info['orders'] > 0 && ! $fresh ) {
				$lines[] = '' === $info['last']
					/* translators: 1: payment provider id, 2: number of orders, 3: period, e.g. "48 Stunden" */
					? sprintf( __( '%1$s: Bestellungen innerhalb von %3$s: %2$d, aber noch nie eine Webhook-Benachrichtigung erhalten.', 'pass-captain' ), $provider, $info['orders'], self::hours( $t['payment_webhook_hours'] ) )
					/* translators: 1: payment provider id, 2: number of orders, 3: period, e.g. "48 Stunden", 4: date and time (UTC) */
					: sprintf( __( '%1$s: Bestellungen innerhalb von %3$s: %2$d, letzte Webhook-Benachrichtigung am %4$s (UTC).', 'pass-captain' ), $provider, $info['orders'], self::hours( $t['payment_webhook_hours'] ), $info['last'] );
			}
		}
		if ( $lines ) {
			return self::result( 'payment_webhooks', 'recommended', __( 'Pass Captain: keine aktuellen Zahlungs-Webhooks', 'pass-captain' ), self::p( __( 'Zahlungen werden über Webhooks des Zahlungsanbieters bestätigt. Prüfe die Webhook-URL und das Signatur-Secret im Dashboard des Anbieters.', 'pass-captain' ) ) . self::list_html( $lines ) );
		}
		return self::result( 'payment_webhooks', 'good', __( 'Pass Captain: Zahlungs-Webhooks kommen an', 'pass-captain' ), self::p( __( 'Für alle aktiven Zahlungsanbieter mit aktuellen Bestellungen sind Webhook-Benachrichtigungen eingegangen.', 'pass-captain' ) ) );
	}

	/** Display names of the webhook failure kinds. */
	private static function failure_kinds() {
		return array(
			'signature'  => __( 'Signatur ungültig', 'pass-captain' ),
			'payload'    => __( 'Nachricht ungültig', 'pass-captain' ),
			'verify'     => __( 'Prüfung beim Anbieter nicht möglich', 'pass-captain' ),
			'processing' => __( 'Verarbeitung fehlgeschlagen', 'pass-captain' ),
			'exception'  => __( 'Absturz bei der Verarbeitung', 'pass-captain' ),
			'start'      => __( 'Zahlungsstart fehlgeschlagen', 'pass-captain' ),
		);
	}

	/** "Code – Fehler (Fehler-ID …), am … UTC" of a failure entry. */
	private static function failure_detail( array $e ) {
		$text = (string) ( $e['code'] ?? '' ) . ( '' !== (string) ( $e['error'] ?? '' ) ? ' – ' . $e['error'] : '' );
		if ( '' !== (string) ( $e['ref'] ?? '' ) ) {
			/* translators: %s: error reference */
			$text .= ' ' . sprintf( __( '(Fehler-ID %s)', 'pass-captain' ), $e['ref'] );
		}
		/* translators: 1: error details, 2: date and time (UTC) */
		return sprintf( __( '%1$s, zuletzt am %2$s (UTC)', 'pass-captain' ), trim( $text ), gmdate( 'Y-m-d H:i:s', (int) ( $e['t'] ?? 0 ) ) );
	}

	/**
	 * Failed payment webhooks per provider in the mail window (A323), shown right away: a wrong
	 * signing secret or a crashing handler means payments stay unconfirmed.
	 */
	public static function test_webhook_errors( $now = null ) {
		$t = self::thresholds();
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		$entries = Monitor::provider_failures( Monitor::WEBHOOK_OPTION, $now - $t['mail_window_hours'] * HOUR_IN_SECONDS );
		$total = Monitor::count_failures( $entries );
		$crashes = Monitor::count_failures( $entries, 'exception' );
		if ( ! $entries || ( $t['webhook_failures'] > 0 && $total < $t['webhook_failures'] && ( ! $t['webhook_exceptions_critical'] || $crashes < $t['webhook_exceptions_critical'] ) ) ) {
			/* translators: %s: period, e.g. "24 Stunden" */
			$text = sprintf( __( 'Innerhalb von %s wurde keine Webhook-Benachrichtigung eines Zahlungsanbieters abgelehnt oder fehlerhaft verarbeitet.', 'pass-captain' ), self::hours( $t['mail_window_hours'] ) );
			return self::result( 'webhook_errors', 'good', __( 'Pass Captain: Zahlungs-Webhooks ohne Fehler', 'pass-captain' ), self::p( $text ) );
		}
		$kinds = self::failure_kinds();
		$by = array();
		foreach ( $entries as $e ) {
			$key = $e['provider'] . '|' . $e['kind'];
			if ( ! isset( $by[ $key ] ) ) { $by[ $key ] = array( 'n' => 0, 'last' => $e ); }
			$by[ $key ]['n'] += max( 1, (int) ( $e['n'] ?? 1 ) );
		}
		$lines = array();
		foreach ( $by as $key => $info ) {
			list( $provider, $kind ) = explode( '|', $key, 2 );
			/* translators: 1: payment provider id, 2: kind of failure, 3: number, 4: details of the last failure */
			$lines[] = sprintf( __( '%1$s: %2$s – %3$d× (%4$s)', 'pass-captain' ), $provider, $kinds[ $kind ] ?? $kind, $info['n'], self::failure_detail( $info['last'] ) );
		}
		$critical = ( $t['webhook_exceptions_critical'] > 0 && $crashes >= $t['webhook_exceptions_critical'] ) || ( $t['webhook_failures_critical'] > 0 && $total >= $t['webhook_failures_critical'] );
		$intro = __( 'Zahlungsanbieter bestätigen Zahlungen per Webhook. Abgelehnte Benachrichtigungen (falsches Signatur-Secret, falsche Webhook-URL) oder Abstürze bei der Verarbeitung führen dazu, dass bezahlte Bestellungen offen bleiben. Der Anbieter stellt fehlgeschlagene Benachrichtigungen erneut zu, sobald die Ursache behoben ist.', 'pass-captain' );
		return self::result( 'webhook_errors', $critical ? 'critical' : 'recommended', __( 'Pass Captain: Zahlungs-Webhooks werden abgelehnt oder scheitern', 'pass-captain' ), self::p( $intro ) . self::list_html( $lines ) . self::p( __( 'Details mit Fehler-ID stehen im Support-Protokoll der Betriebsübersicht.', 'pass-captain' ) ), self::operations_link() );
	}

	/** Failed payment starts per provider within payment_start_window_minutes (A324). */
	public static function test_payment_start( $now = null ) {
		$t = self::thresholds();
		$now = null === $now ? \PassCaptain\Clock::real() : (int) $now;
		$entries = Monitor::provider_failures( Monitor::PAYMENT_START_OPTION, $now - $t['payment_start_window_minutes'] * MINUTE_IN_SECONDS );
		$total = Monitor::count_failures( $entries );
		if ( ! $entries || ( $t['payment_start_failures'] > 0 && $total < $t['payment_start_failures'] ) ) {
			/* translators: %s: period, e.g. "60 Minuten" */
			$text = sprintf( __( 'Innerhalb von %s ist kein Zahlungsstart bei einem Zahlungsanbieter fehlgeschlagen.', 'pass-captain' ), self::duration( $t['payment_start_window_minutes'] ) );
			return self::result( 'payment_start', 'good', __( 'Pass Captain: Zahlungen starten ohne Fehler', 'pass-captain' ), self::p( $text ) );
		}
		$by = array();
		foreach ( $entries as $e ) {
			if ( ! isset( $by[ $e['provider'] ] ) ) { $by[ $e['provider'] ] = array( 'n' => 0, 'last' => $e ); }
			$by[ $e['provider'] ]['n'] += max( 1, (int) ( $e['n'] ?? 1 ) );
		}
		$lines = array();
		foreach ( $by as $provider => $info ) {
			/* translators: 1: payment provider id, 2: number of failed payment starts, 3: details of the last failure */
			$lines[] = sprintf( __( '%1$s: %2$d fehlgeschlagene Zahlungsstarts (%3$s)', 'pass-captain' ), $provider, $info['n'], self::failure_detail( $info['last'] ) );
		}
		$critical = $t['payment_start_failures_critical'] > 0 && $total >= $t['payment_start_failures_critical'];
		/* translators: %s: period, e.g. "60 Minuten" */
		$intro = sprintf( __( 'Innerhalb von %s konnten Käufer:innen die Zahlung nicht starten; sie sahen einen neutralen Hinweis mit Fehler-ID, die Bestellung wurde storniert. Häufig sind Zugangsdaten, Kontoeinschränkungen oder eine nicht unterstützte Währung beim Anbieter die Ursache.', 'pass-captain' ), self::duration( $t['payment_start_window_minutes'] ) );
		return self::result( 'payment_start', $critical ? 'critical' : 'recommended', __( 'Pass Captain: Zahlungsstart beim Anbieter schlägt fehl', 'pass-captain' ), self::p( $intro ) . self::list_html( $lines ), self::operations_link() );
	}

	/** Transient with the last anonymous REST probe (A375). */
	const REST_PROBE_TRANSIENT = 'wpcap_health_rest_probe';

	/**
	 * A375: probe the public events endpoint without cookies or nonce (as a buyer's browser, the
	 * checkout and the scanner do). Security plugins and "disable REST for guests" snippets answer
	 * 401/403 there, which silently breaks checkout, order portal and scanner. Cached six hours
	 * (filter wpcap_health_rest_probe_ttl); the response code only, never the body.
	 *
	 * @return array{code:int,error:string,time:int}
	 */
	public static function rest_probe( $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::REST_PROBE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['code'] ) ) { return $cached; }
		// Through the outbound guard (A202); allow_local: the site may reach itself on a private/loopback address.
		$response = \PassCaptain\Security\OutboundHttp::request( rest_url( 'pass-captain/v1/events' ), array(
			'method'    => 'GET',
			'timeout'   => 5,
			'cookies'   => array(),
			'headers'   => array( 'Cache-Control' => 'no-cache', 'Accept' => 'application/json' ),
			// Loopback: core's own Site Health loopback test uses the same filter.
			'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		), array( 'allow_local' => true, 'max_bytes' => 262144 ) );
		$probe = array( 'code' => 0, 'error' => '', 'time' => \PassCaptain\Clock::real() );
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			$probe['code'] = is_array( $data ) ? (int) ( $data['response_code'] ?? 0 ) : 0;
			$probe['error'] = $probe['code'] ? '' : sanitize_text_field( $response->get_error_message() );
		} else {
			$probe['code'] = (int) $response['code'];
		}
		set_transient( self::REST_PROBE_TRANSIENT, $probe, max( MINUTE_IN_SECONDS, (int) apply_filters( 'wpcap_health_rest_probe_ttl', 6 * HOUR_IN_SECONDS ) ) );
		return $probe;
	}

	/** A375: anonymous REST access to the plugin's public endpoints. */
	public static function test_rest_anonymous() {
		$probe = self::rest_probe();
		$code = (int) $probe['code'];
		if ( $code >= 200 && $code < 300 ) {
			return self::result( 'rest_anonymous', 'good', __( 'Pass Captain: öffentliche REST-Schnittstelle erreichbar', 'pass-captain' ), self::p( __( 'Die öffentlichen Endpunkte (Eventliste, Checkout, Bestellstatus) antworten auch ohne Anmeldung.', 'pass-captain' ) ) );
		}
		if ( 401 === $code || 403 === $code ) {
			/* translators: %d: HTTP status code, e.g. 403 */
			$text = sprintf( __( 'Die REST-Schnittstelle lehnt Anfragen ohne Anmeldung ab (HTTP %d). Checkout, Bestellportal, Ticketseite und Scanner funktionieren für Käufer:innen dann nicht. Häufige Ursache: ein Sicherheits-Plugin oder Code-Schnipsel, der die REST-API für Gäste sperrt. Bitte den Namensraum pass-captain/v1 dort freigeben.', 'pass-captain' ), $code );
			return self::result( 'rest_anonymous', 'critical', __( 'Pass Captain: REST-Schnittstelle für Gäste gesperrt', 'pass-captain' ), self::p( $text ) );
		}
		$detail = '' !== $probe['error']
			/* translators: %s: error message of the HTTP request */
			? sprintf( __( 'Fehler: %s', 'pass-captain' ), $probe['error'] )
			/* translators: %d: HTTP status code */
			: sprintf( __( 'Antwort: HTTP %d', 'pass-captain' ), $code );
		$text = __( 'Die Website konnte ihre eigene öffentliche REST-Schnittstelle nicht abrufen. Das kann an einer gesperrten Loopback-Verbindung liegen (dann ist nur diese Prüfung betroffen) oder an einer Sperre der REST-API für Gäste. Bitte den Checkout einmal ohne Anmeldung ausprobieren.', 'pass-captain' );
		return self::result( 'rest_anonymous', 'recommended', __( 'Pass Captain: öffentliche REST-Schnittstelle nicht prüfbar', 'pass-captain' ), self::p( $text ) . self::p( $detail ) );
	}

	/** A375: forwarding headers or private peer addresses without configured trusted proxies. */
	public static function test_proxy() {
		$issue = \PassCaptain\Sales\CheckoutThrottle::status_issue();
		if ( null === $issue ) {
			$text = \PassCaptain\RateLimit::trusted_proxies()
				? __( 'Vertrauenswürdige Proxys sind eingetragen; Ratenlimits und Einwilligungsnachweise verwenden die Client-Adresse aus dem Weiterleitungs-Header.', 'pass-captain' )
				: __( 'Es gibt keine Hinweise auf einen vorgeschalteten Proxy oder ein CDN; die direkte Absenderadresse wird verwendet.', 'pass-captain' );
			return self::result( 'proxy', 'good', __( 'Pass Captain: Client-Adressen werden korrekt erkannt', 'pass-captain' ), self::p( $text ) );
		}
		$actions = '<p><a href="' . esc_url( \PassCaptain\Admin\Forms::page_url( \PassCaptain\Admin\SettingsPage::SLUG, array( 'tab' => 'advanced' ) ) . '#wpcap-settings-network' ) . '">' . esc_html__( 'Proxy-Einstellungen öffnen', 'pass-captain' ) . '</a></p>';
		return self::result( 'proxy', 'recommended', __( 'Pass Captain: Proxy ohne vertrauenswürdige Adressen', 'pass-captain' ), self::p( (string) $issue['message'] ), $actions );
	}

	/* ------------------------------------------------------------------ Summary, notice, info tab */

	/** Results of all tests (key => result). */
	public static function run_all() {
		$out = array();
		foreach ( self::test_callbacks() as $key => $callback ) { $out[ $key ] = call_user_func( $callback ); }
		return $out;
	}

	/** Labels of the critical tests (cached five minutes for the admin notice). */
	public static function critical_labels( $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::NOTICE_TRANSIENT );
		if ( is_array( $cached ) ) { return $cached; }
		$labels = array();
		foreach ( self::run_all() as $result ) {
			if ( 'critical' === $result['status'] ) { $labels[] = (string) $result['label']; }
		}
		set_transient( self::NOTICE_TRANSIENT, $labels, 5 * MINUTE_IN_SECONDS );
		return $labels;
	}

	/** Admin notice on plugin pages while a test is critical (administrators only). */
	public static function admin_notice() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects the screen.
		if ( ( ! str_starts_with( $page, 'wpcap' ) && 'pass-captain' !== $page ) || ! current_user_can( 'manage_options' ) ) { return; }
		$labels = self::critical_labels();
		if ( ! $labels ) { return; }
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pass Captain: Der Betrieb ist gestört.', 'pass-captain' ) . '</strong></p>' . self::list_html( $labels ) . '<p><a href="' . esc_url( \PassCaptain\Admin\OperationsPage::url() ) . '">' . esc_html__( 'Betriebsübersicht öffnen', 'pass-captain' ) . '</a> · <a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Website-Zustand', 'pass-captain' ) . '</a></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- list_html escapes.
	}

	/** Section of the Site Health info tab (filter debug_information). */
	public static function debug_information( $info ) {
		$info = is_array( $info ) ? $info : array();
		$fields = array();
		$fields['disable_wp_cron'] = array(
			'label' => 'DISABLE_WP_CRON',
			'value' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'ja', 'pass-captain' ) : __( 'nein', 'pass-captain' ),
		);
		foreach ( Monitor::HEARTBEAT_HOOKS as $hook ) {
			$beat = Monitor::last_heartbeat( $hook );
			$fields[ 'heartbeat_' . $hook ] = array(
				/* translators: %s: name of the background job (WP-Cron hook, e.g. wpcap_cron); the value column shows the time */
				'label' => sprintf( __( 'Letzter Lauf %s', 'pass-captain' ), $hook ),
				'value' => $beat ? gmdate( 'Y-m-d H:i:s', $beat ) . ' UTC' : __( 'nie', 'pass-captain' ),
			);
		}
		if ( function_exists( 'wp_next_scheduled' ) ) {
			foreach ( self::RECURRING_HOOKS as $hook ) {
				$next = wp_next_scheduled( $hook );
				$fields[ 'next_' . $hook ] = array(
					/* translators: %s: name of the background job (WP-Cron hook, e.g. wpcap_cron); the value column shows the time */
					'label' => sprintf( __( 'Nächster Lauf %s', 'pass-captain' ), $hook ),
					'value' => $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : __( 'nicht eingeplant', 'pass-captain' ),
				);
			}
		}
		$labels = Queues::labels();
		foreach ( array_keys( Queues::available() ) as $queue ) {
			$s = Queues::stats( $queue );
			$counts = array();
			foreach ( $s['counts'] as $status => $n ) { $counts[] = $status . ': ' . $n; }
			$fields[ 'queue_' . $queue ] = array(
				'label' => $labels[ $queue ] ?? $queue,
				/* translators: 1: counts per status, 2: due items, 3: age of the oldest due item in minutes */
				'value' => sprintf( __( '%1$s; fällig %2$d, ältester seit %3$d Min.', 'pass-captain' ), $counts ? implode( ', ', $counts ) : '–', $s['due'], (int) floor( $s['oldest_due_seconds'] / 60 ) ),
			);
		}
		$t = self::thresholds();
		$fields['mail_failures'] = array(
			/* translators: %d: hours */
			'label' => sprintf( __( 'Fehlgeschlagene Plugin-E-Mails (%d h)', 'pass-captain' ), $t['mail_window_hours'] ),
			'value' => (string) count( Monitor::mail_failures( \PassCaptain\Clock::real() - $t['mail_window_hours'] * HOUR_IN_SECONDS ) ),
		);
		if ( self::sms_active() ) {
			$fields['sms_failures'] = array(
				/* translators: %d: hours */
				'label' => sprintf( __( 'Fehlgeschlagene SMS (%d h)', 'pass-captain' ), $t['mail_window_hours'] ),
				'value' => (string) self::sms_failures(),
			);
		}
		foreach ( self::payment_webhooks() as $provider => $data ) {
			$fields[ 'payment_webhook_' . $provider ] = array(
				/* translators: %s: payment provider id */
				'label' => sprintf( __( 'Letzter Zahlungs-Webhook %s', 'pass-captain' ), $provider ),
				'value' => '' !== $data['last'] ? $data['last'] . ' UTC' : __( 'nie', 'pass-captain' ),
			);
		}
		foreach ( array( Monitor::WEBHOOK_OPTION => 'webhook_failures', Monitor::PAYMENT_START_OPTION => 'payment_start_failures' ) as $option => $key ) {
			$entries = Monitor::provider_failures( $option, \PassCaptain\Clock::real() - $t['mail_window_hours'] * HOUR_IN_SECONDS );
			$fields[ $key ] = array(
				'label' => Monitor::WEBHOOK_OPTION === $option
					/* translators: %d: hours */
					? sprintf( __( 'Fehlgeschlagene Zahlungs-Webhooks (%d h)', 'pass-captain' ), $t['mail_window_hours'] )
					/* translators: %d: hours */
					: sprintf( __( 'Fehlgeschlagene Zahlungsstarts (%d h)', 'pass-captain' ), $t['mail_window_hours'] ),
				'value' => (string) Monitor::count_failures( $entries ) . ( $entries ? ' – ' . self::failure_detail( $entries[0] ) : '' ),
			);
		}
		$info['pass-captain-operations'] = array(
			'label'  => __( 'Pass Captain – Betrieb', 'pass-captain' ),
			'fields' => $fields,
		);
		$info['pass-captain-support'] = array(
			'label'       => __( 'Pass Captain – Support', 'pass-captain' ),
			'description' => __( 'Für Support-Anfragen: „Website-Informationen in die Zwischenablage kopieren“ überträgt diese Angaben. Sie enthalten keine Zugangsdaten, Ticketcodes oder Kundendaten.', 'pass-captain' ),
			'fields'      => self::support_fields(),
		);
		return $info;
	}

	/** Payment mode of a provider from its configuration: live, test or not configured ('' = no notion of a mode). */
	public static function provider_mode( $provider ) {
		$provider = (string) $provider;
		$prefix = static function ( $key, array $test, array $live ) {
			$value = (string) \PassCaptain\Secrets::get( $key );
			if ( '' === $value ) { return 'unconfigured'; }
			foreach ( $test as $p ) { if ( str_starts_with( $value, $p ) ) { return 'test'; } }
			foreach ( $live as $p ) { if ( str_starts_with( $value, $p ) ) { return 'live'; } }
			return 'unknown';
		};
		if ( 'stripe' === $provider ) { return $prefix( 'stripe_secret_key', array( 'sk_test_', 'rk_test_' ), array( 'sk_live_', 'rk_live_' ) ); }
		if ( 'mollie' === $provider ) { return $prefix( 'mollie_api_key', array( 'test_' ), array( 'live_' ) ); }
		if ( 'paypal' === $provider ) { return 'live' === (string) Settings::get( 'paypal_mode' ) ? 'live' : 'test'; }
		return '';
	}

	/**
	 * Support export of the Site Health info tab (A329): versions, schema state, modules, payment
	 * providers with mode, WooCommerce, currencies, locale, open holds, debug mode and the last errors
	 * of the support log (redacted when written). Never secrets, codes or customer data.
	 *
	 * @return array<string, array{label: string, value: string}>
	 */
	public static function support_fields() {
		global $wpdb;
		$yes = __( 'ja', 'pass-captain' );
		$no = __( 'nein', 'pass-captain' );
		$f = array();
		$f['version'] = array( 'label' => __( 'Plugin-Version', 'pass-captain' ), 'value' => defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : '?' );
		$stored = (string) get_option( 'wpcap_db_version', '' );
		$expected = \PassCaptain\Installer::version();
		/* translators: 1: stored schema version, 2: schema version of the code */
		$value = sprintf( __( '%1$s (Code: %2$s)', 'pass-captain' ), '' !== $stored ? $stored : '–', $expected );
		$f['db_version'] = array( 'label' => __( 'Datenbankschema', 'pass-captain' ), 'value' => $value . ( $stored === $expected ? '' : ' – ' . __( 'Update ausstehend', 'pass-captain' ) ) );
		$fingerprint = (string) get_option( 'wpcap_schema_fingerprint', '' );
		/* translators: 1: stored fingerprint, 2: expected fingerprint */
		$value = sprintf( __( '%1$s (erwartet %2$s)', 'pass-captain' ), '' !== $fingerprint ? $fingerprint : '–', \PassCaptain\Installer::fingerprint() );
		$f['fingerprint'] = array( 'label' => __( 'Schema-Fingerabdruck', 'pass-captain' ), 'value' => $value );
		$schema_error = get_option( 'wpcap_schema_error', array() );
		$f['schema_error'] = array(
			'label' => __( 'Fehler beim letzten Schema-Update', 'pass-captain' ),
			'value' => is_array( $schema_error ) && ! empty( $schema_error['missing'] )
				/* translators: 1: date and time (UTC), 2: missing tables/columns, 3: database error */
				? sprintf( __( '%1$s UTC: fehlt %2$s %3$s', 'pass-captain' ), gmdate( 'Y-m-d H:i:s', (int) ( $schema_error['at'] ?? 0 ) ), implode( ', ', array_slice( array_map( 'strval', (array) $schema_error['missing'] ), 0, 10 ) ), \PassCaptain\Redact::text( (string) ( $schema_error['db_error'] ?? '' ) ) )
				: __( 'keiner', 'pass-captain' ),
		);
		$modules = array();
		foreach ( Modules::classes() as $class ) {
			$short = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
			$modules[] = $short . ( Modules::enabled( $class ) ? '' : ' (' . __( 'aus', 'pass-captain' ) . ')' );
		}
		$f['modules'] = array( 'label' => __( 'Module', 'pass-captain' ), 'value' => $modules ? implode( ', ', $modules ) : '–' );
		$modes = array(
			'live'         => __( 'Live', 'pass-captain' ),
			'test'         => __( 'Test', 'pass-captain' ),
			'unconfigured' => __( 'nicht eingerichtet', 'pass-captain' ),
			'unknown'      => __( 'unbekannt', 'pass-captain' ),
		);
		$providers = array();
		foreach ( (array) Settings::get( 'enabled_providers' ) as $provider ) {
			$provider = sanitize_key( (string) $provider );
			if ( '' === $provider ) { continue; }
			$mode = self::provider_mode( $provider );
			$providers[] = $provider . ( '' !== $mode ? ' (' . ( $modes[ $mode ] ?? $mode ) . ')' : '' );
		}
		$f['providers'] = array( 'label' => __( 'Zahlungsarten (Modus)', 'pass-captain' ), 'value' => $providers ? implode( ', ', $providers ) : '–' );
		$woo = class_exists( 'WooCommerce' );
		$f['woocommerce'] = array(
			'label' => __( 'WooCommerce', 'pass-captain' ),
			'value' => $woo ? trim( ( defined( 'WC_VERSION' ) ? (string) WC_VERSION : $yes ) . ( function_exists( 'get_woocommerce_currency' ) ? ' · ' . (string) get_woocommerce_currency() : '' ) ) : $no,
		);
		$currencies = (array) $wpdb->get_col( "SELECT DISTINCT currency FROM {$wpdb->prefix}wpcap_ticket_types WHERE status='active' ORDER BY currency LIMIT 10" );
		$f['currencies'] = array( 'label' => __( 'Währungen aktiver Ticketarten', 'pass-captain' ), 'value' => $currencies ? implode( ', ', array_map( 'sanitize_text_field', $currencies ) ) : '–' );
		$f['locale'] = array( 'label' => __( 'Sprache / Zeitzone', 'pass-captain' ), 'value' => ( function_exists( 'get_locale' ) ? (string) get_locale() : '' ) . ' / ' . ( function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '' ) );
		$holds = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(quantity),0) AS q, SUM(expires_at<%s) AS expired FROM {$wpdb->prefix}wpcap_reservations WHERE status='active'", \PassCaptain\Clock::mysql() ) );
		/* translators: 1: open reservations, 2: tickets held, 3: reservations past their expiry */
		$value = $holds ? sprintf( __( '%1$d (%2$d Tickets), davon abgelaufen %3$d', 'pass-captain' ), (int) $holds->n, (int) $holds->q, (int) $holds->expired ) : '–';
		$f['holds'] = array( 'label' => __( 'Offene Reservierungen', 'pass-captain' ), 'value' => $value );
		$f['debug'] = array( 'label' => __( 'Debug-Modus (Support-Protokoll)', 'pass-captain' ), 'value' => \PassCaptain\Log::debug_enabled() ? $yes . ( defined( 'WPCAP_DEBUG' ) ? ' (WPCAP_DEBUG)' : '' ) : $no );
		$f['php'] = array( 'label' => __( 'PHP / Datenbank', 'pass-captain' ), 'value' => PHP_VERSION . ' / ' . ( method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '?' ) );
		$errors = \PassCaptain\Log::entries( array( 'min_level' => 'error', 'limit' => 5 ) );
		$lines = array();
		foreach ( $errors as $e ) {
			$lines[] = $e['created_at'] . ' UTC [' . $e['channel'] . '] ' . $e['reference'] . ' ' . \PassCaptain\Redact::text( (string) $e['message'] );
		}
		$f['last_errors'] = array( 'label' => __( 'Letzte Fehler (Support-Protokoll)', 'pass-captain' ), 'value' => $lines ? implode( ' | ', $lines ) : __( 'keine', 'pass-captain' ) );
		/**
		 * Fields of the "Pass Captain – Support" section of the Site Health info tab (support export).
		 * Never add secrets, ticket codes or personal data.
		 *
		 * @param array $fields Key => {label, value}.
		 */
		return (array) apply_filters( 'wpcap_support_info', $f );
	}
}
