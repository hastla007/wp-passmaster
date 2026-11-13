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
			'site_name' => __( 'Name der Website', 'pass-captain' ),
			'site_url' => __( 'Adresse der Website', 'pass-captain' ),
		);
	}

	private static function ticket_placeholders() {
		return array(
			'order_id' => __( 'Bestellnummer', 'pass-captain' ),
			'customer_email' => __( 'E-Mail-Adresse der Käufer:in', 'pass-captain' ),
			'event_name' => __( 'Event (erstes Ticket)', 'pass-captain' ),
			'event_date' => __( 'Beginn mit Zeitzone (erstes Ticket)', 'pass-captain' ),
			'event_location' => __( 'Ort (erstes Ticket)', 'pass-captain' ),
			'ticket_count' => __( 'Anzahl Tickets', 'pass-captain' ),
			'ticket_list' => __( 'Ticketliste mit Datum, Ort, Link (und Code bzw. Wallet-Links)', 'pass-captain' ),
			'ticket_links' => __( 'Nur die Ticket-Links, einer pro Zeile', 'pass-captain' ),
		);
	}

	private static function single_ticket_placeholders() {
		return array(
			'event_name' => __( 'Event', 'pass-captain' ),
			'ticket_name' => __( 'Ticketart', 'pass-captain' ),
			'event_date' => __( 'Beginn mit Zeitzone', 'pass-captain' ),
			'event_location' => __( 'Ort', 'pass-captain' ),
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
		$vars = array( 'order_reference' => \PassCaptain\Frontend\Format::order_reference( $public_id ), 'payment_instructions' => Blocks::payment_instructions( null ) );
		if ( 32 !== strlen( $public_id ) ) { return $vars; }
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,public_id,payment_provider,payment_status,total_minor,currency FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $public_id ) );
		if ( $order && \PassCaptain\Payments\BankTransfer::applies( $order ) ) {
			$vars['payment_instructions'] = Blocks::payment_instructions( \PassCaptain\Payments\BankTransfer::details( $order ) );
		}
		return $vars;
	}

	/** Built-in mail types. */
	private static function core() {
		return array(
			'tickets' => array(
				'label' => __( 'Ticketversand', 'pass-captain' ),
				'description' => __( 'Tickets nach bestätigter Zahlung (mit PDF-Anhang).', 'pass-captain' ),
				'placeholders' => self::ticket_placeholders(),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Tickets für %s', 'pass-captain' ), '{site_name}' ); },
				/* translators: %s: site name */
				'body' => static function () { return sprintf( __( 'Deine Tickets für %s:', 'pass-captain' ), '{site_name}' ) . "\n\n{ticket_list}"; },
				'sample' => static function () { return self::sample_ticket_vars( false ); },
			),
			'tickets_resend' => array(
				'label' => __( 'Tickets erneut senden', 'pass-captain' ),
				'description' => __( 'Erneuter Versand mit neuen Ticketcodes (je Empfänger:in).', 'pass-captain' ),
				'placeholders' => self::ticket_placeholders(),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Tickets für %s', 'pass-captain' ), '{site_name}' ); },
				/* translators: %s: site name */
				'body' => static function () { return sprintf( __( 'Deine Tickets für %s:', 'pass-captain' ), '{site_name}' ) . "\n\n{ticket_list}"; },
				'sample' => static function () { return self::sample_ticket_vars( true ); },
			),
			'order_access' => array(
				'label' => __( 'Bestellzugang', 'pass-captain' ),
				'description' => __( 'Persönlicher Link zur Bestellübersicht nach der Bestellung.', 'pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
					'order_reference' => __( 'Kurze Bestellnummer (z. B. 3F2A-9C1E, auch Verwendungszweck)', 'pass-captain' ),
					'access_link' => __( 'Persönlicher Link zur Bestellung', 'pass-captain' ),
					'payment_instructions' => __( 'Zahlungsinformationen bei offener Überweisung/manueller Zahlung (Betrag, IBAN, BIC, Empfänger, Verwendungszweck, Frist); sonst leer', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Bestellung bei %s', 'pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					// {payment_instructions} is empty unless a manual payment is pending; it brings its own trailing blank line.
					/* translators: %s: site name */
					return sprintf( __( 'Über diesen persönlichen Link kannst du den Status deiner Bestellung bei %s ansehen und Tickets verwalten:', 'pass-captain' ), '{site_name}' ) . "\n\n{access_link}\n\n{payment_instructions}" . __( 'Bewahre diesen Link sicher auf und teile ihn nicht.', 'pass-captain' );
				},
				// Values derived from the order when the caller does not pass them.
				'vars' => static function ( array $vars ) { return self::order_access_vars( (string) ( $vars['order_id'] ?? '' ) ); },
				'sample' => static function () {
					return array(
						'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e',
						'order_reference' => '3F2A-9C1E',
						'access_link' => Mailer::link( home_url( '/?wpcap_order=3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e&wpcap_access=' . str_repeat( 'd4', 32 ) ) ),
						'payment_instructions' => Blocks::payment_instructions( array( 'reference' => '3F2A-9C1E', 'remittance' => sprintf( /* translators: %s: short order number */ __( 'Bestellung %s', 'pass-captain' ), '3F2A-9C1E' ), 'amount' => '50,00 €', 'recipient' => 'Kulturverein Musterstadt', 'iban' => 'AT61 1904 3002 3457 3201', 'bic' => 'BKAUATWW', 'due_date' => '18.01.2027 12:00', 'instructions' => '' ) ),
					);
				},
			),
			'ticket_transfer_recipient' => array(
				'label' => __( 'Ticketübertragung: Empfänger:in', 'pass-captain' ),
				'description' => __( 'Neues Ticket für die Person, an die ein Ticket übertragen wurde.', 'pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'ticket_link' => __( 'Link zum Ticket', 'pass-captain' ),
					'ticket_extras' => __( 'Zusatzinformationen zum Ticket (z. B. Wallet-Links)', 'pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
				),
				'subject' => static function () { return __( 'Ein Ticket wurde an dich übertragen', 'pass-captain' ); },
				'body' => static function () { return "{event_name} — {ticket_name}\n{event_date}\n{event_location}\n\n{ticket_link}\n{ticket_extras}"; },
				'sample' => static function () { return self::sample_single_ticket() + array( 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_transfer_sender' => array(
				'label' => __( 'Ticketübertragung: Bestätigung', 'pass-captain' ),
				'description' => __( 'Bestätigung an die Käufer:in, dass ein Ticket übertragen wurde.', 'pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'recipient_email' => __( 'E-Mail-Adresse der neuen Inhaber:in', 'pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
				),
				'subject' => static function () { return __( 'Ticketübertragung abgeschlossen', 'pass-captain' ); },
				/* translators: 1: event, 2: recipient e-mail */
				'body' => static function () { return sprintf( __( 'Dein Ticket für %1$s wurde an %2$s übertragen. Der bisherige Ticketcode ist ungültig.', 'pass-captain' ), '{event_name}', '{recipient_email}' ); },
				'sample' => static function () { return self::sample_single_ticket() + array( 'recipient_email' => 'freundin@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_assigned_attendee' => array(
				'label' => __( 'Teilnehmerzuweisung: Teilnehmer:in', 'pass-captain' ),
				'description' => __( 'Ticket für eine Person, der die Käufer:in ein Ticket zugewiesen hat.', 'pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'attendee_name' => __( 'Name der Teilnehmer:in', 'pass-captain' ),
					'attendee_email' => __( 'E-Mail-Adresse der Teilnehmer:in', 'pass-captain' ),
					'ticket_link' => __( 'Link zum Ticket', 'pass-captain' ),
					'ticket_extras' => __( 'Zusatzinformationen zum Ticket (z. B. Wallet-Links)', 'pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Dein Ticket für %s', 'pass-captain' ), '{site_name}' ); },
				/* translators: %s: attendee name */
				'body' => static function () { return "{event_name} — {ticket_name}\n{event_date}\n{event_location}\n" . sprintf( __( 'Teilnehmer: %s', 'pass-captain' ), '{attendee_name}' ) . "\n\n{ticket_link}{ticket_extras}"; },
				'sample' => static function () { return self::sample_single_ticket() + array( 'attendee_name' => 'Alex Muster', 'attendee_email' => 'alex@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_assigned_buyer' => array(
				'label' => __( 'Teilnehmerzuweisung: Käufer:in', 'pass-captain' ),
				'description' => __( 'Hinweis an die Käufer:in, dass der bisherige Code eines zugewiesenen Tickets ungültig ist.', 'pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'attendee_name' => __( 'Name der Teilnehmer:in', 'pass-captain' ),
					'attendee_email' => __( 'E-Mail-Adresse der Teilnehmer:in', 'pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Ticket zugewiesen – %s', 'pass-captain' ), '{site_name}' ); },
				/* translators: 1: event and ticket, 2: date, 3: attendee name, 4: attendee email */
				'body' => static function () { return sprintf( __( "Das Ticket %1\$s (%2\$s) wurde %3\$s (%4\$s) zugewiesen. Der neue Ticketcode wurde an diese Adresse gesendet; der bisherige Code für dieses Ticket ist nicht mehr gültig.", 'pass-captain' ), '{event_name} — {ticket_name}', '{event_date}', '{attendee_name}', '{attendee_email}' ); },
				'sample' => static function () { return self::sample_single_ticket() + array( 'attendee_name' => 'Alex Muster', 'attendee_email' => 'alex@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'refund_confirmation' => array(
				'label' => __( 'Erstattungsbestätigung', 'pass-captain' ),
				'description' => __( 'Bestätigung einer abgeschlossenen Erstattung (nicht bei WooCommerce-Bestellungen).', 'pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
					'refund_amount' => __( 'Erstatteter Betrag mit Währung', 'pass-captain' ),
					'revoked_count' => __( 'Anzahl ungültig gewordener Tickets', 'pass-captain' ),
					'revoked_note' => __( 'Satz zu ungültig gewordenen Tickets mit Zeilenumbruch (leer, wenn keine)', 'pass-captain' ),
					'credit_note_number' => __( 'Nummer der Gutschrift', 'pass-captain' ),
					'credit_note' => __( 'Zeile „Gutschrift: …“ mit Zeilenumbruch (leer ohne Gutschrift)', 'pass-captain' ),
					'reason' => __( 'Grund der Erstattung', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Erstattung bestätigt – %s', 'pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					/* translators: 1: amount, 2: order id */
					return sprintf( __( 'Für deine Bestellung %2$s haben wir %1$s erstattet.', 'pass-captain' ), '{refund_amount}', '{order_id}' ) . "\n{revoked_note}{credit_note}\n" . __( 'Je nach Zahlungsart kann es einige Tage dauern, bis der Betrag gutgeschrieben ist.', 'pass-captain' );
				},
				'sample' => static function () {
					return array( 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e', 'refund_amount' => '25,00 EUR', 'revoked_count' => 1, 'revoked_note' => self::revoked_note( 1 ), 'credit_note_number' => 'GS-2027-0001', 'credit_note' => self::credit_note( 'GS-2027-0001' ), 'reason' => 'Kulanz' );
				},
			),
			'event_cancelled' => array(
				'label' => __( 'Eventabsage', 'pass-captain' ),
				'description' => __( 'Information an Käufer:innen und Ticketinhaber:innen, wenn ein ganzes Event abgesagt wird.', 'pass-captain' ),
				'placeholders' => array( 'event_name' => __( 'Event', 'pass-captain' ) ),
				'subject' => static function () { return sprintf( __( 'Eventabsage: %s', 'pass-captain' ), '{event_name}' ); },
				'body' => static function () { return sprintf( __( 'Das Event „%s“ wurde abgesagt. Bitte kontaktiere den Veranstalter für Informationen zu den nächsten Schritten. Diese Nachricht ist keine Bestätigung einer Erstattung.', 'pass-captain' ), '{event_name}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert' ); },
			),
			'occurrence_cancelled' => array(
				'label' => __( 'Terminabsage', 'pass-captain' ),
				'description' => __( 'Information an Ticketinhaber:innen, wenn ein einzelner Termin abgesagt wird.', 'pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'pass-captain' ),
					'event_date' => __( 'Datum und Uhrzeit des Termins', 'pass-captain' ),
					'event_location' => __( 'Ort', 'pass-captain' ),
					'timezone' => __( 'Zeitzone', 'pass-captain' ),
					'refund_note' => __( 'Hinweis zur Erstattung (automatisch oder durch den Veranstalter)', 'pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Absage: %s', 'pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: date and time, 3: refund note */
				'body' => static function () { return sprintf( __( "Der Termin „%1\$s“ am %2\$s wurde abgesagt. Dein Ticket ist dafür nicht mehr gültig.\n\n%3\$s", 'pass-captain' ), '{event_name}', '{event_date}', '{refund_note}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'event_date' => '15.01.2027 19:30', 'event_location' => 'Stadthalle Wien', 'timezone' => 'Europe/Vienna', 'refund_note' => __( 'Der Ticketpreis wird automatisch erstattet.', 'pass-captain' ) ); },
			),
			'occurrence_rescheduled' => array(
				'label' => __( 'Terminänderung', 'pass-captain' ),
				'description' => __( 'Information an Ticketinhaber:innen, wenn ein Termin verschoben wird.', 'pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'pass-captain' ),
					'old_date' => __( 'Bisheriges Datum und Uhrzeit', 'pass-captain' ),
					'event_date' => __( 'Neues Datum und Uhrzeit', 'pass-captain' ),
					'timezone' => __( 'Zeitzone', 'pass-captain' ),
					'event_location' => __( 'Ort', 'pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Terminänderung: %s', 'pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: old date, 3: new date, 4: timezone, 5: location */
				'body' => static function () { return sprintf( __( "Der Termin für „%1\$s“ wurde verschoben.\n\nBisher: %2\$s\nNeu: %3\$s (%4\$s)\nOrt: %5\$s\n\nDein Ticket bleibt gültig. Bei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.", 'pass-captain' ), '{event_name}', '{old_date}', '{event_date}', '{timezone}', '{event_location}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'old_date' => '15.01.2027 19:30', 'event_date' => '22.01.2027 20:00', 'timezone' => 'Europe/Vienna', 'event_location' => 'Stadthalle Wien' ); },
			),
			'waitlist_joined' => array(
				'label' => __( 'Warteliste: Eintragung', 'pass-captain' ),
				'description' => __( 'Bestätigung der Eintragung in die Warteliste.', 'pass-captain' ),
				'placeholders' => array(
					'position' => __( 'Position auf der Warteliste', 'pass-captain' ),
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
				),
				'subject' => static function () { return __( 'Du stehst auf der Warteliste', 'pass-captain' ); },
				/* translators: %d: waitlist position */
				'body' => static function () { return str_replace( '%d', '{position}', __( 'Du bist auf der Warteliste eingetragen (Position %d). Wir schreiben dir, sobald ein Platz frei wird.', 'pass-captain' ) ); },
				'sample' => static function () { return array( 'position' => 3, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz' ); },
			),
			'waitlist_offer' => array(
				'label' => __( 'Warteliste: Platzangebot', 'pass-captain' ),
				'description' => __( 'Angebot an die nächste Person auf der Warteliste (ohne Reservierung).', 'pass-captain' ),
				'placeholders' => array(
					'offer_expires' => __( 'Ablauf des Angebots', 'pass-captain' ),
					'accept_link' => __( 'Link zur Annahme', 'pass-captain' ),
					'position' => __( 'Position auf der Warteliste', 'pass-captain' ),
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
				),
				'subject' => static function () { return __( 'Ein Platz könnte verfügbar sein', 'pass-captain' ); },
				/* translators: 1: expiry date, 2: accept link */
				'body' => static function () { return sprintf( __( 'Dein Wartelistenangebot läuft am %1$s ab. Mit der Annahme wird das Ticket für dich reserviert, sofern es noch verfügbar ist: %2$s', 'pass-captain' ), '{offer_expires}', '{accept_link}' ); },
				'sample' => static function () { return array( 'offer_expires' => '16.01.2027 19:30 UTC', 'accept_link' => Mailer::link( home_url( '/?wpcap_waitlist_accept=12&token=' . str_repeat( 'e5', 32 ) ) ), 'position' => 1, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz' ); },
			),
			'member_pass' => array(
				'label' => __( 'Mitgliedspass', 'pass-captain' ),
				'description' => __( 'Passcode einer Mitgliedschaft bzw. eines Mehrfachpasses.', 'pass-captain' ),
				'placeholders' => array(
					'pass_code' => __( 'Passcode', 'pass-captain' ),
					'valid_from' => __( 'Gültig ab', 'pass-captain' ),
					'valid_until' => __( 'Gültig bis', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Dein Pass für %s', 'pass-captain' ), '{site_name}' ); },
				'body' => static function () { return __( 'Dein Passcode:', 'pass-captain' ) . " {pass_code}\n" . __( 'Gültig bis:', 'pass-captain' ) . ' {valid_until}'; },
				'sample' => static function () { return array( 'pass_code' => str_repeat( 'f6', 32 ), 'valid_from' => '2027-01-01 00:00:00', 'valid_until' => '2027-12-31 23:59:59' ); },
			),
		);
	}

	/** Refund mail: sentence about revoked tickets incl. line break ('' for none). Call inside the recipient locale. */
	public static function revoked_note( $count ) {
		$count = (int) $count;
		/* translators: %d: number of tickets */
		return $count ? sprintf( _n( '%d Ticket ist damit ungültig.', '%d Tickets sind damit ungültig.', $count, 'pass-captain' ), $count ) . "\n" : '';
	}

	/** Refund mail: credit note line incl. line break ('' without number). Call inside the recipient locale. */
	public static function credit_note( $number ) {
		/* translators: %s: credit note number */
		return '' !== (string) $number ? sprintf( __( 'Gutschrift: %s', 'pass-captain' ), $number ) . "\n" : '';
	}
}
