<?php
/**
 * Pre-rendered placeholder values (ticket lists, links) with a plain-text and an HTML variant.
 * The text variants reproduce the plugin's historic plain-text mails exactly.
 */
namespace PassCaptain\Mail;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Blocks {
	/**
	 * Ticket list of the issue mail: event, date, location, link and code per ticket.
	 *
	 * @param array $tickets Items with event, ticket, when, location, url, token, help_code (optional).
	 */
	public static function issued_tickets( array $tickets ) {
		$text = '';
		$html = '';
		foreach ( $tickets as $t ) {
			$text .= sprintf( "%s — %s\n%s\n%s\n\n", $t['event'], $t['ticket'], $t['when'], $t['location'] );
			$text .= __( 'Ticket öffnen oder drucken:', 'pass-captain' ) . ' ' . esc_url_raw( $t['url'] ) . "\n";
			$text .= __( 'Ticket-Code:', 'pass-captain' ) . ' ' . $t['token'] . "\n";
			$help = (string) ( $t['help_code'] ?? '' );
			if ( '' !== $help ) { $text .= \PassCaptain\HelpCode::label( $help ) . "\n"; }
			$text .= "\n";
			$html .= '<p style="margin:0 0 16px"><strong>' . esc_html( $t['event'] . ' — ' . $t['ticket'] ) . '</strong><br>' . esc_html( $t['when'] ) . '<br>' . esc_html( $t['location'] ) . '<br>'
				. esc_html__( 'Ticket öffnen oder drucken:', 'pass-captain' ) . ' ' . Mailer::link( $t['url'] )['html'] . '<br>'
				. esc_html__( 'Ticket-Code:', 'pass-captain' ) . ' <code>' . esc_html( $t['token'] ) . '</code>'
				. ( '' !== $help ? '<br>' . esc_html( \PassCaptain\HelpCode::caption() ) . ' <strong style="font-family:monospace;letter-spacing:.1em">' . esc_html( $help ) . '</strong>' : '' ) . '</p>';
		}
		return Mailer::block( $text, $html );
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
	 * Ordered lines for {order_items}: "2 × Event — Type (date): 50,00 €" per line.
	 *
	 * @param array[] $lines Orders::summary_lines().
	 */
	public static function order_items( array $lines ) {
		if ( ! $lines ) { return Mailer::block( '', '' ); }
		$title = __( 'Deine Bestellung', 'pass-captain' );
		$text = $title . "\n";
		$html = '<p style="margin:0 0 8px"><strong>' . esc_html( $title ) . '</strong></p><table role="presentation" style="border-collapse:collapse;margin:0 0 16px">';
		foreach ( $lines as $l ) {
			$label = sprintf( '%d × %s — %s (%s)', (int) $l['quantity'], $l['event'], $l['ticket'], $l['when'] );
			$money = \PassCaptain\Frontend\Format::money( (int) $l['gross_minor'], (string) $l['currency'] );
			$text .= $label . ': ' . $money . "\n";
			$html .= '<tr><td style="padding:2px 16px 2px 0;vertical-align:top">' . esc_html( $label ) . '</td><td style="padding:2px 0;text-align:right">' . esc_html( $money ) . '</td></tr>';
		}
		$html .= '</table>';
		return Mailer::block( $text . "\n", $html );
	}

	/** Extra text from filters such as wpcap_ticket_code_mail_section (wallet links). */
	public static function extra( $text ) {
		$text = (string) $text;
		return Mailer::block( $text, '' === trim( $text ) ? '' : '<p style="margin:0 0 16px">' . Mailer::text_to_html( trim( $text, "\n" ) ) . '</p>' );
	}
}
