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
	 * @param array $tickets Items with event, ticket, when, location, url, token.
	 */
	public static function issued_tickets( array $tickets ) {
		$text = '';
		$html = '';
		foreach ( $tickets as $t ) {
			$text .= sprintf( "%s — %s\n%s\n%s\n\n", $t['event'], $t['ticket'], $t['when'], $t['location'] );
			$text .= __( 'Ticket öffnen oder drucken:', 'wp-pass-captain' ) . ' ' . esc_url_raw( $t['url'] ) . "\n";
			$text .= __( 'Ticket-Code:', 'wp-pass-captain' ) . ' ' . $t['token'] . "\n\n";
			$html .= '<p style="margin:0 0 16px"><strong>' . esc_html( $t['event'] . ' — ' . $t['ticket'] ) . '</strong><br>' . esc_html( $t['when'] ) . '<br>' . esc_html( $t['location'] ) . '<br>'
				. esc_html__( 'Ticket öffnen oder drucken:', 'wp-pass-captain' ) . ' ' . Mailer::link( $t['url'] )['html'] . '<br>'
				. esc_html__( 'Ticket-Code:', 'wp-pass-captain' ) . ' <code>' . esc_html( $t['token'] ) . '</code></p>';
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

	/** Extra text from filters such as wpcap_ticket_code_mail_section (wallet links). */
	public static function extra( $text ) {
		$text = (string) $text;
		return Mailer::block( $text, '' === trim( $text ) ? '' : '<p style="margin:0 0 16px">' . Mailer::text_to_html( trim( $text, "\n" ) ) . '</p>' );
	}
}
