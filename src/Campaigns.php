<?php
/** Transaction-safe discount campaigns for checkout. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Campaigns {
	/** Normalised campaign code ("summer-10 " → "SUMMER-10"). */
	public static function normalize_code( $code ) {
		return strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( (string) $code ) ) ) );
	}

	/**
	 * Whether a code entered in the single checkout code field is a discount campaign (any status, so an expired
	 * campaign still gets the campaign's own error message instead of "unknown voucher").
	 */
	public static function is_code( $code ) {
		global $wpdb;
		$code = self::normalize_code( $code );
		if ( '' === $code ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}wpcap_campaigns WHERE code=%s LIMIT 1", $code ) );
	}

	/**
	 * Validate and calculate up to three explicit campaign codes.
	 * The caller must already be inside the order transaction.
	 */
	public static function calculate( array $selected, array $codes, $currency ) {
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
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT *,discount_value AS value FROM $table WHERE code=%s AND status='active' FOR UPDATE", $code ) );
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
			$left = $discount;
			foreach ( $selected as $index => $line ) {
				$type_match = ! (int) $campaign->ticket_type_id || (int) $campaign->ticket_type_id === (int) $line['type']->ticket_type_id;
				$event_match = ! (int) $campaign->event_id || (int) $campaign->event_id === (int) $line['event_id'];
				if ( ! $type_match || ! $event_match ) { continue; }
				$part = min( $remaining[ $index ], $left );
				$remaining[ $index ] -= $part;
				$left -= $part;
				if ( $left < 1 ) { break; }
			}
			$adjustments[] = array( 'campaign' => $campaign, 'amount_minor' => $discount );
		}
		return $adjustments;
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
		Audit::record( 'campaign_created', 'campaign', (int) $wpdb->insert_id, array( 'code' => $code, 'kind' => $kind ) );
		return array( 'campaign_id' => (int) $wpdb->insert_id, 'code' => $code );
	}
}
