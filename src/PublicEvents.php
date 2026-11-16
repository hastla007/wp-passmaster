<?php
/** Public event listing and checkout embed. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PublicEvents {
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
		$providers = array();
		foreach ( Payments\Registry::enabled( 'EUR' ) as $id => $gateway ) { $providers[] = array( 'id' => $id, 'title' => $gateway->title() ); }
		wp_localize_script( 'wpcap-checkout', 'WPCAP_CHECKOUT', array(
			'url' => esc_url_raw( rest_url( 'pass-captain/v1/checkout' ) ),
			// Waitlist join: …/events/{event_id}/waitlist.
			'eventsUrl' => esc_url_raw( rest_url( 'pass-captain/v1/events/' ) ),
			'previewUrl' => esc_url_raw( rest_url( 'pass-captain/v1/campaigns/preview' ) ),
			'money' => Frontend\Format::js_config(),
			// Common mail domains for the typo hint on the buyer e-mail (A553).
			'emailDomains' => self::email_domains(),
			'i18n' => array(
				'selectTickets' => __( 'Bitte wähle mindestens ein Ticket.', 'pass-captain' ),
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
				'email' => __( 'E-Mail der Teilnehmer:in (optional, für ein eigenes Ticket)', 'pass-captain' ),
				/* translators: %s: formatted amount */
				'estimate' => __( 'Summe: %s (vorbehaltlich Rabatt; der endgültige Preis wird bei der Bestellung berechnet)', 'pass-captain' ),
				/* translators: %s: formatted amount */
				'orderTotal' => __( 'Gesamtbetrag: %s.', 'pass-captain' ),
				// Error summary and inline field errors.
				'errorSummary' => __( 'Bitte prüfe deine Angaben', 'pass-captain' ),
				/* translators: %d: number of problems */
				'errorCount' => __( 'Es gibt %d Problem(e). Wähle einen Eintrag, um zum Feld zu springen.', 'pass-captain' ),
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
			),
		) );
		wp_register_style( 'wpcap-public', WPCAP_URL . 'assets/public.css', array(), WPCAP_VERSION );
		wp_register_script( 'wpcap-scanner', WPCAP_URL . 'assets/scanner.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-scanner', 'WPCAP_SCANNER', array( 'validateUrl' => esc_url_raw( rest_url( 'pass-captain/v1/tickets/validate' ) ), 'checkinUrl' => esc_url_raw( rest_url( 'pass-captain/v1/tickets/check-in' ) ), 'offlineSyncUrl' => esc_url_raw( rest_url( 'pass-captain/v1/tickets/offline-sync' ) ), 'jsqrUrl' => esc_url_raw( WPCAP_URL . 'assets/vendor/jsqr/jsQR.js?ver=1.4.0' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'i18n' => array(
			'syncButton' => __( 'Offline-Scans synchronisieren', 'pass-captain' ),
			/* translators: %d: number of queued scans */
			'syncCount' => __( '%d Offline-Scan(s) synchronisieren', 'pass-captain' ),
			'syncFailed' => __( 'Synchronisierung fehlgeschlagen.', 'pass-captain' ),
			/* translators: 1: synced scans, 2: conflicts */
			'synced' => __( '%1$d Offline-Scan(s) synchronisiert; Konflikte: %2$d.', 'pass-captain' ),
			'queued' => __( 'Offline gespeichert. Der Einlass wird erst nach der Synchronisierung serverseitig bestätigt.', 'pass-captain' ),
			'unchecked' => __( 'NICHT GEPRÜFT (offline): Das Ticket kann ohne Verbindung nicht geprüft werden. Nur nach Sichtprüfung einlassen; für geprüften Offline-Einlass die Offline-Scanner-App verwenden.', 'pass-captain' ),
			'admitUnchecked' => __( 'Nach Sichtprüfung einlassen (wird später geprüft)', 'pass-captain' ),
			'checking' => __( 'Ticket wird geprüft …', 'pass-captain' ),
			'invalid' => __( 'Ticket nicht gültig.', 'pass-captain' ),
			'noCamera' => __( 'Kamera-QR-Scan wird von diesem Browser nicht unterstützt. Nutze die manuelle Eingabe.', 'pass-captain' ),
			'cameraError' => __( 'Kamerazugriff nicht möglich:', 'pass-captain' ),
			'ok' => __( 'Einlass', 'pass-captain' ),
			'refused' => __( 'Kein Einlass', 'pass-captain' ),
			'attention' => __( 'Achtung', 'pass-captain' ),
			/* translators: %s: scanner mode, e.g. "Einlass" */
			'modeBanner' => __( 'Modus: %s', 'pass-captain' ),
			'modeIn' => __( 'Einlass', 'pass-captain' ),
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
			'viaHelpCode' => __( 'Per Hilfscode geprüft – Ticketinhaber ggf. mit Ausweis abgleichen.', 'pass-captain' ),
			'helpOffline' => __( 'Hilfscodes können nur online geprüft werden. Bitte QR-Code scannen oder den langen Ticket-Code eingeben.', 'pass-captain' ),
			'rateLimited' => __( 'Zu viele Hilfscode-Eingaben. Bitte kurz warten oder den QR-Code bzw. den langen Ticket-Code verwenden.', 'pass-captain' ),
			'soundOn' => __( 'Ton an', 'pass-captain' ),
			'soundOff' => __( 'Ton aus', 'pass-captain' ),
			'dismiss' => __( 'Antippen zum Schließen', 'pass-captain' ),
			'discarded' => __( 'Scan verworfen – der vorige Scan wird noch bearbeitet. Bitte erneut scannen.', 'pass-captain' ),
			'multipleCodes' => __( 'Mehrere Ticketcodes in einer Eingabe – nichts geprüft. Bitte jedes Ticket einzeln scannen.', 'pass-captain' ),
			/* translators: %s: date/time of the earlier offline admission on this device */
			'queuedAlready' => __( 'Auf diesem Gerät bereits offline eingelassen um %s.', 'pass-captain' ),
			'timeout' => __( 'Keine Antwort vom Server.', 'pass-captain' ),
			/* translators: %d: HTTP status code */
			'badAnswer' => __( 'Unerwartete Antwort des Servers (HTTP %d) – das Ticket wurde nicht geprüft. Bitte erneut scannen.', 'pass-captain' ),
		) ) );
	}

	/**
	 * [wp_pass_captain_events event="12" occurrence="34" limit="50"] – upcoming bookable occurrences,
	 * optionally only of one event or one occurrence (also the block attributes).
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'event' => 0, 'occurrence' => 0, 'limit' => 50 ), is_array( $atts ) ? $atts : array(), 'wp_pass_captain_events' );
		return self::render_listing( $atts, '' );
	}

	/**
	 * Render callback of the block wp-pass-captain/events (render.php, A449): the block wrapper
	 * attributes (block class, custom CSS class, alignment, spacing) go on the root element, so
	 * theme.json block styles and the classes set in the editor apply on the website too.
	 */
	public static function render_block( array $attributes ) {
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'wpcap-events' ) ) : '';
		return self::render_listing( array( 'event' => absint( $attributes['event'] ?? 0 ), 'occurrence' => absint( $attributes['occurrence'] ?? 0 ), 'limit' => 50 ), $wrapper );
	}

	/**
	 * Event listing of the shortcode and the block.
	 *
	 * @param array  $atts    event, occurrence, limit.
	 * @param string $wrapper Attributes of the root element ('' = class="wpcap-events").
	 */
	private static function render_listing( array $atts, $wrapper ) {
		global $wpdb;
		// Upcoming occurrences, plus running ones (multi-day) with a ticket type still on sale (A535).
		$where = ' AND ' . Occurrence::listable_sql( 'o', Clock::mysql() );
		$args = array();
		if ( absint( $atts['event'] ) ) { $where .= ' AND e.event_id=%d'; $args[] = absint( $atts['event'] ); }
		if ( absint( $atts['occurrence'] ) ) { $where .= ' AND o.occurrence_id=%d'; $args[] = absint( $atts['occurrence'] ); }
		$args[] = max( 1, min( 200, absint( $atts['limit'] ) ?: 50 ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location" . ( DoorsOpen::ready() ? ',o.doors_open_at' : '' ) . " FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled'{$where} ORDER BY o.starts_at ASC LIMIT %d",
			...$args
		) );
		$meta = array();
		$listing = self::listing_data( $rows, $meta );
		$card_listing = self::card_listing( $rows, $listing, $meta );
		// A293: dates whose ticket types all start selling later get a "Vorverkauf ab …" card.
		$presale = self::presale_starts( $rows, $listing );
		$shown = 0;
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		ob_start();
		?>
		<div <?php echo '' !== $wrapper ? $wrapper : 'class="wpcap-events"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes. ?>>
			<?php foreach ( $rows as $event ) :
				$types = $listing[ (int) $event->occurrence_id ] ?? array();
				if ( ! $types ) {
					if ( isset( $presale[ (int) $event->occurrence_id ] ) ) {
						echo self::presale_card( $event, $presale[ (int) $event->occurrence_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in presale_card().
						++$shown;
					}
					continue;
				}
				++$shown;
				$providers = Payments\Registry::enabled( $types[0]->currency );
				// Bank transfer is not offered when the start is closer than manual_cutoff_hours.
				if ( isset( $providers['manual'] ) && count( $providers ) > 1 && null === Orders::manual_hold_until( strtotime( $event->starts_at . ' UTC' ) ) ) { unset( $providers['manual'] ); }
				$legal = self::legal_context();
				?>
				<?php
				// Badges, seats left, price and the event's other dates (theme templates may ignore it).
				$card = Frontend\EventCard::context( $event, $types, $card_listing );
				$template = self::theme_template( 'event-card.php' );
				if ( ! $template ) { $template = WPCAP_PATH . 'templates/event-card.php'; }
				ob_start();
				include $template;
				$card_html = ob_get_clean();
				$card_html = apply_filters( 'wpcap_event_card_html', $card_html, $event, $types );
				echo Hooks::legacy_filter( 'wpcap_event_card_html', $card_html, $event, $types ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes all output; trusted theme/plugin filter can customize markup.
				?>
			<?php endforeach; ?>
			<?php if ( ! $shown ) : ?><p class="wpcap-events-empty"><?php esc_html_e( 'Aktuell sind keine Veranstaltungen verfügbar.', 'pass-captain' ); ?></p><?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
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
		$now = current_time( 'mysql', true );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT occurrence_id, MIN(sales_start) AS sales_start FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id IN (" . implode( ',', array_unique( $ids ) ) . ") AND status='active' AND sales_start > %s AND (sales_end IS NULL OR sales_end > sales_start) GROUP BY occurrence_id", $now ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
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
		$html = '<article class="wpcap-event wpcap-event-presale" id="wpcap-occ-' . (int) $event->occurrence_id . '"><header class="wpcap-event-header">'
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
	 * @param object[] $rows Listing rows (event_id, name, description, occurrence_id, location, …); changed in place.
	 * @param array    $meta Set to occurrences (id => row with rescheduled_at, including upcoming cancelled
	 *                       dates of the listed events) – loaded by the same query, no extra round trip.
	 * @return array occurrence_id => object[] ticket types.
	 */
	public static function listing_data( array $rows, &$meta = null ) {
		$meta = array( 'occurrences' => array() );
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $rows ) { return array(); }
		$now = current_time( 'mysql', true );
		$event_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->event_id; }, $rows ) ) );
		$occ_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) ) );
		$translations = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_id,name,description,location FROM {$p}wpcap_event_translations WHERE event_id IN (" . implode( ',', $event_ids ) . ') AND locale=%s', determine_locale() ) ) as $translation ) {
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
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.occurrence_id,tt.name,tt.price_minor,tt.currency,tt.capacity,tt.max_per_order,tt.tax_rate_bp" . ( AdmissionChecks::ready() ? ',tt.id_check,tt.min_age' : '' ) . " FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.occurrence_id IN ($occ_in) AND tt.status='active' AND (tt.sales_start IS NULL OR tt.sales_start <= %s) AND (tt.sales_end IS NULL OR tt.sales_end >= %s) AND " . Occurrence::type_sale_sql( 'tt', 'o', $now ) . ' ORDER BY tt.ticket_type_id', $now, $now ) ) as $type ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integer list and prepared fragment.
			$by_occurrence[ (int) $type->occurrence_id ][] = $type;
			$all_types[ (int) $type->ticket_type_id ] = $type;
		}
		if ( ! $all_types ) { return array(); }
		$occurrences = array();
		// Same query also brings the last reschedule (badge "Verschoben") and upcoming cancelled dates
		// of the listed events (badge "Abgesagt" in the card's date list).
		$event_in = implode( ',', $event_ids );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT o.*," . Frontend\EventCard::rescheduled_sql( 'o' ) . " AS rescheduled_at FROM {$p}wpcap_occurrences o WHERE o.occurrence_id IN ($occ_in) OR (o.event_id IN ($event_in) AND o.status='cancelled' AND o.starts_at >= %s)", $now ) ) as $occurrence ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer lists.
			$occurrences[ (int) $occurrence->occurrence_id ] = $occurrence;
		}
		$meta['occurrences'] = $occurrences;
		$type_in = implode( ',', array_keys( $all_types ) );
		$fields = array();
		// Only field definitions are exposed (no answers); used to render attendee inputs.
		foreach ( (array) $wpdb->get_results( "SELECT ticket_type_id,field_key AS `key`,label,field_type AS type,required,purpose FROM {$p}wpcap_participant_fields WHERE ticket_type_id IN ($type_in) AND status='active' ORDER BY position,field_id", ARRAY_A ) as $field ) {
			$type_id = (int) $field['ticket_type_id'];
			unset( $field['ticket_type_id'] );
			$fields[ $type_id ][] = $field;
		}
		$available = Orders::availability_many( array_keys( $all_types ), array( 'occurrences' => $occurrences ) );
		foreach ( $by_occurrence as $occ_id => $types ) {
			$occurrence = $occurrences[ $occ_id ] ?? null;
			foreach ( $types as $type ) {
				// Effective server-side price (e.g. series price tiers); the checkout charges the same.
				$type->base_price_minor = (int) $type->price_minor;
				$type->price_label = Orders::price_label( $type, $occurrence, array( 'now' => $now ) );
				$type->price_minor = Orders::unit_price( $type, $occurrence, 1, array( 'now' => $now ) );
				$type->available = array_key_exists( (int) $type->ticket_type_id, $available ) ? $available[ (int) $type->ticket_type_id ] : 0;
				$type->fields = $fields[ (int) $type->ticket_type_id ] ?? array();
			}
		}
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

	/**
	 * Choices for the events block: published events with their upcoming dates. Labels carry the next
	 * date (and the date for each occurrence) so events with the same name can be told apart.
	 *
	 * @return array{events: array[]}
	 */
	public static function block_choices() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.event_id,e.name,o.occurrence_id,o.starts_at,o.timezone FROM {$p}wpcap_events e INNER JOIN {$p}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 500", current_time( 'mysql', true ) ) );
		$events = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->event_id;
			$when = Orders::format_start( $row->starts_at, $row->timezone );
			if ( ! isset( $events[ $id ] ) ) {
				$events[ $id ] = array( 'id' => $id, 'label' => wp_specialchars_decode( (string) $row->name, ENT_QUOTES ) . ' (' . $when . ', #' . $id . ')', 'occurrences' => array() );
			}
			$events[ $id ]['occurrences'][] = array( 'id' => (int) $row->occurrence_id, 'label' => $when );
		}
		return array( 'events' => array_values( $events ) );
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
			"SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.status<>'cancelled' AND " . Occurrence::end_sql( 'o' ) . ' >= %s AND o.starts_at <= %s ORDER BY o.starts_at,o.occurrence_id LIMIT 100', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static expression.
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

	public static function render_scanner_shortcode() {
		// A508: never cache the scanner page (login state, nonce). Headers were sent on
		// template_redirect (PageCache::maybe_no_store_request()); DONOTCACHEPAGE covers late rendering.
		PageCache::no_store( 'scanner' );
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) {
			return '<p>' . esc_html__( 'Für den Scanner ist eine Anmeldung mit Einlassberechtigung erforderlich.', 'pass-captain' ) . '</p>';
		}
		wp_enqueue_script( 'wpcap-scanner' );
		wp_enqueue_style( 'wpcap-public' );
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
		return '<section class="wpcap-scanner"><h2>' . esc_html__( 'Pass Captain Check-in', 'pass-captain' ) . '</h2><p>' . esc_html__( 'Scanne den Ticketcode oder gib ihn manuell ein.', 'pass-captain' ) . '</p><p class="wpcap-scan-mode" hidden></p><video class="wpcap-camera" playsinline hidden></video><div class="wpcap-camera-controls"><button type="button" class="wpcap-camera-start">' . esc_html__( 'Kamera starten', 'pass-captain' ) . '</button><button type="button" class="wpcap-camera-stop" hidden>' . esc_html__( 'Kamera stoppen', 'pass-captain' ) . '</button><button type="button" class="wpcap-camera-torch" aria-pressed="false" hidden>' . esc_html__( 'Licht', 'pass-captain' ) . '</button><button type="button" class="wpcap-sound-toggle" aria-pressed="true">' . esc_html__( 'Ton', 'pass-captain' ) . '</button></div><form class="wpcap-scan-form"><label>' . esc_html__( 'Ticket-Code oder Hilfscode', 'pass-captain' ) . ' <input name="token" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="wpcap-scan-help" required></label><p id="wpcap-scan-help" class="wpcap-scan-help">' . esc_html__( 'Ist der QR-Code nicht lesbar, den 8-stelligen Hilfscode unter dem QR-Code eintippen (z. B. K7QM-4XWA).', 'pass-captain' ) . '</p><button type="submit">' . esc_html__( 'Ticket prüfen und einchecken', 'pass-captain' ) . '</button></form><div class="wpcap-scan-result"></div><p class="wpcap-scan-live wpcap-sr-only" role="status" aria-live="assertive" aria-atomic="true"></p><div class="wpcap-scan-overlay" aria-hidden="true" hidden></div></section>';
	}
}
