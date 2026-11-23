<?php
/**
 * Settings register (SET-PC-01): one description per setting as the single source for forms, validation,
 * export/import, reset, WP-CLI and the change log.
 *
 * Every key of Settings::defaults() is described by an entry:
 *   key, type, default, min/max, options, tab, section, page, label, help, legal (needs legal review),
 *   secret, country (depends on the seller country, SET-PC-02), capability, source (core|module|addon),
 *   internal (no form field, maintained by the plugin), fields (sub-fields of a group setting).
 *
 * Sources, in this order: the core settings screen (Admin\SettingsPage::all_sections(), including the
 * sections of Security\Policy and add-ons), the modules (optional static settings_schema(): key => entry),
 * the internal keys below, then the filter wp_pass_captain_settings_schema_v1. A key without any
 * description gets a generic entry with described = false; the test zz_set_pc_01 keeps that list empty
 * except for the documented backlog.
 *
 * validate() reports instead of correcting silently: a number outside its limits or an unknown choice is
 * a WP_Error with a message naming the field, never clamped to the nearest allowed value.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SettingsSchema {
	/** Entry types the register knows (form field types of Admin\SettingsPage plus group). */
	const TYPES = array( 'int', 'bool', 'choice', 'text', 'textarea', 'html', 'money', 'secret', 'email', 'iban', 'bic', 'currency', 'currencies', 'url', 'prefix', 'providers', 'modules', 'list', 'group' );

	/**
	 * Settings whose value has legal weight (texts with paragraphs of law, invoice data, retention, tax):
	 * shown with the note "Rechtsprüfung" and never changed silently by a country preset (SET-PC-02/-04).
	 */
	const LEGAL = array(
		'terms_text', 'consumer_info_text', 'legal_texts', 'require_terms', 'withdrawal_notice', 'withdrawal_notice_text',
		'withdrawal_right_text', 'seller_name', 'seller_address', 'seller_vat_id', 'seller_tax_number', 'seller_country',
		'seller_small_business', 'seller_small_business_note', 'invoice_prefix', 'credit_note_prefix',
		'billing_required_threshold_minor', 'auto_invoice', 'retention_financial_years', 'retention_order_years',
		'service_fee_tax', 'service_fee_tax_class', 'service_fee_tax_rate', 'service_fee_refund', 'voucher_default_purpose',
		'datev_chart', 'datev_contra_account', 'fiscal_allow_local_service', 'reschedule_refund_request',
		'boxoffice_receipt_prefix', 'boxoffice_receipt_footer', 'partners', 'sms',
	);

	/** Settings a country preset (DE, AT, CH, EU, OTHER; SET-PC-02) proposes a value for. */
	const COUNTRY = array(
		'seller_country', 'withdrawal_notice_text', 'withdrawal_right_text', 'seller_small_business', 'seller_small_business_note',
		'invoice_prefix', 'credit_note_prefix', 'billing_required_threshold_minor', 'retention_financial_years',
		'retention_order_years', 'datev_chart', 'datev_contra_account', 'fiscal_allow_local_service', 'service_fee_tax_class',
		'boxoffice_receipt_prefix', 'sms', 'mollie',
	);

	/** @var array|null Per-request cache of all(). */
	private static $cache = null;

	/** Drops the per-request cache (settings or locale changed). */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Every described setting: key => entry (see class comment). Built once per request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		if ( null !== self::$cache ) { return self::$cache; }
		$defaults = Settings::defaults();
		$entries = array();
		foreach ( Admin\SettingsPage::all_sections() as $section_id => $section ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $key => $def ) {
				if ( ! array_key_exists( $key, $defaults ) || isset( $entries[ $key ] ) || ! is_array( $def ) ) { continue; }
				$entries[ $key ] = self::normalize(
					$key,
					$def + array(
						'tab' => (string) ( $section['tab'] ?? 'general' ),
						'section' => (string) $section_id,
						'page' => 'settings',
						'source' => empty( $section['addon'] ) ? 'core' : 'addon',
					),
					$defaults[ $key ]
				);
			}
		}
		foreach ( Modules::classes() as $class ) {
			if ( ! Modules::provides( $class, 'settings_schema' ) ) { continue; }
			foreach ( (array) $class::settings_schema() as $key => $def ) {
				if ( ! array_key_exists( $key, $defaults ) || isset( $entries[ $key ] ) || ! is_array( $def ) ) { continue; }
				$entries[ $key ] = self::normalize( $key, $def + array( 'source' => 'module', 'module' => $class ), $defaults[ $key ] );
			}
		}
		foreach ( self::internal() as $key => $def ) {
			if ( array_key_exists( $key, $defaults ) && ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = self::normalize( $key, $def + array( 'internal' => true, 'source' => 'core' ), $defaults[ $key ] );
			}
		}
		/**
		 * Settings register (SET-PC-01): add entries for add-on settings or add help to existing entries.
		 * Type, default, secret and limits of existing entries cannot be changed; keys outside
		 * Settings::defaults() are ignored (register new keys with wp_pass_captain_settings_defaults_v1).
		 *
		 * @param array $entries key => entry.
		 */
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'wp_pass_captain_settings_schema_v1', $entries ) : $entries;
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $key => $def ) {
				$key = (string) $key;
				if ( ! array_key_exists( $key, $defaults ) || ! is_array( $def ) ) { continue; }
				if ( isset( $entries[ $key ] ) ) {
					// Only texts and flags can be added to an existing entry.
					foreach ( array( 'label', 'help' ) as $text ) {
						if ( '' === $entries[ $key ][ $text ] && isset( $def[ $text ] ) && is_string( $def[ $text ] ) ) { $entries[ $key ][ $text ] = $def[ $text ]; }
					}
					continue;
				}
				$entries[ $key ] = self::normalize( $key, $def + array( 'source' => 'addon' ), $defaults[ $key ] );
			}
		}
		foreach ( $defaults as $key => $fallback ) {
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = self::normalize( (string) $key, array( 'described' => false, 'source' => 'unknown' ), $fallback );
			}
		}
		self::$cache = $entries;
		return $entries;
	}

	/** One entry or null for an unknown key. */
	public static function get( $key ) {
		$all = self::all();
		return $all[ (string) $key ] ?? null;
	}

	/** Keys without a description (label and help); the test keeps this list to the documented backlog. */
	public static function undescribed() {
		return array_keys( array_filter( self::all(), static function ( $entry ) { return empty( $entry['described'] ); } ) );
	}

	/**
	 * Checks one value against its entry. Reports instead of correcting: out-of-range numbers, unknown
	 * choices, malformed amounts and addresses are errors naming the field.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Submitted value (form input, CLI or import).
	 * @return mixed|\WP_Error The value to store or an error.
	 */
	public static function validate( $key, $value ) {
		$entry = self::get( $key );
		if ( null === $entry ) {
			/* translators: %s: setting key */
			return new \WP_Error( 'wpcap_setting_unknown', sprintf( __( 'Unbekannte Einstellung „%s“.', 'pass-captain' ), (string) $key ) );
		}
		return self::check_value( $entry, $value, (string) $key );
	}

	/**
	 * Checks the sub-values of a group setting against the field descriptions of its entry (SET-PC-01:
	 * module pages validate through the register, no form checks on its own). Only submitted fields are
	 * checked; fields without a description pass unchanged.
	 *
	 * @param string $key Group setting key (e.g. 'waitlist').
	 * @param array  $raw Submitted sub-values.
	 * @return array|\WP_Error Checked sub-values, or one error listing every invalid field.
	 */
	public static function check_group( $key, array $raw ) {
		$entry = self::get( $key );
		if ( null === $entry ) { return self::validate( $key, $raw ); }
		$out = array();
		$errors = array();
		foreach ( $raw as $field => $value ) {
			$def = $entry['fields'][ $field ] ?? null;
			if ( ! is_array( $def ) ) { $out[ $field ] = $value; continue; }
			$sub = self::normalize( (string) $field, $def, $entry['default'][ $field ] ?? null );
			// An emptied number field means "back to the default", as on the core settings screen.
			if ( 'int' === $sub['type'] && ( null === $value || '' === $value ) ) { $value = $sub['default'] ?? $sub['min']; }
			$checked = self::check_value( $sub, $value, (string) $field );
			if ( is_wp_error( $checked ) ) { $errors[] = $checked->get_error_message(); continue; }
			$out[ $field ] = $checked;
		}
		return $errors ? new \WP_Error( 'wpcap_setting_invalid', implode( ' ', $errors ), array( 'messages' => $errors ) ) : $out;
	}

	/**
	 * Checks several top-level settings at once (module forms, CLI, import).
	 *
	 * @param array $raw key => submitted value.
	 * @return array|\WP_Error key => value to store, or one error listing every invalid field.
	 */
	public static function check_many( array $raw ) {
		$out = array();
		$errors = array();
		foreach ( $raw as $key => $value ) {
			$entry = self::get( $key );
			$checked = null !== $entry && 'group' === $entry['type'] && is_array( $value ) ? self::check_group( (string) $key, $value ) : self::validate( (string) $key, $value );
			if ( is_wp_error( $checked ) ) { $errors[] = $checked->get_error_message(); continue; }
			$out[ $key ] = $checked;
		}
		return $errors ? new \WP_Error( 'wpcap_setting_invalid', implode( ' ', $errors ), array( 'messages' => $errors ) ) : $out;
	}

	/** Checks one value against a (sub-)entry; see validate(). */
	private static function check_value( array $entry, $value, $key ) {
		$label = '' !== $entry['label'] ? $entry['label'] : (string) $key;
		switch ( $entry['type'] ) {
			case 'int':
				if ( is_bool( $value ) || ! is_scalar( $value ) || ! preg_match( '/^\s*-?\d+\s*$/', (string) $value ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_int', sprintf( __( '„%s“ muss eine ganze Zahl sein.', 'pass-captain' ), $label ) );
				}
				$int = (int) $value;
				if ( $int < (int) $entry['min'] || $int > (int) $entry['max'] ) {
					/* translators: 1: field label, 2: smallest allowed value, 3: largest allowed value */
					return new \WP_Error( 'wpcap_setting_range', sprintf( __( '„%1$s“ muss zwischen %2$d und %3$d liegen.', 'pass-captain' ), $label, (int) $entry['min'], (int) $entry['max'] ) );
				}
				return $int;
			case 'bool':
				return empty( $value ) ? 0 : 1;
			case 'choice':
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( ! array_key_exists( $value, (array) $entry['options'] ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_choice', sprintf( __( '„%s“: Dieser Wert steht nicht zur Auswahl.', 'pass-captain' ), $label ) );
				}
				return $value;
			case 'money':
				$parsed = Admin\Forms::parse_money( is_scalar( $value ) && '' !== trim( (string) $value ) ? $value : '0', 2 );
				if ( null === $parsed ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_money', sprintf( __( '„%s“ ist kein gültiger Betrag.', 'pass-captain' ), $label ) );
				}
				return $parsed;
			case 'email':
				$email = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' !== $email && ! is_email( $email ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_email', sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'pass-captain' ), $label ) );
				}
				return '' === $email ? '' : sanitize_email( $email );
			case 'iban':
				// SET-PC-28: checksum (ISO 13616, mod 97); stored without spaces, empty clears the field.
				$raw = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' === $raw ) { return ''; }
				$iban = PayoutAccount::normalize_iban( $raw );
				if ( '' === $iban ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_iban', sprintf( __( '„%s“ ist keine gültige IBAN (Prüfziffer stimmt nicht).', 'pass-captain' ), $label ) );
				}
				return $iban;
			case 'bic':
				$bic = strtoupper( preg_replace( '/\s+/', '', is_scalar( $value ) ? (string) $value : '' ) );
				if ( '' !== $bic && ! preg_match( '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_bic', sprintf( __( '„%s“ ist kein gültiger BIC (8 oder 11 Zeichen, z. B. GIBAATWWXXX).', 'pass-captain' ), $label ) );
				}
				return $bic;
			case 'url':
				$url = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' === $url ) { return ''; }
				if ( ! preg_match( '#^https?://[^\s]+$#i', $url ) || '' === esc_url_raw( $url ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_url', sprintf( __( '„%s“ muss eine vollständige Adresse mit http:// oder https:// sein.', 'pass-captain' ), $label ) );
				}
				return esc_url_raw( $url );
			case 'currency':
				$code = strtoupper( trim( is_scalar( $value ) ? (string) $value : '' ) );
				if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_currency', sprintf( __( '„%s“ muss ein Währungscode aus drei Buchstaben sein (z. B. EUR).', 'pass-captain' ), $label ) );
				}
				return $code;
			case 'currencies':
				$codes = array_values( array_unique( array_filter( array_map( static function ( $c ) { return strtoupper( trim( $c ) ); }, preg_split( '/[\s,;]+/', is_array( $value ) ? implode( ',', array_filter( $value, 'is_scalar' ) ) : ( is_scalar( $value ) ? (string) $value : '' ) ) ) ) ) );
				foreach ( $codes as $code ) {
					if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
						/* translators: 1: field label, 2: invalid code */
						return new \WP_Error( 'wpcap_setting_currencies', sprintf( __( '„%1$s“: „%2$s“ ist kein Währungscode aus drei Buchstaben.', 'pass-captain' ), $label, $code ) );
					}
				}
				return implode( ',', $codes );
			case 'html':
				return is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
			case 'textarea':
				return is_scalar( $value ) ? Text::cut( sanitize_textarea_field( (string) $value ), 5000 ) : '';
			case 'secret':
				return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
			case 'providers':
			case 'modules':
			case 'list':
			case 'group':
				if ( ! is_array( $value ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_setting_list', sprintf( __( '„%s“ erwartet eine Liste von Werten.', 'pass-captain' ), $label ) );
				}
				if ( 'list' === $entry['type'] && $entry['item'] ) {
					if ( $entry['max_items'] > 0 && count( $value ) > $entry['max_items'] ) {
						/* translators: 1: field label, 2: largest number of values */
						return new \WP_Error( 'wpcap_setting_list_size', sprintf( __( '„%1$s“: höchstens %2$d Werte.', 'pass-captain' ), $label, $entry['max_items'] ) );
					}
					$item = self::normalize( $key, $entry['item'] + array( 'label' => $label ), null );
					foreach ( $value as $i => $one ) {
						$checked = self::check_value( $item, $one, $key );
						if ( is_wp_error( $checked ) ) { return $checked; }
						$value[ $i ] = $checked;
					}
				}
				return $value;
			default:
				return is_scalar( $value ) ? Text::cut( sanitize_text_field( (string) $value ), 'trusted_proxies' === $key ? 1000 : 190 ) : '';
		}
	}

	/** Entry with every field set (types and limits checked, flags from the class lists). */
	private static function normalize( $key, array $def, $fallback ) {
		$type = (string) ( $def['type'] ?? self::type_of( $key, $fallback ) );
		if ( ! in_array( $type, self::TYPES, true ) ) { $type = self::type_of( $key, $fallback ); }
		$label = (string) ( $def['label'] ?? '' );
		$help = (string) ( $def['help'] ?? ( $def['description'] ?? '' ) );
		$entry = array(
			'key' => (string) $key,
			'type' => $type,
			'default' => $fallback,
			'min' => 'int' === $type ? (int) ( $def['min'] ?? 0 ) : null,
			'max' => 'int' === $type ? (int) ( $def['max'] ?? PHP_INT_MAX ) : null,
			'options' => 'choice' === $type && is_array( $def['options'] ?? null ) ? $def['options'] : array(),
			'tab' => (string) ( $def['tab'] ?? '' ),
			'section' => (string) ( $def['section'] ?? '' ),
			'page' => (string) ( $def['page'] ?? '' ),
			'label' => $label,
			'help' => $help,
			'legal' => (bool) ( $def['legal'] ?? in_array( $key, self::LEGAL, true ) ),
			'secret' => (bool) ( $def['secret'] ?? ( 'secret' === $type || Settings::is_secret( $key ) ) ),
			'country' => (bool) ( $def['country'] ?? in_array( $key, self::COUNTRY, true ) ),
			'capability' => (string) ( $def['capability'] ?? Admin\SettingsPage::CAP ),
			'source' => (string) ( $def['source'] ?? 'core' ),
			'module' => (string) ( $def['module'] ?? '' ),
			'internal' => ! empty( $def['internal'] ),
			'fields' => 'group' === $type && is_array( $def['fields'] ?? null ) ? $def['fields'] : array(),
			'item' => 'list' === $type && is_array( $def['item'] ?? null ) ? $def['item'] : array(),
			'max_items' => 'list' === $type ? (int) ( $def['max_items'] ?? 0 ) : 0,
		);
		// Described: a label, and for anything a person edits also a help text or a self-explaining choice/flag.
		$entry['described'] = ( $def['described'] ?? true ) && '' !== $label && ( '' !== $help || in_array( $type, array( 'bool', 'choice' ), true ) || $entry['internal'] );
		return $entry;
	}

	/** Type guessed from the default (only for keys nobody described). */
	private static function type_of( $key, $fallback ) {
		if ( preg_match( '/(secret|password|token|_key$|api_key)/', (string) $key ) ) { return 'secret'; }
		if ( is_array( $fallback ) ) { return 'group'; }
		if ( is_bool( $fallback ) ) { return 'bool'; }
		if ( is_int( $fallback ) ) { return 'int'; }
		return 'text';
	}

	/** Keys the plugin maintains itself (no form field on the settings screen). */
	private static function internal() {
		return array(
			'legal_texts' => array(
				'type' => 'group',
				'label' => __( 'Rechtstexte je Sprache', 'pass-captain' ),
				'help' => __( 'Übersetzungen von AGB und Verbraucherinformationen je Sprache; bearbeitet im Reiter „Rechtliches“.', 'pass-captain' ),
				'tab' => 'legal',
				'page' => 'settings',
			),
			'payment_poll_after_minutes' => array(
				'type' => 'int',
				'min' => 1,
				'max' => 1440,
				'label' => __( 'Zahlungsstatus nachfragen nach (Minuten)', 'pass-captain' ),
				'help' => __( 'Nach dieser Zeit fragt Pass Captain den Zahlungsanbieter selbst nach dem Status, falls keine Benachrichtigung (Webhook) eingegangen ist.', 'pass-captain' ),
			),
			'client_ip_header' => array(
				'type' => 'text',
				'label' => __( 'Header der Client-Adresse (intern)', 'pass-captain' ),
				'help' => __( 'Wird aus „Header mit der Client-Adresse“ abgeleitet und nicht direkt bearbeitet.', 'pass-captain' ),
			),
		);
	}
}
