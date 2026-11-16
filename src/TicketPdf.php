<?php
/**
 * Dependency-free PDF renderer for tickets (one A4 page per ticket, Helvetica, vector QR code).
 *
 * Built on Printing\PdfWriter (compressed content streams, QR modules merged into runs, page-wise
 * serialisation). The QR matrix comes from the bundled bacon/bacon-qr-code encoder (installed with
 * Composer in the release ZIP). Without it the PDF still contains the ticket code as text.
 */
namespace PassCaptain;

use PassCaptain\Printing\PdfWriter;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketPdf {
	/** Tickets per mail attachment before the PDF is replaced by a note (filter wp_pass_captain_ticket_pdf_limits_v1). */
	const MAIL_MAX_TICKETS = 50;
	/** Attachment size limit in bytes (same filter). */
	const MAIL_MAX_BYTES = 5242880;

	/**
	 * @param array $tickets List of ['event','ticket','when','location','seat','attendee','token'] arrays.
	 * @param array $meta    ['title' => string, 'footer' => string, 'lang' => locale].
	 * @return string PDF bytes.
	 */
	public static function render( array $tickets, array $meta = array() ) {
		$pdf = self::build( new PdfWriter(), $tickets, $meta );
		return $pdf->output( (string) ( $meta['title'] ?? '' ) );
	}

	/** Streams the PDF into $file (mode 0600) page by page. False when writing failed. */
	public static function render_to_file( $file, array $tickets, array $meta = array() ) {
		$pdf = self::build( new PdfWriter( $file ), $tickets, $meta );
		return $pdf->finish( (string) ( $meta['title'] ?? '' ) );
	}

	private static function build( PdfWriter $pdf, array $tickets, array $meta ) {
		if ( ! empty( $meta['lang'] ) ) { $pdf->set_language( (string) $meta['lang'] ); }
		$values = Security\SignedQr::qr_values( array_map( static function ( $t ) { return (string) ( $t['token'] ?? '' ); }, $tickets ) );
		foreach ( array_values( $tickets ) as $i => $ticket ) {
			if ( ! $pdf->ok() ) { break; }
			$ticket['qr'] = $values[ $i ] ?? (string) ( $ticket['token'] ?? '' );
			self::page( $pdf, $ticket, $meta );
		}
		return $pdf;
	}

	/** One ticket page. Long event names wrap (two lines), other lines are shortened to the page width. */
	private static function page( PdfWriter $pdf, array $t, array $meta ) {
		$pdf->add_page( 595, 842 );
		$width = 495;
		$y = 780;
		$pdf->text( 50, $y, 11, PdfWriter::fit( (string) ( $meta['title'] ?? 'Ticket' ), 11, $width ) );
		$y -= 14;
		foreach ( PdfWriter::wrap( (string) ( $t['event'] ?? '' ), 22, $width, true, 2 ) as $line ) {
			$y -= 22;
			$pdf->text( 50, $y, 22, $line, true );
		}
		$rows = array(
			array( (string) ( $t['ticket'] ?? '' ), 14, false, 26 ),
			array( (string) ( $t['when'] ?? '' ), 12, false, 22 ),
			array( (string) ( $t['location'] ?? '' ), 12, false, 18 ),
			array( (string) ( $t['seat'] ?? '' ), 14, true, 20 ),
			array( (string) ( $t['attendee'] ?? '' ), 12, false, 18 ),
		);
		foreach ( $rows as $i => list( $text, $size, $bold, $step ) ) {
			if ( '' === $text && $i > 1 ) { continue; }
			$y -= $step;
			$pdf->text( 50, $y, $size, PdfWriter::fit( $text, $size, $width, $bold ), $bold );
		}
		$matrix = self::qr_matrix( (string) ( $t['qr'] ?? '' ) );
		$top = $y - 40;
		if ( $matrix ) {
			$count = count( $matrix );
			$module = min( 8.0, 260 / ( $count + 2 * QrCode::QUIET_ZONE ) );
			$size = $module * ( $count + 2 * QrCode::QUIET_ZONE );
			$y0 = $top - $size;
			$pdf->qr_matrix( ( 595 - $size ) / 2, $y0, $size, $matrix, QrCode::QUIET_ZONE );
			$top = $y0 - 20;
		}
		$token = (string) ( $t['token'] ?? '' );
		$pdf->text( 50, $top, 9, 'Code:' );
		$pdf->text( 80, $top, 9, substr( $token, 0, 32 ) );
		$pdf->text( 80, $top - 12, 9, substr( $token, 32 ) );
		if ( ! empty( $meta['footer'] ) ) { $pdf->text( 50, 40, 9, PdfWriter::fit( (string) $meta['footer'], 9, $width ) ); }
	}

	/** QR modules as rows of booleans, or empty array when the encoder is unavailable (see QrCode::matrix()). */
	public static function qr_matrix( $payload ) {
		return QrCode::matrix( (string) $payload );
	}

	/**
	 * Attachment limits for the ticket mail: [max tickets, max bytes]. Above them the PDF is not attached;
	 * the mail carries the ticket links and a note instead (audit R2 #5: 500 tickets made 10–30 MB mails).
	 *
	 * @param object|null $order Order row.
	 * @return array{0:int,1:int}
	 */
	public static function mail_limits( $order = null ) {
		/**
		 * Limits of the ticket PDF attached to the ticket mail.
		 *
		 * @param array       $limits ['max_tickets' => 50, 'max_bytes' => 5 MB].
		 * @param object|null $order  Order row.
		 */
		$limits = (array) apply_filters( 'wp_pass_captain_ticket_pdf_limits_v1', array( 'max_tickets' => self::MAIL_MAX_TICKETS, 'max_bytes' => self::MAIL_MAX_BYTES ), $order );
		return array( max( 0, (int) ( $limits['max_tickets'] ?? self::MAIL_MAX_TICKETS ) ), max( 0, (int) ( $limits['max_bytes'] ?? self::MAIL_MAX_BYTES ) ) );
	}

	/**
	 * Builds the ticket PDF for the ticket mail of an order (in the order's language, with attendee
	 * names) in a fresh private directory. When the order has more tickets than allowed or the PDF is
	 * larger than allowed, no file is attached and `notice` explains where the tickets are instead.
	 *
	 * @param object $order  Order row.
	 * @param array  $issued Issued tickets (ticket_id, token, event, ticket, starts_at, timezone, location).
	 * @return array{files:string[],dir:string,notice:string}
	 */
	public static function mail_attachment( $order, array $issued ) {
		$out = array( 'files' => array(), 'dir' => '', 'notice' => '' );
		if ( ! $issued ) { return $out; }
		list( $max_tickets, $max_bytes ) = self::mail_limits( $order );
		$locale = (string) ( $order->locale ?? '' );
		$too_large = static function () use ( $issued, $locale ) {
			return Mail\Mailer::with_locale( $locale, static function () use ( $issued ) {
				/* translators: %d: number of tickets */
				return sprintf( _n( '%d Ticket ist wegen seiner Größe nicht als PDF angehängt. Öffne es über den Ticketlink in dieser E-Mail; dort kannst du es vorzeigen oder ausdrucken. Deine Bestellung findest du jederzeit über den Link aus der Bestellbestätigung.', 'Deine %d Tickets sind wegen ihrer Anzahl nicht als PDF angehängt. Öffne sie über die Ticketlinks in dieser E-Mail; dort kannst du sie vorzeigen oder ausdrucken. Deine Bestellung findest du jederzeit über den Link aus der Bestellbestätigung.', count( $issued ), 'pass-captain' ), count( $issued ) );
			} );
		};
		if ( $max_tickets > 0 && count( $issued ) > $max_tickets ) {
			$out['notice'] = $too_large();
			return $out;
		}
		$dir = TempFiles::private_dir();
		if ( ! $dir ) { return $out; }
		$out['dir'] = $dir;
		$file = $dir . '/tickets-' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $order->public_id ?? 'order' ) ) . '.pdf';
		$names = self::attendee_names( array_column( $issued, 'ticket_id' ) );
		$ok = Mail\Mailer::with_locale( $locale, static function () use ( $file, $issued, $names, $locale ) {
			$tickets = array_map( static function ( $ticket ) use ( $names ) {
				return array(
					'event' => $ticket['event'],
					'ticket' => $ticket['ticket'],
					'when' => Orders::format_start( $ticket['starts_at'], $ticket['timezone'] ),
					'location' => $ticket['location'],
					'seat' => (string) apply_filters( 'wpcap_ticket_seat_label', '', (int) $ticket['ticket_id'], $ticket ),
					'attendee' => $names[ (int) $ticket['ticket_id'] ] ?? '',
					'token' => $ticket['token'],
				);
			}, $issued );
			return self::render_to_file( $file, $tickets, array( 'title' => get_bloginfo( 'name' ), 'footer' => __( 'Dieses Ticket ist nur einmal gültig. Code nicht weitergeben.', 'pass-captain' ), 'lang' => '' !== $locale ? $locale : Mail\Mailer::current_locale() ) );
		} );
		$size = $ok && is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( ! $ok || ! $size ) {
			TempFiles::remove( $file );
			return $out;
		}
		if ( $max_bytes > 0 && $size > $max_bytes ) {
			TempFiles::remove( $file );
			$out['notice'] = $too_large();
			return $out;
		}
		$out['files'][] = $file;
		return $out;
	}

	/**
	 * Attendee names of tickets (ticket_assignments), keyed by ticket id.
	 *
	 * @param int[] $ticket_ids Ticket ids.
	 * @return array<int,string>
	 */
	public static function attendee_names( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
		if ( ! $ids ) { return array(); }
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id,attendee_name FROM %i WHERE ticket_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( array( $wpdb->prefix . 'wpcap_ticket_assignments' ), $chunk ) ) );
			foreach ( (array) $rows as $row ) { $out[ (int) $row->ticket_id ] = (string) $row->attendee_name; }
		}
		return $out;
	}
}
