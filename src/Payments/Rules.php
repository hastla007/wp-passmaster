<?php
/**
 * Payment method rules (SET-PC-40): limit a payment method by event, order amount and billing country.
 *
 * Setting payment_rules, one rule per line:
 *   <provider> | events=12,15 | min=10,00 | max=500 | countries=DE,AT
 * Every condition is optional; a method without a rule is allowed everywhere. Several lines for the same
 * method are alternatives (one matching line allows it). Amounts are in the order currency (major units).
 * The event card hides methods that cannot apply to its event; the checkout checks the finished order
 * (amount, events, billing country) and refuses a method whose rules do not match.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Payments;

use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rules {
	/**
	 * Parses the setting text.
	 *
	 * @param string $text Setting value.
	 * @return array<string, array[]>|\WP_Error provider => list of rules {events[], min, max, countries[]}.
	 */
	public static function parse( $text ) {
		$rules = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) { continue; }
			$parts = array_map( 'trim', explode( '|', $line ) );
			$provider = sanitize_key( array_shift( $parts ) );
			if ( '' === $provider ) { return self::error( $n ); }
			$rule = array( 'events' => array(), 'min' => null, 'max' => null, 'countries' => array() );
			foreach ( $parts as $part ) {
				if ( '' === $part ) { continue; }
				if ( ! preg_match( '/^(events|min|max|countries)\s*=\s*(.+)$/i', $part, $m ) ) { return self::error( $n ); }
				$key = strtolower( $m[1] );
				$value = trim( $m[2] );
				if ( 'events' === $key ) {
					$rule['events'] = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', $value ) ) ) );
				} elseif ( 'countries' === $key ) {
					$rule['countries'] = array_values( array_filter( array_map( static function ( $c ) { $c = strtoupper( trim( $c ) ); return preg_match( '/^[A-Z]{2}$/', $c ) ? $c : ''; }, preg_split( '/[\s,;]+/', $value ) ) ) );
				} else {
					$minor = \PassCaptain\Admin\Forms::parse_money( $value, 2 );
					if ( null === $minor ) { return self::error( $n ); }
					$rule[ $key ] = (int) $minor;
				}
			}
			$rules[ $provider ][] = $rule;
		}
		return $rules;
	}

	private static function error( $line_index ) {
		/* translators: %d: line number */
		return new \WP_Error( 'wpcap_payment_rules', sprintf( __( 'Zahlungsart-Regeln: Zeile %d ist nicht lesbar. Format: Zahlungsart | events=1,2 | min=10,00 | max=500 | countries=DE,AT', 'pass-captain' ), (int) $line_index + 1 ) );
	}

	/** Settings screen: an unreadable line is reported, the stored rules stay (sanitize callback). */
	public static function sanitize_setting( $value ) {
		$parsed = self::parse( (string) $value );
		return is_wp_error( $parsed ) ? $parsed : (string) $value;
	}

	/** Parsed rules of the setting (invalid text = no rules). */
	public static function all() {
		$rules = self::parse( (string) Settings::get( 'payment_rules', '' ) );
		$rules = is_wp_error( $rules ) ? array() : $rules;
		/**
		 * Payment method rules (SET-PC-40).
		 *
		 * @param array $rules provider => list of {events, min, max, countries}.
		 */
		return (array) apply_filters( 'wp_pass_captain_payment_rules_v1', $rules );
	}

	/**
	 * Whether a payment method may be used.
	 *
	 * @param string $provider Payment method id.
	 * @param array  $context  event_ids (int[]), total_minor (int|null), country (string|'') – unknown values are not checked.
	 */
	public static function allows( $provider, array $context ) {
		$rules = self::all();
		if ( empty( $rules[ $provider ] ) ) { return true; }
		$events = array_map( 'intval', (array) ( $context['event_ids'] ?? array() ) );
		$total = isset( $context['total_minor'] ) ? (int) $context['total_minor'] : null;
		$country = strtoupper( (string) ( $context['country'] ?? '' ) );
		foreach ( $rules[ $provider ] as $rule ) {
			if ( $rule['events'] && $events && array_diff( $events, $rule['events'] ) ) { continue; }
			if ( null !== $total && null !== $rule['min'] && $total < $rule['min'] ) { continue; }
			if ( null !== $total && null !== $rule['max'] && $total > $rule['max'] ) { continue; }
			if ( $rule['countries'] && '' !== $country && ! in_array( $country, $rule['countries'], true ) ) { continue; }
			return true;
		}
		return false;
	}

	/** Filters a list of gateways (id => gateway) by the rules. */
	public static function filter( array $gateways, array $context ) {
		foreach ( array_keys( $gateways ) as $id ) {
			if ( ! self::allows( (string) $id, $context ) ) { unset( $gateways[ $id ] ); }
		}
		return $gateways;
	}

	/** Context of a stored order: events of its lines, total and billing country. */
	public static function order_context( $order_id ) {
		global $wpdb;
		$order = \PassCaptain\Orders::get( (int) $order_id );
		if ( ! $order ) { return array(); }
		$events = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT o.event_id FROM {$wpdb->prefix}wpcap_order_items i JOIN {$wpdb->prefix}wpcap_ticket_types t ON t.ticket_type_id=i.ticket_type_id JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=t.occurrence_id WHERE i.order_id=%d", (int) $order_id ) ) );
		$billing = \PassCaptain\Billing::for_order( $order );
		return array( 'event_ids' => $events, 'total_minor' => (int) $order->total_minor, 'country' => (string) ( $billing['country'] ?? '' ) );
	}
}
