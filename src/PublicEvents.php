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
			'i18n' => array(
				'selectTickets' => __( 'Bitte wähle mindestens ein Ticket.', 'wp-pass-captain' ),
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
				'email' => __( 'E-Mail (optional)', 'wp-pass-captain' ),
				/* translators: %s: formatted amount */
				'estimate' => __( 'Summe: %s (vorbehaltlich Rabatt; der endgültige Preis wird bei der Bestellung berechnet)', 'wp-pass-captain' ),
				/* translators: %s: formatted amount */
				'orderTotal' => __( 'Gesamtbetrag: %s.', 'wp-pass-captain' ),
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
			'checking' => __( 'Ticket wird geprüft …', 'wp-pass-captain' ),
			'invalid' => __( 'Ticket nicht gültig.', 'wp-pass-captain' ),
			'noCamera' => __( 'Kamera-QR-Scan wird von diesem Browser nicht unterstützt. Nutze die manuelle Eingabe.', 'wp-pass-captain' ),
			'cameraError' => __( 'Kamerazugriff nicht möglich:', 'wp-pass-captain' ),
		) ) );
	}

	public static function render_shortcode() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.event_id,e.name,e.description,o.occurrence_id,o.starts_at,o.timezone,o.location FROM {$wpdb->prefix}wpcap_events e INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.event_id=e.event_id WHERE e.status='published' AND o.status='scheduled' AND o.starts_at >= %s ORDER BY o.starts_at ASC LIMIT 50",
			current_time( 'mysql', true )
		) );
		wp_enqueue_script( 'wpcap-checkout' );
		wp_enqueue_style( 'wpcap-public' );
		ob_start();
		?>
		<div class="wpcap-events">
			<?php if ( ! $rows ) : ?><p><?php esc_html_e( 'Aktuell sind keine Veranstaltungen verfügbar.', 'wp-pass-captain' ); ?></p><?php endif; ?>
			<?php foreach ( $rows as $event ) :
				$translation = $wpdb->get_row( $wpdb->prepare( "SELECT name,description,location FROM {$wpdb->prefix}wpcap_event_translations WHERE event_id=%d AND locale=%s", $event->event_id, determine_locale() ) );
				if ( $translation ) {
					$event->name = $translation->name;
					$event->description = $translation->description;
					$event->location = $translation->location ?: $event->location;
				}
				$now = current_time( 'mysql', true );
				$types = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_type_id,occurrence_id,name,price_minor,currency,capacity,max_per_order,tax_rate_bp FROM {$wpdb->prefix}wpcap_ticket_types WHERE occurrence_id=%d AND status='active' AND (sales_start IS NULL OR sales_start <= %s) AND (sales_end IS NULL OR sales_end >= %s) ORDER BY ticket_type_id", $event->occurrence_id, $now, $now ) );
				if ( ! $types ) { continue; }
				$occurrence = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_occurrences WHERE occurrence_id=%d", $event->occurrence_id ) );
				foreach ( $types as $type ) {
					// Effective server-side price (e.g. series price tiers); the checkout charges the same.
					$type->base_price_minor = (int) $type->price_minor;
					$type->price_label = Orders::price_label( $type, $occurrence, array( 'now' => $now ) );
					$type->price_minor = Orders::unit_price( $type, $occurrence, 1, array( 'now' => $now ) );
					$type->available = Orders::availability( $type->ticket_type_id );
					// Only field definitions are exposed (no answers); used to render attendee inputs.
					$type->fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_key AS `key`,label,field_type AS type,required,purpose FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' ORDER BY position,field_id", $type->ticket_type_id ), ARRAY_A );
				}
				$providers = Payments\Registry::enabled( $types[0]->currency );
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

	public static function render_scanner_shortcode() {
		if ( ! is_user_logged_in() || ! current_user_can( 'wpcap_checkin' ) ) {
			return '<p>' . esc_html__( 'Für den Scanner ist eine Anmeldung mit Einlassberechtigung erforderlich.', 'wp-pass-captain' ) . '</p>';
		}
		wp_enqueue_script( 'wpcap-scanner' );
		wp_enqueue_style( 'wpcap-public' );
		return '<section class="wpcap-scanner"><h2>' . esc_html__( 'Pass Captain Check-in', 'wp-pass-captain' ) . '</h2><p>' . esc_html__( 'Scanne den Ticketcode oder gib ihn manuell ein.', 'wp-pass-captain' ) . '</p><video class="wpcap-camera" playsinline hidden></video><button type="button" class="wpcap-camera-start">' . esc_html__( 'Kamera starten', 'wp-pass-captain' ) . '</button><form class="wpcap-scan-form"><label>' . esc_html__( 'Ticket-Code', 'wp-pass-captain' ) . ' <input name="token" type="text" inputmode="text" autocomplete="off" required></label><button type="submit">' . esc_html__( 'Ticket prüfen und einchecken', 'wp-pass-captain' ) . '</button></form><p class="wpcap-scan-result" role="status" aria-live="polite"></p></section>';
	}
}
