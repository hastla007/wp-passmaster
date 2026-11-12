<?php
/**
 * Optional adapter for WP Affiliate Captain (integration contract v1).
 *
 * Reports paid Pass Captain orders (online checkout, POS, box office, waiting list) as sales and
 * reverses the commission on refunds (also partial ones and those of cancelled dates) and on
 * cancellations. Orders that came in through WooCommerce are skipped: WP Affiliate Captain records
 * the WooCommerce order itself, so reporting them here would pay commission twice.
 *
 * Uses only Pass Captain's documented hooks and the public functions of WP Affiliate Captain; never
 * reads Affiliate Captain tables and never sends payment data. The customer e-mail is passed only for
 * Affiliate Captain's self-referral check and is not stored there.
 *
 * @package PassCaptain
 */
namespace PassCaptain\Integrations\AffiliateCaptain;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Adapter {
	const SOURCE   = 'pass-captain';
	const CONTRACT = '1.0.0';
	/** Channels whose request belongs to staff: they never take over a visitor referral. */
	const STAFF_CHANNELS = array( 'pos', 'boxoffice' );

	public static function register() {
		add_filter( 'wpac_registered_integrations', array( __CLASS__, 'register_integration' ) );
		add_filter( 'wpcap_checkout_payload_keys', array( __CLASS__, 'payload_keys' ) );
		add_filter( 'wpcap_checkout_error_messages', array( __CLASS__, 'error_messages' ) );
		add_action( 'wpcap_checkout_form_fields', array( __CLASS__, 'form_field' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ), 20 );
		add_action( 'wpcap_order_creating', array( __CLASS__, 'on_creating' ), 10, 4 );
		add_action( 'wpcap_order_paid', array( __CLASS__, 'on_paid' ) );
		add_action( 'wpcap_refund_completed', array( __CLASS__, 'on_refund' ), 10, 2 );
		add_action( 'wpcap_order_refunded', array( __CLASS__, 'on_refunded' ), 10, 2 );
		add_action( 'wpcap_order_cancelled', array( __CLASS__, 'on_cancelled' ) );
	}

	/** True if WP Affiliate Captain is active with a contract of the same major version. */
	public static function available() {
		if ( ! function_exists( 'wpac_record_sale' ) || ! function_exists( 'wpac_contract_version' ) ) { return false; }
		return strtok( (string) wpac_contract_version(), '.' ) === strtok( self::CONTRACT, '.' );
	}

	public static function register_integration( $list ) {
		$list = is_array( $list ) ? $list : array();
		$list[ self::SOURCE ] = array( 'name' => 'WP Pass Captain', 'version' => defined( 'WPCAP_VERSION' ) ? WPCAP_VERSION : '', 'contract' => self::CONTRACT, 'active' => true );
		return $list;
	}

	/* ---------------------------------------------------------------- Checkout */

	public static function partner_field_enabled() {
		return self::available() && (bool) apply_filters( 'wpcap_affiliate_partner_field', true );
	}

	public static function payload_keys( $keys ) {
		$keys = (array) $keys;
		if ( self::available() ) { $keys[] = 'partner_code'; }
		return $keys;
	}

	public static function error_messages( $messages ) {
		$messages = (array) $messages;
		$messages['partner_code_invalid'] = __( 'Dieser Partnercode ist ungültig oder nicht mehr aktiv.', 'wp-pass-captain' );
		$messages['partner_code_rate_limited'] = __( 'Zu viele ungültige Partnercodes. Bitte warte etwas und versuche es erneut.', 'wp-pass-captain' );
		return $messages;
	}

	public static function form_field() {
		if ( ! self::partner_field_enabled() ) { return; }
		echo '<label>' . esc_html__( 'Partnercode (optional)', 'wp-pass-captain' ) . ' <input name="partner_code" type="text" autocomplete="off" maxlength="64" pattern="[A-Za-z0-9_\-]*"></label>';
	}

	/** Adds the partner code to the checkout request through the checkout's payload event. */
	public static function script() {
		if ( ! self::partner_field_enabled() || ! wp_script_is( 'wpcap-checkout', 'registered' ) ) { return; }
		wp_add_inline_script( 'wpcap-checkout', "document.addEventListener('wpcap:checkout-payload',function(e){var f=e.detail&&e.detail.form&&e.detail.form.querySelector('[name=\"partner_code\"]');if(f&&f.value.trim()){e.detail.payload.partner_code=f.value.trim();}});" );
	}

	/**
	 * Inside the order transaction: validates an entered partner code and stores the referral (code and
	 * the visitor's tracked link/cookie) with the order, because payment is often confirmed later – by
	 * a webhook or staff – when the buyer's request is gone. Throwing aborts the order.
	 */
	public static function on_creating( $order_id, $selected, $payload, $internal ) {
		if ( ! self::available() ) { return; }
		$internal = (array) $internal;
		$channel  = sanitize_key( $internal['source'] ?? ( $internal['key_namespace'] ?? 'native' ) );
		$channel  = '' !== $channel ? $channel : 'native';
		$referral = array( 'channel' => $channel );
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( ( (array) $payload )['partner_code'] ?? '' ) ) );
		if ( '' !== $code ) {
			self::check_code( substr( $code, 0, 64 ) );
			$referral['code'] = substr( $code, 0, 64 );
		}
		$visitor_request = empty( $internal['external'] ) && ! in_array( $channel, self::STAFF_CHANNELS, true );
		// The tracked link/cookie is only read with consent; a manually entered code (above) is the
		// buyer's own declaration and needs none.
		$touch = $visitor_request && function_exists( 'wpac_current_touch' ) && self::tracking_allowed() ? wpac_current_touch() : null;
		if ( is_array( $touch ) && ! empty( $touch['code'] ) ) {
			$referral['touch'] = array(
				'code'       => substr( sanitize_text_field( (string) $touch['code'] ), 0, 64 ),
				'method'     => in_array( $touch['method'] ?? '', array( 'link', 'cookie', 'app' ), true ) ? $touch['method'] : 'link',
				'campaign'   => substr( sanitize_text_field( (string) ( $touch['campaign'] ?? '' ) ), 0, 100 ),
				'content_id' => absint( $touch['content_id'] ?? 0 ),
			);
		}
		self::update_snapshot( $order_id, array( 'affiliate_referral' => $referral ) );
	}

	/**
	 * Whether the visitor's referral cookie/link may be read for this order.
	 *
	 * With the WP Consent API (plugin "WP Consent API" plus a compatible cookie banner) the
	 * "marketing" category decides – no consent, no read. Without a consent API the default is
	 * true: the site operator is then responsible for gating Affiliate Captain's own tracking
	 * cookie in the cookie banner. Filter `wpcap_affiliate_tracking_allowed` (bool, order context
	 * not yet available) overrides both, e.g. for a different consent manager.
	 */
	public static function tracking_allowed() {
		$allowed = function_exists( 'wp_has_consent' ) ? (bool) wp_has_consent( 'marketing' ) : true;
		return (bool) apply_filters( 'wpcap_affiliate_tracking_allowed', $allowed );
	}

	/** Rejects unknown codes; at most 10 wrong codes per hour and client (code guessing). */
	private static function check_code( $code ) {
		$client = class_exists( '\PassCaptain\RateLimit' ) ? \PassCaptain\RateLimit::client() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key    = 'wpcap_partner_fail_' . hash( 'sha256', $client . '|' . wp_salt( 'nonce' ) );
		$fails  = (int) get_transient( $key );
		if ( $fails >= 10 ) { throw new \RuntimeException( 'partner_code_rate_limited' ); }
		if ( ! function_exists( 'wpac_resolve_partner_code' ) || ! wpac_resolve_partner_code( $code, array( 'context' => 'code', 'source' => self::SOURCE ) ) ) {
			set_transient( $key, $fails + 1, HOUR_IN_SECONDS );
			throw new \RuntimeException( 'partner_code_invalid' );
		}
	}

	/* ---------------------------------------------------------------- Sale */

	/** Paid order → wpac_record_sale(). Idempotent on the Affiliate Captain side. */
	public static function on_paid( $order_id ) {
		$order = self::reportable_order( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status || (int) $order->total_minor <= 0 ) { return null; }
		$snapshot = self::snapshot_of( $order );
		if ( isset( $snapshot['affiliate_lines'] ) ) { return null; } // Reported already.
		$items = self::items( $order );
		if ( ! $items ) { return null; }
		$latest_end = 0;
		foreach ( $items as $item ) { $latest_end = max( $latest_end, (int) $item['_event_end'] ); }
		$referral = (array) ( $snapshot['affiliate_referral'] ?? array() );
		$payload  = array(
			'contract'     => self::CONTRACT,
			'source'       => self::SOURCE,
			'order_id'     => (string) (int) $order->order_id,
			'currency'     => (string) $order->currency,
			'paid_at'      => (string) $order->updated_at,
			'coupon_codes' => array_values( array_filter( array_map( static function ( $campaign ) { return is_array( $campaign ) ? (string) ( $campaign['code'] ?? '' ) : ''; }, (array) ( $snapshot['campaigns'] ?? array() ) ) ) ),
			'customer'     => array( 'user_id' => self::user_id_for( $order->customer_email ), 'email' => (string) $order->customer_email ),
			'items'        => array_map( array( __CLASS__, 'public_item' ), $items ),
		);
		if ( ! empty( $referral['code'] ) ) {
			$payload['partner_code']        = (string) $referral['code'];
			$payload['partner_code_method'] = 'code';
		}
		if ( ! empty( $referral['touch']['code'] ) ) {
			$payload['touch'] = $referral['touch'];
		}
		/**
		 * Latest date until which the commission stays on hold. Default: end (or start) of the latest
		 * booked date – until then a ticket can still be refunded or the date cancelled.
		 *
		 * @param int    $timestamp Unix timestamp, 0 = Affiliate Captain default only.
		 * @param object $order     Order row.
		 */
		$hold = (int) apply_filters( 'wpcap_affiliate_hold_until', $latest_end, $order );
		if ( $hold > 0 ) { $payload['hold_until'] = gmdate( 'Y-m-d H:i:s', $hold ); }
		/** Final sale payload sent to WP Affiliate Captain. */
		$payload = apply_filters( 'wpcap_affiliate_sale_payload', $payload, $order );
		$result  = self::call( 'wpac_record_sale', $payload, $order->order_id, 'affiliate_sale' );
		if ( ! is_wp_error( $result ) || 'wpac_queued' === $result->get_error_code() ) {
			$lines = array();
			foreach ( $payload['items'] as $item ) { $lines[ (string) $item['item_id'] ] = (int) $item['net_minor']; }
			self::update_snapshot( $order->order_id, array( 'affiliate_lines' => $lines ) );
		}
		return $result;
	}

	/* ---------------------------------------------------------------- Reversals */

	/**
	 * Completed native refund (also of a cancelled date) → proportional reversal. The refunded net
	 * (amount − VAT) is spread over the lines of the refunded tickets, capped at what was reported.
	 */
	public static function on_refund( $refund_id, $order_id ) {
		$order = self::reported_order( $order_id );
		if ( ! $order ) { return null; }
		global $wpdb;
		$p      = $wpdb->prefix;
		$refund = $wpdb->get_row( $wpdb->prepare( "SELECT refund_id,amount_minor,tax_minor,ticket_ids,reason FROM {$p}wpcap_refunds WHERE refund_id=%d AND order_id=%d AND status='completed'", absint( $refund_id ), (int) $order->order_id ) );
		if ( ! $refund ) { return null; }
		$net_refund = max( 0, (int) $refund->amount_minor - (int) $refund->tax_minor );
		$reported   = (array) self::snapshot_of( $order )['affiliate_lines'];
		$items      = self::items( $order );
		// Weight per line: number of refunded tickets of its ticket type, or the whole order if none named.
		$ticket_ids = array_filter( array_map( 'absint', (array) json_decode( (string) $refund->ticket_ids, true ) ) );
		$per_type   = array();
		if ( $ticket_ids ) {
			$ids = implode( ',', $ticket_ids );
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id, COUNT(*) AS n FROM {$p}wpcap_tickets WHERE ticket_id IN ($ids) AND order_id=%d GROUP BY ticket_type_id", (int) $order->order_id ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$per_type[ (int) $row->ticket_type_id ] = (int) $row->n;
			}
		}
		$weights = array();
		foreach ( $items as $item ) {
			$base = (int) ( $reported[ $item['item_id'] ] ?? $item['net_minor'] );
			if ( $ticket_ids ) {
				$n = min( (int) $item['quantity'], (int) ( $per_type[ $item['_type_id'] ] ?? 0 ) );
				$base = $item['quantity'] > 0 ? intdiv( $base * $n, (int) $item['quantity'] ) : 0;
			}
			if ( $base > 0 ) { $weights[ $item['item_id'] ] = $base; }
		}
		$sum = array_sum( $weights );
		if ( ! $sum || ! $net_refund ) { return null; }
		$net_refund = min( $net_refund, $sum );
		$lines = array();
		$left  = $net_refund;
		$keys  = array_keys( $weights );
		foreach ( $keys as $i => $item_id ) {
			$share   = $i === count( $keys ) - 1 ? $left : intdiv( $net_refund * $weights[ $item_id ], $sum );
			$share   = min( $share, $weights[ $item_id ] );
			$left   -= $share;
			$lines[] = array( 'item_id' => (string) $item_id, 'net_minor' => $share );
		}
		return self::call(
			'wpac_record_refund',
			array( 'contract' => self::CONTRACT, 'source' => self::SOURCE, 'order_id' => (string) (int) $order->order_id, 'refund_id' => 'refund-' . (int) $refund->refund_id, 'type' => 'refund', 'items' => $lines, 'reason' => substr( sanitize_text_field( (string) $refund->reason ), 0, 100 ) ),
			$order->order_id,
			'affiliate_refund'
		);
	}

	/** Order fully refunded (native, provider dashboard) → reverse what is left. */
	public static function on_refunded( $order_id, $reason = '' ) {
		return self::reverse( $order_id, 'refund', 'refund-full', $reason );
	}

	/** Cancelled order: reverses only if a sale was reported (pending orders were never reported). */
	public static function on_cancelled( $order_id ) {
		return self::reverse( $order_id, 'cancellation', 'cancel', 'cancelled' );
	}

	private static function reverse( $order_id, $type, $refund_id, $reason ) {
		$order = self::reported_order( $order_id );
		if ( ! $order ) { return null; }
		return self::call(
			'wpac_record_refund',
			array( 'contract' => self::CONTRACT, 'source' => self::SOURCE, 'order_id' => (string) (int) $order->order_id, 'refund_id' => $refund_id . '-' . (int) $order->order_id, 'type' => $type, 'full' => true, 'reason' => substr( sanitize_text_field( (string) $reason ), 0, 100 ) ),
			$order->order_id,
			'affiliate_refund'
		);
	}

	/* ---------------------------------------------------------------- Helpers */

	/** Order row if the adapter is active and the order did not come from WooCommerce. */
	private static function reportable_order( $order_id ) {
		if ( ! self::available() ) { return null; }
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,customer_email,payment_status,status,currency,total_minor,price_snapshot,payment_provider,updated_at FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", absint( $order_id ) ) );
		if ( ! $order ) { return null; }
		$channel = (string) ( self::snapshot_of( $order )['affiliate_referral']['channel'] ?? '' );
		/**
		 * Channels that are not reported. "woocommerce" is excluded on purpose (see class doc).
		 *
		 * @param string[] $channels Channel or payment provider names.
		 */
		$skip = (array) apply_filters( 'wpcap_affiliate_skip_channels', array( 'woocommerce' ) );
		return ( in_array( $channel, $skip, true ) || in_array( (string) $order->payment_provider, $skip, true ) ) ? null : $order;
	}

	/** Reportable order that has been reported to Affiliate Captain. */
	private static function reported_order( $order_id ) {
		$order = self::reportable_order( $order_id );
		return ( $order && isset( self::snapshot_of( $order )['affiliate_lines'] ) ) ? $order : null;
	}

	private static function snapshot_of( $order ) {
		$snapshot = json_decode( (string) $order->price_snapshot, true );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	/** Merges keys into the order's price snapshot (Pass Captain's own data, no personal data). */
	private static function update_snapshot( $order_id, array $data ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'wpcap_orders';
		$snapshot = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT price_snapshot FROM {$table} WHERE order_id=%d", absint( $order_id ) ) ), true );
		$snapshot = array_merge( is_array( $snapshot ) ? $snapshot : array(), $data );
		$wpdb->update( $table, array( 'price_snapshot' => wp_json_encode( $snapshot ) ), array( 'order_id' => absint( $order_id ) ) );
	}

	/**
	 * Contract items, one per order line. The amount actually paid (after campaign discounts and an
	 * applied voucher) is allocated to the lines like Orders::create() allocates discounts; the line's
	 * stored VAT is then removed. SKU "PASS-E{event}-T{ticket type}" lets rules target events or types.
	 */
	private static function items( $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT oi.item_id,oi.ticket_type_id,oi.quantity,oi.line_total_minor,oi.tax_minor,tt.name AS type_name,o.event_id,o.starts_at,o.ends_at,e.name AS event_name FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE oi.order_id=%d ORDER BY oi.item_id", $order->order_id ) );
		$subtotal = 0;
		foreach ( $lines as $line ) { $subtotal += (int) $line->line_total_minor; }
		$paid  = min( (int) $order->total_minor, $subtotal );
		$left  = $paid;
		$last  = count( $lines ) - 1;
		$items = array();
		foreach ( $lines as $index => $line ) {
			$gross = $index === $last ? $left : ( $subtotal > 0 ? intdiv( $paid * (int) $line->line_total_minor, $subtotal ) : 0 );
			$gross = max( 0, min( $gross, $left ) );
			$left -= $gross;
			$end   = $line->ends_at ? $line->ends_at : $line->starts_at;
			$net   = max( 0, $gross - (int) $line->tax_minor );
			$items[] = array(
				'item_id'      => (string) (int) $line->item_id,
				'product_id'   => 0,
				'parent_id'    => 0,
				'sku'          => 'PASS-E' . (int) $line->event_id . '-T' . (int) $line->ticket_type_id,
				'category_ids' => array(),
				'name'         => $line->event_name . ' – ' . $line->type_name,
				'quantity'     => (int) $line->quantity,
				/** Commission base (net, minor units) of one order line. */
				'net_minor'    => max( 0, (int) apply_filters( 'wpcap_affiliate_net_minor', $net, $line, $order ) ),
				'_type_id'     => (int) $line->ticket_type_id,
				'_event_end'   => $end ? (int) strtotime( $end . ' UTC' ) : 0,
			);
		}
		return $items;
	}

	private static function public_item( $item ) {
		unset( $item['_type_id'], $item['_event_end'] );
		return $item;
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
			Audit::record( $audit_action . '_queued', 'order', (int) $order_id, array( 'message' => $result->get_error_message() ) );
		} elseif ( is_wp_error( $result ) ) {
			Audit::record( $audit_action . '_failed', 'order', (int) $order_id, array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
		} elseif ( is_array( $result ) ) {
			Audit::record( $audit_action, 'order', (int) $order_id, array( 'status' => (string) ( $result['status'] ?? '' ) ) );
		}
		return $result;
	}
}
