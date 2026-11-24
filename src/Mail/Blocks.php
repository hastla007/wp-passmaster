<?php
/**
 * Pre-rendered placeholder values (ticket lists, links) with a plain-text and an HTML variant.
 * The text variants reproduce the plugin's historic plain-text mails exactly.
 */
namespace PassCaptain\Mail;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Blocks {
	/**
	 * Ticket list of the issue mail: event, date, location, link and code per ticket.
	 *
	 * @param array $tickets Items with event, ticket, when, location, url, token, help_code (optional).
	 */
	public static function issued_tickets( array $tickets ) {
		$text = '';
		$html = '';
		// A614: ticket holder and age/ID notice of the ticket type, as on the event card.
		$names = \PassCaptain\TicketPdf::attendee_names( array_map( static function ( $t ) { return (int) ( $t['ticket_id'] ?? 0 ); }, $tickets ) );
		foreach ( $tickets as $t ) {
			$holder = \PassCaptain\AdmissionChecks::holder_line( (string) ( $t['attendee'] ?? ( $names[ (int) ( $t['ticket_id'] ?? 0 ) ] ?? '' ) ), (int) ( $t['ticket_type_id'] ?? 0 ) );
			$text .= sprintf( "%s — %s\n%s\n%s\n", $t['event'], $t['ticket'], $t['when'], $t['location'] ) . ( '' !== $holder ? $holder . "\n" : '' ) . "\n";
			$text .= __( 'Ticket öffnen oder drucken:', 'pass-captain' ) . ' ' . esc_url_raw( $t['url'] ) . "\n";
			$text .= __( 'Ticket-Code:', 'pass-captain' ) . ' ' . $t['token'] . "\n";
			$help = (string) ( $t['help_code'] ?? '' );
			if ( '' !== $help ) { $text .= \PassCaptain\HelpCode::label( $help ) . "\n"; }
			$missing = self::missing_note( (array) ( $t['missing'] ?? array() ) );
			if ( '' !== $missing ) { $text .= $missing . "\n"; }
			$text .= "\n";
			$html .= '<p style="margin:0 0 16px"><strong>' . esc_html( $t['event'] . ' — ' . $t['ticket'] ) . '</strong><br>' . esc_html( $t['when'] ) . '<br>' . esc_html( $t['location'] ) . '<br>'
				. ( '' !== $holder ? esc_html( $holder ) . '<br>' : '' )
				. esc_html__( 'Ticket öffnen oder drucken:', 'pass-captain' ) . ' ' . Mailer::link( $t['url'] )['html'] . '<br>'
				. esc_html__( 'Ticket-Code:', 'pass-captain' ) . ' <code>' . esc_html( $t['token'] ) . '</code>'
				. ( '' !== $help ? '<br>' . esc_html( \PassCaptain\HelpCode::caption() ) . ' <strong style="font-family:monospace;letter-spacing:.1em">' . esc_html( $help ) . '</strong>' : '' )
				. ( '' !== $missing ? '<br><strong>' . esc_html( $missing ) . '</strong>' : '' ) . '</p>';
		}
		return Mailer::block( $text, $html );
	}

	/** A682: "Noch fehlende Angaben: …" for one ticket ('' when nothing is missing). */
	public static function missing_note( array $labels ) {
		if ( ! $labels ) { return ''; }
		/* translators: %s: comma-separated list of missing attendee data */
		return sprintf( __( 'Noch fehlende Angaben: %s – bitte über „Ticket öffnen“ ergänzen.', 'pass-captain' ), implode( ', ', array_map( 'strval', $labels ) ) );
	}

	/**
	 * Ticket list of the resend mail: event, date, location, link and extra section per ticket.
	 *
	 * @param array $tickets Items with event, ticket, when, location, url, extra (text from wpcap_ticket_code_mail_section).
	 */
	public static function code_tickets( array $tickets ) {
		$text = '';
		$html = '';
		foreach ( $tickets as $t ) {
			$text .= sprintf( "%s — %s\n%s\n%s\n%s\n\n", $t['event'], $t['ticket'], $t['when'], $t['location'], esc_url_raw( $t['url'] ) ) . (string) ( $t['extra'] ?? '' );
			$html .= '<p style="margin:0 0 16px"><strong>' . esc_html( $t['event'] . ' — ' . $t['ticket'] ) . '</strong><br>' . esc_html( $t['when'] ) . '<br>' . esc_html( $t['location'] ) . '<br>' . Mailer::link( $t['url'] )['html'] . '</p>'
				. self::extra( (string) ( $t['extra'] ?? '' ) )['html'];
		}
		return Mailer::block( $text, $html );
	}

	/** Bare list of ticket links (one per line). */
	public static function links( array $urls ) {
		$urls = array_map( 'esc_url_raw', array_map( 'strval', $urls ) );
		return Mailer::block( implode( "\n", $urls ), implode( '<br>', array_map( static function ( $url ) { return Mailer::link( $url )['html']; }, $urls ) ) );
	}

	/**
	 * Payment details of a pending manual payment (bank transfer) for {payment_instructions}.
	 * Ends with a blank line so the placeholder can sit in front of the next paragraph; '' when no
	 * details apply (the mail then reads exactly as without the placeholder).
	 *
	 * @param array|null $details Payments\BankTransfer::details() or null.
	 */
	public static function payment_instructions( $details ) {
		if ( ! is_array( $details ) || ! $details ) { return Mailer::block( '', '' ); }
		$title = __( 'Zahlungsinformationen', 'pass-captain' );
		$rows = \PassCaptain\Payments\BankTransfer::rows( $details );
		$instructions = trim( (string) ( $details['instructions'] ?? '' ) );
		$text = $title . "\n";
		$html = '<p style="margin:0 0 8px"><strong>' . esc_html( $title ) . '</strong></p><table role="presentation" style="border-collapse:collapse;margin:0 0 16px">';
		foreach ( $rows as $label => $value ) {
			$text .= $label . ': ' . $value . "\n";
			$html .= '<tr><td style="padding:2px 16px 2px 0;vertical-align:top">' . esc_html( $label ) . '</td><td style="padding:2px 0"><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
		$html .= '</table>';
		if ( '' !== $instructions ) {
			$text .= "\n" . $instructions . "\n";
			$html .= '<p style="margin:0 0 16px">' . Mailer::text_to_html( $instructions ) . '</p>';
		}
		if ( ! empty( $details['due_date'] ) ) {
			$note = __( 'Geht die Zahlung bis dahin nicht ein, verfällt die Reservierung.', 'pass-captain' );
			$text .= $note . "\n";
			$html .= '<p style="margin:0 0 16px">' . esc_html( $note ) . '</p>';
		}
		return Mailer::block( $text . "\n", $html );
	}

	/**
	 * Legal text of the settings (consumer information, terms) for the ticket and access-link mails
	 * (durable medium, § 312f Abs. 2 BGB / § 7 Abs. 3 FAGG): heading, text and a trailing blank line;
	 * empty when the setting is empty, so the mail reads exactly as without the placeholder.
	 *
	 * @param string $setting consumer_info_text|terms_text.
	 */
	public static function legal_text( $setting ) {
		// Legal texts per language (A099): the text for the mail's (switched) locale, default language otherwise.
		$text = trim( (string) ( method_exists( \PassCaptain\Settings::class, 'legal_text' ) ? \PassCaptain\Settings::legal_text( $setting ) : \PassCaptain\Settings::get( $setting ) ) );
		if ( '' === $text ) { return Mailer::block( '', '' ); }
		$title = 'terms_text' === $setting ? __( 'Bedingungen', 'pass-captain' ) : __( 'Verbraucherinformationen', 'pass-captain' );
		$plain = trim( (string) preg_replace( "/\n{3,}/", "\n\n", wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>|</p>#i', "\n", $text ) ) ) );
		return Mailer::block( $title . "\n" . $plain . "\n\n", '<p style="margin:0 0 8px"><strong>' . esc_html( $title ) . '</strong></p><div style="margin:0 0 16px">' . wp_kses_post( function_exists( 'wpautop' ) ? wpautop( $text ) : nl2br( $text ) ) . '</div>' );
	}

	/**
	 * Ordered lines for {order_items}: "2 × Event — Type (date): 50,00 €" per line.
	 *
	 * @param array[] $lines Orders::summary_lines().
	 */
	public static function order_items( array $lines ) {
		if ( ! $lines ) { return Mailer::block( '', '' ); }
		$title = __( 'Deine Bestellung', 'pass-captain' );
		$text = $title . "\n";
		$html = '<p style="margin:0 0 8px"><strong>' . esc_html( $title ) . '</strong></p><table role="presentation" style="border-collapse:collapse;margin:0 0 16px">';
		// A522: label on the start side, amount on the end side of the reading direction.
		$end = Layout::end_side();
		foreach ( $lines as $l ) {
			$label = sprintf( '%d × %s — %s (%s)', (int) $l['quantity'], $l['event'], $l['ticket'], $l['when'] );
			$money = \PassCaptain\Frontend\Format::money( (int) $l['gross_minor'], (string) $l['currency'] );
			$text .= $label . ': ' . $money . "\n";
			$html .= '<tr><td style="padding:2px 0;padding-' . $end . ':16px;vertical-align:top">' . esc_html( $label ) . '</td><td style="padding:2px 0;text-align:' . $end . '">' . esc_html( $money ) . '</td></tr>';
		}
		$html .= '</table>';
		return Mailer::block( $text . "\n", $html );
	}

	/**
	 * {contract_partner}: "Vertragspartner: …" in the agent model (organizer as contract partner), with
	 * a trailing blank line; empty otherwise, so the mail reads exactly as without the placeholder.
	 *
	 * @param array|null $partner Partners\SalesModel::contract_partner_for_order() result.
	 */
	public static function contract_partner( $partner ) {
		if ( ! is_array( $partner ) ) { return Mailer::block( '', '' ); }
		$line = \PassCaptain\Partners\SalesModel::contract_partner_line( $partner );
		return Mailer::block( $line . "\n\n", '<p style="margin:0 0 16px">' . esc_html( $line ) . '</p>' );
	}

	/** Extra text from filters such as wpcap_ticket_code_mail_section (wallet links). */
	public static function extra( $text ) {
		$text = (string) $text;
		return Mailer::block( $text, '' === trim( $text ) ? '' : '<p style="margin:0 0 16px">' . Mailer::text_to_html( trim( $text, "\n" ) ) . '</p>' );
	}
}
