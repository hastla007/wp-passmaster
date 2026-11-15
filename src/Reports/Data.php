<?php
/**
 * Report figures: revenue (gross/net/VAT per rate) per event, occurrence, ticket type, payment provider
 * and day with completed refunds netted, tickets sold vs capacity, check-in rates and hourly histogram,
 * box office vs online share.
 *
 * Sales are counted on the order creation day (paid or later refunded orders), refunds on their
 * completion day (wpcap_refunds.updated_at). Credit notes mirror refunds and are listed for reference
 * only (not subtracted twice). Discounts are allocated to lines like Orders::create().
 *
 * Campaign breakdown: orders, tickets, allocated discount, revenue (gross after discounts) of the
 * orders in the range and completed refunds of the range per redeemed campaign code (net revenue =
 * revenue − refunds); orders without a code form their own row. An order with two codes is split
 * between them proportionally to their discounts (deterministic, never counted twice), refunds
 * follow their order's split. Only lines of events in the user's scope count.
 * Comparison period (filter compare): "previous" = the same number of days directly before,
 * "last_year" = the same dates one year earlier; same scope and event filter.
 */
namespace PassCaptain\Reports;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Data {
	const DIMENSIONS = array( 'event', 'occurrence', 'ticket_type', 'provider', 'day' );
	const COMPARE = array( 'previous', 'last_year' );

	/** QA-12: longest report period in days (longer ranges are shortened to the last 366 days, with a notice). */
	const MAX_RANGE_DAYS = 366;

	/**
	 * Comparison range for a report range.
	 *
	 * @return string[]|null [from, to] (Y-m-d) or null for no comparison.
	 */
	public static function comparison_range( $from, $to, $mode ) {
		if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) ) { return null; }
		$utc = new \DateTimeZone( 'UTC' );
		if ( 'previous' === $mode ) {
			$start = new \DateTimeImmutable( $from, $utc );
			$days = (int) $start->diff( new \DateTimeImmutable( $to, $utc ) )->days + 1;
			return array( $start->modify( '-' . $days . ' days' )->format( 'Y-m-d' ), $start->modify( '-1 day' )->format( 'Y-m-d' ) );
		}
		if ( 'last_year' === $mode ) {
			$shift = static function ( $date ) {
				list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
				$y--;
				while ( ! checkdate( $m, $d, $y ) ) { $d--; } // 29 February → 28 February.
				return sprintf( '%04d-%02d-%02d', $y, $m, $d );
			};
			return array( $shift( $from ), $shift( $to ) );
		}
		return null;
	}

	/** Change between two values: absolute difference and percentage (null when the base is 0). */
	public static function delta( $current, $previous ) {
		$current = (int) $current;
		$previous = (int) $previous;
		return array( 'diff' => $current - $previous, 'pct' => 0 !== $previous ? round( 100 * ( $current - $previous ) / abs( $previous ), 1 ) : null );
	}

	/**
	 * Event scope of the current user for reports: finance and administrators see everything,
	 * wpcap_view_reports only assigned events.
	 *
	 * @return int[]|null|false Null = all, false = no access.
	 */
	public static function scope() {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'wpcap_view_finance' ) ) { return null; }
		if ( ! current_user_can( 'wpcap_view_reports' ) ) { return false; }
		return Scope::event_ids( 'wpcap_view_reports' );
	}

	private static function in_scope( $event_id, $scope ) {
		return null === $scope || in_array( (int) $event_id, (array) $scope, true );
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

	private static function empty_bucket( $label ) {
		return array( 'label' => $label, 'tickets' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0, 'refunded_tickets' => 0, 'refunded' => 0, 'refunded_tax' => 0, 'revenue' => 0, 'revenue_tax' => 0 );
	}

	/**
	 * @param array $filters from, to (Y-m-d, local), event_id (0 = all), occurrence_id (histogram).
	 * @param int[]|null $scope Event scope (see scope()).
	 */
	public static function build( array $filters, $scope ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$tz = Scope::site_timezone();
		$from = self::valid_date( $filters['from'] ?? '' ) ? $filters['from'] : gmdate( 'Y-m-01' );
		$to = self::valid_date( $filters['to'] ?? '' ) ? $filters['to'] : gmdate( 'Y-m-d' );
		if ( $to < $from ) { list( $from, $to ) = array( $to, $from ); }
		// QA-12: at most MAX_RANGE_DAYS per report (the comparison period has the same length).
		$clamped = false;
		if ( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS >= self::MAX_RANGE_DAYS ) {
			$from = gmdate( 'Y-m-d', strtotime( $to . ' 00:00:00 UTC' ) - ( self::MAX_RANGE_DAYS - 1 ) * DAY_IN_SECONDS );
			$clamped = true;
		}
		$event_filter = absint( $filters['event_id'] ?? 0 );
		if ( $event_filter ) {
			$scope = self::in_scope( $event_filter, $scope ) ? array( $event_filter ) : array();
		}
		list( $start, $end ) = self::bounds( $from, $to, $tz );

		$dims = array();
		foreach ( self::DIMENSIONS as $d ) { $dims[ $d ] = array(); }
		$rates = array();
		$channels = array( 'pos' => self::empty_bucket( __( 'Kasse', 'pass-captain' ) ), 'online' => self::empty_bucket( __( 'Online/sonstige', 'pass-captain' ) ) );
		$total = self::empty_bucket( __( 'Gesamt', 'pass-captain' ) );
		$currencies = array();

		$add = static function ( array $keys, $field_values ) use ( &$dims, &$rates, &$channels, &$total ) {
			foreach ( $keys['dims'] as $dim => $kl ) {
				list( $key, $label ) = $kl;
				if ( ! isset( $dims[ $dim ][ $key ] ) ) { $dims[ $dim ][ $key ] = self::empty_bucket( $label ); }
				foreach ( $field_values as $f => $v ) { $dims[ $dim ][ $key ][ $f ] += $v; }
			}
			$rate = $keys['rate'];
			if ( ! isset( $rates[ $rate ] ) ) { $rates[ $rate ] = self::empty_bucket( number_format_i18n( $rate / 100, 2 ) . ' %' ); }
			foreach ( $field_values as $f => $v ) {
				$rates[ $rate ][ $f ] += $v;
				$channels[ $keys['channel'] ][ $f ] += $v;
				$total[ $f ] += $v;
			}
		};
		$keys_for = static function ( $line, $provider, $day ) {
			return array(
				'dims' => array(
					'event' => array( (int) $line->event_id, $line->event_name ),
					'occurrence' => array( (int) $line->occurrence_id, $line->event_name . ' – ' . Scope::local( $line->starts_at, $line->timezone ) ),
					'ticket_type' => array( (int) $line->ticket_type_id, $line->event_name . ' – ' . Scope::local( $line->starts_at, $line->timezone ) . ' – ' . $line->ticket_name ),
					'provider' => array( (string) $provider, (string) $provider ),
					'day' => array( $day, $day ),
				),
				'rate' => (int) $line->tax_rate_bp,
				'channel' => 0 === strpos( (string) $provider, 'pos_' ) ? 'pos' : 'online',
			);
		};

		// Sales.
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,created_at,payment_provider,currency,subtotal_minor,discount_minor,price_snapshot FROM %i WHERE payment_status IN ('paid','refunded') AND created_at >= %s AND created_at < %s ORDER BY order_id", $p . 'wpcap_orders', $start, $end ) );
		$order_map = array();
		foreach ( $orders as $o ) { $order_map[ (int) $o->order_id ] = $o; }
		$lines_by_order = self::lines_by_order( array_keys( $order_map ) );
		$order_scope = array(); // order id => in-scope [tickets, gross after discount, line total before discount, all lines before discount].
		foreach ( $order_map as $order_id => $order ) {
			$lines = $lines_by_order[ $order_id ] ?? array();
			$gross_lines = self::allocate_discount( $lines, (int) $order->discount_minor, \PassCaptain\Orders::snapshot_line_discounts( $order ) );
			$day = Scope::local( $order->created_at, $tz, 'Y-m-d' );
			$share = array( 0, 0, 0, 0 );
			foreach ( $lines as $i => $line ) {
				$share[3] += (int) $line->line_total_minor;
				if ( ! self::in_scope( $line->event_id, $scope ) ) { continue; }
				$currencies[ $order->currency ] = true;
				$gross = $gross_lines[ $i ];
				$tax = (int) $line->tax_minor;
				$share[0] += (int) $line->quantity;
				$share[1] += $gross;
				$share[2] += (int) $line->line_total_minor;
				$add( $keys_for( $line, $order->payment_provider, $day ), array( 'tickets' => (int) $line->quantity, 'gross' => $gross, 'tax' => $tax, 'net' => $gross - $tax, 'revenue' => $gross, 'revenue_tax' => $tax ) );
			}
			if ( $share[0] ) { $order_scope[ $order_id ] = $share; }
		}

		// Completed refunds (netted on their completion day).
		$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.updated_at,ord.payment_provider,ord.discount_minor,(ord.voucher_snapshot IS NOT NULL AND ord.voucher_snapshot<>'') AS has_voucher FROM %i r INNER JOIN %i ord ON ord.order_id=r.order_id WHERE r.status='completed' AND r.updated_at >= %s AND r.updated_at < %s ORDER BY r.refund_id", $p . 'wpcap_refunds', $p . 'wpcap_orders', $start, $end ) );
		$refund_lines = self::lines_by_order( array_unique( array_map( static function ( $r ) { return (int) $r->order_id; }, $refunds ) ) );
		// Ticket types of all refunded tickets in one query per 500 tickets (not one query per refund).
		$refund_ticket_ids = array();
		foreach ( $refunds as $refund ) {
			foreach ( (array) json_decode( (string) $refund->ticket_ids, true ) as $id ) { $refund_ticket_ids[ absint( $id ) ] = true; }
		}
		unset( $refund_ticket_ids[0] );
		$ticket_types = array();
		foreach ( array_chunk( array_keys( $refund_ticket_ids ), 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,ticket_type_id FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_tickets' ), $chunk ) ) ) as $t ) {
				$ticket_types[ (int) $t->ticket_id ] = (int) $t->ticket_type_id;
			}
		}
		$refund_scope = array(); // order id => in-scope refunded amount (campaign attribution).
		foreach ( $refunds as $refund ) {
			$lines = $refund_lines[ (int) $refund->order_id ] ?? array();
			if ( ! $lines ) { continue; }
			$by_type = array();
			foreach ( $lines as $i => $line ) { $by_type[ (int) $line->ticket_type_id ] = $i; }
			// Sales count the full consideration (a multi-purpose voucher is a means of payment), so a
			// refund takes back its voucher part as well: the amounts of its credit note (as Settlements).
			if ( ! empty( $refund->has_voucher ) ) {
				$booked = \PassCaptain\Integrations\Vouchers\Checkout::credit_note_amounts( (int) $refund->refund_id );
				if ( $booked ) {
					$refund->amount_minor = (int) $booked['total_minor'];
					$refund->tax_minor = (int) $booked['tax_minor'];
				}
			}
			$ids = array_filter( array_map( 'absint', (array) json_decode( (string) $refund->ticket_ids, true ) ) );
			$weights = array(); // line index => [tickets, weight]
			if ( $ids ) {
				foreach ( $ids as $ticket_id ) {
					if ( ! isset( $ticket_types[ $ticket_id ] ) ) { continue; }
					$i = $by_type[ $ticket_types[ $ticket_id ] ] ?? null;
					if ( null === $i ) { continue; }
					$unit = intdiv( (int) $lines[ $i ]->line_total_minor, max( 1, (int) $lines[ $i ]->quantity ) );
					$weights[ $i ] = array( ( $weights[ $i ][0] ?? 0 ) + 1, ( $weights[ $i ][1] ?? 0 ) + $unit );
				}
			}
			if ( ! $weights ) {
				foreach ( $lines as $i => $line ) { $weights[ $i ] = array( 0, (int) $line->line_total_minor ); }
			}
			$sum = max( 1, array_sum( array_map( static function ( $w ) { return $w[1]; }, $weights ) ) );
			$amount_left = (int) $refund->amount_minor;
			$tax_left = (int) $refund->tax_minor;
			$keys = array_keys( $weights );
			$day = Scope::local( $refund->updated_at, $tz, 'Y-m-d' );
			foreach ( $keys as $n => $i ) {
				$last = $n === count( $keys ) - 1;
				$amount = $last ? $amount_left : intdiv( (int) $refund->amount_minor * $weights[ $i ][1], $sum );
				$tax = $last ? $tax_left : intdiv( (int) $refund->tax_minor * $weights[ $i ][1], $sum );
				$amount_left -= $amount;
				$tax_left -= $tax;
				$line = $lines[ $i ];
				if ( ! self::in_scope( $line->event_id, $scope ) ) { continue; }
				$refund_scope[ (int) $refund->order_id ] = ( $refund_scope[ (int) $refund->order_id ] ?? 0 ) + $amount;
				$add( $keys_for( $line, $refund->payment_provider, $day ), array( 'refunded_tickets' => $weights[ $i ][0], 'refunded' => $amount, 'refunded_tax' => $tax, 'revenue' => -$amount, 'revenue_tax' => -$tax ) );
			}
		}
		$campaigns = self::campaign_breakdown( $order_scope, $refund_scope );
		foreach ( $dims as $d => $buckets ) {
			if ( 'day' === $d ) { ksort( $buckets ); } else { uasort( $buckets, static function ( $a, $b ) { return strcmp( $a['label'], $b['label'] ); } ); }
			$dims[ $d ] = $buckets;
		}
		ksort( $rates );

		// Credit notes in the range (reference only).
		$credit_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.credit_note_number,c.total_minor,c.tax_minor,i.order_id FROM %i c INNER JOIN %i i ON i.invoice_id=c.invoice_id WHERE c.issued_at >= %s AND c.issued_at < %s', $p . 'wpcap_credit_notes', $p . 'wpcap_invoices', $start, $end ) );
		$credit = array( 'count' => 0, 'total' => 0, 'tax' => 0 );
		$credit_events = self::events_by_order( array_map( static function ( $c ) { return (int) $c->order_id; }, $credit_rows ) );
		foreach ( $credit_rows as $c ) {
			$events = $credit_events[ (int) $c->order_id ] ?? array();
			if ( null !== $scope && array_diff( $events, (array) $scope ) ) { continue; }
			$credit['count']++;
			$credit['total'] += (int) $c->total_minor;
			$credit['tax'] += (int) $c->tax_minor;
		}

		$occurrences = self::occurrence_stats( array_keys( $dims['occurrence'] ), $start, $end, $scope );
		$histogram_occ = absint( $filters['occurrence_id'] ?? 0 );
		if ( ! isset( $occurrences[ $histogram_occ ] ) ) { $histogram_occ = (int) array_key_first( $occurrences ); }

		$comparison = null;
		$compare = in_array( (string) ( $filters['compare'] ?? '' ), self::COMPARE, true ) ? (string) $filters['compare'] : '';
		$range = $compare ? self::comparison_range( $from, $to, $compare ) : null;
		if ( $range ) {
			$base = self::build( array( 'from' => $range[0], 'to' => $range[1], 'event_id' => $event_filter, 'compare' => '' ), $scope );
			$comparison = array(
				'mode' => $compare,
				'from' => $range[0],
				'to' => $range[1],
				'total' => $base['total'],
				'campaigns' => $base['campaigns'],
				'delta' => array(),
			);
			foreach ( array( 'tickets', 'gross', 'refunded', 'revenue', 'revenue_tax' ) as $field ) {
				$comparison['delta'][ $field ] = self::delta( $total[ $field ], $base['total'][ $field ] );
			}
		}

		$report = array(
			'from' => $from,
			'to' => $to,
			'range_clamped' => $clamped,
			'campaigns' => $campaigns,
			'comparison' => $comparison,
			'timezone' => $tz,
			'currency' => 1 === count( $currencies ) ? (string) array_key_first( $currencies ) : ( $currencies ? implode( '/', array_keys( $currencies ) ) : 'EUR' ),
			'total' => $total,
			'dimensions' => $dims,
			'rates' => $rates,
			'channels' => $channels,
			'credit_notes' => $credit,
			'occurrences' => $occurrences,
			'histogram_occurrence' => $histogram_occ,
			'histogram' => $histogram_occ ? self::checkin_histogram( $histogram_occ ) : array(),
			'extra' => array(),
		);
		/**
		 * Report data (filters: from, to, event_id, occurrence_id; $scope: event ids or null = all).
		 * Add-ons put their own figures under `extra` and show them with wp_pass_captain_report_metrics_v1.
		 */
		$filtered = apply_filters( 'wp_pass_captain_report_data_v1', $report, $filters, $scope );
		return is_array( $filtered ) ? $filtered + $report : $report;
	}

	/**
	 * Event ids per order (Scope::order_events() for many orders, one query per 500 orders).
	 *
	 * @param int[] $order_ids Order ids.
	 * @return int[][] order id => event ids
	 */
	private static function events_by_order( array $order_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) ), 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT oi.order_id,o.event_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences' ), $chunk ) ) ) as $row ) {
				$out[ (int) $row->order_id ][] = (int) $row->event_id;
			}
		}
		return $out;
	}

	/**
	 * Campaign rows from the in-scope order shares: key = campaign id (0 = without code).
	 *
	 * An order with several codes is split between them proportionally to each code's discount
	 * (equal parts when no code gave a discount), exact to the minor unit with cumulative rounding in
	 * the order the codes were redeemed – deterministic, and the rows add up to the report totals
	 * (nothing is counted twice). Completed refunds of the range are attributed to the campaigns of
	 * their order in the same proportions (orders created before the range included).
	 *
	 * @param array $order_scope  order id => [tickets, gross after discount, in-scope line total, order line total].
	 * @param array $refund_scope order id => in-scope refunded amount (completed refunds of the range).
	 */
	private static function campaign_breakdown( array $order_scope, array $refund_scope = array() ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$out = array();
		$row = static function ( $label ) { return array( 'label' => $label, 'orders' => 0, 'tickets' => 0, 'discount' => 0, 'revenue' => 0, 'refunded' => 0, 'net_revenue' => 0 ); };
		$uses = array();
		$ids = array_values( array_unique( array_merge( array_map( 'absint', array_keys( $order_scope ) ), array_map( 'absint', array_keys( $refund_scope ) ) ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT cu.campaign_use_id,cu.order_id,cu.campaign_id,cu.code,cu.discount_minor,c.name FROM %i cu LEFT JOIN %i c ON c.campaign_id=cu.campaign_id WHERE cu.status IN ('reserved','committed') AND cu.order_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') ORDER BY cu.campaign_use_id', array_merge( array( $p . 'wpcap_campaign_uses', $p . 'wpcap_campaigns' ), $chunk ) ) ) as $use ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- absint ids.
				$uses[ (int) $use->order_id ][] = $use;
			}
		}
		foreach ( $ids as $order_id ) {
			list( $tickets, $gross, $in_scope_total, $order_total ) = $order_scope[ $order_id ] ?? array( 0, 0, 0, 0 );
			$counted = isset( $order_scope[ $order_id ] ) ? 1 : 0;
			$refunded = (int) ( $refund_scope[ $order_id ] ?? 0 );
			$order_uses = $uses[ $order_id ] ?? array();
			if ( ! $order_uses ) {
				$out[0] = $out[0] ?? $row( __( 'Ohne Kampagnencode', 'pass-captain' ) );
				$out[0]['orders'] += $counted;
				$out[0]['tickets'] += $tickets;
				$out[0]['revenue'] += $gross;
				$out[0]['refunded'] += $refunded;
				continue;
			}
			$weights = array();
			foreach ( $order_uses as $n => $use ) { $weights[ $n ] = max( 0, (int) $use->discount_minor ); }
			$split = static function ( $total ) use ( $weights ) { return self::split_by_weight( (int) $total, $weights ); };
			$parts = array( 'orders' => $split( $counted ), 'tickets' => $split( $tickets ), 'revenue' => $split( $gross ), 'refunded' => $split( $refunded ) );
			foreach ( $order_uses as $n => $use ) {
				$key = (int) $use->campaign_id;
				$out[ $key ] = $out[ $key ] ?? $row( $use->code . ( '' !== (string) $use->name && $use->name !== $use->code ? ' – ' . $use->name : '' ) );
				foreach ( $parts as $field => $values ) { $out[ $key ][ $field ] += $values[ $n ]; }
				// Discount share of the in-scope lines (the code may have covered other events' lines too).
				$out[ $key ]['discount'] += $order_total > 0 ? intdiv( (int) $use->discount_minor * $in_scope_total, $order_total ) : 0;
			}
		}
		foreach ( $out as $key => $values ) { $out[ $key ]['net_revenue'] = $values['revenue'] - $values['refunded']; }
		uasort( $out, static function ( $a, $b ) { return $b['revenue'] <=> $a['revenue'] ?: strcmp( $a['label'], $b['label'] ); } );
		return $out;
	}

	/**
	 * Splits $total over weights proportionally, exact to the unit (largest remainder method: the
	 * parts add up to $total; leftover units go to the largest remainders, ties to the earlier key –
	 * deterministic). Equal weights when all are zero. Keys are kept.
	 *
	 * @param int[] $weights
	 * @return int[]
	 */
	public static function split_by_weight( $total, array $weights ) {
		if ( ! $weights ) { return array(); }
		$total = (int) $total;
		$sum = array_sum( $weights );
		if ( $sum <= 0 ) {
			$weights = array_fill_keys( array_keys( $weights ), 1 );
			$sum = count( $weights );
		}
		$out = array();
		$remainders = array();
		$position = 0;
		foreach ( $weights as $key => $weight ) {
			$out[ $key ] = intdiv( $total * (int) $weight, $sum );
			$remainders[] = array( ( $total * (int) $weight ) % $sum, $position++, $key );
		}
		$left = $total - array_sum( $out );
		usort( $remainders, static function ( $a, $b ) { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; } );
		for ( $i = 0; $i < $left; $i++ ) { ++$out[ $remainders[ $i % count( $remainders ) ][2] ]; }
		return $out;
	}

	/** Order lines grouped by order id (item order = Orders::create order). */
	private static function lines_by_order( array $order_ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_map( 'absint', $order_ids ), 500 ) as $chunk ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT oi.order_id,oi.item_id,oi.ticket_type_id,oi.quantity,oi.line_total_minor,oi.tax_rate_bp,oi.tax_minor,tt.name AS ticket_name,o.occurrence_id,o.starts_at,o.timezone,o.event_id,e.name AS event_name FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id INNER JOIN %i e ON e.event_id=o.event_id WHERE oi.order_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ') ORDER BY oi.order_id,oi.item_id', array_merge( array( $wpdb->prefix . 'wpcap_order_items', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $wpdb->prefix . 'wpcap_events' ), $chunk ) ) ) as $line ) {
				$out[ (int) $line->order_id ][] = $line;
			}
		}
		return $out;
	}

	/**
	 * Gross per line after the order discount: the split stored with the order (per ticket type, the
	 * one its VAT was computed with), else the same exact proportional split as Orders::allocate().
	 *
	 * @param int[]|null $split Per-ticket-type discounts from the price snapshot.
	 */
	public static function allocate_discount( array $lines, $discount, $split = null ) {
		$lines = array_values( $lines );
		$amounts = array();
		foreach ( $lines as $i => $line ) { $amounts[ $i ] = (int) $line->line_total_minor; }
		$parts = \PassCaptain\Orders::allocate( $amounts, (int) $discount );
		$out = array();
		foreach ( $lines as $i => $line ) {
			$share = is_array( $split ) && isset( $split[ (int) ( $line->ticket_type_id ?? 0 ) ] ) ? min( $amounts[ $i ], (int) $split[ (int) $line->ticket_type_id ] ) : $parts[ $i ];
			$out[ $i ] = $amounts[ $i ] - $share;
		}
		return $out;
	}

	/**
	 * Sold vs capacity and check-in rate for occurrences with sales in the range or starting in it.
	 */
	public static function occurrence_stats( array $occurrence_ids, $start, $end, $scope ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$starting = $wpdb->get_col( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE starts_at >= %s AND starts_at < %s', $p . 'wpcap_occurrences', $start, $end ) );
		$ids = array_values( array_unique( array_merge( array_map( 'absint', $occurrence_ids ), array_map( 'absint', $starting ) ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', $ids );
		$out = array();
		$occ_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.capacity,e.name FROM %i o INNER JOIN %i e ON e.event_id=o.event_id WHERE o.occurrence_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY o.starts_at', array_merge( array( $p . 'wpcap_occurrences', $p . 'wpcap_events' ), $ids ) ) );
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.occurrence_id,tt.name,tt.capacity,COALESCE((SELECT SUM(oi.quantity-oi.refunded_quantity) FROM %i oi INNER JOIN %i ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id=tt.ticket_type_id AND ord.payment_status='paid' AND ord.status <> 'refunded'),0) AS sold FROM %i tt WHERE tt.occurrence_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY tt.ticket_type_id', array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_orders', $p . 'wpcap_ticket_types' ), $ids ) ) );
		$issued = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT tt.occurrence_id,COUNT(*) AS n FROM %i t INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND t.status IN ('valid','checked_in') GROUP BY tt.occurrence_id", array_merge( array( $p . 'wpcap_tickets', $p . 'wpcap_ticket_types' ), $ids ) ) ) as $r ) { $issued[ (int) $r->occurrence_id ] = (int) $r->n; }
		$checked = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT tt.occurrence_id,COUNT(DISTINCT c.ticket_id) AS n FROM %i c INNER JOIN %i t ON t.ticket_id=c.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND c.result='checked_in' AND t.status IN ('valid','checked_in') GROUP BY tt.occurrence_id", array_merge( array( $p . 'wpcap_checkins', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types' ), $ids ) ) ) as $r ) { $checked[ (int) $r->occurrence_id ] = (int) $r->n; }
		foreach ( $occ_rows as $o ) {
			if ( ! self::in_scope( $o->event_id, $scope ) ) { continue; }
			$id = (int) $o->occurrence_id;
			$type_rows = array();
			$sold = 0;
			$type_capacity = 0;
			$unlimited_type = false;
			foreach ( $types as $t ) {
				if ( (int) $t->occurrence_id !== $id ) { continue; }
				$type_rows[] = array( 'ticket_type_id' => (int) $t->ticket_type_id, 'name' => $t->name, 'capacity' => (int) $t->capacity, 'sold' => (int) $t->sold );
				$sold += (int) $t->sold;
				$type_capacity += (int) $t->capacity;
				$unlimited_type = $unlimited_type || 0 === (int) $t->capacity;
			}
			$capacity = (int) $o->capacity > 0 ? (int) $o->capacity : ( $unlimited_type ? 0 : $type_capacity );
			$out[ $id ] = array(
				'occurrence_id' => $id,
				'label' => $o->name . ' – ' . Scope::local( $o->starts_at, $o->timezone ),
				'capacity' => $capacity,
				'sold' => $sold,
				'utilisation' => $capacity > 0 ? round( 100 * $sold / $capacity, 1 ) : null,
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
		$tz = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT timezone FROM %i WHERE occurrence_id=%d', $p . 'wpcap_occurrences', absint( $occurrence_id ) ) );
		$times = $wpdb->get_col( $wpdb->prepare( "SELECT COALESCE(c.scanned_at,c.created_at) FROM %i c INNER JOIN %i t ON t.ticket_id=c.ticket_id INNER JOIN %i tt ON tt.ticket_type_id=t.ticket_type_id WHERE tt.occurrence_id=%d AND c.result='checked_in'", $p . 'wpcap_checkins', $p . 'wpcap_tickets', $p . 'wpcap_ticket_types', absint( $occurrence_id ) ) );
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

	/** CSV rows of a report (amounts as decimals). */
	public static function csv_rows( array $report ) {
		if ( ! \PassCaptain\Security\DataPolicy::can_view_payment() ) { return self::csv_rows_without_payment( $report ); }
		// Decimal places of the report currency (JPY 0, KWD 3); a mixed-currency report ("EUR/USD") uses 2.
		$currency = (string) ( $report['currency'] ?? 'EUR' );
		$m = static function ( $minor ) use ( $currency ) { return \PassCaptain\Localization::money_decimal( (int) $minor, $currency ); };
		$head = array( __( 'Tickets', 'pass-captain' ), __( 'Brutto', 'pass-captain' ), __( 'Netto', 'pass-captain' ), __( 'USt', 'pass-captain' ), __( 'Erstattete Tickets', 'pass-captain' ), __( 'Erstattet brutto', 'pass-captain' ), __( 'Erstattet USt', 'pass-captain' ), __( 'Umsatz nach Erstattung', 'pass-captain' ), __( 'USt nach Erstattung', 'pass-captain' ) );
		$vals = static function ( $b ) use ( $m ) { return array( $b['tickets'], $m( $b['gross'] ), $m( $b['net'] ), $m( $b['tax'] ), $b['refunded_tickets'], $m( $b['refunded'] ), $m( $b['refunded_tax'] ), $m( $b['revenue'] ), $m( $b['revenue_tax'] ) ); };
		$rows = array( array( __( 'Bericht', 'pass-captain' ), $report['from'], $report['to'], $report['timezone'], $report['currency'] ) );
		$sections = array( 'event' => __( 'Event', 'pass-captain' ), 'occurrence' => __( 'Termin', 'pass-captain' ), 'ticket_type' => __( 'Ticketart', 'pass-captain' ), 'provider' => __( 'Zahlungsanbieter', 'pass-captain' ), 'day' => __( 'Tag', 'pass-captain' ) );
		foreach ( $sections as $dim => $label ) {
			$rows[] = array();
			$rows[] = array_merge( array( $label ), $head );
			foreach ( $report['dimensions'][ $dim ] as $b ) { $rows[] = array_merge( array( $b['label'] ), $vals( $b ) ); }
		}
		$rows[] = array();
		$rows[] = array_merge( array( __( 'USt-Satz', 'pass-captain' ) ), $head );
		foreach ( $report['rates'] as $b ) { $rows[] = array_merge( array( $b['label'] ), $vals( $b ) ); }
		$rows[] = array();
		$rows[] = array_merge( array( __( 'Kanal', 'pass-captain' ) ), $head );
		foreach ( $report['channels'] as $b ) { $rows[] = array_merge( array( $b['label'] ), $vals( $b ) ); }
		$rows[] = array_merge( array( $report['total']['label'] ), $vals( $report['total'] ) );
		$rows[] = array();
		$rows[] = array( __( 'Termin', 'pass-captain' ), __( 'Kapazität', 'pass-captain' ), __( 'Verkauft', 'pass-captain' ), __( 'Auslastung %', 'pass-captain' ), __( 'Gültige Tickets', 'pass-captain' ), __( 'Eingecheckt', 'pass-captain' ), __( 'Check-in-Quote %', 'pass-captain' ) );
		foreach ( $report['occurrences'] as $o ) {
			$rows[] = array( $o['label'], $o['capacity'] ?: '∞', $o['sold'], null === $o['utilisation'] ? '' : number_format( $o['utilisation'], 1, '.', '' ), $o['issued'], $o['checked_in'], null === $o['checkin_rate'] ? '' : number_format( $o['checkin_rate'], 1, '.', '' ) );
		}
		$rows[] = array();
		$rows[] = array( __( 'Kampagne', 'pass-captain' ), __( 'Bestellungen', 'pass-captain' ), __( 'Tickets', 'pass-captain' ), __( 'Rabatt', 'pass-captain' ), __( 'Umsatz nach Rabatt', 'pass-captain' ), __( 'Erstattet', 'pass-captain' ), __( 'Umsatz nach Erstattung', 'pass-captain' ) );
		foreach ( (array) ( $report['campaigns'] ?? array() ) as $c ) {
			$rows[] = array( $c['label'], $c['orders'], $c['tickets'], $m( $c['discount'] ), $m( $c['revenue'] ), $m( (int) ( $c['refunded'] ?? 0 ) ), $m( (int) ( $c['net_revenue'] ?? $c['revenue'] ) ) );
		}
		if ( ! empty( $report['comparison'] ) ) {
			$cmp = $report['comparison'];
			$rows[] = array();
			$rows[] = array( __( 'Vergleichszeitraum', 'pass-captain' ), $cmp['from'], $cmp['to'], 'previous' === $cmp['mode'] ? __( 'Vorperiode', 'pass-captain' ) : __( 'Vorjahreszeitraum', 'pass-captain' ) );
			$rows[] = array( __( 'Kennzahl', 'pass-captain' ), __( 'Zeitraum', 'pass-captain' ), __( 'Vergleich', 'pass-captain' ), __( 'Differenz', 'pass-captain' ), __( 'Änderung %', 'pass-captain' ) );
			$names = array( 'tickets' => __( 'Tickets', 'pass-captain' ), 'gross' => __( 'Brutto', 'pass-captain' ), 'refunded' => __( 'Erstattet brutto', 'pass-captain' ), 'revenue' => __( 'Umsatz nach Erstattung', 'pass-captain' ), 'revenue_tax' => __( 'USt nach Erstattung', 'pass-captain' ) );
			foreach ( $names as $field => $label ) {
				$money = 'tickets' !== $field;
				$d = $cmp['delta'][ $field ];
				$rows[] = array( $label, $money ? $m( $report['total'][ $field ] ) : $report['total'][ $field ], $money ? $m( $cmp['total'][ $field ] ) : $cmp['total'][ $field ], $money ? $m( $d['diff'] ) : $d['diff'], null === $d['pct'] ? '' : number_format( $d['pct'], 1, '.', '' ) );
			}
		}
		$rows[] = array();
		$rows[] = array( __( 'Gutschriften', 'pass-captain' ), $report['credit_notes']['count'], $m( $report['credit_notes']['total'] ), $m( $report['credit_notes']['tax'] ) );
		if ( $report['histogram'] ) {
			$rows[] = array();
			$rows[] = array( __( 'Check-ins pro Stunde', 'pass-captain' ), $report['occurrences'][ $report['histogram_occurrence'] ]['label'] ?? '' );
			foreach ( $report['histogram'] as $hour => $n ) { $rows[] = array( $hour, $n ); }
		}
		return Exports::filter_rows( $rows, 'report', array( 'report' => $report ) );
	}

	/**
	 * Report CSV for roles without access to payment data (check-in, partner/organiser view):
	 * ticket counts and admission figures only, no amounts (Security\DataPolicy).
	 */
	public static function csv_rows_without_payment( array $report ) {
		$rows = array( array( __( 'Bericht', 'pass-captain' ), $report['from'], $report['to'], $report['timezone'] ) );
		$rows[] = array();
		$rows[] = array( __( 'Termin', 'pass-captain' ), __( 'Kapazität', 'pass-captain' ), __( 'Verkauft', 'pass-captain' ), __( 'Auslastung %', 'pass-captain' ), __( 'Gültige Tickets', 'pass-captain' ), __( 'Eingecheckt', 'pass-captain' ), __( 'Check-in-Quote %', 'pass-captain' ) );
		foreach ( $report['occurrences'] as $o ) {
			$rows[] = array( $o['label'], $o['capacity'] ?: '∞', $o['sold'], null === $o['utilisation'] ? '' : number_format( $o['utilisation'], 1, '.', '' ), $o['issued'], $o['checked_in'], null === $o['checkin_rate'] ? '' : number_format( $o['checkin_rate'], 1, '.', '' ) );
		}
		$rows[] = array();
		$rows[] = array( __( 'Verkaufte Tickets', 'pass-captain' ), (int) $report['total']['tickets'], __( 'Erstattete Tickets', 'pass-captain' ), (int) $report['total']['refunded_tickets'] );
		return Exports::filter_rows( $rows, 'report', array( 'report' => $report, 'without_payment' => true ) );
	}
}
