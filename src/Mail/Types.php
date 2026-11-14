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

	/** Optional line "label: link" (empty without link) for waitlist mails. */
	private static function link_note( $link, $label ) {
		if ( ! is_array( $link ) || '' === (string) ( $link['text'] ?? '' ) ) { return ''; }
		return Mailer::block( "\n\n" . $label . ' ' . $link['text'], '<br><br>' . esc_html( $label ) . ' ' . ( $link['html'] ?? esc_html( (string) $link['text'] ) ) );
	}

	/** Placeholders of the service booking mails. */
	private static function service_placeholders() {
		return array(
			'booking_reference' => __( 'Buchungsnummer', 'pass-captain' ),
			'resource_name' => __( 'Ressource / Angebot', 'pass-captain' ),
			'event_name' => __( 'Event', 'pass-captain' ),
			'slot_date' => __( 'Beginn des Zeitfensters mit Zeitzone', 'pass-captain' ),
			'quantity' => __( 'Anzahl Plätze', 'pass-captain' ),
			'cancel_until' => __( 'Stornierung möglich bis', 'pass-captain' ),
			'rebook_until' => __( 'Umbuchung möglich bis', 'pass-captain' ),
			'manage_link' => __( 'Link zur Buchung (nur Buchungen ohne Bestellung)', 'pass-captain' ),
		);
	}

	private static function service_sample() {
		return array( 'booking_reference' => '#42', 'resource_name' => 'Segelboot „Möwe“', 'event_name' => 'Segelschule Sommer', 'slot_date' => '15.06.2027 10:00 (Europe/Vienna)', 'quantity' => 2, 'cancel_until' => '14.06.2027 10:00', 'rebook_until' => '14.06.2027 10:00', 'manage_link' => '', 'refund_info' => 'Erstattet werden 90,00 EUR (Zahlungsmittel bzw. Gutschein).', 'reason' => 'Storniert auf deinen Wunsch.', 'old_slot_date' => '12.06.2027 10:00', 'confirm_by' => '13.06.2027 18:00', 'fee_info' => '', 'admin_link' => home_url( '/wp-admin/admin.php?page=wpcap-services' ) );
	}

	/** Mail types of service bookings and the slot waitlist (German defaults, editable like all types). */
	private static function service_types() {
		$base = self::service_placeholders();
		$sample = static function () { return self::service_sample(); };
		$footer = static function () { return "\n\n" . __( 'Umbuchen oder stornieren kannst du über den Link zu deiner Bestellung (bzw. den Buchungslink) – stornieren bis {cancel_until}, umbuchen bis {rebook_until}.', 'pass-captain' ) . '{manage_link}'; };
		return array(
			'service_booking_confirmed' => array(
				'label' => __( 'Servicebuchung: Bestätigung', 'pass-captain' ),
				'description' => __( 'Bestätigung einer Termin-/Ressourcenbuchung nach Zahlung bzw. Freigabe.', 'pass-captain' ),
				'placeholders' => $base,
				/* translators: %s: resource */
				'subject' => static function () { return sprintf( __( 'Buchung bestätigt: %s', 'pass-captain' ), '{resource_name}' ); },
				/* translators: 1: reference, 2: resource, 3: date, 4: places */
				'body' => static function () use ( $footer ) { return sprintf( __( "Deine Buchung %1\$s ist bestätigt.\n\n%2\$s\nTermin: %3\$s\nPlätze: %4\$s", 'pass-captain' ), '{booking_reference}', '{resource_name}', '{slot_date}', '{quantity}' ) . $footer(); },
				'sample' => $sample,
			),
			'service_booking_pending' => array(
				'label' => __( 'Servicebuchung: wartet auf Bestätigung', 'pass-captain' ),
				'description' => __( 'Information an Kund:innen, dass der Anbieter die Buchung noch bestätigen muss.', 'pass-captain' ),
				'placeholders' => $base + array( 'confirm_by' => __( 'Entscheidung spätestens', 'pass-captain' ) ),
				'subject' => static function () { return __( 'Deine Buchung wartet auf Bestätigung', 'pass-captain' ); },
				/* translators: 1: reference, 2: resource, 3: date, 4: deadline */
				'body' => static function () { return sprintf( __( "Danke für deine Buchung %1\$s (%2\$s, %3\$s).\n\nDer Anbieter bestätigt die Buchung spätestens bis %4\$s. Kommt keine Bestätigung, wird die Buchung automatisch storniert und vollständig erstattet.", 'pass-captain' ), '{booking_reference}', '{resource_name}', '{slot_date}', '{confirm_by}' ); },
				'sample' => $sample,
			),
			'service_booking_partner_request' => array(
				'label' => __( 'Servicebuchung: Bestätigungsanfrage an Anbieter', 'pass-captain' ),
				'description' => __( 'Hinweis an den Veranstalter/Anbieter, dass eine Buchung bestätigt oder abgelehnt werden muss.', 'pass-captain' ),
				'placeholders' => $base + array( 'confirm_by' => __( 'Entscheidung spätestens', 'pass-captain' ), 'admin_link' => __( 'Link zur Verwaltung', 'pass-captain' ) ),
				/* translators: %s: resource */
				'subject' => static function () { return sprintf( __( 'Neue Buchung zur Bestätigung: %s', 'pass-captain' ), '{resource_name}' ); },
				/* translators: 1: reference, 2: resource, 3: date, 4: places, 5: deadline, 6: link */
				'body' => static function () { return sprintf( __( "Buchung %1\$s für %2\$s am %3\$s (%4\$s Plätze) wartet auf deine Bestätigung.\n\nBitte bis %5\$s bestätigen oder ablehnen, sonst wird sie automatisch storniert und erstattet:\n%6\$s", 'pass-captain' ), '{booking_reference}', '{resource_name}', '{slot_date}', '{quantity}', '{confirm_by}', '{admin_link}' ); },
				'sample' => $sample,
			),
			'service_booking_declined' => array(
				'label' => __( 'Servicebuchung: abgelehnt', 'pass-captain' ),
				'description' => __( 'Information bei Ablehnung durch den Anbieter oder abgelaufener Bestätigungsfrist (mit Erstattung).', 'pass-captain' ),
				'placeholders' => $base + array( 'reason' => __( 'Grund', 'pass-captain' ), 'refund_info' => __( 'Erstattungshinweis', 'pass-captain' ) ),
				'subject' => static function () { return __( 'Deine Buchung konnte nicht bestätigt werden', 'pass-captain' ); },
				/* translators: 1: reference, 2: resource, 3: date, 4: reason, 5: refund note */
				'body' => static function () { return sprintf( __( "Leider konnte deine Buchung %1\$s (%2\$s, %3\$s) nicht bestätigt werden.\n%4\$s\n\n%5\$s", 'pass-captain' ), '{booking_reference}', '{resource_name}', '{slot_date}', '{reason}', '{refund_info}' ); },
				'sample' => $sample,
			),
			'service_booking_cancelled' => array(
				'label' => __( 'Servicebuchung: Stornierung', 'pass-captain' ),
				'description' => __( 'Bestätigung einer Stornierung (durch Kund:in oder Veranstalter) mit Erstattungshinweis.', 'pass-captain' ),
				'placeholders' => $base + array( 'reason' => __( 'Grund', 'pass-captain' ), 'refund_info' => __( 'Erstattungshinweis', 'pass-captain' ) ),
				/* translators: %s: reference */
				'subject' => static function () { return sprintf( __( 'Buchung %s storniert', 'pass-captain' ), '{booking_reference}' ); },
				/* translators: 1: reference, 2: resource, 3: date, 4: reason, 5: refund note */
				'body' => static function () { return sprintf( __( "Deine Buchung %1\$s (%2\$s, %3\$s) wurde storniert.\n%4\$s\n\n%5\$s", 'pass-captain' ), '{booking_reference}', '{resource_name}', '{slot_date}', '{reason}', '{refund_info}' ); },
				'sample' => $sample,
			),
			'service_booking_rebooked' => array(
				'label' => __( 'Servicebuchung: Umbuchung', 'pass-captain' ),
				'description' => __( 'Bestätigung einer Umbuchung auf ein anderes Zeitfenster.', 'pass-captain' ),
				'placeholders' => $base + array( 'old_slot_date' => __( 'Bisheriger Termin', 'pass-captain' ), 'refund_info' => __( 'Erstattungshinweis (Preisdifferenz)', 'pass-captain' ) ),
				/* translators: %s: reference */
				'subject' => static function () { return sprintf( __( 'Buchung %s umgebucht', 'pass-captain' ), '{booking_reference}' ); },
				/* translators: 1: reference, 2: old date, 3: resource, 4: new date, 5: refund note */
				'body' => static function () use ( $footer ) { return sprintf( __( "Deine Buchung %1\$s wurde umgebucht.\n\nBisher: %2\$s\nNeu: %3\$s, %4\$s\n%5\$s", 'pass-captain' ), '{booking_reference}', '{old_slot_date}', '{resource_name}', '{slot_date}', '{refund_info}' ) . $footer(); },
				'sample' => $sample,
			),
			'service_booking_no_show' => array(
				'label' => __( 'Servicebuchung: nicht erschienen', 'pass-captain' ),
				'description' => __( 'Information, dass ein gebuchter Termin als nicht wahrgenommen vermerkt wurde.', 'pass-captain' ),
				'placeholders' => $base + array( 'fee_info' => __( 'Gebührenhinweis', 'pass-captain' ) ),
				'subject' => static function () { return __( 'Termin nicht wahrgenommen', 'pass-captain' ); },
				/* translators: 1: resource, 2: date, 3: fee note */
				'body' => static function () { return sprintf( __( "Für deinen Termin %1\$s am %2\$s haben wir keinen Check-in erhalten; er wurde als nicht wahrgenommen vermerkt.\n%3\$s\n\nFalls das ein Irrtum ist, antworte bitte auf diese E-Mail.", 'pass-captain' ), '{resource_name}', '{slot_date}', '{fee_info}' ); },
				'sample' => $sample,
			),
			'service_waitlist_joined' => array(
				'label' => __( 'Warteliste Zeitfenster: Eintragung', 'pass-captain' ),
				'description' => __( 'Bestätigung der Eintragung in die Warteliste eines ausgebuchten Zeitfensters.', 'pass-captain' ),
				'placeholders' => array( 'resource_name' => $base['resource_name'], 'slot_date' => $base['slot_date'], 'quantity' => $base['quantity'], 'position' => __( 'Position auf der Warteliste', 'pass-captain' ), 'status_link' => __( 'Link zur aktuellen Position', 'pass-captain' ), 'leave_link' => __( 'Link zum Austragen', 'pass-captain' ) ),
				'subject' => static function () { return __( 'Du stehst auf der Warteliste', 'pass-captain' ); },
				/* translators: 1: resource, 2: date, 3: position, 4: status link, 5: leave link */
				'body' => static function () { return sprintf( __( "Du stehst auf der Warteliste für %1\$s am %2\$s (Position %3\$s). Sobald Plätze frei werden, bekommst du ein befristetes Angebot.\n\nAktuelle Position: %4\$s\nAustragen: %5\$s", 'pass-captain' ), '{resource_name}', '{slot_date}', '{position}', '{status_link}', '{leave_link}' ); },
				'sample' => static function () { return array( 'resource_name' => 'Segelboot „Möwe“', 'slot_date' => '15.06.2027 10:00 (Europe/Vienna)', 'quantity' => 1, 'position' => 2, 'status_link' => Mailer::link( home_url( '/?wpcap_service_waitlist=7&do=status&token=' . str_repeat( 'd4', 32 ) ) ), 'leave_link' => Mailer::link( home_url( '/?wpcap_service_waitlist=7&do=leave&token=' . str_repeat( 'd4', 32 ) ) ) ); },
			),
			'service_waitlist_offer' => array(
				'label' => __( 'Warteliste Zeitfenster: Platzangebot', 'pass-captain' ),
				'description' => __( 'Befristetes Angebot an die nächste Person; die Plätze sind bis zum Ablauf reserviert.', 'pass-captain' ),
				'placeholders' => array( 'resource_name' => $base['resource_name'], 'slot_date' => $base['slot_date'], 'quantity' => $base['quantity'], 'offer_expires' => __( 'Ablauf des Angebots', 'pass-captain' ), 'accept_link' => __( 'Link zur Annahme', 'pass-captain' ), 'decline_link' => __( 'Link zum Ablehnen', 'pass-captain' ) ),
				'subject' => static function () { return __( 'Ein Platz ist für dich frei geworden', 'pass-captain' ); },
				/* translators: 1: places, 2: resource, 3: date, 4: expiry, 5: accept link, 6: decline link */
				'body' => static function () { return sprintf( __( "Gute Nachricht: %1\$s Platz/Plätze für %2\$s am %3\$s sind bis %4\$s für dich reserviert.\n\nAnnehmen: %5\$s\nAblehnen: %6\$s\n\nDanach geht das Angebot an die nächste Person auf der Warteliste.", 'pass-captain' ), '{quantity}', '{resource_name}', '{slot_date}', '{offer_expires}', '{accept_link}', '{decline_link}' ); },
				'sample' => static function () { return array( 'resource_name' => 'Segelboot „Möwe“', 'slot_date' => '15.06.2027 10:00 (Europe/Vienna)', 'quantity' => 1, 'offer_expires' => '14.06.2027 12:00', 'accept_link' => Mailer::link( home_url( '/?wpcap_service_waitlist=7&do=accept&token=' . str_repeat( 'e5', 32 ) ) ), 'decline_link' => Mailer::link( home_url( '/?wpcap_service_waitlist=7&do=decline&token=' . str_repeat( 'e5', 32 ) ) ) ); },
			),
		);
	}

	/** Built-in mail types. */
	private static function core() {
		return self::service_types() + array(
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
					'leave_link' => __( 'Link zum Austragen', 'pass-captain' ),
				),
				'subject' => static function () { return __( 'Du stehst auf der Warteliste', 'pass-captain' ); },
				/* translators: %d: waitlist position */
				'body' => static function () { return str_replace( '%d', '{position}', __( 'Du bist auf der Warteliste eingetragen (Position %d). Wir schreiben dir, sobald ein Platz frei wird.', 'pass-captain' ) ) . '{leave_note}'; },
				'vars' => static function ( array $vars ) { return array( 'leave_note' => self::link_note( $vars['leave_link'] ?? null, __( 'Von der Warteliste austragen:', 'pass-captain' ) ) ); },
				'sample' => static function () { return array( 'position' => 3, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz', 'leave_link' => Mailer::link( home_url( '/?wpcap_waitlist_leave=12&token=' . str_repeat( 'e5', 32 ) ) ) ); },
			),
			'waitlist_offer' => array(
				'label' => __( 'Warteliste: Platzangebot', 'pass-captain' ),
				'description' => __( 'Angebot an die nächste Person auf der Warteliste (automatisch, sobald ein Platz frei wird; das Ticket ist bis zum Ablauf reserviert).', 'pass-captain' ),
				'placeholders' => array(
					'offer_expires' => __( 'Ablauf des Angebots', 'pass-captain' ),
					'accept_link' => __( 'Link zur Annahme', 'pass-captain' ),
					'decline_link' => __( 'Link zum Ablehnen', 'pass-captain' ),
					'leave_link' => __( 'Link zum Austragen', 'pass-captain' ),
					'reserved_note' => __( 'Hinweis, ob das Ticket reserviert ist', 'pass-captain' ),
					'position' => __( 'Position auf der Warteliste', 'pass-captain' ),
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
				),
				'subject' => static function () { return __( 'Ein Platz ist für dich frei geworden', 'pass-captain' ); },
				/* translators: 1: expiry date, 2: reserved note, 3: accept link */
				'body' => static function () { return sprintf( __( 'Dein Wartelistenangebot läuft am %1$s ab. %2$s Annehmen: %3$s', 'pass-captain' ), '{offer_expires}', '{reserved_note}', '{accept_link}' ) . '{decline_note}{leave_note}'; },
				'vars' => static function ( array $vars ) { return array( 'reserved_note' => __( 'Mit der Annahme wird das Ticket für dich reserviert, sofern es noch verfügbar ist.', 'pass-captain' ), 'decline_note' => self::link_note( $vars['decline_link'] ?? null, __( 'Kein Interesse mehr? Angebot ablehnen:', 'pass-captain' ) ), 'leave_note' => self::link_note( $vars['leave_link'] ?? null, __( 'Von der Warteliste austragen:', 'pass-captain' ) ) ); },
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
