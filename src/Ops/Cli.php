<?php
/**
 * WP-CLI commands for operations and monitoring:
 *
 *   wp pass-captain diagnose [--repair=<check>] [--repair-all] [--format=<table|json>] [--strict]
 *   wp pass-captain status [--format=<table|json>] [--strict]
 *
 * With --strict the command exits with status 1 when a check reports a problem (diagnose) or a
 * critical issue exists (status), so it can drive an external monitor or a cron e-mail.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Ops;

use PassCaptain\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Cli {
	public static function register() {
		if ( ! class_exists( '\WP_CLI' ) ) { return; }
		\WP_CLI::add_command( 'pass-captain diagnose', array( __CLASS__, 'diagnose' ), array( 'shortdesc' => 'Runs the Pass Captain diagnose/reconciliation checks and optional safe repairs.' ) );
		\WP_CLI::add_command( 'pass-captain status', array( __CLASS__, 'status' ), array( 'shortdesc' => 'Shows queue backlogs, cron health and critical issues of Pass Captain.' ) );
	}

	/**
	 * Builds the diagnose output; repairs run first when requested.
	 *
	 * @return array {rows: check rows, repairs: key => repaired|error message, problems: int}
	 */
	public static function diagnose_data( array $assoc ) {
		$repairs = array();
		$keys = array();
		if ( ! empty( $assoc['repair-all'] ) ) {
			$keys = array_keys( array_filter( Diagnostics::checks(), static function ( $c ) { return $c[1]; } ) );
		} elseif ( ! empty( $assoc['repair'] ) ) {
			$keys = array_map( 'sanitize_key', explode( ',', (string) $assoc['repair'] ) );
		}
		foreach ( $keys as $key ) {
			$before = Diagnostics::run( array( $key ) );
			if ( isset( $before['checks'][ $key ] ) && 0 === $before['checks'][ $key ]['count'] ) { continue; }
			$result = Diagnostics::repair( $key );
			$repairs[ $key ] = is_wp_error( $result ) ? $result->get_error_message() : (int) $result['repaired'];
		}
		$report = Diagnostics::run_and_store();
		$rows = array();
		$problems = 0;
		foreach ( $report['checks'] as $check ) {
			if ( 'ok' !== $check['severity'] ) { ++$problems; }
			$rows[] = array(
				'check'      => $check['key'],
				'count'      => $check['count'],
				'severity'   => $check['severity'],
				'repairable' => $check['repairable'] ? 'yes' : 'no',
				'samples'    => implode( '; ', array_map( static function ( $s ) { return http_build_query( $s, '', ' ' ); }, array_slice( $check['samples'], 0, 3 ) ) ),
				'action'     => '' !== $check['error'] ? $check['error'] : $check['action'],
			);
		}
		return array( 'rows' => $rows, 'repairs' => $repairs, 'problems' => $problems );
	}

	/** `wp pass-captain diagnose` */
	public static function diagnose( $args, $assoc ) {
		$data = self::diagnose_data( (array) $assoc );
		foreach ( $data['repairs'] as $key => $result ) {
			\WP_CLI::log( is_int( $result ) ? sprintf( 'Repaired %s: %d record(s).', $key, $result ) : sprintf( 'Repair %s failed: %s', $key, $result ) );
		}
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $data['rows'], array( 'check', 'count', 'severity', 'repairable', 'samples', 'action' ) );
		}
		if ( $data['problems'] && ! empty( $assoc['strict'] ) ) { \WP_CLI::halt( 1 ); }
	}

	/** Status data: issues, cron health and queue summaries. */
	public static function status_data() {
		$queues = array();
		foreach ( array_keys( Status::queues() ) as $queue ) {
			$s = Status::queue_summary( $queue );
			if ( $s ) { $queues[ $queue ] = $s; }
		}
		return array( 'issues' => Status::issues(), 'cron' => Status::cron_health(), 'queues' => $queues );
	}

	/** `wp pass-captain status` */
	public static function status( $args, $assoc ) {
		$data = self::status_data();
		$critical = count( array_filter( $data['issues'], static function ( $i ) { return 'error' === $i['severity']; } ) );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $data ) );
		} else {
			$rows = array();
			foreach ( $data['queues'] as $queue => $s ) {
				$counts = array();
				foreach ( $s['counts'] as $status => $n ) { $counts[] = $status . '=' . $n; }
				$rows[] = array( 'queue' => $queue, 'open' => implode( ' ', $counts ), 'overdue' => $s['overdue'], 'long_running' => $s['long_running'], 'failed' => $s['failed'] );
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'queue', 'open', 'overdue', 'long_running', 'failed' ) );
			$cron = array_map( static function ( $r ) { return array( 'hook' => $r['hook'], 'next_run' => $r['next_run'] ? gmdate( 'Y-m-d H:i:s', $r['next_run'] ) . ' UTC' : '-', 'last_run' => $r['last_beat'] ? gmdate( 'Y-m-d H:i:s', $r['last_beat'] ) . ' UTC' : '-', 'state' => $r['state'], 'message' => $r['message'] ); }, $data['cron'] );
			\WP_CLI\Utils\format_items( 'table', $cron, array( 'hook', 'next_run', 'last_run', 'state', 'message' ) );
			foreach ( $data['issues'] as $issue ) { \WP_CLI::log( strtoupper( $issue['severity'] ) . ': ' . $issue['message'] ); }
			if ( ! $data['issues'] ) { \WP_CLI::success( 'No issues.' ); }
		}
		if ( $critical && ! empty( $assoc['strict'] ) ) { \WP_CLI::halt( 1 ); }
	}
}
