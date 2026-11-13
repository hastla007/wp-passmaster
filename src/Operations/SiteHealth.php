<?php
/**
 * Operations monitoring through WordPress Site Health (A164): tests for WP-Cron, queue backlogs and
 * dead letters, plugin mail failures, SMS provider failures and payment webhook freshness, the
 * "WP Pass Captain – Betrieb" section of the Site Health info tab, and an admin notice on plugin pages
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
		);
		/**
		 * Thresholds of the WP Pass Captain Site Health checks.
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
				'label' => __( 'WP Pass Captain', 'wp-pass-captain' ),
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
		);
		if ( self::sms_active() ) { $tests['sms'] = array( __CLASS__, 'test_sms' ); }
		return $tests;
	}

	private static function sms_active() {
		return Modules::enabled( 'PassCaptain\\Modules\\Sms' ) && Privacy::has_table( 'wpcap_sms_messages' ) && \PassCaptain\Sms\Service::enabled();
	}

	/** Site Health result array. */
	private static function result( $key, $status, $label, $description, $actions = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'WP Pass Captain', 'wp-pass-captain' ),
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
			return sprintf( _n( '%d Minute', '%d Minuten', $minutes, 'wp-pass-captain' ), $minutes );
		}
		return self::hours( intdiv( $minutes, 60 ) );
	}

	/** "1 Stunde", "24 Stunden". */
	public static function hours( $hours ) {
		$hours = max( 0, (int) $hours );
		/* translators: %d: number of hours */
		return sprintf( _n( '%d Stunde', '%d Stunden', $hours, 'wp-pass-captain' ), $hours );
	}

	private static function p( $text ) { return '<p>' . esc_html( $text ) . '</p>'; }

	private static function list_html( array $lines ) {
		return $lines ? '<ul>' . implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $lines ) ) . '</ul>' : '';
	}

	private static function operations_link() {
		return '<p><a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\OperationsPage::SLUG ) ) . '">' . esc_html__( 'Zur Betriebsübersicht von WP Pass Captain', 'wp-pass-captain' ) . '</a></p>';
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
		$now = null === $now ? time() : (int) $now;
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
			$lines[] = sprintf( __( '%1$s ist seit %2$s überfällig.', 'wp-pass-captain' ), $hook, self::duration( $minutes ) );
		}
		foreach ( $state['missing'] as $hook ) {
			/* translators: %s: cron hook name */
			$lines[] = sprintf( __( '%s ist nicht eingeplant.', 'wp-pass-captain' ), $hook );
		}
		$silent = $state['disabled'] && null !== $state['since'] && $state['since'] >= $t['cron_critical_minutes'];
		if ( $silent ) {
			/* translators: %s: duration, e.g. "3 Stunden" */
			$lines[] = sprintf( __( 'DISABLE_WP_CRON ist gesetzt und die Warteschlangen liefen seit %s nicht mehr – offenbar löst kein externer Cronjob WP-Cron aus.', 'wp-pass-captain' ), self::duration( $state['since'] ) );
		}
		$intro = $state['disabled'] ? __( 'WP-Cron ist per DISABLE_WP_CRON abgeschaltet; ein System-Cronjob muss wp-cron.php regelmäßig aufrufen.', 'wp-pass-captain' ) : __( 'WP-Cron läuft bei Seitenaufrufen. Für verlässliche Ticketmails, Erinnerungen und Webhooks empfehlen wir einen System-Cronjob.', 'wp-pass-captain' );
		if ( null !== $state['since'] ) {
			/* translators: %s: duration, e.g. "4 Minuten" */
			$intro .= ' ' . sprintf( __( 'Letzter Lauf der Warteschlangen vor %s.', 'wp-pass-captain' ), self::duration( $state['since'] ) );
		}
		$actions = '<p><a href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/" target="_blank" rel="noopener">' . esc_html__( 'Externen Cronjob einrichten', 'wp-pass-captain' ) . '</a></p>';
		if ( $critical || $silent ) {
			return self::result( 'cron', 'critical', __( 'WP Pass Captain: geplante Aufgaben laufen nicht', 'wp-pass-captain' ), self::p( $intro ) . self::list_html( $lines ), $actions );
		}
		if ( $lines ) {
			return self::result( 'cron', 'recommended', __( 'WP Pass Captain: geplante Aufgaben verspätet', 'wp-pass-captain' ), self::p( $intro ) . self::list_html( $lines ), $actions );
		}
		return self::result( 'cron', 'good', __( 'WP Pass Captain: geplante Aufgaben laufen pünktlich', 'wp-pass-captain' ), self::p( $intro ) );
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
				$lines[] = sprintf( __( '%1$s: fällig %2$d (ältester wartet seit %3$s), fehlgeschlagen %4$d.', 'wp-pass-captain' ), $labels[ $queue ] ?? $queue, $s['due'], self::duration( $age ), $s['failed'] );
			}
		}
		if ( 'good' === $status ) {
			return self::result( 'queues', 'good', __( 'WP Pass Captain: keine Rückstände in den Warteschlangen', 'wp-pass-captain' ), self::p( __( 'Hintergrundjobs, E-Mails und Webhooks werden zeitnah verarbeitet; es gibt keine endgültig fehlgeschlagenen Einträge.', 'wp-pass-captain' ) ) );
		}
		$label = 'critical' === $status ? __( 'WP Pass Captain: große Rückstände oder viele fehlgeschlagene Einträge', 'wp-pass-captain' ) : __( 'WP Pass Captain: Rückstände oder fehlgeschlagene Einträge in den Warteschlangen', 'wp-pass-captain' );
		return self::result( 'queues', $status, $label, self::p( __( 'Prüfe die Einträge und versuche sie erneut oder verwirf sie.', 'wp-pass-captain' ) ) . self::list_html( $lines ), self::operations_link() );
	}

	/* ------------------------------------------------------------------ Mail */

	public static function test_mail( $now = null ) {
		$t = self::thresholds();
		$now = null === $now ? time() : (int) $now;
		$failures = Monitor::mail_failures( $now - $t['mail_window_hours'] * HOUR_IN_SECONDS );
		$count = count( $failures );
		if ( 0 === $count || ( $t['mail_failures'] > 0 && $count < $t['mail_failures'] ) ) {
			/* translators: %s: period, e.g. "24 Stunden" */
			$text = sprintf( __( 'Innerhalb von %s ist kein Versand einer Plugin-E-Mail fehlgeschlagen.', 'wp-pass-captain' ), self::hours( $t['mail_window_hours'] ) );
			return self::result( 'mail', 'good', __( 'WP Pass Captain: E-Mail-Versand ohne Fehler', 'wp-pass-captain' ), self::p( $text ) );
		}
		$last = $failures[0];
		/* translators: 1: number of failed mails, 2: period, e.g. "24 Stunden" */
		$text = sprintf( _n( 'Innerhalb von %2$s ist %1$d Plugin-E-Mail nicht versendet worden (wp_mail() meldete einen Fehler).', 'Innerhalb von %2$s sind %1$d Plugin-E-Mails nicht versendet worden (wp_mail() meldete einen Fehler).', $count, 'wp-pass-captain' ), $count, self::hours( $t['mail_window_hours'] ) );
		/* translators: 1: mail type, 2: error code, 3: error message */
		$detail = sprintf( __( 'Letzter Fehler: Typ %1$s, Code %2$s %3$s', 'wp-pass-captain' ), (string) $last['type'], (string) $last['code'], (string) $last['error'] );
		$status = $t['mail_failures_critical'] > 0 && $count >= $t['mail_failures_critical'] ? 'critical' : 'recommended';
		return self::result( 'mail', $status, __( 'WP Pass Captain: E-Mails werden nicht zugestellt', 'wp-pass-captain' ), self::p( $text ) . self::p( trim( $detail ) ) . self::p( __( 'Prüfe die SMTP-Einstellungen bzw. das Mail-Plugin der Website.', 'wp-pass-captain' ) ), self::operations_link() );
	}

	/* ------------------------------------------------------------------ SMS */

	/** SMS failures (failed/undelivered) in the mail window. */
	public static function sms_failures( $now = null ) {
		global $wpdb;
		if ( ! Privacy::has_table( 'wpcap_sms_messages' ) ) { return 0; }
		$t = self::thresholds();
		$now = null === $now ? time() : (int) $now;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_sms_messages WHERE status IN ('failed','undelivered') AND updated_at>=%s", gmdate( 'Y-m-d H:i:s', $now - $t['mail_window_hours'] * HOUR_IN_SECONDS ) ) );
	}

	public static function test_sms( $now = null ) {
		$t = self::thresholds();
		$count = self::sms_failures( $now );
		if ( 0 === $count || ( $t['sms_failures'] > 0 && $count < $t['sms_failures'] ) ) {
			/* translators: %s: period, e.g. "24 Stunden" */
			$text = sprintf( __( 'Innerhalb von %s hat der SMS-Anbieter keinen Fehler gemeldet.', 'wp-pass-captain' ), self::hours( $t['mail_window_hours'] ) );
			return self::result( 'sms', 'good', __( 'WP Pass Captain: SMS-Versand ohne Fehler', 'wp-pass-captain' ), self::p( $text ) );
		}
		$status = $t['sms_failures_critical'] > 0 && $count >= $t['sms_failures_critical'] ? 'critical' : 'recommended';
		/* translators: 1: number of SMS, 2: period, e.g. "24 Stunden" */
		$text = sprintf( _n( 'Innerhalb von %2$s ist %1$d SMS fehlgeschlagen oder nicht zugestellt worden.', 'Innerhalb von %2$s sind %1$d SMS fehlgeschlagen oder nicht zugestellt worden.', $count, 'wp-pass-captain' ), $count, self::hours( $t['mail_window_hours'] ) );
		return self::result( 'sms', $status, __( 'WP Pass Captain: SMS-Versand fehlerhaft', 'wp-pass-captain' ), self::p( $text ) . self::p( __( 'Prüfe Zugangsdaten und Guthaben beim SMS-Anbieter.', 'wp-pass-captain' ) ), '<p><a href="' . esc_url( Forms::page_url( 'wpcap-sms' ) ) . '">' . esc_html__( 'SMS-Einstellungen', 'wp-pass-captain' ) . '</a></p>' );
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
		$now = null === $now ? time() : (int) $now;
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
		$now = null === $now ? time() : (int) $now;
		$lines = array();
		foreach ( self::payment_webhooks( $now ) as $provider => $info ) {
			$fresh = '' !== $info['last'] && strtotime( $info['last'] . ' UTC' ) >= $now - $t['payment_webhook_hours'] * HOUR_IN_SECONDS;
			if ( $info['orders'] > 0 && ! $fresh ) {
				$lines[] = '' === $info['last']
					/* translators: 1: payment provider id, 2: number of orders, 3: period, e.g. "48 Stunden" */
					? sprintf( __( '%1$s: Bestellungen innerhalb von %3$s: %2$d, aber noch nie eine Webhook-Benachrichtigung erhalten.', 'wp-pass-captain' ), $provider, $info['orders'], self::hours( $t['payment_webhook_hours'] ) )
					/* translators: 1: payment provider id, 2: number of orders, 3: period, e.g. "48 Stunden", 4: date and time (UTC) */
					: sprintf( __( '%1$s: Bestellungen innerhalb von %3$s: %2$d, letzte Webhook-Benachrichtigung am %4$s (UTC).', 'wp-pass-captain' ), $provider, $info['orders'], self::hours( $t['payment_webhook_hours'] ), $info['last'] );
			}
		}
		if ( $lines ) {
			return self::result( 'payment_webhooks', 'recommended', __( 'WP Pass Captain: keine aktuellen Zahlungs-Webhooks', 'wp-pass-captain' ), self::p( __( 'Zahlungen werden über Webhooks des Zahlungsanbieters bestätigt. Prüfe die Webhook-URL und das Signatur-Secret im Dashboard des Anbieters.', 'wp-pass-captain' ) ) . self::list_html( $lines ) );
		}
		return self::result( 'payment_webhooks', 'good', __( 'WP Pass Captain: Zahlungs-Webhooks kommen an', 'wp-pass-captain' ), self::p( __( 'Für alle aktiven Zahlungsanbieter mit aktuellen Bestellungen sind Webhook-Benachrichtigungen eingegangen.', 'wp-pass-captain' ) ) );
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
		if ( ( ! str_starts_with( $page, 'wpcap' ) && 'wp-pass-captain' !== $page ) || ! current_user_can( 'manage_options' ) ) { return; }
		$labels = self::critical_labels();
		if ( ! $labels ) { return; }
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'WP Pass Captain: Der Betrieb ist gestört.', 'wp-pass-captain' ) . '</strong></p>' . self::list_html( $labels ) . '<p><a href="' . esc_url( Forms::page_url( \PassCaptain\Admin\OperationsPage::SLUG ) ) . '">' . esc_html__( 'Betriebsübersicht öffnen', 'wp-pass-captain' ) . '</a> · <a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Website-Zustand', 'wp-pass-captain' ) . '</a></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- list_html escapes.
	}

	/** Section of the Site Health info tab (filter debug_information). */
	public static function debug_information( $info ) {
		$info = is_array( $info ) ? $info : array();
		$fields = array();
		$fields['disable_wp_cron'] = array(
			'label' => 'DISABLE_WP_CRON',
			'value' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'ja', 'wp-pass-captain' ) : __( 'nein', 'wp-pass-captain' ),
		);
		foreach ( Monitor::HEARTBEAT_HOOKS as $hook ) {
			$beat = Monitor::last_heartbeat( $hook );
			$fields[ 'heartbeat_' . $hook ] = array(
				/* translators: %s: name of the background job (WP-Cron hook, e.g. wpcap_cron); the value column shows the time */
				'label' => sprintf( __( 'Letzter Lauf %s', 'wp-pass-captain' ), $hook ),
				'value' => $beat ? gmdate( 'Y-m-d H:i:s', $beat ) . ' UTC' : __( 'nie', 'wp-pass-captain' ),
			);
		}
		if ( function_exists( 'wp_next_scheduled' ) ) {
			foreach ( self::RECURRING_HOOKS as $hook ) {
				$next = wp_next_scheduled( $hook );
				$fields[ 'next_' . $hook ] = array(
					/* translators: %s: name of the background job (WP-Cron hook, e.g. wpcap_cron); the value column shows the time */
					'label' => sprintf( __( 'Nächster Lauf %s', 'wp-pass-captain' ), $hook ),
					'value' => $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : __( 'nicht eingeplant', 'wp-pass-captain' ),
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
				'value' => sprintf( __( '%1$s; fällig %2$d, ältester seit %3$d Min.', 'wp-pass-captain' ), $counts ? implode( ', ', $counts ) : '–', $s['due'], (int) floor( $s['oldest_due_seconds'] / 60 ) ),
			);
		}
		$t = self::thresholds();
		$fields['mail_failures'] = array(
			/* translators: %d: hours */
			'label' => sprintf( __( 'Fehlgeschlagene Plugin-E-Mails (%d h)', 'wp-pass-captain' ), $t['mail_window_hours'] ),
			'value' => (string) count( Monitor::mail_failures( time() - $t['mail_window_hours'] * HOUR_IN_SECONDS ) ),
		);
		if ( self::sms_active() ) {
			$fields['sms_failures'] = array(
				/* translators: %d: hours */
				'label' => sprintf( __( 'Fehlgeschlagene SMS (%d h)', 'wp-pass-captain' ), $t['mail_window_hours'] ),
				'value' => (string) self::sms_failures(),
			);
		}
		foreach ( self::payment_webhooks() as $provider => $data ) {
			$fields[ 'payment_webhook_' . $provider ] = array(
				/* translators: %s: payment provider id */
				'label' => sprintf( __( 'Letzter Zahlungs-Webhook %s', 'wp-pass-captain' ), $provider ),
				'value' => '' !== $data['last'] ? $data['last'] . ' UTC' : __( 'nie', 'wp-pass-captain' ),
			);
		}
		$info['wp-pass-captain-operations'] = array(
			'label'  => __( 'WP Pass Captain – Betrieb', 'wp-pass-captain' ),
			'fields' => $fields,
		);
		return $info;
	}
}
