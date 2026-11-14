<?php
/**
 * Worker heartbeats: every queue worker records the time of its last completed run, so the
 * system status page can tell "cron is scheduled" apart from "cron actually runs".
 *
 * One option per worker (not one shared array), so parallel workers never overwrite each other.
 * The options are not autoloaded; they are only read by the status page and the admin notice.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Ops;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Heartbeat {
	const OPTION_PREFIX = 'wpcap_heartbeat_';

	/**
	 * Known workers: key => [label, cron hook, expected interval in seconds].
	 * A heartbeat older than STALE_FACTOR intervals counts as stale.
	 */
	public static function workers() {
		return array(
			'jobs'     => array( __( 'Job-Queue', 'pass-captain' ), 'wpcap_process_jobs', 5 * MINUTE_IN_SECONDS ),
			'messages' => array( __( 'E-Mail-Queue', 'pass-captain' ), 'wpcap_process_messages', 5 * MINUTE_IN_SECONDS ),
			'webhooks' => array( __( 'Webhook-Zustellung', 'pass-captain' ), 'wpcap_process_jobs', 5 * MINUTE_IN_SECONDS ),
			'sms'      => array( __( 'SMS-Queue', 'pass-captain' ), 'wpcap_process_messages', 5 * MINUTE_IN_SECONDS ),
			'cleanup'  => array( __( 'Reservierungs-Bereinigung', 'pass-captain' ), 'wpcap_cleanup_reservations', HOUR_IN_SECONDS ),
		);
	}

	/** A heartbeat older than this many expected intervals is stale. */
	const STALE_FACTOR = 4;

	/** Registers heartbeats for hooks whose workers have no single exit point (late priority). */
	public static function register() {
		add_action( 'wpcap_cleanup_reservations', array( __CLASS__, 'beat_cleanup' ), 1000, 0 );
	}

	/** Cron callback for the hourly reservation cleanup. */
	public static function beat_cleanup() { self::beat( 'cleanup' ); }

	/**
	 * Records that $worker finished a run.
	 *
	 * @param string $worker    Worker key (see workers()).
	 * @param int    $processed Items processed in this run.
	 */
	public static function beat( $worker, $processed = 0 ) {
		$worker = sanitize_key( $worker );
		if ( '' === $worker || ! function_exists( 'update_option' ) ) { return; }
		update_option( self::OPTION_PREFIX . $worker, array( 'time' => time(), 'processed' => (int) $processed ), false );
	}

	/**
	 * Last heartbeat of $worker.
	 *
	 * @return array|null {time:int, processed:int} or null when the worker never reported.
	 */
	public static function last( $worker ) {
		$value = get_option( self::OPTION_PREFIX . sanitize_key( $worker ), null );
		if ( ! is_array( $value ) || empty( $value['time'] ) ) { return null; }
		return array( 'time' => (int) $value['time'], 'processed' => (int) ( $value['processed'] ?? 0 ) );
	}

	/** Whether the heartbeat of $worker is missing or older than STALE_FACTOR intervals. */
	public static function is_stale( $worker, $now = null ) {
		$workers = self::workers();
		$interval = isset( $workers[ $worker ] ) ? (int) $workers[ $worker ][2] : 5 * MINUTE_IN_SECONDS;
		$last = self::last( $worker );
		return ! $last || $last['time'] < ( $now ?? time() ) - self::STALE_FACTOR * $interval;
	}
}
