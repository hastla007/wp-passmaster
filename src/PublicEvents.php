<?php
/** Public event listing and checkout embed. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PublicEvents {
	/** Whether the scanner shortcode was rendered in this request (scanner-only localizations). */
	private static $scanner_rendered = false;

	public static function scanner_rendered() {
		return self::$scanner_rendered;
	}

	/**
	 * Mail domains the checkout compares the buyer's e-mail domain with (typo hint, A553).
	 * Filter `wpcap_checkout_email_domains`; an empty list turns the hint off.
	 *
	 * @return string[]
	 */
	public static function email_domains() {
		$domains = array( 'gmail.com', 'googlemail.com', 'gmx.de', 'gmx.at', 'gmx.ch', 'gmx.net', 'gmx.com', 'web.de', 't-online.de', 'online.de', 'freenet.de', 'posteo.de', 'mailbox.org', 'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'live.de', 'msn.com', 'yahoo.com', 'yahoo.de', 'icloud.com', 'me.com', 'aon.at', 'a1.net', 'bluewin.ch', 'arcor.de', 'protonmail.com', 'proton.me' );
		$domains = (array) apply_filters( 'wpcap_checkout_email_domains', $domains );
		$clean = array();
		foreach ( $domains as $domain ) {
			$domain = strtolower( trim( (string) $domain ) );
			if ( 1 === preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain ) ) { $clean[ $domain ] = $domain; }
		}
		return array_values( $clean );
	}

	public static function register_assets() {
		wp_register_script( 'wpcap-checkout', WPCAP_URL . 'assets/checkout.js', array(), WPCAP_VERSION, true );
		// Privacy-friendly commerce events (wpcap:track) and UTM attribution, docs/TRACKING.md.
		wp_register_script( 'wpcap-tracking', WPCAP_URL . 'assets/tracking.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-checkout', 'WPCAP_CHECKOUT', self::checkout_config() );
		self::register_styles();
		wp_register_script( 'wpcap-scanner', WPCAP_URL . 'assets/scanner.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-scanner', 'WPCAP_SCANNER', array( 'validateUrl' => esc_url_raw( rest_url( 'pass-captain/v1/tickets/validate' ) ), 'checkinUrl' => esc_url_raw( rest_url( 'pass-captain/v1/tickets/check-in' ) ), 'offlineSyncUrl' => esc_url_raw( rest_url( 'pass-captain/v1/tickets/offline-sync' ) ), 'undoUrl' => current_user_can( Capabilities::UNDO_CHECKIN ) ? esc_url_raw( rest_url( 'pass-captain/v1/scanner/undo' ) ) : '', 'statsUrl' => esc_url_raw( rest_url( 'pass-captain/v1/scanner/stats' ) ), 'searchUrl' => esc_url_raw( rest_url( 'pass-captain/v1/scanner/search' ) ), 'checkInTicketUrl' => esc_url_raw( rest_url( 'pass-captain/v1/scanner/check-in-ticket' ) ), 'undoReasons' => ScannerDesk::undo_reasons(), 'jsqrUrl' => esc_url_raw( WPCAP_URL . 'assets/vendor/jsqr/jsQR.js?ver=1.4.0' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'i18n' => array(
			'syncButton' => __( 'Offline-Scans synchronisieren', 'pass-captain' ),
			/* translators: %d: number of queued scans (not 1; scanner.js uses syncCountOne for 1) */
			'syncCount' => __( '%d Offline-Scans synchronisieren', 'pass-captain' ),
			/* translators: %d: number of queued scans (always 1) */
			'syncCountOne' => __( '%d Offline-Scan synchronisieren', 'pass-captain' ),
			'syncFailed' => __( 'Synchronisierung fehlgeschlagen.', 'pass-captain' ),
			/* translators: 1: synced scans (not 1; scanner.js uses syncedOne for 1), 2: conflicts */
			'synced' => __( '%1$d Offline-Scans synchronisiert; Konflikte: %2$d.', 'pass-captain' ),
			/* translators: 1: synced scans (always 1), 2: conflicts */
			'syncedOne' => __( '%1$d Offline-Scan synchronisiert; Konflikte: %2$d.', 'pass-captain' ),
			'queued' => __( 'Offline gespeichert. Der Einlass wird erst nach der Synchronisierung serverseitig bestätigt.', 'pass-captain' ),
			'unchecked' => __( 'NICHT GEPRÜFT (offline): Das Ticket kann ohne Verbindung nicht geprüft werden. Nur nach Sichtprüfung einlassen; für geprüften Offline-Einlass die Offline-Scanner-App verwenden.', 'pass-captain' ),
			'admitUnchecked' => __( 'Nach Sichtprüfung einlassen (wird später geprüft)', 'pass-captain' ),
			'checking' => __( 'Ticket wird geprüft …', 'pass-captain' ),
			'invalid' => __( 'Ticket nicht gültig.', 'pass-captain' ),
			'noCamera' => __( 'Kamera-QR-Scan wird von diesem Browser nicht unterstützt. Nutze die manuelle Eingabe.', 'pass-captain' ),
			'cameraError' => __( 'Kamerazugriff nicht möglich:', 'pass-captain' ),
			'ok' => _x( 'Einlass', 'scanner result: entry granted', 'pass-captain' ),
			'refused' => __( 'Kein Einlass', 'pass-captain' ),
			'attention' => __( 'Achtung', 'pass-captain' ),
			/* translators: %s: scanner mode, e.g. "Einlass" */
			'modeBanner' => __( 'Modus: %s', 'pass-captain' ),
			'modeIn' => _x( 'Einlass', 'scanner direction: entry (opposite of exit)', 'pass-captain' ),
			'systemError' => __( 'Systemfehler – das Ticket wurde nicht geprüft. Bitte erneut scannen.', 'pass-captain' ),
			'sessionExpired' => __( 'Sitzung abgelaufen – bitte die Seite neu laden und erneut scannen.', 'pass-captain' ),
			'admitted' => __( 'EINLASS', 'pass-captain' ),
			'notAdmitted' => __( 'KEIN EINLASS', 'pass-captain' ),
			'checkedOutTitle' => __( 'AUSLASS', 'pass-captain' ),
			'firstAdmission' => __( 'Erstmals eingelassen', 'pass-captain' ),
			/* translators: 1: number of admissions, 2: time of the first admission */
			'reentry' => __( 'Einlass Nr. %1$s – zuerst eingelassen um %2$s', 'pass-captain' ),
			/* translators: %s: date/time of the first admission */
			'alreadyAt' => __( 'Bereits eingelassen um %s', 'pass-captain' ),
			/* translators: %s: date/time of the latest admission */
			'lastAt' => __( 'zuletzt um %s', 'pass-captain' ),
			/* translators: %s: name of the entrance area */
			'atArea' => __( 'Bereich: %s', 'pass-captain' ),
			/* translators: %s: scanner device or staff member (A595) */
			'byWhom' => __( 'durch %s', 'pass-captain' ),
			/* translators: %s: name of the entrance area of the first admission */
			'atEntrance' => __( 'Eingang: %s', 'pass-captain' ),
			/* translators: %s: display name of the staff member who admitted first */
			'staffName' => __( 'Personal: %s', 'pass-captain' ),
			/* translators: %d: number of further tickets in the order (A596) */
			'groupTitle' => __( 'Weitere Tickets dieser Bestellung (%d)', 'pass-captain' ),
			/* translators: %d: number of selected tickets */
			'groupCheckin' => __( 'Ausgewählte einchecken (%d)', 'pass-captain' ),
			/* translators: %d: number of tickets admitted */
			'groupDone' => __( '%d weitere eingelassen', 'pass-captain' ),
			/* translators: %d: number of tickets not admitted */
			'groupFailed' => __( '%d nicht eingelassen – bitte einzeln scannen', 'pass-captain' ),
			'viaHelpCode' => __( 'Per Hilfscode geprüft – Ticketinhaber ggf. mit Ausweis abgleichen.', 'pass-captain' ),
			'helpOffline' => __( 'Hilfscodes können nur online geprüft werden. Bitte QR-Code scannen oder den langen Ticket-Code eingeben.', 'pass-captain' ),
			'rateLimited' => __( 'Zu viele Hilfscode-Eingaben. Bitte kurz warten oder den QR-Code bzw. den langen Ticket-Code verwenden.', 'pass-captain' ),
			'soundOn' => __( 'Ton an', 'pass-captain' ),
			'soundOff' => __( 'Ton aus', 'pass-captain' ),
			'torch' => __( 'Taschenlampe', 'pass-captain' ),
			'dismiss' => __( 'Antippen zum Schließen', 'pass-captain' ),
			'discarded' => __( 'Scan verworfen – der vorige Scan wird noch bearbeitet. Bitte erneut scannen.', 'pass-captain' ),
			'multipleCodes' => __( 'Mehrere Ticketcodes in einer Eingabe – nichts geprüft. Bitte jedes Ticket einzeln scannen.', 'pass-captain' ),
			/* translators: %s: date/time of the earlier offline admission on this device */
			'queuedAlready' => __( 'Auf diesem Gerät bereits offline eingelassen um %s.', 'pass-captain' ),
			'timeout' => __( 'Keine Antwort vom Server.', 'pass-captain' ),
			/* translators: %d: HTTP status code */
			'badAnswer' => __( 'Unerwartete Antwort des Servers (HTTP %d) – das Ticket wurde nicht geprüft. Bitte erneut scannen.', 'pass-captain' ),
			/* translators: %d: seconds left to undo the admission */
			'undo' => __( 'Rückgängig (%d s)', 'pass-captain' ),
			'undoReason' => __( 'Grund der Rücknahme', 'pass-captain' ),
			'undoChoose' => __( '– Grund wählen –', 'pass-captain' ),
			'undoOther' => __( 'Anderer Grund …', 'pass-captain' ),
			'undoConfirm' => __( 'Einlass zurücknehmen', 'pass-captain' ),
			'undoNeedReason' => __( 'Bitte einen Grund wählen oder eingeben.', 'pass-captain' ),
			'undone' => __( 'Einlass zurückgenommen – das Ticket ist wieder gültig.', 'pass-captain' ),
			'undoneQueued' => __( 'Offline-Einlass verworfen – er wird nicht hochgeladen.', 'pass-captain' ),
			/* translators: 1: tickets admitted at least once, 2: tickets of the date */
			'counter' => __( 'Eingecheckt: %1$d / %2$d', 'pass-captain' ),
			/* translators: %d: scans saved offline on this device, not yet uploaded */
			'counterQueued' => __( '+%d offline', 'pass-captain' ),
			/* translators: %s: time of the last counter update */
			'counterStale' => __( 'Stand %s', 'pass-captain' ),
			'searchCheckin' => __( 'Einchecken', 'pass-captain' ),
			'searchNone' => __( 'Kein Ticket gefunden.', 'pass-captain' ),
			'searchMore' => __( 'Weitere Treffer – bitte genauer suchen.', 'pass-captain' ),
			/* translators: %s: short order number, e.g. AB12-CD34 */
			'searchOrder' => __( 'Bestellung %s', 'pass-captain' ),
			'searchUsed' => __( 'bereits eingecheckt', 'pass-captain' ),
			'searchVoid' => __( 'widerrufen/erstattet', 'pass-captain' ),
			'searchOffline' => __( 'Die Suche funktioniert nur online. Offline bitte die Offline-Scanner-App verwenden.', 'pass-captain' ),
		) ) );
	}

	/**
	 * Data for assets/checkout.js (WPCAP_CHECKOUT). The payment methods come per listing from
	 * /checkout/options (currency of the listing), so nothing payment-related is resolved here on every
	 * front-end request (A359). The REST nonce is only added for signed-in users: cached pages for guests
	 * stay nonce-free (a cached, expired nonce would break their checkout), and with the nonce the REST
	 * request runs as the signed-in customer, so buyer limits "per account" (Sales\BuyerLimits,
	 * customer_user_id) apply in the native checkout.
	 */
	public static function checkout_config() {
		$config = array(
			'url' => esc_url_raw( rest_url( 'pass-captain/v1/checkout' ) ),
			// Waitlist join: …/events/{event_id}/waitlist.
			'eventsUrl' => esc_url_raw( rest_url( 'pass-captain/v1/events/' ) ),
			'previewUrl' => esc_url_raw( rest_url( 'pass-captain/v1/campaigns/preview' ) ),
			// A456: live purchase state of the cards (cached pages); A457: current legal texts.
			'liveUrl' => esc_url_raw( rest_url( 'pass-captain/v1/checkout/live' ) ),
			'optionsUrl' => esc_url_raw( rest_url( 'pass-captain/v1/checkout/options' ) ),
			// A600: .ics download of the ordered date in the confirmation (…&id={occurrence}); '' without the calendar module.
			'icsBase' => Modules::enabled( Modules\Calendar::class ) ? esc_url_raw( add_query_arg( 'wpcap_ics', 'occurrence', home_url( '/' ) ) ) : '',
			'money' => Frontend\Format::js_config(),
			// Common mail domains for the typo hint on the buyer e-mail (A553).
			'emailDomains' => self::email_domains(),
			'i18n' => array(
				'selectTickets' => __( 'Bitte wähle mindestens ein Ticket.', 'pass-captain' ),
				/* translators: %s: regular price */
				'basePrice' => __( 'statt %s', 'pass-captain' ),
				'providerLegend' => __( 'Zahlungsart', 'pass-captain' ),
				// A457: shown at the terms checkbox after the texts were reloaded.
				'termsUpdated' => __( 'Die Bedingungen wurden inzwischen geändert. Bitte lies die aktuelle Fassung oben und bestätige sie erneut.', 'pass-captain' ),
				/* translators: 1: subtotal, 2: discount, 3: total */
				'estimateDiscount' => __( 'Summe: %1$s − Rabatt %2$s = %3$s (vorläufig; der endgültige Preis wird bei der Bestellung berechnet)', 'pass-captain' ),
				'codeInvalid' => __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'pass-captain' ),
				'waitlistJoining' => __( 'Wird eingetragen …', 'pass-captain' ),
				'waitlistJoined' => __( 'Du stehst auf der Warteliste. Wir melden uns per E-Mail, sobald ein Platz frei wird.', 'pass-captain' ),
				'waitlistFailed' => __( 'Eintragen fehlgeschlagen.', 'pass-captain' ),
				'waitlistEmail' => __( 'Bitte gib eine gültige E-Mail-Adresse ein.', 'pass-captain' ),
				'tooMany' => __( 'Für eine Ticketart wurde die Höchstmenge überschritten.', 'pass-captain' ),
				'creating' => __( 'Bestellung wird angelegt …', 'pass-captain' ),
				'failed' => __( 'Bestellung fehlgeschlagen.', 'pass-captain' ),
				'complete' => __( 'Bestellung abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'pass-captain' ),
				'redirecting' => __( 'Weiterleitung zur Zahlung …', 'pass-captain' ),
				// A309: the answer was no valid order confirmation (e.g. a cache or firewall page).
				'unclearAnswer' => __( 'Wir haben keine eindeutige Antwort erhalten, ob deine Bestellung angelegt wurde. Sieh bitte in dein E-Mail-Postfach oder sende das Formular unverändert erneut ab – es wird dabei keine zweite Bestellung angelegt.', 'pass-captain' ),
				/* translators: %s: short order number */
				'pendingOrder' => __( 'Deine Bestellung %s wartet noch auf die Zahlung.', 'pass-captain' ),
				'resumePayment' => __( 'Zahlung fortsetzen', 'pass-captain' ),
				'pendingHint' => __( 'Sendest du das Formular unverändert erneut ab, wird dieselbe Bestellung fortgesetzt. Mit geänderter Auswahl entsteht eine neue Bestellung; die Reservierung der alten verfällt dann automatisch.', 'pass-captain' ),
				/* translators: %d: hold minutes */
				'pending' => __( 'Bestellung angelegt; deine Tickets sind %d Minuten reserviert.', 'pass-captain' ), // n>1: checkout.js uses pendingOne for 1
				'pendingOne' => __( 'Bestellung angelegt; deine Tickets sind 1 Minute reserviert.', 'pass-captain' ),
				/* translators: %d: hold hours */
				'pendingHours' => __( 'Bestellung angelegt; deine Tickets sind %d Stunden reserviert.', 'pass-captain' ), // n>1: only for 2+ hours (checkout.js)
				/* translators: %d: hold days */
				'pendingDays' => __( 'Bestellung angelegt; deine Tickets sind %d Tage reserviert.', 'pass-captain' ), // n>1: only for 2+ days (checkout.js)
				/* translators: %d: ticket number */
				'attendee' => __( 'Teilnehmer:in %d', 'pass-captain' ),
				// Short note in the quantity live region when the number of attendee blocks changes (A311).
				'attendeesOne' => __( 'Weiter unten: Angaben für 1 Teilnehmer:in.', 'pass-captain' ),
				/* translators: %d: number of attendee blocks (2 or more) */
				'attendeesMany' => __( 'Weiter unten: Angaben für %d Teilnehmende.', 'pass-captain' ), // n>1: checkout.js uses attendeesOne for 1
				'name' => __( 'Name', 'pass-captain' ),
				// A552/A680: attendee blocks.
				'sameAsBuyer' => __( 'Meine Angaben übernehmen', 'pass-captain' ),
				'perOrder' => __( '(für die ganze Bestellung)', 'pass-captain' ),
				'choose' => __( 'Bitte wählen', 'pass-captain' ),
				'email' => __( 'E-Mail der Teilnehmer:in (optional, für ein eigenes Ticket)', 'pass-captain' ),
				/* translators: %s: formatted amount */
				'estimate' => __( 'Summe: %s (vorbehaltlich Rabatt; der endgültige Preis wird bei der Bestellung berechnet)', 'pass-captain' ),
				/* translators: %s: formatted amount */
				'orderTotal' => __( 'Gesamtbetrag: %s.', 'pass-captain' ),
				// A165: shown while a busy server is retried automatically (same order attempt).
				'retrying' => __( 'Gerade ist viel los. Wir versuchen es automatisch noch einmal …', 'pass-captain' ),
				/* translators: %s: formatted service fee amount (A425) */
				'estimateFee' => __( 'inkl. Servicegebühr %s', 'pass-captain' ),
				// Error summary and inline field errors.
				'errorSummary' => __( 'Bitte prüfe deine Angaben', 'pass-captain' ),
				// A383: the summary line is only shown for two or more problems.
				/* translators: %d: number of problems (always 2 or more) */
				'errorCount' => __( 'Es gibt %d Probleme. Wähle einen Eintrag, um zum Feld zu springen.', 'pass-captain' ),
				/* translators: %s: field label */
				'errRequired' => __( 'Bitte fülle „%s“ aus.', 'pass-captain' ),
				/* translators: %s: field label */
				'errEmail' => __( '„%s“: Bitte gib eine gültige E-Mail-Adresse ein, z. B. name@example.com.', 'pass-captain' ),
				/* translators: %s: field label */
				'errInvalid' => __( '„%s“ ist ungültig.', 'pass-captain' ),
				/* translators: 1: attendee block ("Teilnehmer:in 2 · Erwachsene"), 2: error message */
				'attendeeError' => __( '%1$s: %2$s', 'pass-captain' ),
				'errTerms' => __( 'Bitte bestätige die Bedingungen, um zu bestellen.', 'pass-captain' ),
				/* translators: 1: ticket type, 2: maximum quantity */
				'errTooMany' => __( '„%1$s“: Es sind höchstens %2$d Tickets möglich.', 'pass-captain' ),
				// Confirmation panel.
				'thanks' => __( 'Vielen Dank für deine Bestellung!', 'pass-captain' ),
				'thanksComplete' => __( 'Bestellung abgeschlossen', 'pass-captain' ),
				/* translators: %s: short order number */
				'orderNumber' => __( 'Bestellnummer: %s', 'pass-captain' ),
				/* translators: %s: e-mail address */
				'sentTo' => __( 'Wir haben die Bestätigung mit deinem persönlichen Bestelllink an %s gesendet.', 'pass-captain' ),
				/* translators: %s: e-mail address */
				'ticketsSentTo' => __( 'Die Tickets und die Bestätigung haben wir an %s gesendet.', 'pass-captain' ),
				/* translators: %s: buyer e-mail address with the corrected domain */
				'emailSuggest' => __( 'Meintest du %s?', 'pass-captain' ),
				/* translators: %s: buyer e-mail address with the corrected domain */
				'emailSuggestApply' => __( '%s übernehmen', 'pass-captain' ),
				/* translators: 1: suggested e-mail address, 2: e-mail address as entered */
				'emailTypo' => __( 'Bitte prüfe deine E-Mail-Adresse: Meintest du %1$s? Wenn %2$s stimmt, sende das Formular einfach noch einmal ab.', 'pass-captain' ),
				'wrongEmail' => __( 'Adresse falsch? Dann kommen Bestätigung und Tickets nicht an. Wende dich bitte mit deiner Bestellnummer an den Veranstalter.', 'pass-captain' ),
				'spamHint' => __( 'Keine E-Mail erhalten? Sieh bitte auch im Spam-Ordner nach.', 'pass-captain' ),
				'payNow' => __( 'Bitte überweise den Betrag mit diesen Angaben:', 'pass-captain' ),
				/* translators: %s: date and time */
				'payUntil' => __( 'Deine Tickets sind bis %s reserviert. Geht die Zahlung bis dahin nicht ein, verfällt die Reservierung.', 'pass-captain' ),
				// A601: copy buttons and GiroCode of the bank transfer details.
				'copy' => __( 'Kopieren', 'pass-captain' ),
				/* translators: %s: field, e.g. IBAN */
				'copyLabel' => __( '%s kopieren', 'pass-captain' ),
				'copied' => __( 'Kopiert', 'pass-captain' ),
				'girocode' => __( 'Mit der Banking-App scannen (GiroCode)', 'pass-captain' ),
				'girocodeAlt' => __( 'GiroCode: mit der Banking-App scannen, um die Überweisung vorauszufüllen', 'pass-captain' ),
				'afterPayment' => __( 'Nach Zahlungseingang erhältst du deine Tickets per E-Mail.', 'pass-captain' ),
				'amount' => __( 'Betrag', 'pass-captain' ),
				'recipient' => __( 'Empfänger', 'pass-captain' ),
				'iban' => __( 'IBAN', 'pass-captain' ),
				'bic' => __( 'BIC', 'pass-captain' ),
				'remittance' => __( 'Verwendungszweck', 'pass-captain' ),
				'dueDate' => __( 'Zahlbar bis', 'pass-captain' ),
				'orderAgain' => __( 'Weitere Tickets bestellen', 'pass-captain' ),
				// Quantity stepper (−/+ around the number field).
				/* translators: %s: ticket type */
				'stepDown' => __( '%s: ein Ticket weniger', 'pass-captain' ),
				/* translators: %s: ticket type */
				'stepUp' => __( '%s: ein Ticket mehr', 'pass-captain' ),
				/* translators: %s: ticket type */
				'qtyNone' => __( '%s: keine Tickets', 'pass-captain' ),
				/* translators: %s: ticket type */
				'qtyOne' => __( '%s: 1 Ticket', 'pass-captain' ),
				/* translators: 1: ticket type, 2: quantity (2 or more) */
				'qtyMany' => __( '%1$s: %2$d Tickets', 'pass-captain' ), // n>1: qtyOne / qtyNone otherwise
				'qtyLimit' => __( 'Höchstmenge erreicht.', 'pass-captain' ),
				/* translators: %d: maximum tickets per order (2 or more) */
				'errTotal' => __( 'Es sind höchstens %d Tickets pro Person möglich.', 'pass-captain' ), // n>1: checkout.js uses errTotalOne for 1
				'errTotalOne' => __( 'Es ist höchstens 1 Ticket pro Person möglich.', 'pass-captain' ),
				/* translators: %s: formatted amount */
				'submitTotal' => __( 'Summe: %s', 'pass-captain' ), // A602: next to the order button.
				// A600: link in the order confirmation.
				'addToCalendar' => __( 'Termin in den Kalender eintragen (.ics)', 'pass-captain' ),
				// A409: price of a free ticket type (same as Frontend\Format::price()).
				'free' => __( 'kostenlos', 'pass-captain' ),
			),
		);
		// Live state refresh while the page is visible (15–60 s; the state is also loaded once after load).
		$config['poll'] = min( 60, max( 15, (int) apply_filters( 'wpcap_availability_poll_seconds', 20 ) ) );
		// Ticket type availability snapshot (GET /availability, cacheable 5 s) and the waitlist route of an event (id 0 is replaced).
		$config['availabilityUrl'] = esc_url_raw( rest_url( 'pass-captain/v1/availability' ) );
		$config['waitlistUrl'] = esc_url_raw( rest_url( 'pass-captain/v1/events/0/waitlist' ) );
		$config['i18n']['retrying'] = __( 'Gerade ist viel los – neuer Versuch …', 'pass-captain' );
		if ( get_current_user_id() > 0 ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		return $config;
	}

	/** Shortcodes whose output needs a stylesheet, tag => style handle (A391). */
	const SHORTCODE_STYLES = array(
		'wp_pass_captain_events' => 'wpcap-public',
		'wpcap_events' => 'wpcap-public',
		'wp_pass_captain_scanner' => 'wpcap-public',
		'wpcap_scanner' => 'wpcap-public',
		'wpcap_passes' => 'wpcap-public',
		'wp_pass_captain_calendar' => 'wpcap-calendar',
		'wpcap_calendar' => 'wpcap-calendar',
	);

	/**
	 * Registers the front-end stylesheets (init, priority 5, and again on wp_enqueue_scripts; A391):
	 * the blocks name them in block.json ("style"), so WordPress loads them in the <head> of pages
	 * with the block and in the editor canvas – the preview is styled like the website.
	 */
	public static function register_styles() {
		if ( ! function_exists( 'wp_register_style' ) ) { return; }
		$styles = array( 'wpcap-public' => 'assets/public.css', 'wpcap-calendar' => 'assets/calendar/calendar.css' );
		foreach ( $styles as $handle => $file ) {
			if ( ! function_exists( 'wp_style_is' ) || ! wp_style_is( $handle, 'registered' ) ) { wp_register_style( $handle, WPCAP_URL . $file, array(), WPCAP_VERSION ); }
		}
	}

	/**
	 * wp_enqueue_scripts (A391): on a single post or page whose content contains one of the plugin's
	 * shortcodes, its stylesheet goes into the <head> (no unstyled flash, no late <link> in the body).
	 * Shortcodes in widgets or templates keep the enqueue at render time.
	 *
	 * @return string[] Enqueued handles.
	 */
	public static function enqueue_shortcode_styles() {
		if ( ! function_exists( 'is_singular' ) || ! function_exists( 'has_shortcode' ) || ! is_singular() ) { return array(); }
		$post = function_exists( 'get_post' ) ? get_post() : null;
		$content = is_object( $post ) ? (string) ( $post->post_content ?? '' ) : '';
		if ( '' === $content || false === strpos( $content, '[' ) ) { return array(); }
		self::register_styles();
		$handles = array();
		foreach ( self::SHORTCODE_STYLES as $tag => $handle ) {
			if ( ! isset( $handles[ $handle ] ) && has_shortcode( $content, $tag ) ) { $handles[ $handle ] = $handle; }
		}
		foreach ( $handles as $handle ) { wp_enqueue_style( $handle ); }
		return array_values( $handles );
	}

	/** Default attributes of [wpcap_events] and the pass-captain/events block. */
	const DEFAULT_ATTS = array( 'event' => 0, 'occurrence' => 0, 'limit' => 50, 'layout' => 'auto' );

	/** Query parameter of the single-occurrence view (?wpcap_occ=ID) of the list layout. */
	const OCC_PARAM = 'wpcap_occ';

	/**
	 * Normalised shortcode/block attributes: event (id), occurrence (id), limit (1–200), layout
	 * auto|list|cards.
	 */
	public static function atts( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		$atts = function_exists( 'shortcode_atts' ) ? shortcode_atts( self::DEFAULT_ATTS, $atts, 'wp_pass_captain_events' ) : array_merge( self::DEFAULT_ATTS, array_intersect_key( $atts, self::DEFAULT_ATTS ) );
		$layout = sanitize_key( (string) $atts['layout'] );
		return array(
			'event' => absint( $atts['event'] ),
			'occurrence' => absint( $atts['occurrence'] ),
			'limit' => max( 1, min( 200, absint( $atts['limit'] ) ?: 50 ) ),
			'layout' => in_array( $layout, array( 'list', 'cards' ), true ) ? $layout : 'auto',
		);
	}

	/**
	 * Layout "auto" shows the ticket cards (one per event, A342); when a listing would need more cards
	 * than this, it becomes the compact list with a "Tickets" link per date (filter
	 * wpcap_events_auto_list_threshold).
	 */
	public static function auto_list_threshold() {
		return max( 1, (int) apply_filters( 'wpcap_events_auto_list_threshold', 20 ) );
	}

	/**
	 * [wp_pass_captain_events event="12" occurrence="34" limit="50" dates="each" layout="auto|list|cards"]
	 * – upcoming bookable occurrences, optionally only of one event or one occurrence (also the block
	 * attributes). A342: one card per event by default (dates="each": one card per date as before).
	 * layout="list": compact list of dates with a "Tickets" link to the single-date view (?wpcap_occ=ID,
	 * series with a date picker); layout="cards": one card per date; "auto" (default): cards, or the
	 * list when there would be more cards than auto_list_threshold().
	 */
	public static function render_shortcode( $atts = array(), $content = '', $tag = '' ) {
		LegacyNames::shortcode_used( $tag );
		$raw = is_array( $atts ) ? $atts : array();
		$atts = self::shortcode_atts( array( 'event' => 0, 'occurrence' => 0, 'limit' => 50, 'dates' => '', 'layout' => 'auto' ), $raw, $tag, 'wp_pass_captain_events' );
		// A503: an invalid value no longer falls back silently to "all events" (or the defaults).
		$problems = self::invalid_shortcode_atts( $atts, array( 'event' => 'id', 'occurrence' => 'id', 'limit' => 'count', 'dates' => array( '', 'each' ), 'layout' => array( '', 'auto', 'list', 'cards' ) ), $raw );
		$broken = isset( $problems['event'] ) || isset( $problems['occurrence'] );
		$defaults = array( 'limit' => 50, 'dates' => '', 'layout' => 'auto' );
		foreach ( array_keys( $problems ) as $key ) { $atts[ $key ] = $defaults[ $key ] ?? 0; }
		$atts['limit'] = max( 1, min( 200, absint( $atts['limit'] ) ?: 50 ) );
		$atts['layout'] = in_array( strtolower( trim( (string) $atts['layout'] ) ), array( 'list', 'cards' ), true ) ? strtolower( trim( (string) $atts['layout'] ) ) : 'auto';
		$atts['none'] = $broken;
		$html = self::render_listing( $atts, '' );
		if ( ! $problems && false !== strpos( $html, 'wpcap-events-empty' ) && self::can_see_shortcode_notice() ) {
			$problems = self::unknown_shortcode_ids( $atts );
		}
		return self::shortcode_notice( $problems, '' !== (string) $tag ? (string) $tag : 'wp_pass_captain_events' ) . $html;
	}

	/**
	 * shortcode_atts() with the filter tag of the shortcode actually used (A503: [wpcap_events] runs
	 * `shortcode_atts_wpcap_events`); the filter of the long name (`shortcode_atts_{$legacy}`) still
	 * runs for the short name, so existing filters keep working.
	 */
	public static function shortcode_atts( array $pairs, array $atts, $tag, $legacy ) {
		$tag = '' !== (string) $tag ? (string) $tag : (string) $legacy;
		$out = shortcode_atts( $pairs, $atts, $tag );
		if ( $tag !== $legacy && function_exists( 'apply_filters' ) ) { $out = (array) apply_filters( 'shortcode_atts_' . $legacy, $out, $pairs, $atts, $legacy ); }
		return $out;
	}

	/**
	 * Attributes that were given with an unusable value (A503): rule "id" (positive integer or 0),
	 * "count" (positive integer) or a list of allowed values. Only attributes present in $raw count.
	 *
	 * @return array key => given value.
	 */
	public static function invalid_shortcode_atts( array $atts, array $rules, array $raw ) {
		$out = array();
		foreach ( $rules as $key => $rule ) {
			if ( ! array_key_exists( $key, $raw ) ) { continue; }
			$value = is_scalar( $atts[ $key ] ) ? trim( (string) $atts[ $key ] ) : '';
			$ok = is_array( $rule ) ? in_array( strtolower( $value ), $rule, true ) : ( 1 === preg_match( '/^\d{1,10}$/', $value ) && ( 'id' === $rule || (int) $value > 0 ) );
			if ( ! $ok ) { $out[ $key ] = is_scalar( $atts[ $key ] ) ? (string) $atts[ $key ] : ''; }
		}
		return $out;
	}

	/** Whether the visitor may see shortcode hints (editors; never in feeds or REST output). */
	public static function can_see_shortcode_notice() {
		if ( ( function_exists( 'is_feed' ) && is_feed() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! self::is_block_preview() ) ) { return false; }
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
	}

	/** event/occurrence ids of the shortcode that are not (or no longer) public: key => id. */
	private static function unknown_shortcode_ids( array $atts ) {
		global $wpdb;
		$out = array();
		$event = absint( $atts['event'] ?? 0 );
		$occurrence = absint( $atts['occurrence'] ?? 0 );
		if ( $event && ! $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d AND status='published'", $event ) ) ) { $out['event'] = (string) $event; }
		if ( $occurrence && ! $wpdb->get_var( $wpdb->prepare( "SELECT o.occurrence_id FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.occurrence_id=%d AND e.status='published'", $occurrence ) ) ) { $out['occurrence'] = (string) $occurrence; }
		return $out;
	}

	/**
	 * Hint for editors above a shortcode with unusable attributes (A503); visitors see nothing.
	 *
	 * @param array  $problems key => given value.
	 * @param string $tag      Shortcode name.
	 */
	public static function shortcode_notice( array $problems, $tag ) {
		if ( ! $problems || ! self::can_see_shortcode_notice() ) { return ''; }
		$items = array();
		foreach ( $problems as $key => $value ) {
			/* translators: 1: attribute name, 2: value as written in the shortcode */
			$items[] = sprintf( __( '%1$s="%2$s" ist ungültig oder verweist auf nichts Veröffentlichtes', 'pass-captain' ), $key, $value );
		}
		/* translators: 1: shortcode name, 2: list of problems */
		$text = sprintf( __( 'Hinweis für Redakteur:innen zum Shortcode [%1$s]: %2$s. Besucher sehen diesen Hinweis nicht.', 'pass-captain' ), $tag, implode( '; ', $items ) );
		return '<p class="wpcap-shortcode-notice" role="note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A499: whether the shortcode/block output goes to a feed or a REST response (content.rendered of
	 * /wp/v2/posts, headless frontends, apps) – not the editor's block preview. There an order form
	 * without its script makes no sense; the output is a plain list of dates with links (slim_listing(),
	 * calendar: list view). Filter `wpcap_slim_output` ($slim, $what = 'events'|'calendar') switches it.
	 */
	public static function slim_output( $what = 'events' ) {
		$slim = ( function_exists( 'is_feed' ) && is_feed() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! self::is_block_preview() );
		return (bool) apply_filters( 'wpcap_slim_output', $slim, (string) $what );
	}

	/**
	 * Plain list of the listed dates (A499): event name linked to its card on the page
	 * (occurrence_link(); in feeds and REST responses based on the post's permalink), date, place and
	 * "Vorverkauf ab …" for dates not on sale yet. No form, no scripts, no ids.
	 *
	 * @param object[] $cards   Listing rows (one per card).
	 * @param array    $listing occurrence_id => ticket types on sale.
	 * @param array    $presale occurrence_id => sales start.
	 * @param string   $wrapper Attributes of the root element ('' = class="wpcap-events").
	 */
	private static function slim_listing( array $cards, array $listing, array $presale, $wrapper ) {
		$items = '';
		foreach ( $cards as $event ) {
			$occ = (int) $event->occurrence_id;
			if ( empty( $listing[ $occ ] ) && ! isset( $presale[ $occ ] ) ) { continue; }
			$items .= '<li><a href="' . esc_url( self::occurrence_link( $occ ) ) . '">' . esc_html( $event->name ) . '</a> – <time datetime="' . esc_attr( Frontend\EventCard::iso( $event->starts_at ) ) . '">' . esc_html( Frontend\Format::event_start( $event->starts_at, $event->timezone ) ) . '</time>'
				. ( $event->location ? ', ' . esc_html( $event->location ) : '' )
				/* translators: %s: date and time the online sale starts */
				. ( isset( $presale[ $occ ] ) ? ' (' . esc_html( sprintf( __( 'Vorverkauf ab %s', 'pass-captain' ), Frontend\Format::event_start( $presale[ $occ ], $event->timezone ) ) ) . ')' : '' ) . '</li>';
		}
		$body = '' !== $items ? '<ul class="wpcap-events-list">' . $items . '</ul>' : '<p class="wpcap-events-empty">' . esc_html__( 'Aktuell sind keine Veranstaltungen verfügbar.', 'pass-captain' ) . '</p>';
		return '<div ' . ( '' !== $wrapper ? $wrapper : 'class="wpcap-events"' ) . '>' . $body . '</div>';
	}

	/** Whether the request renders the block preview of the editor (REST block renderer). */
	public static function is_block_preview() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
		$route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared.
		return false !== strpos( $uri . ' ' . $route, '/block-renderer/' );
	}

	/**
	 * Render callback of the block pass-captain/events and its former name (render.php, A449, A303): the block wrapper
	 * attributes (block class, custom CSS class, alignment, spacing) go on the root element, so
	 * theme.json block styles and the classes set in the editor apply on the website too.
	 */
	public static function render_block( array $attributes ) {
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'wpcap-events' ) ) : '';
		// eventId/occurrenceId: attribute names of blocks saved before event/occurrence existed.
		$event = absint( $attributes['event'] ?? 0 ) ?: absint( $attributes['eventId'] ?? 0 );
		$occurrence = absint( $attributes['occurrence'] ?? 0 ) ?: absint( $attributes['occurrenceId'] ?? 0 );
		$layout = sanitize_key( (string) ( $attributes['layout'] ?? 'auto' ) );
		return self::render_listing(
			array(
				'event' => $event,
				'occurrence' => $occurrence,
				'limit' => max( 1, min( 200, absint( $attributes['limit'] ?? 50 ) ?: 50 ) ),
				'dates' => empty( $attributes['perDate'] ) ? '' : 'each',
				'layout' => in_array( $layout, array( 'list', 'cards' ), true ) ? $layout : 'auto',
			),
			$wrapper
		);
	}

	/** Query parameter that selects the date shown on an event's card (A342). */
	const OCCURRENCE_PARAM = 'wpcap_occurrence';

	/** Occurrence requested through ?wpcap_occurrence= (0 = none). */
	public static function requested_occurrence() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects what a public page shows.
		return isset( $_GET[ self::OCCURRENCE_PARAM ] ) && is_scalar( $_GET[ self::OCCURRENCE_PARAM ] ) ? absint( $_GET[ self::OCCURRENCE_PARAM ] ) : 0;
	}

	/**
	 * Link that shows the card of an occurrence on the current page (A342): the request URL with
	 * ?wpcap_occurrence={id} and the card anchor #wpcap-occ-{id}.
	 */
	public static function occurrence_link( $occurrence_id ) {
		return Modules\Calendar::nav_url( self::OCCURRENCE_PARAM, absint( $occurrence_id ), 'wpcap-occ-' . absint( $occurrence_id ) );
	}

	/**
	 * Listing rows (event_id, name, description, occurrence_id, starts_at, timezone, location, plus
	 * doors_open_at/revision when available) of published, scheduled occurrences that are upcoming or
	 * still running (multi-day, A535), sorted by start.
	 *
	 * @param int  $event_id      Only this event (0 = all).
	 * @param int  $occurrence_id Only this occurrence (0 = all).
	 * @param int  $limit         1–200.
	 * @param bool $none          No rows at all (A503: invalid shortcode attribute).
	 * @return object[]
	 */
	public static function listing_rows( $event_id = 0, $occurrence_id = 0, $limit = 50, $none = false ) {
		global $wpdb;
		list( $sql, $args ) = self::listing_sql( $event_id, $none );
		if ( absint( $occurrence_id ) ) { $sql .= ' AND o.occurrence_id=%d'; $args[] = absint( $occurrence_id ); }
		$args[] = max( 1, min( 200, absint( $limit ) ?: 50 ) );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY o.starts_at ASC,o.occurrence_id ASC LIMIT %d', ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals.
	}

	/** SELECT of listing_rows() with its arguments (without ORDER BY/LIMIT). */
	private static function listing_sql( $event_id, $none = false ) {
		global $wpdb;
		// Upcoming occurrences, plus running ones (multi-day) with a ticket type still on sale (A535).
		$where = ' AND ' . Occurrence::listable_sql( 'o', Clock::mysql() );
		$args = array();
		if ( absint( $event_id ) ) { $where .= ' AND e.event_id=%d'; $args[] = absint( $event_id ); }
		if ( $none ) { $where .= ' AND 1=0'; } // A503: invalid event/occurrence attribute – no "all events".
		$sql = "SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location" . ( DoorsOpen::ready() ? ',o.doors_open_at' : '' ) . ( Calendar\Revisions::ready() ? ',o.revision' : '' ) . " FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled'{$where}";
		return array( $sql, $args );
	}

	/**
	 * Event listing of the shortcode and the block.
	 *
	 * A342: unless `dates` is "each" (or one occurrence is selected), each event gets one card: the
	 * date requested with ?wpcap_occurrence=, else its first bookable date (else its first presale
	 * date). The card's "Weitere Termine" link to the same page with ?wpcap_occurrence={id}, so the
	 * page holds one checkout form per event instead of one per date. A requested date beyond the
	 * listing limit is loaded too, so links from the calendar, .ics files and mails always find
	 * their card; old #wpcap-occ-{id} links of the event's other dates land on its card.
	 *
	 * Layout "list" (or "auto" with more cards than auto_list_threshold()) shows the compact list;
	 * ?wpcap_occ={id} opens the single-date view (back link, date picker of the series).
	 *
	 * @param array  $atts    event, occurrence, limit, dates ('each' or ''), layout (auto|list|cards).
	 * @param string $wrapper Attributes of the root element ('' = class="wpcap-events").
	 */
	private static function render_listing( array $atts, $wrapper ) {
		global $wpdb;
		$layout = (string) ( $atts['layout'] ?? 'auto' );
		if ( ! absint( $atts['occurrence'] ) && empty( $atts['none'] ) ) {
			$single = absint( wp_unslash( $_GET[ self::OCC_PARAM ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public, read-only view; absint.
			// The compact list has no cards: a date requested with ?wpcap_occurrence= (calendar, .ics, mails) opens its single view.
			if ( ! $single && 'list' === $layout && ! self::slim_output( 'events' ) ) { $single = self::requested_occurrence(); }
			if ( $single ) {
				return Payments\ReturnNotice::take() . self::render_detail( $single, $atts, true, $wrapper );
			}
		}
		list( $sql, $args ) = self::listing_sql( $atts['event'] ?? 0, ! empty( $atts['none'] ) );
		if ( absint( $atts['occurrence'] ) ) { $sql .= ' AND o.occurrence_id=%d'; $args[] = absint( $atts['occurrence'] ); }
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY o.starts_at ASC,o.occurrence_id ASC LIMIT %d', ...array_merge( $args, array( max( 1, min( 200, absint( $atts['limit'] ) ?: 50 ) ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals.
		$requested = absint( $atts['occurrence'] ) ? 0 : self::requested_occurrence();
		if ( $requested && ! in_array( $requested, array_map( static function ( $row ) { return (int) $row->occurrence_id; }, (array) $rows ), true ) ) {
			$extra = $wpdb->get_row( $wpdb->prepare( $sql . ' AND o.occurrence_id=%d', ...array_merge( $args, array( $requested ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals.
			if ( $extra ) {
				$rows[] = $extra;
				usort( $rows, static function ( $a, $b ) { return strcmp( (string) $a->starts_at, (string) $b->starts_at ) ?: (int) $a->occurrence_id <=> (int) $b->occurrence_id; } );
			}
		}
		$rows = (array) $rows;
		if ( 'cards' === $layout ) { $atts['dates'] = 'each'; }
		if ( 'list' === $layout && ! absint( $atts['occurrence'] ) && ! self::slim_output( 'events' ) ) {
			return Payments\ReturnNotice::take() . self::render_list( $rows, $wrapper );
		}
		return self::render_rows( $rows, $atts, $wrapper, $requested );
	}

	/**
	 * Cards (or, for layout "auto" with many cards, the compact list) of the given listing rows.
	 *
	 * @param object[] $rows      Listing rows sorted by start.
	 * @param array    $atts      occurrence, dates, layout.
	 * @param string   $wrapper   Attributes of the root element ('' = class="wpcap-events").
	 * @param int      $requested Occurrence requested with ?wpcap_occurrence= (0 = none).
	 */
	private static function render_rows( array $rows, array $atts, $wrapper = '', $requested = 0 ) {
		/**
		 * The occurrences of an event listing are known (A376): modules load their per-occurrence/per-
		 * event data for exactly these in one query (seat map links, buyer limits) instead of per card
		 * or for the whole history.
		 *
		 * @param int[] $occurrence_ids Occurrences of the listing.
		 * @param int[] $event_ids      Their events.
		 */
		do_action( 'wpcap_listing_occurrences', array_values( array_unique( array_map( static fn( $row ) => (int) $row->occurrence_id, $rows ) ) ), array_values( array_unique( array_map( static fn( $row ) => (int) $row->event_id, $rows ) ) ) );
		$meta = array();
		$listing = self::listing_data( $rows, $meta );
		$card_listing = self::card_listing( $rows, $listing, $meta );
		// A293: dates whose ticket types all start selling later get a "Vorverkauf ab …" card.
		$presale = self::presale_starts( $rows, $listing );
		$grouped = 'each' !== (string) ( $atts['dates'] ?? '' ) && ! absint( $atts['occurrence'] ?? 0 );
		$cards = $grouped ? self::card_rows( $rows, $listing, $presale, $requested ) : $rows;
		if ( self::slim_output( 'events' ) ) { return self::slim_listing( $cards, $listing, $presale, $wrapper ); }
		if ( 'auto' === (string) ( $atts['layout'] ?? '' ) && ! absint( $atts['occurrence'] ?? 0 ) && count( $cards ) > self::auto_list_threshold() ) {
			if ( $requested ) { return Payments\ReturnNotice::take() . self::render_detail( $requested, $atts, true, $wrapper ); }
			return Payments\ReturnNotice::take() . self::render_list( $rows, $wrapper );
		}
		$shown = 0;
		$has_locked = ! empty( $meta['has_locked'] );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_script( 'wpcap-tracking' );
		wp_enqueue_style( 'wpcap-public' );
		ob_start();
		?>
		<div <?php echo '' !== $wrapper ? $wrapper : 'class="wpcap-events"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes. ?>>
			<?php if ( $has_locked ) : // Code-only ticket types exist (Sales\Visibility): reload with ?wpcap_code=. ?>
				<form class="wpcap-unlock" method="get"><label><?php esc_html_e( 'Freischaltcode', 'pass-captain' ); ?> <input type="text" name="wpcap_code" maxlength="32" autocomplete="off" value="<?php echo esc_attr( Sales\Visibility::request_code() ); ?>"></label> <button type="submit"><?php esc_html_e( 'Tickets freischalten', 'pass-captain' ); ?></button></form>
			<?php endif; ?>
			<?php foreach ( $cards as $event ) :
				$types = $listing[ (int) $event->occurrence_id ] ?? array();
				if ( ! $types ) {
					if ( isset( $presale[ (int) $event->occurrence_id ] ) ) {
						echo self::presale_card( $event, $presale[ (int) $event->occurrence_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in presale_card().
						++$shown;
					}
					continue;
				}
				++$shown;
				$providers = self::card_providers( $event, $types[0]->currency );
				$legal = self::legal_context();
				foreach ( $types as $type ) {
					// Properties of the older card contract (theme copies of event-card.php).
					$type->max = Frontend\EventCard::quantity_max( $type, $event );
					$type->upcoming = false;
					$type->sales_start_iso = '';
					$type->waitlist = $type->max < 1 && (bool) apply_filters( 'wpcap_waitlist_frontend', true, $event, $type );
				}
				?>
				<?php
				// Badges, seats left, price and the event's other dates (theme templates may ignore it).
				$card = Frontend\EventCard::context( $event, $types, $card_listing );
				if ( $grouped ) {
					// The other dates switch this card to them (A342); "url" is what the template links to.
					foreach ( $card['dates'] as $i => $date ) {
						if ( $date['linked'] ) { $card['dates'][ $i ]['url'] = self::occurrence_link( $date['occurrence_id'] ); }
					}
				}
				$template = self::theme_template( 'event-card.php' );
				if ( ! $template ) { $template = WPCAP_PATH . 'templates/event-card.php'; }
				ob_start();
				include $template;
				$card_html = ob_get_clean();
				$card_html = apply_filters( 'wpcap_event_card_html', $card_html, $event, $types );
				// Versioned contract of the audit line; the old unversioned name still fires via Hooks::RENAMED (deprecated since 1.5.0).
				$card_html = apply_filters( 'wp_pass_captain_event_card_html_v1', $card_html, $event, $types );
				$card_html = Hooks::legacy_filter( 'wpcap_event_card_html', $card_html, $event, $types );
				echo $grouped ? self::with_date_anchors( $card_html, $card['dates'] ) : $card_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes all output; trusted theme/plugin filter can customize markup.
				?>
			<?php endforeach; ?>
			<?php if ( ! $shown ) : ?><p class="wpcap-events-empty"><?php esc_html_e( 'Aktuell sind keine Veranstaltungen verfügbar.', 'pass-captain' ); ?></p><?php endif; ?>
		</div>
		<?php
		// A307: payment return notice in front of the event list when the theme has no wp_body_open.
		return Payments\ReturnNotice::take() . ob_get_clean();
	}

	/**
	 * Renders the event cards (one per date) for listing rows (event_id, name, description,
	 * occurrence_id, starts_at, timezone, location). Everything the cards need is loaded with a fixed
	 * number of grouped queries for all rows (listing_data()), not per occurrence or ticket type.
	 *
	 * @param object[] $rows Listing rows.
	 */
	public static function render_cards( array $rows ) {
		return self::render_rows( array_values( $rows ), array( 'dates' => 'each', 'layout' => 'cards' ), '' );
	}

	/** Current page URL without the plugin's view/return parameters (base for "Tickets" links). */
	public static function page_url() {
		$url = function_exists( 'get_permalink' ) && function_exists( 'is_singular' ) && is_singular() ? (string) get_permalink() : '';
		if ( '' === $url ) {
			$url = home_url( self::url_part( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by esc_url_raw.
		}
		// Keep the query of the request (e.g. ?page_id=4 without pretty permalinks), not the view/return parameters.
		$keep = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- link building only.
			$key = sanitize_key( (string) $key );
			if ( is_scalar( $value ) && '' !== $key && ! in_array( $key, array( self::OCC_PARAM, self::OCCURRENCE_PARAM, 'wpcap_payment', 'order', 'wpcap_rt', 'wpcap_month' ), true ) && false === strpos( $url, $key . '=' ) ) {
				$keep[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}
		return $keep ? add_query_arg( $keep, $url ) : $url;
	}

	/** One component of a URL ('' when missing). */
	private static function url_part( $url, $component ) {
		$part = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url, $component ) : parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback outside WordPress.
		return is_string( $part ) ? $part : '';
	}

	/** URL of the single-occurrence view on $base (default: this page). */
	public static function occurrence_url( $occurrence_id, $base = null ) {
		return add_query_arg( array( self::OCC_PARAM => absint( $occurrence_id ) ), null === $base ? self::page_url() : (string) $base );
	}

	/**
	 * Single occurrence with its checkout form; series show a date picker for the other dates.
	 *
	 * @param bool   $from_request Opened from a list (?wpcap_occ): adds the "all dates" link.
	 * @param string $wrapper      Block wrapper attributes ('' = plain classes).
	 */
	public static function render_detail( $occurrence_id, array $atts = array(), $from_request = true, $wrapper = '' ) {
		$rows = self::listing_rows( (int) ( $atts['event'] ?? 0 ), $occurrence_id, 1 );
		$back = '';
		if ( $from_request ) {
			$back = '<p class="wpcap-back"><a href="' . esc_url( self::page_url() ) . '">' . esc_html__( '← Alle Termine', 'pass-captain' ) . '</a></p>';
		}
		$root = '' !== $wrapper ? '<div ' . self::with_class( $wrapper, 'wpcap-events-detail' ) . '>' : '<div class="wpcap-events wpcap-events-detail">';
		if ( ! $rows ) {
			return $root . $back . '<p>' . esc_html__( 'Dieser Termin ist nicht (mehr) buchbar.', 'pass-captain' ) . '</p></div>';
		}
		$picker = $from_request ? self::series_picker( (int) $rows[0]->event_id, (int) $rows[0]->occurrence_id ) : '';
		return $root . $back . $picker . self::render_rows( $rows, array( 'dates' => 'each', 'occurrence' => (int) $rows[0]->occurrence_id ), '' ) . '</div>';
	}

	/** Block wrapper attributes with an extra class (first class="…" attribute). */
	private static function with_class( $wrapper, $class ) {
		$wrapper = (string) $wrapper;
		return false !== strpos( $wrapper, 'class="' ) ? (string) preg_replace( '/class="/', 'class="' . esc_attr( $class ) . ' ', $wrapper, 1 ) : $wrapper . ' class="' . esc_attr( $class ) . '"';
	}

	/** Date picker (GET form, works without JavaScript) for the other upcoming dates of a series. */
	private static function series_picker( $event_id, $current ) {
		$rows = self::listing_rows( $event_id, 0, 200 );
		if ( count( $rows ) < 2 ) { return ''; }
		list( $status ) = self::occurrence_states( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) );
		return self::date_form( $rows, $current, __( 'Anderen Termin wählen', 'pass-captain' ), $status );
	}

	/**
	 * GET form with a date select (name wpcap_occ) and a "Tickets" button; keeps the page's other query
	 * parameters as hidden fields.
	 *
	 * @param object[] $rows   Occurrence rows of one event.
	 * @param array    $status occurrence_id => status (soldout|upcoming|available).
	 */
	private static function date_form( array $rows, $selected, $label, array $status ) {
		static $n = 0;
		$id = 'wpcap-occ-picker-' . ( ++$n );
		$url = self::page_url();
		$action = (string) strtok( $url, '?' );
		$hidden = '';
		$query = self::url_part( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			foreach ( $params as $key => $value ) {
				if ( is_scalar( $value ) && self::OCC_PARAM !== $key ) { $hidden .= '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">'; }
			}
		}
		$options = '';
		foreach ( $rows as $row ) {
			$state = $status[ (int) $row->occurrence_id ] ?? '';
			if ( $status && '' === $state ) { continue; } // Nothing on sale or announced for this date.
			$text = Frontend\Format::event_start( $row->starts_at, $row->timezone );
			if ( 'soldout' === $state ) { $text .= ' – ' . __( 'ausverkauft', 'pass-captain' ); }
			if ( 'upcoming' === $state ) { $text .= ' – ' . __( 'Vorverkauf folgt', 'pass-captain' ); }
			$options .= '<option value="' . esc_attr( (string) (int) $row->occurrence_id ) . '"' . ( (int) $row->occurrence_id === (int) $selected ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
		}
		return '<form method="get" action="' . esc_url( $action ) . '" class="wpcap-occ-picker">' . $hidden . '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label> <span class="wpcap-occ-picker-row"><select id="' . esc_attr( $id ) . '" name="' . esc_attr( self::OCC_PARAM ) . '">' . $options . '</select> <button type="submit" class="wp-element-button">' . esc_html__( 'Tickets', 'pass-captain' ) . '</button></span></form>';
	}

	/**
	 * Sales state per occurrence for lists (available|soldout|upcoming); occurrences without an active,
	 * not yet ended ticket type are missing. One DisplayBatch for all rows.
	 *
	 * @param int[] $occ_ids Occurrence ids.
	 * @return array{0: array<int,string>, 1: array<int,string>} status and earliest upcoming sales start (UTC) per occurrence.
	 */
	public static function occurrence_states( array $occ_ids ) {
		global $wpdb;
		$occ_ids = array_values( array_unique( array_filter( array_map( 'absint', $occ_ids ) ) ) );
		if ( ! $occ_ids ) { return array( array(), array() ); }
		$now = Clock::mysql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,sales_start FROM %i WHERE occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ") AND status='active' AND (sales_end IS NULL OR sales_end >= %s)", array_merge( array( $wpdb->prefix . 'wpcap_ticket_types' ), $occ_ids, array( $now ) ) ) );
		$status = array();
		$starts = array();
		$open = array();
		foreach ( $types as $type ) {
			$occ = (int) $type->occurrence_id;
			if ( $type->sales_start && $type->sales_start > $now ) {
				$starts[ $occ ] = isset( $starts[ $occ ] ) ? min( $starts[ $occ ], $type->sales_start ) : $type->sales_start;
				$status[ $occ ] = $status[ $occ ] ?? 'upcoming';
			} else {
				$open[] = $type;
			}
		}
		if ( $open ) {
			DisplayBatch::run( array_map( static function ( $t ) { return (int) $t->ticket_type_id; }, $open ), static function () use ( $open, &$status ) {
				foreach ( $open as $type ) {
					$occ = (int) $type->occurrence_id;
					$remaining = Orders::availability( (int) $type->ticket_type_id );
					if ( null === $remaining || $remaining > 0 ) {
						$status[ $occ ] = 'available';
					} elseif ( 'available' !== ( $status[ $occ ] ?? '' ) && 'upcoming' !== ( $status[ $occ ] ?? '' ) ) {
						$status[ $occ ] = 'soldout';
					}
				}
			} );
		}
		return array( $status, $starts );
	}

	/**
	 * Compact list: one block per event with its upcoming dates as rows ("Tickets" link to the
	 * single-occurrence view); series with more than three dates get a date picker instead. No
	 * checkout forms (a page with 60 dates stays small).
	 *
	 * @param object[] $rows    Listing rows.
	 * @param string   $wrapper Block wrapper attributes ('' = plain classes).
	 */
	public static function render_list( array $rows, $wrapper = '' ) {
		$translations = self::translations( array_values( array_unique( array_map( static function ( $row ) { return (int) $row->event_id; }, $rows ) ) ), determine_locale() );
		list( $status, $starts ) = self::occurrence_states( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) );
		$events = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $status[ (int) $row->occurrence_id ] ) ) { continue; } // No ticket types on sale or announced.
			$events[ (int) $row->event_id ][] = $row;
		}
		$level = min( 6, max( 2, (int) apply_filters( 'wpcap_event_card_heading_level', 2 ) ) );
		$out = '' !== $wrapper ? '<div ' . self::with_class( $wrapper, 'wpcap-events-list' ) . '>' : '<div class="wpcap-events wpcap-events-list">';
		if ( ! $events ) {
			$out .= '<p class="wpcap-events-empty">' . esc_html__( 'Aktuell sind keine Veranstaltungen verfügbar.', 'pass-captain' ) . '</p>';
		}
		foreach ( $events as $event_id => $occurrences ) {
			$first = $occurrences[0];
			$translation = $translations[ $event_id ] ?? null;
			$name = $translation ? $translation->name : $first->name;
			$description = wp_strip_all_tags( (string) ( $translation ? $translation->description : $first->description ) );
			$location = $translation && $translation->location ? $translation->location : $first->location;
			$excerpt = function_exists( 'wp_trim_words' ) ? wp_trim_words( $description, 30 ) : ( mb_strlen( $description ) > 200 ? mb_substr( $description, 0, 200 ) . ' …' : $description );
			$heading_id = 'wpcap-event-' . (int) $event_id . '-title';
			$out .= '<article class="wpcap-event-summary" aria-labelledby="' . esc_attr( $heading_id ) . '"><h' . $level . ' id="' . esc_attr( $heading_id ) . '">' . esc_html( $name ) . '</h' . $level . '>';
			if ( '' !== trim( $excerpt ) ) { $out .= '<p class="wpcap-event-excerpt">' . esc_html( $excerpt ) . '</p>'; }
			if ( count( $occurrences ) > 3 ) {
				// The date picker offers every upcoming date of the series, also beyond the listing limit.
				$all = self::listing_rows( (int) $event_id, 0, 200 );
				list( $all_status ) = count( $all ) > count( $occurrences ) ? self::occurrence_states( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $all ) ) : array( $status );
				$dates = count( array_filter( $all, static function ( $row ) use ( $all_status ) { return isset( $all_status[ (int) $row->occurrence_id ] ); } ) );
				/* translators: 1: number of dates, 2: first date */
				$out .= '<p class="wpcap-occ-meta">' . esc_html( sprintf( __( '%1$d Termine ab %2$s', 'pass-captain' ), $dates, Frontend\Format::event_start( $first->starts_at, $first->timezone ) ) . ( $location ? ' · ' . $location : '' ) ) . '</p>';
				$out .= self::date_form( $all, (int) $first->occurrence_id, __( 'Termin wählen', 'pass-captain' ), $all_status );
			} else {
				$out .= '<ul class="wpcap-occ-rows">';
				foreach ( $occurrences as $row ) {
					$occ = (int) $row->occurrence_id;
					$when = Frontend\Format::event_start( $row->starts_at, $row->timezone );
					$where = $translation && $translation->location ? $translation->location : $row->location;
					$state = $status[ $occ ];
					$badge = '';
					if ( 'soldout' === $state ) { $badge = '<span class="wpcap-badge wpcap-badge-soldout">' . esc_html__( 'Ausverkauft', 'pass-captain' ) . '</span>'; }
					/* translators: %s: date and time */
					if ( 'upcoming' === $state && isset( $starts[ $occ ] ) ) { $badge = '<span class="wpcap-badge">' . esc_html( sprintf( __( 'Vorverkauf ab %s', 'pass-captain' ), Frontend\Format::event_start( $starts[ $occ ], $row->timezone ) ) ) . '</span>'; }
					/* translators: 1: event name, 2: date and time */
					$label = sprintf( __( 'Tickets für %1$s am %2$s', 'pass-captain' ), $name, $when );
					$out .= '<li class="wpcap-occ-row" id="wpcap-occ-' . $occ . '"><span class="wpcap-occ-when">' . esc_html( $when ) . '</span>' . ( $where ? '<span class="wpcap-occ-where">' . esc_html( $where ) . '</span>' : '' ) . $badge . '<a class="wp-element-button wpcap-tickets-link" href="' . esc_url( self::occurrence_url( $occ ) ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Tickets', 'pass-captain' ) . '</a></li>';
				}
				$out .= '</ul>';
			}
			$out .= '</article>';
		}
		return $out . '</div>';
	}

	/**
	 * Published page that shows the events shortcode or block (link target of the calendar when no
	 * checkout page is configured). Cached for a day; forgotten when a post is saved or deleted.
	 */
	public static function shortcode_page_url() {
		$cached = get_transient( 'wpcap_events_page' );
		if ( is_array( $cached ) && isset( $cached['url'] ) ) { return (string) $cached['url']; }
		$url = '';
		$candidates = array();
		if ( function_exists( 'get_posts' ) ) {
			foreach ( array( 'wp_pass_captain_events', 'wpcap_events', LegacyNames::EVENTS_BLOCK ) as $needle ) {
				foreach ( (array) get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 's' => $needle, 'numberposts' => 20, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $post ) {
					$content = (string) $post->post_content;
					$shortcode = LegacyNames::content_has_shortcode( $content, 'wpcap_events' );
					$block = LegacyNames::content_has_block( $content, LegacyNames::EVENTS_BLOCK );
					// A page that shows one fixed date (occurrence attribute) is no list page.
					if ( ( $shortcode || $block ) && ! preg_match( '/\[(?:wp_pass_captain|wpcap)_events[^\]]*occurrence=|"occurrence(?:Id)?":\s*[1-9]/', $content ) ) {
						$candidates[ (int) $post->ID ] = ( 'page' === $post->post_type ? 0 : 1000000000000 ) + (int) $post->ID; // Pages first, then the oldest.
					}
				}
			}
		}
		if ( $candidates ) {
			asort( $candidates );
			$url = (string) get_permalink( (int) array_key_first( $candidates ) );
		}
		set_transient( 'wpcap_events_page', array( 'url' => $url ), DAY_IN_SECONDS );
		return $url;
	}

	/** save_post / deleted_post: forget the cached events page. */
	public static function flush_page_cache() {
		delete_transient( 'wpcap_events_page' );
	}

	/**
	 * Event translations for a locale in one query.
	 *
	 * @param int[]  $event_ids Event ids.
	 * @param string $locale    Locale, e.g. de_DE.
	 * @return object[] event_id => {name, description, location}
	 */
	public static function translations( array $event_ids, $locale ) {
		global $wpdb;
		$out = array();
		if ( ! $event_ids ) { return $out; }
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,name,description,location FROM %i WHERE event_id IN (' . implode( ',', array_fill( 0, count( $event_ids ), '%d' ) ) . ') AND locale=%s', array_merge( array( $wpdb->prefix . 'wpcap_event_translations' ), array_map( 'absint', $event_ids ), array( (string) $locale ) ) ) ) as $row ) {
			$out[ (int) $row->event_id ] = $row;
		}
		return $out;
	}

	/**
	 * Payment methods the card of a date offers (id => gateway): enabled for the currency; bank
	 * transfer is left out when the start is closer than manual_cutoff_hours and another method exists.
	 *
	 * @param object $event Listing row (starts_at).
	 */
	public static function card_providers( $event, $currency ) {
		$providers = Payments\Registry::enabled( (string) $currency );
		if ( isset( $providers['manual'] ) && count( $providers ) > 1 && null === Orders::manual_hold_until( strtotime( $event->starts_at . ' UTC' ) ) ) { unset( $providers['manual'] ); }
		return $providers;
	}

	/** Most dates one live-state request may ask for (A456). */
	const LIVE_MAX = 50;

	/**
	 * A456/A458: live purchase state of listed dates for cards from a page cache or CDN – what the card
	 * would show if it were rendered now: per ticket type the quantity limit, seats left and the current
	 * (time-dependent) price; seats-left text, price line and badges of the header; the payment methods;
	 * whether the waitlist is offered and for which types; the current legal text version (A457).
	 * Dates that are not on sale (any more) come back with on_sale false. Read-only, no personal data.
	 *
	 * @param int[]       $occurrence_ids Dates of the cards on the page.
	 * @param string      $locale         Locale of the form (legal texts, translations).
	 * @param string|null $code           Unlock code of the page (?wpcap_code=, Sales\Visibility); code-only
	 *                                    ticket types are only included when it unlocks them.
	 * @return array{occurrences: array, legal: array, server_time: string}
	 */
	public static function live_state( array $occurrence_ids, $locale = '', $code = '' ) {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $occurrence_ids ) ) ) ), 0, self::LIVE_MAX );
		$out = array();
		foreach ( $ids as $id ) { $out[ $id ] = array( 'on_sale' => false ); }
		$rows = array();
		if ( $ids ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location" . ( DoorsOpen::ready() ? ',o.doors_open_at' : '' ) . " FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.occurrence_id IN (" . implode( ',', $ids ) . ') AND ' . Occurrence::listable_sql( 'o', Clock::mysql() ) . ' ORDER BY o.starts_at ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list, literal column, Occurrence::listable_sql() is a prepared fragment.
				self::LIVE_MAX
			) );
		}
		$meta = array();
		$listing = self::listing_data( $rows, $meta, (string) $code );
		$card_listing = self::card_listing( $rows, $listing, $meta );
		foreach ( $rows as $event ) {
			$occ_id = (int) $event->occurrence_id;
			$types = $listing[ $occ_id ] ?? array();
			if ( ! $types ) { continue; }
			$card = Frontend\EventCard::context( $event, $types, $card_listing );
			$state_types = array();
			$soldout = array();
			foreach ( $types as $type ) {
				$max = Frontend\EventCard::quantity_max( $type, $event );
				if ( null !== $type->available && (int) $type->available < 1 ) { $soldout[] = (int) $type->ticket_type_id; }
				$base = isset( $type->base_price_minor ) && (int) $type->base_price_minor !== (int) $type->price_minor ? Frontend\Format::money( $type->base_price_minor, $type->currency ) : '';
				$state_types[ (int) $type->ticket_type_id ] = array(
					'max' => $max,
					'available' => null === $type->available ? null : max( 0, (int) $type->available ),
					'price_minor' => (int) $type->price_minor,
					'price' => Frontend\Format::price( $type->price_minor, $type->currency ),
					'base_price' => $base,
					'price_label' => (string) ( $type->price_label ?? '' ),
					'currency' => (string) $type->currency,
				);
			}
			$providers = array();
			foreach ( self::card_providers( $event, $types[0]->currency ) as $provider_id => $gateway ) { $providers[] = array( 'id' => (string) $provider_id, 'title' => (string) $gateway->title() ); }
			// "Nächster Termin" depends on the whole listing: the card keeps the badge it was rendered with.
			$badges = array_values( array_filter( (array) $card['badges'], static function ( $badge ) { return 'next' !== ( $badge['key'] ?? '' ); } ) );
			$waitlist = $soldout && (bool) apply_filters( 'wpcap_waitlist_public_enabled', true, $event ) && (bool) apply_filters( 'wpcap_waitlist_frontend', true, $event, null );
			$out[ $occ_id ] = array(
				'on_sale' => true,
				'types' => $state_types,
				'availability' => (string) $card['availability'],
				'price' => (string) $card['price'],
				'badges' => array_map( static function ( $badge ) { return array( 'key' => (string) ( $badge['key'] ?? '' ), 'label' => (string) $badge['label'] ); }, $badges ),
				'all_soldout' => count( $soldout ) === count( $types ),
				'providers' => $providers,
				'waitlist' => $waitlist,
				'waitlist_types' => $waitlist ? $soldout : array(),
				'max_total' => (int) $card['max_total'],
			);
		}
		$legal = self::legal_context( '' === (string) $locale ? null : (string) $locale );
		return array(
			'occurrences' => (object) $out,
			'legal' => array( 'require_terms' => (bool) $legal['require_terms'], 'terms_version' => (string) $legal['terms_version'], 'locale' => (string) $legal['locale'] ),
			'server_time' => gmdate( 'c' ),
		);
	}

	/**
	 * GET /checkout/live?occurrences=1,2&locale=de_DE[&code=…] (A456): live_state() with Cache-Control no-store,
	 * so neither a CDN nor the browser keeps it. Rate-limited per client (filter
	 * `wpcap_checkout_live_limit`, default 120 per minute).
	 */
	public static function rest_live( \WP_REST_Request $request ) {
		$limit = max( 1, (int) apply_filters( 'wpcap_checkout_live_limit', 120 ) );
		if ( ! RateLimit::allow_client( 'checkout_live', $limit, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wpcap_rate_limited', __( 'Bitte warte kurz und versuche es erneut.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$raw = $request->get_param( 'occurrences' );
		$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$code = mb_substr( Campaigns::normalize_code( sanitize_text_field( (string) $request->get_param( 'code' ) ) ), 0, 32 );
		$response = rest_ensure_response( self::live_state( $ids, Mail\Mailer::normalize_locale( (string) $request->get_param( 'locale' ) ), $code ) );
		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'CDN-Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * One listing row per event (A342), in the order of the events' first dates: the requested
	 * occurrence when it belongs to the event and can be shown, else the first date with ticket
	 * types on sale, else the first presale date. Events with neither get no card (as before).
	 *
	 * @param object[] $rows     Listing rows sorted by start.
	 * @param array    $listing  occurrence_id => ticket types on sale.
	 * @param array    $presale  occurrence_id => sales start.
	 * @param int      $requested Requested occurrence (0 = none).
	 * @return object[]
	 */
	public static function card_rows( array $rows, array $listing, array $presale, $requested = 0 ) {
		$pick = array();
		$order = array();
		foreach ( $rows as $row ) {
			$event_id = (int) $row->event_id;
			$occ_id = (int) $row->occurrence_id;
			if ( ! isset( $order[ $event_id ] ) ) { $order[ $event_id ] = count( $order ); }
			$rank = ! empty( $listing[ $occ_id ] ) ? 1 : ( isset( $presale[ $occ_id ] ) ? 2 : 0 );
			if ( ! $rank ) { continue; }
			if ( $occ_id === (int) $requested ) { $rank = 0; }
			if ( ! isset( $pick[ $event_id ] ) || $rank < $pick[ $event_id ][0] ) { $pick[ $event_id ] = array( $rank, $row ); }
		}
		$out = array();
		foreach ( $order as $event_id => $position ) {
			if ( isset( $pick[ $event_id ] ) ) { $out[ $position ] = $pick[ $event_id ][1]; }
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Adds an empty anchor (#wpcap-occ-{id}) per other listed date of the event to the first
	 * rendered card (A342), so calendar links and .ics URLs of those dates still land on the card
	 * that offers them. Repeated cards of the same date (A308 "-i2" forms) get none.
	 */
	private static function with_date_anchors( $html, array $dates ) {
		$html = (string) $html;
		if ( ! $dates || preg_match( '/-i\d+$/', Frontend\EventCard::form_id_in( $html ) ) ) { return $html; }
		$anchors = '';
		foreach ( $dates as $date ) {
			if ( empty( $date['linked'] ) ) { continue; }
			$id = 'wpcap-occ-' . (int) $date['occurrence_id'];
			// A504: an anchor that another card of the page already has is left out.
			if ( false === strpos( $html, 'id="' . $id . '"' ) && Frontend\EventCard::claim_anchor( $id ) ) { $anchors .= '<span id="' . esc_attr( $id ) . '" class="wpcap-occ-anchor"></span>'; }
		}
		if ( '' === $anchors ) { return $html; }
		return (string) preg_replace( '/(<article\b[^>]*>)/', '$1' . str_replace( '$', '\\$', $anchors ), $html, 1 );
	}

	/**
	 * Theme override of a front-end template: `<theme>/pass-captain/<name>` (child theme first), else
	 * the folder used before the rename (see LegacySlug::legacy_theme_template()); '' when the theme
	 * has none.
	 */
	public static function theme_template( $name ) {
		$name     = basename( (string) $name );
		$template = locate_template( 'pass-captain/' . $name );
		return $template ? $template : LegacySlug::legacy_theme_template( $name );
	}

	/**
	 * A407: theme override of a whole front-end page or the mail layout. When the theme has
	 * `pass-captain/<name>` (see theme_template()), it is rendered with $vars extracted plus `$default_html`
	 * (the plugin's own rendering, e.g. to wrap it) and replaces the default; otherwise the default is returned.
	 * The template escapes its own output. Filter `wpcap_template_page` gets the result.
	 *
	 * Pages: ticket-page.php, order-portal.php, ticket-recovery.php, email-layout.php; A690: calendar-list.php,
	 * calendar-month.php, passes.php, service-booking.php.
	 *
	 * @param string $name         Template file name.
	 * @param string $default_html Plugin rendering.
	 * @param array  $vars         Variables for the template.
	 */
	public static function page( $name, $default_html, array $vars = array() ) {
		$file = function_exists( 'locate_template' ) ? self::theme_template( $name ) : '';
		$html = (string) $default_html;
		if ( '' !== $file && is_readable( $file ) ) {
			$render = static function ( $wpcap_template_file, array $wpcap_vars ) {
				extract( $wpcap_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables, documented in docs/HOOKS.md.
				ob_start();
				include $wpcap_template_file;
				return (string) ob_get_clean();
			};
			$html = $render( $file, $vars + array( 'default_html' => (string) $default_html ) );
		}
		/**
		 * Rendered page/layout (A407).
		 *
		 * @param string $html  HTML.
		 * @param string $name  Template name.
		 * @param array  $vars  Template variables.
		 */
		return (string) apply_filters( 'wpcap_template_page', $html, (string) $name, $vars );
	}

	/**
	 * Earliest future sales start per listed occurrence that has no ticket type on sale yet (A293):
	 * occurrence_id => sales_start (UTC). Dates whose sales ended or that have no active ticket type
	 * stay out of the listing.
	 *
	 * @param object[] $rows    Listing rows.
	 * @param array    $listing occurrence_id => ticket types on sale (listing_data()).
	 * @return array
	 */
	public static function presale_starts( array $rows, array $listing ) {
		global $wpdb;
		$ids = array();
		foreach ( $rows as $row ) {
			if ( empty( $listing[ (int) $row->occurrence_id ] ) ) { $ids[] = (int) $row->occurrence_id; }
		}
		if ( ! $ids ) { return array(); }
		$now = Clock::mysql();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT occurrence_id, MIN(sales_start) AS sales_start FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id IN (" . implode( ',', array_fill( 0, count( array_unique( $ids ) ), '%d' ) ) . ") AND status='active' AND sales_start > %s AND (sales_end IS NULL OR sales_end > sales_start) GROUP BY occurrence_id", array_merge( array_values( array_unique( $ids ) ), array( $now ) ) ) ) as $row ) {
			$out[ (int) $row->occurrence_id ] = (string) $row->sales_start;
		}
		return $out;
	}

	/**
	 * Card of a date that is not on sale yet: name, date, place and "Vorverkauf ab …" (A293).
	 * Filter `wpcap_presale_card_html` replaces the markup.
	 *
	 * @param object $event       Listing row (translated).
	 * @param string $sales_start Earliest sales start (UTC).
	 */
	public static function presale_card( $event, $sales_start ) {
		$level = min( 6, max( 2, (int) apply_filters( 'wpcap_event_card_heading_level', 2 ) ) );
		/* translators: %s: date and time the online sale starts */
		$label = sprintf( __( 'Vorverkauf ab %s', 'pass-captain' ), Frontend\Format::event_start( $sales_start, $event->timezone ) );
		// A504: the anchor only on the first card of the date on the page.
		$anchor = Frontend\EventCard::claim_anchor( 'wpcap-occ-' . (int) $event->occurrence_id ) ? ' id="wpcap-occ-' . (int) $event->occurrence_id . '"' : '';
		$html = '<article class="wpcap-event wpcap-event-presale"' . $anchor . ' data-occurrence="' . (int) $event->occurrence_id . '"><header class="wpcap-event-header">'
			. '<h' . $level . ' class="wpcap-event-title">' . esc_html( $event->name ) . '</h' . $level . '>'
			. '<dl class="wpcap-event-facts"><div class="wpcap-fact-date"><dt class="wpcap-sr-only">' . esc_html__( 'Termin', 'pass-captain' ) . '</dt><dd><time datetime="' . esc_attr( Frontend\EventCard::iso( $event->starts_at ) ) . '">' . esc_html( Frontend\Format::event_start( $event->starts_at, $event->timezone ) ) . '</time></dd></div>'
			. ( $event->location ? '<div class="wpcap-fact-location"><dt class="wpcap-sr-only">' . esc_html__( 'Ort', 'pass-captain' ) . '</dt><dd>' . esc_html( $event->location ) . '</dd></div>' : '' )
			. '</dl></header>'
			. ( $event->description ? '<div class="wpcap-description">' . wp_kses_post( wpautop( $event->description ) ) . '</div>' : '' )
			. '<p class="wpcap-presale"><time datetime="' . esc_attr( Frontend\EventCard::iso( $sales_start ) ) . '">' . esc_html( $label ) . '</time></p></article>';
		return (string) apply_filters( 'wpcap_presale_card_html', $html, $event, $sales_start );
	}

	/**
	 * Legal texts of the checkout form in the page language (fallback: default language) plus the
	 * locale and version the form sends back, so the order stores the version the buyer was shown.
	 *
	 * @return array require_terms, terms_text, consumer_info_text, locale, terms_version.
	 */
	public static function legal_context( $locale = null ) {
		$locale = Mail\Mailer::normalize_locale( null === $locale ? Mail\Mailer::current_locale() : (string) $locale );
		return array(
			'require_terms' => (bool) Settings::get( 'require_terms' ),
			'terms_text' => Settings::legal_text( 'terms_text', $locale ),
			'consumer_info_text' => Settings::legal_text( 'consumer_info_text', $locale ),
			'locale' => $locale,
			'terms_version' => Settings::terms_version( $locale ),
		);
	}

	/**
	 * Loads everything the event cards need for the listed occurrences with a constant number of
	 * queries (translations, ticket types, occurrences, participant fields, availability), applies
	 * translations to $rows and returns occurrence_id => ticket types prepared for the card
	 * (price_minor = effective price, base_price_minor, price_label, available, fields).
	 *
	 * Hidden ticket types are never listed, code-only types only with a matching unlock code
	 * (Sales\Visibility); $meta['has_locked'] says whether locked code-only types exist.
	 *
	 * @param object[]    $rows Listing rows (event_id, name, description, occurrence_id, location, …); changed in place.
	 * @param array       $meta Set to occurrences (id => row with rescheduled_at, including upcoming cancelled
	 *                          dates of the listed events) – loaded by the same query, no extra round trip.
	 * @param string|null $code Unlock code (null = ?wpcap_code= of the request).
	 * @return array occurrence_id => object[] ticket types.
	 */
	public static function listing_data( array $rows, &$meta = null, $code = null ) {
		$meta = array( 'occurrences' => array(), 'has_locked' => false );
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $rows ) { return array(); }
		$now = Clock::mysql();
		$event_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->event_id; }, $rows ) ) );
		$occ_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) ) );
		$translations = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_id,name,description,location FROM {$wpdb->prefix}wpcap_event_translations WHERE event_id IN (" . implode( ',', array_fill( 0, count( $event_ids ), '%d' ) ) . ') AND locale=%s', array_merge( $event_ids, array( determine_locale() ) ) ) ) as $translation ) {
			$translations[ (int) $translation->event_id ] = $translation;
		}
		foreach ( $rows as $event ) {
			$translation = $translations[ (int) $event->event_id ] ?? null;
			if ( $translation ) {
				$event->name = $translation->name;
				$event->description = $translation->description;
				$event->location = $translation->location ?: $event->location;
			}
		}
		$occ_in = implode( ',', $occ_ids );
		$by_occurrence = array();
		$all_types = array();
		// Once an occurrence has started only ticket types with a sales_end ahead stay on sale (A535).
		// tt.*: visibility/access_codes, description, attendee name settings and position exist depending on the schema.
		$order = Orders::schema_at_least( '3.17.5' ) ? 'tt.position,tt.ticket_type_id' : 'tt.ticket_type_id';
		$type_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.* FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.occurrence_id IN ($occ_in) AND tt.status='active' AND (tt.sales_start IS NULL OR tt.sales_start <= %s) AND (tt.sales_end IS NULL OR tt.sales_end >= %s) AND " . Occurrence::type_sale_sql( 'tt', 'o', $now ) . " ORDER BY {$order}", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list, static order and prepared fragment.
		// Hidden types never, code-only types only with a matching ?wpcap_code= (Sales\Visibility).
		$code = null === $code ? Sales\Visibility::request_code() : (string) $code;
		$visible = Sales\Visibility::filter_listing( $type_rows, $code );
		$is_code = static function ( $row ) { return 'code' === Sales\Visibility::of( $row ); };
		$meta['has_locked'] = count( array_filter( $type_rows, $is_code ) ) > count( array_filter( $visible, $is_code ) );
		$keep = array_flip( array( 'ticket_type_id', 'occurrence_id', 'name', 'price_minor', 'currency', 'capacity', 'max_per_order', 'tax_rate_bp', 'id_check', 'min_age', 'attendee_name_required', 'attendee_name', 'visibility', 'description', 'sales_start', 'sales_end', 'position' ) );
		foreach ( $visible as $type ) {
			// Only what the card needs (no access codes in the page).
			$type = (object) array_intersect_key( (array) $type, $keep );
			$type->description = (string) ( $type->description ?? '' );
			$type->attendee_name = Orders::attendee_name_mode( $type->attendee_name ?? '' );
			$by_occurrence[ (int) $type->occurrence_id ][] = $type;
			$all_types[ (int) $type->ticket_type_id ] = $type;
		}
		if ( ! $all_types ) { return array(); }
		$occurrences = array();
		// Same query also brings the last reschedule (badge "Verschoben") and upcoming cancelled dates
		// of the listed events (badge "Abgesagt" in the card's date list).
		$event_in = implode( ',', $event_ids );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.*," . Frontend\EventCard::rescheduled_sql( 'o' ) . " AS rescheduled_at FROM {$wpdb->prefix}wpcap_occurrences o WHERE o.occurrence_id IN ($occ_in) OR (o.event_id IN ($event_in) AND o.status='cancelled' AND o.starts_at >= %s)", $now ) ) as $occurrence ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- (int) cast integer lists; EventCard::rescheduled_sql() is a static expression.
			$occurrences[ (int) $occurrence->occurrence_id ] = $occurrence;
		}
		$meta['occurrences'] = $occurrences;
		$type_in = implode( ',', array_keys( $all_types ) );
		$fields = array();
		// Only field definitions are exposed (no answers); used to render attendee inputs.
		foreach ( (array) $wpdb->get_results( 'SELECT ' . ParticipantFields::columns() . " FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id IN ($type_in) AND status='active' ORDER BY position,field_id", ARRAY_A ) as $field ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer list, ParticipantFields::columns() is a column list from literals.
			// A680: type, help text, scope and choices; never answers.
			$fields[ (int) $field['ticket_type_id'] ][] = ParticipantFields::public_definition( $field );
		}
		// One display batch with the rows loaded above (no extra query): availability, price rules and
		// series prices of all listed types answer from grouped queries, not per occurrence.
		$full_types = array();
		foreach ( $type_rows as $row ) { $full_types[ (int) $row->ticket_type_id ] = $row; }
		DisplayBatch::run( array_keys( $all_types ), static function () use ( $all_types, $by_occurrence, $occurrences, $fields, $now ) {
		$available = Orders::availability_many( array_keys( $all_types ), array( 'occurrences' => $occurrences ) );
		foreach ( $by_occurrence as $occ_id => $types ) {
			$occurrence = $occurrences[ $occ_id ] ?? null;
			foreach ( $types as $type ) {
				// Effective server-side price (e.g. series price tiers); the checkout charges the same.
				$type->base_price_minor = (int) $type->price_minor;
				$type->price_label = Orders::price_label( $type, $occurrence, array( 'now' => $now ) );
				// Buyer-visible reason for an automatic price (Sales\PriceRules), e.g. "Frühbucherpreis: gilt bei Buchung bis …".
				$type->price_explanation = Sales\PriceRules::explanation_for( $type, $occurrence, array( 'now' => $now ) );
				$type->price_minor = Orders::unit_price( $type, $occurrence, 1, array( 'now' => $now ) );
				$type->available = array_key_exists( (int) $type->ticket_type_id, $available ) ? $available[ (int) $type->ticket_type_id ] : 0;
				$type->fields = $fields[ (int) $type->ticket_type_id ] ?? array();
				$type->name_required = ParticipantFields::name_required( (int) $type->ticket_type_id, $type ) || 'required' === $type->attendee_name; // A552 (no extra query) or the ticket type's attendee name setting.
			}
		}
		}, array( 'types' => $full_types, 'occurrences' => $occurrences ) );
		return $by_occurrence;
	}

	/**
	 * Listing-wide card data for Frontend\EventCard::context(): seats left per listed date, the next
	 * bookable date of each event and the dates of each event (listed ones linkable, cancelled ones
	 * flagged), without further queries.
	 *
	 * @param object[] $rows    Listing rows.
	 * @param array    $listing occurrence_id => ticket types (listing_data()).
	 * @param array    $meta    listing_data() meta.
	 */
	public static function card_listing( array $rows, array $listing, array $meta ) {
		$occurrences = (array) ( $meta['occurrences'] ?? array() );
		$available = array();
		$dates = array();
		$listed = array();
		foreach ( $rows as $row ) {
			$occ_id = (int) $row->occurrence_id;
			if ( empty( $listing[ $occ_id ] ) ) { continue; }
			$available[ $occ_id ] = Frontend\EventCard::occurrence_available( $listing[ $occ_id ], $occurrences[ $occ_id ] ?? null );
			$date = (object) array(
				'occurrence_id' => $occ_id,
				'event_id' => (int) $row->event_id,
				'starts_at' => $row->starts_at,
				'timezone' => $row->timezone,
				'doors_open_at' => $row->doors_open_at ?? null,
				'rescheduled_at' => $occurrences[ $occ_id ]->rescheduled_at ?? null,
				'status' => 'scheduled',
				'listed' => true,
			);
			$listed[] = $date;
			$dates[ (int) $row->event_id ][] = $date;
		}
		// A date list only where the listing shows several dates of the event (not for occurrence="…").
		$dates = array_filter( $dates, static function ( $list ) { return count( $list ) > 1; } );
		foreach ( $occurrences as $occ ) {
			if ( 'cancelled' !== $occ->status || ! isset( $dates[ (int) $occ->event_id ] ) ) { continue; }
			$occ->listed = false;
			$dates[ (int) $occ->event_id ][] = $occ;
		}
		foreach ( $dates as &$list ) {
			usort( $list, static function ( $a, $b ) { return strcmp( (string) $a->starts_at, (string) $b->starts_at ) ?: (int) $a->occurrence_id <=> (int) $b->occurrence_id; } );
		}
		unset( $list );
		return array(
			'occurrences' => $occurrences,
			'available' => $available,
			'next' => Frontend\EventCard::next_occurrences( $listed, $available ),
			'dates' => $dates,
		);
	}

	/** Most events listed in the events block selection. */
	const BLOCK_CHOICE_EVENTS = 1000;
	/** Most dates listed per event in the events block selection. */
	const BLOCK_CHOICE_DATES = 100;

	/**
	 * Choices for the events block: published events with upcoming dates and, per event, its next
	 * dates. Labels carry the next date (and the date for each occurrence) so events with the same
	 * name can be told apart.
	 *
	 * A453: aggregated per event – every event with an upcoming date is listed (up to
	 * BLOCK_CHOICE_EVENTS), however many dates other events have; per event its next
	 * BLOCK_CHOICE_DATES dates. `dates` is the number of upcoming dates, `more` says the date list
	 * is cut. The editor shows a saved selection that is not in the list as
	 * "Event #X (nicht mehr verfügbar)" instead of silently as "Alle Events".
	 *
	 * @return array{events: array[]}
	 */
	public static function block_choices() {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		$events = array();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,MIN(o.starts_at) AS next_start,COUNT(*) AS dates FROM {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s GROUP BY e.event_id,e.name ORDER BY next_start ASC,e.event_id ASC LIMIT %d",
			$now,
			self::BLOCK_CHOICE_EVENTS
		) );
		foreach ( (array) $rows as $row ) {
			$events[ (int) $row->event_id ] = array( 'name' => wp_specialchars_decode( (string) $row->name, ENT_QUOTES ), 'next' => (string) $row->next_start, 'dates' => (int) $row->dates, 'occurrences' => array() );
		}
		// Dates: events with at most BLOCK_CHOICE_DATES upcoming dates in batches, larger ones each
		// with its own limit, so no event pushes the dates of others out of a shared row limit.
		$small = array();
		$queries = array();
		foreach ( $events as $id => $event ) {
			if ( $event['dates'] > self::BLOCK_CHOICE_DATES ) {
				$queries[] = $wpdb->prepare( "SELECT o.event_id,o.occurrence_id,o.starts_at,o.timezone FROM {$p}wpcap_occurrences o WHERE o.event_id=%d AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC,o.occurrence_id ASC LIMIT %d", $id, $now, self::BLOCK_CHOICE_DATES );
			} else {
				$small[] = (int) $id;
			}
		}
		foreach ( array_chunk( $small, 50 ) as $chunk ) {
			$queries[] = $wpdb->prepare( "SELECT o.event_id,o.occurrence_id,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_occurrences o WHERE o.event_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ") AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC,o.occurrence_id ASC", array_merge( $chunk, array( $now ) ) );
		}
		foreach ( $queries as $sql ) {
			foreach ( (array) $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$id = (int) $row->event_id;
				if ( ! isset( $events[ $id ] ) || count( $events[ $id ]['occurrences'] ) >= self::BLOCK_CHOICE_DATES ) { continue; }
				$events[ $id ]['occurrences'][] = array( 'id' => (int) $row->occurrence_id, 'label' => Orders::format_start( $row->starts_at, $row->timezone ) );
			}
		}
		$out = array();
		foreach ( $events as $id => $event ) {
			$when = $event['occurrences'] ? $event['occurrences'][0]['label'] : Orders::format_start( $event['next'], 'UTC' );
			$out[] = array(
				'id' => $id,
				'label' => $event['name'] . ' (' . $when . ', #' . $id . ')',
				'dates' => $event['dates'],
				'more' => $event['dates'] > count( $event['occurrences'] ),
				'occurrences' => $event['occurrences'],
			);
		}
		return array( 'events' => $out );
	}

	/**
	 * Occurrences the current user may admit to: running ones (multi-day occurrences until their
	 * end, A535), those that ended less than a day ago and those starting within a week (web scanner
	 * date selection: a ticket for another date is refused with its own date instead of a time error).
	 *
	 * @return array[] {id, label}
	 */
	public static function scanner_occurrences() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,e.name FROM {$wpdb->prefix}wpcap_occurrences o INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE o.status<>'cancelled' AND " . Occurrence::end_sql( 'o' ) . ' >= %s AND o.starts_at <= %s ORDER BY o.starts_at,o.occurrence_id LIMIT 100', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Occurrence::end_sql() returns a static SQL expression (sanitized alias, integer duration).
			Clock::mysql( -DAY_IN_SECONDS ),
			Clock::mysql( 7 * DAY_IN_SECONDS )
		) );
		$allowed = array();
		$items = array();
		foreach ( (array) $rows as $row ) {
			$event_id = (int) $row->event_id;
			if ( ! isset( $allowed[ $event_id ] ) ) { $allowed[ $event_id ] = Access::can_access_event( $event_id, 'wpcap_checkin' ); }
			if ( ! $allowed[ $event_id ] ) { continue; }
			$items[] = array( 'id' => (int) $row->occurrence_id, 'label' => wp_specialchars_decode( (string) $row->name, ENT_QUOTES ) . ' – ' . Orders::format_start( $row->starts_at, $row->timezone ) );
		}
		return $items;
	}

	/** Label of the scanner search: names only the fields the person may search (Pii, A544). */
	public static function scanner_search_label() {
		$name = Pii::can_view( 'attendee_name' );
		$email = Pii::can_view( 'attendee_email' ) || Pii::can_view( 'buyer_email' );
		if ( $name && $email ) { return __( 'Ticket suchen (Name, E-Mail oder Bestellnummer)', 'pass-captain' ); }
		if ( $name ) { return __( 'Ticket suchen (Name oder Bestellnummer)', 'pass-captain' ); }
		if ( $email ) { return __( 'Ticket suchen (E-Mail oder Bestellnummer)', 'pass-captain' ); }
		return __( 'Ticket suchen (Bestellnummer)', 'pass-captain' );
	}

	/**
	 * template_redirect: a page with the scanner shortcode is a staff tool – not cached (it carries a
	 * REST nonce), not framed, not indexed. Headers must go out before the theme prints anything, so
	 * this runs before the shortcode renders.
	 *
	 * @return bool Whether the private-page headers were sent.
	 */
	public static function protect_scanner_page() {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) { return false; }
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'wp_pass_captain_scanner' ) ) { return false; }
		Security\PrivatePage::send();
		return true;
	}

	/** template_redirect action (no return value), see protect_scanner_page(). */
	public static function maybe_protect_scanner_page() {
		self::protect_scanner_page();
	}

	public static function render_scanner_shortcode( $atts = array(), $content = '', $tag = '' ) {
		unset( $atts, $content );
		LegacyNames::shortcode_used( $tag );
		// A508: never cache the scanner page (login state, nonce). Headers were sent on
		// template_redirect (PageCache::maybe_no_store_request()); DONOTCACHEPAGE covers late rendering.
		PageCache::no_store( 'scanner' );
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) {
			return '<p>' . esc_html__( 'Für den Scanner ist eine Anmeldung mit Einlassberechtigung erforderlich.', 'pass-captain' ) . '</p>';
		}
		wp_enqueue_script( 'wpcap-scanner' );
		wp_enqueue_style( 'wpcap-public' );
		self::$scanner_rendered = true;
		$occurrences = 'window.WPCAP_SCANNER_OCCURRENCES=' . wp_json_encode( array(
			'label' => __( 'Termin', 'pass-captain' ),
			'all' => __( 'Alle Termine (keine Terminprüfung)', 'pass-captain' ),
			'items' => self::scanner_occurrences(),
		) ) . ';';
		// Block themes render the content before wp_enqueue_scripts registers the script: attach later then.
		if ( wp_script_is( 'wpcap-scanner', 'registered' ) ) {
			wp_add_inline_script( 'wpcap-scanner', $occurrences, 'before' );
		} else {
			add_action( 'wp_enqueue_scripts', static function () use ( $occurrences ) { wp_add_inline_script( 'wpcap-scanner', $occurrences, 'before' ); }, 99 );
		}
		return '<section class="wpcap-scanner">' . Brand::logo_html( 'scanner', 48 ) . '<h2>' . esc_html__( 'Pass Captain Check-in', 'pass-captain' ) . '</h2><p>' . esc_html__( 'Scanne den Ticketcode oder gib ihn manuell ein.', 'pass-captain' ) . '</p><p class="wpcap-scan-mode" hidden></p><p class="wpcap-scan-counter" hidden></p><video class="wpcap-camera" playsinline hidden></video><div class="wpcap-camera-controls"><button type="button" class="wpcap-camera-start">' . esc_html__( 'Kamera starten', 'pass-captain' ) . '</button><button type="button" class="wpcap-camera-stop" hidden>' . esc_html__( 'Kamera stoppen', 'pass-captain' ) . '</button><button type="button" class="wpcap-camera-torch" aria-pressed="false" hidden>' . esc_html__( 'Licht', 'pass-captain' ) . '</button><button type="button" class="wpcap-sound-toggle" aria-pressed="true">' . esc_html__( 'Ton', 'pass-captain' ) . '</button></div><form class="wpcap-scan-form"><label>' . esc_html__( 'Ticket-Code oder Hilfscode', 'pass-captain' ) . ' <input name="token" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="wpcap-scan-help" required></label><p id="wpcap-scan-help" class="wpcap-scan-help">' . esc_html__( 'Ist der QR-Code nicht lesbar, den 8-stelligen Hilfscode unter dem QR-Code eintippen (z. B. K7QM-4XWA).', 'pass-captain' ) . '</p><button type="submit">' . esc_html__( 'Ticket prüfen und einchecken', 'pass-captain' ) . '</button></form><form class="wpcap-search-form" role="search"><label>' . esc_html( self::scanner_search_label() ) . ' <input name="q" type="search" autocomplete="off" spellcheck="false" minlength="3" maxlength="190"></label><button type="submit">' . esc_html__( 'Suchen', 'pass-captain' ) . '</button></form><ul class="wpcap-search-results" aria-live="polite"></ul><div class="wpcap-scan-result"></div><p class="wpcap-scan-live wpcap-sr-only" role="status" aria-live="assertive" aria-atomic="true"></p><div class="wpcap-scan-overlay" aria-hidden="true" hidden></div></section>';
	}
}
