<?php
/**
 * WooCommerce carts with tickets of different invoice issuers ("sellers", see Partners\SalesModel):
 * policy, per-seller allocation of a WooCommerce order and deterministic rounding.
 *
 * Policy (WooCommerce → Einstellungen → Produkte → Pass Captain, option `wpcap_woocommerce_mixed_sellers`,
 * filter `wpcap_woocommerce_mixed_seller_policy`):
 *  - reject (default): adding a ticket of another seller, the cart, the checkout (classic and Store API)
 *    and the hold refuse mixed carts with a clear message.
 *  - split: the WooCommerce order is allowed; one Pass Captain order per seller is created and linked to
 *    the WooCommerce order. Each of them is invoiced by its own issuer.
 *
 * Binding allocation rules for a WooCommerce order (docs/WOOCOMMERCE.md, "Mehrere Verkäufer"); they apply
 * to single-seller orders as well (one bucket):
 *  1. Ticket lines: gross amount = WooCommerce line total after coupons + line tax. Coupons (all types,
 *     including fixed cart coupons) are therefore allocated by WooCommerce itself per line; Pass Captain
 *     never redistributes coupon amounts.
 *  2. Negative fees (cart discounts added as fee lines): distributed over the buckets "each seller's
 *     ticket lines" and "shop" (non-ticket products) in proportion to their gross amounts, then within a
 *     seller over its ticket lines (ticket type ID ascending) in proportion to the line gross. A discount
 *     larger than the buckets is capped at their sum.
 *  3. Positive fees (service, payment fees) and shipping (tickets are virtual and carry none) belong to the
 *     shop operator: they stay in WooCommerce and are not part of any seller order or seller invoice.
 *  4. Non-ticket products stay in WooCommerce (shop bucket) as before.
 *  5. Rounding: every distribution uses the largest-remainder method in minor units: floor all shares,
 *     then give the remaining units one by one to the largest fractional remainders; ties go to the
 *     earlier bucket (platform before organizers by partner ID, shop last; lines by ticket type ID). The
 *     shares always add up exactly to the distributed amount.
 *  6. VAT of a seller line is calculated by the core from the allocated gross amount and the ticket
 *     type's VAT rate (mixed rates are kept per line).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Integrations\WooCommerce;

use PassCaptain\Partners\SalesModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SellerSplit {
	const OPTION      = 'wpcap_woocommerce_mixed_sellers';
	const REJECT      = 'reject';
	const SPLIT       = 'split';
	const SPLIT_META  = '_wpcap_core_orders';
	const PLAN_META   = '_wpcap_seller_allocation';
	const LOOKUP_META = '_wpcap_split_core_id';
	const SHOP        = 'shop';

	/** Effective policy for mixed-seller carts (reject or split). */
	public static function policy() {
		$policy = (string) get_option( self::OPTION, self::REJECT );
		/**
		 * Policy for WooCommerce carts with tickets of different invoice issuers.
		 *
		 * @param string $policy 'reject' (default) or 'split'.
		 */
		$policy = (string) apply_filters( 'wpcap_woocommerce_mixed_seller_policy', $policy );
		return self::SPLIT === $policy ? self::SPLIT : self::REJECT;
	}

	/** Settings rows for WooCommerce → Einstellungen → Produkte (general section). */
	public static function settings( $settings, $section = '' ) {
		if ( '' !== (string) $section ) {
			return $settings;
		}
		$settings[] = array(
			'title' => __( 'Pass Captain – mehrere Rechnungssteller', 'pass-captain' ),
			'type'  => 'title',
			'desc'  => __( 'Tickets von Vermittler-Veranstaltern werden im Namen des Veranstalters verkauft und von ihm in Rechnung gestellt (siehe Verkaufsmodelle).', 'pass-captain' ),
			'id'    => 'wpcap_woocommerce_seller_options',
		);
		$settings[] = array(
			'title'    => __( 'Gemischte Warenkörbe', 'pass-captain' ),
			'desc_tip' => __( 'Ablehnen: Tickets verschiedener Rechnungssteller können nicht gemeinsam gekauft werden. Aufteilen: je Rechnungssteller entsteht eine eigene Pass-Captain-Bestellung mit eigener Rechnung; Rabatte als negative Gebühren werden anteilig verteilt, Gebühren und Versand bleiben beim Shop.', 'pass-captain' ),
			'id'       => self::OPTION,
			'type'     => 'select',
			'default'  => self::REJECT,
			'options'  => array(
				self::REJECT => __( 'Ablehnen (Standard)', 'pass-captain' ),
				self::SPLIT  => __( 'Je Rechnungssteller aufteilen', 'pass-captain' ),
			),
		);
		$settings[] = array(
			'title'    => __( 'Eingecheckte Tickets erstatten', 'pass-captain' ),
			'desc'     => __( 'WooCommerce-Erstattungen mit Menge dürfen bereits eingecheckte Tickets erstatten', 'pass-captain' ),
			'desc_tip' => __( 'Gilt nur, solange die zentrale Einstellung „Erstattung nach Check-in“ (Pass Captain → Einstellungen) nicht gesetzt ist; diese hat Vorrang. Ohne Erlaubnis werden Erstattungen mit Mengenangabe, die eingecheckte Tickets betreffen würden, abgelehnt; eine reine Betragserstattung bleibt möglich.', 'pass-captain' ),
			'id'       => OrderAdapter::CHECKED_IN_OPTION,
			'type'     => 'checkbox',
			'default'  => 'no',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wpcap_woocommerce_seller_options',
		);
		return $settings;
	}

	/* ------------------------------------------------------------------ */
	/* Cart validation                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Error for adding a ticket type to a cart that already holds tickets of other sellers ('' = fine).
	 *
	 * @param int   $type_id     Ticket type being added.
	 * @param int[] $cart_types  Ticket types already in the cart.
	 */
	public static function add_to_cart_error( $type_id, array $cart_types ) {
		$type_id = absint( $type_id );
		if ( ! $type_id || ! $cart_types || self::SPLIT === self::policy() ) {
			return '';
		}
		$keys   = SalesModel::seller_keys_for_types( array_merge( $cart_types, array( $type_id ) ) );
		$own    = $keys[ $type_id ] ?? SalesModel::PLATFORM_KEY;
		$others = array();
		foreach ( $cart_types as $other ) {
			$key = $keys[ absint( $other ) ] ?? SalesModel::PLATFORM_KEY;
			if ( $key !== $own ) {
				$others[ $key ] = SalesModel::seller_name( $key );
			}
		}
		if ( ! $others ) {
			return '';
		}
		$type = OrderAdapter::ticket_type( $type_id );
		$name = $type ? sprintf( '%s – %s', $type->event_name, $type->name ) : '#' . $type_id;
		return sprintf(
			/* translators: 1: event and ticket name, 2: seller of the ticket, 3: sellers already in the cart */
			__( '„%1$s“ wird von %2$s verkauft und in Rechnung gestellt, dein Warenkorb enthält bereits Tickets von %3$s. Tickets verschiedener Rechnungssteller können nicht in einer gemeinsamen Bestellung gekauft werden. Bitte schließe zuerst die aktuelle Bestellung ab oder entferne die anderen Tickets aus dem Warenkorb.', 'pass-captain' ),
			$name,
			SalesModel::seller_name( $own ),
			implode( ', ', $others )
		);
	}

	/**
	 * Error for a cart (or order) whose ticket types belong to more than one seller ('' = fine or split).
	 *
	 * @param int[] $type_ids Ticket types.
	 */
	public static function mixed_error( array $type_ids ) {
		if ( self::SPLIT === self::policy() ) {
			return '';
		}
		$keys = SalesModel::sort_seller_keys( array_values( SalesModel::seller_keys_for_types( $type_ids ) ) );
		if ( count( $keys ) < 2 ) {
			return '';
		}
		return sprintf(
			/* translators: %s: seller names */
			__( 'Dein Warenkorb enthält Tickets verschiedener Rechnungssteller (%s). Diese Tickets können nicht in einer gemeinsamen Bestellung gekauft werden. Bitte bestelle die Tickets je Veranstalter getrennt.', 'pass-captain' ),
			implode( ', ', array_map( array( SalesModel::class, 'seller_name' ), $keys ) )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Allocation                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Largest-remainder (Hamilton) distribution of $amount minor units by $weights.
	 *
	 * Shares are floored, the remaining units go to the largest fractional remainders; ties go to the key
	 * that comes first in $weights. Negative amounts are distributed like their absolute value and negated.
	 * Weights <= 0 receive nothing; if no weight is positive, all keys count equally.
	 *
	 * @param int                $amount  Amount in minor units.
	 * @param array<string|int,int> $weights Weights in their tie-break order.
	 * @return array<string|int,int> Same keys; the values add up to $amount exactly.
	 */
	public static function largest_remainder( $amount, array $weights ) {
		$amount = (int) $amount;
		$out    = array_fill_keys( array_keys( $weights ), 0 );
		if ( 0 === $amount || ! $weights ) {
			return $out;
		}
		$sign    = $amount < 0 ? -1 : 1;
		$abs     = abs( $amount );
		$weights = array_map( static function ( $w ) {
			return max( 0, (int) $w );
		}, $weights );
		if ( array_sum( $weights ) <= 0 ) {
			$weights = array_fill_keys( array_keys( $weights ), 1 );
		}
		$sum        = array_sum( $weights );
		$given      = 0;
		$remainders = array();
		$position   = 0;
		foreach ( $weights as $key => $weight ) {
			$numerator     = $abs * $weight;
			$share         = intdiv( $numerator, $sum );
			$out[ $key ]   = $share;
			$given        += $share;
			$remainders[]  = array( $numerator % $sum, $position++, $key );
		}
		usort( $remainders, static function ( $a, $b ) {
			return array( $b[0], $a[1] ) <=> array( $a[0], $b[1] );
		} );
		for ( $i = 0; $given < $abs; $i++ ) {
			++$out[ $remainders[ $i % count( $remainders ) ][2] ];
			++$given;
		}
		if ( $sign < 0 ) {
			$out = array_map( static function ( $v ) {
				return -$v;
			}, $out );
		}
		return $out;
	}

	/** Item kind of a WooCommerce order item: line_item, fee or shipping. */
	public static function item_kind( $item ) {
		if ( is_object( $item ) && is_callable( array( $item, 'get_type' ) ) ) {
			return (string) $item->get_type();
		}
		return 'line_item';
	}

	/** Gross amount of an order item in minor units (WooCommerce stores decimal strings). */
	private static function item_gross( $item, $factor ) {
		$total = is_callable( array( $item, 'get_total' ) ) ? (float) $item->get_total() : 0.0;
		$tax   = is_callable( array( $item, 'get_total_tax' ) ) ? (float) $item->get_total_tax() : 0.0;
		return (int) round( ( $total + $tax ) * $factor );
	}

	/**
	 * Allocates a WooCommerce order to seller buckets (see the class comment for the rules).
	 *
	 * @param \WC_Order $wc_order       WooCommerce order.
	 * @param callable  $type_of_item   Returns the ticket type ID of a line item (0 = no ticket).
	 * @return array{currency:string, sellers:array<string,array>, shop:array<string,int>, fee_discount:int}
	 *   sellers: seller key => ['lines' => [type_id => [ticket_type_id, quantity, wc_gross, fee_discount,
	 *   gross, unit_minor]], 'wc_gross' => int, 'fee_discount' => int, 'gross' => int], in seller order.
	 */
	public static function allocate( $wc_order, callable $type_of_item ) {
		$currency = (string) $wc_order->get_currency();
		$factor   = 10 ** OrderAdapter::currency_decimals( $currency );
		$lines    = array();
		$shop     = array( 'products_gross' => 0, 'fees_positive' => 0, 'fee_discount' => 0, 'shipping' => 0 );
		$discount = 0;
		foreach ( $wc_order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item ) {
			$kind = self::item_kind( $item );
			if ( 'fee' === $kind ) {
				$gross = self::item_gross( $item, $factor );
				if ( $gross < 0 ) {
					$discount += -$gross;
				} else {
					$shop['fees_positive'] += $gross;
				}
				continue;
			}
			if ( 'shipping' === $kind ) {
				$shop['shipping'] += self::item_gross( $item, $factor );
				continue;
			}
			if ( 'line_item' !== $kind ) {
				continue;
			}
			$type_id = absint( call_user_func( $type_of_item, $item ) );
			$qty     = is_callable( array( $item, 'get_quantity' ) ) ? absint( $item->get_quantity() ) : 0;
			$gross   = max( 0, self::item_gross( $item, $factor ) );
			if ( ! $type_id || ! $qty ) {
				$shop['products_gross'] += $gross;
				continue;
			}
			if ( ! isset( $lines[ $type_id ] ) ) {
				$lines[ $type_id ] = array( 'ticket_type_id' => $type_id, 'quantity' => 0, 'wc_gross' => 0, 'fee_discount' => 0, 'gross' => 0, 'unit_minor' => 0 );
			}
			$lines[ $type_id ]['quantity'] += $qty;
			$lines[ $type_id ]['wc_gross'] += $gross;
		}
		ksort( $lines, SORT_NUMERIC );
		$keys    = SalesModel::seller_keys_for_types( array_keys( $lines ) );
		$sellers = array();
		foreach ( SalesModel::sort_seller_keys( array_values( $keys ) ) as $key ) {
			$sellers[ $key ] = array( 'lines' => array(), 'wc_gross' => 0, 'fee_discount' => 0, 'gross' => 0 );
		}
		foreach ( $lines as $type_id => $line ) {
			$key                                   = $keys[ $type_id ] ?? SalesModel::PLATFORM_KEY;
			$sellers[ $key ]['lines'][ $type_id ]  = $line;
			$sellers[ $key ]['wc_gross']          += $line['wc_gross'];
		}
		// Rule 2: negative fees over sellers and shop, then over the lines of each seller.
		$weights = array();
		foreach ( $sellers as $key => $seller ) {
			$weights[ $key ] = $seller['wc_gross'];
		}
		$weights[ self::SHOP ] = $shop['products_gross'];
		$discount              = min( $discount, array_sum( $weights ) );
		$shares                = self::largest_remainder( $discount, $weights );
		$shop['fee_discount']  = $shares[ self::SHOP ];
		foreach ( $sellers as $key => $seller ) {
			$line_weights = array();
			foreach ( $seller['lines'] as $type_id => $line ) {
				$line_weights[ $type_id ] = $line['wc_gross'];
			}
			$line_shares = self::largest_remainder( $shares[ $key ], $line_weights );
			foreach ( $seller['lines'] as $type_id => $line ) {
				$gross = $line['wc_gross'] - $line_shares[ $type_id ];
				$sellers[ $key ]['lines'][ $type_id ]['fee_discount'] = $line_shares[ $type_id ];
				$sellers[ $key ]['lines'][ $type_id ]['gross']        = $gross;
				$sellers[ $key ]['lines'][ $type_id ]['unit_minor']   = intdiv( $gross, max( 1, $line['quantity'] ) );
			}
			$sellers[ $key ]['fee_discount'] = $shares[ $key ];
			$sellers[ $key ]['gross']        = $seller['wc_gross'] - $shares[ $key ];
		}
		return array(
			'currency'     => $currency,
			'sellers'      => $sellers,
			'shop'         => $shop,
			'fee_discount' => $discount,
		);
	}

	/**
	 * Splits an amount-only refund share over seller orders in proportion to what each can still refund
	 * (largest remainder, seller order as given). The amount is capped at the total refundable.
	 *
	 * @param int             $amount     Ticket share of the refund (minor units).
	 * @param array<int,int>  $refundable core order ID => remaining refundable amount.
	 * @return array<int,int> core order ID => amount.
	 */
	public static function distribute_refund( $amount, array $refundable ) {
		$refundable = array_map( static function ( $v ) {
			return max( 0, (int) $v );
		}, $refundable );
		$amount = min( max( 0, (int) $amount ), array_sum( $refundable ) );
		return self::largest_remainder( $amount, $refundable );
	}
}
