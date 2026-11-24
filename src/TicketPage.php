<?php
/** Private ticket display page keyed by a high-entropy ticket secret. */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TicketPage {
	/** @var bool Registered in this request (the old copy's bootstrap calls register() again, see LegacyCopy). */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = 'wpcap_ticket'; $vars[] = 'wpcap_tref'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		OrderPortal::register();
		TicketRecovery::register(); // A622
	}

	public static function maybe_render() {
		$token = get_query_var( 'wpcap_ticket' );
		$from_url = '';
		if ( $token && is_scalar( $token ) ) {
			// A283: personal page, also its 404 answers – never stored by a page cache or CDN.
			PageCache::no_store( 'ticket' );
			$from_url = Security\Input::hex( $token );
			if ( 64 !== strlen( $from_url ) ) { status_header( 404 ); \PassCaptain\Http::end(); }
			$token = $from_url;
		} else {
			// ?wpcap_tref=… after the token was exchanged for the ticket's access cookie (SEC-7).
			$ref = get_query_var( 'wpcap_tref' );
			$ref = $ref && is_scalar( $ref ) ? Security\Input::hex( $ref ) : '';
			if ( 32 !== strlen( $ref ) ) { return; } // ?wpcap_ticket[]=… is not a ticket link either.
			PageCache::no_store( 'ticket' );
			$token = Security\AccessCookie::resolve( 'ticket', $ref );
			if ( '' === $token || ! hash_equals( $ref, Security\AccessCookie::ticket_ref( $token ) ) ) {
				if ( Security\AccessCookie::marker_present() ) { Security\AccessCookie::blocked_page(); }
				status_header( 404 );
				\PassCaptain\Http::end();
			}
		}
		global $wpdb;
		$ticket = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.ticket_id,t.public_id,t.ticket_type_id,t.status,tt.name AS ticket_name,o.occurrence_id,o.starts_at,o.timezone,o.location,e.name AS event_name,ord.locale,ord.public_id AS order_public_id FROM {$wpdb->prefix}wpcap_tickets t INNER JOIN {$wpdb->prefix}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$wpdb->prefix}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$wpdb->prefix}wpcap_events e ON e.event_id=o.event_id WHERE t.token_hash=%s LIMIT 1",
			hash( 'sha256', $token )
		) );
		if ( ! $ticket ) {
			status_header( 404 );
			// A622: an old or replaced link (e.g. after a reissue) leads to the holder's way back to the tickets.
			echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><p>' . esc_html__( 'Dieses Ticket wurde nicht gefunden oder der Link ist nicht mehr gültig.', 'pass-captain' ) . ' <a href="' . esc_url( TicketRecovery::url() ) . '">' . esc_html__( 'Ticketlinks per E-Mail anfordern', 'pass-captain' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
			\PassCaptain\Http::end();
		}
		if ( '' !== $from_url ) {
			// Token from the mail link → cookie, 303 to ?wpcap_tref=… without token (falls back to rendering).
			$ref = Security\AccessCookie::ticket_ref( $token );
			Security\AccessCookie::exchange( 'ticket', $ref, $token, add_query_arg( 'wpcap_tref', $ref, home_url( '/' ) ) );
		}
		// The ticket page is shown in the language of the order (buyer's choice, W9); the request ends below.
		$locale = Mail\Mailer::normalize_locale( (string) ( $ticket->locale ?? '' ) );
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) ) { switch_to_locale( $locale ); }
		// Consent grant/withdrawal by the ticket holder (A116): POST to the same link, nonce-checked (PRG).
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_consent_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in Consents::handle_self_service().
			$result = self::handle_consent( $ticket, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( self::link_args( $token ) + array( 'wpcap_consent_result' => is_wp_error( $result ) ? 'error' : $result ), home_url( '/' ) ) . '#wpcap-consents' );
			\PassCaptain\Http::end();
		}
		// A570: attendee name and answers by the current holder (POST to the same link, nonce, PRG).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_attendee().
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_attendee_op'] ) ) {
			$result = self::handle_attendee( $ticket, $token, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_safe_redirect( add_query_arg( self::link_args( $token ) + array( 'wpcap_attendee_result' => is_wp_error( $result ) ? substr( sanitize_key( $result->get_error_code() ), 0, 40 ) : $result ), home_url( '/' ) ) . '#wpcap-attendee' );
			\PassCaptain\Http::end();
		}
		// "Send me my order link" (A118): new portal link to the buyer, POST with nonce.
		$link_message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right here.
		if ( \PassCaptain\Http::is_post() && isset( $_POST['wpcap_order_link'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['_wpnonce'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sent = wp_verify_nonce( $nonce, 'wpcap_ticket_order_link_' . (int) $ticket->ticket_id ) ? PortalSelfService::send_order_link( $token ) : new \WP_Error( 'wpcap_nonce', __( 'Das Formular ist abgelaufen. Bitte lade die Seite neu.', 'pass-captain' ) );
			/* translators: %s: masked e-mail address */
			$link_message = is_wp_error( $sent ) ? $sent->get_error_message() : sprintf( __( 'Wir haben dir einen neuen Link zu deiner Bestellung an %s gesendet. Der bisherige Link gilt weiter, bis du den neuen Link zum ersten Mal öffnest.', 'pass-captain' ), $sent );
		}
		$link_form = '';
		$link_ticket = $wpdb->get_row( $wpdb->prepare( "SELECT ticket_id,order_id,transfer_count,attendee_email FROM {$wpdb->prefix}wpcap_tickets WHERE ticket_id=%d", (int) $ticket->ticket_id ) );
		$link_order = $link_ticket ? Orders::get( (int) $link_ticket->order_id ) : null;
		if ( $link_order && PortalSelfService::can_request_order_link( $link_ticket, $link_order ) ) {
			$link_form = '<section class="manage"><h2>' . esc_html__( 'Bestellung verwalten', 'pass-captain' ) . '</h2><p>' . esc_html__( 'Über den persönlichen Bestelllink kannst du Tickets als PDF herunterladen, Teilnehmer zuweisen und – falls angeboten – eine Stornierung anfragen. Du findest ihn in deiner Bestellbestätigung.', 'pass-captain' ) . '</p>'
				. '<form method="post"><input type="hidden" name="wpcap_order_link" value="1"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wpcap_ticket_order_link_' . (int) $ticket->ticket_id ) ) . '"><button type="submit" class="print">' . esc_html__( 'Neuen Bestelllink per E-Mail senden', 'pass-captain' ) . '</button></form>'
				. '<p><small>' . esc_html__( 'Der Link geht an die E-Mail-Adresse der Bestellung; ein früher gesendeter Bestelllink gilt weiter, bis der neue Link zum ersten Mal geöffnet wird.', 'pass-captain' ) . '</small></p></section>';
		}
		if ( '' !== $link_message ) {
			$link_form = '<p class="notice" role="status">' . esc_html( $link_message ) . '</p>' . $link_form;
		}
		Security\PrivatePage::send( true ); // No page cache, no framing, no indexing (secret link); strict CSP (page rendered entirely here).
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		$qr = QrCode::data_uri( Security\SignedQr::qr_value( $token ) );
		$seat = (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket->ticket_id, (array) $ticket );
		// Assigned here for tickets issued before help codes existed (lazy backfill).
		$help_code = in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) ? HelpCode::for_ticket( (int) $ticket->ticket_id, $token ) : '';
		ob_start();
		?><!doctype html><html <?php echo \PassCaptain\Frontend\Format::html_lang_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Format. ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $ticket->event_name ); ?> — <?php esc_html_e( 'Ticket', 'pass-captain' ); ?></title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:42rem;margin:2rem auto;padding:0 1rem;color:#1d2327;overflow-wrap:anywhere}img{max-width:100%}.ticket{border:1px solid #c3c4c7;border-radius:12px;padding:1.5rem}.qr{display:block;width:min(75vw,320px);max-width:100%;height:auto;margin:1.5rem auto}.token{overflow-wrap:anywhere;font-family:monospace}.help-code strong{display:block;font:700 1.75rem/1.2 ui-monospace,monospace;letter-spacing:.12em}.print{padding:.6rem 1rem}.card{border:1px solid #c3c4c7;border-radius:12px;padding:1rem 1.5rem;margin:1rem 0}.muted{color:#50575e}.wpcap-consents{padding-left:1.2rem}.wpcap-consents li{margin:.75rem 0}.wpcap-consents button{padding:.55rem 1rem;min-height:2.75rem;margin-top:.25rem}.danger{background:#b32d2e;color:#fff;border:1px solid #8a1f1f;border-radius:4px}.manage{margin-top:1.5rem;border-top:1px solid #c3c4c7;padding-top:1rem}.notice{font-weight:700}@media print{.print,.card,.manage,.notice{display:none}.ticket{border:0}}</style></head><body><main class="ticket"><?php echo Brand::logo_html( 'ticket_page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in logo_html(); empty without a brand logo. ?><h1><?php echo esc_html( $ticket->event_name ); ?></h1><h2><?php echo esc_html( $ticket->ticket_name ); ?></h2><?php $holder = AdmissionChecks::holder_line( TicketPdf::attendee_names( array( (int) $ticket->ticket_id ) )[ (int) $ticket->ticket_id ] ?? '', (int) $ticket->ticket_type_id ); // A614. ?><?php if ( '' !== $holder ) : ?><p class="holder"><strong><?php echo esc_html( $holder ); ?></strong></p><?php endif; ?><p><?php echo esc_html( Orders::format_start( $ticket->starts_at, $ticket->timezone ) ); ?></p><?php if ( '' !== $seat ) : ?><p><strong><?php echo esc_html( $seat ); ?></strong></p><?php endif; ?><?php $doors = DoorsOpen::label( DoorsOpen::for_occurrence( (int) $ticket->occurrence_id ), $ticket->timezone, $ticket->starts_at ); ?><?php if ( '' !== $doors ) : ?><p><strong><?php echo esc_html( $doors ); ?></strong></p><?php endif; ?><?php if ( $ticket->location ) : ?><p><?php echo esc_html( $ticket->location ); ?></p><?php endif; ?><p><?php echo esc_html( Admin\Forms::status_line( $ticket->status ) ); ?></p><p class="muted"><?php echo esc_html( sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'pass-captain' ), Frontend\Format::order_reference( (string) $ticket->order_public_id ) ) ); ?></p><?php if ( $qr ) : ?><img class="qr" src="<?php echo esc_attr( $qr ); ?>" alt="<?php esc_attr_e( 'Ticket QR-Code', 'pass-captain' ); ?>"><?php endif; ?><p class="token"><?php echo esc_html( $token ); ?></p><?php $wallet = class_exists( WalletPasses\Service::class ) && 'valid' === (string) $ticket->status ? WalletPasses\Service::ticket_links( (int) $ticket->ticket_id ) : array(); // A091 ?><?php if ( $wallet ) : ?><p class="wallet"><?php if ( ! empty( $wallet['apple'] ) ) : ?><a class="print" href="<?php echo esc_url( $wallet['apple'] ); ?>"><?php esc_html_e( 'Zu Apple Wallet hinzufügen', 'pass-captain' ); ?></a> <?php endif; ?><?php if ( ! empty( $wallet['google'] ) ) : ?><a class="print" href="<?php echo esc_url( $wallet['google'] ); ?>"><?php esc_html_e( 'In Google Wallet speichern', 'pass-captain' ); ?></a><?php endif; ?></p><?php endif; ?><?php if ( '' !== $help_code ) : ?><p class="help-code"><?php echo esc_html( HelpCode::caption() ); ?> <strong><?php echo esc_html( $help_code ); ?></strong></p><?php endif; ?><?php echo Frontend\PrintButton::html( __( 'Drucken oder als PDF speichern', 'pass-captain' ), 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html(); no inline handler (strict CSP). ?><?php echo self::attendee_html( $ticket, isset( $_GET['wpcap_attendee_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_attendee_result'] ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in attendee_html(); display flag only. ?><?php echo $link_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></main><?php echo self::legal_footer( (int) $ticket->occurrence_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in legal_footer(). ?><?php echo self::consent_html( $ticket, isset( $_GET['wpcap_consent_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcap_consent_result'] ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Recommended -- escaped in Consents; display flag only. ?></body></html><?php
		// A407: theme override pass-captain/ticket-page.php (gets the ticket row, the holder line and the default page).
		echo PublicEvents::page( 'ticket-page.php', (string) ob_get_clean(), array( 'ticket' => $ticket, 'holder' => $holder ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the default page; a theme template escapes its own output.
		\PassCaptain\Http::end();
	}

	/**
	 * Query arguments of a link back to the ticket page: the token while the page was opened with it in
	 * the URL, the ticket reference after the cookie exchange (SEC-7).
	 */
	private static function link_args( $token ) {
		return Security\AccessCookie::via_cookie() ? array( 'wpcap_tref' => Security\AccessCookie::ticket_ref( $token ) ) : array( 'wpcap_ticket' => $token );
	}

	/**
	 * Attendee card of the ticket page (A570): name and field answers for the holder of the code, until
	 * `attendee_edit_hours` before the start. '' when the data can no longer be changed.
	 *
	 * @param object $ticket Row of maybe_render() (ticket_id, ticket_type_id, status, starts_at).
	 * @param string $flag   Result of the last submission ('saved', 'unchanged' or an error code).
	 */
	public static function attendee_html( $ticket, $flag = '' ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.status,t.refunded,ord.payment_status,COALESCE(a.attendee_name,'') AS attendee_name FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments a ON a.ticket_id=t.ticket_id WHERE t.ticket_id=%d", (int) $ticket->ticket_id ) );
		$hours = max( 0, (int) Settings::get( 'attendee_edit_hours' ) );
		$open = $row && 'valid' === $row->status && ! (int) $row->refunded && 'paid' === $row->payment_status && strtotime( $ticket->starts_at . ' UTC' ) - $hours * HOUR_IN_SECONDS > Clock::now();
		$notice = '';
		if ( 'saved' === $flag ) {
			$notice = __( 'Teilnehmerdaten gespeichert. Der Ticketcode bleibt unverändert.', 'pass-captain' );
		} elseif ( 'unchanged' === $flag ) {
			$notice = __( 'Es wurde nichts geändert.', 'pass-captain' );
		} elseif ( '' !== $flag ) {
			$messages = array(
				'wpcap_assignment_limit' => __( 'Für dieses Ticket wurde der Teilnehmer bereits geändert. Ein weiterer Wechsel der Person ist nicht möglich; kleine Korrekturen des Namens (Tippfehler) und die Angaben bleiben möglich.', 'pass-captain' ),
				'wpcap_assignment_name_limit' => __( 'Der Teilnehmername dieses Tickets wurde bereits mehrfach geändert. Weitere Änderungen sind nur über den Veranstalter möglich.', 'pass-captain' ),
				'wpcap_assignment_not_allowed' => __( 'Die Teilnehmerdaten dieses Tickets können nicht mehr geändert werden.', 'pass-captain' ),
				'wpcap_attendee_field_locked' => __( 'Eine der Angaben kann nicht mehr selbst geändert werden. Bitte wende dich an den Veranstalter.', 'pass-captain' ),
			);
			$notice = $messages[ $flag ] ?? __( 'Die Teilnehmerdaten konnten nicht gespeichert werden. Bitte prüfe die Angaben.', 'pass-captain' );
		}
		if ( ! $open ) {
			return '' !== $notice ? '<p class="notice" id="wpcap-attendee" role="status">' . esc_html( $notice ) . '</p>' : '';
		}
		$fields = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . ParticipantFields::columns() . " FROM {$p}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active' AND field_type<>'consent'" . ( Consents::ready() ? ' AND consent=0' : '' ) . ' ORDER BY position,field_id', (int) $ticket->ticket_type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ParticipantFields::columns() is a column list from literals; the consent condition is a literal. Consents of both kinds are not part of this form (N03: logged ones have their card, type consent the consent link).
		$answers = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_id,answer FROM {$p}wpcap_participant_answers WHERE ticket_id=%d", (int) $ticket->ticket_id ) ) as $a ) { $answers[ (int) $a->field_id ] = (string) $a->answer; }
		$html = '<section class="manage" id="wpcap-attendee"><h2>' . esc_html__( 'Teilnehmerdaten', 'pass-captain' ) . '</h2>';
		if ( '' !== $notice ) { $html .= '<p class="notice" role="status">' . esc_html( $notice ) . '</p>'; }
		$html .= '<form method="post"><input type="hidden" name="wpcap_attendee_op" value="save"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wpcap_ticket_attendee_' . (int) $ticket->ticket_id ) ) . '">';
		$html .= '<p><label>' . esc_html__( 'Name der teilnehmenden Person', 'pass-captain' ) . '<br><input name="attendee_name" type="text" maxlength="190" required value="' . esc_attr( (string) $row->attendee_name ) . '"></label></p>';
		foreach ( (array) $fields as $field ) {
			$value = $answers[ (int) $field->field_id ] ?? '';
			$name = 'wpcap_answer[' . esc_attr( $field->field_key ) . ']';
			$label = esc_html( $field->label . ( (int) $field->required ? ' *' : '' ) );
			if ( ! ParticipantFields::self_editable( $field, (string) $ticket->starts_at ) ) {
				// A683: locked or past the field's own deadline – shown, not sent.
				$html .= '<p>' . esc_html( $field->label ) . '<br><strong>' . esc_html( '' !== $value ? $value : '—' ) . '</strong> <small>' . esc_html__( '(nicht mehr änderbar)', 'pass-captain' ) . '</small></p>';
				continue;
			}
			if ( 'textarea' === $field->field_type ) {
				$input = '<textarea name="' . $name . '"' . ( (int) $field->required ? ' required' : '' ) . '>' . esc_html( $value ) . '</textarea>';
			} elseif ( 'checkbox' === $field->field_type ) {
				$input = '<input type="hidden" name="' . $name . '" value=""><input type="checkbox" name="' . $name . '" value="1"' . ( '1' === $value ? ' checked' : '' ) . '>';
			} elseif ( '' === ( $input = ParticipantFields::control_html( $field, array( 'name' => 'wpcap_answer[' . $field->field_key . ']' ), $value ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
				$input = '<input type="' . ( 'email' === $field->field_type ? 'email' : 'text' ) . '" name="' . $name . '" value="' . esc_attr( $value ) . '"' . ( (int) $field->required ? ' required' : '' ) . '>';
			}
			$html .= '<p><label>' . $label . '<br>' . $input . '</label></p>';
		}
		$html .= '<p><small>' . esc_html__( 'Der Ticketcode bleibt gleich. Der Name kann korrigiert werden; ein Wechsel auf eine andere Person ist nur begrenzt möglich.', 'pass-captain' ) . '</small></p>';
		if ( $hours ) {
			/* translators: %d: hours */
			$html .= '<p><small>' . esc_html( sprintf( _n( 'Änderungen sind bis %d Stunde vor Beginn möglich.', 'Änderungen sind bis %d Stunden vor Beginn möglich.', $hours, 'pass-captain' ), $hours ) ) . '</small></p>';
		}
		$html .= '<button type="submit" class="print">' . esc_html__( 'Teilnehmerdaten speichern', 'pass-captain' ) . '</button></form></section>';
		return $html;
	}

	/**
	 * Saves the attendee card of the ticket page (A570). The caller verified the ticket code.
	 *
	 * @return string|\WP_Error 'saved' or 'unchanged'.
	 */
	public static function handle_attendee( $ticket, $token, array $post ) {
		$nonce = sanitize_text_field( (string) ( $post['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'wpcap_ticket_attendee_' . (int) $ticket->ticket_id ) ) {
			return new \WP_Error( 'wpcap_nonce', __( 'Das Formular ist abgelaufen. Bitte lade die Seite neu.', 'pass-captain' ) );
		}
		// A680: a multi-select posts a list of chosen labels.
		$answers = isset( $post['wpcap_answer'] ) && is_array( $post['wpcap_answer'] ) ? array_map( static function ( $v ) { return is_array( $v ) ? array_values( array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ) : ( is_scalar( $v ) ? (string) $v : '' ); }, $post['wpcap_answer'] ) : array();
		$result = TicketAssignments::update_by_holder( $token, (string) ( $post['attendee_name'] ?? '' ), $answers );
		return is_wp_error( $result ) ? $result : (string) $result['status'];
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

	/**
	 * SET-PC-19/-33: imprint and privacy links below the ticket (setting ticket_pdf.legal_links; empty
	 * without configured addresses).
	 */
	public static function legal_footer( $occurrence_id = 0 ) {
		$config = TicketPdf::settings_for_event( $occurrence_id ? (int) Reports\Scope::occurrence_event( (int) $occurrence_id ) : 0 );
		$links = ! empty( $config['legal_links'] ) ? Frontend\LegalNotice::links_html() : '';
		return '' !== $links ? '<footer class="muted wpcap-legal-links"><p>' . $links . '</p></footer>' : '';
	}

}
