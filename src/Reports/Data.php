<?php
/**
 * Report figures: revenue (gross/net/VAT per rate) per event, occurrence, ticket type, payment provider
 * and day with completed refunds netted, tickets sold vs capacity, check-in rates and hourly histogram,
 * box office vs online share.
 *
 * Sales are counted on the order creation day (paid or later refunded orders), refunds on their
 * completion day (wpcap_refunds.updated_at); refunds of multi-purpose voucher orders count cash + voucher
 * share with the full VAT from the stored refund plans (Vouchers\Checkout::refund_amounts()). Credit notes mirror refunds and are listed for reference
 * only (not subtracted twice). Discounts are allocated to lines like Orders::create().
 * Complimentary orders (guest lists, comp_providers()) are not sales: reported as `comps` only.
 */
namespace PassCaptain\Reports;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Data {
	const DIMENSIONS = array( 'event', 'occurrence', 'ticket_type', 'provider', 'day' );

	/**
	 * Event scope of the current user for reports: administrators see everything, finance everything
	 * inside its location/partner scope (A119; unscoped finance users: everything), wpcap_view_reports
	 * only assigned events. Occurrence-level scope is applied per line (in_scope()).
	 *
	 * @return int[]|null|false Null = all, false = no access.
	 */
	public static function scope() {
		if ( current_user_can( 'manage_options' ) ) { return null; }
		if ( current_user_can( 'wpcap_view_finance' ) ) { return \PassCaptain\Access::filter_event_ids( null ); }
		if ( ! current_user_can( 'wpcap_view_reports' ) ) { return false; }
		return Scope::event_ids( 'wpcap_view_reports' );
	}

	private static function in_scope( $event_id, $scope, $occurrence_id = 0 ) {
		if ( null !== $scope && ! in_array( (int) $event_id, (array) $scope, true ) ) { return false; }
		return ! $occurrence_id || \PassCaptain\Access::occurrence_in_scope( (int) $occurrence_id );
	}

	public static function valid_date( $date ) {
		return is_string( $date ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** UTC bounds [from, to) for local dates in the timezone. */
	public static function bounds( $from, $to, $tz ) {
		$zone = new \DateTimeZone( $tz );
		$utc = new \DateTimeZone( 'UTC' );
		$start = ( new \DateTimeImmutable( $from . ' 00:00:00', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end = ( new \DateTimeImmutable( $to . ' 00:00:00', $zone ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		return array( $start, $end );
	}

	/**
	 * Payment providers of complimentary orders (guest lists, payment_provider "comp"). Their tickets
	 * are not sales: they are excluded from revenue/tickets and reported separately as "comps".
	 * Filter `wpcap_comp_payment_providers` (guest list comps; added for the GuestLists module).
	 *
	 * @return string[]
	 */
	public static function comp_providers() {
		// comp: guest list comps; pos_free: box office "Freikarte" – neither is a sale (no revenue, no ticket fee).
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'wpcap_comp_payment_providers', array( 'comp', 'pos_free' ) ) ) ) );
	}

	/** SQL list of comp providers for IN (...) (sanitize_key values only). */
	private static function comp_sql() {
		$providers = self::comp_providers();
		return $providers ? "'" . implode( "','", $providers ) . "'" : "''";
	}

	private static function empty_bucket( $label ) {
		return array( 'label' => $label, 'tickets' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0, 'refunded_tickets' => 0, 'refunded' => 0, 'refunded_tax' => 0, 'revenue' => 0, 'revenue_tax' => 0, 'revenue_net' => 0 );
	}

	/**
	 * @param array $filters from, to (Y-m-d, local), event_id (0 = all), occurrence_id (histogram).
	 * @param int[]|null $scope Event scope (see scope()).
	 */
	/** Report channels: key => label. */
	public static function channel_labels() {
		return array(
			'pos' => __( 'Kasse', 'wp-pass-captain' ),
			'partner' => __( 'Veranstalter/Wiederverkäufer (API)', 'wp-pass-captain' ),
			'woocommerce' => __( 'WooCommerce-Shop', 'wp-pass-captain' ),
			'online' => __( 'Online/sonstige', 'wp-pass-captain' ),
		);
	}

	/** Sales channel of an order by its payment provider (filter wpcap_report_channel for own providers). */
	public static function channel_of( $provider ) {
		$provider = (string) $provider;
		if ( 0 === strpos( $provider, 'pos_' ) ) { $channel = 'pos'; }
		elseif ( 'partner' === $provider ) { $channel = 'partner'; }
		elseif ( 'woocommerce' === $provider ) { $channel = 'woocommerce'; }
		else { $channel = 'online'; }
		$channel = (string) apply_filters( 'wpcap_report_channel', $channel, $provider );
		return array_key_exists( $channel, self::channel_labels() ) ? $channel : 'online';
	}

	public static function build( array $filters, $scope ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tz = Scope::site_timezone();
		$from = self::valid_date( $filters['from'] ?? '' ) ? $filters['from'] : gmdate( 'Y-m-01' );
		$to = self::valid_date( $filters['to'] ?? '' ) ? $filters['to'] : gmdate( 'Y-m-d' );
		if ( $to < $from ) { list( $from, $to ) = array( $to, $from ); }
		$event_filter = absint( $filters['event_id'] ?? 0 );
		if ( $event_filter ) {
			$scope = self::in_scope( $event_filter, $scope ) ? array( $event_filter ) : array();
		}
		list( $start, $end ) = self::bounds( $from, $to, $tz );

		$dims = array();
		foreach ( self::DIMENSIONS as $d ) { $dims[ $d ] = array(); }
		$rates = array();
		// Sales channels (fixed order; empty channels stay for a stable table): box office, partner API
		// (organizers/resellers), WooCommerce shop, own online checkout and other providers.
		$channels = array();
		foreach ( self::channel_labels() as $channel_key => $channel_label ) { $channels[ $channel_key ] = self::empty_bucket( $channel_label ); }
		$total = self::empty_bucket( __( 'Gesamt', 'wp-pass-captain' ) );
		$currencies = array();
		// Guest list comps (GuestLists module): counted separately, never as sales.
		$comp_providers = self::comp_providers();
		$comps = array( 'orders' => 0, 'tickets' => 0 );

		// Per currency (amounts of different currencies are never added up in these buckets).
		$by_currency = array();
		$add = static function ( array $keys, $field_values ) use ( &$dims, &$rates, &$channels, &$total, &$by_currency ) {
			foreach ( $keys['dims'] as $dim => $kl ) {
				list( $key, $label ) = $kl;
				if ( ! isset( $dims[ $dim ][ $key ] ) ) { $dims[ $dim ][ $key ] = self::empty_bucket( $label ); }
				foreach ( $field_values as $f => $v ) { $dims[ $dim ][ $key ][ $f ] += $v; }
			}
			$rate = $keys['rate'];
			if ( ! isset( $rates[ $rate ] ) ) { $rates[ $rate ] = self::empty_bucket( number_format_i18n( $rate / 100, 2 ) . ' %' ); }
			$cur = $keys['currency'];
			if ( ! isset( $by_currency[ $cur ] ) ) { $by_currency[ $cur ] = array( 'total' => self::empty_bucket( $cur ), 'rates' => array() ); }
			if ( ! isset( $by_currency[ $cur ]['rates'][ $rate ] ) ) { $by_currency[ $cur ]['rates'][ $rate ] = self::empty_bucket( $rates[ $rate ]['label'] ); }
			foreach ( $field_values as $f => $v ) {
				$rates[ $rate ][ $f ] += $v;
				$channels[ $keys['channel'] ][ $f ] += $v;
				$total[ $f ] += $v;
				$by_currency[ $cur ]['total'][ $f ] += $v;
				$by_currency[ $cur ]['rates'][ $rate ][ $f ] += $v;
			}
		};
		$keys_for = static function ( $line, $provider, $day, $currency ) {
			return array(
				'dims' => array(
					'event' => array( (int) $line->event_id, $line->event_name ),
					'occurrence' => array( (int) $line->occurrence_id, $line->event_name . ' – ' . Scope::local( $line->starts_at, $line->timezone ) ),
					'ticket_type' => array( (int) $line->ticket_type_id, $line->event_name . ' – ' . Scope::local( $line->starts_at, $line->timezone ) . ' – ' . $line->ticket_name ),
					'provider' => array( (string) $provider, (string) $provider ),
					'day' => array( $day, $day ),
				),
				'rate' => (int) $line->tax_rate_bp,
				'channel' => self::channel_of( $provider ),
				'currency' => (string) $currency,
			);
		};
		$line_sql = self::line_sql();

		// Sales.
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,created_at,payment_provider,currency,subtotal_minor,discount_minor FROM {$p}wpcap_orders WHERE payment_status IN ('paid','refunded') AND created_at >= %s AND created_at < %s ORDER BY order_id", $start, $end ) );
		$order_map = array();
		foreach ( $orders as $o ) { $order_map[ (int) $o->order_id ] = $o; }
		$lines_by_order = self::lines_by_order( array_keys( $order_map ), $line_sql );
		foreach ( $order_map as $order_id => $order ) {
			$lines = $lines_by_order[ $order_id ] ?? array();
			if ( in_array( (string) $order->payment_provider, $comp_providers, true ) ) {
				$in_scope = false;
				foreach ( $lines as $line ) {
					if ( ! self::in_scope( $line->event_id, $scope, $line->occurrence_id ) ) { continue; }
					$comps['tickets'] += (int) $line->quantity;
					$in_scope = true;
				}
				if ( $in_scope ) { ++$comps['orders']; }
				continue;
			}
			$gross_lines = self::allocate_discount( $lines, (int) $order->discount_minor );
			$day = Scope::local( $order->created_at, $tz, 'Y-m-d' );
			$supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order_id );
			foreach ( $lines as $i => $line ) {
				if ( ! self::in_scope( $line->event_id, $scope, $line->occurrence_id ) ) { continue; }
				if ( \PassCaptain\OrderLines::is_moved_in( $line ) ) { continue; } // Moved in by an exchange.
				$currencies[ $order->currency ] = true;
				$gross = $gross_lines[ $i ];
				$tax = (int) $line->tax_minor;
				$add( $keys_for( $line, $order->payment_provider, $day, $order->currency ), array( 'tickets' => $supplement ? 0 : (int) $line->quantity, 'gross' => $gross, 'tax' => $tax, 'net' => $gross - $tax, 'revenue' => $gross, 'revenue_tax' => $tax, 'revenue_net' => $gross - $tax ) );
			}
		}

		// Completed refunds (netted on their completion day).
		$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.updated_at,ord.payment_provider,ord.currency,ord.discount_minor,(ord.voucher_snapshot IS NOT NULL AND ord.voucher_snapshot<>'') AS has_voucher FROM {$p}wpcap_refunds r INNER JOIN {$p}wpcap_orders ord ON ord.order_id=r.order_id WHERE r.status='completed' AND r.updated_at >= %s AND r.updated_at < %s ORDER BY r.refund_id", $start, $end ) );
		$refund_lines = self::lines_by_order( array_unique( array_map( static function ( $r ) { return (int) $r->order_id; }, $refunds ) ), $line_sql );
		foreach ( $refunds as $refund ) {
			$lines = $refund_lines[ (int) $refund->order_id ] ?? array();
			if ( ! $lines ) { continue; }
			$day = Scope::local( $refund->updated_at, $tz, 'Y-m-d' );
			foreach ( self::refund_split( $refund, $lines ) as $part ) {
				$line = $lines[ $part['index'] ];
				if ( ! self::in_scope( $line->event_id, $scope, $line->occurrence_id ) ) { continue; }
				$add( $keys_for( $line, $refund->payment_provider, $day, $refund->currency ), array( 'refunded_tickets' => $part['tickets'], 'refunded' => $part['amount'], 'refunded_tax' => $part['tax'], 'revenue' => -$part['amount'], 'revenue_tax' => -$part['tax'], 'revenue_net' => $part['tax'] - $part['amount'] ) );
			}
		}
		foreach ( $dims as $d => $buckets ) {
			if ( 'day' === $d ) { ksort( $buckets ); } else { uasort( $buckets, static function ( $a, $b ) { return strcmp( $a['label'], $b['label'] ); } ); }
			$dims[ $d ] = $buckets;
		}
		ksort( $rates );
		ksort( $by_currency );
		foreach ( $by_currency as $cur => $c ) { ksort( $by_currency[ $cur ]['rates'] ); }

		// Correction documents (Rechnungskorrekturen) in the range (reference only).
		$credit_rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.credit_note_number,c.total_minor,c.tax_minor,i.order_id FROM {$p}wpcap_credit_notes c INNER JOIN {$p}wpcap_invoices i ON i.invoice_id=c.invoice_id WHERE c.issued_at >= %s AND c.issued_at < %s", $start, $end ) );
		$credit = array( 'count' => 0, 'total' => 0, 'tax' => 0 );
		foreach ( $credit_rows as $c ) {
			$events = Scope::order_events( $c->order_id );
			if ( null !== $scope && array_diff( $events, (array) $scope ) ) { continue; }
			if ( ! \PassCaptain\Access::order_in_scope( $c->order_id ) ) { continue; }
			$credit['count']++;
			$credit['total'] += (int) $c->total_minor;
			$credit['tax'] += (int) $c->tax_minor;
		}

		$occurrences = self::occurrence_stats( array_keys( $dims['occurrence'] ), $start, $end, $scope );
		$histogram_occ = absint( $filters['occurrence_id'] ?? 0 );
		if ( ! isset( $occurrences[ $histogram_occ ] ) ) { $histogram_occ = (int) array_key_first( $occurrences ); }

		return array(
			'from' => $from,
			'to' => $to,
			'timezone' => $tz,
			'currency' => 1 === count( $currencies ) ? (string) array_key_first( $currencies ) : ( $currencies ? implode( '/', array_keys( $currencies ) ) : 'EUR' ),
			'total' => $total,
			'dimensions' => $dims,
			'rates' => $rates,
			'currencies' => $by_currency,
			'channels' => $channels,
			'credit_notes' => $credit,
			'comps' => $comps,
			'occurrences' => $occurrences,
			'histogram_occurrence' => $histogram_occ,
			'histogram' => $histogram_occ ? self::checkin_histogram( $histogram_occ ) : array(),
		);
	}

	/** SELECT for paid order lines with ticket type, occurrence and event (append a WHERE clause). */
	public static function line_sql() {
		global $wpdb;
		$p = $wpdb->prefix;
		return "SELECT oi.order_id,oi.item_id,oi.ticket_type_id," . \PassCaptain\OrderLines::paid_columns() . ",oi.tax_rate_bp,tt.name AS ticket_name,o.occurrence_id,o.starts_at,o.timezone,o.event_id,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id";
	}

	/**
	 * Splits a completed refund onto the order lines with the same allocation as the correction
	 * document (Invoices::refund_line_parts()): the refunded amount and VAT per tax rate are identical
	 * to the rows of the Rechnungskorrektur and of the accounting export. Multi-purpose voucher orders
	 * count cash + voucher share and the full VAT from the stored refund plan.
	 *
	 * @param object   $refund Refund row (refund_id, order_id, amount_minor, tax_minor, ticket_ids, optional
	 *                         discount_minor and has_voucher).
	 * @param object[] $lines  Lines of the order (lines_by_order()).
	 * @return array[] List of ['index' => line index, 'tickets' => int, 'amount' => int, 'tax' => int].
	 */
	public static function refund_split( $refund, array $lines ) {
		global $wpdb;
		if ( ! $lines ) { return array(); }
		$lines = array_values( $lines );
		$amounts = \PassCaptain\Integrations\Vouchers\Checkout::refund_amounts( $refund );
		$total_amount = (int) $amounts['amount_minor'];
		$total_tax = (int) $amounts['tax_minor'];
		$discount = isset( $refund->discount_minor ) ? (int) $refund->discount_minor : (int) $wpdb->get_var( $wpdb->prepare( "SELECT discount_minor FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", (int) ( $refund->order_id ?? 0 ) ) );
		$out = array();
		foreach ( \PassCaptain\Invoices::refund_line_parts( $refund, $lines, $discount, $total_amount, $total_tax ) as $part ) {
			if ( 0 === $part['gross'] && 0 === $part['tax'] && 0 === $part['tickets'] ) { continue; }
			$out[] = array( 'index' => $part['index'], 'tickets' => $part['tickets'], 'amount' => $part['gross'], 'tax' => $part['tax'] );
		}
		if ( ! $out && ( $total_amount || $total_tax ) ) {
			// No line carries a paid value: book the refund on the last line (totals stay complete).
			$out[] = array( 'index' => count( $lines ) - 1, 'tickets' => 0, 'amount' => $total_amount, 'tax' => $total_tax );
		}
		return $out;
	}

	/** Order lines grouped by order id (item order = Orders::create order). */
	public static function lines_by_order( array $order_ids, $line_sql = null ) {
		global $wpdb;
		$line_sql = $line_sql ?? self::line_sql();
		$out = array();
		foreach ( array_chunk( array_map( 'absint', $order_ids ), 500 ) as $chunk ) {
			foreach ( $wpdb->get_results( $line_sql . ' WHERE oi.order_id IN (' . implode( ',', $chunk ) . ') ORDER BY oi.order_id,oi.item_id' ) as $line ) {
				$out[ (int) $line->order_id ][] = $line;
			}
		}
		return $out;
	}

	/** Gross per line after the order discount (stored line discounts, see OrderLines::after_discount()). */
	public static function allocate_discount( array $lines, $discount ) {
		return \PassCaptain\OrderLines::after_discount( $lines, $discount );
	}

	/**
	 * Sold vs capacity and check-in rate for occurrences with sales in the range or starting in it.
	 */
	public static function occurrence_stats( array $occurrence_ids, $start, $end, $scope ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$starting = $wpdb->get_col( $wpdb->prepare( "SELECT occurrence_id FROM {$p}wpcap_occurrences WHERE starts_at >= %s AND starts_at < %s", $start, $end ) );
		$ids = array_values( array_unique( array_merge( array_map( 'absint', $occurrence_ids ), array_map( 'absint', $starting ) ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', $ids );
		$out = array();
		$occ_rows = $wpdb->get_results( "SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.capacity,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id IN ($in) ORDER BY o.starts_at" );
		$comp_in = self::comp_sql();
		$types = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.occurrence_id,tt.name,tt.capacity,COALESCE((SELECT SUM(oi.quantity-oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=tt.ticket_type_id AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.payment_provider NOT IN ($comp_in)),0) AS sold,COALESCE((SELECT SUM(oi.quantity-oi.refunded_quantity) FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=tt.ticket_type_id AND ord.payment_status='paid' AND ord.status <> 'refunded' AND ord.payment_provider IN ($comp_in)),0) AS comps FROM {$p}wpcap_ticket_types tt WHERE tt.occurrence_id IN ($in) ORDER BY tt.ticket_type_id" );
		$issued = array();
		foreach ( $wpdb->get_results( "SELECT tt.occurrence_id,COUNT(*) AS n FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id IN ($in) AND t.status IN ('valid','checked_in') GROUP BY tt.occurrence_id" ) as $r ) { $issued[ (int) $r->occurrence_id ] = (int) $r->n; }
		$checked = array();
		foreach ( $wpdb->get_results( "SELECT tt.occurrence_id,COUNT(DISTINCT c.ticket_id) AS n FROM {$p}wpcap_checkins c INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id IN ($in) AND c.result='checked_in' AND t.status IN ('valid','checked_in') GROUP BY tt.occurrence_id" ) as $r ) { $checked[ (int) $r->occurrence_id ] = (int) $r->n; }
		foreach ( $occ_rows as $o ) {
			if ( ! self::in_scope( $o->event_id, $scope, $o->occurrence_id ) ) { continue; }
			$id = (int) $o->occurrence_id;
			$type_rows = array();
			$sold = 0;
			$comp_count = 0;
			$type_capacity = 0;
			$unlimited_type = false;
			foreach ( $types as $t ) {
				if ( (int) $t->occurrence_id !== $id ) { continue; }
				$type_rows[] = array( 'ticket_type_id' => (int) $t->ticket_type_id, 'name' => $t->name, 'capacity' => (int) $t->capacity, 'sold' => (int) $t->sold, 'comps' => (int) $t->comps );
				$sold += (int) $t->sold;
				$comp_count += (int) $t->comps;
				$type_capacity += (int) $t->capacity;
				$unlimited_type = $unlimited_type || 0 === (int) $t->capacity;
			}
			$capacity = (int) $o->capacity > 0 ? (int) $o->capacity : ( $unlimited_type ? 0 : $type_capacity );
			$out[ $id ] = array(
				'occurrence_id' => $id,
				'label' => $o->name . ' – ' . Scope::local( $o->starts_at, $o->timezone ),
				'capacity' => $capacity,
				'sold' => $sold,
				'comps' => $comp_count,
				// Utilisation = capacity in use, comps included (they consume capacity like sales).
				'utilisation' => $capacity > 0 ? round( 100 * ( $sold + $comp_count ) / $capacity, 1 ) : null,
				'issued' => $issued[ $id ] ?? 0,
				'checked_in' => $checked[ $id ] ?? 0,
				'checkin_rate' => ! empty( $issued[ $id ] ) ? round( 100 * ( $checked[ $id ] ?? 0 ) / $issued[ $id ], 1 ) : null,
				'types' => $type_rows,
			);
		}
		return $out;
	}

	/** Successful check-ins per local hour of the occurrence ("Y-m-d H:00" => count). */
	public static function checkin_histogram( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tz = (string) $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		$times = $wpdb->get_col( $wpdb->prepare( "SELECT COALESCE(c.scanned_at,c.created_at) FROM {$p}wpcap_checkins c INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=c.ticket_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND c.result='checked_in'", absint( $occurrence_id ) ) );
		$out = array();
		foreach ( $times as $time ) {
			$hour = Scope::local( $time, $tz ?: 'UTC', 'Y-m-d H:00' );
			$out[ $hour ] = ( $out[ $hour ] ?? 0 ) + 1;
		}
		ksort( $out );
		if ( count( $out ) > 1 ) {
			// Fill empty hours so the histogram has a continuous axis (max. 72 bars).
			$first = new \DateTimeImmutable( array_key_first( $out ) . ':00' );
			$last = array_key_last( $out );
			$filled = array();
			for ( $h = $first, $n = 0; $n < 72; $h = $h->modify( '+1 hour' ), $n++ ) {
				$key = $h->format( 'Y-m-d H:00' );
				$filled[ $key ] = $out[ $key ] ?? 0;
				if ( $key === $last ) { break; }
			}
			$out = $filled;
		}
		return $out;
	}

	/** Amount fields of a report bucket that add up across buckets. */
	const SUM_FIELDS = array( 'tickets', 'gross', 'tax', 'net', 'refunded_tickets', 'refunded', 'refunded_tax', 'revenue', 'revenue_tax', 'revenue_net' );

	/**
	 * Arithmetic acceptance of a report (build()): every bucket satisfies net = gross − VAT,
	 * revenue = gross − refunded, revenue VAT = VAT − refunded VAT and revenue net = revenue − revenue VAT;
	 * every grouping (event, occurrence, ticket type, provider, day, tax rate, channel) adds up to the
	 * total, the currencies add up to the total and the tax rates of each currency to its total.
	 *
	 * @param array $report Result of build().
	 * @return string[] Failed checks (empty when the report reconciles), e.g. "rates:revenue".
	 */
	public static function reconcile( array $report ) {
		$failed = array();
		$check_bucket = static function ( $name, array $b ) use ( &$failed ) {
			$rules = array(
				'net' => (int) $b['gross'] - (int) $b['tax'] === (int) $b['net'],
				'revenue' => (int) $b['gross'] - (int) $b['refunded'] === (int) $b['revenue'],
				'revenue_tax' => (int) $b['tax'] - (int) $b['refunded_tax'] === (int) $b['revenue_tax'],
				'revenue_net' => (int) $b['revenue'] - (int) $b['revenue_tax'] === (int) ( $b['revenue_net'] ?? $b['revenue'] - $b['revenue_tax'] ),
			);
			foreach ( $rules as $rule => $ok ) {
				if ( ! $ok ) { $failed[] = $name . ':' . $rule; }
			}
		};
		$check_sum = static function ( $name, array $buckets, array $total ) use ( &$failed ) {
			foreach ( self::SUM_FIELDS as $f ) {
				if ( array_sum( array_map( static function ( $b ) use ( $f ) { return (int) ( $b[ $f ] ?? 0 ); }, $buckets ) ) !== (int) ( $total[ $f ] ?? 0 ) ) { $failed[] = $name . ':' . $f; }
			}
		};
		$total = $report['total'];
		$check_bucket( 'total', $total );
		foreach ( (array) $report['dimensions'] as $dim => $buckets ) {
			foreach ( $buckets as $key => $b ) { $check_bucket( $dim . '/' . $key, $b ); }
			$check_sum( $dim, $buckets, $total );
		}
		foreach ( array( 'rates', 'channels' ) as $group ) {
			foreach ( (array) $report[ $group ] as $key => $b ) { $check_bucket( $group . '/' . $key, $b ); }
			$check_sum( $group, (array) $report[ $group ], $total );
		}
		$currencies = (array) ( $report['currencies'] ?? array() );
		$check_sum( 'currencies', array_column( $currencies, 'total' ), $total );
		foreach ( $currencies as $cur => $c ) {
			$check_bucket( 'currency/' . $cur, $c['total'] );
			$check_sum( 'currency/' . $cur . '/rates', $c['rates'], $c['total'] );
		}
		return array_values( array_unique( $failed ) );
	}

	/** CSV rows of a report (amounts as decimals). */
	public static function csv_rows( array $report ) {
		$m = static function ( $minor ) { return number_format( $minor / 100, 2, '.', '' ); };
		$head = array( __( 'Tickets', 'wp-pass-captain' ), __( 'Brutto', 'wp-pass-captain' ), __( 'Netto', 'wp-pass-captain' ), __( 'USt', 'wp-pass-captain' ), __( 'Erstattete Tickets', 'wp-pass-captain' ), __( 'Erstattet brutto', 'wp-pass-captain' ), __( 'Erstattet USt', 'wp-pass-captain' ), __( 'Umsatz nach Erstattung', 'wp-pass-captain' ), __( 'USt nach Erstattung', 'wp-pass-captain' ), __( 'Netto nach Erstattung', 'wp-pass-captain' ) );
		$vals = static function ( $b ) use ( $m ) { return array( $b['tickets'], $m( $b['gross'] ), $m( $b['net'] ), $m( $b['tax'] ), $b['refunded_tickets'], $m( $b['refunded'] ), $m( $b['refunded_tax'] ), $m( $b['revenue'] ), $m( $b['revenue_tax'] ), $m( $b['revenue_net'] ?? ( $b['revenue'] - $b['revenue_tax'] ) ) ); };
		$rows = array( array( __( 'Bericht', 'wp-pass-captain' ), $report['from'], $report['to'], $report['timezone'], $report['currency'] ) );
		$sections = array( 'event' => __( 'Event', 'wp-pass-captain' ), 'occurrence' => __( 'Termin', 'wp-pass-captain' ), 'ticket_type' => __( 'Ticketart', 'wp-pass-captain' ), 'provider' => __( 'Zahlungsanbieter', 'wp-pass-captain' ), 'day' => __( 'Tag', 'wp-pass-captain' ) );
		foreach ( $sections as $dim => $label ) {
			$rows[] = array();
			$rows[] = array_merge( array( $label ), $head );
			foreach ( $report['dimensions'][ $dim ] as $b ) { $rows[] = array_merge( array( $b['label'] ), $vals( $b ) ); }
		}
		$rows[] = array();
		$rows[] = array_merge( array( __( 'USt-Satz', 'wp-pass-captain' ) ), $head );
		foreach ( $report['rates'] as $b ) { $rows[] = array_merge( array( $b['label'] ), $vals( $b ) ); }
		$rows[] = array();
		$rows[] = array_merge( array( __( 'Kanal', 'wp-pass-captain' ) ), $head );
		foreach ( $report['channels'] as $b ) { $rows[] = array_merge( array( $b['label'] ), $vals( $b ) ); }
		$rows[] = array_merge( array( $report['total']['label'] ), $vals( $report['total'] ) );
		// Per currency and tax rate (the totals above only add up within one currency).
		$rows[] = array();
		$rows[] = array_merge( array( __( 'Währung', 'wp-pass-captain' ), __( 'USt-Satz', 'wp-pass-captain' ) ), $head );
		foreach ( (array) ( $report['currencies'] ?? array() ) as $cur => $c ) {
			foreach ( $c['rates'] as $b ) { $rows[] = array_merge( array( $cur, $b['label'] ), $vals( $b ) ); }
			$rows[] = array_merge( array( $cur, $report['total']['label'] ), $vals( $c['total'] ) );
		}
		$rows[] = array();
		$rows[] = array( __( 'Termin', 'wp-pass-captain' ), __( 'Kapazität', 'wp-pass-captain' ), __( 'Verkauft', 'wp-pass-captain' ), __( 'Auslastung %', 'wp-pass-captain' ), __( 'Gültige Tickets', 'wp-pass-captain' ), __( 'Eingecheckt', 'wp-pass-captain' ), __( 'Check-in-Quote %', 'wp-pass-captain' ) );
		foreach ( $report['occurrences'] as $o ) {
			$rows[] = array( $o['label'], $o['capacity'] ?: '∞', $o['sold'], null === $o['utilisation'] ? '' : number_format( $o['utilisation'], 1, '.', '' ), $o['issued'], $o['checked_in'], null === $o['checkin_rate'] ? '' : number_format( $o['checkin_rate'], 1, '.', '' ) );
		}
		$rows[] = array();
		$rows[] = array( __( 'Rechnungskorrekturen', 'wp-pass-captain' ), $report['credit_notes']['count'], $m( $report['credit_notes']['total'] ), $m( $report['credit_notes']['tax'] ) );
		if ( ! empty( $report['comps']['tickets'] ) ) {
			$rows[] = array( __( 'Freikarten (nicht im Umsatz)', 'wp-pass-captain' ), (int) $report['comps']['tickets'], (int) $report['comps']['orders'] );
		}
		if ( $report['histogram'] ) {
			$rows[] = array();
			$rows[] = array( __( 'Check-ins pro Stunde', 'wp-pass-captain' ), $report['occurrences'][ $report['histogram_occurrence'] ]['label'] ?? '' );
			foreach ( $report['histogram'] as $hour => $n ) { $rows[] = array( $hour, $n ); }
		}
		return $rows;
	}
}
