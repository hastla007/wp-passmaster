<?php
/**
 * Ticket PDF attachment and ticket list length for ticket mails (first delivery, delivery retry,
 * resend, reissue) – one place for the size rules, so every ticket mail behaves the same.
 *
 * - The PDF (one page per ticket, TicketPdf) is attached up to max_tickets() tickets per mail
 *   (filter `wp_pass_captain_ticket_pdf_max_tickets_v1`, default 200); larger mails carry the links only, so a
 *   mail never grows beyond what mail servers accept. The existing filter `wpcap_attach_ticket_pdf`
 *   still switches the attachment off.
 * - With the PDF attached, the ticket list in the mail body is shortened to list_max() entries
 *   (filter `wp_pass_captain_ticket_mail_list_max_v1`, default 25) plus a note: Gmail clips bodies above ~102 KB.
 *   Without a PDF the list stays complete (the links are the only copy of the codes).
 *
 * The PDF holds plaintext ticket codes: it is written to a private temp directory (0700, file 0600)
 * and removed right after the mail (remove()), at the latest at shutdown.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Mail;

use PassCaptain\TempFiles;
use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketAttachment {
	/** Default maximum number of tickets in one attached PDF. */
	const MAX_TICKETS = 200;

	/** Default number of tickets listed in the mail body when the PDF holds all of them. */
	const LIST_MAX = 25;

	/** Maximum tickets per attached PDF for this order. */
	public static function max_tickets( $order_id = 0 ) {
		return max( 1, (int) apply_filters( 'wp_pass_captain_ticket_pdf_max_tickets_v1', self::MAX_TICKETS, (int) $order_id ) );
	}

	/** Tickets listed in the body when the PDF is attached. */
	public static function list_max( $order_id = 0 ) {
		return max( 1, (int) apply_filters( 'wp_pass_captain_ticket_mail_list_max_v1', self::LIST_MAX, (int) $order_id ) );
	}

	/**
	 * Renders the ticket PDF for one mail (TicketPdf::mail_attachment(): order language, attendee
	 * names, streamed and compressed, size limits of `wp_pass_captain_ticket_pdf_limits_v1`). Above
	 * max_tickets() or the PDF limits no file is attached; `notice` then tells the buyer where the
	 * tickets are (append it to the mail body).
	 *
	 * @param object $order   Order row (order_id, public_id, locale).
	 * @param array  $tickets Issued/rotated items with ticket_id, token (plaintext), event, ticket, starts_at, timezone, location.
	 * @return array{files:string[],dir:string,notice:string} Attachment paths (empty when not attached).
	 */
	public static function create( $order, array $tickets ) {
		$none = array( 'files' => array(), 'dir' => '', 'notice' => '' );
		$order_id = (int) ( $order->order_id ?? 0 );
		if ( ! $tickets || ! apply_filters( 'wpcap_attach_ticket_pdf', true, $order_id ) ) {
			return $none;
		}
		if ( count( $tickets ) > self::max_tickets( $order_id ) ) {
			return $none;
		}
		$part = TicketPdf::mail_attachment( $order, array_values( $tickets ) );
		foreach ( (array) $part['files'] as $file ) { TempFiles::track( $file, (string) $part['dir'] ); }
		if ( ! $part['files'] && ! empty( $part['dir'] ) ) {
			TempFiles::remove_dir( (string) $part['dir'] );
			$part['dir'] = '';
		}
		return array( 'files' => (array) $part['files'], 'dir' => (string) $part['dir'], 'notice' => (string) $part['notice'] );
	}

	/** Notice block for a PDF that was left out because of its size (plain text and HTML). */
	public static function notice_block( $notice ) {
		$notice = trim( (string) $notice );
		if ( '' === $notice ) { return Mailer::block( '', '' ); }
		return Mailer::block( $notice . "\n\n", '<p style="margin:0 0 16px"><em>' . esc_html( $notice ) . '</em></p>' );
	}

	/**
	 * Removes the attachment (and files other filters added to the mail).
	 *
	 * @param array    $attachment Result of create().
	 * @param string[] $extra      Further attachment paths of the mail.
	 */
	public static function remove( array $attachment, array $extra = array() ) {
		foreach ( array_unique( array_merge( (array) ( $attachment['files'] ?? array() ), $extra ) ) as $file ) {
			if ( is_string( $file ) && '' !== $file ) { wp_delete_file( $file ); }
		}
		if ( ! empty( $attachment['dir'] ) ) { TempFiles::remove_dir( (string) $attachment['dir'] ); }
	}

	/**
	 * Entries listed in the mail body and the number left out (only when the PDF holds every ticket).
	 *
	 * @return array{0:array,1:int} [listed entries, omitted count].
	 */
	public static function shorten( array $entries, $order_id, $pdf_attached ) {
		$max = self::list_max( $order_id );
		if ( ! $pdf_attached || count( $entries ) <= $max ) { return array( $entries, 0 ); }
		return array( array_slice( $entries, 0, $max ), count( $entries ) - $max );
	}

	/** Note appended to a shortened ticket list (plain text and HTML). */
	public static function omitted_note( $omitted ) {
		if ( $omitted <= 0 ) { return Mailer::block( '', '' ); }
		/* translators: %d: number of tickets not listed in the mail body */
		$text = sprintf( _n( '… und %d weiteres Ticket. Alle Tickets mit QR-Code stehen im PDF-Anhang.', '… und %d weitere Tickets. Alle Tickets mit QR-Code stehen im PDF-Anhang.', (int) $omitted, 'pass-captain' ), (int) $omitted );
		return Mailer::block( $text . "\n\n", '<p style="margin:0 0 16px"><em>' . esc_html( $text ) . '</em></p>' );
	}
}
