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
		wp_localize_script( 'wpcap-checkout', 'WPCAP_CHECKOUT', self::checkout_config() );
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

	/**
	 * Data for assets/checkout.js (WPCAP_CHECKOUT). The REST nonce is only added for signed-in users:
	 * cached pages for guests stay nonce-free (a cached, expired nonce would break their checkout), and
	 * with the nonce the REST request runs as the signed-in customer, so buyer limits "per account"
	 * (Sales\BuyerLimits, customer_user_id) apply in the native checkout.
	 */
	public static function checkout_config() {
		$config = array(
			'url' => esc_url_raw( rest_url( 'wp-pass-captain/v1/checkout' ) ),
			'availabilityUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/availability' ) ),
			// Event id 0 is replaced with the form's event id (esc_url_raw() would strip a {id} placeholder).
			'waitlistUrl' => esc_url_raw( rest_url( 'wp-pass-captain/v1/events/0/waitlist' ) ),
			// Polling interval of the live availability while the page is visible (15–60 s).
			'poll' => min( 60, max( 15, (int) apply_filters( 'wpcap_availability_poll_seconds', 20 ) ) ),
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
				// Live availability, waitlist, quantity stepper, attendee status (audit R2 #7/#9).
				'soldOut' => __( 'Ausverkauft', 'pass-captain' ),
				/* translators: %s: date and time */
				'salesFrom' => __( 'Verkauf ab %s', 'pass-captain' ),
				/* translators: %s: remaining time, e.g. "2 Std. 5 Min." */
				'salesIn' => __( 'Verkaufsstart in %s', 'pass-captain' ),
				'salesOpen' => __( 'Der Verkauf hat begonnen.', 'pass-captain' ),
				/* translators: %d: days */
				'days' => __( '%d Tg.', 'pass-captain' ),
				/* translators: %d: hours */
				'hours' => __( '%d Std.', 'pass-captain' ),
				/* translators: %d: minutes */
				'minutes' => __( '%d Min.', 'pass-captain' ),
				/* translators: %d: seconds */
				'seconds' => __( '%d Sek.', 'pass-captain' ),
				/* translators: %d: remaining tickets */
				'remaining' => __( 'Nur noch %d verfügbar', 'pass-captain' ),
				/* translators: %s: ticket type */
				'decrease' => __( 'Anzahl „%s“ verringern', 'pass-captain' ),
				/* translators: %s: ticket type */
				'increase' => __( 'Anzahl „%s“ erhöhen', 'pass-captain' ),
				/* translators: %d: number of tickets */
				'attendeeCount' => __( 'Angaben für %d Ticket(s)', 'pass-captain' ),
				'attendeeNameRequired' => __( 'Name (wie auf dem Ticket)', 'pass-captain' ),
				'attendeeEmailToggle' => __( 'Ticket an eine eigene E-Mail-Adresse senden (optional)', 'pass-captain' ),
				'choose' => __( '– bitte wählen –', 'pass-captain' ),
				'payButton' => __( 'Zahlungspflichtig bestellen', 'pass-captain' ),
				'freeButton' => __( 'Kostenlos bestellen', 'pass-captain' ),
				'free' => __( 'kostenlos', 'pass-captain' ),
				'retrying' => __( 'Gerade ist viel los – neuer Versuch …', 'pass-captain' ),
				/* translators: %d: remaining tickets */
				'notEnough' => __( 'Es sind nur noch %d Tickets dieser Art verfügbar. Die Auswahl wurde angepasst.', 'pass-captain' ),
				'waitlistOpen' => __( 'Auf die Warteliste', 'pass-captain' ),
				/* translators: %s: ticket type */
				'waitlistOpenLabel' => __( 'Für „%s“ auf die Warteliste setzen', 'pass-captain' ),
				'waitlistEmail' => __( 'E-Mail-Adresse für die Warteliste', 'pass-captain' ),
				'waitlistSubmit' => __( 'Eintragen', 'pass-captain' ),
				'waitlistSending' => __( 'Wird eingetragen …', 'pass-captain' ),
				'waitlistConfirm' => __( 'Bitte bestätige den Link in der E-Mail, die wir dir gerade geschickt haben – erst dann zählt dein Platz auf der Warteliste.', 'pass-captain' ),
				'salesClosed' => __( 'Nicht mehr erhältlich', 'pass-captain' ),
				'waitlistFailed' => __( 'Die Eintragung hat nicht geklappt. Bitte versuche es erneut.', 'pass-captain' ),
			),
		);
		if ( get_current_user_id() > 0 ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		return $config;
	}

	/** Default attributes of [wp_pass_captain_events] and the wp-pass-captain/events block. */
	const DEFAULT_ATTS = array( 'event' => 0, 'occurrence' => 0, 'limit' => 50, 'layout' => 'auto' );

	/** Query parameter of the single-occurrence view (?wpcap_occ=ID). */
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
	 * Up to this many occurrences, layout "auto" shows full checkout cards; more become the compact
	 * list with a "Tickets" link per occurrence (filter wpcap_events_auto_list_threshold).
	 */
	public static function auto_list_threshold() {
		return max( 1, (int) apply_filters( 'wpcap_events_auto_list_threshold', 3 ) );
	}

	/**
	 * [wp_pass_captain_events event="" occurrence="" limit="50" layout="auto|list|cards"]
	 *
	 * Without occurrence: list of upcoming occurrences (compact rows with a "Tickets" button, series
	 * with a date picker) or – layout cards, or auto with few occurrences – the checkout cards. With
	 * occurrence (attribute, or ?wpcap_occ=ID on the page): only that occurrence with its checkout form.
	 */
	public static function render_shortcode( $atts = array() ) {
		$atts = self::atts( $atts );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		if ( $atts['occurrence'] ) {
			return self::render_detail( $atts['occurrence'], $atts, false );
		}
		$requested = absint( wp_unslash( $_GET[ self::OCC_PARAM ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only view.
		if ( $requested ) {
			return self::render_detail( $requested, $atts, true );
		}
		$rows = self::listing_rows( $atts['event'], 0, $atts['limit'] );
		$layout = 'auto' === $atts['layout'] ? ( count( $rows ) > self::auto_list_threshold() ? 'list' : 'cards' ) : $atts['layout'];
		return 'list' === $layout ? self::render_list( $rows ) : self::render_cards( $rows );
	}

	/**
	 * Listing rows (event_id, name, description, occurrence_id, starts_at, timezone, location) of
	 * upcoming published, scheduled occurrences.
	 */
	public static function listing_rows( $event_id = 0, $occurrence_id = 0, $limit = 50 ) {
		global $wpdb;
		$sql = "SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location FROM %i e INNER JOIN %i o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s";
		$args = array( $wpdb->prefix . 'wpcap_events', $wpdb->prefix . 'wpcap_occurrences', current_time( 'mysql', true ) );
		if ( $event_id ) { $sql .= ' AND e.event_id=%d'; $args[] = absint( $event_id ); }
		if ( $occurrence_id ) { $sql .= ' AND o.occurrence_id=%d'; $args[] = absint( $occurrence_id ); }
		$sql .= ' ORDER BY o.starts_at ASC,o.occurrence_id ASC LIMIT %d';
		$args[] = max( 1, min( 200, (int) $limit ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals.
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
			if ( is_scalar( $value ) && '' !== $key && ! in_array( $key, array( self::OCC_PARAM, 'wpcap_payment', 'order', 'wpcap_rt', 'wpcap_month' ), true ) && false === strpos( $url, $key . '=' ) ) {
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
	 * @param bool $from_request Opened from a list (?wpcap_occ): adds the "all dates" link.
	 */
	public static function render_detail( $occurrence_id, array $atts = array(), $from_request = true ) {
		$rows = self::listing_rows( (int) ( $atts['event'] ?? 0 ), $occurrence_id, 1 );
		$back = '';
		if ( $from_request ) {
			$back = '<p class="wpcap-back"><a href="' . esc_url( self::page_url() ) . '">' . esc_html__( '← Alle Termine', 'pass-captain' ) . '</a></p>';
		}
		if ( ! $rows ) {
			return '<div class="wpcap-events wpcap-events-detail">' . $back . '<p>' . esc_html__( 'Dieser Termin ist nicht (mehr) buchbar.', 'pass-captain' ) . '</p></div>';
		}
		$picker = $from_request ? self::series_picker( (int) $rows[0]->event_id, (int) $rows[0]->occurrence_id ) : '';
		return '<div class="wpcap-events-detail">' . $back . $picker . self::render_cards( $rows ) . '</div>';
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
		$now = current_time( 'mysql', true );
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
	 * @param object[] $rows Listing rows.
	 */
	public static function render_list( array $rows ) {
		$translations = self::translations( array_values( array_unique( array_map( static function ( $row ) { return (int) $row->event_id; }, $rows ) ) ), determine_locale() );
		list( $status, $starts ) = self::occurrence_states( array_map( static function ( $row ) { return (int) $row->occurrence_id; }, $rows ) );
		$events = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $status[ (int) $row->occurrence_id ] ) ) { continue; } // No ticket types on sale or announced.
			$events[ (int) $row->event_id ][] = $row;
		}
		$level = min( 6, max( 2, (int) apply_filters( 'wpcap_event_card_heading_level', 2 ) ) );
		$out = '<div class="wpcap-events wpcap-events-list">';
		if ( ! $events ) {
			$out .= '<p>' . esc_html__( 'Aktuell sind keine Veranstaltungen verfügbar.', 'pass-captain' ) . '</p>';
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
			foreach ( array( 'wp_pass_captain_events', 'wp-pass-captain/events' ) as $needle ) {
				foreach ( (array) get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 's' => $needle, 'numberposts' => 20, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) ) as $post ) {
					$content = (string) $post->post_content;
					$shortcode = function_exists( 'has_shortcode' ) ? has_shortcode( $content, 'wp_pass_captain_events' ) : false !== strpos( $content, '[wp_pass_captain_events' );
					$block = false !== strpos( $content, '<!-- wp:wp-pass-captain/events' );
					// A page that shows one fixed date (occurrence attribute) is no list page.
					if ( ( $shortcode || $block ) && ! preg_match( '/\[wp_pass_captain_events[^\]]*occurrence=|"occurrenceId":\s*[1-9]/', $content ) ) {
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
			// Types whose sales start later are shown too (with a countdown, not selectable), so a cached page
			// already contains them when the sale opens; the checkout script enables them live.
			$schema = Orders::schema_at_least( '3.17.5' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- generated placeholders, literal column list.
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp,sales_start' . ( $schema ? ',description,attendee_name' : '' ) . " FROM %i WHERE occurrence_id IN (" . implode( ',', array_fill( 0, count( $occ_ids ), '%d' ) ) . ") AND status='active' AND (sales_end IS NULL OR sales_end >= %s) ORDER BY " . ( $schema ? 'position,' : '' ) . 'ticket_type_id', array_merge( array( $p . 'wpcap_ticket_types' ), $occ_ids, array( $now ) ) ) ) as $type ) {
				$type->upcoming = $type->sales_start && $type->sales_start > $now;
				$type->sales_start_iso = $type->upcoming ? gmdate( 'c', (int) strtotime( $type->sales_start . ' UTC' ) ) : '';
				$type->description = (string) ( $type->description ?? '' );
				$type->attendee_name = Orders::attendee_name_mode( $type->attendee_name ?? '' );
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- generated placeholders.
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_type_id,field_key AS `key`,label,field_type AS type,required,purpose,consent_text,consent_version' . ( Orders::schema_at_least( '3.17.5' ) ? ',options' : '' ) . " FROM %i WHERE ticket_type_id IN (" . implode( ',', array_fill( 0, count( $type_ids ), '%d' ) ) . ") AND status='active' ORDER BY position,field_id", array_merge( array( $p . 'wpcap_participant_fields' ), $type_ids ) ), ARRAY_A ) as $field ) {
				$type_id = (int) $field['ticket_type_id'];
				unset( $field['ticket_type_id'] );
				// select/radio: the choices as a list; other types carry no options.
				if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) { $field['options'] = Orders::field_options( $field ); } else { unset( $field['options'] ); }
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
						$type->max = $type->upcoming ? 0 : ( null === $type->available ? (int) $type->max_per_order : max( 0, min( (int) $type->max_per_order, (int) $type->available ) ) );
						// Waitlist sign-up for sold-out types (filter wpcap_waitlist_frontend, default on).
						$type->waitlist = ! $type->upcoming && $type->max < 1 && (bool) apply_filters( 'wpcap_waitlist_frontend', true, $event, $type );
					}
					$currency = (string) $types[0]->currency;
					if ( ! isset( $providers_by_currency[ $currency ] ) ) { $providers_by_currency[ $currency ] = Payments\Registry::enabled( $currency ); }
					$providers = $providers_by_currency[ $currency ];
					$template = locate_template( 'wp-pass-captain/event-card.php' );
					if ( ! $template ) { $template = WPCAP_PATH . 'templates/event-card.php'; }
					ob_start();
					include $template;
					$card_html = ob_get_clean();
					echo apply_filters( 'wp_pass_captain_event_card_html', $card_html, $event, $types ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes all output; trusted theme/plugin filter can customize markup.
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
