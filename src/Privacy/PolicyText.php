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
	/** Admin screens that show the policy suggestions (guide, privacy settings, editing the policy page). */
	const SCREENS = array( 'options-privacy.php', 'privacy-policy-guide.php', 'post.php' );

	/**
	 * admin_init: registers the suggestion with WordPress – only on the screens that use it, because
	 * building the text reads settings and several tables (PERF-9: not on every admin request).
	 */
	public static function add() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }
		if ( ! in_array( (string) ( $GLOBALS['pagenow'] ?? '' ), self::SCREENS, true ) ) { return; }
		wp_add_privacy_policy_content( \PassCaptain\Brand::name( 'admin' ), wp_kses_post( self::content() ) );
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
			// Webhooks and HTTP/JSON connectors (Modules\Connectors) are both "connected systems".
			'webhooks' => self::rows( 'wpcap_webhook_subscriptions', "status='active'" ) || self::rows( 'wpcap_connector_endpoints', "status='active'" ),
			'affiliate' => class_exists( '\PassCaptain\Integrations\AffiliateCaptain\Adapter' ) && \PassCaptain\Integrations\AffiliateCaptain\Adapter::available(),
			// Fiscalization (cash register signature services such as fiskaly, fiskaltrust, efsta).
			'fiscal' => self::rows( 'wpcap_fiscal_configs', 'enabled=1' ),
			'woocommerce' => class_exists( 'WooCommerce' ),
			// Functions in use (module tables with data): sections appear only then.
			'guest_lists' => self::rows( 'wpcap_guest_lists', '1=1' ),
			'waitlist' => self::rows( 'wpcap_waitlist', '1=1' ),
			'buyer_limits' => self::rows( 'wpcap_alloc_limits', 'max_per_buyer>0' ),
			'box_office' => self::rows( 'wpcap_boxoffice_sales', '1=1' ),
			'exchange' => self::rows( 'wpcap_ticket_exchanges', '1=1' ),
			'partner_sales' => self::rows( 'wpcap_partner_orders', '1=1' ),
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

	/**
	 * Retention sentences of the given steps with the configured periods (Retention::days(), A567);
	 * a step set to 0 (off) gets its own sentence without a period.
	 *
	 * @param string[] $steps checkin_staff, unpaid_customer_email, message_content, audit_logs.
	 */
	public static function retention_sentences( array $steps ) {
		$out = array();
		foreach ( $steps as $step ) {
			$days = Retention::days( $step );
			switch ( $step ) {
				case 'checkin_staff':
					$out[] = $days > 0
						/* translators: %d: days */
						? sprintf( _n( 'Einlassprotokolle bleiben ohne Teilnehmerangaben für Auswertungen erhalten; die Zuordnung zu Mitarbeitenden und Geräten entfernen wir nach %d Tag.', 'Einlassprotokolle bleiben ohne Teilnehmerangaben für Auswertungen erhalten; die Zuordnung zu Mitarbeitenden und Geräten entfernen wir nach %d Tagen.', $days, 'pass-captain' ), $days )
						: __( 'Einlassprotokolle bleiben ohne Teilnehmerangaben für Auswertungen erhalten; für die Zuordnung zu Mitarbeitenden und Geräten ist keine automatische Löschfrist eingestellt.', 'pass-captain' );
					break;
				case 'unpaid_customer_email':
					$out[] = $days > 0
						/* translators: %d: days */
						? sprintf( _n( 'Nicht bezahlte Bestellungen anonymisieren wir nach %d Tag.', 'Nicht bezahlte Bestellungen anonymisieren wir nach %d Tagen.', $days, 'pass-captain' ), $days )
						: __( 'Für nicht bezahlte Bestellungen ist keine automatische Anonymisierung eingestellt.', 'pass-captain' );
					break;
				case 'message_content':
					$out[] = $days > 0
						/* translators: %d: days */
						? sprintf( _n( 'Versandte Benachrichtigungen löschen wir inhaltlich nach %d Tag.', 'Versandte Benachrichtigungen löschen wir inhaltlich nach %d Tagen.', $days, 'pass-captain' ), $days )
						: __( 'Für den Inhalt versandter Benachrichtigungen ist keine automatische Löschfrist eingestellt.', 'pass-captain' );
					break;
				case 'audit_logs':
					$out[] = $days > 0
						/* translators: %d: days */
						? sprintf( _n( 'Protokolle über Verwaltungsvorgänge bewahren wir %d Tag auf.', 'Protokolle über Verwaltungsvorgänge bewahren wir %d Tage auf.', $days, 'pass-captain' ), $days )
						: __( 'Für Protokolle über Verwaltungsvorgänge ist keine automatische Löschfrist eingestellt.', 'pass-captain' );
					break;
			}
		}
		return implode( ' ', $out );
	}

	/** Sentence on the automatic deletion of financial records (Retention::financial_years(), A567). */
	public static function financial_sentence() {
		$years = Retention::financial_years();
		return $years > 0
			/* translators: %d: years */
			? sprintf( _n( 'Rechnungen und Buchungsbelege löschen wir automatisch nach Ablauf des Kalenderjahres ihrer Ausstellung und %d weiteren Jahres.', 'Rechnungen und Buchungsbelege löschen wir automatisch nach Ablauf des Kalenderjahres ihrer Ausstellung und %d weiterer Jahre.', $years, 'pass-captain' ), $years )
			: __( 'Eine automatische Löschung nach Ablauf der Aufbewahrungsfrist ist nicht eingestellt.', 'pass-captain' );
	}

	public static function content() {
		$r = self::recipients();
		$days = Retention::attendee_days();
		$attendee = $days > 0
			/* translators: %d: days */
			? sprintf( _n( '%d Tag nach dem Ende der Veranstaltung', '%d Tage nach dem Ende der Veranstaltung', $days, 'pass-captain' ), $days)
			/* translators: %d: days */
			: sprintf( _n( 'nach der je Feld angegebenen Frist (Name und E-Mail der Teilnehmenden: %d Tag) nach dem Ende der Veranstaltung', 'nach der je Feld angegebenen Frist (Name und E-Mail der Teilnehmenden: %d Tage) nach dem Ende der Veranstaltung', Retention::default_attendee_days(), 'pass-captain' ), Retention::default_attendee_days());

		// A567: periods as configured (Einstellungen → Datenschutz), own wording when a step is off (0).
		$waitlist_days = Retention::days( 'waitlist' );
		$waitlist_period = $waitlist_days > 0
			/* translators: %d: days */
			? sprintf( _n( 'Die E-Mail-Adresse eines Wartelisteneintrags löschen wir %d Tag nach dem Termin.', 'Die E-Mail-Adresse eines Wartelisteneintrags löschen wir %d Tage nach dem Termin.', $waitlist_days, 'pass-captain' ), $waitlist_days )
			: __( 'Für die E-Mail-Adresse eines Wartelisteneintrags ist keine automatische Löschfrist eingestellt.', 'pass-captain' );
		$confirm_hours = (int) \PassCaptain\Waitlists::config()['confirm_hours'];
		$waitlist_text = \PassCaptain\Waitlists::double_opt_in_enabled()
			/* translators: %d: hours */
			? sprintf( _n( 'Warteliste: Die Eintragung wird erst wirksam, wenn Sie sie über den Link in unserer E-Mail bestätigen; unbestätigte Eintragungen löschen wir nach %d Stunde automatisch. Wird ein Platz frei, reservieren wir ihn automatisch für Sie und senden Ihnen ein befristetes Angebot per E-Mail; nehmen Sie es nicht an, geht es an die nächste Person.', 'Warteliste: Die Eintragung wird erst wirksam, wenn Sie sie über den Link in unserer E-Mail bestätigen; unbestätigte Eintragungen löschen wir nach %d Stunden automatisch. Wird ein Platz frei, reservieren wir ihn automatisch für Sie und senden Ihnen ein befristetes Angebot per E-Mail; nehmen Sie es nicht an, geht es an die nächste Person.', $confirm_hours, 'pass-captain' ), $confirm_hours )
			: __( 'Warteliste: Wird ein Platz frei, reservieren wir ihn automatisch für Sie und senden Ihnen ein befristetes Angebot per E-Mail; nehmen Sie es nicht an, geht es an die nächste Person.', 'pass-captain' );

		$html = '<p class="privacy-policy-tutorial">' . esc_html__( 'Vorschlag von Pass Captain. Bitte prüfen und an Ihr Unternehmen, Ihre Verträge und Ihre tatsächlichen Abläufe anpassen; der Text ersetzt keine Rechtsberatung. Abschnitte zu Dienstleistern erscheinen nur, wenn diese in den Einstellungen aktiviert sind.', 'pass-captain' ) . '</p>';

		$html .= self::section( __( 'Ticketkauf, Tickets und Einlass', 'pass-captain' ), array(
			__( 'Für Bestellungen verarbeiten wir Ihre E-Mail-Adresse, die gekauften Tickets, Preise, Zahlungsstatus und die Version der akzeptierten Bedingungen, um den Vertrag zu erfüllen, Tickets und Bestellzugänge per E-Mail zuzustellen und Sie über Änderungen oder Absagen zu informieren (Art. 6 Abs. 1 lit. b DSGVO).', 'pass-captain' ),
			__( 'Angaben zu Teilnehmenden (Name, E-Mail, weitere vom Veranstalter abgefragte Felder mit dem jeweils angegebenen Zweck) verarbeiten wir zur Durchführung der Veranstaltung (Art. 6 Abs. 1 lit. b DSGVO) bzw. aufgrund unseres berechtigten Interesses an einer geordneten Einlasskontrolle (Art. 6 Abs. 1 lit. f DSGVO). Beim Einlass protokollieren wir Zeitpunkt, Ergebnis und gegebenenfalls den Einlassbereich des Tickets, um Mehrfachnutzung zu verhindern (Art. 6 Abs. 1 lit. f DSGVO).', 'pass-captain' ),
			__( 'Wartelisteneinträge, Mitgliedspässe und Terminbuchungen verarbeiten wir, um Ihnen den gewünschten Platz anzubieten bzw. die Leistung zu erbringen (Art. 6 Abs. 1 lit. b DSGVO).', 'pass-captain' ) . ' ' . $waitlist_period . ' ' . __( 'Zum Schutz vor Missbrauch (z. B. automatisierte Bestellversuche) verarbeiten wir die IP-Adresse und gegebenenfalls die E-Mail-Adresse nur als mit einem geheimen Schlüssel gebildeten Hashwert in kurzlebigen Zählern (Art. 6 Abs. 1 lit. f DSGVO).', 'pass-captain' ),
			__( 'Rechnungen und Buchungsbelege bewahren wir aufgrund gesetzlicher Pflichten auf (Art. 6 Abs. 1 lit. c DSGVO).', 'pass-captain' ),
		) );

		$functions = array();
		if ( ! empty( $r['guest_lists'] ) ) { $functions[] = __( 'Gästelisten und Freikarten: Wenn Sie auf eine Gästeliste gesetzt werden, erhalten wir Name, gegebenenfalls E-Mail-Adresse und Firma von der Person oder Organisation, die Sie angemeldet hat (Art. 14 DSGVO). Wir verwenden sie für Freikarte und Einlass (Art. 6 Abs. 1 lit. b bzw. f DSGVO) und leeren diese Angaben nach der Veranstaltung mit der Frist für Teilnehmerangaben.', 'pass-captain' ); }
		if ( ! empty( $r['waitlist'] ) ) { $functions[] = $waitlist_text . ' ' . $waitlist_period; }
		if ( ! empty( $r['buyer_limits'] ) ) { $functions[] = __( 'Höchstmenge pro Person: Um die Höchstmenge je Käufer:in durchzusetzen, speichern wir zu Ihrer E-Mail-Adresse (und gegebenenfalls Ihrem Benutzerkonto) nur einen mit einem geheimen Schlüssel gebildeten Hashwert und die gezählten Bestellungen (Art. 6 Abs. 1 lit. f DSGVO); diese Zähler löschen wir nach dem letzten Termin mit der Frist für Teilnehmerangaben.', 'pass-captain' ); }
		if ( ! empty( $r['box_office'] ) ) { $functions[] = __( 'Abendkasse: Bei Kassenverkäufen erfassen wir Ihre E-Mail-Adresse nur, wenn Sie Tickets oder eine Rechnung zugesandt bekommen möchten, bei „Rechnung auf Firma“ zusätzlich Firma, Name, Anschrift und UID-Nummer. Kassenbelege und Kassenberichte bewahren wir aufgrund gesetzlicher Pflichten auf (Art. 6 Abs. 1 lit. c DSGVO).', 'pass-captain' ); }
		if ( ! empty( $r['exchange'] ) ) { $functions[] = __( 'Ticketumtausch: Für einen Umtausch verarbeiten wir die Bestellung, das bisherige und das neue Ticket sowie einen Aufpreis oder eine Erstattung (Art. 6 Abs. 1 lit. b DSGVO). Haben Sie per Überweisung bezahlt, können Sie für Erstattungen eine Bankverbindung angeben; wir verwenden sie nur dafür.', 'pass-captain' ); }
		if ( ! empty( $r['partner_sales'] ) ) { $functions[] = __( 'Verkauf über Partner: Kaufen Sie bei einem Vertriebspartner oder Veranstalter, der an unser System angebunden ist, übermittelt dieser uns die für Ticket und Einlass nötigen Angaben. Der Partner ist für seine eigene Verarbeitung selbst verantwortlich; Wiederverkäufer erhalten von uns nur Daten ihrer eigenen Verkäufe.', 'pass-captain' ); }
		if ( $functions ) {
			$html .= '<h3>' . esc_html__( 'Weitere Funktionen', 'pass-captain' ) . '</h3>' . self::items( $functions );
		}

		$recipients = array();
		if ( $r['woocommerce'] ) { $recipients[] = __( 'WooCommerce (auf diesem Server): Bestellungen über den Shop werden zusätzlich in WooCommerce gespeichert; dort gelten die Datenschutzhinweise und Löschfristen des Shops.', 'pass-captain' ); }
		if ( $r['stripe'] ) { $recipients[] = __( 'Stripe Payments Europe Ltd. (Irland) für Kartenzahlungen und weitere Zahlarten: Bestellreferenz, Betrag, E-Mail-Adresse; Stripe verarbeitet Zahlungsdaten auch in den USA.', 'pass-captain' ); }
		if ( $r['paypal'] ) { $recipients[] = __( 'PayPal (Europe) S.à r.l. et Cie, S.C.A. (Luxemburg) für PayPal-Zahlungen: Bestellreferenz und Betrag; PayPal kann Daten auch in den USA verarbeiten.', 'pass-captain' ); }
		if ( $r['mollie'] ) { $recipients[] = __( 'Mollie B.V. (Niederlande) für die Zahlungsabwicklung: Bestellreferenz, Betrag und gegebenenfalls das Rechnungsland; Zahlungsdaten geben Sie direkt bei Mollie ein.', 'pass-captain' ); }
		if ( $r['twilio'] ) { $recipients[] = __( 'Twilio Ireland Ltd. (Irland) für den SMS-Versand: Mobilnummer und Nachrichtentext, nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), jederzeit widerrufbar; Twilio verarbeitet Daten auch in den USA.', 'pass-captain' ); }
		if ( $r['sms_http'] ) { $recipients[] = __( 'Unser SMS-Dienstleister (Auftragsverarbeiter) für den SMS-Versand: Mobilnummer und Nachrichtentext, nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), jederzeit widerrufbar.', 'pass-captain' ); }
		if ( $r['apple'] ) { $recipients[] = __( 'Apple Inc. (USA), wenn Sie ein Ticket zu Apple Wallet hinzufügen: Ihr Gerät meldet sich für Aktualisierungen an; wir speichern dafür eine Gerätekennung und ein Push-Token und senden Aktualisierungshinweise über den Apple Push Notification Service.', 'pass-captain' ); }
		if ( $r['google'] ) { $recipients[] = __( 'Google Ireland Ltd. / Google LLC (USA), nur wenn Sie den Link „In Google Wallet speichern“ verwenden: Ticketangaben (Veranstaltung, Termin, Ticketcode, gegebenenfalls Name) werden dann an Google übermittelt und bei Änderungen aktualisiert. Wird das Ticket ungültig oder werden Ihre Daten gelöscht, entfernen wir Name, Platz und Ticketcode aus dem Ticket in Google Wallet.', 'pass-captain' ); }
		if ( $r['partners'] ) { $recipients[] = __( 'Vertriebs- und Veranstaltungspartner: Partner erhalten für ihre Veranstaltungen Bestell- und Einlassdaten, personenbezogene Daten (E-Mail-Adressen) nur, soweit dies vertraglich vereinbart ist (Art. 6 Abs. 1 lit. b bzw. f DSGVO, gegebenenfalls Auftragsverarbeitung).', 'pass-captain' ); }
		if ( $r['webhooks'] ) { $recipients[] = __( 'Angebundene Systeme (Webhooks und HTTP/JSON-Connectoren), z. B. Buchhaltung, CRM oder Newsletter-Dienst: Bestell- und Ticketereignisse; E-Mail-Adressen und Namen der Teilnehmenden nur, wenn dies für das jeweilige System ausdrücklich aktiviert ist.', 'pass-captain' ); }
		if ( ! empty( $r['fiscal'] ) ) { $recipients[] = __( 'Dienstleister für die gesetzlich vorgeschriebene Signatur von Kassenbelegen (z. B. fiskaly, fiskaltrust, efsta; Registrierkassen- bzw. Kassensicherungsverordnung): Belegdaten wie Positionen, Beträge, Steuersätze, Zahlart und Zeitpunkt, keine Kontaktdaten (Art. 6 Abs. 1 lit. c DSGVO).', 'pass-captain' ); }
		if ( $r['affiliate'] ) { $recipients[] = __( 'Affiliate-Programm (Affiliate Captain, auf diesem Server): Wenn Sie über einen Partnerlink kommen oder einen Partnercode eingeben, ordnen wir die Bestellung dem Partner zu, um dessen Provision abzurechnen. Den Empfehlungs-Cookie werten wir nur mit Ihrer Einwilligung aus (Art. 6 Abs. 1 lit. a DSGVO, § 165 Abs. 3 TKG 2021 bzw. § 25 TDDDG); bei einem selbst eingegebenen Code auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO. Ihre E-Mail-Adresse wird nur zur Prüfung von Eigenempfehlungen verwendet und dort nicht gespeichert.', 'pass-captain' ); }
		$recipients[] = __( 'Unser Hosting- und E-Mail-Dienstleister als Auftragsverarbeiter.', 'pass-captain' );
		$html .= '<h3>' . esc_html__( 'Empfänger', 'pass-captain' ) . '</h3>' . self::items( $recipients );

		if ( $r['stripe'] || $r['paypal'] || $r['twilio'] || $r['apple'] || $r['google'] ) {
			$html .= self::section( __( 'Übermittlung in Drittländer', 'pass-captain' ), array(
				__( 'Einige der genannten Dienstleister verarbeiten Daten auch in den USA. Die Übermittlung erfolgt auf Grundlage des EU-U.S. Data Privacy Framework (Angemessenheitsbeschluss, Art. 45 DSGVO), soweit der Empfänger zertifiziert ist, im Übrigen auf Grundlage der EU-Standardvertragsklauseln (Art. 46 Abs. 2 lit. c DSGVO).', 'pass-captain' ),
			) );
		}

		$pass_days = Retention::days( 'member_passes' );
		$years = Retention::order_years();
		$legal = __( 'Bestell-, Zahlungs- und Rechnungsdaten bewahren wir für die gesetzlichen Fristen auf: in Österreich sieben Jahre (§ 132 BAO), in Deutschland bis zu zehn Jahre (§ 147 AO, § 257 HGB). Während dieser Zeit sind diese Daten für andere Zwecke gesperrt.', 'pass-captain' );
		if ( $years ) {
			/* translators: %d: years */
			$legal .= ' ' . sprintf( _n( '%d Jahr nach dem Ende des Bestelljahres entfernen wir E-Mail-Adresse, Rechnungsanschrift und Teilnehmerangaben aus den Bestellungen.', '%d Jahre nach dem Ende des Bestelljahres entfernen wir E-Mail-Adresse, Rechnungsanschrift und Teilnehmerangaben aus den Bestellungen.', $years, 'pass-captain' ), $years );
		}
		$storage = array(
			/* translators: %s: retention description */
			sprintf( __( 'Teilnehmerangaben (Name, E-Mail, Zusatzfelder) löschen wir automatisch %s; Namen auf Wallet-Pässen werden dabei ebenfalls entfernt.', 'pass-captain' ), $attendee ) . ' ' . self::retention_sentences( array( 'checkin_staff' ) ),
			self::retention_sentences( array( 'unpaid_customer_email', 'message_content', 'audit_logs' ) ),
		);
		if ( $pass_days ) {
			/* translators: %d: days */
			$storage[] = sprintf( _n( 'Mitgliedspässe anonymisieren wir %d Tag nach dem Ende ihrer Gültigkeit.', 'Mitgliedspässe anonymisieren wir %d Tage nach dem Ende ihrer Gültigkeit.', $pass_days, 'pass-captain' ), $pass_days );
		}
		$storage[] = $legal . ' ' . self::financial_sentence();
		$html .= self::section( __( 'Speicherdauer', 'pass-captain' ), $storage );

		$html .= self::section( __( 'Ihre Rechte', 'pass-captain' ), array(
			__( 'Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie auf Widerruf erteilter Einwilligungen mit Wirkung für die Zukunft. Bei einer Löschung bleiben Rechnungen und Buchungsdaten bis zum Ablauf der gesetzlichen Aufbewahrungsfrist gespeichert (Art. 17 Abs. 3 lit. b DSGVO). Haben Sie Tickets für künftige Termine, bleiben diese gültig, und wir löschen die zugehörigen Bestelldaten automatisch nach dem letzten Termin; möchten Sie die Tickets sofort aufgeben, teilen Sie uns das bitte mit. Sie können sich bei einer Datenschutzaufsichtsbehörde beschweren.', 'pass-captain' ),
		) );
		return (string) apply_filters( 'wpcap_privacy_policy_content', $html, $r );
	}
}
