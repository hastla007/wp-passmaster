<?php
/**
 * Discount codes and vouchers on waitlist offers (owner decision 25.11.2026: "Rabatte gelten").
 *
 * A booking through a waitlist offer is treated like a normal booking: the buyer may enter discount
 * codes (Campaigns) and a voucher (Voucher Captain, Integrations\Vouchers\Checkout) when accepting a
 * paid offer. The placeholder order of the offer was created at the offer price (external channel,
 * no codes); on acceptance:
 *
 * - discount codes are checked with every rule of the checkout (validity, usage limit, per-buyer limit
 *   for the offer's e-mail address, minimum quantity, stacking, maximum discount) and redeemed inside
 *   the claim transaction of Waitlists::claim_offer(), i.e. while the order row and the offer's holds
 *   are locked; the per-line split, line VAT and price snapshot follow the checkout rules
 *   (price_snapshot.line_discounts, Orders::gross_tax()). Automatic campaigns (no code) are not added:
 *   the offer price was fixed with the offer;
 * - a voucher is applied after the claim like at the checkout (Vouchers\Checkout::apply(): provider
 *   calls never inside a transaction), so a voucher covering everything books the order like a free one.
 *
 * Before the first change the offer price is kept in the price snapshot (`waitlist_offer_price`). If the
 * acceptance fails afterwards (voucher refused, payment method not allowed for the reduced amount,
 * payment start failed) the offer opens again at its offer price: the voucher reservation is released,
 * the code uses are released and lines, VAT and totals are restored. Expiry and decline cancel the order,
 * which releases both through the usual hooks (Orders cancellation, Vouchers\Checkout::release()).
 * Payment, invoice, credit notes and refunds use the stored amounts like any other order.
 */
namespace PassCaptain\Waitlists;

use PassCaptain\Campaigns;
use PassCaptain\Errors;
use PassCaptain\Http;
use PassCaptain\Integrations\Vouchers\Bridge as VoucherBridge;
use PassCaptain\Integrations\Vouchers\Checkout as VoucherCheckout;
use PassCaptain\OrderLines;
use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Discounts {
	/** Key of the offer price (before any code or voucher) in the order's price snapshot. */
	const SNAPSHOT_KEY = 'waitlist_offer_price';

	/**
	 * Discount codes and voucher code of an acceptance. One field `code` ("Gutschein- oder Rabattcode")
	 * works like the checkout: a known campaign code is a discount, anything else is tried as a voucher
	 * when a voucher plugin is connected. `campaign_codes` (list) and `voucher_code` can be sent directly.
	 *
	 * @return array{0: string[], 1: string} Campaign codes, voucher code ('' = none).
	 */
	public static function codes( array $args ) {
		$codes = array();
		foreach ( is_array( $args['campaign_codes'] ?? null ) ? $args['campaign_codes'] : array() as $code ) {
			$code = is_scalar( $code ) ? trim( sanitize_text_field( (string) $code ) ) : '';
			if ( '' !== $code ) { $codes[] = $code; }
		}
		$voucher = trim( sanitize_text_field( Http::str( $args['voucher_code'] ?? '' ) ) );
		$single = trim( sanitize_text_field( Http::str( $args['code'] ?? '' ) ) );
		if ( '' !== $single ) {
			if ( Campaigns::is_code( $single ) || ! VoucherBridge::available() ) {
				$codes[] = $single;
			} elseif ( '' === $voucher ) {
				$voucher = $single;
			}
		}
		return array( array_values( array_unique( $codes ) ), $voucher );
	}

	/**
	 * Ticket lines of a placeholder order in the shape Campaigns::calculate() expects (with the order line).
	 *
	 * @return array[]
	 */
	public static function selected( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT oi.item_id,oi.ticket_type_id,oi.quantity,oi.unit_price_minor,oi.line_total_minor,oi.tax_rate_bp,oi.tax_minor,oi.line_snapshot,tt.occurrence_id,o.event_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d AND oi.quantity>0 ORDER BY oi.item_id", $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', absint( $order_id ) ) );
		$out = array();
		foreach ( $rows as $row ) {
			$snapshot = json_decode( (string) $row->line_snapshot, true );
			$rule = is_array( $snapshot ) && ! empty( $snapshot['price'] ) ? $snapshot['price'] : null;
			$base = is_array( $rule ) && isset( $rule['base_minor'] ) ? (int) $rule['base_minor'] : (int) $row->unit_price_minor;
			$out[] = array(
				'type' => (object) array( 'ticket_type_id' => (int) $row->ticket_type_id, 'occurrence_id' => (int) $row->occurrence_id ),
				'event_id' => (int) $row->event_id,
				'quantity' => (int) $row->quantity,
				'line_total' => (int) $row->line_total_minor,
				'base_minor' => $base,
				'price_rule' => $rule,
				'item' => $row,
			);
		}
		return $out;
	}

	/**
	 * Non-binding check of discount codes for an offer order (no locks): the discount they would give.
	 *
	 * @param object   $order Placeholder order.
	 * @param string[] $codes Campaign codes.
	 * @return int|\WP_Error Discount in minor units.
	 */
	public static function preview( $order, array $codes ) {
		if ( ! $codes ) { return 0; }
		$adjustments = Campaigns::calculate( self::selected( (int) $order->order_id ), $codes, (string) $order->currency, array( 'auto' => false, 'email' => (string) $order->customer_email, 'lock' => false ) );
		if ( is_wp_error( $adjustments ) ) { return self::error( $adjustments ); }
		return (int) array_sum( array_map( static function ( $adjustment ) { return (int) $adjustment['amount_minor']; }, $adjustments ) );
	}

	/**
	 * Error of a discount code for the buyer: the stacking rules explain themselves, everything else gets the
	 * checkout's wording. The offer stays open (status 400/409, field `code`).
	 */
	public static function error( \WP_Error $error ) {
		$code = $error->get_error_code();
		if ( in_array( $code, array( 'wpcap_campaign_not_combinable', 'wpcap_campaign_max_discount', 'wpcap_campaign_min_quantity', 'wpcap_campaign_buyer_limit', 'wpcap_campaign_limit' ), true ) ) {
			return new \WP_Error( $code, $error->get_error_message(), array( 'status' => 409, 'field' => 'code' ) );
		}
		if ( 'wpcap_campaign_exhausted' === $code ) {
			return new \WP_Error( $code, __( 'Das Nutzungslimit des Rabattcodes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409, 'field' => 'code' ) );
		}
		return new \WP_Error( 'wpcap_campaign_invalid', __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'pass-captain' ), array( 'status' => 409, 'field' => 'code' ) );
	}

	/**
	 * Inside the claim transaction (order row and holds locked by the caller): restores the offer price
	 * of an earlier attempt, then redeems the discount codes and writes lines, VAT and totals. With
	 * $remember (codes or a voucher are used) the offer price is kept for a later restore.
	 *
	 * @param string[] $codes Campaign codes.
	 * @return array|\WP_Error discount_minor, total_minor; Errors::busy() on a database error.
	 */
	public static function apply_locked( $order_id, array $codes, $remember ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$restored = self::restore_locked( $order_id );
		if ( is_wp_error( $restored ) ) { return $restored; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,customer_email,currency,subtotal_minor,discount_minor,total_minor,tax_minor,price_snapshot,voucher_snapshot,payment_status FROM %i WHERE order_id=%d", $wpdb->prefix . 'wpcap_orders', $order_id ) );
		if ( self::db_failed() || ! $order ) { return Errors::busy(); }
		if ( ! $codes && ! $remember ) { return array( 'discount_minor' => 0, 'total_minor' => (int) $order->total_minor ); }
		if ( '' !== (string) $order->voucher_snapshot || 'pending' !== $order->payment_status ) {
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$fiscal = class_exists( \PassCaptain\Modules\Fiscalization::class ) ? \PassCaptain\Modules\Fiscalization::order_documents_state( $order_id ) : 'none';
		if ( $codes && 'locked' === $fiscal ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Der Rabattcode kann nicht mehr angewendet werden, weil für diese Bestellung bereits ein Fiskalbeleg übermittelt wird.', 'pass-captain' ), array( 'status' => 409, 'field' => 'code' ) );
		}
		$selected = self::selected( $order_id );
		$price_snapshot = json_decode( (string) $order->price_snapshot, true );
		$price_snapshot = is_array( $price_snapshot ) ? $price_snapshot : array();
		$original = array( 'price_snapshot' => (string) $order->price_snapshot, 'discount_minor' => (int) $order->discount_minor, 'total_minor' => (int) $order->total_minor, 'tax_minor' => (int) $order->tax_minor, 'items' => array() );
		foreach ( $selected as $line ) { $original['items'][ (int) $line['item']->item_id ] = (int) $line['item']->tax_minor; }
		$price_snapshot[ self::SNAPSHOT_KEY ] = $original;
		$discount = 0;
		$tax_total = (int) $order->tax_minor;
		$fiscal_lines = array();
		if ( $codes ) {
			// Lock order of the checkout after the capacity rows: campaign rows FOR UPDATE, then the uses.
			$adjustments = Campaigns::calculate( $selected, $codes, (string) $order->currency, array( 'auto' => false, 'email' => (string) $order->customer_email ) );
			if ( self::db_failed() ) { return Errors::busy(); }
			if ( is_wp_error( $adjustments ) ) { return self::error( $adjustments ); }
			$redeemed = Campaigns::redeem( $adjustments, $order_id );
			if ( is_wp_error( $redeemed ) ) {
				return 'wpcap_campaign_use_failed' === $redeemed->get_error_code() ? Errors::busy() : self::error( $redeemed );
			}
			$parts = Campaigns::line_discounts( $adjustments, count( $selected ) );
			$line_discounts = array();
			$tax_total = (int) $order->tax_minor;
			foreach ( $selected as $index => $line ) {
				$item = $line['item'];
				$part = min( (int) $item->line_total_minor, max( 0, (int) ( $parts[ $index ] ?? 0 ) ) );
				$discount += $part;
				$type_id = (int) $item->ticket_type_id;
				$line_discounts[ $type_id ] = ( $line_discounts[ $type_id ] ?? 0 ) + $part;
				// VAT on the discounted gross amount, same rule as the checkout (no VAT stays no VAT).
				$tax = (int) $item->tax_minor > 0 ? Orders::gross_tax( (int) $item->line_total_minor - $part, (int) $item->tax_rate_bp ) : 0;
				$tax_total += $tax - (int) $item->tax_minor;
				$fiscal_lines[ $type_id ] = array( ( $fiscal_lines[ $type_id ][0] ?? 0 ) + (int) $item->line_total_minor - $part, ( $fiscal_lines[ $type_id ][1] ?? 0 ) + $tax );
				$columns = array( 'tax_minor' => $tax );
				if ( OrderLines::ready() ) { $columns['discount_minor'] = $part; }
				if ( false === $wpdb->update( "{$p}wpcap_order_items", $columns, array( 'item_id' => (int) $item->item_id ) ) ) { return Errors::busy(); }
			}
			$price_snapshot['discount_minor'] = (int) $order->discount_minor + $discount;
			$price_snapshot['campaigns'] = array_map( static function ( $adjustment ) { return array( 'code' => $adjustment['campaign']->code, 'amount_minor' => (int) $adjustment['amount_minor'] ); }, $adjustments );
			$price_snapshot['line_discounts'] = $line_discounts;
		}
		$total = max( 0, (int) $order->total_minor - $discount );
		$price_snapshot['total_minor'] = $total;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET discount_minor=%d,total_minor=%d,tax_minor=%d,price_snapshot=%s WHERE order_id=%d AND payment_status='pending' AND total_minor=%d AND (voucher_snapshot IS NULL OR voucher_snapshot='')", $wpdb->prefix . 'wpcap_orders', (int) $order->discount_minor + $discount, $total, $tax_total, (string) wp_json_encode( $price_snapshot ), $order_id, (int) $order->total_minor ) );
		if ( 1 !== (int) $updated ) { return Errors::busy(); }
		// Unsent fiscal documents (if any) follow the reduced amounts, like a single-purpose voucher.
		if ( $fiscal_lines && 'open' === $fiscal && ! \PassCaptain\Modules\Fiscalization::reprice_open_documents( $order_id, $fiscal_lines ) ) { return Errors::busy(); }
		return array( 'discount_minor' => $discount, 'total_minor' => $total );
	}

	/**
	 * Inside a transaction with the order row locked: back to the offer price kept by apply_locked()
	 * (code uses released, lines, VAT, totals and price snapshot restored, a released voucher forgotten).
	 * Nothing to do for an order without a kept offer price.
	 *
	 * @return true|\WP_Error Errors::busy() on a database error; wpcap_voucher_state while a voucher is still reserved.
	 */
	public static function restore_locked( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$order_id = absint( $order_id );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,price_snapshot,voucher_snapshot,payment_status FROM %i WHERE order_id=%d", $wpdb->prefix . 'wpcap_orders', $order_id ) );
		if ( self::db_failed() || ! $order ) { return Errors::busy(); }
		$snapshot = json_decode( (string) $order->price_snapshot, true );
		$original = is_array( $snapshot ) && is_array( $snapshot[ self::SNAPSHOT_KEY ] ?? null ) ? $snapshot[ self::SNAPSHOT_KEY ] : null;
		if ( null === $original || 'pending' !== $order->payment_status ) { return true; }
		$voucher = json_decode( (string) $order->voucher_snapshot, true );
		if ( is_array( $voucher ) && 'released' !== ( $voucher['status'] ?? '' ) ) {
			// A reservation at the voucher provider is released outside the transaction first (release_voucher()).
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( false === Campaigns::release_for_order( $order_id ) || self::db_failed() ) { return Errors::busy(); }
		// The released uses of the aborted attempt were never committed; dropping them lets the next attempt
		// redeem the same code again (one use per campaign and order, unique key campaign_order).
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE order_id=%d AND status='released'", $wpdb->prefix . 'wpcap_campaign_uses', $order_id ) ) ) { return Errors::busy(); }
		$fiscal_lines = array();
		$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT item_id,ticket_type_id,line_total_minor FROM %i WHERE order_id=%d AND quantity>0", $wpdb->prefix . 'wpcap_order_items', $order_id ) );
		foreach ( $items as $item ) {
			$tax = (int) ( $original['items'][ (int) $item->item_id ] ?? 0 );
			$columns = array( 'tax_minor' => $tax );
			if ( OrderLines::ready() ) { $columns['discount_minor'] = 0; }
			if ( false === $wpdb->update( "{$p}wpcap_order_items", $columns, array( 'item_id' => (int) $item->item_id ) ) ) { return Errors::busy(); }
			$type_id = (int) $item->ticket_type_id;
			$fiscal_lines[ $type_id ] = array( ( $fiscal_lines[ $type_id ][0] ?? 0 ) + (int) $item->line_total_minor, ( $fiscal_lines[ $type_id ][1] ?? 0 ) + $tax );
		}
		$restored = $wpdb->query( $wpdb->prepare( "UPDATE %i SET discount_minor=%d,total_minor=%d,tax_minor=%d,price_snapshot=%s,voucher_snapshot=NULL WHERE order_id=%d AND payment_status='pending'", $wpdb->prefix . 'wpcap_orders', (int) $original['discount_minor'], (int) $original['total_minor'], (int) $original['tax_minor'], (string) $original['price_snapshot'], $order_id ) );
		if ( 1 !== (int) $restored ) { return Errors::busy(); }
		if ( class_exists( \PassCaptain\Modules\Fiscalization::class ) && 'open' === \PassCaptain\Modules\Fiscalization::order_documents_state( $order_id ) && ! \PassCaptain\Modules\Fiscalization::reprice_open_documents( $order_id, $fiscal_lines ) ) {
			return Errors::busy();
		}
		return true;
	}

	/**
	 * Outside any transaction: releases a voucher reservation an unpaid offer order still holds from an
	 * earlier, failed acceptance (the voucher goes back to the provider before the order is restored).
	 *
	 * @return bool True when no reserved voucher is left on the order.
	 */
	public static function release_voucher( $order_id ) {
		$order = Orders::get( (int) $order_id );
		if ( ! $order || 'pending' !== $order->payment_status || '' === (string) $order->voucher_snapshot ) { return true; }
		$state = VoucherCheckout::voucher_state( (int) $order_id );
		if ( is_array( $state ) && 'reserved' === ( $state['status'] ?? '' ) ) {
			VoucherCheckout::release( (int) $order_id );
			$state = VoucherCheckout::voucher_state( (int) $order_id );
		}
		return ! is_array( $state ) || 'released' === ( $state['status'] ?? '' );
	}

	/**
	 * Outside any transaction: back to the offer price after a failed acceptance (voucher released first).
	 *
	 * @return true|\WP_Error
	 */
	public static function restore( $order_id ) {
		global $wpdb;
		if ( ! self::release_voucher( $order_id ) ) {
			return new \WP_Error( 'wpcap_voucher_state', __( 'Der Gutschein kann auf diese Bestellung nicht angewendet werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return Errors::busy(); }
		$wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM %i WHERE order_id=%d FOR UPDATE", $wpdb->prefix . 'wpcap_orders', absint( $order_id ) ) );
		$result = ! self::db_failed() ? self::restore_locked( $order_id ) : Errors::busy();
		if ( is_wp_error( $result ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return is_wp_error( $result ) ? $result : Errors::busy();
		}
		return true;
	}

	/** Code input of the offer page for paid offers ('' for free ones), labelled like the checkout field. */
	public static function form_html( $order ) {
		if ( ! $order || (int) $order->total_minor <= 0 ) { return ''; }
		return '<p class="wpcap-offer-code"><label for="wpcap-wl-code">' . esc_html__( 'Gutschein- oder Rabattcode (optional)', 'pass-captain' ) . '</label><br><input type="text" id="wpcap-wl-code" name="code" maxlength="64" autocomplete="off" spellcheck="false"></p>';
	}

	/**
	 * Whether the last query failed (re-read after every query).
	 *
	 * @phpstan-impure
	 */
	private static function db_failed() {
		global $wpdb;
		return '' !== (string) $wpdb->last_error;
	}

}
