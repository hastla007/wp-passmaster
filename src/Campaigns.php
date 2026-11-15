<?php
/** Transaction-safe discount campaigns for checkout. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Campaigns {
	/**
	 * Validate and calculate up to three explicit campaign codes.
	 * The caller must already be inside the order transaction.
	 *
	 * Stacking rules (docs/SALES-MODELS.md):
	 * - codes are applied in priority order (smaller number first), each on the amount the previous
	 *   codes left over;
	 * - a campaign with combinable = 0 must be the only code of the order;
	 * - a campaign with with_price_rules = 0 ignores lines whose price came from an automatic price
	 *   rule (early bird, last minute, demand, weekday; line key price_rule);
	 * - max_discount_minor caps the discount of one campaign (0 = no cap);
	 * - the setting max_total_discount_bp caps the total reduction of the order (price rule
	 *   reductions against the stored price plus all campaign discounts) as a share of the order
	 *   value at stored prices (0 = no cap); later codes are reduced first.
	 *
	 * @param array $selected Lines: type, event_id, quantity, line_total, optional base_minor and price_rule.
	 */
	public static function calculate( array $selected, array $codes, $currency ) {
		global $wpdb;
		$codes = array_values( array_unique( array_filter( array_map( static function ( $code ) {
			return strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( $code ) ) ) );
		}, $codes ) ) ) );
		sort( $codes, SORT_STRING );
		if ( count( $codes ) > 3 ) { return new \WP_Error( 'wpcap_campaign_limit', __( 'Es können höchstens drei Kampagnencodes verwendet werden.', 'pass-captain' ), array( 'status' => 400 ) ); }
		$campaigns = array();
		$table = $wpdb->prefix . 'wpcap_campaigns';
		$now = current_time( 'mysql', true );
		foreach ( $codes as $code ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT *,discount_value AS value FROM %i WHERE code=%s AND status='active' FOR UPDATE", $table, $code ) );
			if ( ! $campaign || ( $campaign->starts_at && $campaign->starts_at > $now ) || ( $campaign->ends_at && $campaign->ends_at < $now ) || ( $campaign->usage_limit > 0 && (int) $campaign->used_count >= (int) $campaign->usage_limit ) || strtoupper( $campaign->currency ) !== strtoupper( $currency ) ) {
				return new \WP_Error( 'wpcap_campaign_unavailable', __( 'Ein Kampagnencode ist ungültig, abgelaufen oder nicht mehr verfügbar.', 'pass-captain' ), array( 'status' => 409 ) );
			}
			$campaigns[] = $campaign;
		}
		if ( count( $campaigns ) > 1 ) {
			foreach ( $campaigns as $campaign ) {
				if ( ! (int) ( $campaign->combinable ?? 1 ) ) {
					/* translators: %s: campaign code */
					return new \WP_Error( 'wpcap_campaign_not_combinable', sprintf( __( 'Der Code %s kann nicht mit anderen Codes kombiniert werden.', 'pass-captain' ), $campaign->code ), array( 'status' => 409 ) );
				}
			}
		}
		usort( $campaigns, static function ( $a, $b ) { $priority = (int) $a->priority <=> (int) $b->priority; return $priority ?: ( (int) $a->campaign_id <=> (int) $b->campaign_id ); } );
		$adjustments = array();
		$remaining = array_map( static function ( $line ) { return (int) $line['line_total']; }, $selected );
		$applies = static function ( $campaign, $line ) {
			$type_match = ! (int) $campaign->ticket_type_id || (int) $campaign->ticket_type_id === (int) $line['type']->ticket_type_id;
			$event_match = ! (int) $campaign->event_id || (int) $campaign->event_id === (int) $line['event_id'];
			$rule_ok = (int) ( $campaign->with_price_rules ?? 1 ) || empty( $line['price_rule'] );
			return $type_match && $event_match && $rule_ok;
		};
		foreach ( $campaigns as $campaign ) {
			$eligible = 0;
			foreach ( $selected as $index => $line ) {
				if ( $applies( $campaign, $line ) ) { $eligible += $remaining[ $index ]; }
			}
			$discount = 'percent' === $campaign->kind ? (int) floor( $eligible * min( 10000, (int) $campaign->value ) / 10000 ) : min( $eligible, (int) $campaign->value );
			if ( (int) ( $campaign->max_discount_minor ?? 0 ) > 0 ) { $discount = min( $discount, (int) $campaign->max_discount_minor ); }
			if ( $discount < 1 ) { return new \WP_Error( 'wpcap_campaign_no_match', __( 'Ein Kampagnencode passt nicht zu den ausgewählten Tickets.', 'pass-captain' ), array( 'status' => 409 ) ); }
			$left = $discount;
			foreach ( $selected as $index => $line ) {
				if ( ! $applies( $campaign, $line ) ) { continue; }
				$part = min( $remaining[ $index ], $left );
				$remaining[ $index ] -= $part;
				$left -= $part;
				if ( $left < 1 ) { break; }
			}
			$adjustments[] = array( 'campaign' => $campaign, 'amount_minor' => $discount );
		}
		$cap_bp = min( 10000, absint( Settings::get( 'max_total_discount_bp' ) ) );
		if ( $cap_bp > 0 && $adjustments ) {
			$base = 0;
			$rule_reduction = 0;
			foreach ( $selected as $line ) {
				$line_base = isset( $line['base_minor'] ) ? (int) $line['base_minor'] * (int) $line['quantity'] : (int) $line['line_total'];
				$base += $line_base;
				$rule_reduction += max( 0, $line_base - (int) $line['line_total'] );
			}
			$allowed = max( 0, intdiv( $base * $cap_bp, 10000 ) - $rule_reduction );
			$excess = array_sum( array_map( static function ( $a ) { return (int) $a['amount_minor']; }, $adjustments ) ) - $allowed;
			for ( $i = count( $adjustments ) - 1; $i >= 0 && $excess > 0; $i-- ) {
				$cut = min( $excess, (int) $adjustments[ $i ]['amount_minor'] );
				$adjustments[ $i ]['amount_minor'] -= $cut;
				$adjustments[ $i ]['capped'] = true;
				$excess -= $cut;
				if ( $adjustments[ $i ]['amount_minor'] < 1 ) {
					/* translators: %s: campaign code */
					return new \WP_Error( 'wpcap_campaign_max_discount', sprintf( __( 'Der Code %s kann nicht angewendet werden, weil der höchstmögliche Rabatt für diese Bestellung bereits erreicht ist.', 'pass-captain' ), $adjustments[ $i ]['campaign']->code ), array( 'status' => 409 ) );
				}
			}
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
			if ( false === $inserted ) { return new \WP_Error( 'wpcap_campaign_use_failed', __( 'Kampagnennutzung konnte nicht gespeichert werden.', 'pass-captain' ) ); }
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE $campaigns SET used_count=used_count+1 WHERE campaign_id=%d AND (usage_limit=0 OR used_count<usage_limit)", $campaign->campaign_id ) );
			if ( 1 !== $updated ) { return new \WP_Error( 'wpcap_campaign_exhausted', __( 'Das Kampagnenlimit wurde während des Checkouts erreicht.', 'pass-captain' ) ); }
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
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Kampagnencode, Währung oder Rabattwert ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$inserted = $wpdb->insert( $wpdb->prefix . 'wpcap_campaigns', array(
			'event_id' => absint( $data['event_id'] ?? 0 ), 'ticket_type_id' => absint( $data['ticket_type_id'] ?? 0 ), 'code' => $code,
			'name' => sanitize_text_field( $data['name'] ?? $code ), 'kind' => $kind, 'discount_value' => $value, 'currency' => $currency,
			'usage_limit' => absint( $data['usage_limit'] ?? 0 ), 'used_count' => 0, 'priority' => min( 1000, absint( $data['priority'] ?? 100 ) ),
			'starts_at' => sanitize_text_field( $data['starts_at'] ?? '' ) ?: null, 'ends_at' => sanitize_text_field( $data['ends_at'] ?? '' ) ?: null,
			'status' => 'active', 'created_at' => current_time( 'mysql', true ),
		) + self::stacking_values( $data ) );
		if ( false === $inserted ) { return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht angelegt werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		Audit::record( 'campaign_created', 'campaign', (int) $wpdb->insert_id, array( 'code' => $code, 'kind' => $kind ) );
		return array( 'campaign_id' => (int) $wpdb->insert_id, 'code' => $code );
	}

	/**
	 * Stacking columns from input (only keys that are given, so writes keep working before the
	 * schema upgrade): combinable, with_price_rules (0/1), max_discount_minor.
	 */
	private static function stacking_values( array $data, $campaign = null ) {
		$out = array();
		foreach ( array( 'combinable', 'with_price_rules' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) { $out[ $key ] = empty( $data[ $key ] ) ? 0 : 1; }
		}
		if ( array_key_exists( 'max_discount_minor', $data ) ) { $out['max_discount_minor'] = absint( $data['max_discount_minor'] ); }
		return $out;
	}

	public static function get( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE campaign_id=%d', $wpdb->prefix . 'wpcap_campaigns', absint( $campaign_id ) ) );
	}

	/**
	 * Updates a campaign. Code, kind and currency stay fixed once the code was used; the usage
	 * limit cannot drop below the uses so far (0 = unlimited).
	 *
	 * @return array|\WP_Error
	 */
	public static function update( $campaign_id, array $data ) {
		global $wpdb;
		$campaign = self::get( $campaign_id );
		if ( ! $campaign ) { return new \WP_Error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$used = (int) $campaign->used_count > 0 || (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE campaign_id=%d', $wpdb->prefix . 'wpcap_campaign_uses', (int) $campaign->campaign_id ) ) > 0;
		$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( sanitize_text_field( $data['code'] ?? $campaign->code ) ) ) );
		$kind = sanitize_key( $data['kind'] ?? $campaign->kind );
		$value = absint( $data['value'] ?? $campaign->discount_value );
		$currency = strtoupper( sanitize_text_field( $data['currency'] ?? $campaign->currency ) );
		if ( strlen( $code ) < 3 || strlen( $code ) > 32 || ! in_array( $kind, array( 'percent', 'fixed' ), true ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! $value || ( 'percent' === $kind && $value > 10000 ) ) {
			return new \WP_Error( 'wpcap_campaign_invalid', __( 'Kampagnencode, Währung oder Rabattwert ist ungültig.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( $used && ( $code !== $campaign->code || $kind !== $campaign->kind || $currency !== strtoupper( $campaign->currency ) ) ) {
			return new \WP_Error( 'wpcap_campaign_used', __( 'Code, Rabattart und Währung können nach der ersten Einlösung nicht mehr geändert werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$limit = absint( $data['usage_limit'] ?? $campaign->usage_limit );
		if ( $limit > 0 && $limit < (int) $campaign->used_count ) {
			return new \WP_Error( 'wpcap_campaign_limit_low', __( 'Das Nutzungslimit darf nicht unter der Zahl der bisherigen Einlösungen liegen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$starts = array_key_exists( 'starts_at', $data ) ? ( sanitize_text_field( (string) $data['starts_at'] ) ?: null ) : $campaign->starts_at;
		$ends = array_key_exists( 'ends_at', $data ) ? ( sanitize_text_field( (string) $data['ends_at'] ) ?: null ) : $campaign->ends_at;
		if ( $starts && $ends && $ends <= $starts ) {
			return new \WP_Error( 'wpcap_campaign_window', __( 'Das Ende des Gültigkeitszeitraums muss nach dem Beginn liegen.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		$status = in_array( (string) ( $data['status'] ?? $campaign->status ), array( 'active', 'inactive' ), true ) ? (string) ( $data['status'] ?? $campaign->status ) : $campaign->status;
		$values = array(
			'event_id' => absint( $data['event_id'] ?? $campaign->event_id ), 'ticket_type_id' => absint( $data['ticket_type_id'] ?? $campaign->ticket_type_id ), 'code' => $code,
			'name' => sanitize_text_field( $data['name'] ?? $campaign->name ) ?: $code, 'kind' => $kind, 'discount_value' => $value, 'currency' => $currency,
			'usage_limit' => $limit, 'priority' => min( 1000, absint( $data['priority'] ?? $campaign->priority ) ), 'starts_at' => $starts, 'ends_at' => $ends, 'status' => $status,
		) + self::stacking_values( $data, $campaign );
		$updated = $wpdb->update( $wpdb->prefix . 'wpcap_campaigns', $values, array( 'campaign_id' => (int) $campaign->campaign_id ) );
		if ( false === $updated ) { return new \WP_Error( 'wpcap_campaign_save', __( 'Kampagne konnte nicht gespeichert werden (Code bereits vergeben?).', 'pass-captain' ), array( 'status' => 409 ) ); }
		$changed = array();
		foreach ( $values as $key => $new ) {
			if ( (string) $new !== (string) $campaign->$key ) { $changed[ $key ] = $new; }
		}
		Audit::record( 'campaign_updated', 'campaign', (int) $campaign->campaign_id, array( 'changed' => $changed ) );
		return array( 'campaign_id' => (int) $campaign->campaign_id, 'code' => $code );
	}

	/** Activates or deactivates a campaign; inactive codes are rejected at checkout. */
	public static function set_status( $campaign_id, $status ) {
		global $wpdb;
		$campaign = self::get( $campaign_id );
		if ( ! $campaign ) { return new \WP_Error( 'wpcap_campaign_missing', __( 'Kampagne nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$status = 'active' === $status ? 'active' : 'inactive';
		$wpdb->update( $wpdb->prefix . 'wpcap_campaigns', array( 'status' => $status ), array( 'campaign_id' => (int) $campaign->campaign_id ), array( '%s' ), array( '%d' ) );
		Audit::record( 'active' === $status ? 'campaign_activated' : 'campaign_deactivated', 'campaign', (int) $campaign->campaign_id, array( 'code' => $campaign->code ) );
		return array( 'campaign_id' => (int) $campaign->campaign_id, 'status' => $status );
	}
}
