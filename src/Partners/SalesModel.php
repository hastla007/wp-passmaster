<?php
/**
 * Sales models per organizer ("Verkaufsmodell"):
 *
 *  - own_name (Eigenhändler / Leistungskommission, default): the platform sells the tickets in its own
 *    name. Customer invoices are issued by the platform; the organizer is settled with a self-billing
 *    document ("Gutschrift") for its supply to the platform plus a separate platform fee invoice.
 *  - agent (Vermittler): the platform sells in the name and for the account of the organizer. Customer
 *    invoices are issued in the organizer's name and number range ("Vermittelt durch …"); the platform
 *    only invoices its commission/fees to the organizer ("Provisionsrechnung").
 *
 * Events without an organizer always use own_name (platform as seller). One order must not mix
 * different sellers (an agent organizer with other organizers or platform events): the checkout refuses
 * such orders and Invoices::issue_for_order() refuses to invoice them. Legal background: docs/SALES-MODELS.md.
 */
namespace PassCaptain\Partners;

use PassCaptain\Invoices;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SalesModel {
	const OWN_NAME = 'own_name';
	const AGENT = 'agent';
	const MODELS = array( self::OWN_NAME, self::AGENT );
	/** Platform-level model for events without an organizer. */
	const PLATFORM_DEFAULT = self::OWN_NAME;

	/** EU member states (ISO 3166-1 alpha-2). VAT IDs of Greece use the prefix EL. */
	const EU_COUNTRIES = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	public static function labels() {
		return array(
			self::OWN_NAME => __( 'Eigenhändler (Verkauf im eigenen Namen, Gutschrift an Veranstalter)', 'wp-pass-captain' ),
			self::AGENT => __( 'Vermittler (Verkauf im Namen des Veranstalters, Provisionsrechnung)', 'wp-pass-captain' ),
		);
	}

	public static function short_labels() {
		return array( self::OWN_NAME => __( 'Eigenhändler', 'wp-pass-captain' ), self::AGENT => __( 'Vermittler', 'wp-pass-captain' ) );
	}

	/** Model of a partner row (unknown/missing = own_name). */
	public static function model_of( $partner ) {
		$model = is_object( $partner ) ? (string) ( $partner->sales_model ?? '' ) : '';
		return in_array( $model, self::MODELS, true ) ? $model : self::OWN_NAME;
	}

	/** Default note for small businesses (DE § 19 UStG); organizers may enter their own text (e.g. AT § 6 Abs. 1 Z 27 UStG). */
	public static function default_small_business_note() {
		return __( 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet (Kleinunternehmerregelung).', 'wp-pass-captain' );
	}

	public static function reverse_charge_note() {
		return __( 'Steuerschuldnerschaft des Leistungsempfängers / Reverse Charge', 'wp-pass-captain' );
	}

	/** Two-letter country code or ''. */
	public static function country( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		return 2 === strlen( $value ) ? $value : '';
	}

	/** VAT ID without blanks/dots, upper case. */
	public static function normalize_vat_id( $vat_id ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $vat_id ) );
	}

	/**
	 * Formal plausibility of an EU VAT ID for the given country (prefix + 2–12 characters). This is no
	 * validity check: the operator must confirm VAT IDs via BZSt/VIES (see docs/SALES-MODELS.md).
	 */
	public static function vat_id_plausible( $vat_id, $country ) {
		$vat_id = self::normalize_vat_id( $vat_id );
		$country = self::country( $country );
		if ( '' === $vat_id || ! in_array( $country, self::EU_COUNTRIES, true ) ) { return false; }
		$prefix = 'GR' === $country ? 'EL' : $country;
		return 0 === strpos( $vat_id, $prefix ) && (bool) preg_match( '/^[A-Z]{2}[A-Z0-9]{2,12}$/', $vat_id );
	}

	/**
	 * Reverse charge for a B2B service between two EU countries: supplier and recipient are established
	 * in different EU member states and the recipient has a (formally plausible) VAT ID of its country.
	 * (§ 13b Abs. 1, Abs. 5 DE UStG / Art. 196 MwStSystRL / § 19 Abs. 1 AT UStG.)
	 */
	public static function reverse_charge( $supplier_country, $recipient_country, $recipient_vat_id ) {
		$supplier_country = self::country( $supplier_country );
		$recipient_country = self::country( $recipient_country );
		if ( '' === $supplier_country || '' === $recipient_country || $supplier_country === $recipient_country ) { return false; }
		if ( ! in_array( $supplier_country, self::EU_COUNTRIES, true ) ) { return false; }
		return self::vat_id_plausible( $recipient_vat_id, $recipient_country );
	}

	/** Platform seller data (settings "Rechnungssteller"); country defaults to DE. */
	public static function platform_seller() {
		$seller = Invoices::seller();
		if ( '' === $seller['country'] ) { $seller['country'] = 'DE'; }
		return $seller;
	}

	/** Country of a partner (empty = same country as the platform). */
	public static function partner_country( $partner ) {
		$country = self::country( $partner->country ?? '' );
		return '' !== $country ? $country : self::platform_seller()['country'];
	}

	/** Organizer as supplier (seller block on agent invoices and self-billing documents). */
	public static function organizer_seller( $partner ) {
		$legal = trim( (string) ( $partner->legal_name ?? '' ) );
		$small = ! empty( $partner->small_business );
		$note = trim( (string) ( $partner->small_business_note ?? '' ) );
		return array(
			'name' => '' !== $legal ? $legal : (string) $partner->name,
			'address' => (string) $partner->billing_address,
			'vat_id' => (string) $partner->vat_id,
			'tax_number' => (string) ( $partner->tax_number ?? '' ),
			'country' => self::partner_country( $partner ),
			'small_business' => $small,
			'small_business_note' => $small ? ( '' !== $note ? $note : self::default_small_business_note() ) : '',
			'partner_id' => (int) $partner->partner_id,
		);
	}

	/** Labels of the seller fields an organizer still lacks to act as supplier on invoices/Gutschriften. */
	public static function missing_supplier_fields( $partner ) {
		$missing = array();
		if ( '' === trim( (string) $partner->billing_address ) ) { $missing[] = __( 'Rechnungsanschrift', 'wp-pass-captain' ); }
		if ( '' === trim( (string) $partner->vat_id ) && '' === trim( (string) ( $partner->tax_number ?? '' ) ) ) { $missing[] = __( 'USt-IdNr. oder Steuernummer', 'wp-pass-captain' ); }
		return $missing;
	}

	/** Invoice number prefix of an organizer (letters/digits only; default "V{id}"). */
	public static function invoice_prefix( $partner ) {
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $partner->invoice_prefix ?? '' ) );
		return '' !== $prefix ? $prefix : 'V' . (int) $partner->partner_id;
	}

	public static function invoice_series( $partner_id ) {
		return 'invoice-org-' . absint( $partner_id );
	}

	public static function credit_series( $partner_id ) {
		return 'credit-org-' . absint( $partner_id );
	}

	/**
	 * Seller context of an order: who issues the customer invoice and in which number range.
	 *
	 * @return array{model:string,partner_id:int,seller:array,agent:array|null,series:string,prefix:string,credit_series:string,credit_prefix:string}|\WP_Error
	 */
	public static function order_context( $order_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT o.event_id, COALESCE(pe.partner_id,0) AS partner_id FROM {$p}wpcap_order_items oi INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_partner_events pe ON pe.event_id=o.event_id WHERE oi.order_id=%d", absint( $order_id ) ) );
		$sellers = array();
		$agents = array();
		foreach ( (array) $rows as $row ) {
			$partner = (int) $row->partner_id ? Repository::get( (int) $row->partner_id ) : null;
			if ( $partner && self::AGENT === self::model_of( $partner ) ) {
				$sellers[ 'org-' . (int) $partner->partner_id ] = true;
				$agents[ (int) $partner->partner_id ] = $partner;
			} else {
				$sellers['platform'] = true;
			}
		}
		if ( count( $sellers ) > 1 ) {
			$names = array_map( static function ( $pa ) { return (string) $pa->name; }, $agents );
			return new \WP_Error( 'wpcap_invoice_mixed_sellers', sprintf(
				/* translators: %s: organizer names */
				__( 'Diese Bestellung enthält Tickets verschiedener Rechnungssteller (Vermittler-Veranstalter: %s, dazu weitere Veranstalter oder Plattform-Events). Eine gemeinsame Rechnung ist nicht zulässig; bitte die Rechnungen außerhalb des Plugins je Veranstalter erstellen oder die Bestellung stornieren und getrennt neu anlegen.', 'wp-pass-captain' ),
				implode( ', ', $names )
			), array( 'status' => 409, 'partner_ids' => array_keys( $agents ) ) );
		}
		$platform = self::platform_seller();
		if ( ! $agents ) {
			return array( 'model' => self::OWN_NAME, 'partner_id' => 0, 'seller' => $platform, 'agent' => null, 'series' => '', 'prefix' => '', 'credit_series' => 'credit', 'credit_prefix' => (string) Settings::get( 'credit_note_prefix' ) ?: 'GS' );
		}
		$partner = reset( $agents );
		$prefix = self::invoice_prefix( $partner );
		return array(
			'model' => self::AGENT,
			'partner_id' => (int) $partner->partner_id,
			'seller' => self::organizer_seller( $partner ),
			'agent' => array( 'name' => $platform['name'], 'address' => $platform['address'], 'vat_id' => $platform['vat_id'] ),
			'series' => self::invoice_series( $partner->partner_id ),
			'prefix' => $prefix,
			'credit_series' => self::credit_series( $partner->partner_id ),
			'credit_prefix' => $prefix . '-K',
			'partner' => $partner,
		);
	}

	/**
	 * Checks before an agent invoice is issued (organizer data complete, no VAT on lines of a small business).
	 *
	 * @param object[] $items Order items (tax_minor).
	 * @return true|\WP_Error
	 */
	public static function validate_agent_invoice( array $context, array $items ) {
		if ( self::AGENT !== $context['model'] ) { return true; }
		$missing = self::missing_supplier_fields( $context['partner'] );
		if ( $missing ) {
			return new \WP_Error( 'wpcap_invoice_organizer_incomplete', sprintf(
				/* translators: 1: organizer, 2: missing fields */
				__( 'Rechnung im Namen des Veranstalters „%1$s“ nicht möglich – es fehlen: %2$s. Bitte unter Veranstalter ergänzen.', 'wp-pass-captain' ),
				$context['seller']['name'], implode( ', ', $missing )
			), array( 'status' => 409 ) );
		}
		if ( ! empty( $context['seller']['small_business'] ) ) {
			foreach ( $items as $item ) {
				if ( (int) $item->tax_minor > 0 ) {
					return new \WP_Error( 'wpcap_invoice_small_business_vat', sprintf(
						/* translators: %s: organizer */
						__( 'Der Veranstalter „%s“ ist Kleinunternehmer, die Bestellung enthält aber Umsatzsteuer. Ein Steuerausweis wäre unzulässig (§ 14c UStG). Bitte die Ticketarten mit 0 % anlegen.', 'wp-pass-captain' ),
						$context['seller']['name']
					), array( 'status' => 409 ) );
				}
			}
		}
		return true;
	}

	/**
	 * Checkout guard (action wpcap_order_creating, inside the order transaction): refuses orders that
	 * would need invoices from different sellers. Disable with the filter wpcap_refuse_mixed_seller_orders.
	 */
	public static function guard_checkout( $order_id ) {
		if ( ! apply_filters( 'wpcap_refuse_mixed_seller_orders', true, (int) $order_id ) ) { return; }
		$context = self::order_context( $order_id );
		if ( is_wp_error( $context ) ) { throw new \RuntimeException( 'mixed_sellers' ); }
	}

	public static function checkout_messages( $messages ) {
		$messages = (array) $messages;
		$messages['mixed_sellers'] = __( 'Tickets dieser Veranstalter können nicht in einer gemeinsamen Bestellung gekauft werden. Bitte für jeden Veranstalter separat bestellen.', 'wp-pass-captain' );
		return $messages;
	}
}
