<?php
/**
 * Lightweight operational signals for the Site Health checks (A164):
 * - cron heartbeat: time of the last run of the queue cron hooks (one option, written at most once a minute),
 * - mail failures: failed wp_mail() calls of the central plugin mailer (Mail\Mailer::deliver()), kept
 *   seven days / at most 100 entries; stored are time, mail type and error code, the error text only
 *   with e-mail addresses masked – never recipients or content,
 * - payment webhook failures per provider (A323): signature/payload rejections, processing errors and
 *   crashes; and payment start failures per provider (A324). Same storage rules as the mail
 *   failures; repeated failures of the same kind within a minute are merged (counter `n`), so a
 *   flood of invalid requests keeps one entry per minute.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Operations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Monitor {
	const HEARTBEAT_OPTION = 'wpcap_ops_heartbeat';
	const MAIL_OPTION = 'wpcap_ops_mail_failures';
	const MAIL_KEEP_DAYS = 7;
	const MAIL_MAX = 100;
	const WEBHOOK_OPTION = 'wpcap_ops_webhook_failures';
	const PAYMENT_START_OPTION = 'wpcap_ops_payment_start_failures';
	const EVENTS_MAX = 200;

	/** Cron hooks whose runs are recorded. */
	const HEARTBEAT_HOOKS = array( 'wpcap_process_jobs', 'wpcap_process_messages' );

	/** Error of wp_mail_failed during the current plugin mail (null outside of a plugin mail). */
	private static $mail_error = null;

	/** Last failed plugin mail of this request: ['type', 'code', 'error'] (A325), null = none. */
	private static $last_mail_failure = null;

	public static function register() {
		foreach ( self::HEARTBEAT_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'heartbeat' ), -999, 0 );
		}
	}

	/** Records that the current cron hook started (at most one write per hook and minute). */
	public static function heartbeat( $hook = null, $now = null ) {
		$hook = null === $hook ? ( function_exists( 'current_filter' ) ? (string) current_filter() : '' ) : (string) $hook;
		if ( ! in_array( $hook, self::HEARTBEAT_HOOKS, true ) ) { return; }
		$now = null === $now ? time() : (int) $now;
		$beats = get_option( self::HEARTBEAT_OPTION, array() );
		$beats = is_array( $beats ) ? $beats : array();
		if ( isset( $beats[ $hook ] ) && $now - (int) $beats[ $hook ] < 60 ) { return; }
		$beats[ $hook ] = $now;
		update_option( self::HEARTBEAT_OPTION, $beats, false );
	}

	/** Unix time of the last recorded run of a hook (0 = never) – or of any recorded hook when $hook is null. */
	public static function last_heartbeat( $hook = null ) {
		$beats = get_option( self::HEARTBEAT_OPTION, array() );
		$beats = is_array( $beats ) ? array_map( 'intval', $beats ) : array();
		if ( null !== $hook ) { return (int) ( $beats[ (string) $hook ] ?? 0 ); }
		return $beats ? max( $beats ) : 0;
	}

	/** Called by the mailer right before wp_mail(): listens for wp_mail_failed. */
	public static function mail_begin() {
		self::$mail_error = '';
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ), 10, 1 );
	}

	/** wp_mail_failed listener (only active during a plugin mail). */
	public static function capture_mail_error( $error ) {
		if ( null === self::$mail_error ) { return; }
		self::$mail_error = is_wp_error( $error ) ? (string) $error->get_error_code() . '|' . (string) $error->get_error_message() : 'wp_mail_failed|';
	}

	/** Called by the mailer after wp_mail(): records a failure. */
	public static function mail_end( $sent, $type ) {
		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ), 10 );
		$error = (string) self::$mail_error;
		self::$mail_error = null;
		if ( $sent ) { return; }
		list( $code, $message ) = array_pad( explode( '|', $error, 2 ), 2, '' );
		$code = '' !== $code ? $code : 'wp_mail_false';
		self::$last_mail_failure = array( 'type' => sanitize_key( (string) $type ), 'code' => sanitize_key( $code ), 'error' => Queues::safe_error( (string) $message ) );
		self::record_mail_failure( (string) $type, $code, $message );
	}

	/** Forgets the last mail failure of this request (before a job or queued mail runs). */
	public static function reset_last_mail_failure() {
		self::$last_mail_failure = null;
	}

	/** The last mail failure of this request, null when none since the last reset. */
	public static function last_mail_failure() {
		return self::$last_mail_failure;
	}

	/** One-line description of the last mail failure ('' when none): "wp_mail: code – error". */
	public static function last_mail_failure_text() {
		$f = self::$last_mail_failure;
		if ( ! $f ) { return ''; }
		return trim( 'wp_mail (' . $f['type'] . '): ' . $f['code'] . ( '' !== $f['error'] ? ' – ' . $f['error'] : '' ) );
	}

	/** Stores one mail failure (type and code sanitised, error text masked). */
	public static function record_mail_failure( $type, $code, $message = '', $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$list = self::mail_failures_raw();
		$list[] = array(
			't'     => $now,
			'type'  => substr( sanitize_key( (string) $type ), 0, 64 ),
			'code'  => substr( sanitize_key( (string) $code ), 0, 64 ),
			'error' => Queues::safe_error( (string) $message ),
		);
		$cutoff = time() - self::MAIL_KEEP_DAYS * DAY_IN_SECONDS;
		$list = array_values( array_filter( $list, static fn( $e ) => (int) $e['t'] >= $cutoff ) );
		update_option( self::MAIL_OPTION, array_slice( $list, -self::MAIL_MAX ), false );
	}

	private static function mail_failures_raw() {
		$list = get_option( self::MAIL_OPTION, array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Records a provider failure (webhook or payment start). Entries of the same provider, kind and
	 * code within one minute are merged (n). Returns true when a new entry was started (callers log
	 * only then, so a flood of invalid requests does not flood the support log).
	 *
	 * @param string $option   WEBHOOK_OPTION or PAYMENT_START_OPTION.
	 * @param string $provider Provider id.
	 * @param string $kind     signature, payload, verify, processing, exception, start.
	 * @param string $code     Error code.
	 * @param string $message  Error text (redacted, e-mail addresses masked, at most 190 characters).
	 * @param string $reference Support log reference ('' = none).
	 */
	public static function record_provider_failure( $option, $provider, $kind, $code, $message = '', $reference = '', $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$list = self::raw( $option );
		$entry = array(
			't'        => $now,
			'provider' => substr( sanitize_key( (string) $provider ), 0, 32 ),
			'kind'     => substr( sanitize_key( (string) $kind ), 0, 32 ),
			'code'     => substr( sanitize_key( (string) $code ), 0, 64 ),
			'error'    => Queues::safe_error( (string) $message ),
			'ref'      => preg_match( '/^[a-f0-9]{12}$/', (string) $reference ) ? (string) $reference : '',
			'n'        => 1,
		);
		$last = $list ? count( $list ) - 1 : -1;
		$merged = false;
		for ( $i = $last; $i >= 0 && $i >= $last - 10; $i-- ) {
			$e = $list[ $i ];
			if ( ( $e['provider'] ?? '' ) === $entry['provider'] && ( $e['kind'] ?? '' ) === $entry['kind'] && ( $e['code'] ?? '' ) === $entry['code'] && $now - (int) ( $e['t'] ?? 0 ) < 60 ) {
				$list[ $i ]['n'] = (int) ( $e['n'] ?? 1 ) + 1;
				$list[ $i ]['error'] = $entry['error'];
				if ( '' !== $entry['ref'] ) { $list[ $i ]['ref'] = $entry['ref']; }
				$merged = true;
				break;
			}
		}
		if ( ! $merged ) { $list[] = $entry; }
		$cutoff = $now - self::MAIL_KEEP_DAYS * DAY_IN_SECONDS;
		$list = array_values( array_filter( $list, static fn( $e ) => (int) $e['t'] >= $cutoff ) );
		update_option( (string) $option, array_slice( $list, -self::EVENTS_MAX ), false );
		return ! $merged;
	}

	/**
	 * Provider failures since a Unix time, newest first.
	 *
	 * @return array[] t, provider, kind, code, error, ref, n.
	 */
	public static function provider_failures( $option, $since, $provider = '' ) {
		$list = array_values( array_filter( self::raw( $option ), static fn( $e ) => (int) ( $e['t'] ?? 0 ) >= (int) $since && ( '' === (string) $provider || ( $e['provider'] ?? '' ) === (string) $provider ) ) );
		return array_reverse( $list );
	}

	/** Sum of the counters (n) of failure entries, optionally of one kind. */
	public static function count_failures( array $entries, $kind = '' ) {
		$n = 0;
		foreach ( $entries as $e ) {
			if ( '' === (string) $kind || ( $e['kind'] ?? '' ) === (string) $kind ) { $n += max( 1, (int) ( $e['n'] ?? 1 ) ); }
		}
		return $n;
	}

	private static function raw( $option ) {
		$list = get_option( (string) $option, array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/** Mail failures since a Unix time, newest first. */
	public static function mail_failures( $since ) {
		$list = array_values( array_filter( self::mail_failures_raw(), static fn( $e ) => (int) ( $e['t'] ?? 0 ) >= (int) $since ) );
		return array_reverse( $list );
	}
}
