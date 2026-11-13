<?php
/** Transaction-safe discount campaigns for checkout. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Campaigns {
	/**
	 * Validate and calculate up to three explicit campaign codes.
	 * The caller must already be inside the order transaction.
	 *
	 * @param bool $lock False for a non-binding preview (no row locks).
	 * @return array|\WP_Error Adjustments: campaign, amount_minor, lines (index of $selected => discount).
	 */
	public static function calculate( array $selected, array $codes, $currency, $lock = true ) {
		global $wpdb;
		$codes = array_values( array_unique( array_filter( array_map( static function ( $code ) {
			return strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( $code ) ) ) );
		}, $codes ) ) ) );
		sort( $codes, SORT_STRING );
		if ( count( $codes ) > 3 ) { return new \WP_Error( 'wpcap_campaign_limit', __( 'Es können höchstens drei Kampagnencodes verwendet werden.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$campaigns = array();
		$table = $wpdb->prefix . 'wpcap_campaigns';
		$now = current_time( 'mysql', true );
		foreach ( $codes as $code ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT *,discount_value AS value FROM $table WHERE code=%s AND status='active'" . ( $lock ? ' FOR UPDATE' : '' ), $code ) );
			if ( ! $campaign || ( $campaign->starts_at && $campaign->starts_at > $now ) || ( $campaign->ends_at && $campaign->ends_at < $now ) || ( $campaign->usage_limit > 0 && (int) $campaign->used_count >= (int) $campaign->usage_limit ) || strtoupper( $campaign->currency ) !== strtoupper( $currency ) ) {
				return new \WP_Error( 'wpcap_campaign_unavailable', __( 'Ein Kampagnencode ist ungültig, abgelaufen oder nicht mehr verfügbar.', 'wp-pass-captain' ), array( 'status' => 409 ) );
			}
			$campaigns[] = $campaign;
		}
		usort( $campaigns, static function ( $a, $b ) { $priority = (int) $a->priority <=> (int) $b->priority; return $priority ?: ( (int) $a->campaign_id <=> (int) $b->campaign_id ); } );
		$adjustments = array();
		$remaining = array_map( static function ( $line ) { return (int) $line['line_total']; }, $selected );
		foreach ( $campaigns as $campaign ) {
			$eligible = 0;
			foreach ( $selected as $index => $line ) {
				$type_match = ! (int) $campaign->ticket_type_id || (int) $campaign->ticket_type_id === (int) $line['type']->ticket_type_id;
				$event_match = ! (int) $campaign->event_id || (int) $campaign->event_id === (int) $line['event_id'];
				if ( $type_match && $event_match ) { $eligible += $remaining[ $index ]; }
			}
			$discount = 'percent' === $campaign->kind ? (int) floor( $eligible * min( 10000, (int) $campaign->value ) / 10000 ) : min( $eligible, (int) $campaign->value );
			if ( $discount < 1 ) { return new \WP_Error( 'wpcap_campaign_no_match', __( 'Ein Kampagnencode passt nicht zu den ausgewählten Tickets.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
			// Only the eligible lines carry the discount (VAT per rate and per organizer stay right),
			// proportionally to what is left of them; the last eligible line takes the remainder.
			$targets = array();
			foreach ( $selected as $index => $line ) {
				$type_match = ! (int) $campaign->ticket_type_id || (int) $campaign->ticket_type_id === (int) $line['type']->ticket_type_id;
				$event_match = ! (int) $campaign->event_id || (int) $campaign->event_id === (int) $line['event_id'];
				if ( $type_match && $event_match && $remaining[ $index ] > 0 ) { $targets[] = $index; }
			}
			$left = $discount;
			$parts = array();
			foreach ( $targets as $n => $index ) {
				$part = $n === count( $targets ) - 1 ? $left : intdiv( $discount * $remaining[ $index ], max( 1, $eligible ) );
				$part = min( $remaining[ $index ], max( 0, $part ) );
				$parts[ $index ] = $part;
				$left -= $part;
			}
			// Rounding remainder that the last line could not take (capped): first lines with room.
			foreach ( $targets as $index ) {
				if ( $left < 1 ) { break; }
				$room = $remaining[ $index ] - $parts[ $index ];
				$extra = min( $room, $left );
				$parts[ $index ] += $extra;
				$left -= $extra;
			}
			foreach ( $parts as $index => $part ) { $remaining[ $index ] -= $part; }
			$adjustments[] = array( 'campaign' => $campaign, 'amount_minor' => $discount, 'lines' => $parts );
		}
		return $adjustments;
	}

	/**
	 * Discount per selected line (index => minor units) of calculate() adjustments.
	 *
	 * @param array $adjustments Result of calculate().
	 * @param int   $count       Number of selected lines.
	 * @return int[]
	 */
	public static function line_discounts( array $adjustments, $count ) {
		$out = array_fill( 0, max( 0, (int) $count ), 0 );
		foreach ( $adjustments as $adjustment ) {
			foreach ( (array) ( $adjustment['lines'] ?? array() ) as $index => $amount ) {
				$out[ (int) $index ] = ( $out[ (int) $index ] ?? 0 ) + (int) $amount;
			}
		}
		return $out;
	}

	/** Persist applied codes and atomically consume their limits in the caller transaction. */
	public static function redeem( array $adjustments, $order_id ) {
		global $wpdb;
		$campaigns = $wpdb->prefix . 'wpcap_campaigns';
		$uses = $wpdb->prefix . 'wpcap_campaign_uses';
		foreach ( $adjustments as $adjustment ) {
			$campaign = $adjustment['campaign'];
			$inserted = $wpdb->insert( $uses, array( 'campaign_id' => (int) $campaign->campaign_id, 'order_id' => (int) $order_id, 'code' => $campaign->code, 'discount_minor' => (int) $adjustment['amount_minor'], 'status' => 'reserved', 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%d', '%s', '%s' ) );
			if ( false === $inserted ) { return new \WP_Error( 'wpcap_campaign_use_failed', __( 'Kampagnennutzung konnte nicht gespeichert werden.', 'wp-pass-captain' ) ); }
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE $campaigns SET used_count=used_count+1 WHERE campaign_id=%d AND (usage_limit=0 OR used_count<usage_limit)", $campaign->campaign_id ) );
			if ( 1 !== $updated ) { return new \WP_Error( 'wpcap_campaign_exhausted', __( 'Das Kampagnenlimit wurde während des Checkouts erreicht.', 'wp-pass-captain' ) ); }
		}
		return true;
	}

	public static function commit_for_order( $order_id ) {
		global $wpdb;
		return $wpdb->update( $wpdb->prefix . 'wpcap_campaign_uses', array( 'status' => 'committed' ), array( 'order_id' => absint( $order_id ), 'status' => 'reserved' ), array( '%s' ), array( '%d', '%s' ) );
	}

	public static function release_for_order( $order_id ) {
		global $wpdb;
		$uses = $wpdb->prefix . 'wpcap_campaign_uses';
		$campaigns = $wpdb->prefix . 'wpcap_campaigns';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT campaign_id FROM $uses WHERE order_id=%d AND status='reserved'", absint( $order_id ) ) );
		foreach ( $rows as $row ) {
			$released = $wpdb->update( $uses, array( 'status' => 'released' ), array( 'campaign_id' => (int) $row->campaign_id, 'order_id' => absint( $order_id ), 'status' => 'reserved' ), array( '%s' ), array( '%d', '%d', '%s' ) );
			if ( 1 === $released ) { $wpdb->query( $wpdb->prepare( "UPDATE $campaigns SET used_count=IF(used_count>0,used_count-1,0) WHERE campaign_id=%d", $row->campaign_id ) ); }
		}
		return true;
	}

	public static function create( array $data ) {
		global $wpdb;
		$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( $data['code'] ?? '' ) ) ) );
		$kind = sanitize_key( $data['kind'] ?? '' );
		$value = absint( $data['value'] ?? 0 );
		$currency = strtoupper( sanitize_text_field( $data['currency'] ?? 'EUR' ) );
		if ( strlen( $code ) < 3 || strlen( $code ) > 32 || ! in_array( $kind, array( 'percent', 'fixed' ), true ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! $value || ( 'percent' === $kind && $value > 10000 ) ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Kampagnencode, Währung oder Rabattwert ist ungültig.', 'wp-pass-captain' ), array( 'status' => 400 ) );
		}
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_campaigns', array(
			'event_id' => absint( $data['event_id'] ?? 0 ), 'ticket_type_id' => absint( $data['ticket_type_id'] ?? 0 ), 'code' => $code,
			'name' => sanitize_text_field( $data['name'] ?? $code ), 'kind' => $kind, 'discount_value' => $value, 'currency' => $currency,
			'usage_limit' => absint( $data['usage_limit'] ?? 0 ), 'used_count' => 0, 'priority' => min( 1000, absint( $data['priority'] ?? 100 ) ),
			'starts_at' => sanitize_text_field( $data['starts_at'] ?? '' ) ?: null, 'ends_at' => sanitize_text_field( $data['ends_at'] ?? '' ) ?: null,
			'status' => 'active', 'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ) );
		if ( false === $inserted ) { return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht angelegt werden.', 'wp-pass-captain' ), array( 'status' => 409 ) ); }
		$campaign_id = (int) $wpdb->insert_id; // Before the audit insert changes insert_id.
		self::flush();
		Audit::record( 'campaign_created', 'campaign', $campaign_id, array( 'code' => $code, 'kind' => $kind ) );
		return array( 'campaign_id' => $campaign_id, 'code' => $code );
	}

	/* ------------------------------------------------------------------ Administration */

	/** Per-request cache: currency => event ids with an active code (0 = all events). */
	private static $active = array();

	/**
	 * Whether an active, usable code exists for the event (or for all events) in the currency.
	 * One query per currency and request (event listings call it per card).
	 */
	public static function has_active( $event_id, $currency ) {
		global $wpdb;
		$currency = strtoupper( (string) $currency );
		if ( ! isset( self::$active[ $currency ] ) ) {
			$now = current_time( 'mysql', true );
			self::$active[ $currency ] = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_campaigns WHERE status='active' AND currency=%s AND (starts_at IS NULL OR starts_at<=%s) AND (ends_at IS NULL OR ends_at>=%s) AND (usage_limit=0 OR used_count<usage_limit)",
				$currency,
				$now,
				$now
			) ) ) );
		}
		return isset( self::$active[ $currency ][0] ) || isset( self::$active[ $currency ][ absint( $event_id ) ] );
	}

	/** Clears the has_active() cache (after changes; tests). */
	public static function flush() {
		self::$active = array();
	}

	/** One campaign row. */
	public static function get( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_campaigns WHERE campaign_id=%d", absint( $campaign_id ) ) );
	}

	/**
	 * Campaigns with usage statistics (committed orders and discount), newest first.
	 *
	 * @param int[]|null $event_ids Scope (null = all; campaigns for all events only then).
	 */
	public static function all( $event_ids = null, $limit = 200 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$scope = null === $event_ids ? '' : ( $event_ids ? ' AND c.event_id IN (' . implode( ',', array_map( 'absint', $event_ids ) ) . ')' : ' AND 1=0' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*,e.name AS event_name,tt.name AS ticket_name,(SELECT COUNT(*) FROM {$p}wpcap_campaign_uses u WHERE u.campaign_id=c.campaign_id AND u.status='committed') AS orders_committed,(SELECT COALESCE(SUM(u.discount_minor),0) FROM {$p}wpcap_campaign_uses u WHERE u.campaign_id=c.campaign_id AND u.status='committed') AS discount_committed FROM {$p}wpcap_campaigns c LEFT JOIN {$p}wpcap_events e ON e.event_id=c.event_id LEFT JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=c.ticket_type_id WHERE 1=1{$scope} ORDER BY c.campaign_id DESC LIMIT %d", max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/** Activates or deactivates a code (orders keep their discount). @return true|\WP_Error */
	public static function set_status( $campaign_id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) { return new \WP_Error( 'wpcap_campaign_status', __( 'Ungültiger Status.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_campaigns', array( 'status' => $status ), array( 'campaign_id' => absint( $campaign_id ) ), array( '%s' ), array( '%d' ) );
		if ( false === $updated ) { return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) ); }
		self::flush();
		Audit::record( 'campaign_status', 'campaign', absint( $campaign_id ), array( 'status' => $status ) );
		return true;
	}

	/**
	 * Non-binding discount preview for the checkout (no locks, nothing reserved). The checkout
	 * recalculates and may still reject the code (e.g. limit reached meanwhile).
	 *
	 * @param array  $items ticket_type_id, quantity.
	 * @param string $code  Discount code.
	 * @return array|\WP_Error subtotal_minor, discount_minor, total_minor, currency.
	 */
	public static function preview( array $items, $code ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$selected = array();
		$currency = '';
		$subtotal = 0;
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$type_id = absint( is_array( $item ) ? ( $item['ticket_type_id'] ?? 0 ) : 0 );
			$qty = min( 100, absint( is_array( $item ) ? ( $item['quantity'] ?? 0 ) : 0 ) );
			if ( ! $type_id || ! $qty ) { continue; }
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT tt.*,o.event_id FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d AND tt.status='active' AND e.status='published'", $type_id ) );
			if ( ! $type ) { continue; }
			$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id=%d", (int) $type->occurrence_id ) );
			$unit = Orders::unit_price( $type, $occurrence, $qty );
			$currency = $currency ?: strtoupper( (string) $type->currency );
			$line_total = $unit * $qty;
			$subtotal += $line_total;
			$selected[] = array( 'type' => $type, 'event_id' => (int) $type->event_id, 'quantity' => $qty, 'line_total' => $line_total );
		}
		if ( ! $selected ) { return new \WP_Error( 'wpcap_campaign_preview', __( 'Bitte wähle mindestens ein Ticket.', 'wp-pass-captain' ), array( 'status' => 400 ) ); }
		$adjustments = self::calculate( $selected, array( (string) $code ), $currency, false );
		if ( is_wp_error( $adjustments ) ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$discount = array_sum( array_map( static function ( $a ) { return (int) $a['amount_minor']; }, $adjustments ) );
		return array( 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => max( 0, $subtotal - $discount ), 'currency' => $currency );
	}
}
