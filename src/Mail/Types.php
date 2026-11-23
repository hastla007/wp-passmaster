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
			'imprint_url' => __( 'Link zum Impressum (leer, wenn keiner eingestellt ist)', 'pass-captain' ),
			'privacy_url' => __( 'Link zur Datenschutzerklärung', 'pass-captain' ),
			'terms_text' => __( 'AGB / Teilnahmebedingungen in der Sprache des Empfängers', 'pass-captain' ),
			'consumer_info_text' => __( 'Verbraucherinformationen in der Sprache des Empfängers', 'pass-captain' ),
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
			'contract_texts' => __( 'Bedingungen und Verbraucherinformationen in der Fassung, die bei der Bestellung galt (mit Fassungskennung; leer ohne Rechtstexte)', 'pass-captain' ),
		) + self::legal_placeholders();
	}

	/** Placeholders with the legal texts of the settings (ticket and access-link mails). */
	private static function legal_placeholders() {
		return array(
			'consumer_info' => __( 'Verbraucherinformationen aus den Einstellungen (mit Überschrift); leer, wenn nicht gepflegt', 'pass-captain' ),
			'terms' => __( 'AGB / Teilnahmebedingungen aus den Einstellungen (mit Überschrift); leer, wenn nicht gepflegt', 'pass-captain' ),
		);
	}

	/**
	 * Values of the legal placeholders (evaluated when the mail is rendered). {contract_texts}
	 * defaults to the current texts of the settings (consumer information, then terms) and is
	 * replaced by the texts of the order's version (A568, LegalVersions::mail_block()) when the caller
	 * knows the order – one placeholder in the default bodies, so the texts never appear twice.
	 */
	public static function legal_vars() {
		return array(
			'consumer_info' => static function () { return Blocks::legal_text( 'consumer_info_text' ); },
			'terms' => static function () { return Blocks::legal_text( 'terms_text' ); },
			'contract_texts' => static function () {
				$info = Blocks::legal_text( 'consumer_info_text' );
				$terms = Blocks::legal_text( 'terms_text' );
				return Mailer::block( $info['text'] . $terms['text'], $info['html'] . $terms['html'] );
			},
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

	/** Date and location placeholders of the waitlist mails (A565). */
	private static function waitlist_date_placeholders() {
		return array(
			'event_date' => __( 'Beginn des Termins mit Zeitzone', 'pass-captain' ),
			'event_location' => __( 'Ort', 'pass-captain' ),
			'timezone' => __( 'Zeitzone', 'pass-captain' ),
		);
	}

	/** Sample date and location of the waitlist mails. */
	private static function waitlist_date_sample() {
		return array( 'event_date' => '15.01.2027 19:30 Europe/Vienna', 'event_location' => 'Stadthalle Wien', 'timezone' => 'Europe/Vienna' );
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
			'contract_texts' => \PassCaptain\LegalVersions::sample_block(),
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
		$vars = array( 'order_reference' => \PassCaptain\Frontend\Format::order_reference( $public_id ), 'payment_instructions' => Blocks::payment_instructions( null ), 'order_items' => Blocks::order_items( array() ) ) + self::legal_vars();
		if ( 32 !== strlen( $public_id ) ) { return $vars; }
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,public_id,payment_provider,payment_status,total_minor,currency,terms_version,locale FROM %i WHERE public_id=%s', $wpdb->prefix . 'wpcap_orders', $public_id ) );
		if ( $order ) {
			$vars['order_items'] = Blocks::order_items( \PassCaptain\Orders::summary_lines( (int) $order->order_id ) );
			$vars['contract_texts'] = static function () use ( $order ) { return \PassCaptain\LegalVersions::mail_block( $order ); };
		}
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

	/**
	 * Derived values of the waitlist mails for callers of both generations: {unsubscribe_link} falls
	 * back to {leave_link}, {decline_note} is the optional line with {decline_link}, {quantity}
	 * defaults to 1. Passed values win (Mailer::render_template()).
	 */
	private static function waitlist_compat_vars( array $vars ) {
		$derived = array(
			'quantity' => 1,
			'decline_note' => self::link_note( $vars['decline_link'] ?? null, __( 'Kein Interesse mehr? Angebot ablehnen:', 'pass-captain' ) ),
		);
		if ( ! isset( $vars['unsubscribe_link'] ) && isset( $vars['leave_link'] ) ) { $derived['unsubscribe_link'] = $vars['leave_link']; }
		return $derived;
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
			'service_waitlist_confirm' => array(
				'label' => __( 'Warteliste Zeitfenster: Eintragung bestätigen (Double-Opt-in)', 'pass-captain' ),
				'description' => __( 'Bestätigungslink nach der Eintragung in die Warteliste eines Zeitfensters. Erst nach dem Klick zählt der Eintrag und bekommt eine Position.', 'pass-captain' ),
				'placeholders' => array( 'resource_name' => $base['resource_name'], 'slot_date' => $base['slot_date'], 'quantity' => $base['quantity'], 'confirm_link' => __( 'Link zur Bestätigung', 'pass-captain' ), 'confirm_expires' => __( 'Gültigkeit des Bestätigungslinks', 'pass-captain' ) ),
				'subject' => static function () { return __( 'Bitte bestätige deine Eintragung in die Warteliste', 'pass-captain' ); },
				/* translators: 1: resource, 2: date, 3: confirmation link, 4: expiry */
				'body' => static function () { return sprintf( __( "Bitte bestätige deine Eintragung in die Warteliste für %1\$s am %2\$s:\n%3\$s\n\nDer Link ist bis %4\$s gültig. Ohne Bestätigung wirst du nicht eingetragen und die Anfrage wird danach gelöscht. Falls du dich nicht eingetragen hast, kannst du diese E-Mail ignorieren.", 'pass-captain' ), '{resource_name}', '{slot_date}', '{confirm_link}', '{confirm_expires}' ); },
				'sample' => static function () { return array( 'resource_name' => 'Segelboot „Möwe“', 'slot_date' => '15.06.2027 10:00 (Europe/Vienna)', 'quantity' => 1, 'confirm_expires' => '13.06.2027 10:00 UTC', 'confirm_link' => Mailer::link( home_url( '/?wpcap_service_waitlist=7&do=confirm&token=' . str_repeat( 'c3', 32 ) ) ) ); },
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
				// A568: the confirmation carries the contract texts of the order's version ({contract_texts};
				// without a known order the current texts of the settings, see legal_vars()).
				/* translators: %s: site name */
				'body' => static function () { return sprintf( __( 'Deine Tickets für %s:', 'pass-captain' ), '{site_name}' ) . "\n\n{ticket_list}{contract_texts}"; },
				'vars' => static function () { return self::legal_vars(); },
				'sample' => static function () { return self::sample_ticket_vars( false ); },
			),
			'tickets_resend' => array(
				'label' => __( 'Tickets erneut senden', 'pass-captain' ),
				'description' => __( 'Erneuter Versand der Tickets mit neuen oder den gespeicherten Ticketcodes (je Empfänger:in).', 'pass-captain' ),
				'placeholders' => self::ticket_placeholders(),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Tickets für %s', 'pass-captain' ), '{site_name}' ); },
				/* translators: %s: site name */
				'body' => static function () { return sprintf( __( 'Deine Tickets für %s:', 'pass-captain' ), '{site_name}' ) . "\n\n{ticket_list}"; },
				'vars' => static function () { return self::legal_vars(); },
				'sample' => static function () { return self::sample_ticket_vars( true ); },
			),
			// A682: request for attendee data that is still missing (admin action, new order link).
			'attendee_data_request' => array(
				'label' => __( 'Fehlende Teilnehmerangaben', 'pass-captain' ),
				'description' => __( 'Bitte an die Käufer:in, fehlende Angaben zu Teilnehmenden zu ergänzen (mit neuem Bestelllink).', 'pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
					'missing_list' => __( 'Fehlende Angaben je Ticket', 'pass-captain' ),
					'access_link' => __( 'Persönlicher Link zur Bestellung (neu)', 'pass-captain' ),
				),
				/* translators: %s: order number placeholder */
				'subject' => static function () { return sprintf( __( 'Bitte ergänze die Angaben zu deiner Bestellung %s', 'pass-captain' ), '{order_id}' ); },
				'body' => static function () {
					/* translators: %s: order number placeholder */
					return sprintf( __( 'Für deine Bestellung %s fehlen noch Angaben zu den Teilnehmenden:', 'pass-captain' ), '{order_id}' ) . "\n\n{missing_list}\n" . __( 'Bitte ergänze sie über deinen persönlichen Link zur Bestellung:', 'pass-captain' ) . "\n\n{access_link}\n\n" . __( 'Der bisherige Link zur Bestellung gilt damit nicht mehr. Bewahre diesen Link sicher auf und teile ihn nicht.', 'pass-captain' );
				},
				'sample' => static function () {
					return array(
						'order_id' => 'AB12-CD34',
						'missing_list' => Mailer::block( "Sommerkonzert — Stehplatz (15.01.2027 19:30 Europe/Vienna): Name, T-Shirt\n", '<ul><li>Sommerkonzert — Stehplatz (15.01.2027 19:30 Europe/Vienna): Name, T-Shirt</li></ul>' ),
						'access_link' => Mailer::link( home_url( '/?wpcap_order=3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e&wpcap_access=' . str_repeat( 'd4', 32 ) ) ),
					);
				},
			),
			'order_access' => array(
				'label' => __( 'Bestellzugang', 'pass-captain' ),
				'description' => __( 'Persönlicher Link zur Bestellübersicht nach der Bestellung.', 'pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
					'order_reference' => __( 'Kurze Bestellnummer (z. B. 3F2A-9C1E, auch Verwendungszweck)', 'pass-captain' ),
					'access_link' => __( 'Persönlicher Link zur Bestellung', 'pass-captain' ),
					'payment_instructions' => __( 'Zahlungsinformationen bei offener Überweisung/manueller Zahlung (Betrag, IBAN, BIC, Empfänger, Verwendungszweck, Frist); sonst leer', 'pass-captain' ),
					'order_items' => __( 'Bestellte Positionen (Event, Ticketart, Termin, Menge, Preis)', 'pass-captain' ),
					'contract_texts' => __( 'Bedingungen und Verbraucherinformationen in der Fassung, die bei der Bestellung galt (mit Fassungskennung; leer ohne Rechtstexte)', 'pass-captain' ),
				) + self::legal_placeholders(),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Bestellung bei %s', 'pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					// {payment_instructions} is empty unless a manual payment is pending; it brings its own trailing blank line.
					/* translators: %s: site name */
					return sprintf( __( 'Über diesen persönlichen Link kannst du den Status deiner Bestellung bei %s ansehen und Tickets verwalten:', 'pass-captain' ), '{site_name}' ) . "\n\n{access_link}\n\n{order_items}{payment_instructions}{contract_texts}" . __( 'Bewahre diesen Link sicher auf und teile ihn nicht.', 'pass-captain' );
				},
				// Values derived from the order when the caller does not pass them.
				'vars' => static function ( array $vars ) { return self::order_access_vars( (string) ( $vars['order_id'] ?? '' ) ); },
				'sample' => static function () {
					return array(
						'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e',
						'order_reference' => '3F2A-9C1E',
						'access_link' => Mailer::link( home_url( '/?wpcap_order=3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e&wpcap_access=' . str_repeat( 'd4', 32 ) ) ),
						'order_items' => Blocks::order_items( array( array( 'event' => 'Sommerkonzert', 'ticket' => 'Stehplatz', 'when' => '20.07.2027 19:30', 'quantity' => 2, 'gross_minor' => 5000, 'currency' => 'EUR' ) ) ),
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
				'sample' => static function () { return \PassCaptain\Orders::without_code_vars( self::sample_single_ticket() ) + array( 'recipient_email' => 'freundin@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
			),
			'ticket_exchanged' => array(
				'label' => __( 'Ticketumtausch', 'pass-captain' ),
				'description' => __( 'Neues Ticket nach einem Umtausch (anderer Termin oder andere Ticketart); der bisherige Code ist ungültig.', 'pass-captain' ),
				'placeholders' => self::single_ticket_placeholders() + array(
					'old_event_name' => __( 'Bisheriges Event', 'pass-captain' ),
					'old_ticket_name' => __( 'Bisherige Ticketart', 'pass-captain' ),
					'old_event_date' => __( 'Bisheriger Beginn mit Zeitzone', 'pass-captain' ),
					'seat' => __( 'Sitzplatz (leer ohne Saalplan)', 'pass-captain' ),
					'seat_note' => __( 'Zeile „Sitzplatz: …“ mit Zeilenumbruch (leer ohne Saalplan)', 'pass-captain' ),
					'price_note' => __( 'Hinweis zu Aufpreis, Gebühr oder Erstattung mit Zeilenumbruch (leer ohne Preisdifferenz)', 'pass-captain' ),
					'ticket_link' => __( 'Link zum neuen Ticket', 'pass-captain' ),
					'ticket_extras' => __( 'Zusatzinformationen zum Ticket (z. B. Wallet-Links)', 'pass-captain' ),
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Dein Ticket wurde umgetauscht – %s', 'pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					/* translators: 1: previous event, 2: previous ticket type, 3: previous date */
					return __( 'Dein Ticket wurde umgetauscht.', 'pass-captain' ) . "\n\n" . sprintf( __( 'Bisher: %1$s — %2$s (%3$s)', 'pass-captain' ), '{old_event_name}', '{old_ticket_name}', '{old_event_date}' ) . "\n" . __( 'Neu:', 'pass-captain' ) . " {event_name} — {ticket_name}\n{event_date}\n{event_location}\n{seat_note}\n{ticket_link}\n{ticket_extras}{price_note}" . __( 'Der bisherige Ticketcode ist ab sofort ungültig. Bitte verwende nur noch das neue Ticket.', 'pass-captain' );
				},
				'sample' => static function () {
					return self::sample_single_ticket() + array( 'old_event_name' => 'Sommerkonzert', 'old_ticket_name' => 'Stehplatz', 'old_event_date' => '14.01.2027 19:30 Europe/Vienna', 'ticket_name' => 'Sitzplatz Parkett', 'seat' => 'Reihe A, Platz 4', 'seat_note' => __( 'Sitzplatz:', 'pass-captain' ) . " Reihe A, Platz 4\n", 'price_note' => self::exchange_price_note( 'paid', '20,00 EUR' ), 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' );
				},
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
				'sample' => static function () { return \PassCaptain\Orders::without_code_vars( self::sample_single_ticket() ) + array( 'attendee_name' => 'Alex Muster', 'attendee_email' => 'alex@example.com', 'order_id' => '3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e' ); },
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
					'credit_note_number' => __( 'Nummer der Rechnungskorrektur', 'pass-captain' ),
					'credit_note' => __( 'Zeile „Rechnungskorrektur: …“ mit Zeilenumbruch (leer ohne Rechnungskorrektur)', 'pass-captain' ),
					'reason' => __( 'Grund der Erstattung', 'pass-captain' ),
					'refund_intro' => __( 'Einleitungssatz passend zur Zahlart („haben wir … erstattet“ bzw. „erstatten wir … per Überweisung“)', 'pass-captain' ),
					'refund_method_note' => __( 'Hinweis zur Auszahlung je Zahlart (Kartenrückbuchung, bar an der Kasse, Überweisung auf IBAN bzw. Bitte um IBAN)', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Erstattung bestätigt – %s', 'pass-captain' ), '{site_name}' ); },
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
				'label' => __( 'Stornierungsanfrage eingegangen', 'pass-captain' ),
				'description' => __( 'Bestätigung an die Käufer:in, dass ihre Stornierungsanfrage aus dem Bestellportal eingegangen ist und geprüft wird (noch keine Erstattung).', 'pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
					'ticket_count' => __( 'Anzahl angefragter Tickets', 'pass-captain' ),
					'ticket_list' => __( 'Angefragte Tickets, eines pro Zeile (Event, Ticketart, Datum)', 'pass-captain' ),
					'refund_amount' => __( 'Voraussichtlicher Erstattungsbetrag mit Währung', 'pass-captain' ),
					'reason' => __( 'Angegebener Grund (leer, wenn keiner)', 'pass-captain' ),
					'portal_link' => __( 'Persönlicher Link zur Bestellung (Status der Anfrage)', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Stornierungsanfrage – %s', 'pass-captain' ), '{site_name}' ); },
				'body' => static function () {
					/* translators: 1: short order number, 2: amount */
					return sprintf( __( 'Wir haben deine Stornierungsanfrage für die Bestellung %1$s erhalten. Voraussichtliche Erstattung: %2$s.', 'pass-captain' ), '{order_id}', '{refund_amount}' ) . "\n\n{ticket_list}\n\n"
						. __( 'Deine Tickets bleiben gültig, bis wir die Anfrage geprüft haben. Wird sie angenommen, werden die Tickets ungültig und du erhältst eine Erstattungsbestätigung. Den Stand siehst du jederzeit in deiner Bestellung:', 'pass-captain' ) . "\n{portal_link}";
				},
				'sample' => static function () {
					return array( 'order_id' => '3F2A-9C1E', 'ticket_count' => 1, 'ticket_list' => 'Sommerkonzert — Stehplatz, 21.06.2027 20:00 Europe/Vienna', 'refund_amount' => '25,00 EUR', 'reason' => 'Krank', 'portal_link' => 'https://example.com/?wpcap_order=3f2a9c1e5b7d4a6f8e0c2b4d6f8a0c2e&wpcap_access=…' );
				},
			),
			'refund_request_rejected' => array(
				'label' => __( 'Stornierungsanfrage abgelehnt', 'pass-captain' ),
				'description' => __( 'Information an die Käufer:in, dass ihre Stornierungsanfrage aus dem Bestellportal nicht angenommen wurde (Tickets bleiben gültig).', 'pass-captain' ),
				'placeholders' => array(
					'order_id' => __( 'Bestellnummer', 'pass-captain' ),
					'ticket_count' => __( 'Anzahl angefragter Tickets', 'pass-captain' ),
				),
				/* translators: %s: site name */
				'subject' => static function () { return sprintf( __( 'Deine Stornierungsanfrage – %s', 'pass-captain' ), '{site_name}' ); },
				/* translators: %s: short order number */
				'body' => static function () { return sprintf( __( 'Deine Stornierungsanfrage für die Bestellung %s wurde nicht angenommen. Deine Tickets bleiben gültig. Bei Fragen wende dich bitte an den Veranstalter.', 'pass-captain' ), '{order_id}' ); },
				'sample' => static function () { return array( 'order_id' => '3F2A-9C1E', 'ticket_count' => 1 ); },
			),
			'event_cancelled' => array(
				'label' => __( 'Eventabsage', 'pass-captain' ),
				'description' => __( 'Information an Käufer:innen und Ticketinhaber:innen, wenn ein ganzes Event abgesagt wird.', 'pass-captain' ),
				'placeholders' => array( 'event_name' => __( 'Event', 'pass-captain' ) ),
				'subject' => static function () { return sprintf( /* translators: %s: event name placeholder */ __( 'Eventabsage: %s', 'pass-captain' ), '{event_name}' ); },
				'body' => static function () { return sprintf( /* translators: %s: event name placeholder */ __( 'Das Event „%s“ wurde abgesagt. Bitte kontaktiere den Veranstalter für Informationen zu den nächsten Schritten. Diese Nachricht ist keine Bestätigung einer Erstattung.', 'pass-captain' ), '{event_name}' ); },
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
				// Queued mails carry the flag auto_refund; the note is translated when the mail is rendered.
				'vars' => static function ( array $vars ) { return array( 'refund_note' => ! empty( $vars['auto_refund'] ) ? __( 'Der Ticketpreis wird automatisch erstattet.', 'pass-captain' ) : __( 'Der Veranstalter informiert dich über Erstattung oder Ersatztermin.', 'pass-captain' ) ); },
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
					'refund_note' => __( 'Erstattungshinweis mit Frist (Einstellungen → Erstattungen); ohne Antragsmöglichkeit der Hinweis, sich an den Veranstalter zu wenden', 'pass-captain' ),
					'ics_url' => __( 'Link zur aktualisierten Kalenderdatei (.ics)', 'pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Terminänderung: %s', 'pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: old date, 3: new date, 4: timezone, 5: location, 6: refund note */
				'body' => static function () { return sprintf( __( "Der Termin für „%1\$s“ wurde verschoben.\n\nBisher: %2\$s\nNeu: %3\$s (%4\$s)\nOrt: %5\$s\n\nDein Ticket bleibt gültig. %6\$s", 'pass-captain' ), '{event_name}', '{old_date}', '{event_date}', '{timezone}', '{event_location}', '{refund_note}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'old_date' => '15.01.2027 19:30', 'event_date' => '22.01.2027 20:00', 'timezone' => 'Europe/Vienna', 'event_location' => 'Stadthalle Wien', 'refund_note' => str_replace( '{refund_deadline}', '22.01.2027 20:00', (string) \PassCaptain\Settings::get( 'reschedule_refund_note' ) ) ); },
				// Callers without a refund note (no request window configured): the general hint to contact the organiser.
				'vars' => static function () { return array( 'refund_note' => __( 'Bei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.', 'pass-captain' ) ); },
			),
			'occurrence_changed' => array(
				'label' => __( 'Termindetails geändert', 'pass-captain' ),
				'description' => __( 'Information an Ticketinhaber:innen, wenn sich Ort, Ende, Einlass oder Veranstaltungsland eines Termins ändern – auch zusammen mit einer neuen Uhrzeit (dann ersetzt sie die Mail „Terminänderung“). Je Änderung höchstens einmal; die aktualisierte Kalenderdatei ist angehängt.', 'pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'pass-captain' ),
					'changes' => __( 'Liste der Änderungen (bisher → neu), eine Zeile je Angabe', 'pass-captain' ),
					'event_date' => __( 'Aktuelles Datum und Uhrzeit', 'pass-captain' ),
					'old_date' => __( 'Bisheriges Datum und Uhrzeit', 'pass-captain' ),
					'timezone' => __( 'Zeitzone', 'pass-captain' ),
					'event_location' => __( 'Aktueller Ort', 'pass-captain' ),
					'ics_url' => __( 'Link zur aktualisierten Kalenderdatei (.ics)', 'pass-captain' ),
				),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Geänderte Termindetails: %s', 'pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: list of changes, 3: date and time, 4: timezone, 5: location, 6: link to the calendar file */
				'body' => static function () { return sprintf( __( "Für deinen Termin „%1\$s“ haben sich Angaben geändert:\n\n%2\$s\n\nTermin: %3\$s (%4\$s)\nOrt: %5\$s\n\nDein Ticket bleibt gültig. Die aktualisierte Kalenderdatei ist angehängt; du kannst sie auch hier laden: %6\$s\nBei Fragen zu Rückgabe oder Erstattung wende dich bitte an den Veranstalter.", 'pass-captain' ), '{event_name}', '{changes}', '{event_date}', '{timezone}', '{event_location}', '{ics_url}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'changes' => __( 'Ort', 'pass-captain' ) . ': Stadthalle Wien → Konzerthaus Wien', 'event_date' => '22.01.2027 20:00', 'old_date' => '22.01.2027 20:00', 'timezone' => 'Europe/Vienna', 'event_location' => 'Konzerthaus Wien', 'ics_url' => Mailer::link( home_url( '/?wpcap_ics=occurrence&id=42' ) ) ); },
			),
			'waitlist_joined' => array(
				'label' => __( 'Warteliste: Eintragung', 'pass-captain' ),
				'description' => __( 'Bestätigung der Eintragung in die Warteliste.', 'pass-captain' ),
				'placeholders' => array(
					'position' => __( 'Position auf der Warteliste', 'pass-captain' ),
					'quantity' => __( 'Gewünschte Anzahl', 'pass-captain' ),
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
					'unsubscribe_link' => __( 'Persönlicher Link zum Austragen aus der Warteliste', 'pass-captain' ),
					'leave_link' => __( 'Link zum Austragen (ältere Bezeichnung, wie {unsubscribe_link})', 'pass-captain' ),
				) + self::waitlist_date_placeholders(),
				'subject' => static function () { return __( 'Du stehst auf der Warteliste', 'pass-captain' ); },
				'body' => static function () {
					/* translators: %d: waitlist position */
					$intro = str_replace( '%d', '{position}', __( 'Du bist auf der Warteliste eingetragen (Position %d). Wir schreiben dir, sobald ein Platz frei wird.', 'pass-captain' ) );
					return $intro . "\n\n" . Types::waitlist_unsubscribe_note();
				},
				// Callers passing the older {leave_link} (personal leave link) get it in the standard unsubscribe sentence.
				'vars' => static function ( array $vars ) { return self::waitlist_compat_vars( $vars ); },
				'sample' => static function () { return array( 'position' => 3, 'quantity' => 2, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz', 'unsubscribe_link' => Mailer::link( home_url( '/?wpcap_waitlist_unsubscribe=12&token=' . str_repeat( 'a7', 32 ) ) ) ) + self::waitlist_date_sample(); },
			),
			'waitlist_confirm' => array(
				'label' => __( 'Warteliste: E-Mail-Bestätigung', 'pass-captain' ),
				'description' => __( 'Bestätigungslink nach der Eintragung (Double-Opt-in). Erst nach der Bestätigung steht die Person auf der Warteliste; unbestätigte Eintragungen werden gelöscht.', 'pass-captain' ),
				'placeholders' => array(
					'confirm_link' => __( 'Persönlicher Bestätigungslink', 'pass-captain' ),
					'confirm_expires' => __( 'Ablauf des Bestätigungslinks', 'pass-captain' ),
					'confirm_hours' => __( 'Gültigkeit des Bestätigungslinks in Stunden', 'pass-captain' ),
					'quantity' => __( 'Gewünschte Anzahl', 'pass-captain' ),
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
				) + self::waitlist_date_placeholders(),
				/* translators: %s: event name placeholder */
				'subject' => static function () { return sprintf( __( 'Bitte bestätige deinen Wartelistenplatz: %s', 'pass-captain' ), '{event_name}' ); },
				/* translators: 1: quantity, 2: ticket type, 3: event, 4: date, 5: location, 6: confirmation link, 7: expiry */
				'body' => static function () { return sprintf( __( "Du möchtest auf die Warteliste für %1\$s × „%2\$s“ bei „%3\$s“ (%4\$s, %5\$s).\n\nBitte bestätige deine E-Mail-Adresse über diesen Link:\n%6\$s\n\nErst nach der Bestätigung stehst du auf der Warteliste. Der Link gilt bis %7\$s; danach wird die Eintragung gelöscht. Wenn du dich nicht eingetragen hast, musst du nichts tun.", 'pass-captain' ), '{quantity}', '{ticket_name}', '{event_name}', '{event_date}', '{event_location}', '{confirm_link}', '{confirm_expires}' ); },
				// Callers passing the older {leave_link} (personal leave link) get it in the standard unsubscribe sentence.
				'vars' => static function ( array $vars ) { return self::waitlist_compat_vars( $vars ); },
				'sample' => static function () { return array( 'confirm_link' => Mailer::link( home_url( '/?wpcap_waitlist_confirm=12&token=' . str_repeat( 'c9', 32 ) ) ), 'confirm_expires' => '13.01.2027 19:30 CET', 'confirm_hours' => 48, 'quantity' => 2, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz' ) + self::waitlist_date_sample(); },
			),
			'waitlist_changed' => array(
				'label' => __( 'Warteliste: Termin geändert', 'pass-captain' ),
				'description' => __( 'Information an Wartende und Personen mit offenem Angebot, wenn Beginn oder Ort des Termins geändert wurden.', 'pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
					'old_date' => __( 'Bisheriger Beginn mit Zeitzone', 'pass-captain' ),
					'old_location' => __( 'Bisheriger Ort', 'pass-captain' ),
					'quantity' => __( 'Gewünschte bzw. reservierte Anzahl', 'pass-captain' ),
					'status_note' => __( 'Satz zum Stand (bleibt auf der Warteliste bzw. Angebot bleibt gültig)', 'pass-captain' ),
					'unsubscribe_link' => __( 'Persönlicher Link zum Austragen aus der Warteliste', 'pass-captain' ),
					'leave_link' => __( 'Link zum Austragen (ältere Bezeichnung, wie {unsubscribe_link})', 'pass-captain' ),
				) + self::waitlist_date_placeholders(),
				/* translators: %s: event name placeholder */
				'subject' => static function () { return sprintf( __( 'Geänderter Termin auf deiner Warteliste: %s', 'pass-captain' ), '{event_name}' ); },
				'body' => static function () {
					/* translators: 1: event, 2: ticket type, 3: old date, 4: old location, 5: new date, 6: new location, 7: status sentence */
					$text = sprintf( __( "Für „%1\$s“ (%2\$s), auf dessen Warteliste du stehst, haben sich Angaben geändert.\n\nBisher: %3\$s, %4\$s\nNeu: %5\$s, %6\$s\n\n%7\$s", 'pass-captain' ), '{event_name}', '{ticket_name}', '{old_date}', '{old_location}', '{event_date}', '{event_location}', '{status_note}' );
					return $text . "\n\n" . Types::waitlist_unsubscribe_note();
				},
				// Callers passing the older {leave_link} (personal leave link) get it in the standard unsubscribe sentence.
				'vars' => static function ( array $vars ) { return self::waitlist_compat_vars( $vars ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz', 'old_date' => '15.01.2027 19:30 Europe/Vienna', 'old_location' => 'Stadthalle Wien', 'quantity' => 2, 'status_note' => __( 'Du bleibst auf der Warteliste.', 'pass-captain' ), 'unsubscribe_link' => Mailer::link( home_url( '/?wpcap_waitlist_unsubscribe=12&token=' . str_repeat( 'a7', 32 ) ) ) ) + array( 'event_date' => '22.01.2027 20:00 Europe/Vienna', 'event_location' => 'Konzerthaus Wien', 'timezone' => 'Europe/Vienna' ); },
			),
			'waitlist_closed' => array(
				'label' => __( 'Warteliste: geschlossen', 'pass-captain' ),
				'description' => __( 'Der Wartelistenplatz endet, weil der Termin abgesagt wurde, begonnen hat oder der Verkauf beendet ist.', 'pass-captain' ),
				'placeholders' => array(
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
					'reason' => __( 'Grund (Absage oder Ende)', 'pass-captain' ),
				) + self::waitlist_date_placeholders(),
				/* translators: %s: event name placeholder */
				'subject' => static function () { return sprintf( __( 'Warteliste beendet: %s', 'pass-captain' ), '{event_name}' ); },
				/* translators: 1: event, 2: ticket type, 3: reason */
				'body' => static function () { return sprintf( __( "Dein Platz auf der Warteliste für „%1\$s“ (%2\$s) ist beendet. %3\$s\n\nDu musst nichts weiter tun; wir schreiben dir zu diesem Termin nicht mehr.", 'pass-captain' ), '{event_name}', '{ticket_name}', '{reason}' ); },
				'sample' => static function () { return array( 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz', 'reason' => __( 'Der Termin wurde abgesagt.', 'pass-captain' ) ) + self::waitlist_date_sample(); },
			),
			'waitlist_offer' => array(
				'label' => __( 'Warteliste: Platzangebot', 'pass-captain' ),
				'description' => __( 'Angebot an die nächste Person auf der Warteliste; die Tickets sind bis zum Ablauf reserviert.', 'pass-captain' ),
				'placeholders' => array(
					'offer_expires' => __( 'Ablauf des Angebots', 'pass-captain' ),
					'accept_link' => __( 'Link zur Annahme', 'pass-captain' ),
					'decline_link' => __( 'Link zum Ablehnen (optional; sonst über den Annahme-Link)', 'pass-captain' ),
					'quantity' => __( 'Anzahl der reservierten Tickets', 'pass-captain' ),
					'position' => __( 'Position auf der Warteliste', 'pass-captain' ),
					'event_name' => __( 'Event', 'pass-captain' ),
					'ticket_name' => __( 'Ticketart', 'pass-captain' ),
					'unsubscribe_link' => __( 'Persönlicher Link zum Austragen aus der Warteliste', 'pass-captain' ),
					'leave_link' => __( 'Link zum Austragen (ältere Bezeichnung, wie {unsubscribe_link})', 'pass-captain' ),
				) + self::waitlist_date_placeholders(),
				/* translators: %s: event */
				'subject' => static function () { return sprintf( __( 'Tickets für dich reserviert: %s', 'pass-captain' ), '{event_name}' ); },
				'body' => static function () {
					/* translators: 1: quantity, 2: ticket type, 3: event, 4: expiry date, 5: accept link */
					$offer = sprintf( __( "Gute Nachrichten: Für dich sind %1\$s × „%2\$s“ für „%3\$s“ reserviert.\n\nDie Reservierung gilt bis %4\$s. Nimm das Angebot bis dahin an und schließe die Bestellung ab, sonst geht es an die nächste Person auf der Warteliste:\n%5\$s\n\nWenn du die Tickets nicht mehr brauchst, kannst du das Angebot über denselben Link ablehnen.", 'pass-captain' ), '{quantity}', '{ticket_name}', '{event_name}', '{offer_expires}', '{accept_link}' );
					// A565: date (with time zone) and location of the offered date.
					/* translators: 1: date and time with time zone, 2: location */
					$when = sprintf( __( 'Termin: %1$s, %2$s', 'pass-captain' ), '{event_date}', '{event_location}' );
					return $offer . '{decline_note}' . "\n\n" . $when . "\n\n" . Types::waitlist_unsubscribe_note();
				},
				// Callers passing the older {leave_link} (personal leave link) get it in the standard unsubscribe sentence.
				'vars' => static function ( array $vars ) { return self::waitlist_compat_vars( $vars ); },
				'sample' => static function () { return array( 'offer_expires' => '16.01.2027 19:30 UTC', 'accept_link' => Mailer::link( home_url( '/?wpcap_waitlist_accept=12&token=' . str_repeat( 'e5', 32 ) ) ), 'quantity' => 2, 'position' => 1, 'event_name' => 'Sommerkonzert', 'ticket_name' => 'Stehplatz', 'unsubscribe_link' => Mailer::link( home_url( '/?wpcap_waitlist_unsubscribe=12&token=' . str_repeat( 'a7', 32 ) ) ) ) + self::waitlist_date_sample(); },
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
			case 'paid': return sprintf( __( 'Aufpreis bezahlt: %s', 'pass-captain' ), $amount ) . "\n\n";
			/* translators: %s: amount */
			case 'refund': return sprintf( __( 'Die Preisdifferenz von %s wird erstattet.', 'pass-captain' ), $amount ) . "\n\n";
			/* translators: %s: amount */
			case 'refund_pending': return sprintf( __( 'Die Erstattung der Preisdifferenz von %s wird geprüft.', 'pass-captain' ), $amount ) . "\n\n";
			case 'waived': return __( 'Der Umtausch erfolgte aus Kulanz ohne Aufpreis.', 'pass-captain' ) . "\n\n";
		}
		return '';
	}

	/**
	 * Waitlist mails: sentence with the personal unsubscribe link (placeholder {unsubscribe_link}).
	 * Waitlists also appends it to customised templates that lack the link. Call inside the recipient locale.
	 */
	public static function waitlist_unsubscribe_note( $link = '{unsubscribe_link}' ) {
		/* translators: %s: personal unsubscribe link */
		return sprintf( __( "Du möchtest nicht mehr auf der Warteliste stehen? Hier kannst du dich austragen:\n%s", 'pass-captain' ), $link );
	}

	/** Refund mail: correction document line incl. line break ('' without number). Call inside the recipient locale. */
	public static function credit_note( $number ) {
		/* translators: %s: credit note number */
		return '' !== (string) $number ? sprintf( __( 'Rechnungskorrektur: %s', 'pass-captain' ), $number ) . "\n" : '';
	}
}
