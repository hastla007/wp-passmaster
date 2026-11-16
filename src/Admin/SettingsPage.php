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
				'title' => __( 'Allgemein / Reservierung', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'hold_minutes' => array( 'type' => 'int', 'label' => __( 'Reservierungsdauer im Checkout (Minuten)', 'pass-captain' ), 'min' => 1, 'max' => 120 ),
					'occurrence_default_duration_hours' => array( 'type' => 'int', 'label' => __( 'Standarddauer von Terminen ohne Ende (Stunden)', 'pass-captain' ), 'min' => 1, 'max' => 168, 'description' => __( 'Ein Termin ohne Endzeit gilt so lange nach Beginn als laufend (Abendkasse, Terminlisten von Scanner und App). Mehrtägige Termine bitte mit Ende anlegen.', 'pass-captain' ) ),
					'auto_refund_cancelled' => array( 'type' => 'bool', 'label' => __( 'Bei Terminabsage bezahlte Bestellungen automatisch erstatten', 'pass-captain' ) ),
					'late_payment_auto_refund' => array( 'type' => 'bool', 'label' => __( 'Verspätete Zahlungen, für die keine Tickets mehr ausgestellt werden können, automatisch erstatten', 'pass-captain' ), 'description' => __( 'Betrifft Zahlungen nach Ablauf der Reservierung (Termin abgesagt oder vorbei, Kontingent, Rabattcode-Limit oder Käuferlimit erschöpft) und Zahlungen für stornierte Bestellungen. Ohne diese Option bleibt die Bestellung zur manuellen Klärung stehen.', 'pass-captain' ) ),
					'campaign_max_codes' => array( 'type' => 'int', 'label' => __( 'Rabattcodes je Bestellung (höchstens)', 'pass-captain' ), 'min' => 1, 'max' => 5 ),
					'campaign_stacking' => array(
						'type' => 'choice',
						'label' => __( 'Mehrere Rabattcodes', 'pass-captain' ),
						'options' => array(
							'cumulative' => __( 'nacheinander anwenden (jeder Code auf den Restbetrag, Reihenfolge nach Priorität)', 'pass-captain' ),
							'best' => __( 'nur den günstigsten Code anwenden', 'pass-captain' ),
						),
						'description' => __( 'Codes, die als „nicht kombinierbar“ angelegt sind, gelten immer nur allein.', 'pass-captain' ),
					),
					'guestlist_four_eyes' => array( 'type' => 'bool', 'label' => __( 'Vier-Augen-Prinzip für Freikarten (Gästeliste: wer beantragt, darf nicht selbst freigeben; Administratoren ausgenommen)', 'pass-captain' ) ),
				),
			),
			'network' => array(
				'title' => __( 'Proxy / Client-Adresse', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'trusted_proxies' => array( 'type' => 'textarea', 'label' => __( 'Vertrauenswürdige Proxys (IP-Adressen oder CIDR-Bereiche)', 'pass-captain' ), 'overridable' => true, 'description' => __( 'Eine Adresse oder ein Bereich je Zeile, z. B. 10.0.0.0/8 oder die Adressbereiche des CDN. Nur wenn die direkte Gegenstelle (REMOTE_ADDR) hier steht, wird der Weiterleitungs-Header ausgewertet; leer = immer REMOTE_ADDR. Ratenlimits und Einwilligungsnachweise verwenden diese Adresse.', 'pass-captain' ) ),
					'trusted_proxy_header' => array(
						'type' => 'choice',
						'label' => __( 'Header mit der Client-Adresse', 'pass-captain' ),
						'options' => array(
							'x_forwarded_for' => __( 'X-Forwarded-For (rechteste nicht vertrauenswürdige Adresse)', 'pass-captain' ),
							'forwarded' => __( 'Forwarded (RFC 7239, rechteste nicht vertrauenswürdige Adresse)', 'pass-captain' ),
							'cf_connecting_ip' => __( 'CF-Connecting-IP (Cloudflare)', 'pass-captain' ),
							'x_real_ip' => 'X-Real-IP',
							'true_client_ip' => 'True-Client-IP',
						),
						'description' => __( 'Auch per Konstante WPCAP_CLIENT_IP_HEADER oder Filter wpcap_client_ip_header (x-forwarded-for, forwarded, cf-connecting-ip), solange hier X-Forwarded-For gewählt ist.', 'pass-captain' ),
					),
				),
			),
			'features' => array(
				'title' => __( 'Funktionen', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'hidden_modules' => array( 'type' => 'modules', 'label' => __( 'Im Menü ausblenden', 'pass-captain' ), 'description' => __( 'Nur die Menüeinträge werden ausgeblendet; die Funktionen laufen weiter, Daten bleiben erhalten und direkte Links funktionieren. Für Funktionen, die Sie nicht nutzen.', 'pass-captain' ), 'sanitize' => array( Dashboard::class, 'sanitize_hidden' ) ),
				),
			),
			'event_pages' => array(
				'title' => __( 'Eventseiten', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'event_pages' => array( 'type' => 'bool', 'label' => __( 'Eigene Seite pro Event mit teilbarer Adresse und strukturierten Daten (schema.org/Event)', 'pass-captain' ), 'sanitize' => array( \PassCaptain\Frontend\EventPage::class, 'sanitize_switch' ) ),
					'event_base' => array( 'type' => 'text', 'label' => __( 'Adress-Basis der Eventseiten', 'pass-captain' ), 'description' => __( 'Beispiel „event“ ergibt https://example.com/event/sommerfest-ab12cd/. Nur Kleinbuchstaben, Ziffern und Bindestrich; darf nicht wie eine vorhandene Seite heißen.', 'pass-captain' ), 'sanitize' => array( \PassCaptain\Frontend\EventPage::class, 'sanitize_base' ) ),
				),
			),
			'scanner' => array(
				'title' => __( 'Einlass / Scanner-Geräte', 'pass-captain' ),
				'tab' => 'admission',
				'fields' => array(
					'scanner_show_attendee' => array( 'type' => 'bool', 'label' => __( 'Teilnehmernamen beim Scannen anzeigen', 'pass-captain' ) ),
					'signed_qr' => array( 'type' => 'bool', 'label' => __( 'Signierte QR-Codes ausgeben (PC1, Ed25519)', 'pass-captain' ), 'description' => __( 'Neue QR-Codes enthalten statt des Ticketcodes eine signierte Angabe (Ticket-ID, Termin, Gültigkeitszeitraum, Code-Version) ohne personenbezogene Daten; Scanner können die Signatur offline prüfen. Klassische Codes bleiben gültig. Standard aus, damit fremde Scanner-Systeme weiter den Ticketcode lesen können.', 'pass-captain' ) ),
					'device_max_hours' => array( 'type' => 'int', 'label' => __( 'Maximale Gültigkeit einer Gerätekopplung (Stunden)', 'pass-captain' ), 'min' => 1, 'max' => 720 ),
					'admission_day_cutoff_hour' => array( 'type' => 'int', 'label' => __( 'Einlass-Tageswechsel (Uhrzeit, volle Stunde)', 'pass-captain' ), 'min' => 0, 'max' => 12, 'description' => __( '0 = Mitternacht. Beispiel 6: Scans bis 05:59 Uhr zählen noch zum Vortag (Festivals, Nachtveranstaltungen). Gilt für Tagestickets und Tageslimits.', 'pass-captain' ) ),
					'default_entry_window' => array( 'type' => 'bool', 'label' => __( 'Standard-Einlassfenster für Ticketarten ohne eigene Einlassregel', 'pass-captain' ) ),
					'entry_opens_before_minutes' => array( 'type' => 'int', 'label' => __( 'Einlass ab (Minuten vor Beginn)', 'pass-captain' ), 'min' => 0, 'max' => 10080 ),
					'entry_closes_after_minutes' => array( 'type' => 'int', 'label' => __( 'Einlass bis (Minuten nach Ende; ohne Ende: nach Ende des Veranstaltungstags)', 'pass-captain' ), 'min' => 0, 'max' => 10080 ),
				),
			),
			'payments' => array(
				'title' => __( 'Übersicht', 'pass-captain' ),
				'tab' => 'payments',
				'fields' => array(
					'enabled_providers' => array( 'type' => 'providers', 'label' => __( 'Aktive Zahlungsarten', 'pass-captain' ) ),
				),
			),
			'manual' => array(
				'title' => __( 'Überweisung', 'pass-captain' ),
				'tab' => 'payments',
				'provider' => 'manual',
				'fields' => array(
					'manual_instructions' => array( 'type' => 'textarea', 'label' => __( 'Zahlungshinweis', 'pass-captain' ), 'description' => __( 'Wird Käufern nach der Bestellung angezeigt. Ohne IBAN und Empfänger ersetzt er die Überweisungsdaten.', 'pass-captain' ) ),
					'bank_recipient' => array( 'type' => 'text', 'label' => __( 'Überweisung: Empfänger (Kontoinhaber)', 'pass-captain' ), 'description' => __( 'Leer = Name / Firma des Rechnungsstellers.', 'pass-captain' ) ),
					'bank_iban' => array( 'type' => 'text', 'label' => __( 'Überweisung: IBAN', 'pass-captain' ), 'description' => __( 'Wird nach der Bestellung und in der Bestell-E-Mail mit Betrag, Verwendungszweck und Zahlungsfrist angezeigt.', 'pass-captain' ) ),
					'bank_bic' => array( 'type' => 'text', 'label' => __( 'Überweisung: BIC', 'pass-captain' ) ),
					'manual_hold_hours' => array( 'type' => 'int', 'label' => __( 'Reservierung bei manueller Zahlung (Stunden)', 'pass-captain' ), 'min' => 1, 'max' => 720, 'description' => __( 'Endet spätestens mit Beginn des (ersten) Termins.', 'pass-captain' ) ),
					'manual_hold_max_percent' => array( 'type' => 'int', 'label' => __( 'Überweisung: höchstens so viel Prozent eines Kontingents unbezahlt reservieren', 'pass-captain' ), 'min' => 0, 'max' => 100, 'description' => __( 'Gilt je Ticketart und je Termin mit Kapazität. Offene, unbezahlte Überweisungsbestellungen halten zusammen höchstens diesen Anteil (mindestens aber 10 Tickets); danach ist Überweisung für diesen Termin erst wieder möglich, wenn Bestellungen bezahlt werden oder ablaufen. 0 oder 100 = keine Obergrenze.', 'pass-captain' ) ),
					'manual_cutoff_hours' => array( 'type' => 'int', 'label' => __( 'Überweisung nur bis … Stunden vor Beginn anbieten', 'pass-captain' ), 'min' => 0, 'max' => 720, 'description' => __( '0 = bis zum Beginn. Empfohlen: 24–48 Stunden, damit die Zahlung rechtzeitig eingeht.', 'pass-captain' ) ),
				),
			),
			'stripe' => array(
				'title' => __( 'Stripe', 'pass-captain' ),
				'tab' => 'payments',
				'provider' => 'stripe',
				'webhook' => 'stripe',
				'fields' => array(
					'stripe_mode' => array( 'type' => 'choice', 'label' => __( 'Modus', 'pass-captain' ), 'options' => array( 'test' => __( 'Test', 'pass-captain' ), 'live' => __( 'Live', 'pass-captain' ) ) ),
					'stripe_secret_key' => array( 'type' => 'secret', 'label' => __( 'Geheimer API-Schlüssel (Secret Key)', 'pass-captain' ), 'description' => __( 'Stripe-Dashboard → Entwickler → API-Schlüssel. Beginnt mit sk_test_ bzw. sk_live_ (eingeschränkter Schlüssel: rk_…).', 'pass-captain' ) ),
					'stripe_webhook_secret' => array( 'type' => 'secret', 'label' => __( 'Webhook-Signaturgeheimnis (Signing Secret)', 'pass-captain' ), 'description' => __( 'Wird beim Webhook-Endpunkt im Stripe-Dashboard angezeigt und beginnt mit whsec_. Ohne dieses Geheimnis werden Zahlungen nie bestätigt.', 'pass-captain' ) ),
				),
			),
			'paypal' => array(
				'title' => __( 'PayPal', 'pass-captain' ),
				'tab' => 'payments',
				'provider' => 'paypal',
				'webhook' => 'paypal',
				'fields' => array(
					'paypal_mode' => array( 'type' => 'choice', 'label' => __( 'Modus', 'pass-captain' ), 'options' => array( 'sandbox' => __( 'Sandbox', 'pass-captain' ), 'live' => __( 'Live', 'pass-captain' ) ) ),
					'paypal_client_id' => array( 'type' => 'text', 'label' => __( 'Client-ID', 'pass-captain' ), 'overridable' => true, 'description' => __( 'PayPal Developer Dashboard → Apps & Credentials → eigene App (Sandbox bzw. Live passend zum Modus).', 'pass-captain' ) ),
					'paypal_client_secret' => array( 'type' => 'secret', 'label' => __( 'Client-Geheimnis (Secret)', 'pass-captain' ), 'description' => __( 'Steht in derselben App unter der Client-ID.', 'pass-captain' ) ),
					'paypal_webhook_id' => array( 'type' => 'text', 'label' => __( 'Webhook-ID', 'pass-captain' ), 'overridable' => true, 'description' => __( 'Nach dem Anlegen des Webhooks in der App angezeigt (eine Kennung, nicht die URL). Ohne sie werden Zahlungen nie bestätigt.', 'pass-captain' ) ),
				),
			),
			'legal' => array(
				'title' => __( 'Rechtstexte', 'pass-captain' ),
				'tab' => 'legal',
				'fields' => array(
					'require_terms' => array( 'type' => 'bool', 'label' => __( 'Zustimmung im Checkout verlangen', 'pass-captain' ), 'description' => __( 'Zeigt die AGB/Teilnahmebedingungen im Checkout an und verlangt die Bestätigung. Ohne diese Option erscheinen die AGB im Checkout nicht (die Verbraucherinformationen immer).', 'pass-captain' ) ),
					'terms_text' => array( 'type' => 'html', 'label' => __( 'AGB / Teilnahmebedingungen', 'pass-captain' ) ),
					'consumer_info_text' => array( 'type' => 'html', 'label' => __( 'Verbraucherinformationen (z. B. Widerrufshinweis)', 'pass-captain' ) ),
					'withdrawal_notice' => array( 'type' => 'bool', 'label' => __( 'Hinweis „kein Widerrufsrecht“ neben dem Bestellbutton zeigen', 'pass-captain' ), 'description' => __( 'Nur wenn alle Positionen einen festen Veranstaltungstermin haben. Standardtext, bitte prüfen und anpassen – keine Rechtsberatung.', 'pass-captain' ) ),
					'withdrawal_notice_text' => array( 'type' => 'html', 'label' => __( 'Text: kein Widerrufsrecht (terminierte Tickets)', 'pass-captain' ) ),
					'withdrawal_right_text' => array( 'type' => 'html', 'label' => __( 'Text: Widerrufsrecht besteht (Produkte ohne Termin, z. B. Pässe, Gutscheine)', 'pass-captain' ), 'description' => __( 'Wird gezeigt, wenn eine Position keinen festen Termin hat. Hier z. B. die Widerrufsbelehrung oder einen Verweis darauf eintragen.', 'pass-captain' ) ),
				),
			),
			'refunds' => array(
				'title' => __( 'Erstattungen', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'refund_approval_threshold' => array( 'type' => 'money', 'label' => __( 'Zweite Freigabe ab Betrag', 'pass-captain' ), 'description' => __( '0 = keine zweite Freigabe. Betrag in der Bestellwährung, z. B. 100,00.', 'pass-captain' ) ),
					'refund_four_eyes_approvers' => array( 'type' => 'bool', 'label' => __( 'Vier-Augen-Prinzip auch für Freigebende', 'pass-captain' ), 'description' => __( 'Ohne diese Option führen Personen mit Freigabeberechtigung eigene Erstattungen über dem Betrag sofort aus. Mit der Option wartet jede Erstattung über dem Betrag auf die Freigabe einer anderen berechtigten Person.', 'pass-captain' ) ),
					'require_2fa_for_refunds' => array( 'type' => 'bool', 'label' => __( 'Zwei-Faktor-Anmeldung für Erstattungen verlangen', 'pass-captain' ) ),
					'auto_refund_unmatched_payments' => array( 'type' => 'bool', 'label' => __( 'Doppelzahlungen und Zahlungen auf stornierte/abgelaufene Bestellungen automatisch erstatten', 'pass-captain' ), 'description' => __( 'Standard aus: Die Fälle erscheinen im Systemstatus und werden manuell geklärt. Eingeschaltet erstattet ein Hintergrund-Job den Betrag beim Zahlungsanbieter.', 'pass-captain' ) ),
					'reschedule_refund_request' => array( 'type' => 'bool', 'label' => __( 'Nach einer Terminverschiebung Erstattungsantrag im Bestellportal erlauben', 'pass-captain' ), 'description' => __( 'Ticketinhaber:innen können bis zur Frist eine Erstattung beantragen; jeder Antrag braucht eine Freigabe unter „Erstattungen“.', 'pass-captain' ) ),
					'reschedule_refund_hours' => array( 'type' => 'int', 'label' => __( 'Antragsfrist: Stunden vor dem neuen Termin', 'pass-captain' ), 'min' => 0, 'max' => 720, 'description' => __( '0 = bis zum Beginn des neuen Termins.', 'pass-captain' ) ),
					'reschedule_refund_note' => array( 'type' => 'textarea', 'label' => __( 'Erstattungshinweis in der Verschiebungs-Mail', 'pass-captain' ), 'description' => __( 'Platzhalter {refund_deadline} (Frist). Leer = Hinweis an den Veranstalter wenden. Kein Rechtsrat – Rücktrittsrechte bei erheblicher Verschiebung bitte prüfen.', 'pass-captain' ) ),
					'portal_refund_request_hours' => array( 'type' => 'int', 'label' => __( 'Stornierungsanfragen im Bestellportal bis (Stunden vor Beginn)', 'pass-captain' ), 'min' => 0, 'max' => 8760, 'description' => __( '0 = aus. Käufer:innen können gültige, unbenutzte Tickets bis zu dieser Frist zur Stornierung mit Erstattung anfragen (nicht bei Freikarten und WooCommerce-Bestellungen). Jede Anfrage wartet unter „Erstattungen zur Freigabe“ auf eine Entscheidung; ausgezahlt wird erst nach der Freigabe.', 'pass-captain' ) ),
				),
			),
			'vouchers' => array(
				'title' => __( 'Gutscheine', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'voucher_default_purpose' => array(
						'type' => 'choice',
						'label' => __( 'Gutscheine standardmäßig als Mehrzweckgutschein behandeln', 'pass-captain' ),
						'options' => array(
							'multi' => __( 'Ja – Mehrzweckgutschein (Zahlungsmittel, USt bei Einlösung)', 'pass-captain' ),
							'single' => __( 'Nein – Einzweckgutschein (USt bereits beim Gutscheinverkauf)', 'pass-captain' ),
						),
						'description' => __( 'Gilt nur, wenn Voucher Captain zum Gutschein keine Art meldet. Mehrzweckgutschein: Tickets behalten den vollen Preis und die volle Umsatzsteuer, der Gutschein erscheint auf der Rechnung als Zahlung. Einzweckgutschein (Ort der Leistung und Steuersatz standen beim Verkauf fest, z. B. Gutschein für eine bestimmte Veranstaltung im Inland): die Umsatzsteuer wurde schon beim Gutscheinverkauf abgeführt, die Einlösung mindert den Preis. Im Zweifel mit der Steuerberatung klären (siehe docs/VOUCHERS.md).', 'pass-captain' ),
					),
				),
			),
			'privacy' => array(
				'title' => __( 'Datenschutz', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'attendee_retention_days' => array( 'type' => 'int', 'label' => __( 'Teilnehmerdaten nach dem Event löschen nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( '0 = Feldeinstellung verwenden.', 'pass-captain' ) ),
					'attendee_edit_hours' => array( 'type' => 'int', 'label' => __( 'Teilnehmerdaten änderbar bis (Stunden vor Beginn)', 'pass-captain' ), 'min' => 0, 'max' => 720 ),
					'retention_unpaid_customer_email_days' => array( 'type' => 'int', 'label' => __( 'E-Mail-Adressen nie bezahlter Bestellungen pseudonymisieren nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( '0 = nie.', 'pass-captain' ) ),
					'retention_message_content_days' => array( 'type' => 'int', 'label' => __( 'Inhalt und Empfänger versendeter Nachrichten löschen nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650 ),
					'retention_message_rows_days' => array( 'type' => 'int', 'label' => __( 'Versandprotokoll der Nachrichten löschen nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650 ),
					'retention_checkin_staff_days' => array( 'type' => 'int', 'label' => __( 'Personal und Gerät an Check-ins entfernen nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650 ),
					'retention_audit_logs_days' => array( 'type' => 'int', 'label' => __( 'Protokolleinträge (Audit-Log) löschen nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650 ),
					'retention_partner_codes_days' => array( 'type' => 'int', 'label' => __( 'Ticketcodes von Partnerverkäufen löschen (Tage nach dem Termin)', 'pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( '0 = direkt nach Ende des Termins.', 'pass-captain' ) ),
					'retention_financial_years' => array( 'type' => 'int', 'label' => __( 'Rechnungen und Rechnungskorrekturen löschen nach (Jahren)', 'pass-captain' ), 'min' => 0, 'max' => 30, 'description' => __( '0 = nie löschen. Die Frist beginnt mit Ende des Ausstellungsjahres. Gesetzliche Aufbewahrungsfristen beachten (z. B. Österreich 7 Jahre, Deutschland 8 bzw. 10 Jahre) und die Belege vorher im Buchhaltungssystem archivieren. Käuferdaten der betroffenen Bestellungen werden dabei pseudonymisiert.', 'pass-captain' ) ),
					'retention_order_years' => array( 'type' => 'int', 'label' => __( 'Käuferdaten in Bestellungen entfernen nach (Jahren ab Ende des Bestelljahres)', 'pass-captain' ), 'min' => 0, 'max' => 30, 'description' => __( 'Gesetzliche Aufbewahrungsfrist für Buchungsbelege: Deutschland 10 Jahre (§ 147 AO, § 257 HGB), Österreich 7 Jahre (§ 132 BAO). Danach werden Käufer-E-Mail, Rechnungsanschrift und Teilnehmerangaben der Bestellung, ihrer Tickets und Terminbuchungen entfernt. Beträge, Positionen, Erstattungen, Rechnungen, Gutschriften und Fiskalbelege bleiben unverändert; deren Löschung nach Fristablauf entscheiden Sie selbst (laufende Prüfungen verlängern die Frist). 0 = nie.', 'pass-captain' ) ),
					'retention_waitlist_days' => array( 'type' => 'int', 'label' => __( 'Wartelisteneinträge nach dem Termin anonymisieren nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( 'Gilt für Event- und Termin-Wartelisten. 0 = nie.', 'pass-captain' ) ),
					'retention_pass_days' => array( 'type' => 'int', 'label' => __( 'Mitgliedspässe nach Ablauf der Gültigkeit anonymisieren nach (Tagen)', 'pass-captain' ), 'min' => 0, 'max' => 3650, 'description' => __( '0 = nie.', 'pass-captain' ) ),
				),
			),
			'mail' => array(
				'title' => __( 'E-Mail-Versand', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'mail_from_email' => array( 'type' => 'text', 'label' => __( 'Absenderadresse', 'pass-captain' ), 'sanitize' => array( __CLASS__, 'sanitize_mail_address' ), 'description' => __( 'Absender (From) und Envelope-Sender (Return-Path) aller Kunden-E-Mails, z. B. tickets@ihre-domain.de. Die Domain sollte die der Website sein und per SPF, DKIM und DMARC für den Versand über diesen Server bzw. Ihren SMTP-Dienst freigegeben sein. Leer: WordPress-Standard (wordpress@…). Ein SMTP-Plugin, das einen eigenen Absender erzwingt, hat Vorrang.', 'pass-captain' ) ),
					'mail_from_name' => array( 'type' => 'text', 'label' => __( 'Absendername', 'pass-captain' ), 'description' => __( 'Name, den Käufer als Absender sehen. Leer = Name / Firma des Rechnungsstellers, sonst der Name der Website. Die Absenderadresse selbst kommt von WordPress bzw. dem SMTP-Plugin (passend zu SPF/DKIM der Domain).', 'pass-captain' ) ),
					'mail_reply_to' => array( 'type' => 'email', 'label' => __( 'Antwort an (E-Mail-Adresse)', 'pass-captain' ), 'description' => __( 'Antworten der Käufer gehen an diese Adresse. Leer = die Absenderadresse; ist keine eigene Absenderadresse gesetzt, die Administrator-E-Mail der Website.', 'pass-captain' ) ),
					'alert_email' => array( 'type' => 'email', 'label' => __( 'Betriebsalarme an', 'pass-captain' ), 'description' => __( 'Hinweise, die ein Eingreifen erfordern (Erstattung wartet auf Freigabe, Zahlung prüfen, bezahlte Bestellung ohne Tickets, Webhook deaktiviert …). Leer = die Administrator-E-Mail der Website. Die letzten Alarme stehen auch im Systemstatus.', 'pass-captain' ) ),
					'mail_bounce_webhook' => array( 'type' => 'bool', 'label' => __( 'Bounce-/Beschwerde-Webhook des Versanddienstes annehmen', 'pass-captain' ), 'description' => \PassCaptain\Mail\Suppression::webhook_enabled()
						/* translators: %s: webhook URL with token */
						? sprintf( __( 'Diese URL beim Versanddienst (Postmark, SendGrid, Mailgun, Amazon SES/SNS) als Webhook für Bounces und Spam-Beschwerden eintragen: %s – unzustellbare Mails werden als fehlgeschlagen markiert und bei der Bestellung angezeigt; nach einer Beschwerde gehen keine Erinnerungen mehr an die Adresse. Die URL enthält ein geheimes Token.', 'pass-captain' ), \PassCaptain\Mail\Suppression::webhook_url() )
						: __( 'Nach dem Aktivieren erscheint hier die Webhook-URL (mit geheimem Token) für den Versanddienst.', 'pass-captain' ) ),
					'mail_rate_per_minute' => array( 'type' => 'int', 'label' => __( 'Geplante Mails je Minute (höchstens)', 'pass-captain' ), 'min' => 0, 'max' => 10000, 'description' => __( '0 = ohne Begrenzung. Begrenzt Erinnerungen und andere Mails aus der Warteschlange auf das Limit deines Mail-Anbieters; der Rest geht in den nächsten Läufen hinaus. Meldet der Anbieter ein Limit, wird die Mail später erneut versucht statt verworfen.', 'pass-captain' ) ),
				),
			),
			'seller' => array(
				'title' => __( 'Rechnungssteller', 'pass-captain' ),
				'tab' => 'invoices',
				'fields' => array(
					'seller_name' => array( 'type' => 'text', 'label' => __( 'Name / Firma', 'pass-captain' ) ),
					'seller_address' => array( 'type' => 'textarea', 'label' => __( 'Anschrift', 'pass-captain' ) ),
					'seller_vat_id' => array( 'type' => 'text', 'label' => __( 'USt-IdNr.', 'pass-captain' ) ),
					'seller_tax_number' => array( 'type' => 'text', 'label' => __( 'Steuernummer', 'pass-captain' ), 'description' => __( 'Pflichtangabe auf Rechnungen, wenn keine USt-IdNr. angegeben ist.', 'pass-captain' ) ),
					'seller_country' => array( 'type' => 'text', 'label' => __( 'Land (ISO-Code, z. B. DE oder AT)', 'pass-captain' ), 'description' => __( 'Wird für die Reverse-Charge-Prüfung bei Veranstaltern im EU-Ausland verwendet (siehe docs/SALES-MODELS.md) und gilt als Leistungsort für Termine ohne eigenes Veranstaltungsland (Steuerprofile).', 'pass-captain' ) ),
					'tax_profile_four_eyes' => array( 'type' => 'bool', 'label' => __( 'Vier-Augen-Prinzip für Steuerprofile (wer ein Profil anlegt oder bearbeitet, darf es nicht selbst aktivieren; Administratoren ausgenommen)', 'pass-captain' ) ),
					'seller_small_business' => array( 'type' => 'bool', 'label' => __( 'Kleinunternehmer (keine Umsatzsteuer auf eigenen Rechnungen)', 'pass-captain' ), 'description' => __( 'Rechnungen der Plattform tragen dann den Kleinunternehmer-Hinweis und werden verweigert, solange Ticketarten Umsatzsteuer enthalten (Ticketarten mit 0 % anlegen).', 'pass-captain' ) ),
					'seller_small_business_note' => array( 'type' => 'text', 'label' => __( 'Kleinunternehmer-Hinweis (leer = Standardtext für das Land)', 'pass-captain' ), 'description' => __( 'DE: „Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.“ – AT: „Umsatzsteuerfrei aufgrund der Kleinunternehmerregelung gemäß § 6 Abs. 1 Z 27 UStG.“', 'pass-captain' ) ),
					'invoice_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix Rechnungsnummer', 'pass-captain' ) ),
					'billing_required_threshold_minor' => array( 'type' => 'money', 'label' => __( 'Name und Anschrift im Checkout verlangen ab Bestellbetrag (0 = nie; DE: 250 €, AT: 400 €)', 'pass-captain' ) ),
					'credit_note_prefix' => array( 'type' => 'prefix', 'label' => __( 'Präfix Rechnungskorrektur (Storno-/Korrekturbeleg)', 'pass-captain' ) ),
					'auto_invoice' => array( 'type' => 'bool', 'label' => __( 'Rechnung nach Zahlung automatisch erstellen', 'pass-captain' ) ),
					'datev_chart' => array( 'type' => 'choice', 'label' => __( 'Kontenrahmen für den DATEV-Buchungsstapel', 'pass-captain' ), 'options' => array( 'skr03' => __( 'SKR03 (Erlöse 8400/8300, Geldtransit 1360)', 'pass-captain' ), 'skr04' => __( 'SKR04 (Erlöse 4400/4300, Geldtransit 1460)', 'pass-captain' ) ), 'description' => __( 'Eigene Konten, BU-Schlüssel und ein Konto je Zahlungsanbieter über den Filter wpcap_datev_accounts.', 'pass-captain' ) ),
					'datev_contra_account' => array( 'type' => 'text', 'label' => __( 'Geldkonto im Buchungsstapel (leer = Geldtransit des Kontenrahmens)', 'pass-captain' ), 'description' => __( '4 bis 8 Ziffern, z. B. das Verrechnungskonto des Zahlungsdienstleisters.', 'pass-captain' ) ),
				),
			),
			'security' => array(
				'title' => __( 'Sicherheit', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'fiscal_allow_local_service' => array( 'type' => 'bool', 'label' => __( 'Lokalen Fiskaldienst erlauben (HTTP an Loopback-/private Adressen)', 'pass-captain' ), 'description' => __( 'Nur für einen Fiskaldienst auf demselben Server bzw. im lokalen Netz (z. B. efsta EFR unter http://127.0.0.1:5618). Alle anderen ausgehenden Verbindungen zu konfigurierbaren Zielen müssen HTTPS zu öffentlichen Adressen verwenden.', 'pass-captain' ) ),
				),
			),
			'support' => array(
				'title' => __( 'Support und Fehlersuche', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'debug_logging' => array( 'type' => 'bool', 'label' => __( 'Fehlersuche-Protokoll (Debug-Modus) für 24 Stunden einschalten', 'pass-captain' ), 'description' => __( 'Speichert zusätzlich ausführliche Diagnose-Einträge im Support-Protokoll (Betrieb → Support-Protokoll). Schaltet sich 24 Stunden nach dem Einschalten von selbst ab; zum erneuten Einschalten aus- und wieder einschalten. Fehler und Warnungen werden immer protokolliert. Geheimnisse, Ticketcodes und E-Mail-Adressen werden nie gespeichert. Die Konstante WPCAP_DEBUG in wp-config.php hat Vorrang.', 'pass-captain' ) ),
				),
			),
			'uninstall' => array(
				'title' => __( 'Deinstallation', 'pass-captain' ),
				'tab' => 'general',
				'fields' => array(
					'delete_data_on_uninstall' => array( 'type' => 'bool', 'label' => __( 'Beim Löschen des Plugins alle Daten entfernen', 'pass-captain' ), 'description' => __( 'Achtung: Events, Bestellungen, Tickets, Rechnungen, Rechnungskorrekturen, Kassen- und Fiskalbelege werden beim Löschen des Plugins unwiderruflich gelöscht. Rechnungen und Buchungsbelege unterliegen gesetzlichen Aufbewahrungspflichten (DE 10 Jahre, AT 7 Jahre): vorher Rechnungen und das Fiskal-Archiv exportieren und sicher aufbewahren.', 'pass-captain' ) ),
				),
			),
		);
	}

	/**
	 * Core sections plus add-on sections (filter wp_pass_captain_settings_sections_v1).
	 *
	 * Add-ons may add sections or fields; each field key must exist in Settings::defaults() (module
	 * settings_defaults() or wp_pass_captain_settings_defaults_v1). Field types: int (min, max), bool,
	 * choice (options), text, textarea, html, money, secret; optional description and sanitize (callable
	 * receiving the raw value and the current value, returning the value to store or a WP_Error).
	 * Core fields cannot be removed or retyped.
	 */
	public static function all_sections() {
		$core = self::sections();
		$sections = apply_filters( 'wp_pass_captain_settings_sections_v1', $core );
		if ( ! is_array( $sections ) ) { return $core; }
		$out = $core;
		foreach ( $sections as $id => $section ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $section ) || empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) { continue; }
			$known_types = array( 'int', 'bool', 'choice', 'text', 'textarea', 'html', 'money', 'secret' );
			$fields = array();
			foreach ( $section['fields'] as $key => $def ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || ! is_array( $def ) || ! in_array( $def['type'] ?? '', $known_types, true ) ) { continue; }
				if ( 'int' === $def['type'] ) { $def += array( 'min' => 0, 'max' => PHP_INT_MAX ); }
				if ( 'choice' === $def['type'] && ! is_array( $def['options'] ?? null ) ) { continue; }
				$def['label'] = (string) ( $def['label'] ?? $key );
				$fields[ $key ] = $def;
			}
			if ( isset( $core[ $id ] ) ) {
				// Existing core section: only new fields are appended.
				$out[ $id ]['fields'] += array_diff_key( $fields, $core[ $id ]['fields'] );
			} elseif ( $fields ) {
				$out[ $id ] = array( 'title' => (string) ( $section['title'] ?? $id ), 'fields' => $fields, 'addon' => true );
			}
		}
		return $out;
	}

	/** Definitions for every key in Settings::defaults(), including generic ones for unknown keys. */
	public static function field_definitions() {
		$defaults = Settings::defaults();
		$sections = self::all_sections();
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
			$sections['other'] = array( 'title' => __( 'Weitere Einstellungen', 'pass-captain' ), 'tab' => 'general', 'fields' => $other );
		}
		return array_filter( $sections, static function ( $section ) { return ! empty( $section['fields'] ); } );
	}

	/** Tabs of the screen: id => title (core tabs first, then tabs of add-ons). */
	public static function tabs() {
		$tabs = self::core_tabs();
		foreach ( self::extension_tabs() as $id => $def ) { $tabs[ $id ] = $def['title']; }
		return $tabs;
	}

	/** Built-in tabs: id => title. */
	public static function core_tabs() {
		return array(
			'general' => __( 'Allgemein', 'pass-captain' ),
			'admission' => _x( 'Einlass', 'admission (check-in area of work)', 'pass-captain' ),
			'payments' => __( 'Zahlungsarten', 'pass-captain' ),
			'legal' => __( 'Rechtstexte', 'pass-captain' ),
			'invoices' => __( 'Rechnungen', 'pass-captain' ),
		);
	}

	/**
	 * Filter wpcap_settings_tabs (A663, public add-on API): own tabs of add-ons on the settings screen,
	 * id => ['title' => string, 'render' => callable, 'capability' => string (default manage_options)].
	 * The tab renders its own content and form (own nonce, own option); the core settings form and
	 * save() never touch it. Core tabs cannot be replaced or removed; invalid entries are ignored.
	 *
	 * @return array<string,array{title:string,render:callable,capability:string}>
	 */
	public static function extension_tabs() {
		$core = self::core_tabs();
		$out = array();
		foreach ( (array) apply_filters( 'wpcap_settings_tabs', array() ) as $id => $def ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || isset( $core[ $id ] ) || ! is_array( $def ) || ! is_callable( $def['render'] ?? null ) ) { continue; }
			$title = trim( sanitize_text_field( (string) ( $def['title'] ?? '' ) ) );
			$out[ $id ] = array(
				'title' => '' !== $title ? $title : $id,
				'render' => $def['render'],
				'capability' => is_string( $def['capability'] ?? null ) && '' !== $def['capability'] ? $def['capability'] : self::CAP,
			);
		}
		return $out;
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
		return Settings::override_source( $key );
	}

	/**
	 * Markup of a secret input: never contains the value. Shows "gespeichert ••••1234" (last four
	 * characters of long secrets), an empty replace field and a remove checkbox.
	 */
	/** A298: id of the WordPress editor of an HTML field (lowercase letters, digits and underscores only). */
	private static function editor_id( $key ) {
		return 'wpcap_set_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $key ) );
	}

	/** Id of a field's input for its label (the editor of HTML fields has its own id). */
	private static function field_input_id( $key, array $field ) {
		return 'html' === ( $field['type'] ?? '' ) && function_exists( 'wp_editor' ) && apply_filters( 'wpcap_settings_rich_editor', true, $key ) ? self::editor_id( $key ) : 'wpcap-set-' . $key;
	}

	public static function secret_input( $key, $id = '', $multiline = false ) {
		$status = Settings::secret_status( $key );
		$id = '' !== $id ? $id : 'wpcap-set-' . $key;
		$stored = in_array( $status['state'], array( 'stored', 'undecryptable' ), true );
		$placeholder = $stored ? __( 'Neuen Wert eingeben, um ihn zu ersetzen – leer lassen, um beizubehalten', 'pass-captain' ) : __( 'Nicht gesetzt', 'pass-captain' );
		$html = $multiline
			? '<textarea class="large-text code" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '"></textarea>'
			: '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr( $placeholder ) . '">';
		if ( 'stored' === $status['state'] ) {
			/* translators: %s: masked value, e.g. ••••1234 */
			$html .= ' <strong class="wpcap-secret-state">' . esc_html( sprintf( __( 'gespeichert %s', 'pass-captain' ), $status['hint'] ) ) . '</strong>';
		} elseif ( 'undecryptable' === $status['state'] ) {
			$html .= ' <strong class="wpcap-secret-state" style="color:#b32d2e">' . esc_html__( 'Gespeicherter Wert kann nicht entschlüsselt werden – bitte neu eingeben', 'pass-captain' ) . '</strong>';
		} elseif ( 'empty' === $status['state'] ) {
			$html .= ' <strong class="wpcap-secret-state">' . esc_html__( 'Kein Wert gespeichert', 'pass-captain' ) . '</strong>';
		}
		if ( $stored ) {
			$html .= ' <label><input type="checkbox" name="' . esc_attr( $key . '__clear' ) . '" value="1"> ' . esc_html__( 'Gespeicherten Wert entfernen', 'pass-captain' ) . '</label>';
		}
		return $html;
	}

	/* =============================================================================================
	 * Processing
	 * =========================================================================================== */

	/**
	 * Sanitises and stores submitted settings.
	 *
	 * @return array|\WP_Error ['saved' => values written, 'errors' => list of messages for invalid fields]
	 */
	/** Sanitizer of the sender address fields: '' or a valid e-mail address. */
	public static function sanitize_mail_address( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		$email = sanitize_email( $value );
		return is_email( $email ) ? $email : new \WP_Error( 'wpcap_invalid_mail_address', __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'pass-captain' ) );
	}

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
					case 'modules':
						$values[ $key ] = array_map( 'strval', is_array( $raw ) ? array_filter( $raw, 'is_scalar' ) : array() );
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
					case 'email':
						$email = is_scalar( $raw ) ? trim( (string) $raw ) : '';
						if ( '' !== $email && ! is_email( $email ) ) {
							/* translators: %s: field label */
							$errors[] = sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'pass-captain' ), $def['label'] );
							break;
						}
						$values[ $key ] = '' === $email ? '' : sanitize_email( $email );
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
						$values[ $key ] = Forms::text( $in, $key, 'trusted_proxies' === $key ? 1000 : 190 );
				}
				// Deleting all data on uninstall (incl. invoices, credit notes and fiscal records) needs a typed confirmation.
				if ( 'delete_data_on_uninstall' === $key && ! empty( $values[ $key ] ) && empty( $current[ $key ] ) && ! self::uninstall_confirmed( $in ) ) {
					$errors[] = __( '„Beim Löschen des Plugins alle Daten entfernen“ wurde nicht aktiviert: Bitte zur Bestätigung LÖSCHEN eintragen.', 'pass-captain' );
					$values[ $key ] = 0;
				}
				if ( array_key_exists( $key, $values ) && isset( $def['sanitize'] ) && is_callable( $def['sanitize'] ) ) {
					$clean = call_user_func( $def['sanitize'], $values[ $key ], $current[ $key ] ?? null );
					if ( is_wp_error( $clean ) ) {
						$errors[] = $clean->get_error_message();
						unset( $values[ $key ] );
					} else {
						$values[ $key ] = $clean;
					}
				}
			}
		}
		if ( array_key_exists( 'stripe_mode', $values ) || array_key_exists( 'stripe_secret_key', $values ) ) {
			// A274: a key of the other mode is saved (the operator may be switching), but reported – Stripe is
			// not offered until mode and key fit (StripeGateway::is_available()).
			$problem = \PassCaptain\Payments\StripeGateway::mode_problem(
				array_key_exists( 'stripe_secret_key', $values ) ? (string) $values['stripe_secret_key'] : null,
				array_key_exists( 'stripe_mode', $values ) ? (string) $values['stripe_mode'] : (string) $current['stripe_mode']
			);
			if ( '' !== $problem ) { $errors[] = $problem; }
		}
		$changed = array();
		foreach ( $values as $key => $value ) {
			// Secrets are compared with the encrypted store (Settings::all() never contains them).
			$before = \PassCaptain\Secrets::is_secret( $key ) ? \PassCaptain\Secrets::stored( $key ) : ( $current[ $key ] ?? null );
			if ( $before != $value ) { $changed[] = $key; } // phpcs:ignore Universal.Operators.StrictComparisons -- stored types may differ.
		}
		Settings::update( $values );
		$warnings = array();
		if ( isset( $sections['legal'] ) && '' !== self::terms_warning() ) { $warnings[] = self::terms_warning(); }
		if ( $changed ) {
			// Only key names are logged, never values.
			Audit::record( 'settings_updated', 'settings', 0, array( 'keys' => $changed ) );
		}
		// The result never carries secret values back (only which secrets were written).
		$secrets = array_intersect_key( $values, array_flip( Settings::secret_keys() ) );
		$saved = array_diff_key( $values, $secrets );
		$saved['secrets_updated'] = array_keys( $secrets );
		return array( 'saved' => $saved, 'errors' => $errors, 'warnings' => $warnings );
	}

	/**
	 * Warning when the checkout asks to accept terms that have no text (A289): the buyer would
	 * confirm "die Bedingungen" without seeing any. '' when everything is fine.
	 */
	public static function terms_warning() {
		if ( ! Settings::get( 'require_terms' ) ) { return ''; }
		if ( '' !== trim( wp_strip_all_tags( (string) Settings::get( 'terms_text' ) ) ) ) { return ''; }
		return __( 'Die Zustimmung im Checkout ist aktiv, aber für die Standardsprache ist kein AGB-Text hinterlegt. Käufer müssten Bedingungen bestätigen, die sie nicht sehen – bitte AGB/Teilnahmebedingungen eintragen.', 'pass-captain' );
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
		if ( ! is_wp_error( $result ) && ( $result['errors'] || $result['warnings'] ) ) {
			$result['warning'] = implode( ' ', array_merge( $result['errors'], $result['warnings'] ) );
		}
		Forms::finish( $result, __( 'Einstellungen gespeichert.', 'pass-captain' ), Forms::page_url( self::SLUG, self::url_args( $in['wpcap_tab'] ?? '', $in['wpcap_section'] ?? '' ) ) );
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
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'pass-captain' ), 403 ); }
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
		<h1><?php esc_html_e( 'Pass Captain Einstellungen', 'pass-captain' ); ?></h1>
		<?php Forms::render_notices(); ?>
		<?php if ( 'general' === $tab ) : ?>
			<?php list( $vault_type, $vault_message ) = \PassCaptain\Security\Vault::key_notice(); ?>
			<div class="notice notice-<?php echo esc_attr( $vault_type ); ?> inline"><p><?php echo esc_html( $vault_message ); ?></p></div>
		<?php endif; ?>
		<?php if ( 'general' === $tab ) { \PassCaptain\Integrations\Vouchers\IntegrationStatus::render(); } ?>
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Einstellungsbereiche', 'pass-captain' ); ?>">
			<?php foreach ( self::tabs() as $id => $title ) : ?>
				<a href="<?php echo esc_url( Forms::page_url( self::SLUG, array( 'tab' => $id ) ) ); ?>" class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $title ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( 'payments' === $tab ) { self::render_payment_nav( $section ); } ?>
		<?php
		$extension = self::extension_tabs()[ $tab ] ?? null;
		if ( null !== $extension ) {
			// Add-on tab (wpcap_settings_tabs): its own content, no core form.
			if ( current_user_can( $extension['capability'] ) ) {
				call_user_func( $extension['render'], $tab );
			} else {
				echo '<p>' . esc_html__( 'Keine Berechtigung.', 'pass-captain' ) . '</p>';
			}
			echo '</div>';
			return;
		}
		?>
		<?php Forms::open_form( 'wpcap_save_settings' ); ?>
		<input type="hidden" name="wpcap_tab" value="<?php echo esc_attr( $tab ); ?>">
		<?php if ( 'payments' === $tab ) : ?><input type="hidden" name="wpcap_section" value="<?php echo esc_attr( $section ); ?>"><?php endif; ?>
		<?php foreach ( $sections as $id => $def ) : ?>
			<h2 id="wpcap-settings-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $def['title'] ); ?></h2>
			<?php if ( ! empty( $def['provider'] ) ) { self::render_provider_status( (string) $def['provider'], $values ); } ?>
			<?php if ( ! empty( $def['webhook'] ) ) { self::render_webhook_setup( (string) $def['webhook'], $def ); } ?>
			<?php if ( 'legal' === $id && '' !== self::terms_warning() ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( self::terms_warning() ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'legal' === $id ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: locale code */ __( 'Die folgenden Texte gelten für die Standardsprache (%s) und für jede Sprache ohne eigene Fassung.', 'pass-captain' ), Settings::legal_default_locale() ) ); ?></p>
			<?php endif; ?>
			<table class="form-table"><tbody>
			<?php foreach ( $def['fields'] as $key => $field ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( self::field_input_id( $key, $field ) ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td><?php self::render_field( $key, $field, $values[ $key ] ?? null ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php if ( 'legal' === $id ) { self::render_legal_translations(); } ?>
		<?php endforeach; ?>
		<?php submit_button( __( 'Einstellungen speichern', 'pass-captain' ) ); ?>
		</form>
		<?php
		foreach ( $sections as $def ) {
			if ( ! empty( $def['webhook'] ) && Registry::get( (string) $def['provider'] ) && method_exists( Registry::get( (string) $def['provider'] ), 'test_connection' ) ) {
				self::render_connection_test( (string) $def['provider'] );
			}
		}
		?>
		</div>
		<?php
	}

	/** admin-post action of the "Verbindung testen" button (A295). */
	const TEST_ACTION = 'wpcap_test_provider_connection';

	/**
	 * Settings that a provider needs before it is offered (A295): key => label of the missing ones.
	 *
	 * @param string $provider Provider id (stripe, paypal).
	 */
	public static function missing_provider_fields( $provider ) {
		$required = array(
			'stripe' => array( 'stripe_secret_key', 'stripe_webhook_secret' ),
			'paypal' => array( 'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id' ),
		);
		$labels = array();
		foreach ( self::sections() as $section ) { $labels += $section['fields']; }
		$missing = array();
		foreach ( $required[ $provider ] ?? array() as $key ) {
			if ( '' === trim( (string) Settings::secret( $key ) ) ) { $missing[ $key ] = (string) ( $labels[ $key ]['label'] ?? $key ); }
		}
		return $missing;
	}

	/** Webhook URL, the events to select at the provider and the missing fields (A295). */
	private static function render_webhook_setup( $provider, array $def ) {
		$gateway = Registry::get( $provider );
		$events = $gateway && defined( get_class( $gateway ) . '::WEBHOOK_EVENTS' ) ? (array) constant( get_class( $gateway ) . '::WEBHOOK_EVENTS' ) : array();
		echo '<div class="wpcap-webhook-setup">';
		echo '<p>' . esc_html__( 'Webhook-URL:', 'pass-captain' ) . ' <code>' . esc_html( \PassCaptain\Payments\Webhooks::endpoint_url( $provider ) ) . '</code></p>';
		if ( $events ) {
			echo '<p>' . esc_html( 'paypal' === $provider ? __( 'In der PayPal-App unter „Webhooks“ einen Webhook mit dieser URL und diesen Ereignissen anlegen; die angezeigte Webhook-ID unten eintragen:', 'pass-captain' ) : __( 'Im Stripe-Dashboard unter Entwickler → Webhooks einen Endpunkt mit dieser URL und diesen Ereignissen anlegen; das angezeigte Signaturgeheimnis unten eintragen:', 'pass-captain' ) ) . '</p>';
			echo '<ul class="wpcap-webhook-events" style="list-style:disc;margin-left:2em">';
			foreach ( $events as $event ) { echo '<li><code>' . esc_html( (string) $event ) . '</code></li>'; }
			echo '</ul>';
		}
		$missing = self::missing_provider_fields( $provider );
		if ( $missing ) {
			/* translators: %s: field names */
			$text = sprintf( __( 'Noch nicht vollständig eingerichtet. Es fehlen: %s.', 'pass-captain' ), implode( ', ', $missing ) );
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $text ) . ' ' . esc_html( sprintf( /* translators: %s: payment method */ __( '%s wird im Checkout erst danach angeboten.', 'pass-captain' ), $def['title'] ) ) . '</p></div>';
		}
		echo '</div>';
	}

	/** Separate form (outside the settings form) with the "Verbindung testen" button (A295). */
	private static function render_connection_test( $provider ) {
		echo '<h2 id="wpcap-connection-test">' . esc_html__( 'Verbindung testen', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Prüft mit den gespeicherten Zugangsdaten die Anmeldung beim Anbieter und ob der Webhook mit dieser URL und allen nötigen Ereignissen eingerichtet ist. Es wird nichts verändert; vorher speichern.', 'pass-captain' ) . '</p>';
		Forms::open_form( self::TEST_ACTION );
		Forms::hidden( 'provider', $provider );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Verbindung testen', 'pass-captain' ) . '</button></p></form>';
	}

	/**
	 * Runs the connection test of a provider (A295).
	 *
	 * @param array $in provider.
	 * @return array|\WP_Error ok, messages.
	 */
	public static function test_connection( array $in ) {
		if ( ! current_user_can( self::CAP ) ) { return Forms::forbidden(); }
		$provider = sanitize_key( (string) ( $in['provider'] ?? '' ) );
		$gateway = Registry::get( $provider );
		if ( ! $gateway || ! method_exists( $gateway, 'test_connection' ) ) {
			return Forms::error( 'wpcap_provider_test', __( 'Für diese Zahlungsart gibt es keinen Verbindungstest.', 'pass-captain' ) );
		}
		$result = (array) $gateway->test_connection();
		Audit::record( 'payment_connection_tested', 'settings', 0, array( 'provider' => $provider, 'ok' => ! empty( $result['ok'] ) ) );
		return array( 'ok' => ! empty( $result['ok'] ), 'messages' => array_values( (array) ( $result['messages'] ?? array() ) ) );
	}

	/** admin-post handler of the connection test: one notice per finding, back to the provider section. */
	public static function handle_test_connection() {
		$in = Forms::post();
		Forms::verify( self::TEST_ACTION );
		$result = self::test_connection( $in );
		$back = Forms::page_url( self::SLUG, self::url_args( 'payments', sanitize_key( (string) ( $in['provider'] ?? '' ) ) ) ) . '#wpcap-connection-test';
		if ( is_wp_error( $result ) ) { Forms::finish( $result, '', $back ); }
		foreach ( $result['messages'] as $message ) {
			Forms::flash( (string) ( $message[0] ?? 'warning' ), (string) ( $message[1] ?? '' ) );
		}
		Forms::redirect( $back );
	}

	/** Sub-navigation of the payments tab: overview, one entry per provider. */
	private static function render_payment_nav( $current ) {
		$items = array( 'payments' => array( __( 'Übersicht', 'pass-captain' ), Forms::page_url( self::SLUG, array( 'tab' => 'payments' ) ) ) );
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
		echo '<p><label><input type="checkbox" name="wpcap_provider_enabled" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html( sprintf( /* translators: %s: payment method */ __( '%s im Checkout anbieten', 'pass-captain' ), $gateway->title() ) ) . '</label>';
		if ( $enabled && ! $gateway->is_available( 'EUR' ) ) {
			echo ' <em>' . esc_html__( '(nicht vollständig konfiguriert)', 'pass-captain' ) . '</em>';
		}
		echo '</p>';
		$problem = 'stripe' === $provider_id ? \PassCaptain\Payments\StripeGateway::mode_problem() : '';
		if ( '' !== $problem ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
		}
		if ( is_callable( array( $gateway, 'is_test_mode' ) ) && $gateway->is_test_mode() ) {
			// A274: test mode is visible to the operator (and in the checkout through the method title).
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Testmodus aktiv: Es werden keine echten Zahlungen eingezogen. Käufer sehen im Checkout den Hinweis „Testmodus“. Für den Verkauf auf „Live“ umstellen und Live-Zugangsdaten hinterlegen.', 'pass-captain' ) . '</p></div>';
		}
		if ( 'manual' === $provider_id && ! \PassCaptain\Payments\ManualGateway::configured() ) {
			echo '<p class="description">' . esc_html__( 'Überweisung wird im Checkout erst angeboten, wenn Empfänger (oder Rechnungssteller) und IBAN oder ein Zahlungshinweis hinterlegt sind.', 'pass-captain' ) . '</p>';
		}
	}

	/** Legal texts of the further languages (one block per locale) plus a field to add a language. */
	private static function render_legal_translations() {
		$default = Settings::legal_default_locale();
		$stored = Settings::legal_translations();
		$raw = (array) Settings::get( 'legal_texts' );
		echo '<h2 id="wpcap-settings-legal-languages">' . esc_html__( 'Rechtstexte je Sprache', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Käufer sehen die Texte ihrer Sprache (Sprache der Seite bzw. von WPML/Polylang). Leere Felder übernehmen den Text der Standardsprache. Jede Sprache hat eine eigene Version, die mit der Bestellung gespeichert wird.', 'pass-captain' ) . '</p>';
		foreach ( Settings::legal_locales() as $locale ) {
			if ( $locale === $default ) { continue; }
			$texts = isset( $raw[ $locale ] ) && is_array( $raw[ $locale ] ) ? $raw[ $locale ] : array();
			echo '<h3 id="wpcap-legal-' . esc_attr( $locale ) . '">' . esc_html( $locale ) . '</h3>';
			echo '<table class="form-table"><tbody>';
			foreach ( array( 'terms_text' => __( 'AGB / Teilnahmebedingungen', 'pass-captain' ), 'consumer_info_text' => __( 'Verbraucherinformationen (z. B. Widerrufshinweis)', 'pass-captain' ) ) as $key => $label ) {
				$field_id = 'wpcap-legal-' . $locale . '-' . $key;
				echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label></th><td>';
				echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( 'legal_texts[' . $locale . '][' . $key . ']' ) . '" class="large-text" rows="6">' . esc_textarea( isset( $texts[ $key ] ) && is_scalar( $texts[ $key ] ) ? (string) $texts[ $key ] : '' ) . '</textarea>';
				if ( ! isset( $stored[ $locale ][ $key ] ) ) {
					/* translators: %s: locale code */
					echo '<p class="description">' . esc_html( sprintf( __( 'Leer: es gilt der Text von %s.', 'pass-captain' ), Settings::legal_source_locale( $key, $locale ) ) ) . '</p>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			if ( '' !== Settings::terms_version( $locale ) ) {
				/* translators: 1: locale code, 2: version hash */
				echo '<p class="description">' . esc_html( sprintf( __( 'Aktuelle Version für %1$s: %2$s', 'pass-captain' ), $locale, Settings::terms_version( $locale ) ) ) . '</p>';
			}
		}
		// A568: archived versions (append-only); orders reference them by their version.
		$archived = \PassCaptain\LegalVersions::recent( 10 );
		if ( $archived ) {
			echo '<h3>' . esc_html__( 'Archivierte Fassungen', 'pass-captain' ) . '</h3><p class="description">' . esc_html__( 'Jede Fassung, die in Kraft war, bleibt unverändert gespeichert. In der Bestellübersicht steht, welche Fassung für die Bestellung galt, und ihr Text lässt sich dort anzeigen.', 'pass-captain' ) . '</p>';
			echo '<table class="widefat striped" style="max-width:40em"><thead><tr><th scope="col">' . esc_html__( 'Fassung', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Sprache', 'pass-captain' ) . '</th><th scope="col">' . esc_html__( 'Archiviert am', 'pass-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $archived as $row ) {
				echo '<tr><td><code>' . esc_html( (string) $row->version ) . '</code></td><td>' . esc_html( (string) $row->locale ) . '</td><td>' . esc_html( Forms::datetime( (string) $row->created_at, wp_timezone_string() ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		// Keeps the key in the request even without further languages, so saving can drop the last one.
		echo '<input type="hidden" name="legal_texts[]" value="">';
		echo '<p><label for="wpcap-legal-new-locale">' . esc_html__( 'Weitere Sprache hinzufügen (Locale, z. B. en_US):', 'pass-captain' ) . '</label> <input type="text" id="wpcap-legal-new-locale" name="legal_new_locale" size="12" pattern="[a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2}"></p>';
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
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html__( 'Aktiv', 'pass-captain' ) . '</label>';
				break;
			case 'choice':
				Forms::select( $key, $def['options'], (string) $value, $id );
				break;
			case 'providers':
				$enabled = (array) $value;
				foreach ( Registry::all() as $provider_id => $gateway ) {
					echo '<div style="min-height:24px;padding:2px 0"><label><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $provider_id ) . '"' . checked( in_array( $provider_id, $enabled, true ), true, false ) . '> ' . esc_html( $gateway->title() ) . ' <code>' . esc_html( $provider_id ) . '</code>';
					if ( in_array( $provider_id, $enabled, true ) && ! $gateway->is_available( 'EUR' ) ) {
						echo ' <em>' . esc_html__( '(nicht vollständig konfiguriert)', 'pass-captain' ) . '</em>';
					}
					echo '</label>';
					$url = self::provider_url( $provider_id );
					if ( '' !== $url ) {
						/* translators: %s: payment method */
						echo ' – <a href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( '%s einrichten', 'pass-captain' ), $gateway->title() ) ) . '</a>';
					}
					echo '</div>';
				}
				break;
			case 'modules':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $def['label'] ) . '</legend>';
				foreach ( Dashboard::hideable_modules() as $module => $label ) {
					echo '<label style="display:block;min-height:24px;padding:2px 0"><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $module ) . '"' . checked( in_array( $module, (array) $value, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
				}
				echo '</fieldset>';
				break;
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="large-text" rows="4">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'html':
				// A298: legal texts with the WordPress editor (lists, links, bold) where available; textarea otherwise.
				if ( function_exists( 'wp_editor' ) && apply_filters( 'wpcap_settings_rich_editor', true, $key ) ) {
					wp_editor( (string) $value, self::editor_id( $key ), array( 'textarea_name' => $key, 'media_buttons' => false, 'teeny' => true, 'textarea_rows' => 8, 'quicktags' => true ) );
					break;
				}
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="large-text" rows="8">' . esc_textarea( (string) $value ) . '</textarea>';
				$version = 'terms_text' === $key ? Settings::terms_version( Settings::legal_default_locale() ) : '';
				if ( '' !== $version ) {
					echo '<p class="description">' . esc_html( sprintf( /* translators: %s: version hash */ __( 'Aktuelle Version: %s (wird mit jeder Bestellung gespeichert)', 'pass-captain' ), $version ) ) . '</p>';
				}
				break;
			case 'money':
				echo '<input type="text" inputmode="decimal" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" size="10" value="' . esc_attr( Forms::money_input( (int) $value, 2 ) ) . '">';
				break;
			case 'prefix':
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" maxlength="10" size="10" pattern="[A-Za-z0-9\-]+" value="' . esc_attr( (string) $value ) . '">';
				break;
			case 'email':
				echo '<input type="email" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" autocomplete="email" value="' . esc_attr( (string) $value ) . '"' . ( 'mail_reply_to' === $key ? ' placeholder="' . esc_attr( '' !== (string) Settings::get( 'mail_from_email' ) ? (string) Settings::get( 'mail_from_email' ) : (string) get_option( 'admin_email' ) ) . '"' : '' ) . '>';
				break;
			case 'secret':
				// Masked: status with the last four characters, empty input keeps the value (Secrets).
				if ( \PassCaptain\Secrets::is_secret( $key ) ) {
					\PassCaptain\Secrets::render_field( $key, $id );
					break;
				}
				// Secret of an extension outside the store (encrypted in wpcap_settings, Security\Vault): never echoed.
				echo self::secret_input( $key, $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" class="regular-text" value="' . esc_attr( (string) $value ) . '">';
		}
		if ( '' !== $override ) {
			/* translators: %s: constant name */
			echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Wird durch %s (wp-config.php oder Umgebungsvariable) überschrieben; der hier gespeicherte Wert wird nicht verwendet.', 'pass-captain' ), $override ) ) . '</strong></p>';
		}
		if ( ! empty( $def['description'] ) ) {
			echo '<p class="description">' . esc_html( $def['description'] ) . '</p>';
		}
		if ( 'delete_data_on_uninstall' === $key && ! $value ) {
			echo '<p><label for="wpcap-set-delete-confirm">' . esc_html__( 'Zum Aktivieren LÖSCHEN eintragen:', 'pass-captain' ) . '</label> <input type="text" id="wpcap-set-delete-confirm" name="delete_data_on_uninstall_confirm" autocomplete="off" size="10" value=""></p>';
		}
	}

	/** Typed confirmation for delete_data_on_uninstall ("LÖSCHEN" or "DELETE" for translated admin screens, case-insensitive). */
	private static function uninstall_confirmed( array $in ) {
		$typed = is_scalar( $in['delete_data_on_uninstall_confirm'] ?? null ) ? trim( (string) $in['delete_data_on_uninstall_confirm'] ) : '';
		return in_array( mb_strtoupper( $typed ), array( 'LÖSCHEN', 'LOESCHEN', 'DELETE' ), true );
	}
}
