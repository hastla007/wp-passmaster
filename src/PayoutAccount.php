<?php
/**
 * Bank account for refunds of orders paid by bank transfer (manual payment, box office invoice):
 * the money has to be transferred back by the operator, so the buyer can leave an IBAN in the order
 * portal. Stored on the order (schema 3.8.2, column payout_account: JSON iban/holder/updated_at);
 * exported and erased with the order's personal data.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PayoutAccount {
	/** Payment providers whose refunds are paid out by bank transfer. */
	const PROVIDERS = array( 'manual', 'pos_invoice' );

	/** Whether refunds of the order are paid out by transfer (an IBAN is needed). */
	public static function applies( $order ) {
		$order = (object) $order;
		return in_array( (string) ( $order->payment_provider ?? '' ), self::PROVIDERS, true );
	}

	/** Whether the column exists (deferred upgrade may be pending). */
	public static function ready() {
		$version = strtok( (string) get_option( 'wpcap_db_version', '' ), '+' );
		return false !== $version && '' !== $version && version_compare( $version, '3.8.2', '>=' );
	}

	/** Stored account of an order or null: ['iban' => …, 'holder' => …, 'updated_at' => …]. */
	public static function get( $order ) {
		$raw = is_object( $order ) ? ( $order->payout_account ?? null ) : null;
		if ( null === $raw && is_object( $order ) && ! property_exists( $order, 'payout_account' ) && self::ready() ) {
			global $wpdb;
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT payout_account FROM {$wpdb->prefix}wpcap_orders WHERE order_id=%d", (int) $order->order_id ) );
		}
		$data = $raw ? json_decode( (string) $raw, true ) : null;
		return is_array( $data ) && ! empty( $data['iban'] ) ? $data : null;
	}

	/** Normalised IBAN (uppercase, no spaces) or '' when the checksum (ISO 13616, mod 97) fails. */
	public static function normalize_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban ) ) { return ''; }
		$moved = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$digits = '';
		foreach ( str_split( $moved ) as $char ) { $digits .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char; }
		$rest = 0;
		foreach ( str_split( $digits, 7 ) as $chunk ) { $rest = (int) ( (string) $rest . $chunk ) % 97; }
		return 1 === $rest ? $iban : '';
	}

	/** IBAN with only the last four characters visible ("AT61 •••• •••• 3201"). */
	public static function mask( $iban ) {
		$iban = (string) $iban;
		if ( strlen( $iban ) < 8 ) { return $iban; }
		return substr( $iban, 0, 4 ) . ' •••• •••• ' . substr( $iban, -4 );
	}

	/**
	 * Saves (or with an empty IBAN removes) the account of an order whose access token was verified.
	 *
	 * @return array|\WP_Error ['saved' => bool, 'iban_masked' => string]
	 */
	public static function save( $order, $iban, $holder ) {
		global $wpdb;
		if ( ! self::ready() ) { return new \WP_Error( 'wpcap_payout_unavailable', __( 'Die Bankverbindung kann gerade nicht gespeichert werden. Bitte später erneut versuchen.', 'pass-captain' ), array( 'status' => 503 ) ); }
		if ( ! self::applies( $order ) ) { return new \WP_Error( 'wpcap_payout_not_needed', __( 'Für diese Bestellung wird keine Bankverbindung benötigt.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$holder = mb_substr( sanitize_text_field( (string) $holder ), 0, 190 );
		if ( '' === trim( (string) $iban ) ) {
			$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payout_account' => null ), array( 'order_id' => (int) $order->order_id ) );
			Audit::record( 'payout_account_removed', 'order', (int) $order->order_id );
			return array( 'saved' => false, 'iban_masked' => '' );
		}
		$normalized = self::normalize_iban( $iban );
		if ( '' === $normalized ) { return new \WP_Error( 'wpcap_payout_iban', __( 'Die IBAN ist ungültig.', 'pass-captain' ), array( 'status' => 400, 'field' => 'iban' ) ); }
		if ( '' === $holder ) { return new \WP_Error( 'wpcap_payout_holder', __( 'Bitte den Namen des Kontoinhabers angeben.', 'pass-captain' ), array( 'status' => 400, 'field' => 'holder' ) ); }
		$data = array( 'iban' => $normalized, 'holder' => $holder, 'updated_at' => current_time( 'mysql', true ) );
		$ok = $wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'payout_account' => wp_json_encode( $data ) ), array( 'order_id' => (int) $order->order_id ) );
		if ( false === $ok ) { return new \WP_Error( 'wpcap_payout_save', __( 'Die Bankverbindung konnte nicht gespeichert werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		Audit::record( 'payout_account_saved', 'order', (int) $order->order_id );
		return array( 'saved' => true, 'iban_masked' => self::mask( $normalized ) );
	}

	/**
	 * Refund mail: how the money reaches the buyer, per payment provider (text in the recipient's
	 * locale). Card/wallet providers: "may take a few days"; cash at the counter: paid out;
	 * bank transfer: the masked IBAN or a request to leave one in the order overview.
	 */
	public static function refund_note( $order ) {
		$provider = (string) ( ( (object) $order )->payment_provider ?? '' );
		if ( 'pos_cash' === $provider ) { return __( 'Der Betrag wurde dir an der Kasse bar ausgezahlt.', 'pass-captain' ); }
		if ( 'pos_card' === $provider ) { return __( 'Der Betrag wird auf die Karte zurückgebucht, mit der du bezahlt hast.', 'pass-captain' ); }
		if ( 'pos_free' === $provider ) { return ''; }
		if ( self::applies( $order ) ) {
			$account = self::get( (object) $order );
			/* translators: %s: masked IBAN */
			return $account ? sprintf( __( 'Wir überweisen den Betrag in den nächsten Tagen auf dein Konto %s.', 'pass-captain' ), self::mask( $account['iban'] ) ) : __( 'Bitte gib in deiner Bestellübersicht (Link aus der Bestellbestätigung) deine IBAN an oder antworte auf diese E-Mail damit, damit wir den Betrag überweisen können.', 'pass-captain' );
		}
		return __( 'Je nach Zahlungsart kann es einige Tage dauern, bis der Betrag gutgeschrieben ist.', 'pass-captain' );
	}

	/** First sentence of the refund mail: "erstattet" only when the money is already on its way. */
	public static function refund_intro( $order, $reference, $amount ) {
		if ( self::applies( $order ) ) {
			/* translators: 1: amount, 2: order number */
			return sprintf( __( 'Für deine Bestellung %2$s erstatten wir dir %1$s per Überweisung.', 'pass-captain' ), $amount, $reference );
		}
		/* translators: 1: amount, 2: order number */
		return sprintf( __( 'Für deine Bestellung %2$s haben wir %1$s erstattet.', 'pass-captain' ), $amount, $reference );
	}
}
