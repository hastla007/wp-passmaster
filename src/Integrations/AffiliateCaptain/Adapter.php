<?php
/**
 * Optional adapter for WP Affiliate Captain (integration contract v1).
 *
 * Reports paid native Pass Captain orders (web checkout and POS) as sales and their refunds,
 * cancellations and event cancellations as reversals. Orders that came in through WooCommerce
 * are skipped: WP Affiliate Captain already records the WooCommerce order itself, so reporting
 * them here would pay commission twice.
 *
 * Never touches WP Affiliate Captain tables and never sends payment data. The customer e-mail is
 * only passed for the self-referral check and is not stored by Affiliate Captain.
 *
 * @package PassCaptain
 */
namespace PassCaptain\Integrations\AffiliateCaptain;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Adapter {
	const SOURCE   = 'pass-captain';
	const CONTRACT = '1.0.0';

	public static function register() {
		add_filter( 'wpcap_order_snapshot', array( __CLASS__, 'snapshot' ), 10, 2 );
		add_filter( 'wpcap_validate_partner_code', array( __CLASS__, 'validate_code' ), 10, 2 );
		add_filter( 'wpcap_checkout_partner_field', array( __CLASS__, 'show_partner_field' ) );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_paid' ) );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'on_refunded' ), 10, 3 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'on_cancelled' ), 10, 3 );
		add_action( 'wpcap_event_cancelled', array( __CLASS__, 'on_event_cancelled' ), 10, 2 );
		add_filter( 'wpac_registered_integrations', array( __CLASS__, 'register_integration' ) );
	}

	/** True if a WP Affiliate Captain with a compatible contract (same major version) is active. */
	public static function available() {
		if ( ! function_exists( 'wpac_record_sale' ) || ! function_exists( 'wpac_contract_version' ) ) { return false; }
		$theirs = explode( '.', (string) wpac_contract_version() );
		$ours   = explode( '.', self::CONTRACT );
		return $theirs[0] === $ours[0];
	}

	public static function register_integration( $list ) {
		$list = is_array( $list ) ? $list : array();
		$list[ self::SOURCE ] = array(
			'name'     => 'WP Pass Captain',
			'version'  => defined( 'WPCAP_VERSION' ) ? WPCAP_VERSION : '',
			'contract' => self::CONTRACT,
			'active'   => true,
		);
		return $list;
	}

	public static function show_partner_field( $show ) {
		return self::available() ? (bool) apply_filters( 'wpcap_affiliate_partner_field', true ) : (bool) $show;
	}

	/** Checkout: reject unknown partner codes so the buyer can correct a typo. */
	public static function validate_code( $valid, $code ) {
		if ( is_wp_error( $valid ) || ! self::available() || ! function_exists( 'wpac_resolve_partner_code' ) ) { return $valid; }
		// Bound code enumeration: at most 10 wrong codes per hour and (hashed) IP address.
		$ip       = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$rate_key = 'wpcap_partner_fail_' . hash( 'sha256', $ip . '|' . wp_salt( 'nonce' ) );
		$failures = (int) get_transient( $rate_key );
		if ( $failures >= 10 ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'wp-pass-captain' ), array( 'status' => 429 ) );
		}
		$resolved = wpac_resolve_partner_code( (string) $code, array( 'context' => 'code', 'source' => self::SOURCE ) );
		if ( empty( $resolved ) ) {
			set_transient( $rate_key, $failures + 1, HOUR_IN_SECONDS );
			return new \WP_Error( 'wpcap_partner_code_invalid', __( 'Dieser Partnercode ist ungültig oder nicht mehr aktiv.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		return $valid;
	}

	/**
	 * Stores the referral (entered code and/or the visitor's tracked touch) with the order, because
	 * payment is often confirmed later by staff, when the buyer's request is no longer available.
	 * POS and WooCommerce orders never take over the touch of the current (staff) request.
	 */
	public static function snapshot( $snapshot, $payload ) {
		if ( ! is_array( $snapshot ) || ! self::available() || 'native' !== ( $snapshot['source'] ?? '' ) ) { return $snapshot; }
		$referral = array();
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $payload['partner_code'] ?? '' ) ) );
		if ( '' !== $code ) {
			$referral['code'] = substr( $code, 0, 64 );
		}
		$touch = function_exists( 'wpac_current_touch' ) ? wpac_current_touch() : null;
		if ( is_array( $touch ) && ! empty( $touch['code'] ) ) {
			$referral['touch'] = array(
				'code'       => substr( sanitize_text_field( (string) $touch['code'] ), 0, 64 ),
				'method'     => in_array( $touch['method'] ?? '', array( 'link', 'cookie', 'app' ), true ) ? $touch['method'] : 'link',
				'campaign'   => substr( sanitize_text_field( (string) ( $touch['campaign'] ?? '' ) ), 0, 100 ),
				'content_id' => absint( $touch['content_id'] ?? 0 ),
			);
		}
		if ( $referral ) {
			$snapshot['affiliate_referral'] = $referral;
		}
		return $snapshot;
	}

	/** Paid order → wpac_record_sale(). Idempotent on the Affiliate Captain side. */
	public static function on_paid( $order_id ) {
		$order = self::reportable_order( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status || (int) $order->total_minor <= 0 ) { return null; }
		$snapshot = self::snapshot_of( $order );
		$items    = self::items( $order );
		if ( ! $items ) { return null; }
		$latest_start = 0;
		foreach ( $items as $item ) { $latest_start = max( $latest_start, (int) $item['_event_end'] ); }
		$referral = is_array( $snapshot['affiliate_referral'] ?? null ) ? $snapshot['affiliate_referral'] : array();
		$payload  = array(
			'contract'     => self::CONTRACT,
			'source'       => self::SOURCE,
			'order_id'     => (string) (int) $order->order_id,
			'currency'     => (string) $order->currency,
			'paid_at'      => (string) $order->updated_at,
			'coupon_codes' => array_values( array_filter( array_map( static function ( $campaign ) { return is_array( $campaign ) ? (string) ( $campaign['code'] ?? '' ) : ''; }, (array) ( $snapshot['campaigns'] ?? array() ) ) ) ),
			'customer'     => array( 'user_id' => self::user_id_for( $order->customer_email ), 'email' => (string) $order->customer_email ),
			'items'        => array_map( static function ( $item ) { unset( $item['_event_end'], $item['_event_id'] ); return $item; }, $items ),
		);
		if ( ! empty( $referral['code'] ) ) {
			$payload['partner_code']        = (string) $referral['code'];
			$payload['partner_code_method'] = 'code';
		}
		if ( ! empty( $referral['touch']['code'] ) ) {
			$payload['touch'] = $referral['touch'];
		}
		/**
		 * Latest date until which the commission stays on hold. Defaults to the end (or start) of the
		 * latest booked event date: a ticket can still be refunded or the event cancelled until then.
		 *
		 * @since 0.4.0
		 * @param int    $timestamp Unix timestamp, 0 = Affiliate Captain default only.
		 * @param object $order     Order row.
		 */
		$hold = (int) apply_filters( 'wpcap_affiliate_hold_until', $latest_start, $order );
		if ( $hold > 0 ) {
			$payload['hold_until'] = gmdate( 'Y-m-d H:i:s', $hold );
		}
		/**
		 * Final sale payload sent to WP Affiliate Captain.
		 *
		 * @since 0.4.0
		 */
		$payload = apply_filters( 'wpcap_affiliate_sale_payload', $payload, $order );
		$result  = self::call( 'wpac_record_sale', $payload, $order->order_id, 'affiliate_sale' );
		if ( ! is_wp_error( $result ) || 'wpac_queued' === $result->get_error_code() ) {
			self::remember_lines( $order, $payload['items'] );
		}
		return $result;
	}

	/** Full refund → reverse the remaining commission. */
	public static function on_refunded( $order_id, $previous_payment_status = 'paid', $reason = '' ) {
		return self::reverse( $order_id, 'refund', 'refund-' . absint( $order_id ), $previous_payment_status, $reason );
	}

	/** Cancellation of a paid order → reverse the remaining commission. */
	public static function on_cancelled( $order_id, $previous_payment_status = '', $reason = '' ) {
		return self::reverse( $order_id, 'cancellation', 'cancel-' . absint( $order_id ), $previous_payment_status, $reason );
	}

	/** Event cancelled → reverse the commission share of that event's items in every paid order. */
	public static function on_event_cancelled( $event_id, $order_ids ) {
		$results = array();
		foreach ( (array) $order_ids as $order_id ) {
			$order = self::reportable_order( $order_id );
			if ( ! $order || 'paid' !== $order->payment_status ) { continue; }
			// Reverse exactly what was reported (tax profiles may have changed since).
			$reported = (array) ( self::snapshot_of( $order )['affiliate_lines'] ?? array() );
			$lines    = array();
			foreach ( self::items( $order ) as $item ) {
				if ( (int) $item['_event_id'] === absint( $event_id ) ) {
					$net     = array_key_exists( $item['item_id'], $reported ) ? (int) $reported[ $item['item_id'] ] : $item['net_minor'];
					$lines[] = array( 'item_id' => $item['item_id'], 'net_minor' => $net );
				}
			}
			if ( ! $lines ) { continue; }
			$results[ $order->order_id ] = self::call(
				'wpac_record_refund',
				array(
					'contract'  => self::CONTRACT,
					'source'    => self::SOURCE,
					'order_id'  => (string) (int) $order->order_id,
					'refund_id' => 'event-cancelled-' . absint( $event_id ),
					'type'      => 'refund',
					'items'     => $lines,
					'reason'    => 'event_cancelled',
				),
				$order->order_id,
				'affiliate_refund'
			);
		}
		return $results;
	}

	private static function reverse( $order_id, $type, $refund_id, $previous_payment_status, $reason ) {
		if ( 'paid' !== $previous_payment_status ) { return null; }
		$order = self::reportable_order( $order_id );
		if ( ! $order ) { return null; }
		return self::call(
			'wpac_record_refund',
			array(
				'contract'  => self::CONTRACT,
				'source'    => self::SOURCE,
				'order_id'  => (string) (int) $order->order_id,
				'refund_id' => $refund_id,
				'type'      => $type,
				'full'      => true,
				'reason'    => substr( sanitize_text_field( (string) $reason ), 0, 100 ),
			),
			$order->order_id,
			'affiliate_refund'
		);
	}

	/** Order row if the adapter is active and the order is a native (non-WooCommerce) order. */
	private static function reportable_order( $order_id ) {
		if ( ! self::available() ) { return null; }
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,customer_email,payment_status,status,currency,total_minor,discount_minor,price_snapshot,updated_at FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order ) { return null; }
		$source = (string) ( self::snapshot_of( $order )['source'] ?? 'native' );
		/**
		 * Order sources reported by this adapter. "woocommerce" is excluded on purpose (see class doc).
		 *
		 * @since 0.4.0
		 */
		$sources = (array) apply_filters( 'wpcap_affiliate_sources', array( 'native', 'pos' ) );
		return in_array( $source, $sources, true ) ? $order : null;
	}

	/** Keeps the reported net per line with the order (Pass Captain's own data, no personal data). */
	private static function remember_lines( $order, array $items ) {
		global $wpdb;
		$snapshot = self::snapshot_of( $order );
		$snapshot['affiliate_lines'] = array();
		foreach ( $items as $item ) {
			$snapshot['affiliate_lines'][ (string) $item['item_id'] ] = (int) $item['net_minor'];
		}
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'price_snapshot' => wp_json_encode( $snapshot ) ), array( 'order_id' => (int) $order->order_id ), array( '%s' ), array( '%d' ) );
	}

	private static function snapshot_of( $order ) {
		$snapshot = json_decode( (string) $order->price_snapshot, true );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Contract items: one per order line, net of the pro-rata campaign discount and of VAT.
	 * The SKU "PASS-E{event}-T{ticket type}" lets commission rules target events or ticket types
	 * (e.g. SKU rule "PASS-E12-*").
	 */
	private static function items( $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT oi.item_id,oi.ticket_type_id,oi.quantity,oi.line_total_minor,tt.name AS type_name,tt.tax_class,o.event_id,o.starts_at,o.ends_at,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", $order->order_id ) );
		$subtotal = 0;
		foreach ( $lines as $line ) { $subtotal += (int) $line->line_total_minor; }
		$discount  = min( (int) $order->discount_minor, $subtotal );
		$cum_gross = 0;
		$allocated = 0;
		$items     = array();
		foreach ( $lines as $line ) {
			// Same cumulative floor allocation as invoices, so the line discounts add up exactly.
			$cum_gross += (int) $line->line_total_minor;
			$should     = $subtotal ? (int) floor( $discount * $cum_gross / $subtotal ) : 0;
			$line_disc  = $should - $allocated;
			$allocated  = $should;
			$gross      = max( 0, (int) $line->line_total_minor - $line_disc );
			$end        = $line->ends_at ? $line->ends_at : $line->starts_at;
			$items[]    = array(
				'item_id'      => (string) (int) $line->item_id,
				'product_id'   => 0,
				'parent_id'    => 0,
				'sku'          => 'PASS-E' . (int) $line->event_id . '-T' . (int) $line->ticket_type_id,
				'category_ids' => array(),
				'name'         => $line->event_name . ' – ' . $line->type_name,
				'quantity'     => (int) $line->quantity,
				'net_minor'    => self::net_of_tax( $gross, (string) $line->tax_class, $order ),
				'_event_id'    => (int) $line->event_id,
				'_event_end'   => $end ? (int) strtotime( $end . ' UTC' ) : 0,
			);
		}
		return $items;
	}

	/**
	 * Ticket prices are gross. Removes VAT using the single active tax profile for the ticket's tax
	 * class (if exactly one applies on the payment date); otherwise the price is treated as net.
	 * Override with the "wpcap_affiliate_net_minor" filter for multi-country setups.
	 */
	private static function net_of_tax( $gross_minor, $tax_class, $order ) {
		global $wpdb;
		$class = '' !== $tax_class ? $tax_class : 'standard';
		$date  = substr( (string) $order->updated_at, 0, 10 );
		$rates = $wpdb->get_col( $wpdb->prepare( "SELECT rate_basis_points FROM {$wpdb->prefix}wpcap_tax_profiles WHERE status='active' AND tax_class=%s AND effective_from<=%s AND (effective_until IS NULL OR effective_until>=%s)", $class, $date, $date ) );
		$net = $gross_minor;
		if ( 1 === count( array_unique( array_map( 'intval', $rates ) ) ) ) {
			$bp  = (int) $rates[0];
			$net = (int) round( $gross_minor * 10000 / ( 10000 + $bp ) );
		}
		/**
		 * Commission base (net, minor units) of one order line.
		 *
		 * @since 0.4.0
		 * @param int    $net         Net amount.
		 * @param int    $gross_minor Gross amount after discount.
		 * @param string $tax_class   Ticket tax class.
		 * @param object $order       Order row.
		 */
		return max( 0, (int) apply_filters( 'wpcap_affiliate_net_minor', $net, $gross_minor, $tax_class, $order ) );
	}

	private static function user_id_for( $email ) {
		if ( ! function_exists( 'get_user_by' ) ) { return 0; }
		$user = get_user_by( 'email', (string) $email );
		return $user ? (int) $user->ID : 0;
	}

	/** Calls the contract function; failures never break the Pass Captain flow but are audited. */
	private static function call( $function, array $payload, $order_id, $audit_action ) {
		try {
			$result = call_user_func( $function, $payload );
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'wpcap_affiliate_exception', $error->getMessage() );
		}
		if ( is_wp_error( $result ) && 'wpac_queued' === $result->get_error_code() ) {
			// Affiliate Captain stored the event in its retry queue; nothing is lost.
			Audit::record( $audit_action . '_queued', 'order', (int) $order_id, array( 'message' => $result->get_error_message() ) );
		} elseif ( is_wp_error( $result ) ) {
			Audit::record( $audit_action . '_failed', 'order', (int) $order_id, array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
		} elseif ( is_array( $result ) && in_array( $result['status'] ?? '', array( 'created', 'queued', 'adjusted', 'unattributed' ), true ) ) {
			Audit::record( $audit_action, 'order', (int) $order_id, array( 'status' => $result['status'] ) );
		}
		return $result;
	}
}
