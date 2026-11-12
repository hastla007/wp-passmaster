<?php
/**
 * Buyer billing data (B2B invoices): company, name, address, VAT ID and the buyer's own reference.
 *
 * Stored as JSON in wpcap_orders.billing (Orders::billing()). Invoices copy it into their buyer
 * snapshot when they are issued; later changes or a privacy erasure never alter an issued invoice
 * (Art. 17 Abs. 3 lit. b DSGVO, retention duties for invoices).
 *
 * Why: invoices above EUR 400 (AT, § 11 Abs. 1 Z 2 UStG; above EUR 10,000 also the buyer's UID)
 * and above EUR 250 (DE, § 14 Abs. 4 Nr. 1 UStG, § 33 UStDV) need the buyer's name and address;
 * company buyers need them for input tax deduction. Admission tickets are taxed where the event
 * takes place, so no reverse-charge note is printed.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Billing {
	/** Stored fields (all strings). */
	const FIELDS = array( 'company', 'first_name', 'last_name', 'street', 'postcode', 'city', 'country', 'vat_id', 'po_number' );

	/** Fields required when billing data is given at all. */
	const REQUIRED = array( 'first_name', 'last_name', 'street', 'postcode', 'city', 'country' );

	/** Maximum lengths per field. */
	const MAX = array( 'company' => 190, 'first_name' => 100, 'last_name' => 100, 'street' => 190, 'postcode' => 16, 'city' => 100, 'country' => 2, 'vat_id' => 20, 'po_number' => 64 );

	/** EU VAT ID formats (normalised: upper case, no spaces/dots/dashes). Greece uses EL, Northern Ireland XI. */
	const VAT_PATTERNS = array(
		'AT' => 'ATU\d{8}',
		'BE' => 'BE[01]\d{9}',
		'BG' => 'BG\d{9,10}',
		'CY' => 'CY\d{8}[A-Z]',
		'CZ' => 'CZ\d{8,10}',
		'DE' => 'DE\d{9}',
		'DK' => 'DK\d{8}',
		'EE' => 'EE\d{9}',
		'EL' => 'EL\d{9}',
		'ES' => 'ES[A-Z0-9]\d{7}[A-Z0-9]',
		'FI' => 'FI\d{8}',
		'FR' => 'FR[A-HJ-NP-Z0-9]{2}\d{9}',
		'HR' => 'HR\d{11}',
		'HU' => 'HU\d{8}',
		'IE' => 'IE(\d{7}[A-W][A-IW]?|\d[A-Z+*]\d{5}[A-W])',
		'IT' => 'IT\d{11}',
		'LT' => 'LT(\d{9}|\d{12})',
		'LU' => 'LU\d{8}',
		'LV' => 'LV\d{11}',
		'MT' => 'MT\d{8}',
		'NL' => 'NL\d{9}B\d{2}',
		'PL' => 'PL\d{10}',
		'PT' => 'PT\d{9}',
		'RO' => 'RO\d{2,10}',
		'SE' => 'SE\d{10}01',
		'SI' => 'SI\d{8}',
		'SK' => 'SK\d{10}',
		'XI' => 'XI(\d{9}|\d{12}|GD\d{3}|HA\d{3})',
		// Frequent non-EU neighbours.
		'CH' => 'CHE\d{9}(MWST|TVA|IVA)?',
		'GB' => 'GB(\d{9}|\d{12}|GD\d{3}|HA\d{3})',
		'NO' => 'NO\d{9}(MVA)?',
	);

	/** Country code => German name (EU plus frequent neighbours); filter `wpcap_billing_countries`. */
	public static function countries() {
		$countries = array(
			'AT' => __( 'Österreich', 'wp-pass-captain' ),
			'DE' => __( 'Deutschland', 'wp-pass-captain' ),
			'CH' => __( 'Schweiz', 'wp-pass-captain' ),
			'LI' => __( 'Liechtenstein', 'wp-pass-captain' ),
			'BE' => __( 'Belgien', 'wp-pass-captain' ),
			'BG' => __( 'Bulgarien', 'wp-pass-captain' ),
			'DK' => __( 'Dänemark', 'wp-pass-captain' ),
			'EE' => __( 'Estland', 'wp-pass-captain' ),
			'FI' => __( 'Finnland', 'wp-pass-captain' ),
			'FR' => __( 'Frankreich', 'wp-pass-captain' ),
			'GR' => __( 'Griechenland', 'wp-pass-captain' ),
			'IE' => __( 'Irland', 'wp-pass-captain' ),
			'IT' => __( 'Italien', 'wp-pass-captain' ),
			'HR' => __( 'Kroatien', 'wp-pass-captain' ),
			'LV' => __( 'Lettland', 'wp-pass-captain' ),
			'LT' => __( 'Litauen', 'wp-pass-captain' ),
			'LU' => __( 'Luxemburg', 'wp-pass-captain' ),
			'MT' => __( 'Malta', 'wp-pass-captain' ),
			'NL' => __( 'Niederlande', 'wp-pass-captain' ),
			'NO' => __( 'Norwegen', 'wp-pass-captain' ),
			'PL' => __( 'Polen', 'wp-pass-captain' ),
			'PT' => __( 'Portugal', 'wp-pass-captain' ),
			'RO' => __( 'Rumänien', 'wp-pass-captain' ),
			'SE' => __( 'Schweden', 'wp-pass-captain' ),
			'SK' => __( 'Slowakei', 'wp-pass-captain' ),
			'SI' => __( 'Slowenien', 'wp-pass-captain' ),
			'ES' => __( 'Spanien', 'wp-pass-captain' ),
			'CZ' => __( 'Tschechien', 'wp-pass-captain' ),
			'HU' => __( 'Ungarn', 'wp-pass-captain' ),
			'GB' => __( 'Vereinigtes Königreich', 'wp-pass-captain' ),
			'CY' => __( 'Zypern', 'wp-pass-captain' ),
		);
		$countries = apply_filters( 'wpcap_billing_countries', $countries );
		return is_array( $countries ) && $countries ? $countries : array( 'AT' => 'Österreich', 'DE' => 'Deutschland' );
	}

	/**
	 * Preselected country: filter `wpcap_billing_default_country`, else the prefix of the seller's VAT
	 * ID, the WooCommerce shop country or the site locale (de_AT → AT); DE otherwise.
	 */
	public static function default_country() {
		$countries = self::countries();
		$candidates = array();
		$seller_vat = strtoupper( (string) Settings::get( 'seller_vat_id' ) );
		if ( preg_match( '/^([A-Z]{2})/', $seller_vat, $m ) ) { $candidates[] = 'EL' === $m[1] ? 'GR' : $m[1]; }
		$woo = (string) get_option( 'woocommerce_default_country', '' );
		if ( '' !== $woo ) { $candidates[] = strtoupper( substr( $woo, 0, 2 ) ); }
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
		if ( preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m ) ) { $candidates[] = $m[1]; }
		$default = 'DE';
		foreach ( $candidates as $candidate ) {
			if ( isset( $countries[ $candidate ] ) ) { $default = $candidate; break; }
		}
		$default = strtoupper( (string) apply_filters( 'wpcap_billing_default_country', $default ) );
		return isset( $countries[ $default ] ) ? $default : (string) array_key_first( $countries );
	}

	/** Visible field labels (also used in server error messages, same wording as the form). */
	public static function labels() {
		return array(
			'enabled' => __( 'Ich brauche eine Rechnung auf Firma/Adresse', 'wp-pass-captain' ),
			'company' => __( 'Firma', 'wp-pass-captain' ),
			'first_name' => __( 'Vorname', 'wp-pass-captain' ),
			'last_name' => __( 'Nachname', 'wp-pass-captain' ),
			'street' => __( 'Straße und Hausnummer', 'wp-pass-captain' ),
			'postcode' => __( 'PLZ', 'wp-pass-captain' ),
			'city' => __( 'Ort', 'wp-pass-captain' ),
			'country' => __( 'Land', 'wp-pass-captain' ),
			'vat_id' => __( 'UID-Nummer', 'wp-pass-captain' ),
			'po_number' => __( 'Bestell- oder Referenznummer', 'wp-pass-captain' ),
		);
	}

	/** Normalised VAT ID: upper case without spaces, dots, dashes and slashes. */
	public static function normalize_vat_id( $vat_id ) {
		return strtoupper( preg_replace( '/[\s.\-\/]+/u', '', sanitize_text_field( (string) $vat_id ) ) );
	}

	/** Cleans the input to the stored fields (no validation). Unknown keys are dropped. */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out = array();
		foreach ( self::FIELDS as $field ) {
			$value = $input[ $field ] ?? '';
			$value = is_scalar( $value ) ? (string) $value : '';
			$value = 'vat_id' === $field ? self::normalize_vat_id( $value ) : trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $value ) ) );
			if ( 'country' === $field ) { $value = strtoupper( preg_replace( '/[^A-Za-z]/', '', $value ) ); }
			$out[ $field ] = mb_substr( $value, 0, self::MAX[ $field ] );
		}
		return $out;
	}

	/** True when no field carries a value. */
	public static function is_empty( $billing ) {
		if ( ! is_array( $billing ) ) { return true; }
		foreach ( self::FIELDS as $field ) {
			if ( '' !== (string) ( $billing[ $field ] ?? '' ) ) { return false; }
		}
		return true;
	}

	/** Whether the input asks for billing data (checkbox "enabled" or, without the flag, any value given). */
	public static function requested( $input ) {
		if ( ! is_array( $input ) ) { return false; }
		if ( array_key_exists( 'enabled', $input ) ) { return ! empty( $input['enabled'] ) && 'false' !== $input['enabled'] && '0' !== $input['enabled']; }
		return ! self::is_empty( self::sanitize( $input ) );
	}

	/** Format check of a normalised VAT ID by its country prefix. */
	public static function vat_id_format_valid( $vat_id, $country = '' ) {
		$vat_id = self::normalize_vat_id( $vat_id );
		$prefix = substr( $vat_id, 0, 2 );
		$patterns = (array) apply_filters( 'wpcap_vat_id_patterns', self::VAT_PATTERNS );
		if ( isset( $patterns[ $prefix ] ) ) { return 1 === preg_match( '/^' . $patterns[ $prefix ] . '$/', $vat_id ); }
		// Other countries outside the EU: no known format, accept a plausible tax number.
		$eu = array_keys( self::VAT_PATTERNS );
		$country = strtoupper( (string) $country );
		return '' !== $country && ! in_array( 'GR' === $country ? 'EL' : $country, $eu, true ) && 1 === preg_match( '/^[A-Z0-9]{4,20}$/', $vat_id );
	}

	/**
	 * Validates and sanitises billing input.
	 *
	 * @param mixed $input  Raw input (array of FIELDS plus optional "enabled").
	 * @return array|null|\WP_Error Clean fields, null when no billing data was requested, or an error
	 *                              with data.field = "billing_<field>" (checkout form control name).
	 */
	public static function validate( $input ) {
		if ( ! self::requested( $input ) ) { return null; }
		$billing = self::sanitize( $input );
		$labels = self::labels();
		foreach ( self::REQUIRED as $field ) {
			if ( '' === $billing[ $field ] ) {
				/* translators: %s: field label */
				return self::error( $field, sprintf( __( 'Bitte fülle „%s“ aus.', 'wp-pass-captain' ), $labels[ $field ] ) );
			}
		}
		if ( ! isset( self::countries()[ $billing['country'] ] ) ) {
			return self::error( 'country', __( 'Bitte wähle ein Land aus der Liste.', 'wp-pass-captain' ) );
		}
		if ( ! self::postcode_valid( $billing['postcode'], $billing['country'] ) ) {
			/* translators: %s: field label */
			return self::error( 'postcode', sprintf( __( '„%s“ ist ungültig.', 'wp-pass-captain' ), $labels['postcode'] ) );
		}
		if ( '' !== $billing['vat_id'] ) {
			if ( ! self::vat_id_format_valid( $billing['vat_id'], $billing['country'] ) ) {
				return self::error( 'vat_id', __( 'Die UID-Nummer hat kein gültiges Format. Bitte mit Länderkennzeichen angeben, z. B. ATU12345678 oder DE123456789.', 'wp-pass-captain' ) );
			}
			/**
			 * Optional online check of the VAT ID (e.g. against VIES). No external call by default.
			 * Return true to accept, false or a \WP_Error (its message is shown) to reject.
			 *
			 * @param true|\WP_Error $valid   Result so far.
			 * @param string         $vat_id  Normalised VAT ID.
			 * @param array          $billing Clean billing data.
			 */
			$checked = apply_filters( 'wpcap_vat_id_validate', true, $billing['vat_id'], $billing );
			if ( is_wp_error( $checked ) ) { return self::error( 'vat_id', $checked->get_error_message() ); }
			if ( true !== $checked ) { return self::error( 'vat_id', __( 'Die UID-Nummer konnte nicht bestätigt werden. Bitte prüfe die Eingabe.', 'wp-pass-captain' ) ); }
		}
		return $billing;
	}

	private static function postcode_valid( $postcode, $country ) {
		$patterns = array( 'AT' => '\d{4}', 'DE' => '\d{5}', 'CH' => '\d{4}', 'LI' => '94\d{2}', 'NL' => '\d{4} ?[A-Z]{2}', 'PL' => '\d{2}-\d{3}' );
		if ( isset( $patterns[ $country ] ) ) { return 1 === preg_match( '/^' . $patterns[ $country ] . '$/i', $postcode ); }
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9 \-]{1,15}$/', $postcode );
	}

	private static function error( $field, $message ) {
		return new \WP_Error( 'wpcap_billing_invalid', $message, array( 'status' => 400, 'field' => 'billing_' . $field ) );
	}

	/**
	 * Minimum order total (minor units) above which billing data is required in the public checkout.
	 * Off by default (0). Example for Austria: add_filter( 'wpcap_billing_required_threshold', fn() => 40000 );
	 */
	public static function required_threshold( $currency = 'EUR' ) {
		return max( 0, (int) apply_filters( 'wpcap_billing_required_threshold', 0, (string) $currency ) );
	}

	/** Whether an order total requires billing data. */
	public static function required_for( $total_minor, $currency = 'EUR' ) {
		$threshold = self::required_threshold( $currency );
		return $threshold > 0 && (int) $total_minor > $threshold;
	}

	/* ------------------------------------------------------------------ Storage */

	/** Billing data of an order row or id (clean fields, empty array when none). */
	public static function for_order( $order ) {
		if ( ! is_object( $order ) ) { $order = Orders::get( absint( $order ) ); }
		if ( ! $order || empty( $order->billing ) ) { return array(); }
		$data = json_decode( (string) $order->billing, true );
		$billing = self::sanitize( is_array( $data ) ? $data : array() );
		return self::is_empty( $billing ) ? array() : $billing;
	}

	/** Stores (or with an empty array removes) the billing data of an order. */
	public static function save( $order_id, $billing ) {
		global $wpdb;
		$billing = is_array( $billing ) ? self::sanitize( $billing ) : array();
		return false !== $wpdb->update(
			$wpdb->prefix . 'wpcap_orders',
			array( 'billing' => self::is_empty( $billing ) ? null : wp_json_encode( $billing ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'order_id' => absint( $order_id ) )
		);
	}

	/** Whether the order already has an invoice (billing data is then frozen in the invoice). */
	public static function invoice_issued( $order_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d LIMIT 1", absint( $order_id ) ) );
	}

	/**
	 * Order portal: the buyer views/corrects the billing data until the invoice is issued.
	 *
	 * @param object $order Order row whose access token the caller verified.
	 * @return array|\WP_Error ['billing' => array]
	 */
	public static function update_for_order( $order, $input ) {
		if ( self::invoice_issued( (int) $order->order_id ) ) {
			return new \WP_Error( 'wpcap_billing_locked', __( 'Die Rechnung wurde bereits ausgestellt. Für eine Korrektur wende dich bitte an den Veranstalter.', 'wp-pass-captain' ), array( 'status' => 409 ) );
		}
		$billing = self::validate( $input );
		if ( is_wp_error( $billing ) ) { return $billing; }
		if ( ! self::save( (int) $order->order_id, $billing ?: array() ) ) {
			return new \WP_Error( 'wpcap_billing_save', __( 'Die Rechnungsdaten konnten nicht gespeichert werden.', 'wp-pass-captain' ), array( 'status' => 500 ) );
		}
		Audit::record( 'billing_updated', 'order', (int) $order->order_id, array( 'source' => 'portal', 'cleared' => ! $billing ) );
		return array( 'billing' => $billing ?: array() );
	}

	/* ------------------------------------------------------------------ Documents */

	/** Fields for the invoice buyer snapshot (flat keys; name = "first last"). */
	public static function snapshot( $order ) {
		$billing = self::for_order( $order );
		if ( ! $billing ) { return array(); }
		$billing['name'] = trim( $billing['first_name'] . ' ' . $billing['last_name'] );
		$billing['country_name'] = self::countries()[ $billing['country'] ] ?? $billing['country'];
		return $billing;
	}

	/** Recipient address lines from billing data or a buyer snapshot (company, name, street, postcode city, country). */
	public static function address_lines( array $data ) {
		$name = (string) ( $data['name'] ?? trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) ) );
		$country = (string) ( $data['country'] ?? '' );
		$country_name = (string) ( $data['country_name'] ?? ( self::countries()[ $country ] ?? $country ) );
		$lines = array( (string) ( $data['company'] ?? '' ), $name, (string) ( $data['street'] ?? '' ), trim( ( $data['postcode'] ?? '' ) . ' ' . ( $data['city'] ?? '' ) ), $country_name );
		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}

	/** Additional recipient lines: VAT ID and the buyer's reference (label => value). */
	public static function detail_lines( array $data ) {
		$lines = array();
		if ( '' !== (string) ( $data['vat_id'] ?? '' ) ) { $lines[] = __( 'UID des Leistungsempfängers:', 'wp-pass-captain' ) . ' ' . $data['vat_id']; }
		if ( '' !== (string) ( $data['po_number'] ?? '' ) ) { $lines[] = __( 'Ihre Bestell-/Referenznummer:', 'wp-pass-captain' ) . ' ' . $data['po_number']; }
		return $lines;
	}
}
