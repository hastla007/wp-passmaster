<?php
/**
 * Settings screen (manage_options). Covers every key of Settings::defaults(); keys added later
 * without a definition here are rendered generically under "Weitere Einstellungen".
 *
 * Secrets are never echoed back: the form shows only whether a value is stored, an empty input
 * keeps the stored value, and a checkbox removes it. WPCAP_* constants/environment variables
 * override stored values and are shown as such.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Audit;
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
				'title' => __( 'Allgemein / Reservierung', 'pass-captain' ),
				'fields' => array(
					'hold_minutes' => array( 'type' => 'int', 'label' => __( 'Reservierungsdauer im Checkout (Minuten)', 'pass-captain' ), 'min' => 1, 'max' => 120 ),
					'manual_hold_hours' => array( 'type' => 'int', 'label' => __( 'Reservierung bei manueller Zahlung (Stunden)', 'pass-captain' ), 'min' => 1, 'max' => 720 ),
					'auto_refund_cancelled' => array( 'type' => 'bool', 'label' => __( 'Bei Terminabsage bezahlte Bestellungen automatisch erstatten', 'pass-captain' ) ),
				),
			),
			'scanner' => array(
				'title' => __( 'Einlass / Scanner-Geräte', 'pass-captain' ),
				'fields' => array(
					'scanner_show_attendee' => array( 'type' => 'bool', 'label' => __( 'Teilnehmernamen beim Scannen anzeigen', 'pass-captain' ) ),
					'device_max_hours' => array( 'type' => 'int', 'label' => __( 'Maximale Gültigkeit einer Gerätekopplung (Stunden)', 'pass-captain' ), 'min' => 1, 'max' => 720 ),
					'admission_day_cutoff_hour' => array( 'type' => 'int', 'label' => __( 'Einlass-Tageswechsel (Uhrzeit, volle Stunde)', 'pass-captain' ), 'min' => 0, 'max' => 12, 'description' => __( '0 = Mitternacht. Beispiel 6: Scans bis 05:59 Uhr zählen noch zum Vortag (Festivals, Nachtveranstaltungen). Gilt für Tagestickets und Tageslimits.', 'pass-captain' ) ),
					'default_entry_window' => array( 'type' => 'bool', 'label' => __( 'Standard-Einlassfenster für Ticketarten ohne eigene Einlassregel', 'pass-captain' ) ),
					'entry_opens_before_minutes' => array( 'type' => 'int', 'label' => __( 'Einlass ab (Minuten vor Beginn)', 'pass-captain' ), 'min' => 0, 'max' => 10080 ),
					'entry_closes_after_minutes' => array( 'type' => 'int', 'label' => __( 'Einlass bis (Minuten nach Ende; ohne Ende: nach Ende des Veranstaltungstags)', 'pass-captain' ), 'min' => 0, 'max' => 10080 ),
				),
			),
			'payments' => array(
				'title' => __( 'Zahlungsarten', 'pass-captain' ),
				'fields' => array(
					'enabled_providers' => array( 'type' => 'providers', 'label' => __( 'Aktive Zahlungsarten', 'pass-captain' ) ),
					'manual_instructions' => array( 'type' => 'textarea', 'label' => __( 'Hinweis für manuelle Zahlung (z. B. Bankverbindung)', 'pass-captain' ) ),
					'bank_recipient' => array( 'type' => 'text', 'label' => __( 'Überweisung: Empfänger (Kontoinhaber)', 'pass-captain' ), 'description' => __( 'Leer = Name / Firma des Rechnungsstellers.', 'pass-captain' ) ),
					'bank_iban' => array( 'type' => 'text', 'label' => __( 'Überweisung: IBAN', 'pass-captain' ), 'description' => __( 'Wird nach der Bestellung und in der Bestell-E-Mail mit Betrag, Verwendungszweck und Zahlungsfrist angezeigt.', 'pass-captain' ) ),
					'bank_bic' => array( 'type' => 'text', 'label' => __( 'Überweisung: BIC', 'pass-captain' ) ),
				),
			),
			'stripe' => array(
				'title' => __( 'Stripe', 'pass-captain' ),
				'webhook' => 'stripe',
				'fields' => array(
					'stripe_mode' => array( 'type' => 'choice', 'label' => __( 'Modus', 'pass-captain' ), 'options' => array( 'test' => __( 'Test', 'pass-captain' ), 'live' => __( 'Live', 'pass-captain' ) ) ),
					'stripe_secret_key' => array( 'type' => 'secret', 'label' => __( 'Secret Key', 'pass-captain' ) ),
					'stripe_webhook_secret' => array( 'type' => 'secret', 'label' => __( 'Webhook-Signing-Secret', 'pass-captain' ) ),
				),
			),
			'paypal' => array(
				'title' => __( 'PayPal', 'pass-captain' ),
				'webhook' => 'paypal',
				'fields' => array(
					'paypal_mode' => array( 'type' => 'choice', 'label' => __( 'Modus', 'pass-captain' ), 'options' => array( 'sandbox' => __( 'Sandbox', 'pass-captain' ), 'live' => __( 'Live', 'pass-captain' ) ) ),
					'paypal_client_id' => array( 'type' => 'text', 'label' => __( 'Client-ID', 'pass-captain' ), 'overridable' => true ),
					'paypal_client_secret' => array( 'type' => 'secret', 'label' => __( 'Client-Secret', 'pass-captain' ) ),
					'paypal_webhook_id' => array( 'type' => 'text', 'label' => __( 'Webhook-ID', 'pass-captain' ), 'overridable' => true ),
				),
			),
			'legal' => array(
				'title' => __( 'Rechtstexte', 'pass-captain' ),
				'fields' => array(
					'terms_text' => array( 'type' => 'html', 'label' => __( 'AGB / Teilnahmebedingungen', 'pass-captain' ) ),
					'consumer_info_text' => array( 'type' => 'html', 'label' => __( 'Verbraucherinformationen (z. B. Widerrufshinweis)', 'pass-captain' ) ),
					'require_terms' => array( 'type' => 'bool', 'label' => __( 'Zustimmung im Checkout verlangen', 'pass-captain' ) ),
				),
			),
			'refunds' => array(
				'title' => __( 'Erstattungen', 'pass-captain' ),
				'fields' => array(
					'refund_approval_threshold' => array( 'type' => 'money', 'label' => __( 'Zweite Freigabe ab Betrag', 'pass-captain' ), 'description' => __( '0 = keine zweite Freigabe. Betrag in der Bestellwährung, z. B. 100,00.', 'pass-captain' ) ),
					'require_2fa_for_refunds' => array( 'type' => 'bool', 'label' => __( 'Zwei-Faktor-Anmeldung für Erstattungen verlangen', 'pass-captain' ) ),
				),
			),
			'vouchers' => array(
				'title' => __( 'Gutscheine', 'pass-captain' ),
				'fields' => array(
					'voucher_default_purpose' => array(
						'type' => 'choice',
						'label' => __( 'Gutscheine standardmäßig als Mehrzweckgutschein behandeln', 'pass-captain' ),
						'options' => array(
							'multi' => __( 'Ja – Mehrzweckgutschein (Zahlungsmittel, USt bei Einlösung)', 'pass-captain' ),
							'single' => __( 'Nein – Einzweckgutschein (USt bereits beim Gutscheinverkauf)', 'pass-captain' ),
						),
						'description' => __( 'Gilt nur, wenn WP Voucher Captain zum Gutschein keine Art meldet. Mehrzweckgutschein: Tickets behalten den vollen Preis und die volle Umsatzsteuer, der Gutschein erscheint auf der Rechnung als Zahlung. Einzweckgutschein (Ort der Leistung und Steuersatz standen beim Verkauf fest, z. B. Gutschein für eine bestimmte Veranstaltung im Inland): die Umsatzsteuer wurde schon beim Gutscheinverkauf abgeführt, die Einlösung mindert den Preis. Im Zweifel mit der Steuerberatung klären (siehe docs/VOUCHERS.md).', 'pass-captain' ),
					),
				),
			),
			'privacy' => array(
				'title' => __( 'Datenschutz', 'pass-captain' ),
				'fields' => array(
					'attendee_retention_days' => array( 'type' => 'int', 'label' => __( 'Teilnehmerdaten nach dem Event löschen nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( '0 = Feldeinstellung verwenden.', 'pass-captain' ) ),
					'attendee_edit_hours' => array( 'type' => 'int', 'label' => __( 'Teilnehmerdaten änderbar bis (Stunden vor Beginn)', 'pass-captain' ), 'min' => 0, 'max' => 720 ),
				),
			),
			'seller' => array(
				'title' => __( 'Rechnungssteller', 'pass-captain' ),
				'fields' => array(
					'seller_name' => array( 'type' => 'text', 'label' => __( 'Name / Firma', 'pass-captain' ) ),
					'seller_address' => array( 'type' => 'textarea', 'label' => __( 'Anschrift', 'pass-captain' ) ),
					'seller_vat_id' => array( 'type' => 'text', 'label' => __( 'USt-IdNr.', 'pass-captain' ) ),
					'seller_tax_number' => array( 'type' => 'text', 'label' => __( 'Steuernummer', 'pass-captain' ), 'description' => __( 'Pflichtangabe auf Rechnungen, wenn keine USt-IdNr. angegeben ist.', 'pass-captain' ) ),
					'seller_country' => array( 'type' => 'text', 'label' => __( 'Land (ISO-Code, z. B. DE oder AT)', 'pass-captain' ), 'description' => __( 'Wird für die Reverse-Charge-Prüfung bei Veranstaltern im EU-Ausland verwendet (siehe docs/SALES-MODELS.md).', 'pass-captain' ) ),
					'invoice_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix Rechnungsnummer', 'pass-captain' ) ),
					'billing_required_threshold_minor' => array( 'type' => 'money', 'label' => __( 'Name und Anschrift im Checkout verlangen ab Bestellbetrag (0 = nie; DE: 250 €, AT: 400 €)', 'pass-captain' ) ),
					'credit_note_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix Rechnungskorrektur (Storno-/Korrekturbeleg)', 'pass-captain' ) ),
					'auto_invoice' => array( 'type' => 'bool', 'label' => __( 'Rechnung nach Zahlung automatisch erstellen', 'pass-captain' ) ),
				),
			),
			'uninstall' => array(
				'title' => __( 'Deinstallation', 'pass-captain' ),
				'fields' => array(
					'delete_data_on_uninstall' => array( 'type' => 'bool', 'label' => __( 'Beim Löschen des Plugins alle Daten entfernen', 'pass-captain' ), 'description' => __( 'Achtung: Events, Bestellungen, Tickets und Rechnungen werden unwiderruflich gelöscht. Gesetzliche Aufbewahrungspflichten beachten.', 'pass-captain' ) ),
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
			$sections['other'] = array( 'title' => __( 'Weitere Einstellungen', 'pass-captain' ), 'fields' => $other );
		}
		return array_filter( $sections, static function ( $section ) { return ! empty( $section['fields'] ); } );
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
		foreach ( self::field_definitions() as $section ) {
			foreach ( $section['fields'] as $key => $def ) {
				$raw = $in[ $key ] ?? null;
				switch ( $def['type'] ) {
					case 'int':
						if ( null === $raw || '' === $raw ) { break; }
						if ( ! is_scalar( $raw ) || ! preg_match( '/^\s*\d+\s*$/', (string) $raw ) ) {
							/* translators: %s: field label */
							$errors[] = sprintf( __( '„%s“ muss eine ganze Zahl sein.', 'pass-captain' ), $def['label'] );
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
							$errors[] = sprintf( __( '„%s“ ist kein gültiger Betrag.', 'pass-captain' ), $def['label'] );
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
		Settings::update( $values );
		$changed = array();
		foreach ( $values as $key => $value ) {
			if ( ( $current[ $key ] ?? null ) != $value ) { $changed[] = $key; } // phpcs:ignore Universal.Operators.StrictComparisons -- stored types may differ.
		}
		if ( $changed ) {
			Audit::record( 'settings_updated', 'settings', 0, array( 'keys' => $changed ) );
		}
		return array( 'saved' => $values, 'errors' => $errors );
	}

	public static function handle_save() {
		$in = Forms::post();
		Forms::verify( 'wpcap_save_settings' );
		$result = self::save( $in );
		if ( ! is_wp_error( $result ) && $result['errors'] ) {
			$result['warning'] = implode( ' ', $result['errors'] );
		}
		Forms::finish( $result, __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG ) );
	}

	/* =============================================================================================
	 * Rendering
	 * =========================================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
		$values = Settings::all();
		?>
		<div class="wrap wpcap-admin">
		<h1><?php esc_html_e( 'Pass Captain Einstellungen', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php \PassCaptain\Integrations\Vouchers\IntegrationStatus::render(); ?>
		<?php Forms::open_form( 'wpcap_save_settings' ); ?>
		<?php foreach ( self::field_definitions() as $id => $section ) : ?>
			<h2 id="wpcap-settings-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
			<?php if ( ! empty( $section['webhook'] ) ) : ?>
				<p><?php esc_html_e( 'Webhook-URL:', 'pass-captain' ); ?> <code><?php echo esc_html( rest_url( 'wp-pass-captain/v1/payments/' . $section['webhook'] . '/webhook' ) ); ?></code></p>
			<?php endif; ?>
			<table class="form-table"><tbody>
			<?php foreach ( $section['fields'] as $key => $def ) : ?>
				<tr>
					<th scope="row"><label for="wpcap-set-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def['label'] ); ?></label></th>
					<td><?php self::render_field( $key, $def, $values[ $key ] ?? null ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endforeach; ?>
		<?php submit_button( __( 'Einstellungen speichern', 'pass-captain' ) ); ?>
		</form>
		</div>
		<?php
	}

	private static function render_field( $key, array $def, $value ) {
		$id = 'wpcap-set-' . $key;
		$override = ( 'secret' === $def['type'] || ! empty( $def['overridable'] ) ) ? self::override_source( $key ) : '';
		switch ( $def['type'] ) {
			case 'int':
				echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" min="' . esc_attr( $def['min'] ) . '"' . ( PHP_INT_MAX !== $def['max'] ? ' max="' . esc_attr( $def['max'] ) . '"' : '' ) . ' value="' . esc_attr( (int) $value ) . '">';
				break;
			case 'bool':
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html__( 'Aktiv', 'pass-captain' ) . '</label>';
				break;
			case 'choice':
				Forms::select( $key, $def['options'], (string) $value, $id );
				break;
			case 'providers':
				$enabled = (array) $value;
				foreach ( Registry::all() as $provider_id => $gateway ) {
					echo '<label style="display:block;min-height:24px;padding:2px 0"><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $provider_id ) . '"' . checked( in_array( $provider_id, $enabled, true ), true, false ) . '> ' . esc_html( $gateway->title() ) . ' <code>' . esc_html( $provider_id ) . '</code>';
					if ( in_array( $provider_id, $enabled, true ) && ! $gateway->is_available( 'EUR' ) ) {
						echo ' <em>' . esc_html__( '(nicht vollständig konfiguriert)', 'pass-captain' ) . '</em>';
					}
					echo '</label>';
				}
				break;
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="large-text" rows="4">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'html':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="large-text" rows="8">' . esc_textarea( (string) $value ) . '</textarea>';
				if ( 'terms_text' === $key && '' !== Settings::terms_version() ) {
					echo '<p class="description">' . esc_html( sprintf( /* translators: %s: version hash */ __( 'Aktuelle Version: %s (wird mit jeder Bestellung gespeichert)', 'pass-captain' ), Settings::terms_version() ) ) . '</p>';
				}
				break;
			case 'money':
				echo '<input type="text" inputmode="decimal" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" size="10" value="' . esc_attr( Forms::money_input( (int) $value, 2 ) ) . '">';
				break;
			case 'prefix':
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" maxlength="10" size="10" pattern="[A-Za-z0-9\-]+" value="' . esc_attr( (string) $value ) . '">';
				break;
			case 'secret':
				$stored = '' !== (string) $value;
				echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr( $stored ? __( 'Gespeichert – leer lassen, um beizubehalten', 'pass-captain' ) : __( 'Nicht gesetzt', 'pass-captain' ) ) . '">';
				echo ' <strong>' . ( $stored ? esc_html__( 'Wert gespeichert', 'pass-captain' ) : esc_html__( 'Kein Wert gespeichert', 'pass-captain' ) ) . '</strong>';
				if ( $stored ) {
					echo ' <label><input type="checkbox" name="' . esc_attr( $key . '__clear' ) . '" value="1"> ' . esc_html__( 'Gespeicherten Wert entfernen', 'pass-captain' ) . '</label>';
				}
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" value="' . esc_attr( (string) $value ) . '">';
		}
		if ( '' !== $override ) {
			/* translators: %s: constant name */
			echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Wird durch %s (wp-config.php oder Umgebungsvariable) überschrieben; der hier gespeicherte Wert wird nicht verwendet.', 'pass-captain' ), $override ) ) . '</strong></p>';
		} elseif ( 'secret' === $def['type'] ) {
			/* translators: %s: constant name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Empfohlen: als Konstante %s in wp-config.php definieren.', 'pass-captain' ), 'WPCAP_' . strtoupper( $key ) ) ) . '</p>';
		}
		if ( ! empty( $def['description'] ) ) {
			echo '<p class="description">' . esc_html( $def['description'] ) . '</p>';
		}
	}
}
