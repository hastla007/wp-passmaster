<?php
/**
 * Payment details shown to the buyer for a pending manual payment (bank transfer): amount, bank
 * account from the settings, a short reference and the date the reservation ends. Used by the
 * checkout response, the access-link mail ({payment_instructions}) and the order portal.
 */
namespace PassCaptain\Payments;

use PassCaptain\Frontend\Format;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BankTransfer {
	/** Whether the order still waits for a manual payment. */
	public static function applies( $order ) {
		$order = (object) $order;
		return 'manual' === (string) ( $order->payment_provider ?? '' ) && 'pending' === (string) ( $order->payment_status ?? '' ) && (int) ( $order->total_minor ?? 0 ) > 0;
	}

	/** IBAN in groups of four ("AT61 1904 3002 3457 3201"). */
	public static function format_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
		return trim( chunk_split( $iban, 4, ' ' ) );
	}

	/** End of the reservation (UTC, Y-m-d H:i:s) or '' when unknown. */
	public static function due_at( $order_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM {$wpdb->prefix}wpcap_reservations WHERE order_id=%d AND status='active'", absint( $order_id ) ) );
	}

	/**
	 * Details for one order (object or array row of wpcap_orders).
	 *
	 * @return array reference, remittance, amount_minor, currency, amount, recipient, iban, bic, due_at, due_date, instructions.
	 */
	public static function details( $order ) {
		$order = (object) $order;
		$reference = Format::order_reference( $order->public_id ?? '' );
		$due_at = self::due_at( (int) ( $order->order_id ?? 0 ) );
		$instructions = trim( (string) Settings::get( 'manual_instructions' ) );
		$details = array(
			'reference' => $reference,
			/* translators: %s: short order number */
			'remittance' => sprintf( __( 'Bestellung %s', 'pass-captain' ), $reference ),
			'amount_minor' => (int) ( $order->total_minor ?? 0 ),
			'currency' => (string) ( $order->currency ?? '' ),
			'amount' => Format::money( (int) ( $order->total_minor ?? 0 ), (string) ( $order->currency ?? '' ) ),
			'recipient' => trim( (string) Settings::get( 'bank_recipient' ) ) ?: trim( (string) Settings::get( 'seller_name' ) ),
			'iban' => self::format_iban( Settings::get( 'bank_iban' ) ),
			'bic' => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) Settings::get( 'bank_bic' ) ) ),
			'due_at' => $due_at,
			'due_date' => '' !== $due_at ? Format::site_datetime( $due_at ) : '',
			'instructions' => '' !== $instructions ? $instructions : __( 'Die Zahlung wird vom Veranstalter bestätigt. Danach erhältst du deine Tickets per E-Mail.', 'pass-captain' ),
		);
		if ( '' === $details['iban'] ) { $details['recipient'] = ''; $details['bic'] = ''; }
		return (array) apply_filters( 'wpcap_bank_transfer_details', $details, $order );
	}

	/** Label => value rows in display order (empty values are skipped). */
	public static function rows( array $details ) {
		$rows = array(
			__( 'Betrag', 'pass-captain' ) => $details['amount'] ?? '',
			__( 'Empfänger', 'pass-captain' ) => $details['recipient'] ?? '',
			__( 'IBAN', 'pass-captain' ) => $details['iban'] ?? '',
			__( 'BIC', 'pass-captain' ) => $details['bic'] ?? '',
			__( 'Verwendungszweck', 'pass-captain' ) => $details['remittance'] ?? '',
			__( 'Zahlbar bis', 'pass-captain' ) => $details['due_date'] ?? '',
		);
		return array_filter( $rows, static function ( $value ) { return '' !== (string) $value; } );
	}
}
