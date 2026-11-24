<?php
/**
 * "Verlauf" of an order (A328): one timeline for support from the audit log (order and its tickets),
 * the payment events, the queued and sent e-mails, the background jobs and the support log, newest
 * first. The order id is the correlation id across these sources (schema 3.10.16 stores it on
 * queued messages and jobs; older jobs are matched by their payload). Shown are types, states,
 * errors and references – never ticket codes, recipients or mail content.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Log;
use PassCaptain\Privacy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class OrderHistory {
	/** Most entries read per source and shown in total. */
	const LIMIT = 150;

	/**
	 * Timeline entries, newest first.
	 *
	 * @return array[] at (UTC), source (audit|payment|mail|job|log), label, detail, status, reference.
	 */
	public static function entries( $order_id, $limit = self::LIMIT ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$limit = max( 1, (int) $limit );
		$p = $wpdb->prefix;
		$out = array();
		if ( ! $order_id ) { return $out; }

		$ticket_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ticket_id FROM {$p}wpcap_tickets WHERE order_id=%d LIMIT 500", $order_id ) ) );
		$ticket_sql = $ticket_ids ? " OR (object_type='ticket' AND object_id IN (" . implode( ',', $ticket_ids ) . '))' : '';
		$audit = $wpdb->get_results( $wpdb->prepare( "SELECT action,object_type,object_id,user_id,details,created_at FROM {$p}wpcap_audit_logs WHERE (object_type='order' AND object_id=%d)$ticket_sql ORDER BY audit_id DESC LIMIT %d", $order_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( (array) $audit as $row ) {
			$details = json_decode( (string) $row->details, true );
			$out[] = array(
				'at'        => (string) $row->created_at,
				'source'    => 'audit',
				'label'     => (string) $row->action . ( 'ticket' === $row->object_type ? ' · ' . sprintf( /* translators: %d: ticket id */ __( 'Ticket #%d', 'pass-captain' ), (int) $row->object_id ) : '' ),
				'detail'    => is_array( $details ) && $details ? self::summary( $details ) : '',
				'status'    => (int) $row->user_id ? sprintf( /* translators: %d: WordPress user id */ __( 'Benutzer #%d', 'pass-captain' ), (int) $row->user_id ) : '',
				'reference' => '',
			);
		}

		if ( Privacy::has_table( 'wpcap_payment_events' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT provider,provider_event_id,event_type,status,created_at FROM {$p}wpcap_payment_events WHERE order_id=%d ORDER BY payment_event_id DESC LIMIT %d", $order_id, $limit ) ) as $row ) {
				$out[] = array(
					'at'        => (string) $row->created_at,
					'source'    => 'payment',
					'label'     => (string) $row->provider . ' · ' . (string) $row->event_type,
					'detail'    => substr( (string) $row->provider_event_id, 0, 64 ),
					'status'    => (string) $row->status,
					'reference' => '',
				);
			}
		}

		if ( Log::has_column( 'wpcap_message_queue', 'order_id' ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT message_id,template_key,status,attempts,last_error,sent_at,created_at FROM {$p}wpcap_message_queue WHERE order_id=%d ORDER BY message_id DESC LIMIT %d", $order_id, $limit ) ) as $row ) {
				$out[] = array(
					'at'        => (string) ( $row->sent_at ?: $row->created_at ),
					'source'    => 'mail',
					'label'     => (string) $row->template_key,
					/* translators: 1: queue item id, 2: number of attempts */
					'detail'    => trim( sprintf( __( 'Warteschlange #%1$d, %2$d Versuche', 'pass-captain' ), (int) $row->message_id, (int) $row->attempts ) . ( '' !== (string) $row->last_error ? ' – ' . \PassCaptain\Operations\Queues::safe_error( (string) $row->last_error ) : '' ) ),
					'status'    => (string) $row->status,
					'reference' => '',
				);
			}
		}

		$job_where = Log::has_column( 'wpcap_jobs', 'order_id' ) ? $wpdb->prepare( 'order_id=%d OR ', $order_id ) : '';
		// Jobs queued before schema 3.10.16 have no order_id: matched by their payload ({"order_id":N,…}).
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT job_id,job_type,status,attempts,last_error,created_at,updated_at FROM {$p}wpcap_jobs WHERE {$job_where}(payload LIKE %s AND payload REGEXP %s) ORDER BY job_id DESC LIMIT %d", '%' . addcslashes( '"order_id":' . $order_id, '_%\\' ) . '%', '"order_id":' . $order_id . '[,}]', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared fragment.
		foreach ( (array) $jobs as $row ) {
			$out[] = array(
				'at'        => (string) $row->updated_at,
				'source'    => 'job',
				'label'     => (string) $row->job_type,
				/* translators: 1: job id, 2: number of runs */
				'detail'    => trim( sprintf( __( 'Job #%1$d, %2$d Läufe', 'pass-captain' ), (int) $row->job_id, (int) $row->attempts ) . ( '' !== (string) $row->last_error ? ' – ' . \PassCaptain\Operations\Queues::safe_error( (string) $row->last_error ) : '' ) ),
				'status'    => (string) $row->status,
				'reference' => '',
			);
		}

		foreach ( Log::entries( array( 'order_id' => $order_id, 'limit' => $limit ) ) as $row ) {
			$out[] = array(
				'at'        => (string) $row['created_at'],
				'source'    => 'log',
				'label'     => (string) $row['channel'],
				'detail'    => (string) $row['message'],
				'status'    => (string) $row['level'],
				'reference' => (string) $row['reference'],
			);
		}

		usort( $out, static function ( $a, $b ) { return strcmp( $b['at'], $a['at'] ); } );
		return array_slice( $out, 0, $limit );
	}

	/** "key: value, …" of flat audit details (scalars only, at most 8, shortened). */
	private static function summary( array $details ) {
		$parts = array();
		foreach ( $details as $key => $value ) {
			if ( count( $parts ) >= 8 ) { break; }
			if ( is_array( $value ) ) { $value = implode( ',', array_slice( array_filter( $value, 'is_scalar' ), 0, 5 ) ); }
			if ( ! is_scalar( $value ) || '' === (string) $value ) { continue; }
			$parts[] = sanitize_key( (string) $key ) . ': ' . mb_substr( (string) $value, 0, 60 );
		}
		return implode( ', ', $parts );
	}

	/** Display names of the sources. */
	public static function sources() {
		return array(
			'audit'   => __( 'Audit', 'pass-captain' ),
			'payment' => __( 'Zahlung', 'pass-captain' ),
			'mail'    => __( 'E-Mail', 'pass-captain' ),
			'job'     => __( 'Hintergrundjob', 'pass-captain' ),
			'log'     => __( 'Protokoll', 'pass-captain' ),
		);
	}

	/** Section "Verlauf" of the order detail page. */
	public static function render( $order_id ) {
		$entries = self::entries( $order_id );
		echo '<h2 id="wpcap-order-history">' . esc_html__( 'Verlauf', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Alles, was zu dieser Bestellung protokolliert wurde: Audit-Einträge, Zahlungsereignisse, E-Mails, Hintergrundjobs und das Support-Protokoll (mit Fehler-ID), neueste zuerst.', 'pass-captain' ) . '</p>';
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'Noch keine Einträge.', 'pass-captain' ) . '</p>';
			return;
		}
		$sources = self::sources();
		echo '<table class="widefat striped wpcap-order-history"><thead><tr><th scope="col">' . esc_html__( 'Zeit', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Quelle', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Ereignis', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Details', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $entries as $e ) {
			echo '<tr><td>' . esc_html( Forms::datetime( $e['at'], wp_timezone_string(), false ) ) . '</td><td>' . esc_html( $sources[ $e['source'] ] ?? $e['source'] ) . '</td><td><code>' . esc_html( $e['label'] ) . '</code></td><td>' . esc_html( $e['status'] ) . '</td><td>' . esc_html( $e['detail'] );
			if ( '' !== $e['reference'] ) {
				/* translators: %s: error reference */
				echo '<br><small>' . esc_html( sprintf( __( 'Fehler-ID %s', 'pass-captain' ), $e['reference'] ) ) . '</small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
