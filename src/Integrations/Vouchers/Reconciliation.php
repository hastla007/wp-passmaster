<?php
/**
 * Read-only, versioned listing of the orders that involve a voucher, so the voucher system (WP Voucher Captain)
 * can reconcile its redemptions with Pass Captain without reading Pass Captain's tables. Nothing is changed.
 *
 * Contract version 1 (`Reconciliation::VERSION`, since 1.4). Integrations check the constant before calling:
 *
 *     defined( 'PassCaptain\Integrations\Vouchers\Reconciliation::VERSION' ) && Reconciliation::VERSION >= 1
 *
 * Listed are orders paid (partly) by a voucher in the ticket checkout (voucher snapshot with a reservation)
 * and orders created by the ticket service (voucher → tickets, provider reference "voucher:<redemption key>").
 * Later versions only add fields; a change of meaning gets a new version.
 *
 * There is no capability check: this is a PHP API for code running in the same WordPress. Callers show the
 * rows only to users allowed to see orders (Voucher Captain: its report permission).
 */
namespace PassCaptain\Integrations\Vouchers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Reconciliation {
	const VERSION = 1;
	/** Most rows per call; page with after_id/before_id. */
	const MAX_LIMIT = 500;

	/**
	 * Orders with a voucher, ordered by order_id.
	 *
	 * @param array $args limit (1–500, default 100); order ('asc' default, or 'desc' for the newest first);
	 *                    after_id (only orders with a higher order_id) and/or before_id (only lower ones) as
	 *                    cursors – pass the last row's order_id to get the next page; redemption_key (only the
	 *                    orders that reference this redemption key, checkout or ticket service).
	 * @return array<int, array> Rows: order_id (int), public_id, status, payment_status, payment_provider,
	 *                    provider_reference, currency, exchange_key (redemption key of a ticket-service order,
	 *                    else ''), voucher (null or array: source 'checkout'|'ticket_service', redemption_key,
	 *                    status, purpose, amount_minor, amount_paid_by_voucher, refunded_minor – as stored in
	 *                    the voucher snapshot), created_at, updated_at (UTC, Y-m-d H:i:s).
	 */
	public static function orders( array $args = array() ) {
		global $wpdb;
		$limit  = min( self::MAX_LIMIT, max( 1, absint( $args['limit'] ?? 100 ) ) );
		$desc   = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) );
		$after  = absint( $args['after_id'] ?? 0 );
		$before = absint( $args['before_id'] ?? 0 );
		$key    = sanitize_text_field( (string) ( $args['redemption_key'] ?? '' ) );

		$where  = array( "((voucher_snapshot IS NOT NULL AND voucher_snapshot <> '') OR provider_reference LIKE %s)" );
		$params = array( 'voucher:%' );
		if ( $after ) {
			$where[]  = 'order_id > %d';
			$params[] = $after;
		}
		if ( $before ) {
			$where[]  = 'order_id < %d';
			$params[] = $before;
		}
		if ( '' !== $key ) {
			// The snapshot is JSON: match the stored key, then compare exactly below.
			$where[]  = '(provider_reference = %s OR voucher_snapshot LIKE %s)';
			$params[] = 'voucher:' . $key;
			$params[] = '%' . addcslashes( '"redemption_key":' . wp_json_encode( $key ), '_%\\' ) . '%';
		}
		$params[] = $limit;
		$sql      = "SELECT order_id,public_id,status,payment_status,payment_provider,provider_reference,currency,voucher_snapshot,created_at,updated_at FROM {$wpdb->prefix}wpcap_orders WHERE " . implode( ' AND ', $where ) . ' ORDER BY order_id ' . ( $desc ? 'DESC' : 'ASC' ) . ' LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$item = self::row( $row );
			if ( '' !== $key && $key !== $item['exchange_key'] && $key !== ( $item['voucher']['redemption_key'] ?? null ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/** Public shape of one order row (see orders()). */
	private static function row( $row ) {
		$reference = (string) $row->provider_reference;
		$exchange  = TicketService::PROVIDER === (string) $row->payment_provider && 0 === strpos( $reference, 'voucher:' ) ? substr( $reference, strlen( 'voucher:' ) ) : '';
		$voucher   = null;
		$snapshot  = ! empty( $row->voucher_snapshot ) ? json_decode( (string) $row->voucher_snapshot, true ) : null;
		if ( is_array( $snapshot ) ) {
			$from_service = 'external' === ( $snapshot['status'] ?? '' ) || ( isset( $snapshot['source'] ) && ! isset( $snapshot['reservation'] ) );
			$voucher      = array(
				'source'                 => $from_service ? 'ticket_service' : 'checkout',
				'redemption_key'         => (string) ( $from_service ? ( $snapshot['source']['redemption_key'] ?? '' ) : ( $snapshot['reservation']['redemption_key'] ?? '' ) ),
				'status'                 => (string) ( $snapshot['status'] ?? '' ),
				'purpose'                => (string) ( $snapshot['purpose'] ?? '' ),
				'amount_minor'           => (int) ( $snapshot['amount_minor'] ?? 0 ),
				'amount_paid_by_voucher' => (int) ( $snapshot['amount_paid_by_voucher'] ?? 0 ),
				'refunded_minor'         => (int) ( $snapshot['refunded_minor'] ?? 0 ),
			);
		}
		return array(
			'order_id'           => (int) $row->order_id,
			'public_id'          => (string) $row->public_id,
			'status'             => (string) $row->status,
			'payment_status'     => (string) $row->payment_status,
			'payment_provider'   => (string) $row->payment_provider,
			'provider_reference' => $reference,
			'currency'           => (string) $row->currency,
			'exchange_key'       => $exchange,
			'voucher'            => $voucher,
			'created_at'         => (string) $row->created_at,
			'updated_at'         => (string) $row->updated_at,
		);
	}
}
