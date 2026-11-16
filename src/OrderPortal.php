<?php
/** Buyer order portal served through a high-entropy emailed capability link. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class OrderPortal {
	public static function register() {
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = 'wpcap_order'; $vars[] = 'wpcap_access'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		PortalSelfService::register();
	}

	public static function maybe_render() {
		$public_id = sanitize_text_field( Security\Input::text( get_query_var( 'wpcap_order' ) ) );
		if ( '' === $public_id ) { return; }
		$from_url = Security\Input::hex( get_query_var( 'wpcap_access' ) );
		// Without token in the URL: the short-lived access cookie set by the exchange below (SEC-7).
		$token = '' !== $from_url ? $from_url : Security\AccessCookie::resolve( 'order', $public_id );
		if ( '' === $token ) {
			if ( Security\AccessCookie::marker_present() ) { Security\AccessCookie::blocked_page(); }
			return;
		}
		// A283: personal page, also its 404 answers – never stored by a page cache or CDN.
		PageCache::no_store( 'portal' );
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		// A631: a pending (new) link counts as well (Orders::access_matches()).
		if ( 64 !== strlen( $token ) || ! Orders::access_matches( $order, $token ) ) { status_header( 404 ); \PassCaptain\Http::end(); }
		if ( '' !== $from_url ) {
			// Token from the mail link → cookie, 303 to the portal URL without token (falls back to rendering).
			Security\AccessCookie::exchange( 'order', (string) $order->public_id, $token, add_query_arg( array_filter( array( 'wpcap_order' => (string) $order->public_id, 'wpcap_consent' => sanitize_key( Security\Input::text( wp_unslash( $_GET['wpcap_consent'] ?? '' ) ) ) ) ), home_url( '/' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only status flag, reduced by sanitize_key().
		}
		Sales\Consents::maybe_handle_portal_post( $order, $token ); // Consent withdrawal (POST, redirects back).
		Sales\SelfService::maybe_handle_portal_post( $order, $token ); // Cancellation request (POST, redirects back).
		// Consent grant/withdrawal (A116): plain POST to the same link, nonce-checked, then back (PRG).
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_consent_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in Consents::handle_self_service().
			$result = self::handle_consent( $order, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( self::link_args( $public_id, $token ) + array( 'wpcap_consent_result' => is_wp_error( $result ) ? 'error' : $result ), home_url( '/' ) ) . '#wpcap-consents' );
			\PassCaptain\Http::end();
		}
		// Waitlist self-unsubscribe (A158): entries with the order's e-mail address, same pattern.
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_waitlist_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in Waitlists::handle_portal_request().
			$result = Waitlists::handle_portal_request( $order, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( self::link_args( $public_id, $token ) + array( 'wpcap_waitlist_result' => is_wp_error( $result ) ? 'error' : 'removed' ), home_url( '/' ) ) . '#wpcap-waitlist' );
			\PassCaptain\Http::end();
		}
		// Service bookings (A135): cancel/rebook bookings with the order's e-mail address, same pattern.
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_service_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in ServiceBookingFlow::handle_portal_request().
			$result = ServiceBookingFlow::handle_portal_request( $order, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( self::link_args( $public_id, $token ) + array( 'wpcap_service_result' => ServiceBookingFlow::result_flag( $result ) ), home_url( '/' ) ) . '#wpcap-service-bookings' );
			\PassCaptain\Http::end();
		}
		Security\PrivatePage::send(); // No page cache, no framing, no indexing (secret link).
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		// Rendered in the order's language (buyer's choice in checkout or portal, Frontend\Language).
		echo Mail\Mailer::with_locale( (string) ( $order->locale ?? '' ), static function () use ( $order, $token ) { return self::render_page( $order, $token ); } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_page.
		\PassCaptain\Http::end();
	}

	/**
	 * Query arguments of a link back to the portal: the token only while the page was opened with it in
	 * the URL (after the cookie exchange the portal URL carries no token, SEC-7).
	 */
	private static function link_args( $public_id, $token ) {
		return array( 'wpcap_order' => (string) $public_id ) + ( Security\AccessCookie::via_cookie() ? array() : array( 'wpcap_access' => (string) $token ) );
	}

	/**
	 * Consent grant/withdrawal from the portal of an order whose access token the caller verified.
	 * Tickets transferred to someone else are not the buyer's to manage.
	 *
	 * @return string|\WP_Error 'granted' or 'withdrawn'.
	 */
	public static function handle_consent( $order, array $post ) {
		$tickets = array();
		foreach ( self::consent_tickets( $order ) as $ticket ) { $tickets[ (string) $ticket->public_id ] = $ticket; }
		return Consents::handle_self_service( $tickets, $post, (string) $order->public_id, 'portal' );
	}

	/** Tickets of the order whose consents the buyer manages (not transferred). */
	private static function consent_tickets( $order ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.ticket_type_id,t.status,CONCAT(e.name,' — ',tt.name) AS title FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d AND t.transfer_count=0 ORDER BY t.ticket_id", (int) $order->order_id ) );
	}

	/** Portal HTML for an order whose access token was verified by the caller. */
	public static function render_page( $order, $token ) {
		global $wpdb;
		$public_id = (string) $order->public_id;
		$tickets = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.ticket_id,t.ticket_type_id,t.public_id,t.status,t.refunded,t.transfer_count,t.attendee_email,ta.attendee_name,tt.name AS ticket_name,e.name AS event_name,o.starts_at,o.timezone FROM {$wpdb->prefix}wpcap_tickets t LEFT JOIN {$wpdb->prefix}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.order_id=%d ORDER BY t.ticket_id",
			$order->order_id
		) );
		$edit_hours = max( 0, (int) Settings::get( 'attendee_edit_hours' ) );
		$fields = array();
		$answers = array();
		foreach ( $tickets as $ticket ) {
			if ( ! isset( $fields[ $ticket->ticket_type_id ] ) ) {
				// Consent fields have their own card (grant/withdraw, logged).
				$fields[ $ticket->ticket_type_id ] = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . ParticipantFields::columns() .  " FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' AND field_type<>'consent'" . ( Consents::ready() ? ' AND consent=0' : '' ) . ' ORDER BY position,field_id', $ticket->ticket_type_id ) );
			}
		}
		// All answers of the order's tickets in one query (A442), not one per ticket.
		$ticket_ids = array_map( static function ( $ticket ) { return (int) $ticket->ticket_id; }, (array) $tickets );
		foreach ( array_chunk( $ticket_ids, 500 ) as $chunk ) {
			foreach ( (array) $wpdb->get_results( "SELECT ticket_id,field_id,answer FROM {$wpdb->prefix}wpcap_participant_answers WHERE ticket_id IN (" . implode( ',', $chunk ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$answers[ (int) $row->ticket_id ][ (int) $row->field_id ] = $row->answer;
			}
		}
		$missing = ParticipantFields::missing( $ticket_ids, $answers ); // A682: shown per ticket (answers loaded above).
		$has_invoice = (int) $wpdb->get_var( $wpdb->prepare( "SELECT invoice_id FROM {$wpdb->prefix}wpcap_invoices WHERE order_id=%d", $order->order_id ) );
		$base = 'pass-captain/v1/orders/' . rawurlencode( $public_id );
		$reference = Frontend\Format::order_reference( $order->public_id );
		$can_resend = 'paid' === $order->payment_status && (bool) array_filter( $tickets, static function ( $ticket ) { return in_array( $ticket->status, array( 'valid', 'checked_in' ), true ); } );
		// Valid tickets already passed on (own participant e-mail or transferred): a full resend would invalidate their tickets and printouts.
		$passed_on = count( array_filter( $tickets, static function ( $ticket ) use ( $order ) { return 'valid' === $ticket->status && ( (int) $ticket->transfer_count > 0 || ( is_email( $ticket->attendee_email ) && 0 !== strcasecmp( (string) $ticket->attendee_email, (string) $order->customer_email ) ) ); } ) );
		// Valid tickets the buyer still holds: "only my own" is only offered when there is something to send.
		$own_valid = count( array_filter( $tickets, static function ( $ticket ) { return 'valid' === $ticket->status; } ) ) - $passed_on;
		$bank = Payments\BankTransfer::applies( $order ) ? Payments\BankTransfer::details( $order ) : null;
		$strings = array(
			'cancel' => __( 'Abbrechen', 'pass-captain' ),
			'transferFailed' => __( 'Übertragung fehlgeschlagen.', 'pass-captain' ),
			'transferred' => __( 'Ticket wurde übertragen. Der neue Code wurde per E-Mail versendet.', 'pass-captain' ),
			/* translators: %s: recipient e-mail address */
			'transferConfirm' => __( 'Ticket wirklich an %s übertragen? Das ist endgültig und nur einmal möglich: Der bisherige Ticketcode wird sofort ungültig und die neue Person erhält einen neuen Code per E-Mail.', 'pass-captain' ),
			'transferConfirmButton' => __( 'Ja, endgültig übertragen', 'pass-captain' ),
			'assignFailed' => __( 'Zuweisung fehlgeschlagen.', 'pass-captain' ),
			'assignedRotated' => __( 'Teilnehmer wurde zugewiesen. Ein neuer Ticketcode wurde an die Teilnehmer-E-Mail gesendet; der bisherige Code ist ungültig.', 'pass-captain' ),
			'assignedKept' => __( 'Teilnehmerdaten gespeichert. Der Ticketcode bleibt unverändert.', 'pass-captain' ),
			'resendConfirm' => __( 'Tickets jetzt erneut senden? Dabei werden neue Ticketcodes erstellt; die bisher gesendeten Codes und Ausdrucke werden ungültig.', 'pass-captain' ),
			'resendConfirmButton' => __( 'Ja, erneut senden', 'pass-captain' ),
			/* translators: %d: number of tickets passed on to other people */
			'resendConfirmAll' => __( 'Achtung: Auch die %d Tickets, die du an andere Personen weitergegeben hast, bekommen neue Codes. Deren bisherige Tickets und Ausdrucke werden sofort ungültig; die neuen Codes gehen an die Teilnehmer-E-Mail. Trotzdem alle Tickets erneut senden?', 'pass-captain' ),
			'resendFailed' => __( 'Erneuter Versand fehlgeschlagen.', 'pass-captain' ),
			/* translators: %s: masked e-mail address */
			'resent' => __( 'Die Tickets wurden erneut an %s gesendet. Bitte verwende nur noch die neuen Codes.', 'pass-captain' ),
			'sending' => __( 'Wird gesendet …', 'pass-captain' ),
			'billingSaved' => __( 'Rechnungsdaten gespeichert. Sie werden auf der Rechnung verwendet.', 'pass-captain' ),
			'billingCleared' => __( 'Rechnungsdaten entfernt.', 'pass-captain' ),
			'billingFailed' => __( 'Die Rechnungsdaten konnten nicht gespeichert werden.', 'pass-captain' ),
			/* translators: %s: masked IBAN */
			'payoutSaved' => __( 'Bankverbindung gespeichert (%s). Erstattungen überweisen wir auf dieses Konto.', 'pass-captain' ),
			'payoutRemoved' => __( 'Bankverbindung entfernt.', 'pass-captain' ),
			'payoutFailed' => __( 'Die Bankverbindung konnte nicht gespeichert werden.', 'pass-captain' ),
			// Refund request for tickets of postponed dates (RescheduleRefunds).
			'rescheduleConfirm' => __( 'Erstattung für die Tickets der verschobenen Termine beantragen? Der Veranstalter prüft den Antrag; erst nach der Freigabe werden die Tickets ungültig und der Betrag erstattet.', 'pass-captain' ),
			'rescheduleConfirmButton' => __( 'Ja, Erstattung beantragen', 'pass-captain' ),
			'rescheduleRequested' => __( 'Dein Erstattungsantrag ist eingegangen und wird geprüft.', 'pass-captain' ),
			'rescheduleFailed' => __( 'Der Erstattungsantrag konnte nicht gestellt werden.', 'pass-captain' ),
		) + PortalSelfService::script_strings();
		$refundable = RescheduleRefunds::eligible_tickets( $order );
		$refund_open = $refundable ? (bool) $wpdb->get_var( $wpdb->prepare( "SELECT refund_id FROM {$wpdb->prefix}wpcap_refunds WHERE order_id=%d AND status IN ('pending_approval','approved','processing') LIMIT 1", $order->order_id ) ) : false;
		$billing = Billing::for_order( $order );
		ob_start();
		?><!doctype html><html <?php echo \PassCaptain\Frontend\Format::html_lang_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'pass-captain' ), $reference ) ); ?></title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:48rem;margin:2rem auto;padding:0 1rem;color:#1d2327}.card{border:1px solid #c3c4c7;border-radius:10px;padding:1rem;margin:1rem 0}label{display:grid;gap:.25rem;margin:.5rem 0}input{max-width:28rem;padding:.5rem}button{padding:.55rem 1rem;min-height:2.75rem}.status{font-weight:700}.muted{color:#50575e}.bank{display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem}.bank dd{margin:0;font-weight:700;overflow-wrap:anywhere}dialog{max-width:32rem;border:1px solid #c3c4c7;border-radius:10px;padding:1.25rem}dialog::backdrop{background:rgba(0,0,0,.45)}.actions{display:flex;gap:.75rem;flex-wrap:wrap;justify-content:flex-end}.danger{background:#b32d2e;color:#fff;border:1px solid #8a1f1f;border-radius:4px}.wpcap-consents{padding-left:1.2rem}.wpcap-consents li{margin:.75rem 0}:focus-visible{outline:3px solid #135e96;outline-offset:3px}</style></head><body><main><?php echo Brand::logo_html( 'portal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in logo_html(); empty without a brand logo. ?>
<h1><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'pass-captain' ), $reference ) ); ?></h1>
<p class="muted"><?php echo esc_html( sprintf( /* translators: %s: order date */ __( 'Bestellt am %s', 'pass-captain' ), Frontend\Format::site_datetime( $order->created_at ) ) ); ?></p>
<p class="status"><?php echo esc_html( Admin\Forms::status_label( $order->status ) . ( $order->status !== $order->payment_status ? ' · ' . Admin\Forms::status_label( $order->payment_status ) : '' ) ); ?></p>
<?php echo Frontend\Language::portal_form( $order, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in portal_form. ?>
<?php $voucher_paid = class_exists( Integrations\Vouchers\Checkout::class ) ? Integrations\Vouchers\Checkout::voucher_payment_minor( $order ) : 0; ?>
<?php $summary = Orders::summary_lines( (int) $order->order_id ); if ( $summary ) : ?><section class="card" aria-labelledby="wpcap-items-title"><h2 id="wpcap-items-title"><?php esc_html_e( 'Bestellte Tickets', 'pass-captain' ); ?></h2><table class="items"><tbody><?php foreach ( $summary as $line ) : ?><tr><td><?php echo esc_html( sprintf( '%d × %s — %s', $line['quantity'], $line['event'], $line['ticket'] ) ); ?><br><small class="muted"><?php echo esc_html( $line['when'] ); ?></small></td><td style="text-align:right;white-space:nowrap"><?php echo esc_html( Frontend\Format::money( $line['gross_minor'], $line['currency'] ) ); ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
<p><?php echo esc_html( sprintf( /* translators: %s: formatted amount */ __( 'Gesamtbetrag: %s', 'pass-captain' ), Frontend\Format::money( (int) $order->total_minor + $voucher_paid, $order->currency ) ) ); ?><?php if ( $voucher_paid ) : ?><br><?php echo esc_html( sprintf( /* translators: 1: voucher amount, 2: amount to pay */ __( 'davon mit Gutschein bezahlt: %1$s · Zahlbetrag: %2$s', 'pass-captain' ), Frontend\Format::money( $voucher_paid, $order->currency ), Frontend\Format::money( $order->total_minor, $order->currency ) ) ); ?><?php endif; ?></p>
<?php if ( $bank ) : ?><section class="card" aria-labelledby="wpcap-pay-title"><h2 id="wpcap-pay-title"><?php esc_html_e( 'Zahlungsinformationen', 'pass-captain' ); ?></h2><dl class="bank"><?php foreach ( Payments\BankTransfer::rows( $bank ) as $label => $value ) : ?><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?></dl><?php if ( '' !== $bank['instructions'] ) : ?><p><?php echo nl2br( esc_html( $bank['instructions'] ) ); ?></p><?php endif; ?></section><?php endif; ?>
<?php if ( $tickets ) : ?><h2><?php esc_html_e( 'Tickets', 'pass-captain' ); ?></h2><p><?php esc_html_e( 'Die Ticketlinks und QR-Codes findest du in der Ticket-E-Mail (aus Sicherheitsgründen werden Ticketcodes nicht gespeichert und hier nicht angezeigt).', 'pass-captain' ); ?></p>
<?php if ( $can_resend ) : ?><form class="wpcap-resend-form card" data-passed-on="<?php echo (int) $passed_on; ?>"><?php if ( $passed_on ) : ?><p role="note"><strong><?php echo esc_html( sprintf( /* translators: %d: number of tickets */ _n( 'Achtung: %d Ticket hast du an eine andere Person weitergegeben.', 'Achtung: %d Tickets hast du an andere Personen weitergegeben.', $passed_on, 'pass-captain' ), $passed_on ) ); ?></strong> <?php esc_html_e( 'Ein erneuter Versand aller Tickets macht deren Tickets und Ausdrucke ungültig. Sende nur deine eigenen Tickets neu, wenn du nur deine E-Mail verloren hast.', 'pass-captain' ); ?></p><?php if ( $own_valid > 0 ) : ?><label><span><input type="checkbox" name="only_own" value="1" checked> <?php esc_html_e( 'Nur meine eigenen, nicht weitergegebenen Tickets erneut senden', 'pass-captain' ); ?></span></label><?php endif; ?><?php endif; ?><p><button type="submit"><?php echo esc_html( sprintf( /* translators: %s: masked e-mail address */ __( 'Tickets erneut an %s senden', 'pass-captain' ), Frontend\Format::mask_email( $order->customer_email ) ) ); ?></button></p><p class="muted"><small><?php esc_html_e( 'Dabei werden neue Ticketcodes erstellt; bisher gesendete Codes werden ungültig. Tickets mit eigener Teilnehmer-E-Mail gehen an diese Adresse. Höchstens dreimal pro Stunde.', 'pass-captain' ); ?></small></p><p class="message" role="status" aria-live="polite"></p></form><?php endif; ?>
<?php echo PortalSelfService::portal_html( $order, $token, $tickets ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in PortalSelfService. ?>
<?php if ( $refundable ) : $refund_deadline = RescheduleRefunds::deadline_label( $refundable[0]->starts_at, $refundable[0]->timezone ); ?><form class="wpcap-refund-request-form card"><h3><?php esc_html_e( 'Termin verschoben', 'pass-captain' ); ?></h3><p><?php echo esc_html( sprintf( /* translators: 1: number of tickets, 2: deadline */ _n( 'Für %1$d Ticket wurde der Termin verschoben. Kannst du den neuen Termin nicht wahrnehmen, kannst du bis %2$s eine Erstattung beantragen.', 'Für %1$d Tickets wurde der Termin verschoben. Kannst du den neuen Termin nicht wahrnehmen, kannst du bis %2$s eine Erstattung beantragen.', count( $refundable ), 'pass-captain' ), count( $refundable ), $refund_deadline ) ); ?></p><?php if ( $refund_open ) : ?><p class="status"><?php esc_html_e( 'Ein Erstattungsantrag wird bereits geprüft.', 'pass-captain' ); ?></p><?php else : ?><p><button type="submit"><?php esc_html_e( 'Erstattung beantragen', 'pass-captain' ); ?></button></p><?php endif; ?><p class="message" role="status" aria-live="polite"></p></form><?php endif; ?>
<?php foreach ( $tickets as $ticket ) : ?><section class="card"><h3><?php echo esc_html( $ticket->event_name . ' — ' . $ticket->ticket_name ); ?></h3><p><?php echo esc_html( Frontend\Format::event_start( $ticket->starts_at, $ticket->timezone ) ); ?></p><?php $seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket->ticket_id, (array) $ticket ); if ( '' !== $seat ) : ?><p><strong><?php echo esc_html( $seat ); ?></strong></p><?php endif; ?><p><?php echo esc_html( Admin\Forms::status_line( $ticket->status, (bool) (int) $ticket->refunded ) ); ?></p>
<?php echo PortalSelfService::ticket_html( $ticket, $order, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in PortalSelfService. ?>
<?php if ( ! empty( $missing[ (int) $ticket->ticket_id ] ) ) : ?><p class="notice" role="status"><?php echo esc_html( sprintf( /* translators: %s: missing attendee data */ __( 'Für dieses Ticket fehlen noch: %s', 'pass-captain' ), implode( ', ', $missing[ (int) $ticket->ticket_id ] ) ) ); ?></p><?php endif; ?>
<?php if ( 'valid' === $ticket->status && (int) $ticket->transfer_count > 0 ) : ?><p class="muted"><small><?php esc_html_e( 'Dieses Ticket wurde übertragen. Name und Angaben pflegt die neue Inhaberin bzw. der neue Inhaber über den eigenen Ticketlink.', 'pass-captain' ); ?></small></p><?php elseif ( 'valid' === $ticket->status && strtotime( $ticket->starts_at . ' UTC' ) - $edit_hours * HOUR_IN_SECONDS > \PassCaptain\Clock::now() ) : ?><form class="wpcap-assignment-form"><input type="hidden" name="ticket_public_id" value="<?php echo esc_attr( $ticket->public_id ); ?>"><label><?php esc_html_e( 'Teilnehmername', 'pass-captain' ); ?><input name="attendee_name" type="text" maxlength="190" value="<?php echo esc_attr( $ticket->attendee_name ?? '' ); ?>" required></label><label><?php esc_html_e( 'Teilnehmer-E-Mail (optional)', 'pass-captain' ); ?><input name="attendee_email" type="email" autocomplete="email" value="<?php echo esc_attr( $ticket->attendee_email ); ?>"></label><?php foreach ( $fields[ $ticket->ticket_type_id ] as $field ) : $value = $answers[ $ticket->ticket_id ][ (int) $field->field_id ] ?? ''; if ( ! ParticipantFields::self_editable( $field, (string) $ticket->starts_at ) ) : /* A683: locked, shown only */ ?><p><?php echo esc_html( $field->label ); ?>: <strong><?php echo esc_html( '' !== (string) $value ? (string) $value : '—' ); ?></strong> <small><?php esc_html_e( '(nicht mehr änderbar)', 'pass-captain' ); ?></small></p><?php continue; endif; ?><label><?php echo esc_html( $field->label . ( $field->required ? ' *' : '' ) ); ?><?php if ( 'textarea' === $field->field_type ) : ?><textarea data-answer="<?php echo esc_attr( $field->field_key ); ?>" <?php echo $field->required ? 'required' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea><?php elseif ( 'checkbox' === $field->field_type ) : ?><input type="checkbox" value="1" data-answer="<?php echo esc_attr( $field->field_key ); ?>" <?php checked( '1', $value ); ?>><?php elseif ( '' !== ( $wpcap_control = ParticipantFields::control_html( $field, array( 'data-answer' => $field->field_key, 'id' => 'wpcap-pa-' . $ticket->ticket_id . '-' . $field->field_id ), $value ) ) ) : echo $wpcap_control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in control_html(). ?><?php else : ?><input type="<?php echo 'email' === $field->field_type ? 'email' : 'text'; ?>" data-answer="<?php echo esc_attr( $field->field_key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo $field->required ? 'required' : ''; ?>><?php endif; ?></label><?php endforeach; ?><p><small><?php esc_html_e( 'Wenn du eine andere E-Mail-Adresse einträgst, erhält diese Person einen neuen Ticketcode und der bisherige Code wird ungültig. Namens- und Angabenkorrekturen behalten den Code.', 'pass-captain' ); ?></small></p><?php if ( $edit_hours ) : ?><p><small><?php echo esc_html( sprintf( /* translators: %d: hours */ _n( 'Änderungen sind bis %d Stunde vor Beginn möglich.', 'Änderungen sind bis %d Stunden vor Beginn möglich.', $edit_hours, 'pass-captain' ), $edit_hours) ); ?></small></p><?php endif; ?><button type="submit"><?php esc_html_e( 'Teilnehmer zuweisen', 'pass-captain' ); ?></button><p class="message" aria-live="polite"></p></form><?php endif; ?>
<?php if ( 'valid' === $ticket->status && 0 === (int) $ticket->transfer_count && strtotime( $ticket->starts_at . ' UTC' ) > \PassCaptain\Clock::now() ) : ?><form class="wpcap-transfer-form"><input type="hidden" name="ticket_public_id" value="<?php echo esc_attr( $ticket->public_id ); ?>"><label><?php esc_html_e( 'An E-Mail übertragen', 'pass-captain' ); ?><input name="recipient_email" type="email" autocomplete="email" required></label><button type="submit"><?php esc_html_e( 'Ticket einmalig übertragen', 'pass-captain' ); ?></button><p class="message" aria-live="polite"></p></form><?php endif; ?><?php echo (string) apply_filters( 'wpcap_order_portal_ticket_html', '', $ticket, $order, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- modules escape their own markup (e.g. Exchange\Portal). ?></section><?php endforeach; endif; ?>
<?php echo Consents::self_service_html( self::consent_tickets( $order ), (string) $order->public_id, Consents::result_notice( isset( $_GET['wpcap_consent_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_consent_result'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in self_service_html; display flag only. ?>
<?php echo Sales\Consents::portal_section( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in portal_section. ?>
<?php echo (string) apply_filters( 'wpcap_order_portal_sections', '', $order, $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sections escape their own output (e.g. cancellation requests). ?>
<?php echo Waitlists::portal_html( $order, isset( $_GET['wpcap_waitlist_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_waitlist_result'] ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in portal_html; display flag only. ?>
<?php echo ServiceBookingFlow::portal_html( $order, isset( $_GET['wpcap_service_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_service_result'] ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in portal_html; display flag only. ?>
<?php echo self::billing_section( $billing, $has_invoice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in billing_section. ?>
<?php echo self::payout_section( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in payout_section. ?>
<?php if ( $has_invoice ) : ?><p><a href="<?php echo esc_url( add_query_arg( array( 'wpcap_invoice' => $public_id ) + ( Security\AccessCookie::via_cookie() ? array() : array( 'wpcap_access' => $token ) ), home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Rechnung anzeigen', 'pass-captain' ); ?></a><?php $invoice_links = Api\Invoices::links_html( (int) $has_invoice, 'invoice', array( 'audience' => 'customer', 'location' => 'portal', 'order_id' => (int) $order->order_id, 'order_public_id' => (string) $order->public_id, 'access_token' => $token ) ); echo '' !== $invoice_links ? '<br>' . $invoice_links : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links_html(). ?></p><?php endif; ?>
<p><?php esc_html_e( 'Diesen persönlichen Link sicher aufbewahren und nicht teilen.', 'pass-captain' ); ?></p>
<p class="muted"><small><?php echo esc_html( sprintf( /* translators: %s: full order id */ __( 'Vollständige Bestell-ID: %s', 'pass-captain' ), $order->public_id ) ); ?></small></p></main>
<dialog id="wpcap-confirm" aria-labelledby="wpcap-confirm-text"><form method="dialog"><p id="wpcap-confirm-text"></p><div class="actions"><button value="cancel"><?php echo esc_html( $strings['cancel'] ); ?></button><button value="ok" class="danger" id="wpcap-confirm-ok"></button></div></form></dialog>
<?php ob_start(); // A302: the script goes out through wp_get_inline_script_tag() (CSP nonce, Plugin Check). A395: a form whose button is removed or disabled after the request moves focus to its message (settle()); a re-enabled button gets the focus back. ?>(function(){
var S=<?php echo wp_json_encode( $strings ); ?>,TOKEN=<?php echo wp_json_encode( $token ); ?>,URL={transfer:<?php echo wp_json_encode( rest_url( $base . '/transfer' ) ); ?>,assign:<?php echo wp_json_encode( rest_url( $base . '/assign' ) ); ?>,resend:<?php echo wp_json_encode( rest_url( $base . '/resend' ) ); ?>,billing:<?php echo wp_json_encode( rest_url( $base . '/billing' ) ); ?>,payout:<?php echo wp_json_encode( rest_url( $base . '/payout-account' ) ); ?>,refund:<?php echo wp_json_encode( rest_url( $base . '/refund-request' ) ); ?>};
/* The access token goes in the X-WPCAP-Access-Token header (never in a URL, SEC-7) and, for routes that read it from the body, in the JSON body. */
function confirmAction(text,label){var d=document.getElementById('wpcap-confirm');if(!d||typeof d.showModal!=='function'){return Promise.resolve(window.confirm(text));}document.getElementById('wpcap-confirm-text').textContent=text;document.getElementById('wpcap-confirm-ok').textContent=label;var opener=document.activeElement;return new Promise(function(resolve){d.addEventListener('close',function(){if(opener&&opener.focus){opener.focus();}resolve(d.returnValue==='ok');},{once:true});d.returnValue='';d.showModal();d.querySelector('button[value="cancel"]').focus();});}
function settle(message,text){message.textContent=text;message.setAttribute('tabindex','-1');message.focus();}
async function post(url,body){var r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WPCAP-Access-Token':TOKEN},body:JSON.stringify(Object.assign({access_token:TOKEN},body))});var data={};try{data=await r.json();}catch(e){}return {ok:r.ok,data:data};}
document.querySelectorAll('.wpcap-transfer-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button');if(!form.reportValidity()){return;}if(!(await confirmAction(S.transferConfirm.replace('%s',form.recipient_email.value),S.transferConfirmButton))){return;}button.disabled=true;try{var res=await post(URL.transfer,{ticket_public_id:form.ticket_public_id.value,recipient_email:form.recipient_email.value});if(!res.ok){throw new Error(res.data.message||S.transferFailed);}form.querySelectorAll('label,button').forEach(function(n){n.remove();});settle(message,S.transferred);}catch(error){message.textContent=error.message;button.disabled=false;button.focus();}});});
document.querySelectorAll('.wpcap-assignment-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');try{var res=await post(URL.assign,{ticket_public_id:form.ticket_public_id.value,attendee_name:form.attendee_name.value,attendee_email:form.attendee_email.value,answers:Array.from(form.querySelectorAll('[data-answer]')).reduce(function(a,i){a[i.dataset.answer]=i.dataset.multi?Array.from(i.querySelectorAll('input:checked')).map(function(b){return b.value;}):(i.type==='checkbox'?(i.checked?'1':''):i.value);return a;},{})});if(!res.ok){throw new Error(res.data.message||S.assignFailed);}message.textContent=res.data.code_rotated?S.assignedRotated:S.assignedKept;}catch(error){message.textContent=error.message;}});});
/* Resend: with tickets passed on to other people the form offers "only my own tickets" (checked by default); resending all of them asks with an explicit warning. */
document.querySelectorAll('.wpcap-resend-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button');var own=form.querySelector('[name="only_own"]');var all=(!own||!own.checked)&&Number(form.getAttribute('data-passed-on')||0)>0;if(!(await confirmAction(all&&S.resendConfirmAll?S.resendConfirmAll.replace('%d',form.getAttribute('data-passed-on')):S.resendConfirm,S.resendConfirmButton))){return;}button.disabled=true;message.textContent=S.sending;try{var res=await post(URL.resend,{only_own:!!(own&&own.checked)});if(!res.ok){throw new Error(res.data.message||S.resendFailed);}message.textContent=S.resent.replace('%s',res.data.email||'');}catch(error){message.textContent=error.message;}finally{button.disabled=false;if(!document.activeElement||document.activeElement===document.body){button.focus();}}});});
document.querySelectorAll('.wpcap-pdf-form[data-new-codes]').forEach(function(form){form.addEventListener('submit',async function(event){if(form.dataset.confirmed){delete form.dataset.confirmed;return;}event.preventDefault();if(await confirmAction(S.pdfConfirm,S.pdfConfirmButton)){form.dataset.confirmed='1';form.requestSubmit?form.requestSubmit():form.submit();}});});
document.querySelectorAll('.wpcap-refund-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button[type="submit"]');var ids=Array.from(form.querySelectorAll('input[name="ticket_public_ids[]"]:checked')).map(function(i){return i.value;});if(!ids.length){message.textContent=S.refundNone;return;}if(!(await confirmAction(S.refundConfirm,S.refundConfirmButton))){return;}button.disabled=true;try{var res=await post(URL.refund,{ticket_public_ids:ids,reason:form.reason.value,idempotency_key:form.idempotency_key.value,nonce:form.nonce.value});if(!res.ok){throw new Error(res.data.message||S.refundFailed);}form.querySelectorAll('fieldset,label,button').forEach(function(n){n.remove();});settle(message,res.data.message||'');}catch(error){message.textContent=error.message;button.disabled=false;button.focus();}});});
document.querySelectorAll('.wpcap-refund-request-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button');if(!button||!(await confirmAction(S.rescheduleConfirm,S.rescheduleConfirmButton))){return;}button.disabled=true;message.textContent=S.sending;try{var res=await post(URL.refund,{});if(!res.ok){throw new Error(res.data.message||S.rescheduleFailed);}button.remove();settle(message,S.rescheduleRequested);}catch(error){message.textContent=error.message;button.disabled=false;button.focus();}});});
document.querySelectorAll('.wpcap-payout-form').forEach(function(form){form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button');button.disabled=true;try{var res=await post(URL.payout,{iban:form.iban.value.trim(),holder:form.holder.value.trim()});if(!res.ok){throw new Error(res.data.message||S.payoutFailed);}message.textContent=res.data.saved?S.payoutSaved.replace('%s',res.data.iban_masked):S.payoutRemoved;form.iban.value='';}catch(error){message.textContent=error.message;}finally{button.disabled=false;if(!document.activeElement||document.activeElement===document.body){button.focus();}}});});
document.querySelectorAll('.wpcap-billing-form').forEach(function(form){var toggle=form.querySelector('[name="enabled"]'),box=form.querySelector('.billing-fields');function sync(){box.hidden=!toggle.checked;}toggle.addEventListener('change',sync);sync();form.addEventListener('submit',async function(event){event.preventDefault();var message=form.querySelector('.message');var button=form.querySelector('button[type="submit"]');form.querySelectorAll('[aria-invalid]').forEach(function(i){i.removeAttribute('aria-invalid');});if(toggle.checked&&!form.reportValidity()){return;}var billing={enabled:toggle.checked};form.querySelectorAll('[data-billing]').forEach(function(i){billing[i.dataset.billing]=i.value.trim();});button.disabled=true;try{var res=await post(URL.billing,{billing:billing});if(!res.ok){var field=res.data&&res.data.data&&res.data.data.field?form.querySelector('[data-billing="'+String(res.data.data.field).replace('billing_','')+'"]'):null;if(field){field.setAttribute('aria-invalid','true');field.focus();}throw new Error(res.data.message||S.billingFailed);}message.textContent=toggle.checked?S.billingSaved:S.billingCleared;}catch(error){message.textContent=error.message;}finally{button.disabled=false;if(!document.activeElement||document.activeElement===document.body){button.focus();}}});});
})();<?php echo Assets::inline_script_tag( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own script, values JSON-encoded. ?></body></html><?php
		return (string) ob_get_clean();
	}

	/** Bank account for refunds of bank-transfer orders (PayoutAccount); '' when not needed. */
	private static function payout_section( $order ) {
		if ( ! PayoutAccount::applies( $order ) || ! PayoutAccount::ready() || ! in_array( (string) $order->payment_status, array( 'paid', 'refunded', 'paid_unallocated' ), true ) ) { return ''; }
		$account = PayoutAccount::get( $order );
		$html = '<section class="card" aria-labelledby="wpcap-payout-title"><h2 id="wpcap-payout-title">' . esc_html__( 'Bankverbindung für Erstattungen', 'pass-captain' ) . '</h2>';
		$html .= '<p class="muted"><small>' . esc_html__( 'Du hast per Überweisung bezahlt. Falls wir dir etwas erstatten (z. B. bei einer Absage), überweisen wir den Betrag auf dieses Konto.', 'pass-captain' ) . '</small></p>';
		if ( $account ) {
			/* translators: 1: masked IBAN, 2: account holder */
			$html .= '<p>' . esc_html( sprintf( __( 'Gespeichert: %1$s (%2$s)', 'pass-captain' ), PayoutAccount::mask( $account['iban'] ), $account['holder'] ) ) . '</p>';
		}
		$html .= '<form class="wpcap-payout-form"><label>' . esc_html__( 'Kontoinhaber:in', 'pass-captain' ) . '<input name="holder" type="text" autocomplete="name" maxlength="190" value="' . esc_attr( $account ? $account['holder'] : '' ) . '"></label>';
		$html .= '<label>' . esc_html__( 'IBAN', 'pass-captain' ) . '<input name="iban" type="text" autocomplete="off" spellcheck="false" maxlength="42" placeholder="' . esc_attr( $account ? PayoutAccount::mask( $account['iban'] ) : 'AT61 1904 3002 3457 3201' ) . '"></label>';
		$html .= '<p class="muted"><small>' . esc_html__( 'IBAN leer lassen und speichern, um die Bankverbindung zu entfernen.', 'pass-captain' ) . '</small></p>';
		$html .= '<button type="submit">' . esc_html__( 'Bankverbindung speichern', 'pass-captain' ) . '</button><p class="message" role="status" aria-live="polite"></p></form></section>';
		return $html;
	}

	/** Billing data card: editable until the invoice is issued, read-only afterwards. */
	private static function billing_section( array $billing, $has_invoice ) {
		$labels = Billing::labels();
		$html = '<section class="card" aria-labelledby="wpcap-billing-title"><h2 id="wpcap-billing-title">' . esc_html__( 'Rechnungsdaten', 'pass-captain' ) . '</h2>';
		if ( $has_invoice ) {
			$lines = array_merge( Billing::address_lines( $billing ), Billing::detail_lines( $billing ) );
			$html .= $lines ? '<p>' . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</p>' : '';
			$html .= '<p class="muted"><small>' . esc_html__( 'Die Rechnung wurde bereits ausgestellt. Für eine Korrektur wende dich bitte an den Veranstalter.', 'pass-captain' ) . '</small></p></section>';
			return $html;
		}
		$html .= '<form class="wpcap-billing-form"><p class="muted"><small>' . esc_html__( 'Bis zur Ausstellung der Rechnung kannst du hier Firma, Name, Anschrift und UID-Nummer für die Rechnung angeben oder korrigieren.', 'pass-captain' ) . '</small></p>';
		$html .= '<label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" name="enabled" value="1"' . ( $billing ? ' checked' : '' ) . '> ' . esc_html( $labels['enabled'] ) . '</label><div class="billing-fields">';
		$autocomplete = array( 'company' => 'organization', 'first_name' => 'given-name', 'last_name' => 'family-name', 'street' => 'address-line1', 'postcode' => 'postal-code', 'city' => 'address-level2', 'vat_id' => 'off', 'po_number' => 'off' );
		$country = $billing['country'] ?? Billing::default_country();
		foreach ( Billing::FIELDS as $field ) {
			$required = in_array( $field, Billing::REQUIRED, true );
			$label = $labels[ $field ] . ( $required ? ' *' : ' ' . __( '(optional)', 'pass-captain' ) );
			if ( 'country' === $field ) {
				$html .= '<label>' . esc_html( $label ) . '<select data-billing="country" autocomplete="country" required>';
				foreach ( Billing::countries() as $code => $name ) {
					$html .= '<option value="' . esc_attr( $code ) . '"' . ( $code === $country ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
				}
				$html .= '</select></label>';
				continue;
			}
			$html .= '<label>' . esc_html( $label ) . '<input type="text" data-billing="' . esc_attr( $field ) . '" autocomplete="' . esc_attr( $autocomplete[ $field ] ) . '" maxlength="' . (int) Billing::MAX[ $field ] . '" value="' . esc_attr( $billing[ $field ] ?? '' ) . '"' . ( $required ? ' required' : '' ) . '></label>';
		}
		$html .= '</div><button type="submit">' . esc_html__( 'Rechnungsdaten speichern', 'pass-captain' ) . '</button><p class="message" role="status" aria-live="polite"></p></form></section>';
		return $html;
	}
}
