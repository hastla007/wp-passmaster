<?php
/** Public event listing and checkout embed. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PublicEvents {
	public static function register_assets() {
		wp_register_script( 'wpcap-checkout', WPCAP_URL . 'assets/checkout.js', array(), WPCAP_VERSION, true );
		$providers = array();
		foreach ( Payments\Registry::enabled( 'EUR' ) as $id => $gateway ) { $providers[] = array( 'id' => $id, 'title' => $gateway->title() ); }
		wp_localize_script( 'wpcap-checkout', 'WPCAP_CHECKOUT', array(
			'url' => esc_url_raw( rest_url( 'wp-pass-captain/v1/checkout' ) ),
			// Waitlist join: …/events/{event_id}/waitlist.
			'eventsUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/events/' ) ),
			'previewUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/campaigns/preview' ) ),
			'money' => Frontend\Format::js_config(),
			'i18n' => array(
				'selectTickets' => __( 'Bitte wähle mindestens ein Ticket.', 'wp-pass-captain' ),
				/* translators: 1: subtotal, 2: discount, 3: total */
				'estimateDiscount' => __( 'Summe: %1$s − Rabatt %2$s = %3$s (vorläufig; der endgültige Preis wird bei der Bestellung berechnet)', 'wp-pass-captain' ),
				'codeInvalid' => __( 'Der Rabattcode ist ungültig oder passt nicht zu den ausgewählten Tickets.', 'wp-pass-captain' ),
				'waitlistJoining' => __( 'Wird eingetragen …', 'wp-pass-captain' ),
				'waitlistJoined' => __( 'Du stehst auf der Warteliste. Wir melden uns per E-Mail, sobald ein Platz frei wird.', 'wp-pass-captain' ),
				'waitlistFailed' => __( 'Eintragen fehlgeschlagen.', 'wp-pass-captain' ),
				'waitlistEmail' => __( 'Bitte gib eine gültige E-Mail-Adresse ein.', 'wp-pass-captain' ),
				'tooMany' => __( 'Für eine Ticketart wurde die Höchstmenge überschritten.', 'wp-pass-captain' ),
				'creating' => __( 'Bestellung wird angelegt …', 'wp-pass-captain' ),
				'failed' => __( 'Bestellung fehlgeschlagen.', 'wp-pass-captain' ),
				'complete' => __( 'Bestellung abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'wp-pass-captain' ),
				'redirecting' => __( 'Weiterleitung zur Zahlung …', 'wp-pass-captain' ),
				/* translators: %d: hold minutes */
				'pending' => __( 'Bestellung angelegt; deine Tickets sind %d Minuten reserviert.', 'wp-pass-captain' ),
				/* translators: %d: hold hours */
				'pendingHours' => __( 'Bestellung angelegt; deine Tickets sind %d Stunden reserviert.', 'wp-pass-captain' ),
				/* translators: %d: hold days */
				'pendingDays' => __( 'Bestellung angelegt; deine Tickets sind %d Tage reserviert.', 'wp-pass-captain' ),
				/* translators: %d: ticket number */
				'attendee' => __( 'Teilnehmer:in %d', 'wp-pass-captain' ),
				'name' => __( 'Name', 'wp-pass-captain' ),
				'email' => __( 'E-Mail der Teilnehmer:in (optional, für ein eigenes Ticket)', 'wp-pass-captain' ),
				/* translators: %s: formatted amount */
				'estimate' => __( 'Summe: %s (vorbehaltlich Rabatt; der endgültige Preis wird bei der Bestellung berechnet)', 'wp-pass-captain' ),
				/* translators: %s: formatted amount */
				'orderTotal' => __( 'Gesamtbetrag: %s.', 'wp-pass-captain' ),
				// Error summary and inline field errors.
				'errorSummary' => __( 'Bitte prüfe deine Angaben', 'wp-pass-captain' ),
				/* translators: %d: number of problems */
				'errorCount' => __( 'Es gibt %d Problem(e). Wähle einen Eintrag, um zum Feld zu springen.', 'wp-pass-captain' ),
				/* translators: %s: field label */
				'errRequired' => __( 'Bitte fülle „%s“ aus.', 'wp-pass-captain' ),
				/* translators: %s: field label */
				'errEmail' => __( '„%s“: Bitte gib eine gültige E-Mail-Adresse ein, z. B. name@example.com.', 'wp-pass-captain' ),
				/* translators: %s: field label */
				'errInvalid' => __( '„%s“ ist ungültig.', 'wp-pass-captain' ),
				'errTerms' => __( 'Bitte bestätige die Bedingungen, um zu bestellen.', 'wp-pass-captain' ),
				/* translators: 1: ticket type, 2: maximum quantity */
				'errTooMany' => __( '„%1$s“: Es sind höchstens %2$d Tickets möglich.', 'wp-pass-captain' ),
				// Confirmation panel.
				'thanks' => __( 'Vielen Dank für deine Bestellung!', 'wp-pass-captain' ),
				'thanksComplete' => __( 'Bestellung abgeschlossen', 'wp-pass-captain' ),
				/* translators: %s: short order number */
				'orderNumber' => __( 'Bestellnummer: %s', 'wp-pass-captain' ),
				/* translators: %s: e-mail address */
				'sentTo' => __( 'Wir haben die Bestätigung mit deinem persönlichen Bestelllink an %s gesendet.', 'wp-pass-captain' ),
				/* translators: %s: e-mail address */
				'ticketsSentTo' => __( 'Die Tickets und die Bestätigung haben wir an %s gesendet.', 'wp-pass-captain' ),
				'spamHint' => __( 'Keine E-Mail erhalten? Sieh bitte auch im Spam-Ordner nach.', 'wp-pass-captain' ),
				'payNow' => __( 'Bitte überweise den Betrag mit diesen Angaben:', 'wp-pass-captain' ),
				/* translators: %s: date and time */
				'payUntil' => __( 'Deine Tickets sind bis %s reserviert. Geht die Zahlung bis dahin nicht ein, verfällt die Reservierung.', 'wp-pass-captain' ),
				'afterPayment' => __( 'Nach Zahlungseingang erhältst du deine Tickets per E-Mail.', 'wp-pass-captain' ),
				'amount' => __( 'Betrag', 'wp-pass-captain' ),
				'recipient' => __( 'Empfänger', 'wp-pass-captain' ),
				'iban' => __( 'IBAN', 'wp-pass-captain' ),
				'bic' => __( 'BIC', 'wp-pass-captain' ),
				'remittance' => __( 'Verwendungszweck', 'wp-pass-captain' ),
				'dueDate' => __( 'Zahlbar bis', 'wp-pass-captain' ),
				'orderAgain' => __( 'Weitere Tickets bestellen', 'wp-pass-captain' ),
			),
		) );
		wp_register_style( 'wpcap-public', WPCAP_URL . 'assets/public.css', array(), WPCAP_VERSION );
		wp_register_script( 'wpcap-scanner', WPCAP_URL . 'assets/scanner.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-scanner', 'WPCAP_SCANNER', array( 'validateUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/validate' ) ), 'checkinUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/check-in' ) ), 'offlineSyncUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/offline-sync' ) ), 'jsqrUrl' => esc_url_raw( WPCAP_URL . 'assets/vendor/jsqr/jsQR.js?ver=1.4.0' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'i18n' => array(
			'syncButton' => __( 'Offline-Scans synchronisieren', 'wp-pass-captain' ),
			/* translators: %d: number of queued scans */
			'syncCount' => __( '%d Offline-Scan(s) synchronisieren', 'wp-pass-captain' ),
			'syncFailed' => __( 'Synchronisierung fehlgeschlagen.', 'wp-pass-captain' ),
			/* translators: 1: synced scans, 2: conflicts */
			'synced' => __( '%1$d Offline-Scan(s) synchronisiert; Konflikte: %2$d.', 'wp-pass-captain' ),
			'queued' => __( 'Offline gespeichert. Der Einlass wird erst nach der Synchronisierung serverseitig bestätigt.', 'wp-pass-captain' ),
			'unchecked' => __( 'NICHT GEPRÜFT (offline): Das Ticket kann ohne Verbindung nicht geprüft werden. Nur nach Sichtprüfung einlassen; für geprüften Offline-Einlass die Offline-Scanner-App verwenden.', 'wp-pass-captain' ),
			'admitUnchecked' => __( 'Nach Sichtprüfung einlassen (wird später geprüft)', 'wp-pass-captain' ),
			'checking' => __( 'Ticket wird geprüft …', 'wp-pass-captain' ),
			'invalid' => __( 'Ticket nicht gültig.', 'wp-pass-captain' ),
			'noCamera' => __( 'Kamera-QR-Scan wird von diesem Browser nicht unterstützt. Nutze die manuelle Eingabe.', 'wp-pass-captain' ),
			'cameraError' => __( 'Kamerazugriff nicht möglich:', 'wp-pass-captain' ),
			'ok' => __( 'Einlass', 'wp-pass-captain' ),
			'refused' => __( 'Kein Einlass', 'wp-pass-captain' ),
			'attention' => __( 'Achtung', 'wp-pass-captain' ),
			/* translators: %s: scanner mode, e.g. "Einlass" */
			'modeBanner' => __( 'Modus: %s', 'wp-pass-captain' ),
			'modeIn' => __( 'Einlass', 'wp-pass-captain' ),
			'systemError' => __( 'Systemfehler – das Ticket wurde nicht geprüft. Bitte erneut scannen.', 'wp-pass-captain' ),
			'sessionExpired' => __( 'Sitzung abgelaufen – bitte die Seite neu laden und erneut scannen.', 'wp-pass-captain' ),
		) ) );
	}

	/**
	 * [wp_pass_captain_events event="12" occurrence="34" limit="50"] – upcoming bookable occurrences,
	 * optionally only of one event or one occurrence (also the block attributes).
	 */
	public static function render_shortcode( $atts = array() ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'event' => 0, 'occurrence' => 0, 'limit' => 50 ), is_array( $atts ) ? $atts : array(), 'wp_pass_captain_events' );
		$where = '';
		$args = array( current_time( 'mysql', true ) );
		if ( absint( $atts['event'] ) ) { $where .= ' AND e.event_id=%d'; $args[] = absint( $atts['event'] ); }
		if ( absint( $atts['occurrence'] ) ) { $where .= ' AND o.occurrence_id=%d'; $args[] = absint( $atts['occurrence'] ); }
		$args[] = max( 1, min( 200, absint( $atts['limit'] ) ?: 50 ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s{$where} ORDER BY o.starts_at ASC LIMIT %d",
			...$args
		) );
		$listing = self::listing_data( $rows );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		ob_start();
		?>
		<div class="wpcap-events">
			<?php if ( ! $rows ) : ?><p><?php esc_html_e( 'Aktuell sind keine Veranstaltungen verfügbar.', 'wp-pass-captain' ); ?></p><?php endif; ?>
			<?php foreach ( $rows as $event ) :
				$types = $listing[ (int) $event->occurrence_id ] ?? array();
				if ( ! $types ) { continue; }
				$providers = Payments\Registry::enabled( $types[0]->currency );
				// Bank transfer is not offered when the start is closer than manual_cutoff_hours.
				if ( isset( $providers['manual'] ) && count( $providers ) > 1 && null === Orders::manual_hold_until( strtotime( $event->starts_at . ' UTC' ) ) ) { unset( $providers['manual'] ); }
				$legal = array(
					'require_terms' => (bool) Settings::get( 'require_terms' ),
					'terms_text' => (string) Settings::get( 'terms_text' ),
					'consumer_info_text' => (string) Settings::get( 'consumer_info_text' ),
				);
				?>
				<?php
				$template = locate_template( 'wp-pass-captain/event-card.php' );
				if ( ! $template ) { $template = WPCAP_PATH . 'templates/event-card.php'; }
				ob_start();
				include $template;
				$card_html = ob_get_clean();
				echo apply_filters( 'wp_pass_captain_event_card_html', $card_html, $event, $types ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes all output; trusted theme/plugin filter can customize markup.
				?>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Loads everything the event cards need for the listed occurrences with a constant number of
	 * queries (translations, ticket types, occurrences, participant fields, availability), applies
	 * translations to $rows and returns occurrence_id => ticket types prepared for the card
	 * (price_minor = effective price, base_price_minor, price_label, available, fields).
	 *
	 * @param object[] $rows Listing rows (event_id, name, description, occurrence_id, location, …); changed in place.
	 * @return array occurrence_id => object[] ticket types.
	 */
	public static function listing_data( array $rows ) {
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
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp FROM {$p}wpcap_ticket_types WHERE occurrence_id IN ($occ_in) AND status='active' AND (sales_start IS NULL OR sales_start <= %s) AND (sales_end IS NULL OR sales_end >= %s) ORDER BY ticket_type_id", $now, $now ) ) as $type ) {
			$by_occurrence[ (int) $type->occurrence_id ][] = $type;
			$all_types[ (int) $type->ticket_type_id ] = $type;
		}
		if ( ! $all_types ) { return array(); }
		$occurrences = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}wpcap_occurrences WHERE occurrence_id IN ($occ_in)" ) as $occurrence ) {
			$occurrences[ (int) $occurrence->occurrence_id ] = $occurrence;
		}
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
	 * Occurrences the current user may admit to, from yesterday to a week ahead (web scanner date
	 * selection: a ticket for another date is refused with its own date instead of a time error).
	 *
	 * @return array[] {id, label}
	 */
	public static function scanner_occurrences() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.occurrence_id,o.event_id,o.starts_at,o.timezone,e.name FROM {$p}wpcap_occurrences o INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE o.status<>'cancelled' AND o.starts_at BETWEEN %s AND %s ORDER BY o.starts_at,o.occurrence_id LIMIT 100",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS )
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
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) {
			return '<p>' . esc_html__( 'Für den Scanner ist eine Anmeldung mit Einlassberechtigung erforderlich.', 'wp-pass-captain' ) . '</p>';
		}
		wp_enqueue_script( 'wpcap-scanner' );
		wp_enqueue_style( 'wpcap-public' );
		$occurrences = 'window.WPCAP_SCANNER_OCCURRENCES=' . wp_json_encode( array(
			'label' => __( 'Termin', 'wp-pass-captain' ),
			'all' => __( 'Alle Termine (keine Terminprüfung)', 'wp-pass-captain' ),
			'items' => self::scanner_occurrences(),
		) ) . ';';
		// Block themes render the content before wp_enqueue_scripts registers the script: attach later then.
		if ( wp_script_is( 'wpcap-scanner', 'registered' ) ) {
			wp_add_inline_script( 'wpcap-scanner', $occurrences, 'before' );
		} else {
			add_action( 'wp_enqueue_scripts', static function () use ( $occurrences ) { wp_add_inline_script( 'wpcap-scanner', $occurrences, 'before' ); }, 99 );
		}
		return '<section class="wpcap-scanner"><h2>' . esc_html__( 'Pass Captain Check-in', 'wp-pass-captain' ) . '</h2><p>' . esc_html__( 'Scanne den Ticketcode oder gib ihn manuell ein.', 'wp-pass-captain' ) . '</p><p class="wpcap-scan-mode" hidden></p><video class="wpcap-camera" playsinline hidden></video><div class="wpcap-camera-controls"><button type="button" class="wpcap-camera-start">' . esc_html__( 'Kamera starten', 'wp-pass-captain' ) . '</button><button type="button" class="wpcap-camera-stop" hidden>' . esc_html__( 'Kamera stoppen', 'wp-pass-captain' ) . '</button><button type="button" class="wpcap-camera-torch" aria-pressed="false" hidden>' . esc_html__( 'Licht', 'wp-pass-captain' ) . '</button></div><form class="wpcap-scan-form"><label>' . esc_html__( 'Ticket-Code', 'wp-pass-captain' ) . ' <input name="token" type="text" inputmode="text" autocomplete="off" required></label><button type="submit">' . esc_html__( 'Ticket prüfen und einchecken', 'wp-pass-captain' ) . '</button></form><div class="wpcap-scan-result"></div><p class="wpcap-scan-live wpcap-sr-only" role="status" aria-live="assertive" aria-atomic="true"></p></section>';
	}
}
