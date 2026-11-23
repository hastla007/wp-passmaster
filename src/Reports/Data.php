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
 *
 * Campaign breakdown: orders, tickets, allocated discount, revenue (gross after discounts) of the
 * orders in the range and completed refunds of the range per redeemed campaign code (net revenue =
 * revenue − refunds); orders without a code form their own row. An order with two codes is split
 * between them proportionally to their discounts (deterministic, never counted twice), refunds
 * follow their order's split. Only lines of events in the user's scope count.
 * Comparison period (filter compare): "previous" = the same number of days directly before,
 * "last_year" = the same dates one year earlier; same scope and event filter.
 * Complimentary orders (guest lists, comp_providers()) are not sales: reported as `comps` only.
 */
namespace PassCaptain\Reports;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Data {
	const DIMENSIONS = array( 'event', 'occurrence', 'ticket_type', 'provider', 'day' );
	const COMPARE = array( 'previous', 'last_year' );
	/** Channel filter: '' = all orders, 'sales' = without complimentary tickets, 'comp' = only those (Sales\Comps). */
	const CHANNELS = array( 'sales', 'comp' );

	/** A613: date the revenue of an order belongs to: order date (default) or payment date. */
	const BASES = array( 'order', 'payment' );

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
	 * @param array $filters from, to (Y-m-d, local), event_id (0 = all), occurrence_id (histogram),
	 *                       channel ('' | 'sales' | 'comp', see CHANNELS; sales and refunds only).
	 * @param int[]|null $scope Event scope (see scope()).
	 */
	/** Report channels: key => label. */
	public static function channel_labels() {
		return array(
			'pos' => __( 'Kasse', 'pass-captain' ),
			'partner' => __( 'Veranstalter/Wiederverkäufer (API)', 'pass-captain' ),
			'woocommerce' => __( 'WooCommerce-Shop', 'pass-captain' ),
			'online' => __( 'Online/sonstige', 'pass-captain' ),
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
		// Defaults use the local date of the site timezone (00:30 local is already "today").
		$today = \PassCaptain\Clock::today( $tz );
		$from = self::valid_date( $filters['from'] ?? '' ) ? $filters['from'] : substr( $today, 0, 8 ) . '01';
		$to = self::valid_date( $filters['to'] ?? '' ) ? $filters['to'] : $today;
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
		$channel = in_array( (string) ( $filters['channel'] ?? '' ), self::CHANNELS, true ) ? (string) $filters['channel'] : '';
		// Complimentary orders (comp_providers(): guest list, box office free tickets) are never sales; 'comp'
		// reads only them (counted under comps), 'sales' leaves them out.
		$provider_sql = 'comp' === $channel ? ' AND payment_provider IN (' . self::comp_sql() . ')' : ( 'sales' === $channel ? ' AND payment_provider NOT IN (' . self::comp_sql() . ')' : '' );

		$dims = array();
		foreach ( self::DIMENSIONS as $d ) { $dims[ $d ] = array(); }
		$rates = array();
		// Sales channels (fixed order; empty channels stay for a stable table): box office, partner API
		// (organizers/resellers), WooCommerce shop, own online checkout and other providers.
		$channels = array();
		foreach ( self::channel_labels() as $channel_key => $channel_label ) { $channels[ $channel_key ] = self::empty_bucket( $channel_label ); }
		$total = self::empty_bucket( __( 'Gesamt', 'pass-captain' ) );
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
		// Scope per event/occurrence, decided once per report (not once per order line).
		$scope_memo = array();
		$line_in_scope = static function ( $line ) use ( &$scope_memo, $scope ) {
			$key = (int) $line->event_id . '|' . (int) $line->occurrence_id;
			if ( ! isset( $scope_memo[ $key ] ) ) { $scope_memo[ $key ] = self::in_scope( $line->event_id, $scope, $line->occurrence_id ); }
			return $scope_memo[ $key ];
		};

		// Sales: ids by index (paid_created), then blocks of BLOCK orders with their lines and exchange
		// flags (A434: no query per order, never the whole period in memory).
		// A613: by payment date (paid_at, schema 3.18.1; orders paid before it have no paid_at and count by order date).
		$basis = 'payment' === (string) ( $filters['basis'] ?? '' ) && \PassCaptain\Orders::schema_at_least( '3.18.1' ) ? 'payment' : 'order';
		if ( 'payment' === $basis ) {
			$order_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM %i WHERE payment_status IN ('paid','refunded') AND ( ( paid_at >= %s AND paid_at < %s ) OR ( paid_at IS NULL AND created_at >= %s AND created_at < %s ) )" . $provider_sql . ' ORDER BY order_id', $p . 'wpcap_orders', $start, $end, $start, $end ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $provider_sql is built from sanitize_key() values.
		} else {
			$order_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM %i WHERE payment_status IN ('paid','refunded') AND created_at >= %s AND created_at < %s" . $provider_sql . ' ORDER BY order_id', $p . 'wpcap_orders', $start, $end ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $provider_sql is built from sanitize_key() values.
		}
		$order_scope = array(); // order id => in-scope [tickets, gross after discount, line total before discount, all lines before discount].
		foreach ( self::order_blocks( $order_ids, 'order_id,created_at,payment_provider,currency,subtotal_minor,discount_minor,price_snapshot' . ( 'payment' === $basis ? ',paid_at' : '' ), $line_sql ) as list( $order_id, $order, $lines ) ) {
			if ( in_array( (string) $order->payment_provider, $comp_providers, true ) ) {
				$in_scope = false;
				foreach ( $lines as $line ) {
					if ( ! $line_in_scope( $line ) ) { continue; }
					$comps['tickets'] += (int) $line->quantity;
					$in_scope = true;
				}
				if ( $in_scope ) { ++$comps['orders']; }
				continue;
			}
			$gross_lines = self::allocate_discount( $lines, (int) $order->discount_minor, \PassCaptain\Orders::snapshot_line_discounts( $order ) );
			$day = Scope::local( 'payment' === $basis && ! empty( $order->paid_at ) ? $order->paid_at : $order->created_at, $tz, 'Y-m-d' );
			$supplement = \PassCaptain\Exchange\Service::is_supplement( (int) $order_id );
			$share = array( 0, 0, 0, 0 );
			foreach ( $lines as $i => $line ) {
				$share[3] += (int) $line->line_total_minor;
				if ( ! $line_in_scope( $line ) ) { continue; }
				if ( \PassCaptain\OrderLines::is_moved_in( $line ) ) { continue; } // Moved in by an exchange.
				$currencies[ $order->currency ] = true;
				$gross = $gross_lines[ $i ];
				$tax = (int) $line->tax_minor;
				$share[0] += $supplement ? 0 : (int) $line->quantity;
				$share[1] += $gross;
				$share[2] += (int) $line->line_total_minor;
				$add( $keys_for( $line, $order->payment_provider, $day, $order->currency ), array( 'tickets' => $supplement ? 0 : (int) $line->quantity, 'gross' => $gross, 'tax' => $tax, 'net' => $gross - $tax, 'revenue' => $gross, 'revenue_tax' => $tax, 'revenue_net' => $gross - $tax ) );
			}
			if ( $share[0] ) { $order_scope[ $order_id ] = $share; }
		}

		// Completed refunds (netted on their completion day).
		$refunds = $wpdb->get_results( $wpdb->prepare( "SELECT r.refund_id,r.order_id,r.amount_minor,r.tax_minor,r.ticket_ids,r.updated_at,ord.payment_provider,ord.currency,ord.discount_minor,(ord.voucher_snapshot IS NOT NULL AND ord.voucher_snapshot<>'') AS has_voucher FROM %i r INNER JOIN %i ord ON ord.order_id=r.order_id WHERE r.status='completed' AND r.updated_at >= %s AND r.updated_at < %s" . str_replace( 'payment_provider', 'ord.payment_provider', $provider_sql ) . ' ORDER BY r.refund_id', $p . 'wpcap_refunds', $p . 'wpcap_orders', $start, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $provider_sql is built from sanitize_key() values.
		$refund_scope = array(); // order id => in-scope refunded amount (campaign attribution).
		foreach ( array_chunk( $refunds, self::BLOCK ) as $refund_block ) {
			$block_orders = array_values( array_unique( array_map( static function ( $r ) { return (int) $r->order_id; }, $refund_block ) ) );
			$refund_lines = self::lines_by_order( $block_orders, $line_sql );
			$history = \PassCaptain\Invoices::refund_history( $block_orders );
			foreach ( $refund_block as $refund ) {
				$lines = $refund_lines[ (int) $refund->order_id ] ?? array();
				if ( ! $lines ) { continue; }
				$day = Scope::local( $refund->updated_at, $tz, 'Y-m-d' );
				// Same split as the correction document; multi-purpose voucher refunds at their credit note amounts.
				foreach ( self::refund_split( $refund, $lines, $history[ (int) $refund->order_id ] ?? array() ) as $part ) {
					$line = $lines[ $part['index'] ];
					if ( ! $line_in_scope( $line ) ) { continue; }
					$refund_scope[ (int) $refund->order_id ] = ( $refund_scope[ (int) $refund->order_id ] ?? 0 ) + $part['amount'];
					$add( $keys_for( $line, $refund->payment_provider, $day, $refund->currency ), array( 'refunded_tickets' => $part['tickets'], 'refunded' => $part['amount'], 'refunded_tax' => $part['tax'], 'revenue' => -$part['amount'], 'revenue_tax' => -$part['tax'], 'revenue_net' => $part['tax'] - $part['amount'] ) );
				}
			}
		}
		$campaigns = self::campaign_breakdown( $order_scope, $refund_scope );
		foreach ( $dims as $d => $buckets ) {
			if ( 'day' === $d ) { ksort( $buckets ); } else { uasort( $buckets, static function ( $a, $b ) { return \PassCaptain\Localization::compare( $a['label'], $b['label'] ); } ); } // A520: site-language order (Localization::compare).
			$dims[ $d ] = $buckets;
		}
		ksort( $rates );
		ksort( $by_currency );
		foreach ( $by_currency as $cur => $c ) { ksort( $by_currency[ $cur ]['rates'] ); }

		// Correction documents (Rechnungskorrekturen) in the range (reference only).
		$credit_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.credit_note_number,c.total_minor,c.tax_minor,i.order_id FROM %i c INNER JOIN %i i ON i.invoice_id=c.invoice_id WHERE c.issued_at >= %s AND c.issued_at < %s', $p . 'wpcap_credit_notes', $p . 'wpcap_invoices', $start, $end ) );
		$credit = array( 'count' => 0, 'total' => 0, 'tax' => 0 );
		$credit_events = self::events_by_order( array_map( static function ( $c ) { return (int) $c->order_id; }, $credit_rows ) );
		foreach ( $credit_rows as $c ) {
			$events = $credit_events[ (int) $c->order_id ] ?? array();
			if ( null !== $scope && array_diff( $events, (array) $scope ) ) { continue; }
			if ( ! \PassCaptain\Access::order_in_scope( $c->order_id ) ) { continue; }
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
			$base = self::build( array( 'from' => $range[0], 'to' => $range[1], 'event_id' => $event_filter, 'compare' => '', 'channel' => $channel, 'basis' => $basis ), $scope );
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
			'channel' => $channel,
			'basis' => $basis,
			'campaigns' => $campaigns,
			'comparison' => $comparison,
			'timezone' => $tz,
			'currency' => 1 === count( $currencies ) ? (string) array_key_first( $currencies ) : ( $currencies ? implode( '/', array_keys( $currencies ) ) : 'EUR' ),
			'total' => $total,
			'dimensions' => $dims,
			'rates' => $rates,
			'currencies' => $by_currency,
			'channels' => $channels,
			'credit_notes' => $credit,
			'comps' => $comps,
			// A425: service fees of the sales in the range (gross and VAT per currency; part of gross/revenue above).
			'service_fees' => \PassCaptain\ServiceFee::totals( array_keys( $order_scope ) ),
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
		uasort( $out, static function ( $a, $b ) { return $b['revenue'] <=> $a['revenue'] ?: \PassCaptain\Localization::compare( $a['label'], $b['label'] ); } );
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

	/**
	 * SELECT for paid order lines with ticket type, occurrence and event (append a WHERE clause on
	 * oi.order_id). STRAIGHT_JOIN: always driven by the order ids (A434; with few events the optimizer
	 * otherwise starts at the event and reads every line of it for each block).
	 */
	public static function line_sql() {
		global $wpdb;
		$p = $wpdb->prefix;
		return "SELECT STRAIGHT_JOIN oi.order_id,oi.item_id,oi.ticket_type_id," . \PassCaptain\OrderLines::paid_columns() . ",oi.tax_rate_bp,tt.name AS ticket_name,o.occurrence_id,o.starts_at,o.timezone,o.event_id,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id";
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
	public static function refund_split( $refund, array $lines, $history = null ) {
		global $wpdb;
		if ( ! $lines ) { return array(); }
		$lines = array_values( $lines );
		$amounts = \PassCaptain\Integrations\Vouchers\Checkout::refund_amounts( $refund );
		$total_amount = (int) $amounts['amount_minor'];
		$total_tax = (int) $amounts['tax_minor'];
		$discount = isset( $refund->discount_minor ) ? (int) $refund->discount_minor : (int) $wpdb->get_var( $wpdb->prepare( "SELECT discount_minor FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", (int) ( $refund->order_id ?? 0 ) ) );
		$out = array();
		foreach ( \PassCaptain\Invoices::refund_line_parts( $refund, $lines, $discount, $total_amount, $total_tax, $history ) as $part ) {
			if ( 0 === $part['gross'] && 0 === $part['tax'] && 0 === $part['tickets'] ) { continue; }
			$out[] = array( 'index' => $part['index'], 'tickets' => $part['tickets'], 'amount' => $part['gross'], 'tax' => $part['tax'] );
		}
		if ( ! $out && ( $total_amount || $total_tax ) ) {
			// No line carries a paid value: book the refund on the last line (totals stay complete).
			$out[] = array( 'index' => count( $lines ) - 1, 'tickets' => 0, 'amount' => $total_amount, 'tax' => $total_tax );
		}
		return $out;
	}

	/** Order lines grouped by order id (item order = Orders::create order); $line_sql: line_sql() by default. */
	public static function lines_by_order( array $order_ids, $line_sql = null ) {
		global $wpdb;
		$line_sql = $line_sql ?? self::line_sql();
		$out = array();
		foreach ( array_chunk( array_values( array_filter( array_map( 'absint', $order_ids ) ) ), 500 ) as $chunk ) {
			foreach ( (array) $wpdb->get_results( $line_sql . ' WHERE oi.order_id IN (' . implode( ',', $chunk ) . ') ORDER BY oi.order_id,oi.item_id' ) as $line ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL, integer list.
				$out[ (int) $line->order_id ][] = $line;
			}
		}
		return $out;
	}

	/** Orders per block when a report walks many orders (A434). */
	const BLOCK = 1000;

	/**
	 * Walks orders in blocks of BLOCK: reads the order rows ($columns, order_id first) and their lines
	 * per block and preloads the exchange flags (Exchange\Service::is_supplement()), so a report over
	 * tens of thousands of orders runs a few queries per block and keeps one block in memory.
	 *
	 * @param int[]       $order_ids Order ids in processing order.
	 * @param string      $columns   Columns of wpcap_orders to read.
	 * @param string|null $line_sql  Line query (line_sql() by default).
	 * @return \Generator<int, array{0:int,1:object,2:object[]}> [order id, order row, lines].
	 */
	public static function order_blocks( array $order_ids, $columns, $line_sql = null ) {
		global $wpdb;
		foreach ( array_chunk( $order_ids, self::BLOCK ) as $block ) {
			$orders = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT $columns FROM {$wpdb->prefix}wpcap_orders WHERE order_id IN (" . implode( ',', array_fill( 0, count( $block ), '%d' ) ) . ')', array_map( 'absint', $block ) ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns is a fixed column list from the callers.
				$orders[ (int) $row->order_id ] = $row;
			}
			$lines = self::lines_by_order( $block, $line_sql );
			\PassCaptain\Exchange\Service::preload_supplements( $block );
			foreach ( $block as $order_id ) {
				if ( isset( $orders[ (int) $order_id ] ) ) { yield array( (int) $order_id, $orders[ (int) $order_id ], $lines[ (int) $order_id ] ?? array() ); }
			}
		}
	}

	/**
	 * Gross per line after the order discount: the discounts stored on the lines (A050,
	 * OrderLines::after_discount()) when complete; else the split stored with the order's price snapshot
	 * (per ticket type, the one its VAT was computed with); else the exact proportional split.
	 *
	 * @param int[]|null $split Per-ticket-type discounts from the price snapshot.
	 */
	public static function allocate_discount( array $lines, $discount, $split = null ) {
		$lines = array_values( $lines );
		$stored = (bool) $lines;
		foreach ( $lines as $line ) {
			if ( ! isset( $line->discount_minor ) || null === $line->discount_minor ) { $stored = false; break; }
		}
		if ( $stored || ! is_array( $split ) || ! $split ) {
			return \PassCaptain\OrderLines::after_discount( $lines, $discount );
		}
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
		$occ_rows = $wpdb->get_results( "SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,o.capacity,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id IN ($in) ORDER BY o.starts_at" );
		$comp_in = self::comp_sql();
		$types = $wpdb->get_results( "SELECT tt.ticket_type_id,tt.occurrence_id,tt.name,tt.capacity,0 AS sold,0 AS comps FROM {$p}wpcap_ticket_types tt WHERE tt.occurrence_id IN ($in) ORDER BY tt.ticket_type_id" );
		if ( $types ) {
			// Sold and comps per ticket type in one pass over the lines (A434: was two correlated SUMs per type).
			$by_type = array();
			foreach ( $types as $t ) { $by_type[ (int) $t->ticket_type_id ] = $t; }
			foreach ( (array) $wpdb->get_results( "SELECT oi.ticket_type_id,SUM(IF(ord.payment_provider IN ($comp_in),0,oi.quantity-oi.refunded_quantity)) AS sold,SUM(IF(ord.payment_provider IN ($comp_in),oi.quantity-oi.refunded_quantity,0)) AS comps FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_orders ord ON ord.order_id=oi.order_id WHERE oi.ticket_type_id IN (" . implode( ',', array_keys( $by_type ) ) . ") AND ord.payment_status='paid' AND ord.status <> 'refunded' GROUP BY oi.ticket_type_id" ) as $sum ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list (int keys), fixed provider list from comp_sql().
				$by_type[ (int) $sum->ticket_type_id ]->sold = (int) $sum->sold;
				$by_type[ (int) $sum->ticket_type_id ]->comps = (int) $sum->comps;
			}
		}
		// A572/A573: the same "admitted" rule as the no-show list, passes counted on their linked occurrences.
		$attendance = Attendance::counts( $ids );
		// A482: entries with a membership pass (no ticket) are shown next to the admissions.
		$members = \PassCaptain\Memberships::entries_by_occurrence( $ids );
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
				'issued' => $attendance[ $id ]['issued'] ?? 0,
				'checked_in' => $attendance[ $id ]['admitted'] ?? 0,
				'checkin_rate' => ! empty( $attendance[ $id ]['issued'] ) ? round( 100 * ( $attendance[ $id ]['admitted'] ?? 0 ) / $attendance[ $id ]['issued'], 1 ) : null,
				'member_entries' => $members[ $id ] ?? 0,
				'types' => $type_rows,
			);
		}
		return $out;
	}

	/**
	 * Effective check-ins per local hour of the occurrence ("Y-m-d H:00" => count), A571: taken-back
	 * scans are not counted (Attendance::entry_times()), the axis starts at the admission start
	 * (doors open / entry window / start) once there are entries from then on, and no entry is cut off.
	 * Empty hours between two bars are filled up to HISTOGRAM_GAP_HOURS; longer gaps (e.g. a test scan
	 * days before the event that was not taken back) leave the axis discontinuous instead.
	 */
	public static function checkin_histogram( $occurrence_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$occ = $wpdb->get_row( $wpdb->prepare( "SELECT occurrence_id,starts_at,timezone FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", absint( $occurrence_id ) ) );
		if ( ! $occ ) { return array(); }
		$tz = (string) $occ->timezone ?: 'UTC';
		$out = array();
		foreach ( Attendance::entry_times( $occurrence_id ) as $time ) {
			$hour = Scope::local( $time, $tz, 'Y-m-d H:00' );
			if ( '' === $hour ) { continue; }
			$out[ $hour ] = ( $out[ $hour ] ?? 0 ) + 1;
		}
		if ( ! $out ) { return array(); }
		ksort( $out );
		$opening = Scope::local( gmdate( 'Y-m-d H:i:s', Attendance::admission_start( $occ ) ), $tz, 'Y-m-d H:00' );
		if ( '' !== $opening && $opening <= array_key_last( $out ) && ! isset( $out[ $opening ] ) ) {
			$out[ $opening ] = 0;
			ksort( $out );
		}
		$keys = array_keys( $out );
		$filled = array();
		foreach ( $keys as $i => $key ) {
			$filled[ $key ] = $out[ $key ];
			$next = $keys[ $i + 1 ] ?? null;
			if ( null === $next ) { break; }
			$h = new \DateTimeImmutable( $key . ':00' );
			$gap = array();
			for ( $n = 0; $n <= self::HISTOGRAM_GAP_HOURS; $n++ ) {
				$h = $h->modify( '+1 hour' );
				$k = $h->format( 'Y-m-d H:00' );
				if ( $k >= $next ) { break; }
				$gap[ $k ] = 0;
			}
			if ( count( $gap ) <= self::HISTOGRAM_GAP_HOURS && ( ! $gap || $h->format( 'Y-m-d H:00' ) >= $next ) ) { $filled += $gap; }
		}
		ksort( $filled );
		return $filled;
	}

	/** Longest run of empty hours the histogram fills between two bars. */
	const HISTOGRAM_GAP_HOURS = 24;

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
		if ( ! \PassCaptain\Security\DataPolicy::can_view_payment() ) { return self::csv_rows_without_payment( $report ); }
		// Mixed currencies are listed as "EUR/USD": the combined sections use the first currency's minor
		// unit; the per-currency section below uses each currency's own.
		$cur = strtok( (string) $report['currency'], '/' );
		$m = static function ( $minor, $currency = null ) use ( $cur ) { return \PassCaptain\Money::to_decimal( $minor, $currency ?? $cur ); };
		$head = array( __( 'Tickets', 'pass-captain' ), __( 'Brutto', 'pass-captain' ), __( 'Netto', 'pass-captain' ), __( 'USt', 'pass-captain' ), __( 'Erstattete Tickets', 'pass-captain' ), __( 'Erstattet brutto', 'pass-captain' ), __( 'Erstattet USt', 'pass-captain' ), __( 'Umsatz nach Erstattung', 'pass-captain' ), __( 'USt nach Erstattung', 'pass-captain' ), __( 'Netto nach Erstattung', 'pass-captain' ) );
		$vals = static function ( $b, $currency = null ) use ( $m ) { return array( $b['tickets'], $m( $b['gross'], $currency ), $m( $b['net'], $currency ), $m( $b['tax'], $currency ), $b['refunded_tickets'], $m( $b['refunded'], $currency ), $m( $b['refunded_tax'], $currency ), $m( $b['revenue'], $currency ), $m( $b['revenue_tax'], $currency ), $m( $b['revenue_net'] ?? ( $b['revenue'] - $b['revenue_tax'] ), $currency ) ); };
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
		// Per currency and tax rate (the totals above only add up within one currency).
		$rows[] = array();
		$rows[] = array_merge( array( __( 'Währung', 'pass-captain' ), __( 'USt-Satz', 'pass-captain' ) ), $head );
		foreach ( (array) ( $report['currencies'] ?? array() ) as $cur => $c ) {
			foreach ( $c['rates'] as $b ) { $rows[] = array_merge( array( $cur, $b['label'] ), $vals( $b, $cur ) ); }
			$rows[] = array_merge( array( $cur, $report['total']['label'] ), $vals( $c['total'], $cur ) );
		}
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
		$rows[] = array( __( 'Rechnungskorrekturen', 'pass-captain' ), $report['credit_notes']['count'], $m( $report['credit_notes']['total'] ), $m( $report['credit_notes']['tax'] ) );
		if ( ! empty( $report['comps']['tickets'] ) ) {
			$rows[] = array( __( 'Freikarten (nicht im Umsatz)', 'pass-captain' ), (int) $report['comps']['tickets'], (int) $report['comps']['orders'] );
		}
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
