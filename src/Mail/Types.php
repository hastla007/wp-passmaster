<?php
/**
 * Registry of customer mail types.
 *
 * Each type: label, description, placeholders (key => description, without braces), subject and
 * body (callables returning the built-in, translatable default – evaluated in the recipient's
 * locale), sample (callable returning example values for the admin preview / test mail) and
 * optionally vars (callable receiving the passed values and returning derived ones; passed values win).
 * The defaults are the texts the plugin sent before templates existed, so nothing changes until an
 * override is saved. Extend or change via the `wpcap_mail_types` filter.
 */
namespace PassCaptain\Mail;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Types {
	/** Registry per locale (labels and descriptions are translated). */
	private static $cache = array();

	public static function all() {
		$locale = Mailer::current_locale();
		if ( ! isset( self::$cache[ $locale ] ) ) {
			$core = self::core();
			$types = (array) apply_filters( 'wpcap_mail_types', $core );
			foreach ( $types as $key => $def ) {
				if ( ! is_array( $def ) || ! isset( $def['subject'], $def['body'] ) || ! is_callable( $def['subject'] ) || ! is_callable( $def['body'] ) ) { unset( $types[ $key ] ); continue; }
				$types[ $key ] = array_merge( array( 'label' => $key, 'description' => '', 'placeholders' => array(), 'sample' => static function () { return array(); } ), $def );
			}
			// Core types are required by the plugin's own code paths; filters may change but not remove them.
			self::$cache[ $locale ] = $types + $core;
		}
		return self::$cache[ $locale ];
	}

	/** Clears the registry cache (after registering types late, tests). */
	public static function flush() {
		self::$cache = array();
	}

	/** Placeholders available in every mail. */
	public static function global_placeholders() {
		return array(
			'site_name' => __( 'Name der Website', 'wp-pass-captain' ),
			'site_url' => __( 'Adresse der Website', 'wp-pass-captain' ),
			'terms_text' => __( 'AGB / Teilnahmebedingungen in der Sprache des Empfängers', 'wp-pass-captain' ),
			'consumer_info_text' => __( 'Verbraucherinformationen in der Sprache des Empfängers', 'wp-pass-captain' ),
		);
	}

	private static function ticket_placeholders() {
		return array(
			'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
			'customer_email' => __( 'E-Mail-Adresse der Käufer:in', 'wp-pass-captain' ),
			'event_name' => __( 'Event (erstes Ticket)', 'wp-pass-captain' ),
			'event_date' => __( 'Beginn mit Zeitzone (erstes Ticket)', 'wp-pass-captain' ),
			'event_location' => __( 'Ort (erstes Ticket)', 'wp-pass-captain' ),
			'ticket_count' => __( 'Anzahl Tickets', 'wp-pass-captain' ),
			'ticket_list' => __( 'Ticketliste mit Datum, Ort, Link (und Code bzw. Wallet-Links)', 'wp-pass-captain' ),
			'ticket_links' => __( 'Nur die Ticket-Links, einer pro Zeile', 'wp-pass-captain' ),
		);
	}

	private static function single_ticket_placeholders() {
		return array(
			'event_name' => __( 'Event', 'wp-pass-captain' ),
			'ticket_name' => __( 'Ticketart', 'wp-pass-captain' ),
			'event_date' => __( 'Beginn mit Zeitzone', 'wp-pass-captain' ),
			'event_location' => __( 'Ort', 'wp-pass-captain' ),
		);
	}

	/** Sample ticket entries for previews. */
	private static function sample_tickets() {
		$when = '15.01.2027 19:30 Europe/Vienna';
		return array(
			array( 'event' => 'Sommerkonzert', 'ticket' => 'Stehplatz', 'when' => $when, 'location' => 'Stadthalle Wien', 'url' => home_url( '/?wpcap_ticket=' . str_repeat( 'a1', 32 ) ), 'token' => str_repeat( 'a1', 32 ), 'extra' => '' ),
			array( 'event' => 'Sommerkonzert', 'ticket' => 'Stehplatz', 'when' => $when, 'location' => 'Stadthalle Wien', 'url' => home_url( '/?wpcap_ticket=' . str_repeat( 'b2', 32 ) ), 'token' => str_repeat( 'b2', 32 ), 'extra' => '' ),
		);
	}

	private static function sample_ticket_vars( $resend ) {
		$tickets = self::sample_tickets();
		return array(
			'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e',
			'customer_email' => 'kundin@example.com',
			'event_name' => 'Sommerkonzert',
			'event_date' => '15.01.2027 19:30 Europe/Vienna',
			'event_location' => 'Stadthalle Wien',
			'ticket_count' => 2,
			'ticket_list' => $resend ? Blocks::code_tickets( $tickets ) : Blocks::issued_tickets( $tickets ),
			'ticket_links' => Blocks::links( array_column( $tickets, 'url' ) ),
		);
	}

	private static function sample_single_ticket() {
		return array(
			'event_name' => 'Sommerkonzert',
			'ticket_name' => 'Stehplatz',
			'event_date' => '15.01.2027 19:30 Europe/Vienna',
			'event_location' => 'Stadthalle Wien',
			'ticket_link' => Mailer::link( home_url( '/?wpcap_ticket=' . str_repeat( 'c3', 32 ) ) ),
			'ticket_extras' => Blocks::extra( '' ),
		);
	}

	/**
	 * Derived placeholders of the access-link mail: short order number and, while a manual payment
	 * is pending, the payment details.
	 */
	public static function order_access_vars( $public_id ) {
		global $wpdb;
		$vars = array( 'order_reference' => \PassCaptain\Frontend\Format::order_reference( $public_id ), 'payment_instructions' => Blocks::payment_instructions( null ), 'order_items' => Blocks::order_items( array() ) );
		if ( 32 !== strlen( $public_id ) ) { return $vars; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,payment_provider,payment_status,total_minor,currency FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( $order ) { $vars['order_items'] = Blocks::order_items( \PassCaptain\Orders::summary_lines( (int) $order->order_id ) ); }
		if ( $order && \PassCaptain\Payments\BankTransfer::applies( $order ) ) {
			$vars['payment_instructions'] = Blocks::payment_instructions( \PassCaptain\Payments\BankTransfer::details( $order ) );
		}
		return $vars;
	}

	/** Built-in mail types. */
	private static function core() {
		return array(
			'tickets' => array(
				'label' => __( 'Ticketversand', 'wp-pass-captain' ),
				'description' => __( 'Tickets nach bestätigter Zahlung (mit PDF-Anhang).', 'wp-pass-captain' ),
				'placeholders' => self::ticket_placeholders(),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Tickets für %s', 'wp-pass-captain' ), '{site_name}' ); },
				/* translators: %s: site name */
				'body' => static function () { return sprintf( __( 'Deine Tickets für %s:', 'wp-pass-captain' ), '{site_name}' ) . "\n\n{ticket_list}"; },
				'sample' => static function () { return self::sample_ticket_vars( false ); },
			),
			'tickets_resend' => array(
				'label' => __( 'Tickets erneut senden', 'wp-pass-captain' ),
				'description' => __( 'Erneuter Versand mit neuen Ticketcodes (je Empfänger:in).', 'wp-pass-captain' ),
				'placeholders' => self::ticket_placeholders(),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Tickets für %s', 'wp-pass-captain' ), '{site_name}' ); },
				/* translators: %s: site name */
				'body' => static function () { return sprintf( __( 'Deine Tickets für %s:', 'wp-pass-captain' ), '{site_name}' ) . "\n\n{ticket_list}"; },
				'sample' => static function () { return self::sample_ticket_vars( true ); },
			),
			'order_access' => array(
				'label' => __( 'Bestellzugang', 'wp-pass-captain' ),
				'description' => __( 'Persönlicher Link zur Bestellübersicht nach der Bestellung.', 'wp-pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
					'order_reference' => __( 'Kurze Bestellnummer (z. B. 3F2A-9C1E, auch Verwendungszweck)', 'wp-pass-captain' ),
					'access_link' => __( 'Persönlicher Link zur Bestellung', 'wp-pass-captain' ),
					'payment_instructions' => __( 'Zahlungsinformationen bei offener Überweisung/manueller Zahlung (Betrag, IBAN, BIC, Empfänger, Verwendungszweck, Frist); sonst leer', 'wp-pass-captain' ),
					'order_items' => __( 'Bestellte Positionen (Event, Ticketart, Termin, Menge, Preis)', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Bestellung bei %s', 'wp-pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					// {payment_instructions} is empty unless a manual payment is pending; it brings its own trailing blank line.
					/* translators: %s: site name */
					return sprintf( __( 'Über diesen persönlichen Link kannst du den Status deiner Bestellung bei %s ansehen und Tickets verwalten:', 'wp-pass-captain' ), '{site_name}' ) . "\n\n{access_link}\n\n{order_items}{payment_instructions}" . __( 'Bewahre diesen Link sicher auf und teile ihn nicht.', 'wp-pass-captain' );
				},
				// Values derived from the order when the caller does not pass them.
				'vars' => static function ( array $vars ) { return self::order_access_vars( (string) ( $vars['order_id'] ?? '' ) ); },
				'sample' => static function () {
					return array(
						'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e',
						'order_reference' => '3F2A-9C1E',
						'access_link' => Mailer::link( home_url( '/?wpcap_order=3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e&wpcap_access=' . str_repeat( 'd4', 32 ) ) ),
						'order_items' => Blocks::order_items( array( array( 'event' => 'Sommerkonzert', 'ticket' => 'Stehplatz', 'when' => '20.07.2027 19:30', 'quantity' => 2, 'gross_minor' => 5000, 'currency' => 'EUR' ) ) ),
						'payment_instructions' => Blocks::payment_instructions( array( 'reference' => '3F2A-9C1E', 'remittance' => sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'wp-pass-captain' ), '3F2A-9C1E' ), 'amount' => '50,00 €', 'recipient' => 'Kulturverein Musterstadt', 'iban' => 'AT61 1904 3002 3457 3201', 'bic' => 'BKAUATWW', 'due_date' => '18.01.2027 12:00', 'instructions' => '' ) ),
					);
				},
			),
			'ticket_transfer_recipient' => array(
				'label' => __( 'Ticketübertragung: Empfänger:in', 'wp-pass-captain' ),
				'description' => __( 'Neues Ticket für die Person, an die ein Ticket übertragen wurde.', 'wp-pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'ticket_link' => __( 'Link zum Ticket', 'wp-pass-captain' ),
					'ticket_extras' => __( 'Zusatzinformationen zum Ticket (z. B. Wallet-Links)', 'wp-pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
				),
				'subject' => static function () { return __( 'Ein Ticket wurde an dich übertragen', 'wp-pass-captain' ); },
				'body' => static function () { return "{event_name} — {ticket_name}\n{event_date}\n{event_location}\n\n{ticket_link}\n{ticket_extras}"; },
				'sample' => static function () { return self::sample_single_ticket() + array( 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_transfer_sender' => array(
				'label' => __( 'Ticketübertragung: Bestätigung', 'wp-pass-captain' ),
				'description' => __( 'Bestätigung an die Käufer:in, dass ein Ticket übertragen wurde.', 'wp-pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'recipient_email' => __( 'E-Mail-Adresse der neuen Inhaber:in', 'wp-pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
				),
				'subject' => static function () { return __( 'Ticketübertragung abgeschlossen', 'wp-pass-captain' ); },
				/* translators: 1: event, 2: recipient e-mail */
				'body' => static function () { return sprintf( __( 'Dein Ticket für %1$s wurde an %2$s übertragen. Der bisherige Ticketcode ist ungültig.', 'wp-pass-captain' ), '{event_name}', '{recipient_email}' ); },
				'sample' => static function () { return self::sample_single_ticket() + array( 'recipient_email' => 'freundin@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_exchanged' => array(
				'label' => __( 'Ticketumtausch', 'wp-pass-captain' ),
				'description' => __( 'Neues Ticket nach einem Umtausch (anderer Termin oder andere Ticketart); der bisherige Code ist ungültig.', 'wp-pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'old_event_name' => __( 'Bisheriges Event', 'wp-pass-captain' ),
					'old_ticket_name' => __( 'Bisherige Ticketart', 'wp-pass-captain' ),
					'old_event_date' => __( 'Bisheriger Beginn mit Zeitzone', 'wp-pass-captain' ),
					'seat' => __( 'Sitzplatz (leer ohne Saalplan)', 'wp-pass-captain' ),
					'seat_note' => __( 'Zeile „Sitzplatz: …“ mit Zeilenumbruch (leer ohne Saalplan)', 'wp-pass-captain' ),
					'price_note' => __( 'Hinweis zu Aufpreis, Gebühr oder Erstattung mit Zeilenumbruch (leer ohne Preisdifferenz)', 'wp-pass-captain' ),
					'ticket_link' => __( 'Link zum neuen Ticket', 'wp-pass-captain' ),
					'ticket_extras' => __( 'Zusatzinformationen zum Ticket (z. B. Wallet-Links)', 'wp-pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Dein Ticket wurde umgetauscht – %s', 'wp-pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					/* translators: 1: previous event, 2: previous ticket type, 3: previous date */
					return __( 'Dein Ticket wurde umgetauscht.', 'wp-pass-captain' ) . "\n\n" . sprintf( __( 'Bisher: %1$s — %2$s (%3$s)', 'wp-pass-captain' ), '{old_event_name}', '{old_ticket_name}', '{old_event_date}' ) . "\n" . __( 'Neu:', 'wp-pass-captain' ) . " {event_name} — {ticket_name}\n{event_date}\n{event_location}\n{seat_note}\n{ticket_link}\n{ticket_extras}{price_note}" . __( 'Der bisherige Ticketcode ist ab sofort ungültig. Bitte verwende nur noch das neue Ticket.', 'wp-pass-captain' );
				},
				'sample' => static function () {
					return self::sample_single_ticket() + array( 'old_event_name' => 'Sommerkonzert', 'old_ticket_name' => 'Stehplatz', 'old_event_date' => '14.01.2027 19:30 Europe/Vienna', 'ticket_name' => 'Sitzplatz Parkett', 'seat' => 'Reihe A, Platz 4', 'seat_note' => __( 'Sitzplatz:', 'wp-pass-captain' ) . " Reihe A, Platz 4\n", 'price_note' => self::exchange_price_note( 'paid', '20,00 EUR' ), 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' );
				},
			),
			'ticket_assigned_attendee' => array(
				'label' => __( 'Teilnehmerzuweisung: Teilnehmer:in', 'wp-pass-captain' ),
				'description' => __( 'Ticket für eine Person, der die Käufer:in ein Ticket zugewiesen hat.', 'wp-pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'attendee_name' => __( 'Name der Teilnehmer:in', 'wp-pass-captain' ),
					'attendee_email' => __( 'E-Mail-Adresse der Teilnehmer:in', 'wp-pass-captain' ),
					'ticket_link' => __( 'Link zum Ticket', 'wp-pass-captain' ),
					'ticket_extras' => __( 'Zusatzinformationen zum Ticket (z. B. Wallet-Links)', 'wp-pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Dein Ticket für %s', 'wp-pass-captain' ), '{site_name}' ); },
				/* translators: %s: attendee name */
				'body' => static function () { return "{event_name} — {ticket_name}\n{event_date}\n{event_location}\n" . sprintf( __( 'Teilnehmer: %s', 'wp-pass-captain' ), '{attendee_name}' ) . "\n\n{ticket_link}{ticket_extras}"; },
				'sample' => static function () { return self::sample_single_ticket() + array( 'attendee_name' => 'Alex Muster', 'attendee_email' => 'alex@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_assigned_buyer' => array(
				'label' => __( 'Teilnehmerzuweisung: Käufer:in', 'wp-pass-captain' ),
				'description' => __( 'Hinweis an die Käufer:in, dass der bisherige Code eines zugewiesenen Tickets ungültig ist.', 'wp-pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'attendee_name' => __( 'Name der Teilnehmer:in', 'wp-pass-captain' ),
					'attendee_email' => __( 'E-Mail-Adresse der Teilnehmer:in', 'wp-pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Ticket zugewiesen – %s', 'wp-pass-captain' ), '{site_name}' ); },
				/* translators: 1: event and ticket, 2: date, 3: attendee name, 4: attendee email */
				'body' => static function () { return sprintf( __( "Das Ticket %1\$s (%2\$s) wurde %3\$s (%4\$s) zugewiesen. Der neue Ticketcode wurde an diese Adresse gesendet; der bisherige Code für dieses Ticket ist nicht mehr gültig.", 'wp-pass-captain' ), '{event_name} — {ticket_name}', '{event_date}', '{attendee_name}', '{attendee_email}' ); },
				'sample' => static function () { return self::sample_single_ticket() + array( 'attendee_name' => 'Alex Muster', 'attendee_email' => 'alex@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'refund_confirmation' => array(
				'label' => __( 'Erstattungsbestätigung', 'wp-pass-captain' ),
				'description' => __( 'Bestätigung einer abgeschlossenen Erstattung (nicht bei WooCommerce-Bestellungen).', 'wp-pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
					'refund_amount' => __( 'Erstatteter Betrag mit Währung', 'wp-pass-captain' ),
					'revoked_count' => __( 'Anzahl ungültig gewordener Tickets', 'wp-pass-captain' ),
					'revoked_note' => __( 'Satz zu ungültig gewordenen Tickets mit Zeilenumbruch (leer, wenn keine)', 'wp-pass-captain' ),
					'credit_note_number' => __( 'Nummer der Rechnungskorrektur', 'wp-pass-captain' ),
					'credit_note' => __( 'Zeile „Rechnungskorrektur: …“ mit Zeilenumbruch (leer ohne Rechnungskorrektur)', 'wp-pass-captain' ),
					'reason' => __( 'Grund der Erstattung', 'wp-pass-captain' ),
					'refund_intro' => __( 'Einleitungssatz passend zur Zahlart („haben wir … erstattet“ bzw. „erstatten wir … per Überweisung“)', 'wp-pass-captain' ),
					'refund_method_note' => __( 'Hinweis zur Auszahlung je Zahlart (Kartenrückbuchung, bar an der Kasse, Überweisung auf IBAN bzw. Bitte um IBAN)', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Erstattung bestätigt – %s', 'wp-pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					return "{refund_intro}\n{revoked_note}{credit_note}\n{refund_method_note}";
				},
				// Defaults when the caller passes no payment context (wording of card/wallet providers).
				'vars' => static function ( array $vars ) {
					return array(
						'refund_intro' => static function () use ( $vars ) {
							$amount = $vars['refund_amount'] ?? '';
							$amount = is_callable( $amount ) ? (string) call_user_func( $amount ) : (string) $amount;
							return \PassCaptain\PayoutAccount::refund_intro( array(), (string) ( $vars['order_id'] ?? '' ), $amount );
						},
						'refund_method_note' => static function () { return \PassCaptain\PayoutAccount::refund_note( array() ); },
					);
				},
				'sample' => static function () {
					return array( 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e', 'refund_amount' => '25,00 EUR', 'revoked_count' => 1, 'revoked_note' => self::revoked_note( 1 ), 'credit_note_number' => 'RK-2027-000001', 'credit_note' => self::credit_note( 'RK-2027-000001' ), 'reason' => 'Kulanz' );
				},
			),
			'refund_request_received' => array(
				'label' => __( 'Stornierungsanfrage eingegangen', 'wp-pass-captain' ),
				'description' => __( 'Bestätigung an die Käufer:in, dass ihre Stornierungsanfrage aus dem Bestellportal eingegangen ist und geprüft wird (noch keine Erstattung).', 'wp-pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
					'ticket_count' => __( 'Anzahl angefragter Tickets', 'wp-pass-captain' ),
					'ticket_list' => __( 'Angefragte Tickets, eines pro Zeile (Event, Ticketart, Datum)', 'wp-pass-captain' ),
					'refund_amount' => __( 'Voraussichtlicher Erstattungsbetrag mit Währung', 'wp-pass-captain' ),
					'reason' => __( 'Angegebener Grund (leer, wenn keiner)', 'wp-pass-captain' ),
					'portal_link' => __( 'Persönlicher Link zur Bestellung (Status der Anfrage)', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Stornierungsanfrage – %s', 'wp-pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					/* translators: 1: short order number, 2: amount */
					return sprintf( __( 'Wir haben deine Stornierungsanfrage für die Bestellung %1$s erhalten. Voraussichtliche Erstattung: %2$s.', 'wp-pass-captain' ), '{order_id}', '{refund_amount}' ) . "\n\n{ticket_list}\n\n"
						. __( 'Deine Tickets bleiben gültig, bis wir die Anfrage geprüft haben. Wird sie angenommen, werden die Tickets ungültig und du erhältst eine Erstattungsbestätigung. Den Stand siehst du jederzeit in deiner Bestellung:', 'wp-pass-captain' ) . "\n{portal_link}";
				},
				'sample' => static function () {
					return array( 'order_id' => '3F2A-9C1E', 'ticket_count' => 1, 'ticket_list' => 'Sommerkonzert — Stehplatz, 21.06.2027 20:00 Europe/Vienna', 'refund_amount' => '25,00 EUR', 'reason' => 'Krank', 'portal_link' => 'https://example.com/?wpcap_order=3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e&wpcap_access=…' );
				},
			),
			'refund_request_rejected' => array(
				'label' => __( 'Stornierungsanfrage abgelehnt', 'wp-pass-captain' ),
				'description' => __( 'Information an die Käufer:in, dass ihre Stornierungsanfrage aus dem Bestellportal nicht angenommen wurde (Tickets bleiben gültig).', 'wp-pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'wp-pass-captain' ),
					'ticket_count' => __( 'Anzahl angefragter Tickets', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Stornierungsanfrage – %s', 'wp-pass-captain' ), '{site_name}' ); },
				/* translators: %s: short order number */
				'body' => static function () { return sprintf( __( 'Deine Stornierungsanfrage für die Bestellung %s wurde nicht angenommen. Deine Tickets bleiben gültig. Bei Fragen wende dich bitte an den Veranstalter.', 'wp-pass-captain' ), '{order_id}' ); },
				'sample' => static function () { return array( 'order_id' => '3F2A-9C1E', 'ticket_count' => 1 ); },
			),
			'event_cancelled' => array(
				'label' => __( 'Eventabsage', 'wp-pass-captain' ),
				'description' => __( 'Information an Käufer:innen und Ticketinhaber:innen, wenn ein ganzes Event abgesagt wird.', 'wp-pass-captain' ),
				'placeholders' => array( 'event_name' => __( 'Event', 'wp-pass-captain' ) ),
				'subject' => static function () { return sprintf( __( 'Eventabsage: %s', 'wp-pass-captain' ), '{event_name}' ); },
				'body' => static function () { return sprintf( __( 'Das Event „%s“ wurde abgesagt. Bitte kontaktiere den Veranstalter für Informationen zu den nächsten Schritten. Diese Nachricht ist keine Bestätigung einer Erstattung.', 'wp-pass-captain' ), '{event_name}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert' ); },
			),
			'occurrence_cancelled' => array(
				'label' => __( 'Terminabsage', 'wp-pass-captain' ),
				'description' => __( 'Information an Ticketinhaber:innen, wenn ein einzelner Termin abgesagt wird.', 'wp-pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'wp-pass-captain' ),
					'event_date' => __( 'Datum und Uhrzeit des Termins', 'wp-pass-captain' ),
					'event_location' => __( 'Ort', 'wp-pass-captain' ),
					'timezone' => __( 'Zeitzone', 'wp-pass-captain' ),
					'refund_note' => __( 'Hinweis zur Erstattung (automatisch oder durch den Veranstalter)', 'wp-pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Absage: %s', 'wp-pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: date and time, 3: refund note */
				'body' => static function () { return sprintf( __( "Der Termin „%1\$s“ am %2\$s wurde abgesagt. Dein Ticket ist dafür nicht mehr gültig.\n\n%3\$s", 'wp-pass-captain' ), '{event_name}', '{event_date}', '{refund_note}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'event_date' => '15.01.2027 19:30', 'event_location' => 'Stadthalle Wien', 'timezone' => 'Europe/Vienna', 'refund_note' => __( 'Der Ticketpreis wird automatisch erstattet.', 'wp-pass-captain' ) ); },
			),
			'occurrence_rescheduled' => array(
				'label' => __( 'Terminänderung', 'wp-pass-captain' ),
				'description' => __( 'Information an Ticketinhaber:innen, wenn ein Termin verschoben wird.', 'wp-pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'wp-pass-captain' ),
					'old_date' => __( 'Bisheriges Datum und Uhrzeit', 'wp-pass-captain' ),
					'event_date' => __( 'Neues Datum und Uhrzeit', 'wp-pass-captain' ),
					'timezone' => __( 'Zeitzone', 'wp-pass-captain' ),
					'event_location' => __( 'Ort', 'wp-pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Terminänderung: %s', 'wp-pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: old date, 3: new date, 4: timezone, 5: location */
				'body' => static function () { return sprintf( __( "Der Termin für „%1\$s“ wurde verschoben.\n\nBisher: %2\$s\nNeu: %3\$s (%4\$s)\nOrt: %5\$s\n\nDein Ticket bleibt gültig. Bei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.", 'wp-pass-captain' ), '{event_name}', '{old_date}', '{event_date}', '{timezone}', '{event_location}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'old_date' => '15.01.2027 19:30', 'event_date' => '22.01.2027 20:00', 'timezone' => 'Europe/Vienna', 'event_location' => 'Stadthalle Wien' ); },
			),
			'waitlist_joined' => array(
				'label' => __( 'Warteliste: Eintragung', 'wp-pass-captain' ),
				'description' => __( 'Bestätigung der Eintragung in die Warteliste.', 'wp-pass-captain' ),
				'placeholders' => array(
					'position' => __( 'Position auf der Warteliste', 'wp-pass-captain' ),
					'quantity' => __( 'Gewünschte Anzahl', 'wp-pass-captain' ),
					'event_name' => __( 'Event', 'wp-pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'wp-pass-captain' ),
				),
				'subject' => static function () { return __( 'Du stehst auf der Warteliste', 'wp-pass-captain' ); },
				/* translators: %d: waitlist position */
				'body' => static function () { return str_replace( '%d', '{position}', __( 'Du bist auf der Warteliste eingetragen (Position %d). Wir schreiben dir, sobald ein Platz frei wird.', 'wp-pass-captain' ) ); },
				'sample' => static function () { return array( 'position' => 3, 'quantity' => 2, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz' ); },
			),
			'waitlist_offer' => array(
				'label' => __( 'Warteliste: Platzangebot', 'wp-pass-captain' ),
				'description' => __( 'Angebot an die nächste Person auf der Warteliste; die Tickets sind bis zum Ablauf reserviert.', 'wp-pass-captain' ),
				'placeholders' => array(
					'offer_expires' => __( 'Ablauf des Angebots', 'wp-pass-captain' ),
					'accept_link' => __( 'Link zur Annahme', 'wp-pass-captain' ),
					'quantity' => __( 'Anzahl der reservierten Tickets', 'wp-pass-captain' ),
					'position' => __( 'Position auf der Warteliste', 'wp-pass-captain' ),
					'event_name' => __( 'Event', 'wp-pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'wp-pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Tickets für dich reserviert: %s', 'wp-pass-captain' ), '{event_name}' ); },
				/* translators: 1: quantity, 2: ticket type, 3: event, 4: expiry date, 5: accept link */
				'body' => static function () { return sprintf( __( "Gute Nachrichten: Für dich sind %1\$s × „%2\$s“ für „%3\$s“ reserviert.\n\nDie Reservierung gilt bis %4\$s. Nimm das Angebot bis dahin an und schließe die Bestellung ab, sonst geht es an die nächste Person auf der Warteliste:\n%5\$s\n\nWenn du die Tickets nicht mehr brauchst, kannst du das Angebot über denselben Link ablehnen.", 'wp-pass-captain' ), '{quantity}', '{ticket_name}', '{event_name}', '{offer_expires}', '{accept_link}' ); },
				'sample' => static function () { return array( 'offer_expires' => '16.01.2027 19:30 UTC', 'accept_link' => Mailer::link( home_url( '/?wpcap_waitlist_accept=12&token=' . str_repeat( 'e5', 32 ) ) ), 'quantity' => 2, 'position' => 1, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz' ); },
			),
			'member_pass' => array(
				'label' => __( 'Mitgliedspass', 'wp-pass-captain' ),
				'description' => __( 'Passcode einer Mitgliedschaft bzw. eines Mehrfachpasses.', 'wp-pass-captain' ),
				'placeholders' => array(
					'pass_code' => __( 'Passcode', 'wp-pass-captain' ),
					'valid_from' => __( 'Gültig ab', 'wp-pass-captain' ),
					'valid_until' => __( 'Gültig bis', 'wp-pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Dein Pass für %s', 'wp-pass-captain' ), '{site_name}' ); },
				'body' => static function () { return __( 'Dein Passcode:', 'wp-pass-captain' ) . " {pass_code}\n" . __( 'Gültig bis:', 'wp-pass-captain' ) . ' {valid_until}'; },
				'sample' => static function () { return array( 'pass_code' => str_repeat( 'f6', 32 ), 'valid_from' => '2027-01-01 00:00:00', 'valid_until' => '2027-12-31 23:59:59' ); },
			),
		);
	}

	/** Refund mail: sentence about revoked tickets incl. line break ('' for none). Call inside the recipient locale. */
	public static function revoked_note( $count ) {
		$count = (int) $count;
		/* translators: %d: number of tickets */
		return $count ? sprintf( _n( '%d Ticket ist damit ungültig.', '%d Tickets sind damit ungültig.', $count, 'wp-pass-captain' ), $count ) . "\n" : '';
	}

	/**
	 * Exchange mail: note on the price difference incl. trailing blank line ('' when nothing applies).
	 * Call inside the recipient locale.
	 *
	 * @param string $kind   paid (supplement paid), refund (difference refunded), refund_pending (refund awaits approval), waived (goodwill), none.
	 * @param string $amount Formatted amount with currency.
	 */
	public static function exchange_price_note( $kind, $amount = '' ) {
		switch ( (string) $kind ) {
			/* translators: %s: amount */
			case 'paid': return sprintf( __( 'Aufpreis bezahlt: %s', 'wp-pass-captain' ), $amount ) . "\n\n";
			/* translators: %s: amount */
			case 'refund': return sprintf( __( 'Die Preisdifferenz von %s wird erstattet.', 'wp-pass-captain' ), $amount ) . "\n\n";
			/* translators: %s: amount */
			case 'refund_pending': return sprintf( __( 'Die Erstattung der Preisdifferenz von %s wird geprüft.', 'wp-pass-captain' ), $amount ) . "\n\n";
			case 'waived': return __( 'Der Umtausch erfolgte aus Kulanz ohne Aufpreis.', 'wp-pass-captain' ) . "\n\n";
		}
		return '';
	}

	/** Refund mail: correction document line incl. line break ('' without number). Call inside the recipient locale. */
	public static function credit_note( $number ) {
		/* translators: %s: credit note number */
		return '' !== (string) $number ? sprintf( __( 'Rechnungskorrektur: %s', 'wp-pass-captain' ), $number ) . "\n" : '';
	}
}
