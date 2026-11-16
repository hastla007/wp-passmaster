<?php
/**
 * Public booking page for service time slots (A268): shortcode [wpcap_service_booking event="ID"
 * resource="ID"] and block pass-captain/service-booking.
 *
 * The server renders the bookable resources (price, deadlines) and the first week with free slots
 * of the preselected resource; assets/service-booking.js handles week navigation, slot and quantity
 * choice, the customer data step and the booking itself through the public REST routes
 * (GET /events/{id}/service-slots, POST /service-slots/{id}/bookings, the booking-token routes
 * /service-bookings/{id}[/confirm|/rebook|/cancel]). Paid resources go through the regular
 * checkout (ServiceBookingFlow::checkout(): payment provider redirect or bank transfer details),
 * free ones are confirmed at once. Afterwards the page shows a manage link
 * (…#wpcap-booking=<id>-<token>; the token stays in the URL fragment and never reaches the server
 * in a URL) under which the same block shows the booking with rebook and cancel options.
 *
 * Script and styles are only enqueued where the shortcode/block renders an interactive form.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Frontend;

use PassCaptain\Orders;
use PassCaptain\Payments\Registry;
use PassCaptain\PublicEvents;
use PassCaptain\ServiceBookingFlow;
use PassCaptain\ServiceBookings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ServiceBooking {
	const SHORTCODE = 'wpcap_service_booking';
	const BLOCK = 'pass-captain/service-booking';
	const HANDLE = 'wpcap-service-booking';
	/** Upper bound of places per booking (ServiceBookings::reserve() clamps to 20). */
	const MAX_QUANTITY = 20;

	/** Whether this request rendered an interactive booking form (assets enqueued). */
	private static $enqueued = false;
	/** Instance counter for unique HTML ids. */
	private static $instance = 0;

	public static function register() {
		if ( function_exists( 'add_shortcode' ) ) { add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) ); }
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) { return; }
		$block = register_block_type( WPCAP_PATH . 'src/blocks/service-booking' );
		if ( $block && ! empty( $block->editor_script_handles ) && is_admin() ) {
			add_action( 'enqueue_block_editor_assets', static function () use ( $block ) {
				wp_add_inline_script( $block->editor_script_handles[0], 'window.WPCAP_BLOCK_SERVICE=' . wp_json_encode( self::block_choices() ) . ';', 'before' );
			} );
		}
	}

	/** Registers (not enqueues) the script with its REST base, number format and string catalogue. */
	public static function register_assets() {
		if ( ! function_exists( 'wp_register_script' ) ) { return; }
		wp_register_script( self::HANDLE, WPCAP_URL . 'assets/service-booking.js', array(), WPCAP_VERSION, true );
		wp_localize_script( self::HANDLE, 'WPCAP_SERVICE_BOOKING', array(
			'restUrl' => esc_url_raw( rest_url( 'pass-captain/v1/' ) ),
			'money' => Format::js_config(),
			'i18n' => self::i18n(),
		) );
	}

	/** Strings of assets/service-booking.js (German source strings, text domain pass-captain). */
	public static function i18n() {
		return array(
			'loading' => __( 'Zeitfenster werden geladen …', 'pass-captain' ),
			'loadFailed' => __( 'Die Zeitfenster konnten nicht geladen werden. Bitte versuche es erneut.', 'pass-captain' ),
			'noSlotsWeek' => __( 'In dieser Woche sind keine Zeitfenster frei. Wähle eine andere Woche.', 'pass-captain' ),
			'noSlotsResource' => __( 'Für dieses Angebot sind derzeit keine Zeitfenster frei.', 'pass-captain' ),
			/* translators: 1: first day of the week, 2: last day of the week */
			'weekRange' => __( 'Woche vom %1$s bis %2$s', 'pass-captain' ),
			/* translators: 1: week, e.g. "Woche vom 16. November bis 22. November", 2: number of free slots */
			'weekAnnounce' => __( '%1$s: %2$d freie Zeitfenster.', 'pass-captain' ),
			/* translators: %s: week, e.g. "Woche vom 16. November bis 22. November" */
			'weekAnnounceNone' => __( '%s: keine freien Zeitfenster.', 'pass-captain' ),
			'freeOne' => __( '1 Platz frei', 'pass-captain' ),
			/* translators: %d: number of free places (2 or more) */
			'freeMany' => __( '%d Plätze frei', 'pass-captain' ), // n>1: service-booking.js uses freeOne for 1
			/* translators: 1: start time, 2: end time */
			'timeRange' => __( '%1$s–%2$s', 'pass-captain' ),
			'selectSlot' => __( 'Bitte wähle ein Zeitfenster.', 'pass-captain' ),
			/* translators: %d: maximum number of places */
			'errQuantity' => __( 'Bitte wähle zwischen 1 und %d Plätzen.', 'pass-captain' ),
			'errEmail' => __( 'Bitte gib eine gültige E-Mail-Adresse ein, z. B. name@example.com.', 'pass-captain' ),
			/* translators: %s: field label */
			'errRequired' => __( 'Bitte fülle „%s“ aus.', 'pass-captain' ),
			'errTerms' => __( 'Bitte bestätige die Bedingungen, um zu buchen.', 'pass-captain' ),
			'errorSummary' => __( 'Bitte prüfe deine Angaben', 'pass-captain' ),
			/* translators: 1: resource, 2: date and time, 3: number of places */
			'summary' => __( '%1$s · %2$s · %3$s', 'pass-captain' ),
			'placesOne' => __( '1 Platz', 'pass-captain' ),
			/* translators: %d: number of places (2 or more) */
			'placesMany' => __( '%d Plätze', 'pass-captain' ), // n>1: service-booking.js uses placesOne for 1
			/* translators: %s: formatted amount */
			'estimate' => __( 'Summe: %s (der endgültige Preis wird bei der Buchung berechnet)', 'pass-captain' ),
			'submitting' => __( 'Buchung wird angelegt …', 'pass-captain' ),
			'failed' => __( 'Die Buchung ist fehlgeschlagen. Bitte versuche es erneut.', 'pass-captain' ),
			'slotGone' => __( 'Dieses Zeitfenster ist inzwischen nicht mehr frei. Die Liste wurde aktualisiert; bitte wähle ein anderes.', 'pass-captain' ),
			// Confirmation.
			'confirmedTitle' => __( 'Dein Termin ist gebucht', 'pass-captain' ),
			'pendingTitle' => __( 'Fast geschafft: Bitte bezahle deinen Termin', 'pass-captain' ),
			/* translators: %s: e-mail address */
			'sentTo' => __( 'Die Bestätigung haben wir an %s gesendet.', 'pass-captain' ),
			/* translators: %s: short order number */
			'orderNumber' => __( 'Bestellnummer: %s', 'pass-captain' ),
			/* translators: %s: formatted amount */
			'orderTotal' => __( 'Gesamtbetrag: %s', 'pass-captain' ),
			'payNow' => __( 'Bitte überweise den Betrag mit diesen Angaben:', 'pass-captain' ),
			/* translators: %s: date and time */
			'payUntil' => __( 'Dein Termin ist bis %s reserviert. Geht die Zahlung bis dahin nicht ein, verfällt die Reservierung.', 'pass-captain' ),
			'payRedirect' => __( 'Dein Termin ist für kurze Zeit reserviert. Schließe die Zahlung jetzt ab, um ihn verbindlich zu buchen.', 'pass-captain' ),
			'payButton' => __( 'Weiter zur Zahlung', 'pass-captain' ),
			'afterPayment' => __( 'Nach Zahlungseingang ist dein Termin bestätigt; du erhältst eine E-Mail.', 'pass-captain' ),
			'amount' => __( 'Betrag', 'pass-captain' ),
			'recipient' => __( 'Empfänger', 'pass-captain' ),
			'iban' => __( 'IBAN', 'pass-captain' ),
			'bic' => __( 'BIC', 'pass-captain' ),
			'remittance' => __( 'Verwendungszweck', 'pass-captain' ),
			'dueDate' => __( 'Zahlbar bis', 'pass-captain' ),
			'manageLink' => __( 'Termin ansehen, umbuchen oder stornieren', 'pass-captain' ),
			'manageHint' => __( 'Speichere diesen Link: Nur damit kannst du deinen Termin später ändern.', 'pass-captain' ),
			'bookAnother' => __( 'Weiteren Termin buchen', 'pass-captain' ),
			// Manage view.
			'manageTitle' => __( 'Deine Buchung', 'pass-captain' ),
			'manageLoading' => __( 'Buchung wird geladen …', 'pass-captain' ),
			'manageDenied' => __( 'Der Link zu dieser Buchung ist ungültig oder abgelaufen.', 'pass-captain' ),
			'status' => __( 'Status', 'pass-captain' ),
			'when' => __( 'Termin', 'pass-captain' ),
			'places' => __( 'Plätze', 'pass-captain' ),
			'statusHeld' => __( 'Reserviert, noch nicht bestätigt', 'pass-captain' ),
			'statusPending' => __( 'Zahlung offen', 'pass-captain' ),
			'statusConfirmed' => __( 'Bestätigt', 'pass-captain' ),
			'statusCancelled' => __( 'Storniert', 'pass-captain' ),
			'statusExpired' => __( 'Abgelaufen', 'pass-captain' ),
			'statusNoShow' => __( 'Nicht erschienen', 'pass-captain' ),
			'rebookLabel' => __( 'Umbuchen auf', 'pass-captain' ),
			'rebookButton' => __( 'Umbuchen', 'pass-captain' ),
			'rebookNone' => __( 'Derzeit gibt es kein anderes freies Zeitfenster zum Umbuchen.', 'pass-captain' ),
			'rebooked' => __( 'Der Termin ist umgebucht. Du erhältst eine Bestätigung per E-Mail.', 'pass-captain' ),
			/* translators: %s: deadline, e.g. "24 Stunden" */
			'rebookUntil' => __( 'Umbuchen ist bis %s vor Beginn möglich.', 'pass-captain' ),
			/* translators: %s: deadline, e.g. "24 Stunden" */
			'cancelUntil' => __( 'Kostenlos stornieren mit voller Erstattung bis %s vor Beginn.', 'pass-captain' ),
			'cancelButton' => __( 'Termin stornieren', 'pass-captain' ),
			'cancelConfirm' => __( 'Ja, Termin verbindlich stornieren', 'pass-captain' ),
			'cancelQuestion' => __( 'Möchtest du diesen Termin wirklich stornieren? Das kann nicht rückgängig gemacht werden.', 'pass-captain' ),
			'cancelKeep' => __( 'Nein, Termin behalten', 'pass-captain' ),
			'cancelled' => __( 'Der Termin ist storniert. Bezahlte Termine werden automatisch erstattet.', 'pass-captain' ),
			'working' => __( 'Einen Moment bitte …', 'pass-captain' ),
			'durationHour' => __( '1 Stunde', 'pass-captain' ),
			/* translators: %d: hours (2 or more) */
			'durationHours' => __( '%d Stunden', 'pass-captain' ), // n>1: service-booking.js uses durationHour for 1
			'durationDay' => __( '1 Tag', 'pass-captain' ),
			/* translators: %d: days (2 or more) */
			'durationDays' => __( '%d Tage', 'pass-captain' ), // n>1: service-booking.js uses durationDay for 1
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Weeks (Monday to Sunday in the event timezone).
	 * ------------------------------------------------------------------------------------------- */

	private static function zone( $event_id ) {
		try { return new \DateTimeZone( ServiceBookingFlow::timezone( $event_id ) ); } catch ( \Exception $e ) { return new \DateTimeZone( 'UTC' ); }
	}

	/** Monday (Y-m-d, event timezone) of the week containing $utc ('Y-m-d H:i:s' UTC or a timestamp). */
	public static function week_of( $utc, \DateTimeZone $zone ) {
		$ts = is_int( $utc ) ? $utc : strtotime( $utc . ' UTC' );
		$local = ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $zone );
		return $local->modify( '-' . ( (int) $local->format( 'N' ) - 1 ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * UTC range of the local week starting on $week (a Monday, Y-m-d) of the event, or null for an
	 * invalid date. Used by the REST route's `week` parameter.
	 *
	 * @return array{from:string,to:string}|null
	 */
	public static function week_range( $event_id, $week ) {
		$week = (string) $week;
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $week, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return null; }
		$zone = self::zone( absint( $event_id ) );
		$start = new \DateTimeImmutable( $week . ' 00:00:00', $zone );
		if ( '1' !== $start->format( 'N' ) ) { return null; }
		$utc = new \DateTimeZone( 'UTC' );
		return array(
			'from' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to' => $start->modify( '+7 days' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/* ---------------------------------------------------------------------------------------------
	 * Data.
	 * ------------------------------------------------------------------------------------------- */

	/** Published event row (name translated into the page language), or null. */
	private static function event( $event_id ) {
		global $wpdb;
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT event_id,name FROM {$wpdb->prefix}wpcap_events WHERE event_id=%d AND status='published'", absint( $event_id ) ) );
		if ( ! $event ) { return null; }
		$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}wpcap_event_translations WHERE event_id=%d AND locale=%s", (int) $event->event_id, determine_locale() ) );
		if ( '' !== $name ) { $event->name = $name; }
		return $event;
	}

	/**
	 * Active resources of the event (optionally only $only) prepared for the page: price, quantity
	 * limit, deadlines and the first and last week with slots.
	 *
	 * @return array[] resource_id => data.
	 */
	public static function resources( $event_id, $only = 0, ?\DateTimeZone $zone = null ) {
		global $wpdb;
		$zone = $zone ? $zone : self::zone( $event_id );
		$out = array();
		foreach ( ServiceBookings::resources( $event_id ) as $resource ) {
			if ( 'active' !== $resource->status || ( $only && (int) $resource->resource_id !== (int) $only ) ) { continue; }
			$id = (int) $resource->resource_id;
			$type = null;
			$type_id = absint( $resource->ticket_type_id ?? 0 );
			if ( $type_id && ServiceBookings::flow_ready() ) {
				$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $type_id ) );
			}
			$price = $type ? (int) Orders::unit_price( $type, null, 1 ) : 0;
			$currency = $type ? (string) $type->currency : 'EUR';
			$max = min( self::MAX_QUANTITY, max( 1, (int) $resource->capacity ) );
			if ( $type && (int) $type->max_per_order > 0 ) { $max = min( $max, (int) $type->max_per_order ); }
			$first = ServiceBookings::public_slots( $event_id, array( 'resource_id' => $id, 'limit' => 1 ) );
			$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(starts_at) FROM {$wpdb->prefix}wpcap_service_slots WHERE resource_id=%d AND status='open' AND starts_at>%s", $id, \PassCaptain\Clock::mysql() ) );
			$rules = ServiceBookings::rules( $resource );
			$notes = array();
			if ( $rules['cancel_hours'] ) {
				/* translators: %s: deadline, e.g. "24 Stunden" */
				$notes[] = sprintf( __( 'Kostenlos stornierbar bis %s vor Beginn.', 'pass-captain' ), ServiceBookings::duration_label( $rules['cancel_hours'] * 60 ) );
			}
			if ( $rules['lead_minutes'] ) {
				/* translators: %s: lead time, e.g. "24 Stunden" */
				$notes[] = sprintf( __( 'Buchbar bis %s vor Beginn.', 'pass-captain' ), ServiceBookings::duration_label( $rules['lead_minutes'] ) );
			}
			$out[ $id ] = array(
				'id' => $id,
				'name' => (string) $resource->name,
				'paid' => $type && $price > 0,
				'priced' => (bool) $type,
				'priceMinor' => $price,
				'currency' => $currency,
				/* translators: %s: price per place */
				'priceLabel' => $type && $price > 0 ? sprintf( __( '%s pro Platz', 'pass-captain' ), Format::money( $price, $currency ) ) : __( 'kostenlos', 'pass-captain' ),
				'maxQty' => $max,
				'notes' => implode( ' ', $notes ),
				'firstWeek' => $first ? self::week_of( (string) $first[0]['starts_at'], $zone ) : '',
				'lastWeek' => '' !== $last ? self::week_of( $last, $zone ) : '',
			);
		}
		return $out;
	}

	/** Whether $content contains the booking shortcode or block. */
	public static function content_has_form( $content ) {
		return false !== strpos( (string) $content, '[' . self::SHORTCODE ) || false !== strpos( (string) $content, '<!-- wp:' . self::BLOCK );
	}

	/** Event id the first booking shortcode/block in $content shows (0 when none or without event). */
	public static function content_event( $content ) {
		$content = (string) $content;
		if ( preg_match( '/\[' . self::SHORTCODE . '\b[^\]]*\bevent\s*=\s*["\']?(\d+)/', $content, $m ) ) { return (int) $m[1]; }
		if ( preg_match( '/<!-- wp:' . preg_quote( self::BLOCK, '/' ) . '\s+\{[^}]*"eventId"\s*:\s*(\d+)/', $content, $m ) ) { return (int) $m[1]; }
		return 0;
	}

	/**
	 * Public page showing the booking form of $event_id (first published page/post with the shortcode
	 * or block for that event), '' when none. Filter `wpcap_service_booking_page_url`.
	 */
	public static function page_url( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		$url = '';
		if ( ! empty( $wpdb->posts ) && function_exists( 'get_permalink' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type NOT IN ('revision','nav_menu_item','wp_block') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID LIMIT 50", '%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%', '%' . $wpdb->esc_like( '<!-- wp:' . self::BLOCK ) . '%' ) );
			foreach ( (array) $rows as $row ) {
				if ( self::content_event( (string) $row->post_content ) === $event_id ) {
					$url = (string) get_permalink( (int) $row->ID );
					break;
				}
			}
		}
		return (string) apply_filters( 'wpcap_service_booking_page_url', $url, $event_id );
	}

	/** Events with active resources for the block editor (event and resource selection). */
	public static function block_choices() {
		global $wpdb;
		$p = $wpdb->prefix;
		$rows = $wpdb->get_results( "SELECT e.event_id,e.name AS event_name,r.resource_id,r.name FROM {$p}wpcap_events e INNER JOIN {$p}wpcap_resources r ON r.event_id=e.event_id WHERE e.status='published' AND r.status='active' ORDER BY e.name,e.event_id,r.name,r.resource_id LIMIT 500" );
		$events = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->event_id;
			if ( ! isset( $events[ $id ] ) ) { $events[ $id ] = array( 'id' => $id, 'label' => wp_specialchars_decode( (string) $row->event_name, ENT_QUOTES ) . ' (#' . $id . ')', 'resources' => array() ); }
			$events[ $id ]['resources'][] = array( 'id' => (int) $row->resource_id, 'label' => wp_specialchars_decode( (string) $row->name, ENT_QUOTES ) );
		}
		return array( 'events' => array_values( $events ) );
	}

	/* ---------------------------------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------------------------------- */

	/** Whether this request rendered a booking form (and enqueued its assets). */
	public static function assets_enqueued() {
		return self::$enqueued;
	}

	private static function enqueue() {
		self::$enqueued = true;
		if ( function_exists( 'wp_enqueue_script' ) ) { wp_enqueue_script( self::HANDLE ); }
		if ( function_exists( 'wp_enqueue_style' ) ) { wp_enqueue_style( 'wpcap-public' ); }
	}

	public static function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'event' => 0, 'resource' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return self::render( absint( $atts['event'] ), absint( $atts['resource'] ) );
	}

	/** Block render callback (render.php): the block wrapper attributes go on the root element. */
	public static function render_block( array $attributes ) {
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'wpcap-service-booking' ) ) : '';
		return self::render( absint( $attributes['eventId'] ?? 0 ), absint( $attributes['resourceId'] ?? 0 ), $wrapper );
	}

	private static function notice( $text, $wrapper ) {
		if ( function_exists( 'wp_enqueue_style' ) ) { wp_enqueue_style( 'wpcap-public' ); }
		return '<div ' . ( '' !== $wrapper ? $wrapper : 'class="wpcap-service-booking"' ) . '><p class="wpcap-sb-notice">' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Booking UI of an event (optionally one resource). Without a published event, active resources
	 * or free slots a short notice is rendered instead (no script).
	 *
	 * @param int    $event_id    Event.
	 * @param int    $resource_id Only this resource (0 = all active resources of the event).
	 * @param string $wrapper     Block wrapper attributes (escaped attribute string) or ''.
	 */
	public static function render( $event_id, $resource_id = 0, $wrapper = '' ) {
		global $wpdb;
		$event = $event_id ? self::event( $event_id ) : null;
		if ( ! $event ) {
			return self::notice( __( 'Dieses Angebot ist derzeit nicht buchbar.', 'pass-captain' ), $wrapper );
		}
		$event_id = (int) $event->event_id;
		$zone = self::zone( $event_id );
		$resources = self::resources( $event_id, $resource_id, $zone );
		if ( ! $resources ) {
			return self::notice( __( 'Derzeit sind keine Termine buchbar.', 'pass-captain' ), $wrapper );
		}
		$bookable = array_filter( $resources, static function ( $r ) { return '' !== $r['firstWeek']; } );
		if ( ! $bookable ) {
			// Open future slots exist but none has room (or a rule blocks them): sold out.
			$in = implode( ',', array_map( 'intval', array_keys( $resources ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_service_slots WHERE resource_id IN ($in) AND status='open' AND starts_at>%s", \PassCaptain\Clock::mysql() ) );
			return self::notice( $open ? __( 'Alle Termine sind derzeit ausgebucht. Schau später noch einmal vorbei.', 'pass-captain' ) : __( 'Derzeit sind keine Termine buchbar.', 'pass-captain' ), $wrapper );
		}
		self::enqueue();
		$current = reset( $bookable );
		$week = $current['firstWeek'];
		$range = self::week_range( $event_id, $week );
		$slots = ServiceBookings::public_slots( $event_id, array( 'resource_id' => $current['id'] ) + (array) $range );
		$level = min( 5, max( 2, (int) apply_filters( 'wpcap_service_booking_heading_level', 2, $event_id ) ) );
		$id = 'wpcap-sb-' . ( ++self::$instance );
		$any_paid = (bool) array_filter( $resources, static function ( $r ) { return $r['priced']; } );
		$config = array(
			'event' => $event_id,
			'tz' => $zone->getName(),
			'locale' => str_replace( '_', '-', determine_locale() ),
			'pageId' => function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0,
			'todayWeek' => self::week_of( \PassCaptain\Clock::now(), $zone ),
			'level' => $level,
			'resources' => array_values( $resources ),
			'resource' => $current['id'],
			'week' => $week,
		);
		$h = static function ( $offset ) use ( $level ) { return 'h' . min( 6, $level + $offset ); };

		$html = '<div ' . ( '' !== $wrapper ? $wrapper : 'class="wpcap-service-booking"' ) . ' id="' . esc_attr( $id ) . '" data-wpcap-sb="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		$html .= '<' . $h( 0 ) . ' class="wpcap-sb-title">' . esc_html( (string) $event->name ) . '</' . $h( 0 ) . '>';
		$html .= '<form class="wpcap-sb-form" method="post" action="#' . esc_attr( $id ) . '" novalidate>';

		// Step 1: resource, week, slot, places.
		$html .= '<section class="wpcap-sb-step wpcap-sb-step-slot" aria-labelledby="' . esc_attr( $id . '-s1' ) . '">';
		$html .= '<' . $h( 1 ) . ' id="' . esc_attr( $id . '-s1' ) . '" tabindex="-1">' . esc_html__( '1. Termin wählen', 'pass-captain' ) . '</' . $h( 1 ) . '>';
		if ( count( $resources ) > 1 ) {
			$html .= '<fieldset class="wpcap-sb-resources"><legend>' . esc_html__( 'Angebot', 'pass-captain' ) . '</legend>';
			foreach ( $resources as $resource ) {
				$rid = $id . '-r' . $resource['id'];
				$described = '' !== $resource['notes'] ? ' aria-describedby="' . esc_attr( $rid . '-notes' ) . '"' : '';
				$html .= '<div class="wpcap-sb-resource"><input type="radio" name="resource_id" id="' . esc_attr( $rid ) . '" value="' . esc_attr( (string) $resource['id'] ) . '"' . ( $resource['id'] === $current['id'] ? ' checked' : '' ) . $described . '>';
				$html .= '<label for="' . esc_attr( $rid ) . '"><span class="wpcap-sb-resource-name">' . esc_html( $resource['name'] ) . '</span> <span class="wpcap-sb-price">' . esc_html( $resource['priceLabel'] ) . '</span>';
				if ( '' === $resource['firstWeek'] ) { $html .= ' <span class="wpcap-sb-soldout">' . esc_html__( 'derzeit ausgebucht', 'pass-captain' ) . '</span>'; }
				$html .= '</label>';
				if ( '' !== $resource['notes'] ) { $html .= '<small id="' . esc_attr( $rid . '-notes' ) . '">' . esc_html( $resource['notes'] ) . '</small>'; }
				$html .= '</div>';
			}
			$html .= '</fieldset>';
		} else {
			$html .= '<input type="hidden" name="resource_id" value="' . esc_attr( (string) $current['id'] ) . '">';
			$html .= '<p class="wpcap-sb-resource-info"><strong>' . esc_html( $current['name'] ) . '</strong> · <span class="wpcap-sb-price">' . esc_html( $current['priceLabel'] ) . '</span>' . ( '' !== $current['notes'] ? '<br><small>' . esc_html( $current['notes'] ) . '</small>' : '' ) . '</p>';
		}
		$html .= '<fieldset class="wpcap-sb-slots"><legend>' . esc_html__( 'Zeitfenster', 'pass-captain' ) . '</legend>';
		$html .= '<div class="wpcap-sb-week-nav">';
		$html .= '<button type="button" class="wpcap-sb-prev" aria-controls="' . esc_attr( $id . '-days' ) . '" aria-disabled="' . ( $week <= $config['todayWeek'] ? 'true' : 'false' ) . '"><span aria-hidden="true">‹</span> ' . esc_html__( 'Vorherige Woche', 'pass-captain' ) . '</button>';
		$html .= '<p class="wpcap-sb-week" id="' . esc_attr( $id . '-week' ) . '">' . esc_html( self::week_label( $week ) ) . '</p>';
		$html .= '<button type="button" class="wpcap-sb-next" aria-controls="' . esc_attr( $id . '-days' ) . '" aria-disabled="' . ( $week >= $current['lastWeek'] ? 'true' : 'false' ) . '">' . esc_html__( 'Nächste Woche', 'pass-captain' ) . ' <span aria-hidden="true">›</span></button>';
		$html .= '</div>';
		$html .= '<div class="wpcap-sb-days" id="' . esc_attr( $id . '-days' ) . '" aria-describedby="' . esc_attr( $id . '-week' ) . '">' . self::slots_html( $slots, $zone, $id, $h( 2 ) ) . '</div>';
		$html .= '</fieldset>';
		$html .= '<div class="wpcap-field wpcap-sb-qty-field"><label for="' . esc_attr( $id . '-qty' ) . '">' . esc_html__( 'Anzahl Plätze', 'pass-captain' ) . '</label>';
		$html .= '<input id="' . esc_attr( $id . '-qty' ) . '" class="wpcap-sb-qty" name="quantity" type="number" inputmode="numeric" min="1" max="' . esc_attr( (string) $current['maxQty'] ) . '" value="1" required></div>';
		$html .= '<p class="wpcap-sb-estimate" aria-live="polite"></p>';
		$html .= '<button type="button" class="wp-element-button wpcap-sb-continue">' . esc_html__( 'Weiter zu deinen Angaben', 'pass-captain' ) . '</button>';
		$html .= '</section>';

		// Step 2: customer data (and for paid resources payment, invoice data, terms).
		$html .= '<section class="wpcap-sb-step wpcap-sb-step-data" aria-labelledby="' . esc_attr( $id . '-s2' ) . '" hidden>';
		$html .= '<' . $h( 1 ) . ' id="' . esc_attr( $id . '-s2' ) . '" tabindex="-1">' . esc_html__( '2. Deine Angaben', 'pass-captain' ) . '</' . $h( 1 ) . '>';
		$html .= '<p class="wpcap-sb-summary"></p>';
		$html .= '<div class="wpcap-field"><label for="' . esc_attr( $id . '-email' ) . '">' . esc_html__( 'E-Mail-Adresse', 'pass-captain' ) . ' *</label>';
		$html .= '<input id="' . esc_attr( $id . '-email' ) . '" name="email" type="email" autocomplete="email" maxlength="190" required aria-describedby="' . esc_attr( $id . '-email-hint' ) . '">';
		$html .= '<small id="' . esc_attr( $id . '-email-hint' ) . '">' . esc_html__( 'An diese Adresse senden wir die Bestätigung.', 'pass-captain' ) . '</small></div>';
		if ( $any_paid ) { $html .= self::paid_fields_html( $id, $resources, $current ); }
		$html .= '<p class="wpcap-message" role="status" aria-live="polite"></p>';
		$html .= '<div class="wpcap-sb-actions"><button type="button" class="wpcap-sb-back">' . esc_html__( 'Zurück', 'pass-captain' ) . '</button>';
		$html .= '<button type="submit" class="wp-element-button wpcap-sb-submit" data-label-free="' . esc_attr__( 'Termin verbindlich buchen', 'pass-captain' ) . '" data-label-paid="' . esc_attr__( 'Zahlungspflichtig buchen', 'pass-captain' ) . '">' . esc_html( $current['priced'] ? __( 'Zahlungspflichtig buchen', 'pass-captain' ) : __( 'Termin verbindlich buchen', 'pass-captain' ) ) . '</button></div>';
		$html .= '</section></form>';
		$html .= '<p class="wpcap-sb-live wpcap-sr-only" role="status" aria-live="polite" aria-atomic="true"></p>';
		$html .= '<noscript><p class="wpcap-sb-notice">' . esc_html__( 'Zum Buchen wird JavaScript benötigt. Bitte aktiviere JavaScript in deinem Browser.', 'pass-captain' ) . '</p></noscript>';
		return $html . '</div>';
	}

	/** "Woche vom 16. November 2026 bis 22. November 2026" for a Monday (Y-m-d). */
	public static function week_label( $week ) {
		$utc = new \DateTimeZone( 'UTC' );
		$start = strtotime( $week . ' 12:00:00 UTC' );
		$format = (string) get_option( 'date_format' );
		$format = '' !== $format ? $format : 'j. F Y';
		/* translators: 1: first day of the week, 2: last day of the week */
		return sprintf( __( 'Woche vom %1$s bis %2$s', 'pass-captain' ), wp_date( $format, $start, $utc ), wp_date( $format, $start + 6 * DAY_IN_SECONDS, $utc ) );
	}

	/**
	 * Slot list of one week grouped by local day (radio buttons name="slot_id"); assets/service-booking.js
	 * renders the same markup for other weeks.
	 *
	 * @param array[]       $slots   public_slots() rows.
	 * @param \DateTimeZone $zone    Event timezone.
	 * @param string        $id      Instance id prefix.
	 * @param string        $heading Heading tag of the days.
	 */
	public static function slots_html( array $slots, \DateTimeZone $zone, $id, $heading = 'h4' ) {
		if ( ! $slots ) { return '<p class="wpcap-sb-empty">' . esc_html__( 'In dieser Woche sind keine Zeitfenster frei. Wähle eine andere Woche.', 'pass-captain' ) . '</p>'; }
		$time = (string) get_option( 'time_format' );
		$time = '' !== $time ? $time : 'H:i';
		/* translators: PHP date format of a day heading in the slot list, e.g. "Montag, 16. November" */
		$day_format = __( 'l, j. F', 'pass-captain' );
		$days = array();
		foreach ( $slots as $slot ) {
			$start = strtotime( $slot['starts_at'] . ' UTC' );
			$day = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $zone )->format( 'Y-m-d' );
			$days[ $day ][] = $slot + array( 'start_ts' => $start );
		}
		$html = '';
		foreach ( $days as $day => $list ) {
			$day_id = $id . '-d-' . $day;
			$html .= '<section class="wpcap-sb-day" aria-labelledby="' . esc_attr( $day_id ) . '"><' . $heading . ' id="' . esc_attr( $day_id ) . '">' . esc_html( wp_date( $day_format, $list[0]['start_ts'], $zone ) ) . '</' . $heading . '><ul class="wpcap-sb-slot-list">';
			foreach ( $list as $slot ) {
				$slot_id = $id . '-slot-' . (int) $slot['slot_id'];
				$available = (int) $slot['available'];
				/* translators: %d: number of free places */
				$free = sprintf( _n( '%d Platz frei', '%d Plätze frei', $available, 'pass-captain' ), $available );
				/* translators: 1: start time, 2: end time */
				$range = sprintf( __( '%1$s–%2$s', 'pass-captain' ), wp_date( $time, $slot['start_ts'], $zone ), wp_date( $time, strtotime( $slot['ends_at'] . ' UTC' ), $zone ) );
				$html .= '<li class="wpcap-sb-slot"><input type="radio" name="slot_id" id="' . esc_attr( $slot_id ) . '" value="' . esc_attr( (string) (int) $slot['slot_id'] ) . '" data-available="' . esc_attr( (string) $available ) . '" data-starts="' . esc_attr( (string) $slot['starts_at'] ) . '" data-ends="' . esc_attr( (string) $slot['ends_at'] ) . '">';
				$html .= '<label for="' . esc_attr( $slot_id ) . '"><span class="wpcap-sb-time">' . esc_html( $range ) . '</span> <span class="wpcap-sb-free">' . esc_html( $free ) . '</span></label></li>';
			}
			$html .= '</ul></section>';
		}
		return $html;
	}

	/** Payment provider, invoice data and terms (shown by the script while a priced resource is selected). */
	private static function paid_fields_html( $id, array $resources, array $current ) {
		$currency = 'EUR';
		foreach ( $resources as $resource ) {
			if ( $resource['priced'] ) { $currency = $resource['currency']; break; }
		}
		$providers = Registry::enabled( $currency );
		$legal = PublicEvents::legal_context();
		$html = '<div class="wpcap-sb-paid"' . ( $current['priced'] ? '' : ' hidden' ) . '>';
		if ( count( $providers ) > 1 ) {
			$html .= '<fieldset class="wpcap-providers wpcap-field"><legend>' . esc_html__( 'Zahlungsart', 'pass-captain' ) . '</legend>';
			$first = true;
			foreach ( $providers as $provider_id => $gateway ) {
				$html .= '<label><input type="radio" name="provider" value="' . esc_attr( (string) $provider_id ) . '"' . ( $first ? ' checked' : '' ) . '> ' . esc_html( $gateway->title() ) . '</label>';
				$first = false;
			}
			$html .= '</fieldset>';
		} elseif ( $providers ) {
			$html .= '<input type="hidden" name="provider" value="' . esc_attr( (string) array_key_first( $providers ) ) . '">';
		}
		if ( apply_filters( 'wpcap_checkout_billing_enabled', true, null ) ) {
			$labels = \PassCaptain\Billing::labels();
			$threshold = \PassCaptain\Billing::required_threshold( $currency );
			$bid = $id . '-billing';
			$html .= '<fieldset class="wpcap-billing"><legend>' . esc_html__( 'Rechnung mit Firmen-/Adressdaten', 'pass-captain' ) . '</legend>';
			$html .= '<div class="wpcap-field wpcap-billing-toggle"><input id="' . esc_attr( $bid . '-enabled' ) . '" type="checkbox" name="billing_enabled" value="1" aria-controls="' . esc_attr( $bid . '-fields' ) . '"' . ( $threshold ? ' aria-describedby="' . esc_attr( $bid . '-hint' ) . '"' : '' ) . '><label for="' . esc_attr( $bid . '-enabled' ) . '">' . esc_html( $labels['enabled'] ) . '</label></div>';
			if ( $threshold ) {
				/* translators: %s: amount */
				$html .= '<p class="wpcap-billing-hint" id="' . esc_attr( $bid . '-hint' ) . '"><small>' . esc_html( sprintf( __( 'Ab einem Bestellbetrag über %s brauchen wir für die Rechnung Name und Anschrift.', 'pass-captain' ), Format::money( $threshold, $currency ) ) ) . '</small></p>';
			}
			$html .= '<div class="wpcap-billing-fields" id="' . esc_attr( $bid . '-fields' ) . '" hidden>';
			$fields = array( 'company' => array( 'organization', false ), 'first_name' => array( 'given-name', true ), 'last_name' => array( 'family-name', true ), 'street' => array( 'street-address', true ), 'postcode' => array( 'postal-code', true ), 'city' => array( 'address-level2', true ) );
			foreach ( $fields as $key => $def ) {
				$html .= '<div class="wpcap-field"><label for="' . esc_attr( $bid . '-' . $key ) . '">' . esc_html( $labels[ $key ] . ( $def[1] ? ' *' : ' ' . __( '(optional)', 'pass-captain' ) ) ) . '</label><input id="' . esc_attr( $bid . '-' . $key ) . '" name="' . esc_attr( 'billing_' . $key ) . '" type="text" autocomplete="' . esc_attr( $def[0] ) . '" maxlength="' . esc_attr( (string) \PassCaptain\Billing::MAX[ $key ] ) . '"' . ( $def[1] ? ' required' : '' ) . '></div>';
			}
			$html .= '<div class="wpcap-field"><label for="' . esc_attr( $bid . '-country' ) . '">' . esc_html( $labels['country'] ) . ' *</label><select id="' . esc_attr( $bid . '-country' ) . '" name="billing_country" autocomplete="country" required>';
			$default = \PassCaptain\Billing::default_country();
			foreach ( \PassCaptain\Billing::countries() as $code => $name ) {
				$html .= '<option value="' . esc_attr( $code ) . '"' . ( $code === $default ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
			}
			$html .= '</select></div>';
			$html .= '<div class="wpcap-field"><label for="' . esc_attr( $bid . '-vat_id' ) . '">' . esc_html( $labels['vat_id'] . ' ' . __( '(optional)', 'pass-captain' ) ) . '</label><input id="' . esc_attr( $bid . '-vat_id' ) . '" name="billing_vat_id" type="text" autocomplete="off" spellcheck="false" maxlength="24"></div>';
			$html .= '</div></fieldset>';
		}
		if ( ! empty( $legal['locale'] ) ) { $html .= '<input type="hidden" name="locale" value="' . esc_attr( $legal['locale'] ) . '">'; }
		if ( $legal['require_terms'] ) {
			if ( ! empty( $legal['terms_version'] ) ) { $html .= '<input type="hidden" name="terms_version" value="' . esc_attr( $legal['terms_version'] ) . '">'; }
			if ( '' !== trim( (string) $legal['terms_text'] ) ) { $html .= '<details class="wpcap-legal"><summary>' . esc_html__( 'Bedingungen', 'pass-captain' ) . '</summary>' . wp_kses_post( wpautop( $legal['terms_text'] ) ) . '</details>'; }
			$has_info = '' !== trim( (string) $legal['consumer_info_text'] );
			if ( $has_info ) { $html .= '<details class="wpcap-legal"><summary>' . esc_html__( 'Verbraucherinformationen', 'pass-captain' ) . '</summary>' . wp_kses_post( wpautop( $legal['consumer_info_text'] ) ) . '</details>'; }
			$html .= '<div class="wpcap-field wpcap-terms"><input id="' . esc_attr( $id . '-terms' ) . '" type="checkbox" name="accept_terms" value="1" required><label for="' . esc_attr( $id . '-terms' ) . '">' . esc_html( $has_info ? __( 'Ich akzeptiere die Bedingungen und habe die Verbraucherinformationen gelesen.', 'pass-captain' ) : __( 'Ich akzeptiere die Bedingungen.', 'pass-captain' ) ) . '</label></div>';
		}
		return $html . '</div>';
	}
}
