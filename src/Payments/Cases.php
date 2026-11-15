<?php
/**
 * Payment cases that need a decision: money received for a cancelled/expired order
 * (paid_for_closed_order), a second payment for a paid order (double_payment) and provider refunds
 * that failed after they were booked (refund_failed).
 *
 * Every case is stored once per provider, kind and payment reference (wpcap_payment_cases) and listed
 * on the system status page (Diagnostics check `payment_cases`), where it can be marked as resolved.
 * Optionally (setting `auto_refund_unmatched_payments`, default off) money of paid_for_closed_order and
 * double_payment cases is refunded automatically by a job through the gateway (idempotency key
 * `wpcap-case-{id}`); the refund carries the plugin marker, so its webhook is not booked again, and
 * refund events of a case reference are never booked on the order (Webhooks).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Payments;

use PassCaptain\Audit;
use PassCaptain\Jobs;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cases {
	const JOB = 'payment_case_refund';
	const KINDS = array( 'paid_for_closed_order', 'double_payment', 'refund_failed' );

	public static function register() {
		add_filter( 'wp_pass_captain_process_job_v1', array( __CLASS__, 'handle_job' ), 10, 3 );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpcap_payment_cases';
	}

	/**
	 * Records a case (idempotent per provider/kind/reference) and queues the automatic refund when
	 * enabled. Returns the case id (0 when it could not be stored).
	 */
	public static function open( $order, $provider, $kind, $reference, $amount_minor, $currency, $note = '' ) {
		global $wpdb;
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'paid_for_closed_order';
		$reference = substr( sanitize_text_field( (string) $reference ), 0, 191 );
		$provider = sanitize_key( (string) $provider );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (order_id,provider,kind,reference,amount_minor,currency,status,note,created_at,updated_at) VALUES (%d,%s,%s,%s,%d,%s,'open',%s,%s,%s)", self::table(), (int) ( $order->order_id ?? 0 ), $provider, $kind, $reference, max( 0, (int) $amount_minor ), strtoupper( substr( (string) $currency, 0, 3 ) ), substr( sanitize_text_field( (string) $note ), 0, 255 ), $now, $now ) );
		$case_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT case_id FROM %i WHERE provider=%s AND kind=%s AND reference=%s', self::table(), $provider, $kind, $reference ) );
		if ( $case_id && 'refund_failed' !== $kind && '' !== $reference && Settings::get( 'auto_refund_unmatched_payments' ) ) {
			Jobs::enqueue( 'payment-case-refund|' . $case_id, self::JOB, array( 'case_id' => $case_id ) );
		}
		return $case_id;
	}

	public static function get( $case_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE case_id=%d', self::table(), absint( $case_id ) ) );
	}

	/** Open cases (status page). */
	public static function open_cases( $limit = 50 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*,o.public_id FROM %i c LEFT JOIN %i o ON o.order_id=c.order_id WHERE c.status IN ('open','refund_failed') ORDER BY c.case_id LIMIT %d", self::table(), $wpdb->prefix . 'wpcap_orders', max( 1, absint( $limit ) ) ) );
	}

	/** Whether one of the references belongs to a paid_for_closed_order/double_payment case. */
	public static function is_case_reference( $provider, array $references ) {
		global $wpdb;
		$references = array_values( array_filter( array_map( 'strval', $references ) ) );
		if ( ! $references ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT case_id FROM %i WHERE provider=%s AND kind IN ('paid_for_closed_order','double_payment') AND reference IN (" . implode( ',', array_fill( 0, count( $references ), '%s' ) ) . ') LIMIT 1', array_merge( array( self::table(), sanitize_key( (string) $provider ) ), $references ) ) );
	}

	/** Marks a case as resolved by staff. */
	public static function resolve( $case_id, $note = '' ) {
		global $wpdb;
		$case = self::get( $case_id );
		if ( ! $case ) { return new \WP_Error( 'wpcap_payment_case_missing', __( 'Fall nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$wpdb->update( self::table(), array( 'status' => 'resolved', 'note' => substr( sanitize_text_field( (string) $note ), 0, 255 ) ?: (string) $case->note, 'updated_at' => current_time( 'mysql', true ) ), array( 'case_id' => (int) $case->case_id ) );
		Audit::record( 'payment_case_resolved', 'order', (int) $case->order_id, array( 'case_id' => (int) $case->case_id, 'kind' => (string) $case->kind ) );
		return true;
	}

	/**
	 * Refunds the money of a case at the provider (idempotent key per case). Never touches the order's
	 * refund ledger: this payment was never booked on the order.
	 *
	 * @return true|\WP_Error
	 */
	public static function refund( $case_id ) {
		global $wpdb;
		$case = self::get( $case_id );
		if ( ! $case || ! in_array( $case->status, array( 'open', 'refund_failed' ), true ) || 'refund_failed' === $case->kind ) { return true; }
		$gateway = Registry::get( (string) $case->provider );
		if ( ! $gateway || ! $gateway->supports_refunds() || (int) $case->amount_minor <= 0 ) {
			return new \WP_Error( 'wpcap_payment_case_refund', __( 'Für diesen Fall ist keine automatische Erstattung möglich.', 'pass-captain' ) );
		}
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT public_id FROM %i WHERE order_id=%d', $wpdb->prefix . 'wpcap_orders', (int) $case->order_id ) );
		$result = $gateway->refund( array( 'provider_reference' => (string) $case->reference, 'currency' => (string) $case->currency, 'public_id' => $order ? (string) $order->public_id : '' ), (int) $case->amount_minor, 'wpcap-case-' . (int) $case->case_id );
		$status = is_wp_error( $result ) ? 'refund_failed' : 'refunded';
		$wpdb->update( self::table(), array( 'status' => $status, 'note' => is_wp_error( $result ) ? substr( $result->get_error_message(), 0, 255 ) : substr( 'refund ' . (string) $result, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ), array( 'case_id' => (int) $case->case_id ) );
		Audit::record( 'payment_case_' . $status, 'order', (int) $case->order_id, array( 'case_id' => (int) $case->case_id, 'kind' => (string) $case->kind, 'amount_minor' => (int) $case->amount_minor ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Job handler: automatic refund of a case (only while the setting is on). */
	public static function handle_job( $handled, $type, $payload = array() ) {
		if ( self::JOB !== $type ) { return $handled; }
		if ( ! Settings::get( 'auto_refund_unmatched_payments' ) ) { return true; }
		$result = self::refund( absint( ( (array) $payload )['case_id'] ?? 0 ) );
		// A final decline stays a case (status refund_failed) for staff; network errors are retried.
		return ! is_wp_error( $result ) || \PassCaptain\Refunds::is_final_decline( $result );
	}
}
