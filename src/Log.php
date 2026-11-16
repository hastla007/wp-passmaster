<?php
/**
 * Central support log (A327): one place for errors, warnings and – in debug mode – diagnostic
 * messages of the plugin, with levels, channels, a reference ("Fehler-ID") per entry and the
 * order/ticket/occurrence an entry belongs to.
 *
 * - Storage: table {prefix}wpcap_logs (schema 3.10.16). Before the upgrade, or when the insert fails,
 *   entries go to the PHP error log (Redact::log()); entries of level error and above always go there
 *   too, so the host's log keeps them.
 * - Levels: debug < info < notice < warning < error < critical. Without debug mode only info and
 *   above are stored; debug mode (constant WPCAP_DEBUG, or the setting "Fehlersuche-Protokoll",
 *   for 24 hours at most per switch-on) also stores debug entries. Filter `wpcap_log_min_level`.
 * - Redaction: message and context pass through Redact (secrets, card numbers, IBANs, e-mail
 *   addresses); keys that carry ticket codes or access tokens (token, code, access…) never reach the
 *   context. Callers pass ids and codes, never names or full addresses.
 * - Rotation: entries older than RETENTION_DAYS (filter `wpcap_log_retention_days`; debug/info
 *   entries after LOW_RETENTION_DAYS) and beyond MAX_ROWS are removed by the hourly housekeeping;
 *   the entries of an anonymised order with it.
 * - Every stored entry is announced via the action `wpcap_log` (external log shipping).
 *
 * Entries written inside a database transaction share its fate (a rollback removes them); write
 * them after the rollback, as the plugin's own callers do.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Log {
	const TABLE = 'wpcap_logs';
	const LEVELS = array( 'debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500 );
	const RETENTION_DAYS = 30;
	/** Days debug and info entries (e.g. the mail log) are kept at most, so they never crowd out errors. */
	const LOW_RETENTION_DAYS = 7;
	const MAX_ROWS = 20000;
	/** Hours a debug mode switched on in the settings stays active. */
	const DEBUG_HOURS = 24;
	/** Context keys that may hold ticket codes, access links or tokens: always dropped. */
	const DROP_KEY_PATTERN = '/(token|^code$|ticket_code|help_code|pass_code|voucher_code|access|link|url$|^email$|_email$|recipient|^name$|_name$|phone|address|iban)/i';

	/** Option: time the debug mode was switched on in the settings. */
	const DEBUG_SINCE_OPTION = 'wpcap_debug_logging_since';

	/** Request id: groups the entries of one request. */
	private static $request_id = '';
	/** Stack of scoped context (order_id, ticket_id, occurrence_id). */
	private static $scope = array();
	/** Whether the table exists (per prefix; only a positive answer is cached). */
	private static $ready = array();

	public static function register() {
		add_action( 'wpcap_cleanup_reservations', array( __CLASS__, 'purge' ), 70, 0 );
		add_action( 'wpcap_privacy_order_anonymized', array( __CLASS__, 'forget_order' ), 10, 1 );
		add_action( 'update_option_wpcap_settings', array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_wpcap_settings', array( __CLASS__, 'settings_added' ), 10, 2 );
	}

	/** Remembers when the debug mode setting was switched on (it ends after DEBUG_HOURS). */
	public static function settings_changed( $old, $new ) {
		$was = is_array( $old ) && ! empty( $old['debug_logging'] );
		$is = is_array( $new ) && ! empty( $new['debug_logging'] );
		if ( $is && ! $was ) { update_option( self::DEBUG_SINCE_OPTION, time(), false ); }
	}

	public static function settings_added( $name, $value ) {
		self::settings_changed( array(), $value );
	}

	/** Schema 3.10.16 present. */
	public static function ready() {
		global $wpdb;
		$key = (string) $wpdb->prefix;
		if ( empty( self::$ready[ $key ] ) ) {
			$table = $wpdb->prefix . self::TABLE;
			self::$ready[ $key ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		return self::$ready[ $key ];
	}

	/**
	 * Whether a column of schema 3.10.16 exists: wpcap_message_queue.order_id/sent_at and
	 * wpcap_jobs.order_id (only a positive answer is cached).
	 */
	public static function has_column( $table, $column ) {
		static $cache = array();
		global $wpdb;
		$key = $wpdb->prefix . $table . '.' . $column;
		if ( empty( $cache[ $key ] ) ) {
			$cache[ $key ] = null !== $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}" . sanitize_key( $table ) . ' LIKE %s', $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitised table name.
		}
		return $cache[ $key ];
	}

	/** Reference of a log entry: 12 hex characters, shown to buyers as "Fehler-ID". */
	public static function new_reference() {
		return substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
	}

	/** Id of the current request (12 hex characters, the same for all entries of this request). */
	public static function request_id() {
		if ( '' === self::$request_id ) { self::$request_id = self::new_reference(); }
		return self::$request_id;
	}

	/** Whether debug mode is on: constant WPCAP_DEBUG, or the setting switched on less than DEBUG_HOURS ago. */
	public static function debug_enabled() {
		if ( defined( 'WPCAP_DEBUG' ) ) { return (bool) constant( 'WPCAP_DEBUG' ); }
		if ( ! Settings::get( 'debug_logging' ) ) { return false; }
		$since = (int) get_option( self::DEBUG_SINCE_OPTION, 0 );
		return $since > 0 && time() - $since < self::DEBUG_HOURS * HOUR_IN_SECONDS;
	}

	/** Lowest stored level. */
	public static function min_level() {
		$level = self::debug_enabled() ? 'debug' : 'info';
		/**
		 * Lowest level the support log stores (debug, info, notice, warning, error, critical).
		 *
		 * @param string $level Default: debug in debug mode, else info.
		 */
		$level = (string) apply_filters( 'wpcap_log_min_level', $level );
		return isset( self::LEVELS[ $level ] ) ? $level : 'info';
	}

	/** Whether entries of $level are stored. */
	public static function enabled( $level ) {
		$level = (string) $level;
		return isset( self::LEVELS[ $level ] ) && self::LEVELS[ $level ] >= self::LEVELS[ self::min_level() ];
	}

	/**
	 * Runs $callback with a context (order_id, ticket_id, occurrence_id) that entries without their
	 * own ids – including the mail log of Mail\Mailer – are attributed to.
	 *
	 * @param array    $context  Ids.
	 * @param callable $callback Work.
	 */
	public static function scope( array $context, callable $callback ) {
		self::$scope[] = self::ids( $context );
		try {
			return $callback();
		} finally {
			array_pop( self::$scope );
		}
	}

	/** Ids of the innermost scope. */
	public static function current_scope() {
		return self::$scope ? (array) end( self::$scope ) : array();
	}

	/** order_id/ticket_id/occurrence_id of a context (positive integers only). */
	private static function ids( array $context ) {
		$out = array();
		foreach ( array( 'order_id', 'ticket_id', 'occurrence_id' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_numeric( $context[ $key ] ) && (int) $context[ $key ] > 0 ) { $out[ $key ] = (int) $context[ $key ]; }
		}
		return $out;
	}

	/** Context without keys that may hold codes, tokens or personal data; values redacted. */
	public static function clean_context( array $context ) {
		$out = array();
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::DROP_KEY_PATTERN, $key ) ) { continue; }
			$out[ $key ] = is_array( $value ) ? self::clean_context( $value ) : $value;
		}
		return (array) Redact::value( $out );
	}

	/**
	 * Writes an entry. Returns its reference ('' when the level is not stored).
	 *
	 * @param string $level   debug|info|notice|warning|error|critical.
	 * @param string $channel Area, e.g. checkout, payment, webhook, job, mail.
	 * @param string $message Short text (redacted, at most 500 characters).
	 * @param array  $context Structured data; order_id/ticket_id/occurrence_id are stored as columns,
	 *                        `reference` reuses a given reference.
	 */
	public static function write( $level, $channel, $message, array $context = array() ) {
		$level = isset( self::LEVELS[ (string) $level ] ) ? (string) $level : 'error';
		if ( ! self::enabled( $level ) ) { return ''; }
		$reference = isset( $context['reference'] ) && preg_match( '/^[a-f0-9]{12}$/', (string) $context['reference'] ) ? (string) $context['reference'] : self::new_reference();
		unset( $context['reference'] );
		$ids = self::ids( $context ) + self::current_scope();
		foreach ( array_keys( $ids ) as $key ) { unset( $context[ $key ] ); }
		$channel = substr( sanitize_key( (string) $channel ), 0, 32 );
		$message = Redact::text( str_replace( array( "\r", "\n" ), ' ', (string) $message ) );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
		$context = self::clean_context( $context );
		$entry = array(
			'created_at'    => current_time( 'mysql', true ),
			'level'         => $level,
			'channel'       => '' !== $channel ? $channel : 'general',
			'reference'     => $reference,
			'request_id'    => self::request_id(),
			'order_id'      => (int) ( $ids['order_id'] ?? 0 ),
			'ticket_id'     => (int) ( $ids['ticket_id'] ?? 0 ),
			'occurrence_id' => (int) ( $ids['occurrence_id'] ?? 0 ),
			'message'       => $message,
			'context'       => $context ? (string) wp_json_encode( $context ) : '',
		);
		$stored = false;
		if ( self::ready() ) {
			global $wpdb;
			$stored = false !== $wpdb->insert( $wpdb->prefix . self::TABLE, $entry, array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ) );
		}
		if ( ! $stored || self::LEVELS[ $level ] >= self::LEVELS['error'] ) {
			$ids_text = array_filter( array( 'order' => $entry['order_id'], 'ticket' => $entry['ticket_id'], 'occurrence' => $entry['occurrence_id'] ) );
			Redact::log( '[' . $level . '][' . $entry['channel'] . '] ' . $reference . ' ' . $message, $ids_text + $context );
		}
		/**
		 * An entry was written to the support log (already redacted).
		 *
		 * @param array $entry created_at, level, channel, reference, request_id, order_id, ticket_id,
		 *                     occurrence_id, message, context (JSON).
		 */
		do_action( 'wpcap_log', $entry );
		return $reference;
	}

	public static function debug( $channel, $message, array $context = array() ) { return self::write( 'debug', $channel, $message, $context ); }

	public static function info( $channel, $message, array $context = array() ) { return self::write( 'info', $channel, $message, $context ); }

	public static function warning( $channel, $message, array $context = array() ) { return self::write( 'warning', $channel, $message, $context ); }

	public static function error( $channel, $message, array $context = array() ) { return self::write( 'error', $channel, $message, $context ); }

	/**
	 * Logs a throwable (class, code, file:line, message; no trace) with the last database error.
	 * Returns the reference.
	 */
	public static function exception( $channel, \Throwable $e, array $context = array(), $level = 'error' ) {
		global $wpdb;
		$context += array(
			'exception'      => get_class( $e ),
			'exception_code' => (string) $e->getCode(),
			'where'          => basename( $e->getFile() ) . ':' . $e->getLine(),
		);
		if ( ! isset( $context['db_error'] ) && isset( $wpdb ) && is_object( $wpdb ) && '' !== (string) $wpdb->last_error ) {
			$context['db_error'] = (string) $wpdb->last_error;
		}
		return self::write( $level, $channel, Redact::exception( $e ), $context );
	}

	/**
	 * Entries, newest first.
	 *
	 * @param array $args order_id, reference, request_id, channel, min_level, limit (default 50), offset.
	 * @return array[]
	 */
	public static function entries( array $args = array() ) {
		global $wpdb;
		if ( ! self::ready() ) { return array(); }
		$where = array( '1=1' );
		$params = array();
		foreach ( array( 'order_id', 'ticket_id', 'occurrence_id' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) { $where[] = "$key=%d"; $params[] = absint( $args[ $key ] ); }
		}
		foreach ( array( 'reference', 'request_id' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) { $where[] = "$key=%s"; $params[] = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $args[ $key ] ) ); }
		}
		if ( ! empty( $args['channel'] ) ) { $where[] = 'channel=%s'; $params[] = sanitize_key( (string) $args['channel'] ); }
		if ( ! empty( $args['min_level'] ) && isset( self::LEVELS[ (string) $args['min_level'] ] ) ) {
			$levels = array_keys( array_filter( self::LEVELS, static fn( $v ) => $v >= self::LEVELS[ (string) $args['min_level'] ] ) );
			$where[] = 'level IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
			$params = array_merge( $params, $levels );
		}
		if ( ! empty( $args['since'] ) ) { $where[] = 'created_at>=%s'; $params[] = (string) $args['since']; }
		$params[] = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$params[] = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql = "SELECT * FROM {$wpdb->prefix}wpcap_logs WHERE " . implode( ' AND ', $where ) . ' ORDER BY log_id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		return array_map( static function ( $row ) {
			$row['context'] = '' !== (string) $row['context'] ? (array) json_decode( (string) $row['context'], true ) : array();
			return $row;
		}, (array) $rows );
	}

	/** Removes entries older than the retention and beyond MAX_ROWS. Returns removed rows. */
	public static function purge( $now = null ) {
		global $wpdb;
		if ( ! self::ready() ) { return 0; }
		$now = null === $now ? time() : (int) $now;
		/**
		 * Days the support log keeps entries (default 30, at least 1).
		 *
		 * @param int $days Days.
		 */
		$days = max( 1, (int) apply_filters( 'wpcap_log_retention_days', self::RETENTION_DAYS ) );
		$table = $wpdb->prefix . self::TABLE;
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at<%s LIMIT 5000", gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$low = min( $days, self::LOW_RETENTION_DAYS );
		$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE level IN ('debug','info') AND created_at<%s LIMIT 5000", gmdate( 'Y-m-d H:i:s', $now - $low * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$cut = $wpdb->get_var( $wpdb->prepare( "SELECT log_id FROM $table ORDER BY log_id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		if ( $cut ) {
			$removed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE log_id<=%d", (int) $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		}
		return $removed;
	}

	/** Privacy erasure of an order: its log entries go too. */
	public static function forget_order( $order_id ) {
		global $wpdb;
		if ( ! self::ready() || ! absint( $order_id ) ) { return 0; }
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpcap_logs WHERE order_id=%d", absint( $order_id ) ) );
	}

	/** Display names of the levels. */
	public static function level_labels() {
		return array(
			'debug'    => __( 'Debug', 'pass-captain' ),
			'info'     => __( 'Info', 'pass-captain' ),
			'notice'   => __( 'Hinweis', 'pass-captain' ),
			'warning'  => __( 'Warnung', 'pass-captain' ),
			'error'    => __( 'Fehler', 'pass-captain' ),
			'critical' => __( 'Kritisch', 'pass-captain' ),
		);
	}
}
