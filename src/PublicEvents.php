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

	public static function register_assets() {
		wp_register_script( 'wpcap-checkout', WPCAP_URL . 'assets/checkout.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-checkout', 'WPCAP_CHECKOUT', array(
			'url' => esc_url_raw( rest_url( 'wp-pass-captain/v1/checkout' ) ),
			'money' => Frontend\Format::js_config(),
			'i18n' => array(
				'selectTickets' => __( 'Bitte wähle mindestens ein Ticket.', 'pass-captain' ),
				'tooMany' => __( 'Für eine Ticketart wurde die Höchstmenge überschritten.', 'pass-captain' ),
				'creating' => __( 'Bestellung wird angelegt …', 'pass-captain' ),
				'failed' => __( 'Bestellung fehlgeschlagen.', 'pass-captain' ),
				'complete' => __( 'Bestellung abgeschlossen. Die Tickets wurden an deine E-Mail-Adresse gesendet.', 'pass-captain' ),
				'redirecting' => __( 'Weiterleitung zur Zahlung …', 'pass-captain' ),
				/* translators: %d: hold minutes */
				'pending' => __( 'Bestellung angelegt; deine Tickets sind %d Minuten reserviert.', 'pass-captain' ),
				/* translators: %d: hold hours */
				'pendingHours' => __( 'Bestellung angelegt; deine Tickets sind %d Stunden reserviert.', 'pass-captain' ),
				/* translators: %d: hold days */
				'pendingDays' => __( 'Bestellung angelegt; deine Tickets sind %d Tage reserviert.', 'pass-captain' ),
				/* translators: %d: ticket number */
				'attendee' => __( 'Teilnehmer:in %d', 'pass-captain' ),
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
			),
		) );
		wp_register_style( 'wpcap-public', WPCAP_URL . 'assets/public.css', array(), WPCAP_VERSION );
		wp_register_script( 'wpcap-scanner', WPCAP_URL . 'assets/scanner.js', array(), WPCAP_VERSION, true );
		wp_localize_script( 'wpcap-scanner', 'WPCAP_SCANNER', array( 'validateUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/validate' ) ), 'checkinUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/check-in' ) ), 'offlineSyncUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/tickets/offline-sync' ) ), 'jsqrUrl' => esc_url_raw( WPCAP_URL . 'assets/vendor/jsqr/jsQR.js?ver=1.4.0' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'i18n' => array(
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
		) ) );
	}

	public static function render_shortcode() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location FROM %i e INNER JOIN %i o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 50", $wpdb->prefix . 'wpcap_events', $wpdb->prefix . 'wpcap_occurrences',
			current_time( 'mysql', true )
		) );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		return self::render_cards( $rows );
	}

	/**
	 * Renders the event cards for listing rows (event_id, name, description, occurrence_id,
	 * starts_at, timezone, location). Translations, ticket types, participant fields, capacity,
	 * waitlist holds, pools and price rules are loaded with a fixed number of grouped queries for
	 * all rows (DisplayBatch), not per occurrence or ticket type.
	 *
	 * @param object[] $rows Listing rows.
	 */
	public static function render_cards( array $rows ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );
		$event_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->event_id; }, $rows ) ) );
		$occ_ids = array_values( array_unique( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) ) );
		$translations = self::translations( $event_ids, determine_locale() );
		$types_by_occ = array();
		if ( $occ_ids ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp FROM %i WHERE occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ") AND status='active' AND (sales_start IS NULL OR sales_start <= %s) AND (sales_end IS NULL OR sales_end >= %s) ORDER BY ticket_type_id", array_merge( array( $p . 'wpcap_ticket_types' ), $occ_ids, array( $now, $now ) ) ) ) as $type ) {
				$types_by_occ[ (int) $type->occurrence_id ][] = $type;
			}
		}
		$type_ids = array();
		foreach ( $types_by_occ as $list ) {
			foreach ( $list as $type ) { $type_ids[] = (int) $type->ticket_type_id; }
		}
		$fields = array();
		if ( $type_ids ) {
			// Only field definitions are exposed (no answers); used to render attendee inputs.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated placeholders.
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,field_key AS `key`,label,field_type AS type,required,purpose,consent_text,consent_version FROM %i WHERE ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ") AND status='active' ORDER BY position,field_id", array_merge( array( $p . 'wpcap_participant_fields' ), $type_ids ) ), ARRAY_A ) as $field ) {
				$type_id = (int) $field['ticket_type_id'];
				unset( $field['ticket_type_id'] );
				$fields[ $type_id ][] = $field;
			}
		}
		$legal = array(
			'require_terms' => (bool) Settings::get( 'require_terms' ),
			'terms_text' => (string) Settings::get( 'terms_text' ),
			'consumer_info_text' => (string) Settings::get( 'consumer_info_text' ),
		);
		return DisplayBatch::run( $type_ids, static function () use ( $rows, $translations, $types_by_occ, $fields, $legal, $now ) {
			$providers_by_currency = array();
			ob_start();
			?>
			<div class="wpcap-events">
				<?php if ( ! $rows ) : ?><p><?php esc_html_e( 'Aktuell sind keine Veranstaltungen verfügbar.', 'pass-captain' ); ?></p><?php endif; ?>
				<?php foreach ( $rows as $event ) :
					$translation = $translations[ (int) $event->event_id ] ?? null;
					if ( $translation ) {
						$event->name = $translation->name;
						$event->description = $translation->description;
						$event->location = $translation->location ?: $event->location;
					}
					$types = $types_by_occ[ (int) $event->occurrence_id ] ?? array();
					if ( ! $types ) { continue; }
					$occurrence = DisplayBatch::occurrence( $event->occurrence_id );
					foreach ( $types as $type ) {
						// Effective server-side price (e.g. series price tiers); the checkout charges the same.
						$type->base_price_minor = (int) $type->price_minor;
						$type->price_label = Orders::price_label( $type, $occurrence, array( 'now' => $now ) );
						// Buyer-visible reason for an automatic price (Sales\PriceRules), e.g. "Frühbucherpreis: gilt bei Buchung bis …".
						$type->price_explanation = Sales\PriceRules::explanation_for( $type, $occurrence, array( 'now' => $now ) );
						$type->price_minor = Orders::unit_price( $type, $occurrence, 1, array( 'now' => $now ) );
						$type->available = Orders::availability( $type->ticket_type_id );
						$type->fields = $fields[ (int) $type->ticket_type_id ] ?? array();
					}
					$currency = (string) $types[0]->currency;
					if ( ! isset( $providers_by_currency[ $currency ] ) ) { $providers_by_currency[ $currency ] = Payments\Registry::enabled( $currency ); }
					$providers = $providers_by_currency[ $currency ];
					$template = locate_template( 'wp-pass-captain/event-card.php' );
					if ( ! $template ) { $template = WPCAP_PATH . 'templates/event-card.php'; }
					ob_start();
					include $template;
					$card_html = ob_get_clean();
					// Versioned contract wp_pass_captain_event_card_html_v1; the unversioned name still fires (deprecated since 1.5.0).
					$card_html = apply_filters_deprecated( 'wp_pass_captain_event_card_html', array( $card_html, $event, $types ), '1.5.0', 'wp_pass_captain_event_card_html_v1' );
					echo apply_filters( 'wp_pass_captain_event_card_html_v1', $card_html, $event, $types ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes all output; trusted theme/plugin filter can customize markup.
				endforeach; ?>
			</div>
			<?php
			return ob_get_clean();
		} );
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

	public static function render_scanner_shortcode() {
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) {
			return '<p>' . esc_html__( 'Für den Scanner ist eine Anmeldung mit Einlassberechtigung erforderlich.', 'pass-captain' ) . '</p>';
		}
		wp_enqueue_script( 'wpcap-scanner' );
		wp_enqueue_style( 'wpcap-public' );
		self::$scanner_rendered = true;
		return '<section class="wpcap-scanner">' . Brand::logo_html( 'scanner', 48 ) . '<h2>' . esc_html__( 'Pass Captain Check-in', 'pass-captain' ) . '</h2><p>' . esc_html__( 'Scanne den Ticketcode oder gib ihn manuell ein.', 'pass-captain' ) . '</p><video class="wpcap-camera" playsinline hidden></video><button type="button" class="wpcap-camera-start">' . esc_html__( 'Kamera starten', 'pass-captain' ) . '</button><form class="wpcap-scan-form"><label>' . esc_html__( 'Ticket-Code', 'pass-captain' ) . ' <input name="token" type="text" inputmode="text" autocomplete="off" required></label><button type="submit">' . esc_html__( 'Ticket prüfen und einchecken', 'pass-captain' ) . '</button></form><p class="wpcap-scan-result" role="status" aria-live="polite"></p></section>';
	}
}
