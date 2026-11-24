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

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class BankTransfer {
	/** Whether the order still waits for a manual payment. */
	public static function applies( $order ) {
		$order = (object) $order;
		return 'manual' === (string) ( $order->payment_provider ?? '' ) && 'pending' === (string) ( $order->payment_status ?? '' ) && (int) ( $order->total_minor ?? 0 ) > 0;
	}

	/** Account holder shown to the buyer: bank_recipient, else the seller name ('' when neither is set). */
	public static function recipient() {
		$recipient = trim( (string) Settings::get( 'bank_recipient' ) );
		return '' !== $recipient ? $recipient : trim( (string) Settings::get( 'seller_name' ) );
	}

	/** Whether a complete bank account (IBAN and recipient) is configured; only then a transfer is requested (A265). */
	public static function has_account() {
		return '' !== self::format_iban( Settings::get( 'bank_iban' ) ) && '' !== self::recipient();
	}

	/** IBAN in groups of four ("AT61 1904 3002 3457 3201"). */
	public static function format_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
		return trim( chunk_split( $iban, 4, ' ' ) );
	}

	/** End of the reservation (UTC, Y-m-d H:i:s) or '' when unknown. */
	/** A608: time zone of the (first) occurrence of an order ('' when none). */
	public static function order_timezone( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT o.timezone FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE oi.order_id=%d ORDER BY o.starts_at LIMIT 1", absint( $order_id ) ) );
	}

	public static function due_at( $order_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(expires_at) FROM %i WHERE order_id=%d AND status='active'", $wpdb->prefix . 'wpcap_reservations', absint( $order_id ) ) );
	}

	/**
	 * Details for one order (object or array row of wpcap_orders).
	 *
	 * Without a complete bank account (has_account()) recipient, IBAN, BIC and remittance are empty
	 * and 'transfer' is false: the buyer then only sees amount, due date and the instruction text,
	 * never a transfer request without recipient and IBAN (A265).
	 *
	 * @return array reference, remittance, amount_minor, currency, amount, recipient, iban, bic, due_at, due_date, instructions, transfer.
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
			'recipient' => self::recipient(),
			'iban' => self::format_iban( Settings::get( 'bank_iban' ) ),
			'bic' => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) Settings::get( 'bank_bic' ) ) ),
			'due_at' => $due_at,
			// A608: in the event's time zone with the zone named, like the event date of the mail.
			'due_date' => '' !== $due_at ? Format::deadline( $due_at, self::order_timezone( (int) ( $order->order_id ?? 0 ) ) ) : '',
			'instructions' => '' !== $instructions ? $instructions : __( 'Die Zahlung wird vom Veranstalter bestätigt. Danach erhältst du deine Tickets per E-Mail.', 'pass-captain' ),
			'transfer' => true,
		);
		if ( ! self::has_account() ) {
			$details = array_merge( $details, array( 'recipient' => '', 'iban' => '', 'bic' => '', 'remittance' => '', 'transfer' => false ) );
		}
		$details = (array) apply_filters( 'wpcap_bank_transfer_details', $details, $order );
		// A filter may supply or remove the account; a transfer request always needs recipient and IBAN.
		$details['transfer'] = '' !== trim( (string) ( $details['iban'] ?? '' ) ) && '' !== trim( (string) ( $details['recipient'] ?? '' ) );
		if ( ! $details['transfer'] ) {
			$details = array_merge( $details, array( 'recipient' => '', 'iban' => '', 'bic' => '', 'remittance' => '' ) );
		}
		// A601: GiroCode (EPC QR) for banking apps; only SEPA credit transfers in EUR.
		$payload = $details['transfer'] ? self::epc_payload( $details ) : '';
		$svg = '' !== $payload && (bool) apply_filters( 'wpcap_bank_girocode', true, $order ) ? \PassCaptain\QrCode::svg( $payload, 180 ) : '';
		$details['girocode'] = '' !== $svg ? 'data:image/svg+xml,' . rawurlencode( $svg ) : '';
		return $details;
	}

	/**
	 * EPC069-12 payload ("GiroCode", SEPA credit transfer) or '' when the transfer is not in EUR or the
	 * amount is outside 0.01–999 999 999.99. Name max. 70, remittance max. 140 characters (EPC limits).
	 */
	public static function epc_payload( array $details ) {
		if ( 'EUR' !== strtoupper( (string) ( $details['currency'] ?? '' ) ) ) { return ''; }
		$minor = (int) ( $details['amount_minor'] ?? 0 );
		$iban = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $details['iban'] ?? '' ) ) );
		$name = trim( preg_replace( '/[\r\n]+/', ' ', (string) ( $details['recipient'] ?? '' ) ) );
		if ( $minor < 1 || $minor > 99999999999 || '' === $iban || '' === $name ) { return ''; }
		$cut = static function ( $text, $max ) { return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max ); };
		return implode( "\n", array(
			'BCD', '002', '1', 'SCT',
			strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $details['bic'] ?? '' ) ) ),
			$cut( $name, 70 ),
			$iban,
			'EUR' . number_format( $minor / 100, 2, '.', '' ),
			'',
			'',
			$cut( trim( preg_replace( '/[\r\n]+/', ' ', (string) ( $details['remittance'] ?? '' ) ) ), 140 ),
		) );
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
