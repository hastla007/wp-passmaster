<?php
/**
 * Settings screen (manage_options). Covers every key of Settings::defaults(); keys added later
 * without a definition here are rendered generically under "Weitere Einstellungen".
 *
 * The screen is split into tabs (?tab=general|admission|payments|legal|invoices). A form only
 * contains the fields of its tab (on the payments tab: of one provider, ?section=…), and save()
 * only touches the keys of the submitted tab/section, so saving one tab never resets another.
 * Legal texts are kept per language (Settings::legal_texts; default language in the plain keys).
 *
 * Secrets are never echoed back: they are kept encrypted (PassCaptain\Secrets), the form shows
 * only whether a value is stored (with its last four characters), an empty input keeps the stored
 * value, and a checkbox deletes it. WPCAP_* constants/environment variables override stored values
 * and are shown as such.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
use PassCaptain\Mail\Mailer;
use PassCaptain\Payments\Registry;
use PassCaptain\Settings;
use PassCaptain\Modules;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SettingsPage {
	const SLUG = 'wpcap-settings';
	const CAP = 'manage_options';

	/** Field definitions grouped by section. */
	public static function sections() {
		return array(
			'general' => array(
				'title' => __( 'Allgemein / Reservierung', 'wp-pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'hold_minutes' => array( 'type' => 'int', 'label' => __( 'Reservierungsdauer im Checkout (Minuten)', 'wp-pass-captain' ), 'min' => 1, 'max' => 120 ),
					'auto_refund_cancelled' => array( 'type' => 'bool', 'label' => __( 'Bei Terminabsage bezahlte Bestellungen automatisch erstatten', 'wp-pass-captain' ) ),
					'campaign_max_codes' => array( 'type' => 'int', 'label' => __( 'Rabattcodes je Bestellung (höchstens)', 'wp-pass-captain' ), 'min' => 1, 'max' => 5 ),
					'campaign_stacking' => array(
						'type' => 'choice',
						'label' => __( 'Mehrere Rabattcodes', 'wp-pass-captain' ),
						'options' => array(
							'cumulative' => __( 'nacheinander anwenden (jeder Code auf den Restbetrag, Reihenfolge nach Priorität)', 'wp-pass-captain' ),
							'best' => __( 'nur den günstigsten Code anwenden', 'wp-pass-captain' ),
						),
						'description' => __( 'Codes, die als „nicht kombinierbar“ angelegt sind, gelten immer nur allein.', 'wp-pass-captain' ),
					),
					'guestlist_four_eyes' => array( 'type' => 'bool', 'label' => __( 'Vier-Augen-Prinzip für Freikarten (Gästeliste: wer beantragt, darf nicht selbst freigeben; Administratoren ausgenommen)', 'wp-pass-captain' ) ),
				),
			),
			'scanner' => array(
				'title' => __( 'Einlass / Scanner-Geräte', 'wp-pass-captain' ),
				'tab' => 'admission',
				'fields' => array(
					'scanner_show_attendee' => array( 'type' => 'bool', 'label' => __( 'Teilnehmernamen beim Scannen anzeigen', 'wp-pass-captain' ) ),
					'device_max_hours' => array( 'type' => 'int', 'label' => __( 'Maximale Gültigkeit einer Gerätekopplung (Stunden)', 'wp-pass-captain' ), 'min' => 1, 'max' => 720 ),
					'admission_day_cutoff_hour' => array( 'type' => 'int', 'label' => __( 'Einlass-Tageswechsel (Uhrzeit, volle Stunde)', 'wp-pass-captain' ), 'min' => 0, 'max' => 12, 'description' => __( '0 = Mitternacht. Beispiel 6: Scans bis 05:59 Uhr zählen noch zum Vortag (Festivals, Nachtveranstaltungen). Gilt für Tagestickets und Tageslimits.', 'wp-pass-captain' ) ),
					'default_entry_window' => array( 'type' => 'bool', 'label' => __( 'Standard-Einlassfenster für Ticketarten ohne eigene Einlassregel', 'wp-pass-captain' ) ),
					'entry_opens_before_minutes' => array( 'type' => 'int', 'label' => __( 'Einlass ab (Minuten vor Beginn)', 'wp-pass-captain' ), 'min' => 0, 'max' => 10080 ),
					'entry_closes_after_minutes' => array( 'type' => 'int', 'label' => __( 'Einlass bis (Minuten nach Ende; ohne Ende: nach Ende des Veranstaltungstags)', 'wp-pass-captain' ), 'min' => 0, 'max' => 10080 ),
				),
			),
			'payments' => array(
				'title' => __( 'Übersicht', 'wp-pass-captain' ),
				'tab' => 'payments',
				'fields' => array(
					'enabled_providers' => array( 'type' => 'providers', 'label' => __( 'Aktive Zahlungsarten', 'wp-pass-captain' ) ),
				),
			),
			'manual' => array(
				'title' => __( 'Überweisung', 'wp-pass-captain' ),
				'tab' => 'payments',
				'provider' => 'manual',
				'fields' => array(
					'manual_instructions' => array( 'type' => 'textarea', 'label' => __( 'Hinweis für manuelle Zahlung (z. B. Bankverbindung)', 'wp-pass-captain' ) ),
					'bank_recipient' => array( 'type' => 'text', 'label' => __( 'Überweisung: Empfänger (Kontoinhaber)', 'wp-pass-captain' ), 'description' => __( 'Leer = Name / Firma des Rechnungsstellers.', 'wp-pass-captain' ) ),
					'bank_iban' => array( 'type' => 'text', 'label' => __( 'Überweisung: IBAN', 'wp-pass-captain' ), 'description' => __( 'Wird nach der Bestellung und in der Bestell-E-Mail mit Betrag, Verwendungszweck und Zahlungsfrist angezeigt.', 'wp-pass-captain' ) ),
					'bank_bic' => array( 'type' => 'text', 'label' => __( 'Überweisung: BIC', 'wp-pass-captain' ) ),
					'manual_hold_hours' => array( 'type' => 'int', 'label' => __( 'Reservierung bei manueller Zahlung (Stunden)', 'wp-pass-captain' ), 'min' => 1, 'max' => 720, 'description' => __( 'Endet spätestens mit Beginn des (ersten) Termins.', 'wp-pass-captain' ) ),
					'manual_cutoff_hours' => array( 'type' => 'int', 'label' => __( 'Überweisung nur bis … Stunden vor Beginn anbieten', 'wp-pass-captain' ), 'min' => 0, 'max' => 720, 'description' => __( '0 = bis zum Beginn. Empfohlen: 24–48 Stunden, damit die Zahlung rechtzeitig eingeht.', 'wp-pass-captain' ) ),
				),
			),
			'stripe' => array(
				'title' => __( 'Stripe', 'wp-pass-captain' ),
				'tab' => 'payments',
				'provider' => 'stripe',
				'webhook' => 'stripe',
				'fields' => array(
					'stripe_mode' => array( 'type' => 'choice', 'label' => __( 'Modus', 'wp-pass-captain' ), 'options' => array( 'test' => __( 'Test', 'wp-pass-captain' ), 'live' => __( 'Live', 'wp-pass-captain' ) ) ),
					'stripe_secret_key' => array( 'type' => 'secret', 'label' => __( 'Secret Key', 'wp-pass-captain' ) ),
					'stripe_webhook_secret' => array( 'type' => 'secret', 'label' => __( 'Webhook-Signing-Secret', 'wp-pass-captain' ) ),
				),
			),
			'paypal' => array(
				'title' => __( 'PayPal', 'wp-pass-captain' ),
				'tab' => 'payments',
				'provider' => 'paypal',
				'webhook' => 'paypal',
				'fields' => array(
					'paypal_mode' => array( 'type' => 'choice', 'label' => __( 'Modus', 'wp-pass-captain' ), 'options' => array( 'sandbox' => __( 'Sandbox', 'wp-pass-captain' ), 'live' => __( 'Live', 'wp-pass-captain' ) ) ),
					'paypal_client_id' => array( 'type' => 'text', 'label' => __( 'Client-ID', 'wp-pass-captain' ), 'overridable' => true ),
					'paypal_client_secret' => array( 'type' => 'secret', 'label' => __( 'Client-Secret', 'wp-pass-captain' ) ),
					'paypal_webhook_id' => array( 'type' => 'text', 'label' => __( 'Webhook-ID', 'wp-pass-captain' ), 'overridable' => true ),
				),
			),
			'legal' => array(
				'title' => __( 'Rechtstexte', 'wp-pass-captain' ),
				'tab' => 'legal',
				'fields' => array(
					'require_terms' => array( 'type' => 'bool', 'label' => __( 'Zustimmung im Checkout verlangen', 'wp-pass-captain' ) ),
					'terms_text' => array( 'type' => 'html', 'label' => __( 'AGB / Teilnahmebedingungen', 'wp-pass-captain' ) ),
					'consumer_info_text' => array( 'type' => 'html', 'label' => __( 'Verbraucherinformationen (z. B. Widerrufshinweis)', 'wp-pass-captain' ) ),
				),
			),
			'refunds' => array(
				'title' => __( 'Erstattungen', 'wp-pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'refund_approval_threshold' => array( 'type' => 'money', 'label' => __( 'Zweite Freigabe ab Betrag', 'wp-pass-captain' ), 'description' => __( '0 = keine zweite Freigabe. Betrag in der Bestellwährung, z. B. 100,00.', 'wp-pass-captain' ) ),
					'require_2fa_for_refunds' => array( 'type' => 'bool', 'label' => __( 'Zwei-Faktor-Anmeldung für Erstattungen verlangen', 'wp-pass-captain' ) ),
				),
			),
			'vouchers' => array(
				'title' => __( 'Gutscheine', 'wp-pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'voucher_default_purpose' => array(
						'type' => 'choice',
						'label' => __( 'Gutscheine standardmäßig als Mehrzweckgutschein behandeln', 'wp-pass-captain' ),
						'options' => array(
							'multi' => __( 'Ja – Mehrzweckgutschein (Zahlungsmittel, USt bei Einlösung)', 'wp-pass-captain' ),
							'single' => __( 'Nein – Einzweckgutschein (USt bereits beim Gutscheinverkauf)', 'wp-pass-captain' ),
						),
						'description' => __( 'Gilt nur, wenn WP Voucher Captain zum Gutschein keine Art meldet. Mehrzweckgutschein: Tickets behalten den vollen Preis und die volle Umsatzsteuer, der Gutschein erscheint auf der Rechnung als Zahlung. Einzweckgutschein (Ort der Leistung und Steuersatz standen beim Verkauf fest, z. B. Gutschein für eine bestimmte Veranstaltung im Inland): die Umsatzsteuer wurde schon beim Gutscheinverkauf abgeführt, die Einlösung mindert den Preis. Im Zweifel mit der Steuerberatung klären (siehe docs/VOUCHERS.md).', 'wp-pass-captain' ),
					),
				),
			),
			'privacy' => array(
				'title' => __( 'Datenschutz', 'wp-pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'attendee_retention_days' => array( 'type' => 'int', 'label' => __( 'Teilnehmerdaten nach dem Event löschen nach (Tagen)', 'wp-pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( '0 = Feldeinstellung verwenden.', 'wp-pass-captain' ) ),
					'attendee_edit_hours' => array( 'type' => 'int', 'label' => __( 'Teilnehmerdaten änderbar bis (Stunden vor Beginn)', 'wp-pass-captain' ), 'min' => 0, 'max' => 720 ),
				),
			),
			'seller' => array(
				'title' => __( 'Rechnungssteller', 'wp-pass-captain' ),
				'tab' => 'invoices',
				'fields' => array(
					'seller_name' => array( 'type' => 'text', 'label' => __( 'Name / Firma', 'wp-pass-captain' ) ),
					'seller_address' => array( 'type' => 'textarea', 'label' => __( 'Anschrift', 'wp-pass-captain' ) ),
					'seller_vat_id' => array( 'type' => 'text', 'label' => __( 'USt-IdNr.', 'wp-pass-captain' ) ),
					'seller_tax_number' => array( 'type' => 'text', 'label' => __( 'Steuernummer', 'wp-pass-captain' ), 'description' => __( 'Pflichtangabe auf Rechnungen, wenn keine USt-IdNr. angegeben ist.', 'wp-pass-captain' ) ),
					'seller_country' => array( 'type' => 'text', 'label' => __( 'Land (ISO-Code, z. B. DE oder AT)', 'wp-pass-captain' ), 'description' => __( 'Wird für die Reverse-Charge-Prüfung bei Veranstaltern im EU-Ausland verwendet (siehe docs/SALES-MODELS.md).', 'wp-pass-captain' ) ),
					'seller_small_business' => array( 'type' => 'bool', 'label' => __( 'Kleinunternehmer (keine Umsatzsteuer auf eigenen Rechnungen)', 'wp-pass-captain' ), 'description' => __( 'Rechnungen der Plattform tragen dann den Kleinunternehmer-Hinweis und werden verweigert, solange Ticketarten Umsatzsteuer enthalten (Ticketarten mit 0 % anlegen).', 'wp-pass-captain' ) ),
					'seller_small_business_note' => array( 'type' => 'text', 'label' => __( 'Kleinunternehmer-Hinweis (leer = Standardtext für das Land)', 'wp-pass-captain' ), 'description' => __( 'DE: „Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.“ – AT: „Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gemäß § 6 Abs. 1 Z 27 UStG.“', 'wp-pass-captain' ) ),
					'invoice_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix Rechnungsnummer', 'wp-pass-captain' ) ),
					'billing_required_threshold_minor' => array( 'type' => 'money', 'label' => __( 'Name und Anschrift im Checkout verlangen ab Bestellbetrag (0 = nie; DE: 250 €, AT: 400 €)', 'wp-pass-captain' ) ),
					'credit_note_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix Rechnungskorrektur (Storno-/Korrekturbeleg)', 'wp-pass-captain' ) ),
					'auto_invoice' => array( 'type' => 'bool', 'label' => __( 'Rechnung nach Zahlung automatisch erstellen', 'wp-pass-captain' ) ),
				),
			),
			'uninstall' => array(
				'title' => __( 'Deinstallation', 'wp-pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'delete_data_on_uninstall' => array( 'type' => 'bool', 'label' => __( 'Beim Löschen des Plugins alle Daten entfernen', 'wp-pass-captain' ), 'description' => __( 'Achtung: Events, Bestellungen, Tickets und Rechnungen werden unwiderruflich gelöscht. Gesetzliche Aufbewahrungspflichten beachten.', 'wp-pass-captain' ) ),
				),
			),
		);
	}

	/** Definitions for every key in Settings::defaults(), including generic ones for unknown keys. */
	public static function field_definitions() {
		$defaults = Settings::defaults();
		$sections = self::sections();
		foreach ( $sections as $id => $section ) {
			$sections[ $id ]['fields'] = array_intersect_key( $section['fields'], $defaults );
		}
		$known = array();
		foreach ( $sections as $section ) { $known += $section['fields']; }
		$other = array();
		// Module settings are edited on the module's own page; listing them here too would duplicate them.
		$module_keys = Modules::settings_defaults();
		foreach ( $defaults as $key => $default ) {
			if ( isset( $known[ $key ] ) || isset( $module_keys[ $key ] ) || is_array( $default ) ) { continue; }
			if ( preg_match( '/(secret|password|token|_key$|api_key)/', $key ) ) {
				$other[ $key ] = array( 'type' => 'secret', 'label' => $key );
			} elseif ( is_bool( $default ) ) {
				$other[ $key ] = array( 'type' => 'bool', 'label' => $key );
			} elseif ( is_int( $default ) ) {
				$other[ $key ] = array( 'type' => 'int', 'label' => $key, 'min' => 0, 'max' => 1000000 );
			} else {
				$other[ $key ] = array( 'type' => 'text', 'label' => $key );
			}
		}
		if ( $other ) {
			$sections['other'] = array( 'title' => __( 'Weitere Einstellungen', 'wp-pass-captain' ), 'tab' => 'general', 'fields' => $other );
		}
		return array_filter( $sections, static function ( $section ) { return ! empty( $section['fields'] ); } );
	}

	/** Tabs of the screen: id => title. */
	public static function tabs() {
		return array(
			'general' => __( 'Allgemein', 'wp-pass-captain' ),
			'admission' => __( 'Einlass', 'wp-pass-captain' ),
			'payments' => __( 'Zahlungsarten', 'wp-pass-captain' ),
			'legal' => __( 'Rechtstexte', 'wp-pass-captain' ),
			'invoices' => __( 'Rechnungen', 'wp-pass-captain' ),
		);
	}

	/** Valid tab id ('' = unknown). */
	public static function tab( $tab ) {
		$tab = sanitize_key( is_scalar( $tab ) ? (string) $tab : '' );
		return isset( self::tabs()[ $tab ] ) ? $tab : '';
	}

	/**
	 * Sections shown/saved for a tab. On the payments tab only one section is used at a time
	 * (overview or one provider); an unknown section falls back to the overview.
	 *
	 * @param string|null $tab     Tab id; null = all sections (full form without tab marker, e.g.
	 *                             scripts/tests); an unknown tab id yields no section.
	 * @param string      $section Section id within the tab (payments only).
	 */
	public static function scoped_sections( $tab = null, $section = '' ) {
		$sections = self::field_definitions();
		if ( null === $tab ) { return $sections; }
		$tab = self::tab( $tab );
		if ( '' === $tab ) { return array(); }
		$sections = array_filter( $sections, static function ( $def ) use ( $tab ) { return ( $def['tab'] ?? 'general' ) === $tab; } );
		if ( 'payments' === $tab ) {
			$section = sanitize_key( is_scalar( $section ) ? (string) $section : '' );
			$section = isset( $sections[ $section ] ) ? $section : 'payments';
			return isset( $sections[ $section ] ) ? array( $section => $sections[ $section ] ) : array();
		}
		return $sections;
	}

	/**
	 * Settings page URL of a payment provider: its section on the payments tab, or a URL a module
	 * registers via wpcap_payment_settings_url (e.g. Mollie's own page); '' if there is none.
	 */
	public static function provider_url( $provider_id ) {
		foreach ( self::field_definitions() as $id => $section ) {
			if ( ( $section['provider'] ?? '' ) === $provider_id ) { return Forms::page_url( self::SLUG, array( 'tab' => 'payments', 'section' => $id ) ); }
		}
		return (string) apply_filters( 'wpcap_payment_settings_url', '', (string) $provider_id );
	}

	/** Name of the constant/environment variable that overrides a key, or '' if none is set. */
	public static function override_source( $key ) {
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return $constant; }
		$env = getenv( $constant );
		return is_string( $env ) && '' !== $env ? $constant : '';
	}

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/**
	 * Sanitises and stores submitted settings.
	 *
	 * @return array|\WP_Error ['saved' => values written, 'errors' => list of messages for invalid fields]
	 */
	public static function save( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$current = Settings::all();
		$values = array();
		$errors = array();
		// Only the submitted tab (and provider section) is processed: checkboxes of other tabs are
		// not in the form, and treating them as unticked would reset them.
		$sections = self::scoped_sections( array_key_exists( 'wpcap_tab', $in ) ? (string) ( is_scalar( $in['wpcap_tab'] ) ? $in['wpcap_tab'] : '' ) : null, $in['wpcap_section'] ?? '' );
		foreach ( $sections as $section_id => $section ) {
			if ( ! empty( $section['provider'] ) && isset( $in['wpcap_provider_toggle'] ) ) {
				$values['enabled_providers'] = self::toggle_provider( (array) $current['enabled_providers'], (string) $section['provider'], Forms::flag( $in, 'wpcap_provider_enabled' ) );
			}
			if ( 'legal' === $section_id && isset( $in['legal_texts'] ) ) {
				$values['legal_texts'] = self::sanitize_legal_texts( $in['legal_texts'], $in['legal_new_locale'] ?? '' );
			}
			foreach ( $section['fields'] as $key => $def ) {
				$raw = $in[ $key ] ?? null;
				switch ( $def['type'] ) {
					case 'int':
						if ( null === $raw || '' === $raw ) { break; }
						if ( ! is_scalar( $raw ) || ! preg_match( '/^\s*\d+\s*$/', (string) $raw ) ) {
							/* translators: %s: field label */
							$errors[] = sprintf( __( '„%s“ muss eine ganze Zahl sein.', 'wp-pass-captain' ), $def['label'] );
							break;
						}
						$values[ $key ] = max( (int) $def['min'], min( (int) $def['max'], (int) $raw ) );
						break;
					case 'bool':
						$values[ $key ] = Forms::flag( $in, $key ) ? 1 : 0;
						break;
					case 'choice':
						$values[ $key ] = Forms::choice( $in, $key, array_map( 'strval', array_keys( $def['options'] ) ), (string) $current[ $key ] );
						break;
					case 'providers':
						$available = array_keys( Registry::all() );
						$values[ $key ] = array_values( array_intersect( $available, Forms::ids_or_keys( $in, $key ) ) );
						break;
					case 'textarea':
						$values[ $key ] = Forms::textarea( $in, $key, 5000 );
						break;
					case 'html':
						$values[ $key ] = is_scalar( $raw ) ? wp_kses_post( (string) $raw ) : '';
						break;
					case 'money':
						$parsed = Forms::parse_money( is_scalar( $raw ) && '' !== trim( (string) $raw ) ? $raw : '0', 2 );
						if ( null === $parsed ) {
							/* translators: %s: field label */
							$errors[] = sprintf( __( '„%s“ ist kein gültiger Betrag.', 'wp-pass-captain' ), $def['label'] );
							break;
						}
						$values[ $key ] = $parsed;
						break;
					case 'prefix':
						$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', is_scalar( $raw ) ? (string) $raw : '' ) );
						$values[ $key ] = '' !== $prefix ? substr( $prefix, 0, 10 ) : (string) Settings::defaults()[ $key ];
						break;
					case 'secret':
						if ( Forms::flag( $in, $key . '__clear' ) ) {
							$values[ $key ] = '';
						} elseif ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
							$values[ $key ] = trim( sanitize_text_field( (string) $raw ) );
						}
						break;
					default:
						$values[ $key ] = Forms::text( $in, $key, 190 );
				}
			}
		}
		$changed = array();
		foreach ( $values as $key => $value ) {
			// Secrets are compared with the encrypted store (Settings::all() never contains them).
			$before = \PassCaptain\Secrets::is_secret( $key ) ? \PassCaptain\Secrets::stored( $key ) : ( $current[ $key ] ?? null );
			if ( $before != $value ) { $changed[] = $key; } // phpcs:ignore Universal.Operators.StrictComparisons -- stored types may differ.
		}
		Settings::update( $values );
		if ( $changed ) {
			// Only key names are logged, never values.
			Audit::record( 'settings_updated', 'settings', 0, array( 'keys' => $changed ) );
		}
		// The result never carries secret values back (only which secrets were written).
		$secrets = array_intersect_key( $values, array_flip( \PassCaptain\Secrets::KEYS ) );
		$saved = array_diff_key( $values, $secrets );
		$saved['secrets_updated'] = array_keys( $secrets );
		return array( 'saved' => $saved, 'errors' => $errors );
	}

	/** enabled_providers with $provider switched on/off (order of the registry kept). */
	private static function toggle_provider( array $enabled, $provider, $on ) {
		$enabled = array_values( array_diff( array_map( 'strval', $enabled ), array( $provider ) ) );
		if ( $on && Registry::get( $provider ) ) { $enabled[] = $provider; }
		return array_values( array_intersect( array_keys( Registry::all() ), $enabled ) );
	}

	/**
	 * Legal texts of the further languages from the form: legal_texts[locale][terms_text|consumer_info_text].
	 * Languages without any text are dropped; the default language is kept in the plain keys.
	 *
	 * @param mixed $raw        Submitted array.
	 * @param mixed $new_locale Optional additional locale (gets an empty entry so it shows up in the form).
	 */
	public static function sanitize_legal_texts( $raw, $new_locale = '' ) {
		$default = Settings::legal_default_locale();
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $locale => $texts ) {
			$locale = Mailer::normalize_locale( (string) $locale );
			if ( '' === $locale || $locale === $default || ! is_array( $texts ) ) { continue; }
			foreach ( Settings::LEGAL_KEYS as $key ) {
				$text = isset( $texts[ $key ] ) && is_scalar( $texts[ $key ] ) ? trim( wp_kses_post( (string) $texts[ $key ] ) ) : '';
				if ( '' !== $text ) { $out[ $locale ][ $key ] = $text; }
			}
		}
		$new_locale = Mailer::normalize_locale( is_scalar( $new_locale ) ? trim( (string) $new_locale ) : '' );
		if ( '' !== $new_locale && $new_locale !== $default && ! isset( $out[ $new_locale ] ) ) {
			$out[ $new_locale ] = array_fill_keys( Settings::LEGAL_KEYS, '' );
		}
		ksort( $out );
		return $out;
	}

	public static function handle_save() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_settings' );
		$result = self::save( $in );
		if ( ! is_wp_error( $result ) && $result['errors'] ) {
			$result['warning'] = implode( ' ', $result['errors'] );
		}
		Forms::finish( $result, __( 'Einstellungen gespeichert.', 'wp-pass-captain' ), Forms::page_url( self::SLUG, self::url_args( $in['wpcap_tab'] ?? '', $in['wpcap_section'] ?? '' ) ) );
	}

	/** Query args of a tab (and payments section) for redirects and links. */
	public static function url_args( $tab, $section = '' ) {
		$tab = self::tab( $tab );
		if ( '' === $tab ) { return array(); }
		$args = array( 'tab' => $tab );
		if ( 'payments' === $tab ) {
			$id = (string) array_key_first( self::scoped_sections( $tab, $section ) );
			if ( '' !== $id && 'payments' !== $id ) { $args['section'] = $id; }
		}
		return $args;
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	/**
	 * Renders one tab. WordPress calls this without arguments (tab/section then come from the URL);
	 * tests pass them directly.
	 */
	public static function render( $tab = '', $section = '' ) {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'wp-pass-captain' ), 403 ); }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( ! is_string( $tab ) || '' === $tab ) { $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; }
		if ( ! is_string( $section ) || '' === $section ) { $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : ''; }
		// phpcs:enable
		$tab = self::tab( $tab );
		$tab = '' !== $tab ? $tab : 'general';
		$sections = self::scoped_sections( $tab, $section );
		$section = (string) array_key_first( $sections );
		$values = Settings::all();
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Pass Captain Einstellungen', 'wp-pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( 'general' === $tab ) { \PassCaptain\Integrations\Vouchers\IntegrationStatus::render(); } ?>
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Einstellungsbereiche', 'wp-pass-captain' ); ?>">
			<?php foreach ( self::tabs() as $id => $title ) : ?>
				<a href="<?php echo esc_url( Forms::page_url( self::SLUG, array( 'tab' => $id ) ) ); ?>" class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $title ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( 'payments' === $tab ) { self::render_payment_nav( $section ); } ?>
		<?php Forms::open_form( 'wpcap_save_settings' ); ?>
		<input type="hidden" name="wpcap_tab" value="<?php echo esc_attr( $tab ); ?>">
		<?php if ( 'payments' === $tab ) : ?><input type="hidden" name="wpcap_section" value="<?php echo esc_attr( $section ); ?>"><?php endif; ?>
		<?php foreach ( $sections as $id => $def ) : ?>
			<h2 id="wpcap-settings-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $def['title'] ); ?></h2>
			<?php if ( ! empty( $def['provider'] ) ) { self::render_provider_status( (string) $def['provider'], $values ); } ?>
			<?php if ( ! empty( $def['webhook'] ) ) : ?>
				<p><?php esc_html_e( 'Webhook-URL:', 'wp-pass-captain' ); ?> <code><?php echo esc_html( rest_url( 'wp-pass-captain/v1/payments/' . $def['webhook'] . '/webhook' ) ); ?></code></p>
			<?php endif; ?>
			<?php if ( 'legal' === $id ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: locale code */ __( 'Die folgenden Texte gelten für die Standardsprache (%s) und für jede Sprache ohne eigene Fassung.', 'wp-pass-captain' ), Settings::legal_default_locale() ) ); ?></p>
			<?php endif; ?>
			<table class="form-table"><tbody>
			<?php foreach ( $def['fields'] as $key => $field ) : ?>
				<tr>
					<th scope="row"><label for="wpcap-set-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td><?php self::render_field( $key, $field, $values[ $key ] ?? null ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php if ( 'legal' === $id ) { self::render_legal_translations(); } ?>
		<?php endforeach; ?>
		<?php submit_button( __( 'Einstellungen speichern', 'wp-pass-captain' ) ); ?>
		</form>
		</div>
		<?php
	}

	/** Sub-navigation of the payments tab: overview, one entry per provider. */
	private static function render_payment_nav( $current ) {
		$items = array( 'payments' => array( __( 'Übersicht', 'wp-pass-captain' ), Forms::page_url( self::SLUG, array( 'tab' => 'payments' ) ) ) );
		foreach ( self::field_definitions() as $id => $def ) {
			if ( ( $def['tab'] ?? '' ) === 'payments' && ! empty( $def['provider'] ) ) {
				$items[ $id ] = array( $def['title'], Forms::page_url( self::SLUG, array( 'tab' => 'payments', 'section' => $id ) ) );
			}
		}
		// Providers of modules/add-ons with their own settings page (e.g. Mollie).
		foreach ( Registry::all() as $provider_id => $gateway ) {
			$known = false;
			foreach ( self::field_definitions() as $def ) { $known = $known || ( $def['provider'] ?? '' ) === $provider_id; }
			$url = $known ? '' : self::provider_url( $provider_id );
			if ( '' !== $url ) { $items[ 'ext-' . $provider_id ] = array( $gateway->title(), $url ); }
		}
		echo '<ul class="subsubsub" style="float:none">';
		$last = array_key_last( $items );
		foreach ( $items as $id => $item ) {
			$active = $id === $current;
			echo '<li><a href="' . esc_url( $item[1] ) . '"' . ( $active ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $item[0] ) . '</a>' . ( $id === $last ? '' : ' |' ) . ' </li>';
		}
		echo '</ul>';
	}

	/** Status line and on/off switch of one provider (writes enabled_providers for this provider only). */
	private static function render_provider_status( $provider_id, array $values ) {
		$gateway = Registry::get( $provider_id );
		if ( ! $gateway ) { return; }
		$enabled = in_array( $provider_id, (array) ( $values['enabled_providers'] ?? array() ), true );
		echo '<input type="hidden" name="wpcap_provider_toggle" value="1">';
		echo '<p><label><input type="checkbox" name="wpcap_provider_enabled" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html( sprintf( /* translators: %s: payment method */ __( '%s im Checkout anbieten', 'wp-pass-captain' ), $gateway->title() ) ) . '</label>';
		if ( $enabled && ! $gateway->is_available( 'EUR' ) ) {
			echo ' <em>' . esc_html__( '(nicht vollständig konfiguriert)', 'wp-pass-captain' ) . '</em>';
		}
		echo '</p>';
	}

	/** Legal texts of the further languages (one block per locale) plus a field to add a language. */
	private static function render_legal_translations() {
		$default = Settings::legal_default_locale();
		$stored = Settings::legal_translations();
		$raw = (array) Settings::get( 'legal_texts' );
		echo '<h2 id="wpcap-settings-legal-languages">' . esc_html__( 'Rechtstexte je Sprache', 'wp-pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Käufer sehen die Texte ihrer Sprache (Sprache der Seite bzw. von WPML/Polylang). Leere Felder übernehmen den Text der Standardsprache. Jede Sprache hat eine eigene Version, die mit der Bestellung gespeichert wird.', 'wp-pass-captain' ) . '</p>';
		foreach ( Settings::legal_locales() as $locale ) {
			if ( $locale === $default ) { continue; }
			$texts = isset( $raw[ $locale ] ) && is_array( $raw[ $locale ] ) ? $raw[ $locale ] : array();
			echo '<h3 id="wpcap-legal-' . esc_attr( $locale ) . '">' . esc_html( $locale ) . '</h3>';
			echo '<table class="form-table"><tbody>';
			foreach ( array( 'terms_text' => __( 'AGB / Teilnahmebedingungen', 'wp-pass-captain' ), 'consumer_info_text' => __( 'Verbraucherinformationen (z. B. Widerrufshinweis)', 'wp-pass-captain' ) ) as $key => $label ) {
				$field_id = 'wpcap-legal-' . $locale . '-' . $key;
				echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label></th><td>';
				echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( 'legal_texts[' . $locale . '][' . $key . ']' ) . '" class="large-text" rows="6">' . esc_textarea( isset( $texts[ $key ] ) && is_scalar( $texts[ $key ] ) ? (string) $texts[ $key ] : '' ) . '</textarea>';
				if ( ! isset( $stored[ $locale ][ $key ] ) ) {
					/* translators: %s: locale code */
					echo '<p class="description">' . esc_html( sprintf( __( 'Leer: es gilt der Text von %s.', 'wp-pass-captain' ), Settings::legal_source_locale( $key, $locale ) ) ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			if ( '' !== Settings::terms_version( $locale ) ) {
				/* translators: 1: locale code, 2: version hash */
				echo '<p class="description">' . esc_html( sprintf( __( 'Aktuelle Version für %1$s: %2$s', 'wp-pass-captain' ), $locale, Settings::terms_version( $locale ) ) ) . '</p>';
			}
		}
		// Keeps the key in the request even without further languages, so saving can drop the last one.
		echo '<input type="hidden" name="legal_texts[]" value="">';
		echo '<p><label for="wpcap-legal-new-locale">' . esc_html__( 'Weitere Sprache hinzufügen (Locale, z. B. en_US):', 'wp-pass-captain' ) . '</label> <input type="text" id="wpcap-legal-new-locale" name="legal_new_locale" size="12" pattern="[a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2}"></p>';
	}

	private static function render_field( $key, array $def, $value ) {
		$id = 'wpcap-set-' . $key;
		// Secrets show their own source/status line (Secrets::render_field()).
		$override = ( 'secret' !== $def['type'] && ! empty( $def['overridable'] ) ) ? self::override_source( $key ) : '';
		switch ( $def['type'] ) {
			case 'int':
				echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" min="' . esc_attr( $def['min'] ) . '"' . ( PHP_INT_MAX !== $def['max'] ? ' max="' . esc_attr( $def['max'] ) . '"' : '' ) . ' value="' . esc_attr( (int) $value ) . '">';
				break;
			case 'bool':
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html__( 'Aktiv', 'wp-pass-captain' ) . '</label>';
				break;
			case 'choice':
				Forms::select( $key, $def['options'], (string) $value, $id );
				break;
			case 'providers':
				$enabled = (array) $value;
				foreach ( Registry::all() as $provider_id => $gateway ) {
					echo '<div style="min-height:24px;padding:2px 0"><label><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $provider_id ) . '"' . checked( in_array( $provider_id, $enabled, true ), true, false ) . '> ' . esc_html( $gateway->title() ) . ' <code>' . esc_html( $provider_id ) . '</code>';
					if ( in_array( $provider_id, $enabled, true ) && ! $gateway->is_available( 'EUR' ) ) {
						echo ' <em>' . esc_html__( '(nicht vollständig konfiguriert)', 'wp-pass-captain' ) . '</em>';
					}
					echo '</label>';
					$url = self::provider_url( $provider_id );
					if ( '' !== $url ) {
						/* translators: %s: payment method */
						echo ' – <a href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( '%s einrichten', 'wp-pass-captain' ), $gateway->title() ) ) . '</a>';
					}
					echo '</div>';
				}
				break;
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="large-text" rows="4">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'html':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="large-text" rows="8">' . esc_textarea( (string) $value ) . '</textarea>';
				$version = 'terms_text' === $key ? Settings::terms_version( Settings::legal_default_locale() ) : '';
				if ( '' !== $version ) {
					echo '<p class="description">' . esc_html( sprintf( /* translators: %s: version hash */ __( 'Aktuelle Version: %s (wird mit jeder Bestellung gespeichert)', 'wp-pass-captain' ), $version ) ) . '</p>';
				}
				break;
			case 'money':
				echo '<input type="text" inputmode="decimal" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" size="10" value="' . esc_attr( Forms::money_input( (int) $value, 2 ) ) . '">';
				break;
			case 'prefix':
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" maxlength="10" size="10" pattern="[A-Za-z0-9\-]+" value="' . esc_attr( (string) $value ) . '">';
				break;
			case 'secret':
				// Masked: status with the last four characters, empty input keeps the value (Secrets).
				if ( \PassCaptain\Secrets::is_secret( $key ) ) {
					\PassCaptain\Secrets::render_field( $key, $id );
					break;
				}
				// Secret-like key of an extension that is not in the encrypted store: still never echoed.
				echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr( '' !== (string) $value ? __( 'Gespeichert – leer lassen, um beizubehalten', 'wp-pass-captain' ) : __( 'Nicht gesetzt', 'wp-pass-captain' ) ) . '">';
				if ( '' !== (string) $value ) {
					echo ' <label><input type="checkbox" name="' . esc_attr( $key . '__clear' ) . '" value="1"> ' . esc_html__( 'Gespeicherten Wert löschen', 'wp-pass-captain' ) . '</label>';
				}
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" value="' . esc_attr( (string) $value ) . '">';
		}
		if ( '' !== $override ) {
			/* translators: %s: constant name */
			echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Wird durch %s (wp-config.php oder Umgebungsvariable) überschrieben; der hier gespeicherte Wert wird nicht verwendet.', 'wp-pass-captain' ), $override ) ) . '</strong></p>';
		}
		if ( ! empty( $def['description'] ) ) {
			echo '<p class="description">' . esc_html( $def['description'] ) . '</p>';
		}
	}
}
