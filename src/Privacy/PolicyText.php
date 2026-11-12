<?php
/**
 * Suggested privacy policy text (Settings → Privacy → policy guide), built from the active
 * configuration: payment providers, SMS, wallet, partners, webhooks and Affiliate Captain only
 * appear when they are enabled. It is a template for the operator, not legal advice.
 */
namespace PassCaptain\Privacy;

use PassCaptain\Privacy;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PolicyText {
	/** admin_init: registers the suggestion with WordPress. */
	public static function add() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }
		wp_add_privacy_policy_content( 'WP Pass Captain', wp_kses_post( self::content() ) );
	}

	/** Recipients that are active in this installation (keys used in content()). */
	public static function recipients() {
		$settings = Settings::all();
		$providers = (array) ( $settings['enabled_providers'] ?? array() );
		$sms = (array) ( $settings['sms'] ?? array() );
		$out = array(
			'stripe' => in_array( 'stripe', $providers, true ),
			'paypal' => in_array( 'paypal', $providers, true ),
			'mollie' => in_array( 'mollie', $providers, true ),
			'twilio' => 'twilio' === ( $sms['provider'] ?? '' ),
			'sms_http' => 'http' === ( $sms['provider'] ?? '' ),
			'apple' => ! empty( $settings['wallet_apple_enabled'] ),
			'google' => ! empty( $settings['wallet_google_enabled'] ),
			'partners' => self::rows( 'wpcap_partners', "status='active'" ),
			'webhooks' => self::rows( 'wpcap_webhook_subscriptions', "status='active'" ),
			'affiliate' => class_exists( '\PassCaptain\Integrations\AffiliateCaptain\Adapter' ) && \PassCaptain\Integrations\AffiliateCaptain\Adapter::available(),
			'woocommerce' => class_exists( 'WooCommerce' ),
		);
		return (array) apply_filters( 'wpcap_privacy_policy_recipients', $out );
	}

	private static function rows( $suffix, $where ) {
		if ( ! Privacy::has_table( $suffix ) ) { return false; }
		global $wpdb;
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}{$suffix} WHERE $where LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static.
	}

	private static function section( $title, array $paragraphs ) {
		$html = '<h3>' . esc_html( $title ) . '</h3>';
		foreach ( $paragraphs as $paragraph ) { $html .= '<p>' . esc_html( $paragraph ) . '</p>'; }
		return $html;
	}

	private static function items( array $items ) {
		return '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
	}

	public static function content() {
		$r = self::recipients();
		$days = Retention::attendee_days();
		$attendee = $days > 0
			/* translators: %d: days */
			? sprintf( __( '%d Tage nach dem Ende der Veranstaltung', 'wp-pass-captain' ), $days )
			/* translators: %d: days */
			: sprintf( __( 'nach der je Feld angegebenen Frist (Name und E-Mail der Teilnehmenden: %d Tage) nach dem Ende der Veranstaltung', 'wp-pass-captain' ), Retention::default_attendee_days() );

		$html = '<p class="privacy-policy-tutorial">' . esc_html__( 'Vorschlag von WP Pass Captain. Bitte prüfen und an Ihr Unternehmen, Ihre Verträge und Ihre tatsächlichen Abläufe anpassen; der Text ersetzt keine Rechtsberatung. Abschnitte zu Dienstleistern erscheinen nur, wenn diese in den Einstellungen aktiviert sind.', 'wp-pass-captain' ) . '</p>';

		$html .= self::section( __( 'Ticketkauf, Tickets und Einlass', 'wp-pass-captain' ), array(
			__( 'Für Bestellungen verarbeiten wir Ihre E-Mail-Adresse, die gekauften Tickets, Preise, Zahlungsstatus und die Version der akzeptierten Bedingungen, um den Vertrag zu erfüllen, Tickets und Bestellzugänge per E-Mail zuzustellen und Sie über Änderungen oder Absagen zu informieren (Art. 6 Abs. 1 lit. b DSGVO).', 'wp-pass-captain' ),
			__( 'Angaben zu Teilnehmenden (Name, E-Mail, weitere vom Veranstalter abgefragte Felder mit dem jeweils angegebenen Zweck) verarbeiten wir zur Durchführung der Veranstaltung (Art. 6 Abs. 1 lit. b DSGVO) bzw. aufgrund unseres berechtigten Interesses an einer geordneten Einlasskontrolle (Art. 6 Abs. 1 lit. f DSGVO). Beim Einlass protokollieren wir Zeitpunkt, Ergebnis und gegebenenfalls den Einlassbereich des Tickets, um Mehrfachnutzung zu verhindern (Art. 6 Abs. 1 lit. f DSGVO).', 'wp-pass-captain' ),
			__( 'Wartelisteneinträge, Mitgliedspässe und Terminbuchungen verarbeiten wir, um Ihnen den gewünschten Platz anzubieten bzw. die Leistung zu erbringen (Art. 6 Abs. 1 lit. b DSGVO). Zum Schutz vor Missbrauch (z. B. automatisierte Bestellversuche) verarbeiten wir die IP-Adresse kurzzeitig nur als verschlüsselten Hashwert in Zählern, die nach spätestens einer Stunde verfallen (Art. 6 Abs. 1 lit. f DSGVO).', 'wp-pass-captain' ),
			__( 'Rechnungen und Buchungsbelege bewahren wir aufgrund gesetzlicher Pflichten auf (Art. 6 Abs. 1 lit. c DSGVO).', 'wp-pass-captain' ),
		) );

		$recipients = array();
		if ( $r['woocommerce'] ) { $recipients[] = __( 'WooCommerce (auf diesem Server): Bestellungen über den Shop werden zusätzlich in WooCommerce gespeichert; dort gelten die Datenschutzhinweise und Löschfristen des Shops.', 'wp-pass-captain' ); }
		if ( $r['stripe'] ) { $recipients[] = __( 'Stripe Payments Europe Ltd. (Irland) für Kartenzahlungen und weitere Zahlarten: Bestellreferenz, Betrag, E-Mail-Adresse; Stripe verarbeitet Zahlungsdaten auch in den USA.', 'wp-pass-captain' ); }
		if ( $r['paypal'] ) { $recipients[] = __( 'PayPal (Europe) S.à r.l. et Cie, S.C.A. (Luxemburg) für PayPal-Zahlungen: Bestellreferenz und Betrag; PayPal kann Daten auch in den USA verarbeiten.', 'wp-pass-captain' ); }
		if ( $r['mollie'] ) { $recipients[] = __( 'Mollie B.V. (Niederlande) für die Zahlungsabwicklung: Bestellreferenz, Betrag und gegebenenfalls das Rechnungsland; Zahlungsdaten geben Sie direkt bei Mollie ein.', 'wp-pass-captain' ); }
		if ( $r['twilio'] ) { $recipients[] = __( 'Twilio Ireland Ltd. (Irland) für den SMS-Versand: Mobilnummer und Nachrichtentext, nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), jederzeit widerrufbar; Twilio verarbeitet Daten auch in den USA.', 'wp-pass-captain' ); }
		if ( $r['sms_http'] ) { $recipients[] = __( 'Unser SMS-Dienstleister (Auftragsverarbeiter) für den SMS-Versand: Mobilnummer und Nachrichtentext, nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), jederzeit widerrufbar.', 'wp-pass-captain' ); }
		if ( $r['apple'] ) { $recipients[] = __( 'Apple Inc. (USA), wenn Sie ein Ticket zu Apple Wallet hinzufügen: Ihr Gerät meldet sich für Aktualisierungen an; wir speichern dafür eine Gerätekennung und ein Push-Token und senden Aktualisierungshinweise über den Apple Push Notification Service.', 'wp-pass-captain' ); }
		if ( $r['google'] ) { $recipients[] = __( 'Google Ireland Ltd. / Google LLC (USA), wenn Sie ein Ticket zu Google Wallet hinzufügen: Ticketangaben (Veranstaltung, Termin, Ticketcode, gegebenenfalls Name) werden an Google übermittelt.', 'wp-pass-captain' ); }
		if ( $r['partners'] ) { $recipients[] = __( 'Vertriebs- und Veranstaltungspartner: Partner erhalten für ihre Veranstaltungen Bestell- und Einlassdaten, personenbezogene Daten (E-Mail-Adressen) nur, soweit dies vertraglich vereinbart ist (Art. 6 Abs. 1 lit. b bzw. f DSGVO, gegebenenfalls Auftragsverarbeitung).', 'wp-pass-captain' ); }
		if ( $r['webhooks'] ) { $recipients[] = __( 'Angebundene Systeme (Webhooks), z. B. Buchhaltung, CRM oder Newsletter-Dienst: Bestell- und Ticketereignisse, E-Mail-Adressen nur, wenn dies für das jeweilige System ausdrücklich aktiviert ist.', 'wp-pass-captain' ); }
		if ( $r['affiliate'] ) { $recipients[] = __( 'Affiliate-Programm (WP Affiliate Captain, auf diesem Server): Wenn Sie über einen Partnerlink kommen oder einen Partnercode eingeben, ordnen wir die Bestellung dem Partner zu, um dessen Provision abzurechnen. Den Empfehlungs-Cookie werten wir nur mit Ihrer Einwilligung aus (Art. 6 Abs. 1 lit. a DSGVO, § 165 Abs. 3 TKG 2021 bzw. § 25 TDDDG); bei einem selbst eingegebenen Code auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO. Ihre E-Mail-Adresse wird nur zur Prüfung von Eigenempfehlungen verwendet und dort nicht gespeichert.', 'wp-pass-captain' ); }
		$recipients[] = __( 'Unser Hosting- und E-Mail-Dienstleister als Auftragsverarbeiter.', 'wp-pass-captain' );
		$html .= '<h3>' . esc_html__( 'Empfänger', 'wp-pass-captain' ) . '</h3>' . self::items( $recipients );

		if ( $r['stripe'] || $r['paypal'] || $r['twilio'] || $r['apple'] || $r['google'] ) {
			$html .= self::section( __( 'Übermittlung in Drittländer', 'wp-pass-captain' ), array(
				__( 'Einige der genannten Dienstleister verarbeiten Daten auch in den USA. Die Übermittlung erfolgt auf Grundlage des EU-U.S. Data Privacy Framework (Angemessenheitsbeschluss, Art. 45 DSGVO), soweit der Empfänger zertifiziert ist, im Übrigen auf Grundlage der EU-Standardvertragsklauseln (Art. 46 Abs. 2 lit. c DSGVO).', 'wp-pass-captain' ),
			) );
		}

		$html .= self::section( __( 'Speicherdauer', 'wp-pass-captain' ), array(
			/* translators: %s: retention description */
			sprintf( __( 'Teilnehmerangaben (Name, E-Mail, Zusatzfelder) löschen wir automatisch %s. Einlassprotokolle bleiben ohne Teilnehmerangaben für Auswertungen erhalten; die Zuordnung zu Mitarbeitenden und Geräten entfernen wir nach einem Jahr.', 'wp-pass-captain' ), $attendee ),
			__( 'Nicht bezahlte Bestellungen anonymisieren wir nach 90 Tagen. Versandte Benachrichtigungen löschen wir inhaltlich nach 30 Tagen. Protokolle über Verwaltungsvorgänge bewahren wir zwei Jahre auf.', 'wp-pass-captain' ),
			__( 'Bestell-, Zahlungs- und Rechnungsdaten bewahren wir für die gesetzlichen Fristen auf: in Österreich sieben Jahre (§ 132 BAO), in Deutschland bis zu zehn Jahre (§ 147 AO, § 257 HGB). Während dieser Zeit sind diese Daten für andere Zwecke gesperrt.', 'wp-pass-captain' ),
		) );

		$html .= self::section( __( 'Ihre Rechte', 'wp-pass-captain' ), array(
			__( 'Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie auf Widerruf erteilter Einwilligungen mit Wirkung für die Zukunft. Bei einer Löschung bleiben Rechnungen und Buchungsdaten bis zum Ablauf der gesetzlichen Aufbewahrungsfrist gespeichert (Art. 17 Abs. 3 lit. b DSGVO); Tickets für künftige Termine werden mit der Löschung ungültig. Sie können sich bei einer Datenschutzaufsichtsbehörde beschweren.', 'wp-pass-captain' ),
		) );
		return (string) apply_filters( 'wpcap_privacy_policy_content', $html, $r );
	}
}
