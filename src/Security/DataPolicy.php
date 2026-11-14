<?php
/**
 * Data minimisation per role (W9).
 *
 * Profiles are derived from the capabilities of the current user (roles can be customised, so the
 * capability set counts, not the role name):
 *  - full:       manage_options or wpcap_manage_events (organisers, ticket administration)
 *  - operations: order handling with check-in (box office) or refunds
 *  - support:    wpcap_manage_orders only – personal data masked; unmasking per order with a reason,
 *                time-limited (UNMASK_SECONDS) and audited
 *  - finance:    wpcap_view_finance without order handling – payment data yes, no attendee extras
 *  - partner:    wpcap_view_reports without order/finance access – no payment data
 *  - checkin:    admission only – no payment data, no attendee extras
 *
 * Payment data = amounts, payment provider/reference and billing details.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Security;

use PassCaptain\Audit;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DataPolicy {
	/** Lifetime of a per-case unmask (seconds). */
	const UNMASK_SECONDS = 900;

	/** Keys removed from rows for profiles without payment data. */
	const PAYMENT_KEYS = array( 'total_minor', 'subtotal_minor', 'discount_minor', 'tax_minor', 'refunded_minor', 'amount_minor', 'price_minor', 'unit_price_minor', 'line_total_minor', 'gross_minor', 'net_minor', 'revenue', 'revenue_tax', 'gross', 'net', 'tax', 'refunded', 'payment_provider', 'provider_reference', 'billing', 'price_snapshot', 'voucher_snapshot', 'currency_total' );

	/** Profile of the current user (filter wpcap_data_profile). */
	public static function profile() {
		$can = static function ( $cap ) { return current_user_can( $cap ); };
		if ( $can( 'manage_options' ) || $can( 'wpcap_manage_events' ) ) {
			$profile = 'full';
		} elseif ( $can( 'wpcap_manage_orders' ) && ( $can( 'wpcap_checkin' ) || $can( 'wpcap_refund' ) || $can( 'wpcap_view_finance' ) ) ) {
			$profile = 'operations';
		} elseif ( $can( 'wpcap_manage_orders' ) ) {
			$profile = 'support';
		} elseif ( $can( 'wpcap_view_finance' ) ) {
			$profile = 'finance';
		} elseif ( $can( 'wpcap_view_reports' ) ) {
			$profile = 'partner';
		} elseif ( $can( 'wpcap_checkin' ) ) {
			$profile = 'checkin';
		} else {
			$profile = 'none';
		}
		$filtered = apply_filters( 'wpcap_data_profile', $profile, get_current_user_id() );
		return in_array( $filtered, array( 'full', 'operations', 'support', 'finance', 'partner', 'checkin', 'none' ), true ) ? $filtered : $profile;
	}

	/** Amounts, provider references and billing data. */
	public static function can_view_payment() {
		return ! in_array( self::profile(), array( 'partner', 'checkin', 'none' ), true );
	}

	/** Participant answers and contact details beyond the name. */
	public static function can_view_attendee_extras() {
		return ! in_array( self::profile(), array( 'finance', 'partner', 'checkin', 'none' ), true );
	}

	/** Whether personal data of this order is shown masked to the current user. */
	public static function masks_pii( $order_id = 0 ) {
		if ( 'support' !== self::profile() || ! Policy::get( 'support_mask_pii' ) ) { return false; }
		return ! ( $order_id && self::is_unmasked( $order_id ) );
	}

	private static function unmask_key( $order_id ) {
		return 'wpcap_unmask_' . get_current_user_id() . '_' . absint( $order_id );
	}

	public static function is_unmasked( $order_id ) {
		return (bool) get_transient( self::unmask_key( $order_id ) );
	}

	/**
	 * Per-case unmask for support: requires access to the order and a reason; audited.
	 *
	 * @return array|\WP_Error
	 */
	public static function unmask( $order_id, $reason ) {
		$order_id = absint( $order_id );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		if ( ! $order_id || ! \PassCaptain\Admin\Queries::can_access_order( $order_id, 'wpcap_manage_orders' ) ) {
			return new \WP_Error( 'wpcap_unmask_forbidden', __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		if ( strlen( $reason ) < 5 ) {
			return new \WP_Error( 'wpcap_unmask_reason', __( 'Bitte einen Grund (z. B. Ticketnummer der Supportanfrage) angeben.', 'pass-captain' ), array( 'status' => 400 ) );
		}
		set_transient( self::unmask_key( $order_id ), 1, self::UNMASK_SECONDS );
		Audit::record( 'pii_unmasked', 'order', $order_id, array( 'reason' => substr( $reason, 0, 190 ), 'minutes' => (int) ( self::UNMASK_SECONDS / 60 ) ) );
		return array( 'order_id' => $order_id, 'expires_in' => self::UNMASK_SECONDS );
	}

	/** j•••@e•••.com */
	public static function mask_email( $email ) {
		$email = (string) $email;
		if ( '' === $email ) { return ''; }
		$at = strpos( $email, '@' );
		if ( false === $at ) { return self::mask_name( $email ); }
		$local = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$dot = strrpos( $domain, '.' );
		$tld = false === $dot ? '' : substr( $domain, $dot );
		return mb_substr( $local, 0, 1 ) . '•••@' . mb_substr( $domain, 0, 1 ) . '•••' . $tld;
	}

	/** "Max Mustermann" → "M. M•••" */
	public static function mask_name( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		if ( ! $parts || '' === $parts[0] ) { return ''; }
		$out = array();
		foreach ( $parts as $i => $part ) {
			$out[] = mb_substr( $part, 0, 1 ) . ( $i === count( $parts ) - 1 && $i > 0 ? '•••' : '.' );
		}
		return implode( ' ', $out );
	}

	/**
	 * Order row for display to the current user: masked contact/billing for support, no payment data
	 * for profiles without payment access.
	 *
	 * @param object $order Order row.
	 * @return object Copy.
	 */
	public static function order_view( $order ) {
		$order = clone $order;
		if ( self::masks_pii( (int) ( $order->order_id ?? 0 ) ) ) {
			$order->customer_email = self::mask_email( $order->customer_email ?? '' );
			if ( isset( $order->billing ) ) { $order->billing = null; }
			$order->pii_masked = true;
		}
		if ( ! self::can_view_payment() ) {
			foreach ( self::PAYMENT_KEYS as $key ) { unset( $order->$key ); }
		}
		return $order;
	}

	/**
	 * Ticket row (admin lists/details): attendee name/e-mail masked for support, answers removed for
	 * profiles without attendee extras.
	 *
	 * @param object $ticket   Ticket row.
	 * @param int    $order_id Order of the ticket (unmask scope).
	 */
	public static function ticket_view( $ticket, $order_id = 0 ) {
		$ticket = clone $ticket;
		$order_id = $order_id ? $order_id : (int) ( $ticket->order_id ?? 0 );
		if ( self::masks_pii( $order_id ) ) {
			foreach ( array( 'attendee_email', 'assigned_email', 'customer_email' ) as $key ) {
				if ( isset( $ticket->$key ) ) { $ticket->$key = self::mask_email( $ticket->$key ); }
			}
			if ( isset( $ticket->attendee_name ) ) { $ticket->attendee_name = self::mask_name( $ticket->attendee_name ); }
			if ( isset( $ticket->answers ) ) { $ticket->answers = array(); }
		}
		if ( ! self::can_view_attendee_extras() ) {
			if ( isset( $ticket->answers ) ) { $ticket->answers = array(); }
			foreach ( array( 'attendee_email', 'assigned_email' ) as $key ) { unset( $ticket->$key ); }
			if ( isset( $ticket->customer_email ) ) { $ticket->customer_email = self::mask_email( $ticket->customer_email ); }
		}
		return $ticket;
	}

	/**
	 * Removes payment keys from a (nested) array for profiles without payment access.
	 *
	 * @param array $data Data.
	 */
	public static function strip_payment( array $data ) {
		if ( self::can_view_payment() ) { return $data; }
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::PAYMENT_KEYS, true ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::strip_payment( $value );
			}
		}
		return $data;
	}
}
