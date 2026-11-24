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

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
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
			self::OWN_NAME => __( 'Eigenhändler (Verkauf im eigenen Namen, Gutschrift an Veranstalter)', 'pass-captain' ),
			self::AGENT => __( 'Vermittler (Verkauf im Namen des Veranstalters, Provisionsrechnung)', 'pass-captain' ),
		);
	}

	public static function short_labels() {
		return array( self::OWN_NAME => __( 'Eigenhändler', 'pass-captain' ), self::AGENT => __( 'Vermittler', 'pass-captain' ) );
	}

	/** Model of a partner row (unknown/missing = own_name). */
	public static function model_of( $partner ) {
		$model = is_object( $partner ) ? (string) ( $partner->sales_model ?? '' ) : '';
		return in_array( $model, self::MODELS, true ) ? $model : self::OWN_NAME;
	}

	/** Default note for small businesses by country (DE § 19 UStG, AT § 6 Abs. 1 Z 27 UStG); an own text always wins. */
	public static function default_small_business_note( $country = 'DE' ) {
		if ( 'AT' === self::country( $country ) ) {
			return __( 'Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gemäß § 6 Abs. 1 Z 27 UStG.', 'pass-captain' );
		}
		return __( 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet (Kleinunternehmerregelung).', 'pass-captain' );
	}

	public static function reverse_charge_note() {
		return __( 'Steuerschuldnerschaft des Leistungsempfängers / Reverse Charge', 'pass-captain' );
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
			'small_business_note' => $small ? ( '' !== $note ? $note : self::default_small_business_note( self::partner_country( $partner ) ) ) : '',
			'partner_id' => (int) $partner->partner_id,
		);
	}

	/** Labels of the seller fields an organizer still lacks to act as supplier on invoices/Gutschriften. */
	public static function missing_supplier_fields( $partner ) {
		$missing = array();
		if ( '' === trim( (string) $partner->billing_address ) ) { $missing[] = __( 'Rechnungsanschrift', 'pass-captain' ); }
		if ( '' === trim( (string) $partner->vat_id ) && '' === trim( (string) ( $partner->tax_number ?? '' ) ) ) { $missing[] = __( 'USt-IdNr. oder Steuernummer', 'pass-captain' ); }
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT o.event_id, COALESCE(pe.partner_id,0) AS partner_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i pe ON pe.event_id=o.event_id WHERE oi.order_id=%d', $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_partner_events', absint( $order_id ) ) );
		return self::context_from_partners( array_map( static function ( $row ) { return (int) $row->partner_id; }, (array) $rows ) );
	}

	/**
	 * order_context() for many orders: one query per 500 orders, each organizer read once (A434:
	 * accounting export of thousands of orders without invoice).
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int, array|\WP_Error> Order id => order_context() result.
	 */
	public static function order_contexts( array $order_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$partners = array();
		$out = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) ), 500 ) as $chunk ) {
			$by_order = array_fill_keys( $chunk, array() );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT oi.order_id, o.event_id, COALESCE(pe.partner_id,0) AS partner_id FROM %i oi INNER JOIN %i tt ON tt.ticket_type_id=oi.ticket_type_id INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id LEFT JOIN %i pe ON pe.event_id=o.event_id WHERE oi.order_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $p . 'wpcap_order_items', $p . 'wpcap_ticket_types', $p . 'wpcap_occurrences', $p . 'wpcap_partner_events' ), $chunk ) ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list.
				$by_order[ (int) $row->order_id ][] = (int) $row->partner_id;
			}
			foreach ( $by_order as $order_id => $partner_ids ) { $out[ $order_id ] = self::context_from_partners( $partner_ids, $partners ); }
		}
		return $out;
	}

	/**
	 * Seller context from the organizer of each event of an order (0 = platform event).
	 *
	 * @param int[] $partner_ids One entry per distinct event of the order.
	 * @param array $partners    Organizer rows already read (partner id => row|null), filled here.
	 */
	private static function context_from_partners( array $partner_ids, array &$partners = array() ) {
		$sellers = array();
		$agents = array();
		foreach ( $partner_ids as $partner_id ) {
			if ( $partner_id && ! array_key_exists( $partner_id, $partners ) ) { $partners[ $partner_id ] = Repository::get( $partner_id ); }
			$partner = $partner_id ? $partners[ $partner_id ] : null;
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
				__( 'Diese Bestellung enthält Tickets verschiedener Rechnungssteller (Vermittler-Veranstalter: %s, dazu weitere Veranstalter oder Plattform-Events). Eine gemeinsame Rechnung ist nicht zulässig; bitte die Rechnungen außerhalb des Plugins je Veranstalter erstellen oder die Bestellung stornieren und getrennt neu anlegen.', 'pass-captain' ),
				implode( ', ', $names )
			), array( 'status' => 409, 'partner_ids' => array_keys( $agents ) ) );
		}
		$platform = self::platform_seller();
		if ( ! $agents ) {
			return array( 'model' => self::OWN_NAME, 'partner_id' => 0, 'seller' => $platform, 'agent' => null, 'series' => '', 'prefix' => '', 'credit_series' => 'credit', 'credit_prefix' => (string) Settings::get( 'credit_note_prefix' ) ?: \PassCaptain\Invoices::CREDIT_PREFIX_DEFAULT );
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

	/** Seller key of the platform (events without organizer and own_name organizers). */
	const PLATFORM_KEY = 'platform';

	/**
	 * Invoice issuer ("seller") per ticket type, with the same rule as order_context(): an event of an
	 * agent organizer is sold by that organizer ("org-{partner_id}"), everything else by the platform
	 * ("platform"). Used to keep one seller per order, e.g. in WooCommerce carts.
	 *
	 * @param int[] $type_ids Ticket type IDs.
	 * @return array<int,string> ticket type ID => seller key (unknown types: platform).
	 */
	public static function seller_keys_for_types( array $type_ids ) {
		global $wpdb;
		$type_ids = array_values( array_unique( array_filter( array_map( 'absint', $type_ids ) ) ) );
		$out = array_fill_keys( $type_ids, self::PLATFORM_KEY );
		if ( ! $type_ids ) { return $out; }
		$p = $wpdb->prefix;
		$in = implode( ',', $type_ids );
		$rows = $wpdb->get_results( "SELECT tt.ticket_type_id, pa.partner_id, pa.sales_model FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_partner_events pe ON pe.event_id=o.event_id INNER JOIN {$p}wpcap_partners pa ON pa.partner_id=pe.partner_id WHERE tt.ticket_type_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( (array) $rows as $row ) {
			if ( self::AGENT === self::model_of( $row ) ) {
				$out[ (int) $row->ticket_type_id ] = 'org-' . (int) $row->partner_id;
			}
		}
		return $out;
	}

	/** Sorts seller keys deterministically: platform first, then organizers by partner ID. */
	public static function sort_seller_keys( array $keys ) {
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		usort( $keys, static function ( $a, $b ) {
			$na = self::PLATFORM_KEY === $a ? 0 : (int) substr( $a, 4 );
			$nb = self::PLATFORM_KEY === $b ? 0 : (int) substr( $b, 4 );
			return $na <=> $nb;
		} );
		return $keys;
	}

	/** Display name of a seller key (legal name of the organizer or the platform's seller name). */
	public static function seller_name( $key ) {
		if ( 0 === strpos( (string) $key, 'org-' ) ) {
			$partner = Repository::get( (int) substr( (string) $key, 4 ) );
			if ( $partner ) {
				$legal = trim( (string) ( $partner->legal_name ?? '' ) );
				return '' !== $legal ? $legal : (string) $partner->name;
			}
		}
		$name = trim( (string) ( Invoices::seller()['name'] ?? '' ) );
		return '' !== $name ? $name : __( 'Plattform', 'pass-captain' );
	}

	/**
	 * Checks before an agent invoice is issued (organizer data complete, no VAT on lines of a small business).
	 *
	 * @param object[] $items Order items (tax_minor).
	 * @return true|\WP_Error
	 */
	public static function validate_agent_invoice( array $context, array $items ) {
		if ( self::AGENT !== $context['model'] ) {
			// The platform itself as a small business: no VAT may be shown on its invoices either.
			if ( ! empty( $context['seller']['small_business'] ) ) {
				foreach ( $items as $item ) {
					if ( (int) $item->tax_minor > 0 ) {
						return new \WP_Error( 'wpcap_invoice_small_business_vat', __( 'Die Plattform ist als Kleinunternehmer eingestellt, die Bestellung enthält aber Umsatzsteuer. Ein Steuerausweis wäre unzulässig (§ 14c UStG). Bitte die Ticketarten mit 0 % anlegen oder die Einstellung „Kleinunternehmer“ prüfen.', 'pass-captain' ), array( 'status' => 409 ) );
					}
				}
			}
			return true;
		}
		$missing = self::missing_supplier_fields( $context['partner'] );
		if ( $missing ) {
			return new \WP_Error( 'wpcap_invoice_organizer_incomplete', sprintf(
				/* translators: 1: organizer, 2: missing fields */
				__( 'Rechnung im Namen des Veranstalters „%1$s“ nicht möglich – es fehlen: %2$s. Bitte unter Veranstalter ergänzen.', 'pass-captain' ),
				$context['seller']['name'], implode( ', ', $missing )
			), array( 'status' => 409 ) );
		}
		if ( ! empty( $context['seller']['small_business'] ) ) {
			foreach ( $items as $item ) {
				if ( (int) $item->tax_minor > 0 ) {
					return new \WP_Error( 'wpcap_invoice_small_business_vat', sprintf(
						/* translators: %s: organizer */
						__( 'Der Veranstalter „%s“ ist Kleinunternehmer, die Bestellung enthält aber Umsatzsteuer. Ein Steuerausweis wäre unzulässig (§ 14c UStG). Bitte die Ticketarten mit 0 %% anlegen.', 'pass-captain' ),
						$context['seller']['name']
					), array( 'status' => 409 ) );
				}
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ Small-business seller (no VAT) */

	/**
	 * Seller responsible for the customer invoices of an event's tickets, with the rule of
	 * order_context(): an agent organizer sells in its own name, everything else is sold by the platform
	 * (own_name organizers and events without organizer).
	 *
	 * @return array{key:string,model:string,name:string,address:string,small_business:bool,partner_id:int}
	 */
	public static function event_seller( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$partner_id = $event_id ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT partner_id FROM %i WHERE event_id=%d', $wpdb->prefix . 'wpcap_partner_events', $event_id ) ) : 0;
		$partner = $partner_id ? Repository::get( $partner_id ) : null;
		if ( $partner && self::AGENT === self::model_of( $partner ) ) {
			$seller = self::organizer_seller( $partner );
			return array( 'key' => 'org-' . (int) $partner->partner_id, 'model' => self::AGENT, 'name' => (string) $seller['name'], 'address' => (string) $seller['address'], 'small_business' => (bool) $seller['small_business'], 'partner_id' => (int) $partner->partner_id );
		}
		$platform = Invoices::seller();
		return array( 'key' => self::PLATFORM_KEY, 'model' => self::OWN_NAME, 'name' => self::seller_name( self::PLATFORM_KEY ), 'address' => (string) $platform['address'], 'small_business' => ! empty( $platform['small_business'] ), 'partner_id' => 0 );
	}

	/**
	 * VAT rate checkout would apply to a ticket type today: a versioned tax profile (Sales\TaxProfiles,
	 * also for the class "standard"), else a country profile of its tax class (Tax\Profiles), else the
	 * type's own rate. Mirrors Orders::checkout_price_lines()/checkout_apply_taxes() for the own checkout.
	 *
	 * @param object      $type       Ticket type (tax_rate_bp, tax_class, occurrence_id).
	 * @param object|null $occurrence Occurrence row (event_id, venue_country, starts_at).
	 */
	public static function effective_tax_rate_bp( $type, $occurrence, $now = '' ) {
		$now = '' !== (string) $now ? (string) $now : \PassCaptain\Clock::mysql();
		$line = $occurrence ? \PassCaptain\Sales\TaxProfiles::for_line( $type, $occurrence, $now ) : null;
		if ( is_array( $line ) ) { return (int) $line['rate_bp']; }
		return (int) \PassCaptain\Tax\Profiles::resolve( $type, $occurrence, $now, 'native' )['rate_bp'];
	}

	/**
	 * Refusal when a ticket type would carry VAT although its seller is a small business (§ 19 UStG /
	 * § 6 Abs. 1 Z 27 AT UStG: no VAT may be shown, § 14c UStG). Checked when a type is saved, so no
	 * order is sold that could not be invoiced (Invoices refuses wpcap_invoice_small_business_vat).
	 *
	 * @param int $event_id     Event of the type.
	 * @param int $form_rate_bp Rate entered for the type.
	 * @param int $effective_bp Rate checkout would apply (effective_tax_rate_bp(); tax profiles), or null = form rate.
	 * @return \WP_Error|null Error with data field `tax_rate` or `tax_class`.
	 */
	public static function small_business_type_error( $event_id, $form_rate_bp, $effective_bp = null ) {
		$effective_bp = null === $effective_bp ? (int) $form_rate_bp : (int) $effective_bp;
		if ( (int) $form_rate_bp <= 0 && $effective_bp <= 0 ) { return null; }
		$seller = self::event_seller( $event_id );
		if ( ! $seller['small_business'] ) { return null; }
		if ( (int) $form_rate_bp > 0 ) {
			$message = self::AGENT === $seller['model']
				/* translators: %s: organizer */
				? sprintf( __( 'Der Veranstalter „%s“ ist Kleinunternehmer und darf keine Umsatzsteuer ausweisen. Bitte trage 0 als Steuersatz ein.', 'pass-captain' ), $seller['name'] )
				: __( 'Die Plattform ist als Kleinunternehmer eingestellt und darf keine Umsatzsteuer ausweisen. Bitte trage 0 als Steuersatz ein oder prüfe die Einstellung „Kleinunternehmer“ (Einstellungen → Rechnungssteller).', 'pass-captain' );
			return new \WP_Error( 'wpcap_type_small_business_vat', $message, array( 'status' => 400, 'field' => 'tax_rate' ) );
		}
		/* translators: 1: tax rate in percent, 2: seller (organizer or platform) */
		$message = sprintf( __( 'Für diese Ticketart gilt ein aktives Steuerprofil mit %1$s %%, der Rechnungssteller „%2$s“ ist aber Kleinunternehmer und darf keine Umsatzsteuer ausweisen. Bitte passe die Steuerklasse an oder lass das Steuerprofil prüfen.', 'pass-captain' ), \PassCaptain\Admin\Forms::percent_input( $effective_bp ), $seller['name'] );
		return new \WP_Error( 'wpcap_type_small_business_vat', $message, array( 'status' => 400, 'field' => 'tax_class' ) );
	}

	/**
	 * Checkout check (Orders::checkout_apply_taxes()): lines of a small-business seller that would carry
	 * VAT. Lines are grouped by event; the service fee counts for every seller of the order.
	 *
	 * @param array $lines   Order lines (event_id, tax_minor, type->ticket_type_id).
	 * @param int   $fee_tax VAT of the service fee lines.
	 * @return array|null {seller, ticket_type_ids} of the first conflict, or null.
	 */
	public static function checkout_vat_conflict( array $lines, $fee_tax = 0 ) {
		$sellers = array();
		$conflict = null;
		foreach ( $lines as $line ) {
			$event_id = (int) ( $line['event_id'] ?? 0 );
			if ( ! isset( $sellers[ $event_id ] ) ) { $sellers[ $event_id ] = self::event_seller( $event_id ); }
			$seller = $sellers[ $event_id ];
			if ( ! $seller['small_business'] ) { continue; }
			if ( (int) ( $line['tax_minor'] ?? 0 ) > 0 || (int) $fee_tax > 0 ) {
				$conflict = $conflict ?? array( 'seller' => $seller['key'], 'ticket_type_ids' => array() );
				$conflict['ticket_type_ids'][] = (int) ( isset( $line['type'] ) && is_object( $line['type'] ) ? $line['type']->ticket_type_id : 0 );
			}
		}
		return $conflict;
	}

	/**
	 * Active ticket types of upcoming dates that carry VAT although their seller is a small business
	 * (existing data from before the save check, or the setting/organizer flag changed later). Shown in
	 * Site Health; prices are never changed automatically.
	 *
	 * @param int $limit Maximum rows.
	 * @return array[] {ticket_type_id, event_id, event, ticket, starts_at, rate_bp, seller}
	 */
	public static function small_business_vat_types( $limit = 50 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$platform_small = ! empty( Invoices::seller()['small_business'] );
		// Agent organizers that are small businesses; with a small-business platform also every other event.
		$seller_sql = $platform_small
			? "( pa.partner_id IS NULL OR COALESCE(pa.sales_model,'') <> 'agent' OR pa.small_business = 1 )"
			: "( pa.sales_model = 'agent' AND pa.small_business = 1 )";
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.*, o.event_id, o.starts_at AS occ_starts_at, e.name AS event_name FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id LEFT JOIN {$p}wpcap_partner_events pe ON pe.event_id=o.event_id LEFT JOIN {$p}wpcap_partners pa ON pa.partner_id=pe.partner_id WHERE tt.status='active' AND o.starts_at >= %s AND {$seller_sql} ORDER BY tt.tax_rate_bp > 0 DESC, o.starts_at, tt.ticket_type_id LIMIT 500", gmdate( 'Y-m-d H:i:s', \PassCaptain\Clock::now() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a fixed condition.
		$out = array();
		$now = \PassCaptain\Clock::mysql();
		foreach ( (array) $rows as $row ) {
			$rate = (int) $row->tax_rate_bp;
			if ( $rate <= 0 ) {
				$occurrence = \PassCaptain\Admin\Queries::occurrence( (int) $row->occurrence_id );
				$rate = self::effective_tax_rate_bp( $row, $occurrence, $now );
			}
			if ( $rate <= 0 ) { continue; }
			$out[] = array( 'ticket_type_id' => (int) $row->ticket_type_id, 'event_id' => (int) $row->event_id, 'event' => (string) $row->event_name, 'ticket' => (string) $row->name, 'starts_at' => (string) $row->occ_starts_at, 'rate_bp' => $rate, 'seller' => self::event_seller( (int) $row->event_id )['name'] );
			if ( count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Contract partner (agent model) */

	/**
	 * Contract partner of the buyer in the agent model (Vermittler): the organizer with name and
	 * address. Null in the own_name model and for platform events (nothing is shown there).
	 *
	 * @return array{name:string,address:string}|null
	 */
	public static function contract_partner_for_event( $event_id ) {
		$seller = self::event_seller( $event_id );
		return self::AGENT === $seller['model'] ? array( 'name' => $seller['name'], 'address' => $seller['address'] ) : null;
	}

	/**
	 * Contract partner of an order in the agent model (current organizer data, as order_context()); null
	 * for the own_name model, platform events and mixed orders.
	 *
	 * @return array{name:string,address:string}|null
	 */
	public static function contract_partner_for_order( $order_id ) {
		$context = self::order_context( $order_id );
		if ( is_wp_error( $context ) || self::AGENT !== $context['model'] ) { return null; }
		return array( 'name' => (string) $context['seller']['name'], 'address' => (string) $context['seller']['address'] );
	}

	/** "Vertragspartner: Name, Straße, Ort" (address lines joined with commas). */
	public static function contract_partner_line( array $partner ) {
		$parts = array_filter( array_map( 'trim', array_merge( array( (string) $partner['name'] ), preg_split( '/\r\n|\r|\n/', (string) $partner['address'] ) ) ), static fn( $part ) => '' !== $part );
		/* translators: %s: organizer name and address */
		return sprintf( __( 'Vertragspartner: %s', 'pass-captain' ), implode( ', ', $parts ) );
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
		$messages['mixed_sellers'] = __( 'Tickets dieser Veranstalter können nicht in einer gemeinsamen Bestellung gekauft werden. Bitte für jeden Veranstalter separat bestellen.', 'pass-captain' );
		return $messages;
	}
}
