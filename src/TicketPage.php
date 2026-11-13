<?php
/** Private ticket display page keyed by a high-entropy ticket secret. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketPage {
	public static function register() {
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = 'wpcap_ticket'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		OrderPortal::register();
	}

	public static function maybe_render() {
		$token = get_query_var( 'wpcap_ticket' );
		if ( ! $token ) { return; }
		$token = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $token ) );
		if ( 64 !== strlen( $token ) ) { status_header( 404 ); exit; }
		global $wpdb;
		$ticket = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.ticket_type_id,t.status,tt.name AS ticket_name,o.occurrence_id,o.starts_at,o.timezone,o.location,e.name AS event_name FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.token_hash=%s LIMIT 1",
			hash( 'sha256', $token )
		) );
		if ( ! $ticket ) { status_header( 404 ); exit; }
		// Consent grant/withdrawal by the ticket holder (A116): POST to the same link, nonce-checked (PRG).
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_consent_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in Consents::handle_self_service().
			$result = self::handle_consent( $ticket, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( array( 'wpcap_ticket' => $token, 'wpcap_consent_result' => is_wp_error( $result ) ? 'error' : $result ), home_url( '/' ) ) . '#wpcap-consents' );
			exit;
		}
		// "Send me my order link" (A118): new portal link to the buyer, POST with nonce.
		$link_message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right here.
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_order_link'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sent = wp_verify_nonce( $nonce, 'wpcap_ticket_order_link_' . (int) $ticket->ticket_id ) ? PortalSelfService::send_order_link( $token ) : new \WP_Error( 'wpcap_nonce', __( 'Das Formular ist abgelaufen. Bitte lade die Seite neu.', 'pass-captain' ) );
			/* translators: %s: masked e-mail address */
			$link_message = is_wp_error( $sent ) ? $sent->get_error_message() : sprintf( __( 'Wir haben dir einen neuen Link zu deiner Bestellung an %s gesendet. Der bisherige Link ist damit ungültig.', 'pass-captain' ), $sent );
		}
		$link_form = '';
		$link_ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,order_id,transfer_count,attendee_email FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", (int) $ticket->ticket_id ) );
		$link_order = $link_ticket ? Orders::get( (int) $link_ticket->order_id ) : null;
		if ( $link_order && PortalSelfService::can_request_order_link( $link_ticket, $link_order ) ) {
			$link_form = '<section class="manage"><h2>' . esc_html__( 'Bestellung verwalten', 'pass-captain' ) . '</h2><p>' . esc_html__( 'Über den persönlichen Bestelllink kannst du Tickets als PDF herunterladen, Teilnehmer zuweisen und – falls angeboten – eine Stornierung anfragen. Du findest ihn in deiner Bestellbestätigung.', 'pass-captain' ) . '</p>'
				. '<form method="post"><input type="hidden" name="wpcap_order_link" value="1"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wpcap_ticket_order_link_' . (int) $ticket->ticket_id ) ) . '"><button type="submit" class="print">' . esc_html__( 'Neuen Bestelllink per E-Mail senden', 'pass-captain' ) . '</button></form>'
				. '<p><small>' . esc_html__( 'Der Link geht an die E-Mail-Adresse der Bestellung; ein früher gesendeter Bestelllink wird dabei ungültig.', 'pass-captain' ) . '</small></p></section>';
		}
		if ( '' !== $link_message ) {
			$link_form = '<p class="notice" role="status">' . esc_html( $link_message ) . '</p>' . $link_form;
		}
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		$qr = QrCode::data_uri( $token );
		$seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket->ticket_id, (array) $ticket );
		// Assigned here for tickets issued before help codes existed (lazy backfill).
		$help_code = in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) ? HelpCode::for_ticket( (int) $ticket->ticket_id, $token ) : '';
		?><!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $ticket->event_name ); ?> — <?php esc_html_e( 'Ticket', 'pass-captain' ); ?></title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:42rem;margin:2rem auto;padding:0 1rem;color:#1d2327}.ticket{border:1px solid #c3c4c7;border-radius:12px;padding:1.5rem}.qr{display:block;width:min(75vw,320px);height:auto;margin:1.5rem auto}.token{overflow-wrap:anywhere;font-family:monospace}.help-code strong{display:block;font:700 1.75rem/1.2 ui-monospace,monospace;letter-spacing:.12em}.print{padding:.6rem 1rem}.card{border:1px solid #c3c4c7;border-radius:12px;padding:1rem 1.5rem;margin:1rem 0}.muted{color:#50575e}.wpcap-consents{padding-left:1.2rem}.wpcap-consents li{margin:.75rem 0}.wpcap-consents button{padding:.55rem 1rem;min-height:2.75rem;margin-top:.25rem}.danger{background:#b32d2e;color:#fff;border:1px solid #8a1f1f;border-radius:4px}.manage{margin-top:1.5rem;border-top:1px solid #c3c4c7;padding-top:1rem}.notice{font-weight:700}@media print{.print,.card,.manage,.notice{display:none}.ticket{border:0}}</style></head><body><main class="ticket"><h1><?php echo esc_html( $ticket->event_name ); ?></h1><h2><?php echo esc_html( $ticket->ticket_name ); ?></h2><p><?php echo esc_html( Orders::format_start( $ticket->starts_at, $ticket->timezone ) ); ?></p><?php if ( '' !== $seat ) : ?><p><strong><?php echo esc_html( $seat ); ?></strong></p><?php endif; ?><?php $doors = DoorsOpen::label( DoorsOpen::for_occurrence( (int) $ticket->occurrence_id ), $ticket->timezone, $ticket->starts_at ); ?><?php if ( '' !== $doors ) : ?><p><strong><?php echo esc_html( $doors ); ?></strong></p><?php endif; ?><?php if ( $ticket->location ) : ?><p><?php echo esc_html( $ticket->location ); ?></p><?php endif; ?><p><?php echo esc_html( __( 'Status:', 'pass-captain' ) . ' ' . Admin\Forms::status_label( $ticket->status ) ); ?></p><?php if ( $qr ) : ?><img class="qr" src="<?php echo esc_attr( $qr ); ?>" alt="<?php esc_attr_e( 'Ticket QR-Code', 'pass-captain' ); ?>"><?php endif; ?><p class="token"><?php echo esc_html( $token ); ?></p><?php if ( '' !== $help_code ) : ?><p class="help-code"><?php echo esc_html( HelpCode::caption() ); ?> <strong><?php echo esc_html( $help_code ); ?></strong></p><?php endif; ?><button class="print" onclick="window.print()"><?php esc_html_e( 'Drucken oder als PDF speichern', 'pass-captain' ); ?></button><?php echo $link_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></main><?php echo self::consent_html( $ticket, isset( $_GET['wpcap_consent_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_consent_result'] ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in Consents; display flag only. ?></body></html><?php
		exit;
	}

	/** Consent card of the ticket page ('' without consent fields). */
	public static function consent_html( $ticket, $flag = '' ) {
		$row = (object) array( 'ticket_id' => (int) $ticket->ticket_id, 'public_id' => (string) $ticket->public_id, 'ticket_type_id' => (int) $ticket->ticket_type_id, 'status' => (string) $ticket->status, 'title' => (string) $ticket->event_name . ' — ' . (string) $ticket->ticket_name );
		return Consents::self_service_html( array( $row ), (string) $ticket->public_id, Consents::result_notice( $flag ) );
	}

	/**
	 * Consent grant/withdrawal by the holder of the ticket link (the caller verified the code).
	 *
	 * @return string|\WP_Error 'granted' or 'withdrawn'.
	 */
	public static function handle_consent( $ticket, array $post ) {
		return Consents::handle_self_service( array( (string) $ticket->public_id => $ticket ), $post, (string) $ticket->public_id, 'ticket_page' );
	}
}
