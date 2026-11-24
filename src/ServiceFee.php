<?php
/**
 * Presale/service fee (A425), off by default.
 *
 * A fee per ticket (setting `service_fee_ticket_minor`, or per ticket type in the column
 * wpcap_ticket_types.service_fee_minor: NULL = the setting, 0 = no fee for this type) and/or per order
 * (setting `service_fee_order_minor`) for the own online checkout. Trusted channels (WooCommerce,
 * box office, partners, staff holds, guest lists, waitlist offers, service bookings) and free orders
 * never carry it; a line that is free after its discount carries no per-ticket fee.
 *
 * The fee is an order line of its own (wpcap_order_items.fee_kind 'ticket' or 'order', quantity 0 =
 * no admission and no inventory) next to the ticket line it belongs to. It takes the VAT rate and tax
 * profile columns of that ticket line ("tax like the ticket line"), so invoices, the VAT summary per
 * rate, reports and the accounting export include it without special cases. Campaign discounts never
 * reduce it. The amounts that applied are frozen in the order's price_snapshot ("service_fee").
 *
 * Owner decisions of 23.11.2026: shown prices include the per-ticket fee and name the per-order fee
 * (shown(), shown_unit()); the fee is taxed like its ticket line or with an own tax profile (own_tax());
 * an organizer cancellation always refunds it, a refund on the buyer's wish keeps it unless the setting
 * says otherwise (refund_part(), Refunds::calculate()); the per-order fee goes with the last ticket.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceFee {
	const KIND_TICKET = 'ticket';
	const KIND_ORDER = 'order';

	public static function register() {
		add_filter( 'wpcap_invoice_items', array( __CLASS__, 'invoice_items' ), 20, 1 );
	}

	/** Whether the schema has the fee columns (wpcap_order_items.fee_kind, wpcap_ticket_types.service_fee_minor). */
	public static function ready() {
		return Log::has_column( 'wpcap_order_items', 'fee_kind' ) && Log::has_column( 'wpcap_ticket_types', 'service_fee_minor' );
	}

	/** Configured default fees (gross, minor units of the ticket currency). */
	public static function config() {
		return array(
			'ticket_minor' => max( 0, (int) Settings::get( 'service_fee_ticket_minor' ) ),
			'order_minor' => max( 0, (int) Settings::get( 'service_fee_order_minor' ) ),
		);
	}

	/**
	 * Per-ticket fee of a ticket type (gross, minor units): its own value, else the setting; 0 for free
	 * types and before the schema upgrade.
	 *
	 * @param object $type Ticket type row (service_fee_minor may be missing or NULL).
	 */
	public static function per_ticket( $type ) {
		// Add-on products (Sales\Addons) are no tickets and carry no per-ticket fee.
		if ( ! is_object( $type ) || (int) ( $type->price_minor ?? 0 ) <= 0 || Sales\Addons::is_addon( $type ) ) { return 0; }
		$own = property_exists( $type, 'service_fee_minor' ) ? $type->service_fee_minor : null;
		return max( 0, null === $own || '' === $own ? self::config()['ticket_minor'] : (int) $own );
	}

	/**
	 * Fees as shown to buyers before they buy (price display, PAngV/PrAG; owner decision 23.11.2026):
	 * the per-ticket fee of the type (0 for free types) and the per-order fee. The fee charged at checkout
	 * comes from for_order() (filter `wpcap_service_fee`); a site that changes it there per order keeps
	 * the display in line with the filter `wpcap_service_fee_shown`.
	 *
	 * @param object|null $type Ticket type row (null: only the per-order fee is asked for).
	 * @return array{ticket_minor:int, order_minor:int}
	 */
	public static function shown( $type = null ) {
		$fee = array(
			'ticket_minor' => self::ready() && is_object( $type ) ? self::per_ticket( $type ) : 0,
			'order_minor' => self::ready() ? self::config()['order_minor'] : 0,
		);
		/**
		 * Service fee shown on event cards, event pages, the live state, REST /events and JSON-LD
		 * (display only; the checkout computes the fee with `wpcap_service_fee`).
		 *
		 * @param array       $fee  ticket_minor (per ticket of this type), order_minor (per order).
		 * @param object|null $type Ticket type row, null when only the per-order fee is asked for.
		 */
		$fee = (array) apply_filters( 'wpcap_service_fee_shown', $fee, $type );
		return array(
			'ticket_minor' => max( 0, (int) ( $fee['ticket_minor'] ?? 0 ) ),
			'order_minor' => max( 0, (int) ( $fee['order_minor'] ?? 0 ) ),
		);
	}

	/**
	 * Unit price as shown to buyers: the ticket price including its per-ticket fee (a free ticket stays
	 * free and carries no fee).
	 *
	 * @param object   $type Ticket type row.
	 * @param int|null $unit Current unit price (price rules, tiers); null = $type->price_minor.
	 */
	public static function shown_unit( $type, $unit = null ) {
		$unit = null === $unit ? (int) ( $type->price_minor ?? 0 ) : (int) $unit;
		if ( $unit <= 0 || ! is_object( $type ) ) { return $unit; }
		$probe = clone $type;
		$probe->price_minor = $unit;
		return $unit + self::shown( $probe )['ticket_minor'];
	}

	/** Note next to a price that includes the per-ticket fee ("inkl. 1,50 € Servicegebühr"); '' without fee. */
	public static function included_note( $fee_minor, $currency ) {
		if ( (int) $fee_minor <= 0 ) { return ''; }
		/* translators: %s: service fee per ticket included in the shown price */
		return sprintf( __( 'inkl. %s Servicegebühr', 'pass-captain' ), Frontend\Format::money( (int) $fee_minor, (string) $currency ) );
	}

	/** Note next to the prices for the per-order fee ("zzgl. 2,00 € Bearbeitungsgebühr je Bestellung"); '' without fee. */
	public static function order_note( $currency, $order_minor = null ) {
		$order_minor = null === $order_minor ? self::shown()['order_minor'] : (int) $order_minor;
		if ( $order_minor <= 0 ) { return ''; }
		/* translators: %s: processing fee charged once per order */
		return sprintf( __( 'zzgl. %s Bearbeitungsgebühr je Bestellung', 'pass-captain' ), Frontend\Format::money( $order_minor, (string) $currency ) );
	}

	/** Tax of the fee (owner decision 23.11.2026): 'ticket' (like its ticket line, default) or 'own'. */
	public static function tax_mode() {
		return 'own' === (string) Settings::get( 'service_fee_tax' ) ? 'own' : 'ticket';
	}

	/**
	 * Own tax of a fee line ("eigenes Steuerprofil"): the active tax profile of the configured tax class
	 * for the place of supply and date of its ticket line (same resolution as ticket lines:
	 * Sales\TaxProfiles for the event country, Tax\Profiles for an occurrence with a venue country);
	 * without a matching profile the rate setting (service_fee_tax_rate), else the ticket line's rate.
	 * Null in mode 'ticket'.
	 *
	 * Filter `wpcap_service_fee_tax` (array|null $tax, object $type, object|null $occurrence, string $kind).
	 *
	 * @param array       $line       Line of Orders::create() (type, tax_rate_bp, tax_info).
	 * @param object|null $occurrence Occurrence row of the line.
	 * @param string      $now        Sale time (UTC).
	 * @param string      $source     Sales channel.
	 * @param string      $kind       KIND_TICKET or KIND_ORDER.
	 * @return array|null {rate_bp, tax_info, tax_profile}
	 */
	public static function own_tax( array $line, $occurrence, $now, $source, $kind = self::KIND_TICKET ) {
		if ( 'own' !== self::tax_mode() ) { return null; }
		$probe = clone $line['type'];
		$probe->tax_class = sanitize_key( (string) Settings::get( 'service_fee_tax_class' ) ) ?: 'service';
		$fallback = (string) Settings::get( 'service_fee_tax_rate' );
		$fallback_bp = '' === trim( $fallback ) ? null : Admin\Forms::parse_percent_bp( $fallback );
		$ticket_info = (array) ( $line['tax_info'] ?? array() );
		$country = (string) ( $ticket_info['country'] ?? Tax\Profiles::venue_country( $occurrence ) );
		$tax = null;
		$profile = $occurrence ? Sales\TaxProfiles::for_line( $probe, $occurrence, $now ) : null;
		if ( $profile ) {
			$tax = array(
				'rate_bp' => (int) $profile['rate_bp'],
				'tax_info' => array( 'rate_bp' => (int) $profile['rate_bp'], 'source' => Tax\Profiles::SOURCE_PROFILE, 'profile_id' => (int) $profile['tax_profile_id'], 'version' => (string) ( $profile['snapshot']['version'] ?? '' ), 'country' => (string) ( $profile['snapshot']['country'] ?? $country ) ),
				'tax_profile' => $profile,
			);
		} else {
			$probe->tax_rate_bp = null === $fallback_bp ? (int) ( $line['tax_rate_bp'] ?? 0 ) : (int) $fallback_bp;
			$resolved = Tax\Profiles::resolve( $probe, $occurrence, $now, $source );
			if ( Tax\Profiles::SOURCE_PROFILE !== $resolved['source'] ) {
				$resolved['source'] = null === $fallback_bp ? (string) ( $ticket_info['source'] ?? Tax\Profiles::SOURCE_TYPE ) : Tax\Profiles::SOURCE_SETTING;
			}
			$tax = array( 'rate_bp' => (int) $resolved['rate_bp'], 'tax_info' => $resolved, 'tax_profile' => null );
		}
		/**
		 * Own tax of a service fee line (mode "eigenes Steuerprofil").
		 *
		 * @param array       $tax        rate_bp, tax_info (Tax\Profiles::line_columns() input), tax_profile (Sales\TaxProfiles::for_line() result or null).
		 * @param object      $type       Ticket type of the line the fee belongs to.
		 * @param object|null $occurrence Occurrence row.
		 * @param string      $kind       ticket or order.
		 */
		$tax = apply_filters( 'wpcap_service_fee_tax', $tax, $line['type'], $occurrence, $kind );
		return is_array( $tax ) && isset( $tax['rate_bp'] ) ? $tax : null;
	}

	/** Whether the checkout of these types may show a fee (display only). */
	public static function shown_for( array $types ) {
		if ( self::config()['order_minor'] > 0 ) { return true; }
		foreach ( $types as $type ) {
			if ( self::per_ticket( $type ) > 0 ) { return true; }
		}
		return false;
	}

	/**
	 * Fee lines of an order of the own online checkout (Orders::create(), after discounts and VAT).
	 *
	 * Filter `wpcap_service_fee` (array $fee, array $selected, array $context): change or drop the fee
	 * per order. $fee = ['ticket' => [ticket_type_id => per-ticket fee], 'order_minor' => int]; return
	 * zeros for no fee.
	 *
	 * @param array $selected Lines of Orders::create() (type, quantity, line_total, discount_minor, tax_rate_bp, tax_info).
	 * @param int   $payable  Order total after discounts (no fee on free orders).
	 * @param array $context  currency, email; occurrences (id => row), now, source for the own tax
	 *                         (setting service_fee_tax = own, own_tax()).
	 * @return array{lines: array[], total_minor:int, tax_minor:int, snapshot: array}|null Null = no fee.
	 */
	public static function for_order( array $selected, $payable, array $context = array() ) {
		if ( (int) $payable <= 0 || ! $selected ) { return null; }
		$selected = array_values( $selected );
		$fee = array( 'ticket' => array(), 'order_minor' => self::config()['order_minor'] );
		foreach ( $selected as $line ) {
			$fee['ticket'][ (int) $line['type']->ticket_type_id ] = self::per_ticket( $line['type'] );
		}
		$fee = (array) apply_filters( 'wpcap_service_fee', $fee, $selected, $context );
		$lines = array();
		$first = null;
		foreach ( $selected as $index => $line ) {
			$quantity = (int) $line['quantity'];
			// A line that is free after its discount carries no fee.
			if ( $quantity < 1 || (int) $line['line_total'] - (int) ( $line['discount_minor'] ?? 0 ) <= 0 ) { continue; }
			if ( null === $first && ! Sales\Addons::is_addon( $line['type'] ) ) { $first = $index; } // The per-order fee goes with a ticket line.
			$unit = max( 0, (int) ( $fee['ticket'][ (int) $line['type']->ticket_type_id ] ?? 0 ) );
			if ( $unit > 0 ) {
				$lines[] = array( 'index' => $index, 'kind' => self::KIND_TICKET, 'unit_minor' => $unit, 'total_minor' => $unit * $quantity, 'rate_bp' => (int) $line['tax_rate_bp'] );
			}
		}
		if ( null === $first ) { return null; }
		$per_order = max( 0, (int) ( $fee['order_minor'] ?? 0 ) );
		if ( $per_order > 0 ) {
			$lines[] = array( 'index' => $first, 'kind' => self::KIND_ORDER, 'unit_minor' => $per_order, 'total_minor' => $per_order, 'rate_bp' => (int) $selected[ $first ]['tax_rate_bp'] );
		}
		if ( ! $lines ) { return null; }
		// Own tax profile of the fee (else the ticket line's rate, tax info and profile apply in Orders::create()).
		foreach ( $lines as $i => $fee_line ) {
			$parent = $selected[ $fee_line['index'] ];
			$occurrence = $context['occurrences'][ (int) ( $parent['type']->occurrence_id ?? 0 ) ] ?? null;
			$own = self::own_tax( $parent, $occurrence, (string) ( $context['now'] ?? Clock::mysql() ), (string) ( $context['source'] ?? '' ), $fee_line['kind'] );
			if ( $own ) {
				$lines[ $i ]['rate_bp'] = (int) $own['rate_bp'];
				$lines[ $i ]['tax_info'] = $own['tax_info'];
				$lines[ $i ]['tax_profile'] = $own['tax_profile'];
			}
		}
		$total = 0;
		$tax = 0;
		$by_kind = array( self::KIND_TICKET => 0, self::KIND_ORDER => 0 );
		foreach ( $lines as $i => $line ) {
			$lines[ $i ]['tax_minor'] = Orders::gross_tax( $line['total_minor'], $line['rate_bp'] );
			$total += $line['total_minor'];
			$tax += $lines[ $i ]['tax_minor'];
			$by_kind[ $line['kind'] ] += $line['total_minor'];
		}
		return array(
			'lines' => $lines,
			'total_minor' => $total,
			'tax_minor' => $tax,
			'snapshot' => array( 'total_minor' => $total, 'tax_minor' => $tax, 'ticket_minor' => $by_kind[ self::KIND_TICKET ], 'order_minor' => $by_kind[ self::KIND_ORDER ], 'tax' => self::tax_mode() ),
		);
	}

	/**
	 * Fee frozen with an order (price_snapshot.service_fee), or null.
	 *
	 * @param object $order Order row with price_snapshot.
	 * @return array|null total_minor, tax_minor, ticket_minor, order_minor.
	 */
	public static function of_order( $order ) {
		if ( ! is_object( $order ) || ! isset( $order->price_snapshot ) ) { return null; }
		$snapshot = json_decode( (string) $order->price_snapshot, true );
		$fee = is_array( $snapshot ) && is_array( $snapshot['service_fee'] ?? null ) ? $snapshot['service_fee'] : null;
		return $fee && (int) ( $fee['total_minor'] ?? 0 ) > 0 ? $fee : null;
	}

	/**
	 * Extra columns for order line queries whose rows go into refund splits (fee_kind, unit_price_minor);
	 * '' before the schema upgrade.
	 */
	public static function columns( $alias = 'oi' ) {
		$a = '' !== $alias ? $alias . '.' : '';
		return self::ready() ? ",{$a}fee_kind,{$a}unit_price_minor AS fee_unit_minor" : '';
	}

	/**
	 * Weights of service fee lines for a ticket refund (Invoices::refund_line_parts(), credit_note_split()):
	 * each per-ticket fee line of a refunded ticket type takes the share of the refunded tickets (gross
	 * and VAT of the line × tickets / tickets it covers). The per-order fee has no weight: it goes back
	 * with the last ticket as the rest that the other lines have no room for.
	 *
	 * @param object[] $items  Order lines in item order (with fee_kind/fee_unit_minor from columns()).
	 * @param int[]    $counts Ticket type id => refunded tickets.
	 * @param array|object|null $refund Refund row; with fee_minor/fee_kept_minor (schema 3.18.4) the weights
	 *                                  follow what the refund gave back (nothing when the fee was kept).
	 * @return array<int, array{0:int,1:int,2:int}> Line index => [tickets 0, gross, VAT].
	 */
	public static function refund_weights( array $items, array $counts, $refund = null ) {
		$out = array();
		// Owner decision 23.11.2026: refunds record the fee they gave back (fee_minor) or kept (fee_kept_minor).
		$refund = null === $refund ? null : (array) $refund;
		$refunded = $refund && array_key_exists( 'fee_minor', $refund ) ? (int) $refund['fee_minor'] : null;
		$kept = $refund && array_key_exists( 'fee_kept_minor', $refund ) ? (int) $refund['fee_kept_minor'] : 0;
		if ( null !== $refunded && 0 === $refunded && $kept > 0 ) { return $out; } // Fee kept: no fee line takes part.
		foreach ( $items as $i => $item ) {
			if ( self::KIND_TICKET !== (string) ( $item->fee_kind ?? '' ) ) { continue; }
			$n = (int) ( $counts[ (int) $item->ticket_type_id ] ?? 0 );
			if ( $n < 1 ) { continue; }
			$line = (object) array( 'fee_kind' => self::KIND_TICKET, 'unit_price_minor' => (int) ( $item->fee_unit_minor ?? $item->unit_price_minor ?? 0 ), 'line_total_minor' => (int) $item->line_total_minor );
			$covered = self::covered( $line );
			$share = min( $n, $covered );
			$out[ $i ] = array( 0, intdiv( (int) $item->line_total_minor * $share, $covered ), intdiv( (int) $item->tax_minor * $share, $covered ) );
		}
		if ( null !== $refunded && $refunded > 0 ) {
			// Only part of the fees went back (mixed decisions): scale the per-ticket weights to it; what is left
			// is the per-order fee (with the last ticket).
			$sum = array_sum( array_column( $out, 1 ) );
			if ( $sum > $refunded ) {
				foreach ( $out as $i => $w ) { $out[ $i ] = array( 0, intdiv( $w[1] * $refunded, $sum ), intdiv( $w[2] * $refunded, $sum ) ); }
				$sum = array_sum( array_column( $out, 1 ) );
			}
			foreach ( $items as $i => $item ) {
				if ( self::KIND_ORDER !== (string) ( $item->fee_kind ?? '' ) || $refunded - $sum <= 0 ) { continue; }
				$part = min( (int) $item->line_total_minor, $refunded - $sum );
				$out[ $i ] = array( 0, $part, (int) $item->line_total_minor > 0 ? intdiv( (int) $item->tax_minor * $part, (int) $item->line_total_minor ) : 0 );
			}
		}
		return $out;
	}

	/** Refund rule for buyer-initiated refunds (setting service_fee_refund): 'retain' (default) or 'refund'. */
	public static function buyer_refund_rule() {
		return 'refund' === (string) Settings::get( 'service_fee_refund' ) ? 'refund' : 'retain';
	}

	/**
	 * Service fee part of a ticket refund (owner decision 23.11.2026, Refunds::calculate()):
	 *  - tickets of a cancelled occurrence or event (organizer cancellation) always take their per-ticket fee
	 *    back, and the per-order fee goes back with the last ticket;
	 *  - other refunds (buyer's wish: order portal request, staff refund without cancellation) follow the
	 *    setting service_fee_refund (default: retain);
	 *  - $rule 'refund' / 'retain' (refund option service_fee, e.g. from the admin refund form or a
	 *    cancellation flow) decides for all tickets of the refund.
	 * Filter `wpcap_service_fee_refund_rule` (string $rule, int $ticket_id, bool $cancelled, object $order).
	 *
	 * @param object $order      Order row.
	 * @param int[]  $ticket_ids Refunded tickets.
	 * @param bool   $last       Whether these are the last open tickets (the per-order fee is part of the rest).
	 * @param string $rule       '' (automatic), 'refund' or 'retain'.
	 * @return array{refund:int, refund_tax:int, kept:int, kept_tax:int} Gross and VAT of the fee refunded / kept.
	 */
	public static function refund_part( $order, array $ticket_ids, $last, $rule = '' ) {
		global $wpdb;
		$out = array( 'refund' => 0, 'refund_tax' => 0, 'kept' => 0, 'kept_tax' => 0 );
		if ( ! self::ready() || ! is_object( $order ) || ! $ticket_ids ) { return $out; }
		$p = $wpdb->prefix;
		$lines = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,fee_kind,unit_price_minor,line_total_minor,tax_minor FROM %i WHERE order_id=%d AND fee_kind<>'' ORDER BY item_id", $p . 'wpcap_order_items', (int) $order->order_id ) );
		if ( ! $lines ) { return $out; }
		$unit = array();
		$order_fee = null;
		foreach ( $lines as $line ) {
			if ( self::KIND_ORDER === (string) $line->fee_kind ) { $order_fee = $line; continue; }
			$n = self::covered( $line );
			$unit[ (int) $line->ticket_type_id ] = array( (int) $line->line_total_minor / $n, (int) $line->tax_minor / $n );
		}
		$ids = array_values( array_map( 'absint', $ticket_ids ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.ticket_type_id,(o.status='cancelled' OR e.status='cancelled') AS cancelled FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.ticket_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( (int) $order->order_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$buyer = self::buyer_refund_rule();
		$any_refund = false;
		$refund = array( 0.0, 0.0 );
		$kept = array( 0.0, 0.0 );
		foreach ( $rows as $row ) {
			$cancelled = (bool) (int) $row->cancelled;
			$decision = in_array( $rule, array( 'refund', 'retain' ), true ) ? $rule : ( $cancelled ? 'refund' : $buyer );
			/**
			 * Whether the service fee of a refunded ticket goes back ('refund') or is kept ('retain').
			 *
			 * @param string $decision  Default decision (cancellation: refund; else the refund option or setting).
			 * @param int    $ticket_id Ticket.
			 * @param bool   $cancelled Whether its occurrence or event was cancelled.
			 * @param object $order     Order row.
			 */
			$decision = 'retain' === apply_filters( 'wpcap_service_fee_refund_rule', $decision, (int) $row->ticket_id, $cancelled, $order ) ? 'retain' : 'refund';
			if ( 'refund' === $decision ) { $any_refund = true; }
			$fee = $unit[ (int) $row->ticket_type_id ] ?? null;
			if ( ! $fee ) { continue; }
			if ( 'refund' === $decision ) {
				$refund[0] += $fee[0];
				$refund[1] += $fee[1];
			} else {
				$kept[0] += $fee[0];
				$kept[1] += $fee[1];
			}
		}
		if ( $last && $order_fee ) {
			// The per-order fee goes back with the last ticket when that refund gives fees back.
			if ( $any_refund ) {
				$refund[0] += (int) $order_fee->line_total_minor;
				$refund[1] += (int) $order_fee->tax_minor;
			} else {
				$kept[0] += (int) $order_fee->line_total_minor;
				$kept[1] += (int) $order_fee->tax_minor;
			}
		}
		return array( 'refund' => (int) round( $refund[0] ), 'refund_tax' => (int) round( $refund[1] ), 'kept' => (int) round( $kept[0] ), 'kept_tax' => (int) round( $kept[1] ) );
	}

	/** Whether the fee counts as organizer revenue in settlements (setting service_fee_to_partner, default off). */
	public static function to_partner() {
		return (bool) Settings::get( 'service_fee_to_partner' );
	}

	/** Whether an order line (row with fee_kind) is a service fee line. */
	public static function is_fee_line( $line ) {
		return is_object( $line ) && '' !== (string) ( $line->fee_kind ?? '' );
	}

	/** Number of tickets a per-ticket fee line covers (1 for the per-order fee). */
	public static function covered( $line ) {
		$unit = (int) ( $line->unit_price_minor ?? 0 );
		$total = (int) ( $line->orig_line_total_minor ?? $line->line_total_minor ?? 0 );
		return self::KIND_TICKET === (string) ( $line->fee_kind ?? '' ) && $unit > 0 ? max( 1, intdiv( $total, $unit ) ) : 1;
	}

	/**
	 * Display name of a fee line.
	 *
	 * @param object $line      Order line with fee_kind.
	 * @param string $type_name Name of the ticket type it belongs to.
	 */
	public static function label( $line, $type_name = '' ) {
		if ( self::KIND_ORDER === (string) ( $line->fee_kind ?? '' ) ) {
			return __( 'Servicegebühr je Bestellung', 'pass-captain' );
		}
		return '' !== (string) $type_name
			/* translators: %s: ticket type name */
			? sprintf( __( 'Servicegebühr (%s)', 'pass-captain' ), $type_name )
			: __( 'Servicegebühr', 'pass-captain' );
	}

	/** wpcap_invoice_items: fee lines are named as such and count the tickets they cover. */
	public static function invoice_items( $items ) {
		foreach ( (array) $items as $index => $item ) {
			if ( ! self::is_fee_line( $item ) ) { continue; }
			$item = clone $item;
			$item->name = self::label( $item, (string) ( $item->name ?? '' ) );
			$item->quantity = self::covered( $item );
			$items[ $index ] = $item;
		}
		return $items;
	}

	/**
	 * Gross service fee and VAT per currency of paid orders in a range (reports).
	 *
	 * @param int[] $order_ids Orders of the report.
	 * @return array<string, array{gross:int, tax:int, orders:int}>
	 */
	public static function totals( array $order_ids ) {
		global $wpdb;
		$out = array();
		if ( ! $order_ids || ! self::ready() ) { return $out; }
		$p = $wpdb->prefix;
		foreach ( array_chunk( array_values( array_unique( array_map( 'absint', $order_ids ) ) ), 500 ) as $chunk ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.currency,COUNT(DISTINCT oi.order_id) AS orders,SUM(oi.line_total_minor) AS gross,SUM(oi.tax_minor) AS tax FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders o ON o.order_id=oi.order_id WHERE oi.fee_kind<>'' AND oi.order_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') GROUP BY o.currency', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
			foreach ( $rows as $row ) {
				$cur = (string) $row->currency;
				$out[ $cur ] = array(
					'gross' => (int) ( $out[ $cur ]['gross'] ?? 0 ) + (int) $row->gross,
					'tax' => (int) ( $out[ $cur ]['tax'] ?? 0 ) + (int) $row->tax,
					'orders' => (int) ( $out[ $cur ]['orders'] ?? 0 ) + (int) $row->orders,
				);
			}
		}
		return $out;
	}
}
