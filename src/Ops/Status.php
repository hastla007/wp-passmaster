<?php
/**
 * Read model of the system status page: queue backlogs (jobs, e-mails, webhooks, SMS), failed
 * items, WP-Cron health with worker heartbeats, and the list of critical issues that drives the
 * admin notice. Also the controlled queue actions "retry" and "discard" (capability and nonce
 * checks are done by the admin page; every action is audited).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Ops;

use PassCaptain\Audit;
use PassCaptain\Installer;
use PassCaptain\Refunds;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Status {
	/** A due item waiting longer than this (seconds) counts as overdue: the worker is not keeping up. */
	const OVERDUE_AFTER = 30 * MINUTE_IN_SECONDS;

	/** A claim (locked_at) older than this (seconds) is shown as long-running. */
	const LONG_RUNNING_AFTER = 5 * MINUTE_IN_SECONDS;

	/** Workers re-queue claims older than this (seconds); older claims mean no worker ran since. */
	const STALE_CLAIM_AFTER = 15 * MINUTE_IN_SECONDS;

	/** A cron event more than this many seconds past its due time is overdue. */
	const CRON_LATE_AFTER = 10 * MINUTE_IN_SECONDS;

	/** Transient caching the critical issues for the admin notice. */
	const ISSUES_TRANSIENT = 'wpcap_ops_issues';

	/** Per-request cache of table existence. */
	private static $tables = array();

	/**
	 * Queue definitions: key => table suffix, id/type/due columns, claimed/ready/failed/discarded
	 * status, whether the table has updated_at.
	 */
	public static function queues() {
		return array(
			'jobs'     => array( 'label' => __( 'Job-Queue', 'pass-captain' ), 'table' => 'wpcap_jobs', 'id' => 'job_id', 'type' => 'job_type', 'due' => 'run_after', 'claimed' => 'running', 'ready' => 'queued', 'failed' => 'dead_letter', 'discarded' => 'cancelled', 'updated' => 'updated_at' ),
			'messages' => array( 'label' => __( 'E-Mail-Queue', 'pass-captain' ), 'table' => 'wpcap_message_queue', 'id' => 'message_id', 'type' => 'template_key', 'due' => 'send_at', 'claimed' => 'sending', 'ready' => 'queued', 'failed' => 'failed', 'discarded' => 'cancelled', 'updated' => '' ),
			'webhooks' => array( 'label' => __( 'Webhook-Zustellungen', 'pass-captain' ), 'table' => 'wpcap_webhook_deliveries', 'id' => 'delivery_id', 'type' => 'event_type', 'due' => 'next_attempt_at', 'claimed' => 'delivering', 'ready' => 'pending', 'failed' => 'failed', 'discarded' => 'cancelled', 'updated' => 'updated_at' ),
			'sms'      => array( 'label' => __( 'SMS-Queue', 'pass-captain' ), 'table' => 'wpcap_sms_messages', 'id' => 'message_id', 'type' => 'purpose', 'due' => 'send_at', 'claimed' => 'sending', 'ready' => 'queued', 'failed' => 'failed', 'discarded' => 'skipped', 'updated' => 'updated_at' ),
		);
	}

	/** Whether a plugin table exists (module tables are optional). */
	public static function table_exists( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		if ( ! array_key_exists( $table, self::$tables ) ) {
			self::$tables[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', addcslashes( $table, '_%\\' ) ) );
		}
		return self::$tables[ $table ];
	}

	/** Forgets the table cache (tests). */
	public static function flush_cache() { self::$tables = array(); }

	private static function gmt( $offset_seconds = 0, $now = null ) {
		return gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - (int) $offset_seconds );
	}

	/**
	 * Backlog of one queue.
	 *
	 * @return array|null {label, counts: status => n (finished statuses omitted), overdue, long_running, stale_claims}; null if the table does not exist.
	 */
	public static function queue_summary( $queue, $now = null ) {
		global $wpdb;
		$q = self::queues()[ $queue ] ?? null;
		if ( ! $q || ! self::table_exists( $q['table'] ) ) { return null; }
		$t = $wpdb->prefix . $q['table'];
		$finished = array( 'complete', 'sent', 'succeeded', 'cancelled', 'skipped', 'delivered' );
		$counts = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $t ) ) as $row ) {
			if ( ! in_array( $row->status, $finished, true ) ) { $counts[ $row->status ] = (int) $row->n; }
		}
		return array(
			'label'        => $q['label'],
			'counts'       => $counts,
			'overdue'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status=%s AND {$q['due']}<%s", $q['ready'], self::gmt( self::OVERDUE_AFTER, $now ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'long_running' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s AND locked_at<%s', $t, $q['claimed'], self::gmt( self::LONG_RUNNING_AFTER, $now ) ) ),
			'stale_claims' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s AND locked_at<%s', $t, $q['claimed'], self::gmt( self::STALE_CLAIM_AFTER, $now ) ) ),
			'failed'       => $counts[ $q['failed'] ] ?? 0,
		);
	}

	/** Open jobs by status and type: rows {status, job_type, n, oldest_due}. */
	public static function job_backlog() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, job_type, COUNT(*) AS n, MIN(run_after) AS oldest_due FROM %i WHERE status NOT IN ('complete','cancelled') GROUP BY status, job_type ORDER BY status, job_type", $wpdb->prefix . 'wpcap_jobs' ) );
	}

	/**
	 * Failed items of a queue (jobs: dead_letter), newest first.
	 *
	 * @return object[] {id, type, attempts, last_error, updated, recipient?}
	 */
	public static function failed_items( $queue, $limit = 50 ) {
		global $wpdb;
		$q = self::queues()[ $queue ] ?? null;
		if ( ! $q || ! self::table_exists( $q['table'] ) ) { return array(); }
		$t = $wpdb->prefix . $q['table'];
		$updated = $q['updated'] ? $q['updated'] : 'created_at';
		$extra = 'messages' === $queue ? ', recipient' : ( 'webhooks' === $queue ? ', response_code, subscription_id' : '' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$q['id']} AS id, {$q['type']} AS type, attempts, last_error, $updated AS updated $extra FROM $t WHERE status=%s ORDER BY {$q['id']} DESC LIMIT %d", $q['failed'], max( 1, absint( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Recent e-mail transport failures: failed or retrying messages with their last error, grouped. */
	public static function email_failures( $limit = 10 ) {
		global $wpdb;
		if ( ! self::table_exists( 'wpcap_message_queue' ) ) { return array(); }
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT last_error, COUNT(*) AS n, MAX(created_at) AS latest FROM %i WHERE status IN ('failed','queued') AND last_error IS NOT NULL AND last_error<>'' GROUP BY last_error ORDER BY n DESC LIMIT %d", $wpdb->prefix . 'wpcap_message_queue', max( 1, absint( $limit ) ) ) );
	}

	/**
	 * Requeues a failed queue item ("erneut versuchen"): attempts reset, due now. Audited.
	 *
	 * @return true|\WP_Error
	 */
	public static function retry( $queue, $id ) {
		global $wpdb;
		$q = self::queues()[ $queue ] ?? null;
		$id = absint( $id );
		if ( ! $q || ! $id || ! self::table_exists( $q['table'] ) ) { return new \WP_Error( 'wpcap_ops_invalid', __( 'Unbekannter Eintrag.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$t = $wpdb->prefix . $q['table'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE {$q['id']}=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || $q['failed'] !== $row->status ) {
			return new \WP_Error( 'wpcap_ops_state', __( 'Der Eintrag ist nicht (mehr) fehlgeschlagen und wurde nicht verändert.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( 'webhooks' === $queue ) {
			$result = \PassCaptain\Webhooks\Dispatcher::redeliver( $id );
			if ( is_wp_error( $result ) ) { return $result; }
		} else {
			$now = current_time( 'mysql', true );
			$data = array( 'status' => $q['ready'], 'attempts' => 0, $q['due'] => $now, 'locked_at' => null );
			if ( $q['updated'] ) { $data[ $q['updated'] ] = $now; }
			$changed = $wpdb->update( $t, $data, array( $q['id'] => $id, 'status' => $q['failed'] ) );
			if ( 1 !== (int) $changed ) {
				return new \WP_Error( 'wpcap_ops_state', __( 'Der Eintrag ist nicht (mehr) fehlgeschlagen und wurde nicht verändert.', 'pass-captain' ), array( 'status' => 409 ) );
			}
		}
		Audit::record( 'ops_queue_retry', $queue, $id, array( 'type' => (string) $row->{$q['type']}, 'attempts' => (int) $row->attempts, 'last_error' => substr( (string) $row->last_error, 0, 200 ) ) );
		self::flush_issues();
		return true;
	}

	/** Default cap of a bulk retry per click (filter wpcap_ops_bulk_retry_cap, 1–500). */
	const BULK_RETRY_CAP = 100;

	/** Maximum number of items one bulk retry requeues. */
	public static function bulk_retry_cap() {
		return min( 500, max( 1, (int) apply_filters( 'wpcap_ops_bulk_retry_cap', self::BULK_RETRY_CAP ) ) );
	}

	/** Number of failed (dead-letter) items of a queue. */
	public static function failed_count( $queue ) {
		global $wpdb;
		$q = self::queues()[ $queue ] ?? null;
		if ( ! $q || ! self::table_exists( $q['table'] ) ) { return 0; }
		$t = $wpdb->prefix . $q['table'];
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s', $t, $q['failed'] ) );
	}

	/**
	 * Requeues the failed items of a queue, oldest first, at most bulk_retry_cap() per call (each
	 * item like retry(), audited per item, plus one summary audit entry).
	 *
	 * @return array|\WP_Error {retried, skipped, remaining, cap}
	 */
	public static function retry_all( $queue ) {
		global $wpdb;
		$q = self::queues()[ $queue ] ?? null;
		if ( ! $q || ! self::table_exists( $q['table'] ) ) { return new \WP_Error( 'wpcap_ops_invalid', __( 'Unbekannte Warteschlange.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$t = $wpdb->prefix . $q['table'];
		$cap = self::bulk_retry_cap();
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT {$q['id']} FROM $t WHERE status=%s ORDER BY {$q['id']} ASC LIMIT %d", $q['failed'], $cap ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$retried = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			if ( true === self::retry( $queue, $id ) ) { ++$retried; } else { ++$skipped; }
		}
		$remaining = self::failed_count( $queue );
		Audit::record( 'ops_queue_retry_bulk', $queue, 0, array( 'retried' => $retried, 'skipped' => $skipped, 'remaining' => $remaining, 'cap' => $cap ) );
		self::flush_issues();
		return array( 'retried' => $retried, 'skipped' => $skipped, 'remaining' => $remaining, 'cap' => $cap );
	}

	/**
	 * Discards a failed queue item ("verwerfen"): it stays as history with a final status. Audited.
	 *
	 * @return true|\WP_Error
	 */
	public static function discard( $queue, $id ) {
		global $wpdb;
		$q = self::queues()[ $queue ] ?? null;
		$id = absint( $id );
		if ( ! $q || ! $id || ! self::table_exists( $q['table'] ) ) { return new \WP_Error( 'wpcap_ops_invalid', __( 'Unbekannter Eintrag.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$t = $wpdb->prefix . $q['table'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE {$q['id']}=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data = array( 'status' => $q['discarded'], 'locked_at' => null );
		if ( $q['updated'] ) { $data[ $q['updated'] ] = current_time( 'mysql', true ); }
		$changed = $row && $q['failed'] === $row->status ? $wpdb->update( $t, $data, array( $q['id'] => $id, 'status' => $q['failed'] ) ) : 0;
		if ( 1 !== (int) $changed ) {
			return new \WP_Error( 'wpcap_ops_state', __( 'Der Eintrag ist nicht (mehr) fehlgeschlagen und wurde nicht verändert.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		Audit::record( 'ops_queue_discard', $queue, $id, array( 'type' => (string) $row->{$q['type']}, 'attempts' => (int) $row->attempts, 'last_error' => substr( (string) $row->last_error, 0, 200 ) ) );
		self::flush_issues();
		return true;
	}

	/** Recurring plugin cron hooks: hook => [label, interval seconds, heartbeat worker or ''] . */
	public static function cron_hooks() {
		return array(
			'wpcap_process_jobs'         => array( __( 'Jobs und Webhooks', 'pass-captain' ), 5 * MINUTE_IN_SECONDS, 'jobs' ),
			'wpcap_process_messages'     => array( __( 'E-Mails, Erinnerungen und SMS', 'pass-captain' ), 5 * MINUTE_IN_SECONDS, 'messages' ),
			'wpcap_cleanup_reservations' => array( __( 'Abgelaufene Reservierungen', 'pass-captain' ), HOUR_IN_SECONDS, 'cleanup' ),
			'wpcap_privacy_retention'    => array( __( 'Datenschutz-Aufbewahrung', 'pass-captain' ), DAY_IN_SECONDS, 'retention' ),
		);
	}

	/** Whether WP-Cron is disabled (an external cron must call wp-cron.php or `wp cron event run`). */
	public static function cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * WP-Cron health per plugin hook.
	 *
	 * @param int|null   $now           Current time (tests).
	 * @param array|null $next_runs     hook => next run timestamp|false (tests; default wp_next_scheduled()).
	 * @param bool|null  $cron_disabled Overrides DISABLE_WP_CRON (tests).
	 * @return array[] {hook, label, next_run, last_beat, processed, state: ok|warning|error, message}
	 */
	public static function cron_health( $now = null, $next_runs = null, $cron_disabled = null ) {
		$now = $now ?? time();
		$cron_disabled = null === $cron_disabled ? self::cron_disabled() : (bool) $cron_disabled;
		$rows = array();
		foreach ( self::cron_hooks() as $hook => list( $label, $interval, $worker ) ) {
			if ( is_array( $next_runs ) ) {
				$next = $next_runs[ $hook ] ?? false;
			} else {
				$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : null;
			}
			$beat = $worker ? Heartbeat::last( $worker ) : null;
			$stale = $worker && ( ! $beat || $beat['time'] < $now - Heartbeat::STALE_FACTOR * $interval );
			$state = 'ok';
			$message = '';
			if ( false === $next ) {
				$state = 'error';
				$message = __( 'Nicht geplant. Das Plugin plant das Ereignis beim nächsten Seitenaufruf neu; sonst Plugin deaktivieren und wieder aktivieren.', 'pass-captain' );
			} elseif ( $stale && $beat && $cron_disabled ) {
				$state = 'error';
				$message = __( 'WP-Cron ist deaktiviert (DISABLE_WP_CRON) und der Worker lief seit längerem nicht: externen Cron-Job (wp-cron.php oder „wp cron event run --due-now“) prüfen.', 'pass-captain' );
			} elseif ( $stale && $beat ) {
				$state = 'error';
				$message = __( 'Der Worker lief seit längerem nicht. WP-Cron wird vermutlich nicht ausgelöst (wenig Besucher, Caching, Loopback blockiert): einen echten Server-Cron einrichten.', 'pass-captain' );
			} elseif ( $stale && $cron_disabled ) {
				$state = 'warning';
				$message = __( 'WP-Cron ist deaktiviert und es wurde noch kein Worker-Lauf erfasst: externen Cron-Job prüfen.', 'pass-captain' );
			} elseif ( $stale ) {
				$state = 'warning';
				$message = __( 'Noch kein Worker-Lauf erfasst.', 'pass-captain' );
			} elseif ( null !== $next && (int) $next < $now - self::CRON_LATE_AFTER ) {
				$state = 'warning';
				$message = __( 'Der geplante Lauf ist überfällig: WP-Cron wird nicht regelmäßig ausgelöst.', 'pass-captain' );
			}
			$rows[] = array( 'hook' => $hook, 'label' => $label, 'next_run' => $next ? (int) $next : 0, 'last_beat' => $beat ? $beat['time'] : 0, 'processed' => $beat ? $beat['processed'] : 0, 'state' => $state, 'message' => $message );
		}
		return $rows;
	}

	/**
	 * Problems that need an operator, most severe first. Severity "error" triggers the admin notice.
	 *
	 * @return array[] {severity: error|warning, message}
	 */
	public static function issues( $now = null ) {
		global $wpdb;
		$issues = array();
		$error = get_option( Installer::ERROR_OPTION );
		if ( is_array( $error ) && $error ) {
			$issues[] = array( 'severity' => 'error', 'message' => __( 'Die letzte Datenbank-Aktualisierung ist fehlgeschlagen.', 'pass-captain' ) );
		}
		$newer = get_option( Installer::NEWER_OPTION );
		if ( $newer ) {
			/* translators: %s: schema version in the database */
			$issues[] = array( 'severity' => 'error', 'message' => sprintf( __( 'Die Datenbank stammt von einer neueren Plugin-Version (Schema %s) – Restore erforderlich: neuere Plugin-Version installieren oder Datensicherung wiederherstellen.', 'pass-captain' ), (string) $newer ) );
		}
		foreach ( array( 'jobs', 'messages', 'webhooks', 'sms' ) as $queue ) {
			$s = self::queue_summary( $queue, $now );
			if ( ! $s ) { continue; }
			$severity = 'webhooks' === $queue ? 'warning' : 'error'; // Webhook endpoints are external systems.
			if ( $s['failed'] > 0 ) {
				/* translators: 1: queue name, 2: number of items */
				$issues[] = array( 'severity' => $severity, 'message' => sprintf( __( '%1$s: %2$d endgültig fehlgeschlagene Einträge.', 'pass-captain' ), $s['label'], $s['failed'] ) );
			}
			if ( $s['overdue'] > 0 ) {
				/* translators: 1: queue name, 2: number of items */
				$issues[] = array( 'severity' => $severity, 'message' => sprintf( __( '%1$s: %2$d fällige Einträge warten seit über 30 Minuten (Rückstand).', 'pass-captain' ), $s['label'], $s['overdue'] ) );
			}
			if ( $s['stale_claims'] > 0 ) {
				/* translators: 1: queue name, 2: number of items */
				$issues[] = array( 'severity' => 'warning', 'message' => sprintf( __( '%1$s: %2$d Einträge hängen seit über 15 Minuten in Bearbeitung.', 'pass-captain' ), $s['label'], $s['stale_claims'] ) );
			}
		}
		foreach ( self::cron_health( $now ) as $row ) {
			if ( 'ok' !== $row['state'] ) {
				$issues[] = array( 'severity' => $row['state'], 'message' => $row['label'] . ' (' . $row['hook'] . '): ' . $row['message'] );
			}
		}
		$unbooked = Refunds::unbooked_count();
		if ( $unbooked > 0 ) {
			/* translators: %d: number of refunds */
			$issues[] = array( 'severity' => 'error', 'message' => sprintf( __( '%d Erstattungen wurden ausgezahlt, aber noch nicht verbucht.', 'pass-captain' ), $unbooked ) );
		}
		$stuck = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='processing' AND provider_reference='' AND updated_at<%s", $wpdb->prefix . 'wpcap_refunds', self::gmt( self::STALE_CLAIM_AFTER, $now ) ) );
		if ( $stuck > 0 ) {
			/* translators: %d: number of refunds */
			$issues[] = array( 'severity' => 'error', 'message' => sprintf( __( '%d Erstattungen hängen in „processing“ ohne Anbieter-Referenz und brauchen eine manuelle Prüfung.', 'pass-captain' ), $stuck ) );
		}
		// Server isolation other than REPEATABLE READ (the plugin's inventory transactions force it themselves).
		$issues = array_merge( $issues, \PassCaptain\DbSession::status_issues() );
		// QR encoder (bacon/bacon-qr-code, not namespace-prefixed): another plugin may have loaded its own copy first.
		$foreign = \PassCaptain\QrCode::foreign_encoder();
		$qr_ok   = (bool) \PassCaptain\QrCode::matrix( 'pass-captain' );
		if ( '' !== $foreign || ! $qr_ok ) {
			$issues[] = array(
				'severity' => $qr_ok ? 'warning' : 'error',
				'message'  => $qr_ok
					/* translators: %s: file path of the foreign QR library */
					? sprintf( __( 'Die QR-Bibliothek (BaconQrCode) wird von einem anderen Plugin geladen (%s). QR-Codes funktionieren derzeit; nach Updates des anderen Plugins bitte Ticket-QR-Codes prüfen.', 'pass-captain' ), $foreign )
					/* translators: %s: file path of the foreign QR library, may be empty */
					: sprintf( __( 'QR-Codes können nicht erzeugt werden: Die QR-Bibliothek (BaconQrCode) fehlt oder ein anderes Plugin lädt eine inkompatible Version (%s). Tickets zeigen dann keinen QR-Code.', 'pass-captain' ), '' !== $foreign ? $foreign : '-' ),
			);
		}
		// Behind a proxy/CDN without trusted proxies every buyer shares a few addresses (checkout rate limit).
		$proxy = \PassCaptain\Sales\CheckoutThrottle::status_issue();
		if ( $proxy ) { $issues[] = $proxy; }
		// Sender of customer mails (From/Reply-To/Return-Path): missing or not aligned with the site domain.
		$issues = array_merge( $issues, \PassCaptain\Mail\Mailer::sender_issues() );
		usort( $issues, static function ( $a, $b ) { return ( 'error' === $b['severity'] ) <=> ( 'error' === $a['severity'] ); } );
		return $issues;
	}

	/** Issues with severity "error", cached for the admin notice (5 minutes). */
	public static function critical_issues() {
		$cached = get_transient( self::ISSUES_TRANSIENT );
		if ( is_array( $cached ) ) { return $cached; }
		$critical = array_values( array_filter( self::issues(), static function ( $i ) { return 'error' === $i['severity']; } ) );
		set_transient( self::ISSUES_TRANSIENT, $critical, 5 * MINUTE_IN_SECONDS );
		return $critical;
	}

	/** Drops the cached issues after an operator action. */
	public static function flush_issues() {
		if ( function_exists( 'delete_transient' ) ) { delete_transient( self::ISSUES_TRANSIENT ); }
	}
}
